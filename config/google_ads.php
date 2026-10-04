<?php

/*
|--------------------------------------------------------------------------
| Google Ads Module V1
|--------------------------------------------------------------------------
|
| Contract: docs/product/implementation-contracts/23-GOOGLE-ADS-MODULE-V1.md
|
| OAuth CREDENTIALS live in config/services.php under `google_ads`. Nothing
| below is trusted as a raw env string: every value is validated at the point
| of use by App\Library\GoogleAds\GoogleAdsConfig, which falls back to the
| documented safe default (never to "unlimited") when a value is absent,
| malformed or out of range.
|
*/

return [

    // Contract §2 — pinned here, never hard-coded in a client. Current: v25.
    'api_version' => env('GOOGLE_ADS_API_VERSION', 'v25'),

    'base_url' => env('GOOGLE_ADS_BASE_URL', 'https://googleads.googleapis.com'),

    // "http" (real provider) or "fake" (tests / browser acceptance). "fake"
    // is REFUSED when the application environment is production.
    'driver' => env('GOOGLE_ADS_DRIVER', 'http'),

    // Contract §2 — optional. Google sunset developer tokens on 2026-09-09;
    // the header is sent ONLY when this is a non-empty string.
    'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN'),

    'http' => [
        'connect_timeout_seconds' => env('GOOGLE_ADS_CONNECT_TIMEOUT', 5),
        'request_timeout_seconds' => env('GOOGLE_ADS_REQUEST_TIMEOUT', 30),
    ],

    // Contract §5 / §14.
    'sync' => [
        // At most one scheduled read sync per account per interval. A value
        // below 20 is REJECTED and 24 is used (smooth request pattern).
        'min_interval_hours' => env('GOOGLE_ADS_SYNC_MIN_INTERVAL_HOURS', 24),

        // 62 days covers this month and the previous month.
        'metrics_lookback_days' => env('GOOGLE_ADS_METRICS_LOOKBACK_DAYS', 62),
        'search_term_lookback_days' => env('GOOGLE_ADS_SEARCH_TERM_LOOKBACK_DAYS', 30),

        // Bounds per report. Hitting either marks the run partial.
        'max_pages_per_report' => env('GOOGLE_ADS_MAX_PAGES_PER_REPORT', 20),
        'max_rows_per_report' => env('GOOGLE_ADS_MAX_ROWS_PER_REPORT', 100000),

        'max_calls_per_business_per_hour' => env('GOOGLE_ADS_MAX_CALLS_PER_BUSINESS_PER_HOUR', 60),

        'breaker_threshold' => env('GOOGLE_ADS_BREAKER_THRESHOLD', 20),
        'breaker_cooldown_minutes' => env('GOOGLE_ADS_BREAKER_COOLDOWN_MINUTES', 30),

        'manual_refresh_min_minutes' => env('GOOGLE_ADS_MANUAL_REFRESH_MIN_MINUTES', 60),
    ],

    // Contract §9.
    'pacing' => [
        'min_days' => env('GOOGLE_ADS_PACING_MIN_DAYS', 7),
        'tolerance' => env('GOOGLE_ADS_PACING_TOLERANCE', 0.15),
    ],

    // Contract §12 — deterministic thresholds. Money is in account-currency MICROS.
    'recommendations' => [
        'waste_min_spend_micros' => env('GOOGLE_ADS_WASTE_MIN_SPEND_MICROS', 20000000),
        'waste_min_clicks' => env('GOOGLE_ADS_WASTE_MIN_CLICKS', 5),
        'cpl_over_factor' => env('GOOGLE_ADS_CPL_OVER_FACTOR', 1.25),
        'strong_min_conversions' => env('GOOGLE_ADS_STRONG_MIN_CONVERSIONS', 3),
        'zero_conv_campaign_min_spend_micros' => env('GOOGLE_ADS_ZERO_CONV_CAMPAIGN_MIN_SPEND_MICROS', 50000000),
    ],

    // Contract §10 — first-party attribution capture.
    'attribution' => [
        'capture_enabled' => env('GOOGLE_ADS_ATTRIBUTION_CAPTURE_ENABLED', true),
        'cookie_days' => env('GOOGLE_ADS_ATTRIBUTION_COOKIE_DAYS', 90),
        'max_length' => [
            'click_id' => 255,
            'utm' => 255,
            'landing_page' => 512,
        ],
    ],

];
