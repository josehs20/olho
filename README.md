# Investigação Pública

Plataforma Laravel de investigação de **presença digital pública** (OSINT). O usuário compra créditos e os usa para executar módulos de pesquisa sobre informações públicas. Resultados apontam para fontes/evidências; uma correspondência não é identidade confirmada.

## Stack

- Laravel 13 · PHP 8.5 (Docker)
- Breeze (Blade/Tailwind) · MySQL 8.4 · Redis (cache + filas)
- Workers Docker · PIX fake (`FakePixProvider`) · OSINT: Maigret, Sherlock, WhatsMyName e Holehe reais + módulos mock

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

# Popular o banco (idempotente): referência + contas + dados de demonstração
docker compose exec app php artisan db:seed
#   fontes:  7 source_definitions ativas (mock-username, mock-email, mock-web, maigret, sherlock, whatsmyname, holehe)
#   pacotes: 3 credit_packages ativos
#   contas:  admin@example.com e usuario@example.com (senhas em DEMO_*_PASSWORD; fallback local "password")
#   demo:    4 investigações processadas (3 concluídas, 1 parcial) com resultados, evidências e relatório,
#            2 pagamentos PIX (1 pago, 1 aguardando) e saldo de créditos consistente com o ledger
# Roda apenas fora de produção; reexecutar não duplica dados nem reativa o que o admin desativou.

# Seeders individuais (ordem de dependência)
docker compose exec app php artisan db:seed --class=SourceDefinitionSeeder
docker compose exec app php artisan db:seed --class=CreditPackageSeeder
docker compose exec app php artisan db:seed --class=DemoUsersSeeder
docker compose exec app php artisan db:seed --class=DemoDataSeeder

# Fila (worker já roda em background)
docker compose exec app php artisan queue:work --once

# Admin inicial
docker compose exec app php artisan app:create-admin --email=admin@example.com --password='senha-forte'

