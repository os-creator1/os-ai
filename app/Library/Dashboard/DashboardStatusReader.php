<?php

namespace App\Library\Dashboard;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Enums\Business\BusinessServiceStatus;
use App\Enums\GoogleBusinessProfile\GoogleConnectionProduct;
use App\Enums\GoogleBusinessProfile\GoogleLocationHealth;
use App\Enums\Forms\FormDeploymentSource;
use App\Library\Business\UrlNormalizer;
use App\Models\ExternalSiteCrawl;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Customer Experience Slice 4 §5.2 / §7 — the wallet, website and Google
 * status of one or many Businesses in ONE statement, whatever the number of
 * Businesses: never one query per client (§7, §13 "No N+Business").
 *
 * It reads status columns and nothing else. No B5 table (reports, campaigns,
 * contacts, contact_groups, automation_executions) and no conversation table, so it
 * can never become a second KPI formula or a second Conversations owner
 * (§4.1, §9.2). It writes nothing.
 *
 * Every row it can return is keyed by a Business id the caller already holds
 * from the resolved CustomerContext; it never widens that set.
 */
final class DashboardStatusReader
{
    /**
     * GoogleLocationHealth cases that need the customer: the listing is
     * contested, switched off, duplicated or unverified. VerificationPending
     * and AwaitingReview are already in Google's hands, Unknown is no claim at
     * all, and Verified is healthy.
     */
    public const UNHEALTHY_GOOGLE_LOCATION_STATES = [
        GoogleLocationHealth::OwnershipConflict,
        GoogleLocationHealth::Suspended,
        GoogleLocationHealth::Disabled,
        GoogleLocationHealth::Duplicate,
        GoogleLocationHealth::Unverified,
    ];

