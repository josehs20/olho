<?php

namespace Database\Factories;

use App\Models\CreditPackage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditPackage>
 */
class CreditPackageFactory extends Factory
{
    protected $model = CreditPackage::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'credits' => fake()->numberBetween(10, 1000),
            'price_cents' => fake()->numberBetween(1000, 100000),
            'active' => true,
            'display_order' => 0,
        ];
    }
}
