# Prompt 46 — Modo de Desenvolvimento Rápido e Validação Manual

## 1. CONTEXTO

A partir deste prompt, o projeto OLHO deve adotar um fluxo de desenvolvimento focado em velocidade de implementação.

Os prompts anteriores possuem instruções de execução de testes completos, E2E, integrações reais e auditorias extensas. Embora sejam importantes para a validação final do projeto, executar esse processo a cada etapa está tornando o desenvolvimento excessivamente demorado.

A partir deste momento, a validação funcional completa será concentrada em uma etapa final.

Durante a implementação de cada novo prompt, o objetivo principal é:

1. entender a implementação existente;
2. implementar corretamente a funcionalidade solicitada;
3. verificar rapidamente erros evidentes;
4. entregar a funcionalidade para validação manual através da interface;
5. corrigir problemas encontrados posteriormente a partir dos erros reais apresentados.

---

# 2. OBJETIVO

Estabelecer este comportamento como padrão para os próximos prompts do projeto OLHO.

O agente deve priorizar:

* implementação;
* integração com o código existente;
* compatibilidade;
* organização;
* segurança básica;
* verificações rápidas.

O agente **não deve executar automaticamente suítes completas de testes ou E2E demorados após cada implementação**.

---

# 3. REGRA PRINCIPAL

Após implementar uma funcionalidade:

> **NÃO execute a suíte completa de testes neste momento.**

A validação funcional completa será feita posteriormente pelo usuário através da interface do sistema.

O agente deve executar somente verificações rápidas e diretamente relacionadas à alteração realizada.

---

# 4. O QUE DEVE SER FEITO

Após implementar uma funcionalidade, realizar apenas verificações rápidas como:

* verificar sintaxe;
* verificar imports;
* verificar classes inexistentes;
* verificar referências quebradas;
* verificar rotas adicionadas ou alteradas;
* verificar migrations quando aplicável;
* verificar configuração básica;
* verificar erros óbvios de PHP/Laravel;
* verificar se arquivos essenciais estão corretamente conectados;
* executar um teste unitário muito específico somente se for rápido e diretamente relacionado à alteração.

Se uma verificação rápida encontrar um erro claramente relacionado à implementação atual, corrigir o erro.

---

# 5. O QUE NÃO DEVE SER FEITO

Não executar automaticamente:

* suíte completa de PHPUnit;
* todos os Feature Tests;
* todos os Unit Tests;
* E2E completo;
* auditorias completas;
* testes reais dos provedores externos;
* chamadas reais ao PagBank;
* webhooks reais;
* investigações reais do OLHO;
* execução prolongada dos workers;
* testes extensos de Redis;
* testes extensos dos adaptadores OSINT;
* testes de todas as funcionalidades existentes;
* ciclos repetitivos de teste → correção → teste completo.

Também não deve ficar preso tentando fazer a suíte completa passar quando o problema não está diretamente relacionado à alteração atual.

---

# 6. NÃO ALTERAR FUNCIONALIDADES ANTIGAS PARA "FAZER OS TESTES PASSAREM"

Se um teste antigo falhar por motivo não relacionado à implementação atual:

1. identificar o problema;
2. verificar rapidamente se a alteração atual causou o problema;
3. se não estiver relacionado, não modificar código antigo apenas para fazer o teste passar;
4. registrar a ocorrência no relatório final;
5. continuar a implementação.

Não realizar refatorações amplas apenas porque um teste antigo apresentou problema.

---

# 7. INTEGRAÇÕES EXTERNAS

Para integrações como:

* PagBank;
* APIs externas;
* webhooks;
* Maigret;
* Sherlock;
* WhatsMyName;
* Holehe;
* serviços de terceiros;

não executar integrações reais durante cada prompt.

Verificar apenas:

* configuração;
* classes;
* contratos;
* requests;
* services;
* adapters;
* rotas;
* controllers;
* validações;
* tratamento de erros;
* estrutura de webhook;
* persistência.

A validação real da integração será feita posteriormente em uma etapa específica.

---

# 8. VALIDAÇÃO MANUAL PELO USUÁRIO

Ao finalizar cada prompt, entregar uma lista objetiva para o usuário testar manualmente.

Exemplo:

```text
TESTAR MANUALMENTE

1. Acessar /admin/pagamentos
2. Configurar o PagBank
3. Salvar as credenciais
4. Criar uma investigação
5. Selecionar PIX
6. Verificar geração do checkout
7. Verificar status do pagamento
8. Verificar retorno para a investigação
```

A lista deve conter somente os testes relevantes para a funcionalidade implementada.

---

# 9. CASO O USUÁRIO ENCONTRE UM ERRO

O fluxo esperado será:

```text
AGENTE IMPLEMENTA
        ↓
VERIFICAÇÕES RÁPIDAS
        ↓
ENTREGA
        ↓
USUÁRIO TESTA PELA INTERFACE
        ↓
USUÁRIO ENVIA ERRO
        ↓
AGENTE INVESTIGA
        ↓
CORRIGE
        ↓
NOVA VALIDAÇÃO MANUAL
```

