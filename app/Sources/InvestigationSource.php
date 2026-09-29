<?php

namespace App\Sources;

interface InvestigationSource
{
    /**
     * Slug estável da fonte.
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
     * Executa a consulta e retorna os resultados normalizados (0..n).
     *
     * @return SourceResult[]
     */
    public function execute(InvestigationContext $context): array;

    /**
     * Converte saída bruta em um resultado normalizado.
     */
    public function normalize(array $raw): SourceResult;

    /**
     * Verificação opcional de saúde da fonte.
     */
    public function healthCheck(): bool;
}
