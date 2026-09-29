<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CreditPackage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PackageController extends Controller
{
    public function index(): View
    {
        return view('admin.packages.index', [
            'packages' => CreditPackage::query()->orderBy('display_order')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.packages.create');
    }

    public function store(Request $request): RedirectResponse
    {
        CreditPackage::query()->create($this->validated($request));

        return redirect()->route('admin.packages.index')->with('status', 'Pacote criado.');
    }

    public function edit(CreditPackage $package): View
    {
        return view('admin.packages.edit', compact('package'));
    }

    public function update(Request $request, CreditPackage $package): RedirectResponse
    {
        $package->update($this->validated($request));

        return redirect()->route('admin.packages.index')->with('status', 'Pacote atualizado.');
    }

    public function destroy(CreditPackage $package): RedirectResponse
    {
        $package->delete();

        return redirect()->route('admin.packages.index')->with('status', 'Pacote removido.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'credits' => ['required', 'integer', 'min:1'],
            'price_cents' => ['required', 'integer', 'min:0'],
            'active' => ['boolean'],
            'display_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
