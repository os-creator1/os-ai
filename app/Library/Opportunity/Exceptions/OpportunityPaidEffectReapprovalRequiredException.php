<?php

declare(strict_types=1);

namespace App\Library\Opportunity\Exceptions;

/**
 * Implementation Contract 19 §5.3 R-3, §12 19.E — shared base for the two
 * paid-effect refusals that must return the Opportunity to
 * `awaiting_approval` with a FRESH estimate, rather than the generic §5.4(2)
 * failure lifecycle (back to `open`, no new estimate).
 *
 * WHY THESE TWO, AND ONLY THESE TWO. A changed price or a changed payer are
 * both "the thing the human approved no longer describes reality" — the
 * honest response is a NEW estimate awaiting a NEW human confirmation, never
 * a silently-reused old approval (R-3) and never a fabricated one. A missing
 * estimate or an insufficient wallet are different: there is no new, valid
 * figure to show, so those two stay on the ordinary §5.4(2) path
 * (`OpportunityAuthorityRevokedException` directly) — entering
 * `awaiting_approval` with no real estimate, or with one already known
 * insufficient, would be exactly the fabrication R-4 forbids.
 *
 * `reapprovalReasonCode()` is the typed, auditable `opportunity_transitions`
 * reason for the resulting transition — read by
 * `OpportunityManager::returnPaidEffectToAwaitingApproval()`, never a parsed
 * exception message.
 */
abstract class OpportunityPaidEffectReapprovalRequiredException extends OpportunityAuthorityRevokedException
{
    abstract public function reapprovalReasonCode(): string;
}
