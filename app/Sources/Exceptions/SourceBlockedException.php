<?php

namespace App\Sources\Exceptions;

use App\Sources\SourceRunStatus;

final class SourceBlockedException extends SourceException
{
    public function errorCode(): string
    {
        return 'blocked';
    }

    public function runStatus(): SourceRunStatus
    {
        return SourceRunStatus::Failed;
    }
}
