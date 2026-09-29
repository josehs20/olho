# Prompt 14 — Normalização

Implemente `docs/10-resultados-evidencias.md`.

Criar normalizadores que transformem outputs de adapters em `SourceResult`/`source_results` padrão.

Regras:
- preservar URL pública;
- extrair tipo de entidade;
- registrar evidências;
- armazenar timestamp;
- não inserir HTML cru;
- suportar metadata JSON.

Testar normalização de todos os mocks.