    /**
     * @param  array<int, int>  $businessIds
     * @return array<int, BusinessStatusRow> keyed by Business id; every id asked for is present
     */
    public function forBusinesses(array $businessIds): array
    {
        $businessIds = array_values(array_unique(array_map('intval', $businessIds)));

        if ($businessIds === []) {
            return [];
        }

        $active = BusinessLocationLifecycleState::Active->value;
        $formSources = array_map(fn (FormDeploymentSource $source) => $source->value, FormDeploymentSource::cases());
        $unhealthy = array_map(fn (GoogleLocationHealth $state) => $state->value, self::UNHEALTHY_GOOGLE_LOCATION_STATES);

        $rows = DB::table('businesses as b')
            ->leftJoin('business_usage_wallets as w', 'w.business_id', '=', 'b.id')
            ->leftJoin('currencies as c', 'c.id', '=', 'w.currency_id')
            ->leftJoin('websites as s', 's.business_id', '=', 'b.id')
            // SEO Contract 18 §7.3 — a Business can now hold more than one
            // business_google_connections row (one per product). Scoping
            // the join itself to business_profile (rather than filtering
            // afterward) keeps a Business with no GBP connection at all
            // correctly LEFT-JOINed to null, while never matching a
            // search_console row — the same read this returns today,
            // before any such row can exist.
            ->leftJoin('business_google_connections as g', function ($join) {
                $join->on('g.business_id', '=', 'b.id')
                    ->where('g.product', '=', GoogleConnectionProduct::BusinessProfile->value);
            })
            ->whereIn('b.id', $businessIds)
            ->select([
                'b.id as business_id',
                'w.id as wallet_id',
                'w.billing_status',
                'w.debt_balance_micro',
                'w.paid_activity_paused_at',
                'w.available_balance_micro',
                'w.auto_recharge_threshold_micro',
                'w.auto_recharge_enabled',
                'w.consecutive_recharge_failures',
                'w.committed_spend_this_period_micro',
                'w.spend_period_end_utc',
                'w.monthly_spend_cap_micro',
                'c.code as currency_code',
                's.status as website_status',
                'g.state as google_state',
            ])
            // Launch-readiness facts for HomeSetupJourney: EXISTS / scalar probes on canonical rows, riding on this
            // one statement (no extra query per Home request). Each is Business-scoped through an indexed column
            // (business_locations/business_services (business_id,is_primary), forms (business_id,lifecycle_state),
            // form_deployments unique (form_id,...), website_forms.website_id, external_site_crawls
            // (business_id,id), customer_onboardings.business_id). None reads onboarding step flags.
            ->selectRaw('EXISTS (SELECT 1 FROM business_locations bl WHERE bl.business_id = b.id AND bl.is_primary = 1 AND bl.lifecycle_state = ?) AS has_primary_location', [$active])
            ->selectRaw('EXISTS (SELECT 1 FROM business_services bs WHERE bs.business_id = b.id AND bs.is_primary = 1 AND bs.status = ?) AS has_primary_service', [BusinessServiceStatus::Active->value])
            ->selectRaw('(SELECT COUNT(*) FROM business_locations al WHERE al.business_id = b.id AND al.lifecycle_state = ?) AS active_location_count', [$active])
            // A form a visitor can actually submit, by FormDeploymentResolver's own rules: active Form, its current
            // version, an enabled deployment from a supported source, at an active Location of the SAME Business.
            ->selectRaw(
                'EXISTS (SELECT 1 FROM forms lf'
                .' JOIN form_versions fv ON fv.form_id = lf.id AND fv.version = lf.current_version'
                .' JOIN form_deployments fd ON fd.form_id = lf.id AND fd.is_enabled = 1 AND fd.source IN ('.implode(',', array_fill(0, count($formSources), '?')).')'
                .' JOIN business_locations fl ON fl.id = fd.business_location_id AND fl.business_id = lf.business_id AND fl.lifecycle_state = ?'
                ." WHERE lf.business_id = b.id AND lf.lifecycle_state = 'active') AS has_live_form",
                [...$formSources, $active],
            )
            // A website quote form that WebsiteFormSubmissionService/FormLocationResolver would accept: active, on a
            // PUBLISHED site, bound to an active Location of this Business.
            ->selectRaw(
                'EXISTS (SELECT 1 FROM website_forms wf'
                .' JOIN business_locations wl ON wl.id = wf.location_id AND wl.business_id = b.id AND wl.lifecycle_state = ?'
                ." WHERE wf.website_id = s.id AND wf.is_active = 1 AND s.status = 'published') AS has_active_website_form",
                [$active],
            )
            // The latest USABLE check of an external site (completed with pages actually fetched), to be matched in PHP
            // against the Business's CURRENT address; an old address's check must never validate a new one.
            ->selectRaw("(SELECT ec.start_url FROM external_site_crawls ec WHERE ec.business_id = b.id AND ec.status = ? AND ec.pages_fetched > 0 ORDER BY ec.id DESC LIMIT 1) AS ext_audit_url", [ExternalSiteCrawl::COMPLETED])
            ->selectRaw("(SELECT ec2.finished_at FROM external_site_crawls ec2 WHERE ec2.business_id = b.id AND ec2.status = ? AND ec2.pages_fetched > 0 ORDER BY ec2.id DESC LIMIT 1) AS ext_audit_finished_at", [ExternalSiteCrawl::COMPLETED])
            ->selectRaw("(SELECT JSON_UNQUOTE(JSON_EXTRACT(co.metadata, '$.readiness_policy')) FROM customer_onboardings co WHERE co.business_id = b.id ORDER BY co.id DESC LIMIT 1) AS readiness_policy")
            ->addSelect('b.website_url as business_website_url')
            ->selectSub(
                DB::table('business_google_locations as l')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('l.business_id', 'b.id')
                    ->whereIn('l.verification_state', $unhealthy),
                'unhealthy_google_locations',
            )
            ->get();

        $result = [];

        foreach ($businessIds as $id) {
            $result[$id] = BusinessStatusRow::empty($id);
        }

        foreach ($rows as $row) {
            $id = (int) $row->business_id;
            $hasWallet = $row->wallet_id !== null;

            $result[$id] = new BusinessStatusRow(
                businessId: $id,
                hasWallet: $hasWallet,
                billingStatus: $row->billing_status !== null ? (string) $row->billing_status : null,
                debtBalanceMicro: $hasWallet ? (string) ($row->debt_balance_micro ?? '0') : '0',
                paidActivityPaused: $row->paid_activity_paused_at !== null,
                availableBalanceMicro: $hasWallet ? (string) ($row->available_balance_micro ?? '0') : '0',
                autoRechargeThresholdMicro: $row->auto_recharge_threshold_micro !== null ? (string) $row->auto_recharge_threshold_micro : null,
                autoRechargeEnabled: (bool) ($row->auto_recharge_enabled ?? false),
                consecutiveRechargeFailures: (int) ($row->consecutive_recharge_failures ?? 0),
                committedSpendThisPeriodMicro: $hasWallet ? (string) ($row->committed_spend_this_period_micro ?? '0') : '0',
                spendPeriodEndUtc: $row->spend_period_end_utc !== null ? CarbonImmutable::parse((string) $row->spend_period_end_utc, 'UTC') : null,
                monthlySpendCapMicro: $row->monthly_spend_cap_micro !== null ? (string) $row->monthly_spend_cap_micro : null,
                currencyCode: $row->currency_code !== null ? (string) $row->currency_code : null,
                websiteStatus: $row->website_status !== null ? (string) $row->website_status : null,
                googleConnectionState: $row->google_state !== null ? (string) $row->google_state : null,
                unhealthyGoogleLocations: (int) ($row->unhealthy_google_locations ?? 0),
                hasPrimaryLocation: (bool) $row->has_primary_location,
                hasPrimaryService: (bool) $row->has_primary_service,
                hasLiveForm: (bool) $row->has_live_form,
                hasActiveWebsiteForm: (bool) $row->has_active_website_form,
                hasCompletedExternalAudit: $this->auditMatchesCurrentSite($row->business_website_url, $row->ext_audit_url, $row->ext_audit_finished_at),
                activeLocationCount: (int) $row->active_location_count,
                readinessPolicy: is_string($row->readiness_policy) && $row->readiness_policy !== '' ? $row->readiness_policy : null,
            );
        }

        return $result;
    }

