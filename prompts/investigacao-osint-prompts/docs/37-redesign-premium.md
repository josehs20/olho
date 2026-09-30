# Redesign premium e conversão (prompt 42)

Reformulação visual completa da experiência — landing, checkout, investigação
avulsa, processamento, resultado, relatório e área autenticada — com foco em
conversão, mantendo **intactos** backend, pipeline, adapters, regras de crédito e
regras de segurança.

## O que não foi alterado

- pipeline de investigação, jobs, adapters e contratos das fontes;
- regras de crédito/pagamento (avulsa segue "pagou, usou, acabou", sem créditos);
- regras de segurança (token, `no-store`/`noindex`, rate limit, CSRF, 404 em rotas
  de conta para avulsa);
- nenhuma tabela nova: a única mudança de backend é **leitura** adicional no estado
  da avulsa (prévia de descobertas reais).

## Direção de design

- **Tokens Tailwind v4** em `resources/css/app.css` (`@theme`): paleta escura
  (`ink`), texto (`mist`), acento sóbrio (`signal`), linhas, sombras e animações.
- Componentes CSS semânticos: `.surface`, `.surface-2`, `.glass`, `.hud-grid`,
  `.aurora`, `.lift`, `.btn`/`.btn-*`, `.field`, `.badge`/`.badge-*`, `.skeleton`,
  `.eyebrow`, `.text-gradient`.
- Microanimações leves (`rise`, `shimmer`, `pulse-soft`, `scan`) com
  **`prefers-reduced-motion`** respeitado globalmente.
- Acessibilidade: foco visível (`:focus-visible` no acento), contraste alto, alvos
  de toque ≥ 44 px (`min-height` dos botões), `aria-*` nos indicadores, rótulos em
  todos os campos, link "ir para o conteúdo".
- Tipografia unificada em **Instrument Sans** (carregada pelo plugin de fonts do
  Vite); removidos os `<link>` de figtree que divergiam do CSS.

## Landing premium (`GET /`)

Seções: hero com HUD/aurora e dupla CTA (**INICIAR INVESTIGAÇÃO** → jornada avulsa
sem cadastro e "Já possui conta? Entrar"), **preço real** vindo do banco, "O que
pode aparecer na investigação", "Como funciona" (4 passos), planos de créditos,
"O que vem no relatório", FAQ (`<details>`) e CTA final.

Honestidade embutida nas afirmações:

- plataformas são exibidas com cobertura **verificada nos catálogos das fontes
  ativas** (WhatsMyName 717 sites, Maigret, Sherlock) e no resultado da execução
  real de referência (docs/36): foram observados github.com, pinterest.com,
  vimeo.com, flickr.com, t.me, medium.com, tiktok.com, open.spotify.com,
  facebook.com, reddit.com, twitch.tv, entre outros;
- **WhatsApp aparece como "fora do escopo"** — nenhuma fonte acessa mensagens
  privadas (regra do projeto), evitando promessa falsa;
- o escopo configurado por execução é declarado no catálogo (`config/platforms.php`:
  `maigret_top_sites=30`, `whatsmyname_max_sites=40`) e a página informa que a
  cobertura varia e depende do que existir publicamente;
- nenhuma descoberta, imagem, porcentagem ou resultado é fabricado em nenhum ponto.

SEO/OG mínimos via meta tags (`description`, `og:title`, `og:description`,
`og:type`), `robots=index,follow` na landing e `noindex,nofollow` nas telas com
token/área autenticada.

## Jornada avulsa

- **Identificador** (`/investigacao`): indicador de etapas
  (Identificador → Revisão → Pagamento → Investigação → Resultado), formulário com
  rótulos e erros por campo, resumo lateral com **preço real** e avisos de
  transparência.
- **Pagamento** (página do token): PIX copia-e-cola com botão de copiar
  (clipboard + fallback por seleção) e simulação apenas com provider fake; estado de
  expiração visível.
- **Processamento**: lista de etapas derivada do **estado real** persistido
  (fontes preparadas, usernames consultados, plataformas verificadas, descobertas
  consolidadas, correlação, relatório) com marcadores ✓/◉/◌ — sem percentuais
  inventados; estado por fonte com versão, tentativas, duração e resultados.
- **Prévia de descobertas**: até 12 representantes reais (plataforma, título,
  URL, confiança, horário da observação) revelados conforme os dados chegam;
  skeleton e estado vazio elegantes antes disso; aviso de que nada é simulado.
- **Polling**: `/status` (JSON) a cada 5 s, recarregando a página somente quando há
  progresso real (status, fontes concluídas, descobertas, relatório ou pagamento).

## Relatório premium

Mesmo relatório e mesmo manifesto (`report-manifest-v1`), re-skinado: KPIs reais
(status, descobertas, resultados, fontes, créditos consumidos/reservados,
correlação), identificadores pesquisados, tabela de fontes com status/versão/
resultados/tentativas/duração/observação, descobertas com fontes observadoras e
evidencias, correlação explicada (fatores, explicações, limitações), falhas e
timeouts visíveis, aviso do Holehe e rodapé com hash do manifesto. Mantém
`print:` para impressão/PDF e continua disponível para conta (layout do app) e
para avulsa (layout público + link de volta pelo token).

## Área autenticada e admin

