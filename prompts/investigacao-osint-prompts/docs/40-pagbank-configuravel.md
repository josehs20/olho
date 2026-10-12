# Pagamentos PagBank configuráveis (prompt 45)

Integração real com o **PagBank (Checkout / Link de Pagamento)** para PIX, cartão
de débito e cartão de crédito, com parcelamento, sandbox, produção, webhooks,
teste de conexão e ativação/desativação **configuráveis pelo painel
administrativo**, reutilizando a arquitetura de pagamento já existente.

## Diagnóstico da implementação anterior

Nada foi reconstruído: já existia `PaymentProvider` com `FakePixProvider` (mock
PIX), `PaymentService`, models `Payment`/`PaymentEvent`, fluxo de conta (credita
pacote) e fluxo avulso (libera investigação), além de idempotência por
`payments.external_id` / `payments.idempotency_key` /
`payment_events.external_event_id`. O que faltava era:

| Gap | Como foi resolvido |
|---|---|
| Provedor fixo em `config('payments.provider')` | `PaymentSettings` ler `settings` (banco) com fallback para `config` |
| Nenhuma configuração administrativa | Tabela `settings` + `SettingsService` (cache) + tela `/admin/settings/payments` |
| Nenhum gateway real | `PagBankProvider` + `PagBankClient` (HTTP isolado) |
| Sem webhook real nem validação de assinatura | Rota `/webhooks/payments/pagbank` + `PagBankWebhookVerifier` (ECDSA/SHA-256) |
| Sem página de retorno do checkout | Rota assinada `payments.return` |
| Sem colunas de checkout | Migration com `provider_checkout_id`, `checkout_url`, `method`, `environment` |

## Arquitetura

```text
Controller
   ↓
PaymentService                 (fluxo de conta e avulso preservados)
   ↓
PaymentProvider (interface)  ──► FakePixProvider   (mock de desenvolvimento/testes)
   ↓                          └─► PagBankProvider   (implements CheckoutProvider)
                                     ↓
PagBankClient (Http::baseUrl + Bearer + timeout)
                                     ↓
PagBank API  POST /checkouts · GET /checkouts/{id} · GET /public-keys/webhook
```

O `AppServiceProvider` resolve o provedor a partir de `PaymentSettings`; o
`config()` nunca é lido diretamente pelos controllers.

## Configuração administrativa

`Admin → Configurações → Pagamentos` (`/admin/settings/payments`, grupo `admin`).
Chaves persistidas em `settings` (valores cifrados pelo cast `encrypted` do model
`Setting`):

| Chave | Significado | Validação |
|---|---|---|
| `payment.provider` | `fake` ou `pagbank` | `Rule::in` |
| `payment.enabled` | ativa/desativa a integração | boolean |
| `payment.environment` | `sandbox` ou `production` | `Rule::in` |
| `payment.pagbank.token` | API Token (segredo) | opcional; vazio preserva o atual; `clear_token` remove; normalizado (aspas/CRLF/`Bearer` removidos) |
| `payment.pagbank.last_test_at` | horário do último teste de conexão | escrito pelo teste |
| `payment.pagbank.last_test_ok` | resultado do último teste (`1`/`0`) | escrito pelo teste |
| `payment.pagbank.last_test_error` | motivo da última falha (status HTTP + orientação, sem token) | escrito pelo teste |
| `payment.method.pix` | habilita PIX | boolean |
| `payment.method.debit` | habilita débito (sem parcelamento) | boolean |
| `payment.method.credit` | habilita crédito | boolean |
| `payment.pix.expiration_minutes` | validade do PIX | `integer 1..1440` |
| `payment.credit.max_installments` | máximo de parcelas do crédito | `Rule::in` da faixa de `config/payments.php` (1..12) |

Somente PIX, débito e crédito existem — boleto/carteira digital não são
implementados nem exibidos. O token **nunca** é renderizado na resposta nem
gravado em log (o `AuditLogger` registra apenas provedor, ambiente, métodos e
parcelas).

### Teste de conexão

`POST /admin/settings/payments/test` chama `GET /public-keys/webhook`:
exige token válido e **não cria cobrança alguma**. O resultado (ok + horário) é
guardado em `payment.pagbank.last_test_at` / `last_test_ok` e exibido no card de
status da tela. Em caso de falha, `PagBankException::forResponse()` traduz o
status HTTP em orientação acionável — `401` (token inválido/expirado ou de outro
ambiente), `403` (homologação/produto), `400` com `invalid_authorization_header`
(header `Authorization` malformado) — e o texto fica em
`payment.pagbank.last_test_error`, também exibido no painel. O corpo cru do
provedor **nunca** é ecoado e o token nunca aparece na mensagem.

#### Token: como colar

O campo aceita **apenas o valor do token**. O valor é normalizado
(`PaymentSettings::normalizeToken()`) antes de validar/gravar: espaços, CRLF,
aspas e os prefixos `Authorization:` / `Bearer` são removidos, porque o
`PagBankClient` já envia `Authorization: Bearer <token>` — qualquer sobra
produziria um header inválido. Valores com caracteres fora do alfabeto de token
(ex.: colar a linha inteira do header) são rejeitados na validação, com mensagem
explicando que se deve informar apenas o token.

