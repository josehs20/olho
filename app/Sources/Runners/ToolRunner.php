<?php

namespace App\Sources\Runners;

/**
 * Contrato de execução de ferramentas externas.
 *
 * Adapters reais dependem desta interface (e não do Symfony Process), o que
 * permite testar o adapter sem executar a ferramenta.
 */
interface ToolRunner
{
    public function run(ToolCommand $command): ToolResult;
}
