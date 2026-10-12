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

## Etapa 27 — Auditoria final do MVP

- Auditoria integral registrada em `AUDIT.md` (classificação por requisito, pendências e próximos passos).
- Estado final: 165 testes passando, Pint PASS, 56 rotas, 27 commits, migrations aplicadas.
- Pendências principais: RF-17 (reexecução), purga por retenção, landing page, uploads (fora do MVP), `entities`/`entity_identifiers`.

## Ajustes pós-etapa 27 — Login sem verificação de e-mail e interface em pt-BR

- **Verificação de e-mail desativada** (facilitar a criação de conta):
  - `User` não implementa mais `MustVerifyEmail`; removido o middleware `verified` de `/dashboard`, da área do usuário e de `/admin`.
  - Removidas as rotas `verification.*`, os controllers de verificação, a view `auth.verify-email` e o formulário de reenvio no perfil.
  - `RegisteredUserController` não dispara mais o evento `Registered`; `ProfileController` não zera mais `email_verified_at` ao trocar o e-mail.
  - Cobertura: `NoEmailVerificationTest` (acesso imediato após registro e para usuário não verificado, inclusive admin).
- **Interface em pt-BR**:
  - `APP_LOCALE`/`APP_FALLBACK_LOCALE=pt_BR` no `.env`/`.env.example` e padrões em `config/app.php`.
  - Novos `lang/pt_BR/{validation,auth,passwords,pagination}.php` e `lang/pt_BR.json` (textos do Breeze, paginação e rótulos de status do domínio).
  - `Carbon::setLocale()` em `AppServiceProvider` para `diffForHumans()`/datas relativas em português.
  - `welcome.blade.php` reescrita como landing page em português; status de investigação/run/fonte/pagamento exibidos via `__()`.
  - Cobertura: `LocalizationTest` (mensagens de validação, login, status).
- **Seeder de demonstração**: `DemoUsersSeeder` aceita senhas por parâmetro (testes determinísticos) e imprime as credenciais resolvidas em `local`.


## Popular o banco — seeders e factories

- **Factories**: adicionados *states* de domínio e defaults usando enums/constantes dos models —
  `Payment` (`paid`/`awaitingPayment`/`expired`), `Investigation` (`queued`/`running`/`completed`/`partial`/`failed`),
  `InvestigationRun` (idem), `SourceRun` (`completed`/`failed`/`timeout`) e `Report` (`completed`).
  Factories seguem sendo a ferramenta para testes pontuais; seeders montam o conjunto coerente.
- **Seeders novos**:
  - `SourceDefinitionSeeder` — 7 fontes de referência (uma por adapter do `SourceRegistry`). Sem elas, `/investigations/create` não oferece módulos e o pipeline não executa. Determinístico, idempotente e **preserva `active`** (não reativa fonte desligada pelo admin).
  - `CreditPackageSeeder` — 3 pacotes iniciais; também preserva `active`.
  - `DemoDataSeeder` — dataset de demonstração usando os **serviços reais**: `PaymentService` (PIX fake: 1 pagamento pago + 1 aguardando), `InvestigationService` (4 cenários executando o pipeline de verdade — 3 concluídos e 1 `partial` com timeout isolado de fonte, demonstrando que uma falha não derruba a investigação), relatórios, correlação, `AuditLogger` e top-up idempotente de créditos. O pipeline roda com `queue.default=sync` + `cache.default=array` para a seed terminar com os dados já processados.
  - `Support/DemoAccounts` — resolução compartilhada de nome/e-mail das contas `DEMO_*`/`ADMIN_*`.
- `DatabaseSeeder` passa a orquestrar: referência → contas → dados. Tudo idempotente e bloqueado em produção.
- Cobertura: `ReferenceDataSeederTest` (adapters cobertos, tipos, idempotência e preservação do toggle do admin) e `DemoDataSeederTest` (pipeline, relatórios, consistência saldo × ledger, pagamentos e idempotência).

## Etapa 28 — Diagnóstico e E2E com mocks

- **Diagnóstico do ambiente real** (Docker): 6 serviços `Up` (`app`, `web`, `worker`, `scheduler`, `mysql` healthy, `redis` healthy); Laravel 13.34 / PHP 8.5.11; MySQL com 15 migrations aplicadas; Redis `PONG`; fila `redis` vazia (0 pendentes/reservadas/atrasadas); scheduler com `credits:release-expired` a cada 5 min; 56 rotas.
- **Fluxo mapeado**: `Investigation` → `InvestigationRun` → `source_runs` → adapter mock → `source_results`/`evidence` → normalização → deduplicação → correlação (`metadata.correlation`) → `reports` → liquidação de créditos, com etapas idempotentes em `metadata.pipeline`.
- **E2E com mocks**: executado no ambiente Docker (MySQL + Redis + worker) e coberto por teste automatizado novo `InvestigationE2EMockTest` (sem `Queue::fake`, cadeia real de jobs em fila síncrona) — 7 adapters mock em uma execução e cenário de fonte indisponível.
- **Defeitos encontrados e corrigidos** (mínimos, sem refatorar o domínio):
  1. `SourceCache` gravava objetos `SourceResult` no cache; com `config/cache.php → 'serializable_classes' => false` a leitura em Redis devolvia `__PHP_Incomplete_Class` e o `ExecuteSourceJob` falhava na 2ª execução da mesma entrada. Agora o cache guarda apenas primitivos/arrays (`payload` ↔ DTO) e a chave carrega a versão do payload (`p1`) para invalidar formatos antigos.
  2. `StartInvestigationRunJob` despachava `ExecuteSourceJob` dentro do mesmo laço que criava os `source_runs`. Com fila síncrona (usada pelo `DemoDataSeeder`) ou múltiplos workers, a primeira fonte podia concluir e disparar o restante do pipeline antes de os demais `source_runs` existirem, finalizando o run como `completed` mesmo com fonte em `timeout`. Agora todos os `source_runs` são criados antes de qualquer despacho.
  3. `ExecuteSourceJob` não era retry-safe: um job que falhava depois de marcar a fonte como `running` tinha o retry descartado pelo guard de status, prendendo o run em `running` e mantendo a reserva de créditos pendente. Agora uma tentativa interrompida é reexecutada, descartando os resultados parciais daquela fonte.
- Cobertura nova: `InvestigationE2EMockTest` (fluxo completo e ordem de criação das fontes) e `SourceCacheTest::test_cached_payload_contains_no_php_objects`; `InvestigationPipelineTest` ganhou `test_execute_source_job_reexecutes_interrupted_attempt`.
- Estado: 182 testes passando (611 assertions); Pint PASS (194 arquivos).

## Etapa 29 — Contratos do pipeline

- Diagnóstico dos contratos existentes entre `InvestigationService`, jobs, adapters, normalização, deduplicação, correlação e relatório (registrado em `docs/24-contratos-pipeline.md`).
- **Contrato de entrada do adapter**: novo `SourceDescriptor` (`slug`, `type`, `timeout`, `config`) embutido em `InvestigationContext` (parâmetros opcionais, compatível com chamadas existentes). O orquestrador resolve tudo a partir de `source_definitions`; o adapter não acessa Eloquent/banco.
- **Contrato de saída do adapter**: novo `SourceOutcome` (`findings` + `warnings` + `metadata`), agora o retorno de `InvestigationSource::execute()`. Status, duração e erro continuam sendo do orquestrador, garantindo histórico mesmo com falha (docs/20, docs/23). Os 7 adapters mock foram ajustados para o envelope, com comportamento idêntico.
- **Contrato de erro**: novo `SourceNotRegisteredException extends SourceException` (`unavailable`/`failed`) lançada por `SourceRegistry::get()`. Antes, uma `source_definitions` sem adapter registrado lançava `InvalidArgumentException` e derrubava o job; agora a fonte vira falha isolada e o run termina como `partial`.
- **Sem service locator**: `ExecuteSourceJob` recebe `SourceResultNormalizer` por injeção (antes era `app()` dentro de método privado) e persiste `metrics` (`findings_count`, `warnings`, `adapter`).
- **Snapshot de entrada tipado**: `InvestigationRun::moduleSlugs()` substitui a leitura de `input_snapshot['modules']` espalhada pelo código.
- **Cache tipado**: `SourceCache::remember()` passa a devolver `SourceOutcome` e cacheia `{findings, warnings, metadata}` como payload primitivo (versão `p2` da chave), preservando avisos/métricas em cache hit.
- Cobertura nova: `PipelineContractTest` (5 cenários: descriptor sem banco, persistência de findings/evidências/métricas, fonte sem adapter → `partial`, erro de registro estruturado, cache preservando avisos) com `Tests\Support\RecordingSource`.
- Estado: 187 testes passando (649 assertions); Pint PASS (199 arquivos); E2E ao vivo pelos 7 mocks validado (run `completed`, 22 resultados, métricas persistidas).

## Etapa 30 — Orquestrador da investigação

