<?php

namespace App\Sources\Runners;

/**
 * Tradução para português dos avisos públicos das ferramentas externas.
 *
 * As ferramentas (Maigret, Sherlock, Holehe) emitem seus avisos em inglês nas
 * linhas `[!] ...` da saída — texto que o painel admin de testes, o detalhe da
 * investigação e o relatório exibem ao usuário final. O catálogo abaixo cobre as
 * mensagens que essas ferramentas realmente produzem: os tipos de erro do
 * Maigret (`errors.ERRORS_TYPES`) e as recomendações associadas, mais os avisos
 * informativos fixos.
 *
 * Aviso não catalogado **não é descartado nem reescrito**: volta como veio,
 * porque esconder informação da execução é pior que exibi-la sem tradução.
 */
final class ToolWarningsTranslator
{
    /**
     * Tipo de erro relatado pela ferramenta → rótulo em português.
     *
     * @var array<string, string>
     */
    private const ERROR_TYPES = [
        'captcha' => 'Captcha',
        'bot protection' => 'Proteção anti-bot',
        'access denied' => 'Acesso negado',
        'rate limited' => 'Limite de requisições',
        'login required' => 'Login obrigatório',
        'censorship' => 'Censura na rede',
        'request timeout' => 'Tempo de requisição esgotado',
        'request failed' => 'Requisição falhou',
        'unsupported username format' => 'Formato de username não suportado',
        'connecting failure' => 'Falha de conexão',
        'connecting failure (dns)' => 'Falha de conexão (DNS)',
        'connection lost' => 'Conexão perdida',
        'server disconnected' => 'Servidor desconectou',
        'server' => 'Erro do servidor',
        'ssl' => 'Falha de SSL/TLS',
        'http' => 'Erro HTTP',
        'proxy' => 'Proxy',
        'payload' => 'Resposta inválida',
        'unexpected' => 'Resposta inesperada',
        'interrupted' => 'Verificação interrompida',
        'site-specific' => 'Erro específico do site',
        'check is disabled' => 'Verificação desativada no site',
        'skipped' => 'Verificação ignorada',
        'webgate' => 'Portal de verificação do site',
        'webgate timeout' => 'Tempo esgotado no portal de verificação',
        'webgate unavailable' => 'Portal de verificação do site indisponível',
        'webgate unreachable' => 'Portal de verificação do site inacessível',
        'unknown' => 'Erro desconhecido',
    ];

    /**
     * Recomendação da ferramenta → orientação em português. A busca é por
     * fragmento (`str_contains`), então **a ordem importa**: o trecho mais
     * específico precisa vir antes do mais genérico.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const ADVICE = [
        ['to use service cookies', 'Tente trocar de endereço IP ou usar os cookies do serviço consultado.'],
        ['try to switch to another ip address', 'Tente trocar de endereço IP.'],
        ['cloudflare_bypass is enabled', 'O desvio de Cloudflare está ativado, mas nenhum solucionador respondeu: inicie um solucionador (ex.: FlareSolverr) ou desative a opção `--cloudflare-bypass`.'],
        ['dns resolution failed', 'A resolução de DNS falhou para a maioria dos sites; verifique a conexão, VPN ou firewall (alternativa: resolvedor público, ex.: 1.1.1.1) e tente `--dns-resolver threaded`.'],
        ['cloudflare-bypass or a proxy', 'Recomenda-se usar `--cloudflare-bypass` ou um proxy.'],
        ['to lower parallelism, or repeat the search later', 'Tente `-n 10` para reduzir o paralelismo ou repita a busca mais tarde.'],
        ['lower parallelism', 'Verifique a conexão com a internet; se apenas parte dos sites falhar, tente `-n 10` para reduzir o paralelismo.'],
        ['cookies-jar-file', 'Adicione cookies de autorização com a opção `--cookies-jar-file`.'],
        ['increase timeout', 'Aumente o tempo limite ou troque de provedor de internet.'],
        ['internet service provider', 'Troque de provedor de internet.'],
    ];

    /**
     * Aviso informativo fixo → português. A chave é comparada sem caixa e sem
     * pontuação final.
     *
     * @var array<string, string>
     */
    private const MESSAGES = [
        'you can run search by full list of sites with flag `-a`' => 'É possível consultar a lista completa de sites com a opção `-a`.',
        'you can see detailed site check errors with a flag `--print-errors`' => 'Use a opção `--print-errors` para ver os erros detalhados de cada site.',
        'verbose error statistics:' => 'Estatísticas detalhadas de erros:',
        'no usernames to check, exiting' => 'Nenhum username para verificar; a consulta foi encerrada.',
        'no sites to check, exiting' => 'Nenhum site para verificar; a consulta foi encerrada.',
    ];

    /**
     * Converte um aviso público da ferramenta para português. Mensagem
     * desconhecida é devolvida sem alteração (apenas sem espaços nas pontas).
     */
    public static function translate(string $warning): string
    {
        $warning = trim($warning);

        if ($warning === '') {
            return $warning;
        }

        return self::errorSummary($warning) ?? self::known($warning) ?? $warning;
    }

    /**
     * `Too many errors of type "X" (N%). <recomendação>` → versão em português,
     * com o percentual no separador decimal brasileiro.
     */
    private static function errorSummary(string $warning): ?string
    {
        $matched = preg_match(
            '/^too many errors of type\s+"(?<type>[^"]+)"\s+\((?<percent>[\d.,]+)%\)\.?\s*(?<advice>.*)$/iu',
            $warning,
            $parts,
        );

        if ($matched !== 1) {
            return null;
        }

        $type = trim($parts['type']);
        $label = self::ERROR_TYPES[mb_strtolower($type)] ?? $type;
        $message = sprintf('Muitos erros do tipo "%s" (%s%%).', $label, str_replace('.', ',', trim($parts['percent'])));

        $advice = self::advice($parts['advice']);

        return $advice === null ? $message : $message.' '.$advice;
    }

    /**
     * Recomendação que a ferramenta anexa ao aviso, se reconhecida.
     */
    private static function advice(string $advice): ?string
    {
        $advice = mb_strtolower(trim($advice));

        if ($advice === '') {
            return null;
        }

        foreach (self::ADVICE as [$needle, $translation]) {
            if (str_contains($advice, $needle)) {
                return $translation;
            }
        }

        return self::known($advice);
    }

    /**
     * Aviso fixo reconhecido (sem partes variáveis).
     */
    private static function known(string $message): ?string
    {
        $key = rtrim(mb_strtolower(trim($message)), ".! \t\n\r\0\x0B");

        return self::MESSAGES[$key] ?? null;
    }
}
