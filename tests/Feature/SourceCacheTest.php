<?php

namespace Tests\Feature;

use App\Services\SourceCache;
use App\Sources\InvestigationContext;
use App\Sources\InvestigationInput;
use App\Sources\Mocks\MockUsernameSource;
use App\Sources\SourceResult;
use Tests\TestCase;

class SourceCacheTest extends TestCase
{
    public function test_key_includes_source_version_and_input_hash(): void
    {
        $cache = app(SourceCache::class);
        $key = $cache->key(new MockUsernameSource, new InvestigationInput(username: 'joao'));

        $this->assertStringStartsWith('source:mock-username:v1:', $key);
    }

    public function test_remember_caches_result(): void
    {
        $cache = app(SourceCache::class);
        $source = new MockUsernameSource;
        $input = new InvestigationInput(username: 'joao');

        $calls = 0;

        $first = $cache->remember($source, $input, function () use (&$calls) {
            $calls++;

            return ['result'];
        });

        $second = $cache->remember($source, $input, function () use (&$calls) {
            $calls++;

            return ['result'];
        });

        $this->assertSame(1, $calls);
        $this->assertSame($first, $second);
    }

    public function test_different_input_uses_different_cache_key(): void
    {
        $cache = app(SourceCache::class);
        $source = new MockUsernameSource;

        $a = $cache->key($source, new InvestigationInput(username: 'joao'));
        $b = $cache->key($source, new InvestigationInput(username: 'maria'));

        $this->assertNotSame($a, $b);
    }

    public function test_caches_source_result_dtos(): void
    {
        $cache = app(SourceCache::class);
        $source = new MockUsernameSource;
        $input = new InvestigationInput(username: 'joao');

        $results = $cache->remember($source, $input, fn () => $source->execute(new InvestigationContext($input)));

        $this->assertNotEmpty($results);
        $this->assertInstanceOf(SourceResult::class, $results[0]);
    }
}
