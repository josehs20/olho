<?php

namespace App\Payments;

use App\Models\Payment;

/**
 * Capacidade: provedor que emite o PIX **copia e cola na própria aplicação**
 * (`createCharge`) em vez de redirecionar o comprador para uma página
 * hospedada.
 *
 * Hoje só o provedor fake (`fake`, desenvolvimento/testes) tem essa capacidade:
 * o PagBank opera **exclusivamente** por checkout hospedado — a API de Pedidos
 * exigia `customer.tax_id`, dado pessoal que o OLHO deixou de coletar. No
 * checkout é o PagBank que coleta os dados de quem paga.
 */
interface DirectPixProvider extends PaymentProvider
{
    /**
     * Emite o QR Code PIX do pedido e devolve o copia e cola.
     *
     * O pagador **não** faz parte deste contrato: o OLHO não pede nem guarda
     * nome, CPF/CNPJ ou e-mail do comprador.
     */
    public function createCharge(Payment $payment): PixCharge;
}
