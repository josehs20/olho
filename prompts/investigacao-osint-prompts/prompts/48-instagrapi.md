# Prompt 48 — Enriquecimento de Perfis, Imagens e Conteúdo Público

## CONTEXTO

Os módulos Maigret, Sherlock, WhatsMyName e Holehe já estão integrados ao OLHO.

Atualmente, os resultados encontrados apresentam principalmente URLs de perfis.

Queremos transformar esses resultados em informações mais úteis através de uma etapa de **enriquecimento de perfil**, aproveitando as URLs públicas encontradas.

O objetivo é coletar somente informações que estejam publicamente disponíveis e acessíveis sem autenticação, bypass ou acesso a áreas privadas.

---

## 1. AUDITAR PRIMEIRO

Antes de implementar:

* localizar o pipeline atual;
* localizar resultados normalizados;
* localizar `Evidence`;
* localizar `ModuleRun`;
* localizar armazenamento de arquivos/imagens;
* localizar relatórios;
* localizar adapters existentes;
* verificar como URLs encontradas são persistidas.

Reutilizar as estruturas existentes.

Não reconstruir os módulos OSINT.

---

# 2. NOVA ETAPA

Criar uma etapa:

```text
OSINT
 ↓
Resultados encontrados
 ↓
Deduplicação
 ↓
Profile Enrichment
 ↓
Evidências
 ↓
Correlação
 ↓
Relatório
```

O enriquecimento deve ser executado somente para resultados encontrados.

---

# 3. INFORMAÇÕES A TENTAR OBTER

Para cada perfil público encontrado, tentar obter, quando disponível:

* foto/avatar público;
* nome exibido;
* username;
* bio;
* descrição;
* localização pública;
* website;
* título da página;
* descrição Open Graph;
* `og:image`;
* imagens públicas explicitamente associadas ao perfil;
* metadados públicos relevantes.

Cada informação deve possuir sua fonte.

---

# 4. IMAGEM DE PERFIL

Priorizar:

1. `og:image`;
2. avatar público encontrado no HTML;
3. JSON-LD;
4. metadados estruturados;
5. outra imagem explicitamente associada ao perfil público.

Não utilizar:

* login;
* cookies de usuário;
* credenciais;
* sessões privadas;
* bypass de autenticação;
* endpoints privados;
* técnicas para contornar bloqueios da plataforma.

Se não for possível obter a imagem, manter a URL do perfil normalmente.

---

# 5. EVIDÊNCIA VISUAL

Quando uma imagem pública for encontrada, registrar:

```text
Tipo: PROFILE_IMAGE
URL da fonte
URL da imagem
Plataforma
Data/hora da coleta
Método utilizado
```

Se for possível armazenar uma cópia de forma compatível com a implementação existente, utilizar o storage existente.

Não criar armazenamento paralelo desnecessário.

---

# 6. APRESENTAÇÃO NO OLHO

Alterar o resultado para permitir visualização mais rica.

Exemplo:

```text
┌─────────────────────────────────┐
│ [ FOTO ]                        │
│                                 │
│ Instagram                       │
│ @usuario                        │
│ Nome exibido                    │
│ Bio pública                     │
│                                 │
│ Ver perfil                      │
│ Evidências                      │
└─────────────────────────────────┘
```

Quando não houver imagem:

```text
Imagem pública não encontrada
```

Não utilizar imagem fictícia.

---

# 7. EVIDÊNCIAS

Cada dado encontrado deve continuar vinculado à fonte.

Exemplo:

```text
Imagem
Fonte: Instagram
Tipo: PROFILE_IMAGE
Coletado em: 03/10/2026
```

Não apresentar informação inferida como se fosse informação encontrada.

---

# 8. CORRELAÇÃO VISUAL

A imagem pode ser utilizada como **sinal de correlação**, mas nunca como confirmação automática de identidade.

Exemplo:

