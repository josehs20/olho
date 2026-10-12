# Prompt 45 — Configuração de pagamentos PagBank no painel administrativo

## CONTEXTO

O projeto é a plataforma **OLHO**, uma plataforma comercial de investigação de presença digital utilizando informações públicas e fontes autorizadas.

O projeto já possui:

- Laravel 13
- Docker
- MySQL
- Redis
- Laravel Queue
- Laravel Scheduler
- Laravel Breeze
- Blade
- Tailwind CSS v4
- Vite
- PHPUnit
- Laravel Pint
- Fluxo de investigação sem cadastro
- Fluxo de investigação autenticada
- Sistema de créditos
- Sistema de pagamento para investigação avulsa
- Preço da investigação sem cadastro configurável pelo administrador
- Fluxo de pagamento PIX previamente preparado/mockado
- Token/link único para acesso à investigação após pagamento
- Controle de status da investigação
- Webhooks/abstração de pagamento, caso já existentes
- Painel administrativo

A integração de pagamento será feita utilizando o **PagBank**.

### Métodos de pagamento permitidos

O OLHO utilizará exclusivamente:

1. **PIX**
2. **Cartão de débito**
3. **Cartão de crédito**

Não implementar, exibir ou disponibilizar boleto, carteira digital ou qualquer outro método.

Para cartão de crédito, deve existir configuração de quantidade máxima de parcelas.

Para cartão de débito, não existe parcelamento.

---

# OBJETIVO

Implementar uma integração real com o **PagBank**, permitindo que o administrador configure os pagamentos através do painel administrativo.

A configuração deve contemplar:

- PIX
- Cartão de débito
- Cartão de crédito
- Parcelamento do cartão de crédito
- Sandbox
- Produção
- Checkout/Link de pagamento
- Webhooks
- Teste de conexão
- Ativação/desativação da integração

A implementação deve aproveitar ao máximo a arquitetura de pagamento existente.

**NÃO reconstruir o sistema de pagamentos do zero.**

Primeiro analisar o código existente.

---

# REGRA PRINCIPAL

Antes de alterar qualquer arquivo:

1. Inspecione a implementação atual de pagamentos.
2. Identifique Models, migrations, Services, Interfaces, Controllers, Jobs, Events, Listeners, Webhooks, Admin, configuração de preço, fluxo guest, fluxo autenticado, ledger e créditos.
3. Identifique o que já existe para PIX.
4. Identifique se existe uma abstração de gateway/provider.
5. Identifique se existe mock de pagamento.
6. Identifique se existe configuração administrativa.
7. Identifique como o pagamento atual libera a investigação.
8. Identifique os estados atuais de pagamento.
9. Identifique se existe idempotência.
10. Identifique se já existe webhook.

Somente depois dessa auditoria implemente as alterações necessárias.

Não duplicar funcionalidades já existentes.

---

# 1. ARQUITETURA DO PAGAMENTO

Criar ou adaptar uma arquitetura que permita:

```text
PaymentProvider
       ↓
PagBankProvider
       ↓
PagBank Checkout
       ↓
PIX / Débito / Crédito
```

Se já existir uma interface equivalente, reutilizá-la.

Preferir:

```text
Controller
   ↓
PaymentService
   ↓
PaymentProviderInterface
   ↓
PagBankProvider
   ↓
PagBank API
```

O código da aplicação não deve ficar acoplado diretamente a chamadas HTTP espalhadas pelos Controllers.

---

# 2. CONFIGURAÇÃO ADMINISTRATIVA

Criar ou adaptar:

```text
Admin
 └── Configurações
      └── Pagamentos
```

A página deve permitir configurar o PagBank.

Seções:

- Geral
- PagBank
- PIX
- Cartão de débito
- Cartão de crédito
- Webhooks

## Geral

Campos:

- Provedor
- Ativo
- Ambiente

Ambientes:

```text
Sandbox
Produção
```

O sistema nunca deve misturar credenciais de Sandbox com Produção.

