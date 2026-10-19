<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Payments\PaymentSettings;
use App\Payments\PaymentStatus;
use App\Services\AuditLogger;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Conferência manual dos pagamentos por link do banco (provedor `payment_link`).
 *
 * O comprador clica em "Já paguei" e o pedido entra em
 * `awaiting_manual_confirmation`. Nada é liberado por clique: o administrador
 * confere o extrato no site do banco e confirma aqui. A confirmação reaproveita
 * o caminho idempotente do webhook (`PaymentService::confirmManual`), então
 * créditos/execução são liberados uma única vez.
 */
class ManualPaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Fila de conferência: avisados primeiro, depois os demais pedidos abertos.
     *
     * Pedidos já pagos saem da lista; pedidos nunca avisados continuam visíveis
     * para o operador cruzar um crédito no extrato com um pedido antigo.
     */
    public function index(): View
    {
        $payments = Payment::query()
            ->where('provider', PaymentSettings::PROVIDER_PAYMENT_LINK)
            ->whereIn('status', [
                PaymentStatus::Pending->value,
                PaymentStatus::AwaitingPayment->value,
                PaymentStatus::AwaitingManualConfirmation->value,
            ])
            ->with(['user', 'creditPackage', 'guestInvestigation'])
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [PaymentStatus::AwaitingManualConfirmation->value])
            ->latest('id')
            ->paginate(25);

        return view('admin.payments.manual', [
            'payments' => $payments,
            'awaitingManual' => Payment::query()
                ->where('provider', PaymentSettings::PROVIDER_PAYMENT_LINK)
                ->where('status', PaymentStatus::AwaitingManualConfirmation->value)
                ->count(),
        ]);
    }

    /**
     * Confirma o recebimento e libera créditos ou a investigação avulsa.
     */
    public function confirm(Request $request, Payment $payment): RedirectResponse
    {
        abort_unless($payment->provider === PaymentSettings::PROVIDER_PAYMENT_LINK, 404);

        if ($payment->status === PaymentStatus::Paid->value) {
            return redirect()->route('admin.payments.manual')
                ->with('status', sprintf('O pagamento #%d já estava confirmado.', $payment->id));
        }

        try {
            $confirmed = $this->payments->confirmManual($payment);
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $this->audit->log(
            $request->user(),
            'admin.payment.manual_confirm',
            $confirmed,
            [
                'payment_id' => $confirmed->id,
                'provider' => $confirmed->provider,
                'amount_cents' => $confirmed->amount_cents,
                'guest_investigation' => $confirmed->isGuest(),
            ],
            $request->ip(),
        );

        return redirect()->route('admin.payments.manual')->with('status', sprintf(
            'Pagamento #%d confirmado manualmente — %s.',
            $confirmed->id,
            $confirmed->isGuest() ? 'investigação avulsa liberada' : 'créditos liberados',
        ));
    }

    /**
     * Devolve o pedido para "aguardando pagamento" (aviso indevido/duplicado).
     *
     * Nenhum valor é alterado: é só a saída da fila de conferência. Se o
     * comprador avisar de novo, o pedido volta para a fila.
     */
    public function dismiss(Request $request, Payment $payment): RedirectResponse
    {
        abort_unless($payment->provider === PaymentSettings::PROVIDER_PAYMENT_LINK, 404);

        if ($payment->status !== PaymentStatus::AwaitingManualConfirmation->value) {
            return back()->with('status', sprintf('O pagamento #%d não está na fila de conferência.', $payment->id));
        }

        $payment->status = PaymentStatus::AwaitingPayment->value;
        $payment->metadata = array_merge($payment->metadata ?? [], [
            'manual_dismissed_at' => now()->toIso8601String(),
        ]);
        $payment->save();

        $this->audit->log(
            $request->user(),
            'admin.payment.manual_dismiss',
            $payment,
            ['payment_id' => $payment->id, 'provider' => $payment->provider],
            $request->ip(),
        );

        return redirect()->route('admin.payments.manual')
            ->with('status', sprintf('Aviso do pagamento #%d retirado da fila de conferência.', $payment->id));
    }
}
