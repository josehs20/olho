<?php

namespace App\Payments;

use App\Models\Payment;
use App\Payments\PagBank\PagBankClient;
use App\Payments\PagBank\PagBankException;
use App\Payments\PagBank\PagBankWebhookVerifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * Provedor real do PagBank via **Checkout / Link de Pagamento** (prompt 45).
 *
 * Fluxo: OLHO cria o checkout (`POST /checkouts`) → PagBank devolve o link
 * `PAY` → o cliente paga (PIX/débito/crédito) → PagBank notifica por webhook →
 * validamos a assinatura, **consultamos o checkout** e só então confirmamos.
 *
 * O OLHO não pede, não recebe e não armazena dado pessoal do comprador — nem de
 * cartão, nem nome/CPF/CNPJ: o checkout sai com `customer_modifiable: true` e é
 * a **página hospedada do PagBank** que coleta os dados de quem paga.
 */
class PagBankProvider implements CheckoutProvider, PaymentProvider
{
    public function __construct(
        private readonly PagBankClient $client,
        private readonly PagBankWebhookVerifier $verifier,
        private readonly PaymentSettings $settings,
    ) {}

    /**
     * @param  array{methods?: array<int, string>, max_installments?: int, pix_expiration_minutes?: int}  $options
     */
    public function createCheckout(Payment $payment, array $options = []): CheckoutSession
    {
        $methods = $options['methods'] ?? [
            PaymentMethod::Pix->value,
            PaymentMethod::Debit->value,
            PaymentMethod::Credit->value,
        ];

        $payload = $this->buildCheckoutPayload(
            $payment,
            $methods,
            (int) ($options['max_installments'] ?? 1),
            (int) ($options['pix_expiration_minutes'] ?? 30),
        );

        try {
            $data = $this->client->createCheckout($payload, $this->idempotencyKey($payment));
        } catch (PagBankException $exception) {
            $this->logFailure($payment, 'criar checkout', $exception);

            throw $exception;
        }

        $this->settings->forgetCheckoutFailure();

        $redirectUrl = $this->payLink($data);

        if ($redirectUrl === null) {
            throw new PagBankException('PagBank não retornou o link de pagamento (rel=PAY).');
        }

        Log::info('payment.checkout.created', [
            'payment_id' => $payment->id,
            'provider' => PaymentSettings::PROVIDER_PAGBANK,
            'provider_checkout_id' => $data['id'] ?? null,
        ]);

        return new CheckoutSession(
            externalId: (string) ($data['id'] ?? ''),
            redirectUrl: $redirectUrl,
            expiresAt: isset($data['expiration_date']) ? $this->providerDate((string) $data['expiration_date']) : null,
            raw: $data,
        );
    }

    /**
     * Status do pedido no provedor.
     *
     * PIX direto é consultado na API de Pedidos (`ORDE_...`); o checkout, na API
     * de Checkout (`CHEC_...`).
     */
    public function getStatus(string $externalId): PaymentStatus
    {
        if (str_starts_with($externalId, 'ORDE_')) {
            return $this->resolveStatus($this->client->getOrder($externalId));
        }

        return $this->getCheckoutStatus($externalId);
    }

    public function getCheckoutStatus(string $checkoutId): PaymentStatus
    {
        return $this->resolveStatus($this->withChargesFromOrders($this->client->getCheckout($checkoutId)));
    }

    /**
     * Método de pagamento já registrado no checkout, na nomenclatura interna.
     *
     * Usado por quem reconsulta o pedido fora do webhook (a página de retorno do
     * checkout), para gravar o método sem depender da notificação.
     */
    public function getCheckoutMethod(string $checkoutId): ?string
    {
        return $this->paymentMethod($this->withChargesFromOrders($this->client->getCheckout($checkoutId)));
    }

