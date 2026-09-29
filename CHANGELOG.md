# Changelog

Registro de decisões por etapa da construção da plataforma **Investigação Pública**.

## Etapa 01 — Análise do projeto

- Diagnóstico do repositório: Laravel 13.34 existente (skeleton puro, sem auth, MySQL, Redis ou Docker).
- Constatado: host sem drivers PDO/redis e sem MySQL/Redis locais → execução via Docker.
- `laravel/breeze v2.4.2` é compatível com Laravel 13 (`illuminate/* ^13`), porém publica Tailwind v3 e sobrescreve `vite.config.js`.

## Etapa 02 — Estrutura base

- **Docker**: serviços `app` (php:8.5-fpm-alpine), `web` (nginx), `worker`, `scheduler`, `mysql:8.4`, `redis:7`. Extensões instaladas: `pdo_mysql`, `pdo_sqlite`, `bcmath`, `gd`, `intl`, `pcntl`, `zip`, `redis` (pecl). Usuário não-root.
- **Autenticação**: Breeze 2.4.2 stack Blade.
  - **Decisão**: preservar **Tailwind v4** (restaurar `@tailwindcss/vite`, remover `tailwind.config.js`/`postcss.config.js`, usar `@plugin "@tailwindcss/forms"`), em vez do Tailwind v3 que o Breeze publica por padrão.
- **Livewire**: não instalado (nenhum componente desta etapa o justifica).
- **Ambiente**: `.env`/`.env.example` com MySQL + Redis + placeholders PIX (`PAYMENT_PROVIDER=fake`, `PIX_KEY=CONFIGURE_ME`). Sessão em `database`; cache/fila em `redis`.
- **Decisão**: remover `env_file: .env` do serviço `app` para que o `APP_ENV=testing` do `phpunit.xml` não seja sobrescrito (o código lê o `.env` do bind mount; `env_file` exportava `APP_ENV=local` e quebrava o `runningUnitTests()`/CSRF nos testes).
- **Decisão**: healthcheck do `web` usa `http://127.0.0.1/up` (o `wget` do Alpine resolve `localhost` para IPv6 e o nginx escuta IPv4).
- Criadas regras `.cursor/rules/*.mdc` na raiz do projeto (resumo das regras do pacote `prompts/investigacao-osint-prompts/.cursor/rules/`) e `AGENTS.md`/`CLAUDE.md` com diretrizes do projeto.

## Etapa 03 — Autenticação

- **Confirmação de e-mail**: `User` agora implementa `MustVerifyEmail` (verificação obrigatória para acessar `/dashboard`).
- **Admin**: coluna `users.is_admin` (boolean, não mass-assignable) + middleware `admin` (`EnsureUserIsAdmin`) + Gate `access-admin` + comando `php artisan app:create-admin` (lê `--email/--name/--password` ou `ADMIN_*`; gera senha aleatória quando ausente).
- **Throttling**: login já limitado (5 tentativas via `RateLimiter`); adicionado `throttle` a `register` (10/min) e `forgot-password` (6/min).
- **Perfil**: Breeze já fornece `/profile` (nome/e-mail, senha, exclusão). Sem alteração.
- Rota placeholder `/admin` (painel real na etapa 20).
- **Decisão**: `is_admin` não entra em `$fillable` (impede escalonamento via formulário de registro); o comando e a factory usam `forceFill`/atribuição direta.
- Testes: verificação de e-mail (redirect de usuário não verificado), registro (validação de confirmação/e-mail duplicado), throttle de login e acesso admin (403/200/redirect guest).

## Etapa 04 — Banco de dados do domínio

