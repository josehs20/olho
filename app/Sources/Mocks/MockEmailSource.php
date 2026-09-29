<?php

namespace App\Sources\Mocks;

use App\Sources\InvestigationInput;
use App\Sources\SourceResult;

final class MockEmailSource extends MockSource
{
    public function key(): string
    {
        return 'mock-email';
    }

    public function name(): string
    {
        return 'Mock Email';
    }

    public function supports(InvestigationInput $input): bool
    {
        return $input->email !== null;
    }

    protected function scenarioValue(InvestigationInput $input): ?string
    {
        return $input->email;
    }

    protected function buildResults(string $value): array
    {
        return [
            new SourceResult(
                source: $this->key(),
                entityType: 'email_account',
                value: $value,
                url: "https://example.com/account/{$value}",
                title: "Conta {$value}",
                evidence: [['type' => 'email_match', 'value' => $value]],
            ),
        ];
    }
}
