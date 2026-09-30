<?php

namespace App\Sources;

use App\Sources\Exceptions\SourceException;
use Throwable;

final class SourceErrorMapper
{
    public static function toErrorCode(Throwable $e): string
    {
        return $e instanceof SourceException ? $e->errorCode() : 'error';
    }

    public static function toRunStatus(Throwable $e): SourceRunStatus
    {
        return $e instanceof SourceException ? $e->runStatus() : SourceRunStatus::Failed;
    }

    /**
     * Mensagem pública da falha, derivada do código de erro.
     *
     * Nunca expõe detalhes internos da ferramenta (caminhos, comandos, saída de
     * processo, credenciais); o detalhe técnico fica no log sanitizado.
     */
    public static function toPublicMessage(Throwable $e): string
    {
        return match (self::toErrorCode($e)) {
            'timeout' => 'A fonte excedeu o tempo limite.',
            'blocked' => 'A fonte bloqueou a consulta.',
            'invalid_input' => 'A entrada informada não é válida para esta fonte.',
            'unavailable' => 'A fonte está indisponível no momento.',
            default => 'Não foi possível concluir a consulta nesta fonte.',
        };
    }
}
