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
 * Trial (D-1, §11.1a) is derived from the canonical lifecycle facts on the
 * entitlement summary (`trial_ends_at` plus the Grace/Locked timestamps) —
 * the same facts CustomerAccountAccessResolver reads. There is no `trialing`
 * status case and none is introduced here.
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
            return $this->unavailable('unassigned', $policyVersion);
        }

        // The account lifecycle is read from the SAME canonical facts
        // CustomerAccountAccessResolver derives Trial / Grace / Locked from
        // (the assignment's three lifecycle timestamps, carried on the
        // entitlement summary) — never from an account's age, and never from a
        // second trial flag. `trial_ends_at` records an OUTSTANDING trial only:
        // the provider-confirmed conversion (EntitlementManager::recoverAccess)
        // clears it, which is what moves a Workspace onto its paid allowance.
        if ($summary->lockedAt !== null) {
            return $this->unavailable('locked', $policyVersion);
        }

        if ($summary->graceStartedAt !== null) {
            $graceElapsed = ! $summary->graceStartedAt->copy()->addDays(EntitlementManager::GRACE_PERIOD_DAYS)->isFuture();

            // Grace after an UNCONVERTED trial keeps `trial_ends_at` — that
            // trial is over, and a failed payment must never read as paid AI.
            // Grace after a failed renewal of a paying account has no
            // outstanding trial and keeps the paid allowance, because the
            // account keeps full access through Grace (Blueprint §27).
            if ($graceElapsed || $summary->trialEndsAt !== null) {
                return $this->unavailable($graceElapsed ? 'locked' : 'trial_expired', $policyVersion);
            }
        } elseif ($summary->trialEndsAt !== null) {
            if (! $summary->trialEndsAt->isFuture()) {
                // Ended and not yet converted or swept: the trial allowance is
                // over and the paid allowance has not been earned.
                return $this->unavailable('trial_expired', $policyVersion);
            }

            $trial = config('ai.budgets.trial');

            return new AiBudgetPolicy(
                policyKey: 'trial',
                policyVersion: $policyVersion,
                // ONE period for the whole trial, whatever the calendar says.
                periodKey: (string) config('ai.trial_period_key', 'trial'),
                workspaceCapMicrousd: (int) $trial['workspace_cap_microusd'],
                businessCapMicrousd: $trial['business_cap_microusd'] !== null ? (int) $trial['business_cap_microusd'] : null,
                interactiveShareBps: (int) $trial['interactive_share_bps'],
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

    /** A zero-cap policy: every call is refused, and nothing about the account is guessed. */
    private function unavailable(string $policyKey, int $policyVersion): AiBudgetPolicy
    {
        return new AiBudgetPolicy(
            policyKey: $policyKey,
            policyVersion: $policyVersion,
            periodKey: $this->currentCalendarMonthKey(),
            workspaceCapMicrousd: 0,
            businessCapMicrousd: 0,
            interactiveShareBps: 0,
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