Quando o usuário enviar:

* mensagem de erro;
* stack trace;
* screenshot;
* log;
* comportamento inesperado;

investigar primeiro a causa real antes de modificar o código.

Não fazer alterações aleatórias para tentar eliminar o sintoma.

---

# 10. TESTES FINAIS

Os testes completos continuam sendo importantes.

Porém, eles devem ser executados em uma etapa específica de validação final do projeto, e não após cada prompt.

Quando o usuário solicitar explicitamente:

> "Execute os testes completos"

ou

> "Faça a auditoria final"

aí sim executar:

* PHPUnit completo;
* Feature Tests;
* Unit Tests;
* E2E;
* integrações;
* auditoria;
* Pint;
* verificações de segurança;
* demais validações necessárias.

---

# 11. COMPATIBILIDADE COM OS PROMPTS ANTERIORES

Este prompt estabelece uma regra geral para o fluxo de desenvolvimento.

Quando um prompt futuro possuir uma instrução semelhante a:

> "Execute a suíte completa de testes"

ou:

> "Execute todos os testes"

essa instrução deve ser interpretada como opcional durante a implementação, **exceto quando o próprio usuário solicitar explicitamente a execução dos testes completos naquele momento**.

Priorizar o fluxo definido neste prompt.

Não remover testes existentes.

Não desativar PHPUnit.

Não remover E2E.

Não remover auditorias.

Apenas evitar sua execução automática durante cada etapa de desenvolvimento.

---

# 12. QUALIDADE MÍNIMA OBRIGATÓRIA

O modo rápido não significa implementar de qualquer maneira.

Antes de finalizar, verificar:

* código está sintaticamente válido;
* arquivos estão nos locais corretos;
* classes e namespaces estão corretos;
* dependências estão corretas;
* migrations possuem estrutura válida;
* rotas apontam para os controllers corretos;
* views utilizam as variáveis esperadas;
* configurações estão corretamente conectadas;
* nenhuma credencial foi gravada diretamente no código;
* nenhuma informação sensível foi exposta;
* alteração respeita a arquitetura existente.

---

# 13. EVITAR ALTERAÇÕES DESNECESSÁRIAS

Antes de modificar qualquer arquivo:

1. localizar a implementação existente;
2. entender como ela funciona;
3. identificar a menor alteração necessária;
4. reutilizar estruturas existentes;
5. evitar duplicação;
6. evitar refatorações que não sejam necessárias para a tarefa.

Não reconstruir componentes que já funcionam.

Não criar uma segunda implementação de algo que já existe.

---

# 14. RELATÓRIO FINAL

Ao terminar cada prompt, responder de forma objetiva seguindo esta estrutura:

```text
IMPLEMENTAÇÃO CONCLUÍDA

Arquivos alterados:
- arquivo
- arquivo
- arquivo

Implementado:
- funcionalidade 1
- funcionalidade 2
- funcionalidade 3

Verificações rápidas:
✓ Sintaxe
✓ Rotas
✓ Configuração
✓ Estrutura
✓ Verificações relacionadas à implementação

Testes completos:
NÃO EXECUTADOS — validação manual pendente.

TESTAR MANUALMENTE:

1. ...
2. ...
3. ...
4. ...
5. ...

Observações:
- ...
```

Não escrever que os testes passaram se eles não foram executados.

Não afirmar que uma integração externa funciona se ela não foi realmente validada.

---

# 15. REGRA PARA O AGENTE

Sempre que estiver executando um novo prompt do projeto OLHO, siga esta ordem:

```text
1. AUDITAR
   ↓
2. ENTENDER A IMPLEMENTAÇÃO EXISTENTE
   ↓
3. IMPLEMENTAR
   ↓
4. VERIFICAR RAPIDAMENTE
   ↓
5. CORRIGIR ERROS ÓBVIOS RELACIONADOS
   ↓
6. PARAR
   ↓
7. ENTREGAR PARA TESTE MANUAL
```

Não transformar cada prompt em uma auditoria completa do sistema.

---

# 16. PRINCÍPIO

O objetivo deste modo é separar claramente:

### Desenvolvimento

Implementar funcionalidades rapidamente.

### Validação manual

O usuário testa a funcionalidade através da interface.

### Correção

O usuário fornece o erro real encontrado.

### Auditoria final

Depois que as funcionalidades estiverem implementadas, executar todos os testes e validações de uma vez.

---

# 17. CRITÉRIO DE CONCLUSÃO

O prompt está concluído quando:

* a implementação solicitada estiver realizada;
* a integração com o sistema existente estiver feita;
* verificações rápidas forem concluídas;
* não houver erro evidente diretamente relacionado à alteração;
* nenhum teste longo tiver sido executado desnecessariamente;
* houver uma lista objetiva de testes manuais;
* o agente informar claramente o que foi e não foi validado.

**Não executar a suíte completa de testes automaticamente.**

**Não executar E2E completo automaticamente.**

**Não executar integrações externas reais automaticamente.**

A validação completa será realizada posteriormente, em uma etapa final dedicada.