- Criadas 14 tabelas: `credit_packages`, `wallets`, `credit_transactions`, `investigations`, `investigation_runs`, `credit_reservations`, `payments`, `payment_events`, `source_definitions`, `source_runs`, `source_results`, `evidence`, `reports`, `audit_logs` (+ models, relações e factories).
- `entities`/`entity_identifiers` ficam fora (opcionais, para correlação futura — não listadas no prompt 04).
- **Decisões de schema**:
  - Ledger (`credit_transactions`) imutável: só `created_at` (`UPDATED_AT = null`), FKs com `restrictOnDelete` (não apaga por cascade).
  - `audit_logs.user_id` com `nullOnDelete` (preserva trilha mesmo sem o ator).
  - `credit_transactions.reference` e `audit_logs.subject` como morphs (polimórficos).
  - `investigations.current_run_id` sem FK (evita dependência circular com `investigation_runs`).
  - Chave de idempotência: `source_runs` com `unique(investigation_run_id, source_definition_id)`; `investigation_runs` com `unique(investigation_id, run_number)`; `idempotency_key` único em ledger/payments/reservations; `external_id`/`external_event_id` únicos em payments/payment_events.
  - Valores monetários em centavos inteiros (`price_cents`, `amount_cents`, `cost_operational_cents`); créditos como inteiros.
  - Casts JSON (`array`) e `datetime` nos campos pertinentes.
- Models usam `$guarded = []` + `casts()` (populados por services, não por input de request); `User` ganhou `wallet()`, `investigations()`, `payments()`.

## Etapa 05 — Sistema de créditos

- Criado `App\Services\CreditLedgerService` (domínio) com: `balance`, `statement`, `purchase`, `promotional`, `refund`, `adminAdjust`, `reserve`, `consume`, `release`, `releaseExpiredReservations`.
- Exceções de domínio: `InsufficientCreditsException`, `InvalidReservationStateException`.
- **Decisões**:
  - Movimento de saldo sempre via `DB::transaction` + `lockForUpdate()` na carteira (impede corrida e saldo negativo).
  - Idempotência por `idempotency_key` (único) em ledger/reservas; retorno do registro existente em chamadas repetidas.
  - `reserve` = débito imediato (hold), criando ledger `reserve` (-) + `credit_reservations`; `consume` finaliza (ledger `consume` com amount 0, referência à reserva); `release` devolve o saldo (ledger `release` +).
  - `adminAdjust` exige motivo; pode ser negativo, mas nunca abaixo de zero.
  - `releaseExpiredReservations` compensa reservas vencidas (idempotente por reserva).
- Testes: `CreditLedgerServiceTest` (12 cenários: saldo, reserva, consumo, liberação, saldo insuficiente, ajuste sem motivo, idempotência, estado inválido, expiração, invariante `balance = Σ ledger`).
- **Concorrência**: `CreditConcurrencyTest` prova o lock `FOR UPDATE` no MySQL (duas conexões, segunda recebe *lock wait timeout*); é pulado fora do MySQL.

## Etapa 06 — Pagamento PIX abstrato

- Contrato `App\Payments\PaymentProvider` (`createCharge`, `getStatus`, `validateWebhook`) + value objects `PixCharge`, `VerifiedPaymentEvent` e enum `PaymentStatus`.
- `App\Payments\FakePixProvider`: cobrança fake (payload `FAKE_PIX|...` com placeholders, sem chave real) e webhook sem assinatura; fluxo 100% sem internet.
- `App\Services\PaymentService`: `checkout` (pedido → cobrança → `awaiting_payment`), `status`, `handleWebhook`, `simulatePayment`, `confirmPayment` (idempotente) e `grantCredits` (usa `CreditLedgerService::purchase` com chave `payment-paid-{id}`).
- Binding do provider por config em `AppServiceProvider`; `config/payments.php` lê `PAYMENT_PROVIDER`/`PIX_*` (placeholders).
- UI: lista de pacotes (`/credits`), página de pedido com PIX copia-e-cola e botão "Simular pagamento" (fake); rotas `payments.*` + webhook `/payments/webhook` (isento de CSRF).
- **Idempotência**: `payment_events.external_event_id` único (dedup), `payments.external_id` único, e concessão de créditos idempotente por pagamento.
- Testes: `PaymentServiceTest` (checkout, pacote inativo, webhook pago concede créditos uma única vez, evento diferente não duplica, webhook inválido, simulação).

## Etapa 07 — Domínio de investigação