- Novo `App\Services\InvestigationOrchestrator` concentra as decisões de negócio do run (determinação de módulos, criação dos registros por módulo e desfecho); os jobs passam a apenas executar etapas e os controllers seguem sem lógica de negócio. Fluxo documentado em `docs/25-orquestrador-investigacao.md`.
- **Determinação de módulos por identificador (passo 3)**: `split()` usa `InvestigationSource::supports()` quando o adapter existe e, sem adapter, o `source_definitions.type`. Módulo selecionado e não aplicável **não** rejeita mais a investigação: vira `source_run` `skipped` com `error_code = invalid_input` e aviso público (limitação observável, docs/02). Se nenhum módulo for aplicável, a criação é rejeitada com erro de formulário.
- **Validação de créditos (passo 1)**: `InvestigationService` valida o saldo antes de criar a execução (`InsufficientCreditsException`), mantendo a reserva atômica com lock no `StartInvestigationRunJob`. O controller mapeia o erro para o formulário; nada é persistido quando falta saldo. A reserva considera **apenas** os módulos aplicáveis (módulo ignorado não é cobrado).
- **Desfecho tri-estado (passo 11)**: `finalize()` decide `completed` (todas concluíram), `partial` (alguma falhou/timeout, docs/23) ou `failed` (nenhuma fonte concluiu — antes inalcançável). `markFailed()` cobre `insufficient_credits` e `no_applicable_modules`. `failed` libera a reserva; `completed`/`partial` consomem.
- **Observabilidade**: `metadata.finalization` (`status`, `reason`, `counts` por status de fonte, `decided_at`), etapa `pipeline.sources_created_at` e logs `run.started` (com `skipped_sources`), `run.finalized` e `run.failed` no canal `investigations`.
- Relatório: a seção "Falhas e limitações" passa a listar também fontes `skipped` (módulo não aplicável), conforme docs/08.
- Testes: novo `InvestigationOrchestratorTest` (módulo não aplicável → `skipped`; desfecho nos 4 cenários; `markFailed`; POST sem créditos não cria nada) e ajustes em `InvestigationServiceTest`/`InvestigationTest` (carteira financiada) e `PipelineContractTest` (run sem fonte concluída agora é `failed` com reserva compensada).
- Estado: 192 testes passando (699 assertions); Pint PASS (201 arquivos); E2E ao vivo validado (`holehe` ignorado por falta de e-mail, run `completed` com `finalization.counts.skipped = 1`).

## Etapa 31 — Persistência da execução por módulo

- Diagnóstico de `source_runs`: já registrava status/tempos/erro, mas faltavam identificação direta da investigação, snapshot da fonte, contagem de resultados, versão da ferramenta e a mensagem de erro não era sanitizada. Estrutura final documentada em `docs/26-persistencia-source-run.md`.
- Migração `2026_09_30_000160_add_execution_details_to_source_runs_table`: adiciona `investigation_id` (FK denormalizada + índice), `source` (snapshot do slug), `result_count`, `tool_version`, renomeia `attempt` → `attempts` (default 0, agora "tentativas executadas") e faz **backfill** das execuções existentes a partir de `investigation_runs`, `source_definitions` e `source_results`.
- **Mensagem de erro sanitizada**: novo `SourceErrorMapper::toPublicMessage()` deriva a mensagem pública do código de erro (`timeout`, `unavailable`, `blocked`, `invalid_input`), sem expor detalhe interno; o detalhe técnico vai apenas para o log sanitizado (`source.failed`).
- `ExecuteSourceJob` passa a gravar `result_count`, `tool_version` (de `SourceOutcome.metadata`) e `attempts`; `metrics` fica restrito a avisos/metadados extras. `InvestigationOrchestrator::createSourceRuns()` grava `investigation_id`/`source`, e o hook `creating` do model `SourceRun` garante a denormalização em factories/seeders.
- Relatório e painel admin exibem resultados/versão por fonte (admin também tentativas, duração e mensagem pública).
- Testes: novo `SourceRunPersistenceTest` (sucesso, timeout, erro, retry e `skipped`) e ajuste do `PipelineContractTest`.
- Estado: 197 testes passando (739 assertions); Pint PASS (203 arquivos); E2E ao vivo validado (`attempts=1`, `result_count`, `duration_ms` e mensagem pública sanitizada persistidos).

## Etapa 32 — Integração real do Maigret

- Verificações prévias (documentadas em `docs/27-maigret-integracao.md`): licença **MIT**, versão **0.6.6**, requer Python >= 3.10; instalação reproduzível no `docker/php/Dockerfile` (`apk add python3 py3-pip` + `pip install --break-system-packages maigret==0.6.6`, versão fixada por `ARG MAIGRET_VERSION`, sem dependências de build); binário em `/usr/bin/maigret`.
- **Execução segura (docs/21)**: novo `App\Sources\Runners` (`ToolRunner` + `ProcessToolRunner` + `ToolCommand`/`ToolResult`) com argv separado (nunca shell), timeout obrigatório, diretório de trabalho isolado com `HOME` próprio, ambiente controlado (nada da aplicação vaza), captura limitada de stdout/stderr e código de saída explícito (`124` em timeout).
- **Adapter real** `MaigretAdapter` (mesmo slug/contrato do mock): valida o username (`^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$`) antes de executar, roda `maigret <username> --id-type username --timeout N --top-sites N --max-connections N --no-color --no-progressbar --no-autoupdate --no-recursion --print-errors -J simple -fo <workdir>`, converte o relatório bruto pelo novo `MaigretReportParser` e limpa o diretório no `finally`. Timeout do processo = `min(MAIGRET_PROCESS_TIMEOUT, source_definitions.timeout)`.
- **Contrato comum**: `MaigretReportParser` produz `SourceResult` (`social_profile`, `title` = site, `value` = username, `url` = perfil, `confidence` 0.8, evidência `public_profile_url`, metadados site/tags/http_status/rank); entradas sem URL válida são descartadas. Avisos `[!]` da ferramenta viram `warnings` → `source_runs.metrics.warnings`; a versão da ferramenta vai para `source_runs.tool_version` (prompt 31).
- **Troca por configuração**: `config/sources.php` (novo) + `MAIGRET_DRIVER=process|mock`; o `AppServiceProvider` registra o adapter real ou o mock sob o mesmo slug `maigret`. `source_definitions.config` pode sobrepor as opções por fonte (contrato do prompt 29). `phpunit.xml` força `mock` para a suíte permanecer hermetica.
- Testes: `MaigretReportParserTest` (fixture real), `MaigretAdapterTest` (argv, isolamento/limpeza, username insegura sem execução, timeout, relatório ausente, `normalize`, `healthCheck`, troca mock↔real) e `MaigretRealIntegrationTest` (execução real com identificador público institucional `python`, atrás de `MAIGRET_E2E=1`).
- **Teste controlado real** (identificador público `python`): 12,5 s, 19 perfis encontrados; E2E ao vivo pela stack (MySQL + Redis + worker) → run `completed`, `source_runs.tool_version=0.6.6`, `result_count=19`, `duration_ms=11701`, 19 resultados com URL + evidência + `dedup_hash`, correlação e relatório gerados.
- Estado: 209 testes passando (794 assertions) + 1 teste real gated; Pint PASS (214 arquivos).

## Etapa 33 — Integração real do Sherlock

- Verificações prévias (documentadas em `docs/28-sherlock-integracao.md`): licença **MIT**, versão **0.16.2**, Python >= 3.9; instalado na mesma camada Python da imagem junto do Maigret (versões fixadas por `ARG`); binário `/usr/bin/sherlock`.
- **Sem cópia de lógica**: a camada de execução do Maigret foi extraída para reuso — `ToolWorkspace` (diretório isolado + localização de relatório + limpeza), `ToolVersionDetector` (`--version` por ferramenta) e `ToolOutput::publicWarnings()` (avisos públicos) — e o `MaigretAdapter` foi refatorado para usá-la. O `SherlockAdapter` define apenas argv, validação, leitura do CSV e parser.
- **Particularidades do Sherlock tratadas**: não há saída JSON (usamos `--csv`); não há "top N" (escopo por lista curada de 19 sites em `SHERLOCK_SITES`); `--local` usa o banco embarcado; a varredura é sequencial e o CSV só sai no fim, então execução interrompida aproveita as linhas `[+]` já impressas, devolvendo resultados parciais com aviso (ou `timeout` se nada foi capturado).
- **Contrato comum**: `SherlockReportParser` produz `SourceResult` (`social_profile`, `title` = site, `value` = username, `url` = perfil, `confidence` 0.8, evidência `public_profile_url`, metadados site/tool/exists/http_status); linhas `Available` e URLs inválidas são descartadas.
- **Deduplicação entre fontes**: `ResultDeduplicator::canonicalUrl()` passou a normalizar o host removendo `www.`, de modo que a mesma descoberta encontrada por Maigret (`github.com/x`) e Sherlock (`www.github.com/x`) seja agrupada — a representante guarda `metadata.dedup_sources` com todas as fontes e nenhuma evidência é apagada.
- Testes: `SherlockReportParserTest`, `SherlockAdapterTest` (argv/escopo/isolamento/timeout parcial/username insegura/troca mock↔real), `CrossSourceDeduplicationTest` (adapters reais com runner determinístico validando `dedup_sources = [maigret, sherlock]`) e `SherlockRealIntegrationTest` (ferramentas reais, gated por `OSINT_E2E=1`); `ResultDeduplicatorTest` ganhou os casos de canonicalização `www.`.
- Estado: 224 testes passando (860 assertions) + 2 testes reais gated; Pint PASS (224 arquivos).