    public function forBusiness(int $businessId): BusinessStatusRow
    {
        return $this->forBusinesses([$businessId])[$businessId];
    }

    /**
     * Whether the latest usable external-site check belongs to the Business's CURRENT website address and is
     * recent enough to still describe it. Both addresses go through the same normalisation the Website module
     * uses (UrlNormalizer: lower-cased host, no "www."), never the stored canonical_domain column, which can
     * disagree with website_url. A check older than two re-check intervals (the scheduler re-checks weekly) no
     * longer describes the site, so a scheduler that stopped never keeps a Business "ready" forever.
     */
    private function auditMatchesCurrentSite(mixed $currentUrl, mixed $auditUrl, mixed $finishedAt): bool
    {
        if (! is_string($currentUrl) || trim($currentUrl) === '' || ! is_string($auditUrl) || $auditUrl === '' || $finishedAt === null) {
            return false;
        }

        $normalizer = app(UrlNormalizer::class);

        try {
            $current = $normalizer->canonicalDomain($normalizer->normalize($currentUrl));
            $audited = $normalizer->canonicalDomain($normalizer->normalize($auditUrl));
        } catch (\InvalidArgumentException) {
            return false;
        }

        if ($current === null || $current !== $audited) {
            return false;
        }

        $interval = max(1, (int) config('external_site_audit.recrawl_interval_days', 7));

        return CarbonImmutable::parse((string) $finishedAt, 'UTC')->greaterThan(CarbonImmutable::now('UTC')->subDays(2 * $interval));
    }
}
