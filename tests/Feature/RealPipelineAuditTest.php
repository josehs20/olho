<?php

namespace Tests\Feature;

use App\Models\CreditReservation;
use App\Models\CreditTransaction;
use App\Models\Evidence;
use App\Models\SourceResult;
use App\Models\SourceRun;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CreditLedgerService;
use App\Services\InvestigationService;
use App\Sources\Adapters\HoleheAdapter;
use App\Sources\Adapters\MaigretAdapter;
use App\Sources\Adapters\SherlockAdapter;
use App\Sources\Adapters\WhatsMyNameAdapter;
use App\Sources\SourceRegistry;
use Database\Seeders\SourceDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Auditoria E2E com as integrações **reais** (prompt 40).
 *
 * Percorre a jornada completa — usuário → créditos → investigação → fila →
 * adapters reais → resultados brutos → normalização → deduplicação → correlação
 * → evidências → relatório — e grava um relatório de auditoria em
 * `storage/app/osint-audit/` com duração por módulo, resultados, falhas,
 * timeouts, tentativas, consumo e resultado final.
 *
 * Requer rede e os binários/dataset instalados; fica atrás de `OSINT_E2E=1`:
 *
 * ```bash
 * docker compose exec -e OSINT_E2E=1 app php artisan test --filter=RealPipelineAuditTest
 * ```
 *
 * Módulos sem adapter real disponível (`healthCheck()` falso) são registrados no
 * relatório como indisponíveis e **não** entram na execução — nada é declarado
 * como real sem ter rodado de fato.
 */
class RealPipelineAuditTest extends TestCase
{
    use RefreshDatabase;

    /** Identificador público institucional (linguagem/projeto, não pessoa). */
    private const TEST_USERNAME = 'python';

    private const TEST_EMAIL = 'python@python.org';

    private const MINIMUM_REAL_MODULES = 2;

    protected function setUp(): void
    {
        parent::setUp();

        if (env('OSINT_E2E') !== '1') {
            $this->markTestSkipped('Auditoria E2E real: execute com OSINT_E2E=1.');
        }

        $this->seed(SourceDefinitionSeeder::class);

        config(['queue.default' => 'sync', 'cache.default' => 'array']);
    }

    public function test_full_journey_with_real_integrations_is_audited(): void
    {
        $startedAt = Carbon::now();

        $available = $this->registerAvailableRealAdapters();

        $this->assertGreaterThanOrEqual(
            self::MINIMUM_REAL_MODULES,
            count($available),
            'Menos de '.self::MINIMUM_REAL_MODULES.' ferramentas reais disponíveis: a auditoria não seria representativa.',
        );

        $user = User::factory()->create();
        $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);
        app(CreditLedgerService::class)->promotional($user, $wallet, 50, 'audit-topup-'.$user->id);
        $walletBefore = (int) $wallet->refresh()->balance;

        $investigation = app(InvestigationService::class)->create(
            $user,
            ['username' => self::TEST_USERNAME, 'email' => self::TEST_EMAIL],
            $available,
            'Auditoria E2E real — '.self::TEST_USERNAME,
        );

        $investigation->refresh();
        $run = $investigation->currentRun;
        $run->load(['sourceRuns.sourceDefinition', 'sourceRuns.sourceResults.evidence', 'report']);

        // ---- Jornada completa -------------------------------------------------
        $this->assertContains(
            $investigation->status,
            ['completed', 'partial'],
            'A execução real não chegou ao fim com entrega de resultado.',
        );
        $this->assertNotNull($run->report, 'Relatório não foi gerado.');
        $this->assertNotNull($run->report->content_hash);
        $this->assertSame('report-manifest-v1', data_get($run->report->metadata, 'version'));

        $correlation = $run->metadata['correlation'] ?? null;
        $this->assertIsArray($correlation, 'Correlação não foi persistida no run.');
        $this->assertSame('internal_correlation_indicator', $correlation['score_kind'] ?? null);
        $this->assertFalse((bool) ($correlation['identity_asserted'] ?? true));

        // Cada módulo selecionado foi de fato consultado (nada declarado sem execução).
        $this->assertSame(count($available), $run->sourceRuns->count());

        foreach ($run->sourceRuns as $sourceRun) {
            $this->assertGreaterThan(0, (int) $sourceRun->attempts, "O módulo {$sourceRun->source} não foi executado.");
            $this->assertContains($sourceRun->status, ['completed', 'failed', 'timeout']);
            $this->assertNotNull($sourceRun->duration_ms, "O módulo {$sourceRun->source} não registrou duração.");
        }

        // Resultados normalizados, deduplicados e com evidência.
        $results = SourceResult::query()->whereIn('source_run_id', $run->sourceRuns->pluck('id'))->with('evidence')->get();

        $representatives = $results->filter(fn (SourceResult $result) => empty($result->metadata['duplicate_of']));
        $duplicates = $results->reject(fn (SourceResult $result) => empty($result->metadata['duplicate_of']));

        foreach ($results as $result) {
            $this->assertNotNull($result->dedup_hash, 'Resultado sem chave de deduplicação v2.');
        }

        if ($results->isNotEmpty()) {
            $this->assertGreaterThan(0, $representatives->count(), 'A deduplicação não preservou nenhum representante.');
            $this->assertGreaterThan(0, Evidence::query()->count(), 'Resultados sem evidência persistida.');
        }

