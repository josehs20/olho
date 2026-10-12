<?php

namespace Tests\Feature;

use App\Models\ModuleTest;
use App\Models\User;
use App\Models\Wallet;
use App\ModuleTests\ModuleTestStatus;
use App\Services\CreditLedgerService;
use App\Services\SettingsService;
use App\Sources\Integrations\InstagramSettings;
use App\Sources\Integrations\SessionCheckable;
use App\Sources\InvestigationContext;
use App\Sources\InvestigationInput;
use App\Sources\InvestigationSource;
use App\Sources\SourceOutcome;
use App\Sources\SourceRegistry;
use App\Sources\SourceResult;
use Database\Seeders\CreditPackageSeeder;
use Database\Seeders\SourceDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prompt 48 — etapa 2 (Profile Enrichment): sessão do Instagram no painel admin,
 * credencial cifrada e tradução do desfecho da sessão para o contrato de fontes.
 *
 * Os adapters rodam em modo `mock` (phpunit.xml), então a suíte permanece
 * hermética: nenhuma rede é tocada e o comportamento exercitado é o do adapter
 * determinístico, que espelha o contrato do adapter real.
 */
class InstagramIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SourceDefinitionSeeder::class);
        $this->seed(CreditPackageSeeder::class);

        config(['queue.default' => 'sync', 'cache.default' => 'array']);
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        $wallet = Wallet::query()->firstOrCreate(['user_id' => $admin->id], ['balance' => 0]);

        app(CreditLedgerService::class)->promotional($admin, $wallet, 20, 'instagram-topup-'.$admin->id);

        return $admin;
    }

    /**
     * §2.1: a tela existe, exibe o aviso obrigatório e o estado da sessão.
     */
    public function test_integration_screen_is_admin_only_and_shows_the_burner_warning(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.settings.integrations.edit'))
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->get(route('admin.settings.integrations.edit'))
            ->assertOk()
            ->assertSee('burner account')
            ->assertSee('EXPIRED / INVALID')
            ->assertSee('Testar conexão');
    }

    /**
     * O `sessionid` é um segredo: fica cifrado no banco e nunca volta para a view.
     */
    public function test_session_cookie_is_encrypted_and_never_returned_to_the_view(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.settings.integrations.update'), [
            'instagram_driver' => 'mock',
            'session_id' => 'sessionid=1234567890abcdef%3A7%3AABC; ds_user_id=999999;',
            'ds_user_id' => '999999',
        ])->assertRedirect(route('admin.settings.integrations.edit'));

        $settings = app(InstagramSettings::class);

        // Valor normalizado (sem `sessionid=`, `;` ou espaços).
        $this->assertSame('1234567890abcdef:7:ABC', $settings->sessionId());
        $this->assertSame('999999', $settings->dsUserId());

        // Cifrado no banco: o texto puro não aparece em nenhuma coluna.
        $raw = \DB::table('settings')->where('key', InstagramSettings::KEY_SESSION_ID)->value('value');
        $this->assertNotSame('1234567890abcdef:7:ABC', (string) $raw);
        $this->assertStringNotContainsString('1234567890abcdef', (string) $raw);

        // A view mostra apenas a prévia mascarada.
        $this->actingAs($admin)
            ->get(route('admin.settings.integrations.edit'))
            ->assertOk()
            ->assertSee('1234')
            ->assertDontSee('1234567890abcdef');
    }

    /**
     * Ação "Testar conexão": sem sessão não testa; com sessão, atualiza o badge.
     */
    public function test_connection_test_updates_the_session_status(): void
    {
        $admin = $this->admin();

        // Sem credencial: nada é executado e o operador é orientado.
        $this->actingAs($admin)
            ->post(route('admin.settings.integrations.test'))
            ->assertSessionHas('error');

        // Com credencial e driver mock (adapter determinístico), o teste passa.
        app(SettingsService::class)->set(InstagramSettings::KEY_SESSION_ID, '1234567890abcdef');

        $this->actingAs($admin)
            ->post(route('admin.settings.integrations.test'))
            ->assertSessionHas('status');

        $this->assertSame(InstagramSettings::STATUS_HEALTHY, app(InstagramSettings::class)->status());
    }

    /**
     * Sessão rejeitada pelo Instagram: o painel mostra uma mensagem de **ação**
     * clara (em português), nunca o texto cru/genérico devolvido pelo Instagram
     * ("We're sorry, but something went wrong..."), que parece um erro do sistema
     * e não orienta o operador. O diagnóstico cru continua disponível em
     * "Última observação" para inspeção — sem nunca expor o cookie.
     */
    public function test_connection_test_shows_actionable_message_not_raw_instagram_error(): void
    {
        $admin = $this->admin();

        app(SettingsService::class)->set(InstagramSettings::KEY_SESSION_ID, '1234567890abcdef');

        // Adapter falso que reproduz o desfecho real: o Instagram rejeitou o
        // cookie e devolveu seu texto genérico. Espelha o contrato do adapter
        // real (SessionCheckable + InvestigationSource) sem tocar na rede.
        $registry = app(SourceRegistry::class);
        $registry->register(new class implements InvestigationSource, SessionCheckable
        {
            public function key(): string
            {
                return 'instagram';
            }

            public function name(): string
            {
                return 'Instagram (instagrapi)';
            }

            public function version(): string
            {
                return 'test';
            }

            public function supports(InvestigationInput $input): bool
            {
                return true;
            }

            public function execute(InvestigationContext $context): SourceOutcome
            {
                throw new \LogicException('não usado neste teste');
            }

            public function normalize(array $raw): SourceResult
            {
                throw new \LogicException('não usado neste teste');
            }

            public function healthCheck(): bool
            {
                return false;
            }

            public function checkSession(): array
            {
                return [
                    'healthy' => false,
                    'challenge' => false,
                    'account' => null,
                    'message' => "We're sorry, but something went wrong. Please try again.",
                ];
            }
        });

        $this->actingAs($admin)
            ->post(route('admin.settings.integrations.test'))
            ->assertRedirect(route('admin.settings.integrations.edit'))
            ->assertSessionHas('error');

        // A mensagem exibida é a curada/acionável — não o texto cru do Instagram.
        $flash = session('error');
        $this->assertIsString($flash);
        $this->assertStringNotContainsString("We're sorry", $flash);
        $this->assertStringContainsString('recusou a conexão', $flash);
        // Não culpa só a credencial: aponta o bloqueio de IP de datacenter.
        $this->assertStringContainsString('IP de datacenter', $flash);

        $settings = app(InstagramSettings::class);
        $this->assertSame(InstagramSettings::STATUS_EXPIRED, $settings->status());

        // O diagnóstico cru do Instagram fica preservado em "Última observação".
        $this->assertSame(
            "We're sorry, but something went wrong. Please try again.",
            $settings->lastMessage(),
        );
    }

    /**
     * O módulo aparece no painel de testes individuais (prompt 47) e produz um
     * resultado com o avatar como evidência visual.
     */
    public function test_module_is_testable_in_the_admin_panel_with_avatar_evidence(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.tests.index'))
            ->assertOk()
            ->assertSee('Instagram (instagrapi)');

        $this->actingAs($admin)->post(route('admin.tests.store'), [
            'module' => 'instagram',
            'target' => 'joao.silva',
        ])->assertRedirect();

        $test = ModuleTest::query()->latest('id')->firstOrFail();

        $this->assertSame('instagram', $test->module);
        $this->assertTrue($test->statusEnum()->isTerminal());
        $this->assertSame(1, (int) $test->result_count);
        $this->assertSame(2, (int) $test->credits_cost);

        $result = $test->sourceRun->sourceResults()->firstOrFail();

        $this->assertSame('social_profile', $result->entity_type);
        $this->assertSame(
            'https://cdn.example.test/instagram/joao.silva.jpg',
            $result->evidence()->where('type', 'avatar')->value('value'),
        );

        // A interface do módulo (instagram) fica registrada no adapter, com a marca
        // da correlação visual pendente (pHash roda fora da coleta).
        $sourceRun = $test->sourceRun()->firstOrFail()->fresh();

        $this->assertSame('instagrapi', data_get($sourceRun->metrics, 'adapter.tool'));
        $this->assertSame('pending', data_get($sourceRun->metrics, 'adapter.visual_correlation'));

        // §6 (apresentação): a tela de detalhe do teste renderiza a captura de
        // imagem como <img> — a foto é testável no painel, como pede o §22.
        $this->actingAs($admin)
            ->get(route('admin.tests.show', $test))
            ->assertOk()
            ->assertSee('src="https://cdn.example.test/instagram/joao.silva.jpg"', false)
            ->assertSee('Imagem pública do perfil', false);
    }

    /**
     * Sessão revogada/desafio do Instagram: **nada** foi consultado sobre o alvo,
     * então o crédito é estornado e o teste é SKIPPED (não conta como resultado).
     */
    public function test_expired_session_skips_and_refunds_the_reservation(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.tests.store'), [
            'module' => 'instagram',
            'target' => 'no_session',
        ]);

        $test = ModuleTest::query()->latest('id')->firstOrFail();

        $this->assertSame(ModuleTestStatus::Skipped, $test->statusEnum());
        $this->assertSame(20, (int) $test->credits_before);
        $this->assertSame(0, (int) $test->credits_cost);
        $this->assertSame(20, (int) $test->credits_after);
        $this->assertSame('released', $test->creditReservation?->status);
        $this->assertSame('session_invalid', $test->error_code);

        // Mensagem do painel (audiência admin): a orientação curada da própria
        // exceção — não o texto genérico mostrado ao público.
        $this->assertSame('Sessão do Instagram expirada (mock).', $test->error_message_public);
        $this->assertSame(
            'A conexão com a plataforma não está autenticada; a consulta não foi realizada.',
            $test->sourceRun?->error_message_public,
        );
    }
}
