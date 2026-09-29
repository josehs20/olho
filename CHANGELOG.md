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
