# Integração real — Maigret

Adapter real do [Maigret](https://github.com/soxoj/maigret) para busca de
username em fontes públicas.

## Verificações prévias (prompt 32)

| Item | Resultado |
|---|---|
| Licença | MIT |
| Versão | 0.6.6 (fixada em `docker/php/Dockerfile` via `ARG MAIGRET_VERSION`) |
| Requisitos | Python >= 3.10 (imagem usa Python 3.14 do Alpine) |
| Instalação | `apk add python3 py3-pip && pip install --break-system-packages --no-cache-dir maigret==0.6.6` (sem deps de build; wheels musllinux) |
| Binário | `/usr/bin/maigret` (disponível em app, worker e scheduler) |
| Formato de saída | JSON `-J simple` (um arquivo `report_<usuário>_simple.json` por execução) |
| Código de saída | 0 em sucesso; ausência de relatório → `unavailable`; timeout → `timeout` |
| Isolamento | diretório temporário próprio (0700) por execução, removido no `finally` |

## Execução segura (docs/21)

Implementada por `App\Sources\Runners\ProcessToolRunner` + `ToolCommand`:

- **argv separado** — o username vai como elemento próprio da lista; nada é
  concatenado em shell;
- **validação de entrada** — `^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$` (rejeita
  espaços, `;`, `-` inicial), aplicada em `supports()` e em `execute()`;
- **timeout** — por requisição (`--timeout`) e teto do processo
  (`min(MAIGRET_PROCESS_TIMEOUT, source_definitions.timeout)`);
- **diretório de trabalho isolado** e `HOME` apontando para ele;
- **ambiente controlado** — apenas `PATH`, `HOME`, locale e `PYTHONUNBUFFERED`
  (nenhuma variável da aplicação vaza para o processo);
- **stdout/stderr capturados** com limite de 200 KB; relatório JSON limitado por
  `max_report_bytes` (5 MB);
- **sem autoupdate** (`--no-autoupdate`) e banco local (`--db`, opcional) para
  execução determinística.

## Comando executado

```text
maigret <username> --id-type username --timeout 8 --top-sites 30 \
        --max-connections 30 --no-color --no-progressbar --no-autoupdate \
        --no-recursion --print-errors -J simple -fo <workdir> [--no-extracting]
```

Escopo limitado por `MAIGRET_TOP_SITES` (padrão 30) para respeitar as fontes e
caber no timeout do job.

## Conversão para o contrato comum

`MaigretReportParser` converte o relatório bruto em `SourceResult`:

- `source = maigret`, `entity_type = social_profile`, `title` = nome do site,
  `value` = username, `url` = perfil (`url_user`/`status.url`);
- `confidence = 0.8` (site respondeu que o username está reivindicado — sinal
  público, não confirmação de identidade);
- `evidence = [{type: public_profile_url, value: <url>}]`;
- `metadata = {site, tags, http_status, rank, tool}`.

Entradas sem URL válida são descartadas. Avisos `[!] ...` do Maigret viram
`SourceOutcome.warnings` → `source_runs.metrics.warnings`; a versão da ferramenta
vai para `source_runs.tool_version`. O texto exibido ao usuário sai **em
português**: `ToolWarningsTranslator` traduz, no ponto de leitura
(`ToolOutput::publicWarnings()`), os tipos de erro do Maigret e as recomendações
associadas; mensagem não catalogada é preservada como veio (nunca é descartada).

## Configuração

`config/sources.php` (env): `MAIGRET_DRIVER` (`process` | `mock`),
`MAIGRET_BINARY`, `MAIGRET_REQUEST_TIMEOUT`, `MAIGRET_PROCESS_TIMEOUT`,
`MAIGRET_TOP_SITES`, `MAIGRET_MAX_CONNECTIONS`, `MAIGRET_EXTRACT`,
`MAIGRET_DATABASE`, `MAIGRET_MAX_REPORT_BYTES`.

`source_definitions.config` (JSON) pode sobrepor qualquer uma dessas opções por
fonte, via `SourceDescriptor` (contrato do prompt 29). O driver `mock` mantém a
suíte de testes hermetica (phpunit.xml força `mock`).

## Teste controlado

- `MaigretAdapterTest` — montagem da argv, isolamento, limpeza do diretório,
  rejeição de username insegura e mapeamento de falhas (sem rede).
- `MaigretReportParserTest` — conversão do relatório (fixture real).
- `MaigretRealIntegrationTest` — execução real com identificador público
  institucional (`python`), verificando banco → normalização → relatório:
  `docker compose exec -e OSINT_E2E=1 app php artisan test --filter=MaigretRealIntegrationTest`.

## Limitações

- Fontes com bot protection/access denied não são contornadas (sem proxy nem
  bypass); aparecem como avisos na execução;
- `--no-extracting` (padrão) não coleta bio/nome, então a correlação se apoia em
  username/URL;
- Sem proxy configurado, muitos sites podem bloquear o IP do servidor.
