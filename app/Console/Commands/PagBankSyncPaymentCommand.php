<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Payments\PaymentStatus;
use App\Services\PaymentService;
use Illuminate\Console\Command;

class PagBankSyncPaymentCommand extends Command
{
    /**
     * Teto de pedidos por varredura no terminal.
     *
     * Protege o sandbox (cada pedido gera 1–2 chamadas à API) sem impedir o uso
     * normal: o banco local tem poucos pendentes por vez.
     */
    private const SWEEP_LIMIT = 100;

    /** @var string */
    protected $signature = 'pagbank:sync-payment
                            {payment? : ID sequencial, external_id/provider_checkout_id ou reference_id (olho-ID)}
                            {--pending : sincroniza todos os pagamentos PagBank que ainda aguardam confirmação}
                            {--dry-run : consulta apenas o estado no provedor, sem confirmar}
                            {--force : reconsulta o provedor mesmo se o pagamento já estiver pago}';

    /** @var string */
    protected $description = 'Sincroniza o estado de pagamentos PagBank no Sandbox (sem depender do webhook).';

    public function handle(PaymentService $payments): int
    {
        $value = trim((string) ($this->argument('payment') ?? ''));

        if ($this->option('pending')) {
            return $this->sweep($payments, $value);
        }

        if ($value === '') {
            $this->error('Informe o pagamento (ID, CHEC_…, ORDE_…, olho-ID ou idempotency_key) ou use --pending para sincronizar todos os pendentes.');

            return self::FAILURE;
        }

        $payment = $this->resolvePayment();

        if ($payment === null) {
            $this->error('Pagamento não encontrado.');

            return self::FAILURE;
        }

        if ($payment->provider !== 'pagbank') {
            $this->warn("O pagamento #{$payment->id} utiliza o provedor \"{$payment->provider}\"; este comando só sincroniza o PagBank.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            return $this->dryRun($payments, $payment);
        }

        if ($payment->status === PaymentStatus::Paid->value && ! $this->option('force')) {
            $this->info("O pagamento #{$payment->id} já está pago — nenhuma liberação adicional será feita (use --force para reconsultar).");

            return self::SUCCESS;
        }

        try {
            // Reconsulta o provedor e confirma quando o pedido já constar como
            // aprovado. A confirmação é idempotente (PaymentEvent + paid_at): rodar
            // duas vezes não libera a investigação nem credita os créditos de novo.
            $status = $payments->reconcile($payment);
        } catch (\Throwable $exception) {
            $this->error('Falha ao consultar o PagBank: '.$exception->getMessage());

            return self::FAILURE;
        }

        $payment->refresh();

        if ($payment->status === PaymentStatus::Paid->value) {
            $this->info("Pagamento #{$payment->id} confirmado — status local: {$payment->status}.");

            return self::SUCCESS;
        }

        $this->info("Pagamento #{$payment->id} ainda pendente (status local: {$payment->status}; provedor: {$status->value}).");

        return self::SUCCESS;
    }

    /**
     * Varredura: sincroniza **todos** os pagamentos PagBank pendentes.
     *
     * É o "conferir todos os pagamentos" em uma única chamada — inclusive o PIX
     * pago cuja notificação nunca chegou. Cada pedido é reconsultado no provedor
     * e confirmado quando já constar como aprovado, com a mesma liberação
     * idempotente do webhook.
     */
    private function sweep(PaymentService $payments, string $value): int
    {
        if ($value !== '') {
            $this->error('Use o argumento OU --pending — não os dois.');

            return self::FAILURE;
        }

        if ($this->option('force')) {
            $this->error('--force vale para um pagamento específico; --pending já reconsulta cada pendente.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            return $this->sweepDryRun($payments);
        }

        $results = $payments->reconcilePendingPagBank(self::SWEEP_LIMIT);

        if ($results === []) {
            $this->info('Nenhum pagamento PagBank aguardando confirmação.');

            return self::SUCCESS;
        }

        $confirmed = 0;
        $failed = 0;

        foreach ($results as $result) {
            $payment = $result['payment'];

            if ($result['error'] !== null) {
                $failed++;
                $this->line(sprintf('[falha] #%d %s — %s', $payment->id, $this->label($payment), $result['error']));

                continue;
            }

            if ($payment->status === PaymentStatus::Paid->value) {
                $confirmed++;
                $this->line(sprintf(
                    '[confirmado] #%d %s — R$ %s (%s).',
                    $payment->id,
                    $this->label($payment),
                    number_format($payment->amount_cents / 100, 2, ',', '.'),
                    $payment->method?->value ?? 'método não informado',
                ));

                continue;
            }

            $this->line(sprintf(
                '[pendente] #%d %s — aguardando pagamento (provedor: %s).',
                $payment->id,
                $this->label($payment),
                $result['provider_status']?->value ?? '—',
            ));
        }

        $pending = count($results) - $confirmed - $failed;

        $this->newLine();
        $this->line(sprintf(
            '%d consultado(s): %d confirmado(s), %d pendente(s), %d falha(s).',
            count($results),
            $confirmed,
            $pending,
            $failed,
        ));

        if (count($results) >= self::SWEEP_LIMIT) {
            $this->warn('Havia mais pendentes que o limite desta varredura — rode novamente para continuar.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Varredura em modo de inspeção: consulta cada pendente sem confirmar nada.
     */
    private function sweepDryRun(PaymentService $payments): int
    {
        $pending = $payments->pendingPagBank(self::SWEEP_LIMIT);

        if ($pending->isEmpty()) {
            $this->info('Nenhum pagamento PagBank aguardando confirmação.');

            return self::SUCCESS;
        }

        foreach ($pending as $payment) {
            try {
                $status = $payments->status($payment);

                $this->line(sprintf(
                    '#%d %s — status local: %s; provedor: %s.',
                    $payment->id,
                    $this->label($payment),
                    $payment->status,
                    $status->value,
                ));
            } catch (\Throwable $exception) {
                $this->line(sprintf('[falha] #%d %s — %s', $payment->id, $this->label($payment), $exception->getMessage()));
            }
        }

        $this->newLine();
        $this->line('<fg=cyan>[dry-run]</fg=cyan> Nada foi confirmado nem liberado.');

        return self::SUCCESS;
    }

    /**
     * Identificação curta do pedido no terminal (id do provedor).
     */
    private function label(Payment $payment): string
    {
        return (string) ($payment->provider_checkout_id ?? $payment->external_id ?? '—');
    }

    /**
     * Consulta o provedor sem confirmar nada (útil para inspecionar o Sandbox).
     */
    private function dryRun(PaymentService $payments, Payment $payment): int
    {
        try {
            $status = $payments->status($payment);
        } catch (\Throwable $exception) {
            $this->error('Falha ao consultar o PagBank: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->line("Pagamento #{$payment->id} - status local: {$payment->status}; provedor: {$status->value}.");
        $this->line('<fg=cyan>[dry-run]</fg=cyan> Nada foi confirmado nem liberado.');

        return self::SUCCESS;
    }

    /**
     * Resolve o pagamento por id sequencial, ids do provedor ou reference_id.
     *
     * O reference_id enviado ao PagBank é olho-{id} (PagBankProvider::referenceId);
     * external_id/provider_checkout_id guardam o id do checkout (CHEC_...) ou do
     * pedido PIX (ORDE_...); idempotency_key é a referência interna única.
     */
    private function resolvePayment(): ?Payment
    {
        $value = trim((string) $this->argument('payment'));

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            $payment = Payment::query()->find((int) $value);

            if ($payment !== null) {
                return $payment;
            }
        }

        if (preg_match('/^olho-(\d+)$/', $value, $matches) === 1) {
            $payment = Payment::query()->find((int) $matches[1]);

            if ($payment !== null) {
                return $payment;
            }
        }

        return Payment::query()
            ->where('external_id', $value)
            ->orWhere('provider_checkout_id', $value)
            ->orWhere('idempotency_key', $value)
            ->first();
    }
}
