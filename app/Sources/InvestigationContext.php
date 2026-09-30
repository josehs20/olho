<?php

namespace App\Sources;

/**
 * Contrato de **entrada** do adapter (docs/20-adapter-contract.md).
 *
 * Reúne o que a fonte precisa para executar, já resolvido pelo orquestrador:
 * identificadores pesquisados, ids do run/fonte, parâmetros da fonte
 * (`SourceDescriptor`) e o id de correlação da execução.
 *
 * Os parâmetros além de `input` são opcionais para manter a compatibilidade
 * com chamadas diretas em testes.
 */
final class InvestigationContext
{
    public function __construct(
        public readonly InvestigationInput $input,
        public readonly ?int $investigationRunId = null,
        public readonly ?int $sourceRunId = null,
        public readonly ?SourceDescriptor $source = null,
        public readonly ?string $correlationId = null,
    ) {}
}
