<?php

namespace App\Sources\Runners;

/**
 * Comando de ferramenta externa executado como **argv separado** (docs/21).
 *
 * O binário e os argumentos são definidos pelo adapter (a partir da
 * configuração), nunca pela entrada do usuário encadeada em shell.
 */
final class ToolCommand
{
    /**
     * @param  array<int, string>  $arguments
     * @param  array<string, string>  $environment  variáveis extras do processo
     */
    public function __construct(
        public readonly string $binary,
        public readonly array $arguments = [],
        public readonly int $timeout = 60,
        public readonly ?string $workingDirectory = null,
        public readonly array $environment = [],
    ) {}

    /**
     * Representação para log/observabilidade. A execução real usa argv.
     */
    public function toLogLine(): string
    {
        return implode(' ', array_map(
            static fn (string $part): string => escapeshellarg($part),
            [$this->binary, ...$this->arguments],
        ));
    }
}
