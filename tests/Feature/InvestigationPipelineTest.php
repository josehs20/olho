<?php

namespace Tests\Feature;

use App\Jobs\BuildCorrelationJob;
use App\Jobs\BuildHtmlReportJob;
use App\Jobs\DeduplicateResultsJob;
use App\Jobs\ExecuteSourceJob;
use App\Jobs\NormalizeSourceResultsJob;
use App\Jobs\ReleaseReservedCreditsJob;
use App\Jobs\StartInvestigationRunJob;
use App\Models\Investigation;
use App\Models\InvestigationRun;
use App\Models\SourceDefinition;
use App\Models\SourceRun;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CreditLedgerService;
use App\Services\ResultDeduplicator;
use App\Sources\SourceRegistry;
use App\Sources\SourceRunStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InvestigationPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function createPendingRun(array $input = ['username' => 'joao'], int $balance = 100, int $cost = 10): array
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => $balance]);
        $source = SourceDefinition::factory()->create([
            'slug' => 'mock-username',
            'type' => 'username',
            'cost_credits' => $cost,
        ]);

        $investigation = Investigation::factory()->create([
            'user_id' => $user->id,
            'status' => 'queued',
            'selected_modules' => ['mock-username'],
        ]);

        $run = InvestigationRun::factory()->create([
            'investigation_id' => $investigation->id,
            'status' => 'pending',
            'input_snapshot' => array_merge($input, ['modules' => ['mock-username']]),
        ]);

        $investigation->current_run_id = $run->id;
        $investigation->save();

        return [$user, $wallet, $investigation, $run, $source];
    }

    public function test_start_job_reserves_credits_and_dispatches_source_jobs(): void
    {
        [, $wallet, $investigation, $run] = $this->createPendingRun();

        (new StartInvestigationRunJob($run->id))->handle(app(CreditLedgerService::class));

        $run->refresh();
        $this->assertSame(InvestigationRun::STATUS_RUNNING, $run->status);
        $this->assertSame(10, $run->credits_reserved);
        $this->assertSame(Investigation::STATUS_RUNNING, $investigation->fresh()->status);
        $this->assertSame(90, $wallet->fresh()->balance);
        $this->assertSame(1, $run->sourceRuns()->count());

        Queue::assertPushed(ExecuteSourceJob::class);
    }

    public function test_start_job_is_idempotent(): void
    {
        [, , , $run] = $this->createPendingRun();

        (new StartInvestigationRunJob($run->id))->handle(app(CreditLedgerService::class));
        (new StartInvestigationRunJob($run->id))->handle(app(CreditLedgerService::class));

        $this->assertSame(1, $run->sourceRuns()->count());
    }

    public function test_start_job_fails_on_insufficient_credits(): void
    {
        [, , $investigation, $run] = $this->createPendingRun(balance: 0);

        (new StartInvestigationRunJob($run->id))->handle(app(CreditLedgerService::class));

        $this->assertSame(InvestigationRun::STATUS_FAILED, $run->fresh()->status);
        $this->assertSame(Investigation::STATUS_FAILED, $investigation->fresh()->status);
        $this->assertSame(0, $run->sourceRuns()->count());
    }

    public function test_execute_source_job_persists_results(): void
    {
        [, , , $run] = $this->createPendingRun();
        (new StartInvestigationRunJob($run->id))->handle(app(CreditLedgerService::class));

        $sourceRun = $run->sourceRuns()->first();

        (new ExecuteSourceJob($sourceRun->id))->handle(app(SourceRegistry::class));

        $sourceRun->refresh();
        $this->assertSame(SourceRunStatus::Completed->value, $sourceRun->status);
        $this->assertSame(2, $sourceRun->sourceResults()->count());
        $this->assertSame(2, $sourceRun->sourceResults()->with('evidence')->get()->flatMap->evidence->count());
    }

    public function test_execute_source_job_marks_timeout(): void
    {
        [, , , $run] = $this->createPendingRun(input: ['username' => 'timeout']);
        (new StartInvestigationRunJob($run->id))->handle(app(CreditLedgerService::class));

        $sourceRun = $run->sourceRuns()->first();

        (new ExecuteSourceJob($sourceRun->id))->handle(app(SourceRegistry::class));

        $sourceRun->refresh();
        $this->assertSame(SourceRunStatus::Timeout->value, $sourceRun->status);
        $this->assertSame('timeout', $sourceRun->error_code);
    }

    public function test_source_failure_does_not_cancel_other_sources(): void
    {
        $user = User::factory()->create();
        Wallet::factory()->create(['user_id' => $user->id, 'balance' => 100]);
        $usernameSource = SourceDefinition::factory()->create(['slug' => 'mock-username', 'type' => 'username', 'cost_credits' => 5]);
        $emailSource = SourceDefinition::factory()->create(['slug' => 'mock-email', 'type' => 'email', 'cost_credits' => 5]);

        $investigation = Investigation::factory()->create(['user_id' => $user->id, 'status' => 'queued']);
        $run = InvestigationRun::factory()->create([
            'investigation_id' => $investigation->id,
            'status' => 'pending',
            'input_snapshot' => ['username' => 'error', 'email' => 'a@b.c', 'modules' => ['mock-username', 'mock-email']],
        ]);

        (new StartInvestigationRunJob($run->id))->handle(app(CreditLedgerService::class));

        foreach ($run->sourceRuns()->get() as $sourceRun) {
            (new ExecuteSourceJob($sourceRun->id))->handle(app(SourceRegistry::class));
        }

        $this->assertSame(SourceRunStatus::Failed->value, $run->sourceRuns()->where('source_definition_id', $usernameSource->id)->first()->status);
        $this->assertSame(SourceRunStatus::Completed->value, $run->sourceRuns()->where('source_definition_id', $emailSource->id)->first()->status);
    }

    public function test_full_pipeline_completes_run_and_consumes_credits(): void
    {
        [$user, $wallet, , $run] = $this->createPendingRun();

        (new StartInvestigationRunJob($run->id))->handle(app(CreditLedgerService::class));

        $sourceRun = $run->sourceRuns()->first();
        (new ExecuteSourceJob($sourceRun->id))->handle(app(SourceRegistry::class));

        (new NormalizeSourceResultsJob($run->id))->handle(app(ResultDeduplicator::class));
        (new DeduplicateResultsJob($run->id))->handle(app(ResultDeduplicator::class));
        (new BuildCorrelationJob($run->id))->handle();
        (new BuildHtmlReportJob($run->id))->handle();
        (new ReleaseReservedCreditsJob($run->id))->handle(app(CreditLedgerService::class));

        $run->refresh();
        $this->assertSame(InvestigationRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(10, $run->credits_consumed);
        $this->assertSame(90, $wallet->fresh()->balance);
        $this->assertNotNull($run->report);
        $this->assertTrue($run->pipelineStage('normalized_at'));
        $this->assertTrue($run->pipelineStage('deduplicated_at'));
        $this->assertTrue($run->pipelineStage('correlated_at'));
        $this->assertTrue($run->pipelineStage('reported_at'));
        $this->assertTrue($run->pipelineStage('credits_released_at'));

        $results = SourceRun::find($sourceRun->id)->sourceResults()->get();
        $this->assertCount(2, $results);
        // URLs distintas => não são duplicadas entre si.
        $this->assertNull($results->last()->metadata['duplicate_of'] ?? null);
    }
}
