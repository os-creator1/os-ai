<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Implementation Contract 19 §5.4(3) — the approval window elapsed before
 * the approved action took effect, so the approval must be re-requested.
 *
 * Deliberately an authority refusal rather than a state error: an expired
 * approval is authority that has lapsed, and it is checked at BOTH the
 * confirmation and every execution attempt, since a job can be delivered
 * arbitrarily later than the confirmation that queued it.
 */
class OpportunityApprovalExpiredException extends OpportunityAuthorityRevokedException
{
    public static function forOpportunity(int $opportunityId, string $expiredAt): self
    {
        return new self(
            "Opportunity [{$opportunityId}]'s approval expired at [{$expiredAt}] and must be requested again."
        );
    }
}
