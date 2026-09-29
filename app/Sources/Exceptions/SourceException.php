<?php

namespace App\Sources\Exceptions;

use App\Sources\SourceRunStatus;
use RuntimeException;

abstract class SourceException extends RuntimeException
{
    abstract public function errorCode(): string;

    abstract public function runStatus(): SourceRunStatus;
}
