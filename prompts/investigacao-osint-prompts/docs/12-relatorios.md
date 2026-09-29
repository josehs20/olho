# 12 — Relatórios HTML

## Objetivo

Gerar página pronta para leitura e impressão pelo navegador.

## Estrutura

```text
Cabeçalho
Resumo
Entradas informadas
Módulos executados
Resumo por módulo
Resultados
Correlação
Evidências
Fontes
Falhas/limitações
Data e identificação da execução
```

## Segurança

Escapar todo conteúdo de fontes externas antes de renderizar. URLs e HTML remoto nunca devem ser injetados diretamente.

## Snapshot

Relatório deve apontar para um run específico. Atualizações posteriores não devem alterar silenciosamente um relatório histórico já concluído.

## Acesso

Somente o usuário dono e admins autorizados podem abrir o relatório.

Usar autorização por policy e URLs não previsíveis.

## Compartilhamento futuro

Não implementar no MVP. Se implementado, usar token revogável, expiração e escopo mínimo.
