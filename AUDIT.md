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

Situação após a etapa 40 (auditoria E2E real):

1. **RF-17 Reexecução** — **concluído** na etapa 39: `InvestigationService::rerun()` + policy `rerun` + rota
   `POST /investigations/{id}/runs` + botão na UI (docs/34).
2. **Retenção/purga automática** — `config/privacy.php` define os TTLs, mas não existe job/schedule de purga.
   **Aberto** (fase 2): exige decisão de produto sobre o que apagar e o que preservar (ledger e auditoria têm
   retenção maior e são imutáveis).
3. **Landing page** — `/` ainda é a view genérica do Laravel; a jornada avulsa já tem entrada própria em
   `/investigacao`. **Aberto**.
4. **Uploads de imagem** (RF-07) — **fora do escopo do MVP** (exigiria validação de MIME/tamanho/extensão/nome aleatório).
5. **`entities`/`entity_identifiers`** — **fora do escopo** (opcionais para correlação futura).
6. **Snapshot HTML do relatório** — o manifesto já é congelado (`report-manifest-v1` + `content_hash`); persistir o
   HTML em si tem baixo valor. **Aberto (baixa prioridade)**.
7. **Base legal/textos finais** de privacidade/termos e a decisão sobre redistribuir imagem com o binário GPL-3.0 do
   Holehe (docs/30): **depende de revisão jurídica** (terceiros).
8. **Livewire** — não instalado (decisão mantida; o polling da avulsa usa JS nativo).

## Próximos passos técnicos

- Job/schedule de purga por retenção (`RetentionPurgeJob`), com regra explícita para preservar ledger e auditoria.
- Landing page do produto (a jornada avulsa já tem entrada em `/investigacao`).
- Revisão jurídica dos textos de privacidade/termos e da distribuição da imagem.
- (Fase 2) gateway PIX real: QR Code gráfico e webhook assinado pelo provedor.


## Atualização — etapa 41 (investigação avulsa sem cadastro)

- **Testes**: 301 passando + 5 ignorados (4 integrações reais + auditoria gated) · 1310 assertions
- **Pint**: PASS (256 arquivos)
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

## Atualização — etapa 48 (PagBank pronto para produção)

- **Confirmação sob demanda**: botão **"Já paguei — verificar agora"** na página
  do pedido (conta) e na página pública da avulsa. Cada clique consulta o PagBank
  (`PaymentService::reconcile()`) e só libera créditos/investigação quando o
  provedor confirma; rotas `POST` com CSRF e rate limit por usuário/pedido e por
  IP/token. Cobre o webhook que não chega e evita espera no polling.
- **Custo**: nenhuma varredura agendada; o polling da página do pedido reconsulta
  o provedor no máximo uma vez a cada 20s por pedido
  (`PAYMENT_RECONCILE_COOLDOWN_SECONDS`). O status da avulsa não consulta a API.
- **Produção**: três pendências operacionais (não de código) documentadas em
  `docs/42-pagbank-producao.md` — homologação da conta (Pipefy), `APP_URL` HTTPS
  público (com proxy confiável) e chave pública do webhook de produção. Inclui
  roteiro de validação com PIX de R$ 1, conferência de `payment_events`,
  `paid_at`, ledger e liberação da avulsa, além de troubleshooting.
- **Testes**: +8 em `tests/Feature/PaymentVerifyTest.php`; 96 testes da área de
  pagamentos/avulsa verdes após a mudança.
- **Pendências mantidas**: persistir `expired`/`cancelled` de todos os eventos,
  alerta automático de pedido parado e cache da chave pública do webhook.