## Etapa 34 — Integração real do WhatsMyName

- Verificações prévias (documentadas em `docs/29-whatsmyname-integracao.md`): o WhatsMyName é um **dataset** JSON (`wmn-data.json`, 717 sites) sob **CC BY-SA 4.0** (© Micah Hoffman e colaboradores); os checkers embutidos foram removidos em maio/2023 e o repositório mantém apenas o dataset + o schema + um verificador de saúde dos próprios sites. Como não há CLI oficial de busca, o adapter **fixa o dataset por commit** (`ARG WHATSMYNAME_COMMIT`) e executa as verificações conforme o `wmn-data-schema.json`, mantendo o `LICENSE.md` ao lado para atribuição; o build falha se o JSON não interpretar.
- Novo `App\Sources\Adapters\WhatsMyName\{WhatsMyNameDataset,WhatsMyNameChecker,WhatsMyNameReportParser}` + `WhatsMyNameAdapter`: o dataset é carregado/validado (saída inválida → `unavailable`), os sites são filtrados (nunca `xx NSFW xx`) e limitados, as requisições rodam em lotes concorrentes com timeout por request e orçamento total, e cada descoberta vira `SourceResult` (`uri_pretty` como URL pública, evidência `public_profile_url`, metadados site/category/method/http_status).
- **Regras do schema aplicadas**: `e_code`/`e_string` (existe) × `m_code`/`m_string` (livre), `post_body` + `headers` (POST, ex. AniList GraphQL), `strip_bad_char` e `uri_pretty`; site sem `{account}` (na URL ou no corpo) é descartado.
- **Escopo padrão curado**: como a ordem do dataset é alfabética e não há ranking, o padrão passou a ser uma lista curada de 26 sites de alto sinal (`WHATSMYNAME_SITES`, nomes exatos do dataset), com `WHATSMYNAME_MAX_SITES` como teto de segurança — isso também garante sobreposição real com as outras fontes.
- **Tratamento de falha mapeado**: dataset ausente/inválido → `unavailable`; nenhuma resposta HTTP → `unavailable`; orçamento esgotado sem descobertas → `timeout`; com descobertas → resultados parciais + aviso; username insegura → `invalid_input` sem nenhuma requisição. Versão do dataset (`REVISION`) → `source_runs.tool_version`; licença e contagens → `metrics.adapter`.
- Testes: `WhatsMyNameReportParserTest`, `WhatsMyNameAdapterTest` (os cinco cenários pedidos + regras do schema, com `Http::preventStrayRequests()`), `WhatsMyNameDeduplicationTest` (adapter real + mock do Maigret → `dedup_sources = [maigret, whatsmyname]` sem perder evidências) e `WhatsMyNameRealIntegrationTest` (dataset e HTTP reais, gated por `OSINT_E2E=1`).
- Correções de robustez reveladas pelos testes: sites cujo `{account}` está apenas no `post_body` passaram a ser aceitos no carregamento do dataset.
- **Validação ao vivo** (MySQL + Redis + worker, extensão `python`): run `completed` com maigret 0.6.6 (19), sherlock 0.16.2 (11) e whatsmyname (14, commit `062bcfe…`, licença CC BY-SA 4.0 em `metrics.adapter`); 44 resultados, nenhum sem evidência, 10 grupos deduplicados — `github.com/python`, pinterest, vimeo e telegram com `["maigret","sherlock","whatsmyname"]`, flickr com `["maigret","whatsmyname"]` e twitch/spotify/docker hub com `["sherlock","whatsmyname"]`.
- Estado: 239 testes passando (917 assertions) + 3 testes reais gated; Pint PASS (232 arquivos).

## Etapa 35 — Integração real do Holehe

- Verificações prévias (documentadas em `docs/30-holehe-integracao.md`): licença **GPL-3.0** (única fonte não-MIT/CC), versão **1.61**, sem pacote de dependências extra; técnica = consulta pública aos fluxos de **registro/login** dos serviços (com recuperação de senha opcional); saída em CSV; **exit code 1 mesmo em sucesso** (o CSV gerado é o sinal confiável).
- **Salvaguardas inegociáveis**: `--no-password-recovery` fixo no adapter (não configurável), nenhum acesso a conta/credencial/conteúdo privado, colunas `emailrecovery`/`phoneNumber`/`others` jamais lidas ou persistidas, entrada validada e passada como argv separado, execução isolada com timeout e limpeza.
- **GPL-3.0 registrada**: a ferramenta roda como processo separado (sem linkagem com o código da aplicação), mas redistribuir a imagem exige cumprir as obrigações da GPL — decisão jurídica registrada como pendência. O driver padrão em `config/sources.php` é `mock`; `process` é opt-in explícito no ambiente.
- **Aviso obrigatório**: uma indicação de cadastro associada a um e-mail não prova que a pessoa controla a conta — `confidence` 0.5 (abaixo do limiar de "encontrado"), `metadata.disclaimer` persistido em `metrics.adapter` e bloco de aviso no relatório sempre que o run tiver o módulo Holehe.
- Novos `HoleheReportParser` e `HoleheAdapter` (reutilizando `ToolRunner`, `ToolWorkspace`, `ToolVersionDetector` e `ToolOutput`); argv `holehe <email> --no-password-recovery --only-used --no-color --no-clear --csv --timeout N`.
- Testes: `HoleheReportParserTest` (conversão, domínio inválido e prova de que dados de recuperação nunca entram no domínio), `HoleheAdapterTest` (encontrado com `exit_code=1`, nenhum resultado, relatório ausente, timeout, e-mail inválido sem execução, `-NP` na argv, limpeza, troca mock↔process) e `HoleheRealIntegrationTest` (real, gated, com e-mail de **domínio reservado** RFC 2606).
- Ajuste de infraestrutura: `ToolVersionDetector::detect()` passou a aceitar o argumento de versão (o Holehe não tem `--version`; a versão vem do `--help`), mantendo `--version` como padrão das demais ferramentas.
- **Validação ao vivo** (MySQL + Redis + worker, run 22, `python` + `test@example.com`): run `completed` com maigret 0.6.6 (18), whatsmyname (13, commit `062bcfe…`) e **holehe 1.61 (0 serviços, 1931 ms)** — `metrics.adapter.password_recovery = false`, `disclaimer` persistido e aviso renderizado no relatório; nenhum diretório temporário remanescente e fila zerada.
- Estado: 251 testes passando (975 assertions) + 4 testes reais gated; Pint PASS (237 arquivos).

## Etapa 36 — Normalização e deduplicação reais

- Auditoria do modelo normalizado (achados e correções em `docs/31-normalizacao-deduplicacao.md`): sem versão de chave, `dedup_hash` dependente de etapa posterior, URL canônica parcial, `entity_type` livre e consolidação sem observações.
- **Chave estável v2**: `sha256(entity_type | plataforma | identificador | URL canônica)` com `dedup_key_version`, `keyParts()` e `explain()` auditáveis. A "fonte" da chave é a **plataforma observada** (github.com…), nunca a ferramenta — incluir a ferramenta impediria a consolidação entre fontes exigida no mesmo prompt.
- URL canônica: esquema fixo `https` (http/https do mesmo recurso convergem), host sem `www.`, sem barra final/fragmento, parâmetros de rastreamento removidos (`utm_*`, `fbclid`, `ref`, …) e demais parâmetros ordenados (determinístico).
- `SourceResultNormalizer` passou a gravar a chave e a plataforma na persistência; `NormalizeSourceResultsJob` virou reconciliador (recomputa linhas legadas da chave v1). `SourceResult::toSourceResult()` converte a linha persistida de volta ao contrato do adapter.
- Consolidação sem apagar evidência: representante com `dedup_sources`, `dedup_group_size` e `dedup_observations` (fonte, URL, data e evidências de cada observação); duplicatas com `duplicate_of`/`duplicate_of_hash`. Deduplicação converge em reexecuções.
- **Testes cruzados entre adapters**: `CrossAdapterDeduplicationTest` roda Maigret + Sherlock + WhatsMyName + Holehe reais (fixtures + HTTP simulado) — GitHub e Telegram consolidados com 3 fontes observadoras e evidências preservadas; Holehe (e-mail) **nunca** se funde com perfil social, mesmo compartilhando o domínio.

## Etapa 37 — Correlação explicável