- `App\Services\InvestigationService`: `create` (cria `Investigation` + `InvestigationRun` com snapshot do input + módulos, sem executar fontes nem reservar créditos — isso é dos jobs), `history`.
- Validação de entradas: trim, e-mail minúsculo, campos vazios → `null`; módulos exigem `SourceDefinition` ativa e o campo obrigatório do `type` (username/email/phone).
- Status: constantes em `Investigation` (draft…expired) e `InvestigationRun` (pending…failed); criação deixa investigation `queued` e run `pending`.
- `App\Policies\InvestigationPolicy` (`view`) registrado em `AppServiceProvider`; controller usa `AuthorizesRequests::authorize`.
- UI: `/investigations` (histórico), `/investigations/create` (form + seleção de módulos), `/investigations/{id}` (detalhes/execuções), via `Route::resource`.
- `StoreInvestigationRequest` para validação sintática; erros de domínio viram erro no campo `modules`.
- Testes: `InvestigationServiceTest` (snapshot, sem módulo, módulo inativo, input obrigatório por tipo, isolamento de histórico) e `InvestigationTest` (render, criação, 403 para investigação alheia, redirect de guest).

## Etapa 08 — Contrato de source adapters

- Contrato `App\Sources\InvestigationSource` (`key`, `name`, `supports`, `execute`, `normalize`, `healthCheck`).
- DTOs: `InvestigationInput` (com `fromArray`), `InvestigationContext`, `SourceResult`.
- `App\Sources\SourceRegistry` (registro/filtro por `supports`), registrado como singleton no `AppServiceProvider`.
- Enum `SourceRunStatus` (pending/running/completed/failed/timeout/skipped) + exceções (`SourceTimeoutException`, `SourceUnavailableException`, `SourceBlockedException`, `SourceInvalidInputException`) + `SourceErrorMapper` (exceção → `error_code`/status).
- Mocks determinísticos (base `MockSource`): `MockUsernameSource` (`mock-username`), `MockEmailSource` (`mock-email`), `MockWebSource` (`mock-web`).
  - Cenário controlado pelo valor de entrada: `timeout` → timeout, `error` → indisponível, `empty` → vazio, senão resultados determinísticos.
- Sem integração com ferramentas externas (Maigret/Sherlock/WhatsMyName/Holehe ficam para 10–13).
- Testes: `SourceAdapterTest` (registry, suporte, resultados determinísticos, vazio, timeout, erro, error mapper, normalize).

## Etapa 09 — Jobs e workers

- 7 jobs (`app/Jobs/`, fila Redis, recebem apenas IDs):
  - `StartInvestigationRunJob` (inicia run, reserva créditos, cria `source_runs` e despacha `ExecuteSourceJob`);
  - `ExecuteSourceJob` (executa adapter via `SourceRegistry`, persiste resultados/evidências, mapeia erro/timeout, dispara `NormalizeSourceResultsJob` quando todas as fontes terminam);
  - `NormalizeSourceResultsJob` (calcula `dedup_hash`);
  - `DeduplicateResultsJob` (marca duplicados em `metadata.duplicate_of`);
  - `BuildCorrelationJob` (score básico — expandido na etapa 16);
  - `BuildHtmlReportJob` (cria `reports` e finaliza status do run — expandido na etapa 18);
  - `ReleaseReservedCreditsJob` (consome/release a reserva conforme o resultado).
- `InvestigationService::create` agora despacha `StartInvestigationRunJob` (processamento assíncrono).
- Migration `add_metadata_to_investigation_runs` + helpers `pipelineStage()`/`markPipelineStage()` no run para **idempotência** das etapas.
- Retry/backoff/timeout declarados por job (`$tries`, `$backoff`, `$timeout`).
- **Uma fonte falha não cancela as demais**: cada fonte é um `source_runs`/job independente.
- Testes: `InvestigationPipelineTest` (reserva + dispatch, idempotência, saldo insuficiente, persistência, timeout, isolamento de falha, pipeline completo) + ajustes de `Queue::fake()` nos testes de investigação.

## Etapa 10 — Maigret mock

- `App\Sources\Adapters\MaigretMockAdapter` (key `maigret`), seguindo `InvestigationSource`.
  - Aceita `username`; fixtures determinísticas (GitHub/Instagram/Reddit).
  - Cenários: `found` (default) → resultados; `not_found` → vazio; `timeout` → `SourceTimeoutException`; `error` → `SourceUnavailableException`.
  - Sem acesso à internet.
