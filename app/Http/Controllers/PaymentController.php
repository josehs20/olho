<?php

namespace App\Http\Controllers;

use App\Models\CreditPackage;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    /**
     * Lista os pacotes de créditos disponíveis.
     *
     * O preço fixo da investigação avulsa (`kind = guest_investigation`) não é
     * vendido aqui: ele é oferecido na jornada pública sem cadastro.
     */
    public function index(): View
    {
        $packages = CreditPackage::query()
            ->where('kind', CreditPackage::KIND_CREDITS)
            ->where('active', true)
            ->orderBy('display_order')
            ->get();

        return view('payments.index', compact('packages'));
    }

    /**
     * Cria o pedido e a cobrança PIX para um pacote.
     */
    public function store(Request $request, CreditPackage $package): RedirectResponse
    {
        abort_if($package->isGuestKind(), 404);

        $payment = $this->payments->checkout($request->user(), $package);

        return redirect()->route('payments.show', $payment);
    }

    /**
     * Exibe o status do pedido e os dados PIX.
     */
    public function show(Request $request, Payment $payment): View
    {
        $this->ensureAccountOwnership($request->user(), $payment);

        return view('payments.show', [
            'payment' => $payment,
            'isFake' => config('payments.provider') === 'fake',
        ]);
    }

    /**
     * Simula a confirmação do pagamento (somente provider fake).
     */
    public function simulate(Request $request, Payment $payment): RedirectResponse
    {
        $this->ensureAccountOwnership($request->user(), $payment);

        $this->payments->simulatePayment($payment);

        return redirect()->route('payments.show', $payment)->with('status', 'paid');
    }

    /**
     * Endpoint de webhook do gateway.
     */
    public function webhook(Request $request)
    {
        $payment = $this->payments->handleWebhook($request->all(), $request->headers->all());

        if ($payment === null) {
            return response()->json(['error' => 'invalid payload'], 400);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Pedido avulso não pertence a conta alguma: só o token de acesso o alcança
     * (Guest\PaymentController). Rota autenticada responde 404 para não revelar
     * a existência do pedido.
     */
    private function ensureAccountOwnership(User $user, Payment $payment): void
    {
        abort_if($payment->isGuest(), 404);

        abort_if($payment->user_id !== $user->id, 403);
    }
}
