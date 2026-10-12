<?php

namespace Tests\Feature;

use App\Models\CreditPackage;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Models\Wallet;
use App\Payments\PaymentMethod;
use App\Payments\PaymentSettings;
use App\Payments\PaymentStatus;
use App\Payments\PaymentUnavailableException;
use App\Services\PaymentService;
use App\Services\SettingsService;
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

    /**
     * A cobrança direta é sempre um payload PIX: o método fica registrado desde a
     * criação (no checkout do PagBank só é conhecido quando o comprador paga).
     */
    public function test_charge_records_the_pix_method(): void
    {
        $user = User::factory()->create();
        $package = CreditPackage::factory()->create(['credits' => 100, 'price_cents' => 5000]);

        $payment = $this->service->checkout($user, $package);

        $this->assertSame(PaymentMethod::Pix, $payment->method);
    }

    /**
     * Provedor de checkout sem token: a cobrança falharia no gateway e o cliente
     * veria um erro opaco. Aborta antes de criar o pedido, com orientação.
     */
    public function test_checkout_is_blocked_when_the_provider_is_misconfigured(): void
    {
        $user = User::factory()->create();
        $package = CreditPackage::factory()->create(['credits' => 100, 'price_cents' => 5000]);

        app(SettingsService::class)->setMany([
            PaymentSettings::KEY_PROVIDER => PaymentSettings::PROVIDER_PAGBANK,
            PaymentSettings::KEY_ENABLED => '1',
        ]);

        try {
            app(PaymentService::class)->checkout($user, $package);
            $this->fail('A cobrança deveria ter sido bloqueada pela integração incompleta.');
        } catch (PaymentUnavailableException $exception) {
            $this->assertStringContainsString('incompleta', $exception->getMessage());
        }

        $this->assertSame(0, Payment::count());
    }

    /**
     * Integração desativada no painel: nenhuma cobrança é iniciada.
     */
    public function test_checkout_is_blocked_when_payments_are_disabled(): void
    {
        $user = User::factory()->create();
        $package = CreditPackage::factory()->create(['credits' => 100, 'price_cents' => 5000]);

        app(SettingsService::class)->set(PaymentSettings::KEY_ENABLED, '0');

        try {
            app(PaymentService::class)->checkout($user, $package);
            $this->fail('A cobrança deveria ter sido bloqueada com a integração desativada.');
        } catch (PaymentUnavailableException $exception) {
            $this->assertStringContainsString('indisponíveis', $exception->getMessage());
        }

        $this->assertSame(0, Payment::count());
    }
}
