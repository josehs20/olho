<?php

namespace App\Sources;

use App\Sources\Exceptions\SourceNotRegisteredException;

final class SourceRegistry
{
    /** @var array<string, InvestigationSource> */
    private array $sources = [];

    public function __construct(iterable $sources = [])
    {
        foreach ($sources as $source) {
            $this->register($source);
        }
    }

    public function register(InvestigationSource $source): void
    {
        $this->sources[$source->key()] = $source;
    }

    /**
     * @throws SourceNotRegisteredException quando não há adapter para o slug
     */
    public function get(string $key): InvestigationSource
    {
        return $this->sources[$key] ?? throw SourceNotRegisteredException::forSlug($key);
    }

    public function has(string $key): bool
    {
        return isset($this->sources[$key]);
    }

    /**
     * @return InvestigationSource[]
     */
    public function all(): array
    {
        return array_values($this->sources);
    }

    /**
     * Fontes que suportam a entrada informada.
     *
     * @return InvestigationSource[]
     */
    public function supporting(InvestigationInput $input): array
    {
        return array_values(array_filter($this->all(), fn (InvestigationSource $source) => $source->supports($input)));
    }
}
