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

    'citations' => [
        // Citations V1 — a manually tracked listing that was last checked
        // longer ago than this is flagged "Review recommended" (in-product
        // reminder only; nothing is sent). Default 90 days, range 7-365.
        'review_after_days' => env('SEO_CITATIONS_REVIEW_AFTER_DAYS'),

        // Citations V1 — custom directories one Business may keep. Default
        // and ceiling 25: a safety ceiling, not a plan limit.
        'max_custom_directories' => env('SEO_CITATIONS_MAX_CUSTOM_DIRECTORIES'),
    ],

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

        // Contract §8.7 — CONVENTIONAL guidance, explicitly NOT a
        // Google-stated requirement, which is why the finding copy says
        // "recommended" and never "required". Config may tune them; the
        // reader clamps both and falls back to these defaults.
        'seo_title_max_recommended' => env('SEO_AUDIT_SEO_TITLE_MAX_RECOMMENDED'),
        'meta_description_min_recommended' => env('SEO_AUDIT_META_DESCRIPTION_MIN_RECOMMENDED'),

        // Contract §8.7 — the cooldown between MANUAL audit re-runs, per
        // actor per Business. Request-abuse protection only: audit
        // correctness rests on the (website_revision_id, rule_set_version)
        // UNIQUE key, never on this.
        'manual_rerun_cooldown_seconds' => env('SEO_AUDIT_MANUAL_RERUN_COOLDOWN_SECONDS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rank tracking (SEO KEYWORD RANK TRACKING V1) — PAID provider safety model
    |--------------------------------------------------------------------------
    |
    | Money is always integer micro-USD (1 USD = 1_000_000) — never a float.
    | Every value is read through SeoConfig, which FAILS CLOSED: a malformed,
    | absent, zero-or-negative or out-of-range value is ignored and the
    | documented default is used. A lower value may REDUCE a limit; nothing
    | here can raise a limit above its documented hard maximum, and a bad
    | value can never switch a safeguard off. The master switch defaults OFF:
    | no provider call is possible until it is explicitly enabled.
    |
    | These are platform-cost guards, not customer pricing.
    */
    'rank_tracking' => [
        'enabled' => env('SEO_RANK_TRACKING_ENABLED', false),

        'dataforseo' => [
            'login' => env('DATAFORSEO_LOGIN'),
            'password' => env('DATAFORSEO_PASSWORD'),
            'base_url' => env('DATAFORSEO_BASE_URL', 'https://api.dataforseo.com'),
        ],

        // Provider cost FACTS (not business logic): micro-USD per 10-result
        // SERP page on the Standard queue ($0.0006). Used only to ESTIMATE
        // the reservation; the provider-reported cost reconciles it.
        'cost_per_page_micros' => env('SEO_RANK_COST_PER_PAGE_MICROS'),

        // Result depths. Lower only.
        'organic_depth' => env('SEO_RANK_ORGANIC_DEPTH'),
        'local_depth' => env('SEO_RANK_LOCAL_DEPTH'),

        // Plan tiers. Tracked targets and caps can only be lowered; cadence
        // can only be made slower. Keys are entitlement tiers, not plan names
        // scattered through SEO code.
        'tiers' => [
            'trial' => [
                'tracked_targets' => env('SEO_RANK_TRIAL_TARGETS'),
                'cadence_days' => env('SEO_RANK_TRIAL_CADENCE_DAYS'),
                'monthly_cap_micros' => env('SEO_RANK_TRIAL_CAP_MICROS'),
            ],
            'core' => [
                'tracked_targets' => env('SEO_RANK_CORE_TARGETS'),
                'cadence_days' => env('SEO_RANK_CORE_CADENCE_DAYS'),
                'monthly_cap_micros' => env('SEO_RANK_CORE_CAP_MICROS'),
            ],
            'growth' => [
                'tracked_targets' => env('SEO_RANK_GROWTH_TARGETS'),
                'cadence_days' => env('SEO_RANK_GROWTH_CADENCE_DAYS'),
                'monthly_cap_micros' => env('SEO_RANK_GROWTH_CAP_MICROS'),
            ],
        ],

        // Hours a target must wait between paid MANUAL refreshes. Longer only.
        'manual_cooldown_hours' => env('SEO_RANK_MANUAL_COOLDOWN_HOURS'),

        // Aggregate ceilings (micro-USD). Lower only.
        'workspace_monthly_cap_micros' => env('SEO_RANK_WORKSPACE_MONTHLY_CAP_MICROS'),
        'global_daily_cap_micros' => env('SEO_RANK_GLOBAL_DAILY_CAP_MICROS'),
        'global_monthly_cap_micros' => env('SEO_RANK_GLOBAL_MONTHLY_CAP_MICROS'),

        // Retention of observations, in months.
        'retention_months' => env('SEO_RANK_RETENTION_MONTHS'),

        // Freshness window, in days: a position whose last completed check
        // is older than this is shown with "may be out of date", and Growth
        // ignores it. Display/judgement only: it never changes what is
        // scheduled or spent. Default 7, range 2-90.
        'stale_after_days' => env('SEO_RANK_STALE_AFTER_DAYS'),

        // Bounded queue behaviour.
        'max_submit_attempts' => env('SEO_RANK_MAX_SUBMIT_ATTEMPTS'),
        'max_poll_hours' => env('SEO_RANK_MAX_POLL_HOURS'),
    ],

];
