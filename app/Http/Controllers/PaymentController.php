<?php

namespace App\Http\Controllers;

use App\Models\CreditPackage;
use App\Models\Payment;
use App\Models\User;
use App\Payments\PagBank\PagBankException;
use App\Payments\PaymentChannel;
use App\Payments\PaymentMethod;
use App\Payments\PaymentSettings;
use App\Payments\PaymentStatus;
use App\Payments\PaymentUnavailableException;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
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
    public function index(Request $request): View
    {
        $packages = CreditPackage::query()
            ->where('kind', CreditPackage::KIND_CREDITS)
            ->where('active', true)
            ->orderBy('display_order')
            ->get();

        return view('payments.index', [
            'packages' => $packages,
            // Sem integração pronta o botão já aparece desabilitado (o guard do
            // servidor continua valendo em `store()`).
            'available' => $this->payments->available(),
            // Canais oferecidos agora (PIX e/ou cartão): cada botão só aparece se o
            // provedor e as configurações do painel permitirem.
            'channels' => $this->payments->availableChannels(),
            // Saldo lido do ledger a cada carregamento: o comprador que pagou em
            // outra aba volta aqui, recarrega e confere os créditos (nada é somado
            // no navegador).
            'balance' => (int) ($request->user()->wallet->balance ?? 0),
        ]);
    }

    /**
     * Cria o pedido no canal escolhido pelo comprador.
     *
     * Os dois canais terminam no mesmo lugar: a página de pagamento do provedor.
     * - checkout hospedado (PagBank): redireciona direto para o link devolvido
     *   pelo provedor (o OLHO nunca vê dados de cartão nem do comprador);
     * - PIX emitido na própria aplicação (provedor mock): redireciona para a
     *   página do pedido, onde está o código copia e cola e o acompanhamento.
     *
     * Sem modal e sem JavaScript: o POST de formulário basta, e o botão faz o que
     * promete mesmo quando o provedor recusa a cobrança — a tela volta com a
     * mensagem do erro em vez de um `400` silencioso na página.
     */
    public function store(Request $request, CreditPackage $package): RedirectResponse
    {
        abort_if($package->isGuestKind(), 404);

        $channel = $this->channel($request);

        $request->validate([
            'channel' => ['nullable', Rule::enum(PaymentChannel::class)],
        ]);

        try {
            $payment = $this->payments->checkout(
                $request->user(),
                $package,
                $channel,
            );
        } catch (PaymentUnavailableException $exception) {
            // Integração desativada/incompleta (ou canal indisponível): mensagem
            // acionável em vez de 500.
            return back()->with('error', $exception->getMessage());
        } catch (PagBankException) {
            // O provedor recusou a cobrança: o comprador recebe uma mensagem
            // acionável (sem jargão do gateway) e o motivo real — o campo apontado
            // no `400` — fica registrado no log e no painel do administrador.
            $message = __('Não foi possível iniciar o pagamento agora. O motivo foi registrado para o administrador — tente novamente em alguns minutos.');

            return back()->with('error', $message);
        }

        // Checkout hospedado: o comprador paga no ambiente do provedor, sem nenhum
        // salto intermediário. Sem link do provedor (PIX emitido aqui), a página do
        // pedido mostra o código copia e cola e acompanha a confirmação.
        if ($payment->checkout_url !== null) {
            return redirect()->away($payment->checkout_url);
        }

        return redirect()->route('payments.show', $payment);
    }

    /**
     * Estado atual do pedido em JSON — usado pela página do pedido enquanto o
     * comprador espera a confirmação.
     *
     * Reaproveita a reconciliação (reconsulta o provedor no máximo uma vez por
     * janela) e por isso também confirma o pagamento quando a notificação não
     * chega. Nada é liberado aqui: os créditos continuam sendo creditados uma
     * única vez pela reconciliação.
     */
    public function status(Request $request, Payment $payment): JsonResponse
    {
        $this->ensureAccountOwnership($request->user(), $payment);

        try {
            $this->payments->reconcileThrottled($payment);
            $payment->refresh();
        } catch (\Throwable) {
            // Provedor indisponível: vale o último estado persistido.
        }

        return response()->json(['payment' => $this->paymentPayload($payment)]);
    }

    /**
     * "Já paguei": consulta o PagBank agora, por decisão do comprador.
     *
     * Fallback quando a notificação do provedor não chega (webhook perdido,
     * endereço público ainda não publicado) e atalho para quem não quer esperar
     * o polling. A confirmação continua sendo a de `reconcile()`: idempotente,
     * baseada apenas na resposta do provedor — o clique não informa nada além
     * do pedido que a sessão já autoriza.
     */
    public function verify(Request $request, Payment $payment): RedirectResponse
    {
        $this->ensureAccountOwnership($request->user(), $payment);

        if ($payment->status === PaymentStatus::Paid->value) {
            return redirect()->route('payments.show', $payment)
                ->with('status', __('Pagamento já confirmado. Créditos adicionados à sua carteira.'));
        }

        if ($payment->provider === PaymentSettings::PROVIDER_FAKE || $payment->external_id === null) {
            return redirect()->route('payments.show', $payment)
                ->with('error', __('Este pedido ainda não tem uma cobrança no PagBank para consultar. Recarregue a página.'));
        }

        try {
            $this->payments->reconcile($payment);
            $payment->refresh();
        } catch (\Throwable $exception) {
            Log::warning('payment.verify.failed', [
                'payment_id' => $payment->id,
                'provider' => $payment->provider,
                'error' => $exception->getMessage(),
            ]);

            return redirect()->route('payments.show', $payment)
                ->with('error', __('Não foi possível consultar o PagBank agora. Tente novamente em instantes.'));
        }

        if ($payment->status === PaymentStatus::Paid->value) {
            return redirect()->route('payments.show', $payment)
                ->with('status', __('Pagamento confirmado. Créditos adicionados à sua carteira.'));
        }

        return redirect()->route('payments.show', $payment)
            ->with('warning', __('Ainda não identificamos o pagamento. Se você acabou de pagar, aguarde alguns instantes e verifique de novo.'));
    }

    /**
     * Exibe o status do pedido e os dados PIX.
     */
    public function show(Request $request, Payment $payment): View
    {
        $this->ensureAccountOwnership($request->user(), $payment);

        return view('payments.show', [
            'payment' => $payment,
            'isFake' => $payment->provider === PaymentSettings::PROVIDER_FAKE,
            // A página acompanha o pedido até a confirmação chegar.
            'statusUrl' => route('payments.status', $payment),
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
        $payment = $this->payments->handleWebhook(
            $request->all(),
            $request->headers->all(),
            $request->getContent(),
        );

        if ($payment === null) {
            return response()->json(['error' => 'invalid payload'], 400);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Página de retorno do checkout (URL assinada, pública).
     *
     * Consulta o provedor e, se o pagamento já constar como aprovado, confirma o
     * pedido na hora: a notificação pode não chegar (o comprador fecha o
     * checkout, o ambiente local não tem URL pública). Funciona para a jornada
     * avulsa e para a de créditos, sem expor o id sequencial do pedido (a URL é
     * assinada e temporária) e sem confiar em nada além da resposta do provedor.
     */
    public function returnStatus(Payment $payment): View
    {
        $status = $payment->status;

        try {
            // Reconciliação idempotente: repetir a visita não libera créditos nem
            // executa a investigação duas vezes.
            $status = $this->payments->reconcile($payment)->value;
            $payment->refresh();
        } catch (\Throwable) {
            // Mantém o status persistido se o provedor estiver indisponível.
        }

        return view('payments.return', [
            'payment' => $payment,
            'status' => $status,
        ]);
    }

    /**
     * Canal declarado na tela (`pix`/`card`). Ausente mantém o fluxo legado: o
     * provedor decide o que oferecer.
     */
    private function channel(Request $request): ?PaymentChannel
    {
        $channel = $request->input('channel');

        return is_string($channel) && $channel !== '' ? PaymentChannel::tryFrom($channel) : null;
    }

    /**
     * Dados do pedido consumidos pelo acompanhamento (JSON de status).
     *
     * @return array<string, mixed>
     */
    private function paymentPayload(Payment $payment): array
    {
        $payload = data_get($payment->metadata, 'pix_payload');

        return [
            'id' => $payment->id,
            'status' => $payment->status,
            'paid' => $payment->status === PaymentStatus::Paid->value,
            'method' => $payment->method instanceof PaymentMethod ? $payment->method->value : null,
            'amount_cents' => $payment->amount_cents,
            'amount_label' => 'R$ '.number_format($payment->amount_cents / 100, 2, ',', '.'),
            'checkout_url' => $payment->checkout_url,
            'pix_payload' => is_string($payload) && $payload !== '' ? $payload : null,
            'expires_at' => $payment->expires_at?->toIso8601String(),
            'status_url' => route('payments.status', $payment),
            'show_url' => route('payments.show', $payment),
            'next_url' => route('investigations.create'),
        ];
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
