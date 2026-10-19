<?php

namespace App\Payments;

use App\Services\SettingsService;

/**
 * Configuração tipada do subsistema de pagamentos (prompt 45).
 *
 * Lê as configurações administrativas (`App\Models\Setting`, cifradas) com
 * fallback para os defaults de `config/payments.php`. Centraliza o acesso para
 * que nenhum controller dependa de chaves cruas espalhadas pelo código.
 */
final class PaymentSettings
{
    public const PROVIDER_FAKE = 'fake';

    public const PROVIDER_PAGBANK = 'pagbank';

    /**
     * Link de pagamento gerado no site do banco, colado em cada pacote.
     *
     * Sem API, sem webhook e sem expiração: o comprador paga pelo link e o
     * administrador confere o extrato antes de liberar créditos/execução.
     */
    public const PROVIDER_PAYMENT_LINK = 'payment_link';

    public const ENV_SANDBOX = 'sandbox';

    public const ENV_PRODUCTION = 'production';

    // Chaves persistidas em `settings`.
    public const KEY_PROVIDER = 'payment.provider';

    public const KEY_ENABLED = 'payment.enabled';

    public const KEY_ENVIRONMENT = 'payment.environment';

    public const KEY_PAGBANK_TOKEN = 'payment.pagbank.token';

    public const KEY_LAST_TEST_AT = 'payment.pagbank.last_test_at';

    public const KEY_LAST_TEST_OK = 'payment.pagbank.last_test_ok';

    public const KEY_LAST_TEST_ERROR = 'payment.pagbank.last_test_error';

    public const KEY_LAST_CHECKOUT_ERROR = 'payment.pagbank.last_checkout_error';

    public const KEY_METHOD_PIX = 'payment.method.pix';

    public const KEY_METHOD_DEBIT = 'payment.method.debit';

    public const KEY_METHOD_CREDIT = 'payment.method.credit';

    public const KEY_PIX_EXPIRATION = 'payment.pix.expiration_minutes';

    public const KEY_CREDIT_MAX_INSTALLMENTS = 'payment.credit.max_installments';

    /** Métodos internos (traduzidos da nomenclatura do PagBank). */
    public const METHOD_PIX = 'PIX';

    public const METHOD_DEBIT = 'DEBIT_CARD';

    public const METHOD_CREDIT = 'CREDIT_CARD';

    public function __construct(private readonly SettingsService $settings) {}

    public function provider(): string
    {
        return (string) $this->settings->getString(
            self::KEY_PROVIDER,
            (string) config('payments.provider', self::PROVIDER_FAKE),
        );
    }

    public function enabled(): bool
    {
        return $this->settings->getBool(self::KEY_ENABLED, (bool) config('payments.defaults.enabled', true));
    }

    /**
     * O provedor ativo é o link de pagamento do banco?
     */
    public function usesPaymentLink(): bool
    {
        return $this->provider() === self::PROVIDER_PAYMENT_LINK;
    }

    /**
     * Link do banco ativo e com pagamentos habilitados.
     *
     * Não basta para cobrar: cada pacote ainda precisa do seu próprio link
     * (`credit_packages.payment_link`).
     */
    public function hasUsablePaymentLink(): bool
    {
        return $this->usesPaymentLink() && $this->enabled();
    }

    public function environment(): string
    {
        $environment = (string) $this->settings->getString(
            self::KEY_ENVIRONMENT,
            (string) config('payments.defaults.environment', self::ENV_SANDBOX),
        );

        return in_array($environment, [self::ENV_SANDBOX, self::ENV_PRODUCTION], true)
            ? $environment
            : self::ENV_SANDBOX;
    }

    public function isProduction(): bool
    {
        return $this->environment() === self::ENV_PRODUCTION;
    }

    public function token(): ?string
    {
        return self::normalizeToken($this->settings->getString(self::KEY_PAGBANK_TOKEN));
    }

