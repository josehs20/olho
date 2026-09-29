# Prompt 09 — Jobs e workers

Implemente execução assíncrona conforme `docs/03-arquitetura.md`.

Criar os jobs mínimos:
- StartInvestigationRunJob;
- ExecuteSourceJob;
- NormalizeSourceResultsJob;
- DeduplicateResultsJob;
- BuildCorrelationJob;
- BuildHtmlReportJob;
- ReleaseReservedCreditsJob.

Usar Redis queue.
Garantir retry/backoff, timeout e idempotência.

Uma fonte falha não deve cancelar fontes independentes.

Criar testes de dispatch, cadeia e estados.
