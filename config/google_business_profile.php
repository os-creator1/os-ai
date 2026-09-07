<?php

/*
|--------------------------------------------------------------------------
| Google Business Profile — Slice A (read-only)
|--------------------------------------------------------------------------
|
| GBP Slice A contract §28.2. OAuth CREDENTIALS do not live here: they live
| in config/services.php under `google_business_profile`, deliberately
| separate from the `google` block used by Socialite sign-in, which this
| module never touches.
|
| Every value below is validated at the point of use with the house idiom
| (an int, or a digit-only string, then a range check) and is never trusted
| as a raw env string.
|
| NOTE THE DELIBERATELY OPPOSITE FAIL-CLOSED DIRECTIONS:
|   - mirror.retention_days fails closed toward PURGING. Google's API
|     policy caps stored Content at 30 calendar days, so an absent,
|     invalid, zero, negative or above-ceiling value yields an effective
|     TTL of 0 and every mirror is treated as expired.
|     See App\Library\GoogleBusinessProfile\GoogleBusinessProfileRetention.
|   - ledger.retention_days fails closed toward RETAINING, because
|     business_google_operations contains no Google Content — the same
|     direction as config/usage_billing.php's webhook_event.retention_days.
|
*/

return [

    'oauth' => [
        // Contract §9.4 — the signed state TTL. Clamped to [60, 3600];
        // anything outside that (or unset) falls back to 600 seconds.
        'state_ttl_seconds' => env('GOOGLE_BUSINESS_PROFILE_STATE_TTL_SECONDS', 600),
    ],

    'http' => [
        'connect_timeout_seconds' => env('GOOGLE_BUSINESS_PROFILE_CONNECT_TIMEOUT', 5),
        'request_timeout_seconds' => env('GOOGLE_BUSINESS_PROFILE_REQUEST_TIMEOUT', 20),
    ],

    'mirror' => [
        // Contract §13.4 — 1..30 or nothing. Left UNSET by default so a
        // deployment that has not consciously chosen a retention window
        // stores no Google Content across requests at all.
        'retention_days' => env('GOOGLE_BUSINESS_PROFILE_MIRROR_RETENTION_DAYS'),
    ],

    'sync' => [
        // Contract §24.2 — at most one background refresh per binding per
        // day. A configured value below 24 is REJECTED and 24 is used;
        // Google denies quota increases to applications showing "a highly
        // spiky request pattern rather than a smooth distribution".
        'min_interval_hours' => env('GOOGLE_BUSINESS_PROFILE_SYNC_MIN_INTERVAL_HOURS', 24),

        // Contract §24.3 — per-Business ceiling on provider calls per
        // hour. The 300 QPM Google quota is per CLOUD PROJECT and shared
        // across every customer, so a per-tenant limit alone cannot
        // protect it; the breaker below is the project-level guard.
        'max_calls_per_business_per_hour' => env('GOOGLE_BUSINESS_PROFILE_MAX_CALLS_PER_BUSINESS_PER_HOUR', 60),

        'breaker_threshold' => env('GOOGLE_BUSINESS_PROFILE_BREAKER_THRESHOLD', 20),
        'breaker_cooldown_minutes' => env('GOOGLE_BUSINESS_PROFILE_BREAKER_COOLDOWN_MINUTES', 30),
    ],

    'ledger' => [
        // Contract §13.6 — unset means RETAIN. No ledger purge job is
        // scheduled in Slice A; this exists so a later operations pass has
        // an unambiguous target. Rows with status `failed` or `unknown`
        // are never auto-deleted regardless of this value.
        'retention_days' => env('GOOGLE_BUSINESS_PROFILE_LEDGER_RETENTION_DAYS'),
    ],

];
