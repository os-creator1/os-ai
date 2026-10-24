<?php

/*
|--------------------------------------------------------------------------
| Managed messaging (Customer Experience Slice 3 §4.4)
|--------------------------------------------------------------------------
|
| Non-secret behavioural configuration only. Every credential lives in
| config/services.php (and therefore in the environment), never here and
| never in a per-Business database row.
|
| managed_messaging_enabled is a platform-wide kill switch, deliberately
| independent of whether credentials happen to be present: real credentials
| existing in an environment are by themselves insufficient to activate
| production traffic. It defaults to false in every environment, including
| testing, so a test that wants to exercise the real adapter must turn it on
| visibly in that test.
|
*/

return [
    'managed_messaging_enabled' => env('MANAGED_MESSAGING_ENABLED', false),

    /*
    | PR #295 Correction Round 1, item 5 — a SEPARATE, default-OFF
    | production gate for live number purchase and carrier-registration
    | submission specifically. An installation can have
    | managed_messaging_enabled=true (ordinary managed SMS sending) without
    | wanting live provisioning to be reachable at all; normal managed
    | sending never checks this key. Both this gate AND
    | managed_messaging_enabled AND a provider key are required before any
    | real (non-fake) provisioning adapter can be constructed — see
    | ProvisioningAvailability::isConfigured() and
    | TelnyxProvisioningAdapter's own constructor.
    */
    'managed_messaging_provisioning_enabled' => env('MANAGED_MESSAGING_PROVISIONING_ENABLED', false),

    /*
    | Mixed ("Both") 10DLC campaigns. Telnyx's OpenAPI confirms the
    | `subUsecases` field and a per-use-case min/max, but which sub-use-cases
    | a brand may declare is answered per brand at runtime, and carrier
    | acceptance cannot be confirmed without a live submission. Off until
    | that has been verified; the other use cases are unaffected.
    */
    'ten_dlc_mixed_enabled' => env('MESSAGING_10DLC_MIXED_ENABLED', false),

    /*
    | Owner-approved retail prices for the messaging provisioning fees,
    | in micro-units of the billing currency (1,000,000 = 1.00). Nothing is
    | invented: a fee with any value unset stays unconfigured, customers see
    | "pricing not set up", and nothing can be charged for it. Applied by
    | `php artisan messaging:sync-provisioning-rates` (idempotent; run it
    | after changing these). Toll-free verification is $0 and has no entry.
    |
    |   number_purchase         the first charge when a number is bought: this IS the
    |                           first month's rental (no separate rental is taken
    |                           until next month), plus any one-time setup
    |   number_rental_renewal   monthly rental of each number, from month two on
    |   ten_dlc_registration    one-time: carrier brand fee + the first three months
    |                           of campaign fees (Telnyx bills them upfront)
    |   ten_dlc_campaign_renewal monthly campaign cost, charged only AFTER the three
    |                           prepaid months (local numbers)
    |
    | A paid purchase/registration is refused unless its recurring price is
    | configured too, so no ongoing carrier cost is ever left unbilled.
    */
    'rates' => [
        'currency' => env('MESSAGING_RATE_CURRENCY', 'USD'),
        'actor_user_id' => env('MESSAGING_RATE_ACTOR_USER_ID'),
        'approved_by' => env('MESSAGING_RATE_APPROVED_BY'),
        'number_purchase' => ['retail_micro' => env('MESSAGING_NUMBER_PURCHASE_RETAIL_MICRO'), 'cost_micro' => env('MESSAGING_NUMBER_PURCHASE_COST_MICRO'), 'unit' => 'per number'],
        'number_rental_renewal' => ['retail_micro' => env('MESSAGING_NUMBER_RENTAL_RETAIL_MICRO'), 'cost_micro' => env('MESSAGING_NUMBER_RENTAL_COST_MICRO'), 'unit' => 'per month'],
        'ten_dlc_registration' => ['retail_micro' => env('MESSAGING_10DLC_REGISTRATION_RETAIL_MICRO'), 'cost_micro' => env('MESSAGING_10DLC_REGISTRATION_COST_MICRO'), 'unit' => 'per registration'],
        'ten_dlc_campaign_renewal' => ['retail_micro' => env('MESSAGING_10DLC_CAMPAIGN_RETAIL_MICRO'), 'cost_micro' => env('MESSAGING_10DLC_CAMPAIGN_COST_MICRO'), 'unit' => 'per month'],
    ],

    /** Months of campaign fees Telnyx bills upfront at registration (covered by the registration price). */
    'ten_dlc_prepaid_months' => (int) env('MESSAGING_10DLC_PREPAID_MONTHS', 3),

    'default_provider' => 'telnyx',

    'webhook_rejection_retention_days' => env('MESSAGING_WEBHOOK_REJECTION_RETENTION_DAYS', 30),

    /*
    | Phone Numbers + A2P lane — messaging contract §13.2/§28.2. The grace
    | period is deliberately non-zero and never automatic-release: it only
    | bounds how long a Suspended number may sit before an explicit,
    | audited release DECISION becomes eligible
    | (NumberLifecycleManager::recordReleaseDecision() still refuses before
    | this elapses; nothing releases a number on its own, and even that
    | decision never itself claims the carrier has released it). The
    | advance-warning window is how far ahead of next_renewal_at this
    | platform alerts a Business whose projected balance looks insufficient
    | — a look-ahead notice, not the renewal attempt itself.
    |
    | number_release_minimum_notice_days is separate from the grace period:
    | it is how long the release notice must have been CONFIRMED DELIVERED
    | (never merely dispatched) before a release decision may be recorded,
    | so the customer has a genuinely meaningful opportunity to act — e.g.
    | to port the number out — after actually being notified, not merely
    | after this platform attempted to notify them.
    */
    'number_renewal_grace_days' => env('MESSAGING_NUMBER_RENEWAL_GRACE_DAYS', 14),

    'number_renewal_advance_warning_days' => env('MESSAGING_NUMBER_RENEWAL_ADVANCE_WARNING_DAYS', 7),

    'number_release_minimum_notice_days' => env('MESSAGING_NUMBER_RELEASE_MINIMUM_NOTICE_DAYS', 7),
];
