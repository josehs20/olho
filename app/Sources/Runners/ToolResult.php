<?php

namespace App\Sources\Runners;

/**
 * Resultado bruto da execução de uma ferramenta externa (docs/21):
 * código de saída, stdout/stderr capturados, duração e timeout.
 */
final class ToolResult
{
    public const EXIT_TIMEOUT = 124;

    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly int $durationMs,
        public readonly bool $timedOut = false,
        public readonly bool $outputTruncated = false,
    ) {}

    public function isSuccessful(): bool
    {
        return ! $this->timedOut && $this->exitCode === 0;
    }
}
