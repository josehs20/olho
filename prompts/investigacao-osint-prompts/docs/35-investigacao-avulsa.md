# Investigação avulsa — visitante sem conta (prompt 41)

Jornada pública de investigação paga por consulta única, **sem cadastro**, com
preço fixo configurável pelo admin e acesso aos resultados por **token de alta
entropia**. O fluxo autenticado (créditos, ledger, retry, reexecução) permanece
intacto.

## Decisões de produto

1. **"Pagou, usou, acabou"** — a avulsa **não consome créditos**: o visitante paga
   um valor fixo via PIX e tem **uma execução**. Nenhuma carteira, `credit_reservations`
   ou `credit_transactions` é criada/alterada por uma investigação avulsa.
2. **Sem conta** — nenhum usuário é criado e nenhum dado entra no histórico de
   contas. `investigations.user_id` passa a ser nulo para a avulsa (`origin = guest`).
3. **Preço fixo configurável** — o valor é a linha ativa `kind = guest_investigation`
   em `credit_packages`, editável em `/admin/packages` (sem deploy). O valor é
   **congelado** em `investigations.guest_price_cents` no momento da criação; mudar
   o preço depois não altera investigações já criadas.
4. **Token é a única credencial** — nenhuma rota aceita o id da investigação; o
   token (256 bits) é gerado no início, exibido apenas na URL de acompanhamento e
   persistido somente como hash SHA-256.
5. **Nada executa sem pagamento confirmado** — o run nasce `pending` e **não é
   despachado**; a liberação vem do backend (webhook do gateway) e há guarda no
   `StartInvestigationRunJob` como segunda camada.
