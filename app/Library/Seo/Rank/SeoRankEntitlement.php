<?php

namespace App\Library\Seo\Rank;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Workspace\AgencyClientRelationshipStatus;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Seo\SeoConfig;
use App\Models\AgencyClientWorkspaceRelationship;
use App\Models\Business;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The single place that turns "which plan is this Business on" into rank
 * tracking numbers. Everything is decided through EntitlementManager (the
 * existing entitlement architecture) and SeoConfig — there is no plan-name
 * check anywhere else in SEO code.
 *
 * TIER MAPPING (SeoConfig::rankTierFor, the only mapping). A Workspace with a
 * trial_ends_at is a TRIAL (the same derivation CustomerAccountAccessResolver
 * uses, so an expired-but-uncleared trial stays on the strictest limits).
 * Otherwise core -> core, growth -> growth, and an Agency-tier Workspace uses
 * the growth limits: "unlimited locations" never means unlimited paid queries,
 * because the Agency aggregate cap (SeoConfig::rankWorkspaceMonthlyCapMicros)
 * sits on top of every client. Any other tier fails CLOSED to the trial limits.
 *
 * AGENCY CLIENTS. A client Business follows ITS OWN Workspace's assigned plan;
 * only the aggregate cap is owned by the Agency Workspace.
 *
 * Fails closed: anything unexpected is "not entitled" (null).
 */
class SeoRankEntitlement
{
    /** A trial's spend window reaches back this many days from its end. */
    private const TRIAL_WINDOW_DAYS = 90;

    public function __construct(
        private readonly EntitlementManager $entitlements,
        private readonly SeoConfig $config,
    ) {
    }

    public function planFor(Business $business, ?CarbonImmutable $now = null): ?SeoRankPlan
    {
        $now ??= CarbonImmutable::now('UTC');
        $workspace = $business->workspace;

        if ($workspace === null) {
            return null;
        }

        try {
            $decision = $this->entitlements->decide($workspace, $business, PlatformFeature::SeoRankTracking->value, 0);

            if (! $decision->allowed) {
                return null;
            }

            $summary = $this->entitlements->getWorkspaceEntitlementSummary($workspace);
        } catch (Throwable) {
            return null;
        }

        if (! $summary->isAssigned || $summary->tier === null) {
            return null;
        }

        $trialEnds = $summary->trialEndsAt !== null ? CarbonImmutable::instance($summary->trialEndsAt)->utc() : null;
        $isTrial = $trialEnds !== null;

        // The one tier mapping lives in SeoConfig and fails closed: a tier it
        // does not know gets the trial (strictest) limits, never the Growth ones.
        $tier = $this->config->rankTierFor($summary->tier, $isTrial);

        $limits = $this->config->rankTier($tier);

        $periodStart = $isTrial
            ? min($trialEnds->subDays(self::TRIAL_WINDOW_DAYS), $now->subSecond())
            : $now->startOfMonth();

        return new SeoRankPlan(
            $tier,
            $isTrial,
            $limits['tracked_targets'],
            $limits['cadence_days'],
            $limits['monthly_cap_micros'],
            $periodStart,
            $this->budgetWorkspaceId($business),
        );
    }

    /** The Workspace that owns the aggregate cap for this Business. */
    public function budgetWorkspaceId(Business $business): ?int
    {
        $workspaceId = $business->workspace_id !== null ? (int) $business->workspace_id : null;

        if ($workspaceId === null) {
            return null;
        }

        $agencyId = AgencyClientWorkspaceRelationship::query()
            ->where('client_workspace_id', $workspaceId)
            ->where('status', AgencyClientRelationshipStatus::Active->value)
            ->value('agency_workspace_id');

        return $agencyId !== null ? (int) $agencyId : $workspaceId;
    }
}
