# 03 — Arquitetura

## 1. Estilo

Monólito modular Laravel + workers assíncronos.

Não criar microserviços independentes no MVP. O isolamento relevante ocorrerá entre o web app e os workers de tarefas externas.

## 2. Camadas

```text
HTTP/UI
  -> Actions / Controllers / Livewire
  -> Application Services
  -> Domain
  -> Infrastructure adapters
  -> Queue
  -> Storage / DB / Redis
```

### Domain

Regras de negócio puras: investigação, créditos, pagamentos, resultados, correlação.

### Application

Casos de uso: criar investigação, reservar créditos, iniciar run, gerar relatório.

### Infrastructure

Banco, Redis, arquivos, APIs externas, processos de ferramentas OSINT.

### Presentation

Blade, Livewire, rotas e controllers mínimos.

## 3. Contextos

- Identity/Auth
- Billing/Credits
- Investigations
- Sources
- Evidence
- Correlation
- Reports
- Administration
- Audit

## 4. Adapters

Cada ferramenta externa implementa contrato próprio, nunca acessado diretamente por controller.

Exemplo conceitual:

```php
interface InvestigationSource
{
    public function key(): string;
    public function supports(InvestigationInput $input): bool;
    public function execute(InvestigationContext $context): SourceResult;
}
```

## 5. Execução externa

MVP usa mocks. Depois, adaptadores reais poderão executar:

- Maigret;
- Sherlock;
- WhatsMyName;
- Holehe.

A execução deve ocorrer em worker controlado, com timeout, limite de recursos, logs sanitizados e sem input de shell inseguro.

## 6. Jobs

Jobs sugeridos:

- StartInvestigationRunJob
- ExecuteSourceJob
- NormalizeSourceResultsJob
- DeduplicateResultsJob
- BuildCorrelationJob
- BuildHtmlReportJob
- ReleaseReservedCreditsJob

## 7. Comunicação

Jobs devem receber apenas IDs e referências seguras sempre que possível; consultar dados no banco no início do processamento. Evitar payloads gigantes na fila.

## 8. Idempotência

Cada run + source deve possuir uma chave lógica única.

Exemplo:

`run_id + source_id`

Não iniciar novamente uma fonte já concluída sem pedido explícito de reexecução.

## 9. Frontend

Blade para páginas server-rendered.
Livewire somente para:

- atualização de status;
- filtros;
- componentes de saldo;
- formulários com interações reais;
- tabelas administrativas.

Não transformar tudo em Livewire por padrão.

## 10. Relatório

HTML renderizado pelo servidor. CSS preparado para leitura e impressão pelo navegador, sem dependência obrigatória de JS.
