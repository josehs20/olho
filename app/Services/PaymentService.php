<?php

namespace App\Services;

use App\Models\CreditPackage;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Models\Wallet;
use App\Payments\PaymentProvider;
use App\Payments\PaymentStatus;
use App\Payments\VerifiedPaymentEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        private readonly PaymentProvider $provider,
        private readonly CreditLedgerService $credits,
    ) {}

    /**
     * Cria o pedido e inicia a cobrança PIX.
     */
    public function checkout(User $user, CreditPackage $package): Payment
    {
        if (! $package->active) {
            throw new \InvalidArgumentException('Pacote inativo.');
        }

        return DB::transaction(function () use ($user, $package) {
            $payment = Payment::query()->create([
                'user_id' => $user->id,
                'credit_package_id' => $package->id,
                'provider' => (string) config('payments.provider'),
                'amount_cents' => $package->price_cents,
                'status' => PaymentStatus::Pending->value,
                'idempotency_key' => (string) Str::uuid(),
            ]);

            return $this->startCharge($payment);
        });
    }

    /**
     * Cria a cobrança no provedor e marca o pedido como aguardando pagamento.
     */
    public function startCharge(Payment $payment): Payment
    {
        $charge = $this->provider->createCharge($payment);

        $payment->external_id = $charge->externalId;
        $payment->status = PaymentStatus::AwaitingPayment->value;
        $payment->expires_at = $charge->expiresAt ?? $payment->expires_at;
        $payment->metadata = array_merge($payment->metadata ?? [], [
            'pix_payload' => $charge->payload,
            'pix_qr_code' => $charge->qrCode,
        ]);
        $payment->save();

        return $payment;
    }

    /**
     * Consulta o status do pedido (no provider, quando aplicável).
     */
    public function status(Payment $payment): PaymentStatus
    {
        if ($payment->status === PaymentStatus::AwaitingPayment->value && $payment->external_id) {
            return $this->provider->getStatus($payment->external_id);
        }

        return PaymentStatus::from($payment->status);
    }

    /**
     * Processa um webhook do gateway (valida, deduplica e confirma pagamento).
     */
    public function handleWebhook(array $payload, array $headers): ?Payment
    {
        $event = $this->provider->validateWebhook($payload, $headers);

        return $event === null ? null : $this->confirmPayment($event);
    }

    /**
     * Simula a confirmação de pagamento (apenas provider fake).
     */
    public function simulatePayment(Payment $payment): Payment
    {
        if (config('payments.provider') !== 'fake') {
            throw new \RuntimeException('Simulação disponível apenas com provider fake.');
        }

        return $this->confirmPayment(new VerifiedPaymentEvent(
            externalEventId: 'sim_'.Str::uuid(),
            externalId: $payment->external_id,
            status: PaymentStatus::Paid->value,
        ));
    }

    /**
     * Confirma um evento de pagamento verificado, de forma idempotente.
     */
    public function confirmPayment(VerifiedPaymentEvent $event): Payment
    {
        return DB::transaction(function () use ($event) {
            if ($processed = PaymentEvent::query()->where('external_event_id', $event->externalEventId)->first()) {
                return $processed->payment;
            }

            $payment = Payment::query()->where('external_id', $event->externalId)->firstOrFail();

            PaymentEvent::query()->create([
                'payment_id' => $payment->id,
                'external_event_id' => $event->externalEventId,
                'type' => 'payment.'.$event->status,
                'status' => $event->status,
            ]);

            if ($event->status === PaymentStatus::Paid->value && $payment->status !== PaymentStatus::Paid->value) {
                $payment->status = PaymentStatus::Paid->value;
                $payment->paid_at = now();
                $payment->save();

                $this->grantCredits($payment);
            }

            return $payment;
        });
    }

    /**
     * Concede os créditos do pacote (idempotente por pagamento).
     */
    private function grantCredits(Payment $payment): void
    {
        $package = CreditPackage::query()->findOrFail($payment->credit_package_id);
        $user = User::query()->findOrFail($payment->user_id);
        $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

        $this->credits->purchase(
            $user,
            $wallet,
            $package->credits,
            'payment-paid-'.$payment->id,
            ['payment_id' => $payment->id],
        );
    }
}
