<?php

namespace App\Services;

use App\Sources\InvestigationInput;
use App\Sources\InvestigationSource;
use App\Sources\SourceOutcome;
use App\Sources\SourceResult;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Cache de resultado de fonte, escopado por source + versão + hash de entrada.
 * Não inclui usuário: a consulta é sobre informação pública, e a chave de
 * entrada garante que nunca se retorne o resultado de uma entrada diferente.
 */
class SourceCache
{
    /**
     * Versão do payload cacheado. Mudanças no formato invalidam entradas
     * gravadas anteriormente (evita ler cache incompatível após deploy).
     */
    private const PAYLOAD_VERSION = 'p2';

    /**
     * @param  Closure(): SourceOutcome  $callback
     */
    public function remember(InvestigationSource $source, InvestigationInput $input, Closure $callback): SourceOutcome
    {
        if (! config('source_cache.enabled')) {
            return $callback();
        }

        $ttl = (int) config('source_cache.ttl', 3600);

        // Apenas primitivos/arrays entram no cache: `config/cache.php` define
        // `serializable_classes => false` (proteção contra gadget chain), logo
        // objetos voltariam como `__PHP_Incomplete_Class` em stores que
        // serializam (Redis). Aqui o payload é reconstruído em DTOs na leitura.
        $payload = Cache::remember(
            $this->key($source, $input),
            $ttl,
            function () use ($callback) {
                $outcome = $callback();

                return [
                    'findings' => array_map($this->findingToPayload(...), $outcome->findings),
                    'warnings' => $outcome->warnings,
                    'metadata' => $outcome->metadata,
                ];
            },
        );

        return new SourceOutcome(
            findings: array_map($this->findingFromPayload(...), $payload['findings'] ?? []),
            warnings: $payload['warnings'] ?? [],
            metadata: $payload['metadata'] ?? [],
        );
    }

    public function key(InvestigationSource $source, InvestigationInput $input): string
    {
        return 'source:'.$source->key().':'.$source->version().':'.self::PAYLOAD_VERSION.':'.$this->inputHash($input);
    }

    /**
     * @return array<string, mixed>
     */
    private function findingToPayload(SourceResult $result): array
    {
        return [
            'source' => $result->source,
            'entity_type' => $result->entityType,
            'value' => $result->value,
            'url' => $result->url,
            'title' => $result->title,
            'description' => $result->description,
            'confidence' => $result->confidence,
            'evidence' => $result->evidence,
            'metadata' => $result->metadata,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function findingFromPayload(array $payload): SourceResult
    {
        return new SourceResult(
            source: $payload['source'],
            entityType: $payload['entity_type'],
            value: $payload['value'],
            url: $payload['url'] ?? null,
            title: $payload['title'] ?? null,
            description: $payload['description'] ?? null,
            confidence: $payload['confidence'] ?? null,
            evidence: $payload['evidence'] ?? [],
            metadata: $payload['metadata'] ?? [],
        );
    }

    public function inputHash(InvestigationInput $input): string
    {
        return hash('sha256', json_encode([
            'name' => $input->name,
            'username' => $input->username,
            'email' => $input->email,
            'phone' => $input->phone,
            'city' => $input->city,
            'state' => $input->state,
        ]));
    }
}
