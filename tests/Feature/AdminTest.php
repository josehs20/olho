<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_cannot_access_admin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_admin_can_access_dashboard(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('Usuários');
    }

    public function test_admin_can_adjust_credits_with_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($admin)->post("/admin/users/{$user->id}/credits", [
            'amount' => 100,
            'reason' => 'Bônus de teste',
        ]);

        $response->assertRedirect();
        $this->assertSame(100, Wallet::where('user_id', $user->id)->first()->balance);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.credit_adjustment']);
    }

    public function test_credit_adjustment_requires_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)->post("/admin/users/{$user->id}/credits", [
            'amount' => 100,
            'reason' => '',
        ])->assertSessionHasErrors('reason');
    }

    public function test_admin_can_manage_packages(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/admin/packages', [
            'name' => 'Pacote 100',
            'credits' => 100,
            'price_cents' => 5000,
            'active' => 1,
        ]);

        $response->assertRedirect(route('admin.packages.index'));
        $this->assertDatabaseHas('credit_packages', ['name' => 'Pacote 100', 'credits' => 100]);
    }
}
