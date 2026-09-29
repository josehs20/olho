<?php

namespace App\Sources;

final class InvestigationInput
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $username = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?string $city = null,
        public readonly ?string $state = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? null,
            username: $data['username'] ?? null,
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
            city: $data['city'] ?? null,
            state: $data['state'] ?? null,
        );
    }
}
