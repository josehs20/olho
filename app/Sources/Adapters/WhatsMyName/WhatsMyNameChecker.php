<?php

namespace App\Sources\Adapters\WhatsMyName;

use Closure;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Executa as verificações do WhatsMyName seguindo o schema do dataset.
 *
 * Regras de casamento (as mesmas do ecossistema que consome este dataset):
 *
 * - **encontrado**: `http_status == e_code` e o corpo contém `e_string`;
 * - **não encontrado**: `http_status == m_code` e o corpo contém `m_string`
 *   (`m_string` vazio significa "qualquer corpo");
 * - **inconclusivo**: qualquer outra resposta (contabilizada como erro, nunca
 *   como descoberta).
 *
 * Limites: quantidade de sites (aplicada pelo adapter), concorrência, timeout
 * por requisição e orçamento total da execução. Nada aqui contorna
 * autenticação, CAPTCHA ou paywall.
 */
class WhatsMyNameChecker
{
    /**
     * Relógio injetável (segundos) — permite testar o orçamento de tempo sem
     * depender de tempo real.
     *
     * @param  (Closure(): float)|null  $clock
     */
    public function __construct(private readonly ?Closure $clock = null) {}

    /**
     * @param  array<int, array<string, mixed>>  $sites
     * @param  array<string, mixed>  $options
     * @return array{found: array<int, array<string, mixed>>, checked: int, errors: int, timed_out: bool}
     */
    public function check(array $sites, string $username, array $options): array
    {
        $budget = max(0.0, (float) $options['execution_timeout']);
        $concurrency = max(1, (int) $options['concurrency']);
        $started = $this->now();

        $found = [];
        $checked = 0;
        $errors = 0;
        $timedOut = false;

        foreach (array_chunk($sites, $concurrency) as $batch) {
            if ($this->now() - $started >= $budget) {
                $timedOut = true;

                break;
            }

            foreach ($this->dispatch($batch, $username, $options) as $key => $response) {
                $site = $batch[(int) $key] ?? null;

                if ($site === null || ! $response instanceof Response) {
                    $errors++;

                    continue;
                }

                $checked++;

                $verdict = $this->verdict($site, $response->status(), $response->body());

                if ($verdict === 'found') {
                    $found[] = $this->entry($site, $username, $response->status());
                } elseif ($verdict === 'inconclusive') {
                    $errors++;
                }
            }
        }

        return ['found' => $found, 'checked' => $checked, 'errors' => $errors, 'timed_out' => $timedOut];
    }

    /**
     * Veredito de um site para o username verificado.
     *
     * @param  array<string, mixed>  $site
     */
    public function verdict(array $site, int $status, string $body): string
    {
        if ($status === (int) $site['e_code'] && $this->contains($body, (string) ($site['e_string'] ?? ''))) {
            return 'found';
        }

        if ($status === (int) $site['m_code'] && $this->contains($body, (string) ($site['m_string'] ?? ''))) {
            return 'not_found';
        }

        return 'inconclusive';
    }

    /**
     * @param  array<int, array<string, mixed>>  $batch
     * @param  array<string, mixed>  $options
     * @return array<array-key, Response|\Throwable>
     */
    private function dispatch(array $batch, string $username, array $options): array
    {
        $timeout = max(1, (int) $options['request_timeout']);

        return Http::pool(function (Pool $pool) use ($batch, $username, $options, $timeout): void {
            foreach ($batch as $index => $site) {
                $account = $this->account($site, $username);
                $url = $this->substitute((string) $site['uri_check'], $account);

                $request = $pool->as((string) $index)
                    ->timeout($timeout)
                    ->connectTimeout(min(5, $timeout))
                    ->withHeaders($this->headers($site, $options));

                $postBody = $site['post_body'] ?? null;

                if (is_string($postBody) && $postBody !== '') {
                    $request->withBody(
                        $this->substitute($postBody, $account),
                        $this->contentType($site),
                    )->post($url);

                    continue;
                }

                $request->get($url);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $site
     * @return array<string, mixed>
     */
    private function entry(array $site, string $username, int $status): array
    {
        $account = $this->account($site, $username);

        return [
            'site' => (string) $site['name'],
            'url' => $this->substitute((string) ($site['uri_pretty'] ?? $site['uri_check']), $account),
            'category' => is_string($site['cat'] ?? null) ? $site['cat'] : null,
            'http_status' => $status,
            'username' => $account,
            'method' => isset($site['post_body']) ? 'POST' : 'GET',
        ];
    }

    private function contains(string $body, string $needle): bool
    {
        return $needle === '' || str_contains($body, $needle);
    }

    private function now(): float
    {
        return $this->clock !== null ? (float) ($this->clock)() : microtime(true);
    }

    /**
     * O dataset define caracteres que a fonte não aceita no username
     * (`strip_bad_char`), removidos antes da substituição.
     *
     * @param  array<string, mixed>  $site
     */
    private function account(array $site, string $username): string
    {
        $strip = $site['strip_bad_char'] ?? null;

        return is_string($strip) && $strip !== '' ? str_replace(str_split($strip), '', $username) : $username;
    }

    private function substitute(string $template, string $account): string
    {
        return str_replace('{account}', rawurlencode($account), $template);
    }

    /**
     * @param  array<string, mixed>  $site
     * @param  array<string, mixed>  $options
     * @return array<string, string>
     */
    private function headers(array $site, array $options): array
    {
        $headers = is_array($site['headers'] ?? null) ? $site['headers'] : [];

        return array_merge(['User-Agent' => (string) $options['user_agent']], array_filter($headers, 'is_string'));
    }

    /**
     * @param  array<string, mixed>  $site
     */
    private function contentType(array $site): string
    {
        $headers = is_array($site['headers'] ?? null) ? $site['headers'] : [];
        $contentType = $headers['Content-Type'] ?? null;

        return is_string($contentType) && $contentType !== '' ? $contentType : 'application/json';
    }
}
