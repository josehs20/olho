<?php

namespace Database\Factories;

use App\Models\CreditTransaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditTransaction>
 */
class CreditTransactionFactory extends Factory
{
    protected $model = CreditTransaction::class;

    public function definition(): array
    {
        return [
            'wallet_id' => Wallet::factory(),
            'user_id' => User::factory(),
            'type' => 'purchase_credit',
            'amount' => 100,
            'balance_before' => 0,
            'balance_after' => 100,
            'idempotency_key' => fake()->uuid(),
            'description' => fake()->sentence(),
        ];
    }
}
