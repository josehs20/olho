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
            'session_invalid' => 'A conexão com a plataforma não está autenticada; a consulta não foi realizada.',
            'challenge_required' => 'A plataforma exigiu uma verificação adicional; a consulta não foi realizada.',
            default => 'Não foi possível concluir a consulta nesta fonte.',
        };
    }

    /**
     * Mensagem para o **painel administrativo** (audiência interna).
     *
     * Administração pode ver a mensagem curada da própria exceção: ela traz a
     * orientação operacional (ex.: onde configurar a sessão) e é escrita para
     * exposição — sem credenciais, caminhos internos ou saída de processo.
     * Superfícies públicas (relatórios) continuam em `toPublicMessage()`.
     */
    public static function toOperatorMessage(Throwable $e): string
    {
        if ($e instanceof SourceException && trim($e->getMessage()) !== '') {
            return $e->getMessage();
        }

        return self::toPublicMessage($e);
    }
}
