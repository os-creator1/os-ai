<?php

namespace App\Library\Seo;

use App\Enums\Entitlement\WorkspacePlanTier;

/**
 * Contract 18 §8/§11 — the single, fail-closed reader of config/seo.php.
 *
 * Every accessor accepts only an int or a digit-only string, then applies a
 * range check, and otherwise returns the documented DEFAULT. An absent,
 * non-numeric, zero, negative or above-ceiling value therefore never takes
 * effect: it can never raise a ceiling and can never disable one. This is
 * the same house idiom GoogleBusinessProfileRetention uses.
 */
final class SeoConfig
{
    public function citationsReviewAfterDays(): int
    {
        return $this->bounded('seo.citations.review_after_days', 90, 7, 365);
    }

    public function citationsMaxCustomDirectoriesPerBusiness(): int
    {
        return $this->bounded('seo.citations.max_custom_directories', 25, 1, 25);
    }

    public function keywordsMaxActivePerBusiness(): int
    {
        return $this->bounded('seo.keywords.max_active_per_business', 50, 1, 50);
    }

    public function searchConsoleDailyRetentionDays(): int
    {
        return $this->bounded('seo.search_console.daily_retention_days', 400, 1, 480);
    }

    public function searchConsoleSnapshotWeeks(): int
    {
        return $this->bounded('seo.search_console.snapshot_weeks', 12, 1, 26);
    }

    public function searchConsoleStalePurgeDays(): int
    {
        return $this->bounded('seo.search_console.stale_purge_days', 90, 1, 365);
    }

    public function searchConsoleManualRefreshMinIntervalMinutes(): int
    {
        return $this->bounded('seo.search_console.manual_refresh_min_interval_minutes', 15, 5, 1440);
    }

    public function searchConsoleMaxCallsPerBusinessPerHour(): int
    {
        return $this->bounded('seo.search_console.max_calls_per_business_per_hour', 30, 1, 120);
    }

    public function searchConsoleBreakerThreshold(): int
    {
        return $this->bounded('seo.search_console.breaker_threshold', 20, 1, 100);
    }

    public function searchConsoleBreakerCooldownMinutes(): int
    {
        return $this->bounded('seo.search_console.breaker_cooldown_minutes', 30, 1, 240);
    }

    public function reviewRequestCooldownDays(): int
    {
        return $this->bounded('seo.reviews.request_cooldown_days', 90, 1, 365);
    }

    public function auditRunsRetained(): int
    {
        return $this->bounded('seo.audit.runs_retained', 5, 1, 20);
    }

    /**
     * Contract §8.7 — the recommended MAXIMUM SEO title length. Conventional
     * guidance, not a Google requirement; the finding copy says
     * "recommended" for exactly that reason.
     */
    public function auditSeoTitleMaxRecommended(): int
    {
        return $this->bounded('seo.audit.seo_title_max_recommended', 60, 20, 200);
    }

    /**
     * Contract §8.7 — the recommended MINIMUM meta description length, same
     * conventional-guidance caveat.
     */
    public function auditMetaDescriptionMinRecommended(): int
    {
        return $this->bounded('seo.audit.meta_description_min_recommended', 70, 20, 300);
    }

    /**
     * Contract §8.7 — seconds one actor must wait before manually re-running
     * the audit for one Business. Conservative 60s default; clamped so a
     * misconfiguration can neither remove the cooldown nor lock a customer
     * out for an hour-plus.
     */
    public function auditManualRerunCooldownSeconds(): int
    {
        return $this->bounded('seo.audit.manual_rerun_cooldown_seconds', 60, 5, 3600);
    }

    // -----------------------------------------------------------------
    // Rank tracking (paid provider) — micro-USD integers, fail closed.
    // "LowerOnly": the documented default IS the ceiling; config may only
    // reduce it. A malformed value yields the default, never "off".
    // -----------------------------------------------------------------

    public const RANK_TIER_TRIAL = 'trial';
    public const RANK_TIER_CORE = 'core';
    public const RANK_TIER_GROWTH = 'growth';

    public const RANK_TIERS = [self::RANK_TIER_TRIAL, self::RANK_TIER_CORE, self::RANK_TIER_GROWTH];