```text
Sinais relacionados

✓ Mesmo username
✓ Nome compatível
✓ Imagem pública disponível
✓ Bio semelhante
```

Não utilizar:

> "Identidade confirmada."

Não implementar reconhecimento facial ou identificação biométrica neste prompt.

---

# 9. STORIES E CONTEÚDO TEMPORÁRIO

Adicionar suporte experimental para **detectar conteúdo temporário publicamente acessível**, somente quando a plataforma disponibilizar esse conteúdo sem autenticação e sem necessidade de contornar controles de privacidade.

O sistema poderá:

* detectar se existe conteúdo público acessível;
* coletar o conteúdo quando permitido;
* registrar URL;
* registrar plataforma;
* registrar data/hora;
* registrar horário da coleta;
* registrar evidência.

Não implementar:

* visualização anônima;
* ocultação deliberada da identidade do visualizador;
* bypass de viewer list;
* uso de conta falsa para contornar controles;
* acesso a Stories privados;
* bypass de login;
* bypass de bloqueios;
* exploração de APIs privadas;
* roubo/reutilização de cookies;
* credenciais de terceiros.

Se a plataforma exigir autenticação ou impedir acesso público, registrar:

```text
CONTEÚDO NÃO ACESSÍVEL PUBLICAMENTE
```

e continuar a investigação normalmente.

---

# 10. STORIES PÚBLICOS

Quando houver conteúdo público acessível, registrar:

```text
Tipo: STORY_PUBLIC
Plataforma
Perfil
URL
Data da coleta
Hora da coleta
Data de expiração, se disponível
Arquivo/imagem, quando permitido
```

Não prometer que Stories serão encontrados.

A disponibilidade depende da plataforma e da visibilidade pública.

---

# 11. DEDUPLICAÇÃO

Evitar armazenar repetidamente:

* mesma imagem;
* mesma URL;
* mesma evidência;
* mesmo conteúdo.

Utilizar hash quando fizer sentido.

Reutilizar mecanismos existentes de deduplicação.

---

# 12. RATE LIMIT E PERFORMANCE

O enriquecimento não deve transformar uma investigação simples em uma execução extremamente longa.

Implementar:

* timeout por página;
* limite de imagens;
* limite de tamanho;
* controle de requisições;
* tratamento de bloqueios;
* falha isolada por perfil.

Se um perfil não puder ser enriquecido, isso não deve interromper toda a investigação.

---

# 13. STATUS

Registrar separadamente:

```text
ENRICHMENT_PENDING
ENRICHMENT_RUNNING
ENRICHMENT_SUCCESS
ENRICHMENT_PARTIAL
ENRICHMENT_FAILED
ENRICHMENT_SKIPPED
```

Não considerar falha de enriquecimento como falha da investigação principal.

---

# 14. CRÉDITOS

Não criar cobrança adicional automaticamente.

Primeiro auditar o sistema atual de créditos e definir o comportamento mais compatível com a regra existente.

Não duplicar lógica de créditos.

Se o enriquecimento possuir custo, utilizar a mesma estrutura configurável já criada no sistema, sem valores hardcoded.

---

# 15. LOGS

Registrar:

* perfil processado;
* plataforma;
* URL;
* início;
* fim;
* duração;
* status;
* quantidade de dados encontrados;
* quantidade de imagens;
* erro;
* método utilizado.

Nunca registrar:

* senhas;
* cookies;
* tokens privados;
* credenciais.

---

# 16. FALHAS

Uma falha em uma plataforma não deve interromper as demais.

Exemplo:

```text
Instagram → SUCCESS
Facebook → SUCCESS
TikTok → PARTIAL
X → FAILED
LinkedIn → SUCCESS
```

A investigação continua.

---

# 17. PAINEL ADMIN — TESTES

Aproveitar o painel criado no Prompt 47.

Adicionar opção:

```text
Testes
 ├── Módulos
 └── Enriquecimento
```

