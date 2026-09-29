<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\InvalidReservationStateException;
use App\Models\CreditReservation;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CreditLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private CreditLedgerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CreditLedgerService::class);
    }

    private function wallet(int $balance = 0): array
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => $balance]);

        return [$user, $wallet];
    }

    public function test_purchase_credits_increases_balance_and_records_ledger(): void
    {
        [$user, $wallet] = $this->wallet(0);

        $tx = $this->service->purchase($user, $wallet, 100, 'idem-1');

        $this->assertSame(100, $wallet->fresh()->balance);
        $this->assertSame(100, $tx->amount);
        $this->assertSame(0, $tx->balance_before);
        $this->assertSame(100, $tx->balance_after);
        $this->assertSame('purchase_credit', $tx->type);
    }

    public function test_reserve_reduces_balance_and_creates_reservation(): void
    {
        [$user, $wallet] = $this->wallet(100);

        $reservation = $this->service->reserve($user, $wallet, 80, null, 'res-1');

        $this->assertSame(20, $wallet->fresh()->balance);
        $this->assertSame('pending', $reservation->status);
        $this->assertSame(80, $reservation->amount);

        $reserveTx = CreditTransaction::where('type', 'reserve')->first();
        $this->assertSame(-80, $reserveTx->amount);
    }

    public function test_consume_marks_reservation_consumed_without_changing_balance(): void
    {
        [$user, $wallet] = $this->wallet(100);
        $reservation = $this->service->reserve($user, $wallet, 80, null, 'res-1');

        $tx = $this->service->consume($reservation, 'cons-1');

        $this->assertSame(20, $wallet->fresh()->balance);
        $this->assertSame('consumed', $reservation->fresh()->status);
        $this->assertSame('consume', $tx->type);
    }

    public function test_release_restores_balance(): void
    {
        [$user, $wallet] = $this->wallet(100);
        $reservation = $this->service->reserve($user, $wallet, 80, null, 'res-1');

        $tx = $this->service->release($reservation, 'rel-1');

        $this->assertSame(100, $wallet->fresh()->balance);
        $this->assertSame('released', $reservation->fresh()->status);
        $this->assertSame('release', $tx->type);
        $this->assertSame(80, $tx->amount);
    }

    public function test_insufficient_balance_throws_and_rolls_back(): void
    {
        [$user, $wallet] = $this->wallet(10);

        try {
            $this->service->reserve($user, $wallet, 20, null, 'res-1');
            $this->fail('Expected InsufficientCreditsException');
        } catch (InsufficientCreditsException) {
            // esperado
        }

        $this->assertSame(10, $wallet->fresh()->balance);
        $this->assertSame(0, CreditTransaction::count());
        $this->assertSame(0, CreditReservation::count());
    }

    public function test_admin_adjustment_requires_reason(): void
    {
        [$user, $wallet] = $this->wallet(0);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->adminAdjust($user, $wallet, 50, '', 'adj-1');
    }

    public function test_admin_adjustment_can_be_negative_but_not_below_zero(): void
    {
        [$user, $wallet] = $this->wallet(100);

        $this->service->adminAdjust($user, $wallet, -60, 'correção', 'adj-1');
        $this->assertSame(40, $wallet->fresh()->balance);

        $this->expectException(InsufficientCreditsException::class);
        $this->service->adminAdjust($user, $wallet, -100, 'correção', 'adj-2');
    }

    public function test_idempotency_key_prevents_double_credit(): void
    {
        [$user, $wallet] = $this->wallet(0);

        $first = $this->service->purchase($user, $wallet, 100, 'same-key');
        $second = $this->service->purchase($user, $wallet, 100, 'same-key');

        $this->assertTrue($first->is($second));
        $this->assertSame(100, $wallet->fresh()->balance);
        $this->assertSame(1, CreditTransaction::where('idempotency_key', 'same-key')->count());
    }

    public function test_reserve_idempotency_prevents_duplicate_reservation(): void
    {
        [$user, $wallet] = $this->wallet(100);

        $first = $this->service->reserve($user, $wallet, 50, null, 'same-res');
        $second = $this->service->reserve($user, $wallet, 50, null, 'same-res');

        $this->assertTrue($first->is($second));
        $this->assertSame(50, $wallet->fresh()->balance);
        $this->assertSame(1, CreditReservation::count());
    }

    public function test_consume_on_non_pending_reservation_throws(): void
    {
        [$user, $wallet] = $this->wallet(100);
        $reservation = $this->service->reserve($user, $wallet, 50, null, 'res-1');
        $this->service->consume($reservation, 'cons-1');

        $this->expectException(InvalidReservationStateException::class);
        $this->service->consume($reservation, 'cons-2');
    }

    public function test_release_expired_reservations_compensates_balance(): void
    {
        [$user, $wallet] = $this->wallet(100);
        $this->service->reserve($user, $wallet, 30, null, 'res-expired', now()->subMinute());
        $this->service->reserve($user, $wallet, 20, null, 'res-active', now()->addMinute());

        $released = $this->service->releaseExpiredReservations(now());

        $this->assertSame(1, $released);
        $this->assertSame(80, $wallet->fresh()->balance);
        $this->assertSame('released', CreditReservation::where('idempotency_key', 'res-expired')->first()->status);
        $this->assertSame('pending', CreditReservation::where('idempotency_key', 'res-active')->first()->status);
    }

    public function test_ledger_sum_matches_balance(): void
    {
        [$user, $wallet] = $this->wallet(0);
        $this->service->purchase($user, $wallet, 200, 'a');
        $this->service->reserve($user, $wallet, 80, null, 'b');
        $this->service->refund($user, $wallet, 20, 'c');

        $this->assertSame(140, $wallet->fresh()->balance);

        $sum = (int) CreditTransaction::where('wallet_id', $wallet->id)->sum('amount');
        $this->assertSame(140, $sum);
    }
}
