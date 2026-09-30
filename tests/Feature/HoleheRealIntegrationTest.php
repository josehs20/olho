<?php

namespace Tests\Feature;

use App\Models\SourceResult;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CreditLedgerService;
use App\Services\InvestigationService;
use App\Sources\Adapters\HoleheAdapter;
use App\Sources\SourceRegistry;
use App\Sources\SourceRunStatus;
use Database\Seeders\SourceDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste controlado com o Holehe REAL (prompt 35).
 *
 * Usa um endereço de **domínio reservado** (RFC 2606, `example.com`): não
 * pertence a nenhum titular, portanto o teste não investiga pessoas reais.
 * A execução sempre usa `--no-password-recovery`, então nenhuma redefinição de
 * senha é disparada.
 *
 * Requer rede e o binário instalado; por isso fica atrás de `OSINT_E2E=1`:
 *
 * ```bash
 * docker compose exec -e OSINT_E2E=1 app php artisan test --filter=HoleheRealIntegrationTest
 * ```
 */
class HoleheRealIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_EMAIL = 'test@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        if (env('OSINT_E2E') !== '1') {
            $this->markTestSkipped('Teste com a ferramenta real: execute com OSINT_E2E=1.');
        }

        $this->seed(SourceDefinitionSeeder::class);

        $adapter = app(HoleheAdapter::class);

        if (! $adapter->healthCheck()) {
            $this->markTestSkipped('O binário do Holehe não está disponível nesta imagem.');
        }

        app(SourceRegistry::class)->register($adapter);

        config(['queue.default' => 'sync', 'cache.default' => 'array']);
    }

    public function test_real_holehe_runs_without_password_recovery_and_reaches_the_pipeline(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);
        app(CreditLedgerService::class)->promotional($user, $wallet, 50, 'holehe-e2e-'.$user->id);

        $investigation = app(InvestigationService::class)->create(
            $user,
            ['email' => self::TEST_EMAIL],
            ['holehe'],
            'Holehe real — '.self::TEST_EMAIL,
        );

        $investigation->refresh();
        $run = $investigation->currentRun;
        $sourceRun = $run->sourceRuns()->firstOrFail();

        // A execução real concluiu e registrou a versão da ferramenta.
        $this->assertSame(SourceRunStatus::Completed->value, $sourceRun->status);
        $this->assertSame('holehe', $sourceRun->source);
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', (string) $sourceRun->tool_version);
        $this->assertGreaterThan(0, $sourceRun->duration_ms);

        // Salvaguardas registradas na execução.
        $this->assertFalse($sourceRun->metrics['adapter']['password_recovery']);
        $this->assertArrayHasKey('disclaimer', $sourceRun->metrics['adapter']);

        // Resultados (se houver) normalizados com evidência e hash.
        $results = SourceResult::query()
            ->whereHas('sourceRun', fn ($query) => $query->where('investigation_run_id', $run->id))
            ->with('evidence')
            ->get();

        $this->assertSame($sourceRun->result_count, $results->count());
        $this->assertSame(0, $results->filter(fn (SourceResult $result) => $result->evidence->isEmpty())->count());
        $this->assertSame(0, $results->whereNull('dedup_hash')->count());

        // Nenhum dado de recuperação de conta foi persistido.
        $serialized = json_encode($results->map(fn (SourceResult $result) => $result->toArray()), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('emailrecovery', $serialized);
        $this->assertStringNotContainsString('phoneNumber', $serialized);

        // Relatório com o aviso obrigatório sobre a limitação do sinal de e-mail.
        $this->assertTrue($run->report()->exists());

        $this->actingAs($user)
            ->get(route('investigations.report', [$investigation, $run]))
            ->assertOk()
            ->assertSee('não prova que a pessoa investigada controla aquela conta', false);
    }
}
