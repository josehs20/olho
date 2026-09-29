# 10 — Resultados e evidências

## Resultado

Todo resultado deve poder responder:

- o que foi encontrado;
- onde;
- quando;
- por qual fonte;
- por qual método;
- com quais evidências.

## Evidência

Tipos iniciais:

- username_match;
- name_match;
- email_match;
- phone_match;
- url_match;
- image_reference;
- public_page_reference.

## Fonte

Sempre preferir URL canônica e título público quando disponível.

## Integridade

Criar hash do identificador/carga normalizada para deduplicação quando apropriado. Não usar hash como substituto da evidência visível.

## Dados brutos

Podem ser armazenados para debug/auditoria com retenção limitada. Nunca incluir segredos de autenticação.

## Apresentação

O frontend deve diferenciar:

`Encontrado`, `Possível correspondência`, `Fonte indisponível`, `Não pesquisado`.

Nunca apresentar `não encontrado` como prova de inexistência.
