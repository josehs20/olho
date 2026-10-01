# Custo em créditos por requisição configurável (prompt 43)

Auditoria do consumo de créditos e tornar a quantidade de créditos consumida por
requisição/módulo editável pelo administrador, sem alterar código.

## Diagnóstico da implementação anterior (etapas 1–3)

O custo **já vivia no banco**, mas não era editável pela interface:

| Pergunta | Resposta |
|---|---|
| Onde o custo é definido? | Coluna `source_definitions.cost_credits` (`unsignedInteger`, default 0), populada pelo `SourceDefinitionSeeder` |
| Reserva | `StartInvestigationRunJob` -> `InvestigationOrchestrator::reserveCredits()` -> `CreditLedgerService::reserve()`: 1 reserva por módulo aplicável com custo > 0, `DB::transaction` + `lockForUpdate`, chave `run-{id}-source-{slug}-reserve` |
| Consumo | `ReleaseReservedCreditsJob` -> `consume()` chave `run-consume-{id}` quando o módulo foi consultado (`attempts > 0`) |
| Estorno | Mesmo job: nunca consultado (`skipped`/`pending`/`running`) -> `release()` chave `run-release-{id}`; vencidas -> `releaseExpiredReservations` (schedule 5 min) |
| Retry/idempotência | `ShouldBeUnique` no start, chaves únicas por operação e etapa `credits_released_at` |
| Guest (prompt 41) | Pré-pago em dinheiro, sem wallet/reserva/ledger (`credits_reserved = credits_consumed = 0`) |
| Ledger | `credit_transactions` imutável, `balance_before`/`balance_after`, idempotency key |

Valores encontrados (todos definidos no seeder, **não** editáveis no painel):

| Fonte | Valor atual |
|---|---:|
| mock-username / mock-email / mock-web | 1 |
| maigret / sherlock / whatsmyname / holehe | 2 |

**Gaps**: (a) `/admin/sources` só exibia o custo; (b) reexecutar o
`SourceDefinitionSeeder` sobrescrevia o custo editado; (c) a avulsa não registrava
custo interno algum para controle.

## Regra de cobrança vigente (mantida, não alterada)

Documento em `docs/34-creditos-retry-idempotencia.md`: cobra-se **pela consulta
executada** — módulo consultado consome mesmo falhando/estourando timeout; só se
estorna o nunca consultado. O prompt 43 determina explicitamente "não alterar
essa regra neste prompt" — preservada integralmente.

> **Divergência textual registrada**: o prompt 43 descreve "FAILED/TIMEOUT ->
> não cobra", mas a regra estabelecida (prompt 39, decisão de cliente registrada
> em docs/34) é a da consulta executada. Como no mesmo prompt se proíbe alterar a
> regra, o comportamento implementado permanece o mesmo. Mesma natureza da
> divergência do prompt 41 já registrada em `docs/36-e2e-real-auditoria.md`.

## O que mudou nesta etapa

### Configuração (ETAPA 4/5)

- **`AdminSourceCostRequest`** (novo): validação server-side `integer`, `min:0`,
  `max:1000`, com `authorize()` revalidando `isAdmin()` (a rota já roda sob
  middleware `admin` — defesa em profundidade).
- **Rota** `PATCH /admin/sources/{source}/cost` (`admin.sources.update-cost`),
  dentro do grupo `admin` (CSRF + auth + autorização).
- **`Admin\SourceController::updateCost()`**: persiste o valor e registra
  **`admin.source_cost_update`** no `AuditLogger` existente com ator, IP, slug,
  `previous_cost_credits` e `new_cost_credits` (sem novo sistema de auditoria).
  Valor igual ao atual não gera registro vazio.
