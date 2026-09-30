# Persistência da execução por módulo (`source_runs`)

Cada módulo executado em um run tem uma linha em `source_runs`, suficiente para
reconstruir o que aconteceu naquela fonte sem depender de logs.

| Campo | Descrição |
|---|---|
| `investigation_id` | investigação dona da execução (denormalizado + FK, indexado) |
| `investigation_run_id` | run que disparou a execução |
| `source_definition_id` | definição da fonte (configuração, custo) |
| `source` | **snapshot do slug** executado (histórico imune a rename da definição) |
| `status` | `pending`, `running`, `completed`, `failed`, `timeout`, `skipped` |
| `attempts` | tentativas executadas (0 antes de executar; incrementa no retry) |
| `started_at` / `finished_at` | início e fim da execução |
| `duration_ms` | duração medida pelo orquestrador |
| `error_code` | `timeout`, `unavailable`, `blocked`, `invalid_input`, `error` |
| `error_message_public` | **mensagem pública sanitizada**, derivada do código de erro |
| `result_count` | quantidade de descobertas persistidas por esta fonte |
| `tool_version` | versão da ferramenta, quando o adapter informar (`SourceOutcome.metadata.tool_version`) |
| `metrics` (JSON) | avisos (`warnings`) e metadados extras do adapter |

## Regras

- `error_message_public` **nunca** contém detalhe interno (caminho, comando,
  saída de processo, credencial): vem de `SourceErrorMapper::toPublicMessage()`.
  O detalhe técnico é registrado apenas no log sanitizado (`source.failed`).
- `result_count` e `tool_version` são gravados por `ExecuteSourceJob` a partir do
  `SourceOutcome`; uma reexecução recalcula os dois (os resultados parciais da
  tentativa interrompida são descartados antes).
- `attempts` incrementa a cada tentativa de execução; `skipped` permanece com 0.
- `investigation_id` e `source` são preenchidos pelo orquestrador e garantidos
  pelo hook `creating` do model `SourceRun` (mantém a denormalização coerente
  mesmo em factories/seeders).
- O dashboard/relatório exibem `result_count` e `tool_version` por fonte; o
  painel admin exibe também `attempts`, `duration_ms` e a mensagem pública.

## Onde é escrito

- `InvestigationOrchestrator::createSourceRuns()` — cria `pending`/`skipped`;
- `ExecuteSourceJob` — status, tentativas, tempos, erro público, `result_count`,
  `tool_version` e `metrics`.

## Migração

`2026_09_30_000160_add_execution_details_to_source_runs_table` adiciona os
campos, renomeia `attempt` → `attempts` (default 0) e faz backfill das execuções
existentes a partir de `investigation_runs`, `source_definitions` e
`source_results`.