- Auditoria da correlação (`docs/32-correlacao-explicavel.md`): sinais sem explicação/limitação, `evidence_ids` com todos os resultados (não os que casaram), score podendo ser lido como probabilidade e faixa de associação para username coincidente.
- Regra **v2** em `config/correlation.php`: cada sinal é um fator com tipo, peso, `corroborating`, `evaluated`, explicação, limitação e tipos de evidência.
- Saída persistida em `metadata.correlation`: fatores (com `evidence_ids`, fontes, valores observados), `explanations`, `limitations`, `not_evaluated`, `observed_inputs`, `score_kind = internal_correlation_indicator`, `identity_asserted = false` e `signals` mantido por compatibilidade.
- **Sem inferência por username**: sem fator corroborante a faixa é "username coincidente — sem corroboração"; as limitações sempre incluem o aviso de indicador interno e o de fonte única.
- Testes ampliados (`CorrelationServiceTest`): caso **positivo** (username+nome+localização → 70, forte, com explicação/limitação/evidências), **ambíguo** (só username → sem corroboração) e **negativo** (nada casa → 0 + limitação de ausência); duplicatas não somam e a avaliação é determinística.

## Etapa 38 — Relatório baseado em evidências

- Novo `ReportManifestBuilder` + manifesto congelado em `reports.metadata` (`report-manifest-v1`) com `content_hash` sha256: investigação, run (status, motivo, datas, créditos, etapas), identificadores, módulos, fontes (nome, status, versão, resultados, tentativas, duração, mensagens, avisos), resumo, descobertas (consolidadas + observações), correlação, limitações e fontes com falha.
- Relatório HTML reescrito (`docs/33-relatorio-evidencias.md`): identificadores pesquisados, **fontes consultadas com status/versão/tentativas/duração**, descobertas consolidadas com URL, data/hora de observação, evidências e todas as fontes observadoras, correlação com tabela de fatores (explicação/evidências/limitações), falhas/timeout/não aplicáveis visíveis, avisos e rodapé com o hash do manifesto.
- Snapshot alinhado ao modelo atual: não se duplica HTML — congela-se o manifesto. Renomear um módulo depois **não** altera um relatório já emitido (teste dedicado).
- Segurança mantida: conteúdo externo escapado pela Blade e links apenas para URLs `http(s)`.

## Etapa 39 — Créditos, retry e idempotência

- Auditoria (`docs/34-creditos-retry-idempotencia.md`): a reserva única do total fazia o usuário pagar por módulos que falharam em runs parciais.
- **Cobrança por módulo**: `credit_reservations.source` (nova migration) + `InvestigationOrchestrator::reserveCredits()`, com chave idempotente `run-{id}-source-{slug}-reserve`. No encerramento, módulo `completed` é consumido e `failed`/`timeout`/`skipped` é estornado; reservas legadas (uma por run) mantêm o comportamento anterior. `credits_consumed` passou a somar apenas o efetivamente cobrado.
- **Reexecução controlada**: `InvestigationService::rerun()` + policy `rerun` + rota `POST /investigations/{id}/runs` (`throttle`) + botão na UI (com link para o relatório de cada run). Cria novo run com custo independente, recusa enquanto houver execução em andamento e exige saldo; o run anterior permanece intacto.
- **Testes** (`CreditRetryIdempotencyTest`, 9 cenários): run parcial cobra só o módulo concluído; run falho estorna tudo; retry do job de abertura não duplica reserva; reentrega da fonte reconverge resultados e cobra uma vez; reserva vencida é compensada; reexecução cria run independente; recusa durante run em andamento; exige saldo; rota autorizada (403 para terceiros). Invariante verificada em todos: `saldo == soma(ledger)`.
- **Validação ao vivo** (MySQL + Redis + worker): run 23 com as 4 fontes reais — maigret 18, sherlock 10, whatsmyname 13, holehe 0; 41 resultados, todos com chave v2; 28 descobertas (9 consolidadas: github/medium/pinterest/telegram/flickr/vimeo/tiktok/spotify/docker com 2–3 fontes cada); correlação v2 (40, "username coincidente — sem corroboração"); manifesto `report-manifest-v1` (`ba8fa4cdf656`); saldo 467 == ledger. Reexecução ao vivo gerou o run 24 (`run_number` 2) com 3 módulos e 6 créditos, mantendo os runs anteriores intactos e o saldo 461 == ledger.
- Estado: 278 testes passando (1154 assertions) + 4 testes reais gated; Pint PASS (241 arquivos).

## Decisões do cliente (ajuste pós-etapa 39)

1. **Cobrança pela consulta, não pelo resultado** — a regra de liquidação passou de "consome só o módulo concluído" para **"consome toda consulta executada"**: o módulo é cobrado mesmo sem nenhum resultado (`attempts > 0`), inclusive quando a consulta falhou ou estourou o tempo limite; só é **estornado** o que nunca foi consultado (módulo não aplicável, job perdido, worker interrompido). Módulos não aplicáveis continuam sem gerar reserva. `ReleaseReservedCreditsJob::isChargeable()` + `docs/34` + README atualizados; testes renomeados/ampliados (12 cenários, incluindo "módulo sem resultado é cobrado", "módulo não aplicável não gera reserva" e "reserva sem execução é estornada").
2. **Holehe como as demais fontes** — driver padrão passou de `mock` para **`process`** (`config/sources.php`), alinhado a Maigret/Sherlock/WhatsMyName (a suíte de testes segue em `mock` via `phpunit.xml`). Decisão de licença registrada em `docs/30-holehe-integracao.md`, no docblock do adapter, no `.env.example` e no README: a ferramenta roda como **processo separado** e o serviço é acessado pela rede, sem distribuição do binário — a GPL-3.0 não impõe copyleft ao código da plataforma; as obrigações passariam a valer apenas se a imagem com o binário fosse distribuída a terceiros. As salvaguardas técnicas permanecem: `--no-password-recovery` fixo, nenhum dado de recuperação lido/persistido e o aviso de que a indicação de cadastro não prova controle da conta.



## Etapa 41 — Investigação avulsa (sem cadastro)

> Executada antes da etapa 40 (auditoria E2E com as ferramentas reais), que foi
> executada logo em seguida; por isso a documentação desta etapa é o `docs/35` e a
> da etapa 40 é o `docs/36`.

- **Decisão do cliente (billing da avulsa)**: consulta avulsa é **pagamento fixo
  por consulta fixa, sem consumo de crédito** — "pagou, usou, acabou". Nenhuma
  alteração em `credit_reservations`, `credit_transactions` ou carteiras; a
  evidência financeira é `payments` (+ `payment_events`) com o preço congelado em
  `investigations.guest_price_cents`.
- **Preço configurável sem deploy**: `credit_packages.kind` (`credits` |
  `guest_investigation`) reusando o CRUD `/admin/packages`; a linha da avulsa é
  única, criada pelo seeder (R$ 29,90 por padrão), editável e não removível; o
  segmento "Pacotes de créditos" continua restrito a créditos.
- **Banco (4 migrations)**: `credit_packages.kind`; `investigations` com `user_id`
  nullable + `origin` + `access_token_hash` (único) + `access_token_created_at` +
  `access_token_expires_at` + `guest_price_cents` + índice `(origin,status)`;
  `payments` com `user_id` nullable + `guest_investigation_id` (único, FK);
  `reports.user_id` nullable. Nenhum estado novo: `draft` → `queued` → `running` →
  `completed`/`partial`/`failed`, com `expired` para rascunho não pago.
- **Sem execução antes do pagamento**: o run nasce `pending` e **não é despachado**;
  `GuestInvestigationService::release()` (chamado pelo `PaymentService` na
  confirmação do pagamento) libera e despacha; o `StartInvestigationRunJob`
  revalida o pagamento (`unpaid_guest_investigation`) e não reserva créditos
  (`funding = guest_prepaid`); o `ReleaseReservedCreditsJob` faz curto-circuito na
  avulsa. A regra das contas (pós-39) permanece intacta.
- **Token como única credencial**: 256 bits (`random_bytes(32)`), persistido só
  como SHA-256; rotas `/investigacao/acesso/{token}` (+ `/status`, `/relatorio`,
  `/pix/confirmar` apenas com provider fake), CSRF ativo, `no-store`/`noindex`
  (`GuestAccessHeaders`), rate limit `guest-create`/`guest-access`; rotas
  autenticadas respondem 404 para avulsa (não-admin) e a policy nega `view`/`rerun`.
- **Expiração**: `guest:expire-unpaid` a cada 5 minutos (`config/guest.php`,
  padrão 1440 min) marca rascunho + pedido como `expired` (evento idempotente) sem
  executar; pagamento tardio (webhook) ainda executa o que foi pago.
- **UI pública**: `layouts/public.blade.php` (`<x-public-layout>`), formulário
  `/investigacao` e página de acompanhamento com polling, reusando o mesmo
  relatório/manifesto (`reports/show.blade.php` com layout dinâmico + `backUrl`).
