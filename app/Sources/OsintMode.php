<?php

namespace App\Sources;

/**
 * Parâmetro de controle central da execução OSINT (mock vs. integração real).
 *
 * Um único interruptor (`OSINT_MODE`) decide, para todo o pipeline, se as
 * investigações usam os adapters determinísticos (mock, sem rede) ou disparam
 * as integrações reais (Maigret, Sherlock, WhatsMyName, Holehe e o
 * enriquecimento do Instagram via instagrapi).
 *
 * Modos:
 *  - `real` (padrão): ignora qualquer `mock` e força os adapters reais de
 *     todas as fontes. É a execução "definitiva" — nada de `example.com`. Sem
 *     `OSINT_MODE` definido, o sistema já investiga real (sem mock).
 *  - `mock`: força os adapters determinísticos de todas as fontes (comportamento
 *     de teste/desenvolvimento, sem tocar na rede).
 *  - `auto`: cada fonte segue o seu próprio driver
 *     (`config('sources.<slug>.driver')`), exatamente o comportamento anterior —
 *     por isso a suíte de testes e os ambientes existentes não mudam.
 *
 * O modo é resolvido pela configuração (`config/osint.php` → `OSINT_MODE`), nunca
 * por entrada do usuário: nenhum valor vindo do request escolhe real vs. mock.
 */
final class OsintMode
{
    /** Cada fonte decide pelo seu próprio driver (comportamento histórico). */
    public const MODE_AUTO = 'auto';

    /** Força todos os adapters determinísticos (sem rede). */
    public const MODE_MOCK = 'mock';

    /** Força as integrações reais (ignora os mocks). */
    public const MODE_REAL = 'real';

    /**
     * Modo efetivo, normalizado para um dos três valores aceitos. Qualquer valor
     * desconhecido cai em `auto`, o que preserva o comportamento anterior.
     */
    public static function mode(): string
    {
        $mode = strtolower(trim((string) config('osint.mode', self::MODE_AUTO)));

        return in_array($mode, [self::MODE_AUTO, self::MODE_MOCK, self::MODE_REAL], true)
            ? $mode
            : self::MODE_AUTO;
    }

    /** `true` quando o interruptor força a integração real. */
    public static function forcesReal(): bool
    {
        return self::mode() === self::MODE_REAL;
    }

    /** `true` quando o interruptor força os mocks. */
    public static function forcesMock(): bool
    {
        return self::mode() === self::MODE_MOCK;
    }

    /** `true` quando cada fonte segue o seu próprio driver (sem override global). */
    public static function isAuto(): bool
    {
        return self::mode() === self::MODE_AUTO;
    }

    /**
     * Override global do registry: `true` força o adapter real, `false` força o
     * mock e `null` mantém a decisão por fonte (modo `auto`).
     */
    public static function forced(): ?bool
    {
        return match (self::mode()) {
            self::MODE_REAL => true,
            self::MODE_MOCK => false,
            default => null,
        };
    }

    /**
     * Rótulo curto do modo para a interface (resultados/relatório).
     */
    public static function label(): string
    {
        return match (self::mode()) {
            self::MODE_REAL => 'Integração real',
            self::MODE_MOCK => 'Mock (simulado)',
            default => 'Automático (por fonte)',
        };
    }

    /**
     * Tom do badge do modo (verde=real, amarelo=mock, violet=auto), alinhado ao
     * `x-badge`.
     */
    public static function tone(): string
    {
        return match (self::mode()) {
            self::MODE_REAL => 'success',
            self::MODE_MOCK => 'warning',
            default => 'violet',
        };
    }
}
