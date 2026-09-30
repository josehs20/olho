<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\Investigation;
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
        private readonly PaymentService $payments,
    ) {}

    public function simulate(string $token): RedirectResponse
    {
        $investigation = $this->guests->resolveByToken($token) ?? abort(404);

        abort_unless(config('payments.provider') === 'fake', 404);
        abort_unless($investigation->status === Investigation::STATUS_DRAFT, 404);

        $payment = $investigation->guestPayment;

        abort_if($payment === null, 404);
        abort_if($payment->status === PaymentStatus::Paid->value, 404);

        $this->payments->simulatePayment($payment);

        return redirect()
            ->route('guest.investigations.show', ['token' => $token])
            ->with('status', __('Pagamento confirmado. Investigação liberada.'));
    }
}
