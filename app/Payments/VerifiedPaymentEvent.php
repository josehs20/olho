<?php

namespace App\Payments;

final class VerifiedPaymentEvent
{
    public function __construct(
        public readonly string $externalEventId,
        public readonly string $externalId,
        public readonly string $status,
    ) {}
}
