# Contrato dos Adapters

Cada fonte deve implementar um contrato comum.

Entrada mínima:

-   investigation;
-   identifier;
-   identifier_type;
-   opções;
-   timeout;
-   correlation/request id.

Saída mínima:

-   source;
-   source_type;
-   status;
-   findings;
-   evidence;
-   warnings;
-   metadata;
-   duration_ms.

O adapter deve retornar erro estruturado quando a ferramenta falhar.
Exceções não devem apagar o histórico da execução.

A implementação concreta pode usar processo CLI, biblioteca Python ou
HTTP, desde que o domínio Laravel permaneça desacoplado.
