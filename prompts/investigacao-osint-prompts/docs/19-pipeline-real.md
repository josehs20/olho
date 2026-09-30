# Pipeline Real de Investigação

O pipeline deve ser explícito e observável:

``` text
Investigation
  ↓
InvestigationRun
  ↓
ModuleRun[]
  ↓
Source Adapter
  ↓
Raw Result
  ↓
Normalized Result
  ↓
Deduplication
  ↓
Correlation
  ↓
Evidence
  ↓
Report
```

Cada etapa deve possuir status, timestamps, duração e erro estruturado.

Nenhum adapter deve conhecer regras de cobrança, apresentação HTML ou
correlação.
