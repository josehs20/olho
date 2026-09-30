# Execução Segura das Ferramentas

Ferramentas externas devem ser executadas com:

-   argumentos separados, nunca shell concatenado;
-   timeout obrigatório;
-   limite de memória/processo quando suportado;
-   diretório de trabalho isolado;
-   ambiente controlado;
-   captura de stdout/stderr;
-   código de saída;
-   limpeza de arquivos temporários;
-   limite de tamanho da saída;
-   logs sem credenciais.

A entrada do usuário nunca deve virar comando shell diretamente.

Antes de ativar uma ferramenta real, verificar licença, dependências,
termos de uso e comportamento atual do projeto.
