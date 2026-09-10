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

    'default_provider' => 'telnyx',

    'webhook_rejection_retention_days' => env('MESSAGING_WEBHOOK_REJECTION_RETENTION_DAYS', 30),
];
