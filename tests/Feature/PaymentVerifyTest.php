<?php

namespace Tests\Feature;

use App\Models\CreditPackage;
use App\Models\Investigation;
use App\Models\InvestigationRun;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\SourceRun;
use App\Models\User;
use App\Models\Wallet;
use App\Payments\PaymentSettings;
use App\Payments\PaymentStatus;
use App\Services\GuestInvestigationService;
use App\Services\PaymentService;
use App\Services\SettingsService;
use Database\Seeders\CreditPackageSeeder;
use Database\Seeders\SourceDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Já paguei" — confirmação sob demanda no PagBank.
 *
 * Quando a notificação do provedor não chega (webhook perdido ou endereço
 * público ainda não publicado), o comprador pede a verificação: o servidor
 * consulta a API e só libera créditos/investigação se o PagBank confirmar. É
 * idempotente e não aceita status informado pelo navegador.
 */
class PaymentVerifyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SourceDefinitionSeeder::class);
        $this->seed(CreditPackageSeeder::class);

        config(['queue.default' => 'sync', 'cache.default' => 'array']);

        app(SettingsService::class)->setMany([
            PaymentSettings::KEY_PROVIDER => 'pagbank',
            PaymentSettings::KEY_ENABLED => '1',
            PaymentSettings::KEY_ENVIRONMENT => 'sandbox',
            PaymentSettings::KEY_PAGBANK_TOKEN => 'sandbox-token',
            PaymentSettings::KEY_METHOD_PIX => '1',
            PaymentSettings::KEY_METHOD_DEBIT => '0',
            PaymentSettings::KEY_METHOD_CREDIT => '1',
            PaymentSettings::KEY_CREDIT_MAX_INSTALLMENTS => '6',
        ]);
    }

    /**
     * Checkout criado (`POST /checkouts`) + consulta (`GET /checkouts/{id}`).
     *
     * @param  array<string, mixed>  $checkout
     */
    private function fakePagBank(array $checkout, int $statusCode = 200): void
    {
        Http::fake([
            'sandbox.api.pagseguro.com/checkouts' => Http::response([
                'id' => 'CHEC_VERIFY',
                'status' => 'ACTIVE',
                'expiration_date' => '2030-10-01T12:00:00-03:00',
                'links' => [
                    ['rel' => 'PAY', 'href' => 'https://pagamento.pagseguro.uol.com.br/pagamento?code=verify', 'method' => 'GET'],
                ],
            ], 200),
            'sandbox.api.pagseguro.com/checkouts/CHEC_VERIFY' => Http::response($checkout, $statusCode),
        ]);
    }

    /**
     * @return array{0: User, 1: Payment}
     */
    private function startCreditCheckout(int $credits = 40): array
    {
        $user = User::factory()->create();
        $package = CreditPackage::factory()->create(['credits' => $credits, 'price_cents' => 3990]);

        return [$user, app(PaymentService::class)->checkout($user, $package)];
    }

    /**
     * @return array{0: Investigation, 1: string}
     */
    private function startGuest(string $username = 'verify.guest'): array
    {
        $guest = app(GuestInvestigationService::class)->start(['username' => $username]);

        app(PaymentService::class)->checkoutGuest($guest['investigation'], $guest['package']);

        return [$guest['investigation'], $guest['token']];
    }

    /**
     * @return array<string, mixed>
     */
    private function paidCheckout(): array
    {
        return [
            'id' => 'CHEC_VERIFY',
            'status' => 'ACTIVE',
            'charges' => [[
                'id' => 'CHAR_VERIFY',
                'status' => 'PAID',
                'payment_method' => ['type' => 'PIX'],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function waitingCheckout(): array
    {
        return [
            'id' => 'CHEC_VERIFY',
            'status' => 'ACTIVE',
            'charges' => [[
                'id' => 'CHAR_VERIFY',
                'status' => 'WAITING',
                'payment_method' => ['type' => 'PIX'],
            ]],
        ];
    }

    public function test_verify_confirms_a_paid_checkout_and_grants_credits_once(): void
    {
        $this->fakePagBank($this->paidCheckout());

        [$user, $payment] = $this->startCreditCheckout(40);

        $this->actingAs($user)
            ->post(route('payments.verify', $payment))
            ->assertRedirect(route('payments.show', $payment))
            ->assertSessionHas('status');

        $this->assertSame(PaymentStatus::Paid->value, $payment->fresh()->status);
        $this->assertSame(40, (int) Wallet::query()->where('user_id', $user->id)->firstOrFail()->balance);
        $this->assertSame(1, PaymentEvent::query()->where('payment_id', $payment->id)->count());

        // Duplo clique (ou webhook que chega depois) não credita de novo.
        $this->actingAs($user)
            ->post(route('payments.verify', $payment))
            ->assertRedirect(route('payments.show', $payment));

        $this->assertSame(40, (int) Wallet::query()->where('user_id', $user->id)->firstOrFail()->balance);
        $this->assertSame(1, PaymentEvent::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(0, Payment::query()->where('user_id', $user->id)->where('status', '!=', PaymentStatus::Paid->value)->count());
    }

    public function test_verify_keeps_waiting_when_the_checkout_is_not_paid(): void
    {
        $this->fakePagBank($this->waitingCheckout());

        [$user, $payment] = $this->startCreditCheckout();

        $this->actingAs($user)
            ->post(route('payments.verify', $payment))
            ->assertRedirect(route('payments.show', $payment))
            ->assertSessionHas('warning');

        $this->assertSame(PaymentStatus::AwaitingPayment->value, $payment->fresh()->status);
        $this->assertSame(0, Wallet::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, PaymentEvent::query()->count());
    }

    public function test_verify_reports_a_provider_failure_without_releasing_anything(): void
    {
        $this->fakePagBank(['error' => 'unavailable'], 503);

        [$user, $payment] = $this->startCreditCheckout();

        $this->actingAs($user)
            ->post(route('payments.verify', $payment))
            ->assertRedirect(route('payments.show', $payment))
            ->assertSessionHas('error');

        $this->assertSame(PaymentStatus::AwaitingPayment->value, $payment->fresh()->status);
        $this->assertSame(0, Wallet::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, PaymentEvent::query()->count());
    }

    public function test_verify_does_not_touch_another_users_order(): void
    {
        $this->fakePagBank($this->paidCheckout());

        [, $payment] = $this->startCreditCheckout();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->post(route('payments.verify', $payment))
            ->assertForbidden();

        $this->assertSame(PaymentStatus::AwaitingPayment->value, $payment->fresh()->status);
        $this->assertSame(0, PaymentEvent::query()->count());
    }

    public function test_guest_verify_releases_the_investigation_when_pagbank_confirms(): void
    {
        $this->fakePagBank($this->paidCheckout());

        [$investigation, $token] = $this->startGuest('verify.pago');

        $this->post(route('guest.payments.verify', ['token' => $token]))
            ->assertRedirect(route('guest.investigations.show', ['token' => $token]))
            ->assertSessionHas('status');

        $investigation->refresh();

        $this->assertContains($investigation->status, [Investigation::STATUS_COMPLETED, Investigation::STATUS_PARTIAL]);
        $this->assertSame(PaymentStatus::Paid->value, $investigation->guestPayment->status);
        $this->assertGreaterThan(0, SourceRun::query()->count());
        $this->assertSame(1, PaymentEvent::query()->count());
        $this->assertSame(0, Wallet::query()->count());

        // Repetir o clique não executa a investigação de novo.
        $sourceRuns = SourceRun::query()->count();

        $this->post(route('guest.payments.verify', ['token' => $token]))
            ->assertRedirect(route('guest.investigations.show', ['token' => $token]));

        $this->assertSame($sourceRuns, SourceRun::query()->count());
        $this->assertSame(1, PaymentEvent::query()->count());
    }

    public function test_guest_verify_keeps_the_investigation_blocked_while_unpaid(): void
    {
        $this->fakePagBank($this->waitingCheckout());

        [$investigation, $token] = $this->startGuest('verify.pendente');

        $this->post(route('guest.payments.verify', ['token' => $token]))
            ->assertRedirect(route('guest.investigations.show', ['token' => $token]))
            ->assertSessionHas('warning');

        $investigation->refresh();

        $this->assertSame(Investigation::STATUS_DRAFT, $investigation->status);
        $this->assertSame(InvestigationRun::STATUS_PENDING, $investigation->currentRun->status);
        $this->assertSame(PaymentStatus::AwaitingPayment->value, $investigation->guestPayment->status);
        $this->assertSame(0, SourceRun::query()->count());
        $this->assertSame(0, PaymentEvent::query()->count());
    }

    public function test_guest_verify_is_not_a_fake_confirmation_route(): void
    {
        $this->fakePagBank($this->waitingCheckout());

        [$investigation, $token] = $this->startGuest('verify.fake');

        $payment = $investigation->guestPayment;
        $payment->provider = PaymentSettings::PROVIDER_FAKE;
        $payment->save();

        $this->post(route('guest.payments.verify', ['token' => $token]))->assertNotFound();

        $this->assertSame(Investigation::STATUS_DRAFT, $investigation->fresh()->status);
        $this->assertSame(PaymentStatus::AwaitingPayment->value, $payment->fresh()->status);
    }

    public function test_status_polling_queries_the_provider_at_most_once_per_window(): void
    {
        $this->fakePagBank($this->waitingCheckout());

        [$user, $payment] = $this->startCreditCheckout();

        // 1 chamada: criação do checkout no PagBank.
        $this->actingAs($user)->getJson(route('payments.status', $payment))->assertOk();
        $this->actingAs($user)->getJson(route('payments.status', $payment))->assertOk();
        $this->actingAs($user)->getJson(route('payments.status', $payment))->assertOk();

        // Só a primeira consulta de status foi ao provedor; as demais usaram a
        // janela de `payments.reconcile_cooldown_seconds`.
        Http::assertSentCount(2);

        $this->assertSame(PaymentStatus::AwaitingPayment->value, $payment->fresh()->status);
    }
}
