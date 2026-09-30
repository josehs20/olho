<?php

namespace App\Sources\Adapters\Maigret;

use App\Sources\SourceResult;

/**
 * Converte o relatório JSON `simple` do Maigret para o contrato comum
 * (`SourceResult`).
 *
 * Formato (Maigret 0.6.x):
 *
 * ```json
 * { "GitHub": { "site": {...}, "username": "...", "url_user": "...",
 *               "http_status": 200, "rank": 10, "status": {...} } }
 * ```
 */
final class MaigretReportParser
{
    /**
     * Confiança heurística: o site respondeu que o username está reivindicado.
     * É um sinal público, não confirmação de identidade (docs/02, docs/11).
     */
    private const CLAIMED_CONFIDENCE = 0.8;

    /**
     * @param  array<string, mixed>  $report
     * @return array<int, SourceResult>
     */
    public function parse(array $report): array
    {
        $results = [];

        foreach ($report as $siteName => $entry) {
            if (! is_string($siteName) || ! is_array($entry)) {
                continue;
            }

            $result = $this->toSourceResult($siteName, $entry);

            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    public function toSourceResult(string $siteName, array $entry): ?SourceResult
    {
        $url = $this->profileUrl($entry);

        if ($url === null) {
            return null;
        }

        $status = is_array($entry['status'] ?? null) ? $entry['status'] : [];
        $username = $status['username'] ?? $entry['username'] ?? null;
        $tags = $this->tags($status, $entry);

        return new SourceResult(
            source: 'maigret',
            entityType: 'social_profile',
            value: is_string($username) && $username !== '' ? $username : $siteName,
            url: $url,
            title: $siteName,
            confidence: self::CLAIMED_CONFIDENCE,
            evidence: [['type' => 'public_profile_url', 'value' => $url]],
            metadata: array_filter([
                'site' => $siteName,
                'tags' => $tags !== [] ? $tags : null,
                'http_status' => $entry['http_status'] ?? null,
                'rank' => $entry['rank'] ?? null,
                'tool' => 'maigret',
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function profileUrl(array $entry): ?string
    {
        $status = is_array($entry['status'] ?? null) ? $entry['status'] : [];

        foreach ([$entry['url_user'] ?? null, $status['url'] ?? null, $entry['url'] ?? null] as $candidate) {
            if (is_string($candidate) && preg_match('#^https?://#i', $candidate) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $status
     * @param  array<string, mixed>  $entry
     * @return array<int, string>
     */
    private function tags(array $status, array $entry): array
    {
        $tags = $status['tags'] ?? $entry['site']['tags'] ?? [];

        if (! is_array($tags)) {
            return [];
        }

        return array_values(array_filter($tags, 'is_string'));
    }
}
