<?php

namespace Database\Factories;

use App\Models\Investigation;
use App\Models\InvestigationRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvestigationRun>
 */
class InvestigationRunFactory extends Factory
{
    protected $model = InvestigationRun::class;

    public function definition(): array
    {
        return [
            'investigation_id' => Investigation::factory(),
            'run_number' => 1,
            'status' => InvestigationRun::STATUS_PENDING,
            'credits_reserved' => 0,
            'credits_consumed' => 0,
        ];
    }

    public function running(): static
    {
        return $this->state(fn () => [
            'status' => InvestigationRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => InvestigationRun::STATUS_COMPLETED,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
    }

    public function partial(): static
    {
        return $this->state(fn () => [
            'status' => InvestigationRun::STATUS_PARTIAL,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => InvestigationRun::STATUS_FAILED,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
    }
}
