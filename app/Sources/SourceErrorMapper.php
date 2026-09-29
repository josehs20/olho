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
}
