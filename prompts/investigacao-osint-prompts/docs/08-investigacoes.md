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


## Investigação avulsa (prompt 41)

- `investigations.origin` (`account` | `guest`); a avulsa tem `user_id` nulo,
  `guest_price_cents` (preço congelado), `access_token_hash` (SHA-256 do token),
  `access_token_created_at`/`access_token_expires_at`.
- O run #1 é criado no início com o snapshot das entradas, mas **não é
  despachado**: a liberação acontece na confirmação do pagamento. O
  `StartInvestigationRunJob` revalida o pagamento (segunda camada) e a avulsa não
  reserva créditos (`metadata.funding = guest_prepaid`).
- O visitante não escolhe módulos: usa-se todo módulo ativo aplicável à entrada.
- Acesso somente pelo token (`/investigacao/acesso/{token}`), com
  `no-store`/`noindex`; rotas autenticadas respondem 404 para avulsa (admin acessa
  pelo painel).
- Rascunho não pago expira (`guest:expire-unpaid`) e nada é executado.
