<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Investigation;
use Illuminate\View\View;

class InvestigationController extends Controller
{
    public function index(): View
    {
        return view('admin.investigations.index', [
            'investigations' => Investigation::query()->with('user', 'currentRun')->latest()->paginate(20),
        ]);
    }

    public function show(Investigation $investigation): View
    {
        $investigation->load([
            'user',
            'runs.sourceRuns.sourceDefinition',
            'runs.sourceRuns.sourceResults',
        ]);

        return view('admin.investigations.show', compact('investigation'));
    }
}
