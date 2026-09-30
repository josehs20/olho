# Prompt 42 — Redesign premium da plataforma + experiência de conversão

## CONTEXTO

Os Prompts 1–41 já foram concluídos.

A plataforma já possui:

* investigação autenticada;
* investigação guest/avulsa;
* pagamento PIX;
* token de acesso;
* queue;
* workers;
* adapters reais;
* Maigret;
* Sherlock;
* WhatsMyName;
* Holehe;
* normalização;
* deduplicação;
* correlação;
* evidências;
* relatório;
* histórico para usuários cadastrados.

O objetivo deste prompt é realizar uma **reformulação visual completa da experiência**, transformando a plataforma em um produto com aparência premium, profissional e tecnológica, com forte foco em conversão da investigação avulsa.

Não reconstruir o backend.

Não alterar o pipeline de investigação.

Não alterar os adapters.

Não alterar regras de crédito/pagamento.

Não alterar as regras de segurança.

A mudança principal é:

```text
VISUAL
UX
COPY
CONVERSÃO
FEEDBACK DE PROCESSAMENTO
APRESENTAÇÃO DOS RESULTADOS
```

---

# OBJETIVO VISUAL

A plataforma deve transmitir:

```text
investigação
tecnologia
inteligência digital
exclusividade
profissionalismo
curiosidade
descoberta
segurança
```

A experiência deve parecer um **site premium de investigação digital**, e não um painel administrativo tradicional.

Evitar visual genérico de:

* CRUD;
* dashboard empresarial;
* template Bootstrap;
* formulário simples;
* sistema interno.

Criar identidade visual própria utilizando o stack já existente:

* Laravel;
* Blade;
* Tailwind CSS;
* Vite.

Não adicionar framework frontend pesado sem necessidade.

---

# DIREÇÃO DE DESIGN

Criar uma estética inspirada em:

* plataformas modernas de investigação;
* intelligence dashboards;
* cybersecurity premium;
* data intelligence;
* investigação digital;
* ferramentas de análise profissional.

Características visuais:

* fundo sofisticado;
* contrastes fortes;
* tipografia moderna;
* cards premium;
* bordas sutis;
* sombras controladas;
* glassmorphism moderado quando fizer sentido;
* elementos de HUD discretos;
* microanimações;
* indicadores de processamento;
* gráficos/linhas visuais;
* estados de descoberta;
* sensação de sistema trabalhando em segundo plano.

Evitar excesso de neon.

Evitar excesso de efeitos.

O resultado deve parecer caro e profissional, não um site gamer.

---

# LANDING PAGE

Reformular completamente a página inicial.

A primeira tela deve comunicar imediatamente:

```text
INVESTIGUE UMA PRESENÇA DIGITAL
```

ou equivalente comercial mais forte.

O visitante deve entender em poucos segundos que pode fornecer um identificador e iniciar uma investigação.

Criar CTA de destaque:

```text
INICIAR INVESTIGAÇÃO
```

ou

```text
INVESTIGAR AGORA
```

O fluxo de compra avulsa deve ter destaque.

Apresentar também a alternativa:

```text
Já possui conta? Entrar
```

sem transformar o login em barreira.

---

# COPY COMERCIAL

A página deve explicar de forma atraente o que a plataforma procura.

Dar destaque visual para fontes e categorias populares, principalmente:

* Instagram;
* WhatsApp;
* Facebook;
* TikTok;
* X/Twitter;
* LinkedIn;
* YouTube;
* outras redes sociais;
* usernames;
* e-mails;
* sites;
* menções públicas;
* possíveis perfis;
* presença digital;
* informações públicas relacionadas.

Exemplo conceitual:

```text
DESCUBRA ONDE UMA IDENTIDADE DIGITAL APARECE

Uma investigação pode cruzar diferentes fontes públicas
para encontrar sinais, perfis, usernames, sites e outras
presenças digitais relacionadas ao identificador pesquisado.
```

Não afirmar que uma conta será encontrada.

