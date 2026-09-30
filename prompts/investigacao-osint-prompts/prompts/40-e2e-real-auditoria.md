# Prompt 40 --- E2E real e auditoria

Execute uma investigação completa usando as integrações reais
disponíveis.

Fluxo obrigatório:

usuário → créditos → investigação → queue → workers → adapters reais →
resultados brutos → normalização → deduplicação → correlação →
evidências → relatório.

Registrar: - duração por módulo; - quantidade de resultados; - falhas; -
timeouts; - retries; - consumo; - resultado final.

Executar toda a suíte de testes e Pint.

Não declarar integração concluída se algum módulo estiver mockado sem
indicar isso.

Gerar um relatório de integração com: - módulos realmente executados; -
versões; - limitações; - problemas encontrados; - testes; - arquivos
alterados; - commit final.