# Saúde
docker compose ps
docker compose exec redis redis-cli ping
```

## Dados de teste

- **Factories** (`database/factories`): definem como fabricar um registro isolado, com *states* do domínio
  (ex.: `Payment::factory()->paid()`, `SourceRun::factory()->timeout()`, `Investigation::factory()->partial()`).
  Use factories em testes, quando o objetivo é montar só o cenário necessário.
- **Seeders** (`database/seeders`): orquestram um conjunto coerente de dados.
  - `SourceDefinitionSeeder` / `CreditPackageSeeder` — dados de referência determinísticos (sem Faker).
  - `DemoUsersSeeder` — contas admin e comum.
  - `DemoDataSeeder` — pagamentos, investigações (rodando o pipeline real), relatórios e auditoria.

## Regras de segurança

- Nunca commitar segredos (chave PIX, senha, token, API key) — use `CONFIGURE_ME`.
- Créditos com ledger imutável, saldo nunca negativo, transação + lock e idempotência.
- OSINT apenas com fontes públicas (sem contornar auth/CAPTCHA/paywall). Ferramentas externas
  rodam por `ProcessToolRunner` (argv separado, timeout, diretório isolado e ambiente controlado).
- O módulo `maigret` usa a ferramenta real instalada na imagem (`MAIGRET_DRIVER=process`);
  `MAIGRET_DRIVER=mock` mantém o adapter determinístico (usado pelos testes).
  Ver `prompts/investigacao-osint-prompts/docs/27-maigret-integracao.md`.
- O módulo `sherlock` segue o mesmo contrato e a mesma camada de execução
  (`SHERLOCK_DRIVER=process|mock`, escopo por `SHERLOCK_SITES`).
  Ver `prompts/investigacao-osint-prompts/docs/28-sherlock-integracao.md`.
- O módulo `whatsmyname` usa o dataset público fixado por commit na imagem
  (`WHATSMYNAME_DRIVER=http|mock`, escopo por `WHATSMYNAME_MAX_SITES`/categorias).
  Dados do WhatsMyName são **CC BY-SA 4.0, © Micah Hoffman e colaboradores**.
  Ver `prompts/investigacao-osint-prompts/docs/29-whatsmyname-integracao.md`.
- O módulo `holehe` usa a ferramenta real para **e-mails** (`HOLEHE_DRIVER=process|mock`,
  padrão `process`, igual às demais) e roda **sempre** com `--no-password-recovery`:
  nenhuma recuperação de senha é disparada, nenhuma conta/credencial é acessada e as
  colunas de recuperação do CSV nunca são persistidas. O binário é **GPL-3.0**: roda como
  processo separado e o serviço é acessado pela rede (sem distribuição do binário), então
  a GPL não alcança o código da aplicação; se a imagem for distribuída, aplicam-se as
  obrigações da GPL. Uma indicação de cadastro associada a um e-mail **não prova** que a
  pessoa controla a conta — use com base legal (LGPD).
  Ver `prompts/investigacao-osint-prompts/docs/30-holehe-integracao.md`.

## Deduplicação, correlação, relatório e créditos

- **Deduplicação (v2)** — chave estável `sha256(entity_type | plataforma | identificador | URL canônica)`,
  com `dedup_key_version` e `explain()` auditável. Quando várias fontes encontram o mesmo perfil, a
  descoberta é consolidada (representante com `dedup_sources`/`dedup_observations`); nenhuma evidência
  é apagada. Ver `prompts/investigacao-osint-prompts/docs/31-normalizacao-deduplicacao.md`.
- **Correlação (v2)** — só dados observados e regras versionadas em `config/correlation.php`. Cada
  associação guarda fatores, evidências usadas, pontuação interna, explicação e limitações.
  O valor é **indicador interno**, nunca probabilidade de identidade, e username coincidente não
  infere identidade (sem fator corroborante não há faixa de associação).
  Ver `prompts/investigacao-osint-prompts/docs/32-correlacao-explicavel.md`.
- **Relatório** — lê o pipeline persistido (identificadores, fontes e status, descobertas com URL/data
  de observação/evidências, correlação com fatores, falhas e limitações) e congela um **manifesto**
  (`reports.metadata`, `report-manifest-v1`, com `content_hash`) para que um relatório histórico não
  mude silenciosamente. Falhas nunca são escondidas.
  Ver `prompts/investigacao-osint-prompts/docs/33-relatorio-evidencias.md`.
- **Créditos/retry/idempotência** — reserva **por módulo** (`credit_reservations.source`)
  e cobrança **pela consulta executada**: o módulo é cobrado mesmo sem resultado (inclusive
  quando a consulta falha/timeout); só é estornado o que nunca foi consultado (não
  aplicável, job perdido, worker interrompido). Transação + lock na carteira e idempotency
  key por operação. Reexecução controlada cria um novo run com custo independente sem
  sobrescrever o anterior. Ver `prompts/investigacao-osint-prompts/docs/34-creditos-retry-idempotencia.md`.

## Especificação

Documentos e prompts em `prompts/investigacao-osint-prompts/`; regras em `.cursor/rules/*.mdc`; histórico de decisões em `CHANGELOG.md`.



## Investigação avulsa (sem cadastro)

Além da jornada com créditos, existe a consulta avulsa pública: o visitante não
cria conta, paga um preço fixo via PIX e acompanha o resultado pelo próprio link.

- **Preço fixo**: pacote ativo `kind = guest_investigation`, editável em
  `/admin/packages` (sem deploy). O valor pago fica congelado na investigação
  (`guest_price_cents`).
- **Jornada**: `GET/POST /investigacao` -> PIX -> `GET /investigacao/acesso/{token}`
  (progresso, `.../status` para polling e `.../relatorio`).
- **Sem créditos**: a avulsa não movimenta carteira nem ledger ("pagou, usou,
  acabou"); a execução só começa após o pagamento confirmado pelo webhook do
  gateway (a página nunca decide que está pago).
- **Acesso por token** de 256 bits: persistido como hash SHA-256, `no-store` +
  `noindex`, limite por IP e por token; rotas autenticadas não alcançam a avulsa
  (404 para contas comuns; o admin vê pelo painel).
- **Expiração**: rascunho não pago vira `expired` pelo agendador
  (`guest:expire-unpaid`) e nada é executado.
- **`.env`**: `GUEST_UNPAID_EXPIRATION_MINUTES` (padrão 1440) e
  `GUEST_ACCESS_TOKEN_TTL_DAYS` (padrão 365).
- **Detalhes**: `prompts/investigacao-osint-prompts/docs/35-investigacao-avulsa.md`.

- **Auditoria E2E real (etapa 40)**:
  `prompts/investigacao-osint-prompts/docs/36-e2e-real-auditoria.md` — jornada
  completa com Maigret/Sherlock/WhatsMyName/Holehe reais (41 resultados, 28
  descobertas, relatório com limitações reais; 8/8 créditos consumidos). Teste
  gated: `docker compose exec -e OSINT_E2E=1 app php artisan test --filter=RealPipelineAuditTest`,
  que grava um relatório JSON em `storage/app/osint-audit/`.

- **Redesign premium (etapa 42)**:
  `prompts/investigacao-osint-prompts/docs/37-redesign-premium.md` — direção de
  design (tokens Tailwind v4), landing orientada a conversão com preço real e
  cobertura honesta de plataformas, jornada avulsa com processamento em etapas e
  prévia de descobertas reais, relatório premium e componentes reutilizáveis
  (`x-btn`, `x-card`, `x-badge`, `x-stat`, `x-step-indicator`, `x-source-status`…).
  Assets: `npm run build` (Tailwind varre `resources/views`).
