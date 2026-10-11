# 27 — 28-day billing and Private Offers (lane A)

Builds on the existing lane-A platform subscription infrastructure
(`PlatformSubscriptionManager`, `PlatformSubscriptionFinalizer`,
`StripeApiPlatformGateway`). There is **no second subscription system**.

## 1. Standard billing

* Core, Growth and Agency bill **every exactly 28 days**: Stripe
  `recurring.interval=day`, `interval_count=28`. Cycle value `every_28_days`
  (`BillingCycle`). `yearly` is kept; `monthly` is legacy (read-only description).
* Copy says **"Every 28 days"** — never "monthly" for the 28-day cycle.
* **Ordinary signup has no trial.** A new customer pays at Checkout. The plan
  catalog's trial fields are inert unless `config('platform_billing.allow_public_trials')`
  (env `PLATFORM_ALLOW_PUBLIC_TRIALS`, default **false**) is switched on, and no
  public page advertises a trial. Special trials are issued only through eligible
  Private Offers.
* Existing subscriptions keep their own price/cycle/trial/Price snapshots; nothing
  in the migration writes `platform_subscriptions` commercial columns.
* The migration moves `monthly` plan rows to `every_28_days`. A row that pointed
  at a Price loses that pointer (kept in `legacy_provider_price_id`) and comes off
  sale until the owner clicks **Create "Every 28 days" Stripe Price** (Billing &
  Revenue). It fails closed rather than selling a 28-day plan on a monthly Price.

## 2. Private Offers

Platform Owner → Billing & Revenue → **Private Offers** → Create Offer → Copy Link.
Also on the Workspace details screen (card "Private offers").

**Off by default.** `config('platform_billing.private_offers_enabled')`
(`PLATFORM_PRIVATE_OFFERS_ENABLED`, default `false`) is enforced on the server by the
`private.offers` route middleware and by the offer services. While off, no new offer
can be created, re-linked, viewed, accepted or applied, owner screens and offer links
answer 404, and the navigation entries are hidden. It never touches an agreement
already in force: webhooks, renewals, schedule reconciliation, cancel / resume and
*revoke* keep working, so existing subscribers keep their original contractual
billing terms with the switch on or off.

| Term | Behaviour |
|---|---|
| Plan | Core / Growth / Agency |
| Trial | any 1–730 days; starts only after provider-confirmed Checkout |
| Custom recurring price | becomes the offer's *standard* price |
| Discount | fixed or percentage off the standard price |
| Duration | forever · one billing period · N billing periods (N = 28-day periods) |
| After a temporary promotion | automatically reverts to the **explicitly agreed ongoing price**: the permanent negotiated price if one was set, otherwise the normal catalog price |
| Who | customer email (verified-email match) and/or an existing Workspace |
| Limits | expiry date, redemption limit |

Two kinds: `new_subscription` (Checkout) and `billing_change` (explicit, audited
change of an existing subscriber's price; starts at the next renewal, nothing
charged today; needs the customer's acceptance if any phase could cost more than
they pay now, otherwise the owner may apply it directly with a reason).

### Temporary promotion vs permanent negotiated price
These are different things and are labelled differently everywhere:
* *Permanent negotiated price* — the custom recurring price (or a "forever"
  discount). Shown as "Permanent agreed price"; it is what a temporary promotion
  reverts to.
* *Temporary promotion* — a discount for one or N billing periods. Shown as
  "Temporary promotional price" with its exact end date, followed by "Then your
  agreed ongoing price" (negotiated) or "Then the standard plan price" (catalog).
Exact prices and dates are displayed before acceptance, and the same terms are
frozen on the redemption.

### Stripe representation
* Every agreed amount is an **immutable Stripe Price** (day × 28 on the plan's
  Product) created and *re-read and verified* by MotionGrove
  (`PlatformPriceProvisioner`), shared by equal amounts (`platform_stripe_prices`).
  An offer at the plan's list amount uses the plan's own Price.
* A temporary promotion is a **Subscription Schedule**: promo Price until an
  explicit end instant (`start + N × 28 days`), then the standard Price. **No
  coupons** are used (calendar-month coupon durations cannot express N × 28 days).
* Checkout always collects a payment method (`payment_method_collection=always`).

