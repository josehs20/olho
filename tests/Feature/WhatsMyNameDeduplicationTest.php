<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Services\CreditLedgerService;
use App\Services\InvestigationService;
use App\Sources\Adapters\WhatsMyNameAdapter;
use App\Sources\SourceRegistry;
use App\Sources\SourceRunStatus;
use Database\Seeders\SourceDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Integração do WhatsMyName com deduplicação e evidências (prompt 34).
 *
 * O adapter **real** do WhatsMyName (verificações conforme o dataset, com HTTP
 * simulado) roda ao lado do mock do Maigret: a descoberta comum deve ser
 * agrupada mantendo as duas fontes, sem perder as evidências de cada uma.
 */
class WhatsMyNameDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Hermeticidade: nenhum site verificado pode escapar para a rede real.
        Http::preventStrayRequests();

        $this->seed(SourceDefinitionSeeder::class);

        config([
            'queue.default' => 'sync',
            'cache.default' => 'array',
            'sources.whatsmyname.database' => __DIR__.'/../Fixtures/whatsmyname/wmn-data.json',
            'sources.whatsmyname.revision' => null,
            'sources.whatsmyname.categories' => [],
            'sources.whatsmyname.sites' => [],
            'sources.whatsmyname.max_sites' => 40,
            'sources.whatsmyname.concurrency' => 10,
            'sources.whatsmyname.execution_timeout' => 45,
        ]);

        // Registra o adapter real (http) sobre o mock do registry.
        app(SourceRegistry::class)->register(app(WhatsMyNameAdapter::class));
    }

    public function test_shared_discovery_is_deduplicated_keeping_both_sources_and_evidence(): void
    {
        // O dataset aponta o GitHub como existente para o username verificado,
        // e o mock do Maigret encontra o mesmo perfil. Os demais sites do
        // dataset respondem "não encontrado".
        Http::fake([
            'https://github.com/joao' => Http::response('octocat', 200),
            'https://t.me/joao' => Http::response('tgme_page_icon', 200),
            'https://graphql.anilist.co' => Http::response('Not Found', 404),
            'https://joao.blogspot.com*' => Http::response('>Blog not found<', 404),
        ]);

        $user = User::factory()->create();
        $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);
        app(CreditLedgerService::class)->promotional($user, $wallet, 50, 'wmn-dedup-'.$user->id);

        $investigation = app(InvestigationService::class)->create(
            $user,
            ['username' => 'joao'],
            ['maigret', 'whatsmyname'],
            'Dedup WhatsMyName',
        );

        $investigation->refresh();
        $run = $investigation->currentRun;

        $this->assertSame(2, $run->sourceRuns()->where('status', SourceRunStatus::Completed->value)->count());

        $whatsMyName = $run->sourceRuns()->where('source', 'whatsmyname')->firstOrFail();
        $this->assertSame(1, $whatsMyName->result_count);
        $this->assertSame(4, $whatsMyName->metrics['adapter']['sites_checked']);
        $this->assertSame('CC BY-SA 4.0', $whatsMyName->metrics['adapter']['dataset_license']);

        $results = $run->sourceRuns()->with('sourceResults.evidence')->get()->flatMap->sourceResults;

        $shared = $results->groupBy('dedup_hash')->first(
            fn ($items) => $items->contains(fn ($item) => $item->url === 'https://github.com/joao'),
        );

        $this->assertNotNull($shared, 'A descoberta comum deveria formar um grupo.');
        $this->assertCount(2, $shared, 'Cada fonte mantém sua própria descoberta.');
        $this->assertSame(
            ['maigret', 'whatsmyname'],
            collect($shared->first()->metadata['dedup_sources'])->sort()->values()->all(),
        );
        $this->assertSame($shared->first()->id, $shared->last()->metadata['duplicate_of']);

        // Evidências preservadas e hash de deduplicação em todos os resultados.
        $this->assertSame(0, $results->filter(fn ($result) => $result->evidence->isEmpty())->count());
        $this->assertSame(0, $results->whereNull('dedup_hash')->count());
    }
}
