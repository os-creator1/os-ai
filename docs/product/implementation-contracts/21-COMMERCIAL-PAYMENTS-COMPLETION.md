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
4. **Legacy signup disposition, as implemented.** The `register` route (GET and
   POST) now resolves to `Auth\V1SignupController`. `Auth\RegisterController`
   remains **on disk** — deleting it would broaden scope well past payments,
   and inherited installs still reference its views — but it is **no longer
   routed as the customer signup**, so there are not two equally valid signup
   paths. Its per-gateway registration payment routes (`pay-offline`,
   `pay-nowpayments`, and the braintree / authorize-net / sslcommerz /
   aamarpay / vodacommpesa actions) stay registered only so inherited Blade
   views cannot throw on a missing route name; none of them is reachable from
   V1 signup, which takes exactly one payment route: a hosted lane-A Stripe
   Checkout Session.
   `Customer\SubscriptionController` likewise remains on disk and is not
   resurrected: the customer's Plan & subscription page and its actions are
   served by `Workspace\WorkspaceController@plan` and
   `Workspace\PlanSubscriptionController`.
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
- Signup **MUST NOT** require A2P, Google connection, calendar integration,
  Business Stripe Connect or Telnyx configuration. Those are post-signup
  checklist items.
- The payment step uses **Stripe, lane A only**. No dropdown offering
  Braintree / Cash / NowPayments / Authorize.Net / EasyPay / FedaPay /
  Vodacom for the V1 commercial signup.
- Nothing is provisioned as paid until the provider confirms. **No successful
  provider result → no fabricated paid Active state.**

### 7.1 As implemented

`Auth\V1SignupController`, routed at `register` (GET/POST) plus
`signup/complete`, `signup/cancelled`, `signup/plan` and `signup/plan` (POST).

**`register` is GUEST ONLY.** An authenticated actor who POSTed there with a
different email would create a second User and be silently switched into it by
`Auth::login()`, abandoning their own account mid-session. The routes carry the
`guest` middleware, and the controller ALSO checks explicitly, because this
installation's inherited `RedirectIfAuthenticated` computes a home route and
then falls through to the next middleware anyway — so `guest` alone does not
actually stop the request here. Fixing that shared middleware would change
every `guest` route in the application, which is outside this lane, so lane A
states its own boundary. The authenticated re-entry routes (`signup.plan`,
`signup.resume`) remain authenticated.

**Ordering, chosen for durability rather than for screen order.** Provision
first — Workspace + Business + exactly one Primary Location, committed before
Stripe is contacted — then take the money, then assign the plan. Stashing a
half-built account in the session would lose the signup if the session died,
and would be unreachable from the webhook, which is the one path guaranteed to
arrive.

**An abandoned checkout is a known safe state**: a Workspace with no plan
assignment, which `CustomerAccountAccessResolver` already treats as a distinct
pre-existing case. `signup/plan` is the resumable screen.

**RESUME MUST NOT PROVISION.** `startSubscription()` provisions, and
`provision()` always calls `upsertPrimaryLocation()`, which EDITS the existing
Primary Location when one is present. Routing "resume checkout" back through it
meant a Lithuanian account that abandoned checkout would have its Primary
Location silently rewritten to the resume form's defaults — country US,
timezone from config, niche Other. That is data corruption, not a retry.

Re-entry is therefore a separate operation,
`V1SignupManager::restartCheckout()`: it resolves the actor's own unassigned
Workspace, refuses one that already holds a plan (a subscriber changes plan
through §10.2, with its own authorization), and changes ONLY the pending lane-A
checkout state. It never writes Business identity, never writes a Location,
never replaces the niche, and never invents a country or a timezone.

> **Initial signup may provision. Resume may not.**

### 7.3 Checkout attempt identity

`local_idempotency_key` is `platform-subscription:{uid}` and never changes.
That is correct for RETRYING one uncertain provider request and wrong for a
DELIBERATE second attempt, because Stripe's idempotency layer "compares
incoming parameters to those of the original request and errors if they're not
the same", and a key may be pruned after 24 hours. A customer who opens Growth
checkout, cancels, and picks Agency would otherwise resend one key with a
different Price and be rejected by the provider.

