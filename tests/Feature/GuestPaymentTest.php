<?php

namespace Tests\Feature;

use App\Models\CreditPackage;
use App\Models\CreditTransaction;
use App\Models\Investigation;
use App\Models\InvestigationRun;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\SourceRun;
use App\Models\User;
use App\Models\Wallet;
use App\Payments\PaymentStatus;
use App\Payments\VerifiedPaymentEvent;
use App\Services\CreditLedgerService;
use App\Services\GuestInvestigationService;
use App\Services\PaymentService;
use Database\Seeders\CreditPackageSeeder;
use Database\Seeders\SourceDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pagamento da investigação avulsa (prompt 41): "pagou, usou, acabou".
 *
 * Preço fixo, sem créditos, idempotente por investigação e liberado apenas por
 * evento de pagamento verificado (webhook/provider).
 */
class GuestPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SourceDefinitionSeeder::class);
        $this->seed(CreditPackageSeeder::class);

        config(['queue.default' => 'sync', 'cache.default' => 'array']);
    }

    /**
     * @return array{0: Investigation, 1: Payment}
     */
    private function guestWithPayment(): array
    {
        $this->post(route('guest.investigations.store'), ['username' => 'pagamento.teste'])->assertRedirect();

        $investigation = Investigation::query()->latest('id')->firstOrFail();

        return [$investigation, $investigation->guestPayment];
    }

    public function test_guest_checkout_is_idempotent_per_investigation(): void
    {
        $guest = app(GuestInvestigationService::class)->start(['username' => 'idempotente.teste']);

        $first = app(PaymentService::class)->checkoutGuest($guest['investigation'], $guest['package']);
        $second = app(PaymentService::class)->checkoutGuest($guest['investigation'], $guest['package']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Payment::query()->where('guest_investigation_id', $guest['investigation']->id)->count());
    }

    public function test_duplicate_and_repeated_paid_events_release_execution_only_once(): void
    {
        [$investigation, $payment] = $this->guestWithPayment();

        $event = new VerifiedPaymentEvent(
            externalEventId: 'evt-'.Str::uuid(),
            externalId: (string) $payment->external_id,
            status: PaymentStatus::Paid->value,
        );

        app(PaymentService::class)->confirmPayment($event);

        $runsAfterFirst = SourceRun::query()->count();
        $this->assertGreaterThan(0, $runsAfterFirst);
        $this->assertSame(1, PaymentEvent::query()->where('payment_id', $payment->id)->count());

        // Mesmo evento (reentrega do gateway): deduplicado por external_event_id —
        // não registra de novo nem reexecuta nada.
        app(PaymentService::class)->confirmPayment($event);

        $this->assertSame(1, PaymentEvent::query()->where('payment_id', $payment->id)->count());

        // Evento novo para o mesmo pedido já pago: fica registrado para auditoria,
        // porém não libera nova execução (o pagamento já estava pago).
        app(PaymentService::class)->confirmPayment(new VerifiedPaymentEvent(
            externalEventId: 'evt-'.Str::uuid(),
            externalId: (string) $payment->external_id,
            status: PaymentStatus::Paid->value,
        ));

        $this->assertSame(2, PaymentEvent::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(1, InvestigationRun::query()->where('investigation_id', $investigation->id)->count());
        $this->assertSame($runsAfterFirst, SourceRun::query()->count());
        $this->assertSame(PaymentStatus::Paid->value, $payment->refresh()->status);
    }

    public function test_guest_flow_keeps_account_wallets_and_ledger_untouched(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

        app(CreditLedgerService::class)->promotional($user, $wallet, 50, 'guest-test-topup-'.$user->id);

        $balance = (int) $wallet->refresh()->balance;

        [$investigation, $payment] = $this->guestWithPayment();

        app(PaymentService::class)->confirmPayment(new VerifiedPaymentEvent(
            externalEventId: 'evt-'.Str::uuid(),
            externalId: (string) $payment->external_id,
            status: PaymentStatus::Paid->value,
        ));

        $this->assertContains($investigation->refresh()->status, [Investigation::STATUS_COMPLETED, Investigation::STATUS_PARTIAL]);
        $this->assertSame($balance, (int) $wallet->refresh()->balance);
        $this->assertSame($balance, (int) CreditTransaction::query()->where('wallet_id', $wallet->id)->sum('amount'));
        $this->assertSame(0, (int) $investigation->currentRun->credits_reserved);
        $this->assertSame(0, (int) $investigation->currentRun->credits_consumed);
    }

    public function test_guest_price_package_is_not_sold_as_credit_package(): void
    {
        $guestPackage = CreditPackage::query()->guestKind()->firstOrFail();

        $this->expectException(\InvalidArgumentException::class);

        app(PaymentService::class)->checkout(User::factory()->create(), $guestPackage);
    }

    public function test_credit_package_kind_is_hidden_from_the_credits_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('payments.index'))
            ->assertOk()
            ->assertDontSee('Investigação avulsa');
    }
}
