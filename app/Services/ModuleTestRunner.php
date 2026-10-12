<?php

namespace App\Services;

use App\Exceptions\InsufficientCreditsException;
use App\Exceptions\InvalidReservationStateException;
use App\Models\CreditReservation;
use App\Models\Investigation;
use App\Models\InvestigationRun;
use App\Models\ModuleTest;
use App\Models\SourceDefinition;
use App\Models\SourceRun;
use App\Models\Wallet;
use App\ModuleTests\ModuleTestStatus;
use App\Sources\Exceptions\SourceChallengeException;
use App\Sources\Exceptions\SourceException;
use App\Sources\Exceptions\SourceSessionException;
use App\Sources\Integrations\InstagramSettings;
use App\Sources\InvestigationContext;
use App\Sources\InvestigationInput;
use App\Sources\Runners\ToolResult;
use App\Sources\Runners\ToolRunRecorder;
use App\Sources\SourceDescriptor;
use App\Sources\SourceErrorMapper;
use App\Sources\SourceOutcome;
use App\Sources\SourceRegistry;
use App\Sources\SourceRunStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Executa **um** módulo isoladamente para o painel admin de testes (prompt 47).
 *
 * Reaproveita exatamente os componentes do fluxo real — adapter do
 * `SourceRegistry`, `SourceCache`, timeout da `source_definitions`,
 * `SourceOutcomePersister` e `CreditLedgerService` — e para **antes** das etapas
 * de normalização em lote, deduplicação, correlação e relatório (§9): o objetivo
 * é responder "este módulo funciona, quanto demora e o que consome", não gerar
 * um relatório.
 *
 * A execução continua rastreável de verdade: cria `investigations` /
 * `investigation_runs` / `source_runs` reais, grava findings em `source_results`
 * (com evidências) e movimenta o ledger via reserva/consumo/estorno — nenhum
 * armazenamento nem regra de crédito paralela.
 */
class ModuleTestRunner
{
    public function __construct(
        private readonly SourceRegistry $registry,
        private readonly SourceCache $cache,
        private readonly SourceOutcomePersister $persister,
        private readonly CreditLedgerService $credits,
        private readonly LogSanitizer $sanitizer,
        private readonly ToolRunRecorder $recorder,
    ) {}

