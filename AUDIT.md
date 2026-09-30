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
- **Etapa 40 — concluída**: ver seção abaixo e `docs/36-e2e-real-auditoria.md`.

## Atualização — etapa 40 (E2E real e auditoria)

- **Jornada completa com as 4 integrações reais** (MySQL + Redis + worker Docker):
  investigação 26 / run 27 — maigret 18 resultados (10.572 ms, 0.6.6), sherlock 10
  (3.801 ms, 0.16.2), whatsmyname 13 (5.353 ms, dataset `062bcfe4…`), holehe 0
  (3.053 ms, 1.61); 0 falhas, 0 timeouts, 0 retries.
- **Pipeline**: 41 resultados → 28 descobertas (13 duplicatas em 9 grupos), 41
  evidências, correlação score 40 (`internal_correlation_indicator`,
  `identity_asserted = false`), relatório `report-manifest-v1` (`e9496ce1debb…`)
  com 8 limitações — incluindo os avisos reais do Maigret (`Bot protection` 10,53%,
  `Access denied` 5,26%, 6 sites não avaliados); nenhum bypass foi usado.
- **Consumo**: 8 reservados / 8 consumidos, carteira 50 → 42, soma do ledger 42
  (invariante), 0 reservas pendentes.
- **Auditoria automatizada**: `tests/Feature/RealPipelineAuditTest.php` (gated por
  `OSINT_E2E=1`) — ~99–100 assertions em 20–28 s, forçando os adapters reais via
  `SourceRegistry::register()` + `healthCheck()`, gravando
  `storage/app/osint-audit/e2e-real-*.json` (duração, resultados, falhas,
  tentativas, versões e consumo por módulo + resultado final).
- **Testes**: 301 passando + 5 ignorados; Pint PASS (256 arquivos).
- Relatório da etapa: `docs/36-e2e-real-auditoria.md`.

