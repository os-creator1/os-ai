<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Implementation Contract 19 §5.3 R-3, §12 19.E — a live recomputation of a
 * `paid_effect` action's cost, taken at execution, priced higher than the
 * ceiling the human approved, or was computed under a retired price version.
 *
 * A ceiling is never silently raised (R-3): this refuses the execution
 * attempt rather than honour the new, higher figure. Because it extends
 * OpportunityAuthorityRevokedException, the execution is recorded failed and
 * — since every paid_effect action also fails
 * OpportunityActionRegistry::mayRetryUnderOriginalApproval() — the customer
 * must request a fresh approval, priced at the new figure, before it can run
 * (RFC-002 §5.4(4)'s existing re-approval rule, unchanged by this slice).
 */
class OpportunityPaidEffectPriceChangedException extends OpportunityAuthorityRevokedException
{
    public static function forAction(int $opportunityId, string $actionKey): self
    {
        return new self(
            "Action [{$actionKey}] on Opportunity [{$opportunityId}] now costs more than the approved ceiling, or its price version changed."
        );
    }
}
