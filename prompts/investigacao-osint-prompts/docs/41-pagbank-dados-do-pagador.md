# PagBank: dados do pagador e diagnóstico de recusa (prompt 45)

Complemento do `docs/40-pagbank-configuravel.md`, escrito depois do **primeiro
teste real** (sandbox) da integração. Cobre dois pontos que só aparecem com o
provedor de verdade: **por que o checkout é recusado com `400`** e **quais dados
o PagBank exige de quem paga**.

> **Atualização (decisão final — o OLHO não coleta dados do pagador).** A coleta
> de nome e CPF/CNPJ descrita abaixo foi **revertida**. O checkout passou a ser
> criado com `customer_modifiable: true` e **sem** o objeto `customer`: pela doc
> oficial, `customer` é obrigatório **apenas** quando `customer_modifiable = false`,
> então com o default `true` a própria página hospedada coleta os dados do
> comprador. Saíram do projeto `users.tax_id` (migration
> `2026_10_02_000240_drop_tax_id_from_users_table`), `App\Rules\TaxId`,
> `App\Payments\PixPayer`, os campos `payer_*` da interface e as capacidades
> `requiresPayerDocument()`/`requiresPayerIdentity()`. As seções abaixo ficam como
> **registro histórico** do prompt 45; o comportamento vigente está em
> `docs/40-pagbank-configuravel.md` (seção "Atualização") e nas seções marcadas
> como "vigente" aqui.
>
> Fonte: <https://developer.pagbank.com.br/reference/objeto-checkout> —
> "O objeto `customer` torna-se obrigatório caso o valor de `customer_modifiable`
> seja `false`."

## Sintoma observado

Com token válido (o teste de conexão passava) a criação de cobrança falhava — no
PIX e no cartão — com:

```text
PagBank: falha ao criar checkout (HTTP 400). Requisição rejeitada pelo PagBank:
revise o token e os métodos habilitados.
```

A mensagem mandava revisar o que estava certo: o corpo da resposta (que contém a
causa) era descartado por decisão de privacidade e o texto genérico de `400`
apontava para credencial/métodos.

## Diagnóstico acionável

- `PagBankException::forResponse()` extrai `error_messages[]` da resposta:
  `error` (código) e `parameter_name` (campo recusado). São os dois campos de
  **enum/caminho de campo** da doc oficial — passam por limpeza
  (`[A-Za-z0-9_.\[\]]`, 64 caracteres) para que nenhum valor digitado pelo
  comprador possa ser reproduzido na mensagem. O corpo **nunca** é ecoado.
- A mensagem passa a terminar com `O PagBank apontou: invalid_value em
  customer.tax_id.` e o texto do `400` deixou de culpar o token.
- A **última falha de cobrança** é gravada em
  `payment.pagbank.last_checkout_error` (sem token, sem payload), exibida no card
  do PagBank em `/admin/settings/payments` e limpa automaticamente quando uma
  cobrança é aceita. A gravação fica em `PaymentService`, **fora** da transação do
  pedido: dentro dela o rollback levaria junto o diagnóstico justamente quando
  ele é mais necessário (e o pedido nem chega a existir). O log
  `payment.pagbank.failed` (provedor, `payment_id`, contexto, status HTTP e os
  mesmos `error`/`parameter_name`) é escrito pelo próprio provedor.
- **Comprador**: a recusa não vira `500`. O checkout da conta volta para a tela de
  créditos e a avulsa volta ao formulário — nos dois casos com a mensagem "Não foi
  possível iniciar o pagamento agora. O motivo foi registrado para o administrador"
  visível acima do botão (sem jargão do gateway na tela do cliente).

## Dados do pagador (vigente: o OLHO não coleta)

A doc oficial é explícita no objeto **Checkout**: o objeto `customer` só é
**obrigatório** quando `customer_modifiable = false`; com o valor padrão (`true`)
o próprio PagBank coleta nome, CPF/CNPJ e e-mail na página hospedada.

