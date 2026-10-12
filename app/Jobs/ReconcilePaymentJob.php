<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Payments\PaymentSettings;
use App\Payments\PaymentStatus;
use App\Services\PaymentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Verificação do pagamento no worker (webhook que chegou e não concluiu).
 *
 * A notificação do PagBank é o mecanismo normal de confirmação, mas ela pode
 * chegar **sem concluir**: a reconsulta ao provedor falha (rede, 5xx, 429) ou o
 * provedor ainda não devolve uma cobrança conclusiva (o pedido do checkout
 * demora a aparecer). Em vez de deixar o pedido pendente para sempre, a rota do
 * webhook agenda este job — o worker reconsulta o provedor e libera o que já
 * constar como pago (créditos da conta ou investigação avulsa), pelo mesmo
 * caminho idempotente da sincronização manual: repetir não credita duas vezes.
 *
 * Recebe **apenas o id** do pagamento (nenhum modelo serializado) e é `Unique`
 * por pagamento: uma rajada de reentregas da mesma notificação não vira uma fila
 * de consultas ao provedor.
 */
class ReconcilePaymentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public int $timeout = 60;

    public int $uniqueFor = 600;

    public function __construct(
        public int $paymentId,
        public string $reason = 'webhook',
    ) {}

    public function uniqueId(): string
    {
        return 'payment-reconcile-'.$this->paymentId;
    }

    public function handle(PaymentService $payments): void
    {
        $payment = Payment::query()->find($this->paymentId);

        if ($payment === null) {
            $this->skip('not_found');

            return;
        }

        if ($payment->status === PaymentStatus::Paid->value) {
            // Já liberado (o comprador voltou pelo checkout, o operador
            // sincronizou ou a notificação foi reentregue depois): nada a fazer.
            $this->skip('already_paid');

            return;
        }

        if ($payment->provider !== PaymentSettings::PROVIDER_PAGBANK || $payment->external_id === null) {
            // Provedor trocado no painel depois do pedido, ou cobrança que nunca
            // chegou a ser criada no provedor: não há o que consultar.
            $this->skip('not_reconcilable');

            return;
        }

        $status = $payments->reconcile($payment);
        $payment->refresh();

        // Estado ainda pendente **não** é falha: o provedor pode simplesmente não
        // ter concluído o pagamento (PIX esperando). O job termina normalmente e
        // quem continua conferindo é a próxima notificação, o retorno do
        // comprador ou a varredura do painel.
        Log::info('payment.reconcile.job', [
            'payment_id' => $payment->id,
            'provider' => $payment->provider,
            'provider_status' => $status->value,
            'status' => $payment->status,
            'paid' => $payment->status === PaymentStatus::Paid->value,
            'reason' => $this->reason,
        ]);
    }

    /**
     * Tentativas esgotadas (provedor indisponível em todas elas).
     *
     * O pagamento segue pendente e há outros caminhos de confirmação (retorno do
     * comprador, botão do painel, varredura do comando, nova reentrega da
     * notificação) — por isso a falha fica registrada para o operador em vez de
     * silenciosa.
     */
    public function failed(\Throwable $exception): void
    {
        Log::warning('payment.reconcile.failed', [
            'payment_id' => $this->paymentId,
            'reason' => $this->reason,
            'error' => $exception->getMessage(),
        ]);
    }

    private function skip(string $why): void
    {
        Log::info('payment.reconcile.skipped', [
            'payment_id' => $this->paymentId,
            'reason' => $this->reason,
            'skipped' => $why,
        ]);
    }
}