Não afirmar que o sistema consegue acessar contas privadas.

Não afirmar que WhatsApp privado será acessado.

Não afirmar que um resultado automaticamente comprova que determinada conta pertence a determinada pessoa.

O texto deve vender a **possibilidade de descoberta**, não prometer um resultado específico.

---

# ELEMENTO DE CURIOSIDADE

Criar uma seção visual semelhante a:

```text
O QUE PODE APARECER NA INVESTIGAÇÃO?

Instagram        ✓
WhatsApp         ✓
Facebook         ✓
TikTok           ✓
X / Twitter     ✓
LinkedIn         ✓
YouTube          ✓
Websites         ✓
Usernames        ✓
E-mails          ✓
Menções públicas ✓
```

Utilizar animações sutis de descoberta.

Exemplo:

```text
Buscando presença digital...
Encontrando possíveis perfis...
Comparando fontes...
Consolidando descobertas...
```

O objetivo é criar **curiosidade legítima sobre o resultado**, sem inventar resultados.

Nunca exibir um resultado falso como se fosse real.

---

# PREÇO

Apresentar a investigação avulsa com o preço configurado pelo administrador.

Não hardcodar o valor.

Exibir:

```text
INVESTIGAÇÃO AVULSA

Pagamento único
Sem assinatura
Sem cadastro obrigatório

R$ XX,XX

[ INVESTIGAR AGORA ]
```

O preço deve vir da configuração real do sistema.

---

# EXPERIÊNCIA DE COMPRA

Reformular o checkout para parecer parte do produto premium.

Fluxo:

```text
1. O que deseja investigar?
2. Identificador
3. Revisão
4. PIX
5. Processamento
6. Resultado
```

Mostrar um indicador de progresso visual.

Exemplo:

```text
IDENTIFICADOR
──────●────────
PAGAMENTO
────────●─────
INVESTIGAÇÃO
────────────●─
RESULTADO
──────────────●
```

---

# TELA DE PROCESSAMENTO

Esta é uma das partes mais importantes do redesign.

Após pagamento confirmado, não mostrar simplesmente:

```text
Processando...
```

Criar uma experiência visual de investigação.

Exemplo:

```text
INVESTIGAÇÃO EM ANDAMENTO

Analisando presença digital...

✓ Preparando fontes
✓ Consultando usernames
◉ Verificando redes sociais
◌ Consolidando descobertas
◌ Correlacionando evidências
◌ Gerando relatório
```

Mostrar:

* loading;
* skeletons;
* progresso;
* etapas;
* contadores quando reais;
* estados por fonte;
* animações;
* transições.

Os dados exibidos devem ser derivados do estado real da investigação.

Não inventar porcentagens.

Não inventar resultados.

Não inventar fontes consultadas.

---

# PRÉVIA DE DESCOBERTAS

Quando resultados reais começarem a chegar, mostrar uma prévia visual.

Por exemplo:

```text
DESCOBERTAS

Instagram
@exemplo
[imagem pública quando disponível]

TikTok
@exemplo
[imagem pública quando disponível]

Website
exemplo.com
```

A interface pode revelar gradualmente as descobertas para aumentar o interesse do usuário no processamento.

Sempre utilizar apenas dados que realmente chegaram do backend.

---

# IMAGENS

Quando uma fonte retornar imagens públicas:

* utilizar a imagem real retornada;
* preservar origem;
* apresentar thumbnail;
* permitir abrir em tamanho maior quando permitido pela estrutura atual;
* exibir origem da imagem;
* não falsificar imagens.

Quando nenhuma imagem estiver disponível:

* utilizar skeleton;
* placeholder premium;
* avatar genérico;
* estado vazio elegante.

Não criar imagens fictícias apresentadas como resultado real.

---

# RESULTADO

Reformular o relatório visualmente.

Criar apresentação premium contendo:

## Resumo

```text
INVESTIGAÇÃO CONCLUÍDA

Fontes consultadas: X
Descobertas: X
Perfis encontrados: X
Websites: X
Evidências: X
```

