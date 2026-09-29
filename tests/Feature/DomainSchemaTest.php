<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CreditPackage;
use App\Models\CreditTransaction;
use App\Models\Evidence;
use App\Models\Investigation;
use App\Models\InvestigationRun;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Report;
use App\Models\SourceDefinition;
use App\Models\SourceResult;
use App\Models\SourceRun;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_and_ledger_relationships(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 500]);

        $this->assertTrue($wallet->user->is($user));

        $transaction = CreditTransaction::factory()->create([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'amount' => 500,
            'balance_before' => 0,
            'balance_after' => 500,
        ]);

        $this->assertTrue($transaction->wallet->is($wallet));
        $this->assertTrue($transaction->user->is($user));
    }

    public function test_investigation_pipeline_relationships(): void
    {
        $user = User::factory()->create();
        $investigation = Investigation::factory()->create(['user_id' => $user->id]);
        $run = InvestigationRun::factory()->create([
            'investigation_id' => $investigation->id,
            'input_snapshot' => ['username' => 'joaosilva'],
        ]);
        $source = SourceDefinition::factory()->create(['slug' => 'maigret-mock']);
        $sourceRun = SourceRun::factory()->create([
            'investigation_run_id' => $run->id,
            'source_definition_id' => $source->id,
        ]);
        $result = SourceResult::factory()->create([
            'source_run_id' => $sourceRun->id,
            'source_definition_id' => $source->id,
            'value' => 'joaosilva',
        ]);
        $evidence = Evidence::factory()->create([
            'source_result_id' => $result->id,
            'type' => 'username_match',
            'value' => 'joaosilva',
        ]);
        $report = Report::factory()->create([
            'investigation_run_id' => $run->id,
            'user_id' => $user->id,
        ]);

        $this->assertTrue($run->investigation->is($investigation));
        $this->assertTrue($sourceRun->investigationRun->is($run));
        $this->assertTrue($sourceRun->sourceDefinition->is($source));
        $this->assertTrue($result->sourceRun->is($sourceRun));
        $this->assertTrue($evidence->sourceResult->is($result));
        $this->assertTrue($report->investigationRun->is($run));
        $this->assertCount(1, $result->evidence);
    }

    public function test_payment_and_audit_relationships(): void
    {
        $user = User::factory()->create();
        $package = CreditPackage::factory()->create(['credits' => 100, 'price_cents' => 10000]);
        $payment = Payment::factory()->create([
            'user_id' => $user->id,
            'credit_package_id' => $package->id,
        ]);
        $event = PaymentEvent::factory()->create([
            'payment_id' => $payment->id,
            'external_event_id' => 'evt-123',
        ]);
        $audit = AuditLog::factory()->create([
            'user_id' => $user->id,
            'action' => 'admin.credit_adjustment',
        ]);

        $this->assertTrue($payment->user->is($user));
        $this->assertTrue($payment->creditPackage->is($package));
        $this->assertTrue($event->payment->is($payment));
        $this->assertTrue($audit->user->is($user));
    }
}
