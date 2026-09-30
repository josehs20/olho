<?php

namespace App\Sources;

use App\Sources\Exceptions\SourceException;

/**
 * Contrato único de uma fonte de pesquisa (docs/20-adapter-contract.md).
 *
 * A implementação concreta pode usar processo CLI, biblioteca Python ou HTTP —
 * o domínio não conhece esses detalhes. Trocar um mock por uma ferramenta real
 * exige apenas: (1) implementar este contrato; (2) registrar no
 * `SourceRegistry` sob o mesmo `slug` de `source_definitions.slug`.
 *
 * Regras do contrato:
 * - `execute()` devolve `SourceOutcome` (findings + warnings + metadata);
 * - falhas são lançadas como `SourceException` (erro estruturado com
 *   `errorCode()` e `runStatus()`), sem apagar o histórico da execução;
 * - o adapter não acessa o banco: o que precisa vem em `InvestigationContext`.
 */
interface InvestigationSource
{
    /**
     * Slug estável da fonte. Deve casar com `source_definitions.slug`.
     */
    public function key(): string;

    /**
     * Nome amigável da fonte.
     */
    public function name(): string;

    /**
     * Versão do adapter (participa da chave de cache).
     */
    public function version(): string;

    /**
     * Indica se a fonte consegue processar a entrada fornecida.
     */
    public function supports(InvestigationInput $input): bool;

    /**
     * Executa a consulta e devolve o envelope de saída.
     *
     * @throws SourceException
     */
    public function execute(InvestigationContext $context): SourceOutcome;

    /**
     * Converte saída bruta da ferramenta em um resultado normalizado.
     */
    public function normalize(array $raw): SourceResult;

    /**
     * Verificação opcional de saúde da fonte.
     */
    public function healthCheck(): bool;
}
