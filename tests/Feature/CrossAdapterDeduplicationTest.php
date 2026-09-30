<?php

namespace Tests\Feature;

use App\Models\SourceResult;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CreditLedgerService;
use App\Services\InvestigationService;
use App\Services\ResultDeduplicator;
use App\Sources\Adapters\Holehe\HoleheReportParser;
use App\Sources\Adapters\HoleheAdapter;
use App\Sources\Adapters\Maigret\MaigretReportParser;
use App\Sources\Adapters\MaigretAdapter;
use App\Sources\Adapters\Sherlock\SherlockReportParser;
use App\Sources\Adapters\SherlockAdapter;
use App\Sources\Adapters\WhatsMyNameAdapter;
use App\Sources\Runners\ToolVersionDetector;
use App\Sources\Runners\ToolWorkspace;
use App\Sources\SourceRegistry;
use App\Sources\SourceResult as SourceResultDto;
use App\Sources\SourceRunStatus;
use Database\Seeders\SourceDefinitionSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\CrossSourceFakeRunner;
use Tests\TestCase;

/**
 * Deduplicação cruzada com os **quatro adapters reais** (prompt 36).
 *
 * A execução externa é substituída por um runner determinístico (fixtures reais
 * das ferramentas) e por HTTP simulado; os adapters são os de produção. O que
 * se prova aqui:
 *
 *  - a mesma descoberta observada por três ferramentas vira **uma** descoberta
 *    consolidada, com as três fontes observadoras e as evidências de cada uma;
 *  - a plataforma faz parte da chave estável;
 *  - um indício de e-mail (Holehe) **nunca** se funde com um perfil social, nem
 *    quando o domínio do serviço coincide (evita falso positivo de identidade).
 */
class CrossAdapterDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    private string $workdir;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->workdir = storage_path('framework/testing/holehe-cross');

        $this->seed(SourceDefinitionSeeder::class);

        config([
            'queue.default' => 'sync',
            'cache.default' => 'array',
            'sources.holehe.workdir' => $this->workdir,
            'sources.whatsmyname.database' => __DIR__.'/../Fixtures/whatsmyname/wmn-data.json',
            'sources.whatsmyname.revision' => null,
            'sources.whatsmyname.categories' => [],
            'sources.whatsmyname.sites' => [],
            'sources.whatsmyname.max_sites' => 40,
            'sources.whatsmyname.concurrency' => 10,
            'sources.whatsmyname.execution_timeout' => 45,
        ]);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->workdir);

        parent::tearDown();
    }

    private function registerRealAdapters(): void
    {
        $runner = new CrossSourceFakeRunner(
            maigretReport: json_decode(
                (string) file_get_contents(__DIR__.'/../Fixtures/maigret/report_python_simple.json'),
                true,
            ),
            sherlockCsv: (string) file_get_contents(__DIR__.'/../Fixtures/sherlock/report_python.csv'),
            holeheCsv: (string) file_get_contents(__DIR__.'/../Fixtures/holehe/holehe_results.csv'),
        );

        $files = new Filesystem;
        $registry = app(SourceRegistry::class);

        $registry->register(new MaigretAdapter($runner, new MaigretReportParser, new ToolWorkspace($files), new ToolVersionDetector($runner)));
        $registry->register(new SherlockAdapter($runner, new SherlockReportParser, new ToolWorkspace($files), new ToolVersionDetector($runner)));
        $registry->register(new HoleheAdapter($runner, new HoleheReportParser, new ToolWorkspace($files), new ToolVersionDetector($runner)));
        $registry->register(app(WhatsMyNameAdapter::class));
    }

    public function test_same_discovery_from_three_tools_is_consolidated_with_all_sources(): void
    {
        $this->registerRealAdapters();

        // WhatsMyName: GitHub e Telegram existem para o username; os demais não.
        Http::fake([
            'https://github.com/python' => Http::response('octocat', 200),
            'https://t.me/python' => Http::response('tgme_page_title', 200),
            'https://graphql.anilist.co' => Http::response('Not Found', 404),
            'https://python.blogspot.com*' => Http::response('>Blog not found<', 404),
        ]);

        $user = User::factory()->create();
        $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);
        app(CreditLedgerService::class)->promotional($user, $wallet, 50, 'cross-adapter-'.$user->id);

        $investigation = app(InvestigationService::class)->create(
            $user,
            ['username' => 'python', 'email' => 'alvo@example.test'],
            ['maigret', 'sherlock', 'whatsmyname', 'holehe'],
            'Dedup entre adapters reais',
        );

        $investigation->refresh();
        $run = $investigation->currentRun;

        $this->assertSame(4, $run->sourceRuns()->where('status', SourceRunStatus::Completed->value)->count());

        $results = $run->sourceRuns()->with('sourceResults.evidence')->get()->flatMap->sourceResults;

        // Chave estável gravada em todos os resultados, na versão corrente.
        $this->assertSame(0, $results->whereNull('dedup_hash')->count());
        $this->assertSame(0, $results->filter(
            fn (SourceResult $result) => ($result->metadata['dedup_key_version'] ?? null) !== ResultDeduplicator::KEY_VERSION,
        )->count());

        $groups = $results->groupBy('dedup_hash');

        // GitHub: mesma descoberta apontada por Maigret, Sherlock e WhatsMyName.
        $github = $groups->first(fn ($items) => $items->contains(fn (SourceResult $item) => $item->url === 'https://github.com/python'));

        $this->assertNotNull($github);
        $this->assertCount(3, $github);
        $this->assertSame(
            ['maigret', 'sherlock', 'whatsmyname'],
            collect($github->first()->metadata['dedup_sources'])->sort()->values()->all(),
        );
        $this->assertSame(3, $github->first()->metadata['dedup_group_size']);
        $this->assertCount(3, $github->first()->metadata['dedup_observations']);
        $this->assertSame(
            $github->first()->id,
            $github->first()->metadata['dedup_observations'][0]['result_id'],
        );

        // Apenas o representante carrega a consolidação; os demais apontam para ele.
        $this->assertSame(
            2,
            $github->filter(fn (SourceResult $item) => ($item->metadata['duplicate_of'] ?? null) === $github->first()->id)->count(),
        );
        $this->assertSame(1, $github->pluck('dedup_hash')->unique()->count());

        // Telegram: as três ferramentas também concordam.
        $telegram = $groups->first(fn ($items) => $items->contains(fn (SourceResult $item) => $item->url === 'https://t.me/python'));

        $this->assertNotNull($telegram);
        $this->assertCount(3, $telegram);
        $this->assertSame(
            ['maigret', 'sherlock', 'whatsmyname'],
            collect($telegram->first()->metadata['dedup_sources'])->sort()->values()->all(),
        );

        // Cada evidência permanece visível (nada é apagado na consolidação).
        $this->assertSame(0, $results->filter(fn (SourceResult $result) => $result->evidence->isEmpty())->count());

        // Holehe observa o e-mail em github.com/spotify.com — jamais se funde com
        // um perfil social, mesmo compartilhando o domínio do serviço.
        $holehe = $results->filter(fn (SourceResult $result) => $result->entity_type === 'email_account');

        $this->assertCount(2, $holehe);

        foreach ($holehe as $emailResult) {
            $groupOfEmail = $groups->first(fn ($items) => $items->contains($emailResult));

            $this->assertCount(1, $groupOfEmail, 'Indício de e-mail não deve ser consolidado com perfil.');
            $this->assertArrayNotHasKey('dedup_sources', $emailResult->metadata);
        }
    }

    public function test_platform_is_part_of_the_stable_key_across_tools(): void
    {
        $deduplicator = app(ResultDeduplicator::class);

        $maigret = $deduplicator->explain(new SourceResultDto(
            source: 'maigret',
            entityType: 'social_profile',
            value: 'python',
            url: 'https://www.github.com/python',
            metadata: ['site' => 'GitHub'],
        ));

        $this->assertSame(ResultDeduplicator::KEY_VERSION, $maigret['version']);
        $this->assertSame('github.com', $maigret['parts']['platform']);
        $this->assertSame('https://github.com/python', $maigret['parts']['url']);
        $this->assertSame('python', $maigret['parts']['identifier']);
        $this->assertSame('social_profile', $maigret['parts']['entity_type']);

        // Mesma plataforma e mesma observação por outra ferramenta: mesma chave.
        $sherlock = $deduplicator->explain(new SourceResultDto(
            source: 'sherlock',
            entityType: 'profile',
            value: 'Python',
            url: 'https://github.com/python/?utm_source=twitter',
        ));

        $this->assertSame($maigret['hash'], $sherlock['hash']);

        // Sem URL: a plataforma vem dos metadados do adapter.
        $withoutUrl = $deduplicator->explain(new SourceResultDto(
            source: 'holehe',
            entityType: 'email_account',
            value: 'alvo@example.test',
            metadata: ['domain' => 'github.com', 'site' => 'github'],
        ));

        $this->assertSame('github.com', $withoutUrl['parts']['platform']);
        $this->assertNotSame($maigret['hash'], $withoutUrl['hash']);
    }
}