- **Admin**: preço da avulsa em `/admin/packages`; `/admin/investigations` com
  filtro Todas/Contas/Avulsas, origem, preço e pagamento (`Sem conta`);
  `/admin/payments` com tipo Créditos x Avulsa e vínculo; token/hash nunca exibidos.
- **Testes**: `GuestInvestigationTest` (8), `GuestPaymentTest` (5) e
  `GuestSecurityTest` (7) — criação sem conta, nada executa sem pagamento, liberação
  e relatório pelo token, idempotência de pagamento/liberação, expiração, pagamento
  tardio, isolamento entre tokens, rate limit, cabeçalhos e painel; carteira/ledger
  de contas intactos. `DemoDataSeederTest` e `ReferenceDataSeederTest` ajustados
  (3 pacotes de crédito + 1 preço avulso).
- **Validação**: `php artisan test` — 301 passando, 4 ignorados (reais gated), 1310
  assertions; `./vendor/bin/pint --test` — PASS (255 arquivos); 56 rotas.


## Etapa 40 — E2E real e auditoria

> Executada após a etapa 41 (a etapa 41 assumia o prompt 40 concluído, mas os
> estágios 25–41 estavam sem commit); a auditoria real foi feita em seguida, com o
> repositório já commitado. Documento: `docs/36-e2e-real-auditoria.md`.

- **Jornada completa com as 4 integrações reais** (MySQL + Redis + worker Docker):
  investigação 26 / run 27 → maigret 18 resultados em 10.572 ms (0.6.6), sherlock 10
  em 3.801 ms (0.16.2), whatsmyname 13 em 5.353 ms (dataset `062bcfe4…`), holehe 0
  em 3.053 ms (1.61); 0 falhas, 0 timeouts, 0 retries.
- **Pipeline completo**: 41 resultados → 28 descobertas (13 duplicatas em 9 grupos),
  41 evidências, correlação score 40 (`internal_correlation_indicator`,
  `identity_asserted = false`), relatório `report-manifest-v1` (`e9496ce1debb…`) com
  8 limitações — incluindo os avisos reais do Maigret (`Bot protection` 10,53%,
  `Access denied` 5,26%, 6 sites não avaliados) expostos ao usuário; nenhum bypass.
- **Consumo**: 8 créditos reservados e 8 consumidos (cobra a consulta executada),
  carteira 50 → 42, soma do ledger 42 (invariante), 0 reservas pendentes.
- **Auditoria automatizada**: `RealPipelineAuditTest` (gated `OSINT_E2E=1`), ~99–100
  assertions, força os adapters reais por `SourceRegistry::register()` + `healthCheck()`
  e grava `storage/app/osint-audit/e2e-real-*.json` (duração, resultados, falhas,
  tentativas, versões, consumo e resultado final por módulo).
- **Docs**: `36-e2e-real-auditoria.md` (novo), `AUDIT.md`, `README.md` e nota de
  sequência em `35-investigacao-avulsa.md`. Nenhum código de produção foi alterado.
- **Testes**: 301 passando + 5 ignorados (4 integrações reais + auditoria); Pint PASS
  (256 arquivos).


## Etapa 42 — Redesign premium e conversão

- **Direção de design própria**: tokens Tailwind v4 (`ink`/`mist`/`signal`),
  superfícies premium, HUD discreto, microanimações leves e `prefers-reduced-motion`;
  tipografia unificada em Instrument Sans (figtree removido das layouts).
- **Landing reformulada** (`/`): hero "INVESTIGUE UMA PRESENÇA DIGITAL", CTA para a
  jornada avulsa sem cadastro, **preço real do banco**, cobertura de plataformas
  verificada nos catálogos das fontes ativas (WhatsApp explicitamente **fora do
  escopo**), "como funciona", planos de créditos, o que vem no relatório, FAQ e CTA
  final; SEO/OG básicos e `index,follow` só na landing.
- **Jornada avulsa premium**: formulário com etapas e resumo de preço; PIX com
  copiar; **processamento em etapas derivadas do estado real** (✓/◉/◌, sem
  percentuais inventados); estado por fonte; **prévia de descobertas reais**
  (plataforma, título, URL, confiança, horário) com skeleton/estado vazio; polling
  que recarrega apenas quando há progresso real.
- **Relatório premium**: mesmos dados/manifesto, com KPIs reais, tabela de fontes
  (versão/tentativas/duração), descobertas com evidências, correlação explicada,
  falhas e limitações visíveis e rodapé com hash do manifesto (print-friendly).
- **Área autenticada e admin harmonizados** com o mesmo mapa de tokens; navegação
  autenticada com Painel/Investigações/Nova/Créditos/Conta e menu mobile.
- **Componentes reutilizáveis**: `x-btn`, `x-card`, `x-badge`, `x-status-badge`,
  `x-stat`, `x-alert`, `x-section-heading`, `x-step-indicator`, `x-source-status`,
  `x-platform-chip`, `x-empty-state`, `x-skeleton`; componentes Breeze re-skinados
  sem mudar APIs; view da avulsa refatorada em partials.
- **Sem mudanças de backend/pipeline/segurança**: única adição é leitura de estado
  (`GuestInvestigationService::progress()` com descobertas/preview) e
  `config/platforms.php`; nenhuma rota nova (apenas o **nome** `home` na raiz).
- **Testes**: `ExampleTest` virou smoke test da landing (hero, CTA, honestidade do
  WhatsApp); `LocalizationTest` ajustado; suíte **301 passando** + 5 ignorados,
  Pint PASS (256 arquivos). Detalhes, problemas encontrados e melhorias futuras em
  `docs/37-redesign-premium.md`.

## Etapa 43 — Custo em créditos por requisição configurável

- **Auditoria** (`docs/38-custo-creditos-configuravel.md`): o custo já vivia em
  `source_definitions.cost_credits` (reserva por módulo com transação+lock e
  chave `run-{id}-source-{slug}-reserve`, consumo em
  `ReleaseReservedCreditsJob`, estorno do nunca consultado), mas **não era
  editável** no painel e o `SourceDefinitionSeeder` sobrescrevia o valor em
  reseeds. Valores encontrados: mocks = 1; maigret/sherlock/whatsmyname/holehe = 2.
- **Configuração pelo admin**: `PATCH /admin/sources/{source}/cost` +
  `AdminSourceCostRequest` (inteiro, `0..1000`, `authorize()` revalida admin) e
  campo editável em `/admin/sources` com a última alteração visível (data, quem,
  de -> para). Toda alteração registra `admin.source_cost_update` no
  `AuditLogger` existente (ator, IP, slug, valores anterior/novo) — sem novo
  sistema de auditoria e sem migration.
- **Histórico preservado**: a reserva de todos os módulos acontece na abertura
  do run e congela o valor em `credit_reservations.amount`; alterar 10 -> 25
  vale só para execuções futuras. O run passa a gravar
  `metadata.credit_cost` (`total`, `breakdown`, `snapshot_at`) na abertura —
  auditável também para módulos de custo 0 e para a avulsa.
- **Zero créditos**: comportamento explícito — sem reserva, executa e finaliza
  com `credits_reserved = credits_consumed = 0`.
- **Avulsa (prompt 41) intacta**: mesma coluna de custo, nenhuma wallet/reserva/
  ledger e preço financeiro não tocado; custo interno registrado em
  `metadata.credit_cost` (`executed_credits` no encerramento, mesma regra de
  módulo consultado) apenas para controle/auditoria.
- **Seeder**: `cost_credits` gravado apenas na criação da linha (como `active`)
  — reexecutar a seed não reverte o valor administrado.
- **Regra de cobrança não alterada** (como determina o prompt): vigente é a da
  consulta executada (prompt 39/docs 34); a divergência textual do prompt 43
  ("FAILED/TIMEOUT -> não cobra") fica registrada em `docs/38`.
- **Testes**: `AdminSourceCostTest` (7 cenários: alteração+auditoria,
  autorização, validação, custo novo só em execução nova com ledger consistente,
  custo zero, custo interno da avulsa, re-seed). Suíte **309 passando + 5
  ignorados** (integrações reais gated), Pint PASS (259 arquivos).

## Etapa 44 — Identidade verbal da marca Olho

- **Marca**: `APP_NAME="Olho"` (`.env`/`.env.example`); assinatura
  **"Você dá a pista. O Olho procura."**; voz curta e de ação, sem promessas,
  sensacionalismo ou "olho de Deus" como afirmação. Guia completo em
  `docs/39-identidade-verbal.md`.
- **Landing**: novo hero **"VOCÊ DÁ A PISTA. / O OLHO PROCURA."** (variações
  avaliadas: pergunta e lista), CTA principal **INVESTIGAR AGORA** (hero, preço
  e CTA final) e `DEIXAR O OLHO PROCURAR` como contextual; seção
  **"O QUE O OLHO PROCURA?"** com 01 Username · 02 Redes sociais · 03 E-mail ·
  04 Web · 05 Conexões; microcopies de curiosidade por plataforma
  (`platform-chip` ganhou o prop **hook**) com Instagram em destaque; WhatsApp
  segue **fora do escopo** (mensagens privadas nunca são acessadas).
