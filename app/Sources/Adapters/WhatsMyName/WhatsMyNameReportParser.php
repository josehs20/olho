<?php

namespace App\Sources\Adapters\WhatsMyName;

use App\Sources\SourceResult;

/**
 * Converte a saída bruta das verificações do WhatsMyName para o contrato comum
 * (`SourceResult`). Entradas sem site ou sem URL http(s) válida são descartadas.
 */
final class WhatsMyNameReportParser
{
    /**
     * Confiança heurística: a fonte respondeu com o código/trecho que o próprio
     * dataset define como "conta existente". Sinal público, não confirmação de
     * identidade (docs/02).
     */
    private const CLAIMED_CONFIDENCE = 0.8;

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, SourceResult>
     */
    public function parse(array $entries): array
    {
        $results = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $result = $this->toSourceResult($entry);

            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    public function toSourceResult(array $entry): ?SourceResult
    {
        $site = $entry['site'] ?? null;
        $url = $entry['url'] ?? null;

        if (! is_string($site) || trim($site) === '') {
            return null;
        }

        if (! is_string($url) || preg_match('#^https?://#i', $url) !== 1) {
            return null;
        }

        $username = $entry['username'] ?? null;
        $status = $entry['http_status'] ?? null;

        return new SourceResult(
            source: 'whatsmyname',
            entityType: 'social_profile',
            value: is_string($username) && $username !== '' ? $username : $url,
            url: $url,
            title: $site,
            confidence: self::CLAIMED_CONFIDENCE,
            evidence: [['type' => 'public_profile_url', 'value' => $url]],
            metadata: array_filter([
                'site' => $site,
                'tool' => 'whatsmyname',
                'category' => is_string($entry['category'] ?? null) ? $entry['category'] : null,
                'method' => is_string($entry['method'] ?? null) ? $entry['method'] : null,
                'http_status' => $status !== null && is_numeric($status) ? (int) $status : null,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }
}
