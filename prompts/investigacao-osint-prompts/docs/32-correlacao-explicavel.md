# Correlação explicável (prompt 37)

Auditoria da correlação existente e reescrita para que cada associação seja
**explicável**, com fatores, evidências, pontuação interna, explicação e
limitações.

## Achados da auditoria

| # | Achado | Correção |
|---|---|---|
| 1 | os sinais guardavam só `name` e `weight` | cada sinal virou **fator** com tipo, peso, explicação, limitação, evidências usadas e fontes |
| 2 | `evidence_ids` listava **todos** os resultados do run, não os que casaram | as evidências são as dos resultados que efetivamente produziram o fator |
| 3 | o score podia ser lido como probabilidade | `score_kind = internal_correlation_indicator` + limitações fixas persistidas |
| 4 | username coincidente produzia a faixa "possível correspondência" | sem fator **corroborante**, nenhuma faixa de associação é exibida |
| 5 | sinais não avaliados (imagem, bio) desapareciam | vão para `not_evaluated`, explícitos no resultado e no relatório |
| 6 | regra sem versão explícita dos critérios | `correlation.rule_version = v2` e estrutura de regra versionada em config |

## Regras (documentadas e determinísticas)

Fonte única dos pesos: `config/correlation.php`.

| Fator (tipo) | Sinal | Peso | Corrobora? | Avaliado? |
|---|---|---|---|---|
| `same_username` | `username_exact_match` | 40 | **não** (coincidência isolada) | sim |
| `matching_public_name` | `name_compatible` | 20 | sim | sim |
| `same_email` | `email_exact_match` | 20 | sim | sim |
| `matching_location` | `location_compatible` | 10 | sim | sim |
| `matching_image_reference` | `image_reference_compatible` | 20 | sim | **não (MVP)** |
| `similar_bio` | `bio_similar` | 10 | sim | **não (MVP)** |

Critérios de casamento (somente dados observados e persistidos neste run):

- **username/e-mail**: valor observado igual ao informado (minúsculas, sem
  acentos);
- **nome**: igualdade com o valor observado **ou** presença no título/descrição
  pública da descoberta;
- **localização**: cidade/estado informados contidos em `metadata.location`;
- duplicatas consolidadas (prompt 36) **não somam** pontos — só o representante;
- mesmo informando dados, nada é inferido: o que não foi observado não pontua.

## Faixas de exibição

```text
score 0                                  -> sem correspondência observada
score > 0 e nenhum fator corroborante    -> username coincidente — sem corroboração
score >= 30                              -> possível correspondência
score >= 60                              -> correspondência forte por sinais públicos
```

`identity_asserted` é sempre `false`: o serviço não afirma identidade, nem por
username coincidente nem por acúmulo de sinais.

## O que é persistido em `investigation_runs.metadata.correlation`

```json
{
  "score": 70, "score_max": 100,
  "score_kind": "internal_correlation_indicator",
  "rule_version": "v2",
  "label": "correspondência forte por sinais públicos",
  "identity_asserted": false,
  "factors": [{
    "type": "same_username", "signal": "username_exact_match", "weight": 40,
    "corroborating": false,
    "explanation": "O username pesquisado é exatamente o mesmo identificador observado no perfil público.",
    "limitation": "Username é escolhido pelo titular e pode ser reutilizado por pessoas diferentes; isoladamente não indica identidade.",
    "evidence_ids": [12, 13], "evidence_types": ["username_match"],
    "sources": ["maigret", "sherlock"], "observed_values": ["joao"]
  }],
  "signals": [{"name": "username_exact_match", "weight": 40}],
  "evidence_ids": [12, 13], "evidence_count": 2,
  "sources_observed": ["maigret", "sherlock"],
  "explanations": ["..."], "limitations": ["...", "..."],
  "not_evaluated": ["matching_image_reference", "similar_bio"],
  "observed_inputs": {"username": "joao", "...": "..."},
  "observed_results": 4, "evaluated_at": "2026-09-30T00:00:00Z"
}
```

`signals` permanece para compatibilidade com consumidores anteriores.

## Limitações sempre presentes

1. o valor é indicador interno, **não** probabilidade de identidade;
2. só usa dados observados neste run e as regras desta versão;
3. correspondência possível ≠ identidade confirmada;
4. limitação específica de cada fator que casou;
5. aviso quando todos os sinais vieram de **uma única fonte**;
6. quando nada casa: "ausência de correspondência não prova inexistência".

## Testes (`CorrelationServiceTest`)

- **positivo**: username + nome + localização → 70, faixa forte, fatores com
  explicação/limitação/evidências/fontes;
- **ambíguo**: só username → 40, faixa "username coincidente — sem corroboração"
  + limitação explícita + aviso de fonte única;
- **negativo**: nada casa → 0, "sem correspondência observada", limitação de
  ausência;
- duplicatas não somam; nome informado que não aparece no dado observado não
  casa; resultado determinístico (exceto `evaluated_at`).
