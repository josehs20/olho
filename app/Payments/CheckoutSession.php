<?php

namespace App\Payments;

use Illuminate\Support\Carbon;

/**
 * Resultado da criação de um checkout com redirecionamento (PagBank).
 *
 * `externalId` é o identificador do checkout no provedor (`CHEC_...`) e passa a
 * ser a referência externa do pedido; `redirectUrl` é o link `PAY` para onde o
 * cliente é enviado (nunca marcamos o pedido como pago por esse redirecionamento).
 */
final class CheckoutSession
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $externalId,
        public readonly string $redirectUrl,
        public readonly ?Carbon $expiresAt = null,
        public readonly array $raw = [],
    ) {}
}