---

# 3. CREDENCIAIS PAGBANK

Criar configuração segura para:

- API Token
- demais credenciais obrigatórias conforme a API/Checkout utilizado
- ambiente

Nunca exibir o token completo após salvo.

Exemplo:

```text
API Token
••••••••••••••••••••••••
```

Permitir atualização sem expor o valor atual.

Nunca:

- gravar credencial no código
- gravar credencial no frontend
- retornar token em API pública
- mostrar token em logs
- mostrar token em exceptions
- colocar credenciais no Git

Se o projeto já possuir mecanismo seguro para secrets, reutilizá-lo.

---

# 4. AMBIENTE

O administrador deve poder selecionar:

```text
○ Sandbox
○ Produção
```

A aplicação deve utilizar automaticamente o endpoint correspondente ao ambiente.

Quando Produção estiver selecionada, apresentar confirmação visual:

```text
Ambiente: PRODUÇÃO

Os pagamentos realizados neste ambiente possuem valor financeiro real.
```

Não permitir que o usuário final escolha o ambiente.

---

# 5. FORMAS DE PAGAMENTO

Criar uma seção:

```text
Formas de pagamento
```

Com exatamente:

```text
[✓] PIX

[✓] Cartão de débito

[✓] Cartão de crédito
```

O administrador poderá ativar/desativar cada método.

Não adicionar outros métodos.

---

# 6. PIX

Criar seção:

```text
PIX
```

Configurações:

```text
[✓] Aceitar PIX

Validade da cobrança:
[ 30 ] minutos
```

A validade deve ser implementada somente se suportada pelo fluxo utilizado.

Quando o cliente escolher PIX:

```text
OLHO
   ↓
Cria Checkout/Pagamento PagBank
   ↓
PagBank
   ↓
Cliente paga PIX
   ↓
PagBank atualiza pagamento
   ↓
Webhook
   ↓
OLHO confirma pagamento
   ↓
Investigação liberada
```

Não liberar a investigação apenas porque o usuário retornou para o OLHO.

O retorno do navegador não é prova suficiente de pagamento.

A confirmação deve ocorrer através do status oficial do PagBank e/ou webhook validado.

---

# 7. CARTÃO DE DÉBITO

Criar seção:

```text
Cartão de débito
```

Configuração:

```text
[✓] Aceitar cartão de débito
```

Não oferecer parcelamento para débito.

Não armazenar dados de cartão no OLHO.

Utilizar o checkout do PagBank sempre que possível, evitando que os dados sensíveis do cartão trafeguem ou sejam armazenados pela aplicação.

---

# 8. CARTÃO DE CRÉDITO

Criar seção:

```text
Cartão de crédito
```

Configurações:

```text
[✓] Aceitar cartão de crédito

Máximo de parcelas:
[ 6 ]
```

Permitir configuração administrativa da quantidade máxima de parcelas.

Validar os limites efetivamente suportados pelo PagBank.

Não assumir que qualquer número de parcelas é válido.

Não armazenar número do cartão, CVV, senha ou dados sensíveis do cartão no banco do OLHO.

---

# 9. CHECKOUT PAGBANK

Preferir inicialmente o modelo de **Checkout/Link de Pagamento do PagBank**.

Fluxo:

```text
OLHO
  ↓
Cria Checkout
  ↓
PagBank retorna URL
  ↓
OLHO redireciona cliente
  ↓
Cliente escolhe:
    PIX
    Débito
    Crédito
  ↓
Pagamento
  ↓
PagBank
  ↓
Webhook
  ↓
OLHO
```

O cliente deve ser direcionado ao ambiente de pagamento do PagBank.

Não criar formulário próprio de cartão se o Checkout do PagBank atender ao fluxo.

---

# 10. PREÇO DA INVESTIGAÇÃO

O preço da investigação guest já possui configuração administrativa.

Preservar essa funcionalidade.

Quando uma investigação for criada, registrar o preço aplicado naquele momento.

