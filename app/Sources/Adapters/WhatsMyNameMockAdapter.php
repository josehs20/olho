<?php

namespace App\Sources\Adapters;

use App\Sources\Exceptions\SourceTimeoutException;
use App\Sources\Exceptions\SourceUnavailableException;
use App\Sources\InvestigationContext;
use App\Sources\InvestigationInput;
use App\Sources\InvestigationSource;
use App\Sources\SourceResult;

/**
 * Adapter mock do WhatsMyName (sem integração real, sem acesso à internet).
 *
 * Retorna múltiplas fontes/itens para permitir testar a deduplicação.
 * Cenários controlados pelo username:
 *  - 'not_found' -> nenhum item
 *  - 'timeout'    -> SourceTimeoutException
 *  - 'error'      -> SourceUnavailableException
 *  - outro        -> múltiplos itens determinísticos
 */
final class WhatsMyNameMockAdapter implements InvestigationSource
{
    public function key(): string
    {
        return 'whatsmyname';
    }

    public function name(): string
    {
        return 'WhatsMyName';
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
            'timeout' => throw new SourceTimeoutException('whatsmyname timeout'),
            'error' => throw new SourceUnavailableException('whatsmyname unavailable'),
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
        $sites = [
            ['github.com', 'GitHub'],
            ['gitlab.com', 'GitLab'],
            ['bitbucket.org', 'Bitbucket'],
            ['codeberg.org', 'Codeberg'],
            ['sourcehut.org', 'Sourcehut'],
        ];

        return array_map(
            fn (array $site) => new SourceResult(
                source: $this->key(),
                entityType: 'social_profile',
                value: $username,
                url: "https://{$site[0]}/{$username}",
                title: "{$site[1]} — {$username}",
                confidence: 0.8,
                evidence: [['type' => 'username_match', 'value' => $username]],
                metadata: ['site' => $site[0]],
            ),
            $sites,
        );
    }
}
