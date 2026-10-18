<?php

namespace Tests\Feature;

use App\Jobs\StartInvestigationRunJob;
use App\Models\SourceDefinition;
use App\Models\User;
use App\Models\Wallet;
use App\Services\InvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InvestigationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Simula produção: único ambiente que autoriza a integração real.
     * (`phpunit.xml` declara `OSINT_MODE=mock`, então o valor é reafirmado aqui
     * para diferenciar do kill switch.)
     */
    private function production(): void
    {
        $this->app['env'] = 'production';
        config(['osint.mode' => 'real']);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/investigations')->assertRedirect(route('login'));
    }

    public function test_create_screen_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/investigations/create')->assertOk();
    }

    public function test_create_screen_offers_select_all_for_modules(): void
    {
        $user = User::factory()->create();
        SourceDefinition::factory()->create(['slug' => 'username-search', 'name' => 'Busca por username']);

        $this->actingAs($user)->get('/investigations/create')
            ->assertOk()
            ->assertSee('Selecionar todos os módulos')
            ->assertSee('x-ref="selectAll"', false)
            ->assertSee('name="modules[]"', false)
            ->assertSee('value="username-search"', false);
    }

    public function test_production_create_screen_hides_mock_modules(): void
    {
        // APP_ENV=production → integração real: módulos simulados não aparecem.
        $this->production();

        $user = User::factory()->create();
        SourceDefinition::factory()->create(['slug' => 'maigret', 'name' => 'Maigret']);
        SourceDefinition::factory()->create(['slug' => 'mock-username', 'name' => 'Mock Username']);

        $this->actingAs($user)->get('/investigations/create')
            ->assertOk()
            ->assertSee('Maigret')
            ->assertDontSee('Mock Username');
    }

    public function test_non_production_keeps_mock_modules_available(): void
    {
        // Fora de produção o modo efetivo é mock: os módulos simulados seguem
        // disponíveis (desenvolvimento/teste).
        $user = User::factory()->create();
        SourceDefinition::factory()->create(['slug' => 'mock-username', 'name' => 'Mock Username']);

        $this->actingAs($user)->get('/investigations/create')
            ->assertOk()
            ->assertSee('Mock Username');
    }

    public function test_production_rejects_mock_modules_on_store(): void
    {
        Queue::fake();
        $this->production();

        // Fora de `testing` o Laravel valida o CSRF: injeta um token de sessão.
        $this->withSession(['_token' => 'test-token']);

        $user = User::factory()->create();
        Wallet::factory()->create(['user_id' => $user->id, 'balance' => 10]);
        SourceDefinition::factory()->create(['slug' => 'mock-username', 'name' => 'Mock Username']);

        $this->actingAs($user)->post('/investigations', [
            '_token' => 'test-token',
            'username' => 'joaosilva',
            'modules' => ['mock-username'],
        ])->assertSessionHasErrors('modules');

        $this->assertDatabaseCount('investigations', 0);
    }

    public function test_production_guest_investigation_uses_only_real_modules(): void
    {
        $this->production();

        SourceDefinition::factory()->create(['slug' => 'maigret', 'name' => 'Maigret', 'type' => 'username']);
        SourceDefinition::factory()->create(['slug' => 'mock-username', 'name' => 'Mock Username', 'type' => 'username']);

        $investigation = app(InvestigationService::class)
            ->createGuest(['username' => 'joaosilva'], 1000, str_repeat('a', 64));

        $this->assertSame(['maigret'], $investigation->selected_modules);
    }

    public function test_user_can_create_investigation(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        Wallet::factory()->create(['user_id' => $user->id, 'balance' => 10]);
        SourceDefinition::factory()->create(['slug' => 'm', 'type' => 'username']);

        $response = $this->actingAs($user)->post('/investigations', [
            'username' => 'joaosilva',
            'modules' => ['m'],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('investigations', ['user_id' => $user->id, 'status' => 'queued']);
        Queue::assertPushed(StartInvestigationRunJob::class);
    }

    public function test_user_cannot_view_another_users_investigation(): void
    {
        $userA = User::factory()->create();
        Wallet::factory()->create(['user_id' => $userA->id, 'balance' => 10]);
        $userB = User::factory()->create();
        SourceDefinition::factory()->create(['slug' => 'm', 'type' => 'username']);

        $investigation = app(InvestigationService::class)->create($userA, ['username' => 'a'], ['m']);

        $this->actingAs($userB)->get('/investigations/'.$investigation->id)->assertForbidden();
    }
}
