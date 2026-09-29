<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientCreditsException extends RuntimeException
{
    public function __construct(
        public readonly int $balance,
        public readonly int $requested,
    ) {
        parent::__construct("Saldo insuficiente: possui {$balance}, solicitado {$requested}.");
    }
}
