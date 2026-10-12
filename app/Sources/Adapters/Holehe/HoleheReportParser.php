<?php

namespace App\Sources\Adapters\Holehe;

use App\Sources\SourceResult;
use Generator;

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
        $results = [];

        foreach ($this->rows($csv) as $row) {
            $result = $this->toSourceResult($row, $email);

            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * Panorama do relatório: quantos sites foram verificados, quantos
     * confirmaram cadastro e quantos **não puderam** ser verificados.
     *
     * `rateLimit = True` no CSV significa "a verificação deste site não
     * concluiu" (bloqueio/limite do serviço ou exceção interna do módulo), não
     * "e-mail não cadastrado". O panorama permite distinguir uma consulta que
     * rodou de verdade de uma que falhou em massa — sem ele, os dois casos
     * chegam ao painel como "0 resultados" (ver `HoleheAdapter`).
     *
     * `found` conta as linhas marcadas como cadastro (`exists = True`, antes da
     * validação de domínio feita por `parseCsv`); `unavailable` conta as que não
     * puderam ser verificadas.
     *
     * @return array{checked: int, found: int, unavailable: int}
     */
    public function summarize(string $csv): array
    {
        $checked = 0;
        $found = 0;
        $unavailable = 0;

        foreach ($this->rows($csv) as $row) {
            $checked++;

            if ($this->exists($row)) {
                $found++;
            }

            if ($this->isUnavailable($row)) {
                $unavailable++;
            }
        }

        return ['checked' => $checked, 'found' => $found, 'unavailable' => $unavailable];
    }

    /**
     * Linhas do CSV como arrays associativos pelo cabeçalho.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function rows(string $csv): Generator
    {
        $lines = preg_split('/\R/', trim($csv)) ?: [];
        $header = str_getcsv((string) array_shift($lines), ',', '"', '');

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = array_slice(array_pad(str_getcsv($line, ',', '"', ''), count($header), null), 0, count($header));
            $row = array_combine($header, $values);

            if ($row === false) {
                continue;
            }

            yield $row;
        }
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
        return $this->asBool($row['exists'] ?? null);
    }

    /**
     * `rateLimit = True` = o site não pôde ser verificado (bloqueio, limite ou
     * exceção interna do módulo) — nunca confundir com "não cadastrado".
     *
     * @param  array<string, mixed>  $row
     */
    private function isUnavailable(array $row): bool
    {
        return $this->asBool($row['rateLimit'] ?? null);
    }

    private function asBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return is_string($value) && in_array(mb_strtolower(trim($value)), ['true', 'yes', '1'], true);
    }
}
