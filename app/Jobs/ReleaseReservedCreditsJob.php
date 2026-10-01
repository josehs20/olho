<?php

namespace App\Jobs;

use App\Models\CreditReservation;
use App\Models\InvestigationRun;
use App\Services\CreditLedgerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Encerra a cobrança do run (prompt 39).
 *
 * Regra: o usuário paga **pela consulta executada**, não pelo resultado. Cada
 * reserva criada na abertura do run é consumida (módulo efetivamente consultado,
 * com ou sem resultado) ou estornada (módulo nunca consultado). Toda operação é
 * idempotente por reserva (`run-consume-{id}` / `run-release-{id}`) e protegida
 * pela etapa `credits_released_at` do pipeline.
 */
class ReleaseReservedCreditsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 120;

    public function __construct(public int $investigationRunId) {}

    public function handle(CreditLedgerService $credits): void
    {
        $run = InvestigationRun::findOrFail($this->investigationRunId);

        if ($run->pipelineStage('credits_released_at')) {
            return;
        }

        $run->loadMissing(['sourceRuns', 'investigation']);

        // Investigação avulsa (prompt 41): preço fixo pago, sem créditos — não há
        // reserva a consumir ou estornar. A etapa fica registrada para manter o
        // pipeline observável. A regra das contas (pós-39) permanece intacta.
        // O custo interno executado é registrado nos metadados (prompt 43) para
        // controle/auditoria, sem movimentar wallet ou ledger.
        if ($run->investigation?->isGuest()) {
            $metadata = $run->metadata ?? [];
            data_set($metadata, 'credit_cost.executed_credits', $this->executedCost($run));
            data_set($metadata, 'credit_cost.executed_at', now()->toISOString());
            $run->metadata = $metadata;
            $run->save();

            $run->markPipelineStage('credits_released_at');

            return;
        }

        $reservations = $run->creditReservations()->where('status', 'pending')->get();

        foreach ($reservations as $reservation) {
            if ($this->isChargeable($run, $reservation)) {
                $credits->consume($reservation, 'run-consume-'.$reservation->id);
                $run->credits_consumed += $reservation->amount;
            } else {
                $credits->release($reservation, 'run-release-'.$reservation->id);
            }
        }

        $run->save();
        $run->markPipelineStage('credits_released_at');
    }

    /**
     * Cobrança: paga-se pela **consulta executada**, não pelo resultado.
     *
     * Um módulo efetivamente consultado é cobrado mesmo sem resultado — e também
     * quando a consulta falhou ou estourou o tempo limite (a consulta aconteceu).
     * Só é estornado o que **nunca foi consultado**: módulo não aplicável
     * (`skipped`), reserva sem execução (`pending`/`running`, incluindo worker
     * interrompido) ou módulo que não chegou a ser criado no run.
     */
    private function isChargeable(InvestigationRun $run, CreditReservation $reservation): bool
    {
        // Reserva legada (uma por run, sem módulo): mantém o comportamento anterior.
        if ($reservation->source === null) {
            return in_array($run->status, [InvestigationRun::STATUS_COMPLETED, InvestigationRun::STATUS_PARTIAL], true);
        }

        $sourceRun = $run->sourceRuns->firstWhere('source', $reservation->source);

        return $sourceRun !== null && (int) $sourceRun->attempts > 0;
    }

    /**
     * Custo interno efetivamente executado de um run sem wallet (avulsa).
     *
     * Soma o snapshot por módulo gravado na abertura (`metadata.credit_cost.
     * breakdown`) apenas para módulos consultados (`attempts > 0`) — a mesma
     * regra de cobrança das contas. Runs anteriores ao snapshot usam o custo
     * atual da fonte como fallback. Informativo: nenhum wallet/ledger é movido.
     */
    private function executedCost(InvestigationRun $run): int
    {
        $breakdown = data_get($run->metadata, 'credit_cost.breakdown', []);
        $breakdown = is_array($breakdown) ? $breakdown : [];

        $total = 0;

        foreach ($run->sourceRuns as $sourceRun) {
            if ((int) $sourceRun->attempts <= 0) {
                continue;
            }

            $total += (int) ($breakdown[$sourceRun->source]
                ?? $sourceRun->sourceDefinition?->cost_credits
                ?? 0);
        }

        return $total;
    }
}
