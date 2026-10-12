<?php

namespace Tests\Feature;

use App\Models\CreditPackage;
use App\Models\Investigation;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\SourceRun;
use App\Models\User;
use App\Models\Wallet;
use App\Payments\PaymentMethod;
use App\Payments\PaymentSettings;
use App\Payments\PaymentStatus;
use App\Services\PaymentService;
use App\Services\SettingsService;
use Database\Seeders\CreditPackageSeeder;
use Database\Seeders\SourceDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sincronização forçada do PagBank (comando `pagbank:sync-payment` + endpoint
 * admin `admin.payments.sync`).
 *
 * Cobre o caminho "webhook não chegou": reconsulta o provedor e confirma o
 * pedido aprovado — sempre pela resposta do PagBank, de forma idempotente e sem
 * confiar em parâmetro do navegador. A liberação (investigação avulsa ou
 * créditos) continua sendo a mesma do webhook.
 */
class PagBankSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SourceDefinitionSeeder::class);
        $this->seed(CreditPackageSeeder::class);

        app(SettingsService::class)->setMany([
            PaymentSettings::KEY_PROVIDER => 'pagbank',
            PaymentSettings::KEY_ENABLED => '1',
            PaymentSettings::KEY_ENVIRONMENT => 'sandbox',
            PaymentSettings::KEY_PAGBANK_TOKEN => 'sandbox-token',
            PaymentSettings::KEY_METHOD_PIX => '1',
            PaymentSettings::KEY_METHOD_DEBIT => '0',
            PaymentSettings::KEY_METHOD_CREDIT => '1',
            PaymentSettings::KEY_CREDIT_MAX_INSTALLMENTS => '6',
        ]);

        $this->fakePagBank(paid: true);
    }

    /**
     * Checkout criado (`POST /checkouts`) + consulta (`GET /checkouts/{id}`).
     *
     * O mesmo checkout `CHEC_SYNC` é reaproveitado: o OLHO sempre grava esse id no
     * pedido, e a consulta é quem decide o estado visto pela sincronização.
     */
    private function fakePagBank(bool $paid): void
    {
        Http::fake([
            'sandbox.api.pagseguro.com/checkouts' => Http::response([
                'id' => 'CHEC_SYNC',
                'status' => 'ACTIVE',
                'expiration_date' => '2030-10-01T12:00:00-03:00',
                'links' => [
                    ['rel' => 'PAY', 'href' => 'https://pagamento.pagseguro.uol.com.br/pagamento?code=sync', 'method' => 'GET'],
                ],
            ], 200),
            'sandbox.api.pagseguro.com/checkouts/CHEC_SYNC' => Http::response([
                'id' => 'CHEC_SYNC',
                'status' => $paid ? 'PAID' : 'ACTIVE',
                'charges' => [[
                    'id' => 'CHARGE_SYNC',
                    'status' => $paid ? 'PAID' : 'WAITING',
                    'payment_method' => ['type' => 'PIX'],
                ]],
            ], 200),
        ]);
    }

    /**
     * Jornada avulsa: cria a investigação sem conta e devolve o pedido PagBank.
     */
    private function startGuestPayment(string $username): Payment
    {
        $this->post(route('guest.investigations.store'), ['username' => $username])->assertRedirect();

        return Investigation::query()->latest('id')->firstOrFail()->guestPayment()->firstOrFail();
    }

    public function test_admin_sync_confirms_a_paid_checkout_and_releases_the_guest_investigation(): void
    {
        $admin = User::factory()->admin()->create();
        $payment = $this->startGuestPayment('sync.avulsa');
        $investigation = $payment->guestInvestigation()->firstOrFail();

        $this->actingAs($admin)
            ->postJson(route('admin.payments.sync', $payment))
            ->assertOk()
            ->assertJson([
                'payment_id' => $payment->id,
                'status' => PaymentStatus::Paid->value,
                'provider_status' => PaymentStatus::Paid->value,
                'paid' => true,
                'released_investigation' => true,
                'credits_granted' => false,
            ]);

        $this->assertSame(PaymentStatus::Paid->value, $payment->refresh()->status);
        $this->assertContains($investigation->refresh()->status, [Investigation::STATUS_COMPLETED, Investigation::STATUS_PARTIAL]);
        $this->assertGreaterThan(0, SourceRun::query()->count());
    }

    public function test_admin_sync_grants_credits_for_a_paid_account_checkout(): void
    {
        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->create();
        $package = CreditPackage::factory()->create(['credits' => 80, 'price_cents' => 2990]);

        $payment = app(PaymentService::class)->checkout($buyer, $package);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.sync', $payment))
            ->assertOk()
            ->assertJson([
                'paid' => true,
                'credits_granted' => true,
                'released_investigation' => false,
            ]);

        $this->assertSame(80, (int) Wallet::query()->where('user_id', $buyer->id)->firstOrFail()->balance);
    }

    /**
     * Regressão do sandbox real (cartão aprovado, créditos não liberados):
     * `GET /checkouts/{id}` continua `ACTIVE` e **sem** `charges`; a cobrança
     * paga aparece só no pedido do checkout (`orders[].id` → `ORDE_...`).
     */
    public function test_admin_sync_grants_credits_when_the_paid_charge_is_only_in_the_checkout_order(): void
    {
        // O stub do setUp descreve um checkout com `charges`: aqui vale o que o
        // PagBank devolve de fato depois do cartão aprovado.
        Http::swap(new Factory);
        Http::fake([
            'sandbox.api.pagseguro.com/checkouts' => Http::response([
                'id' => 'CHEC_ORDER',
                'status' => 'ACTIVE',
                'expiration_date' => '2030-10-01T12:00:00-03:00',
                'links' => [
                    ['rel' => 'PAY', 'href' => 'https://pagamento.sandbox.pagbank.com.br/pagamento?code=order', 'method' => 'GET'],
                ],
            ], 200),
            'sandbox.api.pagseguro.com/checkouts/CHEC_ORDER' => Http::response([
                'id' => 'CHEC_ORDER',
                'status' => 'ACTIVE',
                'orders' => [['id' => 'ORDE_1']],
            ], 200),
            'sandbox.api.pagseguro.com/orders/ORDE_1' => Http::response([
                'id' => 'ORDE_1',
                'charges' => [[
                    'id' => 'CHAR_1',
                    'status' => 'PAID',
                    'paid_at' => '2030-01-01T10:00:00.000-03:00',
                    'payment_method' => ['type' => 'CREDIT_CARD'],
                ]],
            ], 200),
        ]);

        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->create();
        $package = CreditPackage::factory()->create(['credits' => 50, 'price_cents' => 1990]);

        $payment = app(PaymentService::class)->checkout($buyer, $package);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.sync', $payment))
            ->assertOk()
            ->assertJson([
                'status' => PaymentStatus::Paid->value,
                'paid' => true,
                'credits_granted' => true,
            ]);

        $this->assertSame(PaymentStatus::Paid->value, $payment->refresh()->status);
        $this->assertSame(50, (int) Wallet::query()->where('user_id', $buyer->id)->firstOrFail()->balance);
    }

    public function test_admin_sync_is_idempotent_when_the_payment_is_already_paid(): void
    {
        $admin = User::factory()->admin()->create();
        $payment = $this->startGuestPayment('sync.idempotente');

        $this->actingAs($admin)->postJson(route('admin.payments.sync', $payment))->assertOk();
        $this->actingAs($admin)
            ->postJson(route('admin.payments.sync', $payment))
            ->assertOk()
            ->assertJson(['paid' => true]);

        // Uma única confirmação foi registrada, apesar das duas sincronizações.
        $this->assertSame(1, PaymentEvent::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(PaymentStatus::Paid->value, $payment->refresh()->status);
    }

    public function test_admin_sync_keeps_waiting_when_the_checkout_is_not_paid(): void
    {
        // O primeiro stub registrado vence: zera o cliente do setUp para simular
        // um checkout ainda não pago.
        Http::swap(new Factory);
        $this->fakePagBank(paid: false);

        $admin = User::factory()->admin()->create();
        $payment = $this->startGuestPayment('sync.pendente');

        $this->actingAs($admin)
            ->postJson(route('admin.payments.sync', $payment))
            ->assertOk()
            ->assertJson([
                'status' => PaymentStatus::AwaitingPayment->value,
                'provider_status' => PaymentStatus::AwaitingPayment->value,
                'paid' => false,
            ]);

        $this->assertSame(PaymentStatus::AwaitingPayment->value, $payment->refresh()->status);
        $this->assertSame(0, PaymentEvent::query()->where('payment_id', $payment->id)->count());
    }

    public function test_sync_is_restricted_to_admins(): void
    {
        $payment = Payment::factory()->create(['provider' => 'pagbank', 'external_id' => 'CHEC_SYNC']);

        // Sem sessão: o grupo `auth` manda para o login.
        $this->post(route('admin.payments.sync', $payment))->assertRedirect(route('login'));

        // Autenticado, mas sem privilégio de administrador: 403.
        $this->actingAs(User::factory()->create())
            ->postJson(route('admin.payments.sync', $payment))
            ->assertForbidden();
    }

    public function test_sync_rejects_a_non_pagbank_payment(): void
    {
        $admin = User::factory()->admin()->create();
        $payment = Payment::factory()->create(['provider' => 'fake']);

        $response = $this->actingAs($admin)->postJson(route('admin.payments.sync', $payment));

        $response->assertStatus(422)->assertJson(['payment_id' => $payment->id]);
        $this->assertStringContainsString('PagBank', (string) $response->json('error'));
    }

    public function test_command_syncs_by_sequential_id(): void
    {
        $payment = $this->startGuestPayment('cmd.id');

        $exit = Artisan::call('pagbank:sync-payment', ['payment' => (string) $payment->id]);

        $this->assertSame(0, $exit);
        $this->assertSame(PaymentStatus::Paid->value, $payment->refresh()->status);
    }

    public function test_command_syncs_by_reference_id(): void
    {
        $payment = $this->startGuestPayment('cmd.referencia');

        Artisan::call('pagbank:sync-payment', ['payment' => 'olho-'.$payment->id]);

        $this->assertSame(PaymentStatus::Paid->value, $payment->refresh()->status);
    }

    public function test_command_dry_run_does_not_confirm(): void
    {
        $payment = $this->startGuestPayment('cmd.dry-run');

        Artisan::call('pagbank:sync-payment', ['payment' => (string) $payment->id, '--dry-run' => true]);

        $this->assertSame(PaymentStatus::AwaitingPayment->value, $payment->refresh()->status);
        $this->assertSame(0, PaymentEvent::query()->where('payment_id', $payment->id)->count());
        $this->assertStringContainsString('dry-run', Artisan::output());
    }

    public function test_command_ignores_a_non_pagbank_payment(): void
    {
        $payment = Payment::factory()->create(['provider' => 'fake']);

        $exit = Artisan::call('pagbank:sync-payment', ['payment' => (string) $payment->id]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('só sincroniza o PagBank', Artisan::output());
    }

    public function test_payments_index_shows_the_sync_action_only_for_pagbank(): void
    {
        $admin = User::factory()->admin()->create();
        $pagbank = Payment::factory()->create(['provider' => 'pagbank', 'external_id' => 'CHEC_SYNC']);
        $fake = Payment::factory()->create(['provider' => 'fake']);

        $response = $this->actingAs($admin)->get(route('admin.payments.index'))->assertOk();

        $response->assertSee(route('admin.payments.sync', $pagbank), escape: false);
        $response->assertDontSee(route('admin.payments.sync', $fake), escape: false);
    }

    /**
     * Stub de dois checkouts no mesmo ambiente: `CHEC_SYNC` **aprovado** (a
     * cobrança PIX aparece só no pedido, como no sandbox real) e `CHEC_WAITING`
     * ainda aguardando. É o cenário da varredura: um pago, um pendente.
     */
    private function fakePagBankMixed(): void
    {
        Http::swap(new Factory);

        Http::fake([
            'sandbox.api.pagseguro.com/checkouts' => Http::response([
                'id' => 'CHEC_SYNC',
                'status' => 'ACTIVE',
                'expiration_date' => '2030-10-01T12:00:00-03:00',
                'links' => [
                    ['rel' => 'PAY', 'href' => 'https://pagamento.sandbox.pagbank.com.br/pagamento?code=sweep', 'method' => 'GET'],
                ],
            ], 200),
            'sandbox.api.pagseguro.com/checkouts/CHEC_SYNC' => Http::response([
                'id' => 'CHEC_SYNC',
                'status' => 'ACTIVE',
                'orders' => [['id' => 'ORDE_SYNC']],
            ], 200),
            'sandbox.api.pagseguro.com/orders/ORDE_SYNC' => Http::response([
                'id' => 'ORDE_SYNC',
                'charges' => [[
                    'id' => 'CHAR_SYNC',
                    'status' => 'PAID',
                    'paid_at' => '2030-01-01T10:00:00.000-03:00',
                    'payment_method' => ['type' => 'PIX'],
                ]],
            ], 200),
            'sandbox.api.pagseguro.com/checkouts/CHEC_WAITING' => Http::response([
                'id' => 'CHEC_WAITING',
                'status' => 'ACTIVE',
                'charges' => [[
                    'id' => 'CHAR_WAITING',
                    'status' => 'WAITING',
                    'payment_method' => ['type' => 'PIX'],
                ]],
            ], 200),
        ]);
    }

    /**
     * "Conferir todos os pagamentos": a varredura consulta **todos** os pendentes
     * PagBank de uma vez e libera o que já constar como pago — o PIX pago cuja
     * notificação não chegou não fica mais aguardando para sempre.
     */
    public function test_command_sweeps_every_pending_pagbank_payment(): void
    {
        $this->fakePagBankMixed();

        $paid = $this->startGuestPayment('sweep.pago');
        $waiting = Payment::factory()->awaitingPayment()->create([
            'provider' => 'pagbank',
            'external_id' => 'CHEC_WAITING',
        ]);
        $otherProvider = Payment::factory()->awaitingPayment()->create(['provider' => 'fake']);

        $exit = Artisan::call('pagbank:sync-payment', ['--pending' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame(PaymentStatus::Paid->value, $paid->refresh()->status);
        $this->assertSame(PaymentMethod::Pix->value, $paid->method?->value);
        $this->assertSame(PaymentStatus::AwaitingPayment->value, $waiting->refresh()->status);
        // Pedido de outro provedor não entra na varredura do PagBank.
        $this->assertSame(PaymentStatus::AwaitingPayment->value, $otherProvider->refresh()->status);

        $output = Artisan::output();
        $this->assertStringContainsString('[confirmado] #'.$paid->id, $output);
        $this->assertStringContainsString('[pendente] #'.$waiting->id, $output);
        $this->assertStringContainsString('2 consultado(s): 1 confirmado(s), 1 pendente(s), 0 falha(s).', $output);
    }

    public function test_command_sweep_is_idempotent(): void
    {
        $this->fakePagBankMixed();

        $buyer = User::factory()->create();
        $package = CreditPackage::factory()->create(['credits' => 70, 'price_cents' => 1990]);
        $payment = app(PaymentService::class)->checkout($buyer, $package);

        Artisan::call('pagbank:sync-payment', ['--pending' => true]);
        $this->assertSame(70, (int) Wallet::query()->where('user_id', $buyer->id)->firstOrFail()->balance);

        // Segunda varredura: o pedido não é mais pendente e nada é liberado de novo.
        Artisan::call('pagbank:sync-payment', ['--pending' => true]);

        $this->assertSame(70, (int) Wallet::query()->where('user_id', $buyer->id)->firstOrFail()->balance);
        $this->assertSame(1, PaymentEvent::query()->where('payment_id', $payment->id)->count());
    }

    public function test_command_sweep_dry_run_does_not_confirm(): void
    {
        $this->fakePagBankMixed();

        $paid = $this->startGuestPayment('sweep.dry-run');

        Artisan::call('pagbank:sync-payment', ['--pending' => true, '--dry-run' => true]);

        $this->assertSame(PaymentStatus::AwaitingPayment->value, $paid->refresh()->status);
        $this->assertSame(0, PaymentEvent::query()->where('payment_id', $paid->id)->count());

        // `Artisan::output()` consome o buffer: uma leitura só.
        $output = Artisan::output();
        $this->assertStringContainsString('provedor: paid', $output);
        $this->assertStringContainsString('dry-run', $output);
    }

    public function test_command_without_argument_requires_a_choice(): void
    {
        $exit = Artisan::call('pagbank:sync-payment');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--pending', Artisan::output());
    }

    public function test_command_sweep_rejects_force_and_a_specific_payment(): void
    {
        $this->assertSame(1, Artisan::call('pagbank:sync-payment', ['payment' => '1', '--pending' => true]));
        $this->assertStringContainsString('não os dois', Artisan::output());

        $this->assertSame(1, Artisan::call('pagbank:sync-payment', ['--pending' => true, '--force' => true]));
        $this->assertStringContainsString('--force vale para um pagamento específico', Artisan::output());
    }

    public function test_command_sweep_reports_when_there_is_nothing_to_sync(): void
    {
        $exit = Artisan::call('pagbank:sync-payment', ['--pending' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Nenhum pagamento PagBank aguardando confirmação.', Artisan::output());
    }

    /**
     * Botão **Sincronizar pendentes** do painel: mesma varredura do comando,
     * com resumo no flash da sessão.
     */
    public function test_admin_can_sweep_all_pending_payments_from_the_panel(): void
    {
        $this->fakePagBankMixed();

        $admin = User::factory()->admin()->create();
        $paid = $this->startGuestPayment('painel.varredura');

        $this->actingAs($admin)
            ->post(route('admin.payments.sync-pending'))
            ->assertRedirect()
            ->assertSessionHas('status', fn (mixed $message): bool => str_contains((string) $message, '1 confirmado(s) agora'));

        $this->assertSame(PaymentStatus::Paid->value, $paid->refresh()->status);

        $this->actingAs($admin)
            ->get(route('admin.payments.index'))
            ->assertOk()
            ->assertSee('Sincronizar pendentes')
            ->assertSee(route('admin.payments.sync-pending'), escape: false);
    }

    public function test_sweep_is_restricted_to_admins(): void
    {
        // Sem sessão: o grupo `auth` manda para o login.
        $this->post(route('admin.payments.sync-pending'))->assertRedirect(route('login'));

        // Autenticado, mas sem privilégio de administrador: 403.
        $this->actingAs(User::factory()->create())
            ->post(route('admin.payments.sync-pending'))
            ->assertForbidden();
    }
}