## Checkout e mapeamento de status

- `PaymentService::startCharge()` detecta `CheckoutProvider` e chama
  `createCheckout()`, persistindo `provider_checkout_id`, `checkout_url` e
  `environment` no `Payment` (mais `metadata.methods_offered`, para auditoria do
  que foi oferecido). `method` fica **nulo** aqui: no checkout é o comprador que
  escolhe o método na tela do PagBank (ver “Reconciliação e método de pagamento”).
- O `PagBankProvider` monta o payload com `reference_id = olho-{payment.id}`,
  `items` (valor do pacote avulso ou da compra de créditos), `payment_methods`
  conforme os métodos habilitados e a URL de retorno assinada
  (`payments.return`, 6h). O `x-idempotency-key` reusa
  `payments.idempotency_key`.
- O link de pagamento é lido de `links[rel=PAY]`; ausente ⇒ `PagBankException`
  (nunca inventamos URL).
- `PaymentStatus::fromPagBank()` traduz status transacional/checkout; valores
  desconhecidos caem em `AwaitingPayment` (**nunca** em `Paid`). Crédito aprovado
  em qualquer `charge` ⇒ `Paid`; `DECLINED` ⇒ `Failed`.
- `GET /payments/return/{payment}` (URL assinada e temporária, `signed` middleware)
  **reconcilia** o pedido com o provedor: se o checkout já constar como pago, o
  OLHO confirma na hora (créditos liberados / investigação avulsa executada) —
  ver a seção seguinte. Serve às duas jornadas sem expor o id sequencial do
  pedido. Requisição sem assinatura válida ⇒ **403** e nada muda.

## Webhook (zero-trust)

`POST /webhooks/payments/pagbank` (excluída do CSRF em `bootstrap/app.php`,
pública e sem sessão):

1. `PagBankWebhookVerifier` valida `x-payload-signature` (ECDSA/SHA-256) contra
   o **corpo bruto** (`$request->getContent()`) e a chave pública do PagBank
   (cacheada). Assinatura inválida/ausente ⇒ `null` ⇒ HTTP **400**, nada muda.
2. O checkout é **reconsultado** (`GET /checkouts/{id}`) como fonte de verdade —
   o payload do webhook nunca é suficiente.
3. A consulta do checkout **não** devolve o array `charges` — confirmado no
   sandbox: depois de um cartão **aprovado** o checkout continua
   `status: ACTIVE` e a cobrança paga (`CHAR_...`, `status: PAID`) só existe no
   **pedido** do checkout, referenciado em `orders[].id`. A confirmação então:
   - segue `orders[].id` (`ORDE_...`) por `GET /orders/{id}` e usa as cobranças
     do pedido (`PagBankProvider::withChargesFromOrders()`) — sempre pelo id
     devolvido pelo provedor, na API oficial, **nunca** seguindo `links.href`
     do payload;
   - e, quando a notificação já autenticada traz o id da cobrança
     (`charge`/`charges[]`), aceita também `GET /charges/{CHAR_...}`.

   Só um status conclusivo (pago/rejeitado/cancelado/estornado/expirado)
   prevalece — PIX aguardando não sobrescreve o status do checkout — e
   pedido/cobrança indisponível mantém o pagamento **aguardando** (nunca
   confirmamos por ausência de resposta).
4. `PaymentService::handleWebhook()` localiza o `Payment` por `external_id`,
   grava `payment_events` com `external_event_id` determinístico
   (`sha1(checkoutId|status|chargeId)`) e só então confirma.
5. Confirmar paga créditos (conta) ou libera a investigação avulsa; reentregas
   não duplicam evento, crédito nem execução.

## Reconciliação e método de pagamento

- **`PaymentService::reconcile()`** é o caminho de confirmação quando a
  notificação não chega (o comprador fecha o checkout; o ambiente local não tem
  URL pública, então o PagBank não alcança o webhook). A página de retorno chama
  esse método: o estado vem **sempre** da consulta ao provedor (checkout **e** o
  pedido que ele referencia, quando a consulta do checkout não traz `charges`)
  — nunca de parâmetro de URL — e, se estiver pago, a confirmação usa um evento derivado do
  pedido (`reconcile-paid-{checkout_id}`), portanto **idempotente**: revisitar a
  página (ou receber o webhook depois) não libera créditos nem executa a
  investigação duas vezes.
- Sem o webhook alcançável — caso do `docker compose` local — o fluxo
  ponta-a-ponta (pagar no sandbox → voltar ao OLHO) **funciona** por esse
  caminho; o webhook segue sendo o mecanismo normal em produção.
- **Reforço pelo worker**: quando a notificação **chega e não conclui** (erro na
  reconsulta ao provedor ou status ainda aguardando), `PaymentService::handleWebhook()`
  agenda `ReconcilePaymentJob` — a verificação roda no worker e libera o pedido
  pelo mesmo `reconcile()` idempotente. É a seção seguinte; o painel tem o botão
  **Revalidar** para o mesmo caminho por decisão do operador.

