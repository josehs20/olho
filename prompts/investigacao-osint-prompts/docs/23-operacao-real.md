# Operação Real

Após os adapters reais funcionarem:

-   registrar versão da ferramenta quando possível;
-   registrar duração;
-   registrar timeout;
-   registrar quantidade de resultados;
-   registrar falhas;
-   controlar concorrência;
-   evitar disparos excessivos para a mesma fonte;
-   permitir reexecução sem duplicar descobertas;
-   manter histórico das execuções.

O sistema deve continuar útil quando uma fonte estiver indisponível: o
resultado geral pode ser `PARTIAL`.
