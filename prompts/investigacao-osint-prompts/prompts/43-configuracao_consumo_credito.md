# Prompt 43 — Auditoria e configuração do consumo de créditos por requisição

## CONTEXTO

Os Prompts 1–42 já foram concluídos.

A plataforma possui atualmente:

* usuários autenticados;
* investigação sem cadastro;
* investigação avulsa;
* pagamento PIX;
* créditos;
* ledger;
* investigações;
* módulos/fontes;
* adapters reais;
* queue/workers;
* normalização;
* deduplicação;
* correlação;
* evidências;
* relatórios;
* histórico;
* painel administrativo.

O consumo de créditos foi implementado nas etapas anteriores do projeto.

Existe agora uma necessidade de negócio:

> **A quantidade de créditos consumida por cada requisição/módulo deve ser configurável pelo administrador, sem necessidade de alterar código.**

Não assumir que o valor está fixo.

Primeiro auditar exatamente como o sistema atual calcula, reserva, consome, estorna e registra créditos.

Somente depois adaptar o projeto caso seja necessário.

---

# OBJETIVO

Determinar como funciona atualmente a cobrança de créditos e verificar se a quantidade consumida por requisição/módulo é:

* fixa no código;
* definida em configuração;
* armazenada no banco;
* definida por source;
* definida por módulo;
* definida por investigação;
* calculada dinamicamente;
* ou uma combinação dessas regras.

Depois da auditoria:

### Se já for configurável

Não reimplementar.

Apenas documentar:

* onde é configurado;
* quem pode alterar;
* como o valor é aplicado;
* como a cobrança é registrada;
* quais regras existem;
* quais testes comprovam o comportamento.

### Se NÃO for configurável

Adaptar o sistema para que o administrador possa definir a quantidade de créditos consumida por requisição/módulo.

---

# REGRA DE NEGÓCIO DESEJADA

O administrador deve conseguir definir algo equivalente a:

```text
Fonte/Módulo       Créditos por requisição

Maigret             10
Sherlock            15
WhatsMyName         20
Holehe               5
```

Os valores acima são apenas exemplos.

**Não utilizar esses números automaticamente.**

Os valores atuais devem ser preservados durante a migração.

O administrador deverá poder alterá-los posteriormente.

---

# IMPORTANTE — NÃO CONFUNDIR PREÇO COM CRÉDITO

Existem dois conceitos diferentes:

## Preço da investigação avulsa

É o valor financeiro pago pelo visitante.

Exemplo:

```text
R$ 29,90
```

## Consumo de créditos

É a quantidade de unidades internas consumidas pela execução de cada módulo/requisição.

Exemplo:

```text
Maigret → 10 créditos
Sherlock → 15 créditos
```

Não misturar esses dois conceitos.

Uma alteração na quantidade de créditos de um módulo não deve alterar automaticamente o preço financeiro da investigação avulsa.

---

# ETAPA 1 — AUDITORIA ANTES DE ALTERAR

Primeiro localizar e analisar:

* models relacionados a créditos;
* credit ledger;
* credit transactions;
* serviços de crédito;
* reservas;
* consumo;
* estornos;
* investigação;
* investigation modules;
* module runs;
* source definitions;
* adapters;
* jobs;
* workers;
* controllers;
* configurações;
* migrations;
* seeders;
* factories;
* testes;
* painel administrativo.

Pesquisar especialmente por:

```text
credit
credits
consume
consumption
reserve
reserved
ledger
cost
price
amount
module
source
investigation
```

Também verificar:

```text
config/
database/
app/Models/
app/Services/
app/Actions/
app/Jobs/
app/Http/
resources/views/
tests/
```

Não alterar nada nesta etapa.

---

# ETAPA 2 — MAPEAR A COBRANÇA ATUAL

Documentar o fluxo real.

Responder objetivamente:

### 1. Onde o custo é definido?

Exemplos:

```text
config/credits.php
source_definitions
database
enum
Service
Job
hardcoded
```

### 2. Onde o crédito é reservado?

Identificar:

* método;
* classe;
* tabela;
* transação;
* momento da reserva.

### 3. Onde o crédito é efetivamente consumido?

Identificar:

* método;
* classe;
* Job;
* evento;
* serviço.

### 4. Quando o crédito é estornado?

Verificar:

* FAILED;
* TIMEOUT;
* SKIPPED;
* cancelamento;
* retry;
* exception;
* investigação parcial.

### 5. Como retry funciona?

Garantir que uma mesma execução não gere cobrança duplicada.

### 6. Como o ledger registra?

Identificar:

* tipo de transação;
* quantidade;
* investigação;
* módulo;
* usuário;
* referência/idempotency key;
* timestamp.

### 7. Como investigação guest funciona?

Verificar como o consumo é associado à investigação sem usuário.

### 8. Como investigação autenticada funciona?

Verificar se continua compatível.

---

# ETAPA 3 — IDENTIFICAR VALOR ATUAL

Descobrir exatamente qual valor está sendo cobrado atualmente por cada módulo.

Não alterar os valores.

Gerar uma tabela:

