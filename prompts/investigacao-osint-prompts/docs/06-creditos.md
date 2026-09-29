# 06 — Créditos

## Objetivo

Criar um subsistema financeiro interno robusto o suficiente para suportar vendas de pacotes e consumo auditável.

## Requisitos

- saldo atual;
- ledger;
- reserva;
- débito;
- estorno;
- ajuste administrativo;
- extrato;
- idempotência.

## Operações

```text
purchase_credit
promotional_credit
reserve
consume
release
refund
admin_adjustment
```

## Concorrência

Usar transação + lock apropriado na carteira para impedir dois débitos simultâneos de ultrapassarem o saldo.

## Exibição

Mostrar saldo e histórico sem expor dados internos desnecessários.

## Integração

Investigações nunca devem escrever diretamente em `wallet.balance`. Usar um serviço de domínio, por exemplo `CreditLedgerService`.
