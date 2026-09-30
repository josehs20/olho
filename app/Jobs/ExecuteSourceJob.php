<?php

namespace App\Jobs;

use App\Models\SourceDefinition;
use App\Models\SourceRun;
use App\Services\LogSanitizer;
use App\Services\SourceCache;
use App\Services\SourceResultNormalizer;
use App\Sources\Exceptions\SourceException;
use App\Sources\InvestigationContext;
use App\Sources\InvestigationInput;
use App\Sources\SourceDescriptor;
use App\Sources\SourceErrorMapper;
use App\Sources\SourceOutcome;
use App\Sources\SourceRegistry;
use App\Sources\SourceResult;
use App\Sources\SourceRunStatus;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ExecuteSourceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [10, 60];

    public int $timeout = 60;

    public function __construct(public int $sourceRunId) {}

    public function uniqueId(): string
    {
        return 'execute-source-'.$this->sourceRunId;
    }

    public function handle(
        SourceRegistry $registry,
        SourceCache $cache,
        SourceResultNormalizer $normalizer,
        LogSanitizer $sanitizer,
    ): void {
        $sourceRun = SourceRun::findOrFail($this->sourceRunId);

        $status = SourceRunStatus::tryFrom($sourceRun->status);

        // Terminal (ou desconhecido) já processado: nada a fazer (idempotência).
        if (! in_array($status, [SourceRunStatus::Pending, SourceRunStatus::Running], true)) {
            return;
        }

        // `running` indica tentativa anterior interrompida (job falhou/retry).
        // Descarta os resultados parciais para a reexecução convergir em um
        // único conjunto — sem isso a fonte ficaria presa e o run nunca
        // finalizaria (reserva de crédito pendente).
        if ($status === SourceRunStatus::Running) {
            $sourceRun->sourceResults()->delete();
        }

        $sourceRun->status = SourceRunStatus::Running->value;
        $sourceRun->attempts = $sourceRun->attempts + 1;
        $sourceRun->started_at = now();
        $sourceRun->save();

        $started = microtime(true);

        try {
            $definition = $sourceRun->sourceDefinition;
            $adapter = $registry->get($definition->slug);

            $run = $sourceRun->investigationRun;
            $input = InvestigationInput::fromArray($run->input_snapshot ?? []);
            $context = new InvestigationContext(
                input: $input,
                investigationRunId: $run->id,
                sourceRunId: $sourceRun->id,
                source: new SourceDescriptor(
                    slug: $definition->slug,
                    type: $definition->type,
                    timeout: (int) $definition->timeout,
                    config: $definition->config ?? [],
                ),
                correlationId: 'run:'.$run->id.':source:'.$definition->slug,
            );

            $outcome = $cache->remember($adapter, $input, fn () => $adapter->execute($context));

            foreach ($outcome->findings as $result) {
                $this->persistResult($sourceRun, $definition, $result, $normalizer);
            }

            $this->recordOutcome($sourceRun, $outcome);

            $this->finish($sourceRun, SourceRunStatus::Completed, null, null, $started);
        } catch (SourceException $e) {
            $this->finish(
                $sourceRun,
                SourceErrorMapper::toRunStatus($e),
                SourceErrorMapper::toErrorCode($e),
                SourceErrorMapper::toPublicMessage($e),
                $started,
            );

            // Detalhe técnico apenas no log sanitizado (não na base).
            Log::channel('investigations')->warning('source.failed', $sanitizer->sanitize([
                'source_run_id' => $sourceRun->id,
                'investigation_run_id' => $sourceRun->investigation_run_id,
                'error_code' => SourceErrorMapper::toErrorCode($e),
                'detail' => $e->getMessage(),
            ]));
        }

        Log::channel('investigations')->info('source.executed', $sanitizer->sanitize([
            'source_run_id' => $sourceRun->id,
            'investigation_run_id' => $sourceRun->investigation_run_id,
            'status' => $sourceRun->status,
            'error_code' => $sourceRun->error_code,
            'duration_ms' => $sourceRun->duration_ms,
        ]));

        $this->continueRunIfDone($sourceRun->investigation_run_id);
    }

    private function persistResult(
        SourceRun $sourceRun,
        SourceDefinition $definition,
        SourceResult $result,
        SourceResultNormalizer $normalizer,
    ): void {
        $model = $sourceRun->sourceResults()->create(array_merge(
            ['source_definition_id' => $definition->id],
            $normalizer->attributes($result),
        ));

        foreach ($normalizer->evidenceAttributes($result) as $evidence) {
            $model->evidence()->create($evidence);
        }
    }

    /**
     * Persiste o que a execução produziu (prompt 31): contagem de resultados,
     * versão da ferramenta e avisos/metadados públicos do adapter.
     */
    private function recordOutcome(SourceRun $sourceRun, SourceOutcome $outcome): void
    {
        $sourceRun->result_count = count($outcome->findings);
        $sourceRun->tool_version = $this->toolVersion($outcome);

        $metrics = $sourceRun->metrics ?? [];

        if ($outcome->warnings !== []) {
            $metrics['warnings'] = array_slice($outcome->warnings, 0, 20);
        }

        if ($outcome->metadata !== []) {
            $metrics['adapter'] = $outcome->metadata;
        }

        $sourceRun->metrics = $metrics;
    }

    private function toolVersion(SourceOutcome $outcome): ?string
    {
        $version = $outcome->metadata['tool_version'] ?? null;

        return is_scalar($version) ? (string) $version : null;
    }

    private function finish(SourceRun $sourceRun, SourceRunStatus $status, ?string $errorCode, ?string $errorMessage, float $started): void
    {
        $sourceRun->status = $status->value;
        $sourceRun->error_code = $errorCode;
        $sourceRun->error_message_public = $errorMessage;
        $sourceRun->finished_at = now();
        $sourceRun->duration_ms = (int) round((microtime(true) - $started) * 1000);
        $sourceRun->save();
    }

    private function continueRunIfDone(int $investigationRunId): void
    {
        $incomplete = SourceRun::query()
            ->where('investigation_run_id', $investigationRunId)
            ->whereIn('status', [SourceRunStatus::Pending->value, SourceRunStatus::Running->value])
            ->exists();

        if (! $incomplete) {
            NormalizeSourceResultsJob::dispatch($investigationRunId);
        }
    }
}
