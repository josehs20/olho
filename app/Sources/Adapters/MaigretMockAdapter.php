<?php

namespace App\Sources\Adapters;

use App\Sources\Exceptions\SourceTimeoutException;
use App\Sources\Exceptions\SourceUnavailableException;
use App\Sources\InvestigationContext;
use App\Sources\InvestigationInput;
use App\Sources\InvestigationSource;
use App\Sources\SourceResult;

/**
 * Adapter mock do Maigret (sem integração real, sem acesso à internet).
 *
 * Cenários controlados pelo username:
 *  - 'not_found' -> nenhum resultado
 *  - 'timeout'    -> SourceTimeoutException
 *  - 'error'      -> SourceUnavailableException
 *  - outro        -> fixtures determinísticas (found)
 */
final class MaigretMockAdapter implements InvestigationSource
{
    public function key(): string
    {
        return 'maigret';
    }

    public function name(): string
    {
        return 'Maigret';
    }

    public function supports(InvestigationInput $input): bool
    {
        return $input->username !== null;
    }

    public function execute(InvestigationContext $context): array
    {
        $username = $context->input->username;

        return match ($username) {
            'not_found' => [],
            'timeout' => throw new SourceTimeoutException('maigret timeout'),
            'error' => throw new SourceUnavailableException('maigret unavailable'),
            default => $this->fixtures($username),
        };
    }

    public function normalize(array $raw): SourceResult
    {
        return new SourceResult(
            source: $this->key(),
            entityType: $raw['entity_type'] ?? 'social_profile',
            value: $raw['value'] ?? '',
            url: $raw['url'] ?? null,
            title: $raw['title'] ?? null,
            description: $raw['description'] ?? null,
            confidence: $raw['confidence'] ?? null,
            evidence: $raw['evidence'] ?? [],
            metadata: $raw['metadata'] ?? [],
        );
    }

    public function healthCheck(): bool
    {
        return true;
    }

    /**
     * @return SourceResult[]
     */
    private function fixtures(string $username): array
    {
        return [
            new SourceResult(
                source: $this->key(),
                entityType: 'social_profile',
                value: $username,
                url: "https://github.com/{$username}",
                title: "GitHub — {$username}",
                confidence: 0.9,
                evidence: [['type' => 'username_match', 'value' => $username]],
            ),
            new SourceResult(
                source: $this->key(),
                entityType: 'social_profile',
                value: $username,
                url: "https://instagram.com/{$username}",
                title: "Instagram — {$username}",
                confidence: 0.7,
                evidence: [['type' => 'username_match', 'value' => $username]],
            ),
            new SourceResult(
                source: $this->key(),
                entityType: 'social_profile',
                value: $username,
                url: "https://reddit.com/user/{$username}",
                title: "Reddit — {$username}",
                confidence: 0.6,
                evidence: [['type' => 'username_match', 'value' => $username]],
            ),
        ];
    }
}
