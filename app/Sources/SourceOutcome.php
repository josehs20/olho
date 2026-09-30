<?php

namespace App\Sources;

/**
 * Contrato de **saída** de um adapter de fonte (docs/20-adapter-contract.md).
 *
 * - `findings`: descobertas normalizadas, cada uma apontando sua evidência;
 * - `warnings`: avisos públicos da execução (ex.: itens ignorados, limite atingido);
 * - `metadata`: métricas da execução (ex.: versão da ferramenta), persistidas em
 *   `source_runs.metrics`.
 *
 * Status, duração e erro estruturado **não** fazem parte desta saída: são
 * responsabilidade do orquestrador (`ExecuteSourceJob`) e do contrato de
 * exceções (`SourceException`), de modo que o histórico da execução exista mesmo
 * quando o adapter falha.
 */
final class SourceOutcome
{
    /**
     * @param  array<int, SourceResult>  $findings
     * @param  array<int, string>  $warnings
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly array $findings = [],
        public readonly array $warnings = [],
        public readonly array $metadata = [],
    ) {}

    /**
     * @param  array<int, SourceResult>  $findings
     */
    public static function of(array $findings): self
    {
        return new self(findings: array_values($findings));
    }

    public function isEmpty(): bool
    {
        return $this->findings === [];
    }
}
