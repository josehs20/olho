# Prompt 31 --- Persistência da execução por módulo

Verifique se existe persistência suficiente para saber exatamente o que
ocorreu em cada fonte.

Se necessário, crie uma estrutura de execução por módulo contendo: -
investigation_id; - source; - status; - started_at; - finished_at; -
duration_ms; - attempts; - error_code; - error_message sanitizada; -
result_count; - tool_version quando disponível.

Atualize dashboard/relatório somente se necessário.

Adicione testes de sucesso, timeout, erro e retry.
