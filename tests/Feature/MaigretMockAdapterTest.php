<?php

namespace Tests\Feature;

use App\Sources\Adapters\MaigretMockAdapter;
use App\Sources\Exceptions\SourceTimeoutException;
use App\Sources\Exceptions\SourceUnavailableException;
use App\Sources\InvestigationContext;
use App\Sources\InvestigationInput;
use App\Sources\SourceRegistry;
use Tests\TestCase;

class MaigretMockAdapterTest extends TestCase
{
    public function test_key_is_maigret(): void
    {
        $this->assertSame('maigret', (new MaigretMockAdapter)->key());
    }

    public function test_supports_username(): void
    {
        $adapter = new MaigretMockAdapter;

        $this->assertTrue($adapter->supports(new InvestigationInput(username: 'joao')));
        $this->assertFalse($adapter->supports(new InvestigationInput(email: 'a@b.c')));
    }

    public function test_found_scenario_returns_deterministic_fixtures(): void
    {
        $results = (new MaigretMockAdapter)->execute(new InvestigationContext(new InvestigationInput(username: 'joao')));

        $this->assertCount(3, $results);
        $this->assertSame('social_profile', $results[0]->entityType);
        $this->assertSame('joao', $results[0]->value);
        $this->assertSame('https://github.com/joao', $results[0]->url);
        $this->assertSame('maigret', $results[0]->source);
    }

    public function test_not_found_scenario_returns_empty(): void
    {
        $results = (new MaigretMockAdapter)->execute(new InvestigationContext(new InvestigationInput(username: 'not_found')));

        $this->assertSame([], $results);
    }

    public function test_timeout_scenario_throws(): void
    {
        $this->expectException(SourceTimeoutException::class);

        (new MaigretMockAdapter)->execute(new InvestigationContext(new InvestigationInput(username: 'timeout')));
    }

    public function test_error_scenario_throws(): void
    {
        $this->expectException(SourceUnavailableException::class);

        (new MaigretMockAdapter)->execute(new InvestigationContext(new InvestigationInput(username: 'error')));
    }

    public function test_normalize_maps_raw_to_result(): void
    {
        $result = (new MaigretMockAdapter)->normalize([
            'entity_type' => 'social_profile',
            'value' => 'joao',
            'url' => 'https://github.com/joao',
            'confidence' => 0.9,
            'evidence' => [['type' => 'username_match', 'value' => 'joao']],
        ]);

        $this->assertSame('maigret', $result->source);
        $this->assertSame('joao', $result->value);
        $this->assertSame(0.9, $result->confidence);
    }

    public function test_registered_in_registry(): void
    {
        $this->assertInstanceOf(MaigretMockAdapter::class, app(SourceRegistry::class)->get('maigret'));
    }
}
