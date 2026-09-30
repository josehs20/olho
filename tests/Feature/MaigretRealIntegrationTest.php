<?php

namespace Tests\Feature;

use App\Models\SourceResult;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CreditLedgerService;
use App\Services\InvestigationService;
use App\Sources\Adapters\MaigretAdapter;
use App\Sources\SourceRegistry;
use App\Sources\SourceRunStatus;
use Database\Seeders\SourceDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste controlado com a ferramenta REAL (prompt 32).
 *
 * Executa o Maigret instalado na imagem contra um identificador público
 * institucional (`python`) e verifica que o resultado chega ao banco,
 * à normalização/deduplicação/correlação e ao relatório.
 *
 * Requer rede e o binário instalado; por isso fica atrás de `OSINT_E2E=1`:
 *
 * ```bash
 * docker compose exec -e OSINT_E2E=1 app php artisan test --filter=MaigretRealIntegrationTest
 * ```
 */
class MaigretRealIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** Identificador público institucional, escolhido para o teste controlado. */
    private const TEST_USERNAME = 'python';

    protected function setUp(): void
    {
        parent::setUp();

        if (env('OSINT_E2E') !== '1') {
            $this->markTestSkipped('Teste com a ferramenta real: execute com OSINT_E2E=1.');
        }

        $this->seed(SourceDefinitionSeeder::class);

        $adapter = app(MaigretAdapter::class);

        if (! $adapter->healthCheck()) {
            $this->markTestSkipped('O binário do Maigret não está disponível nesta imagem.');
        }

        // Força o adapter real independentemente do driver configurado.
        app(SourceRegistry::class)->register($adapter);

        config(['queue.default' => 'sync', 'cache.default' => 'array']);
    }

    public function test_maigret_results_reach_database_normalization_and_report(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);
        app(CreditLedgerService::class)->promotional($user, $wallet, 50, 'maigret-e2e-'.$user->id);

        $investigation = app(InvestigationService::class)->create(
            $user,
            ['username' => self::TEST_USERNAME],
            ['maigret'],
            'Maigret real — '.self::TEST_USERNAME,
        );

        $investigation->refresh();
        $run = $investigation->currentRun;
        $sourceRun = $run->sourceRuns()->firstOrFail();

        // Execução da ferramenta real registrada no banco.
        $this->assertSame(SourceRunStatus::Completed->value, $sourceRun->status);
        $this->assertSame('maigret', $sourceRun->source);
        $this->assertSame(1, $sourceRun->attempts);
        $this->assertNotNull($sourceRun->tool_version);
        $this->assertGreaterThan(0, $sourceRun->result_count);
        $this->assertGreaterThan(0, $sourceRun->duration_ms);

        // Resultados normalizados, com evidência e hash de deduplicação.
        $results = SourceResult::query()
            ->whereHas('sourceRun', fn ($query) => $query->where('investigation_run_id', $run->id))
            ->with('evidence')
            ->get();

        $this->assertSame($sourceRun->result_count, $results->count());
        $this->assertSame(0, $results->filter(fn (SourceResult $result) => $result->evidence->isEmpty())->count());
        $this->assertSame(0, $results->whereNull('dedup_hash')->count());
        $this->assertSame(0, $results->whereNull('url')->count());

        // Correlação e relatório.
        $this->assertArrayHasKey('score', $run->metadata['correlation']);
        $this->assertTrue($run->report()->exists());

        $this->actingAs($user)
            ->get(route('investigations.report', [$investigation, $run]))
            ->assertOk()
            ->assertSee('Maigret');
    }
}
