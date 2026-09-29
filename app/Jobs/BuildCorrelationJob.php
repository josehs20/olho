<?php

namespace App\Jobs;

use App\Models\InvestigationRun;
use App\Models\SourceResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BuildCorrelationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $investigationRunId) {}

    public function handle(): void
    {
        $run = InvestigationRun::findOrFail($this->investigationRunId);

        if ($run->pipelineStage('correlated_at')) {
            BuildHtmlReportJob::dispatch($run->id);

            return;
        }

        // Correlação básica (expandida na etapa 16): fontes distintas com correspondência.
        $results = SourceResult::query()
            ->whereHas('sourceRun', fn ($q) => $q->where('investigation_run_id', $run->id))
            ->get();

        $distinctSources = $results
            ->filter(fn (SourceResult $r) => empty($r->metadata['duplicate_of']))
            ->pluck('source_definition_id')
            ->unique()
            ->count();

        $metadata = $run->metadata ?? [];
        $metadata['correlation'] = [
            'score' => $distinctSources,
            'rule_version' => 'v0',
        ];
        $run->metadata = $metadata;
        $run->save();

        $run->markPipelineStage('correlated_at');

        BuildHtmlReportJob::dispatch($run->id);
    }
}
