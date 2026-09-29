<?php

namespace Database\Factories;

use App\Models\SourceDefinition;
use App\Models\SourceResult;
use App\Models\SourceRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourceResult>
 */
class SourceResultFactory extends Factory
{
    protected $model = SourceResult::class;

    public function definition(): array
    {
        return [
            'source_run_id' => SourceRun::factory(),
            'source_definition_id' => SourceDefinition::factory(),
            'entity_type' => 'social_profile',
            'value' => fake()->userName(),
            'url' => fake()->url(),
        ];
    }
}