    public function validateWebhook(array $payload, array $headers, ?string $rawBody = null): ?VerifiedPaymentEvent
    {
        // 1) Assinatura obrigatória (nunca confiar apenas no payload).
        if (! $this->verifier->verify((string) $rawBody, $this->signatures($headers))) {
            Log::warning('payment.webhook.rejected', ['provider' => PaymentSettings::PROVIDER_PAGBANK]);

            return null;
        }

        $referenceId = (string) ($payload['id'] ?? $payload['checkout_id'] ?? '');

        if ($referenceId === '') {
            return null;
        }

        // 2) Zero-trust: consulta o estado real no provedor antes de confirmar.
        // O id notificado pode ser de pedido (`ORDE_`, PIX direto), de cobrança
        // (`CHAR_`) ou de checkout (`CHEC_`): cada natureza tem a sua consulta —
        // e o checkout pago é resolvido pelo pedido que ele referencia quando a
        // consulta não devolve `charges` (`withChargesFromOrders()`).
        $data = $this->withChargesFromOrders($this->notificationData($referenceId));
        // O `external_id` gravado no OLHO é o do **pedido** (PIX) ou do checkout.
        // Numa notificação de pedido o PagBank manda o id da cobrança; o pedido
        // vem em `metadata.ps_order_id`.
        $externalId = $this->externalIdFromNotification($referenceId, $data);
        $status = $this->resolveStatus($data);

        // 3) O checkout é a fonte de verdade, mas o array `charges` não aparece
        // no exemplo oficial da consulta. Quando a notificação (já autenticada)
        // traz o id da cobrança e o objeto consultado não devolveu cobranças,
        // confirmamos diretamente na API de pagamentos antes de decidir.
        $checkoutChargeId = $this->chargeId($data);
        $chargeId = $checkoutChargeId ?? $this->chargeId($payload);
        $charge = null;

        if ($checkoutChargeId === null && $chargeId !== null) {
            $charge = $this->chargeData($chargeId);

            // A cobrança só prevalece quando é conclusiva (pago/rejeitado/
            // cancelado/estornado/expirado); um PIX ainda aguardando não
            // sobrescreve o status do checkout.
            $chargeStatus = $charge !== null ? PaymentStatus::fromPagBank($charge['status'] ?? null) : null;

            if ($chargeStatus !== null && $chargeStatus !== PaymentStatus::AwaitingPayment) {
                $status = $chargeStatus;
            }
        }

        // O método escolhido pelo comprador aparece na cobrança — no checkout
        // consultado, na própria notificação ou na cobrança buscada acima.
        $method = $this->paymentMethod($data)
            ?? $this->paymentMethod($payload)
            ?? ($charge !== null ? $this->paymentMethod($charge) : null);

        Log::info('payment.webhook.received', [
            'provider' => PaymentSettings::PROVIDER_PAGBANK,
            'provider_reference_id' => $referenceId,
            'provider_external_id' => $externalId,
            'status' => $status->value,
            'method' => $method,
        ]);

        return new VerifiedPaymentEvent(
            externalEventId: sha1($externalId.'|'.$status->value.'|'.($chargeId ?? '')),
            externalId: $externalId,
            status: $status->value,
            method: $method,
        );
    }

    /**
     * Consulta o objeto notificado no provedor, conforme a natureza do id.
     *
     * @return array<string, mixed>
     */
    private function notificationData(string $id): array
    {
        return match (true) {
            str_starts_with($id, 'ORDE_') => $this->client->getOrder($id),
            str_starts_with($id, 'CHAR_') => $this->client->getCharge($id),
            default => $this->client->getCheckout($id),
        };
    }

    /**
     * `external_id` gravado no OLHO a partir do id recebido na notificação.
     *
     * O PIX direto guarda o id do **pedido** (`ORDE_`). Se a notificação trouxer
     * a cobrança (`CHAR_`), o pedido vem em `metadata.ps_order_id` — é ele que
     * identifica o pagamento no banco do OLHO.
     *
     * @param  array<string, mixed>  $data
     */
    private function externalIdFromNotification(string $id, array $data): string
    {
        if (str_starts_with($id, 'ORDE_')) {
            return $id;
        }

        $orderId = $data['metadata']['ps_order_id'] ?? null;

        return is_string($orderId) && str_starts_with($orderId, 'ORDE_') ? $orderId : $id;
    }

    /**
     * Data informada pelo provedor, no fuso da aplicação.
     *
     * O banco guarda a hora local sem offset: converter antes de gravar preserva
     * o instante — um prazo em `-03:00` não vira 3 horas a menos de validade.
     */
    private function providerDate(string $value): Carbon
    {
        return Carbon::parse($value)->setTimezone(config('app.timezone'));
    }

