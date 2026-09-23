<?php

namespace App\Library\Seo;

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