### Safety model
* Link = 256-bit random token; only its SHA-256 is stored; GET is a pure read.
* The customer must tick acceptance; the server recomputes the terms and compares
  a hash of the commercial facts to what they were shown.
* Redemption is `reserved` → `redeemed` **only** from a provider-confirmed
  subscription snapshot (`OfferReconciler`, called by the shared finalizer path,
  the Checkout return and the webhook consumer). Idempotent under webhook replay.
* Capacity counted under a lock on the offer row; `unique(offer, active_slot)`
  guarantees one live claim per Workspace.
* Free trials: `platform_trial_claims` (unique Workspace, mailbox key, provider
  customer); reserved at Checkout, *granted* only when the provider confirms the
  trial; survives cancel / resubscribe / alias accounts; legacy trials backfilled.
* A plan change never changes a subscriber's billing cycle (a legacy monthly
  subscriber cannot be moved onto a 28-day plan by the self-serve change); such a
  move is a deliberate, separate step.
* Revoking never alters a redeemed agreement; a plan change on private pricing
  needs an explicit acknowledgement and releases the schedule first.
* History: append-only `platform_subscription_term_snapshots`.

## 3. Stripe setup required
1. `STRIPE_SECRET` / `STRIPE_KEY` and `STRIPE_PLATFORM_SUBSCRIPTION_WEBHOOK_SECRET`
   as before (test keys on staging). No new env vars.
2. If a **restricted** key is used it must also allow write on **Products, Prices
   and Subscription Schedules** (plus the existing Checkout / Customers /
   Subscriptions / Billing Portal).
3. Webhook endpoint unchanged (`/stripe/webhook/platform-subscriptions`, same six
   events). Promotion phase changes arrive as `customer.subscription.updated`.
4. After deploy: Billing & Revenue → for each plan **Create "Every 28 days"
   Stripe Price**, then tick *Available to new signups*.
5. Verify once in Stripe **test mode** (test clocks): schedule phases, trial +
   promotion, billing change. The fake gateway models Stripe, it is not Stripe.
6. A subscription under a schedule cannot be edited in the Dashboard; release the
   schedule there first.
7. Scheduler must run (`platform-offers:sweep`, every 15 min).

## 4. Verification status (Stripe)

**Exercised against real Stripe TEST mode** (sandbox account, `sk_test_` key, Stripe
CLI webhook forwarding with real signed deliveries, test clocks; no mocked gateway,
no live key). The same scenarios were run on two API versions and on final code:

| Run | Requests carried `Stripe-Version` | Webhook event payload |
|---|---|---|
| Account default | `2023-08-16` | `2023-08-16` |
| Newer API | `2026-08-26.dahlia` (header injected on every SDK request, in the test process **and** the webhook server; every response echoed that version) | `2026-09-30.endive` (`stripe listen --latest`) |

> `Stripe::setApiVersion()` does **not** reach `StripeClient` in stripe-php 7.128, so a
> header injected through the HTTP client is the only reliable pin. PHP's built-in
> server also does not run `auto_prepend_file` when a router script is used; a pin
> installed that way is silently absent. A run is only counted when the response's
> own `Stripe-Version` header was logged.

Scenarios that passed on both versions: public signup sends **no** trial and always
collects a payment method; paid subscription bills a **28-day** interval and renews
exactly 28 days later with no proration; entitlement only after provider-confirmed
payment (real webhook); a declined first payment leaves the subscription incomplete
and un-entitled; private offer 50 % for 2 periods → promotional Price, schedule
phases at start + 2 × 28 d then + 28 d with `end_behavior=release`, billing
49.50 / 49.50 / 99.00 with no proration line, release leaves the standard Price;
28-day trial offer (trialing, first charge at the boundary, then every 28 days);
trial + one-period promotion; billing change (permanent 99 → 79, and 30 % off for 2
periods then 99) starting at the current period end with nothing charged today;
upgrade during a promotion (acknowledgement required, schedule released first);
signature rejection and replay idempotency; a foreign subscription fails closed;
revoked / bogus links reveal no terms.

