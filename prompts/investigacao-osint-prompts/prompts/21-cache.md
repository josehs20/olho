# Prompt 21 — Cache e idempotência operacional

Implementar cache somente onde reduzir custo/latência sem prejudicar a precisão.

Definir cache key com:
- source;
- versão do adapter;
- hash de entrada;
- parâmetros relevantes.

Configurar TTL.

Não retornar resultado de usuário diferente.

Criar idempotência adicional para jobs e webhooks onde necessário.
