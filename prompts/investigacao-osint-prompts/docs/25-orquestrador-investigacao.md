# Orquestrador da Investigação

`App\Services\InvestigationOrchestrator` concentra as decisões de negócio do run.
Os jobs apenas executam etapas; controllers apenas validam/redirecionam.

## Passos (prompt 30)

| # | Passo | Onde |
|---|---|---|
| 1 | valida créditos | `InvestigationService::assertSufficientCredits` (antecipado) + `StartInvestigationRunJob` (reserva atômica com lock) |
| 2 | cria a execução | `InvestigationService::create` (investigation + run + snapshot) |
| 3 | determina módulos aplicáveis ao tipo de identificador | `InvestigationOrchestrator::split()` |
| 4 | cria registros de execução por módulo | `InvestigationOrchestrator::createSourceRuns()` |
| 5 | envia jobs para a fila | `StartInvestigationRunJob` → `ExecuteSourceJob` |
| 6 | executa adapters | `ExecuteSourceJob` + `InvestigationSource` |
| 7 | persiste resultados | `ExecuteSourceJob` + `SourceResultNormalizer` |
| 8 | normaliza | `NormalizeSourceResultsJob` |
| 9 | deduplica | `DeduplicateResultsJob` + `ResultDeduplicator` |
| 10 | correlaciona | `BuildCorrelationJob` + `CorrelationService` |
| 11 | finaliza | `BuildHtmlReportJob` + `InvestigationOrchestrator::finalize()` |

## Determinação de módulos (passo 3)

- O usuário escolhe explicitamente os módulos (RF-08).
- O orquestrador decide quais são **aplicáveis** aos identificadores informados:
  - adapter registrado → `InvestigationSource::supports()`;
  - sem adapter → pelo `source_definitions.type` (`username`, `email`, `phone`, `web`).
- Módulo não aplicável **não** rejeita a investigação: gera um `source_run`
  `skipped` com `error_code = invalid_input` e aviso público (docs/02 — limitação).
- Se **nenhum** módulo selecionado for aplicável, a criação é rejeitada
  (`InvalidArgumentException`) — `InvestigationService` / erro de formulário.
- Créditos são reservados apenas para os módulos aplicáveis.

## Desfecho do run (passo 11)

| Condição | Status | Motivo (`metadata.finalization.reason`) |
|---|---|---|
| ≥1 fonte concluída e nenhuma falhou | `completed` | `all_sources_completed` |
| ≥1 fonte concluída e ≥1 falhou/timeout | `partial` | `some_sources_failed` |
| nenhuma fonte concluída, ≥1 falhou/timeout | `failed` | `all_sources_failed` |
| nada executou (apenas `skipped`) | `failed` | `no_source_executed` |
| créditos insuficientes / sem módulo aplicável | `failed` | `insufficient_credits` / `no_applicable_modules` |

Créditos: `completed` e `partial` consomem a reserva; `failed` libera a reserva
(compensação de falha definitiva — docs/02, regra 4).

A decisão é persistida em `investigation_runs.metadata.finalization`
(`status`, `reason`, `counts` por status de fonte, `decided_at`) e registrada no
canal de log `investigations` (`run.started`, `run.finalized`, `run.failed`).

## Idempotência e observabilidade

- Etapas do pipeline em `investigation_runs.metadata.pipeline`
  (`sources_created_at`, `normalized_at`, `deduplicated_at`, `correlated_at`,
  `reported_at`, `credits_released_at`); cada job retorna cedo se a etapa existe.
- `StartInvestigationRunJob` só inicia run `pending`; `ExecuteSourceJob` só
  executa fonte `pending`/`running` (tentativa interrompida é refeita).
- Jobs `ShouldBeUnique` por id; reservas idempotentes por `idempotency_key`.
- A criação da investigação não executa ferramentas externas na requisição HTTP
  (docs/08).
