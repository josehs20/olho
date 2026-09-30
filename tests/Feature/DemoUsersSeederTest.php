<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoUsersSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Senhas determinísticas, independentes do .env do desenvolvedor.
        $this->app->bind(DemoUsersSeeder::class, fn () => new DemoUsersSeeder('password', 'password'));
    }

    public function test_seeds_an_admin_and_a_regular_user_with_verified_email(): void
    {
        $this->seed(DemoUsersSeeder::class);

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $user = User::query()->where('email', 'usuario@example.com')->firstOrFail();

        $this->assertTrue($admin->is_admin);
        $this->assertFalse($user->is_admin);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('password', $admin->password));
        $this->assertTrue(Hash::check('password', $user->password));
    }

    public function test_seeded_users_can_authenticate(): void
    {
        $this->seed(DemoUsersSeeder::class);

        $this->post('/login', ['email' => 'admin@example.com', 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();

        auth()->logout();

        $this->post('/login', ['email' => 'usuario@example.com', 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_seeder_is_idempotent_and_grants_demo_credits_only_once(): void
    {
        $this->seed(DemoUsersSeeder::class);
        $this->seed(DemoUsersSeeder::class);

        $this->assertSame(2, User::query()->count());

        $user = User::query()->where('email', 'usuario@example.com')->firstOrFail();
        $wallet = Wallet::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame(500, $wallet->balance);
        $this->assertSame(
            1,
            CreditTransaction::query()
                ->where('user_id', $user->id)
                ->where('type', 'promotional_credit')
                ->count(),
        );
    }
}