**Bug found and fixed by this run.** Stripe refuses to change cancelation behaviour on
a subscription a Subscription Schedule manages ("updating any cancelation behavior
directly is not allowed"), which is exactly what a Private Offer promotion or billing
change is. Cancelling at period end during one failed. `OfferReconciler::withScheduleSuspended()`
now releases the agreement's own schedule first (a schedule MotionGrove did not
create is never touched and the call fails closed), keeps the redemption `redeemed`
with its schedule `pending`, and `restoreAfterResume()` puts the agreed Price path
back with the same boundaries when the customer resumes; the sweep does not re-apply
while the cancellation stands. Verified on real Stripe: cancel → resume → 49.50,
49.50, 99.00 (and 99, 79, 79 for a billing change), no proration.

**Newer API webhook shape.** From API version 2025-03-31 an `invoice` event no longer
carries `subscription` at the top level; it is under `parent.subscription_details`.
Both the webhook intake and the event job now read either shape (before this, a
`subscription_create` `invoice.paid` on a newer-version endpoint was recorded without
its subscription id and failed `no_matching_local_record`).

**Version-mixing hazard (test environment only).** A subscription created on a newer
API version has `billing_mode.type=flexible`, and Stripe refuses schedule calls on it
from a request pinned to an older version. In production every request uses the one
version the account / SDK uses, so this cannot arise; do not pin different versions
for different processes.

Private Offers are additionally behind the server-side switch in section 6.
## 5. Known limitations
* **Email-bound offers require real email verification.** With
  `ACCOUNT_VERIFICATION=false` every new account is stamped verified at creation
  without proving the mailbox, so an email match would prove nothing. The system
  therefore fails closed: email-bound offers cannot be created, an existing one
  cannot be redeemed (`offer_verification_disabled`) and the offer page does not
  offer account creation. Offers bound to a **Workspace** (ownership-checked) work
  normally, as do ordinary signup and billing changes. A staging install running
  `ACCOUNT_VERIFICATION=false` can therefore test Workspace-bound offers only.
* The offer link appears in web-server access logs (it is a bearer secret in the
  URL); it is never stored in plain text by the app and is rotated with "New link".
* Public trials share the same ledger but one reservation can outlive its 2-hour
  window on a 24 h Checkout session; the ledger's unique keys then keep the first
  claim and log the conflict.
* A "failed" schedule state (a foreign schedule on the subscription) needs
  operator attention; the sweep only retries `pending`.
* Legacy monthly subscribers are left exactly as they are; they cannot self-serve
  a plan change onto 28-day plans.
* Until the 28-day Stripe Prices are created after deploy, formerly monthly plans
  are off sale and signup/plan-changes show "not ready to be purchased".
* Private Offers are separable: the 28-day change does not depend on them.

## 6. Launch sequence and rollback
1. Deploy code + `php artisan migrate`. Data effect: plans move to `every_28_days`;
   plans that pointed at a monthly Price lose that pointer (kept in
   `legacy_provider_price_id`), come off sale, trials are switched off. Existing
   subscriptions are not written (proven by `LaunchAcceptanceTest`). Trial history is
   back-filled into the trial ledger.
2. Billing & Revenue → each plan → **Create "Every 28 days" Stripe Price** (creates
   and verifies a day × 28 Price on the plan's Product).
3. Save each plan with *Available to new signups* ticked (verifies the Price again).
4. Test-mode signup end to end; then repeat with live keys only when approved.
5. Ensure the scheduler runs (`platform-offers:sweep`).
6. Private Offers stay hidden until `PLATFORM_PRIVATE_OFFERS_ENABLED=true` is set
   deliberately (it is `false` unless set).

Until step 3 signups are closed ("not ready to be purchased") and existing monthly
subscribers cannot self-serve a plan change onto 28-day plans (billing schedules are
never re-anchored implicitly).

**Rollback:** `migrate:rollback` restores each moved plan's monthly Price and cycle
from `legacy_provider_price_id`, then drops the new columns/tables. Subscriptions
created on 28-day Prices in the meantime keep their own Price/cycle snapshots and
keep billing every 28 days at Stripe (rollback never touches Stripe or
subscriptions); they would need a deliberate manual decision. Private-offer tables
are dropped, so rolling back after offers were redeemed orphans their schedules at
Stripe — release them first.
