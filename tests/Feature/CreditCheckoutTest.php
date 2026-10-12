<?php

namespace Tests\Feature;

use App\Models\CreditPackage;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use App\Payments\PaymentMethod;
use App\Payments\PaymentSettings;
use App\Payments\PaymentStatus;
use App\Services\PaymentService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Escolha do canal de pagamento na tela de créditos (`/credits`).
 *
 * O canal (PIX ou cartão) é a **intenção declarada** na tela; quem confirma o
 * pagamento é sempre o provedor (webhook ou reconciliação). Nada é liberado pelo
 * navegador: a página de status apenas consulta o servidor.
 */
class CreditCheckoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Integração PagBank habilitada (token de teste, todos os métodos ligados).
     */
    private function enablePagBank(): void
    {
        app(SettingsService::class)->setMany([
            PaymentSettings::KEY_PROVIDER => PaymentSettings::PROVIDER_PAGBANK,
            PaymentSettings::KEY_ENABLED => '1',
            PaymentSettings::KEY_ENVIRONMENT => PaymentSettings::ENV_SANDBOX,
            PaymentSettings::KEY_PAGBANK_TOKEN => 'sandbox-token',
            PaymentSettings::KEY_METHOD_PIX => '1',
            PaymentSettings::KEY_METHOD_DEBIT => '1',
            PaymentSettings::KEY_METHOD_CREDIT => '1',
        ]);
    }

    private function package(int $credits = 100, int $priceCents = 4990): CreditPackage
    {
        return CreditPackage::factory()->create(['credits' => $credits, 'price_cents' => $priceCents]);
    }

    public function test_credits_page_offers_the_pix_button_with_the_fake_provider(): void
    {
        $package = $this->package();

        $this->actingAs(User::factory()->create())
            ->get('/credits')
            ->assertOk()
            ->assertSee('Comprar com PIX')
            // O botão é um POST direto para o provedor: sem modal e sem JavaScript.
            // O destino abre em outra aba (`target="_blank"`) para a tela de
            // créditos continuar à mão — quem paga volta e vê o saldo.
            ->assertSee('action="'.route('payments.store', $package).'"', escape: false)
            ->assertSee('target="_blank"', escape: false)
            ->assertSee('name="channel" value="pix"', escape: false)
            ->assertDontSee('pixCheckout')
            // O mock não tem checkout hospedado: cartão não é oferecido.
            ->assertDontSee('Comprar com cartão');
    }

    public function test_credits_page_offers_pix_and_card_when_the_hosted_checkout_is_configured(): void
    {
        $this->enablePagBank();
        $this->package();

        $response = $this->actingAs(User::factory()->create())
            ->get('/credits')
            ->assertOk()
            ->assertSee('Comprar com PIX')
            ->assertSee('Comprar com cartão')
            // A página avisa que o pagamento sai daqui: o comprador não perde a
            // tela de créditos (é para cá que ele volta depois de pagar).
            ->assertSee('O pagamento abre em outra aba');

        // Os dois botões abrem o provedor em outra aba.
        $this->assertSame(2, substr_count((string) $response->getContent(), 'target="_blank"'));
    }

    /**
     * PIX com o provedor mock (sem checkout hospedado): o botão é um POST simples e
     * a resposta é um redirecionamento para a página do pedido — é lá que o código
     * copia e cola aparece e o acompanhamento começa.
     */
    public function test_pix_choice_with_the_local_mock_points_to_the_order_page(): void
    {
        $user = User::factory()->create();
        $package = $this->package();

        $response = $this->actingAs($user)->post(route('payments.store', $package), [
            'channel' => 'pix',
        ]);

        $payment = Payment::query()->latest('id')->firstOrFail();

        $response->assertRedirect(route('payments.show', $payment));

        $this->assertSame(PaymentStatus::AwaitingPayment->value, $payment->status);
        $this->assertSame(PaymentMethod::Pix, $payment->method);
        $this->assertNotNull(data_get($payment->metadata, 'pix_payload'));
        // Nada é creditado antes da confirmação do provedor.
        $this->assertSame(0, Wallet::query()->where('user_id', $user->id)->count());
    }

    /**
     * Com o PagBank ativo o PIX também passa pelo **checkout hospedado**: o botão
     * leva o comprador direto para a página do provedor e nenhum dado de quem paga
     * sai daqui.
     *
     * É o que remove a coleta de CPF/CNPJ do OLHO: o payload sai com
     * `customer_modifiable: true` e o documento é informado na página do
     * provedor (era o `400 invalid_value em customer.tax_id` do fluxo antigo).
     */
    public function test_pix_choice_uses_the_hosted_checkout_when_pagbank_is_active(): void
    {
        $this->enablePagBank();

        Http::fake([
            'sandbox.api.pagseguro.com/checkouts' => Http::response([
                'id' => 'CHEC_PIX',
                'status' => 'ACTIVE',
                'links' => [['rel' => 'PAY', 'href' => 'https://pagamento.pagseguro.uol.com.br/pagamento?code=pix']],
            ], 200),
        ]);

        $user = User::factory()->create();
        $package = $this->package();

        // Redirecionamento direto: nada de modal nem de resposta JSON no caminho.
        $this->actingAs($user)
            ->post(route('payments.store', $package), ['channel' => 'pix'])
            ->assertRedirect('https://pagamento.pagseguro.uol.com.br/pagamento?code=pix');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === 'https://sandbox.api.pagseguro.com/checkouts'
                && array_column($body['payment_methods'], 'type') === [PaymentMethod::Pix->value]
                && ($body['customer_modifiable'] ?? null) === true
                && ! array_key_exists('customer', $body);
        });

        $payment = Payment::query()->latest('id')->firstOrFail();

        $this->assertSame('CHEC_PIX', $payment->provider_checkout_id);
        $this->assertSame(PaymentStatus::AwaitingPayment->value, $payment->status);
        // O método só é conhecido depois que o comprador escolhe na tela do PagBank
        // e o PIX copia-e-cola vive no mock: o OLHO registra a intenção.
        $this->assertNull($payment->method);
        $this->assertNull(data_get($payment->metadata, 'pix_payload'));
        // Nada é creditado antes da confirmação do provedor.
        $this->assertSame(0, Wallet::query()->where('user_id', $user->id)->count());
    }

    public function test_card_choice_redirects_to_the_hosted_checkout_without_pix(): void
    {
        $this->enablePagBank();

        Http::fake([
            'sandbox.api.pagseguro.com/checkouts' => Http::response([
                'id' => 'CHEC_TESTE',
                'status' => 'ACTIVE',
                'links' => [
                    ['rel' => 'PAY', 'href' => 'https://pagamento.pagseguro.uol.com.br/pagamento?code=teste', 'method' => 'GET'],
                ],
            ], 200),
        ]);

        $user = User::factory()->create(['name' => 'Maria de Souza']);
        $package = $this->package();

        // O comprador escolhe o meio na tela do provedor: o OLHO só envia os
        // métodos aceitos e o endereço de retorno (nenhum dado pessoal).
        $this->actingAs($user)
            ->post(route('payments.store', $package), ['channel' => 'card'])
            ->assertRedirect('https://pagamento.pagseguro.uol.com.br/pagamento?code=teste');

        // Só cartão no link do canal, e sem `customer` no payload.
        Http::assertSent(function ($request) {
            $types = array_column($request->data()['payment_methods'] ?? [], 'type');

            return $request->url() === 'https://sandbox.api.pagseguro.com/checkouts'
                && in_array(PaymentMethod::Credit->value, $types, true)
                && in_array(PaymentMethod::Debit->value, $types, true)
                && ! in_array(PaymentMethod::Pix->value, $types, true)
                && ! array_key_exists('customer', $request->data());
        });
    }

    public function test_status_endpoint_reports_the_paid_order_without_double_crediting(): void
    {
        $user = User::factory()->create();
        $package = $this->package(credits: 300, priceCents: 9900);

        $payment = app(PaymentService::class)->checkout($user, $package);
        app(PaymentService::class)->simulatePayment($payment);

        $this->actingAs($user)
            ->getJson(route('payments.status', $payment))
            ->assertOk()
            ->assertJsonPath('payment.paid', true)
            ->assertJsonPath('payment.status', PaymentStatus::Paid->value);

        $this->assertSame(300, Wallet::query()->where('user_id', $user->id)->firstOrFail()->balance);

        // Repetir o acompanhamento não libera créditos de novo.
        $this->actingAs($user)->getJson(route('payments.status', $payment))->assertOk();

        $this->assertSame(300, Wallet::query()->where('user_id', $user->id)->firstOrFail()->balance);
    }

    public function test_status_endpoint_hides_another_account_payment(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $package = $this->package();

        $payment = app(PaymentService::class)->checkout($owner, $package);

        $this->actingAs($other)
            ->getJson(route('payments.status', $payment))
            ->assertForbidden();
    }

    public function test_card_choice_is_refused_when_only_the_local_pix_mock_is_available(): void
    {
        $user = User::factory()->create();
        $package = $this->package();

        // O botão de cartão nem é oferecido pelo mock: um POST forjado não pode
        // virar um PIX disfarçado de cartão.
        $this->actingAs($user)
            ->from('/credits')
            ->post(route('payments.store', $package), ['channel' => 'card'])
            ->assertRedirect('/credits')
            ->assertSessionHas('error', 'Esta forma de pagamento não está disponível no momento. Escolha outra opção ou avise o administrador.');

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_payment_page_shows_the_pix_code_and_follows_the_order(): void
    {
        $user = User::factory()->create();
        $payment = app(PaymentService::class)->checkout($user, $this->package());

        $payload = (string) data_get($payment->metadata, 'pix_payload');

        $this->assertNotSame('', $payload);

        $this->actingAs($user)
            ->get(route('payments.show', $payment))
            ->assertOk()
            ->assertSee('Copiar código PIX')
            ->assertSee($payload)
            // O acompanhamento consulta o endpoint de status (URL embutida no JSON do script).
            ->assertSee(json_encode(route('payments.status', $payment)), escape: false)
            // Provider fake: a simulação é a única forma de confirmar no MVP.
            ->assertSee('Simular pagamento (provider fake)');
    }

    public function test_payment_page_of_a_paid_order_points_to_the_credits(): void
    {
        $user = User::factory()->create();
        $payment = app(PaymentService::class)->checkout($user, $this->package());

        app(PaymentService::class)->simulatePayment($payment);

        $this->actingAs($user)
            ->get(route('payments.show', $payment->refresh()))
            ->assertOk()
            ->assertSee('Pagamento confirmado. Créditos adicionados à sua carteira.')
            ->assertDontSee('Simular pagamento (provider fake)');
    }

    public function test_payment_page_hides_another_account_order(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $payment = app(PaymentService::class)->checkout($owner, $this->package());

        $this->actingAs($other)
            ->get(route('payments.show', $payment))
            ->assertForbidden();
    }

    /**
     * O cartão abre o checkout hospedado do PagBank: a requisição leva apenas os
     * métodos aceitos e o link de retorno. Nome e CPF/CNPJ de quem paga são
     * coletados na página do provedor.
     */
    public function test_card_choice_sends_the_buyer_to_the_hosted_checkout(): void
    {
        Http::fake([
            'sandbox.api.pagseguro.com/checkouts' => Http::response([
                'id' => 'CHEC_CARD',
                'status' => 'ACTIVE',
                'links' => [['rel' => 'PAY', 'href' => 'https://pagamento.pagseguro.uol.com.br/pagamento?code=card']],
            ], 200),
        ]);

        $this->enablePagBank();
        $package = $this->package();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('payments.store', $package), ['channel' => 'card'])
            ->assertRedirect('https://pagamento.pagseguro.uol.com.br/pagamento?code=card');

        Http::assertSent(fn ($request) => $request->url() === 'https://sandbox.api.pagseguro.com/checkouts'
            && array_column($request['payment_methods'], 'type') === ['CREDIT_CARD', 'DEBIT_CARD']
            && ! array_key_exists('customer', $request->data()));

        $payment = Payment::query()->latest('id')->firstOrFail();

        $this->assertSame('CHEC_CARD', $payment->provider_checkout_id);

        // A página do pedido não é abandonada: o link do PagBank abre em outra aba,
        // então ela segue acompanhando a confirmação (o pagador pode voltar).
        $this->actingAs($user)
            ->get(route('payments.show', $payment))
            ->assertOk()
            ->assertSee('href="https://pagamento.pagseguro.uol.com.br/pagamento?code=card"', escape: false)
            ->assertSee('target="_blank"', escape: false)
            ->assertSee('rel="noopener noreferrer"', escape: false);
    }

    /**
     * O cartão não depende de documento no cadastro: a conta que nunca informou
     * CPF/CNPJ vê o botão normalmente, porque é a página do PagBank que coleta
     * esses dados na hora de pagar.
     */
    public function test_credits_page_offers_the_card_without_a_document_in_the_account(): void
    {
        $this->enablePagBank();
        $this->package();

        $this->actingAs(User::factory()->create())
            ->get('/credits')
            ->assertOk()
            ->assertSee('Comprar com cartão');
    }

    /**
     * O comprador que pagou em outra aba volta para `/credits` e confere os
     * créditos: o saldo vem do ledger no servidor (nada é somado no navegador) e a
     * tela avisa que o pagamento acontece em outra aba.
     */
    public function test_credits_page_shows_the_balance_after_a_confirmed_order(): void
    {
        $user = User::factory()->create();
        $payment = app(PaymentService::class)->checkout($user, $this->package(credits: 42));

        // Só o provedor credita: a simulação do mock é o caminho de confirmação aqui.
        app(PaymentService::class)->simulatePayment($payment->refresh());

        $this->assertSame(42, (int) Wallet::query()->where('user_id', $user->id)->value('balance'));

        $this->actingAs($user)
            ->get('/credits')
            ->assertOk()
            ->assertSee('O pagamento abre em outra aba')
            ->assertSee('Saldo de créditos')
            ->assertSee('class="font-semibold tabular-nums text-mist-50">42</span>', escape: false);
    }

    /**
     * Recusa real do provedor (`400`) no fluxo da conta: o pedido é revertido, o
     * comprador recebe uma mensagem acionável (não um `500`) e o diagnóstico do
     * PagBank precisa **sobreviver ao rollback** para aparecer no painel.
     */
    public function test_rejected_pagbank_checkout_is_reported_without_leaving_an_order(): void
    {
        Http::fake([
            'sandbox.api.pagseguro.com/checkouts' => Http::response([
                'error_messages' => [[
                    'error' => 'invalid_value',
                    'description' => 'Field has an invalid value. Please check the documentation.',
                    'parameter_name' => 'payment_methods[0].type',
                ]],
            ], 400),
        ]);

        $this->enablePagBank();
        $package = $this->package();
        $user = User::factory()->create(['name' => 'Maria de Souza']);

        $this->actingAs($user)
            ->from('/credits')
            ->post(route('payments.store', $package), ['channel' => 'card'])
            ->assertRedirect('/credits')
            ->assertSessionHas('error', 'Não foi possível iniciar o pagamento agora. O motivo foi registrado para o administrador — tente novamente em alguns minutos.');

        // A transação do pedido reverteu: nada ficou para trás.
        $this->assertSame(0, Payment::query()->count());

        // Diagnóstico gravado **fora** da transação (senão o rollback o levaria).
        $this->assertStringContainsString(
            'invalid_value em payment_methods[0].type',
            (string) app(PaymentSettings::class)->lastCheckoutFailure(),
        );
    }
}