Permitir informar uma URL pública encontrada e executar somente o enriquecimento daquela URL.

Mostrar:

* duração;
* status;
* informações encontradas;
* imagens encontradas;
* erros;
* logs;
* fonte;
* método.

Isso permitirá testar o enriquecimento isoladamente sem executar uma investigação completa.

---

# 18. SEGURANÇA

Toda URL externa deve ser tratada como entrada não confiável.

Evitar:

* SSRF;
* execução arbitrária;
* shell injection;
* download ilimitado;
* arquivos maliciosos;
* conteúdo HTML não sanitizado.

Validar:

* protocolo;
* domínio;
* MIME;
* tamanho;
* extensão;
* redirects;
* timeout.

---

# 19. PRIVACIDADE E ESCOPO

O sistema deve trabalhar somente com informações públicas e legitimamente acessíveis.

Não tentar acessar:

* perfis privados;
* conteúdo privado;
* contas autenticadas;
* mensagens;
* dados atrás de login;
* conteúdo oculto por mecanismos de privacidade.

Quando algo não estiver publicamente acessível, registrar a limitação.

---

# 20. NÃO ALTERAR O QUE JÁ FUNCIONA

Não modificar desnecessariamente:

* Maigret;
* Sherlock;
* WhatsMyName;
* Holehe;
* pipeline existente;
* regras de crédito;
* investigação normal;
* correlação existente;
* painel de testes existente.

Adicionar o enriquecimento aproveitando a arquitetura atual.

---

# 21. VALIDAÇÃO

Não executar a suíte completa.

Não executar E2E completo.

Não executar uma investigação longa.

Fazer somente verificações rápidas de:

* sintaxe;
* rotas;
* imports;
* migrations;
* storage;
* permissões;
* erros evidentes.

A validação funcional será manual.

---

# 22. TESTE MANUAL

Depois da implementação:

1. acessar `/admin/testes`;
2. testar uma URL pública de Instagram;
3. verificar foto;
4. verificar nome;
5. verificar username;
6. verificar bio;
7. verificar evidências;
8. verificar logs;
9. verificar duração;
10. testar outra plataforma;
11. testar uma URL sem imagem;
12. testar uma URL inacessível;
13. verificar que uma falha não interrompe a investigação.

Para conteúdo temporário, testar somente conteúdo que esteja publicamente acessível sem autenticação.

---

# 23. CRITÉRIO DE CONCLUSÃO

O prompt estará concluído quando:

* o enriquecimento estiver integrado ao pipeline;
* perfis encontrados puderem ser enriquecidos;
* imagens públicas puderem ser identificadas quando disponíveis;
* informações públicas adicionais forem exibidas;
* evidências forem registradas;
* logs forem gerados;
* falhas forem isoladas;
* painel administrativo permitir testar o enriquecimento;
* conteúdo público temporário puder ser detectado quando tecnicamente acessível;
* nenhuma técnica de bypass ou acesso privado for implementada.

---

# 24. RELATÓRIO FINAL

```text
IMPLEMENTAÇÃO CONCLUÍDA

Arquivos alterados:
- ...

Implementado:
- Profile Enrichment
- Imagens públicas
- Dados públicos do perfil
- Evidências
- Logs
- Teste individual no Admin
- Detecção de conteúdo público temporário, quando acessível

Verificações rápidas:
✓ ...

Testes completos:
NÃO EXECUTADOS.

TESTAR MANUALMENTE:
1. Instagram
2. Facebook
3. TikTok
4. X
5. LinkedIn
6. Imagem
7. Dados do perfil
8. Evidências
9. Logs
10. Conteúdo público temporário
```

Não executar testes longos automaticamente.

Não criar mecanismos para visualizar conteúdo privado ou ocultar deliberadamente a identidade do visualizador.

Não realizar refatorações fora do objetivo deste prompt.
