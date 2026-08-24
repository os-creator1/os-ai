<?php

    /*
    |--------------------------------------------------------------------------
    | RFC-005 Milestone 3 — Usage Billing / Payment-Provider Configuration
    |--------------------------------------------------------------------------
    |
    | New keys, additive only (M3 contract §19). No retention default is
    | invented — an unset USAGE_BILLING_WEBHOOK_RETENTION_DAYS must fail
    | closed, never silently retain payloads forever or purge immediately.
    |
    */

    return [

        'webhook_event' => [
            'lease_minutes' => env('USAGE_BILLING_WEBHOOK_LEASE_MINUTES', 5),
            'max_attempts' => env('USAGE_BILLING_WEBHOOK_MAX_ATTEMPTS', 5),
            'retention_days' => env('USAGE_BILLING_WEBHOOK_RETENTION_DAYS'),
        ],

        /*
        |----------------------------------------------------------------
        | RFC-005 Milestone 5 — Conversations pilot tuple
        |----------------------------------------------------------------
        |
        | Three nullable scalars, all null by default (fail-closed).
        | docs/automation/RFC-005-M5-CONTRACT.md §9.2/§3.6. A qualifying
        | send requires the resolved Business id, destination country id,
        | and SendingServer id to exactly equal all three simultaneously.
        |
        */

        'conversations_metering' => [
            'pilot_business_id' => env('USAGE_BILLING_CONVERSATIONS_PILOT_BUSINESS_ID'),
            'pilot_country_id' => env('USAGE_BILLING_CONVERSATIONS_PILOT_COUNTRY_ID'),
            'pilot_sending_server_id' => env('USAGE_BILLING_CONVERSATIONS_PILOT_SENDING_SERVER_ID'),
        ],

    ];
