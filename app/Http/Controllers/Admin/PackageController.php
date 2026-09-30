<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CreditPackage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pacotes de créditos e preço da investigação avulsa (prompt 41).
 *
 * O preço da avulsa é a linha `kind = guest_investigation`: criada pelo seeder e
 * apenas editável aqui (única, sem créditos), para que o admin altere o valor
 * sem deploy sem transformá-la em um pacote comum.
 */
class PackageController extends Controller
{
    public function index(): View
    {
        $packages = CreditPackage::query()->orderBy('display_order')->get();

        return view('admin.packages.index', [
            'packages' => $packages->where('kind', CreditPackage::KIND_CREDITS)->values(),
            'guestPackage' => $packages->firstWhere('kind', CreditPackage::KIND_GUEST_INVESTIGATION),
        ]);
    }

    public function create(): View
    {
        return view('admin.packages.create');
    }

    public function store(Request $request): RedirectResponse
    {
        CreditPackage::query()->create($this->validatedCredits($request) + [
            'kind' => CreditPackage::KIND_CREDITS,
        ]);

        return redirect()->route('admin.packages.index')->with('status', 'Pacote criado.');
    }

    public function edit(CreditPackage $package): View
    {
        return view('admin.packages.edit', compact('package'));
    }

    public function update(Request $request, CreditPackage $package): RedirectResponse
    {
        if ($package->isGuestKind()) {
            $package->update($request->validate([
                'name' => ['required', 'string', 'max:255'],
                'price_cents' => ['required', 'integer', 'min:1'],
                'active' => ['boolean'],
            ]) + ['credits' => 0]);

            return redirect()->route('admin.packages.index')
                ->with('status', 'Preço da investigação avulsa atualizado.');
        }

        $package->update($this->validatedCredits($request));

        return redirect()->route('admin.packages.index')->with('status', 'Pacote atualizado.');
    }

    public function destroy(CreditPackage $package): RedirectResponse
    {
        // Remover o preço da avulsa deixaria a jornada pública indisponível.
        if ($package->isGuestKind()) {
            return redirect()->route('admin.packages.index')
                ->withErrors(['package' => 'O preço da investigação avulsa é editável, não removível.']);
        }

        $package->delete();

        return redirect()->route('admin.packages.index')->with('status', 'Pacote removido.');
    }

    private function validatedCredits(Request $request): array
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
