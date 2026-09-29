# 01 — Requisitos funcionais e não funcionais

## 1. Requisitos funcionais

### RF-01 Cadastro

O sistema deve permitir cadastro com nome, e-mail, senha e confirmação de senha. Deve validar unicidade do e-mail e possuir confirmação de e-mail e recuperação de senha.

### RF-02 Login

Deve permitir login, logout, proteção de sessão, limitação de tentativas e encerramento de sessões quando apropriado.

### RF-03 Dashboard

Deve mostrar saldo, últimas investigações, status, consumo e atalho para nova investigação.

### RF-04 Carteira de créditos

Deve existir saldo atual e ledger imutável. Operações de crédito não podem ser feitas alterando apenas um número sem histórico.

### RF-05 Pacotes

Administrador pode criar/editar/desativar pacotes de créditos e seus preços.

### RF-06 Pedido PIX

Usuário seleciona pacote, cria pedido e recebe instruções/QR payload conforme adapter configurado. Créditos só são adicionados após confirmação válida.

### RF-07 Nova investigação

Usuário informa nome, username, e-mail, telefone, cidade/estado e/ou arquivo de imagem conforme módulos suportados. Campos não usados permanecem opcionais.

### RF-08 Seleção de módulos

O sistema deve permitir seleção explícita de módulos. Cada módulo declara seu custo em créditos, tipos de entrada suportados e se está habilitado.

### RF-09 Reserva de créditos

Ao iniciar uma investigação, o sistema deve reservar créditos de forma transacional para impedir saldo negativo e corrida de condições.

### RF-10 Execução assíncrona

Consultas potencialmente demoradas devem usar filas.

### RF-11 Resultado parcial

Uma fonte pode falhar sem invalidar as demais. O usuário deve visualizar o status de cada módulo.

### RF-12 Normalização

Resultados de diferentes fontes devem ser convertidos para o modelo interno padronizado.

### RF-13 Deduplicação

Resultados equivalentes devem ser agrupados com indicação das fontes que encontraram o mesmo item.

### RF-14 Correlação

O sistema deve calcular uma confiança explicável com regras determinísticas. Cada ponto deve poder ser rastreado a uma evidência.

### RF-15 Relatório HTML

Deve existir página de relatório contendo resumo, entradas, módulos executados, resultados, evidências, fontes, falhas e limitações.

### RF-16 Histórico

Usuário vê suas investigações, execução, custo, status e relatório.

### RF-17 Reexecução

Reexecução cria novo `run`, mantendo o histórico anterior.

### RF-18 Administração

Painel simples para usuários, pacotes, fontes, investigações, pagamentos, créditos e auditoria.

### RF-19 Logs

O sistema deve registrar eventos relevantes de aplicação, investigação e auditoria.

### RF-20 Configuração

Segredos e chaves de integração ficam em variáveis de ambiente/secret manager.

## 2. Requisitos não funcionais

### RNF-01 Leveza

Evitar SPA pesada. Priorizar Blade, Livewire apenas onde houver benefício e JavaScript mínimo.

### RNF-02 Segurança

Validar input, proteger sessões, CSRF, XSS, uploads, comandos externos e endpoints administrativos.

### RNF-03 Escalabilidade

Workers devem poder ser aumentados independentemente do web container.

### RNF-04 Observabilidade

Métricas operacionais mínimas: jobs processados, falhas, timeouts, duração, fontes saudáveis/não saudáveis.

### RNF-05 Testabilidade

Domain services, adapters, jobs e ações de créditos devem possuir testes unitários/integração adequados.

### RNF-06 Idempotência

Webhooks, confirmação de pagamento e débito/crédito de carteira devem ser idempotentes.

### RNF-07 Auditabilidade

Operações financeiras e administrativas devem possuir trilha de auditoria.

## 3. Critérios de aceite transversais

- nenhuma operação financeira duplica créditos;
- nenhuma consulta deixa saldo negativo;
- job reenviado não duplica resultados sem uma regra explícita;
- uma fonte indisponível gera status de falha/timeout, não erro global;
- resultados possuem fonte e timestamp;
- dados de usuários não aparecem entre contas diferentes;
- rotas administrativas exigem autorização;
- segredos não aparecem nos logs.
