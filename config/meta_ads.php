<?php

/*
|--------------------------------------------------------------------------
| Meta Ads Module V1
|--------------------------------------------------------------------------
|
| Contract: docs/product/implementation-contracts/24-META-ADS-MODULE-V1.md
|
| OAuth CREDENTIALS live in config/services.php under `meta_ads`. Nothing
| below is trusted as a raw env string: every value is validated at the point
| of use by App\Library\MetaAds\MetaAdsConfig, which falls back to the
| documented safe default (never to "unlimited") when a value is absent,
| malformed or out of range.
|
*/

return [

    // Contract §2 — pinned here, never hard-coded in a client. Current: v26.0.
    'api_version' => env('META_ADS_API_VERSION', 'v26.0'),

    // Graph API host (https only) and the Facebook host that serves the OAuth dialog.
    'base_url' => env('META_ADS_BASE_URL', 'https://graph.facebook.com'),
    'oauth_dialog_base_url' => env('META_ADS_OAUTH_DIALOG_BASE_URL', 'https://www.facebook.com'),

    // "http" (real provider) or "fake" (tests / browser acceptance). "fake"
    // is REFUSED when the application environment is production.
    'driver' => env('META_ADS_DRIVER', 'http'),

    'http' => [
        'connect_timeout_seconds' => env('META_ADS_CONNECT_TIMEOUT', 5),
        'request_timeout_seconds' => env('META_ADS_REQUEST_TIMEOUT', 30),
    ],

    // Contract §3 — OAuth state lifetime (60..3600 s; anything else falls back to 600).
    'oauth' => [
        'state_ttl_seconds' => env('META_ADS_OAUTH_STATE_TTL_SECONDS', 600),
    ],

    // Contract §3 — Meta has no refresh token; re-authorising is the renewal path.
    'token' => [
        'reauth_warning_days' => env('META_ADS_REAUTH_WARNING_DAYS', 7),
    ],

    // Contract §6.
    'sync' => [
        // A value below 20 is REJECTED and 24 is used.
        'min_interval_hours' => env('META_ADS_SYNC_MIN_INTERVAL_HOURS', 24),
        'metrics_lookback_days' => env('META_ADS_METRICS_LOOKBACK_DAYS', 62),

        // Bounds per report. Hitting either marks the run partial.
        'max_pages_per_report' => env('META_ADS_MAX_PAGES_PER_REPORT', 20),
        'max_rows_per_report' => env('META_ADS_MAX_ROWS_PER_REPORT', 100000),

        // Graph `limit` per page. Capped at 500 by MetaAdsConfig.
        'page_size' => env('META_ADS_PAGE_SIZE', 500),

        'max_calls_per_business_per_hour' => env('META_ADS_MAX_CALLS_PER_BUSINESS_PER_HOUR', 120),

        'breaker_threshold' => env('META_ADS_BREAKER_THRESHOLD', 20),
        'breaker_cooldown_minutes' => env('META_ADS_BREAKER_COOLDOWN_MINUTES', 30),

        'manual_refresh_min_minutes' => env('META_ADS_MANUAL_REFRESH_MIN_MINUTES', 60),

        // Stop a run cleanly (partial / usage_high) once Meta reports this much quota used.
        'usage_stop_percent' => env('META_ADS_USAGE_STOP_PERCENT', 85),
    ],

    // Contract §5.3 — same arithmetic as Google.
    'pacing' => [
        'min_days' => env('META_ADS_PACING_MIN_DAYS', 7),
        'tolerance' => env('META_ADS_PACING_TOLERANCE', 0.15),
    ],

    // Contract §12 — deterministic thresholds. Money is in account-currency MICROS.
    'recommendations' => [
        'zero_result_min_spend_micros' => env('META_ADS_ZERO_RESULT_MIN_SPEND_MICROS', 50000000),
        'cpr_over_factor' => env('META_ADS_CPR_OVER_FACTOR', 1.25),
        'strong_min_results' => env('META_ADS_STRONG_MIN_RESULTS', 3),
        'frequency_threshold' => env('META_ADS_FREQUENCY_THRESHOLD', 3.0),
        'fatigue_cpr_worsening_factor' => env('META_ADS_FATIGUE_CPR_WORSENING_FACTOR', 1.3),
        'fatigue_min_results' => env('META_ADS_FATIGUE_MIN_RESULTS', 3),
        'fatigue_min_spend_micros' => env('META_ADS_FATIGUE_MIN_SPEND_MICROS', 20000000),
    ],

    /*
    | Contract §5.2 — the ONLY Meta `actions` types that are ever stored or
    | offered to the owner as "the result". action_type => owner-facing label.
    |
    | These are long-documented Marketing API action types; they could not be
    | re-verified against Meta's field reference in-session (contract §2), so
    | they are pinned by the Fake/HTTP contract tests only. Adding a type here
    | is a deliberate product decision, not a convenience.
    */
    'result_types' => [
        'lead' => 'Leads',
        'onsite_conversion.lead_grouped' => 'Leads (on-Facebook forms)',
        'offsite_conversion.fb_pixel_lead' => 'Leads (website)',
        'onsite_conversion.messaging_conversation_started_7d' => 'Messaging conversations started',
        'link_click' => 'Link clicks',
    ],

    /*
    | Only an https creative thumbnail whose host matches one of these is ever
    | stored. An entry is an exact host, or "*.suffix" for any sub-domain of
    | suffix. Anything else becomes NULL (nothing is fetched or proxied by us).
    */
    'thumbnail_hosts' => [
        '*.fbcdn.net',
        '*.cdninstagram.com',
    ],

];
