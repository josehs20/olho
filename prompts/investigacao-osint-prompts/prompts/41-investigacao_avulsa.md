# Prompt 41 — Investigação avulsa sem cadastro + pagamento PIX

## CONTEXTO

Os Prompts 1–40 já foram concluídos.

O MVP está funcionando e o pipeline real já foi validado:

usuário → créditos → investigação → Redis/Queue → adapters → resultados → normalização → deduplicação → correlação → evidências → relatório.

Não reconstruir funcionalidades já existentes.

Não modificar desnecessariamente o pipeline de investigação, adapters, workers, créditos, ledger, correlação, relatórios ou testes existentes.

A nova etapa deve adicionar uma segunda jornada comercial:

### Jornada atual

```text
Usuário cadastrado
    ↓
Login/conta
    ↓
Compra/adquire créditos
    ↓
Cria investigação
    ↓
Investigação
    ↓
Histórico disponível na conta
```

### Nova jornada

```text
Visitante
    ↓
Escolhe investigação
    ↓
Preço fixo da investigação
    ↓
PIX
    ↓
Pagamento confirmado
    ↓
Investigação liberada
    ↓
Execução
    ↓
Resultado
    ↓
Link/token único para consulta posterior
```

O visitante NÃO deve ser obrigado a criar conta para realizar uma investigação avulsa.

Usuários cadastrados continuam utilizando normalmente a plataforma atual e devem manter histórico, investigações, créditos, dados e funcionalidades existentes.

---

# OBJETIVO

Implementar uma modalidade de **investigação avulsa sem cadastro**, com preço único configurável pelo administrador.

O pagamento deve ser associado diretamente à investigação avulsa.

Após a confirmação do pagamento, o visitante recebe acesso à investigação através de um **token/link único**, sem necessidade de criação de conta.

A solução deve aproveitar o máximo possível das estruturas existentes de investigação, créditos, pagamentos, ledger, jobs, workers, resultados e relatórios.

Não duplicar desnecessariamente a lógica já existente.

---

# REGRAS DE NEGÓCIO

## 1. Dois tipos de usuário

O sistema deve passar a suportar:

### Usuário autenticado

Continua usando a plataforma atual.

Possui:

* conta;
* login;
* histórico;
* investigações;
* créditos;
* dados persistidos;
* relatórios;
* gerenciamento normal da plataforma.

### Visitante

Pode:

* criar uma investigação avulsa;
* pagar por PIX;
* aguardar confirmação;
* executar uma investigação;
* acompanhar o processamento;
* visualizar o resultado;
* retornar posteriormente através de um link/token único.

Não pode acessar investigações de outros visitantes.

Não precisa criar usuário/password apenas para executar uma investigação avulsa.

---

# 2. PREÇO FIXO

Criar configuração administrativa para:

```text
Preço da investigação avulsa
```

Exemplo:

```text
R$ 29,90
```

O valor NÃO deve ficar hardcoded no frontend.

Deve ser armazenado/configurado através do mecanismo administrativo/config existente, reutilizando o padrão já utilizado pelo projeto sempre que possível.

O administrador deve conseguir alterar o preço sem alteração de código.

Registrar o valor aplicado no momento da criação da compra/investigação.

Uma investigação já criada deve manter seu preço original mesmo que o administrador altere o preço posteriormente.

---

# 3. CRIAÇÃO DA INVESTIGAÇÃO

Permitir iniciar uma investigação sem autenticação.

O visitante deve informar somente os dados necessários para a investigação.

Não exigir:

* cadastro;
* senha;
* login;
* criação de conta.

Antes do pagamento, criar a representação da investigação avulsa em estado apropriado, porém impedir a execução até a confirmação do pagamento.

Exemplo conceitual:

```text
GUEST_DRAFT
    ↓
PENDING_PAYMENT
    ↓
PAID
    ↓
QUEUED
    ↓
RUNNING
    ↓
COMPLETED / PARTIAL / FAILED
```

Adaptar aos status já existentes, evitando criar estados duplicados quando a estrutura atual puder representar corretamente o fluxo.

---

# 4. PAGAMENTO

Reutilizar a arquitetura de pagamento existente.

Não criar um segundo sistema de pagamentos.

