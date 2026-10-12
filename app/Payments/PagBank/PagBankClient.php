<?php

namespace App\Payments\PagBank;

use App\Payments\PaymentSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP da API do PagBank (Checkout / Link de Pagamento).
 *
 * Encapsula hosts por ambiente (Sandbox/Produção), autenticação (`Bearer`) e
 * timeout. O token nunca é exposto em mensagens de erro ou logs.
 *
 * Endpoints oficiais usados (doc PagBank):
 * - `POST /checkouts` — Criar Checkout;
 * - `GET  /checkouts/{id}` — Consultar Checkout;
 * - `POST /orders` — Criar pedido (API de Pedidos: cobrança PIX com QR Code);
 * - `GET  /orders/{id}` — Consultar pedido (fonte de verdade do PIX direto);
 * - `GET  /charges/{id}` — Consultar pagamento;
 * - `GET  /public-keys/{type}` — chave pública (`type=webhook`) para validar o
 *   webhook. Atenção: o tipo é **path** (`/public-keys/webhook`, conforme o
 *   OpenAPI oficial). A variante com query string (`?type=webhook`) **não
 *   existe** e o gateway do PagBank responde `403 Invalid key=value pair
 *   (missing equal-sign) in Authorization header...` — mensagem que parece
 *   problema de token, mas é rota inválida (o token nem chega a ser avaliado).
 */
class PagBankClient
{
    /** Caminho oficial da chave pública usada para validar webhooks. */
    public const WEBHOOK_PUBLIC_KEY_PATH = '/public-keys/webhook';

    public function __construct(private readonly PaymentSettings $settings) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createCheckout(array $payload, string $idempotencyKey): array
    {
        $response = $this->http()
            ->withHeaders(['x-idempotency-key' => $idempotencyKey])
            ->post('/checkouts', $payload);

        return $this->decode($response, 'criar checkout');
    }

    /**
     * @return array<string, mixed>
     */
    public function getCheckout(string $checkoutId): array
    {
        $response = $this->http()->get('/checkouts/'.rawurlencode($checkoutId));

        return $this->decode($response, 'consultar checkout');
    }

    /**
     * Consulta um pedido (`ORDE_...`).
     *
     * Fonte de verdade do PIX direto: a confirmação sempre vem daqui (ou do
     * webhook já validado), nunca da tela do comprador.
     *
     * @return array<string, mixed>
     */
    public function getOrder(string $orderId): array
    {
        $response = $this->http()->get('/orders/'.rawurlencode($orderId));

        return $this->decode($response, 'consultar pedido');
    }

    /**
     * Consulta uma cobrança (`CHAR_...`).
     *
     * Fonte de verdade do **pagamento** (a consulta do checkout é a fonte de
     * verdade do checkout). Usada quando o checkout não devolve o array
     * `charges` e a notificação traz o id da cobrança.
     *
     * @return array<string, mixed>
     */
    public function getCharge(string $chargeId): array
    {
        $response = $this->http()->get('/charges/'.rawurlencode($chargeId));

        return $this->decode($response, 'consultar pagamento');
    }

    /**
     * Chave pública (Base64 X.509) para validar a assinatura do webhook.
     *
     * Serve também como teste de conexão/credenciais: exige um token válido e
     * não cria cobrança alguma.
     */
    public function getWebhookPublicKey(): ?string
    {
        $response = $this->publicKeysResponse();

        if (! $response->successful()) {
            return null;
        }

        $key = $response->json('public_key');

        return (is_string($key) && $key !== '') ? $key : null;
    }

    /**
     * Teste de credenciais/ambiente para o administrador.
     *
     * Mesma chamada de `getWebhookPublicKey()`, mas **lança** `PagBankException`
     * com o status HTTP e a orientação de correção: o webhook precisa apenas de
     * um "sim/não", já o operador do painel precisa saber o que ajustar.
     */
    public function checkConnection(): void
    {
        $response = $this->publicKeysResponse();

        if (! $response->successful()) {
            throw PagBankException::forResponse('validar credenciais', $response);
        }
    }

    private function publicKeysResponse(): Response
    {
        // Path param oficial (`/public-keys/webhook`): a forma com `?type=` cai
        // em rota inexistente e o gateway responde 403 com a mensagem
        // "Invalid key=value pair (missing equal-sign) in Authorization header".
        return $this->http()->get(self::WEBHOOK_PUBLIC_KEY_PATH);
    }

    private function http(): PendingRequest
    {
        $token = $this->settings->token();

        if ($token === null) {
            throw new PagBankException('Token de autenticação do PagBank não configurado.');
        }

        return Http::baseUrl($this->settings->apiBaseUrl())
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout($this->settings->timeout());
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response, string $context): array
    {
        if (! $response->successful()) {
            // O corpo é consultado apenas por palavras-chave e nunca é ecoado:
            // pode conter dados do comprador. O token nunca entra aqui.
            throw PagBankException::forResponse($context, $response);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
