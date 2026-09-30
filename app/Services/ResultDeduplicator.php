<?php

namespace App\Services;

use App\Models\InvestigationRun;
use App\Models\SourceResult as SourceResultModel;
use App\Sources\SourceResult;
use Illuminate\Support\Collection;

/**
 * Deduplicação entre fontes (prompt 36).
 *
 * Chave estável composta por:
 *  - tipo de entidade;
 *  - **plataforma** do serviço onde a observação vive (github.com, instagram.com…);
 *  - identificador observado (username/e-mail já normalizado);
 *  - URL canônica, quando disponível.
 *
 * Interpretação registrada em `docs/31-normalizacao-deduplicacao.md`: a
 * "fonte" que faz parte da chave é a **plataforma** (serviço observado), nunca a
 * ferramenta que descobriu (`maigret`/`sherlock`/`whatsmyname`/`holehe`) —
 * incluir a ferramenta impediria a consolidação da mesma descoberta entre
 * fontes, que é o requisito seguinte do mesmo prompt.
 *
 * Nada é apagado: cada resultado permanece com a sua evidência; a descoberta
 * consolidada é o representante do grupo, com a lista de fontes observadoras.
 */
class ResultDeduplicator
{
    /**
     * Versão da chave. Alterar a composição exige bump: linhas antigas são
     * recomputadas (o hash antigo nunca é usado como identidade).
     */
    public const KEY_VERSION = 'v2';

