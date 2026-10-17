# 42 — PagBank em produção: checklist de subida

> Situação em 17/10/2026: a integração está completa, testada no sandbox
> (pedidos `paid` de ponta a ponta) e pronta para produção **no código**. O que
> falta para vender é operacional e não se resolve com deploy: **conta
> homologada**, **URL pública HTTPS** e **chave pública do webhook de produção**.

Este documento é o roteiro de subida e o runbook do que conferir quando um
pagamento não libera. A especificação da integração continua em
`docs/40-pagbank-configuravel.md` e a do checkout sem dados do pagador em
`docs/41-pagbank-dados-do-pagador.md`.

## O que o código já garante

| Garantia | Onde |
|---|---|
| Confirmação só com resposta do provedor (zero-trust) | `PaymentService::reconcile()` / `PagBankProvider::validateWebhook()` |
| Webhook autenticado por ECDSA/SHA-256 sobre o corpo bruto | `PagBankWebhookVerifier` + `x-payload-signature` |
| Idempotência (evento, crédito, execução) | `payment_events.external_event_id`, `payments.idempotency_key`, `payment-paid-{id}` |
| Confirmação sob demanda ("Já paguei") para conta e avulsa | `PaymentController::verify()`, `Guest\PaymentController::verify()` |
| Retorno do checkout assinado (6h) que reconcilia sozinho | rota `payments.return` (`signed`) |
| Webhook que chega e não conclui agenda verificação no worker | `PaymentService::handleWebhook()` → `ReconcilePaymentJob` |
| Painel para revalidar pedido/pendentes | `/admin/payments` (rotas `admin.payments.revalidate*`) |
| Usuário não paga para descobrir indisponibilidade | `PaymentService::available()` + aviso no painel |

**Decisão de custo:** não existe varredura agendada (cron) de pagamentos
pendentes. Quem garante a confirmação é o webhook, o retorno do checkout e o
botão **"Já paguei"**; o painel tem a revalidação manual. Nenhum worker fica
consultando o PagBank de tempos em tempos.

## Os três pré-requisitos de produção

### 1. Homologação da conta (allowlist do PagBank)

Sintoma enquanto a conta não está liberada: no ambiente `production`, `POST
/checkouts` responde **403** com `error_messages[].error =
allowlist_access_required` (o sandbox continua funcionando; foi o que travou o
teste manual em 10/2026).

Como resolver: solicitar a homologação pelo formulário público do PagBank —
<https://app.pipefy.com/public/form/e7ouI6XN> — com a conta PJ, o site já
publicado (produtos, preços, termos e política de privacidade) e o domínio
definitivo. A análise e o prazo são do PagBank.

Não há contorno técnico: token de produção válido **não** passa pela allowlist.
Enquanto o status não mudar, mantenha o provedor desativado no painel ou use o
sandbox.

### 2. URL pública HTTPS (`APP_URL`)

`PaymentSettings::webhookIsPublic()` só é verdadeiro quando a URL de notificação
começa com `https://`. Com `APP_URL=http://localhost` as **três** URLs saem do
payload do checkout (`notification_urls`, `payment_notification_urls`,
`redirect_url`/`return_url`) — o PagBank recusa endereço local
(`400 invalid_value`).

Em produção:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://seu-dominio-real
SESSION_SECURE_COOKIE=true
```

Dois cuidados:

- **Proxy/TLS**: se o TLS termina no proxy e a aplicação recebe HTTP, configure
  os proxies confiáveis (`X-Forwarded-Proto`) para `route()` gerar `https://`
  também dentro da requisição. Sem isso a URL pode sair `http://` e as URLs do
  checkout são omitidas (o pagamento funciona, mas sem webhook/retorno).
- **`APP_KEY` estável**: a URL de retorno é assinada; trocar a chave em produção
  invalida os retornos já emitidos.

O painel `/admin/settings/payments` mostra um aviso enquanto a URL não for
pública.

### 3. Chave pública do webhook (produção)

No sandbox a chave já existe e `GET /public-keys/webhook` responde. Na conta de
produção ainda não homologada essa rota respondeu **404** — sem chave, o OLHO
não valida assinatura (`PagBankWebhookVerifier::verify()` devolve `false`) e o
webhook responde `400`.

Criar/solicitar a chave na API oficial, com o **token de produção**:

```bash
curl -X POST https://api.pagseguro.com/public-keys \
  -H "Authorization: Bearer $PAGBANK_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"type":"webhook"}'
```

Depois confirme que a chave é servida (é essa rota que o OLHO consulta a cada
notificação, sem cache):

```bash
curl https://api.pagseguro.com/public-keys/webhook \
  -H "Authorization: Bearer $PAGBANK_TOKEN"
```

Sem a chave o checkout continua sendo criado e pago, mas a confirmação fica com
o botão **"Já paguei"**, o retorno assinado e a revalidação no painel. Com a
chave, o webhook passa a ser o caminho normal (e mais rápido).

