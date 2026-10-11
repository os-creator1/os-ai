<?php

/*
|--------------------------------------------------------------------------
| Legacy (Ultimate SMS) payment gateways
|--------------------------------------------------------------------------
|
| The inherited per-gateway payment routes (success / return URLs, provider callbacks, card-form posts, offline
| and crypto flows) are NOT part of the V1 product and several of them never verify anything with the provider:
| they trust the query string (payment_method, user_id, sms_unit, price) and then credit SMS units, activate
| sender IDs / numbers / keywords or write paid invoices. They are therefore closed by default
| (App\Http\Middleware\LegacyPaymentGate).
|
| A gateway may be re-enabled ONLY after its success/callback handler has been audited to verify the provider's
| authenticity server-side (signature or a provider API lookup bound to this user, amount and entity, with
| replay protection). List its PaymentMethods type, comma separated, e.g. LEGACY_PAYMENT_GATEWAYS=mollie
|
*/

return [
    'enabled_gateways' => array_values(array_filter(array_map(
        fn ($v) => strtolower(trim($v)),
        explode(',', (string) env('LEGACY_PAYMENT_GATEWAYS', ''))
    ))),
];
