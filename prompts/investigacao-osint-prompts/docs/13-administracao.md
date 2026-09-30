# 13 — Administração

## Dashboard

Indicadores:

- usuários ativos;
- investigações por status;
- pagamentos;
- créditos vendidos/consumidos;
- fontes com erro;
- jobs pendentes.

## Usuários

Listagem e busca. Ações:

- ver detalhes;
- bloquear/desbloquear;
- conceder/remover créditos com motivo;
- visualizar investigações;
- visualizar eventos de auditoria relevantes.

## Pacotes

CRUD simples de pacotes de créditos.

## Fontes

Ativar/desativar, timeout, retries e parâmetros operacionais não secretos.

## Investigações

Consultar status, source runs e erro sanitizado.

## Auditoria

Todo ajuste financeiro e ação administrativa sensível gera audit log.


## Investigação avulsa (prompt 41)

- `/admin/packages` ganhou a seção "Investigação avulsa (sem cadastro)": preço
  vigente editável (linha `kind = guest_investigation`), que **não** pode ser
  criada nem removida pelo painel; a criação de pacotes continua sendo apenas de
  créditos.
- `/admin/investigations` lista e filtra por origem (Todas / Contas / Avulsas),
  mostrando preço avulso, status do pagamento e `Sem conta`.
- `/admin/payments` distingue Créditos x Avulsa e vincula o pedido à investigação.
- O token de acesso e o seu hash não são exibidos no painel.
