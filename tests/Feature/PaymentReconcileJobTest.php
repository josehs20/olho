<?php

namespace Tests\Feature;

use App\Jobs\ReconcilePaymentJob;
use App\Models\CreditPackage;
use App\Models\Investigation;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\SourceRun;
use App\Models\User;
use App\Models\Wallet;
use App\Payments\PaymentSettings;
use App\Payments\PaymentStatus;
use App\Services\PaymentService;
use App\Services\SettingsService;
use Database\Seeders\CreditPackageSeeder;
use Database\Seeders\SourceDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Ajustes pós-etapa 47 — webhook que chega e **não conclui**.
 *
 * A notificação autenticada que não consegue confirmar (erro na reconsulta ao
 * provedor ou status ainda inconclusivo) agenda a verificação no worker
 * (`ReconcilePaymentJob`); o botão **Revalidar** do painel faz o mesmo quando o
 * operador decide. O worker reconsulta o PagBank e libera créditos ou a
 * investigação avulsa pelo caminho idempotente de `PaymentService::reconcile()`
 * — nunca por conta do payload.
 */
class PaymentReconcileJobTest extends TestCase
{
    use RefreshDatabase;

    private string $publicKey;

    /** @var callable(string): string */
    private $signer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SourceDefinitionSeeder::class);
        $this->seed(CreditPackageSeeder::class);

        [$this->publicKey, $this->signer] = $this->ecKeys();

        app(SettingsService::class)->setMany([
            PaymentSettings::KEY_PROVIDER => PaymentSettings::PROVIDER_PAGBANK,
            PaymentSettings::KEY_ENABLED => '1',
            PaymentSettings::KEY_ENVIRONMENT => 'sandbox',
            PaymentSettings::KEY_PAGBANK_TOKEN => 'sandbox-token',
            PaymentSettings::KEY_METHOD_PIX => '1',
            PaymentSettings::KEY_METHOD_DEBIT => '0',
            PaymentSettings::KEY_METHOD_CREDIT => '1',
            PaymentSettings::KEY_CREDIT_MAX_INSTALLMENTS => '6',
        ]);

        $this->fakePagBank(paid: false);
    }

    /**
     * Par de chaves EC do teste: assina o corpo como o PagBank assina a
     * notificação (`x-payload-signature`, ECDSA/SHA-256).
     *
     * @return array{0: string, 1: callable(string): string}
     */
    private function ecKeys(): array
    {
        $resource = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        openssl_pkey_export($resource, $privatePem);
        $details = openssl_pkey_get_details($resource);

        $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', (string) $details['key']));

        $signer = function (string $body) use ($privatePem): string {
            openssl_sign($body, $signature, $privatePem, OPENSSL_ALGO_SHA256);

            return base64_encode($signature);
        };

        return [base64_encode($der), $signer];
    }

    /**
     * Checkout (`POST /checkouts`), chave pública do webhook e a consulta
     * (`GET /checkouts/CHEC_JOB`) — que é quem decide o estado visto pela
     * verificação.
     */
    private function fakePagBank(bool $paid): void
    {
        // `Http::swap` descarta os stubs anteriores: `Http::fake()` **acumula** e o
        // primeiro stub de cada URL vence, então sem a troca o estado `paid`
        // pedido depois da criação do checkout seria ignorado.
        Http::swap(new Factory);

        Http::fake([
            'sandbox.api.pagseguro.com/checkouts' => Http::response([
                'id' => 'CHEC_JOB',
                'status' => 'ACTIVE',
                'expiration_date' => '2030-10-01T12:00:00-03:00',
                'links' => [
                    ['rel' => 'PAY', 'href' => 'https://pagamento.pagseguro.uol.com.br/pagamento?code=job', 'method' => 'GET'],
                ],
            ], 200),
            'sandbox.api.pagseguro.com/public-keys*' => Http::response(['public_key' => $this->publicKey], 200),
            'sandbox.api.pagseguro.com/checkouts/CHEC_JOB' => Http::response([
                'id' => 'CHEC_JOB',
                'status' => $paid ? 'PAID' : 'ACTIVE',
                'charges' => [[
                    'id' => 'CHAR_JOB',
                    'status' => $paid ? 'PAID' : 'WAITING',
                    'payment_method' => ['type' => 'PIX'],
                ]],
            ], 200),
        ]);
    }

    /**
     * Provedor instável no momento da notificação: a reconsulta devolve 5xx.
     */
    private function fakePagBankUnavailable(): void
    {
        Http::swap(new Factory);

        Http::fake([
            'sandbox.api.pagseguro.com/public-keys*' => Http::response(['public_key' => $this->publicKey], 200),
            'sandbox.api.pagseguro.com/checkouts/CHEC_JOB' => Http::response(['error' => 'unavailable'], 503),
        ]);
    }

    /**
     * @return array{0: User, 1: Payment}
     */
    private function createAccountPayment(int $credits = 120): array
    {
        $user = User::factory()->create();
        $package = CreditPackage::factory()->create(['credits' => $credits, 'price_cents' => 4990]);

        return [$user, app(PaymentService::class)->checkout($user, $package)];
    }

    /**
     * Jornada avulsa: cria a investigação sem conta e devolve o pedido PagBank.
     */
    private function createGuestPayment(string $username): Payment
    {
        $this->post(route('guest.investigations.store'), ['username' => $username])->assertRedirect();

        return Investigation::query()->latest('id')->firstOrFail()->guestPayment()->firstOrFail();
    }

    /**
     * Notificação autenticada (mesmo corpo assinado enviado ao provedor).
     *
     * @param  array<string, mixed>  $payload
     */
    private function signedWebhook(array $payload): TestResponse
    {
        $body = json_encode($payload);

        return $this->withHeaders(['x-payload-signature' => ($this->signer)($body)])
            ->postJson(route('payments.webhook.pagbank'), $payload);
    }

    /**
     * Executa o job como o worker faria (uma passada).
     */
    private function runJob(Payment $payment, string $reason = 'test'): void
    {
        (new ReconcilePaymentJob($payment->id, $reason))->handle(app(PaymentService::class));
    }

    /**
     * Notificação que chega e **não** libera nada (o provedor ainda não devolveu
     * cobrança conclusiva): o worker é acionado para conferir de novo.
     */
    public function test_webhook_that_cannot_confirm_schedules_the_worker(): void
    {
        [, $payment] = $this->createAccountPayment();

        Queue::fake();

        $this->signedWebhook(['id' => 'CHEC_JOB'])->assertOk();

        Queue::assertPushed(ReconcilePaymentJob::class, fn (ReconcilePaymentJob $job): bool => $job->paymentId === $payment->id
            && $job->reason === 'webhook-inconclusive'
            && $job->delay !== null);

        $this->assertSame(PaymentStatus::AwaitingPayment->value, $payment->refresh()->status);
        $this->assertSame(0, Wallet::query()->count());
    }

    /**
     * Erro ao consultar o provedor na rota do webhook (5xx/429/rede): a resposta
     * também é 5xx — o PagBank reentrega a notificação — e o pedido é agendado no
     * worker, para não depender apenas da reentrega.
     */
    public function test_webhook_error_schedules_the_worker_and_keeps_the_payment_pending(): void
    {
        [, $payment] = $this->createAccountPayment();

        $this->fakePagBankUnavailable();

        Queue::fake();

        $this->signedWebhook(['id' => 'CHEC_JOB'])->assertStatus(500);

        Queue::assertPushed(ReconcilePaymentJob::class, fn (ReconcilePaymentJob $job): bool => $job->paymentId === $payment->id
            && $job->reason === 'webhook-error');

        $this->assertSame(PaymentStatus::AwaitingPayment->value, $payment->refresh()->status);
        $this->assertSame(0, PaymentEvent::query()->count());
        $this->assertSame(0, Wallet::query()->count());
    }

    /**
     * Notificação de um id que não é de pedido do OLHO: nada é agendado (não se
     * inventa pedido a partir do payload) — só o registro para o operador.
     */
    public function test_webhook_error_for_an_unknown_reference_does_not_schedule_anything(): void
    {
        Http::swap(new Factory);

        Http::fake([
            'sandbox.api.pagseguro.com/public-keys*' => Http::response(['public_key' => $this->publicKey], 200),
            'sandbox.api.pagseguro.com/checkouts/CHEC_DESCONHECIDO' => Http::response(['error' => 'unavailable'], 503),
        ]);

        Queue::fake();

        $this->signedWebhook(['id' => 'CHEC_DESCONHECIDO'])->assertStatus(500);

        Queue::assertNothingPushed();
    }

    /**
     * O worker confirma o checkout pago e credita uma única vez (idempotente).
     */
    public function test_worker_confirms_a_paid_checkout_and_grants_credits_once(): void
    {
        [$user, $payment] = $this->createAccountPayment(credits: 120);

        $this->fakePagBank(paid: true);

        $this->runJob($payment);

        $this->assertSame(PaymentStatus::Paid->value, $payment->refresh()->status);
        $this->assertSame(120, (int) Wallet::query()->where('user_id', $user->id)->firstOrFail()->balance);
        $this->assertSame(1, PaymentEvent::query()->where('payment_id', $payment->id)->count());
        $this->assertTrue(PaymentEvent::query()->where('external_event_id', 'reconcile-paid-CHEC_JOB')->exists());

        // Repetir a verificação (notificação reentregue, segundo clique, retorno do
        // comprador) não credita de novo: o pedido já consta pago e o evento é o
        // mesmo (`reconcile-paid-CHEC_JOB`).
        $this->runJob($payment);

        $this->assertSame(120, (int) Wallet::query()->where('user_id', $user->id)->firstOrFail()->balance);
        $this->assertSame(1, PaymentEvent::query()->where('payment_id', $payment->id)->count());
    }

    /**
     * Jornada avulsa: o worker libera a investigação (nenhum crédito envolvido).
     */
    public function test_worker_releases_a_paid_guest_investigation(): void
    {
        $payment = $this->createGuestPayment('job.avulsa');
        $investigation = $payment->guestInvestigation()->firstOrFail();

        $this->fakePagBank(paid: true);

        $this->runJob($payment);

        $this->assertSame(PaymentStatus::Paid->value, $payment->refresh()->status);
        $this->assertContains($investigation->refresh()->status, [Investigation::STATUS_COMPLETED, Investigation::STATUS_PARTIAL]);
        $this->assertGreaterThan(0, SourceRun::query()->count());
    }

    /**
     * Pedido já pago ou de outro provedor: o job não gasta chamada à API.
     */
    public function test_worker_skips_payments_that_have_nothing_to_verify(): void
    {
        [, $paid] = $this->createAccountPayment();
        $paid->update(['status' => PaymentStatus::Paid->value, 'paid_at' => now()]);

        Http::swap(new Factory);
        Http::fake();

        $this->runJob($paid);

        $this->assertSame(PaymentStatus::Paid->value, $paid->refresh()->status);

        // Provedor trocado no painel depois do pedido (ou cobrança sem `external_id`).
        $other = Payment::factory()->create([
            'provider' => 'fake',
            'status' => PaymentStatus::AwaitingPayment->value,
            'external_id' => 'FAKE_1',
        ]);

        $this->runJob($other);

        $this->assertSame(PaymentStatus::AwaitingPayment->value, $other->refresh()->status);
        Http::assertNothingSent();
    }

    /**
     * Botão **Revalidar** da coluna Ações: agenda a verificação do pedido no worker.
     */
    public function test_admin_schedules_the_verification_of_a_single_payment(): void
    {
        $admin = User::factory()->admin()->create();
        [, $payment] = $this->createAccountPayment();

        Queue::fake();

        $this->actingAs($admin)
            ->postJson(route('admin.payments.revalidate', $payment))
            ->assertOk()
            ->assertJson(['payment_id' => $payment->id, 'queued' => true]);

        Queue::assertPushed(ReconcilePaymentJob::class, fn (ReconcilePaymentJob $job): bool => $job->paymentId === $payment->id
            && $job->reason === 'admin');
    }

    public function test_revalidate_requires_an_admin(): void
    {
        [, $payment] = $this->createAccountPayment();

        $this->post(route('admin.payments.revalidate', $payment))->assertRedirect(route('login'));

        // Autenticado, mas sem privilégio de administrador: 403.
        $this->actingAs(User::factory()->create())
            ->postJson(route('admin.payments.revalidate', $payment))
            ->assertForbidden();
    }

    public function test_revalidate_rejects_payments_without_a_pagbank_charge(): void
    {
        $admin = User::factory()->admin()->create();

        $other = Payment::factory()->create(['provider' => 'fake', 'external_id' => 'FAKE_2']);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.revalidate', $other))
            ->assertStatus(422)
            ->assertJson(['payment_id' => $other->id]);

        // PagBank, mas cobrança nunca criada no provedor: não há o que consultar.
        $withoutCharge = Payment::factory()->create([
            'provider' => PaymentSettings::PROVIDER_PAGBANK,
            'status' => PaymentStatus::Pending->value,
            'external_id' => null,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.payments.revalidate', $withoutCharge))
            ->assertStatus(422);
    }

    /**
     * Botão **Revalidar pendentes**: mesma varredura em lote, pela fila.
     */
    public function test_admin_schedules_the_verification_of_all_pending_payments(): void
    {
        $admin = User::factory()->admin()->create();

        [, $first] = $this->createAccountPayment();

        // Segundo pendente sem passar pelo checkout: o stub devolve sempre o mesmo
        // `CHEC_JOB`, e `payments.external_id` é único.
        $second = Payment::factory()->create([
            'provider' => PaymentSettings::PROVIDER_PAGBANK,
            'status' => PaymentStatus::AwaitingPayment->value,
            'external_id' => 'CHEC_SEGUNDO',
        ]);

        $paid = Payment::factory()->create([
            'provider' => PaymentSettings::PROVIDER_PAGBANK,
            'status' => PaymentStatus::Paid->value,
            'external_id' => 'CHEC_JA_PAGO',
        ]);

        Queue::fake();

        $this->actingAs($admin)
            ->post(route('admin.payments.revalidate-pending'))
            ->assertRedirect()
            ->assertSessionHas('status', fn (mixed $message): bool => str_contains((string) $message, '2 verificação(ões) agendada(s)'));

        Queue::assertPushed(ReconcilePaymentJob::class, fn (ReconcilePaymentJob $job): bool => $job->paymentId === $first->id && $job->reason === 'admin-bulk');
        Queue::assertPushed(ReconcilePaymentJob::class, fn (ReconcilePaymentJob $job): bool => $job->paymentId === $second->id && $job->reason === 'admin-bulk');
        Queue::assertNotPushed(ReconcilePaymentJob::class, fn (ReconcilePaymentJob $job): bool => $job->paymentId === $paid->id);
        Queue::assertPushed(ReconcilePaymentJob::class, 2);
    }

    /**
     * Painel: o botão aparece para pedidos PagBank pendentes, e o do topo sempre.
     */
    public function test_payments_index_shows_the_revalidate_action(): void
    {
        $admin = User::factory()->admin()->create();

        [, $pending] = $this->createAccountPayment();

        $paid = Payment::factory()->create([
            'provider' => PaymentSettings::PROVIDER_PAGBANK,
            'status' => PaymentStatus::Paid->value,
            'external_id' => 'CHEC_PAGO',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.payments.index'))->assertOk();

        $response->assertSee(route('admin.payments.revalidate', $pending), escape: false);
        $response->assertSee(route('admin.payments.revalidate-pending'), escape: false);
        // Pedido já pago não tem o que revalidar.
        $response->assertDontSee(route('admin.payments.revalidate', $paid), escape: false);
    }

    /**
     * O botão **Sincronizar** (formulário HTML) mostra o resultado na própria
     * lista, em vez de jogar o operador na resposta JSON.
     */
    public function test_sync_button_shows_the_result_on_the_page(): void
    {
        $admin = User::factory()->admin()->create();
        [, $payment] = $this->createAccountPayment();

        $this->actingAs($admin)
            ->post(route('admin.payments.sync', $payment))
            ->assertRedirect()
            ->assertSessionHas('status', fn (mixed $message): bool => str_contains((string) $message, 'segue pendente'));

        $this->fakePagBank(paid: true);

        $this->actingAs($admin)
            ->post(route('admin.payments.sync', $payment))
            ->assertRedirect()
            ->assertSessionHas('status', fn (mixed $message): bool => str_contains((string) $message, 'confirmado no PagBank'));
    }
}