    /**
     * Normaliza o token informado pelo administrador.
     *
     * Copiar o token de "Minha Conta"/Portal do Desenvolvedor costuma trazer
     * aspas, quebras de linha/ espaços e, às vezes, o próprio prefixo `Bearer`.
     * Como o cliente HTTP já envia `Authorization: Bearer <token>`, qualquer
     * sobra produz um header inválido e o PagBank responde
     * `invalid_authorization_header` ("Invalid key=value pair (missing
     * equal-sign) in Authorization header"). Aqui removemos tudo isso antes de
     * gravar e de usar.
     */
    public static function normalizeToken(?string $token): ?string
    {
        if ($token === null) {
            return null;
        }

        $normalized = trim($token);
        $normalized = trim($normalized, "\"'");
        $normalized = (string) preg_replace('/^authorization\s*:\s*/i', '', $normalized);
        $normalized = trim($normalized);
        $normalized = (string) preg_replace('/^bearer\s+/i', '', $normalized);
        // Espaços, quebras de linha e caracteres de controle não pertencem ao token.
        $normalized = (string) preg_replace('/[\s\x00-\x1F\x7F]+/u', '', $normalized);
        $normalized = trim($normalized, "\"'");

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Prévia segura do token para diagnóstico no painel.
     *
     * Mostra apenas início/fim e o comprimento — o suficiente para o operador
     * comparar com o valor exibido no portal, sem revelar a credencial.
     */
    public static function maskToken(?string $token): ?string
    {
        $token = self::normalizeToken($token);

        if ($token === null) {
            return null;
        }

        $length = strlen($token);

        if ($length <= 12) {
            return str_repeat('•', $length)." ({$length} caracteres)";
        }

        return substr($token, 0, 8).'…'.substr($token, -6)." ({$length} caracteres)";
    }

    public function apiBaseUrl(): string
    {
        return (string) config("payments.pagbank.environments.{$this->environment()}.api");
    }

    /**
     * URL de notificação enviada em cada checkout criado (`notification_urls`).
     */
    public function webhookUrl(): string
    {
        return route('payments.webhook.pagbank');
    }

    /**
     * A URL de notificação é alcançável pelo PagBank (HTTPS público)?
     *
     * Em desenvolvimento o `APP_URL` é `http://localhost...`: o provedor não
     * alcança esse endereço e a URL local só polui o payload (e pode ser recusada
     * como parâmetro inválido). Nesse cenário a notificação é **omitida** e a
     * confirmação acontece no retorno do comprador (`payments.return`), que
     * reconsulta o provedor.
     */
    public function webhookIsPublic(): bool
    {
        return str_starts_with($this->webhookUrl(), 'https://');
    }

    /**
     * Lista de URLs de notificação do payload; vazia quando não é pública.
     *
     * @return array<int, string>
     */
    public function notificationUrls(): array
    {
        return $this->webhookIsPublic() ? [$this->webhookUrl()] : [];
    }

    /**
     * Última falha de criação de cobrança no PagBank (diagnóstico do operador).
     *
     * Guardada porque a mensagem que o comprador vê é curta e genérica: o
     * operador precisa do motivo real (campo recusado) para corrigir a
     * configuração. Nunca contém token nem dados do comprador.
     */
    public function lastCheckoutFailure(): ?string
    {
        return $this->settings->getString(self::KEY_LAST_CHECKOUT_ERROR);
    }

    public function rememberCheckoutFailure(?string $message): void
    {
        $this->settings->set(self::KEY_LAST_CHECKOUT_ERROR, $message);
    }

    /**
     * Limpa o diagnóstico anterior (chamado quando uma cobrança é aceita).
     *
     * Só escreve quando há algo a limpar: evita um `DELETE` a cada checkout.
     */
    public function forgetCheckoutFailure(): void
    {
        if ($this->settings->has(self::KEY_LAST_CHECKOUT_ERROR)) {
            $this->settings->forget(self::KEY_LAST_CHECKOUT_ERROR);
        }
    }

    public function timeout(): int
    {
        return (int) config('payments.pagbank.timeout', 15);
    }

    public function pixEnabled(): bool
    {
        return $this->settings->getBool(self::KEY_METHOD_PIX, (bool) config('payments.defaults.method_pix', true));
    }

    public function debitEnabled(): bool
    {
        return $this->settings->getBool(self::KEY_METHOD_DEBIT, (bool) config('payments.defaults.method_debit', true));
    }

    public function creditEnabled(): bool
    {
        return $this->settings->getBool(self::KEY_METHOD_CREDIT, (bool) config('payments.defaults.method_credit', true));
    }

    public function pixExpirationMinutes(): int
    {
        return max(1, $this->settings->getInt(
            self::KEY_PIX_EXPIRATION,
            (int) config('payments.defaults.pix_expiration_minutes', 30),
        ));
    }

    public function maxInstallments(): int
    {
        $max = $this->settings->getInt(
            self::KEY_CREDIT_MAX_INSTALLMENTS,
            (int) config('payments.defaults.credit_max_installments', 6),
        );

        $bounds = config('payments.pagbank.installments', ['min' => 1, 'max' => 12]);

        return max((int) $bounds['min'], min((int) $bounds['max'], $max));
    }

    /**
     * Métodos habilitados na nomenclatura interna.
     *
     * @return array<int, string>
     */
    public function enabledMethods(): array
    {
        $methods = [];

        if ($this->pixEnabled()) {
            $methods[] = self::METHOD_PIX;
        }

        if ($this->debitEnabled()) {
            $methods[] = self::METHOD_DEBIT;
        }

        if ($this->creditEnabled()) {
            $methods[] = self::METHOD_CREDIT;
        }

        return $methods;
    }

    /**
     * A integração PagBank está pronta para uso (provedor + token + método)?
     */
    public function hasUsablePagBank(): bool
    {
        return $this->provider() === self::PROVIDER_PAGBANK
            && $this->enabled()
            && $this->token() !== null
            && $this->enabledMethods() !== [];
    }
}
