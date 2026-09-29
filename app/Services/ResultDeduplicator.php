<?php

namespace App\Services;

use App\Models\InvestigationRun;
use App\Models\SourceResult;

class ResultDeduplicator
{
    /**
     * Hash canônico para deduplicação: tipo + identificador normalizado + URL canônica.
     */
    public function dedupHash(SourceResult $result): string
    {
        return hash('sha256', implode('|', [
            mb_strtolower(trim($result->entity_type)),
            mb_strtolower(trim($result->value)),
            $this->canonicalUrl($result->url),
        ]));
    }

    /**
     * Agrupa resultados equivalentes de um run, marcando duplicados e
     * persistindo quais fontes encontraram cada item (sem apagar evidência).
     */
    public function deduplicate(InvestigationRun $run): void
    {
        $results = SourceResult::query()
            ->whereHas('sourceRun', fn ($q) => $q->where('investigation_run_id', $run->id))
            ->with('sourceDefinition')
            ->orderBy('id')
            ->get();

        foreach ($results as $result) {
            if ($result->dedup_hash === null) {
                $result->dedup_hash = $this->dedupHash($result);
                $result->save();
            }
        }

        foreach ($results->groupBy('dedup_hash') as $group) {
            if ($group->count() < 2) {
                continue;
            }

            $representative = $group->shift();

            $slugs = $group
                ->pluck('sourceDefinition.slug')
                ->prepend($representative->sourceDefinition?->slug)
                ->filter()
                ->unique()
                ->values()
                ->all();

            $metadata = $representative->metadata ?? [];
            $metadata['dedup_sources'] = $slugs;
            $representative->metadata = $metadata;
            $representative->save();

            foreach ($group as $duplicate) {
                $meta = $duplicate->metadata ?? [];
                $meta['duplicate_of'] = $representative->id;
                $duplicate->metadata = $meta;
                $duplicate->save();
            }
        }
    }

    /**
     * Normaliza a URL para comparação (minúscula, sem barra final, sem fragmento).
     */
    public function canonicalUrl(?string $url): string
    {
        if ($url === null || trim($url) === '') {
            return '';
        }

        $url = mb_strtolower(trim($url));
        $url = preg_replace('/#.*$/', '', $url) ?? '';
        $url = rtrim($url, '/');

        return $url;
    }
}
