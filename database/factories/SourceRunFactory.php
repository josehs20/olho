<?php

namespace Database\Factories;

use App\Models\InvestigationRun;
use App\Models\SourceDefinition;
use App\Models\SourceRun;
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
            'status' => 'pending',
            'attempt' => 1,
        ];
    }
}
