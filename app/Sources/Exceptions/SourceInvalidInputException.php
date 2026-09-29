<?php

namespace App\Sources\Exceptions;

use App\Sources\SourceRunStatus;

final class SourceInvalidInputException extends SourceException
{
    public function errorCode(): string
    {
        return 'invalid_input';
    }

    public function runStatus(): SourceRunStatus
    {
        return SourceRunStatus::Skipped;
    }
}
