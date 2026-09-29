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

        $results = SourceResult::query()
            ->whereHas('sourceRun', fn ($q) => $q->where('investigation_run_id', $run->id))
            ->whereNull('dedup_hash')
            ->get();

        foreach ($results as $result) {
            $result->dedup_hash = $deduplicator->dedupHash($result);
            $result->save();
        }

        $run->markPipelineStage('normalized_at');

        DeduplicateResultsJob::dispatch($run->id);
    }
}
