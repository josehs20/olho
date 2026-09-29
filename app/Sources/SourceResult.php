<?php

namespace App\Sources;

/**
 * Resultado normalizado devolvido por um adapter de fonte.
 */
final class SourceResult
{
    public function __construct(
        public readonly string $source,
        public readonly string $entityType,
        public readonly string $value,
        public readonly ?string $url = null,
        public readonly ?string $title = null,
        public readonly ?string $description = null,
        public readonly ?float $confidence = null,
        public readonly array $evidence = [],
        public readonly array $metadata = [],
    ) {}
}