- **Método de pagamento**: `payment_method.type` da cobrança é traduzido por
  `PaymentMethod::fromPagBank()` e gravado em `payments.method` no momento da
  confirmação (webhook ou reconciliação) — antes disso o campo fica nulo, porque
  quem escolhe é o comprador no ambiente do PagBank. `PagBankProvider` lê o
  método do checkout consultado, da própria notificação (`charges[]`, `charge`
  ou o objeto transacional) ou de `GET /charges/{id}`, e expõe
  `getCheckoutMethod()` (capacidade de `CheckoutProvider`) para a reconciliação.
  No `FakePixProvider` a cobrança é sempre um payload PIX, então `method = PIX`
  desde a criação.
- **Guarda de configuração** (`PaymentService::available()` /
  `ensureProviderReady()`): com o PagBank selecionado, a cobrança exige
  integração ativa **+** token salvo **+** ao menos um método habilitado. Sem
  isso a compra é recusada com mensagem acionável (`PaymentUnavailableException`)
  em vez de estourar um erro do gateway; a lista de pacotes e o formulário da
  avulsa já aparecem com o pagamento indisponível.

## Webhook que chega e não conclui: verificação no worker

A notificação autenticada também é o **gatilho** de uma verificação de reforço,
para o pedido não ficar pendente quando a confirmação não fecha na hora:

- **Erro na reconsulta** (rede, `5xx`, `429`, timeout): `PaymentService::handleWebhook()`
  registra `payment.webhook.confirm_failed` e **deixa a exceção subir** — a
  resposta é `5xx`, então o PagBank também reentrega a notificação — **e** agenda
  `ReconcilePaymentJob` para o pedido (resolvido pelo id do provedor que veio no
  payload: `external_id`/`provider_checkout_id`, mais `metadata.ps_order_id` numa
  notificação de cobrança). Id de pedido que não é do OLHO ⇒
  `payment.webhook.confirm_unmatched`, nada é agendado (o payload nunca cria
  pedido).
- **Notificação que não liberou nada** (status resolvido como aguardando após a
  reconsulta — o pedido do checkout demora a aparecer, ou a consulta degradou):
  `payment.webhook.inconclusive` e o job é agendado com `reason =
  webhook-inconclusive`.
- `ReconcilePaymentJob` (`app/Jobs/…`) recebe **apenas o id** do pagamento, é
  `ShouldBeUnique` por pagamento (rajada de reentregas não vira fila de consultas,
  trava de 10 min) e roda no worker com `tries = 3` e `backoff = [60, 300]`. Uma
  passada por execução: reconsulta o PagBank pelo mesmo `reconcile()` idempotente
  e libera créditos ou a investigação avulsa. Pedido já pago, de outro provedor ou
  sem cobrança criada ⇒ `payment.reconcile.skipped` (nenhuma chamada à API);
  conclusão ⇒ `payment.reconcile.job`; tentativas esgotadas ⇒
  `payment.reconcile.failed` (o pedido segue pendente e os outros caminhos —
  retorno do comprador, botão do painel, comando, nova reentrega — continuam
  valendo). **Estado ainda aguardando não é falha**: o job termina normalmente.

## Sincronização forçada (webhook não chegou)

Caminho operacional para confirmar um pedido aprovado sem depender do webhook —
ambiente local (o PagBank não alcança `localhost`), notificação perdida ou
comprador que fecha o checkout antes de voltar:

- `php artisan pagbank:sync-payment <ID|olho-ID|CHEC_...|ORDE_...|idempotency_key>`
  — reconsulta o provedor e confirma quando já estiver pago; `--dry-run` só
  mostra o estado no provedor (e o status local) sem confirmar nada; `--force`
  reconsulta mesmo com o pedido já pago. Pedido de outro provedor é ignorado com
  aviso. O comando resolve o pedido por **id sequencial**, `reference_id`
  (`olho-{id}`), `external_id`/`provider_checkout_id` (`CHEC_`/`ORDE_`) ou
  `idempotency_key`.
- `php artisan pagbank:sync-payment --pending` — **varredura**: percorre todos os
  `payments.provider = pagbank` ainda em `awaiting_payment` (do mais antigo para
  o mais novo, teto de 100 por execução) e confirma os que já constarem como
  pagos, com uma linha por pedido (`[confirmado]`, `[pendente]`, `[falha]`) e um
  resumo. É o "conferir todos os pagamentos" em uma única chamada: falha de
  consulta em um pedido não interrompe os demais (o comando termina com código 1
  apenas se houver falha, para o script perceber). `--dry-run` mostra o estado de
  cada pendente no provedor sem confirmar; `--force` **não** se combina com
  `--pending` (cada pendente já é reconsultado) e informar um pagamento **e** a
  opção é recusado.
- **Painel** `/admin/payments`: a coluna **Ações** mostra **Sincronizar** (POST
  `admin.payments.sync`, `@csrf`) **apenas** para pedidos `payments.provider =
  pagbank`; a resposta JSON traz `status`, `provider_status`, `paid`,
  `credits_granted` e `released_investigation`. Fora do grupo `auth`+`admin` ⇒
  401/403 (rate limit `admin-sync`: 30/min, 300/h).
