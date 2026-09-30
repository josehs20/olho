# Prompt 39 --- Créditos, retry e idempotência

Audite o consumo de créditos durante execução real.

Garantir: - uma cobrança coerente por investigação/módulo conforme regra
atual; - nenhum consumo duplicado em retry; - lock/transação; -
idempotency key; - reexecução controlada; - histórico no ledger; -
investigação parcial sem corrupção de saldo.

Simular falha no worker, retry e reexecução.

Os testes devem provar que o saldo final é consistente.
