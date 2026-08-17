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

    ];
