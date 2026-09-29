<?php

namespace Database\Factories;

use App\Models\SourceDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourceDefinition>
 */
class SourceDefinitionFactory extends Factory
{
    protected $model = SourceDefinition::class;

    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'type' => 'username',
            'active' => true,
            'cost_credits' => 1,
            'timeout' => 30,
        ];
    }
}
