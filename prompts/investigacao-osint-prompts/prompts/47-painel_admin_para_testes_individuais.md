# Prompt 47 — Painel Admin para Teste Individual dos Módulos

## CONTEXTO

O OLHO possui módulos OSINT integrados, mas uma investigação completa pode demorar muito e dificultar a identificação de qual módulo está causando lentidão, erro ou consumo incorreto de créditos.

Precisamos criar no **painel administrativo** uma área exclusiva para testar cada módulo individualmente.

Módulos atuais:

* Maigret
* Sherlock
* WhatsMyName
* Holehe

O objetivo é permitir testes controlados, acompanhar a execução e verificar **tempo, status, resultados, erros e consumo de créditos**.

---

## OBJETIVO

Adicionar ao `/admin` um novo menu:

**Testes**

Dentro dele, criar uma interface para executar cada módulo separadamente.

O teste deve utilizar a **mesma implementação real existente** no sistema, incluindo adapter, service, worker, execução externa, normalização e regras de crédito quando aplicável.

Não criar uma segunda implementação dos módulos.

---

## 1. AUDITAR PRIMEIRO

Antes de alterar o código:

* localizar os adapters dos 4 módulos;
* localizar a execução via queue/worker;
* localizar `ModuleRun`;
* localizar regras de crédito;
* localizar logs/evidências;
* localizar status existentes;
* identificar como uma investigação normal executa os módulos.

Reutilizar a arquitetura existente.

Não modificar o fluxo normal das investigações sem necessidade.

---

## 2. MENU ADMIN

Adicionar:

```text
Admin
 └── Testes
      └── Testar módulos
```

Usar o padrão visual e de autorização já existente no painel.

Somente usuários administrativos podem acessar.

---

## 3. TELA DE TESTE

Criar uma tela simples contendo:

### Seleção do módulo

```text
[ Maigret       ▼ ]
```

Opções:

* Maigret
* Sherlock
* WhatsMyName
* Holehe

### Entrada

Permitir informar o alvo necessário para o módulo.

Exemplos:

```text
Username: exemplo
```

ou

```text
Email: exemplo@email.com
```

Adaptar o campo conforme o módulo, utilizando a lógica já existente.

### Botão

```text
EXECUTAR TESTE
```

---

## 4. EXECUÇÃO

Ao executar:

* criar uma execução identificável;
* utilizar o adapter real;
* utilizar o mecanismo real de execução;
* utilizar queue/worker existente quando necessário;
* respeitar timeout;
* capturar stdout/stderr;
* registrar exit code;
* registrar duração;
* registrar status;
* registrar resultados;
* registrar erros.

Não executar o módulo diretamente pelo controller se a arquitetura atual utiliza jobs/workers.

---

## 5. STATUS

Exibir pelo menos:

```text
PENDING
RUNNING
SUCCESS
PARTIAL
FAILED
TIMEOUT
SKIPPED
```

Usar os status existentes sempre que possível.

---

## 6. LOG DA EXECUÇÃO

Criar uma página ou painel de detalhes do teste.

Exibir:

* ID da execução;
* módulo;
* alvo;
* data/hora;
* início;
* término;
* duração;
* status;
* quantidade de resultados;
* quantidade de evidências;
* exit code;
* erro;
* stdout resumido;
* stderr resumido;
* consumo de créditos;
* saldo antes;
* saldo depois.

Não exibir credenciais ou secrets.

Evitar armazenar logs ilimitados.

---

## 7. CRÉDITOS

Este ponto é obrigatório.

O teste deve permitir verificar claramente a regra atual de consumo de créditos.

Registrar:

```text
Crédito antes: X
Custo aplicado: Y
Crédito depois: Z
```

O cálculo deve utilizar a implementação existente de créditos.

Não duplicar a lógica.

Não criar uma regra paralela somente para os testes.

Respeitar:

* SUCCESS → consumo conforme regra existente;
* FAILED → sem consumo;
* TIMEOUT → sem consumo;
* SKIPPED → sem consumo;
* retry/idempotência conforme implementação existente.

Se o sistema atual possuir reserva/ledger, utilizar o mesmo mecanismo.

---

## 8. HISTÓRICO

Criar uma listagem dos testes realizados.

Colunas:

```text
ID | Módulo | Alvo | Status | Duração | Resultados | Créditos | Data | Ações
```

Ações:

```text
Ver detalhes
Executar novamente
```

Não permitir alteração manual dos resultados.

---

## 9. TESTE ISOLADO

Cada execução deve testar **somente um módulo**.

Não executar automaticamente:

