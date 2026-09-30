<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Investigation;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InvestigationController extends Controller
{
    public function index(Request $request): View
    {
        $investigations = Investigation::query()
            ->with(['user', 'currentRun', 'guestPayment'])
            ->when(
                $request->filled('origin'),
                fn ($query) => $query->where('origin', $request->string('origin')->toString()),
            )
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.investigations.index', [
            'investigations' => $investigations,
            'origin' => $request->string('origin')->toString(),
        ]);
    }

    public function show(Investigation $investigation): View
    {
        $investigation->load([
            'user',
            'guestPayment.creditPackage',
            'runs.sourceRuns.sourceDefinition',
            'runs.sourceRuns.sourceResults',
        ]);

        return view('admin.investigations.show', compact('investigation'));
    }
}
