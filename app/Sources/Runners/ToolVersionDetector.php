<?php

namespace App\Sources\Runners;

/**
 * Descobre a versão da ferramenta externa (`<binário> --version`), quando
 * disponível (docs/23), usando a mesma execução controlada do `ToolRunner`.
 */
class ToolVersionDetector
{
    public function __construct(private readonly ToolRunner $runner) {}

    /**
     * @param  array<int, string>  $arguments  argumento de versão da ferramenta
     *                                         (`--version`; algumas usam `--help`)
     */
    public function detect(
        string $binary,
        string $pattern,
        ?string $workingDirectory = null,
        int $timeout = 15,
        array $arguments = ['--version'],
    ): ?string {
        $result = $this->runner->run(new ToolCommand($binary, $arguments, $timeout, $workingDirectory));

        $output = trim($result->stdout."\n".$result->stderr);

        return preg_match($pattern, $output, $matches) === 1 ? $matches[1] : null;
    }
}
