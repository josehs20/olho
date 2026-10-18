<?php

namespace Tests\Feature;

use App\Sources\Adapters\WhatsMyName\WhatsMyNameChecker;
use App\Sources\Adapters\WhatsMyName\WhatsMyNameDataset;
use App\Sources\Adapters\WhatsMyName\WhatsMyNameReportParser;
use App\Sources\Adapters\WhatsMyNameAdapter;
use App\Sources\Adapters\WhatsMyNameMockAdapter;
use App\Sources\Exceptions\SourceInvalidInputException;
use App\Sources\Exceptions\SourceTimeoutException;
use App\Sources\Exceptions\SourceUnavailableException;
use App\Sources\InvestigationContext;
use App\Sources\InvestigationInput;
use App\Sources\SourceRegistry;
use Closure;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Adapter real do WhatsMyName com HTTP simulado: cobre os cinco cenários
 * pedidos (encontrado, nenhum resultado, fonte indisponível, timeout e saída
 * inválida) além das regras do schema (categorias excluídas, `post_body`,
 * `strip_bad_char`, `uri_pretty`).
 */
class WhatsMyNameAdapterTest extends TestCase
{
    private function datasetPath(): string
    {
        return __DIR__.'/../Fixtures/whatsmyname/wmn-data.json';
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Nada de requisições reais: todo site verificado deve estar stubado.
        Http::preventStrayRequests();

        config([
            'sources.whatsmyname.database' => $this->datasetPath(),
            'sources.whatsmyname.revision' => null,
            'sources.whatsmyname.categories' => [],
            'sources.whatsmyname.sites' => [],
            'sources.whatsmyname.max_sites' => 40,
            'sources.whatsmyname.concurrency' => 10,
            'sources.whatsmyname.execution_timeout' => 45,
        ]);
    }

    private function adapter(?Closure $clock = null): WhatsMyNameAdapter
    {
        return new WhatsMyNameAdapter(
            new WhatsMyNameDataset(new Filesystem),
            new WhatsMyNameChecker($clock),
            new WhatsMyNameReportParser,
        );
    }

    private function context(string $username = 'python'): InvestigationContext
    {
        return new InvestigationContext(input: new InvestigationInput(username: $username));
    }

    /**
     * Relógio determinístico: cada chamada devolve o próximo valor da série.
     */
    private function clock(float ...$values): Closure
    {
        $index = 0;

        return function () use (&$index, $values): float {
            return $values[min($index++, count($values) - 1)];
        };
    }

    private function fakeAllSites(string $github = 'octocat', int $githubStatus = 200): void
    {
        Http::fake([
            'https://github.com/python' => Http::response($github, $githubStatus),
            'https://t.me/python' => Http::response('tgme_page_title', 200),
            'https://graphql.anilist.co' => Http::response('{"data":{"User":{"id":1}}}', 200),
            'https://python.blogspot.com*' => Http::response('>Blog not found<', 404),
        ]);
    }

