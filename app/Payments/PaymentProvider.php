<?php

namespace App\Payments;

use App\Models\Payment;

interface PaymentProvider
{
    /**
     * Cria uma cobrança PIX para o pedido e retorna os dados de pagamento.
     */
    public function createCharge(Payment $payment): PixCharge;

    /**
     * Consulta o status atual de uma cobrança no provedor.
     */
    public function getStatus(string $externalId): PaymentStatus;

    /**
     * Valida um payload de webhook e retorna o evento verificado (ou null se inválido).
     */
    public function validateWebhook(array $payload, array $headers): ?VerifiedPaymentEvent;
}