- **Jornada avulsa**: "O que você quer investigar?" + "O Olho vai procurar
  sinais públicos…"; processamento com etapas na voz do Olho mapeadas a estados
  **reais** e título derivado do estado ("O Olho está esperando a sua pista" →
  "O OLHO ESTÁ PROCURANDO..." → "O QUE O OLHO ENCONTROU"); pagamento
  "Sua investigação está pronta para começar" + CTA **LIBERAR INVESTIGAÇÃO**;
  descobertas "O Olho encontrou :count descobertas" e, sem resultado,
  "O Olho não encontrou ocorrências nas fontes consultadas." com a ressalva de
  que isso **não** significa ausência da pessoa.
- **Relatório/painel**: "RELATÓRIO OLHO" + "Mapa de descobertas" e seções em
  caixa alta (RESUMO, ENTRADAS INFORMADAS, FONTES CONSULTADAS, DESCOBERTAS,
  POSSÍVEIS CONEXÕES, FALHAS E LIMITAÇÕES) sem tocar nos dados; painel
  autenticado com header "Painel".
- **SEO**: title "Olho — investigação de presença digital", description e
  Open Graph/Twitter (`og:site_name`, `twitter:card`…) com termos naturais
  (presença digital, username, redes sociais, fontes públicas) — sem stuffing.
- **Sem backend**: nenhum adapter, job, fila, crédito, pagamento, banco ou regra
  de correlação foi alterado; também removido um botão duplicado (artefato) na
  página de criação guest e restaurado o card de transparência.
- **Testes**: copy atualizada em `ExampleTest`, `LocalizationTest`, `ReportTest`
  e `SourceRunPersistenceTest`; suíte **309 passando + 5 ignorados**, Pint PASS
  (259 arquivos); verificação manual HTTP de landing, `/investigacao`, `/login`
  e `/up`.


## Etapa 45 — PagBank: dados do pagador e diagnóstico de recusa

- **Sintoma**: em teste real (sandbox) o PIX e o cartão devolviam
  `PagBank: falha ao criar checkout (HTTP 400). Requisição rejeitada pelo
  PagBank: revise o token e os métodos habilitados.` — mensagem que mandava
  revisar o que estava correto (o teste de conexão passava) porque o corpo da
  resposta era descartado por decisão de privacidade.
- **Diagnóstico do `400`**: `PagBankException` passa a extrair
  `error_messages[]` (`error` + `parameter_name`, os enums/caminhos de campo da
  doc oficial) e a citá-los na mensagem (`O PagBank apontou: invalid_value em
  customer.tax_id`), sanitizados e **sem ecoar o corpo** (que pode carregar dados
  do comprador); o texto do `400` deixou de culpar o token. A última falha de
  cobrança é registrada por `PaymentService` (`payment.pagbank.last_checkout_error`,
  **depois** do rollback da transação), aparece em `/admin/settings/payments` e é
  limpa quando uma cobrança é aceita; o provedor registra
  `payment.pagbank.failed` (com `payment_id`, status e `error`/`parameter_name`) e
  o comprador recebe uma mensagem acionável em vez de `500`.
- **Dados do pagador (causa provável da recusa)**: o PagBank identifica quem paga
  pelo objeto `customer` — `tax_id` é **obrigatório** na API de Pedidos (Criar
  pedido com QR Code PIX) e o Checkout/Link de Pagamento recebe o mesmo dado.
  Os dois payloads passam a enviar `customer` (nome + CPF/CNPJ; e-mail só na
  faixa aceita: 10–60 no Checkout, 10–255 nos Pedidos, ver `PixPayer`).
  - **Cadastro**: `users.tax_id` (documento **cifrado**, validado pelo dígito
    verificador via `App\Rules\TaxId`, nome com sobrenome), máscara na interface,
    campo editável no perfil (em branco preserva o atual) e remoção na exclusão de
    conta (`AccountDeletionService`) — migration
    `2026_10_02_000230_add_tax_id_to_users_table`.
  - **`/credits`**: o modal do PIX abre com os dados do comprador preenchidos;
    o cartão envia os mesmos dados em campos ocultos e fica bloqueado, com link
    para o perfil, quando a conta ainda não tem o documento.
  - **Avulsa**: o formulário público ganha "Seus dados (quem paga)" quando o
    provedor ativo exige (`payer_name`/`payer_tax_id`/`payer_email`, **nunca
    persistidos**): sem eles a investigação não é criada, em vez de o provedor
    recusar a cobrança.
- **URL de notificação**: `notification_urls`/`payment_notification_urls` só
  entram no payload quando a `APP_URL` é HTTPS público — em desenvolvimento o
  PagBank não alcança o endereço local. O painel avisa e a confirmação passa a
  depender do retorno do comprador (`payments.return`, que reconsulta o provedor).
- **Especificação**: `docs/41-pagbank-dados-do-pagador.md`.
- **Testes**: `PagBankProviderTest` (13), `CreditCheckoutTest` (17),
  `GuestPagBankPaymentTest` (8), `AdminPaymentSettingsTest`, `RegistrationTest`
  (7); suíte **374 passando + 5 ignorados**, Pint PASS (285 arquivos) e
  `npm run build` OK.


## Etapa 46 — Modo de desenvolvimento rápido e validação manual

- **Diretriz de processo** (sem alteração de código de produto): a partir desta
  etapa, o fluxo por prompt passa a priorizar **implementação → verificações
  rápidas → entrega para validação manual**. A validação funcional completa
  (PHPUnit, Feature/Unit, E2E, integrações reais, auditoria) é concentrada em uma
  **etapa final dedicada** ou executada quando o usuário pedir explicitamente.
- **Regra codificada em**: novo `.cursor/rules/05-fluxo-desenvolvimento-rapido.mdc`
  (alwaysApply) e ajuste de `.cursor/rules/04-testes.mdc`, `AGENTS.md`,
  `CLAUDE.md`, `docs/17-testes.md` e das regras-espelho do pacote de spec
  (`.cursor/rules/05-testing.mdc`, `.cursor/rules/08-cline-execution.mdc`).
- **Não fazer automaticamente**: suíte completa, todos os Feature/Unit, E2E,
  auditorias, integrações externas reais (PagBank, webhooks, Maigret, Sherlock,
  WhatsMyName, Holehe), investigações reais e execução prolongada de workers.
- **Testes antigos que falham por motivo não relacionado**: identificar a causa,
  não alterar código antigo apenas para "fazer o teste passar" e registrar no
  relatório final.
- **Preservação**: nenhum teste foi removido; PHPUnit, E2E e auditorias
  permanecem intactos — apenas deixam de rodar automaticamente a cada etapa.
- **Relatório final**: passa a exigir arquivos alterados, o que foi implementado,
  verificações rápidas feitas, aviso de que os testes completos **não** foram
  executados e uma lista objetiva de **TESTAR MANUALMENTE**.


## Etapa 47 — Checkout PagBank sem dados do pagador (PIX e cartão)

- **Decisão**: o OLHO deixa de pedir nome e CPF/CNPJ para pagar. Pela doc oficial
  do PagBank, no objeto **Checkout** o `customer` só é obrigatório quando
  `customer_modifiable = false`; com o valor padrão (`true`) é a **página
  hospedada** que coleta os dados do comprador. O checkout passa a ser criado com
  `customer_modifiable: true` e **sem** o objeto `customer`.
- **Removido do produto**: coluna `users.tax_id` (migration
  `2026_10_02_000240_drop_tax_id_from_users_table`), `App\Rules\TaxId`,
  `App\Payments\PixPayer`, `DirectPixProvider::requiresPayerDocument()`,
  `CheckoutProvider::requiresPayerIdentity()`,
  `PaymentService::payerIdentityRequired()` e os campos de documento/`payer_*` do
  cadastro, perfil, modal de PIX e formulário da avulsa.
- **Fluxo resultante**: cadastro com nome, e-mail e senha; `/credits` (PIX e
  cartão concluídos na página hospedada do PagBank — o modal de PIX redireciona
  quando o provedor não emite o código na aplicação; o provedor `fake` mantém o
  copia e cola) e `/investigacao` (formulário de identificação + botão de
  pagamento) **sem nenhum campo de pagador**. O CPF/CNPJ é digitado pelo próprio
  pagador no ambiente do PagBank — o OLHO não pede nem recebe.
- **Diagnóstico do `400`** (etapa 45) permanece: `error_messages` na mensagem, no
  log e no painel; o texto do `400` deixou de citar "dados do pagador".
- **Docs**: `docs/40` (seção "Atualização") e `docs/41` (banner de decisão final +
  seções "vigente") reconciliados com o código.

## Ajustes pós-etapa 47 — botão de pagamento direto no PagBank (teste manual)

