<?php

namespace Tests\Feature;

use App\Models\SourceResult;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CreditLedgerService;
use App\Services\InvestigationService;
use App\Sources\Adapters\MaigretAdapter;
use App\Sources\Adapters\SherlockAdapter;
use App\Sources\SourceRegistry;
use App\Sources\SourceRunStatus;
use Database\Seeders\SourceDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste controlado com as ferramentas REAIS (prompt 33).
 *
 * Executa Maigret e Sherlock instalados na imagem contra um identificador
 * público institucional (`python`) e valida que:
 * - as duas fontes chegam ao banco com versão da ferramenta e contagem;
 * - a mesma descoberta encontrada pelas duas é deduplicada **sem perder a
 *   lista de fontes** (`metadata.dedup_sources`);
 * - o relatório é gerado.
 *
 * Requer rede e os binários instalados; por isso fica atrás de `OSINT_E2E=1`:
 *
 * ```bash
 * docker compose exec -e OSINT_E2E=1 app php artisan test --filter=SherlockRealIntegrationTest
 * ```
 */
class SherlockRealIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** Identificador público institucional, escolhido para o teste controlado. */
    private const TEST_USERNAME = 'python';

    protected function setUp(): void
    {
        parent::setUp();

        if (env('OSINT_E2E') !== '1') {
            $this->markTestSkipped('Teste com as ferramentas reais: execute com OSINT_E2E=1.');
        }

        $this->seed(SourceDefinitionSeeder::class);

        $maigret = app(MaigretAdapter::class);
        $sherlock = app(SherlockAdapter::class);

        if (! $maigret->healthCheck() || ! $sherlock->healthCheck()) {
            $this->markTestSkipped('Maigret/Sherlock não estão disponíveis nesta imagem.');
        }

        // Força os adapters reais independentemente do driver configurado.
        app(SourceRegistry::class)->register($maigret);
        app(SourceRegistry::class)->register($sherlock);

        config(['queue.default' => 'sync', 'cache.default' => 'array']);
    }

    public function test_real_tools_share_discoveries_through_deduplication(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);
        app(CreditLedgerService::class)->promotional($user, $wallet, 50, 'real-dedup-'.$user->id);

        $investigation = app(InvestigationService::class)->create(
            $user,
            ['username' => self::TEST_USERNAME],
            ['maigret', 'sherlock'],
            'Ferramentas reais — '.self::TEST_USERNAME,
        );

        $investigation->refresh();
        $run = $investigation->currentRun;

        // As duas fontes executaram de verdade e registraram seus dados.
        $this->assertSame(2, $run->sourceRuns()->count());
        $this->assertSame(2, $run->sourceRuns()->where('status', SourceRunStatus::Completed->value)->count());

        foreach ($run->sourceRuns()->get() as $sourceRun) {
            $this->assertNotNull($sourceRun->tool_version, "{$sourceRun->source} deveria registrar a versão da ferramenta.");
            $this->assertGreaterThan(0, $sourceRun->result_count, "{$sourceRun->source} deveria encontrar resultados.");
        }

        $results = SourceResult::query()
            ->whereHas('sourceRun', fn ($query) => $query->where('investigation_run_id', $run->id))
            ->with(['evidence', 'sourceDefinition'])
            ->get();

        $this->assertGreaterThan(0, $results->count());
        $this->assertSame(0, $results->filter(fn (SourceResult $result) => $result->evidence->isEmpty())->count());
        $this->assertSame(0, $results->whereNull('dedup_hash')->count());

        // A mesma descoberta pelas duas ferramentas forma um grupo com as
        // duas fontes listadas, sem apagar nenhuma das evidências.
        $shared = $results->groupBy('dedup_hash')->filter(fn ($items) => $items->count() > 1);

        $this->assertGreaterThan(
            0,
            $shared->count(),
            'Maigret e Sherlock deveriam compartilhar ao menos uma descoberta (mesma URL canônica).',
        );

        foreach ($shared as $group) {
            $this->assertSame(
                ['maigret', 'sherlock'],
                collect($group->first()->metadata['dedup_sources'])->sort()->values()->all(),
            );
        }

        // Relatório gerado com as duas fontes.
        $this->assertTrue($run->report()->exists());

        $this->actingAs($user)
            ->get(route('investigations.report', [$investigation, $run]))
            ->assertOk()
            ->assertSee('Maigret')
            ->assertSee('Sherlock');
    }
}