Os valores devem ser reais.

## Redes sociais

Dar destaque visual para:

* Instagram;
* WhatsApp;
* Facebook;
* TikTok;
* X;
* LinkedIn;
* YouTube.

Quando houver resultado real, mostrar:

* plataforma;
* username;
* URL;
* imagem pública;
* nome retornado;
* observações;
* origem;
* data/hora da observação.

Quando não houver resultado:

```text
Nenhuma ocorrência encontrada nesta fonte.
```

Não dizer:

```text
Essa pessoa não possui conta.
```

---

# EVIDÊNCIAS

Criar cards de evidência visualmente ricos.

Exemplo:

```text
INSTAGRAM

@usuario

Fonte:
Instagram / fonte pública correspondente

URL:
...

Observado em:
...

Evidência:
...

Confiança:
...
```

Mostrar claramente que a plataforma está apresentando **observações encontradas nas fontes consultadas**.

---

# CORRELAÇÃO

A correlação existente deve ser apresentada de forma visual e compreensível.

Exemplo:

```text
POSSÍVEL RELAÇÃO ENTRE DESCOBERTAS

Username semelhante
Nome compatível
Imagem compatível
Bio semelhante
```

Mostrar fatores reais registrados pelo backend.

Não apresentar o score interno como:

```text
95% ESTA É A MESMA PESSOA
```

ou equivalente.

Não transformar correlação em confirmação de identidade.

Preferir termos como:

```text
Sinais relacionados encontrados
```

ou

```text
Possíveis relações entre descobertas
```

---

# RESULTADOS PARCIAIS

Caso parte das fontes falhe:

```text
INVESTIGAÇÃO CONCLUÍDA PARCIALMENTE
```

Mostrar quais fontes:

* concluíram;
* não retornaram resultados;
* falharam;
* sofreram timeout.

Não esconder falhas para tornar o resultado aparentemente melhor.

A experiência visual, porém, deve continuar premium e clara.

---

# LOADING E MICROINTERAÇÕES

Implementar:

* skeleton loaders;
* fade;
* slide;
* progress indicators;
* pulsos discretos;
* estados de sucesso;
* estados de processamento;
* transições entre etapas;
* animações de cards entrando na tela.

As animações devem ser leves.

Respeitar acessibilidade e `prefers-reduced-motion`.

Não sacrificar performance.

---

# MOBILE

O redesign deve ser mobile-first.

Testar no mínimo:

* 360px;
* 390px;
* 414px;
* tablet;
* desktop.

A jornada:

```text
investigar → pagar → acompanhar → resultado
```

deve funcionar muito bem no celular.

---

# HEADER

Criar header moderno e limpo.

Para visitante:

```text
Logo
Como funciona
O que investigamos
Preço
Entrar
[ Investigar agora ]
```

Para usuário autenticado:

```text
Logo
Investigações
Histórico
Créditos
Conta
```

Adaptar aos recursos realmente existentes.

Não criar links para páginas inexistentes.

---

# CONFIANÇA

Transmitir confiança visual sem fazer promessas falsas.

Adicionar pequenas informações como:

```text
Pagamento único
Sem assinatura
Investigação realizada em fontes públicas
Resultado protegido por acesso exclusivo
```

Não colocar avisos legais longos no topo.

Informações sobre limitações das fontes podem ficar discretamente no rodapé, FAQ ou seção informativa.

Manter, de forma não intrusiva, a informação de que os resultados dependem das informações públicas disponíveis.

Essa informação não deve dominar a página nem destruir a conversão.

---

# FOOTER

Criar footer premium contendo:

* marca;
* como funciona;
* investigação;
* preço;
* contato;
* termos;
* privacidade;
* informação sobre fontes públicas;
* copyright.

Somente links existentes.

---

# PAINEL AUTENTICADO

O dashboard dos usuários cadastrados também deve ser modernizado para manter a identidade visual.