Minting a fresh random key per click is worse: two simultaneously payable
Checkout Sessions for one Workspace means two possible subscriptions.

So an ATTEMPT is modelled durably — `checkout_attempt_uid`,
`checkout_attempt_price_id`, `checkout_attempt_started_at` — and the provider
key is `platform-subscription:{uid}:attempt:{attempt_uid}`:

| Situation | Behaviour |
|---|---|
| Same Price, session still `open` | SAME attempt key. Identical parameters, so Stripe returns the original session. |
| Same Price, session id never recorded (lost response) | SAME attempt key, re-driven. |
| Different Price | The open session is EXPIRED at the provider first (`POST /v1/checkout/sessions/{id}/expire`, valid only from `open`), then a NEW attempt is minted. |
| Previous session already `expired` | A new attempt is minted. |
| Previous session already `complete` | Refused — `CHECKOUT_ALREADY_COMPLETED`. The customer has paid; the webhook or the success endpoint converges that account. |

**At most one payable session exists per Workspace at any moment**, by
construction rather than by timing.

#### 7.3.1 Replacement is compare-and-swap, never a lock across the network

The table above describes one request in isolation. Under concurrency it was
not enough, and the gap was a money gap: the DECISION to replace is taken
against provider state read OUTSIDE any lock, so two simultaneous requests
could both inspect the same open session, both expire it, and both mint an
attempt — two payable sessions, two possible subscriptions, one Workspace.

Locking the row inside the mint does not fix that, and holding a transaction
across the Stripe call is forbidden by §5. The rule is therefore:

- `checkout_attempt_generation` is a monotonically advancing **CAS token**. A
  request records the generation it inspected, and the mint commits only if the
  generation is still that one.
- A superseded request **abandons its own decision and starts again from
  current database state**; it never overwrites a newer attempt.
- After a Checkout Session is created at the provider, the session id is
  persisted **only if the attempt uid is still current**. If it is not, the
  session that was just created is an ORPHAN that would still be payable, so it
  is expired at the provider before the request retries.
- Retry is **bounded** (three rounds). A request that keeps losing gives up
  with `CHECKOUT_CONTENDED`, leaving nothing payable behind, rather than
  recursing without a limit.

Expiring a superseded session is best-effort by design: a concurrent
replacement may legitimately have expired it a moment earlier, and that must
not turn into an error for the customer whose request actually succeeded.

An attempt's creation parameters are FIXED for its life: `provider_customer_id`
is deliberately not written from the checkout result, because doing so would
change the parameters the next call sends and make an honest retry fail the
provider's own idempotency comparison. The customer id becomes authoritative
when the finalizer reads it off the confirmed subscription.

**Account creation uses `UserRepository::store(..., confirmed: true)`**, not
`AccountRepository::register()`. `store()` is the same call `register()` makes
for the user and Customer — `Hash::make` for the password (no plaintext is ever
persisted), the `unique:users.email` rule, the standard Customer permissions —
without `register()`'s two legacy side effects: a notification hard-coded to
`user_id => 1`, which raises a foreign-key violation on any install where the
platform admin is not literally user 1 and would take the whole signup down
with it, and an implicit login this controller performs explicitly instead.

### 7.2 Webhook-driven activation — the seam

**THE WEBHOOK ALONE MUST BE ABLE TO FINISH THE ACCOUNT.** A customer who pays
and closes the tab never reaches the Checkout success endpoint. If activation
lived only there, that customer would be charged and left with an unassigned
Workspace — a money bug, not a UX one.

There is therefore **exactly one activation operation**,
`V1SignupManager::activateFromConfirmedSubscription()`, called by both the
success endpoint and `ProcessPlatformSubscriptionEvent`. Plan-assignment logic
is not duplicated between controller and job.

It is:

- **gated on provider confirmation** — `pending`, `incomplete`,
  `incomplete_expired`, `canceled`, `unpaid` and `paused` activate nothing;
  `trialing`, `active` and `past_due` do (`past_due` means a real subscription
  whose latest renewal failed, and Blueprint §27 keeps that account usable
  through Grace);
