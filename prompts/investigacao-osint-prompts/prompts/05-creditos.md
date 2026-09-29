# Prompt 05 — Sistema de créditos

Implemente `docs/06-creditos.md`.

Criar serviços de domínio para:
- saldo;
- reserva;
- consumo;
- liberação;
- refund;
- ajuste administrativo.

Requisitos:
- transações;
- locking apropriado;
- idempotency key;
- ledger detalhado;
- saldo nunca negativo.

Criar testes de concorrência/isolamento conforme a capacidade do ambiente de teste.

Não conectar ao pagamento real.
