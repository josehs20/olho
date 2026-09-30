# Integração real — Holehe

[Holehe](https://github.com/megadose/holehe) como fonte para **e-mails**, dentro
do escopo de informações públicas e com salvaguardas explícitas.

## Verificações prévias (prompt 35)

| Item | Resultado |
|---|---|
| Licença | **GPL-3.0** (as demais fontes são MIT/CC BY-SA) |
| Versão | 1.61 (fixada em `docker/php/Dockerfile` via `ARG HOLEHE_VERSION`) |
| Requisitos | Python (mesma camada da imagem) |
| Técnica | verifica se o e-mail aparece cadastrado em ~117 serviços usando os fluxos públicos de **registro/login** (e, opcionalmente, recuperação de senha) |
| Saída | CSV `holehe_<epoch>_<email>_results.csv` com `name,domain,method,frequent_rate_limit,rateLimit,exists,emailrecovery,phoneNumber,others` |
| Código de saída | **1 mesmo em execução bem-sucedida** — o CSV gerado é o sinal confiável |

## Escopo e salvaguardas (inegociáveis)

1. **`--no-password-recovery` é obrigatório e não configurável**: o passo de
   recuperação de senha nunca é executado, evitando disparar redefinições na
   conta do titular.
2. **Nenhum acesso a conta, credencial ou conteúdo privado** — apenas requisições
   não autenticadas a endpoints públicos de cadastro.
3. As colunas `emailrecovery`/`phoneNumber`/`others` **nunca são lidas nem
   persistidas** (`HoleheReportParser` ignora explicitamente).
4. Entrada validada (`filter_var` + padrão + rejeição de `-` inicial) e passada
   como elemento próprio da argv (nunca shell).
5. Execução isolada (diretório próprio, `HOME` apontando para ele), com timeout
   por requisição e teto do processo; o diretório é removido no `finally`.
6. **Licença GPL-3.0 — decisão registrada**: a ferramenta roda como **processo
   separado** (sem linkagem com o código da aplicação) e o serviço é acessado
   pela rede, sem distribuição do binário. Nesse cenário (uso interno/SaaS, mesma
   forma do Maigret e do Sherlock) a GPL não impõe copyleft ao código da
   plataforma. Obrigações da GPL passariam a valer apenas se a **imagem** com o
   binário fosse distribuída a terceiros — nesse caso é preciso disponibilizar o
   código correspondente do Holehe e avisos de licença. Driver padrão:
   `process` (igual às outras ferramentas); a suíte de testes usa `mock`.

## Aviso obrigatório ao usuário

Uma indicação técnica de que um e-mail aparece cadastrado em um serviço **não
prova que uma pessoa controla aquela conta** (e-mail pode ser compartilhado,
corporativo, descartável ou reciclado). Por isso:

- `confidence = 0.5` (abaixo do limiar de "encontrado" da interface, que exibe
  "possível correspondência");
- `metadata.disclaimer` persistido em `source_runs.metrics.adapter`;
- bloco de aviso no relatório sempre que o run tiver o módulo Holehe.

## Execução

```text
holehe <email> --no-password-recovery --only-used --no-color --no-clear \
       --csv --timeout <segundos>
```

`-NP` é fixo no código do adapter; não existe opção para habilitar a
recuperação de senha.

## Conversão para o contrato comum

`HoleheReportParser` produz `SourceResult` apenas para linhas com
`exists = True`: `entity_type = email_account`, `value` = e-mail, `title` =
domínio, `url = https://<domínio>`, evidência
`email_registration_indicator` = domínio e metadados
`site/domain/method/tool/signal=public_signup_check`.

Mapeamento de falhas: sem CSV → `unavailable`; timeout → `timeout`; e-mail
inválido → `invalid_input` (sem executar nada).

## Testes

- `HoleheReportParserTest` — conversão, descarte de domínio inválido e prova de
  que colunas de recuperação nunca entram no domínio;
- `HoleheAdapterTest` — resultado encontrado (com `exit_code = 1`), nenhum
  resultado, relatório ausente, timeout, e-mail inválido sem execução, `-NP`
  obrigatório na argv, limpeza do diretório e troca mock↔process;
- `HoleheRealIntegrationTest` (gated por `OSINT_E2E=1`) — execução real com
  **e-mail de domínio reservado** (RFC 2606, `test@example.com`), que não
  pertence a nenhum titular.

```bash
docker compose exec -e OSINT_E2E=1 app php artisan test --filter=HoleheRealIntegrationTest
```

## Limitações

- Muitos serviços bloqueiam/limitam as consultas anônimas: falsos negativos são
  esperados;
- o resultado é um **indício de cadastro**, não um perfil: não há URL de perfil
  (apenas o domínio do serviço), o que limita a correlação;
- a licença GPL-3.0 do binário não alcança o código da aplicação enquanto o uso
  for interno/SaaS (processo separado, sem distribuição do binário); se a imagem
  passar a ser distribuída, aplicar as obrigações da GPL.
