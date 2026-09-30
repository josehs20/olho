<?php

namespace Database\Factories;

use App\Models\CreditPackage;
use App\Models\Payment;
use App\Models\User;
use App\Payments\PaymentStatus;
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
            'status' => PaymentStatus::Pending->value,
            'idempotency_key' => fake()->uuid(),
        ];
    }

    /**
     * Cobrança paga (com data de pagamento).
     */
    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Paid->value,
            'paid_at' => now(),
        ]);
    }

    /**
     * Cobrança aguardando o PIX.
     */
    public function awaitingPayment(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::AwaitingPayment->value,
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    /**
     * Cobrança expirada sem pagamento.
     */
    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Expired->value,
            'expires_at' => now()->subMinutes(5),
        ]);
    }
}
