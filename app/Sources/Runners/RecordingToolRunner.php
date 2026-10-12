<?php

namespace App\Sources\Runners;

/**
 * Decorator do `ToolRunner` que registra o resultado da execução no
 * `ToolRunRecorder` (prompt 47). Repassa o resultado sem alterá-lo: comportamento
 * idêntico ao runner real, apenas com observação adicional.
 */
final class RecordingToolRunner implements ToolRunner
{
    public function __construct(
        private readonly ToolRunner $runner,
        private readonly ToolRunRecorder $recorder,
    ) {}

    public function run(ToolCommand $command): ToolResult
    {
        $result = $this->runner->run($command);

        $this->recorder->record($result);

        return $result;
    }
}
