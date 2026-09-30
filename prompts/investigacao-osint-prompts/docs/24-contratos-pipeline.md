# Contratos do Pipeline

Contratos tipados entre os módulos do pipeline. O objetivo é permitir trocar um
adapter mock por uma ferramenta real (Maigret, Sherlock, WhatsMyName, Holehe)
sem alterar o restante do domínio.

``` text
InvestigationService
  ↓ (InvestigationRun.input_snapshot["modules"])
StartInvestigationRunJob        → cria source_runs, reserva créditos, despacha
  ↓
ExecuteSourceJob                → monta InvestigationContext + SourceDescriptor
  ↓
InvestigationSource::execute()  → SourceOutcome | SourceException
  ↓
SourceResultNormalizer          → source_results + evidence
  ↓
ResultDeduplicator → CorrelationService → Report
```

## 1. Contrato de entrada do adapter

`InvestigationContext` é o que o adapter recebe. Tudo é resolvido pelo
orquestrador (`ExecuteSourceJob`): o adapter **não** acessa Eloquent, o banco ou
o painel admin.

| Campo | Origem | Observação |
|---|---|---|
| `input` | `InvestigationInput::fromArray(run.input_snapshot)` | identificadores pesquisados |
| `investigationRunId` / `sourceRunId` | `investigation_runs` / `source_runs` | rastreabilidade |
| `source` (`SourceDescriptor`) | `source_definitions` | `slug`, `type`, `timeout`, `config` |
| `correlationId` | derivado | `run:{runId}:source:{slug}` |

`SourceDescriptor::config($key, $default)` dá leitura tolerante de opções
específicas da fonte (ex.: caminho do binário), sem o adapter tocar no banco.

## 2. Contrato de saída do adapter

`InvestigationSource::execute(InvestigationContext): SourceOutcome`.

| Campo | Destino |
|---|---|
| `findings` (`SourceResult[]`) | `source_results` + `evidence` (cada finding aponta sua evidência) |
| `warnings` (`string[]`) | `source_runs.metrics.warnings` (limite de 20) |
| `metadata` (`array`) | `source_runs.metrics.adapter` (ex.: versão da ferramenta) |

`SourceResult` continua sendo o DTO de descoberta (fonte, tipo de entidade,
valor, URL, título, descrição, confiança, evidências, metadados) consumido por
normalização, deduplicação e correlação.

**Status, duração e erro não fazem parte da saída.** São responsabilidade do
orquestrador, para que `source_runs` registre o histórico mesmo quando o adapter
falha (docs/20 e docs/23).

## 3. Contrato de erro

Falhas do adapter são lançadas como `SourceException` abstrata, mapeadas por
`SourceErrorMapper`:

| Exceção | `errorCode()` | `runStatus()` |
|---|---|---|
| `SourceTimeoutException` | `timeout` | `Timeout` |
| `SourceUnavailableException` | `unavailable` | `Failed` |
| `SourceBlockedException` | `blocked` | `Failed` |
| `SourceInvalidInputException` | `invalid_input` | `Skipped` |
| `SourceNotRegisteredException` | `unavailable` | `Failed` |

`SourceNotRegisteredException` cobre o caso de existir `source_definitions` sem
adapter correspondente: a fonte vira `failed/unavailable` e o run termina como
`partial` — nunca fica preso.

## 4. Registro de fontes

`SourceRegistry` mapeia `slug → InvestigationSource`. O registro é feito em
`AppServiceProvider` (composition root). Trocar um mock por uma ferramenta real:

1. implementar `InvestigationSource` em `app/Sources/Adapters`;
2. registrar no `SourceRegistry` com o **mesmo `slug`** de `source_definitions.slug`;
3. opcionalmente, usar `source_definitions.config`/`timeout` (já disponíveis em `InvestigationContext->source`).

Nenhum job, normalizador, deduplicador ou view precisa mudar.

## 5. Contratos internos tipados

- `InvestigationRun::moduleSlugs()` — leitura única do `input_snapshot["modules"]`;
- `SourceCache::remember(): SourceOutcome` — cache escopado por
  `source + versão + payload + hash de entrada`, gravando **apenas**
  primitivos/arrays (compatível com `cache.serializable_classes = false`);
- `ResultDeduplicator` mantém uma descoberta consolidada com
  `metadata.dedup_sources` e marca os demais com `metadata.duplicate_of` (sem
  apagar evidências).

## 6. Fora do escopo (próximos prompts)

- Tipar o resultado da correlação (hoje array em `run.metadata.correlation`) —
  prompt 37;
- Relatório consumindo correlação/fatores de forma explícita — prompt 38;
- Execução de ferramentas reais (processo/CLI, timeout, sandbox) — docs/21.
