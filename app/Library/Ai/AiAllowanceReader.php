<?php

namespace App\Library\Ai;

use App\Library\Ai\Enums\AiUsageState;
use App\Models\Business;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * One reader for "where does this Workspace's included AI stand", built only on
 * the canonical pieces: AiBudgetPolicyResolver (lifecycle-aware policy),
 * AiUsageReadModel (recorded usage) and AiUsagePresenter::stateFor() (the very
 * rule Settings -> Billing -> AI usage renders). It adds the one distinction
 * the billing sentence does not need: the INTERACTIVE share being spent is not
 * the overall allowance being used up.
 *
 * Never reserves, routes or refuses a call. Its one write is the ledger's own upward-only adoption of an upgraded plan's cap
 * (AiUsageLedgerManager::reconcileUpgradedCaps), done before the standing is read so a Workspace that upgraded inside the month is
 * not shown — and not blocked by — the smaller cap its period snapshotted under the old plan.
 */
final class AiAllowanceReader
{
    public function __construct(
        private readonly AiBudgetPolicyResolver $policyResolver,
        private readonly AiUsageReadModel $readModel,
        private readonly AiUsagePresenter $presenter,
        private readonly AiUsageLedgerManager $ledger,
    ) {
    }

    public function forWorkspace(Workspace $workspace, ?Business $business = null): AiAllowance
    {
        $policy = $this->policyResolver->resolveFor($workspace);

        if (! $policy->hasAllowance()) {
            return new AiAllowance(AiAllowance::UNAVAILABLE, false, $policy->policyKey, null);
        }

        $this->ledger->reconcileUpgradedCaps((int) $workspace->id, $policy);

        $renews = $policy->isTrial() ? null : $this->renewalDate($policy);

        $overall = $this->presenter->stateFor($this->readModel->workspaceStanding((int) $workspace->id, $policy, supersedeRefusalsThatNowFit: true));

        // An Agency Business that has spent its OWN allowance is limited even when the Workspace is not.
        if ($overall !== AiUsageState::LimitReached && $business !== null && $policy->businessCapMicrousd !== null) {
            $standing = $this->readModel->businessStandings((int) $workspace->id, [(int) $business->id], $policy, assistantPreflight: true)[(int) $business->id] ?? null;

            if ($standing !== null && $this->presenter->stateFor($standing) === AiUsageState::LimitReached) {
                $overall = AiUsageState::LimitReached;
            }
        }

        if ($overall === AiUsageState::LimitReached) {
            return new AiAllowance($policy->isTrial() ? AiAllowance::TRIAL_LIMIT_REACHED : AiAllowance::LIMIT_REACHED, $policy->isTrial(), $policy->policyKey, $renews);
        }

        if ($this->readModel->interactiveShareSpent((int) $workspace->id, $policy, supersedeRefusalsThatNowFit: true)) {
            return new AiAllowance(AiAllowance::INTERACTIVE_LIMIT, $policy->isTrial(), $policy->policyKey, $renews);
        }

        return new AiAllowance(
            $overall === AiUsageState::NearingLimit ? AiAllowance::NEARING_LIMIT : AiAllowance::AVAILABLE,
            $policy->isTrial(),
            $policy->policyKey,
            $renews,
        );
    }

    private function renewalDate(AiBudgetPolicy $policy): ?string
    {
        if (preg_match('/^\d{4}-\d{2}$/', $policy->periodKey) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m', $policy->periodKey, 'UTC')->addMonthNoOverflow()->startOfMonth()->format('j F');
    }
}
