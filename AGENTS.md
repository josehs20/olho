# AGENTS.md — Investigação Pública

Diretrizes para agentes que trabalham neste repositório.

## O que é

Plataforma Laravel 13 de investigação de **presença digital pública** (OSINT). O usuário compra créditos e os usa para executar módulos de pesquisa. Resultados apontam para fontes/evidências; uma correspondência não é identidade confirmada.

## Especificação

A especificação completa (visão, requisitos, regras de negócio, arquitetura, banco, segurança, testes) vive em:

- `prompts/investigacao-osint-prompts/docs/*.md`
- `prompts/investigacao-osint-prompts/prompts/*.md` (ordem em `00-ordem-de-execucao.md`)
- `.cursor/rules/*.mdc` (regras aplicáveis ao código)

Execute um prompt por vez, em ordem, sempre inspecionando o estado real do repositório antes de alterar arquivos.

## Stack

Laravel 13 · PHP 8.5 (Docker) · Breeze (Blade/Tailwind) · MySQL 8.4 · Redis (cache + filas) · workers Docker · PIX fake · OSINT com mocks no MVP.

## Como rodar

```bash
cp .env.example .env      # preencha segredos se necessário
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --force
```

App em `http://localhost:8080`. Healthcheck em `/up`.

## Qualidade

```bash
docker compose exec app php artisan test          # validação final / sob solicitação
docker compose exec app ./vendor/bin/pint --test  # validação final / sob solicitação
```

Fluxo de desenvolvimento (etapa 46): implementar → **verificações rápidas** →
entregar para **validação manual**. A suíte completa e o Pint são executados na
validação final ou quando o usuário pedir explicitamente — não a cada prompt.
Detalhes em `.cursor/rules/05-fluxo-desenvolvimento-rapido.mdc`.

## Regras inegociáveis

- Nunca commit segredo (chave PIX, senha, token, API key). Use placeholders `CONFIGURE_ME`.
- Créditos: ledger imutável, saldo nunca negativo, transação + lock, idempotency key.
- SQL parametrizado; nunca concatenar input de usuário em shell.
- OSINT: apenas fontes públicas; não burlar auth/CAPTCHA/paywall; mocks no MVP.
- Jobs recebem apenas IDs; idempotência por `run_id + source_id`.
