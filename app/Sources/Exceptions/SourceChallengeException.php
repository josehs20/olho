<?php

namespace App\Sources\Exceptions;

use App\Sources\SourceRunStatus;

/**
 * O Instagram exigiu verificação humana (challenge) para a conta de serviço
 * (prompt 48 §2). A sessão existe, mas não pode ser usada até que o desafio seja
 * resolvido manualmente pelo operador — nenhuma consulta é feita ao alvo.
 */
final class SourceChallengeException extends SourceException
{
    public function errorCode(): string
    {
        return 'challenge_required';
    }

    public function runStatus(): SourceRunStatus
    {
        return SourceRunStatus::Skipped;
    }
}
