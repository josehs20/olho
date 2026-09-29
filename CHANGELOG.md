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
