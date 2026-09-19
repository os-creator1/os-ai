<?php

/*
|--------------------------------------------------------------------------
| SEO module — technical safety ceilings (Contract 18)
|--------------------------------------------------------------------------
|
| Every value below is a TECHNICAL SAFETY CEILING or operational default,
| never a commercial plan limit (no document authorizes one) and never a
| Google-stated limit unless a comment says so.
|
| Every value is read through App\Library\Seo\SeoConfig, which validates it
| at the point of use with the house idiom (an int, or a digit-only string,
| then a range check) and FAILS CLOSED toward the documented default: an
| absent, non-numeric, zero, negative or above-ceiling value is ignored and
| the default is used. Nothing here is trusted as a raw env string.
|
| Sub-slice 18A only defines this file and its reader. The Search Console,
| Keywords, Citations, Reviews and Audit sub-slices consume their own
| sections later; defining the values here keeps one source of truth.
|
*/

return [

    'keywords' => [
        // Contract §8.4 — 50 active keywords per Business, all tiers.
        // Ceiling is also 50: config may lower it, never raise it.
        'max_active_per_business' => env('SEO_KEYWORDS_MAX_ACTIVE'),
    ],

    'search_console' => [
        // Contract §8.3 — OUR design cap, not a Google-stated limit.
        // Default 400, hard ceiling 480.
        'daily_retention_days' => env('SEO_SC_DAILY_RETENTION_DAYS'),

        // Contract §8.3 — weekly snapshots kept. Default 12, ceiling 26.
        'snapshot_weeks' => env('SEO_SC_SNAPSHOT_WEEKS'),

        // Contract §11.3 — days without a successful sync before cached
        // data of a revoked / permission-lost connection is purged.
        'stale_purge_days' => env('SEO_SC_STALE_PURGE_DAYS'),

        // Contract §11.1 — per-Business minimum interval between manual
        // refreshes, in minutes.
        'manual_refresh_min_interval_minutes' => env('SEO_SC_MANUAL_REFRESH_MIN_INTERVAL_MINUTES'),

        // Contract §11.2 — per-Business ceiling on provider calls per hour,
        // and the project-level circuit breaker (GBP's values).
        'max_calls_per_business_per_hour' => env('SEO_SC_MAX_CALLS_PER_BUSINESS_PER_HOUR'),
        'breaker_threshold' => env('SEO_SC_BREAKER_THRESHOLD'),
        'breaker_cooldown_minutes' => env('SEO_SC_BREAKER_COOLDOWN_MINUTES'),
    ],

    'reviews' => [
        // Contract §8.6 — at most one non-declined review request per
        // (contact, Location) inside this window. Coordinator decision: 90.
        'request_cooldown_days' => env('SEO_REVIEW_REQUEST_COOLDOWN_DAYS'),
    ],

    'audit' => [
        // Contract §8.7 — audit runs retained per Website.
        'runs_retained' => env('SEO_AUDIT_RUNS_RETAINED'),
    ],

];