- **idempotent, and race-safe against the database** — the pre-check handles
  the common case, and `unique(workspace_id)` plus `assignFirstPlan()`'s own
  Workspace row lock turn a genuine browser-versus-webhook race into
  `WorkspacePlanAlreadyAssignedException` for the loser, which is caught as
  convergence;
- **free of browser and session state** — it takes everything from the durable
  subscription row;
- **gated on the ROW's state, not on this attempt having changed it.**
  Activating only on `APPLIED` left a durability hole: if the finalizer
  succeeded and activation then failed, the retry's finalizer would report
  nothing new and activation would be skipped forever, stranding a PAID
  Workspace. Every disposition that is not an IDENTITY FAILURE
  (`subscription_mismatch`, `customer_mismatch`, `operation_id_mismatch`) now
  activates, and the seam's own provider-confirmed gate plus its idempotency
  decide whether anything actually happens;
- **backed by a bounded retry policy.** `ProcessPlatformSubscriptionEvent`
  declares `tries = 3` with `[10, 60]` second backoff, overriding `Base`'s
  single attempt — money events must not be one-shot. The claim's WHERE
  already admits a `failed` row, so a retry reclaims the same event safely.
  A duplicate delivery of an event whose row is `failed` also redispatches it,
  turning Stripe's own retry into our recovery; every other state is left
  strictly alone;
- **not the Blueprint installer.** `InstallBlueprintOnFirstPlanAssigned`
  already listens for `WorkspacePlanAssigned`, which the assignment dispatches,
  and `NicheBlueprintInstaller::installForBusiness()` is itself the idempotent
  entry point that triggers and the recovery command share. A Blueprint failure
  therefore cannot undo a legitimate paid subscription, and the existing
  reconciliation path repairs it.

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
- A provider-confirmed **trial** on a Workspace that already holds a plan
  assignment restores access through `startProviderConfirmedTrial()` — see
  §9.1.
- Inactive follows the existing retention policy. Suspended stays separate,
  manual and compliance-only.
- Webhook replay must not double-transition, and out-of-order delivery must
  not move canonical state backwards.

### 9.1 Provider-confirmed trials on an existing assignment

The finalizer's `trialing` arm used to write nothing, and for a first signup
that was right: the assignment is created afterwards, carrying the same
provider-confirmed trial end, so there was nothing to converge.

Re-subscribing (§10.4) broke that assumption. A customer who cancels is
**Locked**. If they come back on a plan that carries a trial, Stripe confirms
`trialing`, the tier converges — and the stale `locked_at` stayed, so a
customer holding a valid Stripe trial was told their account was locked. The
money moved and the access did not.

`EntitlementManager::startProviderConfirmedTrial()` is the narrowest writer for
that, and EntitlementManager remains the **only** writer of the three lifecycle
timestamps — nothing in `App\Library\PlatformBilling` touches
`workspace_plan_assignments` directly.

| Rule | Why |
|---|---|
| Sets `trial_ends_at` to the **provider-confirmed** trial end; clears `grace_started_at` and `locked_at` | The provider decides when it starts charging. A local value that disagreed would either cut a trial short or promise one Stripe will not honour. Nothing here reads the catalog, so §8's "a later catalog edit cannot rewrite an existing trial" is untouched. |
| Not `recoverAccess()` | That means "nothing is outstanding" and clears all three. A trial **is** outstanding; clearing it would hide the account from the expiry sweep forever. |
| Idempotent — an assignment already on this exact trial, with no grace and no lock, is returned untouched | Every `customer.subscription.updated` for a trialing subscription reaches this writer. Replay must not move the trial end or write a second transition/event. |
| Reached **only** when provider status is `trialing` | Local pending intent never grants a trial. |
| `trialing` with **no usable trial end** (absent, or already in the past) → fail closed, lock stays, operator warned | Unlocking on a status alone is the fabricated paid state §7 forbids, and an ended trial is not a trial. A later, complete observation converges it. |
| Suspended / Inactive still throw, via the shared lifecycle preamble | An administrative suspension outranks any provider event (Contract 03 §5). |

