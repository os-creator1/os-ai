<?php

namespace App\Library\Entitlement;

final readonly class UsageAuthorizationResult
{
    public function __construct(
        public bool $authorized,
        public ?string $reason = null,
    ) {
    }
}
