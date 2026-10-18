<?php

namespace App\Sources\Adapters;

use App\Sources\Exceptions\SourceChallengeException;
use App\Sources\Exceptions\SourceSessionException;
use App\Sources\Exceptions\SourceUnavailableException;
use App\Sources\Integrations\SessionCheckable;
use App\Sources\InvestigationContext;
use App\Sources\InvestigationInput;
use App\Sources\InvestigationSource;
use App\Sources\SourceOutcome;
use App\Sources\SourceResult;

/**
 * Adapter mock do Instagram (sem rede, determinístico).
 *
 * Espelha o contrato do `InstagramAdapter` — mesmo slug, mesmo `SourceOutcome`,
 * avatar como evidência visual — para que a suíte de testes exercite a pipeline
 * inteira (persistência, correlação, relatório) sem consultar o Instagram.
 * Cenários controlados pelo username:
 *  - 'not_found'  -> perfil inexistente (0 resultados)
 *  - 'no_session' -> sessão expirada (não consome créditos)
 *  - 'challenge'  -> verificação humana exigida
 *  - 'error'      -> Instagram indisponível
 *  - outro        -> perfil determinístico com avatar
 */
final class InstagramMockAdapter implements InvestigationSource, SessionCheckable
{
    public function key(): string
    {
        return 'instagram';
    }

    public function name(): string
    {
        return 'Instagram (instagrapi)';
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
        $username = (string) $context->input->username;

        return match ($username) {
            'not_found' => new SourceOutcome(metadata: $this->metadata($username, 0)),
            'no_session' => throw new SourceSessionException('Sessão do Instagram expirada (mock).'),
            'challenge' => throw new SourceChallengeException('O Instagram exige verificação humana (mock).'),
            'error' => throw new SourceUnavailableException('Instagram indisponível (mock).'),
            default => new SourceOutcome(
                findings: [$this->fixture($username)],
                metadata: $this->metadata($username, 1),
            ),
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
     * Diagnóstico da credencial para a ação "Testar conexão" (§2.1).
     *
     * O mock não tem cookie nenhum para validar: reporta a sessão como ativa
     * quando há um `sessionid` salvo (o que o painel precisa para exercitar o
     * fluxo de status sem tocar na rede).
     *
     * @return array{healthy: bool, challenge: bool, message: ?string, account: ?string}
     */
    public function checkSession(): array
    {
        return [
            'healthy' => true,
            'challenge' => false,
            'account' => 'instagram (mock)',
            'message' => null,
        ];
    }

    private function fixture(string $username): SourceResult
    {
        $handle = strtolower($username);
        $name = str_replace(['.', '_'], ' ', $handle);

        return new SourceResult(
            source: $this->key(),
            entityType: 'social_profile',
            value: $handle,
            url: "https://www.instagram.com/{$handle}/",
            title: ucwords($name),
            description: 'Perfil público de demonstração.',
            confidence: 0.6,
            evidence: [
                ['type' => 'avatar', 'value' => "https://cdn.example.test/instagram/{$handle}.jpg"],
                ['type' => 'reference', 'value' => "account: https://www.instagram.com/{$handle}/"],
                ['type' => 'reference', 'value' => 'followers: 1234'],
                ['type' => 'reference', 'value' => 'posts: 42'],
            ],
            metadata: [
                'tool' => 'instagrapi',
                'tool_version' => 'mock',
                'username' => $handle,
                'user_id' => (string) crc32($handle),
                'avatar_available' => true,
                'is_private' => false,
                'is_verified' => false,
                'visual_correlation' => 'pending',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(string $username, int $found): array
    {
        return [
            'tool' => 'instagrapi',
            'tool_version' => 'mock',
            'username' => strtolower($username),
            'profiles_found' => $found,
            'session_status' => 'HEALTHY',
            'visual_correlation' => 'pending',
        ];
    }
}