| Fonte       | Valor atual | Onde definido  | Configurável? |
| ----------- | ----------: | -------------- | ------------- |
| Maigret     |           X | arquivo/tabela | SIM/NÃO       |
| Sherlock    |           X | arquivo/tabela | SIM/NÃO       |
| WhatsMyName |           X | arquivo/tabela | SIM/NÃO       |
| Holehe      |           X | arquivo/tabela | SIM/NÃO       |

Adicionar outras fontes existentes no projeto.

Se houver uma regra global:

| Regra            | Valor |
| ---------------- | ----: |
| Custo por módulo |     X |
| Reserva          |     X |
| Consumo          |     X |

---

# ETAPA 4 — DEFINIR A CONFIGURAÇÃO

Se o sistema ainda utilizar valores fixos, criar uma configuração persistente no banco.

Preferir uma estrutura já existente de configurações administrativas.

Não criar um mecanismo paralelo se o projeto já possuir:

```text
settings
system_settings
admin_settings
configurations
source_definitions
```

A configuração deve permitir:

```text
Fonte/Módulo
    ↓
credit_cost
```

Exemplo conceitual:

```text
source_definitions

id
name
slug
driver
enabled
credit_cost
config
...
```

**Somente adicionar uma coluna/tabela nova se a estrutura existente não for adequada.**

---

# ETAPA 5 — PAINEL ADMINISTRATIVO

Adicionar no painel administrativo uma área para configurar:

```text
Custo em créditos por requisição
```

Exemplo visual:

```text
Maigret
Créditos por requisição: [ 10 ]

Sherlock
Créditos por requisição: [ 15 ]

WhatsMyName
Créditos por requisição: [ 20 ]

Holehe
Créditos por requisição: [ 5 ]
```

Os nomes e valores devem vir do banco.

Permitir alteração pelo administrador.

Validar:

* inteiro;
* maior ou igual a zero;
* limite máximo razoável;
* autorização administrativa.

Não permitir valores inválidos.

---

# ZERO CRÉDITOS

Definir explicitamente o comportamento caso o administrador configure:

```text
credit_cost = 0
```

O módulo deve poder executar sem consumir créditos.

Não implementar comportamento inesperado.

Documentar essa possibilidade.

---

# HISTÓRICO DE ALTERAÇÃO

Sempre que possível, utilizar o mecanismo de auditoria já existente.

Registrar:

```text
quem alterou
qual módulo
valor anterior
novo valor
data/hora
```

Não criar outro sistema de auditoria caso já exista um adequado.

---

# ETAPA 6 — INTEGRAÇÃO COM O CONSUMO

O cálculo do crédito deve passar a utilizar a configuração persistida.

Conceitualmente:

```text
Investigation
      ↓
ModuleRun
      ↓
SourceDefinition
      ↓
credit_cost
      ↓
CreditService
      ↓
Ledger
```

Não colocar:

```php
$credits = 10;
```

ou equivalente dentro de:

* Job;
* Controller;
* Adapter;
* Service específico;
* frontend.

O valor deve ser obtido da configuração central.

---

# REGRA PARA INVESTIGAÇÃO COMPLETA

Se uma investigação utilizar:

```text
Maigret      → 10
Sherlock     → 15
Holehe       → 5
```

o consumo máximo previsto será:

```text
30 créditos
```

Mas o consumo efetivo deve continuar obedecendo à regra estabelecida anteriormente:

* módulo concluído com sucesso → consome;
* módulo FAILED → não consome;
* módulo TIMEOUT → não consome;
* módulo SKIPPED → não consome;
* retry não gera cobrança duplicada;
* reexecução deve respeitar idempotência.

Não alterar essa regra neste prompt.

---

# RESERVA

Auditar também a reserva.

Se o sistema atualmente reserva o total antes da execução:

```text
10 + 15 + 5 = 30
```

verificar se o valor reservado é calculado dinamicamente a partir das configurações.

Se a regra atual já for adequada, preservar.

Se houver necessidade de alteração para suportar custos configuráveis, fazer somente a alteração mínima.

Garantir:

```text
saldo
≥
reserva necessária
```

quando a política atual exigir reserva antecipada.

---

# ALTERAÇÃO DO PREÇO NÃO DEVE ALTERAR EXECUÇÕES ANTIGAS

Uma investigação já criada deve manter o custo que foi efetivamente utilizado.

Não recalcular historicamente investigações antigas quando o administrador alterar:

```text
Maigret: 10 → 20
```

Investigações futuras utilizarão 20.

Investigações já processadas permanecem com seus registros originais.

Para isso, se necessário, persistir no `ModuleRun` o:

```text
credit_cost
```

aplicado naquele momento.

Não depender apenas do valor atual da configuração para reconstruir o histórico.

---

# GUEST / INVESTIGAÇÃO AVULSA

Garantir compatibilidade com a funcionalidade implementada no Prompt 41.

A investigação guest deve utilizar exatamente a mesma regra de custo por módulo.

Não criar uma segunda tabela de preços de créditos exclusivamente para guest.

O visitante não possui saldo de créditos.

Portanto, o custo deve ser utilizado internamente para:

