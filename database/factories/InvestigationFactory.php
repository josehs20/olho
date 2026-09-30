<?php

namespace Database\Factories;

use App\Models\Investigation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Investigation>
 */
class InvestigationFactory extends Factory
{
    protected $model = Investigation::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->words(3, true),
            'status' => Investigation::STATUS_DRAFT,
        ];
    }

    public function queued(): static
    {
        return $this->state(fn () => ['status' => Investigation::STATUS_QUEUED]);
    }

    public function running(): static
    {
        return $this->state(fn () => ['status' => Investigation::STATUS_RUNNING]);
    }

    public function completed(): static
    {
        return $this->state(fn () => ['status' => Investigation::STATUS_COMPLETED]);
    }

    public function partial(): static
    {
        return $this->state(fn () => ['status' => Investigation::STATUS_PARTIAL]);
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status' => Investigation::STATUS_FAILED]);
    }
}
