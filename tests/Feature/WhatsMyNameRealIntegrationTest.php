<?php

namespace Tests\Feature;

use App\Models\SourceResult;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CreditLedgerService;
use App\Services\InvestigationService;
use App\Sources\Adapters\WhatsMyNameAdapter;
use App\Sources\SourceRegistry;
use App\Sources\SourceRunStatus;
use Database\Seeders\SourceDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste controlado com o dataset REAL do WhatsMyName (prompt 34).
 *
 * Executa as verificações conforme o schema publicado contra um identificador
 * público institucional (`python`) e valida que o resultado chega ao banco,
 * à normalização (hash de deduplicação) e ao relatório.
 *
 * Requer rede e o dataset fixado na imagem; por isso fica atrás de `OSINT_E2E=1`:
 *
 * ```bash
 * docker compose exec -e OSINT_E2E=1 app php artisan test --filter=WhatsMyNameRealIntegrationTest
 * ```
 */
class WhatsMyNameRealIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** Identificador público institucional, escolhido para o teste controlado. */
    private const TEST_USERNAME = 'python';

    protected function setUp(): void
    {
        parent::setUp();

        if (env('OSINT_E2E') !== '1') {
            $this->markTestSkipped('Teste com a fonte real: execute com OSINT_E2E=1.');
        }

        $this->seed(SourceDefinitionSeeder::class);

        $adapter = app(WhatsMyNameAdapter::class);

        if (! $adapter->healthCheck()) {
            $this->markTestSkipped('O dataset do WhatsMyName não está disponível nesta imagem.');
        }

        // Escopo enxuto: sites públicos e estáveis para o identificador de teste.
        config([
            'sources.whatsmyname.sites' => [
                'GitHub', 'GitLab', 'BitBucket', 'Telegram', 'Reddit',
                'Pinterest', 'TikTok', 'Vimeo', 'Medium', 'Spotify',
                'Keybase', 'Gravatar', 'HackerNews', 'Codeberg',
            ],
            'sources.whatsmyname.max_sites' => 40,
            'sources.whatsmyname.concurrency' => 10,
            'sources.whatsmyname.execution_timeout' => 60,
            'queue.default' => 'sync',
            'cache.default' => 'array',
        ]);

        app(SourceRegistry::class)->register($adapter);
    }

    public function test_real_dataset_results_reach_database_normalization_and_report(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);
        app(CreditLedgerService::class)->promotional($user, $wallet, 50, 'wmn-e2e-'.$user->id);

        $investigation = app(InvestigationService::class)->create(
            $user,
            ['username' => self::TEST_USERNAME],
            ['whatsmyname'],
            'WhatsMyName real — '.self::TEST_USERNAME,
        );

        $investigation->refresh();
        $run = $investigation->currentRun;
        $sourceRun = $run->sourceRuns()->firstOrFail();

        $this->assertSame(SourceRunStatus::Completed->value, $sourceRun->status);
        $this->assertSame('whatsmyname', $sourceRun->source);
        $this->assertNotNull($sourceRun->tool_version);
        $this->assertGreaterThan(0, $sourceRun->result_count);
        $this->assertGreaterThan(0, $sourceRun->duration_ms);
        $this->assertGreaterThan(0, $sourceRun->metrics['adapter']['sites_checked']);

        $results = SourceResult::query()
            ->whereHas('sourceRun', fn ($query) => $query->where('investigation_run_id', $run->id))
            ->with('evidence')
            ->get();

        $this->assertSame($sourceRun->result_count, $results->count());
        $this->assertSame(0, $results->filter(fn (SourceResult $result) => $result->evidence->isEmpty())->count());
        $this->assertSame(0, $results->whereNull('url')->count());
        $this->assertSame(0, $results->whereNull('dedup_hash')->count());

        $this->assertArrayHasKey('score', $run->metadata['correlation']);
        $this->assertTrue($run->report()->exists());
    }
}
