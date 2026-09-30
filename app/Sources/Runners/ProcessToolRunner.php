<?php

namespace App\Sources\Runners;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Executa ferramentas externas com as garantias de docs/21:
 *
 * - argumentos separados (argv), nunca shell concatenado;
 * - timeout obrigatório;
 * - diretório de trabalho isolado;
 * - ambiente controlado (nada da aplicação vaza para o processo);
 * - stdout/stderr capturados com limite de tamanho;
 * - código de saída explícito (`ToolResult::EXIT_TIMEOUT` em timeout).
 */
class ProcessToolRunner implements ToolRunner
{
    private const MAX_OUTPUT_BYTES = 200_000;

    public function run(ToolCommand $command): ToolResult
    {
        $process = new Process(
            [$command->binary, ...$command->arguments],
            $command->workingDirectory,
            $this->environment($command),
            null,
            $command->timeout,
        );

        $started = microtime(true);
        $timedOut = false;

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $timedOut = true;
        }

        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();

        return new ToolResult(
            exitCode: $timedOut ? ToolResult::EXIT_TIMEOUT : (int) $process->getExitCode(),
            stdout: $this->truncate($stdout),
            stderr: $this->truncate($stderr),
            durationMs: (int) round((microtime(true) - $started) * 1000),
            timedOut: $timedOut,
            outputTruncated: strlen($stdout) > self::MAX_OUTPUT_BYTES || strlen($stderr) > self::MAX_OUTPUT_BYTES,
        );
    }

    /**
     * Ambiente mínimo: PATH, HOME no diretório isolado e locale. Nenhuma
     * variável da aplicação (APP_KEY, tokens, credenciais) é repassada.
     *
     * @return array<string, string>
     */
    private function environment(ToolCommand $command): array
    {
        return array_filter([
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME' => $command->workingDirectory,
            'LANG' => 'C.UTF-8',
            'LC_ALL' => 'C.UTF-8',
            'PYTHONUNBUFFERED' => '1',
            ...$command->environment,
        ], static fn (mixed $value): bool => is_string($value));
    }

    private function truncate(string $output): string
    {
        return strlen($output) > self::MAX_OUTPUT_BYTES
            ? substr($output, 0, self::MAX_OUTPUT_BYTES)
            : $output;
    }
}
