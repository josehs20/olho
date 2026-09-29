<?php

namespace App\Jobs;

use App\Models\SourceDefinition;
use App\Models\SourceRun;
use App\Services\SourceCache;
use App\Services\SourceResultNormalizer;
use App\Sources\Exceptions\SourceException;
use App\Sources\InvestigationContext;
use App\Sources\InvestigationInput;
use App\Sources\SourceErrorMapper;
use App\Sources\SourceRegistry;
use App\Sources\SourceResult;
use App\Sources\SourceRunStatus;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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

    public function handle(SourceRegistry $registry, SourceCache $cache): void
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

            $results = $cache->remember($adapter, $input, fn () => $adapter->execute($context));

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
        $normalizer = app(SourceResultNormalizer::class);

        $model = $sourceRun->sourceResults()->create(array_merge(
            ['source_definition_id' => $definition->id],
            $normalizer->attributes($result),
        ));

        foreach ($normalizer->evidenceAttributes($result) as $evidence) {
            $model->evidence()->create($evidence);
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
