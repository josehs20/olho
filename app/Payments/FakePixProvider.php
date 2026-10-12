<?php

namespace App\Payments;

use App\Models\Payment;
use Illuminate\Support\Str;

/**
 * Provider fake para testes locais, sem chamada externa e sem chave PIX real.
 */
final class FakePixProvider implements DirectPixProvider
{
    /**
     * Nenhum dado do comprador entra aqui: o payload de teste é montado só com o
     * valor do pedido.
     */
    public function createCharge(Payment $payment): PixCharge
    {
        $externalId = 'fake_'.Str::uuid();

        return new PixCharge(
            externalId: $externalId,
            payload: $this->buildPayload($payment, $externalId),
            qrCode: 'FAKE-QR-'.$externalId,
            expiresAt: now()->addMinutes((int) config('payments.pix.expiration_minutes', 30)),
        );
    }

    public function getStatus(string $externalId): PaymentStatus
    {
        // No modo fake o estado é conduzido pelo webhook; sempre aguardando pagamento.
        return PaymentStatus::AwaitingPayment;
    }

    public function validateWebhook(array $payload, array $headers, ?string $rawBody = null): ?VerifiedPaymentEvent
    {
        $externalId = $payload['external_id'] ?? null;

        if (! is_string($externalId) || $externalId === '') {
            return null;
        }

        return new VerifiedPaymentEvent(
            externalEventId: (string) ($payload['event_id'] ?? 'evt_'.Str::uuid()),
            externalId: $externalId,
            status: (string) ($payload['status'] ?? PaymentStatus::Paid->value),
        );
    }

    private function buildPayload(Payment $payment, string $externalId): string
    {
        $key = (string) config('payments.pix.key');
        $name = (string) config('payments.pix.receiver_name');
        $city = (string) config('payments.pix.receiver_city');
        $amount = number_format($payment->amount_cents / 100, 2, '.', '');

        return "FAKE_PIX|key={$key}|name={$name}|city={$city}|amount={$amount}|txid={$externalId}";
    }
}
