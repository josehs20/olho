<?php

namespace Database\Factories;

use App\Models\CreditPackage;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'credit_package_id' => CreditPackage::factory(),
            'provider' => 'fake',
            'external_id' => fake()->uuid(),
            'amount_cents' => 5000,
            'status' => 'pending',
            'idempotency_key' => fake()->uuid(),
        ];
    }
}