    /**
     * URLs de notificação do payload (omitidas quando não há URL pública).
     *
     * @return array<string, array<int, string>>
     */
    private function notificationPayload(): array
    {
        $urls = $this->settings->notificationUrls();

        if ($urls === []) {
            return [];
        }

        return [
            'notification_urls' => $urls,
            'payment_notification_urls' => $urls,
        ];
    }

    /**
     * Payload do Checkout hospedado (`POST /checkouts`).
     *
     * Contrato conferido na doc oficial (Criar Checkout / Objeto Checkout):
     * `reference_id` e `items` são obrigatórios; `payment_methods_configs` só
     * vale para cartão; as notificações **e** as URLs de retorno (`redirect_url` e
     * `return_url`) só saem quando há endereço público HTTPS (ver
     * `PaymentSettings::notificationUrls()`), porque o provedor valida e recusa a
     * cobrança inteira quando o endereço não é alcançável.
     *
     * O objeto `customer` **não** é enviado: com `customer_modifiable: true` (o
     * padrão documentado) a página hospedada do PagBank coleta nome, CPF/CNPJ e
     * e-mail do comprador — o OLHO não pede nem guarda esses dados.
     *
     * @param  array<int, string>  $methods
     * @return array<string, mixed>
     */
    private function buildCheckoutPayload(
        Payment $payment,
        array $methods,
        int $maxInstallments,
        int $expirationMinutes,
    ): array {
        $payload = [
            'reference_id' => $this->referenceId($payment),
            'expiration_date' => now()->addMinutes(max(1, $expirationMinutes))->toIso8601String(),
            'items' => [[
                'reference_id' => $this->referenceId($payment),
                'name' => $this->itemName($payment),
                'quantity' => 1,
                'unit_amount' => (int) $payment->amount_cents,
            ]],
            'payment_methods' => $this->paymentMethods($methods),
            // O step de dados pessoais continua na página do PagBank: é lá que o
            // comprador informa nome e CPF/CNPJ (nada é enviado pelo OLHO).
            'customer_modifiable' => true,
        ];

        // As duas URLs de retorno só entram quando o app tem endereço público: o
        // PagBank **valida** o endereço e recusa o checkout inteiro quando ele não
        // é alcançável (`400 invalid_value` nos dois campos com
        // `http://localhost...`) — foi o que travou o teste manual. Mesma regra
        // das URLs de notificação (`PaymentSettings::webhookIsPublic()`).
        if ($this->settings->webhookIsPublic()) {
            $payload['redirect_url'] = $this->returnUrl($payment);
            $payload['return_url'] = $payload['redirect_url'];
        }

        $configs = $this->paymentMethodsConfigs($methods, $maxInstallments);

        if ($configs !== []) {
            $payload['payment_methods_configs'] = $configs;
        }

        return array_merge($payload, $this->notificationPayload());
    }

    /**
     * Métodos aceitos no checkout (`payment_methods`).
     *
     * Contrato confirmado na doc do PagBank (Objeto Checkout): cada item é
     * `{"type": "PIX|DEBIT_CARD|CREDIT_CARD"}`; `brands` é opcional e, omitida,
     * aceita todas as bandeiras suportadas. O limite de parcelas **não** mora
     * aqui: vai em `payment_methods_configs` (ver `paymentMethodsConfigs()`).
     *
     * @param  array<int, string>  $methods
     * @return array<int, array<string, string>>
     */
    private function paymentMethods(array $methods): array
    {
        return array_map(
            fn (string $method): array => ['type' => $method],
            array_values($methods),
        );
    }

