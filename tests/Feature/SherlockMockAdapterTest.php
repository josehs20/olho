<?php

namespace Tests\Feature;

use App\Sources\Adapters\SherlockMockAdapter;
use App\Sources\Exceptions\SourceTimeoutException;
use App\Sources\Exceptions\SourceUnavailableException;
use App\Sources\InvestigationContext;
use App\Sources\InvestigationInput;
use App\Sources\SourceRegistry;
use Tests\TestCase;

class SherlockMockAdapterTest extends TestCase
{
    public function test_key_is_sherlock(): void
    {
        $this->assertSame('sherlock', (new SherlockMockAdapter)->key());
    }

    public function test_supports_username(): void
    {
        $adapter = new SherlockMockAdapter;

        $this->assertTrue($adapter->supports(new InvestigationInput(username: 'joao')));
        $this->assertFalse($adapter->supports(new InvestigationInput(email: 'a@b.c')));
    }

    public function test_found_scenario_returns_multiple_profiles(): void
    {
        $results = (new SherlockMockAdapter)->execute(new InvestigationContext(new InvestigationInput(username: 'joao')));

        $this->assertGreaterThan(1, count($results));
        $this->assertSame('social_profile', $results[0]->entityType);
        $this->assertSame('joao', $results[0]->value);
        $this->assertSame('sherlock', $results[0]->source);
    }

    public function test_not_found_scenario_returns_empty(): void
    {
        $results = (new SherlockMockAdapter)->execute(new InvestigationContext(new InvestigationInput(username: 'not_found')));

        $this->assertSame([], $results);
    }

    public function test_timeout_scenario_throws(): void
    {
        $this->expectException(SourceTimeoutException::class);

        (new SherlockMockAdapter)->execute(new InvestigationContext(new InvestigationInput(username: 'timeout')));
    }

    public function test_error_scenario_throws_sanitized_error(): void
    {
        try {
            (new SherlockMockAdapter)->execute(new InvestigationContext(new InvestigationInput(username: 'error')));
            $this->fail('Expected SourceUnavailableException');
        } catch (SourceUnavailableException $e) {
            $this->assertSame('sherlock unavailable', $e->getMessage());
        }
    }

    public function test_normalize_maps_raw_to_result(): void
    {
        $result = (new SherlockMockAdapter)->normalize([
            'entity_type' => 'social_profile',
            'value' => 'joao',
            'url' => 'https://twitter.com/joao',
        ]);

        $this->assertSame('sherlock', $result->source);
        $this->assertSame('joao', $result->value);
    }

    public function test_registered_in_registry(): void
    {
        $this->assertInstanceOf(SherlockMockAdapter::class, app(SourceRegistry::class)->get('sherlock'));
    }
}
