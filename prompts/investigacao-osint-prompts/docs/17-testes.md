# 17 — Testes

## Pirâmide

### Unit

- regras de crédito;
- cálculo de preço;
- estados;
- normalizadores;
- deduplicação;
- score/correlação;
- validações de adapters.

### Feature

- cadastro/login;
- compra de pacote;
- pagamento fake;
- webhook fake;
- criação de investigação;
- execução de fila;
- autorização de relatório;
- admin.

### Integration

- MySQL;
- Redis queue quando útil;
- storage;
- adapters mock.

## Cenários obrigatórios

1. saldo insuficiente;
2. duas requisições de débito concorrentes;
3. webhook duplicado;
4. pagamento confirmado duas vezes;
5. job retry;
6. source timeout;
7. source error;
8. source sem resultados;
9. resultado duplicado entre fontes;
10. usuário tentando abrir investigação de outro usuário;
11. admin ajustando crédito sem motivo;
12. input malformado;
13. upload inválido;
14. relatório com conteúdo HTML malicioso vindo da fonte.

## Qualidade

Executar formatador/linter e suite de testes antes de finalizar cada prompt.
