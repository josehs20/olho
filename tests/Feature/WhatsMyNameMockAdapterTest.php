<?php

namespace Tests\Feature;

use App\Sources\Adapters\WhatsMyNameMockAdapter;
use App\Sources\Exceptions\SourceTimeoutException;
use App\Sources\Exceptions\SourceUnavailableException;
use App\Sources\InvestigationContext;
use App\Sources\InvestigationInput;
use App\Sources\SourceRegistry;
use Tests\TestCase;

class WhatsMyNameMockAdapterTest extends TestCase
{
    public function test_key_is_whatsmyname(): void
    {
        $this->assertSame('whatsmyname', (new WhatsMyNameMockAdapter)->key());
    }

    public function test_supports_username(): void
    {
        $adapter = new WhatsMyNameMockAdapter;

        $this->assertTrue($adapter->supports(new InvestigationInput(username: 'joao')));
        $this->assertFalse($adapter->supports(new InvestigationInput(email: 'a@b.c')));
    }

    public function test_returns_multiple_items_for_deduplication(): void
    {
        $results = (new WhatsMyNameMockAdapter)->execute(new InvestigationContext(new InvestigationInput(username: 'joao')));

        $this->assertGreaterThan(2, count($results));
        $this->assertSame('social_profile', $results[0]->entityType);
        $this->assertSame('joao', $results[0]->value);
        $this->assertSame('whatsmyname', $results[0]->source);
        $this->assertSame('github.com', $results[0]->metadata['site']);
    }

    public function test_not_found_scenario_returns_empty(): void
    {
        $results = (new WhatsMyNameMockAdapter)->execute(new InvestigationContext(new InvestigationInput(username: 'not_found')));

        $this->assertSame([], $results);
    }

    public function test_timeout_scenario_throws(): void
    {
        $this->expectException(SourceTimeoutException::class);

        (new WhatsMyNameMockAdapter)->execute(new InvestigationContext(new InvestigationInput(username: 'timeout')));
    }

    public function test_error_scenario_throws(): void
    {
        $this->expectException(SourceUnavailableException::class);

        (new WhatsMyNameMockAdapter)->execute(new InvestigationContext(new InvestigationInput(username: 'error')));
    }

    public function test_normalize_maps_raw_to_result(): void
    {
        $result = (new WhatsMyNameMockAdapter)->normalize([
            'entity_type' => 'social_profile',
            'value' => 'joao',
            'url' => 'https://github.com/joao',
        ]);

        $this->assertSame('whatsmyname', $result->source);
        $this->assertSame('joao', $result->value);
    }

    public function test_registered_in_registry(): void
    {
        $this->assertInstanceOf(WhatsMyNameMockAdapter::class, app(SourceRegistry::class)->get('whatsmyname'));
    }
}
