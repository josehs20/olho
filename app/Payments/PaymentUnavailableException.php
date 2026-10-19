<?php

namespace App\Payments;

use RuntimeException;

/**
 * Cobrança indisponível: integração desativada ou incompleta (prompt 45).
 *
 * Exceção de domínio para que os controllers devolvam uma orientação acionável
 * ao usuário em vez do erro cru do gateway (ou de um 500). Nunca carrega token,
 * payload ou dado do comprador.
 */
final class PaymentUnavailableException extends RuntimeException
{
    public static function disabled(): self
    {
        return new self('Pagamentos estão temporariamente indisponíveis. Tente novamente mais tarde.');
    }

    public static function misconfigured(): self
    {
        return new self('Não foi possível iniciar o pagamento: a integração do provedor está incompleta. Avise o administrador.');
    }

    /**
     * Canal de pagamento escolhido indisponível (ex.: cartão sem checkout
     * configurado, ou PIX desabilitado pelo administrador).
     */
    public static function channelUnavailable(): self
    {
        return new self('Esta forma de pagamento não está disponível no momento. Escolha outra opção ou avise o administrador.');
    }

    /**
     * O provedor ativo é o link do banco, mas o pacote escolhido ainda não tem
     * link configurado (Admin → Pacotes).
     */
    public static function missingPaymentLink(): self
    {
        return new self('Este pacote ainda não tem link de pagamento configurado. Avise o administrador.');
    }
}
