<?php

namespace Tests\Feature;

use App\Models\Investigation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_are_present(): void
    {
        $this->get('/login')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_user_cannot_mass_assign_admin(): void
    {
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'admin-inject@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'is_admin' => 1,
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'admin-inject@example.com',
            'is_admin' => false,
        ]);
    }

    public function test_investigation_title_is_escaped(): void
    {
        $user = User::factory()->create();
        Investigation::factory()->create(['user_id' => $user->id, 'title' => '<script>alert(1)</script>']);

        $response = $this->actingAs($user)->get('/investigations');

        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;', false);
    }

    public function test_admin_search_is_sql_injection_safe(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['name' => 'Victim', 'email' => 'victim@example.com']);

        $response = $this->actingAs($admin)->get('/admin/users?q='.urlencode("' OR '1'='1"));

        $response->assertOk();
        $response->assertDontSee('victim@example.com');
    }
}
