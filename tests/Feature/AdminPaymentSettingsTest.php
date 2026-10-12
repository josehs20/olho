<?php

namespace Tests\Feature;

use App\Models\User;
use App\Payments\PaymentSettings;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Prompt 45 — Admin → Configurações → Pagamentos.
 *
 * Comprova: acesso restrito a admin, persistência da configuração, token
 * cifrado no banco e nunca exposto, normalização do token colado (aspas/CRLF e
 * prefixo `Bearer` produzem header inválido no PagBank), validação server-side e
 * teste de conexão sem efeito financeiro com diagnóstico seguro.
 */
class AdminPaymentSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'provider' => 'pagbank',
            'enabled' => '1',
            'environment' => 'sandbox',
            'token' => 'sandbox-token-secret',
            'method_pix' => '1',
            'method_debit' => '1',
            'method_credit' => '1',
            'pix_expiration_minutes' => 30,
            'credit_max_installments' => 6,
        ], $overrides);
    }

    public function test_admin_can_view_and_save_settings(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.settings.payments.edit'))->assertOk();

        $this->actingAs($admin)
            ->put(route('admin.settings.payments.update'), $this->basePayload([
                'environment' => 'production',
                'method_debit' => '0',
                'pix_expiration_minutes' => 45,
            ]))
            ->assertRedirect(route('admin.settings.payments.edit'));

        $settings = app(SettingsService::class);
        $settings->flush();

        $this->assertSame('pagbank', $settings->get(PaymentSettings::KEY_PROVIDER));
        $this->assertSame('production', $settings->get(PaymentSettings::KEY_ENVIRONMENT));
        $this->assertSame('0', (string) $settings->get(PaymentSettings::KEY_METHOD_DEBIT));
        $this->assertSame('45', (string) $settings->get(PaymentSettings::KEY_PIX_EXPIRATION));
        $this->assertSame('6', (string) $settings->get(PaymentSettings::KEY_CREDIT_MAX_INSTALLMENTS));
    }

    public function test_token_is_stored_encrypted_and_never_returned(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('admin.settings.payments.update'), $this->basePayload(['token' => 'super-secret-token']))
            ->assertRedirect();

        // Cifrado no banco (nunca texto puro).
        $raw = (string) DB::table('settings')->where('key', PaymentSettings::KEY_PAGBANK_TOKEN)->value('value');
        $this->assertNotSame('super-secret-token', $raw);
        $this->assertStringNotContainsString('super-secret-token', $raw);

        // Nunca exposto na resposta do painel.
        $this->actingAs($admin)
            ->get(route('admin.settings.payments.edit'))
            ->assertOk()
            ->assertDontSee('super-secret-token');
    }

    public function test_non_admin_cannot_access_or_change_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.settings.payments.edit'))->assertForbidden();
        $this->actingAs($user)->put(route('admin.settings.payments.update'), $this->basePayload())->assertForbidden();
        $this->actingAs($user)->post(route('admin.settings.payments.test'))->assertForbidden();
    }

    public function test_invalid_configuration_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('admin.settings.payments.update'), $this->basePayload(['environment' => 'staging']))
            ->assertSessionHasErrors('environment');

        $this->actingAs($admin)
            ->put(route('admin.settings.payments.update'), $this->basePayload(['credit_max_installments' => 99]))
            ->assertSessionHasErrors('credit_max_installments');

        $this->actingAs($admin)
            ->put(route('admin.settings.payments.update'), $this->basePayload(['provider' => 'stripe']))
            ->assertSessionHasErrors('provider');
    }

    private function configurePagBank(): void
    {
        app(SettingsService::class)->setMany([
            PaymentSettings::KEY_PROVIDER => 'pagbank',
            PaymentSettings::KEY_ENVIRONMENT => 'sandbox',
            PaymentSettings::KEY_PAGBANK_TOKEN => 'sandbox-token',
        ]);
    }

    public function test_test_connection_reports_success(): void
    {
        $admin = User::factory()->admin()->create();
        $this->configurePagBank();

        Http::fake(['sandbox.api.pagseguro.com/public-keys*' => Http::response(['public_key' => 'Zm9v'], 200)]);

        $this->actingAs($admin)->post(route('admin.settings.payments.test'))
            ->assertRedirect(route('admin.settings.payments.edit'))
            ->assertSessionHas('status');

        // Endpoint oficial: o tipo é **path param** (`/public-keys/webhook`); a
        // forma com query string (`?type=webhook`) cai em rota inexistente e o
        // gateway responde 403 com a mensagem de "Authorization header".
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://sandbox.api.pagseguro.com/public-keys/webhook'
            && ! str_contains($request->url(), 'type='));

        $settings = app(SettingsService::class);
        $settings->flush();

        $this->assertSame('1', (string) $settings->get(PaymentSettings::KEY_LAST_TEST_OK));
        // Sucesso limpa o motivo da falha anterior.
        $this->assertNull($settings->get(PaymentSettings::KEY_LAST_TEST_ERROR));
    }

    public function test_test_connection_reports_failure(): void
    {
        $admin = User::factory()->admin()->create();
        $this->configurePagBank();

        Http::fake(['sandbox.api.pagseguro.com/public-keys*' => Http::response([], 401)]);

        $this->actingAs($admin)->post(route('admin.settings.payments.test'))
            ->assertRedirect(route('admin.settings.payments.edit'))
            // Mensagem acionável: traz o status HTTP e o que revisar.
            ->assertSessionHas('error', fn (mixed $message): bool => str_contains((string) $message, 'HTTP 401'));

        $settings = app(SettingsService::class);
        $settings->flush();

        $this->assertSame('0', (string) $settings->get(PaymentSettings::KEY_LAST_TEST_OK));
        $this->assertStringContainsString('HTTP 401', (string) $settings->get(PaymentSettings::KEY_LAST_TEST_ERROR));
    }

    /**
     * Corpo real devolvido pelo gateway do PagBank para rota inexistente (ex.:
     * `GET /public-keys?type=webhook`) e para `Authorization` malformado. O
     * painel deve orientar a correção sem ecoar o corpo nem o token.
     */
    public function test_test_connection_explains_gateway_rejection(): void
    {
        $admin = User::factory()->admin()->create();
        $this->configurePagBank();

        Http::fake([
            'sandbox.api.pagseguro.com/public-keys*' => Http::response(
                '{"message":"Invalid key=value pair (missing equal-sign) in Authorization header (hashed with SHA-256 and encoded with Base64): \'djSGu8CPxe9AN9fYcdzOx/ZrYAOBMhe4vQTcMAla2+A=\'."}',
                403,
                ['Content-Type' => 'application/json'],
            ),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.settings.payments.test'))
            ->assertRedirect(route('admin.settings.payments.edit'))
            ->assertSessionHas('error', fn (mixed $message): bool => str_contains((string) $message, 'HTTP 403')
                && str_contains((string) $message, 'public-keys/webhook')
                && str_contains((string) $message, 'Bearer')
                // Corpo do provedor nunca é ecoado e o token nunca aparece.
                && ! str_contains((string) $message, 'missing equal-sign')
                && ! str_contains((string) $message, 'djSGu8CP')
                && ! str_contains((string) $message, 'sandbox-token'));

        $settings = app(SettingsService::class);
        $settings->flush();

        $this->assertSame('0', (string) $settings->get(PaymentSettings::KEY_LAST_TEST_OK));
        $this->assertStringContainsString('public-keys/webhook', (string) $settings->get(PaymentSettings::KEY_LAST_TEST_ERROR));
    }

    /**
     * Credencial recusada (corpo oficial `UNAUTHORIZED` / "Invalid credential.
     * Review AUTHORIZATION header"): a orientação aponta token x ambiente.
     */
    public function test_test_connection_explains_rejected_credential(): void
    {
        $admin = User::factory()->admin()->create();
        $this->configurePagBank();

        Http::fake([
            'sandbox.api.pagseguro.com/public-keys*' => Http::response([
                'error_messages' => [[
                    'code' => 'UNAUTHORIZED',
                    'description' => 'Invalid credential. Review AUTHORIZATION header',
                ]],
            ], 401),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.settings.payments.test'))
            ->assertRedirect(route('admin.settings.payments.edit'))
            ->assertSessionHas('error', fn (mixed $message): bool => str_contains((string) $message, 'HTTP 401')
                && str_contains((string) $message, 'ambiente')
                && ! str_contains((string) $message, 'sandbox-token'));
    }

    /**
     * O card de status mostra o endpoint efetivamente testado e uma prévia
     * mascarada do token em uso — sem nunca revelar o token completo.
     */
    public function test_panel_shows_endpoint_and_masked_token(): void
    {
        $admin = User::factory()->admin()->create();

        $token = 'a1298e7f-e2b0-4e4e-b0f0-7b2e86a52693';

        app(SettingsService::class)->setMany([
            PaymentSettings::KEY_PROVIDER => 'pagbank',
            PaymentSettings::KEY_ENVIRONMENT => 'sandbox',
            PaymentSettings::KEY_PAGBANK_TOKEN => $token,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.settings.payments.edit'))
            ->assertOk()
            ->assertSee('GET https://sandbox.api.pagseguro.com/public-keys/webhook')
            ->assertSee(PaymentSettings::maskToken($token), false)
            ->assertDontSee($token);
    }

    /**
     * Colar o token como vem de "Minha Conta"/Portal do Desenvolvedor (aspas,
     * CRLF, espaços e o próprio prefixo `Bearer`) não pode virar um header
     * `Authorization` inválido: o valor é normalizado antes de gravar e usar.
     */
    public function test_pasted_token_is_normalized_before_use(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('admin.settings.payments.update'), $this->basePayload([
                'token' => "  \"Bearer  sandbox-token-secret\"\r\n",
            ]))
            ->assertRedirect(route('admin.settings.payments.edit'));

        $settings = app(SettingsService::class);
        $settings->flush();

        $this->assertSame('sandbox-token-secret', $settings->getString(PaymentSettings::KEY_PAGBANK_TOKEN));

        Http::fake(['sandbox.api.pagseguro.com/public-keys*' => Http::response(['public_key' => 'Zm9v'], 200)]);

        $this->actingAs($admin)->post(route('admin.settings.payments.test'))->assertRedirect();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sandbox-token-secret'));
    }

    public function test_token_with_invalid_characters_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        // Colar o header inteiro (não apenas o token) é rejeitado com mensagem clara.
        $this->actingAs($admin)
            ->put(route('admin.settings.payments.update'), $this->basePayload([
                'token' => 'Authorization: Bearer abc/def?x=1',
            ]))
            ->assertSessionHasErrors('token');
    }

    /**
     * O PagBank recusa checkout sem `payment_methods`: com o provedor selecionado
     * nenhuma forma pode ficar desmarcada. O `fake` ignora as formas — segue
     * livre (evita travar o desenvolvimento).
     */
    public function test_pagbank_requires_at_least_one_payment_method(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('admin.settings.payments.update'), $this->basePayload([
                'method_pix' => '0',
                'method_debit' => '0',
                'method_credit' => '0',
            ]))
            ->assertSessionHasErrors('methods');

        $this->actingAs($admin)
            ->put(route('admin.settings.payments.update'), $this->basePayload([
                'provider' => 'fake',
                'method_pix' => '0',
                'method_debit' => '0',
                'method_credit' => '0',
            ]))
            ->assertRedirect(route('admin.settings.payments.edit'));
    }

    /**
     * O painel avisa quando o PagBank está selecionado mas a integração ainda não
     * consegue cobrar (sem token ou sem forma de pagamento).
     */
    public function test_panel_warns_when_pagbank_cannot_charge_yet(): void
    {
        $admin = User::factory()->admin()->create();

        app(SettingsService::class)->setMany([
            PaymentSettings::KEY_PROVIDER => 'pagbank',
            PaymentSettings::KEY_ENABLED => '1',
            PaymentSettings::KEY_METHOD_PIX => '0',
            PaymentSettings::KEY_METHOD_DEBIT => '0',
            PaymentSettings::KEY_METHOD_CREDIT => '0',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.settings.payments.edit'))
            ->assertOk()
            ->assertSee('ainda não consegue cobrar');
    }

    /**
     * Token salvo (e já testado) com o provedor ainda no `fake` é a armadilha mais
     * fácil de cair: a tela mostra "Conectado" e todos os métodos ativos, mas o
     * cliente continua vendo só PIX porque o checkout hospedado não existe no
     * simulado. O painel precisa dizer isso em voz alta.
     *
     * As duas requisições no mesmo teste são intencionais: o Laravel guarda a
     * instância do controller no objeto de rota, então o painel só reflete o
     * estado atual se as configurações forem resolvidas por método (e não no
     * construtor, onde o memo do `SettingsService` ficaria obsoleto).
     */
    public function test_panel_warns_when_a_saved_pagbank_token_is_idle(): void
    {
        $admin = User::factory()->admin()->create();

        app(SettingsService::class)->setMany([
            PaymentSettings::KEY_PROVIDER => 'fake',
            PaymentSettings::KEY_ENABLED => '1',
            PaymentSettings::KEY_PAGBANK_TOKEN => 'sandbox-token',
            PaymentSettings::KEY_LAST_TEST_OK => '1',
            PaymentSettings::KEY_METHOD_PIX => '1',
            PaymentSettings::KEY_METHOD_DEBIT => '1',
            PaymentSettings::KEY_METHOD_CREDIT => '1',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.settings.payments.edit'))
            ->assertOk()
            ->assertSee('o provedor ativo é o Fake', escape: false);

        // Sem token salvo o aviso não faz sentido: nada a ativar.
        app(SettingsService::class)->forget(PaymentSettings::KEY_PAGBANK_TOKEN);

        $this->actingAs($admin)
            ->get(route('admin.settings.payments.edit'))
            ->assertOk()
            ->assertDontSee('o provedor ativo é o Fake', escape: false);
    }

    /**
     * O `400` do PagBank é opaco para quem opera a conta ("revise o token"): o
     * painel mostra o diagnóstico da última cobrança **recusada** — código do
     * erro e campo apontado pelo provedor — para a correção ser objetiva.
     */
    public function test_panel_shows_the_last_rejected_checkout(): void
    {
        $admin = User::factory()->admin()->create();

        app(SettingsService::class)->setMany([
            PaymentSettings::KEY_PROVIDER => 'pagbank',
            PaymentSettings::KEY_ENABLED => '1',
            PaymentSettings::KEY_PAGBANK_TOKEN => 'sandbox-token',
            PaymentSettings::KEY_METHOD_PIX => '1',
        ]);

        app(PaymentSettings::class)->rememberCheckoutFailure(
            'PagBank: falha ao criar checkout (HTTP 400). O PagBank apontou: invalid_value em payment_methods[0].type.',
        );

        $this->actingAs($admin)
            ->get(route('admin.settings.payments.edit'))
            ->assertOk()
            ->assertSee('Última falha ao criar cobrança:')
            ->assertSee('invalid_value em payment_methods[0].type', escape: false)
            // O diagnóstico nunca carrega a credencial.
            ->assertDontSee('sandbox-token');
    }

    /**
     * Em desenvolvimento o `APP_URL` é `http://localhost`: o PagBank **recusa**
     * toda URL local (webhook **e** retorno do checkout) e o painel avisa que a
     * confirmação passa a depender da reconciliação.
     */
    public function test_panel_warns_when_the_notification_url_is_not_public(): void
    {
        $admin = User::factory()->admin()->create();

        app(SettingsService::class)->setMany([
            PaymentSettings::KEY_PROVIDER => 'pagbank',
            PaymentSettings::KEY_ENABLED => '1',
            PaymentSettings::KEY_METHOD_PIX => '1',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.settings.payments.edit'))
            ->assertOk()
            ->assertSee('o PagBank recusa URLs locais')
            ->assertSee('notification_urls, redirect_url e return_url ficam de fora do checkout');
    }
}
