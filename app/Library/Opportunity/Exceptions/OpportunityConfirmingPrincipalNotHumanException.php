<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Implementation Contract 19 §5.4(1) — the principal confirming this
 * approval is not a human.
 *
 * The contract names a `confirmed_by_user_id`; the schema's actual field is
 * `opportunity_action_executions.initiated_by_user_id`, the NOT NULL user
 * whose explicit act created the execution. The invariant is enforced
 * against that field: an absent user, or a confirming principal whose type
 * is `coo`, is rejected. Self-approval by one human stays permitted.
 */
class OpportunityConfirmingPrincipalNotHumanException extends OpportunityAuthorityRevokedException
{
    public static function forOpportunity(int $opportunityId, string $principalType): self
    {
        return new self(
            "Opportunity [{$opportunityId}] cannot be confirmed by principal type [{$principalType}]; a human must confirm."
        );
    }
}
