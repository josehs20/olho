<?php

namespace Tests\Feature;

use App\Models\Investigation;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\SourceRun;
use App\Payments\PaymentSettings;
use App\Payments\PaymentStatus;
use App\Services\SettingsService;
use Database\Seeders\CreditPackageSeeder;
use Database\Seeders\SourceDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Prompt 45 — jornada avulsa com PagBank: checkout, webhook assinado,
 * liberação só após confirmação, idempotência e rejeição de manipulação.
 */
class GuestPagBankPaymentTest extends TestCase
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

        config(['queue.default' => 'sync', 'cache.default' => 'array']);

        [$this->publicKey, $this->signer] = $this->ecKeys();

        app(SettingsService::class)->setMany([
            PaymentSettings::KEY_PROVIDER => 'pagbank',
            PaymentSettings::KEY_ENABLED => '1',
            PaymentSettings::KEY_ENVIRONMENT => 'sandbox',
            PaymentSettings::KEY_PAGBANK_TOKEN => 'sandbox-token',
            PaymentSettings::KEY_METHOD_PIX => '1',
            PaymentSettings::KEY_METHOD_DEBIT => '1',
            PaymentSettings::KEY_METHOD_CREDIT => '1',
            PaymentSettings::KEY_CREDIT_MAX_INSTALLMENTS => '6',
        ]);

        Http::fake([
            'sandbox.api.pagseguro.com/checkouts' => Http::response([
                'id' => 'CHEC_GUEST',
                'status' => 'ACTIVE',
                'expiration_date' => '2030-10-01T12:00:00-03:00',
                'links' => [
                    ['rel' => 'PAY', 'href' => 'https://pagamento.pagseguro.uol.com.br/pagamento?code=guest', 'method' => 'GET'],
                ],
            ], 200),
            'sandbox.api.pagseguro.com/public-keys*' => Http::response(['public_key' => $this->publicKey], 200),
            'sandbox.api.pagseguro.com/checkouts/CHEC_GUEST' => Http::response([
                'id' => 'CHEC_GUEST',
                'status' => 'ACTIVE',
                'charges' => [['id' => 'CHARGE_GUEST', 'status' => 'PAID']],
            ], 200),
        ]);
    }

    /**
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

    private function startGuest(string $username): Payment
    {
        return $this->startGuestWithToken($username)[0];
    }

    /**
     * Mesma jornada, com o token em claro da página pública — a única credencial
     * do comprador (o banco guarda só o hash), necessária para conferir a tela
     * que ele vê depois de começar o pagamento.
     *
     * @return array{0: Payment, 1: string}
     */
    private function startGuestWithToken(string $username): array
    {
        // O formulário público pede apenas o alvo: quem paga se identifica na
        // página hospedada do provedor.
        $response = $this->post(route('guest.investigations.store'), [
            'username' => $username,
        ]);
        $response->assertRedirect();

        $payment = Investigation::query()->latest('id')->firstOrFail()->guestPayment;
        $token = basename((string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));

        return [$payment, $token];
    }

    public function test_guest_checkout_creates_pagbank_checkout_without_executing(): void
    {
        $payment = $this->startGuest('pagbank.novo');

        $this->assertSame('pagbank', $payment->provider);
        $this->assertSame('CHEC_GUEST', $payment->provider_checkout_id);
        $this->assertNotNull($payment->checkout_url);
        $this->assertSame(PaymentStatus::AwaitingPayment->value, $payment->status);
        $this->assertSame(0, SourceRun::query()->count());
    }

    /**
     * A página pública é o único acesso do comprador à investigação: o botão do
     * PagBank abre em **outra aba** para ele não perder a tela (nem o link, que
     * depende do token) quando o pagamento sai do OLHO.
     */
    public function test_guest_page_opens_the_pagbank_checkout_in_another_tab(): void
    {
        [$payment, $token] = $this->startGuestWithToken('pagbank.nova.aba');

        $this->assertNotNull($payment->checkout_url);

        $this->get(route('guest.investigations.show', ['token' => $token]))
            ->assertOk()
            ->assertSee('href="'.$payment->checkout_url.'"', escape: false)
            ->assertSee('target="_blank"', escape: false)
            ->assertSee('rel="noopener noreferrer"', escape: false);
    }

    public function test_signed_webhook_confirms_payment_and_releases_investigation(): void
    {
        $payment = $this->startGuest('pagbank.pago');
        $investigation = $payment->guestInvestigation()->firstOrFail();

        $body = json_encode(['id' => 'CHEC_GUEST']);

        $this->withHeaders(['x-payload-signature' => ($this->signer)($body)])
            ->postJson(route('payments.webhook.pagbank'), ['id' => 'CHEC_GUEST'])
            ->assertOk();

        $this->assertSame(PaymentStatus::Paid->value, $payment->refresh()->status);
        $this->assertContains($investigation->refresh()->status, [Investigation::STATUS_COMPLETED, Investigation::STATUS_PARTIAL]);
        $this->assertGreaterThan(0, SourceRun::query()->count());
    }

    public function test_repeated_webhooks_are_idempotent(): void
    {
        $payment = $this->startGuest('pagbank.dup');
        $body = json_encode(['id' => 'CHEC_GUEST']);
        $signature = ($this->signer)($body);

        foreach ([1, 2, 3] as $ignored) {
            $this->withHeaders(['x-payload-signature' => $signature])
                ->postJson(route('payments.webhook.pagbank'), ['id' => 'CHEC_GUEST'])
                ->assertOk();
        }

        $this->assertSame(1, PaymentEvent::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(PaymentStatus::Paid->value, $payment->refresh()->status);
    }

    public function test_webhook_without_valid_signature_does_not_release(): void
    {
        $payment = $this->startGuest('pagbank.fraude');

        // Ataque: forjar status=PAID sem assinatura válida.
        $this->postJson(route('payments.webhook.pagbank'), ['id' => 'CHEC_GUEST', 'status' => 'PAID'])
            ->assertStatus(400);

        $this->assertSame(PaymentStatus::AwaitingPayment->value, $payment->refresh()->status);
        $this->assertSame(0, SourceRun::query()->count());
    }

    /**
     * O formulário público **não** pede dados de quem paga, nem com o PagBank
     * ativo: é a página hospedada do provedor que identifica o comprador.
     */
    public function test_guest_form_does_not_ask_for_payer_data_even_with_pagbank_active(): void
    {
        $this->get(route('guest.investigations.create'))
            ->assertOk()
            ->assertDontSee('Seus dados (quem paga)')
            ->assertDontSee('CPF ou CNPJ (pagador)')
            ->assertDontSee('payer_tax_id');
    }

    /**
     * Sem documento nenhum a avulsa é criada e o checkout hospedado abre: quem
     * coleta nome e CPF/CNPJ é a página do PagBank.
     */
    public function test_guest_checkout_does_not_require_payer_data_with_pagbank_active(): void
    {
        $payment = $this->startGuest('pagbank.sem-documento');

        $this->assertSame('pagbank', $payment->provider);
        $this->assertSame('CHEC_GUEST', $payment->provider_checkout_id);
        $this->assertSame(1, Investigation::query()->count());
        $this->assertSame(1, Payment::query()->count());
    }

    /**
     * O checkout da avulsa sai **sem** `customer`: nome e CPF/CNPJ são digitados
     * na página do PagBank (daí o `customer_modifiable`), então não passam nem
     * pelo formulário nem pelo pedido.
     */
    public function test_guest_checkout_sends_no_buyer_data_to_pagbank(): void
    {
        $payment = $this->startGuest('pagbank.pagador');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://sandbox.api.pagseguro.com/checkouts'
                && ($body['customer_modifiable'] ?? null) === true
                && ! array_key_exists('customer', $body);
        });

        // Minimização de dados: o pedido não guarda nada do comprador.
        $this->assertArrayNotHasKey('customer', (array) data_get($payment->metadata, 'pagbank', []));
    }

    /**
     * Recusa do provedor na avulsa: o visitante recebe o que fazer (nunca um
     * `500`) e nenhum pedido é criado — o diagnóstico fica no log e no painel.
     */
    public function test_rejected_guest_checkout_is_reported_without_an_order(): void
    {
        // Os fakes acumulam entre si e o **primeiro** stub que casa vence: para
        // simular a recusa aqui, o cliente HTTP do teste começa limpo.
        Http::swap(new Factory);

        Http::fake([
            'sandbox.api.pagseguro.com/checkouts' => Http::response([
                'error_messages' => [['error' => 'invalid_value', 'parameter_name' => 'payment_methods[0].type']],
            ], 400),
        ]);

        $this->from(route('guest.investigations.create'))
            ->post(route('guest.investigations.store'), ['username' => 'pagbank.recusado'])
            ->assertRedirect(route('guest.investigations.create'))
            ->assertSessionHasErrors('payment');

        $this->assertSame(0, Payment::query()->count());

        $this->assertStringContainsString(
            'invalid_value em payment_methods[0].type',
            (string) app(PaymentSettings::class)->lastCheckoutFailure(),
        );
    }
}
