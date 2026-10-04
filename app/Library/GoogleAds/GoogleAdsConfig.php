<?php

namespace App\Library\GoogleAds;

use App\Exceptions\GoogleAds\GoogleAdsConfigurationException;

/**
 * Google Ads Module V1 — the validated reader for config/google_ads.php.
 *
 * Every accessor fails CLOSED to a documented safe default when the
 * configured value is absent, malformed or out of range — never to
 * "unlimited" and never to a weaker security posture (same idiom as
 * GoogleBusinessProfileCallBudget::budgetPerHour(): an int or a digit-only
 * string, then a range check). Nothing outside this class reads
 * config('google_ads.*') directly.
 */
final class GoogleAdsConfig
{
    public const DEFAULT_API_VERSION = 'v25';

    public const DEFAULT_BASE_URL = 'https://googleads.googleapis.com';

    public const DRIVER_HTTP = 'http';

    public const DRIVER_FAKE = 'fake';

    /** Contract §5 — a configured interval below this is rejected. */
    public const MIN_SYNC_INTERVAL_HOURS = 20;

    public const DEFAULT_SYNC_INTERVAL_HOURS = 24;

    public function apiVersion(): string
    {
        $configured = config('google_ads.api_version');

        return is_string($configured) && preg_match('/\Av\d{1,3}\z/', $configured) === 1
            ? $configured
            : self::DEFAULT_API_VERSION;
    }

    /**
     * https only (a plain-http or malformed base URL is replaced by the
     * default, so a stray env value can never downgrade transport), no
     * trailing slash, no query or fragment.
     */
    public function baseUrl(): string
    {
        $configured = config('google_ads.base_url');

        if (! is_string($configured)) {
            return self::DEFAULT_BASE_URL;
        }

        $parts = parse_url(trim($configured));

        if ($parts === false
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['user'])) {
            return self::DEFAULT_BASE_URL;
        }

        return 'https://' . strtolower($parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . rtrim((string) ($parts['path'] ?? ''), '/');
    }

    /**
     * "http" unless explicitly "fake". `fake` is REFUSED in production — it
     * throws rather than silently using the real provider, so a misconfigured
     * production never serves fixture data as a customer's real ads.
     *
     * @throws GoogleAdsConfigurationException
     */
    public function driver(): string
    {
        $configured = config('google_ads.driver');
        $driver = is_string($configured) ? strtolower(trim($configured)) : self::DRIVER_HTTP;

        if ($driver === self::DRIVER_FAKE) {
            if (app()->environment('production')) {
                throw GoogleAdsConfigurationException::fakeDriverInProduction();
            }

            return self::DRIVER_FAKE;
        }

        return self::DRIVER_HTTP;
    }

    /** Null (header omitted) unless a non-empty string is configured. */
    public function developerToken(): ?string
    {
        $configured = config('google_ads.developer_token');

        if (! is_string($configured)) {
            return null;
        }

        $trimmed = trim($configured);

        return $trimmed === '' ? null : $trimmed;
    }

    public function connectTimeoutSeconds(): int
    {
        return $this->int('google_ads.http.connect_timeout_seconds', 5, 1, 60);
    }

    public function requestTimeoutSeconds(): int
    {
        return $this->int('google_ads.http.request_timeout_seconds', 30, 1, 120);
    }

    /** Floored at MIN_SYNC_INTERVAL_HOURS: a lower value falls back to 24. */
    public function syncMinIntervalHours(): int
    {
        return $this->int(
            'google_ads.sync.min_interval_hours',
            self::DEFAULT_SYNC_INTERVAL_HOURS,
            self::MIN_SYNC_INTERVAL_HOURS,
            24 * 30,
        );
    }

    public function metricsLookbackDays(): int
    {
        return $this->int('google_ads.sync.metrics_lookback_days', 62, 1, 400);
    }

    public function searchTermLookbackDays(): int
    {
        return $this->int('google_ads.sync.search_term_lookback_days', 30, 1, 90);
    }

