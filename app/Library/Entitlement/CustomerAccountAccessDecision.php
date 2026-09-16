<?php

namespace App\Library\Entitlement;

use App\Enums\Entitlement\CustomerAccountAccessState;
use Carbon\CarbonInterface;

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
        /**
         * Contract 03 §5 — two optional, NON-BLOCKING lifecycle hints. Both
         * only ever accompany a Usable decision: a Workspace in Trial or in
         * Grace keeps full access (Blueprint §27), and a consumer that does
         * not care about billing prompts never has to look at either field.
         * Both stay null for every decision today's three states produce, so
         * no existing consumer changes.
         */
        public ?CarbonInterface $trialEndsAt = null,
        public ?CarbonInterface $graceEndsAt = null,
    ) {
    }

    public function isLocked(): bool
    {
        return $this->state->isLocked();
    }

    /** Usable, and a trial is outstanding — a billing prompt may be shown. */
    public function isInTrial(): bool
    {
        return $this->trialEndsAt !== null;
    }

    /** Usable, and the 3-day Grace window is running — a billing prompt should be shown. */
    public function isInGracePeriod(): bool
    {
        return $this->graceEndsAt !== null;
    }

    public static function usable(): self
    {
        return new self(CustomerAccountAccessState::Usable, 'usable');
    }
}
