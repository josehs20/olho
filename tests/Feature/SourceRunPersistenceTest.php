<?php

namespace Tests\Feature;

use App\Jobs\ExecuteSourceJob;
use App\Models\InvestigationRun;
use App\Models\SourceRun;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CreditLedgerService;
use App\Services\InvestigationService;
use App\Services\LogSanitizer;
use App\Services\SourceCache;
use App\Services\SourceOutcomePersister;
use App\Sources\SourceRegistry;
use App\Sources\SourceRunStatus;
use Database\Seeders\SourceDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Persistência da execução por módulo (prompt 31): o `source_runs` deve contar
 * exatamente o que ocorreu em cada fonte — inclusive em sucesso, timeout, erro,
 * retry e módulo não aplicável.
 */
class SourceRunPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SourceDefinitionSeeder::class);
        config(['queue.default' => 'sync', 'cache.default' => 'array']);
    }

    /**
     * @param  array<string, string>  $input
     * @param  array<int, string>  $modules
     */
    private function runInvestigation(array $input, array $modules): InvestigationRun
    {
        $user = User::factory()->create();
        $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

        app(CreditLedgerService::class)->promotional($user, $wallet, 50, 'srp-topup-'.$user->id);

        $investigation = app(InvestigationService::class)->create($user, $input, $modules);

        return $investigation->fresh()->currentRun;
    }

    private function sourceRunOf(InvestigationRun $run, string $slug): SourceRun
    {
        return $run->sourceRuns()->where('source', $slug)->firstOrFail();
    }

    public function test_success_persists_full_execution_details(): void
    {
        $run = $this->runInvestigation(['username' => 'joao'], ['maigret']);
        $sourceRun = $this->sourceRunOf($run, 'maigret');

        $this->assertSame($run->investigation_id, $sourceRun->investigation_id);
        $this->assertSame('maigret', $sourceRun->source);
        $this->assertSame(SourceRunStatus::Completed->value, $sourceRun->status);
        $this->assertSame(1, $sourceRun->attempts);
        $this->assertSame(3, $sourceRun->result_count);
        $this->assertSame(3, $sourceRun->sourceResults()->count());

        // O mock não declara versão da ferramenta; o campo é opcional.
        $this->assertNull($sourceRun->tool_version);
        $this->assertNull($sourceRun->error_code);
        $this->assertNull($sourceRun->error_message_public);

        $this->assertNotNull($sourceRun->started_at);
        $this->assertNotNull($sourceRun->finished_at);
        $this->assertIsInt($sourceRun->duration_ms);
        $this->assertGreaterThanOrEqual(0, $sourceRun->duration_ms);
    }

    public function test_timeout_persists_public_error_without_internals(): void
    {
        $run = $this->runInvestigation(['username' => 'timeout'], ['maigret']);
        $sourceRun = $this->sourceRunOf($run, 'maigret');

        $this->assertSame(SourceRunStatus::Timeout->value, $sourceRun->status);
        $this->assertSame('timeout', $sourceRun->error_code);
        $this->assertSame('A fonte excedeu o tempo limite.', $sourceRun->error_message_public);

        // A mensagem crua da ferramenta não é persistida.
        $this->assertStringNotContainsString('maigret timeout', (string) $sourceRun->error_message_public);

        $this->assertSame(0, $sourceRun->result_count);
        $this->assertSame(1, $sourceRun->attempts);
        $this->assertNotNull($sourceRun->finished_at);
    }

    public function test_error_persists_public_error(): void
    {
        $run = $this->runInvestigation(['username' => 'error'], ['maigret']);
        $sourceRun = $this->sourceRunOf($run, 'maigret');

        $this->assertSame(SourceRunStatus::Failed->value, $sourceRun->status);
        $this->assertSame('unavailable', $sourceRun->error_code);
        $this->assertSame('A fonte está indisponível no momento.', $sourceRun->error_message_public);
        $this->assertSame(0, $sourceRun->result_count);
    }

    public function test_retry_increments_attempts_and_keeps_result_count_consistent(): void
    {
        $run = $this->runInvestigation(['username' => 'joao'], ['maigret']);
        $sourceRun = $this->sourceRunOf($run, 'maigret');

        $this->assertSame(1, $sourceRun->attempts);
        $this->assertSame(3, $sourceRun->result_count);

        // Tentativa interrompida + reexecução (retry do job).
        $sourceRun->forceFill(['status' => SourceRunStatus::Running->value])->save();

        (new ExecuteSourceJob($sourceRun->id))->handle(
            app(SourceRegistry::class),
            app(SourceCache::class),
            app(SourceOutcomePersister::class),
            app(LogSanitizer::class),
        );

        $sourceRun->refresh();
        $this->assertSame(SourceRunStatus::Completed->value, $sourceRun->status);
        $this->assertSame(2, $sourceRun->attempts);
        $this->assertSame(3, $sourceRun->result_count);
        $this->assertSame(3, $sourceRun->sourceResults()->count());
    }

    public function test_execution_details_are_exposed_in_report_and_admin_views(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);
        app(CreditLedgerService::class)->promotional($user, $wallet, 50, 'srp-view-'.$user->id);

        $investigation = app(InvestigationService::class)->create(
            $user,
            ['name' => 'Empresa XPTO', 'username' => 'timeout'],
            ['mock-web', 'maigret'],
            'Persistência visível',
        );

        $investigation->refresh();
        $run = $investigation->currentRun;

        // Relatório do cliente: status de cada fonte, contagem de resultados e limitação.
        $this->actingAs($user)
            ->get(route('investigations.report', [$investigation, $run]))
            ->assertOk()
            ->assertSee('FONTES CONSULTADAS')
            ->assertSee('Resultados')
            ->assertSee('A fonte excedeu o tempo limite.');

        // Painel admin: detalhes operacionais da execução por módulo.
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.investigations.show', $investigation))
            ->assertOk()
            ->assertSee('tentativas')
            ->assertSee('duração');
    }

    public function test_skipped_module_persists_zero_attempts_and_reason(): void
    {
        $run = $this->runInvestigation(['username' => 'joao'], ['maigret', 'holehe']);
        $skipped = $this->sourceRunOf($run, 'holehe');

        $this->assertSame(SourceRunStatus::Skipped->value, $skipped->status);
        $this->assertSame('invalid_input', $skipped->error_code);
        $this->assertSame(0, $skipped->attempts);
        $this->assertSame(0, $skipped->result_count);
        $this->assertStringContainsString('não é aplicável', (string) $skipped->error_message_public);
    }
}
