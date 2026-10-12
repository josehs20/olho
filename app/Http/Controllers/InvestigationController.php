<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientCreditsException;
use App\Http\Requests\StoreInvestigationRequest;
use App\Models\Investigation;
use App\Models\SourceDefinition;
use App\Services\InvestigationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InvestigationController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly InvestigationService $service) {}

    public function index(Request $request): View
    {
        return view('investigations.index', [
            'investigations' => $this->service->history($request->user()),
        ]);
    }

    public function create(): View
    {
        return view('investigations.create', [
            'modules' => SourceDefinition::query()->where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(StoreInvestigationRequest $request): RedirectResponse
    {
        try {
            $investigation = $this->service->create(
                $request->user(),
                $request->only(['name', 'username', 'email', 'phone', 'city', 'state']),
                $request->input('modules', []),
                $request->input('title'),
            );
        } catch (InsufficientCreditsException $e) {
            return back()->withInput()->withErrors([
                'modules' => __('Saldo insuficiente para executar a investigação: são necessários :requested créditos.', [
                    'requested' => $e->requested,
                ]),
            ]);
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['modules' => $e->getMessage()]);
        }

        return redirect()->route('investigations.show', $investigation);
    }

    public function show(Request $request, Investigation $investigation): View
    {
        // Avulsa (prompt 41) pertence apenas ao token: contas comuns recebem 404
        // (não revela existência); o admin segue podendo inspecionar no painel.
        abort_if($investigation->isGuest() && ! $request->user()->is_admin, 404);

        $this->authorize('view', $investigation);

        $investigation->load([
            'runs.sourceRuns.sourceDefinition',
            'runs.sourceRuns.sourceResults.evidence',
            'runs.report',
        ]);

        return view('investigations.show', [
            'investigation' => $investigation,
            'progress' => $this->service->progress($investigation),
            'statusUrl' => route('investigations.status', $investigation),
        ]);
    }

    /**
     * Estado observável para o polling da página de resultados (JSON leve, sem
     * dados sensíveis). Mesma autorização do `show` — o dono (ou admin) acompanha
     * o próprio processamento sem precisar recarregar a página.
     */
    public function status(Request $request, Investigation $investigation): JsonResponse
    {
        abort_if($investigation->isGuest() && ! $request->user()->is_admin, 404);

        $this->authorize('view', $investigation);

        return response()->json([
            'progress' => $this->service->progress($investigation),
        ]);
    }

    /**
     * Reexecução controlada: cria um novo run com custo independente.
     */
    public function rerun(Request $request, Investigation $investigation): RedirectResponse
    {
        abort_if($investigation->isGuest() && ! $request->user()->is_admin, 404);

        $this->authorize('rerun', $investigation);

        try {
            $this->service->rerun($request->user(), $investigation);
        } catch (InsufficientCreditsException $e) {
            return back()->withErrors([
                'rerun' => __('Saldo insuficiente para reexecutar a investigação: são necessários :requested créditos.', [
                    'requested' => $e->requested,
                ]),
            ]);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['rerun' => $e->getMessage()]);
        }

        return redirect()
            ->route('investigations.show', $investigation)
            ->with('status', __('Nova execução iniciada. O run anterior permanece disponível para auditoria.'));
    }
}
