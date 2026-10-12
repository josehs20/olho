<?php

namespace App\Payments;

final class VerifiedPaymentEvent
{
    /**
     * @param  string|null  $method  Método efetivamente usado (nomenclatura interna:
     *                               `PIX`, `DEBIT_CARD`, `CREDIT_CARD`). Muitos eventos
     *                               não o trazem — o comprador só o escolhe na tela do
     *                               provedor, depois do checkout criado.
     */
    public function __construct(
        public readonly string $externalEventId,
        public readonly string $externalId,
        public readonly string $status,
        public readonly ?string $method = null,
    ) {}
}
