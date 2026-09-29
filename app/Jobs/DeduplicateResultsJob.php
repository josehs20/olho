<?php

namespace App\Jobs;

use App\Models\InvestigationRun;
use App\Services\ResultDeduplicator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeduplicateResultsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $investigationRunId) {}

    public function handle(ResultDeduplicator $deduplicator): void
    {
        $run = InvestigationRun::findOrFail($this->investigationRunId);

        if ($run->pipelineStage('deduplicated_at')) {
            BuildCorrelationJob::dispatch($run->id);

            return;
        }

        $deduplicator->deduplicate($run);

        $run->markPipelineStage('deduplicated_at');

        BuildCorrelationJob::dispatch($run->id);
    }
}
