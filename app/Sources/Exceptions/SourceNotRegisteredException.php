<?php

namespace App\Sources\Exceptions;

use App\Sources\SourceRunStatus;

/**
 * Fonte habilitada na base (ou no painel admin) sem adapter registrado no
 * `SourceRegistry`.
 *
 * É falha **estruturada** da fonte (`unavailable`): o run continua e termina
 * como `partial`, em vez de derrubar o job com uma exceção genérica.
 */
final class SourceNotRegisteredException extends SourceException
{
    public function errorCode(): string
    {
        return 'unavailable';
    }

    public function runStatus(): SourceRunStatus
    {
        return SourceRunStatus::Failed;
    }

    public static function forSlug(string $slug): self
    {
        return new self("Nenhum adapter registrado para a fonte '{$slug}'.");
    }
}
