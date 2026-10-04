<?php

namespace App\Library\Seo\Rank;

use App\Enums\Seo\SeoRankCheckType;
use App\Enums\Seo\SeoRankRunState;
use App\Enums\Seo\SeoRankTrackingState;
use App\Enums\Seo\SeoRankTrigger;
use App\Library\Seo\Rank\Provider\DataForSeoRankProvider;
use App\Library\Seo\Rank\Provider\SeoRankProvider;
use App\Library\Seo\SeoConfig;
use App\Models\Business;
use App\Models\SeoRankCheckRun;
use App\Models\SeoRankObservation;
use App\Models\SeoRankProviderLedger;
use App\Models\SeoRankTarget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * THE central rank-tracking budget authority (Contract: SEO KEYWORD RANK
 * TRACKING V1 §4/§24/§25/§26). No provider call may happen unless
 * reserveRun() has just created the run and its ledger reservation, and the
 * ledger rows here are the ONLY spend truth: every cap is a sum over
 * seo_rank_provider_ledger. It is also the ONLY writer of that ledger.
 *
 * reserveRun() runs in one transaction under a row lock on the Business, inside
 * a short cache lock that serializes the cross-Business (Workspace / global)
 * sums, and performs NO network I/O. Checks, in order, all fail closed:
 *
 *   master switch -> idempotency key -> target still tracked and its keyword
 *   active -> entitlement -> slot allowance -> pending check -> (manual only)
 *   recent-result and cooldown -> Business cap -> Workspace/Agency cap ->
 *   global daily -> global monthly.
 *
 * Money is integer micro-USD. A row counts as its ACTUAL provider cost once
 * known, else its reservation; `released` rows count nothing; `held` rows keep
 * counting (an ambiguous submit may have been charged).
 */
class SeoRankTrackingBudget
{
    private const CLOSED_STATES = [SeoRankRunState::Completed, SeoRankRunState::FailedTerminal];

    public const PROVIDER_ENABLED = 'enabled';
    public const PROVIDER_DISABLED = 'disabled';
    public const PROVIDER_NOT_CONFIGURED = 'not_configured';

    public function __construct(
        private readonly SeoConfig $config,
        private readonly SeoRankEntitlement $entitlement,
        private readonly SeoRankProvider $provider,
    ) {
    }

    /**
     * The deployment state of paid tracking: the master switch must be on AND the
     * provider must hold credentials. Anything else is closed — no reservation, no
     * submit, no call. Never exposes a credential.
     */
    public function providerState(): string
    {
        if (! $this->config->rankTrackingEnabled()) {
            return self::PROVIDER_DISABLED;
        }

        return $this->provider->isConfigured() ? self::PROVIDER_ENABLED : self::PROVIDER_NOT_CONFIGURED;
    }

    public function enabled(): bool
    {
        return $this->providerState() === self::PROVIDER_ENABLED;
    }

    /** Estimated cost of one run at a given depth, from provider cost FACTS in config. */
    public function estimateMicros(int $depth): int
    {
        return (int) ceil($depth / 10) * $this->config->rankCostPerPageMicros();
    }

    public function depthFor(SeoRankCheckType $type): int
    {
        return $type === SeoRankCheckType::Organic ? $this->config->rankOrganicDepth() : $this->config->rankLocalDepth();
    }

    /**
     * Ask permission to run one paid check. On success the run (state
     * `scheduled`, due now) and its `reserved` ledger row exist and are
     * committed; the caller may then — and only then — queue the provider call.
     */
    public function reserveRun(
        SeoRankTarget $target,
        SeoRankCheckType $type,
        SeoRankTrigger $trigger,
        string $idempotencyKey,
        ?int $actorUserId = null,
        ?CarbonImmutable $now = null,
    ): SeoRankBudgetDecision {
        $now ??= CarbonImmutable::now('UTC');

        if (! $this->enabled()) {
            return SeoRankBudgetDecision::refused(SeoRankBudgetDecision::DISABLED);
        }

        $lock = Cache::lock('seo-rank-budget-authority', 20);

        try {
            $lock->block(15);
        } catch (\Throwable) {
            // Could not serialize the aggregate sums: fail closed.
            return SeoRankBudgetDecision::refused(SeoRankBudgetDecision::DISABLED);
        }

        try {
            return DB::transaction(fn () => $this->reserveLocked($target, $type, $trigger, $idempotencyKey, $actorUserId, $now));
        } finally {
            $lock->release();
        }
    }

