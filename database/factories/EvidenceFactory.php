<?php

namespace Database\Factories;

use App\Models\Evidence;
use App\Models\SourceResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Evidence>
 */
class EvidenceFactory extends Factory
{
    protected $model = Evidence::class;

    public function definition(): array
    {
        return [
            'source_result_id' => SourceResult::factory(),
            'type' => 'username_match',
            'value' => fake()->userName(),
        ];
    }
}
