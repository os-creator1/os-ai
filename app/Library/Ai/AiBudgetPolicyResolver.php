<?php

namespace App\Library\Ai;

use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Ai\Exceptions\AiPlatformBudgetMisconfiguredException;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Workspace;
use Illuminate\Support\Carbon;

/**
 * Contract §11.1 — the ONLY reader of `config('ai.budgets')` and
 * `config('ai.policy_version')`. Every amount used anywhere in the AI
 * budget system passes through this class; T-BUD-7's architecture test
 * asserts no amount literal exists anywhere else.
 *
 * Reuses `EntitlementManager::getWorkspaceEntitlementSummary()` (§1.2)
 * rather than re-querying `workspace_plan_assignments` — the same
 * seam WorkspacePlanPresenter already depends on, so this resolver and
 * every other entitlement-aware surface can never disagree about a
 * Workspace's tier or status.
 *
 * Trial (D-1, §11.1a) is deliberately NOT handled here. `trialing` does
 * not exist as a WorkspacePlanAssignmentStatus case yet — RFC-004 gains
 * it only when slice T-1 lands, alongside the resolver branch that
 * selects the `trial` policy row below. Until then no Workspace can be
 * on trial (K-4), so this resolver correctly never selects it. The
 * `trial` config row exists now so config/ai.php already has one home
 * for the amount T-1 will need; it is unreachable code, not implemented
 * code, until that case exists.
 */
final class AiBudgetPolicyResolver
{
    public function __construct(
        private readonly EntitlementManager $entitlementManager,
    ) {
    }

    public function resolveFor(Workspace $workspace): AiBudgetPolicy
    {
        $policyVersion = (int) config('ai.policy_version');
        $summary = $this->entitlementManager->getWorkspaceEntitlementSummary($workspace);

        if (! $summary->isAssigned || $summary->tier === null || $summary->status !== WorkspacePlanAssignmentStatus::Active) {
            // Unassigned, inactive or suspended: zero cap, every call
            // refused, deterministic behaviour continues (§11.1, T-BUD-6).
            return new AiBudgetPolicy(
                policyKey: 'unassigned',
                policyVersion: $policyVersion,
                periodKey: $this->currentCalendarMonthKey(),
                workspaceCapMicrousd: 0,
                businessCapMicrousd: 0,
                interactiveShareBps: 0,
            );
        }

        $policyKey = match ($summary->tier) {
            WorkspacePlanTier::Core => 'core',
            WorkspacePlanTier::Growth => 'growth',
            WorkspacePlanTier::Agency => 'agency',
        };

        $budget = config('ai.budgets.' . $policyKey);

        return new AiBudgetPolicy(
            policyKey: $policyKey,
            policyVersion: $policyVersion,
            periodKey: $this->currentCalendarMonthKey(),
            workspaceCapMicrousd: (int) $budget['workspace_cap_microusd'],
            businessCapMicrousd: $budget['business_cap_microusd'] !== null ? (int) $budget['business_cap_microusd'] : null,
            interactiveShareBps: (int) $budget['interactive_share_bps'],
        );
    }

    /**
     * Contract §5.7a D — the platform policy path. No customer plan lookup
     * occurs; `EntitlementManager` is not consulted at all. All six
     * `AiBudgetPolicy` fields are supplied, none omitted, none defaulted.
     *
     * Fail closed in both dimensions (§5.7a D):
     *  - an absent, non-numeric or non-positive monthly cap resolves to a
     *    zero `workspaceCapMicrousd`, which the gateway already treats as
     *    "refuse every call" — the same zero-cap semantics `unassigned`
     *    already relies on;
     *  - an `interactive_share_bps` outside `0…10000` raises
     *    {@see AiPlatformBudgetMisconfiguredException} rather than being
     *    clamped or left to become an unbounded interactive lane.
     */
    public function resolveForPlatform(): AiBudgetPolicy
    {
        $rawCap = config('ai.platform.monthly_cap_microusd');
        $cap = is_numeric($rawCap) && (int) $rawCap > 0 ? (int) $rawCap : 0;

        $rawShareBps = config('ai.platform.interactive_share_bps');

        if (! is_numeric($rawShareBps) || (int) $rawShareBps < 0 || (int) $rawShareBps > 10_000) {
            throw AiPlatformBudgetMisconfiguredException::invalidInteractiveShareBps();
        }

        return new AiBudgetPolicy(
            policyKey: 'platform',
            policyVersion: (int) config('ai.policy_version'),
            periodKey: $this->currentCalendarMonthKey(),
            workspaceCapMicrousd: $cap,
            businessCapMicrousd: null,
            interactiveShareBps: (int) $rawShareBps,
        );
    }

    private function currentCalendarMonthKey(): string
    {
        return Carbon::now('UTC')->format('Y-m');
    }
}
