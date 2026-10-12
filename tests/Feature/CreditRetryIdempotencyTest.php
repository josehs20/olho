<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientCreditsException;
use App\Jobs\BuildCorrelationJob;
use App\Jobs\BuildHtmlReportJob;
use App\Jobs\DeduplicateResultsJob;
use App\Jobs\ExecuteSourceJob;
use App\Jobs\NormalizeSourceResultsJob;
use App\Jobs\ReleaseReservedCreditsJob;
use App\Jobs\StartInvestigationRunJob;
use App\Models\CreditReservation;
use App\Models\CreditTransaction;
use App\Models\Investigation;
use App\Models\InvestigationRun;
use App\Models\SourceDefinition;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CorrelationService;
use App\Services\CreditLedgerService;
use App\Services\InvestigationOrchestrator;
use App\Services\InvestigationService;
use App\Services\LogSanitizer;
use App\Services\ReportManifestBuilder;
use App\Services\ResultDeduplicator;
use App\Services\SourceCache;
use App\Services\SourceOutcomePersister;
use App\Sources\SourceRegistry;
use Database\Seeders\SourceDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Créditos, retry e idempotência (prompt 39).
 *
 * Prova, com o pipeline real (filas síncronas), que:
 *
 *  - a cobrança é por módulo concluído (módulo que falha é estornado);
 *  - retry de worker e reexecução de jobs não geram consumo duplicado;
 *  - lock/transação + idempotency key mantêm o saldo consistente com o ledger;
 *  - uma investigação parcial não corrompe o saldo;
 *  - a reexecução cria um run novo, com custo independente e histórico intacto.
 */
class CreditRetryIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SourceDefinitionSeeder::class);

        // Fila fake: as etapas do pipeline são executadas manualmente em ordem,
        // de modo que os estados intermediários (reservas, retries) são observáveis.
        Queue::fake();

        config(['cache.default' => 'array']);
    }

    /**
     * Invariante central: saldo = soma do ledger; reservas pendentes refletem o
     * que ainda não foi liquidado.
     */
    private function assertLedgerConsistent(Wallet $wallet, string $context = ''): void
    {
        $ledger = (int) CreditTransaction::query()->where('wallet_id', $wallet->id)->sum('amount');

        $this->assertSame(
            (int) $wallet->fresh()->balance,
            $ledger,
            "Saldo divergente do ledger ({$context}).",
        );
    }

    /**
     * Cria investigação direto no estado pendente (sem depender do seeder de módulos reais).
     *
     * @param  array<int, array{slug: string, type: string, cost: int}>  $modules
     */
    private function pendingInvestigation(array $modules, array $input, int $balance = 100): array
    {
        $user = User::factory()->create();
        $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

        // O saldo inicial entra pelo ledger: assim a invariante
        // `saldo == soma(ledger)` vale em todas as asserções.
        app(CreditLedgerService::class)->promotional($user, $wallet, $balance, 'fixture-credit-'.$user->id);

        $slugs = [];

        foreach ($modules as $module) {
            SourceDefinition::query()->updateOrCreate(
                ['slug' => $module['slug']],
                ['name' => $module['slug'], 'type' => $module['type'], 'cost_credits' => $module['cost'], 'timeout' => 30, 'active' => true],
            );
            $slugs[] = $module['slug'];
        }

        $investigation = Investigation::factory()->create([
            'user_id' => $user->id,
            'status' => Investigation::STATUS_QUEUED,
            'selected_modules' => $slugs,
        ]);

        $run = InvestigationRun::factory()->create([
            'investigation_id' => $investigation->id,
            'status' => InvestigationRun::STATUS_PENDING,
            'input_snapshot' => array_merge($input, ['modules' => $slugs]),
        ]);

        $investigation->current_run_id = $run->id;
        $investigation->save();

        return [$user, $wallet, $investigation, $run];
    }

    private function startRun(InvestigationRun $run): void
    {
        (new StartInvestigationRunJob($run->id))->handle(
            app(CreditLedgerService::class),
            app(InvestigationOrchestrator::class),
            app(LogSanitizer::class),
        );
    }

    private function executeAllSources(InvestigationRun $run): void
    {
        foreach ($run->sourceRuns()->get() as $sourceRun) {
            (new ExecuteSourceJob($sourceRun->id))->handle(
                app(SourceRegistry::class),
                app(SourceCache::class),
                app(SourceOutcomePersister::class),
                app(LogSanitizer::class),
            );
        }
    }

    private function finishPipeline(InvestigationRun $run): void
    {
        (new NormalizeSourceResultsJob($run->id))->handle(app(ResultDeduplicator::class));
        (new DeduplicateResultsJob($run->id))->handle(app(ResultDeduplicator::class));
        (new BuildCorrelationJob($run->id))->handle(app(CorrelationService::class));
        (new BuildHtmlReportJob($run->id))->handle(
            app(InvestigationOrchestrator::class),
            app(LogSanitizer::class),
            app(ReportManifestBuilder::class),
        );
        (new ReleaseReservedCreditsJob($run->id))->handle(app(CreditLedgerService::class));
    }

    public function test_partial_run_charges_both_queries_even_without_results(): void
    {
        [, $wallet, , $run] = $this->pendingInvestigation(
            [['slug' => 'mock-username', 'type' => 'username', 'cost' => 5], ['slug' => 'mock-email', 'type' => 'email', 'cost' => 5]],
            ['username' => 'error', 'email' => 'a@b.c'],
        );

        $this->startRun($run);

        // Reserva por módulo (nunca uma reserva única com o total).
        $this->assertSame(2, CreditReservation::query()->where('investigation_run_id', $run->id)->count());
        $this->assertSame(90, $wallet->fresh()->balance);
        $this->assertSame(10, $run->fresh()->credits_reserved);

        $this->executeAllSources($run);
        $this->finishPipeline($run);

        $run->refresh();

        $this->assertSame(InvestigationRun::STATUS_PARTIAL, $run->status);
        // As duas consultas aconteceram: cobra-se por ambas, mesmo que uma tenha
        // falhado e a outra (mock-email) tenha retornado resultado.
        $this->assertSame(10, $run->credits_consumed);
        $this->assertSame(90, $wallet->fresh()->balance);
        $this->assertSame(
            0,
            CreditReservation::query()->where('investigation_run_id', $run->id)->where('status', 'released')->count(),
        );
        $this->assertSame(
            2,
            CreditReservation::query()->where('investigation_run_id', $run->id)->where('status', 'consumed')->count(),
        );

        $this->assertLedgerConsistent($wallet, 'run parcial');
    }

    public function test_module_that_found_nothing_is_still_charged(): void
    {
        [, $wallet, , $run] = $this->pendingInvestigation(
            [['slug' => 'mock-username', 'type' => 'username', 'cost' => 5]],
            ['username' => 'empty'],
        );

        $this->startRun($run);
        $this->executeAllSources($run);
        $this->finishPipeline($run);

        $run->refresh();
        $sourceRun = $run->sourceRuns()->firstOrFail();

        $this->assertSame(InvestigationRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(0, $sourceRun->result_count);
        $this->assertSame(1, $sourceRun->attempts);
        // A consulta foi feita: cobra, mesmo sem nenhum resultado.
        $this->assertSame(5, $run->credits_consumed);
        $this->assertSame(95, $wallet->fresh()->balance);

        $this->assertLedgerConsistent($wallet, 'consulta sem resultado');
    }

    public function test_module_not_applicable_has_no_reservation_and_is_not_charged(): void
    {
        [, $wallet, , $run] = $this->pendingInvestigation(
            [['slug' => 'mock-username', 'type' => 'username', 'cost' => 5], ['slug' => 'mock-email', 'type' => 'email', 'cost' => 5]],
            ['username' => 'joao'],
        );

        $this->startRun($run);

        // Só o módulo aplicável gera reserva; o outro fica `skipped`.
        $this->assertSame(1, CreditReservation::query()->where('investigation_run_id', $run->id)->count());
        $this->assertSame(95, $wallet->fresh()->balance);
        $this->assertSame('skipped', $run->sourceRuns()->where('source', 'mock-email')->first()?->status);

        $this->executeAllSources($run);
        $this->finishPipeline($run);

        $run->refresh();

        $this->assertSame(5, $run->credits_consumed);
        $this->assertSame(95, $wallet->fresh()->balance);

        $this->assertLedgerConsistent($wallet, 'módulo não aplicável');
    }

    public function test_reservation_without_execution_is_released(): void
    {
        [, $wallet, , $run] = $this->pendingInvestigation(
            [['slug' => 'mock-username', 'type' => 'username', 'cost' => 5], ['slug' => 'mock-email', 'type' => 'email', 'cost' => 5]],
            ['username' => 'joao', 'email' => 'a@b.c'],
        );

        $this->startRun($run);

        // Os dois módulos são aplicáveis: duas reservas.
        $this->assertSame(2, CreditReservation::query()->where('investigation_run_id', $run->id)->count());
        $this->assertSame(90, $wallet->fresh()->balance);

        // Apenas o módulo de username é consultado; o de e-mail fica sem execução
        // (job perdido/permanece `running`), cenário que o encerramento cobre.
        $usernameSourceRun = $run->sourceRuns()->where('source', 'mock-username')->firstOrFail();

        (new ExecuteSourceJob($usernameSourceRun->id))->handle(
            app(SourceRegistry::class),
            app(SourceCache::class),
            app(SourceOutcomePersister::class),
            app(LogSanitizer::class),
        );

        $emailSourceRun = $run->sourceRuns()->where('source', 'mock-email')->firstOrFail();
        $emailSourceRun->status = 'running';
        $emailSourceRun->save();

        app(InvestigationOrchestrator::class)->finalize($run->fresh());

        (new ReleaseReservedCreditsJob($run->id))->handle(app(CreditLedgerService::class));

        $run->refresh();

        // Consulta executada é cobrada; a que nunca aconteceu é estornada.
        $this->assertSame(5, $run->credits_consumed);
        $this->assertSame(95, $wallet->fresh()->balance);
        $this->assertSame('consumed', $run->creditReservations()->where('source', 'mock-username')->first()->status);
        $this->assertSame('released', $run->creditReservations()->where('source', 'mock-email')->first()->status);
        $this->assertSame(0, $emailSourceRun->refresh()->attempts);

        $this->assertLedgerConsistent($wallet, 'reserva sem execução');
    }

    public function test_failed_run_still_charges_the_queries_that_happened(): void
    {
        [, $wallet, , $run] = $this->pendingInvestigation(
            [['slug' => 'mock-username', 'type' => 'username', 'cost' => 5]],
            ['username' => 'error'],
        );

        $this->startRun($run);
        $this->executeAllSources($run);
        $this->finishPipeline($run);

        $run->refresh();

        $this->assertSame(InvestigationRun::STATUS_FAILED, $run->status);
        // A consulta foi executada (a fonte respondeu indisponível): é cobrada.
        $this->assertSame(5, $run->credits_consumed);
        $this->assertSame(95, $wallet->fresh()->balance);
        $this->assertSame('consumed', $run->creditReservations()->first()->status);

        $this->assertLedgerConsistent($wallet, 'run falho com consulta executada');
    }

    public function test_start_job_retry_does_not_duplicate_reservations(): void
    {
        [, $wallet, , $run] = $this->pendingInvestigation(
            [['slug' => 'mock-username', 'type' => 'username', 'cost' => 5], ['slug' => 'mock-email', 'type' => 'email', 'cost' => 5]],
            ['username' => 'joao', 'email' => 'a@b.c'],
        );

        $this->startRun($run);
        // Retry do job de abertura (worker reerguido, fila reentrega).
        $this->startRun($run);

        $this->assertSame(2, CreditReservation::query()->where('investigation_run_id', $run->id)->count());
        $this->assertSame(2, $run->sourceRuns()->count());
        $this->assertSame(90, $wallet->fresh()->balance);
        $this->assertSame(2, CreditTransaction::query()->where('type', 'reserve')->count());

        $this->assertLedgerConsistent($wallet, 'retry do start');
    }

    public function test_worker_retry_reconverges_results_and_charges_once(): void
    {
        [, $wallet, , $run] = $this->pendingInvestigation(
            [['slug' => 'mock-username', 'type' => 'username', 'cost' => 5]],
            ['username' => 'joao'],
        );

        $this->startRun($run);

        $sourceRun = $run->sourceRuns()->firstOrFail();

        (new ExecuteSourceJob($sourceRun->id))->handle(
            app(SourceRegistry::class),
            app(SourceCache::class),
            app(SourceOutcomePersister::class),
            app(LogSanitizer::class),
        );

        $this->assertSame(2, $sourceRun->sourceResults()->count());

        // Worker interrompido: a fonte fica presa em `running` e o job é reentregue.
        $sourceRun->refresh();
        $sourceRun->status = 'running';
        $sourceRun->save();

        (new ExecuteSourceJob($sourceRun->id))->handle(
            app(SourceRegistry::class),
            app(SourceCache::class),
            app(SourceOutcomePersister::class),
            app(LogSanitizer::class),
        );

        $this->assertSame(2, $sourceRun->refresh()->sourceResults()->count());
        $this->assertSame(1, CreditReservation::query()->where('investigation_run_id', $run->id)->count());

        $this->finishPipeline($run);

        $run->refresh();

        // Encerramento repetido não cobra de novo (idempotência por etapa do pipeline).
        (new ReleaseReservedCreditsJob($run->id))->handle(app(CreditLedgerService::class));

        $this->assertSame(InvestigationRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(5, $run->credits_consumed);
        $this->assertSame(95, $wallet->fresh()->balance);
        $this->assertSame(1, CreditTransaction::query()->where('type', 'consume')->count());

        $this->assertLedgerConsistent($wallet, 'retry da fonte');
    }

    public function test_interrupted_run_reservation_is_compensated(): void
    {
        [, $wallet, , $run] = $this->pendingInvestigation(
            [['slug' => 'mock-username', 'type' => 'username', 'cost' => 5]],
            ['username' => 'joao'],
        );

        $this->startRun($run);

        $this->assertSame(95, $wallet->fresh()->balance);

        // Worker morre antes de qualquer liquidação: a reserva vence e é compensada.
        $run->creditReservations()->update(['expires_at' => now()->subMinute()]);

        $released = app(CreditLedgerService::class)->releaseExpiredReservations(now());

        $this->assertSame(1, $released);
        $this->assertSame(100, $wallet->fresh()->balance);
        $this->assertSame('released', $run->creditReservations()->first()->status);

        $this->assertLedgerConsistent($wallet, 'reserva compensada');
    }

    public function test_rerun_creates_independent_run_without_touching_the_previous_one(): void
    {
        [$user, $wallet, $investigation, $run] = $this->pendingInvestigation(
            [['slug' => 'mock-username', 'type' => 'username', 'cost' => 5]],
            ['username' => 'joao'],
        );

        $this->startRun($run);
        $this->executeAllSources($run);
        $this->finishPipeline($run);

        $firstRun = $run->fresh();
        $firstSourceRun = $firstRun->sourceRuns()->firstOrFail();
        $firstResults = $firstSourceRun->sourceResults()->count();

        $this->assertSame(95, $wallet->fresh()->balance);

        // Reexecução controlada pelo serviço (mesma entrada, novo run).
        $secondRun = app(InvestigationService::class)->rerun($user, $investigation->fresh());

        $this->assertSame(2, $secondRun->run_number);
        $this->assertSame(InvestigationRun::STATUS_PENDING, $secondRun->status);
        $this->assertSame($secondRun->id, $investigation->fresh()->current_run_id);

        $this->startRun($secondRun);
        $this->executeAllSources($secondRun);
        $this->finishPipeline($secondRun);

        $secondRun->refresh();

        $this->assertSame(InvestigationRun::STATUS_COMPLETED, $secondRun->status);
        $this->assertSame(5, $secondRun->credits_consumed);
        $this->assertSame(90, $wallet->fresh()->balance);

        // O run anterior permanece intacto e auditável (nunca sobrescrito).
        $this->assertSame(InvestigationRun::STATUS_COMPLETED, $firstRun->fresh()->status);
        $this->assertSame($firstResults, $firstSourceRun->sourceResults()->count());
        $this->assertNotSame($firstSourceRun->id, $secondRun->sourceRuns()->first()->id);

        $this->assertLedgerConsistent($wallet, 'reexecução');
    }

    public function test_rerun_is_rejected_while_a_run_is_in_progress(): void
    {
        [$user, , $investigation, $run] = $this->pendingInvestigation(
            [['slug' => 'mock-username', 'type' => 'username', 'cost' => 5]],
            ['username' => 'joao'],
        );

        $this->startRun($run);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Já existe uma execução em andamento');

        app(InvestigationService::class)->rerun($user, $investigation->fresh());
    }

    public function test_rerun_requires_sufficient_credits(): void
    {
        [$user, $wallet, $investigation, $run] = $this->pendingInvestigation(
            [['slug' => 'mock-username', 'type' => 'username', 'cost' => 5]],
            ['username' => 'joao'],
        );

        $this->startRun($run);
        $this->executeAllSources($run);
        $this->finishPipeline($run);

        // Saldo zerado por ajuste administrativo (movimento auditável no ledger).
        app(CreditLedgerService::class)->adminAdjust($user, $wallet, -95, 'zerar saldo para teste', 'fixture-zero-'.$user->id);

        $this->assertSame(0, $wallet->fresh()->balance);

        $this->expectException(InsufficientCreditsException::class);

        app(InvestigationService::class)->rerun($user, $investigation->fresh());
    }

    public function test_rerun_route_is_authorized_and_creates_a_run(): void
    {
        [$user, , $investigation, $run] = $this->pendingInvestigation(
            [['slug' => 'mock-username', 'type' => 'username', 'cost' => 5]],
            ['username' => 'joao'],
        );

        $this->startRun($run);
        $this->executeAllSources($run);
        $this->finishPipeline($run);

        $this->actingAs($user)
            ->post(route('investigations.rerun', $investigation))
            ->assertRedirect(route('investigations.show', $investigation));

        $this->assertSame(2, $investigation->fresh()->runs()->count());

        // Outro usuário não pode reexecutar.
        $this->actingAs(User::factory()->create())
            ->post(route('investigations.rerun', $investigation))
            ->assertForbidden();
    }
}
