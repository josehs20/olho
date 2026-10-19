<?php

namespace Tests\Feature;

use App\Models\CreditPackage;
use App\Models\CreditTransaction;
use App\Models\Investigation;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Models\Wallet;
use App\Payments\PaymentChannel;
use App\Payments\PaymentMethod;
use App\Payments\PaymentSettings;
use App\Payments\PaymentStatus;
use App\Payments\PaymentUnavailableException;
use App\Services\GuestInvestigationService;
use App\Services\PaymentService;
use App\Services\SettingsService;
use Database\Seeders\CreditPackageSeeder;
use Database\Seeders\SourceDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Link de pagamento colado pelo administrador (provedor `payment_link`).
 *
 * O comprador paga no site do banco, avisa "já paguei" e **nada** é liberado
 * por esse clique: a confirmação é exclusivamente manual, feita pelo
 * administrador depois de conferir o extrato.
 */
class PaymentLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SourceDefinitionSeeder::class);
        $this->seed(CreditPackageSeeder::class);

        config(['queue.default' => 'sync', 'cache.default' => 'array']);

        app(SettingsService::class)->setMany([
            PaymentSettings::KEY_PROVIDER => PaymentSettings::PROVIDER_PAYMENT_LINK,
            PaymentSettings::KEY_ENABLED => '1',
        ]);
    }

    private function packageWithLink(string $link = 'https://banco.test/pix/abc123'): CreditPackage
    {
        return CreditPackage::factory()->create([
            'credits' => 100,
            'price_cents' => 5000,
            'payment_link' => $link,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_checkout_points_the_order_to_the_bank_link_without_expiration(): void
    {
        $user = User::factory()->create();
        $package = $this->packageWithLink();

        $payment = app(PaymentService::class)->checkout($user, $package, PaymentChannel::Pix);

        $this->assertSame(PaymentStatus::AwaitingPayment->value, $payment->status);
        $this->assertSame(PaymentSettings::PROVIDER_PAYMENT_LINK, $payment->provider);
        $this->assertSame('https://banco.test/pix/abc123', $payment->checkout_url);
        $this->assertSame(PaymentMethod::Pix, $payment->method);
        $this->assertNull($payment->expires_at);
        $this->assertSame('link-'.$payment->id, $payment->external_id);
        // Nenhum crédito antes da conferência do administrador.
        $this->assertSame(0, CreditTransaction::query()->count());
    }

    public function test_checkout_refuses_a_package_without_link_and_creates_no_order(): void
    {
        $user = User::factory()->create();
        $package = CreditPackage::factory()->create([
            'credits' => 100,
            'price_cents' => 5000,
            'payment_link' => null,
        ]);

        try {
            app(PaymentService::class)->checkout($user, $package);
            $this->fail('A cobrança deveria ter sido recusada: o pacote não tem link.');
        } catch (PaymentUnavailableException $exception) {
            $this->assertStringContainsString('link de pagamento', $exception->getMessage());
        }

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_card_channel_is_not_offered_with_a_bank_link(): void
    {
        $user = User::factory()->create();
        $package = $this->packageWithLink();

        try {
            app(PaymentService::class)->checkout($user, $package, PaymentChannel::Card);
            $this->fail('Cartão não existe no fluxo de link do banco.');
        } catch (PaymentUnavailableException) {
            $this->assertSame(0, Payment::query()->count());
        }
    }

    public function test_the_store_redirects_to_the_order_page_so_the_buyer_keeps_the_already_paid_button(): void
    {
        $user = User::factory()->create();
        $package = $this->packageWithLink();

        $response = $this->actingAs($user)->post(route('payments.store', $package), [
            'channel' => PaymentChannel::Pix->value,
        ]);

        $payment = Payment::query()->latest('id')->firstOrFail();

        // Sem redirecionar direto para o banco: a página do pedido é o ponto de
        // retorno, com o botão do link e o "já paguei".
        $response->assertRedirect(route('payments.show', $payment));

        $this->actingAs($user)
            ->get(route('payments.show', $payment))
            ->assertOk()
            ->assertSee('ABRIR LINK DE PAGAMENTO')
            ->assertSee('JÁ PAGUEI');
    }

    public function test_buyer_click_only_enqueues_the_payment_for_manual_review(): void
    {
        $user = User::factory()->create();
        $package = $this->packageWithLink();
        $payment = app(PaymentService::class)->checkout($user, $package, PaymentChannel::Pix);

        $this->actingAs($user)
            ->post(route('payments.verify', $payment))
            ->assertRedirect(route('payments.show', $payment));

        $payment->refresh();

        $this->assertSame(PaymentStatus::AwaitingManualConfirmation->value, $payment->status);
        $this->assertSame(0, CreditTransaction::query()->count());
        $this->assertSame(1, PaymentEvent::query()->where('external_event_id', 'manual-request-'.$payment->id)->count());

        // Clicar de novo é idempotente: um único aviso na fila.
        $this->actingAs($user)->post(route('payments.verify', $payment))->assertRedirect();
        $this->assertSame(1, PaymentEvent::query()->where('external_event_id', 'manual-request-'.$payment->id)->count());

        $this->actingAs($user)
            ->get(route('payments.show', $payment))
            ->assertOk()
            ->assertSee('Em conferência');
    }

    public function test_admin_confirmation_releases_the_credits_exactly_once(): void
    {
        $user = User::factory()->create();
        $package = $this->packageWithLink();
        $payment = app(PaymentService::class)->checkout($user, $package, PaymentChannel::Pix);

        app(PaymentService::class)->requestManualConfirmation($payment);

        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.payments.manual-confirm', $payment))
            ->assertRedirect(route('admin.payments.manual'));

        $payment->refresh();

        $this->assertSame(PaymentStatus::Paid->value, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame($package->credits, (int) Wallet::query()->where('user_id', $user->id)->value('balance'));
        $this->assertSame(1, CreditTransaction::query()->where('user_id', $user->id)->count());

        // Segundo clique (duplo submit/duas abas) não credita de novo.
        $this->actingAs($admin)
            ->post(route('admin.payments.manual-confirm', $payment))
            ->assertRedirect(route('admin.payments.manual'));

        $this->assertSame(1, CreditTransaction::query()->where('user_id', $user->id)->count());
        $this->assertSame($package->credits, (int) Wallet::query()->where('user_id', $user->id)->value('balance'));
    }

    public function test_admin_dismiss_returns_the_payment_to_awaiting_payment_without_crediting(): void
    {
        $user = User::factory()->create();
        $package = $this->packageWithLink();
        $payment = app(PaymentService::class)->checkout($user, $package, PaymentChannel::Pix);

        app(PaymentService::class)->requestManualConfirmation($payment);

        $this->actingAs($this->admin())
            ->post(route('admin.payments.manual-dismiss', $payment))
            ->assertRedirect(route('admin.payments.manual'));

        $payment->refresh();

        $this->assertSame(PaymentStatus::AwaitingPayment->value, $payment->status);
        $this->assertNotNull(data_get($payment->metadata, 'manual_dismissed_at'));
        $this->assertSame(0, CreditTransaction::query()->count());
    }

    public function test_non_admin_cannot_confirm_the_payment(): void
    {
        $user = User::factory()->create();
        $package = $this->packageWithLink();
        $payment = app(PaymentService::class)->checkout($user, $package, PaymentChannel::Pix);

        app(PaymentService::class)->requestManualConfirmation($payment);

        $this->actingAs($user)
            ->post(route('admin.payments.manual-confirm', $payment))
            ->assertForbidden();

        $this->assertSame(PaymentStatus::AwaitingManualConfirmation->value, $payment->refresh()->status);
        $this->assertSame(0, CreditTransaction::query()->count());
    }

    public function test_guest_payment_link_releases_the_investigation_only_after_manual_confirmation(): void
    {
        $guestPackage = CreditPackage::query()->guestKind()->firstOrFail();
        $guestPackage->update(['payment_link' => 'https://banco.test/pix/avulsa']);

        $guest = app(GuestInvestigationService::class)->start(['username' => 'link.teste']);
        $investigation = $guest['investigation'];

        $payment = app(PaymentService::class)->checkoutGuest($investigation, $guestPackage);

        $this->assertSame(PaymentSettings::PROVIDER_PAYMENT_LINK, $payment->provider);
        $this->assertSame(PaymentStatus::AwaitingPayment->value, $payment->status);

        // "Já paguei": entra na fila, mas a investigação continua em rascunho.
        $this->post(route('guest.payments.verify', ['token' => $guest['token']]))->assertRedirect();

        $this->assertSame(PaymentStatus::AwaitingManualConfirmation->value, $payment->refresh()->status);
        $this->assertSame(Investigation::STATUS_DRAFT, $investigation->refresh()->status);

        // Administrador confere o extrato e libera.
        $this->actingAs($this->admin())
            ->post(route('admin.payments.manual-confirm', $payment))
            ->assertRedirect(route('admin.payments.manual'));

        $this->assertSame(PaymentStatus::Paid->value, $payment->refresh()->status);
        // Liberada: saiu do rascunho e ganhou execução (com a fila em `sync` no
        // teste, a execução mock pode terminar antes desta asserção).
        $investigation->refresh();
        $this->assertNotSame(Investigation::STATUS_DRAFT, $investigation->status);
        $this->assertNotSame(Investigation::STATUS_EXPIRED, $investigation->status);
        $this->assertNotNull($investigation->current_run_id);
        // Avulsa não gera créditos.
        $this->assertSame(0, CreditTransaction::query()->count());
    }

    public function test_admin_manual_page_lists_the_payment_awaiting_review(): void
    {
        $user = User::factory()->create();
        $package = $this->packageWithLink();
        $payment = app(PaymentService::class)->checkout($user, $package, PaymentChannel::Pix);

        app(PaymentService::class)->requestManualConfirmation($payment);

        $this->actingAs($this->admin())
            ->get(route('admin.payments.manual'))
            ->assertOk()
            ->assertSee($user->email)
            ->assertSee('Confirmar recebimento')
            ->assertSee('1 aviso pendente');
    }

    public function test_admin_can_select_the_payment_link_provider_in_the_settings_form(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.settings.payments.update'), [
                'provider' => PaymentSettings::PROVIDER_PAYMENT_LINK,
                'enabled' => 1,
                'environment' => PaymentSettings::ENV_SANDBOX,
                'method_pix' => 1,
                'method_debit' => 0,
                'method_credit' => 0,
                'pix_expiration_minutes' => 30,
                'credit_max_installments' => 1,
            ])
            ->assertRedirect();

        $this->assertSame(
            PaymentSettings::PROVIDER_PAYMENT_LINK,
            app(SettingsService::class)->get(PaymentSettings::KEY_PROVIDER),
        );

        $this->actingAs($this->admin())
            ->get(route('admin.settings.payments.edit'))
            ->assertOk()
            ->assertSee('Link do banco (confirmação manual)');
    }

    public function test_admin_can_save_the_bank_link_on_a_package_and_unsafe_schemes_are_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.packages.store'), [
            'name' => 'Pacote com link',
            'credits' => 50,
            'price_cents' => 2500,
            'active' => 1,
            'display_order' => 1,
            'payment_link' => 'https://banco.test/pix/pacote',
        ])->assertRedirect(route('admin.packages.index'));

        $this->assertDatabaseHas('credit_packages', [
            'name' => 'Pacote com link',
            'payment_link' => 'https://banco.test/pix/pacote',
        ]);

        $this->actingAs($admin)->post(route('admin.packages.store'), [
            'name' => 'Pacote inseguro',
            'credits' => 50,
            'price_cents' => 2500,
            'active' => 1,
            'display_order' => 2,
            'payment_link' => 'javascript:alert(1)',
        ])->assertSessionHasErrors('payment_link');

        $this->assertDatabaseMissing('credit_packages', ['name' => 'Pacote inseguro']);
    }
}
