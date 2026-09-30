<?php

namespace Tests\Support;

use App\Sources\Runners\ToolCommand;
use App\Sources\Runners\ToolResult;
use App\Sources\Runners\ToolRunner;
use Closure;

/**
 * Runner de teste: registra os comandos solicitados e delega a um callback,
 * permitindo exercitar adapters reais sem executar a ferramenta.
 */
final class FakeToolRunner implements ToolRunner
{
    /** @var array<int, ToolCommand> */
    public array $commands = [];

    /** @var Closure(ToolCommand): ToolResult|null */
    public ?Closure $onRun = null;

    public function run(ToolCommand $command): ToolResult
    {
        $this->commands[] = $command;

        if ($this->onRun !== null) {
            return ($this->onRun)($command);
        }

        return new ToolResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
