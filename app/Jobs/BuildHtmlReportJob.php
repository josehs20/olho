<?php

namespace App\Jobs;

use App\Models\Investigation;
use App\Models\InvestigationRun;
use App\Models\SourceRun;
use App\Sources\SourceRunStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BuildHtmlReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $investigationRunId) {}

    public function handle(): void
    {
        $run = InvestigationRun::findOrFail($this->investigationRunId);

        if ($run->pipelineStage('reported_at')) {
            ReleaseReservedCreditsJob::dispatch($run->id);

            return;
        }

        // Relatório básico (expandido na etapa 18): registra a linha de report.
        $run->report()->updateOrCreate(
            ['investigation_run_id' => $run->id],
            [
                'user_id' => $run->investigation->user_id,
                'status' => 'completed',
                'generated_at' => now(),
            ],
        );

        $run->markPipelineStage('reported_at');

        $this->finalizeRunStatus($run);

        ReleaseReservedCreditsJob::dispatch($run->id);
    }

    private function finalizeRunStatus(InvestigationRun $run): void
    {
        $hasFailure = SourceRun::query()
            ->where('investigation_run_id', $run->id)
            ->whereIn('status', [SourceRunStatus::Failed->value, SourceRunStatus::Timeout->value])
            ->exists();

        $run->status = $hasFailure ? InvestigationRun::STATUS_PARTIAL : InvestigationRun::STATUS_COMPLETED;
        $run->finished_at = now();
        $run->save();

        $investigation = $run->investigation;
        $investigation->status = $hasFailure ? Investigation::STATUS_PARTIAL : Investigation::STATUS_COMPLETED;
        $investigation->save();
    }
}
