<?php

namespace App\Payments;

/**
 * Canal de pagamento escolhido pelo comprador (dois botões: PIX e Cartão).
 *
 * O canal é a **intenção** declarada na tela; o método (`PaymentMethod`) é o que
 * o provedor confirma depois. No cartão, crédito ou débito só é conhecido na
 * página do PagBank — por isso o canal não tenta adivinhar o método.
 */
enum PaymentChannel: string
{
    case Pix = 'pix';

    case Card = 'card';

    public function label(): string
    {
        return match ($this) {
            self::Pix => 'PIX',
            self::Card => 'Cartão de crédito ou débito',
        };
    }
}
