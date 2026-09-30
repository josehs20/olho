<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A verificação de e-mail foi desativada: contas novas têm acesso imediato.
 */
class NoEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_user_can_access_dashboard(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_unverified_user_can_access_protected_areas(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/investigations')->assertOk();
        $this->actingAs($user)->get('/credits')->assertOk();
    }

    public function test_unverified_admin_can_access_admin_area(): void
    {
        $admin = User::factory()->admin()->unverified()->create();

        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    public function test_registration_grants_immediate_access(): void
    {
        $response = $this->post('/register', [
            'name' => 'Nova Conta',
            'email' => 'nova@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));

        $this->get('/dashboard')->assertOk();
    }
}
