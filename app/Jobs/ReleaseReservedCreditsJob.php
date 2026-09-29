<?php

namespace App\Jobs;

use App\Models\InvestigationRun;
use App\Services\CreditLedgerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReleaseReservedCreditsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 120;

    public function __construct(public int $investigationRunId) {}

    public function handle(CreditLedgerService $credits): void
    {
        $run = InvestigationRun::findOrFail($this->investigationRunId);

        if ($run->pipelineStage('credits_released_at')) {
            return;
        }

        $reservations = $run->creditReservations()->where('status', 'pending')->get();

        foreach ($reservations as $reservation) {
            $completed = in_array(
                $run->status,
                [InvestigationRun::STATUS_COMPLETED, InvestigationRun::STATUS_PARTIAL],
                true,
            );

            if ($completed) {
                $credits->consume($reservation, 'run-consume-'.$reservation->id);
                $run->credits_consumed += $reservation->amount;
            } else {
                $credits->release($reservation, 'run-release-'.$reservation->id);
            }
        }

        $run->save();
        $run->markPipelineStage('credits_released_at');
    }
}
