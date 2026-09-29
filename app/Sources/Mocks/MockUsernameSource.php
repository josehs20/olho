<?php

namespace App\Sources\Mocks;

use App\Sources\InvestigationInput;
use App\Sources\SourceResult;

final class MockUsernameSource extends MockSource
{
    public function key(): string
    {
        return 'mock-username';
    }

    public function name(): string
    {
        return 'Mock Username';
    }

    public function supports(InvestigationInput $input): bool
    {
        return $input->username !== null;
    }

    protected function scenarioValue(InvestigationInput $input): ?string
    {
        return $input->username;
    }

    protected function buildResults(string $value): array
    {
        return [
            new SourceResult(
                source: $this->key(),
                entityType: 'social_profile',
                value: $value,
                url: "https://example.com/{$value}",
                title: "Perfil de {$value}",
                evidence: [['type' => 'username_match', 'value' => $value]],
            ),
            new SourceResult(
                source: $this->key(),
                entityType: 'social_profile',
                value: $value,
                url: "https://twitter.com/{$value}",
                title: "@{$value}",
                evidence: [['type' => 'username_match', 'value' => $value]],
            ),
        ];
    }
}
