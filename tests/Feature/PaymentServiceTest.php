<?php

namespace Tests\Feature;

use App\Models\CreditPackage;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Models\Wallet;
use App\Payments\PaymentStatus;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PaymentService::class);
    }

    public function test_checkout_creates_payment_awaiting_payment(): void
    {
        $user = User::factory()->create();
        $package = CreditPackage::factory()->create(['credits' => 100, 'price_cents' => 5000]);

        $payment = $this->service->checkout($user, $package);

        $this->assertSame(PaymentStatus::AwaitingPayment->value, $payment->status);
        $this->assertNotNull($payment->external_id);
        $this->assertNotNull($payment->expires_at);
        $this->assertSame(5000, $payment->amount_cents);
        $this->assertSame(0, Wallet::where('user_id', $user->id)->count());
    }

    public function test_checkout_rejects_inactive_package(): void
    {
        $user = User::factory()->create();
        $package = CreditPackage::factory()->create(['active' => false]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->checkout($user, $package);
    }

    public function test_webhook_paid_grants_credits_once(): void
    {
        $user = User::factory()->create();
        $package = CreditPackage::factory()->create(['credits' => 100, 'price_cents' => 5000]);
        $payment = $this->service->checkout($user, $package);

        $this->service->handleWebhook([
            'external_id' => $payment->external_id,
            'event_id' => 'evt-1',
            'status' => 'paid',
        ], []);

        $this->assertSame(100, Wallet::where('user_id', $user->id)->first()->balance);
        $this->assertSame(PaymentStatus::Paid->value, $payment->fresh()->status);

        // Reenvio do mesmo evento não duplica créditos.
        $this->service->handleWebhook([
            'external_id' => $payment->external_id,
            'event_id' => 'evt-1',
            'status' => 'paid',
        ], []);

        $this->assertSame(100, Wallet::where('user_id', $user->id)->first()->balance);
        $this->assertSame(1, PaymentEvent::count());
    }

    public function test_webhook_with_different_event_id_does_not_double_credit(): void
    {
        $user = User::factory()->create();
        $package = CreditPackage::factory()->create(['credits' => 100, 'price_cents' => 5000]);
        $payment = $this->service->checkout($user, $package);

        $this->service->handleWebhook(['external_id' => $payment->external_id, 'event_id' => 'evt-1', 'status' => 'paid'], []);
        $this->service->handleWebhook(['external_id' => $payment->external_id, 'event_id' => 'evt-2', 'status' => 'paid'], []);

        $this->assertSame(100, Wallet::where('user_id', $user->id)->first()->balance);
    }

    public function test_invalid_webhook_does_not_change_payment(): void
    {
        $user = User::factory()->create();
        $package = CreditPackage::factory()->create(['credits' => 100, 'price_cents' => 5000]);
        $payment = $this->service->checkout($user, $package);

        $result = $this->service->handleWebhook([], []);

        $this->assertNull($result);
        $this->assertSame(PaymentStatus::AwaitingPayment->value, $payment->fresh()->status);
        $this->assertSame(0, PaymentEvent::count());
        $this->assertSame(0, Wallet::where('user_id', $user->id)->count());
    }

    public function test_simulate_payment_grants_credits(): void
    {
        $user = User::factory()->create();
        $package = CreditPackage::factory()->create(['credits' => 200, 'price_cents' => 9000]);
        $payment = $this->service->checkout($user, $package);

        $this->service->simulatePayment($payment);

        $this->assertSame(200, Wallet::where('user_id', $user->id)->first()->balance);
        $this->assertSame(PaymentStatus::Paid->value, $payment->fresh()->status);
        $this->assertSame(1, Payment::count());
    }
}