- **Causa raiz do "botão que não faz nada"** (investigação avulsa ficava sem
  pagamento): o PagBank **valida** as URLs do payload e recusa a cobrança inteira
  com `400 invalid_value em redirect_url; invalid_value em return_url` quando
  apontam para `http://localhost:8080` (`APP_URL` local). Nenhum checkout era
  criado e não havia link para pagar.
- **Correção**: `PagBankProvider::buildCheckoutPayload()` só envia `redirect_url`
  e `return_url` quando `PaymentSettings::webhookIsPublic()` é verdadeiro — a mesma
  regra que já valia para `notification_urls()`; sem endereço público o comprador
  paga na página do PagBank e a confirmação fica com a reconciliação
  (`payments.return`/`payments.show`), que consulta a API antes de liberar.
- **Modal de PIX removido** em `/credits`: o botão virou um `POST` de formulário
  (`channel=pix|credit_card`) que **redireciona direto** para o checkout hospedado
  (`redirect()->away($checkout_url)`), sem Alpine nem JSON. `PaymentController::store()`
  passou a devolver sempre `RedirectResponse`; a falha do provedor volta para a
  tela com `back()->with('error', ...)`.
- **PIX do provedor local (`fake`)**: continua abrindo a página do pedido
  (`payments.show`) com o copia e cola e o acompanhamento — o OLHO não perde a
  emissão própria quando não há checkout hospedado.
- **Pagamento em outra aba**: os dois botões de `/credits` passaram a ser
  formulários com `target="_blank"` (a especificação HTML já implica `noopener`
  em formulários) — a tela de créditos fica aberta e o comprador volta, recarrega
  e vê o saldo no painel. O mesmo vale para o botão "PAGAR COM PAGBANK" na página
  do pedido e na página pública da avulsa, cuja URL é o único acesso do comprador
  à investigação. A página avisa ("O pagamento abre em outra aba").
- **Saldo na tela de créditos**: `/credits` passou a exibir o saldo atual
  (lido da carteira no servidor, nunca somado no navegador) ao lado do aviso —
  voltar e recarregar já mostra os créditos comprados.
- **Erro visível**: o aviso de falha do pagamento na avulsa passou a aparecer
  **acima** do botão "Revisar e pagar" (antes ficava depois dele, fora da dobra —
  dava a impressão de que o clique não fazia nada).
- **Painel**: o aviso de URL não pública agora explica que `notification_urls`,
  `redirect_url` e `return_url` ficam de fora e que a confirmação depende da
  reconciliação (antes falava só do "retorno do comprador").
- **Verificação de ponta a ponta**: script temporário (`storage/app/diag-pagbank.php`,
  removido depois) chamou o checkout real no **sandbox** para conta e avulsa —
  ambas retornaram `awaiting_payment` com `checkout_url` do PagBank e
  `lastCheckoutFailure()` limpo. O diagnóstico falso-próximo do fluxo era o
  `invalid_value` das URLs, não a credencial.
- **Testes**: `CreditCheckoutTest` (botão PIX via formulário, redirect para o
  PagBank e para o pedido local, erros por sessão), `PagBankProviderTest`
  (`redirect_url`/`return_url` só com URL pública HTTPS) e `AdminPaymentSettingsTest`
  (novo texto do aviso) atualizados.
- **Docs**: `docs/40` e `docs/41` atualizados com a regra das URLs de retorno, a
  causa raiz confirmada e a remoção do modal.

## Ajustes pós-etapa 47 — Holehe: bug do `--timeout` e falha silenciosa

- **Causa raiz (teste manual do módulo Holehe com e-mail real)**: a execução
  retornava `SUCCESS` com **0 resultados** para um e-mail cadastrado em vários
  serviços. O `argparse` do Holehe 1.61 declara `-T/--timeout` **sem**
  `type=int`, então a string (`"10"`) chegava ao `httpx.AsyncClient(timeout=...)`
  e o backend trio/anyio estourava `TypeError: '<' not supported between
  instances of 'str' and 'int'` nas requisições que passam pela validação de
  timeout — cada módulo que estoura é gravado como `rateLimit = True`, ou seja,
  o CSV dizia "nenhum cadastro" mesmo para um e-mail que existe. Medido no
  container, mesmo e-mail: `--csv --timeout 10` → 0 encontrados / 116 de 117
  bloqueados; `--csv` (timeout interno padrão) → 3 encontrados (replit, spotify,
  twitter) / 74 bloqueados; `--csv --timeout 10` **com o patch** → os mesmos 3
  encontrados / 72 bloqueados.
- **Correção**: `docker/php/Dockerfile` aplica o `type=int` que o upstream
  pretendia (`sed` + `grep` de verificação — o build falha se o padrão não
  existir mais, em vez de deixar o bug voltar em silêncio). É a única alteração
  no binário.
- **Fim da falha silenciosa**: `HoleheReportParser::summarize()` passou a expor o
  panorama do relatório (`checked`/`found`/`unavailable`); o adapter publica
  `metadata.sites_checked` e `metadata.sites_unavailable`, exibidos no painel do
  teste ("Serviços verificados · sem resposta (bloqueio/erro)").
- **Desfecho honesto**: quando ≥95% dos sites não puderam ser verificados **e**
  nenhum cadastro apareceu (relatórios com menos de 20 sites ficam fora da
  regra), o adapter lança `unavailable` em vez de devolver lista vazia — uma
  consulta não concluída deixa de ser apresentada como "e-mail sem cadastros".
  Bloqueio alto porém parcial (74 de 117, o normal em IP de datacenter) continua
  sendo resultado vazio legítimo.
- **Cache**: `ADAPTER_VERSION` do Holehe foi para `v2` (a versão faz parte da
  chave do `SourceCache`), então resultados vazios gravados com o bug não são
  reaproveitados por 1 h de TTL.
- **Testes**: `HoleheReportParserTest` (panorama no fixture e relatório vazio),
  `HoleheAdapterTest` (falha em massa → `unavailable`, bloqueio alto parcial →
  resultado vazio válido, `sites_checked` no caminho feliz) e
  `AdminModuleTestPanelTest` (panorama na tela de detalhe).
- **Docs**: `docs/30-holehe-integracao.md` com a causa raiz, as medições, a
  defesa contra a repetição e o cuidado com `HOLEHE_VERSION` futuro (o patch é
  local e o build avisa quando ele deixa de se aplicar).

## Ajustes pós-etapa 47 — Instagram: falha no painel de testes e foto no detalhe

- **Causa 1 — sessão não configurada era cobrada e aparecia como erro
  genérico**: o adapter real lançava `SourceUnavailableException` quando não há
  `sessionid` salvo — os testes #1/#2 do módulo terminaram `FAILED` com
  "A fonte está indisponível no momento." e **2 créditos consumidos** por uma
  consulta que nunca aconteceu. Agora lança `SourceSessionException` (`Skipped`,
  reserva **estornada**, igual a sessão expirada/desafio — o comportamento que o
  `ModuleTestRunner` e o `ReleaseReservedCreditsJob` já documentavam).
- **Causa 2 — imagem desatualizada sem o `instagrapi`**: o `Dockerfile` da etapa
  48 instala `instagrapi==2.x`, mas a imagem em execução era anterior ao build —
  `python3 -c "import instagrapi"` falhava com `ModuleNotFoundError`. Imagem
  reconstruída (`docker compose build app`) e containers recriados; o build
  confirma a camada com `import instagrapi`.
