<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Services\CreditLedgerService;
use App\Services\InvestigationService;
use App\Sources\Adapters\Maigret\MaigretReportParser;
use App\Sources\Adapters\MaigretAdapter;
use App\Sources\Adapters\Sherlock\SherlockReportParser;
use App\Sources\Adapters\SherlockAdapter;
use App\Sources\Runners\ToolVersionDetector;
use App\Sources\Runners\ToolWorkspace;
use App\Sources\SourceRegistry;
use App\Sources\SourceRunStatus;
use Database\Seeders\SourceDefinitionSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CrossSourceFakeRunner;
use Tests\TestCase;

/**
 * Deduplicação entre fontes reais (prompt 33): a mesma descoberta apontada por
 * Maigret e Sherlock deve ser agrupada, mantendo a lista de fontes observadoras
 * e sem apagar as evidências de cada uma.
 *
 * Os adapters são os de produção; apenas a execução externa é substituída por
 * um runner que devolve os relatórios reais das ferramentas (fixtures).
 */
class CrossSourceDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SourceDefinitionSeeder::class);
        config(['queue.default' => 'sync', 'cache.default' => 'array']);
    }

    public function test_same_discovery_from_maigret_and_sherlock_is_deduplicated_keeping_sources(): void
    {
        $runner = new CrossSourceFakeRunner(
            maigretReport: json_decode(
                (string) file_get_contents(__DIR__.'/../Fixtures/maigret/report_python_simple.json'),
                true,
            ),
            sherlockCsv: (string) file_get_contents(__DIR__.'/../Fixtures/sherlock/report_python.csv'),
        );

        $files = new Filesystem;
        $registry = app(SourceRegistry::class);
        $registry->register(new MaigretAdapter($runner, new MaigretReportParser, new ToolWorkspace($files), new ToolVersionDetector($runner)));
        $registry->register(new SherlockAdapter($runner, new SherlockReportParser, new ToolWorkspace($files), new ToolVersionDetector($runner)));

        $user = User::factory()->create();
        $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);
        app(CreditLedgerService::class)->promotional($user, $wallet, 50, 'dedup-'.$user->id);

        $investigation = app(InvestigationService::class)->create(
            $user,
            ['username' => 'python'],
            ['maigret', 'sherlock'],
            'Dedup cruzada',
        );

        $investigation->refresh();
        $run = $investigation->currentRun;

        $this->assertSame(2, $run->sourceRuns()->count());
        $this->assertSame(2, $run->sourceRuns()->where('status', SourceRunStatus::Completed->value)->count());

        $results = $run->sourceRuns()->with('sourceResults.evidence')->get()->flatMap->sourceResults;
        $groups = $results->groupBy('dedup_hash');

        // Telegram: mesma URL nas duas ferramentas.
        $telegram = $groups->first(fn ($items) => $items->contains(fn ($item) => $item->url === 'https://t.me/python'));

        $this->assertNotNull($telegram, 'A descoberta comum deveria formar um grupo.');
        $this->assertCount(2, $telegram, 'Cada fonte mantém sua própria descoberta.');
        $this->assertSame(
            ['maigret', 'sherlock'],
            collect($telegram->first()->metadata['dedup_sources'])->sort()->values()->all(),
        );
        $this->assertSame($telegram->first()->id, $telegram->last()->metadata['duplicate_of']);

        // GitHub: sherlock usa www.github.com e maigret github.com; a
        // canonicalização reconhece a mesma descoberta.
        $github = $groups->first(fn ($items) => $items->contains(fn ($item) => str_contains((string) $item->url, 'github.com/python')));

        $this->assertNotNull($github);
        $this->assertCount(2, $github);
        $this->assertSame(
            ['https://github.com/python', 'https://www.github.com/python'],
            $github->pluck('url')->sort()->values()->all(),
        );

        // Nenhuma evidência é perdida na deduplicação.
        $this->assertSame(0, $results->filter(fn ($result) => $result->evidence->isEmpty())->count());
        $this->assertSame(0, $results->whereNull('dedup_hash')->count());
    }
}
