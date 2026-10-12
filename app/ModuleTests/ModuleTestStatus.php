<?php

namespace App\ModuleTests;

use App\Sources\SourceRunStatus;

/**
 * Status de um teste individual de módulo (prompt 47 §5).
 *
 * Reutiliza a nomenclatura persistida de `source_runs` sempre que o significado
 * é o mesmo (`completed` = sucesso), acrescentando `partial`, que descreve a
 * execução que terminou com ressalva (ex.: varredura interrompida por timeout
 * mas com resultados parciais já entregues pelo adapter).
 */
enum ModuleTestStatus: string
{
    case Pending = 'pending';

    case Running = 'running';

    case Success = 'completed';

    /** Executou e entregou resultados, porém com ressalva (ex.: parcial). */
    case Partial = 'partial';

    case Failed = 'failed';

    case Timeout = 'timeout';

    case Skipped = 'skipped';

    /**
     * Rótulo exibido no painel (§5).
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'PENDING',
            self::Running => 'RUNNING',
            self::Success => 'SUCCESS',
            self::Partial => 'PARTIAL',
            self::Failed => 'FAILED',
            self::Timeout => 'TIMEOUT',
            self::Skipped => 'SKIPPED',
        };
    }

    /**
     * Execução encerrada (nada mais a acompanhar).
     */
    public function isTerminal(): bool
    {
        return ! in_array($this, [self::Pending, self::Running], true);
    }

    public static function fromSourceRunStatus(SourceRunStatus $status): self
    {
        return match ($status) {
            SourceRunStatus::Pending => self::Pending,
            SourceRunStatus::Running => self::Running,
            SourceRunStatus::Completed => self::Success,
            SourceRunStatus::Failed => self::Failed,
            SourceRunStatus::Timeout => self::Timeout,
            SourceRunStatus::Skipped => self::Skipped,
        };
    }
}
