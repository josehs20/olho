<?php

namespace Tests\Feature;

use App\Models\Investigation;
use App\Models\InvestigationRun;
use App\Models\SourceRun;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CreditLedgerService;
use App\Services\InvestigationOrchestrator;
use App\Services\InvestigationService;
use App\Sources\SourceRunStatus;
use Database\Seeders\SourceDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Orquestrador do run: determinação de módulos aplicáveis, criação das
 * execuções por módulo e desfecho (COMPLETED / PARTIAL / FAILED).
 */
class InvestigationOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync', 'cache.default' => 'array']);
    }

    private function orchestrator(): InvestigationOrchestrator
    {
        return app(InvestigationOrchestrator::class);
    }

    private function fundedUser(int $credits = 50): User
    {
        $user = User::factory()->create();
        $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

        app(CreditLedgerService::class)->promotional($user, $wallet, $credits, 'orq-topup-'.$user->id);

        return $user->fresh();
    }

    public function test_modules_not_applicable_to_identifier_are_skipped_not_rejected(): void
    {
        $this->seed(SourceDefinitionSeeder::class);
        $user = $this->fundedUser(50);

        // 'holehe' exige e-mail e só temos username: deve virar `skipped`
        // (limitação observável), sem rejeitar a investigação.
        $investigation = app(InvestigationService::class)->create($user, ['username' => 'joao'], ['maigret', 'holehe']);

        $investigation->refresh();
        $run = $investigation->currentRun;

        $sourceRunOf = fn (string $slug) => $run->sourceRuns()
            ->whereHas('sourceDefinition', fn ($query) => $query->where('slug', $slug))
            ->firstOrFail();

        $this->assertSame(Investigation::STATUS_COMPLETED, $investigation->status);
        $this->assertSame(SourceRunStatus::Completed->value, $sourceRunOf('maigret')->status);

        $skipped = $sourceRunOf('holehe');
        $this->assertSame(SourceRunStatus::Skipped->value, $skipped->status);
        $this->assertSame('invalid_input', $skipped->error_code);
        $this->assertNotNull($skipped->error_message_public);

        // Cobra apenas o módulo que efetivamente executou (maigret = 2 créditos).
        $this->assertSame(2, $run->credits_reserved);
        $this->assertSame(2, $run->credits_consumed);

        $this->assertTrue($run->pipelineStage('sources_created_at'));
        $this->assertSame(1, $run->metadata['finalization']['counts']['skipped']);
        $this->assertSame('all_sources_completed', $run->metadata['finalization']['reason']);
    }

    public function test_finalize_decides_completed_partial_and_failed(): void
    {
        // Todas as fontes concluíram.
        $this->assertFinalization(
            [SourceRunStatus::Completed, SourceRunStatus::Completed],
            InvestigationRun::STATUS_COMPLETED,
            'all_sources_completed',
        );

        // Uma concluiu e outra falhou: o run continua útil (docs/23).
        $this->assertFinalization(
            [SourceRunStatus::Completed, SourceRunStatus::Timeout],
            InvestigationRun::STATUS_PARTIAL,
            'some_sources_failed',
        );

        // Nenhuma concluiu.
        $this->assertFinalization(
            [SourceRunStatus::Failed, SourceRunStatus::Timeout],
            InvestigationRun::STATUS_FAILED,
            'all_sources_failed',
        );

        // Nada executou (todos os módulos não aplicáveis).
        $this->assertFinalization(
            [SourceRunStatus::Skipped],
            InvestigationRun::STATUS_FAILED,
            'no_source_executed',
        );
    }

    /**
     * @param  array<int, SourceRunStatus>  $statuses
     */
    private function assertFinalization(array $statuses, string $expectedStatus, string $expectedReason): void
    {
        $investigation = Investigation::factory()->create(['status' => Investigation::STATUS_RUNNING]);
        $run = InvestigationRun::factory()->for($investigation)->create(['status' => InvestigationRun::STATUS_RUNNING]);

        foreach ($statuses as $status) {
            SourceRun::factory()->for($run, 'investigationRun')->create(['status' => $status->value]);
        }

        $result = $this->orchestrator()->finalize($run);

        $this->assertSame($expectedStatus, $result['status']);
        $this->assertSame($expectedReason, $result['reason']);

        $run->refresh();
        $this->assertSame($expectedStatus, $run->status);
        $this->assertSame($expectedStatus, $investigation->fresh()->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame($expectedStatus, $run->metadata['finalization']['status']);
    }

    public function test_mark_failed_records_reason_and_public_summary(): void
    {
        $run = InvestigationRun::factory()->create(['status' => InvestigationRun::STATUS_PENDING]);

        $finalization = $this->orchestrator()->markFailed($run, 'insufficient_credits', 'Saldo insuficiente.');

        $this->assertSame(InvestigationRun::STATUS_FAILED, $finalization['status']);
        $this->assertSame('insufficient_credits', $finalization['reason']);

        $run->refresh();
        $this->assertSame(InvestigationRun::STATUS_FAILED, $run->status);
        $this->assertSame('insufficient_credits', $run->metadata['finalization']['reason']);
        $this->assertSame('Saldo insuficiente.', $run->error_summary);
        $this->assertSame(InvestigationRun::STATUS_FAILED, $run->investigation->status);
    }

    public function test_http_creation_without_credits_reports_validation_error_and_creates_nothing(): void
    {
        $this->seed(SourceDefinitionSeeder::class);

        $user = User::factory()->create();
        Wallet::factory()->create(['user_id' => $user->id, 'balance' => 0]);

        $response = $this->actingAs($user)->post('/investigations', [
            'username' => 'joao',
            'modules' => ['maigret'],
        ]);

        $response->assertSessionHasErrors('modules');

        $this->assertSame(0, Investigation::query()->count());
        $this->assertSame(0, InvestigationRun::query()->count());
    }
}
