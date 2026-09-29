<?php

namespace App\Jobs;

use App\Exceptions\InsufficientCreditsException;
use App\Models\Investigation;
use App\Models\InvestigationRun;
use App\Models\SourceDefinition;
use App\Models\Wallet;
use App\Services\CreditLedgerService;
use App\Sources\SourceRunStatus;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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

    public function handle(CreditLedgerService $credits): void
    {
        $run = InvestigationRun::findOrFail($this->investigationRunId);

        if ($run->status !== InvestigationRun::STATUS_PENDING) {
            return;
        }

        $investigation = $run->investigation;
        $user = $investigation->user;
        $moduleSlugs = $run->input_snapshot['modules'] ?? $investigation->selected_modules ?? [];

        $sources = SourceDefinition::query()
            ->whereIn('slug', $moduleSlugs)
            ->where('active', true)
            ->get();

        $totalCost = (int) $sources->sum('cost_credits');

        $run->status = InvestigationRun::STATUS_RUNNING;
        $run->started_at = now();
        $run->credits_reserved = $totalCost;
        $run->save();

        $investigation->status = Investigation::STATUS_RUNNING;
        $investigation->save();

        if ($totalCost > 0) {
            try {
                $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

                $credits->reserve($user, $wallet, $totalCost, $run, 'run-reserve-'.$run->id, now()->addHour());
            } catch (InsufficientCreditsException $e) {
                $this->failRun($run, $investigation, 'Saldo insuficiente para executar a investigação.');

                return;
            }
        }

        foreach ($sources as $source) {
            $sourceRun = $run->sourceRuns()->create([
                'source_definition_id' => $source->id,
                'status' => SourceRunStatus::Pending->value,
            ]);

            ExecuteSourceJob::dispatch($sourceRun->id);
        }

        if ($sources->isEmpty()) {
            $this->failRun($run, $investigation, 'Nenhum módulo ativo para executar.');
        }
    }

    private function failRun(InvestigationRun $run, Investigation $investigation, string $message): void
    {
        $run->status = InvestigationRun::STATUS_FAILED;
        $run->finished_at = now();
        $run->error_summary = $message;
        $run->save();

        $investigation->status = Investigation::STATUS_FAILED;
        $investigation->save();
    }
}
