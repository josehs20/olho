<?php

namespace App\Sources\Runners;

/**
 * Executa um script auxiliar que devolve **objeto JSON + saída bruta**
 * (docs/21 e docs/42-instagrapi-integracao.md).
 *
 * É o equivalente do `ToolRunner` para integrações cujo binário não gera
 * arquivo de relatório: o script escreve um JSON no stdout e o processo devolve
 * o exit code real. A execução mantém as mesmas garantias do `ToolRunner`
 * (argv separado, timeout obrigatório, diretório isolado, captura limitada) e a
 * distinção **exit code ≠ estado de negócio** fica explícita:
 *
 * - `successful` reflete `exit code === 0` e ausência de timeout;
 * - `payload` traz o JSON (quando houver);
 * - `errorCode` / `errorMessage` / `sessionStatus` vêm dos campos que o script
 *   publica no próprio JSON — nunca de parsing de stdout nem de mensagens de
 *   outro processo.
 *
 * O runner **não monta minimalmente o ambiente** que envia credenciais: o
 * `environment` do `ToolCommand` é repassado palavra por palavra, porque é ali
 * que o adapter coloca o `sessionid` (nunca na argv, que aparece em `ps`).
 */
class ToolProcessRunner
{
    /** Mesmo teto do `ProcessToolRunner`: saída capturada não cresce sem limite. */
    private const MAX_OUTPUT_BYTES = 200_000;

    public function __construct(private readonly ToolRunner $runner) {}

    /**
     * @param  array<string, mixed>  $context  dados públicos para o log (nunca segredos)
     */
    public function run(ToolCommand $command, array $context = []): ToolProcessOutcome
    {
        $result = $this->runner->run($command);

        if ($result->timedOut) {
            return new ToolProcessOutcome(
                successful: false,
                errorCode: 'timeout',
                errorMessage: 'A execução excedeu o tempo limite.',
            );
        }

        $payload = $this->decode($result->stdout);

        if (! is_array($payload)) {
            return new ToolProcessOutcome(
                successful: false,
                errorCode: $result->exitCode === 0 ? 'invalid_output' : 'process_failed',
                errorMessage: $result->exitCode === 0
                    ? 'O script não devolveu um objeto JSON válido.'
                    : 'O script terminou com erro antes de devolver o resultado.',
                warnings: $this->warnings(trim($result->stderr)),
            );
        }

        return new ToolProcessOutcome(
            successful: $result->exitCode === 0,
            payload: $payload,
            warnings: $this->warnings($payload['warnings'] ?? []),
            errorCode: $this->string($payload['error_code'] ?? null),
            errorMessage: $this->string($payload['error'] ?? null),
            sessionStatus: $this->string($payload['session_status'] ?? null),
            accountUsername: $this->string($payload['account_username'] ?? null),
            userId: $this->string($payload['user_id'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $stdout): ?array
    {
        $stdout = trim($stdout);

        if ($stdout === '') {
            return null;
        }

        // Alguns scripts acrescentam logs antes do JSON: lê a partir da primeira
        // `{`. A saída já vem truncada no limite do runner e o adapter valida o
        // tamanho antes de confiar no conteúdo.
        $start = strpos($stdout, '{');

        if ($start === false) {
            return null;
        }

        $decoded = json_decode(substr($stdout, $start), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Avisos públicos informados pelo script (lista de strings).
     *
     * @return array<int, string>
     */
    private function warnings(mixed $warnings): array
    {
        if (! is_array($warnings)) {
            return [];
        }

        $clean = array_filter(array_map(
            fn (mixed $warning): ?string => is_string($warning) ? trim($warning) : null,
            $warnings,
        ), fn (?string $warning): bool => $warning !== null && $warning !== '');

        // Limita o que é persistido, como o `ToolOutput::publicWarnings`.
        return array_slice(array_values(array_unique($clean)), 0, 10);
    }

    private function string(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
