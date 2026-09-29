<?php

namespace App\Sources\Mocks;

use App\Sources\Exceptions\SourceTimeoutException;
use App\Sources\Exceptions\SourceUnavailableException;
use App\Sources\InvestigationContext;
use App\Sources\InvestigationInput;
use App\Sources\InvestigationSource;
use App\Sources\SourceResult;

/**
 * Base para mocks determinísticos. O cenário é controlado pelo valor de entrada:
 *  - 'timeout' -> SourceTimeoutException
 *  - 'error'   -> SourceUnavailableException
 *  - 'empty'   -> nenhum resultado
 *  - outro     -> resultados determinísticos
 */
abstract class MockSource implements InvestigationSource
{
    public function healthCheck(): bool
    {
        return true;
    }

    abstract public function key(): string;

    abstract public function name(): string;

    abstract public function supports(InvestigationInput $input): bool;

    public function execute(InvestigationContext $context): array
    {
        $value = $this->scenarioValue($context->input);

        return match ($value) {
            'timeout' => throw new SourceTimeoutException($this->key().' timeout'),
            'error' => throw new SourceUnavailableException($this->key().' unavailable'),
            'empty', null => [],
            default => $this->buildResults($value),
        };
    }

    public function normalize(array $raw): SourceResult
    {
        return new SourceResult(
            source: $this->key(),
            entityType: $raw['entity_type'] ?? 'unknown',
            value: $raw['value'] ?? '',
            url: $raw['url'] ?? null,
            title: $raw['title'] ?? null,
            description: $raw['description'] ?? null,
            confidence: $raw['confidence'] ?? null,
            evidence: $raw['evidence'] ?? [],
            metadata: $raw['metadata'] ?? [],
        );
    }

    abstract protected function scenarioValue(InvestigationInput $input): ?string;

    /**
     * @return SourceResult[]
     */
    abstract protected function buildResults(string $value): array;
}
