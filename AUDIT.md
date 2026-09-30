# Auditoria final do MVP

Data: 2026-09-29 · Laravel 13.34 · PHP 8.5 (Docker)

## Resumo

MVP funcional de ponta a ponta: cadastro/login, créditos (ledger imutável), PIX fake, investigações por username/email, pipeline assíncrono (7 jobs), adapters mock (Maigret/Sherlock/WhatsMyName/Holehe), normalização, deduplicação, correlação determinística, relatório HTML, dashboard do cliente, painel admin, privacidade, segurança e Docker.

- **Testes**: 165 passed (487 assertions)
- **Pint**: PASS (183 arquivos)
- **Rotas**: 56
- **Migrations**: todas aplicadas

## Classificação por requisito

| # | Área | Estado |
|---|---|---|
| 1 | Requisitos funcionais (RF-01…RF-20) | implementado (exceto RF-17) |
| 2 | Arquitetura (camadas + adapters + jobs) | implementado |
| 3 | Banco (14 tabelas + metadata) | implementado |
| 4 | Créditos (ledger/lock/idempotência) | implementado |
| 5 | Pagamento PIX fake | implementado |
| 6 | Autenticação (Breeze + admin) | implementado |
| 7 | Fila/workers (Redis) | implementado |
| 8 | Adapters mock | implementado |
| 9 | Normalização | implementado |
| 10 | Deduplicação | implementado |
| 11 | Correlação | implementado |
| 12 | Relatório HTML | implementado |
| 13 | Dashboard cliente | implementado |
| 14 | Admin | implementado |
| 15 | Privacidade/LGPD | parcial (retenção configurada; sem purga automática) |
| 16 | Segurança | parcial (uploads não implementados — fora do MVP) |
| 17 | Docker | implementado |
| 18 | Testes | implementado |

## Pendências (próxima fase)

1. **RF-17 Reexecução**: criar novo `run` mantendo o histórico (método `reexecute` no `InvestigationService`).
2. **Retenção/purga automática**: `config/privacy.php` existe, mas falta job/schedule que apague dados por TTL.
3. **Landing page**: `resources/views/welcome.blade.php` ainda é a página genérica do Laravel.
4. **Uploads de imagem** (RF-07 menciona "arquivo de imagem conforme módulos"): fora do MVP; requer validação MIME/tamanho/extensão/nome aleatório.
5. **`entities`/`entity_identifiers`**: opcionais para correlação futura (não criadas).
6. **Snapshot HTML do relatório**: o HTML é renderizado sob demanda (view); persistir conteúdo estático fica para depois.
7. **Base legal/textos finais** de privacidade/termos: validar com assessoria jurídica antes de produção.
8. **Livewire**: não instalado (decisão: só se houver benefício real em dashboards/tabelas).

## Próximos passos técnicos

- Implementar reexecução de investigação (RF-17).
- Adicionar job de purga por retenção (`RetentionPurgeJob` + schedule).
- Personalizar landing page.
- (Fase 2) integrar fontes reais seguindo licença/termos (Maigret, Sherlock, WhatsMyName, Holehe).


## Atualização — etapa 41 (investigação avulsa sem cadastro)

- **Testes**: 301 passando + 4 ignorados (integrações reais gated) · 1310 assertions
- **Pint**: PASS (255 arquivos)
- **Rotas**: 56 (6 novas: jornada avulsa, token, status, relatório, confirmação PIX)
- **Migrations**: +4 (`credit_packages.kind`, acesso avulso em `investigations`,
  `payments.guest_investigation_id`, `reports.user_id` nullable)
- **Escopo entregue**: consulta avulsa com preço fixo configurável pelo admin,
  pagamento PIX reusando a abstração existente, execução apenas após pagamento,
  acompanhamento e relatório por token de alta entropia, expiração de rascunho não
  pago, painel administrativo com origem/preço/pagamento e isolamento total do
  histórico de contas. Créditos, ledger e a regra de cobrança das contas não foram
  alterados (a avulsa não consome créditos).
- **Pendente**: etapa 40 (auditoria E2E com Maigret/Sherlock/WhatsMyName/Holehe
  reais, doc de integração e tag `etapa-40-e2e-real`).