6. **Preço fixo x regra de créditos** — a regra do prompt 39 ("cobra-se pela
   consulta executada") vale para contas. Na avulsa não há estorno: o preço é pago
   antes e a execução acontece uma vez (divergência do texto do §13 do prompt 41
   registrada aqui).

## Fluxo

```text
GET  /investigacao                              formulário público (preço fixo, sem cadastro)
POST /investigacao            -> investigations(origin=guest, user_id=null, status=draft, guest_price_cents)
                                 + run #1 pending (snapshot, NÃO despachado)
                                 + payments(user_id=null, guest_investigation_id, amount_cents) + PIX
GET  /investigacao/acesso/{token}               PIX pendente -> progresso -> resultado
GET  /investigacao/acesso/{token}/status        JSON para polling (status, módulos, resultados)
POST /investigacao/acesso/{token}/pix/confirmar simulação (somente provider fake)
GET  /investigacao/acesso/{token}/relatorio     mesmo relatório/manifesto, layout público
POST /payments/webhook                          confirma o pagamento -> libera a execução (backend)
```

Módulos: o visitante **não escolhe** módulos; todos os módulos ativos aplicáveis
à entrada informada são executados (os não aplicáveis ficam `skipped`, como no
fluxo autenticado).

## Modelo de dados (4 migrations)

| Migration | Mudança |
|---|---|
| `2026_09_30_000180_add_kind_to_credit_packages_table` | `credit_packages.kind` (`credits` \| `guest_investigation`) + índice |
| `2026_09_30_000190_add_guest_access_to_investigations_table` | `user_id` nullable (FK preservada), `origin` (`account` \| `guest`), `access_token_hash` char(64) único, `access_token_created_at`, `access_token_expires_at`, `guest_price_cents`, índice `origin+status` |
| `2026_09_30_000200_add_guest_investigation_to_payments_table` | `user_id` nullable, `guest_investigation_id` (único, FK `investigations`, cascade) |
| `2026_09_30_000210_make_user_id_nullable_on_reports_table` | `reports.user_id` nullable |

Estados: **nenhum estado novo** — a avulsa usa `draft` (não paga) → `queued`
(liberada) → `running` → `completed`/`partial`/`failed`, e `expired` para o
rascunho nunca pago. O pagamento usa `payments.status` (`pending`,
`awaiting_payment`, `paid`, `expired`, …).

## Billing (pagamento fixo)

- `PaymentService::checkoutGuest($investigation, $package)` cria o pedido
  idempotente por investigação (`idempotency_key = guest-investigation-{id}`,
  `guest_investigation_id` único) e guarda o `credit_package_id` para rastrear o
  preço aplicado.
- `PaymentService::confirmPayment()`: pagamento pago de avulsa chama
  `GuestInvestigationService::release()`; pagamento pago de conta continua
  creditando o pacote na carteira (caminho original).
- `StartInvestigationRunJob`: para avulsa não reserva créditos, grava
  `credits_reserved = 0` e `metadata.funding = guest_prepaid`; se o pagamento não
  estiver pago, encerra o run como `unpaid_guest_investigation` sem executar.
- `ReleaseReservedCreditsJob`: curto-circuito para avulsa (nada a consumir/estornar);
  a regra das contas segue inalterada.
- Evidência financeira: `payments` + `payment_events` + `guest_price_cents`.

## Token e segurança

- `bin2hex(random_bytes(32))` (256 bits), persistido como `sha256`; a comparação
  exige exatamente 64 hex minúsculos (formato inválido é rejeitado antes da consulta).
- Rotas de token: `no-store`/`no-cache` (`Cache-Control`, `Pragma`) e
  `X-Robots-Tag: noindex, nofollow, noarchive` via `GuestAccessHeaders`.
- Limites: `guest-create` (5/min + 20/h por IP) e `guest-access` (60/min por IP +
  30/min por hash de token).
- CSRF ativo (rotas web); liberação **nunca** vem do front — não existe rota que
  aceite "pago"; simulação só com `PAYMENT_PROVIDER=fake`.
- Rotas autenticadas respondem **404** para investigação avulsa (contas comuns não
  descobrem a existência); admin acessa pelo painel; a policy `view` nega para
  não-admin e `rerun` nega para todos.
- O relatório exige execução concluída/parcial (não existe relatório de avulsa
  não paga).

## Expiração

`guest.unpaid_expiration_minutes` (padrão 1440) — o agendador roda
`guest:expire-unpaid` a cada 5 minutos (`routes/console.php`): rascunho avulso não
pago vira `expired` e o pedido vira `expired` (evento `payment.expired`
idempotente por `guest-expire-{payment_id}`). Nada é executado. Se o pagamento
chegar **depois** do prazo (webhook atrasado), a investigação é executada — o
visitante pagou; a expiração barra apenas execução **sem** pagamento.

## Administração (prompt 41, item 12)

- `/admin/packages`: seção "Investigação avulsa (sem cadastro)" com o preço
  vigente (editável; **não** removível e não criável — linha única do seeder) e a
  lista de pacotes de créditos separada.
- `/admin/investigations`: filtro Todas/Contas/Avulsas, colunas Origem, Preço
  avulso, Pagamento e `Sem conta` no lugar do e-mail.
- `/admin/investigations/{id}`: origem, preço e status do pagamento da avulsa.
- `/admin/payments`: tipo (Créditos \| Avulsa), `Sem conta` e vínculo com a
  investigação avulsa.
- Token e hash **não** são exibidos no painel.

## Testes

| Arquivo | Cenários |
|---|---|
| `GuestInvestigationTest` (8) | formulário público com preço; criação sem conta sem executar e sem créditos; liberação + relatório pelo token; endpoint de status; front não marca pago; fora do histórico de contas; expiração sem execução; pagamento tardio executa |
| `GuestPaymentTest` (5) | checkout avulso idempotente; reentrega/evento repetido não reexecuta; carteiras e ledger intactos; pacote avulso não é vendido como crédito; pacote avulso oculto em `/credits` |
| `GuestSecurityTest` (7) | token desconhecido/malformado 404; `no-store`/`noindex`; relatório indisponível antes da execução; simulação exige token + provider fake; isolamento entre tokens; painel do admin; rate limit de criação (429) |
| Ajustados | `DemoDataSeederTest` e `ReferenceDataSeederTest` (passam a contar 3 pacotes de crédito + 1 preço avulso) |

Validação: `php artisan test` — **301 passando, 4 ignorados (testes reais gated),
1310 assertions**; `./vendor/bin/pint --test` — PASS (255 arquivos); 56 rotas.

## Limitações e pendências

- Sem QR Code gráfico: o PIX é exibido como copia-e-cola (mesmo comportamento da
  jornada de créditos; o gateway real definirá a imagem).
- Não há estorno automático de avulsa (não previsto no prompt 41); pagamento
  tardio é sempre executado.
- `credits_reserved`/`credits_consumed` de um run avulso são 0 por definição — o
  valor cobrado está em `payments.amount_cents` e `investigations.guest_price_cents`.
- O admin vê o relatório da avulsa pelo painel (status/execuções) e o conteúdo
  completo pela rota de token; não há "impersonação" de link no painel.
- Retenção: a avulsa paga segue `config/privacy.php` (365 dias); não existe rotina
  de expurgo nova (apenas a expiração de rascunho não pago).
- A etapa 40 (auditoria E2E com as ferramentas reais) continua pendente — este doc
  é a `35` porque foi executado antes dela.