Usar a abstração existente (`PaymentProviderInterface` ou equivalente), mantendo compatibilidade com o mock atual.

Para o MVP, o pagamento PIX pode continuar utilizando o mecanismo mock existente.

Estruturar a solução para que futuramente seja possível conectar um gateway PIX real sem modificar a investigação.

Fluxo esperado:

```text
Criar investigação
        ↓
Gerar pagamento PIX
        ↓
Exibir valor
        ↓
Exibir QR Code/código PIX quando disponível
        ↓
Aguardar confirmação
        ↓
Confirmar pagamento
        ↓
Liberar investigação
```

Nunca liberar a investigação somente porque o frontend recebeu uma resposta de sucesso.

A liberação deve depender do estado persistido do pagamento no backend.

---

# 5. TOKEN DE ACESSO

Após criar uma investigação avulsa, gerar um token criptograficamente seguro e impossível de adivinhar.

O token deve permitir acesso SOMENTE àquela investigação.

Exemplo conceitual:

```text
/investigacao/acesso/{token}
```

Não utilizar:

```text
/investigacao/123
```

como mecanismo de autorização do visitante.

O ID interno da investigação não deve ser suficiente para acessar o resultado.

O token deve:

* possuir entropia adequada;
* ser armazenado de forma segura;
* permitir expiração quando apropriado;
* não expor dados sensíveis;
* não permitir acesso a outras investigações;
* ser validado sempre no backend.

---

# 6. LINK APÓS O PAGAMENTO

Depois da confirmação do pagamento, exibir claramente:

```text
Sua investigação foi liberada.

Acompanhe o processamento através deste link:

[ABRIR INVESTIGAÇÃO]

Guarde este link.
Ele será necessário para consultar esta investigação posteriormente.
```

Permitir também copiar o link.

Não exigir cadastro depois do pagamento.

---

# 7. PÁGINA DE ACOMPANHAMENTO

Criar página específica para o visitante acompanhar:

```text
Pagamento confirmado
        ↓
Preparando investigação
        ↓
Consultando fontes
        ↓
Processando resultados
        ↓
Consolidando evidências
        ↓
Gerando relatório
```

Utilizar atualização dinâmica/polling ou mecanismo já existente no projeto.

Não recarregar manualmente a página sempre que possível.

Exibir:

* status;
* progresso;
* módulos processados;
* módulos concluídos;
* módulos em andamento;
* eventuais falhas;
* resultado disponível.

Não expor informações internas do sistema, stack traces, credenciais, caminhos de arquivos ou erros técnicos sensíveis.

---

# 8. RESULTADO

Quando concluído:

```text
INVESTIGAÇÃO CONCLUÍDA
```

e permitir acessar o relatório existente.

Não criar um segundo formato de relatório.

Utilizar o pipeline e relatório existentes.

O visitante deve receber exatamente o mesmo resultado funcional que um usuário autenticado teria para aquela investigação.

---

# 9. HISTÓRICO

Não alterar a experiência dos usuários autenticados.

Usuários registrados continuam tendo:

* histórico;
* investigações;
* relatórios;
* dados;
* créditos;
* ledger;
* informações da conta.

Investigações guest devem permanecer separadas de contas autenticadas, a menos que exista uma funcionalidade futura de vinculação.

Não criar automaticamente uma conta para o visitante.

---

# 10. SEGURANÇA

Auditar especialmente:

* autorização pelo token;
* brute force de token;
* rate limiting;
* enumeração de investigações;
* CSRF;
* XSS;
* manipulação de status de pagamento;
* confirmação falsa de pagamento;
* acesso direto por ID;
* replay de confirmação;
* duplicidade de pagamento;
* duplicidade de investigação;
* idempotência;
* concorrência;
* exposição de dados de outras investigações.

Nunca aceitar do frontend:

```text
paid=true
```

ou equivalente como prova de pagamento.

Toda confirmação deve ser validada pelo backend.

---

# 11. EXPIRAÇÃO

Reutilizar ou implementar mecanismo simples para expiração de investigações avulsas abandonadas.

Exemplo:

```text
criada → pagamento pendente → expirada
```

Não executar uma investigação que nunca foi paga.

Investigações pagas não devem expirar antes que o usuário consiga acessar o resultado, salvo regra comercial explicitamente documentada.

