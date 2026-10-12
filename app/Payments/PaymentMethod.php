<?php

namespace App\Payments;

/**
 * Métodos de pagamento suportados pelo OLHO (prompt 45).
 *
 * Os valores espelham a nomenclatura do PagBank (`PIX`, `DEBIT_CARD`,
 * `CREDIT_CARD`). Qualquer nomenclatura divergente do provedor é traduzida
 * nesta camada — o enum nunca contém métodos que o OLHO não oferece.
 */
enum PaymentMethod: string
{
    case Pix = 'PIX';

    case Debit = 'DEBIT_CARD';

    case Credit = 'CREDIT_CARD';

    public function label(): string
    {
        return match ($this) {
            self::Pix => 'PIX',
            self::Debit => 'Cartão de débito',
            self::Credit => 'Cartão de crédito',
        };
    }

    /**
     * Traduz o `type` retornado pelo PagBank (case-insensitive) para o enum.
     */
    public static function fromPagBank(?string $type): ?self
    {
        if ($type === null || $type === '') {
            return null;
        }

        return match (mb_strtoupper($type)) {
            'PIX' => self::Pix,
            'DEBIT_CARD', 'DEBIT' => self::Debit,
            'CREDIT_CARD', 'CREDIT' => self::Credit,
            default => null,
        };
    }
}
