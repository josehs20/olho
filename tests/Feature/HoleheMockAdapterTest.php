<?php

namespace Tests\Feature;

use App\Sources\Adapters\HoleheMockAdapter;
use App\Sources\Exceptions\SourceTimeoutException;
use App\Sources\Exceptions\SourceUnavailableException;
use App\Sources\InvestigationContext;
use App\Sources\InvestigationInput;
use App\Sources\SourceRegistry;
use Tests\TestCase;

class HoleheMockAdapterTest extends TestCase
{
    public function test_key_is_holehe(): void
    {
        $this->assertSame('holehe', (new HoleheMockAdapter)->key());
    }

    public function test_supports_email(): void
    {
        $adapter = new HoleheMockAdapter;

        $this->assertTrue($adapter->supports(new InvestigationInput(email: 'a@b.c')));
        $this->assertFalse($adapter->supports(new InvestigationInput(username: 'joao')));
    }

    public function test_found_scenario_returns_services(): void
    {
        $results = (new HoleheMockAdapter)->execute(new InvestigationContext(new InvestigationInput(email: 'a@b.c')));

        $this->assertGreaterThan(2, count($results));
        $this->assertSame('email_account', $results[0]->entityType);
        $this->assertSame('a@b.c', $results[0]->value);
        $this->assertSame('holehe', $results[0]->source);
        $this->assertSame('twitter.com', $results[0]->metadata['service']);
    }

    public function test_not_found_scenario_returns_empty(): void
    {
        $results = (new HoleheMockAdapter)->execute(new InvestigationContext(new InvestigationInput(email: 'not_found')));

        $this->assertSame([], $results);
    }

    public function test_timeout_scenario_throws(): void
    {
        $this->expectException(SourceTimeoutException::class);

        (new HoleheMockAdapter)->execute(new InvestigationContext(new InvestigationInput(email: 'timeout')));
    }

    public function test_error_scenario_throws(): void
    {
        $this->expectException(SourceUnavailableException::class);

        (new HoleheMockAdapter)->execute(new InvestigationContext(new InvestigationInput(email: 'error')));
    }

    public function test_normalize_maps_raw_to_result(): void
    {
        $result = (new HoleheMockAdapter)->normalize([
            'entity_type' => 'email_account',
            'value' => 'a@b.c',
            'url' => 'https://twitter.com',
        ]);

        $this->assertSame('holehe', $result->source);
        $this->assertSame('a@b.c', $result->value);
    }

    public function test_registered_in_registry(): void
    {
        $this->assertInstanceOf(HoleheMockAdapter::class, app(SourceRegistry::class)->get('holehe'));
    }
}
