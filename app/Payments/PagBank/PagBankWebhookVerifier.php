<?php

namespace App\Payments\PagBank;

/**
 * Valida a autenticidade dos webhooks do PagBank.
 *
 * Conforme a doc "Validação de autenticidade": a assinatura vem no header
 * `x-payload-signature` (Base64, ECDSA/SHA-256) e é verificada contra o **corpo
 * bruto** da requisição usando a chave pública obtida em `GET /public-keys`.
 * Durante rotação de chaves podem vir múltiplas assinaturas — basta uma válida.
 */
class PagBankWebhookVerifier
{
    public function __construct(private readonly PagBankClient $client) {}

    /**
     * @param  array<int, string>  $signatures  valores Base64 de `x-payload-signature`
     */
    public function verify(string $rawBody, array $signatures): bool
    {
        $signatures = array_values(array_filter(
            $signatures,
            fn (mixed $sig): bool => is_string($sig) && $sig !== '',
        ));

        if ($rawBody === '' || $signatures === []) {
            return false;
        }

        $publicKeyBase64 = $this->client->getWebhookPublicKey();

        if ($publicKeyBase64 === null) {
            return false;
        }

        $pem = $this->toPem($publicKeyBase64);

        if ($pem === null) {
            return false;
        }

        foreach ($signatures as $signature) {
            $der = base64_decode($signature, true);

            if ($der === false) {
                continue;
            }

            if (openssl_verify($rawBody, $der, $pem, OPENSSL_ALGO_SHA256) === 1) {
                return true;
            }
        }

        return false;
    }

    private function toPem(string $base64): ?string
    {
        $der = base64_decode($base64, true);

        if ($der === false) {
            return null;
        }

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }
}
