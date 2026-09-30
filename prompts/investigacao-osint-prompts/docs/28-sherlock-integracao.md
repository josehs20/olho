# Integração real — Sherlock

Adapter real do [Sherlock](https://github.com/sherlock-project/sherlock) para
busca de username em redes sociais. Usa **a mesma camada de execução** do
Maigret — nenhuma lógica de execução foi copiada.

## Verificações prévias

| Item | Resultado |
|---|---|
| Licença | MIT |
| Versão | 0.16.2 (fixada em `docker/php/Dockerfile` via `ARG SHERLOCK_VERSION`) |
| Requisitos | Python >= 3.9 |
| Instalação | mesma camada Python da imagem (`pip install sherlock-project==0.16.2`) |
| Binário | `/usr/bin/sherlock` |
| Formato de saída | **não há JSON**: CSV (`--csv`) com `username,name,url_main,url_user,exists,http_status,response_time_s`; stdout `[+] Site: url` como alternativa |
| Escopo | **não há "top N"**: o limite vem de uma lista de sites (`--site`, `SHERLOCK_SITES`) |
| Banco de dados | `--local` força o `data.json` embarcado (sem auto-update) |

## Reuso (não cópia) da integração do Maigret

| Componente compartilhado | Uso |
|---|---|
| `ToolRunner` / `ProcessToolRunner` | argv separado, timeout, ambiente controlado, saída limitada |
| `ToolWorkspace` | diretório isolado (0700) + descoberta de relatório + limpeza no `finally` |
| `ToolVersionDetector` | `<binário> --version` com padrão de versão por ferramenta |
| `ToolOutput::publicWarnings()` | avisos públicos (`[!] ...`) sem detalhes internos |

O `SherlockAdapter` só define o que é específico: argumentos da CLI, validação
de entrada, leitura do CSV e conversão para o contrato comum.

## Comando executado

```text
sherlock <username> --timeout 6 --no-color --print-found --local --csv \
         -fo <workdir> [--site <nome>]...
```

Lista padrão (19 sites, `SHERLOCK_SITES`): GitHub, GitLab, BitBucket, Telegram,
Twitter, Instagram, Reddit, Pinterest, TikTok, YouTube, Twitch, Vimeo, Medium,
Spotify, SoundCloud, Docker Hub, Keybase, Gravatar, HackerNews.

## Conversão para o contrato comum

`SherlockReportParser` produz `SourceResult`: `source = sherlock`,
`entity_type = social_profile`, `title` = site, `value` = username, `url` =
perfil, `confidence = 0.8` (site marcou `exists = Claimed`), evidência
`public_profile_url` e metadados `site/tool/exists/http_status`.

Linhas com `exists = Available` (username livre) e URLs inválidas são
descartadas. A saída parcial em stdout (`[+]`) é usada quando a varredura é
interrompida, com aviso explícito de resultados parciais.

## Deduplicação entre fontes

Descobertas iguais (**mesma URL canônica + mesmo tipo + mesmo username**)
formam um grupo: a representante recebe `metadata.dedup_sources` com todas as
fontes observadoras e as demais ficam com `metadata.duplicate_of` — **nenhuma
evidência é apagada**. O `ResultDeduplicator` passou a remover o prefixo `www.`
na URL canônica, para que `maigret → github.com/x` e `sherlock → www.github.com/x`
sejam reconhecidos como a mesma descoberta.

Exemplo real (`python`): Telegram e Pinterest são encontrados pelas duas
ferramentas com a mesma URL; GitHub difere apenas pelo `www.`.

## Teste controlado

- `SherlockReportParserTest` — CSV, saída parcial e descarte de não reivindicados.
- `SherlockAdapterTest` — argv, `--local`/`--csv`/`--site`, isolamento, limpeza,
  timeout com e sem saída parcial, username insegura sem execução, `normalize`,
  `healthCheck` e troca mock↔real.
- `CrossSourceDeduplicationTest` — adapters reais de Maigret e Sherlock com
  runner determinístico: a mesma descoberta é agrupada com
  `dedup_sources = [maigret, sherlock]`.
- `SherlockRealIntegrationTest` — ferramentas reais (Maigret + Sherlock),
  validando banco → deduplicação → relatório:
  `docker compose exec -e OSINT_E2E=1 app php artisan test --filter=SherlockRealIntegrationTest`.

## Limitações

- Varredura sequencial: o escopo precisa ser limitado por `SHERLOCK_SITES`
  (sem proxy, sites com proteção anti-bot aparecem como não encontrados/erro);
- o CSV só é escrito ao final — em timeout, o adapter usa os `[+]` parciais;
- sem proxy configurado, muitos sites podem bloquear o IP do servidor.
