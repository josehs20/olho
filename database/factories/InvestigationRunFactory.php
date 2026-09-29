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
            'status' => 'pending',
            'credits_reserved' => 0,
            'credits_consumed' => 0,
        ];
    }
}
