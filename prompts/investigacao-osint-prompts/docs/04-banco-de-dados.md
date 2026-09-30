# 04 — Banco de dados

## Entidades principais

### users

Usuários de aplicação. Usar estrutura compatível com autenticação Laravel.

### wallets

Uma carteira por usuário.

Campos sugeridos:

- id
- user_id
- balance
- created_at
- updated_at

### credit_transactions

Ledger imutável.

Campos:

- id
- wallet_id
- user_id
- type
- amount
- balance_before
- balance_after
- reference_type
- reference_id
- idempotency_key
- description
- metadata JSON
- created_at

### credit_reservations

Controle temporário de créditos antes da conclusão.

### credit_packages

Pacotes vendáveis:

- name
- credits
- price_cents
- active
- display_order
- metadata

### payments

Pedido de pagamento:

- user_id
- credit_package_id
- provider
- external_id
- amount_cents
- status
- expires_at
- paid_at
- idempotency_key
- metadata

### payment_events

Eventos recebidos de gateway/webhook, deduplicados por external event id.

### investigations

Cabeçalho da investigação.

- user_id
- title opcional
- status
- selected_modules JSON ou relação
- current_run_id opcional
- created_at
- updated_at

### investigation_runs

Execução individual.

- investigation_id
- run_number
- status
- started_at
- finished_at
- credits_reserved
- credits_consumed
- input_snapshot JSON
- error_summary

### source_definitions

Catálogo de fontes/adapters.

### source_runs

Execução de fonte por run.

- investigation_run_id
- source_definition_id
- status
- attempt
- started_at
- finished_at
- duration_ms
- error_code
- error_message_public
- metrics JSON

### source_results

Resultado bruto normalizado por fonte.

### entities

Opcional para correlação futura. Não guardar dados desnecessários. Pode representar pessoa, organização, perfil, domínio etc.

### entity_identifiers

Liga entidade a username, e-mail, domínio etc.

### evidence

Evidência de um resultado.

### reports

Snapshot/metadata do relatório HTML gerado.

### audit_logs

Ações sensíveis administrativas e financeiras.

## Índices

Criar índices para:

- user_id;
- status;
- created_at;
- external_id;
- idempotency_key;
- run/source unique key;
- URLs normalizadas/hash quando aplicável.

## Regras

- foreign keys;
- cascade/restrict conforme o caso;
- não apagar ledger por cascade de usuário sem política explícita;
- usar casts JSON adequados;
- valores monetários em centavos inteiros;
- UTC no armazenamento.


## Investigação avulsa (prompt 41)

- `credit_packages.kind` (`credits` | `guest_investigation`) — o preço da avulsa é
  uma linha única, sem créditos.
- `investigations`: `user_id` nullable, `origin`, `access_token_hash` (char(64),
  único), `access_token_created_at`, `access_token_expires_at`,
  `guest_price_cents`, índice `(origin, status)`.
- `payments`: `user_id` nullable e `guest_investigation_id` (único, FK
  `investigations` com cascade) — um pedido por avulsa.
- `reports.user_id` nullable (relatório de run avulso não tem dono).
- Nenhuma tabela de créditos foi alterada: a avulsa não movimenta ledger nem
  carteiras.
