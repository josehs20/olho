<?php

namespace Tests\Feature;

use App\Models\CreditReservation;
use App\Models\CreditTransaction;
use App\Models\Investigation;
use App\Models\InvestigationRun;
use App\Models\SourceDefinition;
use App\Models\SourceResult;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CreditLedgerService;
use App\Services\InvestigationService;
use App\Sources\SourceRunStatus;
use Database\Seeders\SourceDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ponta a ponta com os adapters mock, sem `Queue::fake()`.
 *
 * Percorre a cadeia real de jobs (InvestigationService -> StartInvestigationRunJob
 * -> ExecuteSourceJob -> NormalizeSourceResultsJob -> DeduplicateResultsJob ->
 * BuildCorrelationJob -> BuildHtmlReportJob -> ReleaseReservedCreditsJob) usando
 * a fila síncrona, para provar que o fluxo completo funciona apenas com mocks.
 */
class InvestigationE2EMockTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private const ALL_MOCK_MODULES = [
        'mock-username',
        'mock-email',
        'mock-web',
        'maigret',
        'sherlock',
        'whatsmyname',
        'holehe',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SourceDefinitionSeeder::class);

        // phpunit.xml já define QUEUE_CONNECTION=sync; explícito para o teste não
        // depender do ambiente. Cache de fonte em memória evita vazar resultados
        // entre execuções.
        config(['queue.default' => 'sync', 'cache.default' => 'array']);
    }

    /**
     * Cria usuário com carteira financiada pelo ledger (saldo == soma das transações).
     *
     * @return array{0: User, 1: Wallet}
     */
    private function userWithFundedWallet(int $credits): array
    {
        $user = User::factory()->create();
        $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

        app(CreditLedgerService::class)->promotional(
            $user,
            $wallet,
            $credits,
            'e2e-topup-'.$user->id,
        );

        return [$user, $wallet->fresh()];
    }

    public function test_full_pipeline_runs_end_to_end_with_only_mock_adapters(): void
    {
        [$user, $wallet] = $this->userWithFundedWallet(50);

        $investigation = app(InvestigationService::class)->create(
            $user,
            [
                'name' => 'João Silva',
                'username' => 'joao.silva',
                'email' => 'joao.silva@example.com',
                'city' => 'São Paulo',
                'state' => 'SP',
            ],
            self::ALL_MOCK_MODULES,
            'E2E mock — todos os adapters',
        );

        $investigation->refresh();
        $run = $investigation->currentRun()->firstOrFail();

        // 1. Investigação e run finalizados com timestamps.
        $this->assertSame(Investigation::STATUS_COMPLETED, $investigation->status);
        $this->assertSame(InvestigationRun::STATUS_COMPLETED, $run->status);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);

        // 2. Cada módulo selecionado gerou um source_run executado com sucesso.
        $this->assertSame(count(self::ALL_MOCK_MODULES), $run->sourceRuns()->count());
        $this->assertSame(
            0,
            $run->sourceRuns()->where('status', '!=', SourceRunStatus::Completed->value)->count(),
        );

        // 3. Resultados persistidos e sempre acompanhados de evidência.
        $results = SourceResult::query()
            ->whereHas('sourceRun', fn ($query) => $query->where('investigation_run_id', $run->id))
            ->with('evidence')
            ->get();

        $this->assertGreaterThan(0, $results->count());
        $this->assertSame(0, $results->filter(fn (SourceResult $r) => $r->evidence->isEmpty())->count());

        // 4. Deduplicação marcou itens repetidos entre fontes diferentes
        //    (ex.: github.com/{username} vem do maigret e do whatsmyname).
        $duplicates = $results->filter(fn (SourceResult $r) => ! empty($r->metadata['duplicate_of']));
        $this->assertGreaterThan(0, $duplicates->count());

        $representative = $results->firstWhere('id', $duplicates->first()->metadata['duplicate_of']);
        $this->assertNotNull($representative);
        $this->assertNotEmpty($representative->metadata['dedup_sources'] ?? []);

        // 5. Correlação persistida e explicável (username exato presente na entrada).
        $correlation = $run->metadata['correlation'] ?? null;
        $this->assertIsArray($correlation);
        $this->assertGreaterThanOrEqual(40, $correlation['score']);
        $this->assertSame(config('correlation.rule_version'), $correlation['rule_version']);
        $this->assertNotEmpty($correlation['evidence_ids']);
        $this->assertContains(
            'username_exact_match',
            array_column($correlation['signals'], 'name'),
        );

        // 6. Relatório gerado.
        $this->assertTrue($run->report()->exists());
        $this->assertDatabaseHas('reports', [
            'investigation_run_id' => $run->id,
            'status' => 'completed',
        ]);

        // 7. Etapas do pipeline registradas (idempotência).
        foreach (['normalized_at', 'deduplicated_at', 'correlated_at', 'reported_at', 'credits_released_at'] as $stage) {
            $this->assertTrue($run->pipelineStage($stage), "Etapa {$stage} não registrada.");
        }

        // 8. Créditos: reserva total consumida e saldo consistente com o ledger.
        $totalCost = (int) SourceDefinition::query()
            ->whereIn('slug', self::ALL_MOCK_MODULES)
            ->sum('cost_credits');

        $this->assertSame($totalCost, $run->credits_reserved);
        $this->assertSame($totalCost, $run->credits_consumed);
        $this->assertSame(50 - $totalCost, $wallet->fresh()->balance);
        $this->assertSame(
            $wallet->fresh()->balance,
            (int) CreditTransaction::query()->where('wallet_id', $wallet->id)->sum('amount'),
        );
        $this->assertSame(
            0,
            CreditReservation::query()->where('user_id', $user->id)->where('status', 'pending')->count(),
        );
    }

    /**
     * Regressão: o run não pode ser finalizado antes de todos os source_runs
     * existirem. Com fila síncrona, `mock-web` (sucesso) é criado/executado antes
     * de `sherlock` (timeout) — ambos por id e por ordem alfabética de slug — e
     * mesmo assim o run precisa terminar como `partial`.
     */
    public function test_run_is_not_finalized_before_all_source_runs_exist(): void
    {
        [$user] = $this->userWithFundedWallet(50);

        $investigation = app(InvestigationService::class)->create(
            $user,
            ['name' => 'Empresa XPTO', 'username' => 'timeout'],
            ['mock-web', 'sherlock'],
            'E2E mock — fonte indisponível',
        );

        $investigation->refresh();
        $run = $investigation->currentRun()->firstOrFail();

        $this->assertSame(2, $run->sourceRuns()->count());
        $this->assertSame(
            1,
            $run->sourceRuns()->where('status', SourceRunStatus::Completed->value)->count(),
        );
        $this->assertSame(
            1,
            $run->sourceRuns()->where('status', SourceRunStatus::Timeout->value)->count(),
        );

        $this->assertSame(InvestigationRun::STATUS_PARTIAL, $run->status);
        $this->assertSame(Investigation::STATUS_PARTIAL, $investigation->status);

        // Resultados da fonte que respondeu continuam disponíveis (falha isolada).
        $this->assertGreaterThan(0, SourceResult::query()
            ->whereHas('sourceRun', fn ($query) => $query->where('investigation_run_id', $run->id))
            ->count());

        $this->assertTrue($run->report()->exists());
        $this->assertTrue($run->pipelineStage('credits_released_at'));
    }
}