- **Botão "Sincronizar pendentes"** (topo da lista, POST
  `admin.payments.sync-pending`): a mesma varredura em lote pelo navegador —
  consulta no máximo 25 pedidos por clique (cada pedido gera 1–2 chamadas à API)
  e devolve o resumo no flash da sessão (`N pagamento(s) PagBank consultado(s): X
  confirmado(s) agora, Y ainda pendente(s), Z com falha.`). Também restrito a
  `auth`+`admin` com o rate limit `admin-sync`.
- **Botões pelo worker** (mesma tela): **Revalidar** (coluna Ações, POST
  `admin.payments.revalidate`, `@csrf` — só aparece para pedido PagBank **não
  pago** e com cobrança criada) e **Revalidar pendentes** (topo, POST
  `admin.payments.revalidate-pending`, teto de 25 por clique) agendam
  `ReconcilePaymentJob` em vez de consultar o provedor na requisição: a página
  responde na hora ("N verificação(ões) agendada(s) no worker…") e cada pedido é
  conferido pela fila, com as retentativas do job. É o caminho quando a
  notificação chegou e não concluiu (ou quando a consulta imediata está
  indisponível). Mesma restrição `auth`+`admin` e rate limit `admin-sync`; para
  pedido de outro provedor/sem cobrança o endpoint responde **422** (JSON) ou
  flash de erro (formulário).