* outros módulos;
* correlação;
* investigação completa;
* relatório completo;
* outros adapters.

O objetivo é descobrir exatamente qual módulo está funcionando ou causando lentidão.

---

## 10. CONTROLE DE TEMPO

Como existe um problema de investigações demorando horas, o painel deve tornar isso visível.

Mostrar:

```text
Início: 13:20:10
Tempo decorrido: 00:02:35
```

Quando finalizar:

```text
Duração: 00:02:41
```

Respeitar o timeout configurado pelo sistema.

Não aumentar timeout automaticamente.

---

## 11. EXECUÇÃO ASSÍNCRONA

Se o módulo normalmente roda através de queue:

* disparar o job;
* retornar imediatamente para a interface;
* atualizar o status;
* permitir acompanhar a execução.

Não deixar uma requisição HTTP aguardando indefinidamente.

Se já existir polling, websocket ou mecanismo semelhante, reutilizá-lo.

Caso não exista, implementar apenas o mecanismo mínimo necessário para atualizar o status.

---

## 12. SEGURANÇA

A área deve:

* exigir autenticação;
* exigir autorização administrativa;
* validar entrada;
* aplicar rate limit quando apropriado;
* impedir execução arbitrária de comandos;
* nunca concatenar entrada do usuário em shell;
* utilizar argumentos separados conforme o adapter existente;
* não expor secrets;
* proteger contra acesso aos testes de outro usuário não autorizado.

---

## 13. PERFORMANCE

O objetivo desta funcionalidade é justamente identificar gargalos.

Registrar o tempo de:

```text
Queue
→ Job
→ Adapter
→ Processo externo
→ Normalização
→ Persistência
→ Finalização
```

Se a arquitetura existente permitir, registrar esses tempos sem refatoração ampla.

Caso não seja possível medir uma etapa sem grande alteração, registrar apenas o tempo total.

---

## 14. NÃO ALTERAR O FUNCIONAMENTO NORMAL

Não alterar desnecessariamente:

* adapters;
* regras de crédito;
* investigação normal;
* correlação;
* relatórios;
* checkout;
* fluxo de usuário;
* integrações existentes.

Reutilizar o máximo possível.

---

## 15. TESTES DURANTE ESTE PROMPT

**Não executar a suíte completa.**

Não executar E2E completo.

Não executar investigações reais completas.

Fazer somente verificações rápidas:

* sintaxe;
* rotas;
* migrations;
* imports;
* permissões;
* estrutura;
* erros evidentes relacionados à implementação.

Depois finalizar para validação manual.

---

## 16. TESTE MANUAL OBRIGATÓRIO

Após implementar, informar ao usuário para testar:

1. acessar `/admin`;
2. abrir **Testes**;
3. selecionar Maigret;
4. executar um username;
5. acompanhar status;
6. verificar duração;
7. verificar resultados;
8. verificar créditos;
9. verificar logs;
10. repetir para Sherlock;
11. repetir para WhatsMyName;
12. repetir para Holehe;
13. executar novamente um módulo para verificar idempotência/reexecução;
14. verificar execução com erro/timeout quando possível.

O objetivo é descobrir exatamente:

* qual módulo demora;
* quanto demora;
* se termina;
* quantos resultados retorna;
* quanto consome;
* onde ocorre eventual erro.

---

## 17. CRITÉRIO DE CONCLUSÃO

Considerar o prompt concluído quando:

* menu **Testes** existir no admin;
* cada módulo puder ser executado isoladamente;
* execução utilizar o código real existente;
* status puder ser acompanhado;
* duração puder ser identificada;
* resultados puderem ser visualizados;
* erros puderem ser visualizados;
* consumo de créditos puder ser conferido;
* histórico puder ser consultado;
* somente administradores tiverem acesso;
* investigação normal continuar funcionando sem alterações desnecessárias.

---

## 18. RELATÓRIO FINAL

Responder somente com:

```text
IMPLEMENTAÇÃO CONCLUÍDA

Arquivos alterados:
- ...

Implementado:
- Painel de testes
- Execução individual dos módulos
- Logs
- Tempo de execução
- Resultados
- Consumo de créditos
- Histórico

Verificações rápidas:
✓ ...

Testes completos:
NÃO EXECUTADOS.

TESTAR MANUALMENTE:
1. Maigret
2. Sherlock
3. WhatsMyName
4. Holehe
5. Créditos
6. Logs
7. Tempo de execução
8. Reexecução
```

Não executar testes longos automaticamente.
Não realizar refatorações fora do objetivo deste prompt.
