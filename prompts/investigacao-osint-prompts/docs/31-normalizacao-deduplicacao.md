# Normalização e deduplicação reais (prompt 36)

Auditoria do modelo normalizado depois das fontes reais (Maigret, Sherlock,
WhatsMyName, Holehe) e definição da **chave de deduplicação estável**.

## Achados da auditoria

| # | Achado | Correção |
|---|---|---|
| 1 | a chave era `sha256(entity_type \| value \| url)` — sem plataforma e sem versão, impossível evoluir sem reescrever histórico | chave **v2** versionada, com `keyParts()`/`explain()` auditáveis |
| 2 | `dedup_hash` só era preenchido em uma etapa posterior (podia ficar nulo se o job não rodasse) | o **normalizador grava a chave** junto do resultado; o job virou reconciliador |
| 3 | a URL canônica só removia fragmento e `www.` | também fixa o esquema em `https`, remove parâmetros de rastreamento e ordena os parâmetros restantes |
| 4 | `entity_type` era texto livre | conjunto canônico (`social_profile`, `email_account`, `phone_account`, `web_page`, `person_name`, `unknown`) com mapa de aliases |
| 5 | a descoberta consolidada guardava só os slugs das fontes | guarda `dedup_sources`, `dedup_group_size` e `dedup_observations` (fonte, URL, data e evidências de cada observação) |
| 6 | duplicatas de execuções anteriores podiam ficar “presas” | a deduplicação limpa os marcadores antes de recalcular (converge em reexecuções) |

## Chave estável (v2)

```text
sha256( entity_type | platform | identifier | canonical_url )
```

- **`entity_type`**: tipo canônico da entidade observada;
- **`platform`**: serviço onde a observação vive (host da URL canônica; sem URL,
  `metadata.platform` → `metadata.domain` → `metadata.network` → `metadata.site`);
- **`identifier`**: valor observado normalizado (minúsculas, sem espaços);
- **`canonical_url`**: URL normalizada.

### Por que a ferramenta *não* entra na chave

O prompt pede "source/platform". A leitura adotada — e registrada no código — é
que a fonte da chave é a **plataforma observada** (github.com, instagram.com…),
não a ferramenta que descobriu. Se `maigret`, `sherlock` e `whatsmyname` fossem
parte da chave, a mesma descoberta viraria três itens e a consolidação exigida
no mesmo prompt seria impossível.

### Normalização da URL

```text
https://www.Example.com/Perfil/?utm_source=x&id=7#bio  ->  https://example.com/perfil?id=7
```

- esquema fixo `https` (http/https do mesmo recurso são a mesma observação);
- host minúsculo, sem `www.`;
- sem barra final e sem fragmento;
- parâmetros de rastreamento removidos (`utm_*`, `fbclid`, `gclid`, `ref`, …);
- demais parâmetros ordenados (resultado determinístico).

## Consolidação sem apagar evidência

Para cada grupo (`dedup_hash` com 2+ resultados):

- **representante** (menor `id`): recebe `dedup_sources`, `dedup_group_size` e
  `dedup_observations`;
- **demais**: recebem `duplicate_of` (id do representante) e `duplicate_of_hash`;
- nenhum resultado, evidência ou URL é removido — cada fonte mantém a sua linha.

## Tipos diferentes não se misturam

Um indício de e-mail (Holehe: `email_account`, `https://github.com`) **nunca**
se funde com um perfil social (`social_profile`, `https://github.com/python`),
mesmo compartilhando a plataforma — evita transformar um sinal fraco em
identidade.

## Testes

- `ResultDeduplicatorTest`: chave, plataforma (URL e metadados), URL canônica
  (esquema/rastreamento/ordem), consolidação, convergência e rehash de linhas
  antigas (`v1` → `v2`).
- `SourceResultNormalizerTest`: tipo canônico, plataforma e chave gravados.
- `CrossAdapterDeduplicationTest` (**teste cruzado entre adapters reais**):
  Maigret + Sherlock + WhatsMyName consolidam GitHub e Telegram com as três
  fontes observadoras e evidências preservadas; Holehe não se funde com perfis.
