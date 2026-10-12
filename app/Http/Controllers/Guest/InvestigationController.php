<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGuestInvestigationRequest;
use App\Models\Investigation;
use App\Models\InvestigationRun;
use App\Payments\PagBank\PagBankException;
use App\Payments\PaymentSettings;
use App\Payments\PaymentUnavailableException;
use App\Services\GuestInvestigationService;
use App\Services\PaymentService;
use App\Services\ReportManifestBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Jornada avulsa (prompt 41): visitante sem conta.
 *
 * O token é a única credencial: nenhuma ação aceita o id da investigação, e o
 * acesso a acompanhamento/relatório é sempre derivado do token informado na URL.
 */
class InvestigationController extends Controller
{
    public function __construct(
        private readonly GuestInvestigationService $guests,
        private readonly PaymentService $payments,
        private readonly ReportManifestBuilder $manifests,
    ) {}

    public function create(): View
    {
        return view('guest.investigations.create', [
            'price' => $this->guests->pricePackage(),
        ]);
    }

    /**
     * Cria a investigação avulsa (sem executar) e o pedido correspondente.
     */
    public function store(StoreGuestInvestigationRequest $request): RedirectResponse
    {
        // Sem provedor pronto não há cobrança: não criamos a investigação (nem o
        // token) para não deixar um pedido órfão sem forma de pagamento.
        if (! $this->payments->available()) {
            return back()->withInput()->withErrors([
                'payment' => __('Pagamentos estão temporariamente indisponíveis. Tente novamente mais tarde.'),
            ]);
        }

        try {
            $guest = $this->guests->start(
                $request->only(['name', 'username', 'email', 'phone', 'city', 'state']),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['username' => $e->getMessage()]);
        }

        try {
            $this->payments->checkoutGuest($guest['investigation'], $guest['package']);
        } catch (PaymentUnavailableException $exception) {
            // Configuração mudou entre as duas etapas: mensagem acionável, sem 500.
            return back()->withInput()->withErrors(['payment' => $exception->getMessage()]);
        } catch (PagBankException) {
            // Provedor recusou a cobrança: o motivo real (campo do `400`) fica no
            // log e no painel do administrador; o visitante recebe o que fazer.
            return back()->withInput()->withErrors([
                'payment' => __('Não foi possível iniciar o pagamento agora. O motivo foi registrado para o administrador — tente novamente em alguns minutos.'),
            ]);
        }

        return redirect()->route('guest.investigations.show', ['token' => $guest['token']]);
    }

    /**
     * Página única de acompanhamento: PIX pendente → progresso → resultado.
     */
    public function show(string $token): View
    {
        $investigation = $this->guestOr404($token);

        $investigation->load(['currentRun.sourceRuns.sourceDefinition', 'currentRun.report']);

        $payment = $investigation->guestPayment;

        return view('guest.investigations.show', [
            'token' => $token,
            'investigation' => $investigation,
            'payment' => $payment,
            'progress' => $this->guests->progress($investigation),
            'isFake' => $payment?->provider === PaymentSettings::PROVIDER_FAKE,
            'checkoutUrl' => $payment?->checkout_url,
            'statusUrl' => route('guest.investigations.status', ['token' => $token]),
            'reportUrl' => route('guest.investigations.report', ['token' => $token]),
        ]);
    }

    /**
     * Estado observável para o polling da página (sem dados sensíveis).
     */
    public function status(string $token): JsonResponse
    {
        $investigation = $this->guestOr404($token);
        $payment = $investigation->guestPayment;

        return response()->json([
            'payment' => [
                'status' => $payment?->status,
                'paid_at' => $payment?->paid_at?->toISOString(),
            ],
            'progress' => $this->guests->progress($investigation),
        ]);
    }

    /**
     * Relatório da avulsa: mesmo relatório/manifesto do fluxo autenticado, com
     * layout público e sem exigir conta.
     */
    public function report(string $token): View
    {
        $investigation = $this->guestOr404($token);

        $run = $investigation->currentRun()
            ->with([
                'sourceRuns.sourceDefinition',
                'sourceRuns.sourceResults.evidence',
                'report',
            ])
            ->first();

        abort_if($run === null, 404);

        // Só existe relatório de execução que aconteceu (avulsa não paga nunca gera).
        abort_unless(
            $run->report !== null || in_array($run->status, [InvestigationRun::STATUS_COMPLETED, InvestigationRun::STATUS_PARTIAL], true),
            404,
        );

        return view('reports.show', [
            'investigation' => $investigation,
            'run' => $run,
            'manifest' => $this->manifests->for($run),
            'correlation' => $run->metadata['correlation'] ?? null,
            'layout' => 'public-layout',
            'backUrl' => route('guest.investigations.show', ['token' => $token]),
        ]);
    }

    private function guestOr404(string $token): Investigation
    {
        return $this->guests->resolveByToken($token) ?? abort(404);
    }
}