- **View `/admin/sources`**: campo editável por fonte ("Créditos por
  requisição"), botão Salvar e a última alteração visível (data, quem, de -> para),
  lida do histórico de auditoria. Texto discreto explica que o custo vale para as
  **próximas** execuções e que zero créditos executa sem consumo.

### Integração com o consumo (ETAPA 6)

- **`StartInvestigationRunJob`**: grava `metadata.credit_cost` no run com
  `total`, `breakdown` por módulo e `snapshot_at` — o valor lido da
  configuração **no momento da abertura**. Nenhum `$credits = 10` hardcoded em
  job/controller/adapter/frontend: o fluxo continua
  `SourceDefinition -> cost_credits -> CreditLedgerService -> Ledger`.
- **`ReleaseReservedCreditsJob` (avulsa)**: registra
  `metadata.credit_cost.executed_credits` (snapshot por módulo somado apenas
  para módulos consultados — mesma regra das contas) e `executed_at`. Informativo
  para controle/auditoria; **nenhum** wallet ou ledger é movido.
- **`SourceDefinitionSeeder`**: `cost_credits` passa a ser gravado **apenas na
  criação** da linha (como já acontece com `active`) — reexecutar a seed não
  reverte o valor configurado pelo administrador.

### Zero créditos (comportamento explícito)

`credit_cost = 0` é permitido e significa "executa sem consumir": a reserva filtra
`cost_credits > 0`, a validação de saldo antecipada retorna cedo para custo 0 e o
run finaliza com `credits_reserved = credits_consumed = 0` e nenhuma linha de
ledger.

### Histórico e alteração durante execução

- A reserva de **todos** os módulos aplicáveis acontece em **uma única
  transação na abertura do run**; o valor fica imutável em
  `credit_reservations.amount` (com `source` por módulo). Alterar o custo depois
  **nunca** recalcula runs antigos: execução nova usa o novo valor, a antiga
  mantém o cobrado (provado em teste).
- O `metadata.credit_cost` do run torna o histórico auditável mesmo para módulos
  de custo 0 (que não geram reserva) e para a avulsa (que não gera ledger).

### Guest / investigação avulsa (compatibilidade com o prompt 41)

- Usa **exatamente** a mesma coluna de custo — não existe segunda tabela de
  preços para guest.
- O visitante continua sem saldo: nenhum wallet, reserva ou transação é criado;
  o preço financeiro da avulsa (pacote `guest_investigation`) **não** foi tocado.
- O custo configurado é registrado internamente nos metadados do run
  (`total`/`breakdown` na abertura, `executed_credits` no encerramento) para
  controle, auditoria e custo operacional.

## Arquivos alterados

- `app/Http/Requests/AdminSourceCostRequest.php` (novo)
- `app/Http/Controllers/Admin/SourceController.php` (`updateCost`, histórico na index)
- `routes/web.php` (rota PATCH de custo)
- `resources/views/admin/sources/index.blade.php` (campo + histórico)
- `app/Jobs/StartInvestigationRunJob.php` (snapshot `metadata.credit_cost`)
- `app/Jobs/ReleaseReservedCreditsJob.php` (custo interno executado da avulsa)
- `database/seeders/SourceDefinitionSeeder.php` (preserva `cost_credits`)
- `tests/Feature/AdminSourceCostTest.php` (novo, 7 cenários)

Nenhuma migration: `source_definitions.cost_credits` já é a estrutura adequada.

## Testes (AdminSourceCostTest)

1. admin altera custo com validação e auditoria (quem/valor anterior/novo) — e a
   tela expõe o campo;
2. anônimo é redirecionado ao login e usuário comum recebe 403, sem registro de
   auditoria;
3. valores inválidos (`-1`, `abc`, `1001`, vazio) são rejeitados sem persistir;
4. execução nova usa o custo configurado (10) e a alteração 10 -> 25 vale para a
   nova execução, mantendo a antiga cobrada em 10 (reservas + ledger consistente:
   `saldo == soma(ledger)`);
5. custo zero executa sem wallet/reserva/consumo;
6. avulsa registra o custo configurado internamente (`breakdown` reflete 7,
   `executed_credits` igual à soma dos módulos consultados) sem tocar
   wallet/ledger;
7. re-seed preserva o custo administrado (42) e ainda aplicaria o padrão em
   linhas novas.

Suíte completa: **309 passando, 5 ignorados** (integrações reais gated por
`OSINT_E2E=1`); Pint PASS.

