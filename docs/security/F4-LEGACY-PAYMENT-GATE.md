# F4 - legacy payment routes are closed by default

## Finding (confirmed against current staging `138199f8`)
The inherited Ultimate SMS routes under `customer.*` / `user.*` (success/return URLs, provider callbacks, card-form
posts, offline and crypto flows) were reachable by any logged-in customer and several handlers never contact the
provider. Reproduced by `tests/Feature/Security/LegacyPaymentGateTest.php` against unmodified code:

* `POST /payment/top-up/success?payment_method=2checkout&user_id=<any>&sms_unit=1000000&price=1` credited 1,000,000 SMS
  units (to the caller or to any other tenant): `user_id`, `sms_unit`, `price` all come from the request.
* `GET /senderid/<any uid>/success?payment_method=coinpayments` activated another tenant's sender ID and wrote a paid invoice.
* `GET /subscriptions/<plan>/success?payment_method=2checkout` created a PAID invoice and subscription for free.

Precondition: the matching `PaymentMethods` row active. All are seeded inactive, but an administrator can enable one.
Other branches (MPGS, 0xProcessing, Razorpay, Instamojo, PayUMoney ...) look weak by inspection but were not individually proven.

## Fix
`App\Http\Middleware\LegacyPaymentGate` (alias `legacy.payments`, attached to the customer and auth route groups)
refuses every legacy payment route (success/return, `callback.*`, braintree/authorize.net/vodacommpesa posts, offline,
nowpayments, top-up and plan/sender-id/number/keyword purchase POSTs) unless its gateway is listed in
`config('legacy_payments.enabled_gateways')` (env `LEGACY_PAYMENT_GATEWAYS`, default empty). Unknown or unnamed gateway = refused.
Callbacks/API posts get HTTP 410; browsers are redirected home with a message. The admin "activate gateway" toggle refuses
to enable an unlisted legacy gateway. V1 payments (Stripe webhooks, business payments, platform/agency billing) use different
routes and are untouched.

## Re-enabling a gateway
Only after auditing its handler to verify the provider server-side (signature or API lookup bound to this user, amount and
entity, with replay protection). `offlinePayment()` also has an ownership gap (`$sender->user`) to fix first.
