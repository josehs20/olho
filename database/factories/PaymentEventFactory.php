<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\PaymentEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentEvent>
 */
class PaymentEventFactory extends Factory
{
    protected $model = PaymentEvent::class;

    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'external_event_id' => fake()->uuid(),
            'type' => 'payment.updated',
            'status' => 'paid',
        ];
    }
}