- Registrado no `SourceRegistry` (AppServiceProvider).
- Testes: `MaigretMockAdapterTest` (key, suporte, found/not_found/timeout/error, normalização, registro).

## Etapa 11 — Sherlock mock

- `App\Sources\Adapters\SherlockMockAdapter` (key `sherlock`), mesmos princípios do Maigret.
  - Aceita `username`; múltiplos perfis determinísticos (twitter/facebook/youtube/twitch/pinterest).
  - Cenários: default → múltiplos perfis; `not_found` → vazio; `timeout` → `SourceTimeoutException`; `error` → `SourceUnavailableException` (mensagem sanitizada).
- Registrado no `SourceRegistry`.
- Testes: `SherlockMockAdapterTest` (key, suporte, múltiplos perfis, not_found/timeout/erro sanitizado, normalização, registro).

## Etapa 12 — WhatsMyName mock

- `App\Sources\Adapters\WhatsMyNameMockAdapter` (key `whatsmyname`).
  - Aceita `username`; múltiplos itens determinísticos (github/gitlab/bitbucket/codeberg/sourcehut) com `metadata.site` para permitir testar deduplicação.
  - Cenários: default → múltiplos itens; `not_found` → vazio; `timeout` → `SourceTimeoutException`; `error` → `SourceUnavailableException`.
- Registrado no `SourceRegistry`.
- Testes: `WhatsMyNameMockAdapterTest` (key, suporte, múltiplos itens, not_found/timeout/error, normalização, registro).

## Etapa 13 — Holehe mock

- `App\Sources\Adapters\HoleheMockAdapter` (key `holehe`), baseado em **e-mail**.
  - Simula serviços onde o e-mail está cadastrado (twitter/spotify/instagram/netflix/amazon) com `metadata.service`.
  - Cenários: default → serviços encontrados; `not_found` → vazio; `timeout` → `SourceTimeoutException`; `error` → `SourceUnavailableException`.
- Registrado no `SourceRegistry`.
- Testes: `HoleheMockAdapterTest` (key, suporte por e-mail, serviços, not_found/timeout/error, normalização, registro).

## Etapa 14 — Normalização

- `App\Services\SourceResultNormalizer`: transforma a saída dos adapters em `source_results` padrão.
  - Preserva URL pública (só `http(s)://`; demais viram `null`).
  - Extrai tipo de entidade (default `unknown`).
  - Remove HTML cru (`strip_tags`) de `value`/`title`/`description`/evidência.
  - Timestamp (`observed_at`) e `metadata`/`raw_payload` JSON.
  - `evidenceAttributes()` para mapear evidências.
- `ExecuteSourceJob` refatorado para usar o normalizador (antes a lógica ficava inline).
- Testes: `SourceResultNormalizerTest` (atributos, strip de HTML, URL, entity_type default, evidências, e normalização de **todos** os adapters registrados).

## Etapa 15 — Deduplicação

- `App\Services\ResultDeduplicator`: deduplicação **entre fontes**.
  - Estratégia determinística: hash de `tipo + valor normalizado + URL canônica`.
  - `canonicalUrl()` normaliza (minúscula, remove fragmento e barra final).
  - Agrupa equivalentes, marca `metadata.duplicate_of` nos duplicados e persiste `metadata.dedup_sources` (quais fontes acharam o item) no representante — **sem apagar evidências**.
- `NormalizeSourceResultsJob` usa `ResultDeduplicator::dedupHash` (hash canônico); `DeduplicateResultsJob` usa `deduplicate()`.
- Ajuste no `InvestigationPipelineTest` (URLs distintas não são mais duplicadas entre si).
- Testes: `ResultDeduplicatorTest` (duplicados exatos, quase duplicados, itens distintos, não apaga evidência, `canonicalUrl`).

## Etapa 16 — Correlação

