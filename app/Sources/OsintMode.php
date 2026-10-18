<?php

namespace App\Sources;

/**
 * Parâmetro de controle central da execução OSINT (mock vs. integração real).
 *
 * A decisão é **validada pelo ambiente antes de cada investigação** e reaplicada
 * a cada adapter resolvido pelo `SourceRegistry`:
 *
 *  - `APP_ENV=production` → integração real (Maigret, Sherlock, WhatsMyName,
 *     Holehe e o enriquecimento do Instagram via instagrapi);
 *  - qualquer outro `APP_ENV` (`local`, `testing`, `staging`, …) → mock
 *     determinístico, sem tocar na rede, **independentemente** de `OSINT_MODE`;
 *  - em produção, `OSINT_MODE=mock` funciona como kill switch explícito, para
 *     interromper as integrações reais sem alterar o `APP_ENV`.
 *
 * O modo é resolvido pelo ambiente/configuração (`config/osint.php` →
 * `OSINT_MODE`), nunca por entrada do usuário: nenhum valor vindo do request
 * escolhe real vs. mock.
 */
final class OsintMode
{
    /** Força as integrações reais (ignora os mocks). */
    public const MODE_REAL = 'real';

    /** Força todos os adapters determinísticos (sem rede). */
    public const MODE_MOCK = 'mock';

    /** Único `APP_ENV` que autoriza a execução real. */
    public const PRODUCTION_ENV = 'production';

    /**
     * Modo efetivo da execução, já validado contra o `APP_ENV`.
     *
     * Fora de produção o retorno é sempre `mock`: desenvolvimento e suíte de
     * testes nunca disparam ferramentas externas. Em produção, qualquer valor
     * que não seja explicitamente `mock` resolve para `real` (inclusive `auto`
     * ou valores desconhecidos, por segurança).
     */
    public static function mode(): string
    {
        if (! self::isProduction()) {
            return self::MODE_MOCK;
        }

        $mode = strtolower(trim((string) config('osint.mode', self::MODE_REAL)));

        return $mode === self::MODE_MOCK ? self::MODE_MOCK : self::MODE_REAL;
    }

    /** `true` somente quando o `APP_ENV` autoriza as integrações reais. */
    public static function isProduction(): bool
    {
        return app()->environment(self::PRODUCTION_ENV);
    }

    /** `true` quando o modo efetivo exige a integração real. */
    public static function forcesReal(): bool
    {
        return self::mode() === self::MODE_REAL;
    }

    /** `true` quando o modo efetivo exige os mocks. */
    public static function forcesMock(): bool
    {
        return self::mode() === self::MODE_MOCK;
    }

    /**
     * Override global do registry: `true` força o adapter real e `false` força o
     * mock. Não há mais decisão por fonte — o ambiente decide para todas.
     */
    public static function forced(): bool
    {
        return self::forcesReal();
    }

    /**
     * Rótulo curto do modo para a interface (resultados/relatório).
     */
    public static function label(): string
    {
        return self::forcesReal() ? 'Integração real' : 'Mock (simulado)';
    }

    /**
     * Tom do badge do modo (verde=real, amarelo=mock), alinhado ao `x-badge`.
     */
    public static function tone(): string
    {
        return self::forcesReal() ? 'success' : 'warning';
    }
}