Se o administrador alterar posteriormente o preço, investigações antigas não devem ser recalculadas.

---

# 11. PAYMENT

Se já existir entidade equivalente, reutilizar.

Caso seja necessário criar, adaptar uma estrutura semelhante a:

```text
payments
```

Campos conceituais:

```text
id
investigation_id
user_id nullable
provider
provider_payment_id nullable
provider_checkout_id nullable
reference_id
amount
currency
method nullable
status
environment
paid_at nullable
expires_at nullable
raw_response nullable
metadata nullable
created_at
updated_at
```

Não criar campos desnecessários.

---

# 12. MÉTODO DE PAGAMENTO

O pagamento deve registrar:

```text
PIX
DEBIT_CARD
CREDIT_CARD
```

Se a API do PagBank retornar nomenclatura diferente, criar uma camada de tradução para o padrão interno.

Não criar enumerações para métodos não utilizados pelo OLHO.

---

# 13. STATUS

Padronizar os estados internamente, aproveitando os existentes.

Exemplo:

```text
PENDING
PAID
FAILED
CANCELED
EXPIRED
REFUNDED
```

Criar tradução:

```text
PagBank status
        ↓
Internal Payment Status
```

---

# 14. WEBHOOK

Implementar webhook do PagBank.

Utilizar a estrutura existente caso já exista.

Exemplo:

```text
POST /webhooks/payments/pagbank
```

O webhook deve:

1. Receber a notificação.
2. Validar a requisição conforme mecanismo suportado pelo PagBank.
3. Identificar o pagamento.
4. Consultar o PagBank quando necessário.
5. Confirmar o estado real.
6. Atualizar o pagamento.
7. Atualizar a investigação.
8. Liberar a investigação somente quando o pagamento estiver confirmado.
9. Ser idempotente.

---

# 15. IDEMPOTÊNCIA

O webhook pode ser enviado mais de uma vez.

Portanto:

```text
Webhook 1 → processa
Webhook 2 → ignora duplicidade
Webhook 3 → ignora duplicidade
```

Nunca:

- duplicar investigação
- duplicar cobrança
- duplicar créditos
- gerar múltiplos tokens
- liberar duas vezes
- registrar múltiplos pagamentos para o mesmo evento

---

# 16. SEGURANÇA DO WEBHOOK

Não confiar cegamente no payload.

Quando necessário:

```text
Webhook recebido
       ↓
Validar
       ↓
Consultar PagBank
       ↓
Confirmar estado
       ↓
Atualizar sistema
```

Nunca permitir que alguém consiga liberar uma investigação simplesmente enviando:

```text
status=PAID
```

para o endpoint.

---

# 17. REDIRECIONAMENTO

Configurar as URLs necessárias para o Checkout, como:

```text
success_url
cancel_url
```

ou os campos equivalentes suportados pelo PagBank.

A página de sucesso deve apenas consultar e exibir o estado atual.

Ela não deve marcar o pagamento como pago.

---

# 18. TESTE DE CONEXÃO

Adicionar no painel:

```text
[ Testar conexão com PagBank ]
```

O teste deve:

1. Ler a configuração atual.
2. Verificar credenciais.
3. Verificar ambiente.
4. Fazer chamada segura à API.
5. Não gerar cobrança real.
6. Não criar investigação.
7. Não consumir créditos.

Sucesso:

```text
✓ Conexão realizada com sucesso
Ambiente: Sandbox
Status: Conectado
```

Erro:

```text
✗ Não foi possível conectar

Verifique as credenciais e a configuração do PagBank.
```

Nunca mostrar credenciais na mensagem.

---

# 19. STATUS DA INTEGRAÇÃO

No painel administrativo mostrar:

```text
PagBank

Status:
● Conectado

Ambiente:
Produção

PIX:
✓ Ativo

Débito:
✓ Ativo

Crédito:
✓ Ativo

Parcelamento:
Até 6x

Último teste:
01/10/2026 15:30
```

Os valores devem vir da configuração real.

---