    /**
     * The ONE mapping from an entitlement plan tier to a rank-tracking tier
     * key. A trial is always the strictest tier. Core is Core. Growth, and an
     * Agency-tier Workspace ("unlimited locations" never means unlimited paid
     * queries), use the Growth limits. ANYTHING ELSE — no tier, or a tier added
     * later that nobody has priced yet — fails CLOSED to the trial tier, never
     * to a more generous one.
     */
    public function rankTierFor(?WorkspacePlanTier $planTier, bool $isTrial): string
    {
        if ($isTrial) {
            return self::RANK_TIER_TRIAL;
        }

        return match ($planTier) {
            WorkspacePlanTier::Core => self::RANK_TIER_CORE,
            WorkspacePlanTier::Growth, WorkspacePlanTier::Agency => self::RANK_TIER_GROWTH,
            default => self::RANK_TIER_TRIAL,
        };
    }

    /** Master switch. Only a literal true / "true" / "1" enables; anything else is OFF. */
    public function rankTrackingEnabled(): bool
    {
        $v = config('seo.rank_tracking.enabled');

        return $v === true || $v === 1 || $v === '1' || (is_string($v) && strtolower($v) === 'true');
    }

    public function rankCostPerPageMicros(): int
    {
        return $this->bounded('seo.rank_tracking.cost_per_page_micros', 600, 100, 5000);
    }

    public function rankOrganicDepth(): int
    {
        return $this->lowerOnly('seo.rank_tracking.organic_depth', 100, 10);
    }

    public function rankLocalDepth(): int
    {
        return $this->lowerOnly('seo.rank_tracking.local_depth', 10, 10);
    }

    /** @return array{tracked_targets: int, cadence_days: int, monthly_cap_micros: int} */
    public function rankTier(string $tier): array
    {
        $defaults = [
            self::RANK_TIER_TRIAL => [5, 3, 500_000],
            self::RANK_TIER_CORE => [5, 1, 1_500_000],
            self::RANK_TIER_GROWTH => [20, 1, 4_500_000],
        ];

        // An unknown tier gets the most restrictive tier, never an open one.
        $tier = array_key_exists($tier, $defaults) ? $tier : self::RANK_TIER_TRIAL;
        [$targets, $cadence, $cap] = $defaults[$tier];

        return [
            'tracked_targets' => $this->lowerOnly("seo.rank_tracking.tiers.{$tier}.tracked_targets", $targets, 1),
            'cadence_days' => $this->atLeast("seo.rank_tracking.tiers.{$tier}.cadence_days", $cadence, 30),
            'monthly_cap_micros' => $this->lowerOnly("seo.rank_tracking.tiers.{$tier}.monthly_cap_micros", $cap, 1),
        ];
    }

    public function rankManualCooldownHours(): int
    {
        return $this->atLeast('seo.rank_tracking.manual_cooldown_hours', 24, 168);
    }

    public function rankWorkspaceMonthlyCapMicros(): int
    {
        return $this->lowerOnly('seo.rank_tracking.workspace_monthly_cap_micros', 25_000_000, 1);
    }

    public function rankGlobalDailyCapMicros(): int
    {
        return $this->lowerOnly('seo.rank_tracking.global_daily_cap_micros', 10_000_000, 1);
    }

    public function rankGlobalMonthlyCapMicros(): int
    {
        return $this->lowerOnly('seo.rank_tracking.global_monthly_cap_micros', 150_000_000, 1);
    }

    public function rankRetentionMonths(): int
    {
        return $this->lowerOnly('seo.rank_tracking.retention_months', 13, 1);
    }

    /**
     * Days after which a stored rank position is "may be out of date".
     * Default 7 (a trial checks every 3 days, so a healthy target is never
     * flagged); range 2-90. A malformed value yields the default.
     */
    public function rankStaleAfterDays(): int
    {
        return $this->bounded('seo.rank_tracking.stale_after_days', 7, 2, 90);
    }

    public function rankMaxSubmitAttempts(): int
    {
        return $this->lowerOnly('seo.rank_tracking.max_submit_attempts', 3, 1);
    }

    public function rankMaxPollHours(): int
    {
        return $this->lowerOnly('seo.rank_tracking.max_poll_hours', 24, 1);
    }

    /** Valid range is [min, default]: the value can only be lowered. */
    private function lowerOnly(string $key, int $default, int $min): int
    {
        return $this->bounded($key, $default, $min, $default);
    }

    /** Valid range is [default, max]: the value can only be raised (e.g. slower cadence, longer cooldown). */
    private function atLeast(string $key, int $default, int $max): int
    {
        return $this->bounded($key, $default, $default, $max);
    }

    private function bounded(string $key, int $default, int $min, int $max): int
    {
        $configured = config($key);

        if (! is_int($configured) && ! (is_string($configured) && ctype_digit($configured))) {
            return $default;
        }

        $value = (int) $configured;

        return ($value >= $min && $value <= $max) ? $value : $default;
    }
}
