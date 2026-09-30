# Relatório baseado em evidências (prompt 38)

O relatório HTML passou a ser uma leitura dos **dados persistidos do pipeline**
(resultados, evidências, execuções por módulo e correlação), com um
**manifesto congelado** por run.

## O que o relatório mostra

| Seção | Conteúdo |
|---|---|
| Cabeçalho | investigação, run, status, início/conclusão, geração do relatório, versão e hash do manifesto |
| Resumo | status, créditos consumidos/reservados, indicador de correlação, descobertas (e quantas consolidadas), fontes concluídas/falhas/não aplicáveis, resultados observados |
| Entradas informadas | identificadores pesquisados (nome, username, e-mail, telefone, cidade, estado) |
| Fontes consultadas | por módulo: nome+slug, tipo, status de exibição, versão da ferramenta, resultados, tentativas, duração e observação pública |
| Descobertas | descoberta consolidada: título/valor, tipo, plataforma, URL, **data/hora de observação**, confiança, evidências e **todas as fontes observadoras** (com URL e data de cada observação) |
| Correlação | indicador, regra, faixa, tabela de fatores (tipo, peso, explicação, evidências, fontes, limitação), não avaliados |
| Falhas e limitações | fontes com falha/timeout/não aplicáveis (com mensagem pública), fontes ainda não concluídas, avisos das fontes, limitações da correlação e aviso do módulo Holehe |
| Rodapé | data de geração, run e hash do manifesto |

Nada é escondido para parecer investigação completa: módulo que falhou aparece
na tabela de fontes **e** nas limitações.

## Manifesto (snapshot alinhado ao modelo atual)

O modelo já persiste resultados, evidências e correlação por run, então **não** é
necessário duplicar o HTML. O que é congelado é o **manifesto**
(`reports.metadata`, versão `report-manifest-v1`, com `content_hash` sha256):

```text
investigation, run (status, motivo, datas, créditos, etapas do pipeline),
identifiers, modules_requested, sources[] (nome, status, versão, resultados,
tentativas, duração, mensagens), summary (contagens), discoveries[]
(consolidadas + observações), correlation, limitations, sources_failed[]
```

Motivo (docs/12-relatorios.md: "atualizações posteriores não devem alterar
silenciosamente um relatório histórico"): o manifesto guarda o estado no momento
da emissão. Renomear um módulo, desativar uma fonte ou mudar um peso de
correlação depois **não** altera o relatório já emitido — o controller prefere o
manifesto persistido e só recalcula quando ele não existe (run em andamento) ou
está em versão antiga.

## Segurança

- todo conteúdo de fonte externa é escapado pela Blade (`{{ }}`);
- URLs só viram link quando começam com `http` (defesa contra `javascript:`);
- acesso restrito ao dono (policy) e a admins autorizados.

## Testes (`ReportTest`)

- seções obrigatórias: identificadores, fontes com status, descoberta com URL,
  data de observação e evidência, correlação com fatores e limitações;
- falha/timeout/não aplicável **visíveis**, com a mensagem pública e o aviso do
  Holehe;
- manifesto persistido com versão, `content_hash`, identificadores e contagens;
- **congelamento**: renomear o módulo depois não muda o relatório emitido;
- acesso negado para outro usuário (403).
