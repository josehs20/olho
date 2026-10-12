<?php

namespace Tests\Feature;

use App\Models\Investigation;
use App\Models\InvestigationRun;
use App\Models\SourceDefinition;
use App\Models\User;
use App\Models\Wallet;
use App\Services\InvestigationService;
use Database\Seeders\CreditPackageSeeder;
use Database\Seeders\SourceDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Atualização dinâmica da tela de resultados (polling) e o modo de execução.
 *
 * O endpoint `/investigations/{investigation}/status` devolve o estado
 * observável (JSON) para o navegador se manter sincronizado sem `F5`, e a página
 * de resultados passa a exibir o modo de execução (Mock/Real) congelado no run.
 */
class InvestigationStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SourceDefinitionSeeder::class);
        config(['queue.default' => 'sync', 'cache.default' => 'array']);
    }

    public function test_show_page_renders_with_results_sections(): void
    {
        $user = User::factory()->create();
        Wallet::factory()->create(['user_id' => $user->id, 'balance' => 100]);

        $investigation = app(InvestigationService::class)->create(
            $user,
            ['name' => 'João Silva', 'username' => 'joao'],
            ['maigret'],
            'Investigação de teste',
        );

        $this->actingAs($user)
            ->get(route('investigations.show', $investigation))
            ->assertOk()
            ->assertSee('Panorama da execução')
            ->assertSee('Modo de execução')
            ->assertSee('Parâmetros de entrada')
            ->assertSee('Resultados por módulo')
            ->assertSee('Evidências e enriquecimento');
    }

    public function test_status_endpoint_returns_progress_json(): void
    {
        $user = User::factory()->create();
        Wallet::factory()->create(['user_id' => $user->id, 'balance' => 100]);

        $investigation = app(InvestigationService::class)->create(
            $user,
            ['username' => 'joao'],
            ['maigret'],
        );

        $this->actingAs($user)
            ->getJson(route('investigations.status', $investigation))
            ->assertOk()
            // Com fila síncrona o pipeline já concluiu: o estado fresco do banco
            // é a fonte da verdade (o modelo em memória ainda traz `queued`).
            ->assertJsonPath('progress.status', $investigation->fresh()->status)
            ->assertJsonStructure([
                'progress' => ['status', 'run', 'sources', 'discoveries_total', 'completed_sources', 'report_available', 'execution_mode'],
            ]);
    }

    public function test_status_endpoint_exposes_execution_mode_snapshot(): void
    {
        $user = User::factory()->create();
        Wallet::factory()->create(['user_id' => $user->id, 'balance' => 100]);

        $investigation = app(InvestigationService::class)->create(
            $user,
            ['username' => 'joao'],
            ['maigret'],
        );

        // O pipeline síncrono gravou o snapshot do modo de execução no run.
        $run = $investigation->fresh()->currentRun;
        $this->assertIsArray($run->metadata['execution_mode'] ?? null);
        $this->assertArrayHasKey('mode', $run->metadata['execution_mode']);

        $this->actingAs($user)
            ->getJson(route('investigations.status', $investigation))
            ->assertOk()
            ->assertJsonPath('progress.execution_mode.mode', $run->metadata['execution_mode']['mode']);
    }

    public function test_user_cannot_poll_another_users_investigation(): void
    {
        $owner = User::factory()->create();
        Wallet::factory()->create(['user_id' => $owner->id, 'balance' => 100]);
        SourceDefinition::factory()->create(['slug' => 'm', 'type' => 'username']);

        $investigation = app(InvestigationService::class)->create($owner, ['username' => 'a'], ['m']);

        $other = User::factory()->create();

        $this->actingAs($other)
            ->getJson(route('investigations.status', $investigation))
            ->assertForbidden();
    }

    public function test_guest_must_not_be_reachable_via_authenticated_status(): void
    {
        // Avulsa pertence ao token: para a conta comum é 404 (não revela existência).
        $admin = User::factory()->admin()->create();
        $investigation = Investigation::factory()->create([
            'user_id' => null,
            'origin' => Investigation::ORIGIN_GUEST,
            'status' => 'draft',
            'access_token_hash' => Investigation::hashAccessToken(str_repeat('a', 64)),
        ]);
        InvestigationRun::factory()->create([
            'investigation_id' => $investigation->id,
            'status' => InvestigationRun::STATUS_PENDING,
            'input_snapshot' => ['username' => 'x'],
        ]);

        $regular = User::factory()->create();

        $this->actingAs($regular)
            ->getJson(route('investigations.status', $investigation))
            ->assertNotFound();

        // O admin segue podendo inspecionar.
        $this->actingAs($admin)
            ->getJson(route('investigations.status', $investigation))
            ->assertOk();
    }

    /**
     * Unificação das rotas de disparo (Task 2): `/investigacao` (avulsa) e
     * `/investigations` (conta) convergem no **mesmo** serviço de processamento
     * (`InvestigationService`) e no mesmo pipeline, e ambas respeitam o parâmetro
     * central de execução (`OSINT_MODE`). Prova isso pelo snapshot do modo
     * gravado no run e pela execução real dos módulos nas duas jornadas.
     */
    public function test_both_entry_points_share_the_same_processing_and_mode(): void
    {
        config(['osint.mode' => 'mock']);

        // (a) Conta autenticada.
        $user = User::factory()->create();
        Wallet::factory()->create(['user_id' => $user->id, 'balance' => 100]);

        $accountInvestigation = app(InvestigationService::class)->create(
            $user,
            ['username' => 'joao'],
            ['maigret'],
        );
        $accountRun = $accountInvestigation->fresh()->currentRun;

        // (b) Avulsa (mesmo serviço por baixo — GuestInvestigationService delega
        //     ao InvestigationService). A execução é liberada pelo pagamento.
        $this->seed(CreditPackageSeeder::class);
        $response = $this->post(route('guest.investigations.store'), ['username' => 'maria']);
        $response->assertRedirect();
        $token = basename((string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));
        $this->post(route('guest.payments.simulate', ['token' => $token]))->assertRedirect();

        $guestInvestigation = Investigation::query()->where('origin', Investigation::ORIGIN_GUEST)->latest('id')->firstOrFail();
        $guestRun = $guestInvestigation->fresh()->currentRun;

        // Mesmo modo de execução congelado nos dois runs (mock neste teste).
        $this->assertSame('mock', $accountRun->metadata['execution_mode']['mode'] ?? null);
        $this->assertSame('mock', $guestRun->metadata['execution_mode']['mode'] ?? null);

        // Mesma estrutura de dados e mesmo pipeline: ambos criaram source_runs e
        // finalizaram o run com um status terminal do domínio.
        $this->assertGreaterThan(0, $accountRun->sourceRuns()->count());
        $this->assertGreaterThan(0, $guestRun->sourceRuns()->count());
        $this->assertContains($accountRun->status, ['completed', 'partial', 'failed']);
        $this->assertContains($guestRun->status, ['completed', 'partial', 'failed']);
    }
}