    /**
     * Executa o teste. Idempotente: um teste em estado terminal é ignorado, de
     * modo que reentrega do job não reexecuta a ferramenta nem cobra de novo.
     */
    public function run(int $moduleTestId): void
    {
        $test = ModuleTest::query()->findOrFail($moduleTestId);

        if (! $test->isRunning()) {
            return;
        }

        $definition = $test->sourceDefinition;

        if ($definition === null || ! $definition->active) {
            $this->finalizeTest($test, null, [
                'status' => ModuleTestStatus::Failed,
                'error_code' => 'module_inactive',
                'message' => __('O módulo não está ativo. Ative-o em Fontes antes de testar.'),
            ]);

            return;
        }

        // Saldo observado antes da execução (§7) — exibido mesmo quando o módulo
        // acaba não aplicável ou sem créditos.
        $test->credits_before = $this->balanceOf($test->user_id);
        $test->save();

        $input = InvestigationInput::fromArray($test->input ?? []);
        $jobStartedAt = microtime(true);
        $stages = ['adapter_ms' => null, 'process_ms' => null, 'persist_ms' => null];

        try {
            $adapter = $this->registry->get($definition->slug);
        } catch (Throwable $e) {
            $this->finalizeTest($test, null, [
                'status' => ModuleTestStatus::Failed,
                'error_code' => 'source_unavailable',
                'message' => __('O módulo não está registrado no runtime atual (confira o driver em config/sources.php).'),
                'timings' => $stages,
                'job_started_at' => $jobStartedAt,
            ]);

            $this->log($test, 'error', 'module_test.not_registered', ['detail' => $e->getMessage()]);

            return;
        }

        // Mesma decisão do pipeline (§8): módulo não aplicável à entrada nunca é
        // consultado — registra SKIPPED sem criar execução e sem reservar crédito.
        if (! $adapter->supports($input)) {
            $this->finalizeTest($test, null, [
                'status' => ModuleTestStatus::Skipped,
                'message' => __('O módulo não é aplicável ao dado informado — nenhuma consulta foi feita e nenhum crédito foi consumido.'),
                'timings' => $stages,
                'job_started_at' => $jobStartedAt,
            ]);

            return;
        }

        $sourceRun = $this->ensureExecutionRecords($test, $definition);
        $test->refresh();

        $sourceRun->status = SourceRunStatus::Running->value;
        $sourceRun->attempts = $sourceRun->attempts + 1;
        $sourceRun->started_at ??= now();
        $sourceRun->save();

        $reservation = null;

        try {
            $reservation = $this->reserveCredits($test, $definition);
        } catch (InsufficientCreditsException $e) {
            // Sem saldo: nada é consultado, nada é cobrado.
            $this->settleCredits($test, null, false);

            $sourceRun->status = SourceRunStatus::Skipped->value;
            $sourceRun->error_code = 'insufficient_credits';
            $sourceRun->error_message_public = __('Saldo insuficiente para executar o módulo.');
            $sourceRun->save();

            $this->finalizeTest($test, $sourceRun, [
                'status' => ModuleTestStatus::Failed,
                'error_code' => 'insufficient_credits',
                'message' => __('Saldo insuficiente para consultar este módulo (saldo: :balance, custo: :cost).', [
                    'balance' => $this->balanceOf($test->user_id),
                    'cost' => (int) $definition->cost_credits,
                ]),
                'timings' => $stages,
                'job_started_at' => $jobStartedAt,
            ]);

            return;
        }

        $status = ModuleTestStatus::Success;
        $errorCode = null;
        $message = null;
        $outcome = null;
        $tool = null;
        $counts = ['results' => 0, 'evidence' => 0];
        $sourceError = null;

        $adapterStarted = microtime(true);
        $this->recorder->start();

        try {
            $context = new InvestigationContext(
                input: $input,
                investigationRunId: $test->investigation_run_id,
                sourceRunId: $sourceRun->id,
                source: new SourceDescriptor(
                    slug: $definition->slug,
                    type: $definition->type,
                    timeout: (int) $definition->timeout,
                    config: $definition->config ?? [],
                ),
                correlationId: 'module-test:'.$test->id.':source:'.$definition->slug,
            );

            $outcome = $this->cache->remember($adapter, $input, fn () => $adapter->execute($context));

            $stages['adapter_ms'] = $this->elapsed($adapterStarted);

            $persistStarted = microtime(true);
            $counts = $this->persister->persist($sourceRun, $definition, $outcome);
            $this->persister->record($sourceRun, $outcome);
            $stages['persist_ms'] = $this->elapsed($persistStarted);

            // `partial` é a ressalva publicada pelos adapters (ex.: varredura
            // interrompida por tempo limite, mas com resultados já coletados).
            $status = ($outcome->metadata['partial'] ?? false) ? ModuleTestStatus::Partial : ModuleTestStatus::Success;
            $sourceRun->status = SourceRunStatus::Completed->value;
        } catch (SourceException $e) {
            $status = ModuleTestStatus::fromSourceRunStatus(SourceErrorMapper::toRunStatus($e));
            $errorCode = SourceErrorMapper::toErrorCode($e);
            // Audiência do teste individual é a administração: a mensagem
            // curada da própria exceção dá a orientação operacional (ex.: onde
            // atualizar a sessão). A superfície pública (`source_runs`, que
            // alimenta relatórios) mantém o texto neutro abaixo.
            $message = SourceErrorMapper::toOperatorMessage($e);

            $sourceRun->status = SourceErrorMapper::toRunStatus($e)->value;
            $sourceRun->error_code = $errorCode;
            $sourceRun->error_message_public = SourceErrorMapper::toPublicMessage($e);

            // Sessão de serviço ausente/inválida/desafio do Instagram não
            // consulta o alvo: o crédito é estornado (a mesma exceção alimenta o
            // `ReleaseReservedCreditsJob` no fluxo normal).
            $sourceError = $e;

            // Erro estruturado também precisa ficar no `source_runs`: o
            // `save()` de encerramento grava status/erro junto com as métricas
            // do adapter — que, no caso do Instagram, carregam o
            // `session_status` que alimenta o badge do painel (§2.1).
            $this->persister->record($sourceRun, new SourceOutcome(
                warnings: [],
                metadata: $this->sessionAdapterMetadata($outcome),
            ));

            $this->log($test, 'warning', 'module_test.source_failed', [
                'source_run_id' => $sourceRun->id,
                'error_code' => $errorCode,
                'detail' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            // Falha fora do contrato de fontes: registrada para diagnóstico e
            // marcada como FAILED (sem mascarar o erro).
            $status = ModuleTestStatus::Failed;
            $errorCode = 'unexpected_error';
            $message = __('A execução do módulo falhou de forma inesperada.');

            $sourceRun->status = SourceRunStatus::Failed->value;
            $sourceRun->error_code = $errorCode;
            $sourceRun->error_message_public = $message;

            $sourceError = $e;

            $this->persister->record($sourceRun, new SourceOutcome(
                warnings: [],
                metadata: $this->sessionAdapterMetadata($outcome),
            ));

            $this->log($test, 'error', 'module_test.unexpected_error', [
                'source_run_id' => $sourceRun->id,
                'detail' => $e->getMessage(),
            ]);
        } finally {
            // Saída resumida e tempo da ferramenta externa (§4/§13). O adapter
            // pode executar mais de um processo; registra-se o último.
            $tool = $this->recorder->last();
            $this->recorder->stop();
            $stages['process_ms'] = $tool?->durationMs;
            $stages['adapter_ms'] ??= $this->elapsed($adapterStarted);
        }

        // Regra vigente de cobrança: módulo efetivamente consultado é cobrado —
        // com ou sem resultado, inclusive quando a consulta falhou ou expirou.
        // Sessão de serviço ausente/inválida/desafio (`SourceSessionException`,
        // `SourceChallengeException`) não consulta o alvo: o crédito é estornado.
        $this->settleCredits($test, $reservation, $this->wasChargedFor($sourceRun, $sourceError));

        $this->traceSessionState($test);

        $this->finalizeTest($test, $sourceRun, [
            'status' => $status,
            'error_code' => $errorCode,
            'message' => $message,
            'exit_code' => $this->exitCode($outcome, $tool),
            'stdout' => $tool?->stdout,
            'stderr' => $tool?->stderr,
            'results' => $counts['results'],
            'evidence' => $counts['evidence'],
            'warnings' => $outcome?->warnings ?? [],
            'adapter' => $outcome?->metadata ?? [],
            'timings' => $stages,
            'job_started_at' => $jobStartedAt,
        ]);
    }

    /**
     * Encerra um teste cuja execução foi interrompida pelo worker (timeout do
     * job, worker derrubado, `kill`). Sem isso o teste ficaria `running` para
     * sempre e a reserva pendente só seria estornada pelo comando de expiração.
     */
    public function markInterrupted(int $moduleTestId, Throwable $e): void
    {
        $test = ModuleTest::query()->find($moduleTestId);

        if ($test === null || ! $test->isRunning()) {
            return;
        }

        $sourceRun = $test->sourceRun;

        if ($sourceRun !== null) {
            $sourceRun->status = SourceRunStatus::Failed->value;
            $sourceRun->error_code = 'job_interrupted';
            $sourceRun->error_message_public = __('A execução foi interrompida pelo worker.');
            $sourceRun->finished_at ??= now();
            $sourceRun->save();
        }

        // Mesma regra de cobrança do fluxo normal: a consulta iniciada
        // (`attempts > 0`) é cobrada; o que nunca chegou ao módulo é estornado.
        $consulted = (int) ($sourceRun->attempts ?? 0) > 0;

        $this->settleCredits($test, $test->creditReservation, $consulted);

        $this->log($test, 'error', 'module_test.interrupted', ['detail' => $e->getMessage()]);

        $this->finalizeTest($test, $sourceRun, [
            'status' => $consulted ? ModuleTestStatus::Failed : ModuleTestStatus::Skipped,
            'error_code' => 'job_interrupted',
            'message' => __('A execução foi interrompida pelo worker (timeout da fila).'),
        ]);
    }

    /**
     * Garante a execução real correspondente ao teste: investigação, run e fonte.
     *
     * Reaproveitar os registros já existentes mantém o retry do job idempotente;
     * a tentativa anterior tem os resultados parciais descartados, como faz o
     * `ExecuteSourceJob`.
     */
    private function ensureExecutionRecords(ModuleTest $test, SourceDefinition $definition): SourceRun
    {
        return DB::transaction(function () use ($test, $definition) {
            if ($test->source_run_id !== null) {
                $sourceRun = SourceRun::query()->whereKey($test->source_run_id)->firstOrFail();
                $sourceRun->sourceResults()->delete();

                return $sourceRun;
            }

            $input = $test->input ?? [];

            $investigation = Investigation::query()->create([
                'user_id' => $test->user_id,
                'origin' => Investigation::ORIGIN_ACCOUNT,
                'title' => __('Teste de módulo: :module — :target', [
                    'module' => $definition->name,
                    'target' => $test->target,
                ]),
                'status' => Investigation::STATUS_QUEUED,
                'selected_modules' => [$definition->slug],
            ]);

            $run = $investigation->runs()->create([
                'run_number' => 1,
                'status' => InvestigationRun::STATUS_RUNNING,
                'started_at' => now(),
                'input_snapshot' => array_merge($input, ['modules' => [$definition->slug]]),
                'metadata' => ['module_test_id' => $test->id],
            ]);

            $investigation->current_run_id = $run->id;
            $investigation->save();

            $sourceRun = $run->sourceRuns()->create([
                'investigation_id' => $investigation->id,
                'source' => $definition->slug,
                'source_definition_id' => $definition->id,
                'status' => SourceRunStatus::Pending->value,
            ]);

            $test->investigation_id = $investigation->id;
            $test->investigation_run_id = $run->id;
            $test->source_run_id = $sourceRun->id;
            $test->started_at ??= now();
            $test->save();

            return $sourceRun;
        });
    }

    /**
     * Reserva o custo do módulo com o mesmo mecanismo do pipeline (§7): chave de
     * idempotência por teste (retry/reentrega não debitam duas vezes), lock no
     * wallet e `source` identificando o módulo da cobrança.
     */
    private function reserveCredits(ModuleTest $test, SourceDefinition $definition): ?CreditReservation
    {
        $cost = (int) $definition->cost_credits;

        if ($cost <= 0) {
            return null;
        }

        $user = $test->user;
        $wallet = Wallet::query()->firstOrCreate(['user_id' => $test->user_id], ['balance' => 0]);

        if ($user === null) {
            throw new InsufficientCreditsException($this->balanceOf($test->user_id), $cost);
        }

        $reservation = $this->credits->reserve(
            $user,
            $wallet,
            $cost,
            $test->investigationRun,
            'module-test-'.$test->id.'-reserve',
            now()->addHour(),
            $definition->slug,
        );

        $test->credit_reservation_id = $reservation->id;
        $test->save();

        return $reservation;
    }

    /**
     * Fecha a cobrança do teste (§7) com a regra vigente do produto: paga-se pela
     * **consulta executada**, não pelo resultado. `$consulted` é verdadeiro
     * quando o módulo chegou a ser executado (`source_runs.attempts > 0`) — a
     * mesma condição usada pelo `ReleaseReservedCreditsJob` no fluxo normal.
     *
     * O painel exibe o resultado desta decisão (custo aplicado + status da
     * reserva), tornando a regra verificável na prática.
     */
    private function settleCredits(ModuleTest $test, ?CreditReservation $reservation, bool $consulted): void
    {
        if ($reservation === null) {
            $test->credits_cost = 0;
            $test->credits_after = $this->balanceOf($test->user_id);
            $test->save();

            return;
        }

        $reservation->refresh();

        if ($reservation->status === 'pending') {
            try {
                if ($consulted) {
                    $this->credits->consume($reservation, 'module-test-'.$test->id.'-consume');
                } else {
                    $this->credits->release($reservation, 'module-test-'.$test->id.'-release');
                }
            } catch (InvalidReservationStateException $e) {
                // Outra execução já encerrou esta reserva: o estado persistido
                // permanece a fonte da verdade e é lido logo abaixo.
                $this->log($test, 'warning', 'module_test.reservation_already_settled', [
                    'detail' => $e->getMessage(),
                ]);
            }
        }

        $reservation->refresh();

        $test->credits_cost = $reservation->status === 'consumed' ? (int) $reservation->amount : 0;
        $test->credits_after = $this->balanceOf($test->user_id);
        $test->save();
    }

    /**
     * Consolida o resultado do teste e o estado da execução real.
     *
     * @param  array<string, mixed>  $result
     */
    private function finalizeTest(ModuleTest $test, ?SourceRun $sourceRun, array $result): void
    {
        /** @var ModuleTestStatus $status */
        $status = $result['status'];
        $finishedAt = now();

        $timings = $this->timings($test, $finishedAt, $result['timings'] ?? [], $result['job_started_at'] ?? null);

        $metadata = $test->metadata ?? [];
        $metadata['timings'] = $timings;

        $warnings = $result['warnings'] ?? [];

        if (is_array($warnings) && $warnings !== []) {
            $metadata['warnings'] = array_slice($warnings, 0, 20);
        }

        $adapter = $result['adapter'] ?? [];

        if (is_array($adapter) && $adapter !== []) {
            $metadata['adapter'] = $adapter;
        }

        $test->status = $status->value;
        $test->finished_at = $finishedAt;
        $test->duration_ms = $timings['total_ms'] ?? $timings['job_ms'] ?? $test->duration_ms;
        $test->result_count = $result['results'] ?? $test->result_count;
        $test->evidence_count = $result['evidence'] ?? $test->evidence_count;
        $test->exit_code = $result['exit_code'] ?? $test->exit_code;
        $test->error_code = $result['error_code'] ?? null;
        $test->error_message_public = $result['message'] ?? null;
        $test->stdout = $this->summarize($result['stdout'] ?? null);
        $test->stderr = $this->summarize($result['stderr'] ?? null);
        $test->credits_after ??= $test->credits_before;
        $test->metadata = $metadata;
        $test->save();

        $this->finalizeExecution($test, $sourceRun, $status, $finishedAt);

        Log::channel('investigations')->info('module_test.finished', [
            'module_test_id' => $test->id,
            'module' => $test->module,
            'status' => $status->value,
            'duration_ms' => $test->duration_ms,
            'result_count' => $test->result_count,
            'exit_code' => $test->exit_code,
            'credits_cost' => $test->credits_cost,
            'error_code' => $test->error_code,
        ]);
    }

    /**
     * Reflete o desfecho na fonte/run da execução real e encerra a investigação
     * auxiliar criada para o teste (rastreabilidade do que foi consultado).
     */
    private function finalizeExecution(ModuleTest $test, ?SourceRun $sourceRun, ModuleTestStatus $status, \DateTimeInterface $finishedAt): void
    {
        if ($sourceRun !== null) {
            $sourceRun->finished_at ??= $finishedAt;
            $sourceRun->duration_ms = $test->durationMs();
            $sourceRun->save();
        }

        $run = $test->investigationRun;
        $investigation = $test->investigation;

        if ($run === null || $investigation === null) {
            return;
        }

        $runStatus = $this->runStatusFor($status);

        $metadata = $run->metadata ?? [];
        data_set($metadata, 'credit_cost.total', (int) $test->credits_cost);
        data_set($metadata, 'credit_cost.breakdown', [(string) $test->module => (int) $test->credits_cost]);
        data_set($metadata, 'credit_cost.executed_credits', (int) $test->credits_cost);

        $run->status = $runStatus;
        $run->finished_at = $finishedAt;
        $run->credits_reserved = (int) ($test->creditReservation?->amount ?? 0);
        $run->credits_consumed = (int) $test->credits_cost;
        $run->error_summary = in_array($status, [ModuleTestStatus::Success, ModuleTestStatus::Partial], true)
            ? null
            : (string) $test->error_message_public;
        $run->metadata = $metadata;
        $run->save();

        $investigation->status = match ($runStatus) {
            InvestigationRun::STATUS_COMPLETED => Investigation::STATUS_COMPLETED,
            InvestigationRun::STATUS_PARTIAL => Investigation::STATUS_PARTIAL,
            default => Investigation::STATUS_FAILED,
        };
        $investigation->save();
    }

    /**
     * Cobra-se pela consulta executada, não pelo resultado. Só a sessão de
     * serviço ausente/inválida/desafio do Instagram não consulta o alvo
     * (`SourceErrorMapper` já grava o `error_code` no `source_runs`, e o
     * `ReleaseReservedCreditsJob` usa o mesmo critério no fluxo normal).
     */
    private function wasChargedFor(SourceRun $sourceRun, ?Throwable $exception): bool
    {
        if ($exception instanceof SourceSessionException || $exception instanceof SourceChallengeException) {
            return false;
        }

        return (int) $sourceRun->attempts > 0;
    }

    /**
     * Metadados do adapter para erro estruturado: só o estado da sessão do
     * Instagram sobrevive a uma execução que não devolveu `SourceOutcome`
     * (sessão expirada/desafio). O `session_status` precisa chegar a
     * `source_runs.metrics.adapter` para o badge do painel (§2.1).
     *
     * @return array<string, mixed>
     */
    private function sessionAdapterMetadata(?SourceOutcome $outcome): array
    {
        $metadata = [];

        $status = strtoupper((string) ($outcome?->metadata['session_status'] ?? ''));

        if ($status !== '') {
            $metadata['session_status'] = $status;
        }

        return $metadata;
    }

    /**
     * Reflete o estado da sessão de serviço observado na execução no painel de
     * integrações (prompt 48 §2.1).
     *
     * Só o adapter do Instagram publica `session_status` em `source_runs.metrics`;
     * quando isso acontece, o badge do painel deixa de depender de alguém ter
     * clicado em "Testar conexão" — uma sessão revogada no meio da madrugada
     * aparece em vermelho na próxima execução do módulo.
     */
    private function traceSessionState(ModuleTest $test): void
    {
        $metrics = $test->sourceRun?->metrics;
        $adapter = is_array($metrics) ? ($metrics['adapter'] ?? null) : null;

        if (! is_array($adapter)) {
            return;
        }

        $status = strtoupper((string) ($adapter['session_status'] ?? ''));

        if ($status === '') {
            return;
        }

        $settings = app(InstagramSettings::class);

        $settings->rememberStatus(
            match ($status) {
                'HEALTHY' => InstagramSettings::STATUS_HEALTHY,
                'CHALLENGE_REQUIRED' => InstagramSettings::STATUS_CHALLENGE_REQUIRED,
                default => InstagramSettings::STATUS_EXPIRED,
            },
            (string) ($test->sourceRun?->error_message_public ?? $settings->lastMessage() ?? ''),
        );
    }

    /**
     * Status do run correspondente ao do teste. `investigation_runs` não possui
     * `skipped`/`timeout`: módulo não aplicável não completou o run e consulta
     * interrompida é uma execução falha.
     */
    private function runStatusFor(ModuleTestStatus $status): string
    {
        return match ($status) {
            ModuleTestStatus::Success => InvestigationRun::STATUS_COMPLETED,
            ModuleTestStatus::Partial => InvestigationRun::STATUS_PARTIAL,
            default => InvestigationRun::STATUS_FAILED,
        };
    }

    /**
     * Tempos por etapa (§13): fila, job, ferramenta, adapter, persistência e
     * total. Etapas não medidas são omitidas; o total é sempre calculado (tempo
     * de vida do teste, do enfileiramento ao encerramento).
     *
     * @param  array<string, int|float|null>  $stages
     * @return array<string, int>
     */
    private function timings(ModuleTest $test, \DateTimeInterface $finishedAt, array $stages, ?float $jobStartedAt): array
    {
        $timings = [];

        if ($test->queued_at !== null && $test->started_at !== null) {
            $timings['queue_ms'] = (int) round(abs($test->queued_at->diffInMilliseconds($test->started_at)));
        }

        foreach ($stages as $stage => $value) {
            if ($value !== null) {
                $timings[$stage] = (int) round((float) $value);
            }
        }

        if ($jobStartedAt !== null) {
            $timings['job_ms'] = (int) round((microtime(true) - $jobStartedAt) * 1000);
        }

        if ($test->queued_at !== null) {
            $timings['total_ms'] = (int) round(abs($test->queued_at->diffInMilliseconds($finishedAt)));
        }

        return $timings;
    }

    /**
     * Exit code da ferramenta: o adapter publica em `metadata.exit_code`; quando
     * não publica, vale a saída bruta do processo observada pelo `ToolRunRecorder`.
     */
    private function exitCode(?SourceOutcome $outcome, ?ToolResult $tool): ?int
    {
        $reported = $outcome?->metadata['exit_code'] ?? null;

        if (is_numeric($reported)) {
            return (int) $reported;
        }

        return $tool?->exitCode;
    }

    /**
     * Saída resumida (§6): sanitizada (sem segredos) e truncada no limite
     * configurado em `config/module_tests.php`.
     */
    private function summarize(?string $output): ?string
    {
        if ($output === null || trim($output) === '') {
            return null;
        }

        $output = $this->sanitizer->sanitize($output);
        $limit = max(200, (int) config('module_tests.output_limit', 4000));

        if (strlen($output) <= $limit) {
            return $output;
        }

        return substr($output, 0, $limit)."\n[... saída truncada]";
    }

    private function balanceOf(?int $userId): int
    {
        if ($userId === null) {
            return 0;
        }

        return (int) (Wallet::query()->where('user_id', $userId)->value('balance') ?? 0);
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(ModuleTest $test, string $level, string $event, array $context = []): void
    {
        Log::channel('investigations')->{$level}($event, $this->sanitizer->sanitize(array_merge([
            'module_test_id' => $test->id,
            'module' => $test->module,
        ], $context)));
    }
}
