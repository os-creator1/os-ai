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
 * OpportunityPaidEffectReapprovalRequiredException,
 * OpportunityManager::returnPaidEffectToAwaitingApproval() catches it and
 * returns the Opportunity directly to `awaiting_approval` with a freshly
 * computed estimate at the new figure — never the generic §5.4(2) failure
 * path back to `open`, and never the old approval silently honoured.
 */
class OpportunityPaidEffectPriceChangedException extends OpportunityPaidEffectReapprovalRequiredException
{
    public static function forAction(int $opportunityId, string $actionKey): self
    {
        return new self(
            "Action [{$actionKey}] on Opportunity [{$opportunityId}] now costs more than the approved ceiling, or its price version changed."
        );
    }

    public function reapprovalReasonCode(): string
    {
        return 'paid_effect_price_changed';
    }
}
