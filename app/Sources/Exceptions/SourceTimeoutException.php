<?php

namespace App\Sources\Exceptions;

use App\Sources\SourceRunStatus;

final class SourceTimeoutException extends SourceException
{
    public function errorCode(): string
    {
        return 'timeout';
    }

    public function runStatus(): SourceRunStatus
    {
        return SourceRunStatus::Timeout;
    }
}