First signup is unaffected: no assignment exists yet, so this arm does nothing
and signup activation creates the assignment from the same provider-confirmed
`trial_ends_at`.

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

#### 10.2.1 Every plan change is a DURABLE OPERATION, written before Stripe

Calling Stripe and then writing locally is not a plan change; it is two
independent events that usually happen together. A lost HTTP response, or any
exception in between, left the customer **paying for Growth while receiving
Core**, permanently: the webhook finalizer mirrors provider price and status,
and had no way to know which local tier that price was supposed to mean.

So a change is persisted **before the provider is touched**:

| Column | Meaning |
|---|---|
| `pending_operation_uid` | This operation's identity, and the source of its provider idempotency key |
| `pending_kind` | `upgrade`, `downgrade` or `resubscribe` |
| `pending_plan_catalog_id` | The target tier |
| `pending_price_id` | The target immutable Stripe Price |
| `pending_price_snapshot` / `pending_currency_id` / `pending_currency_code` / `pending_billing_cycle` | The commercial terms **agreed at request time** |
| `pending_effective_at` | Now for an upgrade; the period boundary for a downgrade |

The provider call happens outside every transaction, and the key is
`platform-subscription:{uid}:change:{pending_operation_uid}`.

**The key belongs to the operation, not to the target catalog id.** A Stripe
Price is immutable, so repricing a tier means a different Price; keying on the
catalog would send one key with different parameters for the same tier, which
the provider rejects.

**Convergence has exactly one seam**, `PlatformSubscriptionFinalizer`, reached
identically by the synchronous provider response, the scheduled downgrade
sweep, and any later webhook. Its gate is provider truth:

> **PROVIDER PRICE CHANGED TO TARGET → eventually the local commercial snapshot
> AND the canonical V1 entitlement converge to that same target, exactly
> once.**

If the provider is not on the target Price, **the entitlement is not widened**
and the operation stays open. When it is, convergence writes the commercial
snapshot, then the canonical entitlement, then clears the operation — in that
order, because every step is idempotent and clearing first would destroy the
record that repair depends on.

An operation that may already have reached the provider (an upgrade, a
re-subscribe, or a downgrade whose boundary has passed) is not silently
replaced: the same target re-drives the same operation and the same key, and a
different target is refused with `CHANGE_IN_PROGRESS`.

#### 10.2.2 A scheduled downgrade is bound to the terms agreed at request time

The boundary can arrive weeks after the request. Everything the sweep sends —
the Price, the amount, the currency, the cycle — comes from the operation, not
from today's catalog. **A customer who scheduled a 97.00 Core plan is not
moved onto a repriced 147.00 Core when the boundary arrives.**

### 10.3 Cancellation

- Customer cancellation has **one** deterministic meaning: it preserves access
  through the already-paid period (provider `cancel_at_period_end` + the local
  mirror of it), unless the authoritative product docs say otherwise.
- At the actual end of service, the lifecycle/access authority changes
  accordingly.
- Webhook replay must not double-transition or corrupt a canceled Workspace.

### 10.4 Re-subscribing after the subscription has fully ended

A `canceled` or `incomplete_expired` customer has no provider relationship left
to change, so §10.2 correctly refuses them — and that refusal was a dead end.
The page still offered upgrade/downgrade controls that could only ever fail,
and the account had no way back at all.

The rules:

- **Terminal ended states show "Start subscription again", never plan-change
  controls.** `state` is `ended`, which outranks `locked`: the lock is the
  consequence of the subscription ending, and telling that customer their
  account is merely locked invites them to fix a payment method that has
  nothing left to pay.
- **The account is reused, never rebuilt.** The same Workspace, the same
  Business, the same Locations, the same local subscription row — one row per
  Workspace is structural. No second account is ever provisioned.
- **A NEW provider subscription is created through hosted Checkout.** A
  canceled Stripe subscription cannot be revived by changing its Price.
- **Old provider history is retired, not erased.** The finished provider
  subscription id moves to `retired_provider_subscription_ids`, and the
  finalizer's cross-check refuses any snapshot naming a retired id. This is
  what stops a late `customer.subscription.deleted` from the previous life
  locking the account the customer has just paid to restart — those events
  still resolve to this row through the shared customer id and through our own
  `app_operation_id` metadata, so the retired list is the only thing that can
  tell the two lives apart.
