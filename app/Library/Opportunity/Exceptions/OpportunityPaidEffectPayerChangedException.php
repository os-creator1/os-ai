<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Implementation Contract 19 §5.3 R-3, §12 19.E — a live re-resolution of
 * who pays for a `paid_effect` action, taken at execution, no longer funds
 * from the same source (EffectivePayer::sameFundingAs()) as the payer the
 * human approved.
 *
 * The payer can change between approval and execution — a payer assignment
 * edited, an Agency-rebill relationship terminated — and an approval never
 * silently carries over to a new payer who never saw the estimate. Because
 * it extends OpportunityAuthorityRevokedException, the execution is recorded
 * failed and the customer must request a fresh approval against the new
 * payer before it can run.
 */
class OpportunityPaidEffectPayerChangedException extends OpportunityAuthorityRevokedException
{
    public static function forAction(int $opportunityId, string $actionKey): self
    {
        return new self(
            "Action [{$actionKey}] on Opportunity [{$opportunityId}] is now funded by a different payer than the one approved."
        );
    }
}
