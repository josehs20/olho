# 08 — Investigações

## Criação

O usuário informa os dados disponíveis e seleciona módulos.

Exemplo:

```json
{
  "name": "João Silva",
  "username": "joaosilva",
  "email": null,
  "phone": null,
  "city": "Belo Horizonte",
  "state": "MG"
}
```

## Snapshot

O run salva cópia do input usado para permitir reprodução e auditoria.

## Status

Investigação:

`draft`, `queued`, `running`, `partial`, `completed`, `failed`, `cancelled`, `expired`.

Fonte:

`pending`, `running`, `completed`, `failed`, `timeout`, `skipped`.

## Seleção de módulos

Cada módulo declara:

- chave;
- nome amigável;
- entrada necessária;
- custo em créditos;
- ativo/inativo;
- ordem/prioridade;
- timeout padrão.

## Regras de entrada

- trim;
- normalização sem destruir valor original;
- guardar valor normalizado e, quando justificável, valor de exibição separado;
- não guardar dados extras não necessários à finalidade da consulta.

## Execução

A criação da investigação não deve executar ferramentas externas na mesma requisição HTTP.

## Relatório

O relatório deve informar:

- o que foi pesquisado;
- quais módulos rodaram;
- quais falharam;
- resultados;
- fontes;
- nível de confiança e critérios;
- limitações.
