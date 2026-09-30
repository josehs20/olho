<?php

namespace App\Sources\Adapters;

use App\Sources\Exceptions\SourceTimeoutException;
use App\Sources\Exceptions\SourceUnavailableException;
use App\Sources\InvestigationContext;
use App\Sources\InvestigationInput;
use App\Sources\InvestigationSource;
use App\Sources\SourceOutcome;
use App\Sources\SourceResult;

/**
 * Adapter mock do Sherlock (sem integração real, sem acesso à internet).
 *
 * Cenários controlados pelo username:
 *  - 'not_found' -> nenhum perfil
 *  - 'timeout'    -> SourceTimeoutException
 *  - 'error'      -> SourceUnavailableException (erro sanitizado)
 *  - outro        -> múltiplos perfis determinísticos
 */
final class SherlockMockAdapter implements InvestigationSource
{
    public function key(): string
    {
        return 'sherlock';
    }

    public function name(): string
    {
        return 'Sherlock';
    }

    public function version(): string
    {
        return 'v1';
    }

    public function supports(InvestigationInput $input): bool
    {
        return $input->username !== null;
    }

    public function execute(InvestigationContext $context): SourceOutcome
    {
        $username = $context->input->username;

        return SourceOutcome::of(match ($username) {
            'not_found' => [],
            'timeout' => throw new SourceTimeoutException('sherlock timeout'),
            'error' => throw new SourceUnavailableException('sherlock unavailable'),
            default => $this->fixtures($username),
        });
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
        $networks = [
            ['twitter', 0.9],
            ['facebook', 0.8],
            ['youtube', 0.7],
            ['twitch', 0.6],
            ['pinterest', 0.5],
        ];

        return array_map(
            fn (array $network) => new SourceResult(
                source: $this->key(),
                entityType: 'social_profile',
                value: $username,
                url: "https://{$network[0]}.com/{$username}",
                title: ucfirst($network[0])." — {$username}",
                confidence: $network[1],
                evidence: [['type' => 'username_match', 'value' => $username]],
            ),
            $networks,
        );
    }
}
