<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidReservationStateException extends RuntimeException
{
    public function __construct(public readonly string $current)
    {
        parent::__construct("Reserva em estado '{$current}' não pode ser alterada.");
    }
}
