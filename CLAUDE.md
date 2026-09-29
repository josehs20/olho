# CLAUDE.md — Investigação Pública

Ver `AGENTS.md` para as diretrizes completas do projeto.

Resumo:

- Laravel 13, PHP 8.5 (Docker), Breeze Blade/Tailwind, MySQL 8.4, Redis (cache + filas).
- Especificação em `prompts/investigacao-osint-prompts/` e regras em `.cursor/rules/*.mdc`.
- Executar um prompt por vez, em ordem, inspecionando o estado real do repositório.
- Rodar: `docker compose up -d --build`, `docker compose exec app php artisan test`, `./vendor/bin/pint --test`.
- Nunca commit segredos; créditos com ledger imutável e saldo nunca negativo; SQL parametrizado; OSINT só com fontes públicas e mocks no MVP.
