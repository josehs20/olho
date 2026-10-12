<?php

namespace App\Payments\PagBank;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Falha de comunicação/negócio com a API do PagBank.
 *
 * A mensagem nunca contém o token nem o payload sensível: do corpo da resposta
 * saem **apenas** as palavras-chave conhecidas da doc oficial e os campos de
 * enum/caminho de campo de `error_messages[]` (`error`, `parameter_name`), que
 * formam o diagnóstico da recusa. Nenhum valor informado pelo comprador é
 * reproduzido — o corpo cru jamais é ecoado.
 */
class PagBankException extends RuntimeException
{
    /** Quantidade máxima de erros do provedor citados no diagnóstico. */
    private const MAX_DIAGNOSTICS = 3;

    /**
     * @param  array<int, array{error: string, parameter: string|null}>  $diagnostics
     */
    public function __construct(string $message, int $code = 0, private readonly array $diagnostics = [])
    {
        parent::__construct($message, $code);
    }

    /**
     * Erro HTTP com orientação de correção (usado pelo teste de conexão do admin).
     *
     * O código da exceção é o status HTTP, permitindo tratamento específico.
     *
     * O PagBank devolve no corpo a lista `error_messages`, com o código do erro e
     * o **nome do parâmetro** recusado (ex.: `invalid_value` em
     * `payment_methods[0].type`). É isso que transforma um `400` opaco na causa real: o
     * diagnóstico acompanha a exceção para ser exibido/registrado por quem a
     * captura. O corpo **não** é ecoado — só os campos de enum (`error` e
     * `parameter_name`), descartando qualquer valor do comprador que venha junto.
     */
    public static function forResponse(string $context, Response $response): self
    {
        $status = $response->status();
        $diagnostics = self::parseDiagnostics($response);
        $hint = self::hint($status, (string) $response->body());

        $message = "PagBank: falha ao {$context} (HTTP {$status}).";

        if ($hint !== null) {
            $message .= ' '.$hint;
        }

        $summary = self::summarize($diagnostics);

        if ($summary !== null) {
            $message .= ' O PagBank apontou: '.$summary.'.';
        }

        return new self($message, $status, $diagnostics);
    }

    /**
     * Códigos/parâmetros recusados pelo PagBank (nunca o corpo cru).
     *
     * @return array<int, array{error: string, parameter: string|null}>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * Resumo em uma linha do diagnóstico (`invalid_value em payment_methods[0].type`).
     */
    public function diagnosticSummary(): ?string
    {
        return self::summarize($this->diagnostics);
    }

    /**
     * Extrai `error_messages[].error` + `parameter_name` da resposta.
     *
     * Os dois campos são enums/caminhos de campo da doc oficial: passam por uma
     * limpeza que mantém apenas letras, números, `_`, `.` e `[]`, de modo que
     * nenhum valor informado pelo comprador possa ser reproduzido na mensagem.
     *
     * @return array<int, array{error: string, parameter: string|null}>
     */
    private static function parseDiagnostics(Response $response): array
    {
        $messages = $response->json('error_messages');

        if (! is_array($messages)) {
            return [];
        }

        $diagnostics = [];

        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $error = self::clean($message['error'] ?? $message['code'] ?? null);

            if ($error === null) {
                continue;
            }

            $diagnostics[] = [
                'error' => $error,
                'parameter' => self::clean($message['parameter_name'] ?? null),
            ];

            if (count($diagnostics) >= self::MAX_DIAGNOSTICS) {
                break;
            }
        }

        return $diagnostics;
    }

    /**
     * @param  array<int, array{error: string, parameter: string|null}>  $diagnostics
     */
    private static function summarize(array $diagnostics): ?string
    {
        $parts = [];

        foreach ($diagnostics as $diagnostic) {
            $parts[] = $diagnostic['parameter'] === null
                ? $diagnostic['error']
                : $diagnostic['error'].' em '.$diagnostic['parameter'];
        }

        return $parts === [] ? null : implode('; ', $parts);
    }

    private static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = (string) preg_replace('/[^A-Za-z0-9_.\[\]]/', '', $value);
        $value = trim($value);

        return $value === '' ? null : substr($value, 0, 64);
    }

    /**
     * Orientação acionável a partir do status e de palavras-chave do corpo.
     *
     * @see https://developer.pagbank.com.br/docs/codigos-de-status
     * @see https://developer.pagbank.com.br/reference/codigos-de-erro-checkout
     */
    private static function hint(int $status, string $body): ?string
    {
        $body = strtolower($body);

        // Gateway do PagBank: devolvido em rota inexistente/legado (ex.:
        // `GET /public-keys?type=webhook` em vez de `/public-keys/webhook`) —
        // **antes** de avaliar o token — e também para `Authorization` fora do
        // formato `Bearer <token>`.
        if (str_contains($body, 'missing equal-sign') || str_contains($body, 'invalid key=value pair')) {
            return 'A requisição foi recusada no gateway do PagBank. Confirme o endpoint (GET /public-keys/webhook) e o header Authorization no formato "Bearer <token>" — o campo do token aceita apenas o valor, sem "Bearer", aspas ou quebra de linha.';
        }

        // Erros oficiais de credencial: `invalid_authorization_header` e
        // `UNAUTHORIZED` ("Invalid credential. Review AUTHORIZATION header").
        if (str_contains($body, 'invalid_authorization_header')
            || str_contains($body, 'invalid credential')
            || str_contains($body, 'unauthorized')) {
            return 'Token recusado pelo PagBank: confira se o valor salvo é o token do ambiente selecionado (Sandbox: Portal do Desenvolvedor → Tokens; Produção: Vendas → Integrações → Gerar Token).';
        }

        // Erro oficial `allowlist_access_required`: vendedor não homologado.
        if (str_contains($body, 'allowlist_access_required')) {
            return 'A conta ainda não foi liberada pelo PagBank (homologação) para o produto Checkout.';
        }

        return match (true) {
            // 400 é recusa de **parâmetro** (não de credencial): apontar o token
            // aqui levava o operador a reconfigurar o que já estava certo.
            $status === 400 => 'Requisição rejeitada pelo PagBank: confira os parâmetros enviados (valor, itens, método e URLs) — o diagnóstico ao final da mensagem aponta o campo recusado.',
            $status === 401 => 'Token inválido, expirado ou de outro ambiente: Sandbox (Portal do Desenvolvedor → Tokens) e Produção (Vendas → Integrações → Gerar Token) usam tokens diferentes.',
            $status === 403 => 'Sem permissão para este recurso: verifique a homologação da conta e a aprovação prévia do débito.',
            $status === 404 => 'Recurso não encontrado no ambiente configurado.',
            $status === 406 => 'Método ou negociação inválidos para o endpoint.',
            $status === 429 => 'Limite de requisições da conta atingido; tente novamente em instantes.',
            $status >= 500 => 'Instabilidade no PagBank; tente novamente.',
            default => null,
        };
    }
}