        // Duplicata consolidada aponta para o representante existente do run.
        foreach ($duplicates as $duplicate) {
            $this->assertTrue(
                $representatives->contains(
                    fn (SourceResult $representative) => (string) $representative->id === (string) $duplicate->metadata['duplicate_of'],
                ),
                'Duplicata aponta para um representante inexistente no run.',
            );
            $this->assertSame((string) $duplicate->dedup_hash, (string) $duplicate->metadata['duplicate_of_hash']);
        }

        // ---- Consumo (regra pós-39: cobra a consulta executada) ---------------
        $expectedReserved = (int) $run->sourceRuns->sum(fn (SourceRun $sourceRun) => (int) $sourceRun->sourceDefinition->cost_credits);
        $expectedConsumed = (int) $run->sourceRuns
            ->filter(fn (SourceRun $sourceRun) => (int) $sourceRun->attempts > 0)
            ->sum(fn (SourceRun $sourceRun) => (int) $sourceRun->sourceDefinition->cost_credits);

        $this->assertSame($expectedReserved, (int) $run->credits_reserved);
        $this->assertSame($expectedConsumed, (int) $run->credits_consumed);
        $this->assertSame(0, CreditReservation::query()->where('investigation_run_id', $run->id)->where('status', 'pending')->count());

        $wallet = $wallet->refresh();
        $this->assertSame($walletBefore - $expectedConsumed, (int) $wallet->balance);
        $this->assertSame(
            (int) $wallet->balance,
            (int) CreditTransaction::query()->where('wallet_id', $wallet->id)->sum('amount'),
            'Invariante saldo == soma do ledger violada.',
        );

        $this->writeAuditReport([
            'generated_at' => now()->toISOString(),
            'duration_ms_total' => $startedAt->diffInMilliseconds(now()),
            'input' => ['username' => self::TEST_USERNAME, 'email' => self::TEST_EMAIL],
            'environment' => [
                'php' => PHP_VERSION,
                // O driver configurado pode estar em `mock` (phpunit.xml) — o teste
                // força os adapters reais via SourceRegistry::register() e valida
                // cada um por healthCheck(); as versões por módulo comprovam o
                // binário/dataset que de fato executou.
                'configured_drivers' => [
                    'maigret' => config('sources.maigret.driver'),
                    'sherlock' => config('sources.sherlock.driver'),
                    'whatsmyname' => config('sources.whatsmyname.driver'),
                    'holehe' => config('sources.holehe.driver'),
                ],
                'adapters_forced_real' => true,
                'adapters_selected' => $available,
            ],
            'run' => [
                'id' => $run->id,
                'status' => $run->status,
                'reason' => data_get($run->metadata, 'finalization.reason'),
                'started_at' => $run->started_at?->toISOString(),
                'finished_at' => $run->finished_at?->toISOString(),
                'duration_ms' => $run->started_at && $run->finished_at
                    ? $run->started_at->diffInMilliseconds($run->finished_at)
                    : null,
            ],
            'modules' => $run->sourceRuns->map(fn (SourceRun $sourceRun) => [
                'source' => $sourceRun->source,
                'name' => $sourceRun->sourceDefinition?->name,
                'status' => $sourceRun->status,
                'attempts' => (int) $sourceRun->attempts,
                'duration_ms' => $sourceRun->duration_ms,
                'results' => (int) $sourceRun->result_count,
                'tool_version' => $sourceRun->tool_version,
                'error_code' => $sourceRun->error_code,
                'error_message_public' => $sourceRun->error_message_public,
                'cost_credits' => (int) $sourceRun->sourceDefinition->cost_credits,
            ])->values()->all(),
            'consumption' => [
                'credits_reserved' => (int) $run->credits_reserved,
                'credits_consumed' => (int) $run->credits_consumed,
                'wallet_before' => $walletBefore,
                'wallet_after' => (int) $wallet->balance,
                'ledger_sum' => (int) CreditTransaction::query()->where('wallet_id', $wallet->id)->sum('amount'),
            ],
            'pipeline' => [
                'results_total' => $results->count(),
                'discoveries' => $representatives->count(),
                'duplicates' => $duplicates->count(),
                'dedup_groups' => $representatives
                    ->filter(fn (SourceResult $result) => (int) ($result->metadata['dedup_group_size'] ?? 1) > 1)
                    ->count(),
                'evidence_total' => (int) Evidence::query()->count(),
                'report_hash' => $run->report->content_hash,
                'correlation_score' => $correlation['score'] ?? null,
                'correlation_range' => $correlation['range'] ?? null,
                'limitations' => data_get($run->report->metadata, 'limitations', []),
            ],
        ]);
    }

    /**
     * Registra apenas os adapters reais cujo healthCheck confirma disponibilidade.
     *
     * @return array<int, string> slugs efetivamente auditados
     */
    private function registerAvailableRealAdapters(): array
    {
        $registry = app(SourceRegistry::class);
        $available = [];

        $adapters = [
            'maigret' => MaigretAdapter::class,
            'sherlock' => SherlockAdapter::class,
            'whatsmyname' => WhatsMyNameAdapter::class,
            'holehe' => HoleheAdapter::class,
        ];

        foreach ($adapters as $slug => $class) {
            $adapter = app($class);

            if (! $adapter->healthCheck()) {
                continue;
            }

            $registry->register($adapter);
            $available[] = $slug;
        }

        return $available;
    }

    /**
     * @param  array<string, mixed>  $audit
     */
    private function writeAuditReport(array $audit): void
    {
        $directory = storage_path('app/osint-audit');
        File::ensureDirectoryExists($directory);

        $path = $directory.'/e2e-real-'.now()->format('Ymd-His').'.json';

        File::put($path, json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->assertFileExists($path);
    }
}
