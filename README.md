# Investigação Pública

Plataforma Laravel de investigação de **presença digital pública** (OSINT). O usuário compra créditos e os usa para executar módulos de pesquisa sobre informações públicas. Resultados apontam para fontes/evidências; uma correspondência não é identidade confirmada.

## Stack

- Laravel 13 · PHP 8.5 (Docker)
- Breeze (Blade/Tailwind) · MySQL 8.4 · Redis (cache + filas)
- Workers Docker · PIX fake (`FakePixProvider`) · OSINT com mocks no MVP

## Requisitos

- Docker + Docker Compose

## Como rodar (desenvolvimento)

```bash
cp .env.example .env            # preencha segredos se necessário (placeholders CONFIGURE_ME)
docker compose up -d --build    # sobe app, web, worker, scheduler, mysql, redis
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force
```

App em `http://localhost:8080` (porta configurável via `APP_PORT`). Healthcheck em `/up`.

## Serviços

| Serviço | Descrição |
|---|---|
| `app` | PHP-FPM (Laravel) |
| `web` | Nginx |
| `worker` | `queue:work redis` |
| `scheduler` | `schedule:work` |
| `mysql` | Banco de dados |
| `redis` | Cache + filas |

## Comandos úteis

```bash
# Testes e qualidade
docker compose exec app php artisan test
docker compose exec app ./vendor/bin/pint --test

# Banco
docker compose exec app php artisan migrate --force
docker compose exec app php artisan migrate:status

# Fila (worker já roda em background)
docker compose exec app php artisan queue:work --once

# Admin inicial
docker compose exec app php artisan app:create-admin --email=admin@example.com --password='senha-forte'

# Saúde
docker compose ps
docker compose exec redis redis-cli ping
```

## Regras de segurança

- Nunca commitar segredos (chave PIX, senha, token, API key) — use `CONFIGURE_ME`.
- Créditos com ledger imutável, saldo nunca negativo, transação + lock e idempotência.
- OSINT apenas com fontes públicas; mocks no MVP (sem contornar auth/CAPTCHA/paywall).

## Especificação

Documentos e prompts em `prompts/investigacao-osint-prompts/`; regras em `.cursor/rules/*.mdc`; histórico de decisões em `CHANGELOG.md`.

