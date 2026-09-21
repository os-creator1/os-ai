<?php

declare(strict_types=1);

namespace App\Enums\Opportunity;

/**
 * Implementation Contract 19 §5.4(1) — who PROPOSED the action an execution
 * carries out. Until 19.D this was the bare literal `'customer'` written at
 * three call sites (RFC-002 §31); making it an enum is what lets the COO
 * become a distinct proposer in `19.F` without a magic string appearing in
 * a fourth place.
 *
 * PROPOSING IS NOT CONFIRMING. This type answers only "where did the
 * recommendation come from". The confirming principal — the human whose
 * explicit act turns an approval into an execution — is recorded separately
 * as `opportunity_action_executions.initiated_by_user_id`, and §5.4(1)
 * requires it to always be a real human: an execution proposed by the COO
 * still only ever runs because a person confirmed it. `Coo` is therefore
 * never a valid CONFIRMING principal, which
 * OpportunityAuthorityGuard::assertConfirmingPrincipalIsHuman() enforces.
 *
 * Self-approval by one human (the same person requests and confirms) stays
 * permitted: §23 requires *a* human confirmation, not four eyes.
 */
enum OpportunityInitiatedByType: string
{
    /** A person acting in the customer portal proposed and confirmed it. */
    case Customer = 'customer';

    /**
     * The AI COO proposed it (`19.F`). Declared now so §5.4(1)'s "the
     * confirming principal is never `coo`" invariant is enforceable before
     * anything can produce the value — a guard written after the first
     * producer ships is a guard that shipped too late.
     */
    case Coo = 'coo';

    /**
     * The proposers that may also be the confirming principal. Deliberately
     * an allowlist rather than `!== Coo`: a future proposer is refused as a
     * confirmer until someone states that it is human.
     *
     * @return array<int, self>
     */
    public static function confirmingPrincipals(): array
    {
        return [self::Customer];
    }

    public function mayConfirm(): bool
    {
        return in_array($this, self::confirmingPrincipals(), true);
    }
}
