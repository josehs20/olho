<?php

namespace Tests\Unit;

use App\Sources\Runners\ToolOutput;
use App\Sources\Runners\ToolWarningsTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Tradução dos avisos públicos das ferramentas externas (inglês → português).
 *
 * As mensagens vêm das linhas `[!] ...` da saída real do Maigret
 * (`errors.notify_about_errors()` + `notify.warning(..., '!')`) — exatamente o
 * texto que o painel de testes, o histórico da investigação e o relatório
 * exibem.
 */
class ToolWarningsTranslatorTest extends TestCase
{
    public function test_translates_maigret_error_summaries_with_their_advice(): void
    {
        $this->assertSame(
            'Muitos erros do tipo "Proteção anti-bot" (7,89%). Tente trocar de endereço IP.',
            ToolWarningsTranslator::translate(
                'Too many errors of type "Bot protection" (7.89%). Try to switch to another ip address'
            ),
        );

        $this->assertSame(
            'Muitos erros do tipo "Acesso negado" (5,26%). Recomenda-se usar `--cloudflare-bypass` ou um proxy.',
            ToolWarningsTranslator::translate(
                'Too many errors of type "Access denied" (5.26%). It\'s recommended to use --cloudflare-bypass or a proxy, e.g. https://www.rapidproxy.io/?ref=soxoj'
            ),
        );

        $this->assertSame(
            'Muitos erros do tipo "Captcha" (12,0%). Tente trocar de endereço IP ou usar os cookies do serviço consultado.',
            ToolWarningsTranslator::translate(
                'Too many errors of type "Captcha" (12.0%). Try to switch to another IP address or to use service cookies'
            ),
        );

        // Tipo sem recomendação associada na ferramenta: só o cabeçalho.
        $this->assertSame(
            'Muitos erros do tipo "Formato de username não suportado" (10,53%).',
            ToolWarningsTranslator::translate('Too many errors of type "Unsupported username format" (10.53%)'),
        );

        // Tipo não catalogado é preservado dentro da mensagem em português.
        $this->assertSame(
            'Muitos erros do tipo "Site fora do ar" (3,10%).',
            ToolWarningsTranslator::translate('Too many errors of type "Site fora do ar" (3.10%).'),
        );
    }

    public function test_translates_fixed_maigret_messages(): void
    {
        $this->assertSame(
            'É possível consultar a lista completa de sites com a opção `-a`.',
            ToolWarningsTranslator::translate('You can run search by full list of sites with flag `-a`'),
        );

        $this->assertSame(
            'Use a opção `--print-errors` para ver os erros detalhados de cada site.',
            ToolWarningsTranslator::translate('You can see detailed site check errors with a flag `--print-errors`'),
        );
    }

    /**
     * Mensagem que a ferramenta venha a emitir e não esteja no catálogo continua
     * visível, sem tradução: nunca escondemos informação da execução.
     */
    public function test_keeps_unknown_warnings_untouched(): void
    {
        $this->assertSame('Site X is unavailable', ToolWarningsTranslator::translate('Site X is unavailable'));
        $this->assertSame('', ToolWarningsTranslator::translate('   '));
    }

    public function test_public_warnings_are_translated_and_deduplicated(): void
    {
        $stdout = <<<'TXT'
        [!] Too many errors of type "Bot protection" (7.89%). Try to switch to another ip address
        [*] Checking username jose_henrique ...
        [!] Too many errors of type "Bot protection" (7.89%). Try to switch to another ip address
        TXT;

        $this->assertSame(
            ['Muitos erros do tipo "Proteção anti-bot" (7,89%). Tente trocar de endereço IP.'],
            ToolOutput::publicWarnings($stdout),
        );
    }
}
