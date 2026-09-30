# Prompt 30 --- Orquestrador real da investigação

Implemente ou ajuste o orquestrador para que uma investigação:

1.  valide créditos;
2.  crie sua execução;
3.  determine os módulos aplicáveis ao tipo de identificador;
4.  crie registros de execução por módulo;
5.  envie jobs para a fila;
6.  execute adapters;
7.  persista resultados;
8.  passe resultados pela normalização;
9.  execute deduplicação;
10. execute correlação;
11. finalize como COMPLETED, PARTIAL ou FAILED.

A execução deve ser idempotente e observável.

Não colocar lógica de negócio dentro dos controllers.
