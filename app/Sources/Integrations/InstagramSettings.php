<?php

namespace App\Sources\Integrations;

use App\Services\SettingsService;

/**
 * Configuração tipada da integração do Instagram (prompt 48 §2).
 *
 * Lê credenciais e indicadores de status do painel admin
 * (`/admin/settings/integrations`) com fallback para `config/sources.php`.
 *
 * O `sessionid` é um **segredo**: fica cifrado no banco (cast `encrypted` de
 * `App\Models\Setting`, aplicado por `SettingsService::set()`, que hidrata o
 * modelo antes do `INSERT`/`UPDATE`) e nunca é devolvido à view, logado ou
 * colocado na argv do processo (só no ambiente do script Python, que não
 * aparece em `ps`).
 *
 * Os indicadores de saúde (`status`, `last_check_*`) são a fonte de verdade do
 * badge exibido no painel: são atualizados pela ação "Testar conexão" **e**
 * automaticamente sempre que um módulo do Instagram é executado — uma sessão
 * revogada aparece em vermelho mesmo que ninguém a tenha testado.
 */
final class InstagramSettings
{
    public const DRIVER_PROCESS = 'process';

    public const DRIVER_MOCK = 'mock';

    // Estado do painel (§2.1).
    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_HEALTHY = 'healthy';

    public const STATUS_CHALLENGE_REQUIRED = 'challenge_required';

    public const STATUS_EXPIRED = 'expired';

    // Chaves persistidas em `settings`.
    public const KEY_DRIVER = 'integration.instagram.driver';

    public const KEY_SESSION_ID = 'integration.instagram.session_id';

    public const KEY_DS_USER_ID = 'integration.instagram.ds_user_id';

    public const KEY_PROXY = 'integration.instagram.proxy';

    public const KEY_STATUS = 'integration.instagram.status';

    public const KEY_LAST_CHECK_AT = 'integration.instagram.last_check_at';

    public const KEY_LAST_CHECK_ERROR = 'integration.instagram.last_check_error';

    public const KEY_LAST_MESSAGE = 'integration.instagram.last_message';

    public function __construct(private readonly SettingsService $settings) {}

    public function sessionId(): ?string
    {
        return self::normalizeCookie($this->settings->getString(
            self::KEY_SESSION_ID,
            (string) config('sources.instagram.session_id', ''),
        ));
    }

    public function hasSession(): bool
    {
        return $this->sessionId() !== null;
    }

    public function dsUserId(): ?string
    {
        return self::normalizeCookie($this->settings->getString(
            self::KEY_DS_USER_ID,
            (string) config('sources.instagram.ds_user_id', ''),
        ));
    }

    /**
     * Proxy de egresso para a consulta ao Instagram (HTTP/HTTPS/SOCKS).
     *
     * O Instagram limita IPs de datacenter (429 / login_required) e vincula a
     * sessão ao IP de origem; com um proxy residencial/limpo a sessão do burner
     * volta a ser aceita. Resolve **primeiro** o valor salvo no painel e cai para
     * `config/sources.php` (`INSTAGRAM_PROXY`) — mesmo padrão do `sessionid`.
     *
     * A URL pode trazer credenciais (`http://user:pass@host:port`): é um segredo e
     * trafega apenas pelo ambiente do processo, nunca na argv nem em log.
     */
    public function proxy(): ?string
    {
        return self::normalizeProxy($this->settings->getString(
            self::KEY_PROXY,
            (string) config('sources.instagram.proxy', ''),
        ));
    }

    /**
     * Driver em uso: `process` executa o script Python/instagrapi; `mock` mantém
     * o adapter determinístico (sem rede), usado pela suíte de testes.
     *
     * O driver é lido **primeiro de `config/sources.php`** (`.env`/`INSTAGRAM_DRIVER`)
     * e o valor salvo no painel serve de override: um `mock` esquecido no banco
     * não deve desligar a integração real em produção, e vice-versa.
     */
    public function driver(): string
    {
        $override = $this->settings->getString(self::KEY_DRIVER);
        $configured = (string) config('sources.instagram.driver', self::DRIVER_PROCESS);

        $driver = $override !== null && $override !== '' ? $override : $configured;

        return in_array($driver, [self::DRIVER_PROCESS, self::DRIVER_MOCK], true)
            ? $driver
            : self::DRIVER_PROCESS;
    }

