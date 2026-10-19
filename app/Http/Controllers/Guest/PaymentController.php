<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\Investigation;
use App\Payments\PaymentSettings;
use App\Payments\PaymentStatus;
use App\Services\GuestInvestigationService;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

/**
 * Pagamento da investigação avulsa (prompt 41).
 *
 * Em produção a liberação vem do webhook do gateway; aqui existe apenas a
 * simulação do provider fake. Em nenhum caminho o front decide que o pedido
 * está pago: quem libera a execução é o estado persistido do pagamento.
 *
 * A exceção é o botão "Já paguei": ele **pede** uma consulta ao provedor, mas
 * quem confirma é a resposta da API (mesma reconciliação idempotente do
 * webhook) — o clique nunca envia um status.
 */
class PaymentController extends Controller
{
    public function __construct(
        private readonly GuestInvestigationService $guests,
        private readonly PaymentService $payments,
        private readonly PaymentSettings $settings,
    ) {}

    public function simulate(string $token): RedirectResponse
    {
        $investigation = $this->guests->resolveByToken($token) ?? abort(404);

        // Com gateway real não existe rota de simulação: quem confirma é o
        // webhook. A verificação vem antes de resolver o PaymentService para
        // responder 404 (e não um erro de provider não suportado).
        abort_unless($this->settings->provider() === PaymentSettings::PROVIDER_FAKE, 404);

        $payment = $investigation->guestPayment;

        abort_if($payment === null, 404);
        // Pedido criado com outro provedor (troca no painel após o checkout):
        // simular seria falsificar o pagamento.
        abort_unless($payment->provider === PaymentSettings::PROVIDER_FAKE, 404);
        abort_unless($investigation->status === Investigation::STATUS_DRAFT, 404);
        abort_if($payment->status === PaymentStatus::Paid->value, 404);

        $this->payments->simulatePayment($payment);

        return redirect()
            ->route('guest.investigations.show', ['token' => $token])
            ->with('status', __('Pagamento confirmado. Investigação liberada.'));
    }

    /**
     * "Já paguei": consulta o PagBank agora e libera se ele confirmar o pagamento.
     *
     * É o caminho de confirmação sob demanda da jornada sem login — o visitante
     * não tem painel nem polling que reconsulte o provedor. Serve para quando a
     * notificação do PagBank não chega (webhook perdido ou endereço público
     * ainda não publicado) e para quem pagou e não quer esperar.
     *
     * O token é a única credencial (a rota não aceita id), a consulta é
     * idempotente e limitada por IP/token, e a liberação continua sendo a de
     * `reconcile()`/`confirmPayment()` — baseada apenas na resposta do provedor.
     */
    public function verify(string $token): RedirectResponse
    {
        $investigation = $this->guests->resolveByToken($token) ?? abort(404);
        $payment = $investigation->guestPayment;

        abort_if($payment === null, 404);

        // Sem provedor real não há o que consultar: o fake tem a rota de
        // simulação, e "já paguei" não pode confirmar nada sozinho.
        abort_if($payment->provider === PaymentSettings::PROVIDER_FAKE, 404);

        if ($payment->status === PaymentStatus::Paid->value) {
            return redirect()
                ->route('guest.investigations.show', ['token' => $token])
                ->with('status', __('Pagamento já confirmado. A investigação está liberada.'));
        }

        // Link do banco: "Já paguei" só registra o aviso para o administrador
        // conferir o extrato. A execução continua bloqueada até a confirmação
        // manual — o visitante nunca libera a própria investigação.
        if ($payment->provider === PaymentSettings::PROVIDER_PAYMENT_LINK) {
            $payment = $this->payments->requestManualConfirmation($payment);

            if ($payment->status === PaymentStatus::AwaitingManualConfirmation->value) {
                return redirect()
                    ->route('guest.investigations.show', ['token' => $token])
                    ->with('status', __('Pagamento informado. O administrador vai conferir e liberar a investigação.'));
            }

            return redirect()
                ->route('guest.investigations.show', ['token' => $token])
                ->with('error', __('Este pedido não está mais aguardando pagamento.'));
        }

        if ($payment->status !== PaymentStatus::AwaitingPayment->value) {
            return redirect()
                ->route('guest.investigations.show', ['token' => $token])
                ->with('error', __('Este pedido não está mais aguardando pagamento.'));
        }

        if ($payment->external_id === null) {
            return redirect()
                ->route('guest.investigations.show', ['token' => $token])
                ->with('error', __('A cobrança ainda não foi criada no PagBank. Recarregue a página e tente de novo.'));
        }

        try {
            $this->payments->reconcile($payment);
            $payment->refresh();
        } catch (\Throwable $exception) {
            Log::warning('payment.guest_verify.failed', [
                'payment_id' => $payment->id,
                'provider' => $payment->provider,
                'error' => $exception->getMessage(),
            ]);

            return redirect()
                ->route('guest.investigations.show', ['token' => $token])
                ->with('error', __('Não foi possível consultar o PagBank agora. Tente novamente em instantes.'));
        }

        if ($payment->status === PaymentStatus::Paid->value) {
            return redirect()
                ->route('guest.investigations.show', ['token' => $token])
                ->with('status', __('Pagamento confirmado. Investigação liberada.'));
        }

        return redirect()
            ->route('guest.investigations.show', ['token' => $token])
            ->with('warning', __('Ainda não identificamos o pagamento. Se você acabou de pagar, aguarde alguns instantes e verifique de novo.'));
    }
}
