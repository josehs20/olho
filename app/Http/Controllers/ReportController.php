<?php

namespace App\Http\Controllers;

use App\Models\Investigation;
use App\Models\InvestigationRun;
use App\Services\ReportManifestBuilder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly ReportManifestBuilder $manifests) {}

    public function show(Request $request, Investigation $investigation, InvestigationRun $run): View
    {
        // Avulsa: relatório apenas pelo token (ou admin no painel) — ver prompt 41.
        abort_if($investigation->isGuest() && ! $request->user()->is_admin, 404);

        $this->authorize('view', $investigation);

        abort_if($run->investigation_id !== $investigation->id, 404);

        $run->load([
            'sourceRuns.sourceDefinition',
            'sourceRuns.sourceResults.evidence',
            'report',
        ]);

        return view('reports.show', [
            'investigation' => $investigation,
            'run' => $run,
            // Manifesto congelado (relatório histórico) ou calculado agora.
            'manifest' => $this->manifests->for($run),
            'correlation' => $run->metadata['correlation'] ?? null,
        ]);
    }
}
