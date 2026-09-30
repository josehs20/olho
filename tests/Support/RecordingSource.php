<?php

namespace Tests\Support;

use App\Sources\InvestigationContext;
use App\Sources\InvestigationInput;
use App\Sources\InvestigationSource;
use App\Sources\SourceOutcome;
use App\Sources\SourceResult;

/**
 * Adapter de teste: registra o contexto recebido e devolve um `SourceOutcome`
 * com findings, avisos e metadados — usado para verificar os contratos do
 * pipeline sem depender de uma fonte concreta.
 */
final class RecordingSource implements InvestigationSource
{
    public ?InvestigationContext $context = null;

    public function __construct(private readonly string $slug = 'contract-source') {}

    public function key(): string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return 'Recording Source';
    }

    public function version(): string
    {
        return 'v1';
    }

    public function supports(InvestigationInput $input): bool
    {
        return $input->username !== null;
    }

    public function execute(InvestigationContext $context): SourceOutcome
    {
        $this->context = $context;

        $value = (string) ($context->input->username ?? $context->input->email ?? '');

        return new SourceOutcome(
            findings: [
                new SourceResult(
                    source: $this->slug,
                    entityType: 'social_profile',
                    value: $value,
                    url: 'https://example.com/'.$value,
                    evidence: [['type' => 'username_match', 'value' => $value]],
                ),
            ],
            warnings: ['item ignorado por limite'],
            metadata: ['tool_version' => '1.2.3'],
        );
    }

    public function normalize(array $raw): SourceResult
    {
        return new SourceResult(
            source: $this->slug,
            entityType: $raw['entity_type'] ?? 'unknown',
            value: $raw['value'] ?? '',
        );
    }

    public function healthCheck(): bool
    {
        return true;
    }
}
