<?php

namespace Tests\Feature;

use App\Models\Investigation;
use App\Models\User;
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
}
