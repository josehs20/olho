<?php

namespace App\Payments;

/**
 * Link de pagamento gerado no site do banco (PIX estático/checkout do banco).
 *
 * O OLHO não cria a cobrança nem recebe notificação: o administrador cola um
 * link fixo em cada pacote e confere o extrato manualmente. Por isso este
 * provedor:
 *
 * - nunca confirma pagamento (`getStatus` sempre devolve `AwaitingPayment`);
 * - não expõe webhook (`validateWebhook` devolve `null`);
 * - não cria cobrança (`createCharge` é um erro de programação — o
 *   `PaymentService` trata o fluxo em `startPaymentLink()`).
 *
 * A confirmação é uma ação administrativa explícita (`confirmManual`), com
 * auditoria. Nenhum clique do comprador libera créditos.
 */
final class PaymentLinkProvider implements PaymentProvider
{
    public function getStatus(string $externalId): PaymentStatus
    {
        // Sem API do banco: o estado só muda por confirmação manual.
        return PaymentStatus::AwaitingPayment;
    }

    public function validateWebhook(array $payload, array $headers, ?string $rawBody = null): ?VerifiedPaymentEvent
    {
        return null;
    }

    /**
     * Não há cobrança a criar: o link já existe e foi colado pelo administrador.
     */
    public function createCharge(Payment $payment): PixCharge
    {
        throw new \LogicException('O provedor de link de pagamento não cria cobranças.');
    }
}
