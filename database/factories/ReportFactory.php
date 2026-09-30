<?php

namespace Database\Factories;

use App\Models\InvestigationRun;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    public function definition(): array
    {
        return [
            'investigation_run_id' => InvestigationRun::factory(),
            'user_id' => User::factory(),
            'status' => 'pending',
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'generated_at' => now(),
        ]);
    }
}
