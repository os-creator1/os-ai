<?php

namespace App\Library\Seo\Rank;

use App\Enums\Seo\SeoRankCheckType;
use App\Enums\Seo\SeoRankTrigger;
use App\Models\SeoRankTarget;
use Carbon\CarbonImmutable;

/**
 * Decides WHICH checks a target needs and when, and asks SeoRankTrackingBudget
 * for each one. It never calls a provider and never writes a run or ledger row
 * itself — reservations exist only because the budget authority created them.
 *
 * CADENCE + IDEMPOTENCY. Time is cut into buckets of `cadence_days` UTC days;
 * the scheduled idempotency key is target:check_type:s<bucket>, so at most one
 * automatic paid check per target per check type exists inside a cadence
 * window, however often the scheduler ticks or a job is retried. Manual keys
 * are target:check_type:m<utc-day>; the rolling cooldown and the recent-result
 * rule in the budget are what actually limit manual refreshes.
 *
 * STAGGER. Each target gets a deterministic offset (crc32 of its uid) inside
 * its cadence window, so Businesses spread across the day instead of all
 * firing at midnight. A brand-new target (next_check_at NULL) is due at once.
 *
 * NO IDENTITY, NO SPEND. If we cannot possibly match our own result (no
 * canonical domain for organic; no domain, phone or CID for local) the paid
 * call is skipped: its answer could only be "not matched".
 */
class SeoRankCheckPlanner
{
    public function __construct(
        private readonly SeoRankTrackingBudget $budget,
        private readonly SeoRankEntitlement $entitlement,
    ) {
    }

    /**
     * @return list<SeoRankBudgetDecision>
     */
    public function planScheduled(SeoRankTarget $target, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $business = $target->business;

        if ($business === null || $target->next_check_at !== null && $target->next_check_at->gt($now)) {
            return [];
        }

        $plan = $this->entitlement->planFor($business, $now);

        if ($plan === null) {
            return [];
        }

        $decisions = $this->reserveAll($target, SeoRankTrigger::Scheduled, 's' . self::bucket($plan->cadenceDays, $now), null, $now);

        $progressed = array_filter($decisions, fn (SeoRankBudgetDecision $d) => $d->allowed || $d->reason === SeoRankBudgetDecision::ALREADY_SCHEDULED);

        if ($progressed !== [] || $decisions === []) {
            // Nothing more to do until the next window (also true when there is
            // no identity to match: re-check next window, not every tick).
            $target->forceFill(['next_check_at' => self::nextDueAt($target, $plan->cadenceDays, $now)])->save();
        }

        return $decisions;
    }

    /**
     * @return list<SeoRankBudgetDecision>
     */
    public function planManual(SeoRankTarget $target, int $actorUserId, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');

        return $this->reserveAll($target, SeoRankTrigger::Manual, 'm' . intdiv($now->getTimestamp(), 86400), $actorUserId, $now);
    }

    public static function bucket(int $cadenceDays, CarbonImmutable $now): int
    {
        return intdiv(intdiv($now->getTimestamp(), 86400), max(1, $cadenceDays));
    }

    /** Start of the NEXT cadence window plus this target's deterministic stagger offset. */
    public static function nextDueAt(SeoRankTarget $target, int $cadenceDays, CarbonImmutable $now): CarbonImmutable
    {
        $cadenceDays = max(1, $cadenceDays);
        $nextBucketStart = (self::bucket($cadenceDays, $now) + 1) * $cadenceDays * 86400;
        $offset = crc32($target->uid) % ($cadenceDays * 86400);

        return CarbonImmutable::createFromTimestampUTC($nextBucketStart + $offset);
    }

    /**
     * @return list<SeoRankBudgetDecision>
     */
    private function reserveAll(SeoRankTarget $target, SeoRankTrigger $trigger, string $period, ?int $actorUserId, CarbonImmutable $now): array
    {
        $identity = SeoRankIdentity::forBusiness($target->business);
        $decisions = [];

        foreach ([SeoRankCheckType::Organic, SeoRankCheckType::Local] as $type) {
            $matchable = $type === SeoRankCheckType::Organic ? $identity->canMatchOrganic() : $identity->canMatchLocal();

            if (! $matchable) {
                continue;
            }

            $decisions[] = $this->budget->reserveRun(
                $target,
                $type,
                $trigger,
                $target->id . ':' . $type->value . ':' . $period,
                $actorUserId,
                $now,
            );
        }

        return $decisions;
    }
}
