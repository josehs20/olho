<?php

namespace Tests\Feature;

use App\Models\Investigation;
use App\Models\User;
use Database\Seeders\CreditPackageSeeder;
use Database\Seeders\SourceDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Segurança da jornada avulsa (prompt 41): o token é a única credencial.
 *
 * Cobre enumeração, isolamento entre tokens, headers de não-cacheamento,
 * ausência de liberação pelo front, indisponibilidade de relatório antes da
 * execução e limite de criação por IP.
 */
class GuestSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SourceDefinitionSeeder::class);
        $this->seed(CreditPackageSeeder::class);

        config(['queue.default' => 'sync', 'cache.default' => 'array']);
    }

    private function guestToken(string $username = 'seguranca.teste'): string
    {
        $response = $this->post(route('guest.investigations.store'), ['username' => $username]);
        $response->assertRedirect();

        return basename((string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));
    }

    public function test_unknown_or_malformed_tokens_are_not_found(): void
    {
        $unknown = str_repeat('a', 64);

        $this->get(route('guest.investigations.show', ['token' => $unknown]))->assertNotFound();
        $this->getJson(route('guest.investigations.status', ['token' => str_repeat('b', 64)]))->assertNotFound();
        $this->get(route('guest.investigations.report', ['token' => str_repeat('c', 64)]))->assertNotFound();

        // Formato inválido é rejeitado antes de qualquer consulta.
        $this->get(route('guest.investigations.show', ['token' => 'x']))->assertNotFound();
        $this->get(route('guest.investigations.show', ['token' => strtoupper($unknown)]))->assertNotFound();
    }

    public function test_token_pages_are_not_cacheable_nor_indexable(): void
    {
        $token = $this->guestToken();

        $response = $this->get(route('guest.investigations.show', ['token' => $token]));

        $response->assertOk();
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_report_is_unavailable_before_the_paid_execution(): void
    {
        $token = $this->guestToken();

        $this->get(route('guest.investigations.show', ['token' => $token]))->assertOk();
        $this->get(route('guest.investigations.report', ['token' => $token]))->assertNotFound();
    }

    public function test_simulation_requires_a_valid_token_and_the_fake_provider(): void
    {
        $token = $this->guestToken();

        $this->post('/investigacao/acesso/'.str_repeat('d', 64).'/pix/confirmar')->assertNotFound();

        // Provider real: não existe rota de simulação.
        config(['payments.provider' => 'mercadopago']);
        $this->post(route('guest.payments.simulate', ['token' => $token]))->assertNotFound();
        config(['payments.provider' => 'fake']);

        $investigation = Investigation::query()->latest('id')->firstOrFail();
        $this->assertSame(Investigation::STATUS_DRAFT, $investigation->status);
    }

    public function test_tokens_are_isolated_between_guest_investigations(): void
    {
        $first = $this->guestToken('primeiro.visitante');
        $second = $this->guestToken('segundo.visitante');

        $this->assertNotSame($first, $second);

        $this->get(route('guest.investigations.show', ['token' => $first]))
            ->assertOk()
            ->assertSee('primeiro.visitante')
            ->assertDontSee('segundo.visitante');

        $this->get(route('guest.investigations.show', ['token' => $second]))
            ->assertOk()
            ->assertSee('segundo.visitante')
            ->assertDontSee('primeiro.visitante');
    }

    public function test_admin_panel_shows_guest_investigations_and_their_price(): void
    {
        $this->guestToken('painel.admin');

        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.investigations.index'))
            ->assertOk()
            ->assertSee('Sem conta')
            ->assertSee('painel.admin');

        $this->actingAs($admin)
            ->get(route('admin.investigations.index', ['origin' => 'guest']))
            ->assertOk()
            ->assertSee('Avulsa');

        $investigation = Investigation::query()->latest('id')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('admin.investigations.show', $investigation))
            ->assertOk()
            ->assertSee('Avulsa (sem conta)');

        $this->actingAs($admin)
            ->get(route('admin.payments.index'))
            ->assertOk()
            ->assertSee('Avulsa');

        $this->actingAs($admin)
            ->get(route('admin.packages.index'))
            ->assertOk()
            ->assertSee('Investigação avulsa');
    }

    public function test_creation_is_rate_limited_per_ip(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('guest.investigations.store'), ['username' => 'rate.limit.'.$attempt])
                ->assertRedirect();
        }

        $this->post(route('guest.investigations.store'), ['username' => 'rate.limit.blocked'])
            ->assertStatus(429);
    }
}