| API | Objeto | Decisão do OLHO |
|---|---|---|
| Checkout/Link — `POST /checkouts` (PIX, débito, crédito) | `customer` é **opcional** com `customer_modifiable = true` | **não enviado** (`customer_modifiable: true`): a página hospedada coleta os dados |
| Pedidos — `POST /orders` (PIX copia e cola na aplicação) | `customer.tax_id` obrigatório | **não usado**: o PIX do PagBank é servido pelo checkout hospedado, não por `POST /orders` |

- **Nada é pedido em lugar nenhum**: cadastro (nome, e-mail e senha), perfil,
  tela de créditos e formulário da avulsa não têm campo de documento. O
  CPF/CNPJ é digitado **pelo próprio pagador** no ambiente do PagBank.
- **Nada é persistido**: não existem `users.tax_id`, `App\Rules\TaxId`,
  `App\Payments\PixPayer`, `requiresPayerDocument()`, `requiresPayerIdentity()`
  nem `PaymentService::payerIdentityRequired()`.
- O `Payment` guarda apenas o essencial: valor, método, status, `external_id`,
  `checkout_url` e metadados do provedor.


## Onde os dados são coletados (vigente: em nenhum ponto do OLHO)

| Fluxo | O que o OLHO pede | O que o OLHO guarda |
|---|---|---|
| Conta (`/credits`) | apenas o canal (PIX ou cartão) | nada do pagador: o `Payment` guarda valor, método, status, `external_id` e metadados do provedor |
| Avulsa (`/investigacao`) | os dados do **investigado** (username/e-mail/telefone/nome/cidade/UF) — nada de pagador | nada do pagador; o pedido guarda só o estado da cobrança |

- **Cadastro**: nome, e-mail e senha. Sem sobrenome obrigatório, sem CPF/CNPJ e
  sem qualquer coluna de documento (`RegistrationTest` verifica que `tax_id` nem
  existe em `users`).
- **Perfil**: apenas nome e e-mail (`ProfileUpdateRequest`).
- **PIX e cartão**: o comprador conclui na página hospedada do PagBank e informa
  ali os próprios dados; o OLHO só acompanha o estado do pedido. O destino abre em
  **outra aba** (`target="_blank"` nos botões de `/credits`, no link da página do
  pedido e no botão da página pública da avulsa), então a tela anterior continua
  aberta — o comprador volta, recarrega e vê o saldo/a investigação.
- **Sem provedor hospedado (`fake`)**: nada é pedido — a jornada de
  desenvolvimento e os mocks continuam iguais.

## URLs enviadas ao PagBank (notificação e retorno)

As **três** URLs do payload só entram quando a `APP_URL` é **HTTPS público**
(`PaymentSettings::webhookIsPublic()`): `notification_urls`,
`payment_notification_urls` e as URLs de retorno do checkout (`redirect_url` e
`return_url` — uma URL assinada de `payments.return`).

O motivo foi medido no sandbox (02/10): com `APP_URL=http://localhost:8080` o
PagBank **recusa a cobrança inteira** com `400 invalid_value em redirect_url;
invalid_value em return_url` — nenhum checkout é criado e o botão de pagamento
parece não funcionar (o comprador fica sem link para pagar). Como o provedor valida
o endereço, um app local não recebe o comprador de volta: em desenvolvimento o
pagamento acontece na página do PagBank e a confirmação fica com a
**reconciliação** (`payments.show`/painel), que consulta o estado real na API antes
de liberar qualquer crédito. Com `APP_URL` público HTTPS as três URLs voltam ao
payload e o retorno (`payments.return`, idempotente) confirma na hora.

O painel (`/admin/settings/payments`) avisa quando a URL não é pública.

## Arquivos