- `App\Services\CorrelationService` (determinístico, configurável, explicável):
  - Pesos configuráveis em `config/correlation.php` (username +40, nome +20, e-mail +20, imagem +20*, bio +10, localização +10; *não usado no MVP).
  - Persiste em `run.metadata.correlation`: `score` (máx. 100), `rule_version` (`v1`), `signals` (sinais que casaram) e `evidence_ids` (resultados usados).
  - `label()` neutro: `baixa correspondência` (<30), `possível correspondência` (30–59), `correspondência forte por sinais públicos` (≥60).
  - Sem IA; sem afirmar identidade.
- `BuildCorrelationJob` refatorado para usar o serviço.
- Testes: `CorrelationServiceTest` (username, nome, e-mail, localização, determinismo com mesmos inputs, persistência).

## Etapa 17 — Evidências e fontes

- `SourceRun::displayStatus()` classifica a fonte para exibição:
  - `encontrado` (completed + resultado com confiança ≥ 0.7);
  - `possível correspondência` (completed + confiança baixa);
  - `sem resultado` (completed sem resultados);
  - `fonte indisponível` (failed/timeout);
  - `não pesquisado` (pending/running/skipped).
- UI de `/investigations/{id}` agora exibe fonte, URL, timestamp, método (tipo de evidência) e status por fonte, com **escaping** (Blade `{{ }}`, sem `{!! !!}`).
- `InvestigationController::show()` faz eager load de `sourceRuns/sourceDefinition/sourceResults/evidence`.
- Testes: `SourceRunDisplayStatusTest` (5 estados) e `EvidenceEscapingTest` (título com `<script>` é escapado).

## Etapa 18 — Relatório HTML

- `App\Http\Controllers\ReportController@show` + rota `investigations.report` (`/investigations/{investigation}/runs/{run}/report`).
- View `reports/show.blade.php` server-rendered com: cabeçalho, resumo (status/créditos/correlação), entradas, módulos, resultados (fonte/URL/timestamp/evidência), correlação, falhas/limitações e data — com **escaping** e classes `print:*` para impressão.
- Autorização via `InvestigationPolicy` (`authorize('view', ...)`) + validação de que o run pertence à investigação (404).
- Testes: `ReportTest` (usuário vê o próprio relatório com seções; usuário alheio recebe 403).

## Etapa 19 — Dashboard do cliente

- `App\Http\Controllers\DashboardController` + rota `/dashboard`.
- View `dashboard.blade.php` com: saldo de créditos, botão "Nova investigação" + "Comprar créditos", investigações recentes (com status), créditos gastos e pagamentos recentes.
- Estados vazios amigáveis ("Você ainda não tem investigações.", "Nenhum pagamento ainda.").
- Página leve (Blade, sem Livewire).
- Testes: `DashboardTest` (redirect de guest, estados vazios e dados exibidos).

## Etapa 20 — Painel administrativo

