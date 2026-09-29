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
            'status' => 'draft',
        ];
    }
}