Melhorar:

* cards;
* histórico;
* status;
* relatórios;
* créditos;
* investigações;
* navegação.

Não transformar o dashboard em uma cópia da landing page.

A área autenticada deve parecer a continuação do mesmo produto.

---

# ADMIN

Não reformular profundamente as funcionalidades administrativas neste prompt.

A identidade visual pode ser harmonizada, mas o objetivo principal é:

```text
landing
checkout
investigação guest
processamento
resultado
dashboard do usuário
```

---

# REQUISITOS TÉCNICOS

Antes de alterar qualquer arquivo:

1. analisar a estrutura atual de Blade;
2. analisar componentes Tailwind;
3. analisar layouts;
4. analisar Vite;
5. identificar CSS existente;
6. identificar componentes reutilizáveis;
7. analisar as páginas atuais;
8. identificar o fluxo guest criado pelo Prompt 41.

Priorizar componentes reutilizáveis.

Evitar duplicação.

Não reescrever backend sem necessidade.

Não modificar contratos dos adapters.

Não modificar o pipeline de execução.

Não modificar banco salvo quando estritamente necessário para suportar uma melhoria visual/UX.

---

# RESPONSIVIDADE E ACESSIBILIDADE

Garantir:

* contraste;
* foco de teclado;
* labels;
* aria quando necessário;
* navegação sem mouse;
* `prefers-reduced-motion`;
* textos legíveis;
* botões adequados para toque.

---

# PERFORMANCE

Não carregar imagens grandes sem necessidade.

Utilizar:

* lazy loading;
* thumbnails;
* dimensões apropriadas;
* skeletons;
* carregamento progressivo.

Não adicionar bibliotecas apenas para efeitos simples que podem ser implementados com Tailwind/CSS/JS existente.

---

# TESTES

Criar/ajustar testes quando necessário para garantir que o redesign não quebre:

* landing;
* criação da investigação;
* guest flow;
* PIX;
* token;
* login;
* dashboard;
* histórico;
* relatório;
* responsividade onde houver infraestrutura de teste.

Executar:

```bash
php artisan test
```

e:

```bash
./vendor/bin/pint --test
```

Corrigir regressões.

---

# CRITÉRIO VISUAL DE CONCLUSÃO

Ao finalizar, o sistema deve apresentar claramente esta experiência:

```text
LANDING PREMIUM
      ↓
IDENTIFICADOR
      ↓
PREÇO
      ↓
PIX
      ↓
INVESTIGAÇÃO VISUAL
      ↓
LOADING / DESCOBERTAS
      ↓
PRÉVIA DE RESULTADOS
      ↓
RELATÓRIO PREMIUM
```

O visitante deve conseguir entender:

1. o que pode investigar;
2. que Instagram, WhatsApp, Facebook, TikTok, X, LinkedIn, YouTube e outras fontes públicas podem participar da busca quando suportadas;
3. quanto custa;
4. que não precisa criar conta;
5. como iniciar;
6. como acompanhar;
7. como acessar o resultado posteriormente.

A experiência deve despertar curiosidade e incentivar a ação de investigação, mas **sem fabricar descobertas, imagens, resultados ou promessas de acesso a informações privadas**.

---

# REGRAS FINAIS

Não recriar o projeto.

Não executar prompts anteriores.

Não alterar funcionalidades que já estão funcionando sem justificativa.

Primeiro analisar a implementação atual.

Depois apresentar uma lista curta das áreas que serão modificadas.

Em seguida implementar.

Ao finalizar informar:

* páginas modificadas;
* componentes criados;
* CSS/JS criado ou alterado;
* rotas modificadas, se houver;
* testes;
* Pint;
* problemas encontrados;
* possíveis melhorias futuras.

Não executar uma nova mudança de backend apenas porque ela facilitaria o design.

O objetivo principal deste prompt é transformar a plataforma existente em uma experiência **premium de investigação digital, visualmente sofisticada, responsiva e orientada à conversão**.
