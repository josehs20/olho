<?php

namespace App\Payments;

/**
 * Contrato mínimo de um provedor de pagamento.
 *
 * *Cobrar* é uma capacidade adicional, exposta por `DirectPixProvider` (PIX
 * copia-e-cola, só o fake) e `CheckoutProvider` (checkout hospedado, PagBank).
 * O `PaymentService` decide o fluxo por `instanceof`, sem acoplar o domínio a um
 * provedor específico — e sem que nenhum dado pessoal do comprador circule pelo
 * OLHO.
 */
interface PaymentProvider
{
    /**
     * Consulta o status atual de uma cobrança no provedor.
     */
    public function getStatus(string $externalId): PaymentStatus;

    /**
     * Valida um payload de webhook e retorna o evento verificado (ou null se inválido).
     *
     * `$rawBody` é o corpo **bruto** da requisição — necessário para validar a
     * assinatura (ex.: PagBank `x-payload-signature`) sem re-serializar o JSON.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    public function validateWebhook(array $payload, array $headers, ?string $rawBody = null): ?VerifiedPaymentEvent;
}