    private function reserveLocked(
        SeoRankTarget $target,
        SeoRankCheckType $type,
        SeoRankTrigger $trigger,
        string $idempotencyKey,
        ?int $actorUserId,
        CarbonImmutable $now,
    ): SeoRankBudgetDecision {
        $business = Business::query()->whereKey($target->business_id)->lockForUpdate()->first();

        if ($business === null) {
            return SeoRankBudgetDecision::refused(SeoRankBudgetDecision::TARGET_INACTIVE);
        }

        // Idempotency first: a replay of the same period is never a new charge.
        // (Manual requests run the pending / recent-result / cooldown checks FIRST so
        // the owner gets the accurate explanation; the key is still re-checked below
        // as the final backstop before anything is created.)
        if ($trigger !== SeoRankTrigger::Manual) {
            $existing = SeoRankCheckRun::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return SeoRankBudgetDecision::refused(SeoRankBudgetDecision::ALREADY_SCHEDULED, $existing);
            }
        }

        $fresh = SeoRankTarget::query()
            ->whereKey($target->id)
            ->where('business_id', $business->id)
            ->where('tracking_state', SeoRankTrackingState::Tracking->value)
            ->whereHas('keyword', fn ($q) => $q->active())
            ->first();

        if ($fresh === null) {
            return SeoRankBudgetDecision::refused(SeoRankBudgetDecision::TARGET_INACTIVE);
        }

        $plan = $this->entitlement->planFor($business, $now);

        if ($plan === null) {
            return SeoRankBudgetDecision::refused(SeoRankBudgetDecision::NOT_ENTITLED);
        }

        if (! $this->withinSlotAllowance($business, $fresh, $plan)) {
            return SeoRankBudgetDecision::refused(SeoRankBudgetDecision::OVER_SLOT_LIMIT);
        }

        $open = SeoRankCheckRun::query()
            ->where('seo_rank_target_id', $fresh->id)
            ->where('check_type', $type->value)
            ->whereNotIn('state', array_map(fn (SeoRankRunState $s) => $s->value, self::CLOSED_STATES))
            ->first();

        if ($open !== null) {
            return SeoRankBudgetDecision::refused(SeoRankBudgetDecision::PENDING, $open);
        }

        if ($trigger === SeoRankTrigger::Manual) {
            $cooldownStart = $now->subHours($this->config->rankManualCooldownHours());

            $recent = SeoRankObservation::query()
                ->where('seo_rank_target_id', $fresh->id)
                ->where('checked_at', '>', $cooldownStart)
                ->exists();

            if ($recent) {
                return SeoRankBudgetDecision::refused(SeoRankBudgetDecision::RECENT_RESULT);
            }

            $recentManual = SeoRankCheckRun::query()
                ->where('seo_rank_target_id', $fresh->id)
                ->where('check_type', $type->value)
                ->where('trigger', SeoRankTrigger::Manual->value)
                ->where('created_at', '>', $cooldownStart)
                ->whereHas('ledgerEntry', fn ($q) => $q->where('status', '!=', SeoRankProviderLedger::RELEASED))
                ->exists();

            if ($recentManual) {
                return SeoRankBudgetDecision::refused(SeoRankBudgetDecision::COOLDOWN);
            }
        }

