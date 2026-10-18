<?php

namespace App\Jobs;

use App\Exceptions\InsufficientCreditsException;
use App\Models\Investigation;
use App\Models\InvestigationRun;
use App\Models\SourceDefinition;
use App\Models\Wallet;
use App\Payments\PaymentStatus;
use App\Services\CreditLedgerService;
use App\Services\InvestigationOrchestrator;
use App\Services\LogSanitizer;
use App\Sources\InvestigationInput;
use App\Sources\OsintMode;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class StartInvestigationRunJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 120;

    public function __construct(public int $investigationRunId) {}

    public function uniqueId(): string
    {
        return 'start-run-'.$this->investigationRunId;
    }

    public function handle(
        CreditLedgerService $credits,
        InvestigationOrchestrator $orchestrator,
        LogSanitizer $sanitizer,
    ): void {
        $run = InvestigationRun::findOrFail($this->investigationRunId);

        // Idempotência: apenas um run pendente inicia a execução.
        if ($run->status !== InvestigationRun::STATUS_PENDING) {
            return;
        }

        $investigation = $run->investigation;
        $isGuest = $investigation->isGuest();

        // (0) Avulsa (prompt 41): "pagou, usou, acabou". O run nasce no rascunho e
        // só é despachado após a confirmação do pagamento; esta guarda é a segunda
        // camada — sem pagamento pago nada é executado (e nada é cobrado).
        if ($isGuest && ! $this->guestIsPaid($investigation)) {
            $this->fail(
                $orchestrator,
                $sanitizer,
                $run,
                'unpaid_guest_investigation',
                'Aguardando confirmação do pagamento.',
            );

            return;
        }

        $user = $investigation->user;
        $moduleSlugs = $run->moduleSlugs() ?: ($investigation->selected_modules ?? []);
        $input = InvestigationInput::fromArray($run->input_snapshot ?? []);

        $definitions = SourceDefinition::query()
            ->whereIn('slug', $moduleSlugs)
            ->where('active', true)
            ->executable()
            ->get();

        // Validação de ambiente imediatamente antes da execução (defesa em
        // profundidade para runs enfileirados antes da troca de APP_ENV): com
        // integração real, módulos simulados não têm adapter e não executam.
        if ($definitions->isEmpty()) {
            $this->fail(
                $orchestrator,
                $sanitizer,
                $run,
                'no_executable_modules',
                'Nenhum módulo executável disponível neste ambiente.',
            );

            return;
        }

        // (3) Determina os módulos aplicáveis ao tipo de identificador; os
        // demais serão registrados como `skipped` (limitação observável).
        $selection = $orchestrator->split($definitions, $input);
        $totalCost = (int) $selection['applicable']->sum('cost_credits');

        if ($selection['applicable']->isEmpty()) {
            $this->fail(
                $orchestrator,
                $sanitizer,
                $run,
                'no_applicable_modules',
                'Nenhum módulo aplicável aos dados informados.',
            );

            return;
        }

        // (1) Valida/reserva créditos de forma atômica antes de qualquer execução.
        //     Reserva por módulo: o encerramento consome só o que foi concluído.
        //     A avulsa é pré-paga em dinheiro: nenhum crédito é reservado/consumido.
        if (! $isGuest && $totalCost > 0) {
            $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

            try {
                $orchestrator->reserveCredits(
                    $credits,
                    $user,
                    $wallet,
                    $run,
                    $selection['applicable'],
                    now()->addHour(),
                );
            } catch (InsufficientCreditsException) {
                $this->fail(
                    $orchestrator,
                    $sanitizer,
                    $run,
                    'insufficient_credits',
                    'Saldo insuficiente para executar a investigação.',
                );

                return;
            }
        }

        // (2) Abre a execução.
        //     Snapshot do custo por módulo (prompt 43): gravado nos metadados do
        //     run para auditoria e para o custo interno da avulsa (sem wallet).
        //     Na conta, a reserva abaixo já preserva o valor aplicado em
        //     `credit_reservations.amount` — alterar o custo depois nunca
        //     recalcula runs antigos.
        $metadata = $run->metadata ?? [];
        $metadata['credit_cost'] = [
            'total' => $totalCost,
            'breakdown' => $selection['applicable']
                ->mapWithKeys(fn (SourceDefinition $definition) => [$definition->slug => (int) $definition->cost_credits])
                ->all(),
            'snapshot_at' => now()->toISOString(),
        ];
        // Modo de execução (mock vs. integração real) congelado no run: a UI e o
        // relatório exibem o que valia **nesta** execução, mesmo que o operador
        // mude `APP_ENV`/`OSINT_MODE` depois. A escolha real/mock é apenas do
        // ambiente (AppServiceProvider), nunca da entrada do usuário.
        $metadata['execution_mode'] = [
            'mode' => OsintMode::mode(),
            'label' => OsintMode::label(),
            'environment' => app()->environment(),
            'snapshot_at' => now()->toISOString(),
        ];
        $run->metadata = $metadata;

        $run->status = InvestigationRun::STATUS_RUNNING;
        $run->started_at = now();
        $run->credits_reserved = $isGuest ? 0 : $totalCost;
        $run->save();

        $investigation->status = Investigation::STATUS_RUNNING;
        $investigation->save();

        // (4) Cria todos os registros de execução por módulo antes de despachar.
        // Sem isso, com fila síncrona (ou múltiplos workers concorrentes) o
        // primeiro ExecuteSourceJob pode concluir e disparar o restante do
        // pipeline antes de os demais source_runs existirem — finalizando o run
        // prematuramente.
        $sourceRuns = $orchestrator->createSourceRuns($run, $selection['applicable'], $selection['skipped']);
        $run->markPipelineStage('sources_created_at');

        // (5) Envia os jobs para a fila.
        foreach ($sourceRuns as $sourceRun) {
            ExecuteSourceJob::dispatch($sourceRun->id);
        }

        Log::channel('investigations')->info('run.started', $sanitizer->sanitize([
            'investigation_run_id' => $run->id,
            'funding' => $isGuest ? 'guest_prepaid' : 'credits',
            'sources' => $sourceRuns->count(),
            'skipped_sources' => $selection['skipped']->count(),
            'credits_reserved' => $run->credits_reserved,
        ]));
    }

    /**
     * A avulsa só executa com o pagamento confirmado no backend.
     */
    private function guestIsPaid(Investigation $investigation): bool
    {
        return $investigation->guestPayment()
            ->where('status', PaymentStatus::Paid->value)
            ->exists();
    }

    private function fail(
        InvestigationOrchestrator $orchestrator,
        LogSanitizer $sanitizer,
        InvestigationRun $run,
        string $reason,
        string $summary,
    ): void {
        $orchestrator->markFailed($run, $reason, $summary);

        Log::channel('investigations')->warning('run.failed', $sanitizer->sanitize([
            'investigation_run_id' => $run->id,
            'reason' => $reason,
        ]));
    }
}
