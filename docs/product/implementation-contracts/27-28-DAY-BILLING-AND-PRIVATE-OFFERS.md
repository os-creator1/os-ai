# 27 — 28-day billing and Private Offers (lane A)

Builds on the existing lane-A platform subscription infrastructure
(`PlatformSubscriptionManager`, `PlatformSubscriptionFinalizer`,
`StripeApiPlatformGateway`). There is **no second subscription system**.

## 1. Standard billing

* Core, Growth and Agency bill **every exactly 28 days**: Stripe
  `recurring.interval=day`, `interval_count=28`. Cycle value `every_28_days`
  (`BillingCycle`). `yearly` is kept; `monthly` is legacy (read-only description).
* Copy says **"Every 28 days"** — never "monthly" for the 28-day cycle.
* **No free trial by default.** A new customer pays at Checkout. An owner may still
  switch a plan trial on deliberately.
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
| After a temporary discount | automatically reverts to the standard price (custom price if set, else plan price) |
| Who | customer email (verified-email match) and/or an existing Workspace |
| Limits | expiry date, redemption limit |

Two kinds: `new_subscription` (Checkout) and `billing_change` (explicit, audited
change of an existing subscriber's price; starts at the next renewal, nothing
charged today; needs the customer's acceptance if any phase could cost more than
they pay now, otherwise the owner may apply it directly with a reason).

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
