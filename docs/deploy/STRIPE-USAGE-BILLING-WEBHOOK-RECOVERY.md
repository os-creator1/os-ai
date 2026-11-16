# Stripe usage-billing webhook — recovery runbook

Endpoint: `POST /stripe/webhook/usage-billing` (`StripeWebhookController`).

## Root cause (code-verified; production logs were not accessible)

`StripePaymentProviderGateway::verifyWebhookSignature()` called
`assertConfigured()` first. That check requires the **outbound API**
configuration (`STRIPE_MODE` in `test|live`, a `STRIPE_SECRET` starting with
`sk_<mode>_`, and a non-empty `STRIPE_API_VERSION`, which has no default) in
addition to the webhook secret. Any gap — most plausibly `STRIPE_API_VERSION`
unset, `STRIPE_MODE` left at its `test` default with live keys, or a restricted
`rk_live_` key (which `deploy/.../preflight.sh` allows but the check rejects) —
threw a `RuntimeException` *before* signature verification. Every delivery then
returned HTTP 500 (and so would an unsigned probe), until Stripe disabled the
endpoint. Verify against the production log: look for
`services.stripe.… must not be empty` / `does not match the configured …mode`
in `storage/logs` around the 500s (read-only).

## Fix

Webhook verification now needs only `STRIPE_WEBHOOK_SECRET`. Signature
verification is unchanged (`Webhook::constructEvent` over the raw body).
An empty webhook secret fails closed as **400** with a critical log line and
nothing stored. Idempotency is unchanged (unique `provider_event_id`; duplicate
→ 200, no second dispatch). Wallet crediting still runs in the queued job,
which still requires full API configuration.

## Before re-enabling (production actions need separate authorization)

1. Deploy this fix. Confirm env presence (names only, never print values):
   `STRIPE_MODE=live`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` (the `whsec_`
   for **this** endpoint), `STRIPE_API_VERSION`. Run
   `deploy/forge/production/preflight.sh`; clear/rebuild config cache.
2. Confirm a queue worker is running and the failed-jobs table is empty or understood.
3. Smoke test with no side effects: an unsigned `curl -i -X POST
   https://getmotiongrove.com/stripe/webhook/usage-billing -d '{}'` must return **400**.
4. In the Stripe Dashboard re-enable the endpoint (or send a Stripe CLI/test
   event from the Dashboard) and confirm one 2xx delivery and one
   `payment_provider_events` row.

## Reconciling missed events (never replay blindly)

Events Stripe failed to deliver are kept ~30 days. Do not bulk "resend all".

1. List the gap in Stripe (Dashboard → Developers → Events/Webhooks, filter by
   the endpoint's failed deliveries, or `stripe events list` read-only) and
   record each `evt_` id, type and object id.
2. For each, check `payment_provider_events.provider_event_id` locally. Present
   → already ingested; do nothing.
3. For absent ones, compare against the funding attempt / wallet ledger by
   `provider_object_id` (PaymentIntent / Checkout Session). Only if the attempt
   is still non-terminal and the amount, currency and customer match, resend
   **that single event** from the Dashboard ("Resend"). Intake re-verifies the
   signature and the job's own amount/currency/customer/metadata checks and
   idempotency prevent duplicate wallet credits or receipts.
4. Money already charged in Stripe but with no local attempt/credit: review in
   the admin `provider-events` screen and resolve by documented disposition —
   never insert ledger rows by hand.
5. After each resend, confirm exactly one event row, one terminal transition
   and one ledger credit; stop at the first anomaly.
