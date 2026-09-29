# 00 — Visão geral do produto

## 1. Nome de trabalho

`Investigação Pública`.

O nome comercial pode ser alterado depois; o domínio do código não deve depender do nome de marca.

## 2. Proposta

A aplicação permite pesquisar a presença digital pública de uma pessoa ou entidade a partir de informações fornecidas pelo usuário. O produto vende créditos e cada módulo de pesquisa possui custo em créditos.

## 3. Princípios

- Evidência antes de conclusão.
- Correspondência possível não é identidade confirmada.
- Toda informação relevante deve apontar para uma fonte.
- Toda execução deve ter timestamp.
- Falhas de uma fonte não devem derrubar a investigação inteira.
- Módulos externos devem ser plugáveis.
- O domínio Laravel não deve depender de uma ferramenta OSINT específica.
- Pagamento confirmado por webhook/validação do provedor, nunca por simples retorno do navegador.
- O produto deve ser observável e auditável.

## 4. Usuários

### Cliente

Pesquisa, compra créditos, acompanha investigações, consulta relatórios, gerencia conta e solicita suporte.

### Administrador

Gerencia usuários, módulos, custos, preços, créditos manuais, pagamentos, falhas, auditoria e conteúdo operacional.

## 5. Jornada principal

```text
Landing page
  -> cadastro/login
  -> compra de créditos
  -> nova investigação
  -> definição dos dados de entrada
  -> seleção de módulos
  -> reserva de créditos
  -> fila
  -> workers
  -> normalização
  -> deduplicação
  -> correlação
  -> relatório HTML
```

## 6. MVP funcional

Obrigatório:

- autenticação;
- dashboard;
- carteira/ledger de créditos;
- pacotes de crédito;
- pedido e fluxo PIX abstrato;
- investigação por username;
- mock de Maigret, Sherlock e WhatsMyName;
- processamento por fila;
- resultados normalizados;
- deduplicação;
- evidências e fontes;
- correlação baseada em regras determinísticas;
- relatório HTML;
- histórico;
- painel admin simples;
- logs e auditoria;
- Docker;
- testes automatizados.

## 7. Fora do MVP

- cartão;
- Google/Apple Login;
- 2FA;
- app mobile;
- integração real com provedores OSINT;
- IA/LLM no produto;
- PDF;
- reconhecimento facial em massa;
- rastreamento em tempo real;
- dados privados.

## 8. Conceito de resultado

Um resultado precisa representar o que realmente foi observado, não uma opinião. Campos essenciais:

- origem;
- categoria;
- valor consultado;
- URL pública;
- título/descrição quando disponível;
- timestamp;
- evidências;
- confiança explicável;
- dados brutos controlados para auditoria.
