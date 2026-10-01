<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdminSourceCostRequest;
use App\Models\AuditLog;
use App\Models\SourceDefinition;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SourceController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        // Última alteração de custo por fonte (prompt 43): quem alterou, quando e
        // os valores anterior/novo — reaproveitando a auditoria já existente.
        $lastCostChanges = AuditLog::query()
            ->where('action', 'admin.source_cost_update')
            ->where('subject_type', SourceDefinition::class)
            ->with('user')
            ->latest('id')
            ->get()
            ->unique('subject_id')
            ->keyBy('subject_id');

        return view('admin.sources.index', [
            'sources' => SourceDefinition::query()->orderBy('name')->get(),
            'lastCostChanges' => $lastCostChanges,
        ]);
    }

    public function toggle(SourceDefinition $source): RedirectResponse
    {
        $source->active = ! $source->active;
        $source->save();

        return back()->with('status', 'Fonte atualizada.');
    }

    /**
     * Custo em créditos por requisição (prompt 43).
     *
     * O valor persiste em `source_definitions.cost_credits` e passa a valer para
     * as próximas execções (reserva/estorno continuam usando o snapshot gravado
     * em `credit_reservations.amount`, então runs antigos nunca são recalculados).
     * Toda alteração é auditada com o valor anterior.
     */
    public function updateCost(AdminSourceCostRequest $request, SourceDefinition $source): RedirectResponse
    {
        $previous = (int) $source->cost_credits;
        $cost = (int) $request->integer('cost_credits');

        if ($previous !== $cost) {
            $source->cost_credits = $cost;
            $source->save();

            $this->audit->log(
                $request->user(),
                'admin.source_cost_update',
                $source,
                [
                    'slug' => $source->slug,
                    'previous_cost_credits' => $previous,
                    'new_cost_credits' => $cost,
                ],
                $request->ip(),
            );
        }

        return back()->with('status', 'Custo de créditos atualizado.');
    }
}
