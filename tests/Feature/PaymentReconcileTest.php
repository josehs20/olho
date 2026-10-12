<?php

namespace Tests\Feature;

use App\Models\CreditPackage;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Models\Wallet;
use App\Payments\PaymentMethod;
use App\Payments\PaymentSettings;
use App\Payments\PaymentStatus;
use App\Services\PaymentService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Prompt 45 — retorno do checkout (reconciliação).
 *
 * Em produção quem libera é a notificação (webhook). Quando ela não chega — o
 * caso do ambiente local, sem URL pública —, a página de retorno consulta o
 * provedor e confirma o pedido na hora: idempotente e sem confiar em nada além
 * da resposta do PagBank (nunca em parâmetro de URL).
 */
class PaymentReconcileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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
    }

    /**
     * Checkout criado (`POST /checkouts`) + consulta (`GET /checkouts/{id}`).
     *
     * @param  array<string, mixed>  $checkout
     */
    private function fakePagBank(array $checkout): void
    {
        Http::fake([
            'sandbox.api.pagseguro.com/checkouts' => Http::response([
                'id' => 'CHEC_RET',
                'status' => 'ACTIVE',
                'expiration_date' => '2030-10-01T12:00:00-03:00',
                'links' => [
                    ['rel' => 'PAY', 'href' => 'https://pagamento.pagseguro.uol.com.br/pagamento?code=ret', 'method' => 'GET'],
                ],
            ], 200),
            'sandbox.api.pagseguro.com/checkouts/CHEC_RET' => Http::response($checkout, 200),
        ]);
    }

    /**
     * @return array{0: User, 1: Payment, 2: string}
     */
    private function startCheckout(int $credits = 120): array
    {
        $user = User::factory()->create();
        $package = CreditPackage::factory()->create(['credits' => $credits, 'price_cents' => 4990]);

        $payment = app(PaymentService::class)->checkout($user, $package);

        $url = URL::temporarySignedRoute('payments.return', now()->addHour(), ['payment' => $payment->id]);

        return [$user, $payment, $url];
    }

    public function test_return_page_confirms_a_paid_checkout_and_grants_credits_once(): void
    {
        $this->fakePagBank([
            'id' => 'CHEC_RET',
            'status' => 'PAID',
            'charges' => [[
                'id' => 'CHAR_RET',
                'status' => 'PAID',
                'payment_method' => ['type' => 'CREDIT_CARD'],
            ]],
        ]);

        [$user, $payment, $url] = $this->startCheckout();

        $this->get($url)->assertOk()->assertSee('Pagamento confirmado');

        $payment = $payment->fresh();

        $this->assertSame(PaymentStatus::Paid->value, $payment->status);
        // O método é gravado a partir da resposta do provedor (o comprador escolhe
        // na tela do PagBank, depois do checkout criado).
        $this->assertSame(PaymentMethod::Credit, $payment->method);
        $this->assertSame(120, (int) Wallet::query()->where('user_id', $user->id)->firstOrFail()->balance);

        // Revisitar a página (ou receber a notificação depois) não libera de novo.
        $this->get($url)->assertOk();

        $this->assertSame(120, (int) Wallet::query()->where('user_id', $user->id)->firstOrFail()->balance);
        $this->assertSame(1, PaymentEvent::query()->where('payment_id', $payment->id)->count());
    }

    public function test_return_page_keeps_waiting_when_the_checkout_is_not_paid(): void
    {
        $this->fakePagBank([
            'id' => 'CHEC_RET',
            'status' => 'ACTIVE',
            'charges' => [[
                'id' => 'CHAR_RET',
                'status' => 'WAITING',
                'payment_method' => ['type' => 'PIX'],
            ]],
        ]);

        [$user, $payment, $url] = $this->startCheckout();

        $this->get($url)->assertOk()->assertSee('Aguardando confirmação do pagamento');

        $this->assertSame(PaymentStatus::AwaitingPayment->value, $payment->fresh()->status);
        $this->assertNull($payment->fresh()->method);
        $this->assertSame(0, Wallet::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, PaymentEvent::query()->count());
    }

    public function test_return_page_rejects_unsigned_requests(): void
    {
        $this->fakePagBank(['id' => 'CHEC_RET', 'status' => 'PAID']);

        [, $payment] = $this->startCheckout();

        // A URL assinada é a única credencial da página de retorno.
        $this->get(route('payments.return', ['payment' => $payment->id]))->assertForbidden();
        $this->assertSame(PaymentStatus::AwaitingPayment->value, $payment->fresh()->status);
        $this->assertSame(0, PaymentEvent::query()->count());
    }
}
