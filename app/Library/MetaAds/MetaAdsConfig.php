<?php

namespace App\Library\MetaAds;

use App\Exceptions\MetaAds\MetaConfigurationException;

/**
 * Meta Ads Module V1 — the validated reader for config/meta_ads.php and the
 * `services.meta_ads` credentials.
 *
 * Every accessor fails CLOSED to a documented safe default when the
 * configured value is absent, malformed or out of range — never to
 * "unlimited" and never to a weaker security posture. Nothing outside this
 * class reads config('meta_ads.*') directly.
 */
final class MetaAdsConfig
{
    public const DEFAULT_API_VERSION = 'v26.0';

    public const DEFAULT_BASE_URL = 'https://graph.facebook.com';

    public const DEFAULT_OAUTH_DIALOG_BASE_URL = 'https://www.facebook.com';

    public const DRIVER_HTTP = 'http';

    public const DRIVER_FAKE = 'fake';

    /** Contract §6 — a configured interval below this is rejected. */
    public const MIN_SYNC_INTERVAL_HOURS = 20;

    public const DEFAULT_SYNC_INTERVAL_HOURS = 24;

    public const MAX_PAGE_SIZE = 500;

    /** Contract §2 — V1 requests exactly these; `business_management` is NOT requested. */
    public const SCOPES = ['ads_read', 'ads_management'];

    /** Used when config('meta_ads.result_types') is absent or unusable. */
    public const DEFAULT_RESULT_TYPES = [
        'lead' => 'Leads',
        'onsite_conversion.lead_grouped' => 'Leads (on-Facebook forms)',
        'offsite_conversion.fb_pixel_lead' => 'Leads (website)',
        'onsite_conversion.messaging_conversation_started_7d' => 'Messaging conversations started',
        'link_click' => 'Link clicks',
    ];

    public const DEFAULT_THUMBNAIL_HOSTS = ['*.fbcdn.net', '*.cdninstagram.com'];

    public function apiVersion(): string
    {
        $configured = config('meta_ads.api_version');

        return is_string($configured) && preg_match('/\Av\d{1,3}\.\d{1,2}\z/', $configured) === 1
            ? $configured
            : self::DEFAULT_API_VERSION;
    }

    /** https only; no trailing slash, query or fragment. */
    public function baseUrl(): string
    {
        return $this->httpsOrigin(config('meta_ads.base_url'), self::DEFAULT_BASE_URL);
    }

    public function oauthDialogBaseUrl(): string
    {
        return $this->httpsOrigin(config('meta_ads.oauth_dialog_base_url'), self::DEFAULT_OAUTH_DIALOG_BASE_URL);
    }

    /** `{base_url}/{api_version}` */
    public function versionBase(): string
    {
        return $this->baseUrl() . '/' . $this->apiVersion();
    }

    /**
     * "http" unless explicitly "fake". `fake` is REFUSED in production.
     *
     * @throws MetaConfigurationException
     */
    public function driver(): string
    {
        $configured = config('meta_ads.driver');
        $driver = is_string($configured) ? strtolower(trim($configured)) : self::DRIVER_HTTP;

        if ($driver === self::DRIVER_FAKE) {
            if (app()->environment('production')) {
                throw MetaConfigurationException::fakeDriverInProduction();
            }

            return self::DRIVER_FAKE;
        }

        return self::DRIVER_HTTP;
    }

    public function appId(): ?string
    {
        return $this->secretString('services.meta_ads.app_id');
    }

    public function appSecret(): ?string
    {
        return $this->secretString('services.meta_ads.app_secret');
    }

    public function redirectUri(): ?string
    {
        return $this->secretString('services.meta_ads.redirect');
    }

    public function connectTimeoutSeconds(): int
    {
        return $this->int('meta_ads.http.connect_timeout_seconds', 5, 1, 60);
    }

    public function requestTimeoutSeconds(): int
    {
        return $this->int('meta_ads.http.request_timeout_seconds', 30, 1, 120);
    }

    /** Contract §3 — signed OAuth state lifetime; 60..3600 seconds, else 600. */
    public function oauthStateTtlSeconds(): int
    {
        return $this->int('meta_ads.oauth.state_ttl_seconds', 600, 60, 3600);
    }

    public function reauthWarningDays(): int
    {
        return $this->int('meta_ads.token.reauth_warning_days', 7, 1, 30);
    }

    /** Floored at MIN_SYNC_INTERVAL_HOURS: a lower value falls back to 24. */
    public function syncMinIntervalHours(): int
    {
        return $this->int('meta_ads.sync.min_interval_hours', self::DEFAULT_SYNC_INTERVAL_HOURS, self::MIN_SYNC_INTERVAL_HOURS, 24 * 30);
    }

    public function metricsLookbackDays(): int
    {
        return $this->int('meta_ads.sync.metrics_lookback_days', 62, 1, 400);
    }

    public function maxPagesPerReport(): int
    {
        return $this->int('meta_ads.sync.max_pages_per_report', 20, 1, 200);
    }

    public function maxRowsPerReport(): int
    {
        return $this->int('meta_ads.sync.max_rows_per_report', 100000, 1, 1000000);
    }

    /** Graph `limit`; a larger configured value falls back to the cap (500). */
    public function pageSize(): int
    {
        return $this->int('meta_ads.sync.page_size', self::MAX_PAGE_SIZE, 1, self::MAX_PAGE_SIZE);
    }

