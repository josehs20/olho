<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\Investigation;
use App\Payments\PaymentSettings;
use App\Payments\PaymentStatus;
use App\Services\GuestInvestigationService;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;

/**
 * Pagamento da investigação avulsa (prompt 41).
 *
 * Em produção a liberação vem do webhook do gateway; aqui existe apenas a
 * simulação do provider fake. Em nenhum caminho o front decide que o pedido
 * está pago: quem libera a execução é o estado persistido do pagamento.
 */
class PaymentController extends Controller
{
    public function __construct(
        private readonly GuestInvestigationService $guests,
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

        app(PaymentService::class)->simulatePayment($payment);

        return redirect()
            ->route('guest.investigations.show', ['token' => $token])
            ->with('status', __('Pagamento confirmado. Investigação liberada.'));
    }
}
