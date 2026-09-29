<?php

namespace App\Sources;

final class InvestigationContext
{
    public function __construct(
        public readonly InvestigationInput $input,
        public readonly ?int $investigationRunId = null,
        public readonly ?int $sourceRunId = null,
    ) {}
}
