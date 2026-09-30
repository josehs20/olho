<?php

namespace App\Jobs;

use App\Models\InvestigationRun;
use App\Models\SourceResult;
use App\Services\ResultDeduplicator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class NormalizeSourceResultsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $investigationRunId) {}

    public function handle(ResultDeduplicator $deduplicator): void
    {
        $run = InvestigationRun::findOrFail($this->investigationRunId);

        if ($run->pipelineStage('normalized_at')) {
            DeduplicateResultsJob::dispatch($run->id);

            return;
        }

        // Reconciliação: garante hashes da versão corrente em todas as linhas
        // (o normalizador já grava a chave; aqui cobrimos resultados legados ou
        // gravados por uma versão anterior da chave).
        $results = SourceResult::query()
            ->whereHas('sourceRun', fn ($q) => $q->where('investigation_run_id', $run->id))
            ->get();

        foreach ($results as $result) {
            if ($result->dedup_hash === null || ($result->metadata['dedup_key_version'] ?? null) !== ResultDeduplicator::KEY_VERSION) {
                $deduplicator->stamp($result);
                $result->save();
            }
        }

        $run->markPipelineStage('normalized_at');

        DeduplicateResultsJob::dispatch($run->id);
    }
}
