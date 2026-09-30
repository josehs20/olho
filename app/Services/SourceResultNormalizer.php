<?php

namespace App\Services;

use App\Sources\SourceResult;

/**
 * Normaliza a saída de um adapter para o modelo interno `source_results`,
 * aplicando as regras de docs/10-resultados-evidencias.md.
 *
 * Desde o prompt 36 o resultado também sai com a **chave de deduplicação
 * estável** e a plataforma observada, para que nenhuma linha dependa de uma
 * etapa posterior para ser comparável.
 */
class SourceResultNormalizer
{
    public function __construct(private readonly ResultDeduplicator $deduplicator) {}

    /**
     * Atributos normalizados prontos para persistência.
     */
    public function attributes(SourceResult $result): array
    {
        $metadata = $result->metadata;
        $platform = $this->deduplicator->platform($result);

        if ($platform !== null) {
            $metadata['platform'] = $platform;
        }

        $metadata['dedup_key_version'] = ResultDeduplicator::KEY_VERSION;

        return [
            'entity_type' => $this->deduplicator->canonicalEntityType($result->entityType),
            'value' => $this->cleanText($result->value),
            'url' => $this->normalizeUrl($result->url),
            'title' => $this->nullableCleanText($result->title),
            'description' => $this->nullableCleanText($result->description),
            'confidence' => $result->confidence,
            'dedup_hash' => $this->deduplicator->dedupHash($result),
            'raw_payload' => [
                'evidence' => $result->evidence,
                'metadata' => $result->metadata,
            ],
            'metadata' => $metadata,
            'observed_at' => now(),
        ];
    }

    /**
     * Evidências normalizadas para persistência.
     */
    public function evidenceAttributes(SourceResult $result): array
    {
        return array_map(function (array $evidence) {
            return [
                'type' => $evidence['type'] ?? 'reference',
                'value' => $this->nullableCleanText($evidence['value'] ?? null),
            ];
        }, $result->evidence);
    }

    private function cleanText(string $value): string
    {
        return trim(strip_tags($value));
    }

    private function nullableCleanText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $cleaned = trim(strip_tags($value));

        return $cleaned === '' ? null : $cleaned;
    }

    private function normalizeUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);

        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return null;
        }

        return $url;
    }
}