    /**
     * Estado da sessão exibido no painel. Sem credencial salva (e sem fallback de
     * configuração) o estado é `EXPIRED`: a integração não tem como autenticar.
     */
    public function status(): string
    {
        $status = (string) $this->settings->getString(
            self::KEY_STATUS,
            $this->hasSession() ? self::STATUS_UNKNOWN : self::STATUS_EXPIRED,
        );

        return in_array($status, [
            self::STATUS_UNKNOWN,
            self::STATUS_HEALTHY,
            self::STATUS_CHALLENGE_REQUIRED,
            self::STATUS_EXPIRED,
        ], true) ? $status : self::STATUS_EXPIRED;
    }

    public function isHealthy(): bool
    {
        return $this->status() === self::STATUS_HEALTHY;
    }

    public function lastCheckAt(): ?string
    {
        return $this->settings->getString(self::KEY_LAST_CHECK_AT);
    }

    public function lastCheckError(): ?string
    {
        return $this->settings->getString(self::KEY_LAST_CHECK_ERROR);
    }

    /**
     * Última mensagem observada da integração (teste de conexão ou execução de
     * módulo). Diagnóstico para o operador — nunca contém o cookie.
     */
    public function lastMessage(): ?string
    {
        return $this->settings->getString(self::KEY_LAST_MESSAGE);
    }

    /**
     * Grava o resultado do teste/disparo e atualiza os indicadores do painel em
     * uma única escrita.
     */
    public function rememberStatus(string $status, ?string $message): void
    {
        $this->settings->setMany([
            self::KEY_STATUS => $status,
            self::KEY_LAST_CHECK_AT => now()->toIso8601String(),
            self::KEY_LAST_CHECK_ERROR => $status === self::STATUS_HEALTHY ? null : $message,
            self::KEY_LAST_MESSAGE => $message,
        ]);
    }

    /* ---------------------------------------------------------------------
     | Rótulos do painel (§2.1)
     | --------------------------------------------------------------------- */

    public static function label(string $status): string
    {
        return match ($status) {
            self::STATUS_HEALTHY => 'HEALTHY',
            self::STATUS_CHALLENGE_REQUIRED => 'CHALLENGE_REQUIRED',
            default => 'EXPIRED / INVALID',
        };
    }

    public static function variant(string $status): string
    {
        return match ($status) {
            self::STATUS_HEALTHY => 'signal',
            self::STATUS_CHALLENGE_REQUIRED => 'warning',
            default => 'danger',
        };
    }

    /**
     * Normaliza o valor colado do cookie: o DevTools entrega pares
     * `sessionid=ABC;` e às vezes a linha inteira do `Cookie`. Um valor com
     * espaços/`;` produziria um cookie inválido e o Instagram responderia 401
     * (o mesmo cuidado do `PaymentSettings::normalizeToken`).
     */
    public static function normalizeCookie(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (str_contains($value, '=')) {
            parse_str(str_replace(';', '&', $value), $parsed);

            $value = (string) ($parsed['sessionid'] ?? $parsed['ds_user_id'] ?? $value);
        }

        $value = trim($value, " \t\n\r\0\x0B\"'");

        return preg_match('/^[A-Za-z0-9._%:\-]+$/', $value) === 1 ? $value : null;
    }

    /**
     * Normaliza a URL de proxy: apenas `http(s)://` ou `socks5(h)://`, com host
     * obrigatório e sem espaços. Aceita credenciais na URL (`user:pass@host`).
     * Qualquer outra coisa (esquema estranho, entrada vazia, host ausente) é
     * descartada — o adapter segue sem proxy em vez de passar um valor inválido
     * ao cliente, o que quebraria todas as requisições.
     */
    public static function normalizeProxy(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value, " \t\n\r\0\x0B\"'");

        if ($value === '' || preg_match('#^(https?|socks5h?|socks4)://[^\s/]+#i', $value) !== 1) {
            return null;
        }

        return $value;
    }
}
