# E2E real e auditoria (prompt 40)

Execução de uma investigação completa com as integrações **reais** — usuário →
créditos → investigação → fila → workers → adapters reais → resultados brutos →
normalização → deduplicação → correlação → evidências → relatório — com registro
de duração por módulo, resultados, falhas, timeouts, retries, consumo e resultado
final.

> Esta etapa foi executada **depois** da etapa 41 (investigação avulsa), por
decisão de sequência do projeto; por isso esta documentação é a `36`.

## Como a auditoria é executada

Dois caminhos complementares, ambos com os binários/dataset reais da imagem:

1. **Stack real** (MySQL + Redis + `worker` + `scheduler` + nginx), com a jornada
   autenticada completa disparada pelo domínio (`InvestigationService::create`).
   Evidência: investigação 26 / run 27.
2. **Teste gated** `tests/Feature/RealPipelineAuditTest.php`, que percorre a mesma
   cadeia em processo (`QUEUE_CONNECTION=sync`), valida invariantes e grava um
   **relatório de auditoria JSON** em `storage/app/osint-audit/e2e-real-*.json`.
   Evidência: artefatos `storage/app/osint-audit/e2e-real-20260930-204744.json` e
   `e2e-real-20260930-205419.json`.

```bash
# auditoria automatizada (requer rede + ferramentas instaladas)
docker compose exec -e OSINT_E2E=1 app php artisan test --filter=RealPipelineAuditTest
# suíte padrão: a auditoria fica ignorada (sem rede)
docker compose exec app php artisan test
```

O teste **força os adapters reais** via `SourceRegistry::register()` — o
`phpunit.xml` mantém `*_DRIVER=mock` para a suíte hermética — e só inclui um módulo
quando o `healthCheck()` confirma o binário/dataset. Nada é declarado real sem ter
rodado: a versão de cada ferramenta é persistida em `source_runs.tool_version` e
copiada para o relatório de auditoria (`adapters_forced_real: true` e
`configured_drivers` ficam registrados no artefato).

## Módulos realmente executados

Identificador público institucional (projeto/linguagem, não pessoa):
`username = python`, `email = python@python.org`.

| Módulo | Driver real | Status | Tentativas | Duração | Resultados | Versão da ferramenta | Custo |
|---|---|---|---|---|---|---|---|
| Maigret | `process` | completed | 1 | 10.695 ms (live 10.572 ms) | 18 | 0.6.6 | 2 |
| Sherlock | `process` | completed | 1 | 3.689 ms (live 3.801 ms) | 11 (live 10) | 0.16.2 | 2 |
| WhatsMyName | `http` | completed | 1 | 10.163 ms (live 5.353 ms) | 13 | dataset `062bcfe48df79fa618e96edc79dc9673f3fe5643` | 2 |
| Holehe | `process` | completed | 1 | 1.800 ms (live 3.053 ms) | 0 | 1.61 | 2 |

- **Falhas**: 0 · **Timeouts**: 0 · **Retries**: 0 (todos `attempts = 1`).
- Os módulos `mock-*` existem no registry para a suíte hermética, mas **não**
  foram selecionados nesta execução (apenas os 4 slugs reais).
- No live, os quatro `ExecuteSourceJob` foram processados pelo container
  `olho-worker-1` (Redis); no teste, a fila é `sync`.

## Resultado final

| Métrica | Stack real (INV 26 / run 27) | Teste automatizado (run 1) |
|---|---|---|
| Status do run | `completed` (`all_sources_completed`) | `completed` (`all_sources_completed`) |
| Duração total | ~21 s | 26,0 s (27,6 s ponta a ponta) |
| Resultados | 41 | 42 |
| Descobertas (representantes) | 28 | 28 |
| Duplicatas consolidadas | 13 | 14 |
| Grupos de deduplicação (2+ fontes) | 9 | 10 |
| Evidências | 41 | 42 |
| Correlação | score 40 · 1 fator · 4 limitações | score 40 |
| Relatório | `report-manifest-v1` · `e9496ce1debb…` · 8 limitações | `report-manifest-v1` · `ec02944851de…` · 8 limitações |