**Vigentes:** `app/Payments/PagBank/PagBankException.php` (diagnóstico do `400`),
`app/Payments/PagBankProvider.php` (checkout com `customer_modifiable: true` e
**sem** `customer`; URLs de notificação **e** de retorno só com endereço público;
memória da última falha), `app/Payments/PaymentSettings.php`
(`notificationUrls()`, `webhookIsPublic()`, última falha), `app/Payments/CheckoutProvider.php`,
`app/Http/Controllers/PaymentController.php`,
`app/Http/Requests/StoreGuestInvestigationRequest.php`,
`app/Http/Requests/ProfileUpdateRequest.php`,
`app/Http/Controllers/Auth/RegisteredUserController.php`,
`app/Http/Controllers/Admin/PaymentSettingsController.php`,
`database/factories/UserFactory.php` e as views (`payments/index`,
`guest/investigations/create`, `auth/register`,
`profile/partials/update-profile-information-form`, `admin/payments/settings`).

**Revertidos** (existiam só na versão que coletava o pagador):
`App\Payments\PixPayer`, `App\Rules\TaxId`, a coluna `users.tax_id` e as
capacidades `requiresPayerDocument()`/`requiresPayerIdentity()`/
`PaymentService::payerIdentityRequired()`.

## Testes

- `PagBankProviderTest`: link `PAY`; checkout **sem** `customer` (com
  `customer_modifiable: true`); `notification_urls`, `redirect_url` e
  `return_url` presentes só com HTTPS público; `400` com `error_messages` →
  diagnóstico na exceção e no log; limpeza ao aceitar.
- `CreditCheckoutTest`: PIX e cartão redirecionam para o checkout do PagBank
  **sem** exigir documento; PIX do provedor local vai para a página do pedido;
  recusa `400` do provedor → aviso na tela (sem `500`), nenhum pedido e
  diagnóstico no painel (gravado fora da transação).
- `GuestPagBankPaymentTest`: a avulsa cria a cobrança **sem** pedir dados do
  pagador (a página não traz `payer_tax_id`); recusa do provedor tratada sem
  `500`.
- `RegistrationTest`: o cadastro **não** expõe nem aceita `tax_id` (a coluna não
  existe em `users`).
- `AdminPaymentSettingsTest`: painel mostra a última falha e o aviso de URL não
  pública.

## Pendências / dependências externas

- **Resolvido (02/10, teste manual)**: o `400 invalid_value` em `redirect_url` e
  `return_url` era o bloqueio real do fluxo local — resolvido com a regra de URL
  pública. Repetido contra o sandbox de verdade: conta e avulsa passaram a criar o
  checkout (`awaiting_payment`) e devolver o link de pagamento.
- Se a conta continuar recusando (`400`), o **diagnóstico exibido no painel**
  nomeia o campo: os candidatos restantes são homologação do produto Checkout
  (`allowlist_access_required`), configuração de PIX na conta e limites por
  parâmetro (valor máximo de item, URL acima de 255 caracteres).
- O débito continua exigindo aprovação interna do PagBank (docs/40).
- O diagnóstico foi verificado com `Http::fake` (o sandbox exige conta
  homologada) e o payload conferido contra o OpenAPI oficial.

## Fontes conferidas

- Criar Checkout (OpenAPI + exemplo de `400` com `error_messages`) —
  <https://developer.pagbank.com.br/reference/criar-checkout>
- Objeto Checkout (`customer` obrigatório quando `customer_modifiable = false`;
  `email` 10-60; `config_options[].value` é **String**;
  `notification_urls`/`payment_notification_urls` 5-100;
  `redirect_url`/`return_url` 255) —
  <https://developer.pagbank.com.br/reference/objeto-checkout>
- Objeto Order (`customer.tax_id` obrigatório; `name` 1-120; `email` 10-255) —
  <https://developer.pagbank.com.br/reference/objeto-order>
- Códigos de erro do Checkout —
  <https://developer.pagbank.com.br/reference/codigos-de-erro-checkout>

**Migrações:** `2026_10_02_000230_add_tax_id_to_users_table.php` (etapa 45) e
`2026_10_02_000240_drop_tax_id_from_users_table.php` (remove a coluna — estado
vigente).