Não inventar política de retenção permanente.

Seguir as estruturas de retenção já existentes.

---

# 12. ADMIN

Adicionar informações para o administrador visualizar:

* investigações guest;
* pagamentos avulsos;
* valor cobrado;
* status do pagamento;
* status da investigação;
* token/link não precisa ser exposto integralmente no painel;
* criação;
* pagamento confirmado;
* execução;
* conclusão;
* falhas.

Adicionar configuração:

```text
Preço da investigação avulsa
```

Utilizar o padrão visual e arquitetural existente do painel administrativo.

---

# 13. CRÉDITOS

Não quebrar o mecanismo atual de créditos.

A investigação avulsa paga deve possuir uma regra explícita de financiamento da execução.

O valor pago pelo visitante não deve ser tratado automaticamente como "créditos da conta", pois o visitante não possui conta.

Criar uma camada de consumo/billing associada à investigação guest aproveitando o ledger existente sempre que possível.

A cobrança efetiva da investigação deve continuar respeitando a regra estabelecida no Prompt 39:

* sucesso → consumo correspondente;
* FAILED → não cobrar módulo;
* TIMEOUT → não cobrar módulo;
* SKIPPED → não cobrar módulo;
* retry → não gerar cobrança duplicada.

Não duplicar cobrança em caso de retry ou reexecução.

---

# 14. UX

A nova jornada deve reduzir ao mínimo a quantidade de etapas entre:

```text
QUERO INVESTIGAR
        ↓
PAGAR
        ↓
RECEBER RESULTADO
```

Não adicionar formulário de cadastro antes do pagamento.

O cadastro deve ser apresentado apenas como alternativa, quando fizer sentido:

```text
Já possui uma conta?
Entrar
```

e não como requisito.

---

# 15. TESTES

Criar testes para pelo menos:

### Guest

* visitante cria investigação;
* investigação fica bloqueada antes do pagamento;
* preço configurável é aplicado;
* pagamento confirmado libera execução;
* pagamento não confirmado não libera execução;
* token acessa somente investigação correta;
* token inválido é rejeitado;
* investigação pode ser acompanhada sem login;
* resultado pode ser acessado sem login;
* usuário autenticado continua funcionando;
* nenhuma investigação guest aparece no histórico de outro usuário;
* retry não duplica cobrança;
* confirmação duplicada de pagamento é idempotente.

### Segurança

* não acessar por ID sem token;
* tentativa de trocar token/ID;
* rate limiting;
* acesso cruzado entre investigações.

### Regressão

Executar a suíte atual e garantir que as funcionalidades autenticadas continuam funcionando.

---

# 16. REGRAS DE IMPLEMENTAÇÃO

Antes de alterar qualquer arquivo:

1. inspecionar as estruturas atuais de:

   * investigations;
   * users;
   * credits;
   * ledger;
   * payments;
   * payment providers;
   * jobs;
   * reports;
   * authorization;
   * routes;
   * middleware;
   * testes.

2. identificar o menor conjunto de mudanças necessário.

3. reutilizar componentes existentes.

4. não duplicar serviços já existentes.

5. não modificar adapters reais.

6. não alterar o pipeline real de OSINT sem necessidade.

7. não introduzir dependências externas sem necessidade.

8. manter compatibilidade com Docker.

9. manter compatibilidade com PHPUnit.

10. executar Pint.

---

# 17. CRITÉRIO DE CONCLUSÃO

Considerar o Prompt 41 concluído somente quando for possível demonstrar:

```text
visitante
   ↓
cria investigação
   ↓
recebe preço configurável
   ↓
gera PIX
   ↓
pagamento confirmado
   ↓
investigação liberada
   ↓
Redis/Queue
   ↓
workers
   ↓
investigação executada
   ↓
resultado persistido
   ↓
relatório
   ↓
visitante acessa por token
```

e também:

```text
usuário autenticado
   ↓
continua funcionando normalmente
   ↓
histórico preservado
```

Ao finalizar, informar:

* arquivos criados;
* arquivos alterados;
* migrations;
* rotas;
* middleware;
* serviços reutilizados;
* testes executados;
* resultado da suíte;
* Pint;
* eventuais pendências;
* commit.

Não executar alteração relacionada ao Prompt 42 neste prompt.
