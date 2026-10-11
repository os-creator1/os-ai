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

**Real Stripe test mode has NOT been exercised** (no test credentials and no route
to api.stripe.com from the build environment). What *is* established:

* **Official docs (search of docs.stripe.com):** `from_subscription` on schedule
  create is documented; phases are sequential and one phase's `end_date` equals the
  next `start_date`; `end_behavior` is `release|cancel`; **`iterations` was removed
  from schedule create/update in API version 2025-09-30 and now errors** (use
  `duration`). The gateway therefore never sends `iterations`: every phase, including
  the last, ends at an explicit `end_date` (the last one a single period after it
  starts), and `end_behavior=release` leaves the subscription on that Price.
* **stripe-php v7.128.0:** the services (`subscriptionSchedules` create / retrieve /
  update / release, `products`, `prices`) and the fields used (`Subscription.schedule`,
  `trial_end`, schedule `status`, `end_behavior`, Checkout `expires_at`) exist.
* **Wire contract (`StripeWireContractTest`):** the REAL gateway and REAL SDK with
  only the HTTP transport recorded prove the exact parameters sent: Price
  `recurring[interval]=day`, `interval_count=28`; schedule create `{from_subscription}`
  then update with `end_behavior=release`, per-phase `end_date`, first-phase
  `start_date` and `trial_end`, `proration_behavior=none`, **no `iterations`, no
  coupon**; Checkout `payment_method_collection=always`, `expires_at`,
  `subscription_data.metadata`, and no trial parameters when there is no trial.

Still BLOCKED until run in test mode (test clocks) — see the acceptance checklist
in the PR: that Stripe *accepts* those parameters on the account's API version;
the promotion boundary lands on the 28-day period boundary and the next invoice is
the reverted amount; trial → first paid invoice; billing-change timing with no
proration line; webhook retry/replay delivery; invoice amounts.

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
