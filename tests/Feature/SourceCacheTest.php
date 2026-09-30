<?php

namespace Tests\Feature;

use App\Services\SourceCache;
use App\Sources\InvestigationContext;
use App\Sources\InvestigationInput;
use App\Sources\Mocks\MockUsernameSource;
use App\Sources\SourceOutcome;
use App\Sources\SourceResult;
use Illuminate\Support\Facades\Cache;
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

        $produce = function () use (&$calls, $source, $input) {
            $calls++;

            return $source->execute(new InvestigationContext($input));
        };

        $first = $cache->remember($source, $input, $produce);
        $second = $cache->remember($source, $input, $produce);

        $this->assertSame(1, $calls);
        $this->assertEquals($first, $second);
    }

    public function test_cached_payload_contains_no_php_objects(): void
    {
        $cache = app(SourceCache::class);
        $source = new MockUsernameSource;
        $input = new InvestigationInput(username: 'joao');

        $cache->remember($source, $input, fn () => $source->execute(new InvestigationContext($input)));

        $payload = Cache::get($cache->key($source, $input));

        $this->assertIsArray($payload);
        $this->assertSame(['findings', 'warnings', 'metadata'], array_keys($payload));
        $this->assertNotEmpty($payload['findings']);

        foreach ($payload['findings'] as $finding) {
            // Objetos no cache viram `__PHP_Incomplete_Class` com
            // `config/cache.php` → `serializable_classes => false`.
            $this->assertIsArray($finding);
        }

        // A chave carrega a versão do payload para invalidar formatos antigos.
        $this->assertStringStartsWith('source:mock-username:v1:p2:', $cache->key($source, $input));
    }

    public function test_different_input_uses_different_cache_key(): void
    {
        $cache = app(SourceCache::class);
        $source = new MockUsernameSource;

        $a = $cache->key($source, new InvestigationInput(username: 'joao'));
        $b = $cache->key($source, new InvestigationInput(username: 'maria'));

        $this->assertNotSame($a, $b);
    }

    public function test_remember_returns_source_outcome_with_dtos(): void
    {
        $cache = app(SourceCache::class);
        $source = new MockUsernameSource;
        $input = new InvestigationInput(username: 'joao');

        $outcome = $cache->remember($source, $input, fn () => $source->execute(new InvestigationContext($input)));

        $this->assertInstanceOf(SourceOutcome::class, $outcome);
        $this->assertNotEmpty($outcome->findings);
        $this->assertInstanceOf(SourceResult::class, $outcome->findings[0]);
    }
}
