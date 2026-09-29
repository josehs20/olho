# 11 — Correlação

## Objetivo

Agrupar sinais públicos compatíveis e explicar por que dois resultados podem pertencer à mesma entidade, sem afirmar identidade como certeza automática.

## MVP

Usar regras determinísticas transparentes.

Exemplo de pesos configuráveis:

- username exatamente igual: +40;
- nome normalizado compatível: +20;
- imagem/referência pública compatível, quando tecnicamente suportado: +20;
- bio/descrição semelhante: +10;
- cidade/estado compatível: +10.

Os pesos são parâmetros de produto, não uma verdade universal.

## Score

Persistir:

- score;
- versão da regra;
- sinais usados;
- evidências relacionadas.

## Faixas de exibição

Usar termos neutros e explicáveis, por exemplo:

- baixa correspondência;
- possível correspondência;
- correspondência forte por sinais públicos.

Evitar linguagem que transforme inferência em fato.

## Não fazer

- inferir atributos sensíveis sem base apropriada;
- inferir localização privada;
- usar reconhecimento facial de pessoas como funcionalidade automática do MVP;
- afirmar identidade certa apenas por username/nome.

## Futuro

A arquitetura pode permitir um componente de IA para reclassificação/explicação, mas qualquer uso futuro deverá manter rastreabilidade e não substituir a evidência primária.
