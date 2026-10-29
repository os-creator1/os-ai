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
    | Owner-approved fee schedule, in micro-units of the billing currency
    | (1,000,000 = 1.00), applied by `php artisan messaging:sync-provisioning-rates`
    | (idempotent). Nothing is invented: a fee with any value unset stays
    | unconfigured, customers see "pricing not set up", and nothing can be
    | charged for it. Toll-free verification is $0 and has no entry.
    |
    | PASS-THROUGH fees (zero markup): the carrier's real price is read at the
    | time — per number from Telnyx's search quote — and the rate here is only
    | the owner's MULTIPLIER on it. 1000000 = exactly the carrier cost (0%
    | markup); 1100000 would be +10%. Never put a dollar amount in these.
    |
    |   number_purchase          multiplier on (upfront cost + FIRST month's rental) at order
    |   number_rental_renewal    multiplier on each number's own monthly cost, from month two
    |   sms_outbound, sms_inbound, mms_outbound, mms_inbound_local, mms_inbound_toll_free
    |                            owner-approved RETAIL BASE per billable part. Outbound is held and
    |                            charged when the carrier accepts the message, then RECONCILED to
    |                            Telnyx's final record (messaging:reconcile-costs); inbound is billed
    |                            by that reconciliation.
    |   carrier_surcharge        multiplier on Telnyx's ACTUAL carrier surcharge; must be exactly
    |                            1000000 (surcharge at cost), also carries settlement debits
    |   carrier_pass_through     multiplier on an explicitly billed carrier charge (e.g. a
    |                            campaign review) — see messaging:charge-carrier-fee
    |
    | FIXED fees (carrier's published at-cost amounts; verify on invoices):
    |
    |   ten_dlc_brand_registration     carrier brand fee, charged when the brand is created
    |   ten_dlc_registration           first three campaign months (the carrier bills them
    |                                  upfront) for standard campaign types
    |   ten_dlc_campaign_renewal       monthly campaign cost after those three months
    |   ten_dlc_low_volume_registration / ten_dlc_low_volume_campaign_renewal
    |                                  the same two for the separate Low Volume Mixed type
    |
    | A paid purchase/registration is refused unless its recurring price is
    | configured too, so no ongoing carrier cost is ever left unbilled.
    */
    'rates' => [
        'currency' => env('MESSAGING_RATE_CURRENCY', 'USD'),
        'actor_user_id' => env('MESSAGING_RATE_ACTOR_USER_ID'),
        'approved_by' => env('MESSAGING_RATE_APPROVED_BY'),
        'number_purchase' => ['retail_micro' => env('MESSAGING_NUMBER_PURCHASE_RETAIL_MICRO'), 'cost_micro' => env('MESSAGING_NUMBER_PURCHASE_COST_MICRO'), 'unit' => 'per 1.00 of carrier cost'],
        // Owner-approved US retail base per BILLABLE PART (USD), before Telnyx's actual carrier surcharge.
        // cost_micro is informational (Telnyx's real per-message cost is reconciled from the message record).
        'sms_outbound' => ['retail_micro' => env('MESSAGING_SMS_OUTBOUND_RETAIL_MICRO', 7470), 'cost_micro' => env('MESSAGING_SMS_OUTBOUND_COST_MICRO', 0), 'unit' => 'per outbound SMS segment'],
        'sms_inbound' => ['retail_micro' => env('MESSAGING_SMS_INBOUND_RETAIL_MICRO', 7470), 'cost_micro' => env('MESSAGING_SMS_INBOUND_COST_MICRO', 0), 'unit' => 'per inbound SMS segment'],
        'mms_outbound' => ['retail_micro' => env('MESSAGING_MMS_OUTBOUND_RETAIL_MICRO', 22000), 'cost_micro' => env('MESSAGING_MMS_OUTBOUND_COST_MICRO', 0), 'unit' => 'per outbound MMS'],
        'mms_inbound_local' => ['retail_micro' => env('MESSAGING_MMS_INBOUND_LOCAL_RETAIL_MICRO', 16500), 'cost_micro' => env('MESSAGING_MMS_INBOUND_LOCAL_COST_MICRO', 0), 'unit' => 'per inbound MMS (local number)'],
        'mms_inbound_toll_free' => ['retail_micro' => env('MESSAGING_MMS_INBOUND_TOLL_FREE_RETAIL_MICRO', 20000), 'cost_micro' => env('MESSAGING_MMS_INBOUND_TOLL_FREE_COST_MICRO', 0), 'unit' => 'per inbound MMS (toll-free number)'],
        'carrier_surcharge' => ['retail_micro' => env('MESSAGING_CARRIER_SURCHARGE_RETAIL_MICRO', 1000000), 'cost_micro' => env('MESSAGING_CARRIER_SURCHARGE_COST_MICRO', 1000000), 'unit' => 'per 1.00 of Telnyx carrier surcharge'],
        'carrier_pass_through' => ['retail_micro' => env('MESSAGING_CARRIER_PASS_THROUGH_RETAIL_MICRO'), 'cost_micro' => env('MESSAGING_CARRIER_PASS_THROUGH_COST_MICRO'), 'unit' => 'per 1.00 of carrier cost'],
        'number_rental_renewal' => ['retail_micro' => env('MESSAGING_NUMBER_RENTAL_RETAIL_MICRO'), 'cost_micro' => env('MESSAGING_NUMBER_RENTAL_COST_MICRO'), 'unit' => 'per 1.00 of carrier cost'],
        'ten_dlc_registration' => ['retail_micro' => env('MESSAGING_10DLC_REGISTRATION_RETAIL_MICRO'), 'cost_micro' => env('MESSAGING_10DLC_REGISTRATION_COST_MICRO'), 'unit' => 'per registration'],
        'ten_dlc_brand_registration' => ['retail_micro' => env('MESSAGING_10DLC_BRAND_RETAIL_MICRO'), 'cost_micro' => env('MESSAGING_10DLC_BRAND_COST_MICRO'), 'unit' => 'per brand'],
        'ten_dlc_low_volume_registration' => ['retail_micro' => env('MESSAGING_10DLC_LOW_VOLUME_REGISTRATION_RETAIL_MICRO'), 'cost_micro' => env('MESSAGING_10DLC_LOW_VOLUME_REGISTRATION_COST_MICRO'), 'unit' => 'per registration'],
        'ten_dlc_low_volume_campaign_renewal' => ['retail_micro' => env('MESSAGING_10DLC_LOW_VOLUME_CAMPAIGN_RETAIL_MICRO'), 'cost_micro' => env('MESSAGING_10DLC_LOW_VOLUME_CAMPAIGN_COST_MICRO'), 'unit' => 'per month'],
        'ten_dlc_campaign_renewal' => ['retail_micro' => env('MESSAGING_10DLC_CAMPAIGN_RETAIL_MICRO'), 'cost_micro' => env('MESSAGING_10DLC_CAMPAIGN_COST_MICRO'), 'unit' => 'per month'],
    ],

    /**
     * Per-part carrier-surcharge ESTIMATE (micro-USD) held with an outbound message so the wallet is
     * known to cover it. Headroom only: the charge is corrected to Telnyx's actual surcharge afterwards.
     */
    'estimated_carrier_surcharge_micro' => (int) env('MESSAGING_ESTIMATED_CARRIER_SURCHARGE_MICRO', 5000),

    /**
     * Automatic reconciliation (scheduled): the platform administrator recorded as the actor on credits, the largest
     * single settlement it may apply on its own (larger variances become a visible incident), and the bound on retries.
     */
    'reconciliation' => [
        'actor_user_id' => env('MESSAGING_RECONCILIATION_ACTOR_USER_ID'),
        'max_auto_adjustment_micro' => (int) env('MESSAGING_RECONCILIATION_MAX_AUTO_ADJUSTMENT_MICRO', 1_000_000),
        'max_attempts' => (int) env('MESSAGING_RECONCILIATION_MAX_ATTEMPTS', 12),
        'retry_minutes' => (int) env('MESSAGING_RECONCILIATION_RETRY_MINUTES', 120),
    ],

    /**
     * Fail closed for paid messaging: while the managed switches are on, a managed SMS/MMS is REFUSED (before any
     * carrier contact) unless its retail meter and the carrier-surcharge meter are metered and priced. Only set this
     * to false for tests/local development, never in production - otherwise an unpriced meter sends for free.
     */
    'require_paid_billing' => (bool) env('MESSAGING_REQUIRE_PAID_BILLING', true),

    /** Largest single upfront or monthly number price (micro-USD) that will be sold; dearer special numbers are never offered. */
    'number_price_ceiling_micro' => (int) env('MESSAGING_NUMBER_PRICE_CEILING_MICRO', 25_000_000),

    /**
     * Telnyx's published pricing says "an additional charge of $0.10 per month applies to add SMS and
     * MMS capabilities to a number" on top of the listed number price, and cost_information is the
     * number's listed price; so the add-on is charged as part of the monthly price (ONE combined
     * monthly amount per number — never as a second line). The pilot invoice must confirm it: if
     * Telnyx's cost_information already includes it, set this to 0 so it is never charged twice.
     * Snapshotted on each number at purchase (sms_addon_monthly_cost_micro).
     */
    'number_sms_addon_monthly_cost_micro' => (int) env('MESSAGING_NUMBER_SMS_ADDON_MONTHLY_COST_MICRO', 100000),

    // Disclosure-only: the possible one-time carrier campaign review fee shown
    // before a customer submits. It is never charged at submit time; if the
    // carrier bills it, it is charged explicitly via messaging:charge-carrier-fee.
    'ten_dlc_review_fee_micro' => (int) env('MESSAGING_10DLC_REVIEW_FEE_DISCLOSURE_MICRO', 15000000),

    /** Months of campaign fees Telnyx bills upfront at registration (covered by the registration price). */
    // Telnyx qualification error codes (errors[0].code) CONFIRMED to mean the brand is
    // not eligible for the campaign type. Empty until confirmed from provider documentation:
    // every other refusal is treated as an uncertain, retryable submission issue.
    'qualification_ineligible_codes' => array_filter(explode(',', (string) env('MESSAGING_QUALIFICATION_INELIGIBLE_CODES', ''))),

    // Hours a brand may stay unverified before the customer is shown "Needs attention" (polling continues).
    'brand_verification_attention_hours' => (int) env('MESSAGING_BRAND_VERIFICATION_ATTENTION_HOURS', 72),

    'ten_dlc_prepaid_months' => (int) env('MESSAGING_10DLC_PREPAID_MONTHS', 3),

    /*
    | PILOT RESTRICTION. Real provisioning (searching/buying numbers, registering
    | a campaign, submitting toll-free verification) is only open to the
    | Businesses listed here — never to every customer by default. Set
    | MESSAGING_PILOT_BUSINESS_IDS to a comma-separated list of Business ids
    | (e.g. "42"). Only after the pilot is signed off, MESSAGING_PROVISIONING_OPEN_TO_ALL=true
    | opens it to everyone. This sits on top of the existing master switches
    | (managed_messaging_enabled, managed_messaging_provisioning_enabled).
    */
    'provisioning_open_to_all' => (bool) env('MESSAGING_PROVISIONING_OPEN_TO_ALL', false),
    'provisioning_pilot_business_ids' => env('MESSAGING_PILOT_BUSINESS_IDS', ''),

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
