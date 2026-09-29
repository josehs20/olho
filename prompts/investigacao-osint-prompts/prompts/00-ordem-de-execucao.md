# Ordem de execução

Execute os prompts nesta ordem. Não pule etapa crítica sem justificar.

```text
01 analisar projeto
02 estrutura base
03 autenticação
04 banco
05 créditos
06 pagamento PIX fake
07 investigação
08 source adapter
09 queue/workers
10 Maigret mock
11 Sherlock mock
12 WhatsMyName mock
13 Holehe mock
14 normalização
15 deduplicação
16 correlação
17 evidências
18 relatório
19 dashboard cliente
20 admin
21 cache/idempotência
22 logs/auditoria
23 privacidade
24 segurança
25 testes
26 Docker
27 auditoria final
```

Regra: cada prompt deve ser executado contra o estado real do repositório, não contra uma suposição de que a etapa anterior foi concluída.