# 20. HISTÓRICO DE PAGAMENTOS

Se já existir página administrativa de pagamentos, adaptar.

Caso não exista, criar:

```text
Admin
 └── Pagamentos
```

Mostrar:

- ID
- investigação
- usuário/guest
- valor
- método
- provider
- status
- data
- ID PagBank
- ambiente

Filtros:

```text
Status
Método
Data
Provider
```

Não mostrar dados sensíveis do cartão.

---

# 21. LOGS

Registrar eventos importantes:

```text
payment.checkout.created
payment.pending
payment.webhook.received
payment.confirmed
payment.failed
payment.expired
payment.canceled
```

Registrar identificadores úteis:

```text
payment_id
investigation_id
provider
provider_payment_id
status
```

Nunca registrar:

- API Token
- número completo de cartão
- CVV
- credenciais
- dados sensíveis

---

# 22. MOCK / SANDBOX

Preservar os mecanismos de mock utilizados pelos testes.

A arquitetura deve permitir:

```text
Testing
   ↓
FakePaymentProvider

Production
   ↓
PagBankProvider
```

Testes automatizados não devem depender da internet.

---

# 23. CONFIGURAÇÃO CENTRALIZADA

Centralizar:

```text
Payment
 ├── Provider
 │     └── PagBank
 │
 ├── Environment
 │     ├── Sandbox
 │     └── Production
 │
 ├── Methods
 │     ├── PIX
 │     ├── Debit Card
 │     └── Credit Card
 │
 └── Checkout
```

Evitar configurações espalhadas pelo código.

---

# 24. PAINEL ADMINISTRATIVO

A estrutura esperada é:

```text
Configurações
│
└── Pagamentos
     │
     ├── Geral
     ├── PagBank
     ├── PIX
     ├── Cartão de débito
     ├── Cartão de crédito
     └── Webhooks
```

Botões:

```text
Testar conexão
Salvar configurações
```

Seguir o padrão visual existente do painel.

---

# 25. PERMISSÕES

Somente administradores autorizados podem:

- visualizar configurações
- alterar credenciais
- alterar ambiente
- ativar/desativar PIX
- ativar/desativar débito
- ativar/desativar crédito
- alterar parcelamento
- testar integração

Usuário comum nunca deve acessar essas configurações.

---

# 26. FALLBACK

Não remover o mecanismo de pagamento existente enquanto a integração PagBank estiver sendo implementada.

Se existir:

```text
MockPaymentProvider
```

mantê-lo para testes.

Não utilizar mock como pagamento real.

---

# 27. FLUXO GUEST

Preservar o fluxo criado anteriormente:

```text
Visitante
   ↓
Escolhe investigação
   ↓
Preço configurado
   ↓
Checkout PagBank
   ↓
PIX / Débito / Crédito
   ↓
Pagamento confirmado
   ↓
Investigação
   ↓
Token único
   ↓
Link de resultado
```

O pagamento guest não exige cadastro.

Não transformar o pagamento guest em créditos de usuário.

---

# 28. FLUXO USUÁRIO AUTENTICADO

Não quebrar o fluxo existente.

Usuários autenticados continuam podendo:

- utilizar créditos
- consultar histórico
- acessar investigações
- visualizar resultados

A integração PagBank deve coexistir com o sistema de créditos.

---

# 29. CRÉDITOS

Não misturar:

```text
Pagamento financeiro
```

com:

```text
Crédito interno
```

O PagBank representa pagamento financeiro.

O sistema de créditos representa consumo interno.

Preservar as regras implementadas anteriormente:

- sucesso → cobra módulo
- failed → não cobra
- timeout → não cobra
- skipped → não cobra
- retry não duplica cobrança
- ledger consistente
- custo configurável pelo administrador

---

# 30. REEMBOLSO

Não implementar automaticamente fluxo de reembolso neste prompt se o sistema ainda não possuir esse recurso.

Entretanto, manter a estrutura preparada para:

```text
REFUNDED
```

---