* controle;
* ledger;
* auditoria;
* custo operacional;
* consumo da investigação;

sem exigir que o visitante possua uma conta de créditos.

Não alterar o preço financeiro da investigação avulsa neste prompt.

---

# ADMINISTRADOR

O administrador deve poder:

* visualizar custo atual;
* alterar custo;
* salvar;
* visualizar valor anterior;
* visualizar data da alteração;
* visualizar quem alterou.

Se já existir histórico administrativo, reutilizar.

---

# TESTES OBRIGATÓRIOS

Criar ou adaptar testes para comprovar:

## Configuração

* custo padrão existente é preservado;
* administrador altera custo;
* novo valor é persistido;
* valor inválido é rejeitado;
* usuário comum não consegue alterar;
* custo zero funciona, se permitido.

## Consumo

Exemplo:

```text
Maigret = 10
Sherlock = 20
```

Executar investigação e verificar:

```text
Maigret → 10
Sherlock → 20
```

Não utilizar valores hardcoded nos testes se a intenção é validar configuração.

## Alteração

Alterar:

```text
Maigret = 10
```

para:

```text
Maigret = 25
```

Executar nova investigação.

Confirmar:

```text
nova execução → 25
execução antiga → continua registrada como 10
```

## Falhas

Confirmar:

```text
SUCCESS → cobra
FAILED → não cobra
TIMEOUT → não cobra
SKIPPED → não cobra
```

## Retry

Confirmar:

```text
primeira execução → cobrança
retry → nenhuma cobrança duplicada
```

## Idempotência

Executar novamente o mesmo comando/job e verificar que não ocorre consumo duplicado.

## Guest

Confirmar que a investigação avulsa utiliza o custo configurado.

## Ledger

Confirmar que:

```text
saldo final
=
saldo inicial
-
consumo efetivo
```

e que o ledger contém os valores corretos.

---

# TESTE DE REGRESSÃO

Executar a suíte completa existente.

Não aceitar regressões em:

* investigação autenticada;
* investigação guest;
* PIX;
* créditos;
* ledger;
* queue;
* workers;
* adapters;
* relatório;
* histórico;
* administração.

Executar também:

```bash
php artisan test
```

e:

```bash
./vendor/bin/pint --test
```

Se o projeto possuir comandos específicos de E2E, executá-los conforme a documentação existente.

---

# SEGURANÇA

Verificar:

* somente administrador altera custos;
* validação server-side;
* CSRF;
* autorização;
* valores negativos;
* valores absurdamente altos;
* concorrência;
* alteração de custo durante investigação;
* consistência da transação;
* idempotência.

Uma alteração de configuração não pode permitir manipulação arbitrária do ledger.

---

# MIGRATION

Se for necessário criar coluna/tabela:

1. criar migration;
2. preservar valores atuais;
3. preencher os registros existentes com os valores atualmente utilizados;
4. garantir rollback seguro;
5. testar banco novo;
6. testar banco existente.

Não perder configurações atuais.

Não zerar custos existentes acidentalmente.

---

# DOCUMENTAÇÃO

Atualizar a documentação relevante para explicar:

* como o custo é determinado;
* onde é configurado;
* como administrador altera;
* como investigação histórica preserva o custo aplicado;
* como falhas são tratadas;
* como retries funcionam;
* como guest utiliza a configuração.

Registrar no changelog.

---

# CRITÉRIO DE CONCLUSÃO

O Prompt 43 estará concluído quando:

### Antes

```text
código
  ↓
valor fixo
  ↓
consumo
```

### Depois

```text
ADMIN
  ↓
configuração de custo
  ↓
SourceDefinition / configuração
  ↓
Investigation
  ↓
ModuleRun
  ↓
CreditService
  ↓
Ledger
```

O administrador deve conseguir alterar:

```text
Maigret: 10 → 25
```

sem alterar código.

Uma nova investigação deve utilizar 25.

Uma investigação antiga deve manter o custo histórico aplicado.

O comportamento de:

* FAILED;
* TIMEOUT;
* SKIPPED;
* retry;
* idempotência;

deve continuar funcionando conforme as regras já estabelecidas.

---

# REGRAS DE EXECUÇÃO

Antes de modificar:

1. analisar a implementação atual;
2. identificar exatamente onde o custo é definido;
3. identificar exatamente onde a cobrança acontece;
4. identificar reserva;
5. identificar estorno;
6. identificar ledger;
7. identificar investigação guest;
8. identificar painel administrativo;
9. identificar testes existentes.

Somente depois alterar.

Não reconstruir o sistema.

Não alterar adapters.

Não alterar o pipeline OSINT.

Não alterar o preço financeiro da investigação avulsa.

Não modificar regras de negócio não relacionadas ao consumo de créditos.

Fazer a menor alteração necessária.

Ao finalizar informar:

* diagnóstico da implementação anterior;
* onde o valor estava definido;
* valores atuais encontrados;
* arquivos alterados;
* migrations;
* configuração criada/reutilizada;
* telas administrativas;
* testes executados;
* resultado da suíte;
* Pint;
* eventuais pendências;
* commit realizado.