> O **Teste de conexão** do painel usa a mesma rota `GET
> /public-keys/webhook`: um `404` ali significa "sem chave de webhook", **não**
> token inválido (`401`). A mensagem do painel já distingue os dois casos.

## Passo a passo da subida

1. Deploy com `APP_ENV=production`, `APP_DEBUG=false` e `APP_URL=https://…`.
2. `php artisan migrate --force`, `config:cache`, `route:cache`, `view:cache`.
3. Redis e os serviços `worker`/`scheduler` ativos (o worker é quem confirma
   pelo `ReconcilePaymentJob` quando o webhook chega inconclusivo).
4. Painel → **Configurações → Pagamentos**: provedor PagBank, ambiente
   `production`, token de produção, PIX/cartão habilitados, parcelas máximas.
5. Crie a chave pública do webhook (seção 3) e rode o **Teste de conexão**.
6. Faça o **PIX de R$ 1** do roteiro abaixo com uma conta de teste.
7. Confirme que `POST https://seu-dominio/webhooks/payments/pagbank` responde
   (rota pública, isenta de CSRF; sem `Authorization` do OLHO — a autenticação é
   a assinatura do PagBank).
8. Só então libere a venda.

## Roteiro de validação real (PIX de R$ 1)

Objetivo: provar, no ambiente final, que pagamento aprovado vira crédito (conta)
ou execução (avulsa) — e que repetir a confirmação não duplica nada.

1. Em **Admin → Pacotes**, crie um pacote `kind = credits`, ativo, com **1
   crédito** e **R$ 1,00**. (Não altere o preço da consulta avulsa em produção
   só para testar.)
2. Com uma conta de teste, compre o pacote pagando por **PIX**.
3. No checkout do PagBank, conclua o pagamento.
4. Volte ao OLHO e clique em **JÁ PAGUEI — VERIFICAR AGORA** na página do pedido
   (ou aguarde o webhook). O esperado: página recarregada com "Pagamento
   confirmado" e saldo +1.
5. Confira o banco (via `docker compose exec app php artisan tinker` ou MySQL):

```php
$payment = App\Models\Payment::query()->latest('id')->first();

$payment->only([
    'id', 'provider', 'environment', 'status', 'paid_at',
    'provider_checkout_id', 'external_id', 'method',
]);

App\Models\PaymentEvent::query()
    ->where('payment_id', $payment->id)
    ->get(['type', 'status', 'external_event_id', 'created_at']);

App\Models\CreditTransaction::query()
    ->where('idempotency_key', 'payment-paid-'.$payment->id)
    ->first();

App\Models\Wallet::query()->where('user_id', $payment->user_id)->value('balance');
```

O que deve aparecer:

- `payments.status = paid`, `paid_at` preenchido, `environment = production`;
- pelo menos um `payment_events` com `status = paid` (o `external_event_id`
  varia: hash do webhook ou `reconcile-paid-CHEC_…` quando veio da consulta);
- **uma** `credit_transactions` com `idempotency_key = payment-paid-{id}` e
  `balance_after` igual ao saldo;
- saldo da carteira = soma do ledger.

6. Repita o clique em **"Já paguei"** e confirme que nada mudou (mesmo saldo,
   mesmo evento). Se o webhook chegar depois, também não duplica.
7. Para a **avulsa** (sem login): crie a investigação em `/investigacao`, pague
   o PIX, clique em **"Já paguei"** na página com token e confira:

```php
$investigation = App\Models\Investigation::query()->latest('id')->first();

$investigation->only(['id', 'origin', 'status', 'current_run_id']);
$investigation->guestPayment->only(['provider', 'status', 'paid_at']);
$investigation->currentRun?->only(['status', 'credits_reserved', 'credits_consumed']);
```

Esperado: `payments.status = paid`, investigação saindo de `draft` para
`queued`/`running` e terminando `completed`/`partial`, run com
`credits_reserved = 0` e `credits_consumed = 0` (a avulsa é pré-paga).

8. Observe os logs de confirmação:

```bash
docker compose logs -f app worker | grep -E 'payment\.(webhook|verify|sync|guest_verify)'
```

## Quem confirma o quê (e quando)

| Caminho | Gatilho | Consulta o PagBank? | Observação |
|---|---|---|---|
| Webhook | PagBank chama `/webhooks/payments/pagbank` | Sim (zero-trust: consulta o checkout/pedido/cobrança antes de confirmar) | Caminho normal em produção; exige chave pública do webhook |
| **"Já paguei"** | Clique do comprador (conta ou avulsa) | Sim, na hora | Fallback do webhook e atalho para não esperar; idempotente |
| Retorno do checkout | Comprador volta pelo `redirect_url` assinado | Sim | Vale 6h; página pública `payments.return` |
| Worker | Webhook autenticado que não concluiu | Sim, `ReconcilePaymentJob` | Cobre provedor atrasado/rede instável |
| Painel | **Revalidar** / **Revalidar pendentes** | Sim (via worker) | Operação; admite `422` para pedido sem cobrança |
| Polling da página | A cada 5s no navegador | **No máximo 1 vez a cada 20s por pedido** | `PAYMENT_RECONCILE_COOLDOWN_SECONDS`; dentro da janela só lê o banco |
| Avulsa `/status` | A cada 5s no navegador | **Não** | Só lê banco; a consulta é o botão "Já paguei" |