        $existing = SeoRankCheckRun::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return SeoRankBudgetDecision::refused(SeoRankBudgetDecision::ALREADY_SCHEDULED, $existing);
        }

        $depth = $this->depthFor($type);
        $cost = $this->estimateMicros($depth);

        $capRefusal = $this->capRefusal($business, $plan, $cost, $now);

        if ($capRefusal !== null) {
            return SeoRankBudgetDecision::refused($capRefusal);
        }

        $run = new SeoRankCheckRun();
        $run->forceFill([
            'business_id' => $business->id,
            'workspace_id' => $plan->budgetWorkspaceId,
            'seo_rank_target_id' => $fresh->id,
            'check_type' => $type->value,
            'trigger' => $trigger->value,
            'idempotency_key' => $idempotencyKey,
            'state' => SeoRankRunState::Scheduled->value,
            'provider' => $fresh->provider,
            'depth' => $depth,
            'reserved_micros' => $cost,
            'requested_by_user_id' => $actorUserId,
            'next_attempt_at' => $now,
        ])->save();

        $ledger = new SeoRankProviderLedger();
        $ledger->forceFill([
            'business_id' => $business->id,
            'workspace_id' => $plan->budgetWorkspaceId,
            'provider' => $fresh->provider,
            'operation' => $type === SeoRankCheckType::Organic ? 'organic_serp' : 'local_finder',
            'seo_rank_check_run_id' => $run->id,
            'usage_month' => $now->format('Y-m'),
            'usage_day' => $now->toDateString(),
            'reserved_micros' => $cost,
            'status' => SeoRankProviderLedger::RESERVED,
        ])->save();

        return SeoRankBudgetDecision::created($run);
    }

    /** The first spend cap this run would breach, or null. */
    private function capRefusal(Business $business, SeoRankPlan $plan, int $cost, CarbonImmutable $now): ?string
    {
        $businessSpent = $this->sum(SeoRankProviderLedger::query()
            ->where('business_id', $business->id)
            ->where('usage_day', '>=', $plan->capPeriodStart->toDateString()));

        if ($businessSpent + $cost > $plan->monthlyCapMicros) {
            return SeoRankBudgetDecision::BUSINESS_CAP;
        }

        if ($plan->budgetWorkspaceId !== null) {
            $workspaceSpent = $this->sum(SeoRankProviderLedger::query()
                ->where('workspace_id', $plan->budgetWorkspaceId)
                ->where('usage_month', $now->format('Y-m')));

            if ($workspaceSpent + $cost > $this->config->rankWorkspaceMonthlyCapMicros()) {
                return SeoRankBudgetDecision::WORKSPACE_CAP;
            }
        }

        $dailySpent = $this->sum(SeoRankProviderLedger::query()->where('usage_day', $now->toDateString()));

        if ($dailySpent + $cost > $this->config->rankGlobalDailyCapMicros()) {
            return SeoRankBudgetDecision::GLOBAL_DAILY_CAP;
        }

        $monthlySpent = $this->sum(SeoRankProviderLedger::query()->where('usage_month', $now->format('Y-m')));

        if ($monthlySpent + $cost > $this->config->rankGlobalMonthlyCapMicros()) {
            return SeoRankBudgetDecision::GLOBAL_MONTHLY_CAP;
        }

        return null;
    }

    /** Targets beyond the plan's allowance (e.g. after a downgrade) are never checked. */
    private function withinSlotAllowance(Business $business, SeoRankTarget $target, SeoRankPlan $plan): bool
    {
        $allowedIds = $this->trackedTargetsQuery($business)
            ->orderBy('tracked_since')
            ->orderBy('id')
            ->limit($plan->trackedTargets)
            ->pluck('id')
            ->all();

        return in_array($target->id, $allowedIds, true);
    }

    /** Slots in use: tracking targets whose keyword is still active. */
    public function trackedTargetsQuery(Business $business)
    {
        return SeoRankTarget::query()
            ->where('business_id', $business->id)
            ->where('tracking_state', SeoRankTrackingState::Tracking->value)
            ->whereHas('keyword', fn ($q) => $q->active());
    }

    /**
     * Would a NEW paid check for this Business be refused for SPEND right now?
     * Read-only; used for the "paused until your usage period resets" copy. It does
     * not consider the provider switch/credentials (see providerState()).
     */
    public function isPausedBySpend(Business $business, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now('UTC');

        // Spend only. Provider unavailability is a separate state (providerState()).
        $plan = $this->entitlement->planFor($business, $now);

        if ($plan === null) {
            return false;
        }

        $cost = $this->estimateMicros($this->config->rankLocalDepth()) + $this->estimateMicros($this->config->rankOrganicDepth());

        return $this->capRefusal($business, $plan, $cost, $now) !== null;
    }

    // ---------------------------------------------------------------------
    // Ledger transitions — the only writers of seo_rank_provider_ledger.
    // ---------------------------------------------------------------------

    /**
     * The provider accepted (or is presumed to have accepted) the task: the
     * reservation becomes the spend of record. `$actualMicros` is the
     * provider-reported cost when known; otherwise the reservation stands.
     */
    public function commit(SeoRankCheckRun $run, ?int $actualMicros = null): void
    {
        $update = ['status' => SeoRankProviderLedger::COMMITTED];

        if ($actualMicros !== null) {
            $update['actual_micros'] = $actualMicros;
        }

        SeoRankProviderLedger::query()->where('seo_rank_check_run_id', $run->id)->update($update);

        if ($actualMicros !== null) {
            SeoRankCheckRun::query()->whereKey($run->id)->update(['actual_micros' => $actualMicros]);
        }
    }

    /** The provider definitively never created a task: nothing was spent. */
    public function release(SeoRankCheckRun $run): void
    {
        SeoRankProviderLedger::query()
            ->where('seo_rank_check_run_id', $run->id)
            ->where('status', '!=', SeoRankProviderLedger::COMMITTED)
            ->update(['status' => SeoRankProviderLedger::RELEASED]);
    }

    /** Submit outcome unknown: keep counting the reservation, resubmit nothing. */
    public function hold(SeoRankCheckRun $run): void
    {
        SeoRankProviderLedger::query()
            ->where('seo_rank_check_run_id', $run->id)
            ->where('status', SeoRankProviderLedger::RESERVED)
            ->update(['status' => SeoRankProviderLedger::HELD]);
    }

    /** Provider key used on targets created through the UI. */
    public function providerKey(): string
    {
        return DataForSeoRankProvider::KEY;
    }

    private function sum($query): int
    {
        return (int) $query
            ->where('status', '!=', SeoRankProviderLedger::RELEASED)
            ->sum(DB::raw('COALESCE(actual_micros, reserved_micros)'));
    }
}
