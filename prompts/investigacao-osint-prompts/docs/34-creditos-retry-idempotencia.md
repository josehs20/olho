# Créditos, retry e idempotência (prompt 39)

Auditoria do consumo de créditos durante a execução real e correções para que
retry, reexecução e falha de worker não corrompam o saldo.

## Achados da auditoria

| # | Achado | Correção |
|---|---|---|
| 1 | uma **única reserva** com o total do run: em investigação parcial o usuário pagava também pelos módulos que falharam | **reserva por módulo**, com `credit_reservations.source`; o encerramento consome só o concluído e estorna o resto |
| 2 | a idempotência da abertura dependia apenas do status do run | chave estável por módulo (`run-{id}-source-{slug}-reserve`) + guarda de status |
| 3 | `credits_consumed` somava o total reservado mesmo com fontes falhas | soma apenas as reservas consumidas (módulos concluídos) |
| 4 | não havia reexecução controlada (docs/02 já a previa: run novo, custo independente, histórico intacto) | `InvestigationService::rerun()` + policy `rerun` + rota `POST /investigations/{id}/runs` + botão na UI |
| 5 | ausência de teste com falha de worker/retry provando consistência | `CreditRetryIdempotencyTest` com cenários de retry, reentrega, interrupção e reexecução |

## Regra de cobrança (explícita)

- cada módulo aplicável gera **uma reserva** com o seu `cost_credits`;
- módulos com custo zero não geram reserva;
- módulos **não aplicáveis** à entrada não geram reserva (ficam `skipped`);
- no encerramento, decide-se **por execução**:
  - módulo **efetivamente consultado** (`attempts > 0`: `completed`, `failed` ou
    `timeout`) → **consome**, mesmo **sem nenhum resultado** e mesmo quando a
    consulta falhou;
  - módulo **nunca consultado** (`skipped`/`pending`/`running`, job perdido,
    worker interrompido) ou módulo que não chegou a existir no run → **estorna**;
  - reserva legada sem `source` (uma por run, formato anterior ao prompt 39)
    mantém o comportamento antigo: consome em run `completed`/`partial`;
- `runs.credits_reserved` = soma reservada; `runs.credits_consumed` = soma
  efetivamente cobrada.

> **Decisão de produto (cliente, pós-prompt 39)**: cobra-se **pela consulta**,
> não pelo resultado. A consulta é o recurso consumido — o módulo entregou o
> resultado que a fonte pública tinha (inclusive “nada encontrado”), e um módulo
> indisponível também consumiu a tentativa. Estorna-se apenas o que não foi
> consultado, o que mantém a regra coerente e o saldo íntegro.

## Garantias de integridade

- **Transação + lock**: todo movimento passa por
  `CreditLedgerService` com `DB::transaction` e `lockForUpdate()` na carteira;
  nenhuma investigação escreve `wallet.balance` diretamente;
- **Idempotency key** em cada operação: reserva por módulo, consumo
  (`run-consume-{reservation}`), estorno (`run-release-{reservation}`) e
  compensação por vencimento (`release-expired-{reservation}`);
- **Etapas do pipeline** (`sources_created_at`, `normalized_at`,
  `deduplicated_at`, `correlated_at`, `reported_at`, `credits_released_at`)
  tornam a reentrega de jobs segura;
- **Retry de fonte** (`ExecuteSourceJob`, `tries=2`) descarta resultados parciais
  antes de reexecutar: não duplica descobertas nem cobra duas vezes;
- **Compensação**: reservas vencidas (`expires_at`) são liberadas por
  `releaseExpiredReservations()` (comando/rotina de manutenção);
- **Invariante dos testes**: `wallet.balance == SUM(credit_transactions.amount)`.

## Reexecução controlada

- cria um **novo run** (`run_number` seguinte) a partir do snapshot da última
  execução, com reserva independente — nunca sobrescreve o anterior;
- recusada enquanto houver run `pending`/`running` na mesma investigação;
- exige saldo suficiente (erro de créditos devolvido à UI);
- restrita ao dono ou admin (policy `rerun`);
- resultados, evidências e ledger do run anterior permanecem auditáveis.

## Cenários provados (`CreditRetryIdempotencyTest`)

| Cenário | Resultado esperado |
|---|---|
| run parcial (consulta falhou + consulta com resultado) | cobra as **duas** consultas (10), saldo 90, nenhuma reserva estornada |
| módulo que não encontrou nada (`empty`) | cobra 5, saldo 95 (resultado vazio não isenta) |
| módulo **não aplicável** à entrada | nenhuma reserva; nada a cobrar nem a estornar |
| módulo aplicável que **nunca foi executado** | estorna a reserva (`attempts = 0`), cobra só a consulta feita |
| run com todas as consultas falhando | cobra as consultas executadas (`credits_consumed > 0`) |
| retry do job de abertura | 2 reservas (uma por módulo), saldo debitado uma única vez |
| reentrega do job de fonte após interrupção | resultados reconvergem (sem duplicar), 1 reserva, 1 consumo |
| worker morto antes da liquidação | reserva vencida é compensada (saldo 100) |
| reexecução pelo serviço | run #2 independente, saldo 90, run #1 intacto |
| reexecução durante run em andamento | recusada (`InvalidArgumentException`) |
| reexecução sem saldo | `InsufficientCreditsException` |
| rota de reexecução | dono cria o run; outro usuário recebe 403 |