- **Mensagem do painel (audiência admin)**: novo
  `SourceErrorMapper::toOperatorMessage()` — o teste individual passa a exibir a
  mensagem curada da própria exceção ("Sessão do Instagram não configurada.
  Informe o sessionid em Configurações → Integrações."), enquanto
  `source_runs.error_message_public` (relatórios/audiência pública) mantém o
  texto neutro. `toPublicMessage()` ganhou casos para `session_invalid` e
  `challenge_required` em vez do default genérico.
- **Foto no detalhe do teste (prompt 48 §6/§22)**: a seção "Resultados
  encontrados" agora renderiza a captura de imagem — a evidência `avatar` vira
  `<img>` (somente URL HTTPS, `loading="lazy"`, `referrerpolicy="no-referrer"`,
  link para abrir em nova aba) e, para achado do Instagram **sem** foto, exibe
  "Imagem pública não encontrada" (nunca foto fictícia). O chip textual do
  avatar deixa de duplicar a URL quando a imagem já está visível.
- **Correção do Blade**: o arquivo usava `@php(expr)` inline e recebeu um bloco
  `@php ... @endphp` — o `storePhpBlocks()` do Blade casa `@php ... @endphp`
  desde o **primeiro** `@php` do arquivo, o que engolia o miolo da view (erro de
  sintaxe "unexpected token class"). Volto-se à forma inline, que é a
  convenção deste arquivo.
- **Testes**: `InstagramAdapterTest` (sessão ausente → `SourceSessionException`
  + orientação + nenhum processo executado), `InstagramIntegrationTest`
  (imagem `<img>` na tela; mensagem operacional vs. pública no caso
  `no_session`), `AdminModuleTestPanelTest` (achado sem foto → "Imagem pública
  não encontrada") e `SourceAdapterTest` (mensagens públicas/operador de
  `session_invalid`/`challenge_required`).
- **Para testar de verdade**: configurar o `sessionid` em
  Configurações → Integrações (conta burner) e rodar o teste do Instagram em
  `/admin/testes` — sem credencial o teste agora sai `SKIPPED` com estorno e a
  orientação de configuração, sem cobrar crédito.

## Ajustes pós-etapa 47 — PIX/cartão aprovado não liberava (webhook ausente)

- **Causa raiz (sandbox real)**: depois do cartão/PIX aprovado, a consulta
  `GET /checkouts/{CHEC_...}` continua devolvendo `status: ACTIVE` e **sem** o
  array `charges`. A cobrança paga (`CHAR_...`, `PAID`) só existe no **pedido**
  do checkout (`orders[].id` → `ORDE_...`). Como a confirmação dependia de
  `charges` no checkout (ou do webhook, que não chega em `localhost`), o pedido
  ficava `awaiting_payment` para sempre e os créditos nunca eram liberados.
- **Correção**: `PagBankProvider::withChargesFromOrders()` +
  `orderCharges()` seguem `orders[].id` por `GET /orders/{id}` (id devolvido pelo
  provedor, na API oficial — **nunca** seguindo `links.href` do payload) e usam
  as cobranças do pedido em `getCheckoutStatus()`, `getCheckoutMethod()` e no
  webhook. Pedido indisponível mantém `awaiting_payment` (nunca confirmamos por
  ausência de resposta).
- **Sincronização forçada**: `php artisan pagbank:sync-payment <ID|olho-ID|CHEC_|ORDE_|idempotency_key>`
  (`--dry-run`, `--force`) e o botão **Sincronizar** por pedido em
  `/admin/payments` (POST `admin.payments.sync`, `auth`+`admin`, rate limit
  `admin-sync` 30/min) — reconsultam o provedor e confirmam o que já estiver pago.
- **Novo: varredura de todos os pendentes** — `pagbank:sync-payment --pending`
  percorre todos os `provider = pagbank` em `awaiting_payment` (teto de 100 por
  execução, uma linha por pedido, resumo no fim; falha em um pedido não
  interrompe os demais) e o botão **Sincronizar pendentes** no topo de
  `/admin/payments` (POST `admin.payments.sync-pending`, teto de 25 por clique,
  resumo no flash). `PaymentService` ganhou `pendingPagBank()` e
  `reconcilePendingPagBank()`; a confirmação segue idempotente
  (`payment_events.external_event_id = reconcile-paid-{CHEC_...}`).
- **Auditoria**: `payment_events.external_event_id` distingue a origem da
  confirmação — `reconcile-paid-...` (sincronização forçada), `sim_...` (provedor
  fake) ou o sha1 de 40 dígitos `sha1(checkoutId|status|chargeId)` (webhook).
  `grep payment.webhook storage/logs/laravel.log` mostra se a notificação chegou
  (`payment.webhook.received`) ou foi rejeitada (`payment.webhook.rejected`).
- **Verificação de ponta a ponta (sandbox)**: PIX de R$ 79,90
  (`CHEC_DBBB022B-...`, cobrança `CHAR_E63D6F8C-...` `PAID` no pedido
  `ORDE_5AC8A61C-...`) seguia `awaiting_payment` porque o webhook não chegou;
  `pagbank:sync-payment --pending` confirmou o pedido, gravou `method = PIX` e
  creditou 500 créditos (`payment-paid-11`, saldo 763 → 1363, junto dos 100 do
  `#9` também confirmado). A varredura repetida liberou 0 novos
  (mesma transação no ledger) e os checkouts `#6`/`#7` seguem pendentes porque o
  PagBank os devolve como `expired` — a reconciliação só **persiste** estado
  conclusivo de pagamento.
- **Testes**: `PagBankProviderTest` (status/método pelo pedido do checkout, pedido
  indisponível mantém `awaiting_payment`, webhook confirmado pelo pedido) e
  `PagBankSyncTest` (varredura `--pending` com um pago/um pendente/um de outro
  provedor, idempotência, `--dry-run`, falta de argumento, combinações inválidas,
  botão do painel com resumo no flash e restrição a admin). Suíte: `447` testes,
  `5` skipped.
- **Docs**: `docs/40-pagbank-configuravel.md` (webhook item 3, reconciliação,
  seção "Sincronização forçada" com a varredura e o roteiro "Como conferir todos
  os pagamentos e se o webhook chegou", arquivos e testes).

## Ajustes pós-etapa 47 — webhook que chega e não conclui: verificação no worker

- **Decisão**: a confirmação não depende mais de um único momento. Quando o
  webhook **chega** e não consegue concluir, o pedido passa a ser verificado no
  **worker** (`ReconcilePaymentJob`) em vez de ficar pendente esperando ação do
  operador. Dois gatilhos, em `PaymentService::handleWebhook()`:
  - **erro na reconsulta ao provedor** (rede, `5xx`, `429`, timeout): a exceção
    continua subindo (resposta `5xx`, então o PagBank **também** reentrega a
    notificação) e o pedido é agendado com `reason = webhook-error`;
  - **notificação autenticada que não liberou nada** (status resolvido como
    aguardando: o pedido do checkout demora a aparecer, ou a consulta degradou —
    ex.: `payment.webhook.charge_unavailable`): `payment.webhook.inconclusive` e
    o job com `reason = webhook-inconclusive`.
- **`ReconcilePaymentJob`**: recebe **apenas o id** do pagamento (nenhum modelo
  serializado), é `ShouldBeUnique` por pagamento (`uniqueFor` de 10 min: rajada de
  reentregas não vira fila de consultas), roda no worker com `tries = 3`,
  `backoff = [60, 300]` e atraso de 1 min no agendamento (não reconsulta no mesmo
  instante em que a consulta falhou). Uma passada por execução: `reconcile()`
  idempotente libera créditos da conta ou a investigação avulsa. Logs:
  `payment.reconcile.job` (concluiu), `payment.reconcile.skipped` (já pago, outro
  provedor, sem cobrança criada, pagamento inexistente — **nenhuma** chamada à
  API), `payment.reconcile.failed` (provedor indisponível nas 3 tentativas; o
  pedido segue pendente e os outros caminhos continuam valendo). **Estado ainda
  aguardando não é falha**: o job termina normalmente.
- **Pedido do OLHO a partir da notificação**:
  `PaymentService::paymentForWebhook()` resolve `external_id`/`provider_checkout_id`
  (e `metadata.ps_order_id` numa notificação de cobrança `CHAR_`) — sempre pelo id
  que o provedor devolveu, nunca por `links.href`; id desconhecido grava
  `payment.webhook.confirm_unmatched` e nada é agendado (o payload não cria
  pedido).
- **Painel `/admin/payments`**: novos botões que usam o **worker** em vez de
  consultar a API na requisição — **Revalidar** na coluna Ações (só para pedido
  PagBank não pago e com cobrança criada) e **Revalidar pendentes** no topo
  (teto de 25 por clique, `queuePendingPagBank()`) —, com o resumo no flash. As
  rotas novas (`admin.payments.revalidate` e `admin.payments.revalidate-pending`)
  seguem exclusivas de admin com o rate limit `admin-sync`; fora do PagBank ou sem
  cobrança a resposta é `422`.
- **Correção de UX no painel**: os botões **Sincronizar**/**Sincronizar
  pendentes** são formulários HTML e devolviam o **JSON** cru na tela; agora
  respondem com **flash na própria lista** ("Pagamento #N confirmado no PagBank —
  créditos liberados." / "… segue pendente (provedor responde \"expired\").") e
  continuam devolvendo JSON para `Accept: application/json` (API/testes). O
  endpoint também passou a usar a constante `PaymentSettings::PROVIDER_PAGBANK`.
- **Testes**: `tests/Feature/PaymentReconcileJobTest.php` (12 cenários: webhook
  inconclusivo e webhook com erro agendam o job, id desconhecido não agenda, o job
  confirma e credita uma única vez, libera a avulsa, não gasta chamada à API para
  pedido já pago/outro provedor, botões Revalidar/Revalidar pendentes com restrição
  a admin e `422`, botão na lista e flash do Sincronizar). Suíte: `459` testes
  (`454` passed + `5` skipped), `2046` asserções; `pint --test` sem apontamento nos
  arquivos desta etapa.
- **Docs**: `docs/40-pagbank-configuravel.md` (nova seção "Webhook que chega e não
  conclui: verificação no worker", botões do worker na seção de sincronização,
  passo 4 do roteiro de conferência, arquivos e testes) e `docs/07-pagamentos.md`.
- **Fora de escopo (registrado)**: persistir `expired`/`cancelled` no banco,
  varredura agendada (cron) dos pendentes e alarme de pedido parado — o worker
  disparado pelo próprio webhook (e pelos botões) cobre o caminho principal.

