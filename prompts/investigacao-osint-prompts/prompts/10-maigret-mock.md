# Prompt 10 — Adapter mock Maigret

Não integrar Maigret real ainda.

Criar `MaigretMockAdapter` seguindo o contrato de `docs/09-source-adapters.md`.

Características:
- aceita username;
- retorna fixtures determinísticas;
- permite cenários: found, not_found, timeout, error;
- registra source key `maigret`;
- não acessa internet.

Criar testes para cada cenário e para normalização.
