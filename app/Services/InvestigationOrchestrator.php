<?php

namespace App\Services;

use App\Models\InvestigationRun;
use App\Models\SourceDefinition;
use App\Models\SourceRun;
use App\Models\User;
use App\Models\Wallet;
use App\Sources\InvestigationInput;
use App\Sources\SourceRegistry;
use App\Sources\SourceRunStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Regras de orquestração de um run de investigação.
 *
 * Concentra o que é decisão de negócio (e não de transporte): quais módulos são
 * aplicáveis à entrada informada, como os registros de execução por módulo são
 * criados e qual o desfecho final do run (COMPLETED / PARTIAL / FAILED).
 *
 * Os jobs apenas executam as etapas e chamam este serviço; controllers não
 * contêm lógica de negócio.
 */
class InvestigationOrchestrator
{
    public function __construct(private readonly SourceRegistry $registry) {}

    /**
     * Divide os módulos selecionados entre aplicáveis e não aplicáveis.
     *
     * @param  Collection<int, SourceDefinition>  $definitions
     * @return array{applicable: Collection<int, SourceDefinition>, skipped: Collection<int, SourceDefinition>}
     */
    public function split(Collection $definitions, InvestigationInput $input): array
    {
        [$applicable, $skipped] = $definitions->partition(
            fn (SourceDefinition $definition) => $this->isApplicable($definition, $input),
        );

        return [
            'applicable' => $applicable->values(),
            'skipped' => $skipped->values(),
        ];
    }

    /**
     * Um módulo é aplicável quando suporta algum dos identificadores informados.
     */
    public function isApplicable(SourceDefinition $definition, InvestigationInput $input): bool
    {
        // O adapter é a fonte da verdade quando existe (contrato `supports()`).
        if ($this->registry->has($definition->slug)) {
            return $this->registry->get($definition->slug)->supports($input);
        }

        // Sem adapter registrado, decide pelo tipo declarado na fonte, para que
        // a execução ainda aconteça e falhe de forma estruturada (`unavailable`).
        return match ($definition->type) {
            'username' => $input->username !== null,
            'email' => $input->email !== null,
            'phone' => $input->phone !== null,
            'web' => $input->name !== null || $input->username !== null,
            default => true,
        };
    }

    /**
     * Cria um registro de execução por módulo.
     *
     * Aplicáveis ficam `pending` (a executar); não aplicáveis ficam `skipped`
     * com aviso público, de modo que a limitação fique observável no run em vez
     * de rejeitar a investigação inteira.
     *
     * @param  Collection<int, SourceDefinition>  $applicable
     * @param  Collection<int, SourceDefinition>  $skipped
     * @return Collection<int, SourceRun> registros pendentes de execução
     */
    public function createSourceRuns(InvestigationRun $run, Collection $applicable, Collection $skipped): Collection
    {
        $pending = $applicable->map(fn (SourceDefinition $definition) => $run->sourceRuns()->create([
            'investigation_id' => $run->investigation_id,
            'source' => $definition->slug,
            'source_definition_id' => $definition->id,
            'status' => SourceRunStatus::Pending->value,
        ]));

        foreach ($skipped as $definition) {
            $run->sourceRuns()->create([
                'investigation_id' => $run->investigation_id,
                'source' => $definition->slug,
                'source_definition_id' => $definition->id,
                'status' => SourceRunStatus::Skipped->value,
                'error_code' => 'invalid_input',
                'error_message_public' => "A fonte '{$definition->name}' não é aplicável aos dados informados.",
                'finished_at' => now(),
            ]);
        }

        return $pending;
    }

    /**
     * Reserva os créditos dos módulos aplicáveis, **um por módulo** (prompt 39).
     *
     * A regra de cobrança é por módulo executado: cada reserva carrega o slug da
     * fonte e uma chave de idempotência estável (`run-{id}-source-{slug}-reserve`).
     * Assim, um retry do job de abertura não debita de novo, e o encerramento
     * pode consumir só os módulos concluídos e estornar os que falharam.
     *
     * @param  Collection<int, SourceDefinition>  $applicable
     */
    public function reserveCredits(
        CreditLedgerService $credits,
        User $user,
        Wallet $wallet,
        InvestigationRun $run,
        Collection $applicable,
        ?Carbon $expiresAt = null,
    ): Collection {
        return DB::transaction(function () use ($credits, $user, $wallet, $run, $applicable, $expiresAt) {
            return $applicable
                ->filter(fn (SourceDefinition $definition) => (int) $definition->cost_credits > 0)
                ->map(fn (SourceDefinition $definition) => $credits->reserve(
                    $user,
                    $wallet,
                    (int) $definition->cost_credits,
                    $run,
                    "run-{$run->id}-source-{$definition->slug}-reserve",
                    $expiresAt,
                    $definition->slug,
                ))
                ->values();
        });
    }

    /**
     * Decide e persiste o desfecho do run a partir do status das fontes.
     *
     * Regra (docs/23): o sistema continua útil quando uma fonte está
     * indisponível — o run é `partial`. Sem nenhuma fonte concluída, nada foi
     * entregue ao usuário e o run é `failed` (reserva compensada).
     *
     * @return array{status: string, reason: string, counts: array<string, int>}
     */
    public function finalize(InvestigationRun $run): array
    {
        $counts = SourceRun::query()
            ->where('investigation_run_id', $run->id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total)
            ->all();

        $completed = $counts[SourceRunStatus::Completed->value] ?? 0;
        $failed = ($counts[SourceRunStatus::Failed->value] ?? 0) + ($counts[SourceRunStatus::Timeout->value] ?? 0);
        $skipped = $counts[SourceRunStatus::Skipped->value] ?? 0;

        [$status, $reason] = match (true) {
            $completed > 0 && $failed > 0 => [InvestigationRun::STATUS_PARTIAL, 'some_sources_failed'],
            $completed > 0 => [InvestigationRun::STATUS_COMPLETED, 'all_sources_completed'],
            $failed > 0 => [InvestigationRun::STATUS_FAILED, 'all_sources_failed'],
            default => [InvestigationRun::STATUS_FAILED, 'no_source_executed'],
        };

        return $this->writeFinalization($run, $status, $reason, [
            'completed' => $completed,
            'failed' => $failed,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Encerra o run antes de qualquer execução de fonte (créditos insuficientes
     * ou nenhum módulo aplicável), mantendo o motivo observável.
     *
     * @return array{status: string, reason: string, counts: array<string, int>}
     */
    public function markFailed(InvestigationRun $run, string $reason, ?string $summary = null): array
    {
        if ($summary !== null) {
            $run->error_summary = $summary;
        }

        return $this->writeFinalization($run, InvestigationRun::STATUS_FAILED, $reason, []);
    }

    /**
     * @param  array<string, int>  $counts
     * @return array{status: string, reason: string, counts: array<string, int>}
     */
    private function writeFinalization(InvestigationRun $run, string $status, string $reason, array $counts): array
    {
        $finalization = [
            'status' => $status,
            'reason' => $reason,
            'counts' => $counts,
            'decided_at' => now()->toISOString(),
        ];

        $metadata = $run->metadata ?? [];
        $metadata['finalization'] = $finalization;

        $run->metadata = $metadata;
        $run->status = $status;
        $run->finished_at = now();
        $run->save();

        $investigation = $run->investigation;
        $investigation->status = $status;
        $investigation->save();

        return $finalization;
    }
}
