<?php

namespace App\Sources\Runners;

/**
 * Captura opcional da última execução de ferramenta externa.
 *
 * O `ProcessToolRunner` já devolve stdout/stderr/exit code em `ToolResult`, mas
 * o adapter consome esse objeto internamente e não o repassa ao orquestrador.
 * Para o painel admin de testes (prompt 47 §4) precisamos registrar a saída
 * bruta do processo; em vez de alterar cada adapter, o `RecordingToolRunner`
 * (decorator registrado no `AppServiceProvider`) guarda aqui o último resultado
 * **quando a captura está ligada**.
 *
 * Fora da janela `start()`/`stop()` nada é armazenado — o fluxo normal das
 * investigações não é afetado. O singleton vive no processo do worker, que
 * processa um job por vez.
 */
final class ToolRunRecorder
{
    private ?ToolResult $last = null;

    private bool $enabled = false;

    public function start(): void
    {
        $this->last = null;
        $this->enabled = true;
    }

    public function stop(): void
    {
        $this->enabled = false;
    }

    public function record(ToolResult $result): void
    {
        if ($this->enabled) {
            $this->last = $result;
        }
    }

    /**
     * Último resultado capturado na janela de captura (null quando não houve
     * chamada de ferramenta externa — ex.: fonte que consulta via HTTP).
     */
    public function last(): ?ToolResult
    {
        return $this->last;
    }
}
