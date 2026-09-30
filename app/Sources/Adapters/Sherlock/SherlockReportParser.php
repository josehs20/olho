<?php

namespace App\Sources\Adapters\Sherlock;

use App\Sources\SourceResult;

/**
 * Converte a saída do Sherlock para o contrato comum (`SourceResult`).
 *
 * Formatos suportados (Sherlock 0.16.x):
 *
 * - CSV (`--csv`): `username,name,url_main,url_user,exists,http_status,response_time_s`
 * - stdout (`--print-found`): `[+] GitHub: https://www.github.com/python`
 */
final class SherlockReportParser
{
    /**
     * Confiança heurística: o site respondeu que o username está reivindicado
     * (`exists = Claimed`). Sinal público, não confirmação de identidade.
     */
    private const CLAIMED_CONFIDENCE = 0.8;

    /**
     * @return array<int, SourceResult>
     */
    public function parseCsv(string $csv): array
    {
        $lines = preg_split('/\R/', trim($csv)) ?: [];
        $header = str_getcsv((string) array_shift($lines), ',', '"', '');

        $results = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = array_slice(array_pad(str_getcsv($line, ',', '"', ''), count($header), null), 0, count($header));
            $row = array_combine($header, $values);

            if ($row === false) {
                continue;
            }

            $result = $this->toSourceResult($row);

            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * Extrai as descobertas de uma saída parcial (`--print-found`), usada
     * quando a varredura não chegou a gerar o CSV.
     *
     * @return array<int, SourceResult>
     */
    public function parseFoundLines(string $output, string $username): array
    {
        $results = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $clean = trim(preg_replace('/\e\[[0-9;]*m/', '', $line) ?? $line);

            if (preg_match('/^\[\+\]\s*(?<name>[^:]+):\s*(?<url>https?:\/\/\S+)\s*$/', $clean, $matches) !== 1) {
                continue;
            }

            $result = $this->toSourceResult([
                'name' => trim($matches['name']),
                'url_user' => $matches['url'],
                'username' => $username,
                'exists' => 'Claimed',
            ]);

            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function toSourceResult(array $row): ?SourceResult
    {
        if (! $this->isClaimed($row)) {
            return null;
        }

        $url = $this->profileUrl($row);

        if ($url === null) {
            return null;
        }

        $name = $row['name'] ?? null;
        $username = $row['username'] ?? null;
        $httpStatus = $row['http_status'] ?? null;

        return new SourceResult(
            source: 'sherlock',
            entityType: 'social_profile',
            value: is_string($username) && $username !== '' ? $username : $url,
            url: $url,
            title: is_string($name) && $name !== '' ? $name : $url,
            confidence: self::CLAIMED_CONFIDENCE,
            evidence: [['type' => 'public_profile_url', 'value' => $url]],
            metadata: array_filter([
                'site' => is_string($name) && $name !== '' ? $name : null,
                'tool' => 'sherlock',
                'exists' => is_string($row['exists'] ?? null) ? $row['exists'] : null,
                'http_status' => $httpStatus !== null && is_numeric($httpStatus) ? (int) $httpStatus : null,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function profileUrl(array $row): ?string
    {
        $candidate = $row['url_user'] ?? $row['url'] ?? null;

        return is_string($candidate) && preg_match('#^https?://#i', $candidate) === 1 ? $candidate : null;
    }

    /**
     * O Sherlock marca `exists` como `Claimed` (encontrado) ou `Available`
     * (username livre); só a primeira é uma descoberta.
     *
     * @param  array<string, mixed>  $row
     */
    private function isClaimed(array $row): bool
    {
        $exists = $row['exists'] ?? null;

        if (! is_string($exists) || $exists === '') {
            return true;
        }

        return in_array(mb_strtolower($exists), ['claimed', 'found', 'true', '1'], true);
    }
}
