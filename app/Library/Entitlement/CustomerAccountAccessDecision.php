<?php

namespace App\Library\Entitlement;

use App\Enums\Entitlement\CustomerAccountAccessState;

/**
 * Chat F — Customer Account Access Gate.
 *
 * CustomerAccountAccessResolver's one return shape: the access state, a
 * machine-readable reason (the same vocabulary EntitlementManager::decide()
 * already uses — 'plan_inactive', 'plan_suspended' — never a new one
 * invented for this gate), and the customer-safe copy/recovery action for
 * the locked screen to render. Never a provider payload, a raw exception,
 * or an invented fact (a fake retention date, a fake "payment restores
 * this" promise) — every non-null field here is something the resolver
 * can prove from persisted state.
 */
final readonly class CustomerAccountAccessDecision
{
    public function __construct(
        public CustomerAccountAccessState $state,
        public string $reason,
        public ?string $heading = null,
        public ?string $message = null,
        public ?string $recoveryRouteName = null,
        public ?string $recoveryLabel = null,
    ) {
    }

    public function isLocked(): bool
    {
        return $this->state->isLocked();
    }

    public static function usable(): self
    {
        return new self(CustomerAccountAccessState::Usable, 'usable');
    }
}
