# Implementation Contract 21 — Commercial Payments Completion

**Status:** authoritative close-out contract for the remaining payment work.
**Base:** current `main` at authoring time — `9b7c50af` (PR #365).
**Supersedes nothing.** Contract 17 (lane B) and RFC-005/Contract 09 (lane D)
remain authoritative for their own lanes; this contract does not reopen them.

This is not a planning document. Every claim about existing code below was
verified mechanically against the tree at `9b7c50af` and is cited by path.

---

## 0. Why this contract exists

Everything in the product except *taking the platform's own money* is built.
The remaining work is commercial, not architectural, and it is the last thing
standing between this repository and a real paying customer.

The goal is **not** "another payment slice". The goal is:

> A brand-new customer signs up, picks a V1 plan, provides a payment method,
> enters a configured trial or an active subscription, receives the correct
> Workspace entitlements, renews / fails / recovers / cancels correctly, and
> the money lands in the Platform Owner's Stripe account.

After lane C and the four-lane conformance pass, payments are **frozen**
unless a real production defect appears.

---

## 1. The four money lanes — locked

Four different economic relationships happen to use one payment provider.
That is a coincidence of vendor, not of domain. This section is the locked
definition; §2 is the enforcement rule.

### Lane A — Platform SaaS subscription revenue

**Who pays whom:** a Workspace owner (Core / Growth / Agency subscriber) pays
**the Platform Owner**.

**Where the money lands:** the Platform Owner's own Stripe account, as a
direct charge. No Connect account is involved in either direction.

**Status at `9b7c50af`:** **NOT IMPLEMENTED.** There is no Lane-A provider
boundary, no Lane-A subscription record, no Lane-A webhook endpoint, and no
Lane-A signup. This contract builds it.

### Lane B — Business revenue

**Who pays whom:** an end customer of a Business (the Business's own client)
pays **that Business**.

**Where the money lands:** the Business's **own connected Stripe account**
(`business_stripe_connections.stripe_account_id`), as a direct charge on that
account. The platform takes **no** application fee (Contract 17 §11.2).

**Status:** implemented and merged — Contract 17 sub-slices A–G.
Artifacts: `business_documents`, `business_document_versions`,
`business_document_payment_schedule_items`, `business_document_payments`,
`business_document_refunds`, `business_stripe_connections`,
`business_payment_events`; `app/Library/Payments/*`;
`stripe/webhook/business-payments`.

**This money is the Business's revenue, not the platform's.**

### Lane C — Agency SaaS revenue

**Who pays whom:** an Agency's own SaaS client pays **that Agency**.

**Where the money lands:** the **Agency's** connected Stripe account. The
revenue belongs to the Agency, exactly as lane B's belongs to the Business.

**Status:** **NOT IMPLEMENTED.** Contract 01/07/08A build the Agency ↔ Client
Workspace relationship and provisioning; none of it takes money. Lane C is the
phase immediately after this one and is explicitly **out of scope here**.

### Lane D — Usage funding

**Who pays whom:** a Business / Workspace / AgencyRebill payer funds **the
platform's usage wallet** so metered work (messaging, AI) can be performed.

**Where the money lands:** the platform's Stripe account — but as *funding of
a prepaid balance*, not as subscription revenue.

**Status:** substantially implemented — RFC-005 M1–M3 + Contract 09.
Artifacts: `business_usage_wallets`, `business_usage_ledger_entries`,
`business_usage_reservations`, `business_usage_addon_*`,
`payment_provider_customers`, `payment_provider_events`,
`business_payment_instruments`; `app/Library/Usage/*`;
`stripe/webhook/usage-billing`.

### 1.1 The one-line summary

| Lane | Payer | Payee | Nature |
|---|---|---|---|
| A | Workspace owner | Platform Owner | SaaS subscription revenue |
| B | Business's client | the Business | Business revenue |
| C | Agency's client | the Agency | Agency revenue |
| D | Business/Workspace/Agency | platform wallet | usage funding |

**Only lane A is ordinary platform SaaS subscription revenue.**

---

## 2. Cross-lane isolation — the enforcement rule

> **No transaction, subscription, customer, event, instrument or webhook
> artifact may be shared between lanes merely because all four lanes use
> Stripe.**

Concretely, and testably:

- Lane A **MUST NOT** create, read-for-authority, or mutate:
  - any Contract 17 table (`business_document*`, `business_stripe_connections`,
    `business_payment_events`);
  - any RFC-005 table (`business_usage_*`, `payment_provider_customers`,
    `payment_provider_events`, `business_payment_instruments`);
  - `App\Library\Payments\*` (lane B) or `App\Library\Usage\*` (lane D);
  - lane C's tables once they exist.
- Lane A **MUST** own its own provider-customer identity, its own
  subscription record, and its own webhook event table and endpoint.
- Lane D's `payment_provider_customers` is **not** reusable as "the Stripe
  customer for this account". It is scoped to usage funding and to a payer
  resolution model (`EffectivePayer`) that has no meaning for a Workspace
  subscription.
- A wallet credit **never** unlocks a Locked Workspace, and a Lane-A payment
  **never** credits a usage wallet (Blueprint §27, already stated).

**What IS legitimately shared:** the *API credential* for the platform's own
Stripe account (`services.stripe.secret`). Lanes A and D both charge into that
account; lane B uses the same credential only to act *on behalf of* a connected
account via the `Stripe-Account` header. Sharing a credential is not sharing a
commercial identity, and this contract permits exactly that and nothing more.

Each lane keeps its **own webhook endpoint and its own signing secret**:

| Lane | Endpoint | Secret |
|---|---|---|
| A | `stripe/webhook/platform-subscriptions` (new) | `services.stripe.platform_subscription_webhook.secret` (new) |
| B | `stripe/webhook/business-payments` | `services.stripe.connect_webhook.secret` |
| D | `stripe/webhook/usage-billing` | `services.stripe.webhook.secret` |

### 2.1 Reporting

Platform reporting may read all four lanes. It **MUST NOT** present B, C or D
as platform SaaS revenue. A combined multi-lane revenue surface is the
four-lane conformance phase's concern; this phase requires only that lane A be
operationally supportable on its own (§11).

### 2.2 Do not rewrite B or D

Lanes B and D are not reopened by this contract. They are touched only if the
final cross-lane conformance test proves a **concrete** defect, and then only
to the extent that defect requires.

---

## 3. Current repository reality — the two subscription worlds

Verified inventory at `9b7c50af`.

### 3.1 The legacy Ultimate SMS world

| Artifact | Path |
|---|---|
| `Plan`, `Subscription`, `SubscriptionLog`, `SubscriptionTransaction` | `app/Models/` |
| `Invoices`, `PaymentMethods`, `PlanSendingCreditPrice`, `PlansCoverageCountries` | `app/Models/` |
| `SubscriptionRepository` / `EloquentSubscriptionRepository` / `EloquentPlanRepository` | `app/Repositories/` |
| `Auth\RegisterController` | signup — selects a legacy `Plan`, then a legacy `PaymentMethods` gateway |
| `Customer\SubscriptionController` | legacy plan page, renew, purchase, cancel |
| `Customer\PaymentController` | legacy gateway callbacks |
| `Admin\PlanController`, `Admin\SubscriptionController`, `Admin\InvoiceController` | legacy admin |
| `CheckSubscription`, `CheckUserPreferences` | scheduled legacy commands |

`RegisterController` offers Braintree, Stripe, Authorize.Net, Cash,
NowPayments, EasyPay, FedaPay and Vodacom M-Pesa as signup payment methods.

**Exactly 14 files in `app/` reference the legacy `Subscription` model.** None
of them is an entitlement, access-gate or middleware path.

### 3.2 The V1 world

| Artifact | Path |
|---|---|
| `workspace_plan_catalog` | tier, display_name, price, currency_id, billing_cycle, slot counts, `is_active` |
| `workspace_plan_features` | per-tier feature availability |
| `workspace_plan_assignments` | **`unique(workspace_id)`** — status, `is_complimentary` + reason/grantor/grant time, `additional_business_slots`, `trial_ends_at`, `grace_started_at`, `locked_at` |
| `workspace_plan_catalog_pricing_changes` | the existing price-history authority |
| `EntitlementManager` | `app/Library/Entitlement/` — 2460 lines, sole writer |
| `CustomerAccountAccessResolver` / `Guard` | the sole access authority |
| `WorkspacePlanPresenter` | the customer-facing plan view model |
| `WorkspacePlanTier` | `core` / `growth` / `agency` — identity only |
| `WorkspacePlanAssignmentStatus` | `active` / `inactive` / `suspended` |
| `CustomerAccountAccessState` | `usable` / `locked` / `locked_inactive` / `locked_suspended` |

### 3.3 The canonical lifecycle already exists — do not duplicate it

Blueprint §27's **Trial → Active → Grace → Locked → Inactive** (+ Suspended)
is already implemented, and **not** as a fourth status enum. It is derived by
`CustomerAccountAccessResolver::resolveActiveLifecycle()` from
`WorkspacePlanAssignmentStatus::Active` plus three timestamps, in this order:

1. `locked_at` set → **Locked**
2. `grace_started_at` + `GRACE_PERIOD_DAYS` elapsed → **Locked** (defensive)
3. `grace_started_at` still running → **Usable**, Grace hint
4. `trial_ends_at` set → **Usable**, trial hint
5. all null → plain **Active**

`EntitlementManager` already owns every writer this contract needs:

- `assignFirstPlan(..., ?CarbonInterface $trialEndsAt = null)`
- `changePlan(...)`, `changePlanStatus(...)`
- `enterGracePeriod()`, `lockForNonPayment()`, `recoverAccess()`
- `advanceExpiredTrialIntoGrace()`, `lockElapsedGracePeriod()`
- `findWorkspaceIdsWithExpiredOutstandingTrial()`,
  `findWorkspaceIdsWithElapsedGracePeriod()`
- `grantComplimentaryStatus()`, `revokeComplimentaryStatus()`
- `updateCatalogPricing()` (+ `workspace_plan_catalog_pricing_changes`)
- `GRACE_PERIOD_DAYS = 3`

`app/Console/Commands/AdvanceWorkspaceAccountLifecycle` is already scheduled
hourly and drives the trial→Grace and Grace→Locked sweeps.

> **Lane A therefore adds no lifecycle enum, no second grace window, no second
> price-history table and no second plan authority. It supplies the
> *provider-confirmed reasons* that make those existing writers fire.**

### 3.4 The actual defect — a fabricated complimentary assignment

`WorkspaceManager::resolveLegacyOnboardingWorkspace()` →
`EntitlementManager::createLegacyOnboardingCompatibilityAssignment()` gives
**every** legacy-registered customer a `is_complimentary = true`, **Core**
assignment, regardless of the plan they selected and paid for through
`RegisterController`.

So today:

- the legacy `Subscription` row records what they bought and paid;
- the V1 `workspace_plan_assignments` row says "free Core";
- V1 access is derived from the V1 row, so **the money and the entitlement
  disagree by construction**.

This is the concrete meaning of "two authorities", and removing it is the
central obligation of lane A.

---

## 4. One V1 authority — the required end state

**`workspace_plan_assignments` (via `EntitlementManager` and
`CustomerAccountAccessResolver`) is the sole authority for what a Workspace is
entitled to and whether it may be used.** That is already true for access; this
contract makes it true for *commercial provenance* too.

### 4.1 Retirement / compatibility strategy — smallest safe

Legacy tables are **not** dropped. Inherited SMS-era code still reads them, and
deleting them would broaden scope far past payments.

Binding rules instead:

1. **New V1 signup MUST NOT create a legacy `Subscription`, legacy
   `SubscriptionTransaction`, legacy `Invoices` or legacy `PaymentMethods`
   row.**
2. **New V1 signup MUST NOT route through
   `createLegacyOnboardingCompatibilityAssignment()`.** It creates a real,
   non-complimentary assignment at the selected tier.
3. **No legacy `Plan` or `Subscription` row may influence any V1 entitlement,
   capability, access or lifecycle decision.** (Already true; now
   structurally asserted.)
4. Legacy `RegisterController` and `Customer\SubscriptionController` may
   remain reachable for inherited installs, but they are **no longer the
   canonical V1 signup or the canonical plan page**, and the V1 routes must
   not link to them.
5. `createLegacyOnboardingCompatibilityAssignment()` stays only for genuinely
   pre-existing legacy Workspaces. It must be unreachable from the new V1
   signup path.

### 4.2 Structural tests required

- A legacy `Subscription` at any status, for a user whose Workspace holds a V1
  assignment, changes **no** entitlement, capability or access decision.
- The V1 signup path creates **zero** rows in `subscriptions`,
  `subscription_transactions`, `invoices` and `payment_methods`.
- A V1 signup's assignment is `is_complimentary = false` and its catalog tier
  equals the tier the customer selected.
- `workspace_plan_assignments` has exactly one row per Workspace
  (`unique(workspace_id)` — already enforced in DDL).

---

## 5. Lane A provider boundary

One explicit boundary class, in its own namespace, reachable only by lane A.

- It is the **only** lane-A code permitted to reference a `Stripe\*` SDK class.
- Nothing crosses it except normalized value objects and plain strings — no
  `Stripe\*` object, no raw payload, no API key, no provider error text.
- It never reads a Connect account id and never sends the `Stripe-Account`
  header. Lane A is a **direct, first-party** charge on the platform's own
  account.
- It is constructed **lazily** and fails closed at call time, never at
  construction — the same rule Contract 17 §12.D arrived at after a missing key
  turned into a 500 on an unauthenticated page.

**Provider rules:**

- No provider network call inside a DB transaction or while a row lock is held.
- Every charge- or subscription-creating call carries a **durable local
  idempotency key** derived from a local row's UID, so a repeat can never
  originate a second subscription or a second charge.
- An uncertain response re-drives **the same local row and the same key**.
- Before implementing any provider-specific behavior, the **exact** current
  Stripe API behavior required is verified against Stripe's official
  documentation. No generic Stripe research, and no reliance on recalled
  parameter names.

### 5.1 Secrets

`services.stripe.secret` and the lane-A webhook signing secret remain
**environment/secure runtime configuration**. They are **not** moved into
editable database columns to make an admin screen convenient.

The Platform Owner surface may show: configured / not configured, test vs live
mode, whether the webhook endpoint has been seen, last event processed, and
setup instructions. It **MUST NOT** render a secret, a key prefix, a key
length, or any substring of a key back to the browser, ever — including
immediately after configuration.

---

## 6. Canonical local Lane-A subscription identity

One durable local record per Workspace subscription. It must answer all of the
following **without querying Stripe first**:

| Question | Source |
|---|---|
| Which Workspace? | `workspace_id` |
| Which V1 plan/tier? | `workspace_plan_catalog_id` |
| Which local commercial-price version? | snapshotted price + currency + billing cycle at purchase |
| Which Stripe customer? | `provider_customer_id` |
| Which Stripe subscription? | `provider_subscription_id` |
| Current billing period? | `current_period_start` / `current_period_end` |
| Trial end? | `trial_ends_at` (snapshotted) |
| Cancel at period end? | `cancel_at_period_end` |
| Provider state? | local enum mapped from the provider's, in one seam |
| Latest processed provider event? | `last_event_id` / `last_event_at` |
| Why is this Workspace Trial/Active/Grace/Locked? | the assignment's own timestamps + the subscription's recorded reason |

Rules:

- **Raw Stripe objects are never the domain model.** The provider's status
  vocabulary dies in one mapping seam, exactly as Contract 17 §11.8 does it.
- No full provider payloads are stored beyond what replay safety needs.
- **No card details, ever.** Not a PAN, not a CVC, not an expiry. Card data is
  collected only by Stripe's own hosted UI / Elements and never reaches this
  application.
- The commercial terms are **snapshotted at purchase**. Changing the catalog
  price tomorrow must not retroactively rewrite an existing subscriber's terms.

---

## 7. Signup — the canonical V1 flow

Blueprint §6, implemented for real:

```
Name / email / password
  → Business name
  → Niche
  → Basic info
  → Plan (Core / Growth / Agency)
  → Payment method + trial start
  → Workspace + Business + exactly one Primary Location
  → correct V1 plan assignment
  → Blueprint installation
  → Home
```

- Visual presentation may be simplified; the **persisted authority must be
  correct**.
- Signup **MUST NOT** require A2P, Google connection, calendar integration, or
  Business Stripe Connect. Those are post-signup checklist items.
- The payment step uses **Stripe, lane A only**. No dropdown offering
  Braintree / Cash / NowPayments / Authorize.Net / EasyPay / FedaPay /
  Vodacom for the V1 commercial signup.
- Nothing is provisioned as paid until the provider confirms. **No successful
  provider result → no fabricated paid Active state.**

---

## 8. Trials

- Trial availability and duration are **Platform Owner commercial
  configuration** per tier, not constants in application logic. `$97`, `$297`,
  `$497`, 7 days and 14 days are **never** hard-coded. Fixtures in tests may
  use any amounts.
- The trial length is **snapshotted** onto the subscription/assignment at
  signup (`trial_ends_at`), so changing the default tomorrow does not rewrite
  an existing subscriber's trial.
- A payment method is collected as part of trial signup (Blueprint §6's
  "payment method + trial start").
- Trial is **not** a new lifecycle status: it is
  `WorkspacePlanAssignmentStatus::Active` + `trial_ends_at`, which
  `CustomerAccountAccessResolver` already renders as Usable-with-hint.

---

## 9. Lifecycle behavior

Canonical, per Blueprint §27 and Contract 03, using the **existing** writers:

- A failed renewal starts **one** 3-day Grace window
  (`enterGracePeriod()`), once. Repeated provider failure events must not
  extend it repeatedly.
- Grace retains normal access with a billing warning.
- Grace expiry → **Locked** (`lockForNonPayment()`, driven by the existing
  scheduled sweep). Bounded and idempotent.
- Locked is read-only / paid access blocked, **data preserved**.
- A confirmed successful payment **immediately** restores access
  (`recoverAccess()`) and clears the billing-failure state.
- Inactive follows the existing retention policy. Suspended stays separate,
  manual and compliance-only.
- Webhook replay must not double-transition, and out-of-order delivery must
  not move canonical state backwards.

---

## 10. Plan changes, upgrades, downgrades, cancellation

### 10.1 Pricing changes

- Changing a catalog price **MUST NOT** mutate historical commercial terms and
  **MUST NOT** silently move existing subscribers to the new amount.
- `EntitlementManager::updateCatalogPricing()` +
  `workspace_plan_catalog_pricing_changes` remain the **only** price-history
  authority. No second one is created.
- Where the provider requires a new immutable Price identity for a new amount,
  that is modeled explicitly rather than mutated in place.
- Contract obligation (tests must prove):
  - **new signup** uses the currently published price;
  - **existing active subscriptions** keep their snapshotted price;
  - **upgrade** and **downgrade** use the price published at the moment of the
    change.

### 10.2 Upgrade / downgrade

- **Upgrade → immediate.**
- **Downgrade → at the current billing period's end.**
- Entitlement effects follow the canonical Workspace plan assignment.
- **Customer data is never deleted because a feature disappeared on
  downgrade.** Components become inactive/unaddable, never destroyed
  (Blueprint §16/§21).
- Location over-capacity continues to follow the existing capacity rules
  (Addendum §6). Lane A invents no payment-specific deletion or archive
  behavior.

### 10.3 Cancellation

- Customer cancellation has **one** deterministic meaning: it preserves access
  through the already-paid period (provider `cancel_at_period_end` + the local
  mirror of it), unless the authoritative product docs say otherwise.
- At the actual end of service, the lifecycle/access authority changes
  accordingly.
- Webhook replay must not double-transition or corrupt a canceled Workspace.

---

## 11. Platform Owner controls

One coherent commercial configuration surface in the Platform Owner area,
requiring **no source edits** to run the business:

- per tier: **price, currency, billing cycle, trial enabled, trial duration,
  availability for new signup**;
- enough Stripe status to know whether lane A can actually accept payments:
  configured / mode / webhook seen / last event processed;
- Billing / Revenue view with: current Core/Growth/Agency pricing and trials,
  Active / Trial / Grace / Locked counts, failed-payment attention items,
  basic lane-A revenue facts from local + provider-confirmed state, and
  webhook health.

Plan commercial configuration belongs to the **V1 plan/catalog domain**, not
to a new payments-only settings table.

Lane B/C/D money is **never** presented as platform SaaS revenue.

### 11.1 Complimentary / manual accounts

- The Platform Owner may run complimentary Workspaces **without fabricating a
  Stripe subscription**. `workspace_plan_assignments.is_complimentary` +
  `grantComplimentaryStatus()` / `revokeComplimentaryStatus()` already express
  this and remain the mechanism.
- Complimentary remains clearly distinct from a paid active subscription in
  every read model.
- Administrators may mark/maintain complimentary status, inspect status, and
  extend or support a trial. **They may never fabricate a customer's financial
  consent or create a charge on their behalf.**

---

## 12. Webhooks

Support the **minimum closed event set** required to derive:

- subscription started / trialing
- subscription active
- successful recurring payment
- payment failure → Grace
- subscription updates (plan, cancel-at-period-end, period roll)
- cancellation / end of service
- relevant checkout completion

Exact event names and object relationships are taken from **current official
Stripe documentation** at implementation time, not from memory.

Endpoint policy:

1. **Signature verified against the raw body before anything is inserted.** An
   invalid signature is a 400 with **zero** side effects.
2. Durable intake: identity + hash recorded; a duplicate delivery is caught on
   a unique key and answered 200 with **zero** reprocessing.
3. Processing is claimed atomically (claim/lease), so concurrent delivery of
   one event is processed once.
4. **Lane/account checked.** An event whose customer/subscription does not
   resolve to a lane-A record is **fail-closed** with a reason code — never
   guessed at, and never allowed to touch another lane's rows.
5. Unknown events are acknowledged and ignored with a reason code.
6. Out-of-order delivery must not move terminal or canonical state backwards.

---

## 13. Customer "Plan & Subscription" page

`customer.workspaces.plan.show` already renders from `WorkspacePlanPresenter`
(the V1 authority). It gains:

- current Core / Growth / Agency tier
- current subscription + lifecycle status
- price and billing period
- trial end when trialing
- next renewal when known
- cancel / change-plan actions appropriate to the current state

It **MUST NOT** surface legacy Ultimate SMS plan semantics as the current
product.

---

## 14. Boundaries — what lane A may never do

Lane A code **must not** create or mutate:

- Contract 17 Business documents, payments or refunds
- `business_stripe_connections` rows
- lane C Agency subscription rows, once they exist
- RFC-005 wallet / usage ledger rows, and must never record usage funding as
  subscription revenue

Existing Contract 17 and usage-billing tests that prove this separation must
remain green.

---

## 15. Exit condition for lane A

Lane A is complete when the automated suite proves, end to end:

new V1 signup → Stripe subscription/trial → correct Workspace provisioning →
canonical plan assignment → entitlement/access → webhook-driven renewal and
failure → Grace / Locked / recovery → upgrade / downgrade → cancellation →
Platform Owner controls —

**and there is no competing legacy subscription authority for new V1
customers.**

"Stripe Checkout opens" is **not** completion.

---

## 16. Live acceptance

`FakeStripe` tests passing is **not** a live test and must never be reported as
one. `docs/product/PAYMENTS-LIVE-ACCEPTANCE.md` carries the operator checklist
to be executed manually in Stripe **test mode** after the lanes merge. Lane C,
B and D sections are appended to that same document later.

---

## 16.1 Verified provider facts (checked against official Stripe docs)

Checked at implementation time against `docs.stripe.com`, not from memory.
Recorded here so a future reader can tell what was verified from what was
assumed.

- **Subscription `status` is a closed enum:** `incomplete`,
  `incomplete_expired`, `trialing`, `active`, `past_due`, `canceled`,
  `unpaid`, `paused`. (`/api/subscriptions/object`)
- **`current_period_start` / `current_period_end` now live on the
  SUBSCRIPTION ITEM** (`items.data[].current_period_*`), not on the
  subscription object, in the current API. Older pinned API versions still
  expose them at subscription level, so the gateway reads the item first and
  falls back — it never assumes one shape.
- `trial_start`, `trial_end`, `cancel_at_period_end`, `cancel_at`,
  `canceled_at`, `ended_at`, `default_payment_method`, `latest_invoice` are
  subscription-level. (`/api/subscriptions/object`)
- **Trial mechanism:** the **Trial Offer API is public preview**, requires the
  `2026-03-25.preview` API version, and is explicitly **not supported by
  Checkout** — Stripe's own guidance there is "instead use legacy free trials
  with `trial_end`". Lane A therefore uses the classic
  `subscription_data.trial_period_days` (integer, minimum 1, maximum 730 days).
  (`/billing/subscriptions/trials`, `/billing/subscriptions/trials/free-trials`)
- A trial subscription still issues an immediate invoice for **0**. At trial
  end Stripe generates an invoice and attempts the charge roughly an hour
  later, and a new billing period begins.
- `subscription_data.trial_settings.end_behavior.missing_payment_method`
  (`create_invoice` / `pause` / `cancel`) governs a trial that ends with no
  payment method. Lane A collects a payment method at signup, so the default
  `create_invoice` applies.
- **Checkout Session:** `mode: subscription` is the documented way to set up
  fixed-price subscriptions; `client_reference_id` (≤ 200 chars) is the
  documented field for reconciling a session with internal systems and is what
  carries lane A's durable local identity. The session object exposes
  `customer`, `subscription`, `status` and `payment_status`.
  (`/api/checkout/sessions/create`)
- **The closed lane-A event set**, with Stripe's own stated meaning:
  - `checkout.session.completed` — signup checkout finished
  - `customer.subscription.created` — "subscription starts"
  - `customer.subscription.updated` — "sent when a subscription starts or
    changes… renewing a subscription… and changing plans all trigger this"
  - `customer.subscription.deleted` — "sent when a customer's subscription
    ends"
  - `invoice.paid` — "provision access to your product when you receive this
    event and the subscription status is active"
  - `invoice.payment_failed` — "a payment for an invoice failed"

  Everything else is acknowledged and ignored with a reason code.

---

## 17. Sequencing after this contract

1. **Lane A** — this phase, on `agent/final-payments-a-platform-subscriptions`.
2. **Lane C** — Agency SaaS plans, only after lane A is reviewed and merged.
3. **Four-lane conformance** + real test/live acceptance.
4. **Freeze.**

No unrelated product work (SEO, COO, or otherwise) between these phases.