# 31. DOCUMENTAÇÃO

Criar/atualizar:

```text
docs/pagamentos-pagbank.md
```

Documentar:

- arquitetura
- configuração
- Sandbox
- Produção
- credenciais
- PIX
- cartão de débito
- cartão de crédito
- parcelamento
- checkout
- webhook
- testes
- troubleshooting

Utilizar documentação oficial atual do PagBank como referência.

---

# 32. TESTES

Criar testes automatizados para:

## Configuração

- admin consegue salvar configuração
- usuário comum não consegue
- credencial não aparece em resposta
- Sandbox funciona
- Produção funciona
- configuração inválida é rejeitada

## PIX

- PIX habilitado
- PIX desabilitado
- checkout com PIX
- pagamento confirmado
- PIX expirado
- pagamento pendente

## Débito

- débito habilitado
- débito desabilitado
- checkout com débito
- pagamento confirmado
- pagamento recusado

## Crédito

- crédito habilitado
- crédito desabilitado
- checkout com crédito
- quantidade máxima de parcelas
- pagamento aprovado
- pagamento recusado

## PagBank

Mockar HTTP/API.

Testar:

```text
create checkout
success
error
timeout
invalid credentials
```

## Webhook

Testar:

```text
pending
paid
failed
expired
canceled
duplicate webhook
unknown payment
```

## Guest

Testar:

```text
guest
→ checkout
→ payment
→ webhook
→ paid
→ investigation
→ token
→ result
```

## Segurança

Testar:

- acesso não autorizado
- token não exposto
- credencial não exposta
- webhook inválido
- duplicidade
- manipulação de status
- acesso cruzado entre investigações

---

# 33. TESTE MANUAL

Depois dos testes automatizados, preparar checklist para Sandbox:

```text
[ ] Configurar PagBank Sandbox
[ ] Testar conexão
[ ] Ativar PIX
[ ] Ativar cartão de débito
[ ] Ativar cartão de crédito
[ ] Definir parcelas
[ ] Criar investigação guest
[ ] Gerar checkout
[ ] Abrir checkout PagBank
[ ] Testar PIX
[ ] Testar débito
[ ] Testar crédito
[ ] Receber webhook
[ ] Confirmar pagamento
[ ] Confirmar liberação da investigação
[ ] Confirmar geração do token
[ ] Acessar resultado
[ ] Confirmar que não houve duplicidade
```

Não realizar cobrança real em Produção durante os testes automatizados.

---

# 34. DOCUMENTAÇÃO OFICIAL

Utilizar como fonte principal a documentação oficial atual do PagBank:

- APIs PagBank:
  https://developer.pagbank.com.br/docs/apis-pagbank
- Checkout:
  https://developer.pagbank.com.br/docs/checkout
- Ambientes:
  https://developer.pagbank.com.br/docs/ambientes-disponiveis
- Pedidos e pagamentos:
  https://developer.pagbank.com.br/docs/pedidos-e-pagamentos-order
- PIX:
  utilizar a documentação atual correspondente à versão da API escolhida.

Antes de implementar, verificar na documentação atual do PagBank exatamente quais métodos são suportados pelo Checkout escolhido.

**Não inventar endpoints, parâmetros ou campos.**

---

# 35. RESTRIÇÕES IMPORTANTES

NÃO:

- implementar boleto
- implementar métodos de pagamento adicionais
- armazenar cartão
- armazenar CVV
- colocar token PagBank no frontend
- expor credenciais
- confiar apenas no redirect
- liberar investigação apenas pelo retorno do navegador
- duplicar pagamentos
- duplicar investigações
- duplicar créditos
- remover o sistema de mock
- quebrar o sistema de créditos
- alterar adapters OSINT sem necessidade
- alterar lógica das investigações sem necessidade
- criar outro painel administrativo
- criar outro sistema de autenticação
- reescrever o projeto inteiro

Sempre preferir alteração incremental.

---

# 36. COMPATIBILIDADE COM O PROJETO ATUAL

