<?php

namespace Tests\Feature;

use App\Models\Investigation;
use App\Models\InvestigationRun;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_dashboard_shows_empty_states(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Saldo de créditos');
        $response->assertSee('Créditos gastos');
        $response->assertSee('Você ainda não tem investigações.');
        $response->assertSee('Nenhum pagamento ainda.');
    }

    public function test_dashboard_shows_data(): void
    {
        $user = User::factory()->create();
        Wallet::factory()->create(['user_id' => $user->id, 'balance' => 1200]);
        $investigation = Investigation::factory()->create(['user_id' => $user->id, 'title' => 'Pesquisa Joao']);
        InvestigationRun::factory()->create(['investigation_id' => $investigation->id, 'credits_consumed' => 75]);
        Payment::factory()->create(['user_id' => $user->id, 'amount_cents' => 3000, 'status' => 'paid']);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('1,200');
        $response->assertSee('75');
        $response->assertSee('Pesquisa Joao');
        $response->assertSee('R$ 30,00');
        $response->assertSee('paid');
    }
}
