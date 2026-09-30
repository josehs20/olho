<?php

namespace App\Services;

use App\Jobs\StartInvestigationRunJob;
use App\Models\CreditPackage;
use App\Models\Investigation;
use App\Models\InvestigationRun;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Payments\PaymentStatus;
use Illuminate\Support\Facades\DB;

/**
 * Jornada de investigação avulsa (prompt 41): visitante sem conta.
 *
 * Regras: preço fixo pago antes ("pagou, usou, acabou"), sem consumo de créditos;
 * acesso apenas pelo token de alta entropia (persistido como hash) e nenhuma
 * execução antes da confirmação do pagamento pelo backend.
 */
class GuestInvestigationService
{
    /** Bytes de entropia do token de acesso (256 bits). */
    private const TOKEN_BYTES = 32;

    public function __construct(private readonly InvestigationService $investigations) {}

    /**
     * Preço fixo vigente (pacote ativo `guest_investigation`), operado pelo admin.
     */
    public function pricePackage(): CreditPackage
    {
        return CreditPackage::query()
            ->guestKind()
            ->where('active', true)
            ->orderBy('display_order')
            ->first()
            ?? throw new \RuntimeException('Preço da investigação avulsa não configurado em admin/pacotes.');
    }

    /**
     * Cria a investigação avulsa (sem execução), o token de acesso e resolve o
     * preço vigente. O pedido PIX é criado pelo PaymentService.
     *
     * @param  array<string, mixed>  $input
     * @return array{investigation: Investigation, token: string, package: CreditPackage}
     */
    public function start(array $input, ?string $title = null): array
    {
        $package = $this->pricePackage();
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));

        $investigation = $this->investigations->createGuest(
            $input,
            (int) $package->price_cents,
            Investigation::hashAccessToken($token),
            $title,
        );

        return ['investigation' => $investigation, 'token' => $token, 'package' => $package];
    }

    /**
     * Resolve a investigação avulsa pelo token em claro — única autorização de
     * acesso (o id nunca autoriza nada).
     */
    public function resolveByToken(string $token): ?Investigation
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $investigation = Investigation::query()
            ->where('origin', Investigation::ORIGIN_GUEST)
            ->where('access_token_hash', Investigation::hashAccessToken($token))
            ->first();

        if ($investigation === null) {
            return null;
        }

        $expiresAt = $investigation->access_token_expires_at;

        if ($expiresAt !== null && $expiresAt->isPast()) {
            return null;
        }

        return $investigation;
    }

    /**
     * Libera a execução após o pagamento confirmado (idempotente).
     *
     * Chamado apenas pelo backend (webhook/provider via PaymentService), nunca
     * pelo front. A expiração barra execução **sem** pagamento; se o pagamento
     * chegar depois do prazo, o visitante pagou e a investigação é executada.
     */
    public function release(Payment $payment): void
    {
        if (! $payment->isGuest() || $payment->status !== PaymentStatus::Paid->value) {
            return;
        }

        $origin = $payment->guestInvestigation()->first();

        if ($origin === null) {
            return;
        }

        $released = DB::transaction(function () use ($origin): ?Investigation {
            $locked = Investigation::query()->lockForUpdate()->find($origin->id);

            if ($locked === null || ! $locked->isGuest()) {
                return null;
            }

            if (! in_array($locked->status, [Investigation::STATUS_DRAFT, Investigation::STATUS_EXPIRED], true)) {
                return null; // já liberada/em execução ou estado terminal
            }

            $locked->status = Investigation::STATUS_QUEUED;
            $locked->save();

            return $locked;
        });

        if ($released === null || $released->current_run_id === null) {
            return;
        }

        StartInvestigationRunJob::dispatch($released->current_run_id);
    }

    /**
     * Expira rascunhos avulsos nunca pagos. Nada é executado.
     */
    public function expireUnpaid(): int
    {
        $cutoff = now()->subMinutes((int) config('guest.unpaid_expiration_minutes'));
        $expired = 0;

        Investigation::query()
            ->where('origin', Investigation::ORIGIN_GUEST)
            ->where('status', Investigation::STATUS_DRAFT)
            ->where('created_at', '<', $cutoff)
            ->with('guestPayment')
            ->chunkById(100, function ($investigations) use (&$expired): void {
                foreach ($investigations as $investigation) {
                    if ($this->expire($investigation)) {
                        $expired++;
                    }
                }
            });

        return $expired;
    }

    /**
     * Estado observável da avulsa para a página de acompanhamento (polling).
     *
     * @return array<string, mixed>
     */
    public function progress(Investigation $investigation): array
    {
        $run = $investigation->currentRun()
            ->with(['sourceRuns.sourceDefinition', 'sourceRuns.sourceResults', 'report'])
            ->first();

        $sources = $run === null
            ? []
            : $run->sourceRuns->sortBy('id')->values()->map(fn ($sourceRun) => [
                'source' => $sourceRun->source,
                'name' => $sourceRun->sourceDefinition?->name ?? $sourceRun->source,
                'status' => $sourceRun->status,
                'display_status' => $sourceRun->displayStatus(),
                'results' => $sourceRun->sourceResults->count(),
            ])->all();

        return [
            'status' => $investigation->status,
            'run' => $run === null ? null : [
                'id' => $run->id,
                'status' => $run->status,
                'started_at' => $run->started_at?->toISOString(),
                'finished_at' => $run->finished_at?->toISOString(),
                'error_summary' => $run->error_summary,
            ],
            'sources' => $sources,
            'report_available' => $run !== null && ($run->report !== null
                || in_array($run->status, [InvestigationRun::STATUS_COMPLETED, InvestigationRun::STATUS_PARTIAL], true)),
        ];
    }

    private function expire(Investigation $investigation): bool
    {
        $payment = $investigation->guestPayment;

        // Pagamento confirmado (webhook atrasado): libera em vez de expirar.
        if ($payment !== null && $payment->status === PaymentStatus::Paid->value) {
            $this->release($payment);

            return false;
        }

        return (bool) DB::transaction(function () use ($investigation, $payment): bool {
            $locked = Investigation::query()->lockForUpdate()->find($investigation->id);

            if ($locked === null || $locked->status !== Investigation::STATUS_DRAFT) {
                return false;
            }

            $locked->status = Investigation::STATUS_EXPIRED;
            $locked->save();

            if ($payment !== null && $payment->status !== PaymentStatus::Paid->value) {
                $payment->status = PaymentStatus::Expired->value;
                $payment->save();

                PaymentEvent::query()->firstOrCreate(
                    ['external_event_id' => 'guest-expire-'.$payment->id],
                    [
                        'payment_id' => $payment->id,
                        'type' => 'payment.expired',
                        'status' => PaymentStatus::Expired->value,
                    ],
                );
            }

            return true;
        });
    }
}
