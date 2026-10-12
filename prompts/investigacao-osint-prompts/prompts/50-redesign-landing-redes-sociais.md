# Prompt 50 — Redesign da Landing (Hero) com Foco em Redes Sociais e Demonstração Visual

## CONTEXTO

A página inicial precisava deixar explícito o foco da plataforma —
**investigação e enriquecimento de perfis em redes sociais e fontes abertas
(OSINT)** — e tangibilizar a promessa de valor com um preview do relatório
enriquecido, sem tocar no backend nem inventar descobertas.

## O que foi feito

### 1. Hero com foco em redes sociais
- Novo título de impacto: **"Descubra a pegada digital e os perfis em redes
  sociais em segundos."** A assinatura de marca **"VOCÊ DÁ A PISTA. / O OLHO
  PROCURA."** foi preservada (identidade verbal, prompt 44).
- **Formulário de busca rápida** (Username, Nome, E-mail, Telefone) que aponta
  para a rota **existente** `guest.investigations.store` — nenhuma rota de
  backend foi alterada ou crirada.
- Ícones inline das redes suportadas (Instagram, X, Reddit, TikTok, LinkedIn,
  Spotify) ao lado do botão.

### 2. Demonstração visual do relatório (fictícia)
- Seção destacada com cartões de **perfil enriquecido de exemplo** (avatar,
  `@handle`, nome, localização, bio, badges das redes onde foi "encontrado").
- Amostra de **evidência** e **sinal de correlação**.
- Badges: *"Enriquecimento de perfil em tempo real"*, *"Coleta somente de fontes
  públicas"*, *"Relatório unificado"*.
- **Honestidade:** avatares são **SVG gerados localmente** (nenhuma imagem de
  pessoa real, nenhum request de rede). Selo "demonstração" + aviso de que
  correspondência não é identidade confirmada.

### 3. Argumentos de conversão
- Bloco de benefícios: **Mapeamento Multiplataforma**, **Enriquecimento Visual**,
  **Relatórios Prontos para Auditoria**.

### 4. CTAs estratégicos
- *"INVESTIGAR AGORA"* (hero, preço, CTA final), *"VER EXEMPLO DE RELATÓRIO"*
  (demonstração → cria avulsa) e condução suave para cadastro/login quando
  deslogado.

### 5. Polimento e responsividade
- Tailwind/ecossistema Blade existente: `surface`, `surface-2`, `rounded-xl`,
  `shadow` implícito dos tokens, `lift`, gradientes (`text-gradient`, `aurora`).
- Layout responsivo (1 coluna no mobile, 2 no desktop) e animações `rise`/`pulse`
  já usadas no projeto.

## Estrutura (modular)
- `resources/views/components/social-icon.blade.php` — ícones SVG inline.
- `resources/views/components/demo-avatar.blade.php` — avatar SVG fictício por seed.
- `resources/views/welcome/partials/hero.blade.php` — hero + formulário.
- `resources/views/welcome/partials/demo-report.blade.php` — demonstração do relatório.
- `resources/views/welcome/partials/benefits.blade.php` — benefícios.
- `resources/views/welcome.blade.php` — compõe os partials + SEO meta preservado.

## Testes
- `ExampleTest::test_landing_showcases_social_osint_demo_and_benefits` cobre o
  redesign (hero, demonstração fictícia, benefícios, rota do formulário e a
  ausência de `<img>` externo). Os testes anteriores da landing seguem verdes.
