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
 * Adapter mock do Holehe (por e-mail, sem integração real, sem acesso à internet).
 *
 * Simula os serviços onde o e-mail está cadastrado.
 * Cenários controlados pelo e-mail:
 *  - 'not_found' -> nenhum serviço
 *  - 'timeout'    -> SourceTimeoutException
 *  - 'error'      -> SourceUnavailableException
 *  - outro        -> serviços encontrados determinísticos
 */
final class HoleheMockAdapter implements InvestigationSource
{
    public function key(): string
    {
        return 'holehe';
    }

    public function name(): string
    {
        return 'Holehe';
    }

    public function version(): string
    {
        return 'v1';
    }

    public function supports(InvestigationInput $input): bool
    {
        return $input->email !== null;
    }

    public function execute(InvestigationContext $context): SourceOutcome
    {
        $email = $context->input->email;

        return SourceOutcome::of(match ($email) {
            'not_found' => [],
            'timeout' => throw new SourceTimeoutException('holehe timeout'),
            'error' => throw new SourceUnavailableException('holehe unavailable'),
            default => $this->fixtures($email),
        });
    }

    public function normalize(array $raw): SourceResult
    {
        return new SourceResult(
            source: $this->key(),
            entityType: $raw['entity_type'] ?? 'email_account',
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
    private function fixtures(string $email): array
    {
        $services = [
            ['twitter.com', 'Twitter'],
            ['spotify.com', 'Spotify'],
            ['instagram.com', 'Instagram'],
            ['netflix.com', 'Netflix'],
            ['amazon.com', 'Amazon'],
        ];

        return array_map(
            fn (array $service) => new SourceResult(
                source: $this->key(),
                entityType: 'email_account',
                value: $email,
                url: "https://{$service[0]}",
                title: "{$service[1]} — {$email}",
                confidence: 0.8,
                evidence: [['type' => 'email_match', 'value' => $email]],
                metadata: ['service' => $service[0]],
            ),
            $services,
        );
    }
}
