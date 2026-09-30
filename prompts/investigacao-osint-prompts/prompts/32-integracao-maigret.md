# Prompt 32 --- Integração real do Maigret

Substitua o adapter mock do Maigret por uma integração real, mantendo o
contrato existente.

Antes de editar: - verificar como o projeto executa dependências
externas; - verificar versão/licença/requisitos do Maigret; - definir
instalação reproduzível no Docker; - definir timeout; - definir formato
de saída; - definir tratamento de código de saída; - definir isolamento.

Não usar shell concatenado com input do usuário.

O resultado bruto deve ser convertido pelo adapter para o contrato
comum.

Executar primeiro um teste controlado com um identificador público de
teste escolhido pelo projeto.

Somente considerar concluído se o resultado chegar ao banco,
normalização e relatório.
