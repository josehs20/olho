<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CreditReservation;
use App\Models\CreditTransaction;
use App\Models\Investigation;
use App\Models\InvestigationRun;
use App\Models\SourceDefinition;
use App\Models\SourceRun;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CreditLedgerService;
use App\Services\InvestigationService;
use Database\Seeders\CreditPackageSeeder;
use Database\Seeders\SourceDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 43 — custo em créditos por requisição configurável pelo administrador.
 *
 * Comprova: configuração editável apenas por admin com validação server-side,
 * auditoria da alteração (quem/valor anterior/novo), persistência entre seeds,
 * consumo refletindo o custo configurado, execuções antigas com o custo
 * histórico preservado, custo zero sem consumo e avulsa usando a mesma
 * configuração internamente sem movimentar wallet/ledger.
 */
class AdminSourceCostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SourceDefinitionSeeder::class);
        $this->seed(CreditPackageSeeder::class);

        config(['queue.default' => 'sync', 'cache.default' => 'array']);
    }

    /**
     * @return array{0: User, 1: Wallet}
     */
    private function userWithFundedWallet(int $credits): array
    {
        $user = User::factory()->create();
        $wallet = Wallet::query()->firstOrCreate(['user_id' => $user->id], ['balance' => 0]);

        app(CreditLedgerService::class)->promotional(
            $user,
            $wallet,
            $credits,
            'source-cost-topup-'.$user->id,
        );

        return [$user, $wallet->fresh()];
    }

    private function setCost(string $slug, int $cost): void
    {
        SourceDefinition::query()->where('slug', $slug)->update(['cost_credits' => $cost]);
    }

    public function test_admin_updates_credit_cost_with_validation_and_audit(): void
    {
        $admin = User::factory()->admin()->create();
        $maigret = SourceDefinition::query()->where('slug', 'maigret')->firstOrFail();
        $this->assertSame(2, (int) $maigret->cost_credits);

        // A tela de fontes expõe o campo editável.
        $this->actingAs($admin)
            ->get('/admin/sources')
            ->assertOk()
            ->assertSee(__('Créditos por requisição'));

        $this->actingAs($admin)
            ->patch("/admin/sources/{$maigret->id}/cost", ['cost_credits' => 25])
            ->assertRedirect();

        $this->assertSame(25, (int) $maigret->fresh()->cost_credits);

        $audit = AuditLog::query()->where('action', 'admin.source_cost_update')->firstOrFail();
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame($maigret->id, $audit->subject_id);
        $this->assertSame('maigret', $audit->metadata['slug']);
        $this->assertSame(2, (int) $audit->metadata['previous_cost_credits']);
        $this->assertSame(25, (int) $audit->metadata['new_cost_credits']);
    }

    public function test_non_admin_cannot_update_credit_cost(): void
    {
        $user = User::factory()->create();
        $maigret = SourceDefinition::query()->where('slug', 'maigret')->firstOrFail();

        // Sem sessão: o grupo `auth` redireciona para o login.
        $this->patch("/admin/sources/{$maigret->id}/cost", ['cost_credits' => 99])
            ->assertRedirect(route('login'));

        $this->assertSame(2, (int) $maigret->fresh()->cost_credits);

        // Usuário comum logado: recusado pelo middleware `admin`.
        $this->actingAs($user)
            ->patch("/admin/sources/{$maigret->id}/cost", ['cost_credits' => 99])
            ->assertForbidden();

        $this->assertSame(2, (int) $maigret->fresh()->cost_credits);
        $this->assertSame(0, AuditLog::query()->where('action', 'admin.source_cost_update')->count());
    }

    public function test_invalid_credit_cost_values_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $maigret = SourceDefinition::query()->where('slug', 'maigret')->firstOrFail();

        foreach ([-1, 'abc', 1001, ''] as $invalid) {
            $this->actingAs($admin)
                ->patch("/admin/sources/{$maigret->id}/cost", ['cost_credits' => $invalid])
                ->assertSessionHasErrors('cost_credits');

            $this->assertSame(2, (int) $maigret->fresh()->cost_credits, "Valor inválido aceito: {$invalid}");
        }

        $this->assertSame(0, AuditLog::query()->where('action', 'admin.source_cost_update')->count());
    }

    public function test_configured_cost_is_used_on_execution_and_history_keeps_original_cost(): void
    {
        $admin = User::factory()->admin()->create();
        [$user, $wallet] = $this->userWithFundedWallet(100);

        // Execução 1: maigret custa 10.
        $this->setCost('maigret', 10);

        $first = app(InvestigationService::class)->create(
            $user,
            ['username' => 'joao.silva'],
            ['maigret'],
            'Custo 10',
        );
        $firstRun = $first->fresh()->currentRun()->firstOrFail();

        $this->assertSame(10, $firstRun->credits_reserved);
        $this->assertSame(10, $firstRun->credits_consumed);
        $this->assertSame(10, $firstRun->metadata['credit_cost']['total']);
        $this->assertSame(10, (int) $firstRun->metadata['credit_cost']['breakdown']['maigret']);

        // Administrador altera 10 → 25 pelo painel.
        $maigret = SourceDefinition::query()->where('slug', 'maigret')->firstOrFail();
        $this->actingAs($admin)
            ->patch("/admin/sources/{$maigret->id}/cost", ['cost_credits' => 25])
            ->assertRedirect();

        // Execução 2: nova investigação usa o novo custo.
        $second = app(InvestigationService::class)->create(
            $user,
            ['username' => 'maria.souza'],
            ['maigret'],
            'Custo 25',
        );
        $secondRun = $second->fresh()->currentRun()->firstOrFail();

        $this->assertSame(25, $secondRun->credits_reserved);
        $this->assertSame(25, $secondRun->credits_consumed);
        $this->assertSame(25, (int) $secondRun->metadata['credit_cost']['breakdown']['maigret']);

        // A execução antiga permanece com o custo original aplicado.
        $this->assertSame(10, $firstRun->fresh()->credits_consumed);
        $this->assertSame(10, (int) CreditReservation::query()->where('investigation_run_id', $firstRun->id)->sum('amount'));
        $this->assertSame(25, (int) CreditReservation::query()->where('investigation_run_id', $secondRun->id)->sum('amount'));

        // Ledger consistente: saldo final = soma do ledger.
        $this->assertSame(100 - 10 - 25, $wallet->fresh()->balance);
        $this->assertSame(
            $wallet->fresh()->balance,
            (int) CreditTransaction::query()->where('wallet_id', $wallet->id)->sum('amount'),
        );
        $this->assertSame(0, CreditReservation::query()->where('status', 'pending')->count());
    }

    public function test_zero_credit_cost_executes_without_consuming_credits(): void
    {
        $user = User::factory()->create();
        // Sem wallet e sem saldo: custo zero não exige crédito algum.
        $this->setCost('maigret', 0);

        $investigation = app(InvestigationService::class)->create(
            $user,
            ['username' => 'joao.silva'],
            ['maigret'],
            'Custo zero',
        );
        $run = $investigation->fresh()->currentRun()->firstOrFail();

        $this->assertContains($run->status, [InvestigationRun::STATUS_COMPLETED, InvestigationRun::STATUS_PARTIAL]);
        $this->assertSame(0, $run->credits_reserved);
        $this->assertSame(0, $run->credits_consumed);
        $this->assertSame(0, $run->metadata['credit_cost']['total']);
        $this->assertSame(0, (int) $run->metadata['credit_cost']['breakdown']['maigret']);
        $this->assertSame(0, CreditReservation::query()->count());
        $this->assertSame(0, CreditTransaction::query()->count());
        $this->assertSame(0, Wallet::query()->count());
    }

    public function test_guest_investigation_uses_configured_cost_internally_without_touching_wallet(): void
    {
        // O visitante não tem carteira; o custo configurado é registrado apenas
        // como controle interno (mesma configuração das contas, prompt 43).
        $this->setCost('maigret', 7);

        $response = $this->post(route('guest.investigations.store'), ['username' => 'joao.silva']);
        $response->assertRedirect();

        $investigation = Investigation::query()->latest('id')->firstOrFail();
        $token = basename((string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));

        $this->post(route('guest.payments.simulate', ['token' => $token]))->assertRedirect();

        $run = $investigation->fresh()->currentRun()->firstOrFail();
        $cost = $run->metadata['credit_cost'] ?? null;

        $this->assertIsArray($cost);
        $this->assertSame(7, (int) ($cost['breakdown']['maigret'] ?? -1));
        $this->assertSame(array_sum($cost['breakdown']), (int) $cost['total']);
        $this->assertGreaterThan(0, (int) $cost['total']);

        // Custo efetivamente executado: mesma regra das contas (módulo consultado).
        $breakdown = $cost['breakdown'];
        $expectedExecuted = (int) SourceRun::query()
            ->where('investigation_run_id', $run->id)
            ->where('attempts', '>', 0)
            ->get()
            ->sum(fn (SourceRun $sourceRun) => (int) ($breakdown[$sourceRun->source] ?? 0));

        $this->assertSame($expectedExecuted, (int) ($cost['executed_credits'] ?? -1));
        $this->assertNotNull($cost['executed_at'] ?? null);

        // Nenhum movimento financeiro de créditos para o visitante.
        $this->assertSame(0, (int) $run->credits_reserved);
        $this->assertSame(0, (int) $run->credits_consumed);
        $this->assertSame(0, CreditReservation::query()->count());
        $this->assertSame(0, CreditTransaction::query()->count());
        $this->assertSame(0, Wallet::query()->count());
    }

    public function test_reseeding_preserves_admin_configured_credit_cost(): void
    {
        $this->setCost('maigret', 42);

        $this->seed(SourceDefinitionSeeder::class);

        $this->assertSame(42, (int) SourceDefinition::query()->where('slug', 'maigret')->value('cost_credits'));
        $this->assertSame(1, (int) SourceDefinition::query()->where('slug', 'mock-username')->value('cost_credits'));
    }
}