- Shell autenticado (`layouts/app.blade.php` + `layouts/navigation.blade.php`):
  navegação com Painel, Investigações, Nova investigação, Créditos e Conta, CTA
  "Investigar", menu do usuário com perfil/privacidade/exportação/sair e menu
  mobile funcional.
- Demais telas (dashboard, investigações, pagamentos, perfil, privacidade, admin)
  receberam o **mesmo mapa de tokens** (superfícies, textos, bordas, badges,
  botões) — o admin não foi reformulado funcionalmente, apenas harmonizado.
- Navegação autenticada usa apenas rotas existentes (nada de link morto).

## Componentes Blade criados

| Componente | Uso |
|---|---|
| `x-btn` | botões/links com variantes (primary, outline, ghost, danger) e tamanhos |
| `x-card` | superfície com eyebrow/título/subtítulo/ações |
| `x-badge` e `x-status-badge` | rótulos e estados reais (investigação, run, pagamento, fonte) |
| `x-stat` | indicadores numéricos |
| `x-alert` | avisos por tom (info/success/warning/danger) |
| `x-section-heading` | cabeçalho de seção da landing |
| `x-step-indicator` | progresso por etapas |
| `x-source-status` | linha de fonte com versão/tentativas/duração/resultados |
| `x-platform-chip` | plataforma coberta/fora do escopo (com nota) |
| `x-empty-state` e `x-skeleton` | estados vazios e carregamento |
| `application-logo` | nova marca (lente de investigação) |

Componentes Breeze (`primary-button`, `secondary-button`, `danger-button`,
`text-input`, `input-label`, `input-error`, `dropdown*`, `nav-link`,
`responsive-nav-link`, `modal`, `auth-session-status`) foram re-skinados mantendo
as mesmas APIs (nenhuma view precisou mudar de assinatura).

## CSS/JS

- `resources/css/app.css`: tokens (`@theme`), base (fundo, foco, scrollbar,
  `color-scheme: dark`), componentes semânticos, keyframes e
  `prefers-reduced-motion`.
- JS: apenas inline, sem novas bibliotecas — copiar PIX (clipboard com fallback) e
  polling do acompanhamento com recarga condicionada a progresso real.
- Assets recompilados (`npm run build`); bundle CSS ~78 kB (gzip ~14 kB).

## Rotas

- Nenhuma rota nova ou removida. A rota `GET /` ganhou o **nome** `home`
  (aditivo) para o link da marca e os âncoras do menu funcionarem de qualquer
  página.
- `GET /` passou a enviar dados reais para a view (preço da avulsa, pacotes de
  créditos, catálogo de plataformas).

## Backend

- `GuestInvestigationService::progress()` passou a expor `discoveries`
  (representantes reais), `discoveries_total`, `completed_sources` e `correlated`
  para alimentar a prévia e o polling — apenas leitura, sem migração e sem alterar
  pipeline/segurança.
- `config/platforms.php` (novo) documenta cobertura e escopo das fontes para a
  landing.

## Testes e validação

- `ExampleTest` reescrito como **smoke test da landing**: renderiza `/` com seeds
  mínimas e verifica hero, CTA e a marcação honesta de WhatsApp como "fora do
  escopo".
- `LocalizationTest` ajustado à nova copy do hero.
- Suíte completa: **301 testes passando** + 5 ignorados (integrações reais gated),
  1316 assertions. Pint: PASS (256 arquivos).
- Verificação ao vivo: landing 200 (preço R$ 29,90 vindo do banco, 27 plataformas
  cobertas), formulário da avulsa 200 com etapas, acompanhamento 200 com fontes
  reais e link do relatório, `/status` 200 JSON, login 200.

## Problemas encontrados (e correções)

| # | Problema | Correção |
|---|---|---|
| 1 | aspas duplas dentro de atributos HTML quebravam a compilação Blade (`href="{{ route("x") }}"`) | normalização para aspas simples nas expressões |
| 2 | `>` dentro de expressão de atributo (`:title="... > 0 ? ..."`) cortava a tag do componente | cálculo movido para o bloco `@php` |
| 3 | combinação de componente + condicionais aninhados num arquivo grande gerava `endif` órfão | view da avulsa refatorada em **partials** (payment, processing, sources, discoveries, discovery-list, access, included, scripts) |
| 4 | slot `header` do layout público tinha sido removido, quebrando títulos das páginas da avulsa | slot restaurado |
| 5 | tokens novos não existiam no CSS servido (Tailwind varre as views) | `npm run build` a cada mudança de view |
| 6 | `.tmp_compile.php` (script de verificação de Blade) | removido do repositório antes do commit |

## Possíveis melhorias futuras

- Imagem OG real e favicon próprio; `sitemap`/`canonical`.
- Auditoria de acessibilidade com ferramenta dedicada (axe/Lighthouse) e testes de
  contraste automatizados.
- Regressão visual (screenshots por viewport 360/390/414/tablet/desktop) em CI.
- Redesign completo do admin (hoje apenas harmonizado, conforme o prompt).
- QR Code gráfico do PIX quando houver gateway real (o prompt aceita copia-e-cola).
- Compressão/brotli e cache de assets no nginx; self-host das fontes (já feito pelo
  Vite) exposto com `preload`.
- Skeleton progressivo com transições entre etapas também por JS (hoje a recarga
  ocorre apenas quando há progresso real — decisão por simplicidade e performance).