- Painel admin simples (sem pacote externo), sob `admin` middleware (`/admin`, `admin.*`).
- Seções: dashboard (usuários/investigações/pagamentos/créditos vendidos/fontes com erro), usuários (busca + **ajuste de créditos com motivo obrigatório + audit log**), pacotes (CRUD), pagamentos, investigações (lista + detalhe com source runs/erros), fontes (ativar/desativar) e auditoria.
- `App\Services\AuditLogger` (`log(actor, action, subject, metadata, ip)`).
- Controllers em `App\Http\Controllers\Admin\` + views `admin/*` com nav parcial.
- `AdminCreditAdjustmentRequest` (amount ≠ 0 + reason obrigatório).
- Testes: `AdminTest` (403 para não-admin, dashboard, ajuste de créditos com motivo + audit, motivo obrigatório, CRUD de pacote).

## Etapa 21 — Cache e idempotência operacional

- `InvestigationSource::version()` (participa da chave de cache); adapters retornam `v1`.
- `App\Services\SourceCache`: cache de resultado de fonte com chave `source:{key}:{version}:{hash de entrada}` e TTL (`config/source_cache.php`).
  - Não inclui usuário: os dados são públicos por entrada; o hash de entrada garante que nunca se retorne o resultado de outra entrada.
- `ExecuteSourceJob` usa o cache ao executar o adapter (erros/timeout não são cacheados, pois lançam exceção).
- Idempotência extra: `ExecuteSourceJob` e `StartInvestigationRunJob` implementam `ShouldBeUnique` (`uniqueId` por sourceRun/run).
- Webhooks já idempotentes via `payment_events.external_event_id`.
- Testes: `SourceCacheTest` (chave com source/versão/hash, remember, entrada diferente → chave diferente, serialização de `SourceResult` DTOs).

## Etapa 22 — Logs e auditoria

- Três níveis: application logs (Laravel), investigation/source logs (canal `investigations` em `config/logging.php`) e audit logs (`audit_logs` + admin/audit, já existentes).
- `App\Services\LogSanitizer`: sanitiza arrays (chaves sensíveis → `[REDACTED]`) e strings (mascara `key=...`/`PIX_KEY=...`) antes de logar.
- Jobs registram eventos no canal `investigations`: `StartInvestigationRunJob` (`run.started`/`run.failed`) e `ExecuteSourceJob` (`source.executed` com status/erro/duração), sempre sanitizados.
- Interface administrativa de auditoria já existente (etapa 20).
- Testes: `LogSanitizerTest` (chaves sensíveis, PIX key em string, dados seguros intactos).

## Etapa 23 — Privacidade e governança

- `config/privacy.php`: retenção configurável por categoria (investigações, relatórios, resultados, logs, auditoria, financeiro).
- `App\Services\AccountDeletionService`: exclusão de conta remove dados operacionais (investigações/results/relatórios via cascade) e **anonimiza** dados pessoais, preservando registros financeiros/auditoria.
- `ProfileController::destroy` passa a usar o serviço (em vez de `$user->delete()`).
- `AccountController`: `privacy()` (tela de privacidade/contato) e `export()` (exportação JSON dos dados da conta).
- Páginas públicas editáveis: `legal/privacy.blade.php` e `legal/terms.blade.php` (placeholders).
- Documentado que base legal/textos finais devem ser validados antes da produção (CHANGELOG).
- Testes: `PrivacyTest` (privacidade/termos, tela de privacidade, exportação, exclusão anonimiza e remove investigações) + ajuste no `ProfileTest`.

## Etapa 24 — Hardening de segurança

- Auditoria: sem shell commands, sem SQL cru (`DB::raw`/`whereRaw`), sem `create($request->all())`, sem `{!! !!}` no código de aplicação.
- `App\Http\Middleware\SecurityHeaders` (X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy) adicionado ao grupo `web`.
- Rate limit em endpoints sensíveis: `POST /investigations` e `POST /credits/{package}/checkout` (`throttle:10,1`).
- Testes de segurança: `SecurityTest` (headers, mass assignment de `is_admin`, XSS no título da investigação, busca admin segura contra SQL injection).

## Etapa 25 — Suíte de testes

- Cobertura dos fluxos críticos revisada: auth, créditos (+concorrência), PIX fake, investigação, jobs, adapters mock, normalização, deduplicação, correlação, relatório, admin, privacidade e segurança.
- Lacunas preenchidas:
  - `PrivacyTest::test_account_deletion_preserves_financial_records` (carteira/ledger/pagamentos preservados após exclusão);
  - `InvestigationPipelineTest::test_release_reserved_credits_releases_on_failed_run` (caminho de release/refund do job);
  - `InvestigationPipelineTest::test_pipeline_with_failure_marks_run_partial` (run/investigação ficam `partial` com fonte falha).
- Upload inválido (cenário 13 de docs/17) permanece não aplicável: MVP não implementa uploads.
- Suíte completa executada sem regressões.

## Etapa 26 — Docker e operação

- `README.md` substituído por documentação do projeto (como rodar, serviços, comandos úteis e regras de segurança).
- Tarefa agendada real adicionada em `routes/console.php`: `credits:release-expired` (compensa reservas vencidas) a cada 5 minutos.
- Validação do ciclo completo: build limpo ✓, stack sobe do zero ✓, migrations ✓, Redis (`PONG`) ✓, scheduler (`schedule:list`) ✓, worker processa job ✓.
- Nenhum segredo embutido (placeholders `CONFIGURE_ME` no `.env.example`).
