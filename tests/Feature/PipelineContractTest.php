<?php

namespace Tests\Feature;

use App\Models\CreditReservation;
use App\Models\Investigation;
use App\Models\InvestigationRun;
use App\Models\SourceDefinition;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CreditLedgerService;
use App\Services\InvestigationService;
use App\Services\SourceCache;
use App\Sources\Exceptions\SourceException;
use App\Sources\Exceptions\SourceNotRegisteredException;
use App\Sources\InvestigationContext;
use App\Sources\InvestigationInput;
use App\Sources\SourceErrorMapper;
use App\Sources\SourceRegistry;
use App\Sources\SourceResult;
use App\Sources\SourceRunStatus;
use Database\Seeders\SourceDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RecordingSource;
use Tests\TestCase;

/**
 * Contratos do pipeline: entrada/saída do adapter, registro de fontes e
 * persistência de métricas. Garante que trocar um adapter mock por uma
 * ferramenta real não exige mudanças no restante do domínio.
 */
class PipelineContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync', 'cache.default' => 'array']);
    }

    /**
     * @return array{0: User, 1: Wallet}
     */
    private function userWithFundedWallet(int $credits = 50): array
    {
        $user = User::factory()->create();
        $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

        app(CreditLedgerService::class)->promotional(
            $user,
            $wallet,
            $credits,
            'contract-topup-'.$user->id,
        );

        return [$user, $wallet->fresh()];
    }

    public function test_adapter_receives_source_descriptor_without_reading_the_database(): void
    {
        $this->seed(SourceDefinitionSeeder::class);

        SourceDefinition::query()
            ->where('slug', 'maigret')
            ->firstOrFail()
            ->fill(['timeout' => 45, 'config' => ['binary' => '/usr/bin/maigret']])
            ->save();

        $recording = new RecordingSource('maigret');
        app(SourceRegistry::class)->register($recording);

        [$user] = $this->userWithFundedWallet();

        $investigation = app(InvestigationService::class)->create($user, ['username' => 'joao'], ['maigret']);
        $run = $investigation->fresh()->currentRun;

        $this->assertNotNull($recording->context, 'O adapter deveria ter sido executado.');
        $this->assertNotNull($recording->context->source);

        $this->assertSame('maigret', $recording->context->source->slug);
        $this->assertSame('username', $recording->context->source->type);
        $this->assertSame(45, $recording->context->source->timeout);
        $this->assertSame('/usr/bin/maigret', $recording->context->source->config('binary'));

        $this->assertSame($run->id, $recording->context->investigationRunId);
        $this->assertSame('run:'.$run->id.':source:maigret', $recording->context->correlationId);

        // O snapshot de entrada expõe os módulos por contrato, não por string solta.
        $this->assertSame(['maigret'], $run->moduleSlugs());
    }

    public function test_job_persists_findings_evidence_and_outcome_metrics(): void
    {
        $this->seed(SourceDefinitionSeeder::class);
        app(SourceRegistry::class)->register(new RecordingSource('maigret'));

        [$user] = $this->userWithFundedWallet();

        $investigation = app(InvestigationService::class)->create($user, ['username' => 'joao'], ['maigret']);
        $run = $investigation->fresh()->currentRun;
        $sourceRun = $run->sourceRuns()->firstOrFail();

        $this->assertSame(SourceRunStatus::Completed->value, $sourceRun->status);
        $this->assertSame(1, $sourceRun->sourceResults()->count());
        $this->assertSame(1, $sourceRun->sourceResults()->firstOrFail()->evidence()->count());

        // Campos de execução por módulo (prompt 31).
        $this->assertSame($run->investigation_id, $sourceRun->investigation_id);
        $this->assertSame('maigret', $sourceRun->source);
        $this->assertSame(1, $sourceRun->attempts);
        $this->assertSame(1, $sourceRun->result_count);
        $this->assertSame('1.2.3', $sourceRun->tool_version);

        // `metrics` permanece para avisos/metadados extras do adapter.
        $this->assertSame(['item ignorado por limite'], $sourceRun->metrics['warnings']);
        $this->assertSame(['tool_version' => '1.2.3'], $sourceRun->metrics['adapter']);
    }

    public function test_source_without_registered_adapter_fails_structurally(): void
    {
        SourceDefinition::factory()->create([
            'slug' => 'maigret-real',
            'name' => 'Maigret (real)',
            'type' => 'username',
            'active' => true,
            'cost_credits' => 5,
        ]);

        [$user, $wallet] = $this->userWithFundedWallet();

        $investigation = app(InvestigationService::class)->create($user, ['username' => 'joao'], ['maigret-real']);

        $investigation->refresh();
        $run = $investigation->currentRun;
        $sourceRun = $run->sourceRuns()->firstOrFail();

        $this->assertSame(SourceRunStatus::Failed->value, $sourceRun->status);
        $this->assertSame('unavailable', $sourceRun->error_code);
        $this->assertNotNull($sourceRun->error_message_public);

        // Nenhuma fonte concluiu: nada foi entregue ao usuário → run `failed`.
        // A consulta, porém, foi executada (a fonte respondeu indisponível) e,
        // pela regra de cobrança vigente, cobra-se pela consulta: a reserva é
        // consumida e nenhuma reserva fica pendente.
        $this->assertSame(InvestigationRun::STATUS_FAILED, $run->status);
        $this->assertSame(Investigation::STATUS_FAILED, $investigation->status);
        $this->assertSame('all_sources_failed', $run->metadata['finalization']['reason']);
        $this->assertSame(1, $sourceRun->attempts);
        $this->assertSame(5, $run->credits_consumed);
        $this->assertSame(45, $wallet->fresh()->balance);

        $this->assertTrue($run->pipelineStage('reported_at'));
        $this->assertTrue($run->pipelineStage('credits_released_at'));
        $this->assertSame(
            0,
            CreditReservation::query()->where('user_id', $user->id)->where('status', 'pending')->count(),
        );
        $this->assertSame(
            1,
            CreditReservation::query()->where('user_id', $user->id)->where('status', 'consumed')->count(),
        );
    }

    public function test_registry_error_is_a_structured_source_error(): void
    {
        $exception = SourceNotRegisteredException::forSlug('inexistente');

        $this->assertInstanceOf(SourceException::class, $exception);
        $this->assertSame('unavailable', SourceErrorMapper::toErrorCode($exception));
        $this->assertSame(SourceRunStatus::Failed, SourceErrorMapper::toRunStatus($exception));
    }

    public function test_cache_preserves_warnings_and_metadata(): void
    {
        $cache = app(SourceCache::class);
        $source = new RecordingSource('maigret');
        $input = new InvestigationInput(username: 'joao');

        $cache->remember($source, $input, fn () => $source->execute(new InvestigationContext($input)));

        // Se o cache não fosse usado, a exceção abaixo derrubaria o teste.
        $cached = $cache->remember($source, $input, function (): never {
            throw new \RuntimeException('O adapter não deveria ser reexecutado.');
        });

        $this->assertSame(['item ignorado por limite'], $cached->warnings);
        $this->assertSame(['tool_version' => '1.2.3'], $cached->metadata);
        $this->assertCount(1, $cached->findings);
        $this->assertInstanceOf(SourceResult::class, $cached->findings[0]);
    }
}
