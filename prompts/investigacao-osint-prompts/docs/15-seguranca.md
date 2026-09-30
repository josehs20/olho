# 15 — Segurança

## Aplicação

- validação de requests;
- authorization por policies/gates;
- CSRF;
- escaping de Blade;
- rate limiting;
- headers de segurança;
- sessão segura;
- secrets em ambiente.

## SQL

Usar Eloquent/query builder parametrizado. Nenhum SQL concatenado com entrada do usuário.

## Shell/processos

Não concatenar input do usuário em `shell_exec`, `exec`, `proc_open` ou equivalentes.

Ferramentas externas deverão receber argumentos por estrutura segura e lista fixa de flags permitidas.

## Uploads

- validar MIME real;
- limites de tamanho;
- extensão controlada;
- nome interno aleatório;
- armazenar fora de diretório executável;
- impedir path traversal;
- considerar antivírus antes da integração real.

## Webhooks

- validar assinatura;
- validar timestamp/replay quando suportado;
- idempotency;
- rate limiting;
- não confiar no status vindo do frontend.

## Logs

Nunca logar:

- senha;
- tokens;
- cookies;
- chaves PIX reais;
- API keys;
- conteúdo desnecessário de dados pessoais.

## Workers

Limitar CPU/memória/timeout conforme Docker e configuração do worker. Um processo externo travado não deve travar o web app.


## Investigação avulsa (prompt 41)

- Token de 256 bits (`random_bytes`), persistido apenas como SHA-256; formato
  inválido é rejeitado antes de qualquer consulta (sem enumeração útil).
- Nenhuma rota aceita o id da investigação avulsa; o token mapeia exatamente uma
  investigação (sem IDOR) e o relatório é derivado dele.
- Páginas com token: `Cache-Control: no-store`, `Pragma: no-cache` e
  `X-Robots-Tag: noindex, nofollow, noarchive`.
- Limites: `guest-create` (5/min + 20/h por IP) e `guest-access` (60/min por IP +
  30/min por hash de token).
- Liberação da execução somente por evento de pagamento verificado (webhook) ou
  pela simulação do provider fake; CSRF habilitado nas rotas web.
- Rotas autenticadas de investigação/relatório respondem 404 para avulsa
  (não-admin), evitando revelar a existência; a policy nega `view` (não-admin) e
  `rerun` (todos).
