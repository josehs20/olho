# Integração real — WhatsMyName

[WhatsMyName](https://github.com/WebBreacher/WhatsMyName) como fonte
independente de busca de username.

## Verificações prévias (prompt 34)

| Item | Resultado |
|---|---|
| Formato | **dataset** JSON (`wmn-data.json`, ~717 sites) descrito por `wmn-data-schema.json` |
| Licença | **CC BY-SA 4.0** — © 2015-2026 Micah Hoffman e colaboradores (atribuição + share-alike) |
| Dependências | nenhuma (os checkers embutidos foram **removidos em maio/2023**) |
| Modo de execução atual | o repositório mantém apenas o dataset + um verificador de saúde dos próprios sites (`scripts/checker`, requer Playwright/Chromium); a busca é feita por ferramentas externas que consomem o JSON (SpiderFoot, transformações Maltego, Naminter, Blackbird…) |

Como não existe CLI oficial de busca, o adapter **fixa o dataset por commit** na
imagem e executa as verificações conforme o schema publicado — o mesmo caminho
das integrações conhecidas. O `LICENSE.md` é mantido ao lado do dataset para
cumprir a atribuição da CC BY-SA.

## Regras de verificação (schema)

Cada site declara `uri_check` (com `{account}`), `e_code`/`e_string` (conta
existente) e `m_code`/`m_string` (username livre), além de opcionais
`uri_pretty`, `post_body`, `headers` e `strip_bad_char`.

| Veredito | Condição |
|---|---|
| encontrado | `status == e_code` e o corpo contém `e_string` |
| não encontrado | `status == m_code` e o corpo contém `m_string` (`m_string` vazio = qualquer corpo) |
| inconclusivo | qualquer outra resposta (contabilizada como erro, nunca como descoberta) |

Recursos do dataset aplicados pelo adapter:

- `uri_pretty` → **URL pública** da descoberta (a URL de verificação não é exposta);
- `post_body` + `headers` → requisição POST (ex.: APIs GraphQL como o AniList);
- `strip_bad_char` → caracteres removidos do username antes da substituição;
- categorias: **`xx NSFW xx` nunca é consultada** (exclusão padrão, configurável).

## Limites e segurança

- Escopo limitado por uma **lista curada padrão** de 26 sites
  (`WHATSMYNAME_SITES`, nomes exatos do dataset) e por
  `WHATSMYNAME_MAX_SITES` (40) como teto; alternativamente, filtrar por
  `WHATSMYNAME_CATEGORIES` (lista vazia = todas as permitidas);
- requisições com timeout por request (`WHATSMYNAME_REQUEST_TIMEOUT`) e orçamento
  total (`WHATSMYNAME_EXECUTION_TIMEOUT`), executadas em lotes concorrentes
  (`WHATSMYNAME_CONCURRENCY`);
- User-Agent identificável e apenas endpoints públicos — nada contorna
  autenticação, CAPTCHA ou paywall.

## Conversão para o contrato comum

`WhatsMyNameReportParser` produz `SourceResult`: `source = whatsmyname`,
`entity_type = social_profile`, `title` = site, `value` = username, `url` =
perfil (`uri_pretty`), `confidence = 0.8`, evidência `public_profile_url` e
metadados `site/category/method/http_status`. Saídas inválidas são descartadas.

Mapeamento de falhas:

| Situação | Resultado |
|---|---|
| dataset ausente | `SourceUnavailableException` |
| dataset inválido/sem sites verificáveis | `SourceUnavailableException` (saída inválida) |
| nenhuma resposta HTTP válida | `SourceUnavailableException` (fonte inacessível) |
| orçamento esgotado sem descobertas | `SourceTimeoutException` |
| orçamento esgotado com descobertas | resultados parciais + aviso (`metadata.partial`) |
| username insegura | `SourceInvalidInputException` sem nenhuma requisição |

## Testes

- `WhatsMyNameReportParserTest` — conversão e descarte de saída inválida;
- `WhatsMyNameAdapterTest` — os cinco cenários pedidos (encontrado, nenhum
  resultado, fonte indisponível, timeout, saída inválida) + `post_body`,
  `strip_bad_char`, `uri_pretty`, exclusão de NSFW, `healthCheck` e troca
  mock↔http (com `Http::preventStrayRequests()`);
- `WhatsMyNameDeduplicationTest` — adapter real + mock do Maigret: a descoberta
  comum é agrupada com `dedup_sources = [maigret, whatsmyname]` sem perder
  evidências;
- `WhatsMyNameRealIntegrationTest` (gated por `OSINT_E2E=1`) — dataset real e
  HTTP real contra o identificador público institucional `python`.

```bash
docker compose exec -e OSINT_E2E=1 app php artisan test --filter=WhatsMyNameRealIntegrationTest
```

## Limitações

- O dataset muda com o tempo: **nove** falsos negativos/positivos de sites são
  esperados (é um dataset comunitário); o `REVISION` gravado na imagem permite
  saber exatamente qual versão foi usada;
- sem proxy, sites com proteção anti-bot podem responder de forma inconclusiva
  (contabilizados em `sites_unresolved` e exibidos como aviso);
- a atribuição CC BY-SA 4.0 é registrada nos metadados da execução
  (`metrics.adapter.dataset_license`); a exibição ao usuário final deve ser
  avaliada no produto.