    /**
     * Parâmetros de rastreamento removidos da URL canônica — não distinguem
     * recursos, apenas a campanha/referrer que trouxe o link.
     */
    private const TRACKING_PARAMETERS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
        'fbclid', 'gclid', 'dclid', 'msclkid', 'yclid', 'twclid', 'igshid', 'mkt_tok',
        'mc_cid', 'mc_eid', 'ref', 'ref_src', 'ref_url', 'spm', '_ga', '_gl', 'si',
    ];

    /**
     * Hash canônico do resultado (chave de deduplicação estável).
     */
    public function dedupHash(SourceResult $result): string
    {
        return hash('sha256', implode('|', array_map(
            static fn (?string $part): string => $part ?? '',
            $this->keyParts($result),
        )));
    }

    /**
     * Partes que compõem a chave — expostas para auditoria e para o relatório.
     *
     * @return array{entity_type: string, platform: string, identifier: string, url: string}
     */
    public function keyParts(SourceResult $result): array
    {
        return [
            'entity_type' => $this->canonicalEntityType($result->entityType),
            'platform' => (string) ($this->platform($result) ?? ''),
            'identifier' => $this->canonicalIdentifier($result->value),
            'url' => $this->canonicalUrl($result->url),
        ];
    }

    /**
     * Explicação auditável da chave de um resultado.
     *
     * @return array{version: string, parts: array<string, string>, hash: string}
     */
    public function explain(SourceResult $result): array
    {
        return [
            'version' => self::KEY_VERSION,
            'parts' => $this->keyParts($result),
            'hash' => $this->dedupHash($result),
        ];
    }

    /**
     * Normaliza o tipo de entidade para o conjunto canônico observado nos
     * adapters (`social_profile`, `email_account`, `web_page`…).
     */
    public function canonicalEntityType(?string $type): string
    {
        $type = mb_strtolower(trim((string) $type));

        return match ($type) {
            '', 'unknown' => 'unknown',
            'profile', 'account', 'username', 'social', 'social_profile' => 'social_profile',
            'email', 'email_account', 'mail' => 'email_account',
            'phone', 'phone_account' => 'phone_account',
            'web', 'web_page', 'website', 'url', 'page' => 'web_page',
            'name', 'person', 'person_name' => 'person_name',
            default => $type,
        };
    }

    /**
     * Plataforma observada: host da URL canônica tem precedência (é o valor
     * comparável entre ferramentas); sem URL, usa os metadados do adapter.
     */
    public function platform(SourceResult $result): ?string
    {
        foreach ([
            $this->hostFromUrl($result->url),
            $result->metadata['platform'] ?? null,
            $result->metadata['domain'] ?? null,
            $result->metadata['network'] ?? null,
            $result->metadata['site'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $this->normalizeHost($candidate);
            }
        }

        return null;
    }

    /**
     * Agrupa resultados equivalentes de um run, marcando duplicados e
     * persistindo a descoberta consolidada (representante) com todas as fontes
     * observadoras, sem apagar evidência de nenhuma fonte.
     */
    public function deduplicate(InvestigationRun $run): void
    {
        $results = SourceResultModel::query()
            ->whereHas('sourceRun', fn ($query) => $query->where('investigation_run_id', $run->id))
            ->with('sourceRun.sourceDefinition', 'evidence')
            ->orderBy('id')
            ->get();

        // 1) Chave estável em todo resultado — inclusive nos gravados com uma
        //    versão anterior da chave.
        foreach ($results as $result) {
            if ($this->requiresRecompute($result)) {
                $this->stamp($result);
            }

            $result->metadata = $this->withoutConsolidation($result->metadata ?? []);
        }

        // 2) Consolidação: representante + fontes observadoras.
        foreach ($results->groupBy('dedup_hash') as $hash => $group) {
            if ($group->count() < 2 || $hash === '' || $hash === null) {
                continue;
            }

            $this->consolidate((string) $hash, $group);
        }

        foreach ($results as $result) {
            $result->save();
        }
    }

    /**
     * Aplica hash e versão da chave ao resultado persistido.
     */
    public function stamp(SourceResultModel $result): void
    {
        $metadata = $result->metadata ?? [];
        $platform = $this->platform($result->toSourceResult());

        if ($platform !== null) {
            $metadata['platform'] = $platform;
        }

        $metadata['dedup_key_version'] = self::KEY_VERSION;

        $result->dedup_hash = $this->dedupHash($result->toSourceResult());
        $result->metadata = $metadata;
    }

    /**
     * Normaliza a URL para comparação: esquema fixo `https`, host sem `www.`,
     * sem barra final, sem fragmento e sem parâmetros de rastreamento (os
     * demais parâmetros são ordenados para o resultado ser determinístico).
     */
    public function canonicalUrl(?string $url): string
    {
        if ($url === null || trim($url) === '') {
            return '';
        }

        $url = mb_strtolower(trim($url));
        $url = preg_replace('/#.*$/', '', $url) ?? '';

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return rtrim($url, '/');
        }

        $host = $this->normalizeHost($parts['host']);
        $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';

        parse_str($parts['query'] ?? '', $query);
        foreach (array_keys($query) as $key) {
            if (in_array(mb_strtolower((string) $key), self::TRACKING_PARAMETERS, true)) {
                unset($query[$key]);
            }
        }
        ksort($query);

        $canonical = 'https://'.$host.$path;
        $queryString = http_build_query($query);

        return $queryString === '' ? $canonical : $canonical.'?'.$queryString;
    }

    private function canonicalIdentifier(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    private function normalizeHost(string $host): string
    {
        $host = mb_strtolower(trim($host));

        return preg_replace('/^www\./', '', $host) ?? $host;
    }

    private function hostFromUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $host = parse_url(mb_strtolower(trim($url)), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }

    private function requiresRecompute(SourceResultModel $result): bool
    {
        if ($result->dedup_hash === null) {
            return true;
        }

        return ($result->metadata['dedup_key_version'] ?? null) !== self::KEY_VERSION;
    }

    /**
     * Remove marcadores de consolidação anteriores (convergência em
     * reexecuções) sem tocar em evidências.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function withoutConsolidation(array $metadata): array
    {
        foreach (['duplicate_of', 'duplicate_of_hash', 'dedup_sources', 'dedup_group_size', 'dedup_observations'] as $key) {
            unset($metadata[$key]);
        }

        return $metadata;
    }

    /**
     * @param  Collection<int, SourceResultModel>  $group
     */
    private function consolidate(string $hash, Collection $group): void
    {
        $representative = $group->first();
        $duplicates = $group->slice(1)->values();

        $observations = $group->map(function (SourceResultModel $result): array {
            $dto = $result->toSourceResult();

            return [
                'source' => $result->sourceRun?->sourceDefinition?->slug ?? $result->sourceRun?->source,
                'source_run_id' => $result->source_run_id,
                'result_id' => $result->id,
                'platform' => $this->platform($dto),
                'value' => $result->value,
                'url' => $result->url,
                'observed_at' => $result->observed_at?->toISOString(),
                'evidence' => collect($dto->evidence)->pluck('type')->filter()->unique()->values()->all(),
            ];
        })->values()->all();

        $metadata = $representative->metadata ?? [];
        $metadata['dedup_sources'] = collect($observations)->pluck('source')->filter()->unique()->values()->all();
        $metadata['dedup_group_size'] = $group->count();
        $metadata['dedup_observations'] = $observations;
        $metadata['dedup_key_version'] = self::KEY_VERSION;
        $representative->metadata = $metadata;
        $representative->dedup_hash = $hash;

        foreach ($duplicates as $duplicate) {
            $duplicateMetadata = $duplicate->metadata ?? [];
            $duplicateMetadata['duplicate_of'] = $representative->id;
            $duplicateMetadata['duplicate_of_hash'] = $hash;
            $duplicateMetadata['dedup_key_version'] = self::KEY_VERSION;
            $duplicate->metadata = $duplicateMetadata;
            $duplicate->dedup_hash = $hash;
        }
    }
}
