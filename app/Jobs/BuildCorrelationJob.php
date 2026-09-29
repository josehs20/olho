<?php

namespace App\Jobs;

use App\Models\InvestigationRun;
use App\Services\CorrelationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BuildCorrelationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $investigationRunId) {}

    public function handle(CorrelationService $correlation): void
    {
        $run = InvestigationRun::findOrFail($this->investigationRunId);

        if ($run->pipelineStage('correlated_at')) {
            BuildHtmlReportJob::dispatch($run->id);

            return;
        }

        $correlation->persist($run);

        $run->markPipelineStage('correlated_at');

        BuildHtmlReportJob::dispatch($run->id);
    }
}
