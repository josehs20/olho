<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SourceDefinition;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SourceController extends Controller
{
    public function index(): View
    {
        return view('admin.sources.index', [
            'sources' => SourceDefinition::query()->orderBy('name')->get(),
        ]);
    }

    public function toggle(SourceDefinition $source): RedirectResponse
    {
        $source->active = ! $source->active;
        $source->save();

        return back()->with('status', 'Fonte atualizada.');
    }
}
