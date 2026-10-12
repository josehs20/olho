<?php

namespace App\Sources\Exceptions;

use App\Sources\SourceRunStatus;

/**
 * Sessão do Instagram ausente, inválida, expirada ou desafiada (prompt 48 §2/§3).
 *
 * A credencial da conta de serviço pode não existir (ainda não configurada) ou
 * ser revogada pelo Instagram sem aviso: nos dois casos **nada** foi consultado
 * sobre o alvo, então a cobrança precisa ser estornada e o operador avisado no
 * painel de integrações.
 *
 * Por isso o `runStatus()` é `Skipped` (e não `Failed`): `source_runs.attempts`
 * permanece em 0, exatamente como nas fontes não aplicáveis, e o
 * `ReleaseReservedCreditsJob` devolve a reserva. A distinção fica no
 * `error_code`, que alimenta o indicador de status do painel.
 */
final class SourceSessionException extends SourceException
{
    public function errorCode(): string
    {
        return 'session_invalid';
    }

    public function runStatus(): SourceRunStatus
    {
        return SourceRunStatus::Skipped;
    }
}
