# Identidade verbal, copy e posicionamento da marca Olho (prompt 44)

Revisão de copy e posicionamento para tornar a plataforma uma marca única,
premium e orientada a conversão — sem alterar backend, promessas ou resultados.

## Identidade verbal adotada

- **Nome**: **Olho** (`APP_NAME="Olho"` em `.env`/`.env.example`; o código do
  domínio não depende do nome, como previa `docs/00-visao-geral.md`).
- **Assinatura**: **"Você dá a pista. O Olho procura."**
- **Personalidade**: observador, preciso, misterioso, tecnológico, discreto,
  direto, sofisticado.
- **Voz**: frases curtas, poucas palavras, verbos de ação, curiosidade.
  Conceitos: observar, procurar, encontrar, conectar, organizar, revelar.
  "Vigiar" foi evitado (ecoa monitoramento invasivo).
- **O que nunca é dito**: acesso a conteúdo privado, conta privada, WhatsApp de
  alguém, "todos os segredos", "100% de precisão", confirmação de identidade ou
  ausência de pessoa. "Olho de Deus" fica só como inspiração conceitual do
  símbolo — nunca como afirmação institucional.

## Headline e CTAs

Variações avaliadas para o hero:

1. "O QUE EXISTE SOBRE ESSA PRESENÇA NA INTERNET?" (pergunta);
2. **"VOCÊ DÁ A PISTA. / O OLHO PROCURA."** ← **escolhida** (casa com o layout de
   duas linhas + gradiente já existente e com o CTA de ação);
3. "UM NOME. UM USUÁRIO. UM E-MAIL. O OLHO PROCURA O QUE ESTÁ PÚBLICO." (lista).

- **CTA principal**: `INVESTIGAR AGORA` (hero, card de preço e CTA final).
- **CTAs contextuais**: `DEIXAR O OLHO PROCURAR` (seção de fontes) e
  `LIBERAR INVESTIGAÇÃO` (pagamento PIX).
- **Microcopies** espalhadas com moderação: "O que será que o Olho encontra?",
  "Há mais na internet do que aparece à primeira vista", "Um nome pode levar a
  muitos lugares. A busca começa com uma informação.", "O Olho está esperando a
  sua pista.".

## Inventário e mudanças por página

| Página | Mudanças de copy |
|---|---|
| Landing `/` | Hero novo; seção **"O QUE O OLHO PROCURA?"** com 01 Username · 02 Redes sociais · 03 E-mail · 04 Web · 05 Conexões; hooks de curiosidade por plataforma (Instagram em destaque, Facebook, TikTok, X/Twitter, LinkedIn, YouTube, Reddit, GitHub — só para plataformas que já existem em `config/platforms.php`); WhatsApp segue **fora do escopo** com a nota de que mensagens privadas nunca são acessadas; "Como funciona" (Sua pista → Pagamento único → O Olho procura → O que encontrou); seção de relatório; FAQ sem promessas; CTA final |
| `layouts/public` | Link "O que o Olho procura"; rodapé com a assinatura e bloco de confiança discreto (informações públicas, disponibilidade/visibilidade por fonte) |
| `components/platform-chip` | Novo prop `hook` (microcopy sob o nome) — nenhuma plataforma nova é listada |
| Guest criação | Headline "O que você quer investigar?"; helper "O Olho vai procurar sinais públicos…"; card de transparência restaurado e reescrito; removido botão duplicado (artefato de edição) |
| Guest acompanhamento | Etapas na voz do Olho mapeadas a estados reais; título derivado do estado: "O Olho está esperando a sua pista" → "O OLHO ESTÁ PROCURANDO..." → "O QUE O OLHO ENCONTROU"/"INVESTIGAÇÃO CONCLUÍDA" |
| Guest pagamento | "Sua investigação está pronta para começar" (pago: "O Olho começou a procurar"); CTA `LIBERAR INVESTIGAÇÃO` com nota de provider fake |
| Guest descobertas | "O Olho encontrou :count descobertas"; vazio em execução: "A busca continua..."; vazio concluído: "O Olho não encontrou ocorrências nas fontes consultadas." + ressalva de que **não** é ausência da pessoa |
| Relatório | "RELATÓRIO OLHO" + eyebrow "Mapa de descobertas"; seções em caixa alta: RESUMO, ENTRADAS INFORMADAS, FONTES CONSULTADAS, DESCOBERTAS, POSSÍVEIS CONEXÕES, FALHAS E LIMITAÇÕES — **dados intactos** |
| Painel autenticado | Header "Dashboard" → "Painel" (demais textos preservados) |

## SEO

- `<title>`: "Olho — investigação de presença digital".
- Meta description e Open Graph/Twitter (`og:title`, `og:description`,
  `og:type`, `og:site_name`, `twitter:card`, `twitter:title`,
  `twitter:description`) com termos naturais: investigação de presença digital,
  username, Instagram, Facebook, TikTok, X, LinkedIn, YouTube, fontes públicas.
- Sem keyword stuffing; páginas autenticadas e de token continuam `noindex`.

## Garantias mantidas

- Nenhum resultado, imagem, percentual ou fonte inventados.
- Só plataformas efetivamente cobertas aparecem como consultáveis; WhatsApp
  permanece explicitamente fora do escopo.
- Backend intocado: adapters, jobs, fila, workers, créditos, pagamentos, banco,
  normalização, deduplicação e correlação não foram alterados (mudanças apenas
  de view/`<title>`/APP_NAME).

## Testes

- Suíte completa: **309 passando + 5 ignorados** (integrações reais gated por
  `OSINT_E2E=1`); **Pint PASS** (259 arquivos).
- Testes ajustados à nova copy: `ExampleTest` (hero/CTA + meta `twitter:card`),
  `LocalizationTest` (hero em PT-BR), `ReportTest` e `SourceRunPersistenceTest`
  (títulos de seção em caixa alta).
- Verificação manual (HTTP): landing, `/investigacao`, `/login` e `/up`
  respondendo 200 com a copy nova.
