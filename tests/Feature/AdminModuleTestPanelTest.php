<?php

namespace Tests\Feature;

use App\Models\ModuleTest;
use App\Models\SourceDefinition;
use App\Models\SourceResult;
use App\Models\User;
use App\Models\Wallet;
use App\ModuleTests\ModuleTestStatus;
use App\Services\CreditLedgerService;
use Database\Seeders\CreditPackageSeeder;
use Database\Seeders\SourceDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 47 — painel admin de testes individuais de módulo (§15: verificações
 * rápidas de estrutura, autorização, validação e fluxo de execução).
 *
 * A fila roda em `sync` e os adapters em modo `mock` (phpunit.xml), então cada
 * requisição executa o caminho real — adapter, run/source_run, persistência e
 * regras de crédito — sem rede e sem espera. O objetivo é comprovar que o painel
 * usa a implementação existente, não uma paralela.
 */
class AdminModuleTestPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SourceDefinitionSeeder::class);
        $this->seed(CreditPackageSeeder::class);

        config(['queue.default' => 'sync', 'cache.default' => 'array']);
    }

    /**
     * Administrador com saldo disponível (mesmo ledger das contas normais).
     */
    private function adminWithCredits(int $credits = 20): User
    {
        $admin = User::factory()->admin()->create();
        $wallet = Wallet::query()->firstOrCreate(['user_id' => $admin->id], ['balance' => 0]);

        app(CreditLedgerService::class)->promotional($admin, $wallet, $credits, 'module-test-topup-'.$admin->id);

        return $admin;
    }

    public function test_admin_runs_one_module_and_the_panel_records_status_results_credits_and_timings(): void
    {
        $admin = $this->adminWithCredits(20);

        $this->actingAs($admin)
            ->get(route('admin.tests.index'))
            ->assertOk()
            ->assertSee('EXECUTAR TESTE')
            ->assertSee('Maigret');

        $response = $this->actingAs($admin)->post(route('admin.tests.store'), [
            'module' => 'maigret',
            'target' => 'joao.silva',
        ]);

        $test = ModuleTest::query()->latest('id')->firstOrFail();
        $response->assertRedirect(route('admin.tests.show', $test));

        $this->assertSame('maigret', $test->module);
        $this->assertSame('joao.silva', $test->target);
        $this->assertSame(['username' => 'joao.silva'], $test->input);
        $this->assertTrue($test->statusEnum()->isTerminal());
        $this->assertContains($test->statusEnum(), [ModuleTestStatus::Success, ModuleTestStatus::Partial]);
        $this->assertGreaterThan(0, (int) $test->result_count);
        $this->assertGreaterThan(0, (int) $test->evidence_count);

        // Créditos: mesma reserva/consumo do fluxo normal (custo configurado = 2).
        $this->assertSame(20, (int) $test->credits_before);
        $this->assertSame(2, (int) $test->credits_cost);
        $this->assertSame(18, (int) $test->credits_after);
        $this->assertSame('consumed', $test->creditReservation?->status);

        // Tempo: total sempre medido; a execução aconteceu de verdade.
        $this->assertNotNull($test->duration_ms);
        $this->assertArrayHasKey('total_ms', $test->timings());
        $this->assertNotNull($test->investigation_id);
        $this->assertNotNull($test->source_run_id);
        $this->assertSame(1, (int) $test->sourceRun->attempts);

        // Detalhe (log) e acompanhamento renderizam com os dados do teste.
        $this->actingAs($admin)
            ->get(route('admin.tests.show', $test))
            ->assertOk()
            ->assertSee('EXECUTAR NOVAMENTE')
            ->assertSee('joao.silva')
            // §6: o detalhe mostra **o que o módulo encontrou** — os mesmos
            // `source_results` da execução real, com URL e evidência.
            ->assertSee('Resultados encontrados')
            ->assertSee('https://github.com/joao.silva')
            ->assertSee('social_profile')
            ->assertSee('username_match');

        $this->assertSame(
            (int) $test->result_count,
            $test->sourceRun->sourceResults()->count(),
        );

        $this->actingAs($admin)
            ->get(route('admin.tests.status', $test))
            ->assertOk()
            ->assertJsonPath('test.terminal', true)
            ->assertJsonPath('test.status', $test->status);
    }

    public function test_rerun_creates_a_new_independent_test_and_keeps_the_previous_one(): void
    {
        $admin = $this->adminWithCredits(20);

        $this->actingAs($admin)->post(route('admin.tests.store'), [
            'module' => 'sherlock',
            'target' => 'joao.silva',
        ]);

        $first = ModuleTest::query()->latest('id')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.tests.rerun', $first))
            ->assertRedirect();

        $this->assertSame(2, ModuleTest::query()->count());

        $second = ModuleTest::query()->latest('id')->firstOrFail();

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('sherlock', $second->module);
        $this->assertSame('joao.silva', $second->target);
        $this->assertTrue($second->statusEnum()->isTerminal());
        $this->assertTrue($first->fresh()->statusEnum()->isTerminal());
    }

    /**
     * §12: a área é restrita a administradores; visitante é mandado ao login.
     */
    public function test_panel_requires_admin_authorization(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.tests.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.tests.store'), [
            'module' => 'maigret',
            'target' => 'joao.silva',
        ])->assertForbidden();

        $this->assertSame(0, ModuleTest::query()->count());
    }

    public function test_guest_is_redirected_to_login_from_the_panel(): void
    {
        $this->get(route('admin.tests.index'))->assertRedirect(route('login'));
    }

    /**
     * §12: módulo fora da whitelist e alvo incompatível com o tipo da fonte são
     * recusados na validação — nada vira comando ou consulta.
     */
    public function test_module_and_target_are_validated_against_the_source_contract(): void
    {
        $admin = $this->adminWithCredits();

        $this->actingAs($admin)
            ->post(route('admin.tests.store'), ['module' => 'modulo-inexistente', 'target' => 'joao.silva'])
            ->assertSessionHasErrors('module');

        // Holehe consulta e-mail: username é recusado.
        $this->actingAs($admin)
            ->post(route('admin.tests.store'), ['module' => 'holehe', 'target' => 'joao.silva'])
            ->assertSessionHasErrors('target');

        // Maigret consulta username: e-mail é recusado.
        $this->actingAs($admin)
            ->post(route('admin.tests.store'), ['module' => 'maigret', 'target' => 'exemplo@email.com'])
            ->assertSessionHasErrors('target');

        $this->actingAs($admin)
            ->post(route('admin.tests.store'), ['module' => 'maigret'])
            ->assertSessionHasErrors('target');

        $this->assertSame(0, ModuleTest::query()->count());
    }

    public function test_inactive_module_is_rejected_with_a_clear_message(): void
    {
        $admin = $this->adminWithCredits();

        SourceDefinition::query()->where('slug', 'maigret')->update(['active' => false]);

        $this->actingAs($admin)
            ->post(route('admin.tests.store'), ['module' => 'maigret', 'target' => 'joao.silva'])
            ->assertSessionHasErrors('module');

        $this->assertSame(0, ModuleTest::query()->count());
    }

    /**
     * §7: sem saldo, nada é consultado e nada é cobrado (mesma regra do fluxo
     * normal — a reserva nem chega a ser criada).
     */
    public function test_without_credits_the_module_is_not_consulted_and_nothing_is_charged(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.tests.store'), [
            'module' => 'maigret',
            'target' => 'joao.silva',
        ]);

        $test = ModuleTest::query()->latest('id')->firstOrFail();

        $this->assertSame(ModuleTestStatus::Failed, $test->statusEnum());
        $this->assertSame('insufficient_credits', $test->error_code);
        $this->assertSame(0, (int) $test->credits_cost);
        $this->assertSame(0, (int) $test->credits_after);
        $this->assertNull($test->credit_reservation_id);
        $this->assertNotNull($test->error_message_public);

        // A tela de detalhe mostra o erro público e o motivo sem saldo.
        $this->actingAs($admin)
            ->get(route('admin.tests.show', $test))
            ->assertOk()
            ->assertSee('FAILED')
            ->assertSee('Saldo insuficiente', false);
    }

    /**
     * §7: a cobrança segue a regra vigente do produto — paga-se pela **consulta
     * executada**. O estouro de tempo é registrado como TIMEOUT e continua
     * cobrado, exatamente como no pipeline normal (`ReleaseReservedCreditsJob`).
     */
    public function test_timeout_is_recorded_and_billing_follows_the_existing_rule(): void
    {
        $admin = $this->adminWithCredits(10);

        $this->actingAs($admin)->post(route('admin.tests.store'), [
            'module' => 'maigret',
            'target' => 'timeout',
        ]);

        $test = ModuleTest::query()->latest('id')->firstOrFail();

        $this->assertSame(ModuleTestStatus::Timeout, $test->statusEnum());
        $this->assertNotNull($test->error_code);
        $this->assertSame(2, (int) $test->credits_cost);
        $this->assertSame(8, (int) $test->credits_after);
        $this->assertSame(1, (int) $test->sourceRun->attempts);

        // A tela de detalhe exibe TIMEOUT e os tempos por etapa medidos.
        $this->actingAs($admin)
            ->get(route('admin.tests.show', $test))
            ->assertOk()
            ->assertSee('TIMEOUT');

        $this->assertArrayHasKey('total_ms', $test->timings());
        $this->assertArrayHasKey('queue_ms', $test->timings());
    }

    /**
     * §6: módulo que roda e não encontra correspondência também é diagnosticável —
     * a seção de resultados existe e explica o vazio (o crédito já foi cobrado,
     * porque a consulta foi executada).
     */
    public function test_detail_view_explains_when_the_module_finds_nothing(): void
    {
        $admin = $this->adminWithCredits(10);

        $this->actingAs($admin)->post(route('admin.tests.store'), [
            'module' => 'maigret',
            'target' => 'not_found',
        ]);

        $test = ModuleTest::query()->latest('id')->firstOrFail();

        $this->assertSame(0, (int) $test->result_count);
        $this->assertNotNull($test->source_run_id);
        $this->assertSame(0, $test->sourceRun->sourceResults()->count());

        $this->actingAs($admin)
            ->get(route('admin.tests.show', $test))
            ->assertOk()
            ->assertSee('Resultados encontrados')
            ->assertSee('Nenhum resultado gravado nesta execução', false);
    }

    /**
     * Os avisos das ferramentas são exibidos em português. Execuções anteriores à
     * tradução têm o texto em inglês gravado em `metadata`/`metrics`; a leitura
     * converte na hora, sem reexecutar o módulo nem cobrar outro crédito.
     */
    public function test_module_warnings_are_shown_in_portuguese(): void
    {
        $admin = $this->adminWithCredits(10);

        $this->actingAs($admin)->post(route('admin.tests.store'), [
            'module' => 'maigret',
            'target' => 'joao.silva',
        ]);

        $test = ModuleTest::query()->latest('id')->firstOrFail();

        $legacy = 'Too many errors of type "Bot protection" (7.89%). Try to switch to another ip address';
        $translated = 'Muitos erros do tipo "Proteção anti-bot" (7,89%). Tente trocar de endereço IP.';

        $test->metadata = array_merge($test->metadata ?? [], ['warnings' => [$legacy]]);
        $test->save();

        $test->sourceRun->metrics = array_merge($test->sourceRun->metrics ?? [], ['warnings' => [$legacy]]);
        $test->sourceRun->save();

        $this->assertSame([$translated], $test->fresh()->warnings());
        $this->assertSame([$translated], $test->sourceRun->fresh()->publicWarnings());

        $this->actingAs($admin)
            ->get(route('admin.tests.show', $test))
            ->assertOk()
            ->assertSee('Avisos do módulo')
            ->assertSee($translated)
            ->assertDontSee('Too many errors of type');
    }

    /**
     * §6: o painel precisa distinguir "consulta sem correspondência" de
     * "ferramenta que não verificou nada". O panorama da varredura
     * (`metrics.adapter.sites_checked` / `sites_unavailable`) fica visível na
     * seção de resultados.
     */
    public function test_detail_view_shows_how_many_services_were_scanned(): void
    {
        $admin = $this->adminWithCredits(10);

        $this->actingAs($admin)->post(route('admin.tests.store'), [
            'module' => 'maigret',
            'target' => 'not_found',
        ]);

        $test = ModuleTest::query()->latest('id')->firstOrFail();

        $test->sourceRun->metrics = array_merge($test->sourceRun->metrics ?? [], [
            'adapter' => ['tool' => 'holehe', 'sites_checked' => 117, 'sites_unavailable' => 74],
        ]);
        $test->sourceRun->save();

        $this->actingAs($admin)
            ->get(route('admin.tests.show', $test))
            ->assertOk()
            ->assertSee('Serviços verificados')
            ->assertSee('sem resposta (bloqueio/erro)')
            ->assertSee('117')
            ->assertSee('74');
    }

    /**
     * Prompt 48 §6: achado do Instagram sem foto pública — a tela diz a ausência
     * ("Imagem pública não encontrada") e nunca exibe imagem fictícia.
     */
    public function test_instagram_finding_without_image_says_the_image_was_not_found(): void
    {
        $admin = $this->adminWithCredits(10);

        $this->actingAs($admin)->post(route('admin.tests.store'), [
            'module' => 'maigret',
            'target' => 'not_found',
        ]);

        $test = ModuleTest::query()->latest('id')->firstOrFail();

        $instagram = SourceDefinition::query()->where('slug', 'instagram')->firstOrFail();

        SourceResult::factory()->create([
            'source_run_id' => $test->source_run_id,
            'source_definition_id' => $instagram->id,
            'entity_type' => 'social_profile',
            'value' => 'sem.foto',
            'title' => 'Sem Foto',
            'url' => 'https://www.instagram.com/sem.foto/',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.tests.show', $test))
            ->assertOk()
            ->assertSee('Imagem pública não encontrada')
            ->assertDontSee('cdn.example.test');
    }
}
