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

    'default_provider' => 'telnyx',

    'webhook_rejection_retention_days' => env('MESSAGING_WEBHOOK_REJECTION_RETENTION_DAYS', 30),
];
