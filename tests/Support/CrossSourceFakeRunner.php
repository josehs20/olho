<?php

namespace Tests\Support;

use App\Sources\Runners\ToolCommand;
use App\Sources\Runners\ToolResult;
use App\Sources\Runners\ToolRunner;

/**
 * Runner determinístico para os adapters reais: devolve os relatórios reais das
 * ferramentas (fixtures) conforme o binário solicitado, sem executar nada.
 */
final class CrossSourceFakeRunner implements ToolRunner
{
    /**
     * @param  array<string, mixed>  $maigretReport
     */
    public function __construct(
        private readonly array $maigretReport,
        private readonly string $sherlockCsv,
        private readonly ?string $holeheCsv = null,
    ) {}

    public function run(ToolCommand $command): ToolResult
    {
        if (in_array('--version', $command->arguments, true)) {
            return new ToolResult(exitCode: 0, stdout: match ($command->binary) {
                'sherlock' => "Sherlock v0.16.2\n",
                'holehe' => 'usage: holehe', // o Holehe não expõe `--version`
                default => "maigret 0.6.6\n",
            }, stderr: '', durationMs: 5);
        }

        if (in_array('--help', $command->arguments, true)) {
            return new ToolResult(0, "usage: holehe\nholehe v1.61\n", '', 5);
        }

        if ($command->binary === 'holehe') {
            if ($this->holeheCsv === null) {
                return new ToolResult(1, '', 'sem relatório', 500);
            }

            file_put_contents($command->workingDirectory.'/holehe_1234_alvo_results.csv', $this->holeheCsv);

            return new ToolResult(1, '', "All results have been exported\n", 1500);
        }

        if ($command->binary === 'sherlock') {
            file_put_contents($command->workingDirectory.'/python.csv', $this->sherlockCsv);

            return new ToolResult(0, "[+] GitHub: https://www.github.com/python\n", '', 3000);
        }

        file_put_contents(
            $command->workingDirectory.'/report_python_simple.json',
            (string) json_encode($this->maigretReport),
        );

        return new ToolResult(0, '', '', 9000);
    }
}