- **Nothing is marked paid before confirmation.** The row keeps its ended
  status; the tier being bought is recorded as a `resubscribe` operation
  (§10.2.1) and the canonical entitlement moves — and access is restored — only
  when the provider confirms.
- The re-subscribe POST and its Checkout return are allowlisted in
  `CustomerAccountAccessGate`, because a locked account is exactly the account
  that needs them. Neither grants any product access; both remain
  owner-or-active-Admin and answer 404 otherwise.

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

### 11.0 As implemented

`Admin\PlatformBillingController` at `platform-billing` (GET) and
`platform-billing/{tier}` (POST), inside the existing admin route group behind
`EnsureUserIsAdministrator` and the `access backend` gate.

Per tier the owner sets **price, currency, billing cycle, trial enabled, trial
length, availability for new signup, and the Stripe Price id** — so **no
database edit is required to put a plan on sale**. Price and currency go
through `EntitlementManager::updateCatalogPricing()`, which remains the only
price-history authority and writes `workspace_plan_catalog_pricing_changes`
with the reason the owner gave; the trial and availability switches are catalog
columns with no price history of their own.

The page also shows, per tier, **whether it is actually sellable and exactly
what is missing if it is not**, and platform-wide: API key configured/missing,
mode, webhook secret configured/missing, the endpoint URL to paste into Stripe,
and the exact event list to subscribe.

**Provider Price mapping, and the PARITY CHECK.** A Stripe Price is immutable
in the relevant sense, so the workflow is: create a recurring Price on the
platform's own Stripe account, paste its `price_...` id here.

A format check alone is not enough. It proves only that the operator typed
something Price-shaped; it cannot stop the catalog saying **€297 / yearly**
while Stripe actually charges **$99 / monthly** — a silently wrong charge on
every subscriber.

> **THE INVARIANT: the local price / currency / cycle and the Stripe Price MUST
> represent the same commercial terms.**

So before ANY catalog mutation, `PlatformPriceVerifier` retrieves the Price
through `PlatformStripeGateway::retrievePrice()` — the platform secret alone,
never a `Stripe-Account` option — and requires all of:

1. retrievable at all (a lane-B or lane-C Price lives on a CONNECTED account
   and is simply not visible here, so this step is also the cross-lane
   boundary);
2. `active`;
3. recurring, not one-time;
4. currency equals the selected V1 currency;
5. `unit_amount` equals the submitted amount EXACTLY, in the smallest currency
   unit;
6. `recurring.interval` is `month` for monthly, `year` for yearly;
7. `recurring.interval_count` is exactly 1 (an `interval=month,
   interval_count=3` Price bills quarterly);
8. `livemode` matches the platform's configured mode.

It runs OUTSIDE any transaction and BEFORE `updateCatalogPricing()`, so a
failed verification leaves **zero** catalog rows and **zero** pricing-history
rows written.