    public function maxPagesPerReport(): int
    {
        return $this->int('google_ads.sync.max_pages_per_report', 20, 1, 200);
    }

    public function maxRowsPerReport(): int
    {
        return $this->int('google_ads.sync.max_rows_per_report', 100000, 1, 1000000);
    }

    public function maxCallsPerBusinessPerHour(): int
    {
        return $this->int('google_ads.sync.max_calls_per_business_per_hour', 60, 1, 100000);
    }

    public function breakerThreshold(): int
    {
        return $this->int('google_ads.sync.breaker_threshold', 20, 1, 1000);
    }

    public function breakerCooldownMinutes(): int
    {
        return $this->int('google_ads.sync.breaker_cooldown_minutes', 30, 1, 1440);
    }

    public function manualRefreshMinMinutes(): int
    {
        return $this->int('google_ads.sync.manual_refresh_min_minutes', 60, 1, 1440);
    }

    public function pacingMinDays(): int
    {
        return $this->int('google_ads.pacing.min_days', 7, 1, 31);
    }

    /** A fraction in (0, 1]; default 0.15. */
    public function pacingTolerance(): float
    {
        return $this->float('google_ads.pacing.tolerance', 0.15, 0.0, 1.0, minExclusive: true);
    }

    public function wasteMinSpendMicros(): int
    {
        return $this->int('google_ads.recommendations.waste_min_spend_micros', 20_000_000, 1, PHP_INT_MAX);
    }

    public function wasteMinClicks(): int
    {
        return $this->int('google_ads.recommendations.waste_min_clicks', 5, 0, 1_000_000);
    }

    /** At least 1.0 ("over" can never mean at-or-under target); default 1.25. */
    public function cplOverFactor(): float
    {
        return $this->float('google_ads.recommendations.cpl_over_factor', 1.25, 1.0, 100.0);
    }

    public function strongMinConversions(): int
    {
        return $this->int('google_ads.recommendations.strong_min_conversions', 3, 1, 1_000_000);
    }

    public function zeroConversionCampaignMinSpendMicros(): int
    {
        return $this->int('google_ads.recommendations.zero_conv_campaign_min_spend_micros', 50_000_000, 1, PHP_INT_MAX);
    }

    public function attributionCaptureEnabled(): bool
    {
        $configured = config('google_ads.attribution.capture_enabled');

        return match (true) {
            is_bool($configured) => $configured,
            is_string($configured) => in_array(strtolower(trim($configured)), ['1', 'true', 'yes', 'on'], true),
            default => true,
        };
    }

    public function attributionCookieDays(): int
    {
        return $this->int('google_ads.attribution.cookie_days', 90, 1, 365);
    }

    /** @param  'click_id'|'utm'|'landing_page'  $field */
    public function attributionMaxLength(string $field): int
    {
        $defaults = ['click_id' => 255, 'utm' => 255, 'landing_page' => 512];
        $default = $defaults[$field] ?? 255;

        return $this->int('google_ads.attribution.max_length.' . $field, $default, 1, $default);
    }

    private function int(string $key, int $default, int $min, int $max): int
    {
        $configured = config($key);

        if (is_string($configured) && preg_match('/\A\d+\z/', $configured) === 1) {
            $configured = (int) $configured;
        }

        if (! is_int($configured) || $configured < $min || $configured > $max) {
            return $default;
        }

        return $configured;
    }

    private function float(string $key, float $default, float $min, float $max, bool $minExclusive = false): float
    {
        $configured = config($key);

        if (is_string($configured) && is_numeric($configured)) {
            $configured = (float) $configured;
        }

        if (is_int($configured)) {
            $configured = (float) $configured;
        }

        if (! is_float($configured)
            || is_nan($configured)
            || ($minExclusive ? $configured <= $min : $configured < $min)
            || $configured > $max) {
            return $default;
        }

        return $configured;
    }
}
