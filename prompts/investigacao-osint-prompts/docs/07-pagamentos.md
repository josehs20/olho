# 07 — Pagamentos PIX

## Objetivo

Permitir venda de créditos via PIX. A primeira implantação usará um adapter configurável e uma chave PIX empresarial pertencente ao recebedor, armazenada somente em ambiente seguro.

## Abstração

```php
interface PaymentProvider
{
    public function createPixCharge(PaymentOrder $order): PixCharge;
    public function getStatus(string $externalId): PaymentStatus;
    public function validateWebhook(array $payload, array $headers): VerifiedPaymentEvent;
}
```

## MVP

Criar `FakePixProvider` para testes locais.

O sistema deve funcionar sem depender de um gateway real.

## Configuração

Variáveis conceituais:

```env
PAYMENT_PROVIDER=fake
PIX_KEY_TYPE=cnpj
PIX_KEY=CONFIGURE_ME
PIX_RECEIVER_NAME=CONFIGURE_ME
PIX_RECEIVER_CITY=CONFIGURE_ME
PIX_EXPIRATION_MINUTES=30
```

Quando um gateway real for escolhido, as credenciais ficam em `.env`/secret manager.

## Fluxo

```text
checkout
 -> create order
 -> create charge
 -> awaiting_payment
 -> webhook/status validation
 -> paid
 -> credit ledger
```

## Idempotência

- `payments.external_id` único quando fornecido pelo gateway;
- `payment_events.external_event_id` único;
- concessão de créditos dentro de transação idempotente.

## Segurança

Nunca considerar o frontend como fonte de verdade sobre pagamento.
Não armazenar dados de cartão.
Não imprimir chave PIX real nos logs.


## Investigação avulsa (prompt 41)

- O preço fixo da avulsa é a linha ativa `kind = guest_investigation` de
  `credit_packages`, editável em `/admin/packages`; o valor pago fica congelado em
  `investigations.guest_price_cents`.
- `PaymentService::checkoutGuest()` cria **um** pedido por investigação
  (`guest_investigation_id` único + chave de idempotência
  `guest-investigation-{id}`), sem usuário (`payments.user_id` nullable) e reusando
  o mesmo provedor PIX (`PaymentProvider`/`FakePixProvider`) e `payment_events`.
- Ao confirmar o pagamento, o pedido avulso **não credita créditos**: libera a
  execução da investigação (`GuestInvestigationService::release()`), de forma
  idempotente. Pedidos de conta seguem creditando o pacote.
- Não existe rota que aceite "pago" do front; a confirmação vem do webhook do
  gateway (ou da simulação, apenas com `PAYMENT_PROVIDER=fake`).
