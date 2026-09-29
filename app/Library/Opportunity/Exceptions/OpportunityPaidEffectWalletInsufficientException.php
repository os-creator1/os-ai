<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Implementation Contract 19 §5.3, §12 19.E — the payer's wallet cannot
 * currently cover a `paid_effect` action's estimated cost.
 *
 * Raised in two places, deliberately: OpportunityManager::requestApproval()
 * refuses to even enter awaiting_approval on an insufficient wallet — "an
 * insufficient wallet is surfaced before approval, not discovered at
 * execution" — and OpportunityAuthorityGuard::assertPaidEffectIsCovered()
 * raises it again at execution if funds that were sufficient at approval no
 * longer are (a concurrent spend, a debt incurred meanwhile). Neither site
 * ever reserves or debits anything; this is a read-only sufficiency check.
 */
class OpportunityPaidEffectWalletInsufficientException extends OpportunityAuthorityRevokedException
{
    public static function forAction(int $opportunityId, string $actionKey): self
    {
        return new self(
            "Action [{$actionKey}] on Opportunity [{$opportunityId}]'s payer wallet cannot currently cover its estimated cost."
        );
    }
}
