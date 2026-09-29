<?php

namespace App\Http\Controllers;

use App\Models\Investigation;
use App\Models\InvestigationRun;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;

class ReportController extends Controller
{
    use AuthorizesRequests;

    public function show(Investigation $investigation, InvestigationRun $run): View
    {
        $this->authorize('view', $investigation);

        abort_if($run->investigation_id !== $investigation->id, 404);

        $run->load([
            'sourceRuns.sourceDefinition',
            'sourceRuns.sourceResults.evidence',
        ]);

        return view('reports.show', [
            'investigation' => $investigation,
            'run' => $run,
            'correlation' => $run->metadata['correlation'] ?? null,
        ]);
    }
}
