<?php

namespace App\Payments;

use App\Models\Payment;

/**
 * Capacidade adicional de provedores que operam por **checkout com
 * redirecionamento** (ex.: PagBank Checkout/Link de Pagamento), em vez de
 * devolver um payload PIX copia-e-cola.
 *
 * O `PaymentService` decide o fluxo checando `instanceof CheckoutProvider`,
 * sem acoplar o domínio a um provedor específico.
 */
interface CheckoutProvider
{
    /**
     * Cria o checkout no provedor e devolve os dados de redirecionamento.
     *
     * Nenhum dado do pagador vai no payload: a **página hospedada** do provedor
     * coleta nome, CPF/CNPJ e e-mail do comprador.
     *
     * @param  array{methods?: array<int, string>, max_installments?: int, pix_expiration_minutes?: int}  $options
     */
    public function createCheckout(Payment $payment, array $options = []): CheckoutSession;

    /**
     * Consulta o estado real de um checkout no provedor (fonte de verdade).
     */
    public function getCheckoutStatus(string $checkoutId): PaymentStatus;

    /**
     * Método de pagamento já registrado no checkout (quando conhecido).
     *
     * Complementa `getCheckoutStatus()` para quem reconsulta o pedido sem passar
     * pelo webhook — a página de retorno do checkout, por exemplo. `null` quando
     * o provedor ainda não sabe o método (nada foi pago) ou não o informa.
     */
    public function getCheckoutMethod(string $checkoutId): ?string;
}
