<?php

namespace Database\Factories;

use App\Models\InvestigationRun;
use App\Models\SourceDefinition;
use App\Models\SourceRun;
use App\Sources\SourceRunStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourceRun>
 */
class SourceRunFactory extends Factory
{
    protected $model = SourceRun::class;

    public function definition(): array
    {
        return [
            'investigation_run_id' => InvestigationRun::factory(),
            'source_definition_id' => SourceDefinition::factory(),
            'status' => SourceRunStatus::Pending->value,
            'attempts' => 0,
            'result_count' => 0,
        ];
    }

    public function skipped(): static
    {
        return $this->state(fn () => [
            'status' => SourceRunStatus::Skipped->value,
            'attempts' => 0,
            'result_count' => 0,
            'error_code' => 'invalid_input',
            'error_message_public' => 'A entrada informada não é válida para esta fonte.',
            'finished_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => SourceRunStatus::Completed->value,
            'started_at' => now()->subSeconds(2),
            'finished_at' => now(),
            'duration_ms' => 2000,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => SourceRunStatus::Failed->value,
            'error_code' => 'source_unavailable',
            'error_message_public' => 'Fonte indisponível no momento.',
            'started_at' => now()->subSeconds(2),
            'finished_at' => now(),
        ]);
    }

    public function timeout(): static
    {
        return $this->state(fn () => [
            'status' => SourceRunStatus::Timeout->value,
            'error_code' => 'source_timeout',
            'error_message_public' => 'A fonte excedeu o tempo limite.',
            'started_at' => now()->subSeconds(2),
            'finished_at' => now(),
        ]);
    }
}
