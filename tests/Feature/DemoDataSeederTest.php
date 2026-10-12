<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CreditPackage;
use App\Models\CreditReservation;
use App\Models\CreditTransaction;
use App\Models\Evidence;
use App\Models\Investigation;
use App\Models\PaymentEvent;
use App\Models\SourceDefinition;
use App\Models\SourceResult;
use App\Models\User;
use App\Payments\PaymentStatus;
use App\Sources\SourceRegistry;
use App\Sources\SourceRunStatus;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Senhas determinísticas, independentes do .env do desenvolvedor.
        $this->app->bind(DemoUsersSeeder::class, fn () => new DemoUsersSeeder('password', 'password'));
    }

    public function test_it_populates_a_coherent_demo_dataset(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'usuario@example.com')->firstOrFail();

        $this->assertSame(2, User::query()->count());
        $this->assertSame(count(app(SourceRegistry::class)->all()), SourceDefinition::query()->count());
        $this->assertSame(3, CreditPackage::query()->where('kind', CreditPackage::KIND_CREDITS)->count());
        $this->assertSame(1, CreditPackage::query()->where('kind', CreditPackage::KIND_GUEST_INVESTIGATION)->count());

        // O pipeline real (mocks) processou as investigações de demonstração.
        $this->assertSame(4, $user->investigations()->count());
        $this->assertSame(3, $user->investigations()->where('status', Investigation::STATUS_COMPLETED)->count());
        $this->assertSame(1, $user->investigations()->where('status', Investigation::STATUS_PARTIAL)->count());

        // Resultados sempre acompanhados de evidência e correlação persistida.
        $this->assertGreaterThan(0, SourceResult::query()->count());
        $this->assertSame(0, SourceResult::query()->doesntHave('evidence')->count());
        $this->assertGreaterThan(0, Evidence::query()->count());

        $run = Investigation::query()
            ->where('title', 'Presença digital — joao.silva')
            ->firstOrFail()
            ->currentRun;

        $this->assertNotNull($run->metadata['correlation']['score'] ?? null);
        $this->assertDatabaseHas('reports', ['investigation_run_id' => $run->id, 'status' => 'completed']);

        // Falha de uma fonte não derruba a investigação inteira.
        $partialRun = Investigation::query()
            ->where('title', 'Falha isolada de fonte')
            ->firstOrFail()
            ->currentRun;

        $this->assertSame(1, $partialRun->sourceRuns()->where('status', SourceRunStatus::Timeout->value)->count());
        $this->assertGreaterThan(
            0,
            SourceResult::query()->whereHas('sourceRun', fn ($q) => $q->where('investigation_run_id', $partialRun->id))->count(),
        );

        // Saldo consistente com o ledger (nenhuma escrita direta) e reservas liquidadas.
        $wallet = $user->wallet;
        $this->assertSame(
            $wallet->balance,
            (int) CreditTransaction::query()->where('wallet_id', $wallet->id)->sum('amount'),
        );
        $this->assertSame(
            0,
            CreditReservation::query()->where('user_id', $user->id)->where('status', 'pending')->count(),
        );

        // Pagamentos: um confirmado (evento + crédito) e um aguardando PIX.
        $this->assertDatabaseHas('payments', ['user_id' => $user->id, 'status' => PaymentStatus::Paid->value]);
        $this->assertDatabaseHas('payments', ['user_id' => $user->id, 'status' => PaymentStatus::AwaitingPayment->value]);
        $this->assertSame(1, PaymentEvent::query()->count());
        $this->assertDatabaseHas('credit_transactions', ['user_id' => $user->id, 'type' => 'purchase_credit']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.credit_adjustment']);
    }

    public function test_it_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $user = User::query()->where('email', 'usuario@example.com')->firstOrFail();

        $this->assertSame(2, User::query()->count());
        $this->assertSame(count(app(SourceRegistry::class)->all()), SourceDefinition::query()->count());
        $this->assertSame(3, CreditPackage::query()->where('kind', CreditPackage::KIND_CREDITS)->count());
        $this->assertSame(1, CreditPackage::query()->where('kind', CreditPackage::KIND_GUEST_INVESTIGATION)->count());
        $this->assertSame(4, $user->investigations()->count());
        $this->assertSame(2, $user->payments()->count());
        $this->assertSame(1, PaymentEvent::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'admin.credit_adjustment')->count());

        $wallet = $user->wallet;
        $this->assertSame(
            $wallet->balance,
            (int) CreditTransaction::query()->where('wallet_id', $wallet->id)->sum('amount'),
        );
    }
}
