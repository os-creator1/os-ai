<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Implementation Contract 19 §5.4(2) gate 5 — the Workspace/Business is no
 * longer entitled to the feature this action belongs to
 * (`EntitlementManager::decide`), because the plan changed, an override was
 * withdrawn or the feature was switched off since the approval.
 */
class OpportunityEntitlementRevokedException extends OpportunityAuthorityRevokedException
{
    public static function forFeature(int $businessId, string $featureKey, string $reason): self
    {
        return new self(
            "Business [{$businessId}] is no longer entitled to [{$featureKey}] ({$reason})."
        );
    }
}
