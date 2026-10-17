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

## Comprar créditos (checkout hospedado)

- `GET /credits` lista os pacotes com um **botão por canal** (`channel=pix` ou
  `channel=card`). Não há modal nem JavaScript: cada botão é um `POST` para
  `payments.store`.
- O `POST` responde com um redirecionamento: para o link do provedor
  (`payments.checkout_url`) quando existe checkout hospedado, ou para a página do
  pedido (`payments.show`) quando é o OLHO que emite o PIX.
- O destino abre em **outra aba** (`target="_blank"` no formulário — que já
  implica `noopener` para formulários pela própria especificação HTML): a tela de
  créditos continua aberta, então o comprador volta, recarrega e vê o saldo
  (exibido na própria `/credits`, lido da carteira no servidor). O mesmo vale para
  o link "PAGAR COM PAGBANK" na página do pedido e na página pública da avulsa.
- Nenhum dado de pagador passa pelo OLHO: a escolha do meio e o CPF/CNPJ são
  informados na página do provedor.

## Gateway real PagBank (prompt 45)

A partir do prompt 45 o provedor ativo passa a ser escolhido pelo administrador
em `/admin/settings/payments` (`PaymentSettings` sobre a tabela `settings`), com
suporte a **PagBank Checkout** para PIX, débito e crédito. Detalhes de
arquitetura, webhook zero-trust, variáveis e pendências em
`docs/40-pagbank-configuravel.md`. O `FakePixProvider` continua sendo o default do
repositório e o provedor da suíte de testes.

Quando a notificação do provedor **chega e não conclui** (erro na reconsulta ou
status ainda aguardando), `PaymentService::handleWebhook()` agenda
`ReconcilePaymentJob`: o worker reconsulta o PagBank e libera créditos ou a
investigação avulsa pelo mesmo caminho idempotente do webhook — e o painel
`/admin/payments` tem o botão **Revalidar** (por pedido) e **Revalidar
pendentes** (em lote) para acionar essa verificação manualmente.

## Confirmação sob demanda: "Já paguei" (etapa 48)

O webhook é **aceleração**, não a fonte de verdade: a confirmação sempre vem da
consulta ao PagBank. Por isso o comprador também pode pedir a verificação na
hora, sem esperar notificação nem abrir chamado:

- **Conta**: `POST /payments/{payment}/verify` (`payments.verify`) na página do
  pedido — botão **JÁ PAGUEI — VERIFICAR AGORA**.
- **Avulsa**: `POST /investigacao/acesso/{token}/pagamento/verificar`
  (`guest.payments.verify`) na página de acompanhamento — o token continua sendo
  a única credencial.
- O clique não envia status nenhum: o servidor chama `PaymentService::reconcile()`
  (mesma consulta zero-trust do webhook), confirma com `confirmPayment()` e
  libera créditos ou `GuestInvestigationService::release()`. Toda a cadeia é
  idempotente, então duplo clique/webhook posterior não duplicam nada.
- As rotas são `POST` com CSRF, limitadas por usuário/pedido (conta) e por
  IP/token (avulsa), porque cada clique é uma chamada à API do provedor.
- Estados possíveis do retorno: confirmado (sucesso), ainda aguardando (aviso,
  com instrução para tentar de novo) e falha de consulta (erro, sem liberar
  nada). O pedido de outro usuário responde `403`; a avulsa com provedor fake
  responde `404` (não existe confirmação sem cobrança real).
- **Polling com janela**: a página do pedido reconsulta o provedor no máximo uma
  vez a cada `PAYMENT_RECONCILE_COOLDOWN_SECONDS` (20s por padrão); dentro da
  janela o endpoint de status lê apenas o banco. A avulsa nem consulta o
  provedor no polling — só o botão "Já paguei" consulta.

**Não há cron/varredura de pendentes** (decisão de custo): quem garante a
liberação é o webhook, o retorno assinado do checkout, o botão "Já paguei" e a
revalidação manual no painel. O runbook de produção — homologação da conta,
HTTPS/`APP_URL`, chave pública do webhook e roteiro de PIX de R$ 1 — está em
`docs/42-pagbank-producao.md`.

