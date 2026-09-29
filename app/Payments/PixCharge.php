<?php

namespace App\Payments;

use Illuminate\Support\Carbon;

final class PixCharge
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $payload,
        public readonly ?string $qrCode = null,
        public readonly ?Carbon $expiresAt = null,
    ) {}
}
