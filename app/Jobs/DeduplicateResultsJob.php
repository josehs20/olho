<?php

namespace App\Jobs;

use App\Models\InvestigationRun;
use App\Models\SourceResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeduplicateResultsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $investigationRunId) {}

    public function handle(): void
    {
        $run = InvestigationRun::findOrFail($this->investigationRunId);

        if ($run->pipelineStage('deduplicated_at')) {
            BuildCorrelationJob::dispatch($run->id);

            return;
        }

        $results = SourceResult::query()
            ->whereHas('sourceRun', fn ($q) => $q->where('investigation_run_id', $run->id))
            ->whereNotNull('dedup_hash')
            ->orderBy('id')
            ->get();

        foreach ($results->groupBy('dedup_hash') as $group) {
            if ($group->count() < 2) {
                continue;
            }

            $first = $group->shift();

            foreach ($group as $duplicate) {
                $metadata = $duplicate->metadata ?? [];
                $metadata['duplicate_of'] = $first->id;
                $duplicate->metadata = $metadata;
                $duplicate->save();
            }
        }

        $run->markPipelineStage('deduplicated_at');

        BuildCorrelationJob::dispatch($run->id);
    }
}