    public function test_finds_existing_accounts_and_applies_the_dataset_rules(): void
    {
        $this->fakeAllSites();

        $outcome = $this->adapter()->execute($this->context());

        $this->assertSame(
            ['GitHub', 'Telegram', 'AniList'],
            array_map(static fn ($finding) => $finding->title, $outcome->findings),
        );

        $this->assertSame('whatsmyname', $outcome->metadata['tool']);
        $this->assertSame('1111111111111111111111111111111111111111', $outcome->metadata['tool_version']);
        $this->assertSame('CC BY-SA 4.0', $outcome->metadata['dataset_license']);

        // 'xx NSFW xx' e o site sem {account} ficam de fora.
        $this->assertSame(4, $outcome->metadata['sites_checked']);
        $this->assertSame(4, $outcome->metadata['sites_reachable']);
        $this->assertSame(3, $outcome->metadata['accounts_found']);
        $this->assertSame([], $outcome->warnings);

        // A URL pública vem de `uri_pretty`, não da URL interna de verificação.
        $anilist = collect($outcome->findings)->firstWhere('title', 'AniList');
        $this->assertSame('https://anilist.co/user/python', $anilist->url);
        $this->assertSame('POST', $anilist->metadata['method']);

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'adult.example'));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://graphql.anilist.co'
            && $request->method() === 'POST'
            && $request->hasHeader('Content-Type', 'application/json')
            && str_contains($request->body(), 'python'));
    }

    public function test_returns_no_results_when_username_is_free_everywhere(): void
    {
        Http::fake([
            'https://github.com/python' => Http::response('Not Found', 404),
            'https://t.me/python' => Http::response('tgme_page_icon', 200),
            'https://graphql.anilist.co' => Http::response('Not Found', 404),
            'https://python.blogspot.com*' => Http::response('>Blog not found<', 404),
        ]);

        $outcome = $this->adapter()->execute($this->context());

        $this->assertTrue($outcome->isEmpty());
        $this->assertSame(0, $outcome->metadata['accounts_found']);
        $this->assertSame(4, $outcome->metadata['sites_reachable']);
        $this->assertArrayNotHasKey('sites_unresolved', $outcome->metadata);
        $this->assertSame([], $outcome->warnings);
    }

    public function test_unreachable_source_is_reported_as_unavailable(): void
    {
        Http::fake(fn () => throw new ConnectionException('connection refused'));

        $this->expectException(SourceUnavailableException::class);

        $this->adapter()->execute($this->context());
    }

    public function test_invalid_dataset_output_is_reported_as_unavailable(): void
    {
        config(['sources.whatsmyname.database' => __DIR__.'/../Fixtures/whatsmyname/invalid-data.json']);

        Http::fake();

        $this->expectException(SourceUnavailableException::class);

        $this->adapter()->execute($this->context());
    }

    public function test_missing_dataset_is_reported_as_unavailable(): void
    {
        config(['sources.whatsmyname.database' => __DIR__.'/../Fixtures/whatsmyname/does-not-exist.json']);

        $this->expectException(SourceUnavailableException::class);

        $this->adapter()->execute($this->context());
    }

    public function test_timeout_without_results_is_reported_as_timeout(): void
    {
        config(['sources.whatsmyname.execution_timeout' => 5, 'sources.whatsmyname.concurrency' => 1]);
        $this->fakeAllSites('Not Found', 404);

        $this->expectException(SourceTimeoutException::class);

        $this->adapter($this->clock(0.0, 0.0, 6.0))->execute($this->context());
    }

    public function test_timeout_with_results_returns_partial_and_warns(): void
    {
        config(['sources.whatsmyname.execution_timeout' => 5, 'sources.whatsmyname.concurrency' => 1]);
        $this->fakeAllSites();

        $outcome = $this->adapter($this->clock(0.0, 0.0, 6.0))->execute($this->context());

        $this->assertSame(['GitHub'], array_map(static fn ($finding) => $finding->title, $outcome->findings));
        $this->assertTrue($outcome->metadata['partial']);
        $this->assertContains(
            'Tempo limite atingido: resultados parciais (a verificação não terminou).',
            $outcome->warnings,
        );
    }

    public function test_applies_strip_bad_char_from_the_dataset(): void
    {
        config(['sources.whatsmyname.sites' => ['Blogspot']]);
        Http::fake(['https://joaosilva.blogspot.com*' => Http::response('>Blog not found<', 404)]);

        $this->adapter()->execute($this->context('joao.silva'));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'https://joaosilva.blogspot.com'));
    }

    public function test_unsafe_username_is_rejected_without_any_request(): void
    {
        Http::fake();

        try {
            $this->adapter()->execute($this->context('-o /etc/passwd'));
            $this->fail('Esperava SourceInvalidInputException.');
        } catch (SourceInvalidInputException) {
            Http::assertNothingSent();
        }
    }

    public function test_health_check_reads_the_dataset(): void
    {
        $this->assertTrue($this->adapter()->healthCheck());

        config(['sources.whatsmyname.database' => __DIR__.'/../Fixtures/whatsmyname/invalid-data.json']);

        $this->assertFalse($this->adapter()->healthCheck());
    }

    public function test_registry_uses_mock_outside_production_and_real_in_production(): void
    {
        // Fora de produção: mock, mesmo com o driver da fonte em `http`.
        config(['sources.whatsmyname.driver' => 'http']);
        app()->forgetInstance(SourceRegistry::class);
        $this->assertInstanceOf(WhatsMyNameMockAdapter::class, app(SourceRegistry::class)->get('whatsmyname'));

        // Produção: real, mesmo com o driver da fonte em `mock`.
        $this->app['env'] = 'production';
        config(['osint.mode' => 'real', 'sources.whatsmyname.driver' => 'mock']);
        app()->forgetInstance(SourceRegistry::class);
        $this->assertInstanceOf(WhatsMyNameAdapter::class, app(SourceRegistry::class)->get('whatsmyname'));
    }
}