Antes de criar migrations, Models, Services ou Controllers:

verificar se já existe estrutura equivalente.

Se existir:

```text
REUTILIZAR
```

Se estiver parcialmente implementada:

```text
ADAPTAR
```

Somente criar algo novo quando realmente necessário.

---

# 37. CRITÉRIO DE CONCLUSÃO

A tarefa estará concluída somente quando:

- [ ] PagBank estiver integrado através de provider/service isolado
- [ ] Admin possuir página de configuração
- [ ] Sandbox/Produção configuráveis
- [ ] Credenciais protegidas
- [ ] PIX configurável
- [ ] Cartão de débito configurável
- [ ] Cartão de crédito configurável
- [ ] Parcelamento configurável
- [ ] Checkout PagBank funcionando
- [ ] Webhook implementado
- [ ] Webhook idempotente
- [ ] Pagamento confirmado pelo backend
- [ ] Guest funcionando
- [ ] Usuário autenticado preservado
- [ ] Sistema de créditos preservado
- [ ] Histórico de pagamentos funcionando
- [ ] Teste de conexão funcionando
- [ ] Logs sem informações sensíveis
- [ ] Testes automatizados passando
- [ ] Laravel Pint passando
- [ ] Nenhuma regressão nos testes existentes

---

# 38. AUDITORIA FINAL

Executar:

```bash
php artisan test
```

Depois:

```bash
vendor/bin/pint --test
```

Se o projeto possuir comandos específicos de build/frontend, executar também.

Verificar:

```bash
git status
```

Não deixar arquivos temporários ou credenciais no Git.

Procurar por referências como:

```text
PAGBANK_TOKEN
API_TOKEN
PASSWORD
SECRET
```

e garantir que nenhum segredo real tenha sido commitado.

---

# 39. RELATÓRIO FINAL

Ao finalizar, apresentar:

## Arquitetura encontrada

Explicar como o sistema de pagamento estava estruturado antes.

## Alterações realizadas

Listar:

- arquivos criados
- arquivos modificados
- migrations
- Models
- Services
- Controllers
- Jobs
- Webhooks
- telas administrativas
- testes

## PagBank

Informar:

```text
Provider:
PagBank

Ambientes:
Sandbox / Produção

PIX:
Configurável

Débito:
Configurável

Crédito:
Configurável

Parcelamento:
Configurável

Webhook:
Implementado
```

## Testes

Informar:

```text
PHPUnit:
X passed

Pint:
PASS/FAIL
```

## Configuração necessária

Listar exatamente o que o administrador precisa configurar no painel.

Não colocar tokens reais no relatório.

## Pendências

Se alguma funcionalidade depender de configuração externa ou recurso específico da conta PagBank, explicar exatamente qual é a dependência.

---

# RESULTADO ESPERADO

Ao final, o administrador deverá conseguir entrar no OLHO:

```text
Admin
  ↓
Configurações
  ↓
Pagamentos
  ↓
PagBank
```

e visualizar:

```text
PagBank
────────────────────────

Status:
[✓] Ativo

Ambiente:
[ Sandbox ▼ ]

API Token:
[ ••••••••••••••••• ]

────────────────────────

Formas de pagamento

[✓] PIX

[✓] Cartão de débito

[✓] Cartão de crédito

Máximo de parcelas:
[ 6 ]

────────────────────────

[ Testar conexão ]

[ Salvar configurações ]
```

Depois de configurado, o usuário final deverá ter uma experiência:

```text
INVESTIGAÇÃO

Valor: R$ XX,XX

[ INVESTIGAR AGORA ]

        ↓

PAGBANK

PIX
ou
CARTÃO DE DÉBITO
ou
CARTÃO DE CRÉDITO

        ↓

Pagamento confirmado

        ↓

O OLHO começou a investigar.
```

A prioridade é construir uma integração segura, configurável e desacoplada, aproveitando toda a infraestrutura existente e evitando qualquer reconstrução desnecessária do projeto.