Não há cron/varredura de pendentes: essa é uma decisão explícita de custo. Um
pedido que ninguém abriu e cujo webhook falhou permanece `awaiting_payment`
(visível no painel) até uma revalidação manual — o pagamento não se perde, o
ledger só é liberado com resposta do provedor.

## Custos e limites de chamada

- O PagBank **não cobra por requisição** (a receita dele é o MDR); o custo de
  consultar é latência, rate limit e infraestrutura.
- "Já paguei" (conta): 6/min por usuário e 3/min por pedido.
- "Já paguei" (avulsa): 6/min por IP, 4/min por token e 20/h por token.
- Polling do pedido: `payments.reconcile_cooldown_seconds` (20s por padrão,
  ajustável por `PAYMENT_RECONCILE_COOLDOWN_SECONDS`).
- Webhook: 1 `GET /public-keys/webhook` por notificação (a chave não é
  cacheada). É o único custo marginal do caminho normal.

## Troubleshooting

| Sintoma | Causa provável | O que fazer |
|---|---|---|
| `403 allowlist_access_required` ao criar checkout | Conta de produção não homologada | Concluir a homologação (Pipefy); manter sandbox/desativado até lá |
| `401 invalid_authorization_header` | Token do ambiente errado (sandbox × produção) ou revogado | Salvar o token do ambiente selecionado no painel |
| Painel: teste de conexão `404` | Sem chave pública de webhook na conta | Criar em `POST /public-keys` (`{"type":"webhook"}`) |
| Webhook responde `400` | Assinatura inválida/ausente, chave pública ausente ou corpo alterado | Conferir chave e proxy (o corpo bruto precisa chegar intacto) |
| Pagamento pago e pedido ainda `awaiting_payment` | Webhook não chegou (URL não pública, bloqueio de firewall) e ninguém clicou | "Já paguei" na página ou **Revalidar** no painel; conferir `APP_URL`/HTTPS |
| `notification_urls` ausentes no payload | `APP_URL` não é HTTPS público (ou proxy não confiável) | Corrigir `APP_URL`/`TrustProxies`; o pagamento funciona, mas sem webhook |
| "Ainda não identificamos o pagamento" após pagar | PagBank ainda processando o PIX | Esperar alguns instantes e clicar de novo (o limite é por minuto) |
| Avulsa paga não libera | Pagamento expirado no OLHO antes da confirmação | O webhook/consulta tardia ainda libera (`release()` aceita `expired`), desde que o PagBank confirme o pagamento |
| `419` no "Já paguei" | Sessão expirada | Recarregar a página e repetir (o token da avulsa não depende de sessão) |

## Limitações registradas

- Persistir `expired`/`cancelled` de todos os eventos do provedor continua fora
  de escopo; a página mostra o estado que o PagBank devolve na consulta.
- Não há alerta automático de "pedido parado" — o painel `/admin/payments` é a
  visão operacional (e a revalidação manual, o remédio).
- A chave pública do webhook não é cacheada; se o volume de notificações
  crescer, cachear é a próxima otimização.
- A página de retorno do checkout é pública e não reconstrói o token da avulsa
  (o banco guarda só o hash): o visitante continua na aba original da
  investigação, que é onde o botão "Já paguei" vive.

## Arquivos e testes

- `app/Payments/PaymentSettings.php` (token/ambiente/`webhookIsPublic`),
  `app/Payments/PagBankProvider.php`, `app/Payments/PagBank/PagBankClient.php`,
  `app/Payments/PagBank/PagBankWebhookVerifier.php`,
  `app/Services/PaymentService.php` (`reconcile`, `reconcileThrottled`,
  `confirmPayment`), `app/Http/Controllers/PaymentController.php`,
  `app/Http/Controllers/Guest/PaymentController.php`,
  `app/Providers/AppServiceProvider.php` (limitadores), `routes/web.php`,
  `config/payments.php`.
- Telas: `resources/views/payments/show.blade.php`,
  `resources/views/guest/investigations/partials/payment.blade.php`.
- Testes: `tests/Feature/PaymentVerifyTest.php` (8 cenários: crédito pago,
  pendente, falha do provedor, pedido de terceiro, liberação da avulsa,
  avulsa pendente, fake não confirma, janela do polling),
  `tests/Feature/PaymentReconcileTest.php`,
  `tests/Feature/GuestPagBankPaymentTest.php`,
  `tests/Feature/PaymentReconcileJobTest.php`, `tests/Feature/PagBankProviderTest.php`.
