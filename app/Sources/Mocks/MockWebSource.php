<?php

namespace App\Sources\Mocks;

use App\Sources\InvestigationInput;
use App\Sources\SourceResult;

final class MockWebSource extends MockSource
{
    public function key(): string
    {
        return 'mock-web';
    }

    public function name(): string
    {
        return 'Mock Web';
    }

    public function supports(InvestigationInput $input): bool
    {
        return $input->name !== null || $input->username !== null;
    }

    protected function scenarioValue(InvestigationInput $input): ?string
    {
        return $input->name ?? $input->username;
    }

    protected function buildResults(string $value): array
    {
        return [
            new SourceResult(
                source: $this->key(),
                entityType: 'web_page',
                value: $value,
                url: "https://example.com/search?q={$value}",
                title: "Resultado para {$value}",
                evidence: [['type' => 'public_page_reference', 'value' => $value]],
            ),
        ];
    }
}
