<?php

namespace App\Sources\Adapters\Holehe;

use App\Sources\SourceResult;

/**
 * Converte o CSV do Holehe para o contrato comum (`SourceResult`).
 *
 * Colunas do Holehe v1.61:
 * `name,domain,method,frequent_rate_limit,rateLimit,exists,emailrecovery,phoneNumber,others`
 *
 * Apenas linhas com `exists = True` são descobertas. As colunas
 * `emailrecovery`/`phoneNumber`/`others` **nunca** são lidas nem persistidas:
 * além de serem dados de recuperação de conta, a execução sempre usa
 * `--no-password-recovery`.
 */
final class HoleheReportParser
{
    /**
     * Confiança heurística BAIXA de propósito: indicar que um e-mail aparece
     * como cadastrado em um serviço **não prova** que a pessoa controla aquela
     * conta (e-mail pode ser compartilhado, corporativo, descartável ou
     * reciclado). Fica abaixo do limiar de "encontrado" da interface.
     */
    private const SIGNUP_INDICATOR_CONFIDENCE = 0.5;

    /** Colunas com dados sensíveis que jamais entram no domínio. */
    private const IGNORED_COLUMNS = ['emailrecovery', 'phoneNumber', 'others'];

    /**
     * @return array<int, SourceResult>
     */
    public function parseCsv(string $csv, string $email): array
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

            $result = $this->toSourceResult($row, $email);

            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function toSourceResult(array $row, string $email): ?SourceResult
    {
        if (! $this->exists($row)) {
            return null;
        }

        $domain = $row['domain'] ?? null;

        if (! is_string($domain) || ! preg_match('#^[a-z0-9.-]+\.[a-z]{2,}$#i', trim($domain))) {
            return null;
        }

        $domain = mb_strtolower(trim($domain));
        $name = $row['name'] ?? null;
        $url = 'https://'.$domain;

        return new SourceResult(
            source: 'holehe',
            entityType: 'email_account',
            value: $email,
            url: $url,
            title: $domain,
            confidence: self::SIGNUP_INDICATOR_CONFIDENCE,
            evidence: [['type' => 'email_registration_indicator', 'value' => $domain]],
            metadata: array_filter([
                'site' => is_string($name) && $name !== '' ? $name : null,
                'domain' => $domain,
                'method' => is_string($row['method'] ?? null) && $row['method'] !== '' ? $row['method'] : null,
                'tool' => 'holehe',
                'signal' => 'public_signup_check',
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function exists(array $row): bool
    {
        $exists = $row['exists'] ?? null;

        if (is_bool($exists)) {
            return $exists;
        }

        return is_string($exists) && in_array(mb_strtolower(trim($exists)), ['true', 'yes', '1'], true);
    }
}
