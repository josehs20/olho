<?php

namespace Database\Factories;

use App\Models\CreditReservation;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditReservation>
 */
class CreditReservationFactory extends Factory
{
    protected $model = CreditReservation::class;

    public function definition(): array
    {
        return [
            'wallet_id' => Wallet::factory(),
            'user_id' => User::factory(),
            'investigation_run_id' => null,
            'amount' => 10,
            'status' => 'pending',
            'idempotency_key' => fake()->uuid(),
        ];
    }
}
