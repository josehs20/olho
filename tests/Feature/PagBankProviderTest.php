<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Payments\PagBank\PagBankException;
use App\Payments\PagBankProvider;
use App\Payments\PaymentSettings;
use App\Payments\PaymentStatus;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Prompt 45 — provedor PagBank (Checkout) isolado, sem internet (Http::fake).
 */
class PagBankProviderTest extends TestCase
{
    use RefreshDatabase;

    private function provider(): PagBankProvider
    {
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

        return app(PagBankProvider::class);
    }

    private function payment(): Payment
    {
        return Payment::query()->create([
            'provider' => 'pagbank',
            'amount_cents' => 2990,
            'status' => PaymentStatus::Pending->value,
            'idempotency_key' => 'pagbank-test-'.Str::uuid(),
        ]);
    }

    /**
     * @return array{0: string, 1: callable(string): string} base64 da chave pública + assinador
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

    public function test_create_checkout_returns_pay_link(): void
    {
        Http::fake([
            'sandbox.api.pagseguro.com/checkouts' => Http::response([
                'id' => 'CHEC_1',
                'status' => 'ACTIVE',
                'expiration_date' => '2026-10-01T12:00:00-03:00',
                'links' => [
                    ['rel' => 'SELF', 'href' => 'https://sandbox.api.pagseguro.com/checkouts/CHEC_1', 'method' => 'GET'],
                    ['rel' => 'PAY', 'href' => 'https://pagamento.pagseguro.uol.com.br/pagamento?code=abc', 'method' => 'GET'],
                ],
            ], 200),
        ]);

        $payment = $this->payment();

        $session = $this->provider()->createCheckout($payment, [
            'methods' => ['PIX', 'DEBIT_CARD', 'CREDIT_CARD'],
            'max_installments' => 6,
        ]);

        $this->assertSame('CHEC_1', $session->externalId);
        $this->assertStringContainsString('pagamento.pagseguro.uol.com.br', $session->redirectUrl);

        Http::assertSent(fn ($request) => $request->url() === 'https://sandbox.api.pagseguro.com/checkouts'
            && $request->hasHeader('Authorization', 'Bearer sandbox-token')
            && $request['reference_id'] === 'olho-'.$payment->id
            && $request['items'][0]['unit_amount'] === 2990
            && array_column($request['payment_methods'], 'type') === ['PIX', 'DEBIT_CARD', 'CREDIT_CARD']
            // Parcelamento vai em payment_methods_configs (contrato PagBank).
            && ! isset($request['payment_methods'][2]['installments'])
            && $request['payment_methods_configs'][0]['type'] === 'CREDIT_CARD'
            && $request['payment_methods_configs'][0]['config_options'][0]['option'] === 'INSTALLMENTS_LIMIT'
            && $request['payment_methods_configs'][0]['config_options'][0]['value'] === '6'
            // APP_URL de desenvolvimento (`http://localhost`): o PagBank **valida**
            // toda URL do payload e recusa o checkout com `400 invalid_value` em
            // `redirect_url`/`return_url` (foi o que travou o teste manual) — sem
            // endereço público, notificações e URLs de retorno ficam de fora.
            && ! array_key_exists('notification_urls', $request->data())
            && ! array_key_exists('payment_notification_urls', $request->data())
            && ! array_key_exists('redirect_url', $request->data())
            && ! array_key_exists('return_url', $request->data()));
    }

    /**
     * Com URL pública HTTPS o payload leva a notificação, as URLs de retorno do
     * checkout e **nenhum** dado de quem paga.
     *
     * É o contrato que sustenta a remoção da coleta de CPF/CNPJ: o checkout sai
     * com `customer_modifiable: true` e é a **página hospedada do PagBank** que
     * identifica o comprador. Tentar montar o `customer` aqui era justamente o
     * que devolvia `400 invalid_value em customer.tax_id`.
     */
    public function test_create_checkout_sends_public_webhook_urls_without_buyer_data(): void
    {
        // App publicado em HTTPS: o webhook passa a ser alcançável pelo PagBank.
        URL::forceRootUrl('https://olho.test');
        URL::forceScheme('https');

        Http::fake([
            'sandbox.api.pagseguro.com/checkouts' => Http::response([
                'id' => 'CHEC_SEM_COMPRADOR',
                'status' => 'ACTIVE',
                'links' => [['rel' => 'PAY', 'href' => 'https://pagamento.pagseguro.uol.com.br/pagamento?code=abc']],
            ], 200),
        ]);

        $this->provider()->createCheckout($this->payment(), ['methods' => ['PIX', 'CREDIT_CARD']]);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $data['notification_urls'] === ['https://olho.test/webhooks/payments/pagbank']
                && $data['payment_notification_urls'] === $data['notification_urls']
                // As URLs de retorno voltam ao payload só com endereço público: é
                // exatamente o par que o PagBank recusa (`invalid_value`) quando
                // aponta para `http://localhost...`.
                && str_starts_with($data['redirect_url'], 'https://olho.test/payments/return/')
                && $data['return_url'] === $data['redirect_url']
                // Quem informa os dados de quem paga é a página do PagBank.
                && $data['customer_modifiable'] === true
                && ! array_key_exists('customer', $data);
        });
    }

    public function test_installments_limit_is_sent_only_for_credit_above_one(): void
    {
        Http::fake([
            'sandbox.api.pagseguro.com/checkouts' => Http::response([
                'id' => 'CHEC_2',
                'status' => 'ACTIVE',
                'links' => [['rel' => 'PAY', 'href' => 'https://pagamento.pagseguro.uol.com.br/pagamento?code=abc']],
            ], 200),
        ]);

        $this->provider()->createCheckout($this->payment(), [
            'methods' => ['PIX', 'DEBIT_CARD', 'CREDIT_CARD'],
            'max_installments' => 1,
        ]);

        // Sem parcelamento: nenhuma configuração é enviada em vez de enviar um
        // valor inválido (débito nunca parcela).
        Http::assertSent(fn ($request) => ! array_key_exists('payment_methods_configs', $request->data()));

        Http::fake(); // Reinicia os stubs acumulados antes do próximo cenário.

        Http::fake([
            'sandbox.api.pagseguro.com/checkouts' => Http::response([
                'id' => 'CHEC_3',
                'status' => 'ACTIVE',
                'links' => [['rel' => 'PAY', 'href' => 'https://pagamento.pagseguro.uol.com.br/pagamento?code=abc']],
            ], 200),
        ]);

        $this->provider()->createCheckout($this->payment(), [
            'methods' => ['PIX', 'DEBIT_CARD'],
            'max_installments' => 12,
        ]);

        Http::assertSent(fn ($request) => ! array_key_exists('payment_methods_configs', $request->data()));
    }

    /**
     * A recusa `400` do PagBank traz `error_messages` com o código e o parâmetro
     * recusado: esse diagnóstico é a diferença entre um erro opaco ("revise o
     * token") e a causa real — e fica registrado para o operador no painel.
     */
    public function test_rejected_checkout_surfaces_pagbank_diagnostics(): void
    {
        Log::spy();

        Http::fake([
            'sandbox.api.pagseguro.com/checkouts' => Http::response([
                'error_messages' => [[
                    'error' => 'invalid_value',
                    'description' => 'Field has an invalid value. Please check the documentation.',
                    'parameter_name' => 'payment_methods[0].type',
                ]],
            ], 400),
        ]);

        try {
            $this->provider()->createCheckout($this->payment(), ['methods' => ['PIX', 'CREDIT_CARD']]);
            $this->fail('A recusa do PagBank deveria lançar PagBankException.');
        } catch (PagBankException $exception) {
            $this->assertSame(400, $exception->getCode());
            $this->assertSame(
                [['error' => 'invalid_value', 'parameter' => 'payment_methods[0].type']],
                $exception->diagnostics(),
            );
            $this->assertStringContainsString('invalid_value em payment_methods[0].type', $exception->getMessage());
            // A orientação antiga apontava para o token, que aqui estava correto.
            $this->assertStringNotContainsString('revise o token', $exception->getMessage());
            // O corpo cru nunca é ecoado (pode carregar dados do comprador).
            $this->assertStringNotContainsString('Please check the documentation', $exception->getMessage());
        }

        // O provedor registra o motivo no log (a gravação para o painel é feita
        // pelo `PaymentService`, depois do rollback da transação).
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $channel, array $context): bool => $channel === 'payment.pagbank.failed'
                && $context['status'] === 400
                && $context['diagnostics'][0]['error'] === 'invalid_value'
                && $context['diagnostics'][0]['parameter'] === 'payment_methods[0].type')
            ->once();
    }

    public function test_create_checkout_without_pay_link_throws(): void
    {
        Http::fake(['sandbox.api.pagseguro.com/checkouts' => Http::response(['id' => 'CHEC_x', 'links' => []], 200)]);

        $this->expectException(PagBankException::class);

        $this->provider()->createCheckout($this->payment(), ['methods' => ['PIX']]);
    }

    public function test_get_status_maps_paid(): void
    {
        Http::fake([
            'sandbox.api.pagseguro.com/checkouts/CHEC_1' => Http::response([
                'id' => 'CHEC_1',
                'status' => 'ACTIVE',
                'charges' => [['id' => 'CHARGE_1', 'status' => 'PAID']],
            ], 200),
        ]);

        $this->assertSame(PaymentStatus::Paid, $this->provider()->getStatus('CHEC_1'));
    }

    /**
     * Regressão do caso real do sandbox: depois do cartão aprovado,
     * `GET /checkouts/{id}` continua `ACTIVE` e **sem** `charges` — a cobrança
     * (`CHAR_...`, `PAID`) só existe no pedido referenciado em `orders[].id`.
     * Sem seguir essa referência, a sincronização ficava eternamente em
     * `awaiting_payment` e os créditos nunca eram liberados.
     */
    public function test_get_status_follows_the_checkout_orders_when_there_are_no_charges(): void
    {
        Http::fake([
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
                    'paid_at' => '2026-10-12T19:47:36.000-03:00',
                    'payment_method' => ['type' => 'CREDIT_CARD'],
                ]],
            ], 200),
        ]);

        $provider = $this->provider();

        $this->assertSame(PaymentStatus::Paid, $provider->getStatus('CHEC_ORDER'));
        // O método também vem do pedido quando o checkout não expõe a cobrança.
        $this->assertSame('CREDIT_CARD', $provider->getCheckoutMethod('CHEC_ORDER'));
    }

    /**
     * Pedido indisponível não inventa status: o checkout segue aguardando.
     */
    public function test_get_status_stays_awaiting_when_the_checkout_order_is_unreachable(): void
    {
        Http::fake([
            'sandbox.api.pagseguro.com/checkouts/CHEC_ORDER' => Http::response([
                'id' => 'CHEC_ORDER',
                'status' => 'ACTIVE',
                'orders' => [['id' => 'ORDE_1']],
            ], 200),
            'sandbox.api.pagseguro.com/orders/ORDE_1' => Http::response([], 404),
        ]);

        $this->assertSame(PaymentStatus::AwaitingPayment, $this->provider()->getStatus('CHEC_ORDER'));
    }

    /**
     * A notificação pode trazer apenas o id do checkout (sem `charges` no
     * payload): a confirmação vem do pedido do checkout, já autenticado.
     */
    public function test_webhook_confirms_via_the_checkout_order_when_the_payload_has_no_charges(): void
    {
        [$publicKey, $sign] = $this->ecKeys();

        $body = json_encode(['id' => 'CHEC_ORDER']);

        Http::fake([
            'sandbox.api.pagseguro.com/public-keys*' => Http::response(['public_key' => $publicKey], 200),
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
                    'payment_method' => ['type' => 'CREDIT_CARD'],
                ]],
            ], 200),
        ]);

        $event = $this->provider()->validateWebhook(
            ['id' => 'CHEC_ORDER'],
            ['x-payload-signature' => [$sign((string) $body)]],
            (string) $body,
        );

        $this->assertNotNull($event);
        $this->assertSame('CHEC_ORDER', $event->externalId);
        $this->assertSame(PaymentStatus::Paid->value, $event->status);
        $this->assertSame('CREDIT_CARD', $event->method);
    }

    public function test_webhook_with_valid_signature_is_confirmed_after_recheck(): void
    {
        [$publicKey, $sign] = $this->ecKeys();

        $body = json_encode(['id' => 'CHEC_1']);

        Http::fake([
            'sandbox.api.pagseguro.com/public-keys*' => Http::response(['public_key' => $publicKey], 200),
            'sandbox.api.pagseguro.com/checkouts/CHEC_1' => Http::response([
                'id' => 'CHEC_1',
                'status' => 'ACTIVE',
                'charges' => [['id' => 'CHARGE_1', 'status' => 'PAID']],
            ], 200),
        ]);

        $event = $this->provider()->validateWebhook(
            ['id' => 'CHEC_1'],
            ['x-payload-signature' => [$sign((string) $body)]],
            (string) $body,
        );

        $this->assertNotNull($event);
        $this->assertSame('CHEC_1', $event->externalId);
        $this->assertSame(PaymentStatus::Paid->value, $event->status);
    }

    public function test_webhook_consults_the_charge_when_checkout_has_no_charges(): void
    {
        [$publicKey, $sign] = $this->ecKeys();

        $payload = ['id' => 'CHEC_9', 'charges' => [['id' => 'CHAR_9']]];
        $body = json_encode($payload);

        Http::fake([
            'sandbox.api.pagseguro.com/public-keys*' => Http::response(['public_key' => $publicKey], 200),
            // Checkout sem `charges` (como no exemplo oficial) e já INACTIVE.
            'sandbox.api.pagseguro.com/checkouts/CHEC_9' => Http::response(['id' => 'CHEC_9', 'status' => 'INACTIVE'], 200),
            'sandbox.api.pagseguro.com/charges/CHAR_9' => Http::response(['id' => 'CHAR_9', 'status' => 'PAID'], 200),
        ]);

        $event = $this->provider()->validateWebhook(
            $payload,
            ['x-payload-signature' => [$sign((string) $body)]],
            (string) $body,
        );

        $this->assertNotNull($event);
        $this->assertSame('CHEC_9', $event->externalId);
        $this->assertSame(PaymentStatus::Paid->value, $event->status);
    }

    public function test_webhook_keeps_checkout_status_when_charge_is_unreachable(): void
    {
        [$publicKey, $sign] = $this->ecKeys();

        $payload = ['id' => 'CHEC_8', 'charge' => ['id' => 'CHAR_8', 'status' => 'WAITING']];
        $body = json_encode($payload);

        Http::fake([
            'sandbox.api.pagseguro.com/public-keys*' => Http::response(['public_key' => $publicKey], 200),
            'sandbox.api.pagseguro.com/checkouts/CHEC_8' => Http::response(['id' => 'CHEC_8', 'status' => 'EXPIRED'], 200),
            'sandbox.api.pagseguro.com/charges/CHAR_8' => Http::response([], 404),
        ]);

        $event = $this->provider()->validateWebhook(
            $payload,
            ['x-payload-signature' => [$sign((string) $body)]],
            (string) $body,
        );

        $this->assertNotNull($event);
        $this->assertSame(PaymentStatus::Expired->value, $event->status);
    }

    public function test_webhook_with_invalid_signature_is_rejected(): void
    {
        [$publicKey] = $this->ecKeys();

        Http::fake(['sandbox.api.pagseguro.com/public-keys*' => Http::response(['public_key' => $publicKey], 200)]);

        $event = $this->provider()->validateWebhook(
            ['id' => 'CHEC_1', 'status' => 'PAID'],
            ['x-payload-signature' => ['not-a-valid-signature']],
            '{"id":"CHEC_1","status":"PAID"}',
        );

        $this->assertNull($event);
    }

    /**
     * O método escolhido pelo comprador vem na cobrança: o evento verificado já
     * o carrega traduzido para a nomenclatura interna, e a consulta do checkout
     * também o expõe (usada pela página de retorno).
     */
    public function test_checkout_reports_the_payment_method(): void
    {
        [$publicKey, $sign] = $this->ecKeys();

        $body = json_encode(['id' => 'CHEC_7']);

        Http::fake([
            'sandbox.api.pagseguro.com/public-keys*' => Http::response(['public_key' => $publicKey], 200),
            'sandbox.api.pagseguro.com/checkouts/CHEC_7' => Http::response([
                'id' => 'CHEC_7',
                'status' => 'ACTIVE',
                'charges' => [[
                    'id' => 'CHAR_7',
                    'status' => 'PAID',
                    'payment_method' => ['type' => 'CREDIT_CARD'],
                ]],
            ], 200),
        ]);

        $provider = $this->provider();

        $event = $provider->validateWebhook(
            ['id' => 'CHEC_7'],
            ['x-payload-signature' => [$sign((string) $body)]],
            (string) $body,
        );

        $this->assertNotNull($event);
        $this->assertSame(PaymentStatus::Paid->value, $event->status);
        $this->assertSame('CREDIT_CARD', $event->method);
        $this->assertSame('CREDIT_CARD', $provider->getCheckoutMethod('CHEC_7'));
    }

    /**
     * Cobrança aceita limpa o diagnóstico anterior: o painel volta a mostrar o
     * estado atual, não uma falha já resolvida.
     */
    public function test_accepted_checkout_clears_the_previous_failure(): void
    {
        app(PaymentSettings::class)->rememberCheckoutFailure('falha anterior');

        Http::fake([
            'sandbox.api.pagseguro.com/checkouts' => Http::response([
                'id' => 'CHEC_5',
                'status' => 'ACTIVE',
                'links' => [['rel' => 'PAY', 'href' => 'https://pagamento.pagseguro.uol.com.br/pagamento?code=abc']],
            ], 200),
        ]);

        $this->provider()->createCheckout($this->payment(), ['methods' => ['PIX']]);

        $this->assertNull(app(PaymentSettings::class)->lastCheckoutFailure());
    }
}
