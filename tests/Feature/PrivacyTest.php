<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\Investigation;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_view_privacy_and_terms(): void
    {
        $this->get('/privacy')->assertOk()->assertSee('Política de Privacidade');
        $this->get('/terms')->assertOk()->assertSee('Termos de Uso');
    }

    public function test_user_can_view_privacy_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/account/privacy')->assertOk()->assertSee('Exportar meus dados');
    }

    public function test_user_can_export_data(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/account/export');

        $response->assertOk();
        $response->assertHeader('content-disposition');
    }

    public function test_account_deletion_anonymizes_and_removes_investigations(): void
    {
        $user = User::factory()->create();
        Investigation::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->delete('/profile', ['password' => 'password']);

        $this->assertSame('Conta excluída', $user->fresh()->name);
        $this->assertSame(0, Investigation::count());
    }

    public function test_account_deletion_preserves_financial_records(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id, 'balance' => 50]);
        CreditTransaction::factory()->create([
            'wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'amount' => 50,
            'balance_before' => 0,
            'balance_after' => 50,
        ]);
        Payment::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->delete('/profile', ['password' => 'password']);

        $this->assertSame('Conta excluída', $user->fresh()->name);
        $this->assertSame(1, Wallet::where('user_id', $user->id)->count());
        $this->assertSame(1, CreditTransaction::where('user_id', $user->id)->count());
        $this->assertSame(1, Payment::where('user_id', $user->id)->count());
    }
}
