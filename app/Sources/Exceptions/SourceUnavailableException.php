<?php

namespace App\Sources\Exceptions;

use App\Sources\SourceRunStatus;

final class SourceUnavailableException extends SourceException
{
    public function errorCode(): string
    {
        return 'unavailable';
    }

    public function runStatus(): SourceRunStatus
    {
        return SourceRunStatus::Failed;
    }
}
