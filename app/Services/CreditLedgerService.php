<?php

namespace App\Services;

use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\InvalidReservationStateException;
use App\Models\CreditReservation;
use App\Models\CreditTransaction;
use App\Models\InvestigationRun;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CreditLedgerService
{
    /**
     * Saldo atual de uma carteira.
     */
    public function balance(Wallet $wallet): int
    {
        return (int) $wallet->balance;
    }

    /**
     * Extrato (ledger) de uma carteira.
     */
    public function statement(Wallet $wallet, int $limit = 50)
    {
        return CreditTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Credita créditos por compra de pacote.
     */
    public function purchase(User $user, Wallet $wallet, int $amount, string $idempotencyKey, array $metadata = []): CreditTransaction
    {
        $this->assertPositive($amount);

        return $this->apply($wallet, $user, 'purchase_credit', $amount, $idempotencyKey, 'Compra de créditos', $metadata);
    }

    /**
     * Credita créditos promocionais.
     */
    public function promotional(User $user, Wallet $wallet, int $amount, string $idempotencyKey, array $metadata = []): CreditTransaction
    {
        $this->assertPositive($amount);

        return $this->apply($wallet, $user, 'promotional_credit', $amount, $idempotencyKey, 'Crédito promocional', $metadata);
    }

    /**
     * Estorna créditos de volta à carteira.
     */
    public function refund(User $user, Wallet $wallet, int $amount, string $idempotencyKey, array $metadata = []): CreditTransaction
    {
        $this->assertPositive($amount);

        return $this->apply($wallet, $user, 'refund', $amount, $idempotencyKey, 'Estorno de créditos', $metadata);
    }

    /**
     * Ajuste administrativo (positivo ou negativo) com motivo obrigatório.
     */
    public function adminAdjust(User $user, Wallet $wallet, int $amount, string $reason, string $idempotencyKey, array $metadata = []): CreditTransaction
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Ajuste administrativo exige motivo.');
        }

        return $this->apply($wallet, $user, 'admin_adjustment', $amount, $idempotencyKey, $reason, $metadata);
    }

    /**
     * Reserva créditos (débito atômico com lock). Cria reserva + entrada de ledger.
     *
     * `$source` identifica o módulo da reserva (cobrança por módulo, prompt 39):
     * o encerramento do run consome apenas módulos concluídos e estorna os
     * demais. A idempotency key garante que retries não gerem débito duplicado.
     */
    public function reserve(
        User $user,
        Wallet $wallet,
        int $amount,
        ?InvestigationRun $run,
        string $idempotencyKey,
        ?Carbon $expiresAt = null,
        ?string $source = null,
    ): CreditReservation {
        $this->assertPositive($amount);

        return DB::transaction(function () use ($user, $wallet, $amount, $run, $idempotencyKey, $expiresAt, $source) {
            $wallet = Wallet::query()->whereKey($wallet->getKey())->lockForUpdate()->firstOrFail();

            if ($reservation = CreditReservation::query()->where('idempotency_key', $idempotencyKey)->first()) {
                return $reservation;
            }

            $before = (int) $wallet->balance;
            $after = $before - $amount;

            if ($after < 0) {
                throw new InsufficientCreditsException($before, $amount);
            }

            $wallet->balance = $after;
            $wallet->save();

            CreditTransaction::query()->create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'type' => 'reserve',
                'amount' => -$amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'idempotency_key' => $idempotencyKey,
                'description' => 'Reserva de créditos',
            ]);

            return CreditReservation::query()->create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'investigation_run_id' => $run?->id,
                'source' => $source,
                'amount' => $amount,
                'status' => 'pending',
                'idempotency_key' => $idempotencyKey,
                'expires_at' => $expiresAt,
            ]);
        });
    }

    /**
     * Consome uma reserva pendente (finaliza o débito; sem movimento de saldo).
     */
    public function consume(CreditReservation $reservation, string $idempotencyKey): CreditTransaction
    {
        return DB::transaction(function () use ($reservation, $idempotencyKey) {
            $reservation = CreditReservation::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();

            if ($reservation->status !== 'pending') {
                throw new InvalidReservationStateException($reservation->status);
            }

            $wallet = Wallet::query()->whereKey($reservation->wallet_id)->lockForUpdate()->firstOrFail();

            if ($transaction = CreditTransaction::query()->where('idempotency_key', $idempotencyKey)->first()) {
                return $transaction;
            }

            $reservation->status = 'consumed';
            $reservation->consumed_at = now();
            $reservation->save();

            $transaction = CreditTransaction::query()->create([
                'wallet_id' => $wallet->id,
                'user_id' => $reservation->user_id,
                'type' => 'consume',
                'amount' => 0,
                'balance_before' => (int) $wallet->balance,
                'balance_after' => (int) $wallet->balance,
                'idempotency_key' => $idempotencyKey,
                'description' => 'Consumo de reserva',
            ]);

            $transaction->reference()->associate($reservation);
            $transaction->save();

            return $transaction;
        });
    }

    /**
     * Libera uma reserva pendente (devolve o saldo).
     */
    public function release(CreditReservation $reservation, string $idempotencyKey): CreditTransaction
    {
        return DB::transaction(function () use ($reservation, $idempotencyKey) {
            $reservation = CreditReservation::query()->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();

            if ($reservation->status !== 'pending') {
                throw new InvalidReservationStateException($reservation->status);
            }

            $wallet = Wallet::query()->whereKey($reservation->wallet_id)->lockForUpdate()->firstOrFail();

            if ($transaction = CreditTransaction::query()->where('idempotency_key', $idempotencyKey)->first()) {
                return $transaction;
            }

            $before = (int) $wallet->balance;
            $after = $before + $reservation->amount;

            $wallet->balance = $after;
            $wallet->save();

            $reservation->status = 'released';
            $reservation->released_at = now();
            $reservation->save();

            $transaction = CreditTransaction::query()->create([
                'wallet_id' => $wallet->id,
                'user_id' => $reservation->user_id,
                'type' => 'release',
                'amount' => $reservation->amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'idempotency_key' => $idempotencyKey,
                'description' => 'Liberação de reserva',
            ]);

            $transaction->reference()->associate($reservation);
            $transaction->save();

            return $transaction;
        });
    }

    /**
     * Libera reservas pendentes já vencidas (compensação).
     */
    public function releaseExpiredReservations(?Carbon $now = null): int
    {
        $now ??= now();
        $released = 0;

        CreditReservation::query()
            ->where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->orderBy('id')
            ->chunkById(100, function ($reservations) use (&$released) {
                foreach ($reservations as $reservation) {
                    try {
                        $this->release($reservation, 'release-expired-'.$reservation->id);
                        $released++;
                    } catch (\Throwable) {
                        // Já liberada/consumida por outro worker; ignora.
                    }
                }
            });

        return $released;
    }

    /**
     * Aplica um movimento de saldo de forma transacional e idempotente.
     */
    private function apply(Wallet $wallet, User $user, string $type, int $amount, string $idempotencyKey, string $description, array $metadata): CreditTransaction
    {
        return DB::transaction(function () use ($wallet, $user, $type, $amount, $idempotencyKey, $description, $metadata) {
            $wallet = Wallet::query()->whereKey($wallet->getKey())->lockForUpdate()->firstOrFail();

            if ($transaction = CreditTransaction::query()->where('idempotency_key', $idempotencyKey)->first()) {
                return $transaction;
            }

            $before = (int) $wallet->balance;
            $after = $before + $amount;

            if ($after < 0) {
                throw new InsufficientCreditsException($before, $amount);
            }

            $wallet->balance = $after;
            $wallet->save();

            return CreditTransaction::query()->create([
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'type' => $type,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'idempotency_key' => $idempotencyKey,
                'description' => $description,
                'metadata' => $metadata,
            ]);
        });
    }

    private function assertPositive(int $amount): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('O valor deve ser positivo.');
        }
    }
}
