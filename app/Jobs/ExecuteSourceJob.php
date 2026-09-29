<?php

namespace App\Jobs;

use App\Models\SourceDefinition;
use App\Models\SourceRun;
use App\Sources\Exceptions\SourceException;
use App\Sources\InvestigationContext;
use App\Sources\InvestigationInput;
use App\Sources\SourceErrorMapper;
use App\Sources\SourceRegistry;
use App\Sources\SourceResult;
use App\Sources\SourceRunStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExecuteSourceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [10, 60];

    public int $timeout = 60;

    public function __construct(public int $sourceRunId) {}

    public function handle(SourceRegistry $registry): void
    {
        $sourceRun = SourceRun::findOrFail($this->sourceRunId);

        if ($sourceRun->status !== SourceRunStatus::Pending->value) {
            return;
        }

        $sourceRun->status = SourceRunStatus::Running->value;
        $sourceRun->attempt = $sourceRun->attempt + 1;
        $sourceRun->started_at = now();
        $sourceRun->save();

        $started = microtime(true);

        try {
            $definition = $sourceRun->sourceDefinition;
            $adapter = $registry->get($definition->slug);

            $run = $sourceRun->investigationRun;
            $input = InvestigationInput::fromArray($run->input_snapshot ?? []);
            $context = new InvestigationContext($input, $run->id, $sourceRun->id);

            $results = $adapter->execute($context);

            foreach ($results as $result) {
                $this->persistResult($sourceRun, $definition, $result);
            }

            $this->finish($sourceRun, SourceRunStatus::Completed, null, null, $started);
        } catch (SourceException $e) {
            $this->finish(
                $sourceRun,
                SourceErrorMapper::toRunStatus($e),
                SourceErrorMapper::toErrorCode($e),
                $e->getMessage(),
                $started,
            );
        }

        $this->continueRunIfDone($sourceRun->investigation_run_id);
    }

    private function persistResult(SourceRun $sourceRun, SourceDefinition $definition, SourceResult $result): void
    {
        $model = $sourceRun->sourceResults()->create([
            'source_definition_id' => $definition->id,
            'entity_type' => $result->entityType,
            'value' => $result->value,
            'url' => $result->url,
            'title' => $result->title,
            'description' => $result->description,
            'confidence' => $result->confidence,
            'raw_payload' => [
                'evidence' => $result->evidence,
                'metadata' => $result->metadata,
            ],
            'observed_at' => now(),
        ]);

        foreach ($result->evidence as $evidence) {
            $model->evidence()->create([
                'type' => $evidence['type'] ?? 'reference',
                'value' => $evidence['value'] ?? null,
            ]);
        }
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
