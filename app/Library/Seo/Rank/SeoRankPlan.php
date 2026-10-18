<?php

namespace App\Library\Seo\Rank;

use Carbon\CarbonImmutable;

/**
 * The resolved rank-tracking allowance of ONE Business right now. Built only by
 * SeoRankEntitlement from the entitlement architecture plus SeoConfig; callers
 * never look at a plan name.
 *
 * `capPeriodStart` is when the Business spend cap's window begins: the start of
 * the UTC calendar month for a paid plan, or the start of the trial window for a
 * trial. `budgetWorkspaceId` is the Workspace that owns the AGGREGATE cap: the
 * Agency Workspace for an Agency client Business, otherwise the Business's own.
 */
final class SeoRankPlan
{
    public function __construct(
        public readonly string $tier,
        public readonly bool $isTrial,
        public readonly int $trackedTargets,
        public readonly int $cadenceDays,
        public readonly int $monthlyCapMicros,
        public readonly CarbonImmutable $capPeriodStart,
        public readonly ?int $budgetWorkspaceId,
    ) {
    }
}
