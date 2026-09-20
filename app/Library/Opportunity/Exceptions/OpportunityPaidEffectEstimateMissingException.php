<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Implementation Contract 19 §5.4(5) — a `paid_effect` action carries no
 * cost estimate on its approval record, so the executor refuses to run it.
 *
 * FAIL CLOSED, AND NOT YET REACHABLE. No action in the registry is
 * `paid_effect` today, so this never fires for the shipped `add_phone`
 * path. It exists now so the first paid action added later cannot reach an
 * external effect through a code path that simply forgot to ask what it
 * would cost. `19.E` supplies the estimate that satisfies it; a NULL
 * snapshot always means "no estimate", never "unlimited" (§8).
 */
class OpportunityPaidEffectEstimateMissingException extends OpportunityAuthorityRevokedException
{
    public static function forAction(int $opportunityId, string $actionKey): self
    {
        return new self(
            "Action [{$actionKey}] on Opportunity [{$opportunityId}] has a paid effect but no approved cost estimate."
        );
    }
}
