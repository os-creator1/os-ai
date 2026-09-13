<?php

namespace App\Library\Ai\Enums;

/**
 * Which allowance refused a budget refusal (`ai_usage_ledger.refusal_scope`).
 *
 * `refusal_reason` says WHY a call was refused; this says WHOSE allowance was
 * the limit. The two are different facts: an Agency Business-scoped call can
 * be refused because its own per-Business cap is spent, because the
 * Workspace cap is spent, or both — and "the account's AI is used up" is only
 * true in two of those three cases.
 *
 * Decided once, inside AiUsageLedgerManager::reserve(), from the same locked
 * figures that made the refusal, and never reconstructed afterwards.
 */
enum AiRefusalScope: string
{
    /** The Workspace cap alone was the limit. */
    case Workspace = 'workspace';

    /** The Business's own per-Business cap alone was the limit. */
    case Business = 'business';

    /** Both caps would have refused the call. */
    case WorkspaceAndBusiness = 'workspace_and_business';

    /** Only the interactive lane's share was spent (§10.4). */
    case InteractiveShare = 'interactive_share';

    public function includesWorkspace(): bool
    {
        return $this === self::Workspace || $this === self::WorkspaceAndBusiness;
    }

    public function includesBusiness(): bool
    {
        return $this === self::Business || $this === self::WorkspaceAndBusiness;
    }
}
