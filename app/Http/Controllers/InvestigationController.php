<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInvestigationRequest;
use App\Models\Investigation;
use App\Models\SourceDefinition;
use App\Services\InvestigationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
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
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['modules' => $e->getMessage()]);
        }

        return redirect()->route('investigations.show', $investigation);
    }

    public function show(Request $request, Investigation $investigation): View
    {
        $this->authorize('view', $investigation);

        $investigation->load('runs');

        return view('investigations.show', compact('investigation'));
    }
}
