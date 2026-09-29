# Prompt 06 — Pagamento PIX abstrato

Leia `docs/07-pagamentos.md`.

Implemente:
- PaymentProvider interface;
- FakePixProvider;
- criação de pedido;
- tela de checkout;
- status do pedido;
- webhook fake/testável;
- idempotência;
- concessão de créditos após confirmação.

Não usar uma chave PIX real.
Usar placeholders de ambiente.

O fluxo deve ser totalmente testável sem internet.
