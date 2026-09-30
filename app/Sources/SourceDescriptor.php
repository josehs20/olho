<?php

namespace App\Sources;

/**
 * Parâmetros de execução de uma fonte, resolvidos pelo orquestrador a partir da
 * `SourceDefinition` (banco).
 *
 * É a outra metade do contrato de **entrada** do adapter (`InvestigationContext`):
 * o adapter recebe slug, tipo, timeout e configuração já resolvidos e não
 * precisa conhecer Eloquent, o banco ou o painel admin.
 */
final class SourceDescriptor
{
    /**
     * @param  array<string, mixed>  $config  configuração específica da fonte (`source_definitions.config`)
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $type,
        public readonly int $timeout = 30,
        public readonly array $config = [],
    ) {}

    /**
     * Leitura tolerante de uma opção da fonte (ex.: caminho do binário).
     */
    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }
}