    public function maxCallsPerBusinessPerHour(): int
    {
        return $this->int('meta_ads.sync.max_calls_per_business_per_hour', 120, 1, 100000);
    }

    public function breakerThreshold(): int
    {
        return $this->int('meta_ads.sync.breaker_threshold', 20, 1, 1000);
    }

    public function breakerCooldownMinutes(): int
    {
        return $this->int('meta_ads.sync.breaker_cooldown_minutes', 30, 1, 1440);
    }

    public function manualRefreshMinMinutes(): int
    {
        return $this->int('meta_ads.sync.manual_refresh_min_minutes', 60, 1, 1440);
    }

    /** 1..100; default 85. */
    public function usageStopPercent(): int
    {
        return $this->int('meta_ads.sync.usage_stop_percent', 85, 1, 100);
    }

    public function pacingMinDays(): int
    {
        return $this->int('meta_ads.pacing.min_days', 7, 1, 31);
    }

    /** A fraction in (0, 1]; default 0.15. */
    public function pacingTolerance(): float
    {
        return $this->float('meta_ads.pacing.tolerance', 0.15, 0.0, 1.0, minExclusive: true);
    }

    public function zeroResultMinSpendMicros(): int
    {
        return $this->int('meta_ads.recommendations.zero_result_min_spend_micros', 50_000_000, 1, PHP_INT_MAX);
    }

    /** At least 1.0 ("over" can never mean at-or-under target); default 1.25. */
    public function cprOverFactor(): float
    {
        return $this->float('meta_ads.recommendations.cpr_over_factor', 1.25, 1.0, 100.0);
    }

    public function strongMinResults(): int
    {
        return $this->int('meta_ads.recommendations.strong_min_results', 3, 1, 1_000_000);
    }

    public function frequencyThreshold(): float
    {
        return $this->float('meta_ads.recommendations.frequency_threshold', 3.0, 1.0, 100.0);
    }

    /** At least 1.0 (a "worsening" factor below 1 would flag improvements); default 1.3. */
    public function fatigueCprWorseningFactor(): float
    {
        return $this->float('meta_ads.recommendations.fatigue_cpr_worsening_factor', 1.3, 1.0, 100.0);
    }

    public function fatigueMinResults(): int
    {
        return $this->int('meta_ads.recommendations.fatigue_min_results', 3, 1, 1_000_000);
    }

    public function fatigueMinSpendMicros(): int
    {
        return $this->int('meta_ads.recommendations.fatigue_min_spend_micros', 20_000_000, 1, PHP_INT_MAX);
    }

    /**
     * The owner-selectable result types: action_type => label. Malformed
     * entries are dropped; if nothing valid remains, the documented default
     * list is used (never an empty "store everything" / "store nothing").
     *
     * @return array<string, string>
     */
    public function resultTypes(): array
    {
        $configured = config('meta_ads.result_types');
        $valid = [];

        if (is_array($configured)) {
            foreach ($configured as $actionType => $label) {
                if (is_string($actionType)
                    && preg_match('/\A[a-z0-9_.]{1,100}\z/', $actionType) === 1
                    && is_string($label)
                    && trim($label) !== '') {
                    $valid[$actionType] = mb_substr(trim($label), 0, 80);
                }
            }
        }

        return $valid === [] ? self::DEFAULT_RESULT_TYPES : $valid;
    }

    public function isResultType(string $actionType): bool
    {
        return array_key_exists($actionType, $this->resultTypes());
    }

    /**
     * Allowed creative-thumbnail hosts: exact hosts or "*.suffix" patterns,
     * lower-cased. Falls back to the documented defaults when empty/invalid.
     *
     * @return array<int, string>
     */
    public function thumbnailHosts(): array
    {
        $configured = config('meta_ads.thumbnail_hosts');
        $valid = [];

        if (is_array($configured)) {
            foreach ($configured as $host) {
                if (is_string($host)) {
                    $host = strtolower(trim($host));

                    if (preg_match('/\A(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+\z/', $host) === 1) {
                        $valid[] = $host;
                    }
                }
            }
        }

        return $valid === [] ? self::DEFAULT_THUMBNAIL_HOSTS : array_values(array_unique($valid));
    }

    /**
     * Returns the url when it is https, has no credentials and its host is
     * allow-listed; otherwise null.
     */
    public function allowedThumbnailUrl(?string $url): ?string
    {
        if ($url === null || strlen($url) > 2048) {
            return null;
        }

        $parts = parse_url(trim($url));

        if ($parts === false
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower($parts['host']);

        foreach ($this->thumbnailHosts() as $allowed) {
            if (str_starts_with($allowed, '*.')) {
                $suffix = substr($allowed, 1); // ".fbcdn.net"

                if (str_ends_with($host, $suffix) && strlen($host) > strlen($suffix)) {
                    return trim($url);
                }
            } elseif ($host === $allowed) {
                return trim($url);
            }
        }

        return null;
    }

    private function httpsOrigin(mixed $configured, string $default): string
    {
        if (! is_string($configured)) {
            return $default;
        }

        $parts = parse_url(trim($configured));

        if ($parts === false
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['user'])) {
            return $default;
        }

        return 'https://' . strtolower($parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . rtrim((string) ($parts['path'] ?? ''), '/');
    }

    private function secretString(string $key): ?string
    {
        $configured = config($key);

        if (! is_string($configured)) {
            return null;
        }

        $trimmed = trim($configured);

        return $trimmed === '' ? null : $trimmed;
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