- Os botões **Sincronizar**/**Sincronizar pendentes** são formulários HTML: a
  resposta é o **flash na própria lista** ("Pagamento #N confirmado no PagBank —
  créditos liberados." / "… segue pendente (provedor responde \"expired\")."), em
  vez do JSON. As mesmas rotas continuam respondendo JSON para `Accept:
  application/json` (é o que a suíte e um cliente de API usam).
- A confirmação é a mesma do webhook (crédito da conta ou liberação da
  investigação avulsa) e é **idempotente** por `payment_events` (`paid_at`):
  repetir a sincronização não credita nem executa de novo. A liberação vem
  sempre da resposta do provedor — nunca do navegador.

### Como conferir todos os pagamentos (e se o webhook chegou)

1. **Sandbox (fonte de verdade do dinheiro)**:
   `php artisan pagbank:sync-payment --pending --dry-run` lista o que o PagBank
   responde para cada pendente — `paid`, `awaiting_payment` ou `expired` — sem
   liberar nada.
2. **Como o OLHO confirmou**: em `payment_events`, `external_event_id` diz **por
   qual caminho** o pagamento foi confirmado — `reconcile-paid-{CHEC_...}`
   (sincronização forçada, retorno do checkout ou verificação no worker),
   `sim_...` (provedor fake) ou um **sha1 de 40 dígitos**
   (`sha1(checkoutId|status|chargeId)` = webhook do PagBank). `payments.paid_at` e
   o extrato (`credit_transactions.idempotency_key = payment-paid-{id}`) fecham a
   trilha.
3. **A notificação chegou?**
   `docker compose exec app grep payment.webhook storage/logs/laravel.log` —
   cada notificação aceita grava `payment.webhook.received` (com
   `provider_reference_id`, `status` e `method`); assinatura inválida/ausente
   grava `payment.webhook.rejected` (HTTP 400, nada muda). **Nenhuma linha** =
   nada chegou: em `localhost` o PagBank não alcança a aplicação, e é exatamente
   esse caso que a sincronização forçada cobre.
4. **A notificação chegou e não concluiu?** As mesmas linhas de log mostram onde
   travou: `payment.webhook.confirm_failed` (erro na reconsulta, com
   `provider_reference_id`), `payment.webhook.confirm_unmatched` (id que não é de
   pedido do OLHO), `payment.webhook.inconclusive` (nada foi liberado) — cada um
   deles seguido de `payment.reconcile.job` quando o worker conseguiu conferir
   (com `provider_status`, `status` e `paid`), `payment.reconcile.skipped`
   (nada a verificar) ou `payment.reconcile.failed` (provedor indisponível nas 3
   tentativas). O job roda no worker (`docker compose logs worker`), não na
   requisição do webhook.

Evidência no sandbox real (12/10): checkout `CHEC_DBBB022B-...` pago por **PIX**
(R$ 79,90) seguiu `awaiting_payment` porque o webhook não chegou;
`pagbank:sync-payment --pending` reconsultou o provedor, achou a cobrança
`CHAR_E63D6F8C-...` (`PAID`) dentro do pedido `ORDE_5AC8A61C-...` — o checkout
continuava `ACTIVE` e **sem** `charges` —, gravou `method = PIX`, creditou 500
créditos (`payment-paid-11`) e, repetido, não liberou nada de novo (0
confirmados, mesma transação no ledger). Na mesma varredura, o pedido #9 (cartão,
R$ 19,90) também foi confirmado (+100 créditos, `payment-paid-9`); os checkouts
`#6`/`#7` respondem `expired` no PagBank e seguem `awaiting_payment` no banco —
a reconciliação só **persiste** estado conclusivo de pagamento, então
`expired`/`cancelado` continuam sendo reconsultados a cada varredura.


## Métodos e parcelamento

- `PaymentMethod` (`PIX`, `DEBIT_CARD`, `CREDIT_CARD`) traduz a nomenclatura
  interna para a do PagBank.
- `payment_methods` envia um item `{"type": ...}` por método habilitado e
  **omite `brands`** (todas as bandeiras suportadas são aceitas). O Checkout do
  PagBank também suporta boleto/carteiras — não são enviados nem exibidos.
- O limite de parcelas do crédito vai em `payment_methods_configs` (contrato
  confirmado na doc oficial, "Objeto Checkout"):
  `{"type": "CREDIT_CARD", "config_options": [{"option": "INSTALLMENTS_LIMIT", "value": "6"}]}`.
  Só é enviado quando o máximo é > 1 (1 parcela é o padrão do checkout); débito
  nunca parcela (`payment_methods_configs` vale apenas para
  `CREDIT_CARD`/`DEBIT_CARD`, e a opção `INTEREST_FREE_INSTALLMENTS` não é usada
  para não assumir juros por conta própria).
- `PaymentSettings` fornece a lista de métodos habilitados, usada tanto pelo
  checkout quanto pela UI do visitante (o botão "Pagar com PagBank" só aparece
  com integração utilizável).
- O painel **exige ao menos uma forma marcada** quando o provedor é `pagbank`
  (`PaymentSettingsRequest::after()`): o gateway recusa um checkout sem
  `payment_methods`. Com o `fake` as formas são ignoradas e nada é exigido.

## Segurança

- Token cifrado em repouso (`settings`), mascarado na tela, ausente de logs e de
  mensagens de erro do cliente HTTP.
- CSRF dispensado **apenas** na rota do webhook (verificação real é a
  assinatura).
- Nenhum dado de cartão (PAN/CVV) passa ou é armazenado pelo OLHO — o pagamento
  ocorre no ambiente do PagBank.
- Nenhuma rota aceita "pago" vindo do front; a confirmação vem do webhook
  (ou da simulação, exclusivamente com `PAYMENT_PROVIDER=fake`).

## Arquivos

**Criados (app/migrations):** `app/Models/Setting.php`,
`app/Services/SettingsService.php`, `app/Payments/PaymentSettings.php`,
`app/Payments/PaymentMethod.php`, `app/Payments/CheckoutSession.php`,
`app/Payments/CheckoutProvider.php`, `app/Payments/PaymentUnavailableException.php`,
`app/Payments/PagBank/{PagBankClient,PagBankException,PagBankWebhookVerifier}.php`,
`app/Payments/PagBankProvider.php`,
`app/Http/Controllers/Admin/PaymentSettingsController.php`,
`app/Http/Requests/Admin/PaymentSettingsRequest.php`,
`resources/views/admin/payments/settings.blade.php`,
`resources/views/payments/return.blade.php`,
`database/migrations/2026_10_01_000210_create_settings_table.php`,
`database/migrations/2026_10_01_000220_add_pagbank_fields_to_payments_table.php`.

**Alterados:** `config/payments.php`, `app/Providers/AppServiceProvider.php`,
`app/Services/PaymentService.php`,
`app/Payments/{PaymentProvider,PaymentStatus,FakePixProvider}.php`,
`app/Models/Payment.php`, `app/Http/Controllers/PaymentController.php`,
`app/Http/Controllers/Guest/{InvestigationController,PaymentController}.php`,
`app/Http/Controllers/Admin/PaymentController.php`, `bootstrap/app.php`,
`routes/web.php`, `resources/views/admin/partials/nav.blade.php`,
`resources/views/admin/payments/index.blade.php`,
`resources/views/guest/investigations/partials/payment.blade.php`,
`resources/views/payments/show.blade.php`, `.env.example`.

**Sincronização forçada (webhook não chegou):**
`app/Console/Commands/PagBankSyncPaymentCommand.php` (pagamento único e
`--pending`), `app/Http/Controllers/PagBankSyncController.php` (`sync` por pedido
e `syncPending` em lote; `revalidate` e `revalidatePending` pelo worker),
`app/Services/PaymentService.php` (`reconcile`, `pendingPagBank`,
`reconcilePendingPagBank`, `queueReconcile`, `queuePendingPagBank`),
`resources/views/admin/payments/index.blade.php` (coluna **Ações** — botões
**Sincronizar** e **Revalidar** por pedido — e **Sincronizar pendentes** /
**Revalidar pendentes** no topo, com o resumo do flash), `routes/web.php`,
`app/Providers/AppServiceProvider.php` (rate limit `admin-sync`),
`tests/Feature/PagBankSyncTest.php`.

**Verificação no worker (webhook que não concluiu):**
`app/Jobs/ReconcilePaymentJob.php` (id do pagamento, `ShouldBeUnique`, `tries =
3`, `backoff = [60, 300]`), `app/Services/PaymentService.php` (`handleWebhook`,
`scheduleReconcileAfterWebhookFailure`, `paymentForWebhook`),
`app/Http/Controllers/PagBankSyncController.php` (`revalidate` e
`revalidatePending`), `resources/views/admin/payments/index.blade.php`,
`routes/web.php`, `tests/Feature/PaymentReconcileJobTest.php`.

## Testes

- `tests/Feature/AdminPaymentSettingsTest.php` — acesso restrito a admin, tela
  acessível, persistência com validação, **token cifrado no banco e nunca
  devolvido**, valores inválidos rejeitados, teste de conexão sucesso/falha.
- `tests/Feature/PagBankProviderTest.php` — `createCheckout` (headers, payload,
  `payment_methods` por tipo, `payment_methods_configs` com
  `INSTALLMENTS_LIMIT` só no crédito > 1 parcela), checkout sem link `PAY`
  lança, mapeamento `PAID`, webhook com assinatura válida confirmado após
  reconsulta, confirmação via `GET /charges/{id}` quando o checkout não traz
  `charges`, **status e método resolvidos pelo pedido do checkout
  (`orders[].id` → `GET /orders/{id}`) quando a consulta do checkout não traz
  `charges`** (regressão do caso real do sandbox), **pedido indisponível mantém
  `awaiting_payment`**, **webhook confirmado pelo pedido quando o payload traz
  apenas o id do checkout**, status do checkout preservado quando a cobrança está
  indisponível, assinatura inválida rejeitada (par de chaves EC gerado no teste;
  `Http::fake`, sem rede).
- `tests/Feature/PagBankSyncTest.php` — sincronização forçada: admin confirma
  checkout pago e libera a investigação avulsa; **créditos creditados quando a
  cobrança paga só existe no pedido do checkout** (regressão do sandbox);
  idempotência (2 sincronizações ⇒ 1 evento); checkout não pago segue
  aguardando; comando por id sequencial e por `reference_id`; `--dry-run` não
  confirma; pedido de outro provedor é ignorado; endpoint restrito a admin
  (401/403); coluna **Ações** só aparece para `provider = pagbank`;
  **varredura (`--pending`)**: confirma só os pedidos PagBank pendentes que o
  provedor devolve como pagos (um pago, um aguardando e um de outro provedor no
  mesmo banco, com o resumo `2 consultado(s): 1 confirmado(s), 1 pendente(s), 0
  falha(s)`), é idempotente (2ª execução não credita de novo), `--dry-run` não
  confirma, sem argumento (e sem `--pending`) falha com orientação, `--pending`
  com pagamento/`--force` é recusado, sem pendentes informa que nada há para
  sincronizar; **botão do painel** grava o resumo no flash, confirma o pedido e
  aparece na lista restrito a admin (401/403).
- `tests/Feature/GuestPagBankPaymentTest.php` — checkout avulso criado sem
  executar investigação, webhook assinado confirma e libera, reentregas
  idempotentes (1 evento), webhook sem assinatura ⇒ 400 sem liberar.
- `tests/Feature/PaymentReconcileJobTest.php` — **verificação no worker**:
  notificação que não conclui agenda `ReconcilePaymentJob`
  (`webhook-inconclusive`); erro na reconsulta ⇒ **500** (o PagBank reentrega) +
  job (`webhook-error`) e pedido segue pendente; id que não é de pedido do OLHO
  não agenda nada; o job confirma o checkout pago e credita **uma única vez**
  (idempotente), libera a investigação avulsa, e não gasta chamada à API para
  pedido já pago ou de outro provedor; botão **Revalidar** (agenda, restrito a
  admin, 422 para pedido de outro provedor/sem cobrança), **Revalidar pendentes**
  (2 pendentes agendados, pago e pedido já pago fora), botão na lista e flash do
  **Sincronizar** ("confirmado no PagBank" / "segue pendente").
- `tests/Feature/PaymentReconcileTest.php` — retorno do checkout com URL
  assinada: checkout pago libera os créditos **uma única vez** (revisita não
  duplica) e grava o método escolhido; checkout não pago mantém
  `awaiting_payment`; requisição sem assinatura ⇒ **403**.
- `tests/Feature/PaymentServiceTest.php` — cobrança direta registra
  `method = PIX`; integração incompleta (PagBank sem token) e integração
  desativada bloqueiam a cobrança sem criar pedido.

O `FakePixProvider` permanece como provedor de desenvolvimento/testes; nenhuma
funcionalidade existente foi removida.

## Configuração necessária (administrador)

1. **Obter o token de autenticação** — é a **única credencial** do Checkout (não
   há `client_id`/`client_secret`):
   - **Sandbox**: entre no **Portal do Desenvolvedor**
     (<https://portaldev.pagbank.com.br>) com a conta de desenvolvedor, aba
     **Tokens**, e copie o token (o par de chaves do Sandbox é sempre o mesmo);
   - **Produção**: entre no **PagBank** (desktop) com o usuário **master**,
     menu lateral **Vendas → Integrações → Gerar Token**.
     Gerar o token **não** libera todas as funcionalidades: o produto Checkout
     exige **homologação** (formulário oficial; SLA de até 4 dias úteis) e o
     **débito** exige aprovação interna prévia do PagBank.
2. Em `/admin/settings/payments`: provedor **PagBank**, ambiente, token, métodos
   (PIX/débito/crédito), validade do PIX e máximo de parcelas; salvar. Cole
   **apenas o valor do token** (sem `Bearer`, sem aspas). O card mostra `Token em
   uso` mascarado (início…fim + comprimento): compare o comprimento com o valor do
   portal — a normalização une linhas coladas, então um comprimento com o dobro do
   esperado indica **dois valores colados** e o token precisa ser salvo de novo.
3. Clicar **Testar conexão** (usa `GET /public-keys/webhook` — o tipo é **path
   param** exigido pelo OpenAPI oficial — sem cobrança). Se falhar, o painel
   mostra o status HTTP, o **endpoint testado**, a **prévia mascarada do token em
   uso** e o que revisar.
4. **Webhook: não há o que cadastrar no painel do PagBank.** No produto Checkout
   a URL de notificação é enviada **no payload de cada checkout**
   (`notification_urls` e `payment_notification_urls` =
   `https://<host>/webhooks/payments/pagbank`, já implementado em
   `PagBankProvider::buildCheckoutPayload()`). A tela apenas exibe a URL para
   conferência. A autenticidade de cada notificação é validada por assinatura
   (`x-payload-signature`, ECDSA/SHA-256) contra a chave pública de
   `GET /public-keys/webhook`.
5. `PAYMENT_PROVIDER=fake` continua o default do repositório: em produção a
   seleção efetiva vem da configuração administrativa.

## Pendências / dependências externas

- **`charges` na consulta do checkout (`GET /checkouts/{id}`)**: **resolvido** —
  verificado no sandbox que o array realmente não vem na consulta do checkout,
  nem **depois** do pagamento: com um cartão **aprovado** o checkout continua
  `status: ACTIVE` e a cobrança (`CHAR_...`, `status: PAID`, com `paid_at`)
  aparece apenas no **pedido** do checkout (`orders[].id` →
  `GET /orders/{ORDE_...}.charges[]`). A confirmação (webhook, página de retorno
  e sincronização forçada) passou a seguir essa referência via
  `PagBankProvider::withChargesFromOrders()`, sem deixar de aceitar
  `GET /charges/{CHAR_...}` quando a notificação já autenticada traz o id da
  cobrança. Nenhum status não conclusivo é convertido em pago: pedido ou
  cobrança indisponível ⇒ o pagamento continua **aguardando**.
  *Sintoma que motivou a correção:* cartão aprovado no sandbox e créditos não
  liberados na sincronização (`pagbank:sync-payment 10` respondia
  `awaiting_payment` com o pedido `PAID` no PagBank).
- **Escopo da notificação**: o OLHO correlaciona o pagamento pelo id do
  **checkout** que ele criou (`payments.external_id` = `CHEC_...`) e o `reference_id`
  enviado é `olho-{payment.id}`. Notificação que traga apenas o id do checkout já
  é resolvida pelo pedido do checkout (`orders[].id`). Se a conta PagBank
  notificar objetos de pedido (`ORDE_...`) ou de cobrança (`CHAR_...`) **sem**
  vínculo com o checkout gravado, será preciso correlacionar também pelo
  `reference_id` — ajuste pontual em `PagBankProvider::validateWebhook()`.
- **App Checkout / credenciais**: requer conta PagBank habilitada ao produto
  Checkout/Link de Pagamento com token válido; sem isso o teste de conexão falha
  e o botão de pagamento não é exibido. O método **débito exige aprovação interna
  prévia** do PagBank.
- **Webhook**: a chave pública vem de `GET /public-keys/webhook` e deve ser
  mantida em cache (renovável via `PUT /public-keys`, no máximo a cada 7 dias da
  criação do par). No produto Checkout **não** existe cadastro de URL em "Minha
  Conta": a URL de notificação é enviada em cada checkout
  (`notification_urls`/`payment_notification_urls`).
- Regras de negócio globais preservadas: ledger imutável, saldo nunca negativo,
  jobs apenas com IDs, fontes públicas e idempotência por `run_id + source_id`.

## Diagnóstico rápido (Testar conexão)

| Sintoma exibido | Causa provável | Correção |
|---|---|---|
| `HTTP 403` + `Invalid key=value pair (missing equal-sign) in Authorization header (hashed with SHA-256 and encoded with Base64)` | resposta do **gateway** do PagBank: rota inexistente (ex.: `GET /public-keys?type=webhook` em vez do path param `/public-keys/webhook`) ou header `Authorization` fora de `Bearer <token>` — **o token não chega a ser avaliado** | usar o endpoint oficial (`GET /public-keys/webhook`, já corrigido no cliente) e salvar apenas o valor do token |
| `error_messages[].code = UNAUTHORIZED` ("Invalid credential. Review AUTHORIZATION header") / `HTTP 401` | token ausente, expirado, revogado ou do **outro ambiente** | gerar/copiar o token do ambiente selecionado (Sandbox ≠ Produção) |
| `allowlist_access_required` / `HTTP 403` | vendedor ainda **não homologado** para o produto Checkout, ou débito sem aprovação prévia | concluir a homologação no PagBank |
| `HTTP 429` | limite de requisições da conta | repetir depois |
| `HTTP 5xx` | instabilidade no PagBank | repetir; se persistir, abrir chamado |

O OLHO nunca mostra o token nem o corpo cru da resposta: a mensagem é derivada do
status HTTP e de palavras-chave conhecidas da doc oficial. O card ainda exibe
**Endpoint testado** (`GET {host}/public-keys/webhook`) e **Token em uso** em
formato mascarado (início…fim + comprimento), para conferência contra o portal.

## Fontes (documentação oficial conferida)

- `POST /checkouts` — <https://developer.pagbank.com.br/reference/criar-checkout>
- `GET /checkouts/{id}` — <https://developer.pagbank.com.br/reference/consultar-checkout>
- `GET /public-keys/{type}` (tipo como **path param**: `/public-keys/webhook`) —
  <https://developer.pagbank.com.br/reference/consultar-chave-publica>
- Validação de autenticidade dos webhooks (`x-payload-signature`, ECDSA/SHA-256) —
  <https://developer.pagbank.com.br/reference/validacao-de-autenticidade>
- Token de autenticação (Sandbox/Produção) —
  <https://developer.pagbank.com.br/docs/token-de-autenticacao>
- Objeto Checkout (`payment_methods`, `payment_methods_configs`,
  `INSTALLMENTS_LIMIT`, `links`, `status`) — <https://developer.pagbank.com.br/reference/objeto-checkout>
- Guia Checkout e Link de Pagamento — <https://developer.pagbank.com.br/docs/checkout>
- Webhooks do Checkout (status transacionais) — <https://developer.pagbank.com.br/reference/webhooks-checkout>
- Confirmar autenticidade da notificação (`x-payload-signature`,
  `SHA256withECDSA`, corpo bruto) — <https://developer.pagbank.com.br/reference/validacao-de-autenticidade>
- Ambientes — <https://developer.pagbank.com.br/docs/ambientes-disponiveis>

## Checklist (prompt 45)

- [x] Auditoria do sistema de pagamento antes de alterar código
- [x] Arquitetura reutilizada (`PaymentProvider`/`PaymentService`), sem reconstrução
- [x] PIX, débito e crédito configuráveis; só esses três métodos
- [x] Parcelamento do crédito configurável; débito sem parcelamento
- [x] Sandbox e Produção
- [x] Checkout/Link de pagamento
- [x] Webhooks com validação de assinatura e reconsulta
- [x] Retorno do checkout reconcilia com o provedor (libera sem depender do webhook)
- [x] Método do pagamento (PIX/débito/crédito) registrado no pedido
- [x] Cobrança bloqueada com mensagem acionável quando a integração está incompleta
- [x] Teste de conexão sem efeito financeiro
- [x] Ativação/desativação da integração
- [x] Guest e usuário autenticado (créditos) preservados
- [x] Histórico de pagamentos com filtros e colunas do PagBank
- [x] Logs sem token/segredos
- [x] Testes automatizados e Pint (ver comando no AGENTS.md)

## Atualização (ver `docs/41-pagbank-dados-do-pagador.md`)

Dois pontos desta especificação mudaram depois do primeiro teste real no sandbox:

- **Dados do pagador — sem coleta no OLHO**: o checkout é criado com
  `customer_modifiable: true` e **sem** o objeto `customer`. Pela doc oficial, o
  `customer` só é obrigatório quando `customer_modifiable = false`; com o valor
  padrão, quem coleta nome, CPF/CNPJ e e-mail é a **página hospedada do PagBank**.
  O OLHO não pede documento no cadastro, no perfil, na compra de créditos nem no
  formulário da avulsa — e nem chega a recebê-lo.
- **URLs no payload**: `notification_urls`/`payment_notification_urls` **e** as
  URLs de retorno do checkout (`redirect_url`/`return_url`) só saem quando a
  `APP_URL` é HTTPS público. O PagBank **valida** esses campos e recusa a cobrança
  inteira (`400 invalid_value`) quando apontam para `http://localhost...` — foi o
  bloqueio do teste manual. Em desenvolvimento o comprador paga na página do
  provedor e a confirmação fica com a reconciliação; o painel
  (`/admin/settings/payments`) avisa quando a URL não é pública.
- **Compra de créditos sem modal**: o PIX deixou de abrir modal — o botão é um
  POST de formulário que redireciona direto para a página do provedor (ou para a
  página do pedido, quando o PIX é emitido na própria aplicação pelo provedor
  `fake`). O destino abre em **outra aba** (`target="_blank"`, que já implica
  `noopener` em formulários pela especificação HTML): a tela de créditos continua
  à mão, o comprador volta, recarrega e confere o saldo no painel. O link "PAGAR
  COM PAGBANK" da página do pedido e da página pública da avulsa segue a mesma
  regra — nenhum salto faz o comprador perder a tela de onde veio. Nenhum
  JavaScript no caminho do pagamento.
- **Erro `400`**: passou a trazer o diagnóstico do PagBank (`error_messages`) na
  mensagem, no log e no painel — o texto genérico que culpava o token foi
  corrigido; na tela do comprador o aviso aparece acima do botão.


