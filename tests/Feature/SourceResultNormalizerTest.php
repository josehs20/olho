<?php

namespace Tests\Feature;

use App\Services\SourceResultNormalizer;
use App\Sources\InvestigationContext;
use App\Sources\InvestigationInput;
use App\Sources\SourceRegistry;
use App\Sources\SourceResult;
use Tests\TestCase;

class SourceResultNormalizerTest extends TestCase
{
    private SourceResultNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = app(SourceResultNormalizer::class);
    }

    public function test_normalizes_attributes(): void
    {
        $result = new SourceResult(
            source: 'maigret',
            entityType: 'social_profile',
            value: 'joao',
            url: 'https://github.com/joao',
            title: 'GitHub — joao',
            confidence: 0.9,
            evidence: [['type' => 'username_match', 'value' => 'joao']],
            metadata: ['site' => 'github.com'],
        );

        $attributes = $this->normalizer->attributes($result);

        $this->assertSame('social_profile', $attributes['entity_type']);
        $this->assertSame('joao', $attributes['value']);
        $this->assertSame('https://github.com/joao', $attributes['url']);
        $this->assertSame('GitHub — joao', $attributes['title']);
        $this->assertSame(0.9, $attributes['confidence']);
        $this->assertSame(['site' => 'github.com'], $attributes['metadata']);
        $this->assertNotNull($attributes['observed_at']);
    }

    public function test_strips_html_from_text_fields(): void
    {
        $result = new SourceResult(
            source: 'maigret',
            entityType: 'social_profile',
            value: 'joao',
            title: '<b>Perfil</b> <script>alert(1)</script>',
            description: '<p>Descrição</p>',
        );

        $attributes = $this->normalizer->attributes($result);

        $this->assertSame('Perfil alert(1)', $attributes['title']);
        $this->assertSame('Descrição', $attributes['description']);
    }

    public function test_normalizes_url(): void
    {
        $normalizer = $this->normalizer;

        $valid = new SourceResult(source: 's', entityType: 't', value: 'v', url: 'https://example.com/x');
        $this->assertSame('https://example.com/x', $normalizer->attributes($valid)['url']);

        $insecure = new SourceResult(source: 's', entityType: 't', value: 'v', url: 'javascript:alert(1)');
        $this->assertNull($normalizer->attributes($insecure)['url']);

        $null = new SourceResult(source: 's', entityType: 't', value: 'v', url: null);
        $this->assertNull($normalizer->attributes($null)['url']);
    }

    public function test_defaults_entity_type(): void
    {
        $result = new SourceResult(source: 's', entityType: '', value: 'v');

        $this->assertSame('unknown', $this->normalizer->attributes($result)['entity_type']);
    }

    public function test_evidence_attributes(): void
    {
        $result = new SourceResult(
            source: 's',
            entityType: 't',
            value: 'v',
            evidence: [
                ['type' => 'username_match', 'value' => 'joao'],
                ['type' => 'url_match', 'value' => 'https://example.com'],
            ],
        );

        $evidence = $this->normalizer->evidenceAttributes($result);

        $this->assertCount(2, $evidence);
        $this->assertSame('username_match', $evidence[0]['type']);
        $this->assertSame('joao', $evidence[0]['value']);
    }

    public function test_normalizes_output_of_all_registered_sources(): void
    {
        $registry = app(SourceRegistry::class);
        $normalizer = $this->normalizer;

        $inputs = [
            'mock-username' => new InvestigationInput(username: 'joao'),
            'mock-email' => new InvestigationInput(email: 'a@b.c'),
            'mock-web' => new InvestigationInput(name: 'João'),
            'maigret' => new InvestigationInput(username: 'joao'),
            'sherlock' => new InvestigationInput(username: 'joao'),
            'whatsmyname' => new InvestigationInput(username: 'joao'),
            'holehe' => new InvestigationInput(email: 'a@b.c'),
        ];

        foreach ($inputs as $key => $input) {
            $results = $registry->get($key)->execute(new InvestigationContext($input));

            $this->assertNotEmpty($results, "{$key} deveria retornar resultados");

            foreach ($results as $result) {
                $attributes = $normalizer->attributes($result);

                $this->assertNotEmpty($attributes['value']);
                $this->assertNotEmpty($attributes['entity_type']);
                $this->assertNotNull($attributes['url']);
                $this->assertNotNull($attributes['observed_at']);
            }
        }
    }
}
