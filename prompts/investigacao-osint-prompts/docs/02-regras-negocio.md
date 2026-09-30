# 02 — Regras de negócio

## Créditos

1. Créditos são unidade interna, sem significado monetário fora do sistema.
2. O saldo é calculado pelo ledger mais ajustes compensatórios, nunca por mutação silenciosa.
3. Débitos de investigação precisam ser atômicos.
4. Créditos reservados devem possuir expiração/compensação em caso de falha definitiva.
5. Administradores podem ajustar créditos somente com motivo obrigatório.

## Investigação

1. Toda investigação pertence a um usuário — **exceto a investigação avulsa**
   (prompt 41): comprada sem cadastro, com preço fixo e acesso exclusivo por
   token de alta entropia, ela não tem dono e nunca aparece no histórico de
   contas. Ver `35-investigacao-avulsa.md`.
2. Uma investigação pode possuir várias execuções (`runs`).
3. Cada run possui snapshot das entradas usadas.
4. Cada módulo possui execução independente.
5. Um módulo nunca pode acessar dados privados de outro usuário.
6. Resultado público deve guardar URL e timestamp quando possível.
7. Confiança é heurística explicável, não prova de identidade.
8. O relatório deve informar limitações e módulos que falharam.

## Reexecução

- uma reexecução nunca sobrescreve o run anterior;
- custos da nova execução são independentes;
- resultados antigos continuam auditáveis conforme política de retenção.

## Pagamento PIX

- pedido possui status: pending, awaiting_payment, paid, expired, cancelled, refunded, failed;
- somente confirmação verificável muda para `paid`;
- evento de webhook precisa de idempotency key/event id;
- créditos são concedidos uma única vez.

## Fontes

Cada fonte possui:

- slug estável;
- nome;
- tipo;
- ativo/inativo;
- custo operacional opcional;
- timeout;
- retry policy;
- configuração segura.

## Limitações

Quando uma fonte não puder ser consultada, o sistema deve distinguir:

- nenhum resultado encontrado;
- fonte indisponível;
- fonte bloqueou a consulta;
- timeout;
- entrada inválida;
- módulo não habilitado.