    /**
     * Configuração por método (`payment_methods_configs`).
     *
     * Confirmado na doc do PagBank: as configurações valem apenas para
     * `CREDIT_CARD`/`DEBIT_CARD` e o parcelamento máximo é definido por
     * `config_options: [{"option": "INSTALLMENTS_LIMIT", "value": "6"}]`.
     * Débito não parcela, então só configuramos o crédito — e apenas quando o
     * limite é maior que 1 (uma parcela é o padrão do checkout).
     *
     * @param  array<int, string>  $methods
     * @return array<int, array<string, mixed>>
     */
    private function paymentMethodsConfigs(array $methods, int $maxInstallments): array
    {
        if ($maxInstallments <= 1 || ! in_array(PaymentMethod::Credit->value, $methods, true)) {
            return [];
        }

        return [[
            'type' => PaymentMethod::Credit->value,
            'config_options' => [[
                'option' => 'INSTALLMENTS_LIMIT',
                'value' => (string) $maxInstallments,
            ]],
        ]];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function payLink(array $data): ?string
    {
        foreach ((array) ($data['links'] ?? []) as $link) {
            if (is_array($link) && ($link['rel'] ?? null) === 'PAY' && ! empty($link['href'])) {
                return (string) $link['href'];
            }
        }

        return null;
    }

    /**
     * Objeto consultado com as **cobranças resolvidas**.
     *
     * `GET /checkouts/{id}` (produto Checkout) responde `status: ACTIVE` e
     * **sem** o array `charges` — inclusive depois de um cartão aprovado: a
     * cobrança (`CHAR_...`, `status: PAID`) só aparece no **pedido** do
     * checkout, referenciado em `orders[].id`. Sem seguir essa referência, um
     * pagamento autorizado no sandbox ficava eternamente em `awaiting_payment`
     * na sincronização, no retorno do checkout e no webhook (caso real do
     * sandbox reproduzido aqui).
     *
     * Zero-trust: o pedido é buscado pelo **id** que o provedor devolveu
     * (`ORDE_...`) na API oficial do próprio provedor (mesmo token, mesmo
     * ambiente) — nunca seguindo `links.href` do payload. Pedido indisponível
     * não inventa status: o objeto volta como veio (aguardando pagamento).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withChargesFromOrders(array $data): array
    {
        if (is_array($data['charges'] ?? null) && $data['charges'] !== []) {
            return $data;
        }

        $charges = [];

        foreach ((array) ($data['orders'] ?? []) as $order) {
            $orderId = is_array($order) ? ($order['id'] ?? null) : null;

            if (is_string($orderId) && str_starts_with($orderId, 'ORDE_')) {
                $charges = array_merge($charges, $this->orderCharges($orderId));
            }
        }

        if ($charges === []) {
            return $data;
        }

        $data['charges'] = $charges;

        return $data;
    }

    /**
     * Cobranças de um pedido (`GET /orders/{ORDE_...}`).
     *
     * Um pedido indisponível não invalida a consulta já autenticada: o objeto
     * original continua valendo e o pagamento permanece aguardando — nunca
     * confirmamos por ausência de resposta.
     *
     * @return array<int, array<string, mixed>>
     */
    private function orderCharges(string $orderId): array
    {
        try {
            $order = $this->client->getOrder($orderId);
        } catch (PagBankException) {
            Log::info('payment.status.order_unavailable', [
                'provider' => PaymentSettings::PROVIDER_PAGBANK,
                'provider_order_id' => $orderId,
            ]);

            return [];
        }

        return array_values(array_filter((array) ($order['charges'] ?? []), 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveStatus(array $data): PaymentStatus
    {
        $charges = is_array($data['charges'] ?? null) ? $data['charges'] : [];
        $statuses = array_map(
            fn (mixed $charge): ?string => is_array($charge) ? ($charge['status'] ?? null) : null,
            $charges,
        );

        foreach ($statuses as $status) {
            if (PaymentStatus::fromPagBank($status) === PaymentStatus::Paid) {
                return PaymentStatus::Paid;
            }
        }

        foreach ($statuses as $status) {
            if (PaymentStatus::fromPagBank($status) === PaymentStatus::Failed) {
                return PaymentStatus::Failed;
            }
        }

        $checkoutStatus = PaymentStatus::fromPagBank($data['status'] ?? null);

        if (in_array($checkoutStatus, [PaymentStatus::Cancelled, PaymentStatus::Expired], true)) {
            return $checkoutStatus;
        }

        return PaymentStatus::AwaitingPayment;
    }

    /**
     * Id da cobrança (`CHAR_...`) presente no objeto, aceitando tanto o array
     * `charges` (checkout/notificação transacional) quanto o objeto singular
     * `charge` (resposta de criação de pedido).
     *
     * @param  array<string, mixed>  $data
     */
    private function chargeId(array $data): ?string
    {
        $charges = is_array($data['charges'] ?? null) ? $data['charges'] : [];

        foreach ($charges as $charge) {
            if (is_array($charge) && ! empty($charge['id'])) {
                return (string) $charge['id'];
            }
        }

        $charge = $data['charge'] ?? null;

        if (is_array($charge) && ! empty($charge['id'])) {
            return (string) $charge['id'];
        }

        return null;
    }

    /**
     * Cobrança consultada diretamente na API de pagamentos.
     *
     * Uma cobrança indisponível não invalida a notificação já autenticada: o
     * status do checkout continua valendo e o pagamento permanece aguardando.
     *
     * @return array<string, mixed>|null
     */
    private function chargeData(string $chargeId): ?array
    {
        try {
            return $this->client->getCharge($chargeId);
        } catch (PagBankException) {
            Log::info('payment.webhook.charge_unavailable', [
                'provider' => PaymentSettings::PROVIDER_PAGBANK,
                'provider_charge_id' => $chargeId,
            ]);

            return null;
        }
    }

    /**
     * Método de pagamento (`payment_method.type`) presente no objeto, na
     * nomenclatura interna.
     *
     * Aceita as três formas que o PagBank devolve: checkout (array `charges`),
     * notificação com objeto singular `charge` e a própria cobrança
     * (`GET /charges/{id}` ou notificação transacional).
     *
     * @param  array<string, mixed>  $data
     */
    private function paymentMethod(array $data): ?string
    {
        $types = [];

        foreach ((array) ($data['charges'] ?? []) as $charge) {
            $types[] = is_array($charge) ? ($charge['payment_method']['type'] ?? null) : null;
        }

        if (is_array($data['charge'] ?? null)) {
            $types[] = $data['charge']['payment_method']['type'] ?? null;
        }

        $types[] = $data['payment_method']['type'] ?? null;

        foreach ($types as $type) {
            $method = PaymentMethod::fromPagBank(is_string($type) ? $type : null);

            if ($method !== null) {
                return $method->value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<int, string>
     */
    private function signatures(array $headers): array
    {
        $value = $headers['x-payload-signature'] ?? $headers['X-Payload-Signature'] ?? [];

        if (is_string($value)) {
            return [$value];
        }

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * Registra a falha do provedor para o operador (log).
     *
     * A mensagem que o comprador vê é curta: o motivo real (campo recusado) é o
     * que interessa a quem opera a conta. Guardamos **apenas** a mensagem já
     * saneada (`error`/`parameter_name` da doc oficial) — nunca o corpo da
     * resposta, o payload ou o token.
     *
     * A gravação para o painel acontece em `PaymentService`, **depois** do
     * rollback da transação: uma escrita aqui seria revertida junto com o pedido
     * que não chegou a existir.
     */
    private function logFailure(Payment $payment, string $context, PagBankException $exception): void
    {
        Log::warning('payment.pagbank.failed', [
            'provider' => PaymentSettings::PROVIDER_PAGBANK,
            'payment_id' => $payment->id,
            'context' => $context,
            'status' => $exception->getCode(),
            'diagnostics' => $exception->diagnostics(),
        ]);
    }

    private function referenceId(Payment $payment): string
    {
        return 'olho-'.$payment->id;
    }

    private function idempotencyKey(Payment $payment): string
    {
        return (string) ($payment->idempotency_key ?? 'checkout-'.$payment->id);
    }

    private function itemName(Payment $payment): string
    {
        return $payment->isGuest() ? 'Investigação avulsa OLHO' : 'Créditos OLHO';
    }

    /**
     * URL assinada para onde o comprador volta depois de pagar.
     *
     * Enviada apenas com endereço público HTTPS (`buildCheckoutPayload()`): o
     * PagBank valida a URL e devolve `invalid_value` quando ela aponta para um
     * endereço que ele não alcança, sem criar o checkout.
     */
    private function returnUrl(Payment $payment): string
    {
        return URL::temporarySignedRoute('payments.return', now()->addHours(6), ['payment' => $payment->getKey()]);
    }
}
