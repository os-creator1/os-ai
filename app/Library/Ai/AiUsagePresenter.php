<?php

namespace App\Library\Ai;

use App\Library\Ai\Enums\AiUsageState;
use App\Models\Business;
use App\Repositories\Contracts\WorkspaceRepository;
use Carbon\CarbonImmutable;

/**
 * Contract §11.3 (slice AI-2) — Settings -> Billing -> AI usage.
 *
 * Turns AI-1's recorded usage into the one sentence a customer is allowed to
 * read about it. The policy comes from AiBudgetPolicyResolver, the figures
 * from AiUsageReadModel; this class adds only the decision and the words.
 *
 * WHAT NEVER LEAVES THIS CLASS: a token count, an amount, a percentage, a
 * provider or a model. AiUsageSummary has no field that could carry one.
 *
 * WHO SEES WHAT is decided by the caller from the canonical billing
 * authority (BillingProfileManager::billingResponsibilityFor()), never here:
 *   - the state itself, for anyone who may manage this Business's billing;
 *   - per-Business rows, for the Agency owner or an Agency-wide admin only,
 *     and only when the policy actually has a per-Business allowance —
 *     Core and Growth have one allowance, so a list of Businesses would say
 *     nothing the headline has not already said.
 *
 * TRIAL is read from the canonical policy and nowhere else. Until slice T-1
 * adds the trialing plan state the resolver never returns the trial policy,
 * so the trial wording is unreachable in practice — and nothing here guesses
 * at a trial from an account's age, its plan flags or anything else.
 */
final class AiUsagePresenter
{
    private const TRIAL_POLICY_KEY = 'trial';

    private const BASIS_POINTS = 10_000;

    public function __construct(
        private readonly AiBudgetPolicyResolver $policyResolver,
        private readonly AiUsageReadModel $readModel,
        private readonly WorkspaceRepository $workspaceRepository,
    ) {
    }

    /**
     * Null when there is nothing honest to show: the actor may not see
     * billing, or the account has no included AI at all (no active plan —
     * every call is refused, and "used up" would describe an allowance that
     * never existed).
     */
    public function forBillingPage(Business $business, bool $actorMaySeeUsage, bool $actorSeesBusinessRows): ?AiUsageSummary
    {
        if (! $actorMaySeeUsage) {
            return null;
        }

        $business->loadMissing('workspace');
        $workspace = $business->workspace;

        if ($workspace === null) {
            return null;
        }

        $policy = $this->policyResolver->resolveFor($workspace);

        if ($policy->workspaceCapMicrousd <= 0) {
            return null;
        }

        $state = $this->stateFor($this->readModel->workspaceStanding((int) $workspace->id, $policy));

        $rows = [];

        if ($actorSeesBusinessRows && $policy->businessCapMicrousd !== null) {
            $businesses = $this->workspaceRepository->businessesForWorkspace($workspace);
            $standings = $this->readModel->businessStandings(
                (int) $workspace->id,
                $businesses->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                $policy,
            );

            foreach ($businesses as $each) {
                $rowState = $this->stateFor($standings[(int) $each->id]);

                $rows[] = new AiUsageBusinessRow(
                    businessUid: (string) $each->uid,
                    businessName: (string) $each->name,
                    state: $rowState,
                    label: $this->labelFor($rowState),
                );
            }
        }

        return new AiUsageSummary(
            state: $state,
            sentence: $this->sentenceFor($state, $policy),
            trialLine: $this->trialLineFor($policy),
            businessRows: $rows,
        );
    }

    /**
     * §11.3's conditions, in integer arithmetic.
     *
     * Limit reached: committed at or over the cap, or any `budget_exhausted`
     * refusal this period — a refusal is the plainest evidence the allowance
     * ran out, even when a later release has brought committed back under it.
     * Nearing limit: committed at or over the configured share of the cap.
     */
    public function stateFor(AiUsageStanding $standing): AiUsageState
    {
        if ($standing->refusedForBudgetThisPeriod
            || $standing->capMicrousd <= 0
            || $standing->committedMicrousd >= $standing->capMicrousd) {
            return AiUsageState::LimitReached;
        }

        $nearingBps = max(0, min(self::BASIS_POINTS, (int) config('ai.presentation.nearing_limit_bps')));

        // committed / cap >= bps / 10 000, without a float.
        if ($standing->committedMicrousd * self::BASIS_POINTS >= $standing->capMicrousd * $nearingBps) {
            return AiUsageState::NearingLimit;
        }

        return AiUsageState::Normal;
    }

    public function sentenceFor(AiUsageState $state, AiBudgetPolicy $policy): string
    {
        return match ($state) {
            AiUsageState::Normal => __('locale.usage_billing.ai_usage.states.normal'),
            AiUsageState::NearingLimit => __('locale.usage_billing.ai_usage.states.nearing_limit'),
            AiUsageState::LimitReached => $this->isTrial($policy)
                // A trial is one period with nothing after it (§11.1a).
                ? __('locale.usage_billing.ai_usage.states.limit_reached_trial')
                : __('locale.usage_billing.ai_usage.states.limit_reached', ['date' => $this->nextPeriodStartsOn($policy)]),
        };
    }

    public function trialLineFor(AiBudgetPolicy $policy): ?string
    {
        return $this->isTrial($policy) ? __('locale.usage_billing.ai_usage.trial_line') : null;
    }

    private function labelFor(AiUsageState $state): string
    {
        return __('locale.usage_billing.ai_usage.row_states.' . $state->value);
    }

    private function isTrial(AiBudgetPolicy $policy): bool
    {
        return $policy->policyKey === self::TRIAL_POLICY_KEY;
    }

    /**
     * "{first day of the next period}" — the day after the period this state
     * describes ends. Calendar-month periods are keyed `YYYY-MM` in UTC
     * (§10.2), so the next one starts on the first of the following month.
     */
    private function nextPeriodStartsOn(AiBudgetPolicy $policy): string
    {
        $start = preg_match('/^\d{4}-\d{2}$/', $policy->periodKey) === 1
            ? CarbonImmutable::createFromFormat('!Y-m', $policy->periodKey, 'UTC')
            : CarbonImmutable::now('UTC')->startOfMonth();

        return $start->addMonthNoOverflow()->startOfMonth()->format('j F');
    }
}
