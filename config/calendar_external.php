<?php

/*
|--------------------------------------------------------------------------
| External Calendar (Google / Outlook) — Implementation Contract 15 §5.5,
| Sub-slice F
|--------------------------------------------------------------------------
|
| Credentials for the per-User external calendar connection. Deliberately
| its own config file/env prefix, separate from `google_business_profile`
| (Business-scoped, a different Google OAuth client) and from `google`
| (Socialite sign-in) — Blueprint §12's connection is per-User and
| structurally unrelated to either.
|
| Every value is validated at the point of use (ExternalCalendarOAuthConfig)
| and never trusted as a raw env string.
|
*/

return [

    'google' => [
        'client_id' => env('GOOGLE_CALENDAR_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CALENDAR_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_CALENDAR_REDIRECT'),
        // Read-only free/busy scope only — this slice never writes to a
        // provider calendar, only reads busy/free state (Blueprint §12).
        'scopes' => ['https://www.googleapis.com/auth/calendar.readonly'],
    ],

    'outlook' => [
        'client_id' => env('MICROSOFT_CALENDAR_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CALENDAR_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_CALENDAR_REDIRECT'),
        'tenant' => env('MICROSOFT_CALENDAR_TENANT', 'common'),
        'scopes' => ['offline_access', 'Calendars.Read'],
    ],

    'oauth' => [
        // §5.5's pending-connection nonce TTL, the same clamped-[60,3600]
        // idiom GoogleOAuthStateSigner uses.
        'state_ttl_seconds' => env('CALENDAR_EXTERNAL_STATE_TTL_SECONDS', 600),
    ],

    'http' => [
        'connect_timeout_seconds' => env('CALENDAR_EXTERNAL_CONNECT_TIMEOUT', 5),
        'request_timeout_seconds' => env('CALENDAR_EXTERNAL_REQUEST_TIMEOUT', 20),
    ],

    'sync' => [
        // §12.F — an implementation-time decision, not pinned by any
        // authoritative document: how far ahead a full sync reads. Bounded
        // to match a realistic booking horizon rather than an unbounded
        // (and unbounded-cost) provider read.
        'full_sync_window_days' => env('CALENDAR_EXTERNAL_FULL_SYNC_WINDOW_DAYS', 60),

        // §11 — an implementation-time decision: consecutive sync failures
        // before a connection is flagged stale enough to need the staff
        // member's attention. Not itself a state transition (no schema for
        // one exists) — surfaced to the connection settings view only.
        'stale_after_consecutive_failures' => env('CALENDAR_EXTERNAL_STALE_AFTER_FAILURES', 5),

        // Review correction — the rolling-window interval. A provider's
        // incremental/delta cursor never widens the ORIGINAL bounded window
        // a full sync established (Microsoft's own documentation ties a
        // delta token to the calendarView start/end it was created with;
        // Google's syncToken carries incremental state, not a wider
        // timeMin/timeMax). Without a periodic full re-read, the local busy
        // cache would silently stop covering the product's configured
        // "next full_sync_window_days" horizon as time passes, even while
        // every incremental sync keeps succeeding. Bounded well below
        // full_sync_window_days so the cache never drifts far behind the
        // configured horizon, and well above the 15-minute incremental
        // cadence so this never becomes the dominant source of provider
        // request volume.
        'full_resync_interval_hours' => env('CALENDAR_EXTERNAL_FULL_RESYNC_INTERVAL_HOURS', 24),
    ],

    // Review correction, §11/§12.F — provider push-notification channel
    // (Google `events.watch`) / subscription (Microsoft Graph
    // `POST /subscriptions`) lifetime. Google documents no fixed maximum for
    // the `events` resource; Graph's own documented maximum for the `event`
    // resource is exactly 10,080 minutes (7 days) — both default to that
    // same conservative figure. `renewal_lead_hours` is how far ahead of the
    // provider-granted expiration the scheduled sweep renews, so polling
    // never silently becomes the only thing working.
    'notifications' => [
        'google_expiration_minutes' => env('CALENDAR_EXTERNAL_GOOGLE_NOTIFICATION_MINUTES', 10080),
        'outlook_expiration_minutes' => env('CALENDAR_EXTERNAL_OUTLOOK_NOTIFICATION_MINUTES', 10080),
        'renewal_lead_hours' => env('CALENDAR_EXTERNAL_NOTIFICATION_RENEWAL_LEAD_HOURS', 24),
    ],

];
