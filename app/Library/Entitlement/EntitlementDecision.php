<?php

namespace App\Library\Entitlement;

final readonly class EntitlementDecision
{
    public function __construct(
        public bool $allowed,
        public ?string $reason,
    ) {
    }
}
