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
 * silently carries over to a new payer who never saw the estimate (a new
 * payer never inherits the old payer's approval). Because it extends
 * OpportunityPaidEffectReapprovalRequiredException,
 * OpportunityManager::returnPaidEffectToAwaitingApproval() catches it and
 * returns the Opportunity to `awaiting_approval` with a freshly computed
 * estimate against the NEW payer — never the generic §5.4(2) failure path,
 * and never the old approval silently carried forward.
 */
class OpportunityPaidEffectPayerChangedException extends OpportunityPaidEffectReapprovalRequiredException
{
    public static function forAction(int $opportunityId, string $actionKey): self
    {
        return new self(
            "Action [{$actionKey}] on Opportunity [{$opportunityId}] is now funded by a different payer than the one approved."
        );
    }

    public function reapprovalReasonCode(): string
    {
        return 'paid_effect_payer_changed';
    }
}