**Minor units** are handled by `CurrencyMinorUnits`, using Stripe's own
zero-decimal list (BIF CLP DJF GNF JPY KMF KRW MGA PYG RWF VND VUV XAF XOF
XPF) rather than ISO's, with the documented special cases treated as
two-decimal for CHARGES: ISK and UGX ("represent as a two-decimal value where
the decimal amount is always 00"), and HUF/TWD (zero-decimal for payouts only).
The conversion is string arithmetic, never a float, because the result is
compared for exact equality.

**ISK and UGX carry two decimals but only whole units are chargeable.** Stripe
says plainly that you "can't charge fractions of" either, so the factor is 100
AND a non-zero fractional part is refused outright:

| Amount | ISK / UGX | HUF / TWD |
|---|---|---|
| `297` | 29700 | 29700 |
| `297.00` | 29700 | 29700 |
| `297.01` | refused | 29701 |
| `297.50` | refused | 29750 |

Treating `297.50 ISK` as 29750 would let the catalog hold a price Stripe can
never charge, and then let it pass a parity check that should have failed —
exactly the class of failure §11 exists to prevent. HUF and TWD are ordinary
two-decimal currencies for charges and are deliberately unaffected.

The gateway was deliberately **not** extended to create or version Prices
itself: that would be the beginning of a general Stripe product-management
system, which §11 rules out.

**Provider mode is truthful.** `configurationStatus()` reports `test` or `live`
only when it can be derived from a valid configured secret, and `null`
otherwise. Reporting "test" for a missing key would tell an operator their
integration is safely in test mode when in truth it is not configured at all —
the one thing that panel exists to say.

**Billing & Revenue** reports trialing / active / past-due / canceling /
canceled / pending counts, Grace and Locked counts taken from the canonical
plan assignment rather than guessed from provider vocabulary, complimentary
count, failed-payment attention items with their Grace start, a subscription
list carrying Workspace identity, tier, snapshotted price, trial end, period
end and cancellation state, and webhook health (received, failed, latest event
and its state). It is built entirely from durable local facts, so it answers a
support question without a provider round trip.

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

### 13.1 As implemented

`CustomerSubscriptionPresenter` supplies the read model and
`Workspace\PlanSubscriptionController` the actions, at
`{workspaceUid}/plan/change`, `/plan/cancel`, `/plan/resume`,
`/plan/payment-method`, `/plan/resubscribe` and `/plan/resubscribe/return`.

The **price shown is the customer's own snapshot**, not the current catalog
price. The **state word is derived from the access decision first** — the same
authority that actually governs the account — so the page cannot disagree with
the gate.

- **Change plan** lists the sellable tiers with the resulting behaviour stated
  before confirmation ("Upgrade — takes effect immediately, and you are billed
  the difference now" / "Downgrade — takes effect at the end of your current
  billing period. Nothing is deleted."), and requires an explicit
  confirmation checkbox.
- **Cancel** requires explicit confirmation, preserves access through the paid
  period, shows the effective end date afterwards, and is harmless to repeat.
- **Resume** is offered because the provider model genuinely supports it:
  `cancel_at_period_end` is a boolean that can be set back to false while the
  period is still running. It is shown only when there is a scheduled
  cancellation to undo.
- **Grace** shows the billing problem, the deadline from the canonical
  lifecycle, and the real recovery action — never a link to legacy Ultimate SMS
  billing.
- **Ended** (`canceled` / `incomplete_expired`) replaces the plan-change and
  cancel controls entirely with **"Start subscription again"** (§10.4). Showing
  upgrade/downgrade to a customer with no live provider subscription produced a
  form that could only ever fail.

**Owner or active Admin only, never Staff.** A failure is 404, so account
existence is not disclosed.

## 13.2 Payment-method recovery (§4)

An existing subscriber fixes or replaces their card through **Stripe's hosted
Billing Portal**: `POST /v1/billing_portal/sessions` with `customer` and
`return_url`, using the documented `flow_data.type = payment_method_update`
deep link — Stripe's own description is "Customer will be able to add a new
payment method. The payment method will be set as the customer's
`invoice_settings.default_payment_method`."

This was chosen over building our own card form because **card details must
never reach this application**. The gateway method takes no card-shaped
argument and returns only a URL; there is no field anywhere in this lane that
could accept a PAN. The route is reachable from Plan & subscription and from
the Grace billing warning.

**A LOCKED ACCOUNT CAN REACH IT TOO.** This is the point at which a lock is
supposed to end, so gating it made the locked screen's own promise —
"completing payment restores access right away" — unkeepable: the screen sent
the customer to Plan & subscription, and the one action there that could
complete a payment bounced them straight back. `customer.workspaces.plan
.payment-method` is therefore in `CustomerAccountAccessGate`'s exact
route-name allowlist, alongside the locked screen, Plan & subscription and the
two re-subscribe routes (§10.4).

That is the whole locked-account recovery set. `plan.change`, `plan.cancel` and
`plan.resume` are deliberately **not** allowlisted: they are billing mutations,
not recovery. The allowlist is exact names, never a prefix, and grants no
authority of its own — it only lets the request reach the authorization
boundary already in `PlanSubscriptionController` (owner or active Admin with
account-frame authority; 404 for Staff and for strangers).

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
