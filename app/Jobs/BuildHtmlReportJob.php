<?php

namespace App\Jobs;

use App\Models\InvestigationRun;
use App\Services\InvestigationOrchestrator;
use App\Services\LogSanitizer;
use App\Services\ReportManifestBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class BuildHtmlReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $investigationRunId) {}

    public function handle(
        InvestigationOrchestrator $orchestrator,
        LogSanitizer $sanitizer,
        ReportManifestBuilder $manifests,
    ): void {
        $run = InvestigationRun::findOrFail($this->investigationRunId);

        if ($run->pipelineStage('reported_at')) {
            ReleaseReservedCreditsJob::dispatch($run->id);

            return;
        }

        // Manifesto congelado do run (prompt 38): o relatório HTML passa a ser
        // uma leitura dos dados persistidos, sem depender do estado vivo das
        // fontes (um rename de módulo não altera um relatório já emitido).
        $manifest = $manifests->build($run);

        $run->report()->updateOrCreate(
            ['investigation_run_id' => $run->id],
            [
                'user_id' => $run->investigation->user_id,
                'status' => 'completed',
                'content_hash' => $manifests->contentHash($manifest),
                'metadata' => $manifest,
                'generated_at' => now(),
            ],
        );

        $run->markPipelineStage('reported_at');

        // (11) Finaliza o run como COMPLETED, PARTIAL ou FAILED, registrando a
        // decisão (status, motivo e contagem por status de fonte) para auditoria.
        $finalization = $orchestrator->finalize($run);

        Log::channel('investigations')->info('run.finalized', $sanitizer->sanitize([
            'investigation_run_id' => $run->id,
            'status' => $finalization['status'],
            'reason' => $finalization['reason'],
            'counts' => $finalization['counts'],
            'credits_reserved' => $run->credits_reserved,
        ]));

        ReleaseReservedCreditsJob::dispatch($run->id);
    }
}