O relatório entrega as limitações reais das fontes, incluindo os avisos do Maigret
(`Bot protection` em 10,53% dos sites e `Access denied` em 5,26%, com 6 sites não
avaliados). Nenhum bypass, proxy ou `--cloudflare-bypass` foi usado: apenas fontes
públicas, conforme as regras do projeto — o aviso chega ao relatório do usuário
como limitação observável.

## Consumo de créditos

| Métrica | Valor |
|---|---|
| Reservado | 8 créditos (4 módulos × 2) |
| Consumido | 8 créditos |
| Carteira | 50 → 42 |
| Soma do ledger | 42 (invariante `saldo == ledger`) |
| Reservas pendentes | 0 |

Regra aplicada (pós-prompt 39): cobra-se pela **consulta executada** — o Holehe,
mesmo sem resultado, foi consultado (`attempts = 1`) e por isso foi consumido; o
encerramento não deixou reserva pendente.

## Testes

- `RealPipelineAuditTest` (gated por `OSINT_E2E=1`): **~99–100 assertions em 20–28 s** —
  jornada completa; relatório/manifesto/hash; correlação persistida
  (`score_kind = internal_correlation_indicator`, `identity_asserted = false`);
  deduplicação v2 (toda duplicata aponta para o representante existente do run);
  evidência por resultado; consumo esperado por módulo; `0` reservas pendentes; e
  invariante `saldo == soma(ledger)`. Grava o relatório JSON de auditoria.
- Suíte completa (sem rede): **301 passando** + 5 ignorados (as 4 integrações reais
  anteriores e a nova auditoria) — a auditoria é o único teste que exige
  `OSINT_E2E=1`.
- Pint: PASS (256 arquivos).

## Problemas encontrados

| # | Problema | Tratamento |
|---|---|---|
| 1 | Maigret reporta `Bot protection`/`Access denied` em parte dos sites | limitação registrada no relatório; nenhum bypass (regra do projeto); a execução continua útil (`completed`) |
| 2 | Holehe sem resultado para um e-mail institucional | comportamento correto: indica quais sites têm cadastro e nenhum deles tem para esse endereço; a consulta é cobrada por ter sido executada (regra pós-39) |
| 3 | WhatsMyName depende do dataset versionado | a revisão usada (`062bcfe4…`) é gravada em `tool_version` e exibida no relatório, tornando a execução auditável |
| 4 | Nenhuma falha/timeout/retry nesta execução | registrado explicitamente: a auditoria não esconde indisponibilidade — se um módulo falhar, o run vira `partial` e o teste aceita, desde que o motivo fique visível |
| 5 | Divergência textual do §13 do prompt 41 (estornar falhas/timeouts) | a regra vigente é a do prompt 39/decisão do cliente (cobra a consulta executada); registrada em `docs/35-investigacao-avulsa.md` e `docs/34-creditos-retry-idempotencia.md` |

## Arquivos alterados nesta etapa

- `tests/Feature/RealPipelineAuditTest.php` (novo) — auditoria E2E real + artefato JSON.
- `prompts/investigacao-osint-prompts/docs/36-e2e-real-auditoria.md` (novo).
- `AUDIT.md`, `CHANGELOG.md`, `README.md` — estado da etapa e link do relatório.
- `prompts/investigacao-osint-prompts/docs/35-investigacao-avulsa.md` — nota de sequência.

Nenhum código de produção foi alterado nesta etapa: a auditoria confirmou o
comportamento já implementado nas etapas 25–39 + 41.

## Limitações remanescentes

- A auditoria depende de rede e dos binários da imagem; por isso é gated e não
  roda na suíte hermética.
- Fontes podem responder com proteção anti-bot em ambientes sem IP dedicado — o
  resultado sai como limitação, nunca como falha silenciosa.
- O dataset do WhatsMyName é atualizado fora do ciclo da aplicação; a revisão
  usada fica registrada por run.
