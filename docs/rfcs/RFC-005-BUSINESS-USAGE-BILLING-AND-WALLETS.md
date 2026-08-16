# RFC-005 — Business Usage Billing and Wallets

**Status: DRAFT — NOT IMPLEMENTATION-AUTHORIZED**
**Version: 1.0**

- Base SHA: `6ae00f8f88b1963c6d05a045f99f0ce42651d2eb` (`main`)
- Governing contract: `docs/automation/RFC-005-DESIGN-CONTRACT.md`, merged commit `186a82393577e9afc240d40b0ad8ade4c99d27d4`
- Merging this design document does **not** authorize RFC-005 Milestone 1 or any implementation, migration, test, route, view, Stripe/provider call, or billing behavior. Every milestone in §36 requires its own separately drafted, human-reviewed, merged implementation contract before any such work may begin — identical to the discipline RFC-004 followed before its own Milestone 1, and identical to the discipline the merged RFC-005 design contract itself already locked in its §1.
- This document is written to be implementation-grade: each future milestone contract is expected to **reproduce** the relevant sections below, not redesign them, exactly matching RFC-004's own standard (design-contract §5 preamble).

---

## 1. Purpose and problem statement

RFC-003 forward-declared, and RFC-004 explicitly deferred, a Business-scoped usage wallet, usage ledger, billing configuration, and monthly usage budget (RFC-003 §8), together with payer selection, auto-recharge, and Stripe usage-billing changes (RFC-003 §26.2; RFC-004 §19, §31). RFC-004 shipped the entitlement structure — plan tiers, Business feature toggles, complimentary status, additional-Business-slot allocation — and reserved exactly one seam for this RFC: `UsageAuthorizationGateway::check(Business $business, PlatformFeature $feature): UsageAuthorizationResult`, called as the final step of `EntitlementManager::decide()`, currently bound to `NullUsageAuthorizationGateway` (always-authorized). RFC-005 designs the system that will eventually bind a real implementation to that seam: a Business-scoped wallet and append-only ledger, a versioned usage-rate catalog, reservation/commit/release for uncertain-cost metered operations, payer selection with Workspace fallback, a billing contact, payment instruments, manual/paid/promotional credits, auto-recharge, additional-Business-slot payment collection, and the Stripe provider boundary — without ever weakening RFC-003/RFC-004's already-locked tenancy, entitlement, or isolation guarantees.

The problem this RFC solves is narrower than "billing" in general: the legacy SMS-plan/subscription billing stack (four gateways, a 12,887-line controller, `string`-typed money columns) already exists and is explicitly out of scope; RFC-005 is a **new, Business-scoped, Stripe-first, usage-metered** billing system that must coexist with, and never be confused with, that legacy stack.

---

## 2. Governing RFC/contract evidence

This design is bound by, in descending specificity:

1. `docs/automation/RFC-005-DESIGN-CONTRACT.md` (commit `186a823...`) — sixteen inherited locked decisions (its §4), human product requirements (its own top-level section), mandatory A–L contents (its §5), the open-decision/gap rule (its §6), and its governance restrictions (its §1, §7, and closing summary). Every one of those is treated as binding on this document; §7 below (Locked product decisions) restates each with its exact section-cross-reference.
2. RFC-004 (Plans and Business Feature Entitlements) — tag `rfc-004-plans-and-business-feature-entitlements` at `221e18f0...` — specifically §13/§17/§18/§19/§20/§21/§31.
3. RFC-003 (Workspace and Business Account Core) — specifically §4, §8, §14.1, §19, §26.2, §27.
4. `AGENTS.md` — "Workspace authorization" (Staff/Admin/owner/direct-owner/platform-admin as separate authorization paths) and verification rules (only `ultimatesms_testing`, focused-before-broad tests, exact evidence reporting).

`docs/automation/AI-AUTONOMY-STATE.json` was read in full during preflight for this document (still `active_rfc: "RFC-003"`, `active_milestone: "Milestone 4"`, `expected_head_sha: db7829d2c33ff3eb77a5bd42820eb39e349f6d94`) — confirmed unchanged, confirmed stale/historical, confirmed to carry no authorization weight, and left untouched by this document.

---

## 3. Repository audit findings (re-verified at base `6ae00f8f...`)

Re-verification at this exact base found the working tree between the RFC-004 tag (`221e18f0...`) and this base differs by **exactly one file** — the merged design contract itself (`git diff --stat 221e18f0..6ae00f8f` shows only `docs/automation/RFC-005-DESIGN-CONTRACT.md`, 242 insertions, file created). Every repository fact the design contract already recorded is therefore still accurate at this base without re-derivation; this section adds only what is new for drafting the RFC itself.

- **`stripe/stripe-php` is pinned `^7.76` in `composer.json`, and the actually installed/locked version is `v7.128.0`** (`composer.lock`, package `stripe/stripe-php`; `vendor/stripe/stripe-php/VERSION` reads `7.128.0`). Both are within the same `^7.76` major-version constraint (SDK major version 7, PHP SDK `^7.x` corresponds to Stripe API version pinning via `Stripe::setApiVersion()` / the account's dashboard-configured default, not the SDK major version itself). §20 resolves the retain-vs-upgrade recommendation.
- **A real, working Stripe Checkout Session pattern already exists in this repository**, in five legacy `Eloquent*Repository` classes (`EloquentAccountRepository`, `EloquentKeywordRepository`, `EloquentPhoneNumberRepository`, `EloquentSenderIDRepository`, `EloquentSubscriptionRepository`) and in `Customer/PaymentController.php`: `use Stripe\Stripe; use Stripe\TaxRate;` at the repository layer, `\Stripe\Checkout\Session::create([...])` to start a purchase, and — on the browser's return to a `/checkout/order-status`-style success route — `new StripeClient($secret_key)` then `$stripe->checkout->sessions->retrieve($session_id)` to **synchronously confirm the session on the success redirect**, not via an asynchronous webhook. This is real, working precedent for Checkout Session usage in this codebase.
- **No Stripe webhook signature-verification precedent exists anywhere in this repository.** A targeted search for `constructEvent`/`Webhook::` across `app/` returns zero matches. `config/services.php` already declares `stripe.webhook.secret` (`env('STRIPE_WEBHOOK_SECRET')`) and `stripe.webhook.tolerance` (`env('STRIPE_WEBHOOK_TOLERANCE', 300)`) — configuration exists, but no controller, route, or job ever consumes it. `app/Http/Middleware/VerifyCsrfToken.php`'s `$except` array exempts `/payment/*` and ten other gateways' `/callback/{gateway}/*` paths from CSRF — **Stripe has no entry in that list**, because the legacy flow never receives an inbound Stripe callback at all; it only performs the outbound `sessions->retrieve()` call described above. **RFC-005's own webhook endpoint and its CSRF-exemption entry are therefore new, not reused** (§21).
- **`businesses.currency_code` already exists** (`char(3)`, not-nullable, no default listed in `create_businesses_table`), independent of the `currencies` table's own `id`/`code` pair used by `workspace_plan_catalog.currency_id`. `businesses.currency_code` is a raw 3-letter code with no foreign key to `currencies`. This is the natural candidate for a Business wallet's authoritative currency (§10), but the mismatch (raw code vs. FK'd `currencies.id`) must be resolved explicitly, not silently assumed compatible.
- **`workspace_plan_catalog.price` — RFC-004's own, most recent, same-domain money precedent — is `decimal('price', 16, 2)` (not integer minor units, not a raw string), paired with a nullable `currency_id` FK `restrictOnDelete()` to `currencies`.** `additional_business_slot_price_ratio` is `decimal(6, 4)`. This is direct, current-era evidence this repository uses exact-decimal (not float, not minor-unit-integer) money columns for its one existing real-money field. §10 evaluates this precedent directly against RFC-005's own, different requirements (metered, sub-cent-capable pricing) rather than assuming it must be copied unchanged.
- **The `currencies` table carries no decimal-places / zero-decimal-currency metadata** (`id`, `uid`, `user_id`, `name`, `code`, `format` (a display-format string, not a scale), `status`). Any design that needs to convert an exact-decimal or integer-minor-unit amount into a specific currency's correct number of decimal places has no existing source of truth for that scale. §10 records this as an explicit gap, not something to infer from `format`.
- **`workspaces.owner_user_id` and `businesses.customer_id` both use `restrictOnDelete()`; `workspace_plan_assignments.complimentary_granted_by_user_id` is a plain `unsignedBigInteger` with no foreign key**, explicitly documented in its own migration as "mirroring `workspace_transitions.actor_user_id` — must never block a legitimate user-deletion feature." This is the established convention this RFC follows for every *actor* column (never FK'd, always scalar) versus every *tenancy* column (Business/Workspace: always FK'd, `restrictOnDelete()`).
- **Route-naming convention confirmed**: `routes/customer.php`'s `workspaces` group uses `Route::prefix('workspaces')->name('workspaces.')->group(...)`, with nested resource segments named `businesses.store`, `businesses.reassign`, `businesses.features.disable`, etc., every mutating route a `POST`, every route scoped by `{workspaceUid}`/`{businessUid}` (never a raw numeric ID). §30 follows this exact convention for every new customer-facing route.
- **Authorization primitives confirmed**: `WorkspaceMembership` casts `role` to `WorkspaceMembershipRole` (admin/staff) and `business_access_scope` to `WorkspaceBusinessAccessScope` (all/selected), with a separate `is_active` boolean — three independent axes, exactly as `AGENTS.md`'s "Workspace authorization" section requires repository-wide. §24's authorization matrix is built directly on these three axes plus platform-administrator and direct-Business-owner/customer, per `AGENTS.md`.
- **`config/permissions.php` already carries `Workspace` (RFC-003 M5), `Workspace Plans` (RFC-004 M3), and legacy `Subscriptions`/`Payment Gateways`/`view invoices` categories** — none collide by name with a prospective `Business Usage Billing` category, confirmed by direct read.
- **A real, working two-process concurrency test pattern exists**: `EntitlementManagerConcurrencyTest.php` deliberately avoids `RefreshDatabase` (an open transaction would hide a genuinely separate process's committed rows), inserts fixture rows directly (auto-committed), spawns independent OS processes via `Symfony\Component\Process\Process`/`PhpExecutableFinder` against a small dedicated runner script (`Support/concurrent_business_slot_runner.php`), and explicitly tears down every created row itself, including snapshotting/restoring shared catalog state its own scenarios mutate. §35 reuses this exact pattern rather than inventing a new one.
- **`EntitlementManager::decide()`'s exact algorithm was re-read directly at this base**: feature-known → feature-available → Business-in-Workspace consistency (throws, does not return a decision, on mismatch) → Workspace-plan-assigned → Workspace-entitled-by-plan-or-override → not Business-toggled-off → plan-not-suspended → plan-not-inactive → `usageAuthorizationGateway->check()` as the unconditional final step whose `false` result becomes the returned decision's `false`/reason. This exact sequence, and exactly this call signature, is confirmed unchanged from RFC-004's own §14/§19 description quoted in the design contract. RFC-005 integrates by replacing only the bound gateway class (§20/§14 below); zero lines of `EntitlementManager::decide()` change.
- **`changePlan()`, `changePlanStatus()`, `setAdditionalBusinessSlots()`, and `grantComplimentaryStatus()` all follow one identical shape**, confirmed by direct read: `DB::transaction()` wrapping a `findForUpdate()` row lock on the Workspace, an `assertPlatformAdministrator()` (or, for `changePlanStatus`, a mandatory non-empty `$reason` check) authority gate, a repository `update()`, a `transitionRepository->create()` audit-trail row, and an event dispatch. **The events themselves (`WorkspacePlanChanged`, `WorkspacePlanStatusChanged`, `WorkspaceAdditionalBusinessSlotsChanged`, `WorkspaceComplimentaryStatusChanged`, `WorkspaceEntitlementOverrideChanged`, `BusinessFeatureToggleChanged`, `WorkspacePlanAssigned`) all `implements ShouldDispatchAfterCommit`** — the correct name for RFC-004/RFC-003's after-commit event convention (not `ShouldQueueAfterCommit`, which is the distinct *job* interface used by `BuildInitialBusinessSnapshot`/`RunBusinessAdvisorOpportunityProducer`/`ExecuteOpportunityAction`). §31 uses both interfaces correctly, by name, for the correct kind of class.
- **Database engine**: `config/database.php` defaults `DB_CONNECTION` to `mysql`. The universal `findForUpdate()`/row-locking pattern already used throughout RFC-003/RFC-004 requires a transactional storage engine (InnoDB, MySQL's default and the only engine Laravel's `lockForUpdate()` supports meaningfully) — this RFC assumes InnoDB throughout and does not re-verify the deployed MySQL major version; §25 flags `CHECK` constraint support (MySQL 8.0+, enforced rather than merely parsed) as an item for the M1 contract to confirm against the actual deployment target before relying on it, exactly the caution RFC-004 M2 already exercised for its own locking-read invariants.

No conflict was found between the merged design contract, RFC-003, RFC-004, and repository reality at this base. If a future milestone's own re-verification finds one, that milestone's contract stops and reports it rather than silently resolving it here (design-contract §6).

---

## 4. Goals

1. Give every Business an isolated usage wallet, append-only ledger, billing contact, and payer — never Workspace-pooled, never cross-Business-visible (RFC-003 §8, §26.2).
2. Meter only explicitly classified features; every non-metered, already-entitled feature continues working with an empty or non-existent wallet (design-contract §4 item 13, product direction 10).
3. Give the Business, and the platform, independent, non-collapsible controls over spend: a monthly Business spend cap, per-feature limits, and a platform safety ceiling that a customer can tighten but never loosen past (Human product requirements 2–4).
4. Make Stripe the sole v1 payment provider, behind a narrow boundary that never spreads Stripe-specific code through managers or controllers, so a second provider could be added later without redesigning the ledger (product direction 3).
5. Never let this system reuse the legacy multi-gateway `PaymentController`/`invoices`/`subscription_transactions` stack by inference from a similar name (design-contract §4 item 15).
6. Preserve a seam for Agency client rebilling without building it now (design-contract §4 item 4; product direction 5).
7. Never invent a customer retail rate, a default cap value, or provider behavior; every commercially significant figure this RFC does not receive as an explicit human product requirement is named as an open decision in §39, not silently defaulted (design-contract §6).

---

## 5. Non-goals and explicit deferrals

Deferred out of RFC-005 v1 entirely, per RFC-003 §26.2 and RFC-004 §31 (both already cross-checked consistent by the design contract):

- Agency client rebilling execution (schema/service seam only — §16).
- Any second payment provider besides Stripe (§20).
- Any change to the legacy `Plan`/`Subscription` SMS-quota billing stack, `PaymentController.php`, `PaymentMethods`, `Invoices`, or `SubscriptionTransaction` (design-contract §4 item 15; §23).
- Automated tax/VAT calculation, unless the M3 contract explicitly adopts Stripe Tax as its own bounded slice (§23 marks this NON-IMPLEMENTATION-READY pending a human decision, §39 item 6).
- Multi-currency wallets in v1 — v1 targets a single settlement currency; see §39 item 10 for the exact open decision this creates.
- A concrete v1 add-on roster beyond the schema seam and one worked example (§18; §39 item 8).
- Selecting exact retail rates, exact default caps, or an exact auto-recharge threshold value (§39).

---

## 6. Terminology

- **Business wallet** — the single row per Business holding `available_balance`, `reserved_balance`, and the Business's caps/auto-recharge configuration (§12).
- **Usage account** — informal synonym for a Business's wallet + ledger + rate history taken together; not a separate table.
- **Available balance** — spendable balance: total balance minus everything currently reserved.
- **Posted balance** — the sum of all ledger entries' balance effects to date (what the wallet "really" has, including reserved amounts) — equal to `available_balance + reserved_balance` by construction (§12).
- **Reserved balance** — the sum of open (uncommitted, unreleased, unexpired) reservations; not spendable until released back to available or committed into a final charge.
- **Payer** — which of Business/Workspace/(later) Agency-rebill funds a Business's wallet (§16).
- **Funding source** — the payer's selected payment instrument for a given charge attempt.
- **Payment instrument** — a stored Stripe PaymentMethod reference (token only), owned by either a Business or a Workspace (§17).
- **Top-up** — a manually initiated, customer-paid wallet credit.
- **Recharge** — an automatically initiated, threshold-triggered wallet credit (auto-recharge, §19).
- **Charge** — the final, committed ledger debit for a completed metered operation.
- **Reservation** — a provisional hold against available balance for an operation whose exact cost is not yet known (§13).
- **Capture / commit** — turning a reservation into a final charge once the actual cost is known.
- **Release** — returning a reservation's held amount to available balance without a charge (denial, cancellation, or expiry).
- **Refund / reversal** — an explicit, auditable correction to a previously posted entry; never an edit or delete of that entry (§12).
- **Adjustment** — an admin- or system-issued correction entry with a mandatory actor and reason (§18).
- **Metered feature** — a `PlatformFeature` explicitly classified as cost-bearing (§14); every other feature is non-metered and never wallet-gated.
- **Additional-slot checkout** — the payment flow for a paid Core/Growth additional Business slot (RFC-004 §17/§19; §22 below) — a one-time/recurring **allocation** purchase, never a per-use metered charge.

---

## 7. Locked product decisions (inherited — this RFC may only refine, never reopen)

Every item below is one of the design contract's sixteen inherited decisions (its §4), restated here with the exact section of this RFC that implements it. This RFC does not reopen, narrow, or omit any of them.

| # | Locked decision (design-contract §4 item) | Implemented in |
|---|---|---|
| 1 | Workspace remains the universal tenant container; Agency is a plan tier, not a separate tenant model | §9, §16 |
| 2 | Usage wallets/ledger are Business-scoped, not Workspace- or user-scoped | §12 |
| 3 | Default payer: Core/Growth → Workspace pays; Agency → Business/client pays | §16 |
| 4 | Payer concepts: Business pays; Workspace pays; later Agency-rebill | §16 |
| 5 | Payer change affects future charges only — never deletes credits, moves credits, resets a wallet, or rewrites history | §16, §32 |
| 6 | The usage ledger is append-only | §12 |
| 7 | Auto-recharge defaults inherited from RFC-003 §26.2 (threshold below $10, default recharge $10, both editable, disable-able) | §19 |
| 8(a) | Business's own monthly usage/spend budget/cap, distinct from 8(b) | §15 |
| 8(b) | Monthly auto-recharge cap, distinct from 8(a) | §15, §19 |
| 9 | RFC-005 owns payment collection for paid additional-Business-slot allocation; RFC-004's `setAdditionalBusinessSlots()`/`changePlan()` remain the sole allocation mutation | §22 |
| 10 | Complimentary Workspace status waives recurring/slot charges, not metered usage, unless explicitly extended | §14, §39 item 5 |
| 11 | Grandfathered complimentary slots never become debt | §22 |
| 12 | Billing state never deactivates a Workspace or hides tenancy; it blocks paid execution only | §12, §14, §24 |
| 13 | `PlatformFeatureRegistry::isAvailable()` remains a floor no override/billing state bypasses | §14 |
| 14 | Integration is exclusively through `UsageAuthorizationGateway`, without redesigning `EntitlementManager` | §14 |
| 15 | The legacy `Plan`/`Subscription` stack remains untouched absent direct, explicit proof of a safe integration | §5, §23 |
| 16 | RFC-003 §8's Business-scoped usage wallet/ledger/billing-configuration/monthly-usage-budget, distinct from 8(b)'s auto-recharge cap | §12, §15 |

---

## 8. Human product requirements (supplied for RFC-005, not repository facts)

Restated from the design contract's own "Human product requirements supplied for RFC-005" section, with implementation location:

1. Every Business has its own billing contact and billing configuration → §17.
2. Every Business has an adjustable monthly usage budget/cap → §15.
3. Authorized users can configure per-feature usage limits, not only one aggregate cap → §15.
4. A platform safety limit always overrides a customer-configured higher limit → §15.
5. Credits can be added to a specific Business without pooling another's balance → §18, §32.
6. Discrete paid add-ons (e.g. a purchasable audit) must be designed or explicitly deferred with justification, never silently dropped → §18.
7. Owner's unit-economics target: ~$10–$15/Business/month internal AI/API provider spend, **internal cost-control only**, never automatically the customer's retail price, never an unexplained hard suspension threshold → §11, §34.
8. Owner/operator complimentary Agency Workspace's *metered usage* subsidy is a separate, undecided question from the already-locked recurring/slot-charge waiver → §39 item 5.

---

## 9. Recommended architecture

Three new manager classes, mirroring `EntitlementManager`'s own shape (one manager per bounded authority, no controller-owned business rule, `DB::transaction()` + row lock + audit-trail + after-commit event on every mutation):

- **`App\Library\Usage\UsageWalletManager`** — sole write authority for wallets, ledger entries, reservations, the rate catalog, the monthly Business cap, and per-feature limits. Implements the real `UsageAuthorizationGateway` binding (§14) by delegating to its own read methods — the gateway class itself stays a thin adapter, exactly as `NullUsageAuthorizationGateway` already is.
- **`App\Library\Usage\BillingProfileManager`** — sole write authority for billing contact, payer assignment/transitions, and payment-instrument attach/detach records (never the instrument's actual token value beyond what Stripe returns — §33).
- **`App\Library\Usage\UsageBillingCheckoutManager`** — orchestrates provider-facing flows that must call out to Stripe *outside* any database transaction: manual top-up, auto-recharge initiation, additional-slot purchase, add-on purchase. Never performs the actual ledger/allocation mutation itself — it calls into `UsageWalletManager`/`EntitlementManager` only after a provider call has already succeeded and been durably recorded (§13, §22).

A fourth, narrow interface — **`App\Library\Usage\Contracts\PaymentProviderGateway`** — is the entire Stripe boundary (§20): `createSetupIntent()`, `attachPaymentMethod()`, `createTopUpCheckout()`/`createTopUpPaymentIntent()`, `chargeOffSession()`, `retrieveEvent()`/`verifyWebhookSignature()`. `App\Library\Usage\StripePaymentProviderGateway` is the only implementation in v1; no manager or controller ever references the `Stripe\*` namespace directly — only this one adapter class does, exactly mirroring how `UsageAuthorizationGateway` already isolates RFC-004's own read from any future implementation detail.

Repository-per-table, exactly RFC-004's convention: one contract + one Eloquent implementation per new table listed in §25, bound in `AppServiceProvider` the same way the six RFC-004 M1 repositories already are.

---

## 10. Money representation and currency rules

**Recommendation: signed 64-bit integer "micro-units"** (1 unit = 1⁄1,000,000 of the currency's major unit), stored in `BIGINT` columns, for every money field in every new RFC-005 table — rates, provider costs, reservation/ledger amounts, caps, and balances alike. PHP `float` is forbidden for any persisted or in-flight authoritative money value, matching the design contract's explicit requirement.

**Why integer micro-units rather than following `workspace_plan_catalog.price`'s own `decimal(16,2)` precedent directly (§3 above):** `workspace_plan_catalog.price` only ever needed two decimal places because it prices a flat recurring plan or a whole additional Business slot — never a fractional-cent, per-call metered cost. RFC-005 introduces genuinely new metered operations whose real provider cost (an LLM completion, an SMS-classification call) can be a small fraction of one cent; a `decimal(16,2)`-shaped column cannot represent that without silently rounding every sub-cent cost to zero or to one cent, corrupting the very cost data §34 requires the platform to observe accurately. A wider, purely integer representation avoids float entirely (satisfying the same "no float" requirement `decimal` was chosen for elsewhere), gives exact, lossless arithmetic for every add/subtract the ledger performs (no rounding-mode ambiguity accumulating across thousands of entries), and converts to Stripe's own integer-minor-unit API (`amount` in cents) with one fixed, explicit, tested division — `micro_units ÷ (1_000_000 ÷ 10^decimal_places)` — rather than a `decimal`-to-integer-cents conversion needed on every single outbound Stripe call. This is a **recommended new design decision** (category 3, design-contract §6) — it deliberately departs from `workspace_plan_catalog`'s own column shape, for a reason specific to what RFC-005 newly requires; it does not imply that precedent was wrong for its own, non-metered purpose.

**Currency scale gap, and how this RFC resolves it for v1 only:** the `currencies` table carries no decimal-places/zero-decimal metadata (§3). Rather than unilaterally adding a column to the legacy `currencies` table (a shared table this RFC does not own), **v1 scopes every Business wallet to exactly one settlement currency, and that currency's decimal-places value is a fixed constant defined in this RFC's own new code, not read from `currencies`.** §39 item 10 names the exact currency choice as an open decision (recommendation: USD, `decimal_places = 2`, matching the majority of this platform's existing Stripe-adjacent legacy flows and avoiding any zero-decimal-currency edge case in v1). A Business whose `businesses.currency_code` does not match the single supported settlement currency has its wallet feature gated off entirely in v1 (never silently activated in the wrong currency) — this is itself part of §39 item 10's open decision, not something this RFC resolves unilaterally.

**Rules:**

- Balances and reservations are never negative. A ledger entry that would take `available_balance` below zero is rejected by `UsageWalletManager` before it is written (§13).
- Rounding: every rate carries an explicit `rounding_rule` (`RoundHalfUp` for v1's only defined rule; the enum is extensible). Rounding is applied once, at reservation/commit time, to the computed micro-unit charge — never re-applied on read.
- Retail debit (`retail_rate_micro`) and internal provider cost (`provider_cost_micro`) are separate fields on every rate row and every ledger/reservation entry that references one — never derived from one another, never stored in the same column (§11).
- Refunds/reversals are new, signed ledger entries referencing the entry they correct (`reversed_entry_id`) — never an edit of the original.
- Reconciliation: `available_balance_micro + reserved_balance_micro` on the wallet row must always equal the signed sum of every ledger entry's `balance_effect_micro` for that Business. `UsageWalletManager` maintains the wallet row as a cached, always-consistent aggregate (updated in the same transaction as the ledger insert that changes it, same row-lock, same commit) rather than computing it from a `SUM()` on every read — a scheduled reconciliation job (§31) independently recomputes and alerts on drift, but never auto-corrects it (a drift is a bug to fix, not a value to silently overwrite).

---

## 11. Rate catalog, customer charges, provider costs, and rate snapshots

**`business_usage_rates`** (versioned, append-only-by-convention — a new version row is always inserted, an old version's `effective_to` is set, never its other fields edited):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `feature_key` | string(64) | `PlatformFeature`-backed value |
| `version` | unsigned int | starts at 1 per `feature_key` |
| `retail_rate_micro` | bigint | customer-facing price per unit |
| `provider_cost_micro` | bigint | internal estimated provider cost per unit — never exposed to any customer surface (§34) |
| `unit_label` | string(64) | e.g. `"per message"`, `"per 1,000 tokens"` |
| `rounding_rule` | string(32), enum-backed | `round_half_up` only, for v1 |
| `currency_id` | FK `currencies`, `restrictOnDelete()` | must match the wallet's single v1 settlement currency (§10) |
| `effective_from` | timestamp | |
| `effective_to` | timestamp, nullable | null = currently active |
| `created_by_user_id` | unsigned bigint, no FK | actor column convention (§3) |
| `created_at` | timestamp | no `updated_at` — rows are never updated, only superseded |

Unique constraint: `(feature_key, version)`. "At most one active (`effective_to IS NULL`) row per `feature_key`" is a **manager-enforced invariant**, not a DB constraint — MySQL has no clean native way to express a partial-unique-on-null condition, so `UsageWalletManager::setActiveRate()` acquires a locking read (`SELECT ... FOR UPDATE`) on the current active row for that `feature_key` before inserting the new version and closing the old one's `effective_to`, in the same transaction — the identical discipline RFC-004 M2 already established for `workspace_plan_catalog` pricing serialization.

**Snapshotting:** every reservation and every final `UsageCharge`/`FinalAdjustment` ledger entry stores its own copies of `rate_id` (FK, traceability only), `rate_version`, `retail_rate_micro`, `provider_cost_micro`, `unit_label`, `rounding_rule`, `currency_id`, and `quantity` — denormalized, not merely referenced — so that entry remains fully interpretable even if `business_usage_rates` is later queried at a different point in time. A later change to the active rate never recalculates any historical entry.

**Cost/margin visibility:** `provider_cost_micro` (on both the rate catalog and every ledger/reservation entry) is readable only by the platform-administrator authorization path (§24) — no customer-facing controller, view, JSON response, or export includes it, enforced the same way RFC-004 §20 already enforces "no raw entitlement-table query outside the named authorities" (a boundary test, §35).

---

## 12. Business wallet and append-only ledger invariants

**`business_usage_wallets`** — one row per Business:

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `business_id` | FK `businesses`, unique, `restrictOnDelete()` | 1:1, Business-scoped (locked decision 2) |
| `currency_id` | FK `currencies`, `restrictOnDelete()` | the wallet's single settlement currency (§10) |
| `available_balance_micro` | bigint, default 0 | never negative |
| `reserved_balance_micro` | bigint, default 0 | never negative |
| `monthly_spend_cap_micro` | bigint, nullable | Business's own cap (§15); null = platform-safety-limit-bounded only |
| `monthly_spend_period_start` | date | resets per §15's timezone rule |
| `auto_recharge_enabled` | boolean, default false | |
| `auto_recharge_threshold_micro` | bigint, nullable | required if enabled (§19) |
| `auto_recharge_amount_micro` | bigint, nullable | required if enabled |
| `monthly_recharge_cap_micro` | bigint, nullable | distinct from `monthly_spend_cap_micro` (locked decision 8) |
| `monthly_recharge_period_start` | date | |
| `low_balance_notified_at` | timestamp, nullable | dedup window (§19) |
| `billing_status` | string(16), enum-backed, default `active` | `active` \| `suspended` — the "billing state" gate §14/§15 evaluate; see below |
| `billing_status_reason` | text, nullable | required whenever `billing_status = 'suspended'` |
| `billing_status_updated_by_user_id` | unsigned bigint, nullable, no FK | null = system-set (dispute webhook, below) |
| `created_at` / `updated_at` | timestamp | this is the one RFC-005 table that is legitimately mutable — it is a cached aggregate + configuration row, not itself the historical record (the ledger is) |

**`billing_status` — the concrete mechanism behind the "billing state" gate §14/§15 reference, and behind locked decision 12's inherited RFC-004 §18 posture:** `active → suspended` is set automatically, system-authored, the moment a `DisputeChargeback` ledger entry is posted (§23) — pending admin review, never requiring a human in the loop before the gate itself takes effect. `active → suspended` may also be set explicitly by a platform administrator (mandatory reason) for any other non-payment enforcement need. `suspended → active` is **admin-only, mandatory reason, never automatic** — a dispute/chargeback is never auto-cleared by a later successful payment alone. A `suspended` wallet denies every reservation attempt (`billing_suspended`, evaluated by `RealUsageAuthorizationGateway::check()` before any cap/limit/balance check, §14) but never blocks balance/ledger reads, never deactivates the Business or Workspace, and never blocks a non-metered feature — exactly locked decision 12's inherited boundary. `billing_status` is **entirely distinct from, and never conflated with,** `workspace_plan_assignments.status`'s own `suspended`/`inactive` values (RFC-004 §18) — a Business's `billing_status` never changes Workspace-level plan entitlement, and a Workspace-level plan suspension is unaffected by any Business's own `billing_status`. Auto-recharge's own repeated-failure consequence (§19) is deliberately **not** wired to `billing_status` — it only disables `auto_recharge_enabled`, a softer, distinct consequence that leaves manual top-up and existing balance fully usable, never escalating to a full `billing_status` suspension on its own.

Sole write authority: `UsageWalletManager`. No controller ever writes to this table directly (mirrors RFC-004 §20's absolute rule).

**`business_usage_ledger_entries`** — append-only, **never** updated or deleted after insert (no `updated_at` column at all, deliberately, so the schema itself cannot silently support an edit):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `business_id` | FK `businesses`, `restrictOnDelete()` | |
| `wallet_id` | FK `business_usage_wallets`, `restrictOnDelete()` | |
| `entry_type` | string(32), enum-backed | see table below |
| `balance_effect` | string(16), enum-backed | `available_credit` \| `available_debit` \| `reserved_increase` \| `reserved_decrease` \| `none` |
| `amount_micro` | bigint, unsigned | magnitude only; sign/direction comes from `balance_effect` |
| `currency_id` | FK `currencies`, `restrictOnDelete()` | |
| `feature_key` | string(64), nullable | usage-related entries only |
| `quantity` | decimal(14,6), nullable | metered quantity, when applicable |
| `rate_id` / `rate_version` / `retail_rate_micro` / `provider_cost_micro` / `unit_label` / `rounding_rule` | see §11 | nullable; populated for `Reservation`/`UsageCharge`/`FinalAdjustment` only |
| `reservation_id` | FK `business_usage_reservations`, nullable | |
| `correlation_key` | string(191), unique | idempotency (§31) |
| `provider_reference` | string(191), nullable | Stripe object id, when applicable |
| `actor_user_id` | unsigned bigint, nullable, no FK | null = system-generated |
| `reason` | text, nullable | mandatory (enforced by the manager, not the DB) for `ManualCredit`, `FinalAdjustment`, `CorrectionReversal` |
| `reversed_entry_id` | self-referencing FK, nullable, `restrictOnDelete()` | set only on `CorrectionReversal` rows |
| `created_at` | timestamp | immutable; the only timestamp this table has |

Entry types and their `balance_effect`:

| `entry_type` | `balance_effect` | When |
|---|---|---|
| `PaidTopUp` | `available_credit` | customer-initiated top-up succeeds |
| `AutoRecharge` | `available_credit` | auto-recharge succeeds |
| `ManualCredit` | `available_credit` | admin/customer adds credit (§18) |
| `PromotionalCredit` | `available_credit` | promotional/complimentary credit (§18, §39 item 5) |
| `Reservation` | `reserved_increase` | reserve step (§13) |
| `ReservationRelease` | `reserved_decrease` | release/expiry, no charge |
| `UsageCharge` | `reserved_decrease` | commit step — permanently removes the committed portion from reserved |
| `FinalAdjustment` | `available_debit` or `available_credit` (signed by context) | reserved-vs-actual variance true-up, or an admin correction |
| `Refund` | `available_debit` | provider-initiated or admin-initiated refund of prior credit |
| `DisputeChargeback` | `available_debit` | provider claws back funds; also sets the wallet's `billing_status = 'suspended'` (§12) pending admin review |
| `CorrectionReversal` | opposite of the entry it reverses | the only entry type whose sole purpose is undoing another entry's effect, always via `reversed_entry_id` |

`available_balance_micro + reserved_balance_micro` on the wallet row is maintained as an always-consistent cached aggregate of this table (§10). No cross-Business or cross-currency transfer is ever expressible: every ledger entry is scoped to exactly one `business_id` and one `currency_id`, and no manager method accepts two different Businesses' wallets in the same mutation.

---

## 13. Reservation/commit/release/reversal state machines

Reservations are represented as their **own table**, not solely as ledger entries — because a reservation's *state* (pending/committed/released/expired) genuinely changes over its short lifetime, which an immutable ledger entry cannot represent — but every state transition is *also* recorded as its own immutable `business_usage_ledger_entries` row (`Reservation` on create, `ReservationRelease` or `UsageCharge` on terminal transition), so the full history remains fully auditable from the ledger alone even though the reservation row itself is mutable.

**`business_usage_reservations`:**

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `business_id` | FK `businesses`, `restrictOnDelete()` | |
| `wallet_id` | FK `business_usage_wallets`, `restrictOnDelete()` | |
| `feature_key` | string(64) | |
| `status` | string(16), enum-backed | `pending` \| `committed` \| `released` \| `expired` |
| `reserved_amount_micro` | bigint | the held amount |
| `estimated_quantity` | decimal(14,6), nullable | |
| `rate_id` / `rate_version` / `retail_rate_micro` / `provider_cost_micro` / `rounding_rule` | see §11 | snapshot at reservation time |
| `idempotency_key` | string(191), unique | caller-supplied, §31 |
| `correlation_key` | string(191) | ties `Reservation`/`ReservationRelease`/`UsageCharge` ledger rows together |
| `reserved_at` | timestamp | |
| `expires_at` | timestamp | operation-defined TTL; expiry reconciliation releases past this |
| `committed_at` / `released_at` | timestamp, nullable | exactly one is set on a terminal row |
| `final_quantity` / `final_amount_micro` | nullable | set only on commit |

Once `status` is `committed`, `released`, or `expired`, the row is never reopened — `UsageWalletManager` enforces this the same way `EntitlementManager` enforces its own one-directional transitions.

**Algorithms** (each states authority, transaction boundary, lock order, idempotency key, and result):

- **Reserve** — Authority: `UsageWalletManager::reserve()`. Transaction: single `DB::transaction()`. Lock order: wallet row `findForUpdate()` (Business/wallet only — no Workspace lock needed, since a reservation never touches `workspace_plan_assignments`). Idempotency: caller-supplied `idempotency_key`; a repeat call with the same key and same estimated quantity returns the existing `pending` reservation rather than creating a second one. Steps: look up the active rate for `feature_key` → compute `reserved_amount_micro` from `estimated_quantity` → evaluate §15's caps (spend cap, per-feature limit, safety limit) against `available_balance_micro - reserved_amount_micro`, denying with a stable reason if any would go negative or any cap would be exceeded → insert the reservation row (`pending`) → insert the `Reservation` ledger entry (`reserved_increase`) → decrement `available_balance_micro`, increment `reserved_balance_micro` on the wallet row, same transaction. Result: the reservation id, or a stable denial reason (`billing_suspended`, `insufficient_available_balance`, `monthly_spend_cap_exceeded`, `feature_limit_exceeded`, `platform_safety_limit_exceeded`) — `billing_suspended` is checked first, mirroring §14's evaluation order, before any cap or balance check runs.
- **Commit/finalize** — Authority: `UsageWalletManager::commit()`. Lock: wallet row `findForUpdate()`, then the reservation row itself (already implicitly locked via the wallet-scoped transaction). Steps: load the `pending` reservation (error if not pending) → compare `final_amount_micro` (computed from the caller-supplied actual quantity, using the reservation's own snapshotted rate — never a re-read of the current rate) against `reserved_amount_micro` → insert one `UsageCharge` ledger entry (`reserved_decrease`) for `min(final_amount_micro, reserved_amount_micro)` → if `final_amount_micro > reserved_amount_micro`, additionally insert a `FinalAdjustment` (`available_debit`) for the overage, bounded by the same §15 cap checks the reserve step already ran (an overage that would breach a cap is still committed — the operation already happened — but is logged and, per §39, may later drive an alert; it is never silently discarded) → if `final_amount_micro < reserved_amount_micro`, additionally insert a `FinalAdjustment` (`available_credit`) or, more simply, a `ReservationRelease` for the unused portion → mark the reservation `committed`, set `committed_at`/`final_quantity`/`final_amount_micro` → update wallet aggregates. Idempotency: commit is itself keyed by the reservation's own id plus a caller-supplied `idempotency_key` matching the reserve call's correlation — a repeat commit call for an already-`committed` reservation is a no-op returning the original result, never a second charge.
- **Release** — Authority: `UsageWalletManager::release()`. Used for explicit cancellation/denial. Steps: load the `pending` reservation → insert `ReservationRelease` (`reserved_decrease`) for the full `reserved_amount_micro` → mark `released`, set `released_at` → restore `available_balance_micro`. Idempotency: releasing an already-`released`/`expired`/`committed` reservation is a no-op.
- **Reservation expiry reconciliation** — Authority: a scheduled job (`App\Jobs\Usage\ExpireStaleUsageReservations`, `ShouldQueue` + `ShouldQueueAfterCommit` where applicable, following the `App\Jobs\Base` convention) that finds `pending` reservations past `expires_at` and calls the same `release()` path, setting `status = 'expired'` instead of `'released'` for observability, with `actor_user_id = null` (system-generated). Never auto-commits a stale reservation — an operation that never confirmed its outcome is always released, never charged.

**Atomicity / no double-charge:** the caller (the future metered-feature integration, §14/M5) is required to reserve *before* performing the underlying work and commit (or release, on failure) *after* — `UsageWalletManager` itself never performs the metered work. A crash between "work succeeded" and "commit called" leaves a `pending` reservation that expiry reconciliation eventually releases (favoring an unbilled operation over a double-charge, an explicit, named tradeoff — never the reverse).

---

## 14. Metered-feature classification and usage authorization

**Real gateway implementation:** `App\Library\Usage\RealUsageAuthorizationGateway implements UsageAuthorizationGateway` replaces `NullUsageAuthorizationGateway` in the `AppServiceProvider` binding (the one-line change RFC-004 §19 already anticipated). Its `check(Business $business, PlatformFeature $feature)`:

1. If `$feature` is not classified as metered (§14.1 below), return `authorized: true` immediately — **the safe default for a non-metered or unknown classification is always "authorized," never "balance-gated"** (design-contract §4 item 13; product direction 10). A feature only ever becomes balance-gated by an explicit classification row, never by omission.
2. If metered, first check the Business's wallet `billing_status` (§12) — if `suspended`, return denied with reason `billing_suspended` immediately, before any cap/limit/balance check. This is the concrete mechanism behind the "billing state" gate (locked decision 12; §15's evaluation order).
3. Otherwise, delegate to `UsageWalletManager`'s own read-only capacity check (the same cap/limit evaluation §15 defines) against the Business's current wallet state — **this call never reserves anything itself**; reservation is the caller's own explicit step (§13), because `decide()` is a synchronous, cheap authorization check called on every feature access, while reservation is deliberately invoked only at the point real metered work is about to begin. A caller that both checks entitlement via `decide()` and then reserves is expected and correct; `decide()`'s own gateway check is a fast, non-mutating pre-check, not a reservation.
4. Return the stable denial reason (`billing_suspended`, `insufficient_available_balance`, `monthly_spend_cap_exceeded`, `feature_limit_exceeded`, `platform_safety_limit_exceeded`) as `UsageAuthorizationResult(authorized: false, reason: ...)` — which `EntitlementManager::decide()` already surfaces verbatim as its own ninth denial key, `usage_unauthorized`, exactly as RFC-004 reserved it (§3 above; zero changes to `EntitlementManager` itself).

**14.1 Feature classification.** `platform_feature_usage_classifications` (new, tiny table): `feature_key` (unique, `PlatformFeature`-backed), `is_metered` (boolean, default false), `default_rate_id` (FK `business_usage_rates`, nullable), `updated_by_user_id`, `updated_at`. Every `PlatformFeature` case defaults `is_metered = false` on backfill (§32) — **no existing feature becomes metered as a side effect of this RFC shipping**; a feature is only ever metered after an explicit, separate, later product decision names it (§39 does not attempt to name candidates here, since none currently exist in RFC-003/RFC-004).

---

## 15. Monthly Business budget, per-feature limits, and platform safety limits

Three genuinely distinct, non-collapsible controls (Human product requirements 2–4; locked decisions 8(a)/16):

1. **Monthly Business spend cap** (`business_usage_wallets.monthly_spend_cap_micro`) — the ceiling on total metered spend for one Business in one period. Null = uncapped (bounded only by the platform safety limit below).
2. **Per-feature limits** (`business_feature_usage_limits`: `business_id` FK, `feature_key`, `monthly_limit_micro` nullable, `updated_by_user_id`, `updated_at`, unique `(business_id, feature_key)`) — an authorized user may set a tighter ceiling for one specific feature.
3. **Platform safety limit** (`platform_feature_usage_safety_limits`: `feature_key` unique, `max_monthly_limit_micro`, `updated_by_user_id`, `updated_at`) — platform-admin-only, global per-feature ceiling. `UsageWalletManager::setFeatureLimit()` rejects any customer-supplied `monthly_limit_micro` greater than the matching safety limit — a customer may only tighten, never loosen past it (Human product requirement 4).

**Evaluation order** (both at reserve-time, §13, and at `decide()`-time pre-check, §14): structural entitlement (RFC-004 steps 1–6) → Business toggle → billing state (`business_usage_wallets.billing_status`, §12) → per-feature limit → Business monthly spend cap → platform safety limit → (only for the reserve path) available-balance sufficiency. Each has its own stable denial reason (`billing_suspended`, `feature_limit_exceeded`, `monthly_spend_cap_exceeded`, `platform_safety_limit_exceeded`, `insufficient_available_balance`, listed in full in §13/§14); no two are ever collapsed into one generic "denied" result.

**Reservation-near-cap behavior:** a reservation itself may push the Business's period-to-date committed+reserved total up to, but never past, the lowest of the three ceilings — the reserve algorithm's cap check (§13) evaluates the *reservation's* amount against remaining headroom under all three, not just the wallet balance.

**Concurrency:** two reservations racing the last remaining headroom under any one of these controls resolve via the same wallet-row `findForUpdate()` lock the reserve algorithm already takes (§13) — exactly one reservation succeeds, the other receives a denial, mirroring `EntitlementManagerConcurrencyTest.php`'s own proven forced-race pattern (§35).

**Period/timezone:** `monthly_spend_period_start`/`monthly_recharge_period_start` reset on the Business's own configured timezone (falling back to the platform default timezone if none is set) at the start of each calendar month in that timezone — a scheduled job (§31) advances each wallet's period boundary; a cap's "remaining headroom" is always evaluated against the *current* period only.

**Override/adjustment:** a platform administrator may adjust any of the three limits with a mandatory `actor_user_id` and `reason`, recorded as its own `business_usage_limit_transitions`-style audit row (mirroring `workspace_transitions`), never a silent overwrite.

**Isolation test requirement:** one Business's cap/limit/budget state must never affect a different Business's — enforced structurally (every table above is `business_id`-scoped, no shared row), and proven by a direct concurrency test asserting an unrelated Business's reservation is unaffected by another Business's cap-exhausting race (§35), mirroring `EntitlementManagerConcurrencyTest.php`'s own unrelated-Workspace assertions.

---

## 16. Payer selection and Workspace fallback

**`business_payer_assignments`** (current pointer, one row per Business, mutable — mirrors `workspace_plan_assignments`' own shape): `business_id` FK unique `restrictOnDelete()`, `payer_type` (string enum: `business` \| `workspace` \| `agency_rebill` — the third value defined now, never activated before a later RFC, per §5/product direction 5), `effective_payment_instrument_id` (FK `business_payment_instruments`, nullable), `updated_at`.

**`business_payer_transitions`** (append-only history — mirrors `workspace_transitions` exactly): `id`, `business_id` FK, `from_payer_type`, `to_payer_type`, `actor_user_id` (no FK, actor convention), `reason`, `created_at`.

**Default by tier** (locked decision 3): on wallet creation (backfill, §32, or first-Business creation), `payer_type` defaults to `workspace` for Core/Growth Workspaces and `business` for Agency Workspaces, read from the Workspace's current `workspace_plan_assignments`/`workspace_plan_catalog.tier` at creation time — a later plan-tier change does **not** retroactively change an already-set payer (a payer change is always its own explicit, actor-attributed action via `BillingProfileManager::changePayer()`, never an implicit side effect of `EntitlementManager::changePlan()`).

**Effective payer resolution algorithm:** given a Business, read `business_payer_assignments.payer_type` → if `business`, use the Business's own default/selected `business_payment_instruments` row (§17) → if `workspace`, use the owning Workspace's default `business_payment_instruments` row scoped `workspace_id` → if `agency_rebill`, this RFC does not resolve further (out of v1 scope, §5) → if no instrument is found for the resolved payer, the checkout/recharge flow fails with a stable `no_payment_instrument` reason rather than silently falling back to the other payer type.

**Payer change algorithm:** `BillingProfileManager::changePayer()` — `DB::transaction()`, wallet row `findForUpdate()` (not a Workspace lock — this never touches `workspace_plan_assignments`), mandatory `actor_user_id`/`reason`, updates only `business_payer_assignments.payer_type`/`effective_payment_instrument_id`, inserts one `business_payer_transitions` row, dispatches `BusinessPayerChanged implements ShouldDispatchAfterCommit`. **It never touches `business_usage_wallets.available_balance_micro`/`reserved_balance_micro`, never touches any existing ledger entry, and never moves any row between Businesses** — the exact invariant RFC-003 §26.2 locks, satisfied structurally because the payer tables are entirely separate from the wallet/ledger tables.

---

## 17. Billing contact and payment instruments

**`business_billing_contacts`**: `business_id` FK unique `restrictOnDelete()`, `contact_user_id` (FK `users`, nullable — nullable specifically to support independent contact data per Human product requirement 1's clarification), `contact_name` (string, nullable if `contact_user_id` is set), `contact_email` (string, nullable if `contact_user_id` is set), `notification_opt_in` (boolean, default true), `updated_by_user_id` (no FK), `updated_at`. Exactly one of (`contact_user_id` set) or (`contact_name`+`contact_email` set) is required — enforced by `BillingProfileManager`, not a DB constraint (mirrors the manager-enforced-invariant pattern already used in §11).

**Privacy/isolation:** `business_billing_contacts` is `business_id`-scoped with no Workspace-level read path that lists every Business's contact in one query without per-Business authorization (§24) — a Workspace Admin viewing one Business's billing contact does not implicitly see another Business's.

**Notification recipient selection:** low-balance/failed-payment/receipt notifications (§19, §23) resolve their recipient as: `business_billing_contacts.contact_email` if `notification_opt_in` and a contact exists → else the Business's direct owner/customer email → else no notification is sent (never silently substituting an unrelated Workspace member's address).

**Payer-change non-rewrite rule:** every `business_usage_ledger_entries` row that carries billing-relevant metadata records it as of that entry's own creation time (implicitly, since the entry is immutable) — a later billing-contact update never changes what an already-posted entry's "billed to" context was, because the entry never stored a live FK to the contact in the first place, only a `business_id` (from which the *current* contact can always be looked up separately for display, clearly distinguished from "the contact at the time" if that historical value is ever needed — v1 does not persist a per-entry contact snapshot, since the contact is administrative metadata, not a financial fact the way the rate snapshot is).

**`business_payment_instruments`**: `id`, `business_id` (FK, nullable), `workspace_id` (FK, nullable — exactly one of the two is set, manager-enforced), `provider` (string enum, `stripe` only for v1), `provider_customer_id` (string — Stripe Customer id), `provider_payment_method_id` (string — Stripe PaymentMethod id, token reference only, **never raw card data**, per the design contract's explicit PCI requirement and re-confirming `PaymentMethods` is not a precedent for this table, §3), `is_default` (boolean), `status` (string enum: `active` \| `detached`), `created_at`, `detached_at` (nullable).

**Detach/delete behavior:** detaching an instrument still referenced as a payer's `effective_payment_instrument_id` sets `status = 'detached'` and clears that pointer (via `BillingProfileManager`, same transaction) rather than leaving a dangling reference; it never deletes the row (financial audit trail — a past charge's `provider_reference` may still need to be cross-referenced against which instrument was used at the time, resolvable via the ledger entry's own `provider_reference`, not this table).

---

## 18. Manual credit, paid top-up, promotional credit, and add-ons

All four are structurally distinguishable in the ledger by `entry_type` alone (§12) — never conflated:

- **Manual credit** — `UsageWalletManager::addManualCredit()`, callable by a platform administrator (mandatory `actor_user_id`+`reason`) or, per Human product requirement 5, restricted to adding credit to exactly one named Business, never a batch/pooled operation across Businesses.
- **Paid top-up** — orchestrated by `UsageBillingCheckoutManager::initiateTopUp()` (§20/§13's provider-call-outside-transaction discipline): create a Stripe Checkout Session or PaymentIntent for the requested amount → on confirmed success (webhook or, matching the legacy precedent, session-retrieve on return — §20 resolves which), call `UsageWalletManager::recordPaidTopUp()` which inserts the `PaidTopUp` ledger entry inside its own transaction, keyed by the Stripe payment reference as `correlation_key` (idempotent against webhook replay, §31).
- **Promotional credit** — same mechanics as manual credit, distinct `entry_type`, used for the owner/operator Agency subsidy mechanism recommended in §39 item 5 and for any future marketing-driven credit; always actor+reason attributed even when the actor is a scheduled system job (`actor_user_id = null`, `reason` states the automated policy that issued it).
- **Reversal/expiry policy:** v1 credits (manual, promotional, and paid top-up alike) **do not expire** and are **not automatically reversed** — an admin wishing to remove previously granted credit issues a `FinalAdjustment` (`available_debit`) with a mandatory reason, itself a fully visible, distinct ledger entry, never a silent balance overwrite. If a future milestone wants true expiring credit, that is a new, explicitly designed feature, not an implicit property of v1's credit types.

**Add-ons** (Human product requirement 6): `business_usage_addon_catalog` (`addon_key` unique, `display_name`, `price_micro`, `currency_id`, `fulfillment_mode` string enum `wallet_credit` \| `direct_deliverable`, `is_active`) and `business_usage_addon_purchases` (`business_id` FK, `addon_key`, `price_micro` snapshot, `provider_reference`, `idempotency_key` unique, `status` enum `pending`\|`completed`\|`failed`, `requested_by_user_id`, `completed_at` nullable, `created_at`). `fulfillment_mode` makes the wallet-debit-vs-separate-SKU choice **data-driven per add-on**, not hardcoded: a `wallet_credit` add-on's completion calls `UsageWalletManager` to post a ledger entry; a `direct_deliverable` add-on's completion dispatches a fulfillment job (e.g., generating a purchased audit report) with no wallet interaction at all. This RFC's one worked v1 example — a purchasable audit — is recommended as `direct_deliverable` (§39 item 8 leaves the exact v1 roster/pricing open).

**Cross-Business isolation restatement:** none of manual credit, promotional credit, paid top-up, or an add-on purchase ever accepts a "target Business" different from the Business whose wallet/purchase record is being written, and no manager method accepts two Business ids in one credit/purchase call — pooling or transfer across Businesses is structurally inexpressible in v1, not merely policy-forbidden, unless a later, separate, human-approved RFC explicitly changes that locked isolation rule (design-contract §4 item 5).

---

## 19. Auto-recharge and low-balance behavior

**Trigger:** evaluated synchronously at the end of every successful `UsageWalletManager::commit()` (§13) that leaves `available_balance_micro < auto_recharge_threshold_micro`, when `auto_recharge_enabled` is true and no `pending` `business_auto_recharge_attempts` row already exists for that Business (enforced by a locking read on the wallet row before creating a new attempt — the "one active attempt per Business/policy" rule, mirroring RFC-004 M2's catalog-locking-read precedent, since MySQL cannot express a partial-unique "at most one pending row" constraint natively, §3).

**`business_auto_recharge_attempts`**: `id`, `business_id` FK, `wallet_id` FK, `amount_micro` (= `auto_recharge_amount_micro`, bounded by remaining `monthly_recharge_cap_micro` headroom — an attempt that would exceed the recharge cap is not created at all, and instead only a low-balance notification fires), `provider_reference` nullable, `status` enum `pending`\|`succeeded`\|`failed`, `idempotency_key` unique, `failure_reason` nullable, `attempted_at`, `completed_at` nullable.

**Initiation algorithm:** `UsageBillingCheckoutManager::initiateAutoRecharge()` — resolve effective payer/instrument (§16) → if none, skip recharge entirely and fall through to the low-balance notification path only → create the `pending` attempt row (its own short transaction, wallet row locked only long enough to check/create the attempt — never held across the outbound Stripe call, §20) → call `PaymentProviderGateway::chargeOffSession()` **outside** any open transaction → on synchronous success, immediately record it (§19's completion algorithm below); on synchronous failure, mark the attempt `failed` with `failure_reason` and apply the failed-payment retry/disable rule below.

**Webhook completion/failure algorithm:** because auto-recharge is an off-session charge, its authoritative outcome may also (or only) arrive via webhook (§21). `UsageWalletManager::recordAutoRecharge()` is idempotent on `idempotency_key`/`provider_reference` — whichever of (synchronous response, webhook event) arrives first records the outcome; the second arrival is a no-op recognized by the same key, never a double credit. On success: insert `AutoRecharge` ledger entry, mark attempt `succeeded`, clear any low-balance notification dedup state. On failure: mark attempt `failed`; a configurable number of consecutive failures (exact count is an M3-contract implementation detail, not fixed here) disables `auto_recharge_enabled` and sends a distinct "auto-recharge disabled" notification, never silently leaving it enabled and retrying forever.

**Low-balance notification dedup:** `low_balance_notified_at` is set the first time a period crosses below threshold; not re-sent until either the balance recovers above threshold (resets the dedup) or 24 hours have elapsed, whichever comes first — exact interval is an M3 implementation detail within this stated dedup *shape*.

**Zero-balance / reserved-balance behavior:** a reserve request that would take `available_balance_micro` negative is denied (`insufficient_available_balance`, §13) — it never blocks reading balance/ledger data, never deactivates the Business or Workspace (locked decision 12), and never blocks any non-metered feature (locked decision 13).

**Customer-configurable limits vs. platform safety limit:** `auto_recharge_threshold_micro`/`auto_recharge_amount_micro`/`monthly_recharge_cap_micro` are all customer-configurable within RFC-003 §26.2's inherited bounds (threshold below $10, i.e. below `10_000_000` micro-units at the v1 USD/2-decimal scale) — never a channel to bypass §15's platform safety limit, which governs spend, not recharge, and is evaluated independently.

---

## 20. Stripe/provider boundary

**Resolution: Stripe-only v1, behind the narrow `PaymentProviderGateway` interface (§9).** No second provider ships in v1 (product direction 2); the interface's shape (setup/attach, checkout/payment-intent creation, off-session charge, webhook verification) is chosen to be provider-agnostic enough that a second adapter could implement it later without touching `UsageWalletManager`/`BillingProfileManager`/`UsageBillingCheckoutManager`.

**Stripe Customer ownership:** one Stripe Customer per `business_payment_instruments` "owner" (a Business or a Workspace) — `provider_customer_id` lives on the instrument row (§17), not on `businesses`/`workspaces` themselves, so RFC-005 introduces zero new columns on either core tenancy table.

**SetupIntent / instrument attachment:** `PaymentProviderGateway::createSetupIntent()` (Stripe SetupIntent, not a PaymentIntent) for adding an instrument without an immediate charge — the correct Stripe primitive for "save a card for later off-session use," which the legacy Checkout-Session-per-purchase pattern (§3) never needed because every legacy flow charges immediately.

**Checkout Session vs. PaymentIntent — resolved per flow, not globally:**
- Manual top-up and additional-slot/add-on purchase (customer present, one-time): **Checkout Session**, directly reusing the exact working pattern already in `EloquentSubscriptionRepository`/`EloquentAccountRepository`/etc. (`\Stripe\Checkout\Session::create([...])`), for consistency with existing operational familiarity in this codebase — **but note the success-confirmation step differs from the legacy pattern**: §21 requires webhook-based confirmation as authoritative, with the browser-redirect `sessions->retrieve()` pattern kept only as a synchronous UX nicety, never as the sole source of truth (unlike the legacy flow, which relies on it exclusively).
- Auto-recharge (no customer present, off-session): **PaymentIntent** with `off_session: true`/`confirm: true` against the stored `provider_payment_method_id` — Checkout Session is not usable here since no browser session exists to redirect.

**Webhook verification:** `PaymentProviderGateway::verifyWebhookSignature()` wraps `\Stripe\Webhook::constructEvent($payload, $sigHeader, config('services.stripe.webhook.secret'))` — the **first use of this already-configured-but-unused config value** (§3) — reading the **raw request body**, never the framework-parsed JSON (Stripe's signature covers the exact raw bytes). The webhook route is added to `VerifyCsrfToken`'s `$except` array (a new entry, since Stripe currently has none, §3) and is never reachable by an authenticated browser session.

**Event persistence, replay, and idempotency:** every verified event is inserted into `payment_provider_events` (`provider_event_id` unique = Stripe's own event id = the idempotency key) **before** any business-logic processing begins; a replayed event (same `provider_event_id`) is recognized at that insert (unique-constraint violation) and short-circuits to "already processed," never reprocessed. Out-of-order/delayed delivery is handled because every downstream mutation (`recordPaidTopUp`, `recordAutoRecharge`) is itself idempotent on its own `correlation_key`/`idempotency_key`, independent of event arrival order.

**Reconciliation when Stripe and local state diverge:** a scheduled job periodically re-queries Stripe for any `pending`-status local attempt/purchase older than a bounded window and reconciles it against Stripe's own authoritative status — never assumes local `pending` means "still processing" forever.

**No outbound Stripe call ever occurs while a database row lock is held** — every algorithm in §13/§16/§19 explicitly separates "acquire lock, check/write local state, release lock" from "call Stripe," in that order, with the Stripe call never nested inside the locked transaction.

**Test-mode separation:** `config('services.stripe.key'/'secret')` already reads from environment (§3); v1 introduces no new secret-handling pattern beyond what's already configured, and every automated test fakes the `PaymentProviderGateway` interface entirely (§35) — no automated test ever calls real Stripe, test or live mode.

**SDK version recommendation: retain `stripe/stripe-php ^7.76` (currently resolving to `v7.128.0`) for v1.** The installed version already supports SetupIntent, PaymentIntent with `off_session`, Checkout Session, and `Webhook::constructEvent()` — all APIs this design uses are stable, long-available surfaces of the Stripe PHP SDK, not recent additions requiring a major-version bump. Upgrading to Stripe SDK major version 8+ (a breaking change) is not recommended for RFC-005 v1 given no design element here requires it; **this contract/RFC does not perform or choose any such upgrade** — that remains a future, separately justified decision if a later milestone finds a concrete blocking need.

---

## 21. Webhook verification, persistence, replay, and reconciliation

(Consolidated with §20 above per the contract's "combine adjacent sections only if every required topic remains individually addressable" allowance — every sub-topic listed in the contract's required-sections list is present and separately labeled within §20: signature verification, raw-body handling, event persistence, idempotency keys, replay/reorder handling, and reconciliation.)

---

## 22. Additional Business-slot checkout

**`additional_business_slot_purchases`**: `id`, `workspace_id` FK `restrictOnDelete()`, `requested_slots` (unsigned tinyint), `price_micro` (snapshot at purchase time, from `workspace_plan_catalog.price`/`additional_business_slot_price_ratio` — read, never re-derived or duplicated, per locked decision 9), `currency_id` FK, `provider_reference`, `idempotency_key` unique, `status` enum `pending`\|`completed`\|`failed`, `requested_by_user_id`, `completed_at` nullable, `created_at`.

**Sequence:** quote (`UsageBillingCheckoutManager::quoteAdditionalSlots()` reads the current catalog price/ratio, no write) → create a Stripe Checkout Session for the quoted price (§20) → on confirmed success (webhook-authoritative, §21), `UsageBillingCheckoutManager::completeAdditionalSlotPurchase()` marks the purchase row `completed` **and then calls `EntitlementManager::setAdditionalBusinessSlots()`** (the existing RFC-004 authoritative mutation, unchanged, locked decision 9) with the new total slot count — RFC-005 never reimplements or bypasses that method, and never writes to `workspace_plan_assignments` directly.

**Idempotency:** duplicate webhook delivery or a customer retrying a stalled checkout are both absorbed by `idempotency_key` — a second `completed` transition for an already-completed purchase is a no-op; `setAdditionalBusinessSlots()` itself is also idempotent (setting the same count twice is already a no-op in its existing RFC-004 implementation, confirmed by direct read, §3).

**Complimentary/Agency-unlimited behavior:** a Workspace with `is_complimentary` true or an Agency-tier catalog with `unlimited_business_slots` true never reaches this checkout flow at all — `UsageBillingCheckoutManager::quoteAdditionalSlots()` returns a "no purchase needed" result in either case, consistent with locked decisions 10/11 (never converts a grandfathered/complimentary allocation into debt, and never invents a purchase requirement for a Workspace with unlimited slots).

**Price changes between quote and payment:** the Checkout Session is created with the quoted price fixed into the Stripe session itself (Stripe's own Checkout Session amount is immutable once created) — if the catalog price changes after quoting but before the customer completes payment, the customer still pays the originally quoted price for that already-created session; a stale/abandoned session is simply never completed, and a fresh quote reflects the new price.

---

## 23. Refunds, disputes, chargebacks, invoices, receipts, and tax/VAT boundary

**Refunds:** admin-initiated (via `UsageWalletManager`, mandatory actor+reason) or Stripe-initiated (via webhook, §21) — both post a `Refund` ledger entry (`available_debit`) referencing the original credit entry via `reversed_entry_id`-style correlation (a `Refund` is a new entry type distinct from `CorrectionReversal`, since a refund specifically undoes a payment, not an arbitrary prior entry).

**Disputes/chargebacks:** a `charge.dispute.created` (or equivalent) webhook posts a `DisputeChargeback` ledger entry (`available_debit`) and flags the Business's wallet for review — v1 does not attempt automated dispute-response logic; it records the financial fact and, per §24, surfaces it to a platform administrator.

**Invoices/receipts — recommendation: Stripe-hosted receipts are authoritative for v1.** Every Checkout Session/PaymentIntent already produces a Stripe receipt (email + hosted URL) with zero new code; `business_billing_receipts` (`business_id` FK, `ledger_entry_id` FK, `provider_receipt_url`, `provider_reference`, `created_at`) stores only a **pointer/cache** to that Stripe-hosted document, never a new authoritative fiscal record. **The legacy `invoices` table is explicitly not reused** for this purpose (§3's direct evidence: `string`-typed `amount`, `cascade` foreign keys — the opposite of this RFC's `restrictOnDelete()`/exact-integer posture) — a new, minimal, RFC-005-owned pointer table is used instead.

**Tax/VAT posture: marked NON-IMPLEMENTATION-READY, an open human decision (§39 item 6).** Recommendation: adopt Stripe Tax within the M3 Checkout Session/PaymentIntent creation calls if the operator wants automated calculation (a small, well-bounded addition to the same provider boundary, §20); otherwise, v1 explicitly collects no tax, and the deployment guide for whichever milestone ships this must state the resulting operational boundary in writing (the operator remains responsible for any tax obligation outside the platform) — either way, this RFC does not silently pick one.

**Refund/credit-note relationship:** a `Refund` ledger entry is the credit-note-equivalent record in this design — v1 does not generate a separate document titled "credit note"; if a future milestone's tax/VAT decision (above) requires a formal credit note as a legal document, that is scoped into whichever milestone adopts Stripe Tax, not invented here.

---

## 24. Authorization and tenant isolation

Five genuinely distinct authority paths, per `AGENTS.md`'s "Workspace authorization" section and RFC-004 §20's inherited absolute rule (no controller-owned business rule) — one path's grant never implies another's:

| Capability | Workspace owner | Active Workspace Admin | Staff | Direct Business owner/customer | Platform administrator |
|---|---|---|---|---|---|
| View balance/ledger for a Business in their Workspace | Yes | Yes, if `business_access_scope` covers that Business | Yes, if scope covers it | Yes, for their own Business only | Yes, any Business |
| Manage billing contact | Yes | Yes, if scope covers it | No | Yes, own Business | Yes |
| Manage payment instruments | Yes | Yes, if scope covers it | No | Yes, own Business | Yes |
| Change payer | Yes | No — payer changes financial responsibility, restricted to owner/admin-of-record only | No | Yes, own Business (Agency tier's default) | Yes |
| Initiate top-up | Yes | Yes, if scope covers it | No | Yes, own Business | Yes |
| Configure Business spend cap / per-feature limits | Yes | Yes, if scope covers it, bounded by platform safety limit | No | Yes, own Business, bounded by platform safety limit | Yes, including setting the platform safety limit itself |
| Configure auto-recharge | Yes | Yes, if scope covers it | No | Yes, own Business | Yes |
| Issue manual/promotional credit | No | No | No | No | Yes only |
| Set/clear `billing_status = 'suspended'` (§12) | No | No | No | No | Yes only |
| View internal provider cost (`provider_cost_micro`) | No | No | No | No | Yes only |

Unrelated Workspace/Business resources fail closed with a 404-shaped response, never a 403 that would confirm existence — matching RFC-004's own established pattern for cross-Workspace access attempts (`WorkspaceBusinessNotFoundException`/`BusinessWorkspaceMismatchException` precedent, §3). No raw query against any new billing table is permitted outside `UsageWalletManager`/`BillingProfileManager`/`UsageBillingCheckoutManager` and their repositories, except an immutable migration/backfill script (§32) — enforced by a new mechanical boundary test mirroring `NoRawEntitlementTableQueryTest.php` (§35).

**New permission category:** `Business Usage Billing` (`view business usage billing`, `manage business usage billing`) — does not collide with `Workspace`, `Workspace Plans`, `Subscriptions`, or `Payment Gateways` (§3's direct confirmation).

---

## 25. Schema

Full table list (all `restrictOnDelete()` on every tenancy-scoping foreign key, never `cascade`; no native `ENUM` anywhere — every enum-shaped column is a `string` cast to a PHP backed enum, matching RFC-003/RFC-004's exclusive convention; every table backfilled where it needs one row per existing Business, per §32):

| Table | Backfilled? | Sole write authority |
|---|---|---|
| `business_usage_wallets` | Yes — one row per existing Business | `UsageWalletManager` |
| `business_usage_ledger_entries` | No (empty at launch) | `UsageWalletManager` |
| `business_usage_reservations` | No | `UsageWalletManager` |
| `business_usage_rates` | No — seeded with zero rows; §39 item 1 | `UsageWalletManager` |
| `platform_feature_usage_classifications` | Yes — one row per `PlatformFeature` case, `is_metered=false` | `UsageWalletManager` |
| `business_feature_usage_limits` | No | `UsageWalletManager` |
| `platform_feature_usage_safety_limits` | No | `UsageWalletManager` |
| `business_billing_contacts` | No | `BillingProfileManager` |
| `business_payer_assignments` | Yes — one row per existing Business, default payer by tier (§16) | `BillingProfileManager` |
| `business_payer_transitions` | No | `BillingProfileManager` |
| `business_payment_instruments` | No | `BillingProfileManager` |
| `business_auto_recharge_attempts` | No | `UsageBillingCheckoutManager` |
| `additional_business_slot_purchases` | No | `UsageBillingCheckoutManager` |
| `business_usage_addon_catalog` | No — seeded, §39 item 8 | `UsageBillingCheckoutManager` |
| `business_usage_addon_purchases` | No | `UsageBillingCheckoutManager` |
| `business_billing_receipts` | No | `UsageWalletManager` |
| `payment_provider_events` | No | `UsageBillingCheckoutManager` |

Exact columns/types/constraints for each are given in §11 (rates), §12 (wallets, ledger), §13 (reservations), §14.1 (classifications), §15 (limits), §16 (payer), §17 (contact, instruments), §18 (add-ons), §19 (recharge attempts), §22 (slot purchases), §23 (receipts), §20/§21 (provider events). DDL and any data-only backfill operation are proposed as separate migrations, per RFC-003 §10.1/RFC-004 §25.1's shared DDL/data-operation-separation convention (§3, §32).

`CHECK` constraints (e.g., "exactly one of `business_id`/`workspace_id` set" on `business_payment_instruments`) are recommended where the target MySQL version is confirmed 8.0+; the M1 contract must confirm this before relying on any `CHECK` constraint, falling back to manager-level enforcement (already the primary enforcement mechanism throughout this design, §3) if it cannot be confirmed.

---

## 26. PHP enums/value objects/models

New backed enums (string-backed, matching `WorkspacePlanTier`/`WorkspaceMembershipRole` convention): `UsageLedgerEntryType`, `UsageBalanceEffect`, `UsageReservationStatus`, `WalletBillingStatus` (§12), `PayerType`, `PaymentInstrumentStatus`, `AutoRechargeAttemptStatus`, `AddonFulfillmentMode`, `AddonPurchaseStatus`, `SlotPurchaseStatus`, `RoundingRule`.

New readonly value objects (mirroring `UsageAuthorizationResult`'s own shape): `ReservationResult` (bool `authorized`, ?string `reservationId`, ?string `denialReason`), `CommitResult`, `EffectivePayer` (`PayerType`, ?instrument), `CapEvaluation` (per-control remaining headroom, used internally by §15's evaluation order).

New Eloquent models: `BusinessUsageWallet`, `BusinessUsageLedgerEntry`, `BusinessUsageReservation`, `BusinessUsageRate`, `PlatformFeatureUsageClassification`, `BusinessFeatureUsageLimit`, `PlatformFeatureUsageSafetyLimit`, `BusinessBillingContact`, `BusinessPayerAssignment`, `BusinessPayerTransition`, `BusinessPaymentInstrument`, `BusinessAutoRechargeAttempt`, `AdditionalBusinessSlotPurchase`, `BusinessUsageAddonCatalogEntry`, `BusinessUsageAddonPurchase`, `BusinessBillingReceipt`, `PaymentProviderEvent` — one model per table in §25, each `casts` its enum columns, none exposes a `fillable` wider than its manager actually writes (matching RFC-004's own tight-`fillable` convention observed in every M1 model).

---

## 27. Repository contracts

One contract + one Eloquent implementation per table in §25 (seventeen pairs, thirty-four files), bound in `AppServiceProvider` identically to RFC-004 M1's six-repository pattern. No repository method returns a raw query builder to a caller outside its owning manager; every method is named for the exact read/write it performs (`findForUpdate`, `findActiveRateForFeature`, `sumCommittedForPeriod`, etc.), matching RFC-004's own repository-method-naming convention.

---

## 28. Manager/domain authority

Restated from §9 for completeness: `UsageWalletManager` (wallets, ledger, reservations, rates, limits, classifications, receipts), `BillingProfileManager` (billing contact, payer, instruments), `UsageBillingCheckoutManager` (top-up, auto-recharge initiation, additional-slot purchase, add-on purchase, provider-event ingestion), `StripePaymentProviderGateway` (the only class referencing the `Stripe\*` namespace, implementing `PaymentProviderGateway`). No controller, job, or event listener ever writes to a table in §25 directly — matching RFC-004 §20's absolute rule, inherited unchanged (locked decision list, design-contract §4).

---

## 29. Jobs, events, notifications, and scheduling

**Jobs** (`App\Jobs\Usage\*`, extending `App\Jobs\Base`, `ShouldQueue` + `ShouldQueueAfterCommit` where dispatched from within a request-handling transaction — the real, existing convention, §3): `ExpireStaleUsageReservations` (§13), `AdvanceUsagePeriodBoundaries` (§15), `ReconcileProviderPendingState` (§20), `SendLowBalanceNotification`, `SendAutoRechargeDisabledNotification`, `SendReceiptNotification`.

**Events** (`App\Events\Usage\*`, `implements ShouldDispatchAfterCommit` — the correct, real interface name confirmed in §3, not the job interface): `BusinessWalletCredited`, `BusinessWalletDebited`, `BusinessUsageReserved`, `BusinessUsageCommitted`, `BusinessUsageReservationReleased`, `BusinessPayerChanged`, `BusinessBillingContactChanged`, `BusinessAutoRechargeSucceeded`, `BusinessAutoRechargeFailed`, `AdditionalBusinessSlotPurchaseCompleted`. Every event carries IDs/scalars only (RFC-003 §19/RFC-004 §21's shared convention, §3) — never a full model instance.

**Scheduling:** `AdvanceUsagePeriodBoundaries` and `ExpireStaleUsageReservations` run on Laravel's scheduler at a bounded interval (exact cadence is an M1/M3 implementation detail); `ReconcileProviderPendingState` runs less frequently, matching §20's reconciliation requirement.

---

## 30. HTTP/admin/customer surfaces and permissions

**Customer surface** (`routes/customer.php`, nested under the existing `workspaces` prefix/name group, §3's confirmed convention): `workspaces/{workspaceUid}/businesses/{businessUid}/usage` (GET, balance+ledger+caps view), `.../usage/top-up` (POST), `.../usage/payer` (POST), `.../usage/billing-contact` (POST), `.../usage/instruments` (POST/DELETE), `.../usage/limits` (POST), `.../usage/auto-recharge` (POST), `.../usage/slots/quote` and `.../usage/slots/purchase` (GET/POST), `.../usage/addons/{addonKey}/purchase` (POST) — every route `POST` for a mutation, every route scoped by `{workspaceUid}`/`{businessUid}`, never a raw numeric id, exactly matching §3's confirmed existing convention.

**Webhook route** (`routes/web.php` or a dedicated `routes/webhooks.php`, outside the `customer`/authenticated route groups entirely): `POST /webhooks/stripe/usage-billing` — added to `VerifyCsrfToken`'s `$except` (§3, §20), never behind session auth.

**Admin surface** (`Admin\UsageBillingController` or similar, under the new `Business Usage Billing` permission category, §24): read balance/ledger/caps for any Business, issue manual/promotional credit, set the platform safety limit, view (never edit) `provider_cost_micro` aggregates for observability (§34).

**Observability:** admin views never render a raw payment-instrument token or a webhook payload's sensitive fields; logs redact `provider_payment_method_id`/raw webhook bodies beyond what's needed for the `payment_provider_events.payload` audit column (itself admin-only, §24).

---

## 31. Concurrency, lock order, idempotency, and retry rules

**Canonical lock order**, extending RFC-004 §17.2's ascending-Workspace-ID order without contradicting it: **Workspace (only when a path already needs one, e.g. additional-slot allocation via `setAdditionalBusinessSlots()`) → Business → wallet → reservation → auto-recharge-attempt.** Ledger inserts are append-only and take no row lock of their own beyond the wallet row already held by their enclosing transaction. **No RFC-005 algorithm ever locks a wallet row before a Workspace row it also needs in the same transaction** — the additional-slot-purchase completion step (§22) explicitly acquires the Workspace lock first (inside `setAdditionalBusinessSlots()`, unchanged) and only afterward, in a separate later transaction, updates the purchase row — avoiding a fourth deadlock class beyond the one RFC-004 M2 already resolved between legacy onboarding and `transferOwnership()` (§3).

**Idempotency keys**: reservation (`business_usage_reservations.idempotency_key`), ledger correlation (`business_usage_ledger_entries.correlation_key`), auto-recharge attempt, add-on purchase, slot purchase, and provider event (`provider_event_id`) each carry their own unique constraint — the durable idempotency mechanism, not merely an application-level check.

**Exactly-once accounting under at-least-once provider delivery:** guaranteed by the combination of (a) `payment_provider_events.provider_event_id` unique constraint rejecting a replayed webhook before any business logic runs, and (b) every downstream `record*()` method being independently idempotent on its own key — a double-delivery is absorbed at either layer.

**Forced-race test scenarios** (§35 names the concrete tests): two reservations against the same wallet racing the last remaining spend-cap headroom; two auto-recharge triggers racing the "one pending attempt" rule; a reservation racing a manual admin credit on the same wallet; an unrelated-Business reservation proceeding unaffected during another Business's race — all via the real cross-process pattern (§3, §35).

---

## 32. Backfill, rollout, compatibility, and rollback safety

**Backfill:** one `business_usage_wallets` row and one `business_payer_assignments` row per existing Business (§25), created with zero balance, null caps (uncapped by default — no existing Business is retroactively usage-blocked), and payer defaulted by the Workspace's current tier (§16). One `platform_feature_usage_classifications` row per existing `PlatformFeature` case, `is_metered = false` for every one (§14.1) — **rollout introduces zero newly-gated features**; every feature remains exactly as accessible after this RFC ships as before, until a separate, later, explicit product decision marks a specific feature metered.

**DDL/data separation:** every table's `CREATE TABLE` migration is separate from its backfill migration, per RFC-003 §10.1/RFC-004 §25.1's shared convention (§3), so a schema-only deploy can run ahead of the data-populating step if the deployment process requires that split.

**Rollback safety:** a `migrate:rollback` that proceeds in exact FK-safe reverse order will, like RFC-004's own migrations, mechanically drop every RFC-005 table and silently destroy any live wallet/ledger data accumulated since deploy — `restrictOnDelete()` protects row-level deletes, never a `DROP TABLE` (RFC-004's own M4 deployment guide already documents this exact danger for its own tables, §3, and this RFC inherits the identical caveat rather than re-litigating it). The eventual RFC-005 deployment guide (final milestone, §36) must state this explicitly and offer the same three recovery paths RFC-004's guide already established (forward repair, app-code-only rollback, separately reviewed physical removal) — this RFC does not attempt to design around it structurally, since RFC-004 already established that no such structural fix exists within this migration framework.

---

## 33. Security and PCI posture

No raw card data is ever received, stored, or logged by this platform — every payment instrument is a Stripe-issued token (`provider_payment_method_id`) obtained via Stripe.js/Elements on the client side and attached server-side only by reference (§17, §20). `provider_customer_id`/`provider_payment_method_id` are the only Stripe identifiers persisted; webhook payloads are persisted in full (`payment_provider_events.payload`) for audit/replay but are admin-only readable (§24) and are expected to be redacted of any field Stripe itself marks sensitive before any non-admin-facing log line includes them. Secrets (`STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`) remain environment-only, following the already-established `config/services.php` pattern (§3) — no new secret-storage mechanism is introduced.

---

## 34. Observability and internal unit-economics controls

`provider_cost_micro` is aggregated (never exposed per-transaction to a customer surface, §11/§24) into an admin-only dashboard/report showing rolling internal AI/API cost per Business — the direct implementation of Human product requirement 7's ~$10–$15/Business/month **internal cost-control and observability target**. This aggregate:

- Is never used to automatically suspend a Business or throttle a feature — any customer-facing consequence tied to it requires its own separate, explicitly justified product decision (Human product requirement 7's second clarification), which this RFC does not make.
- Is distinct from, and never substituted for, the customer-facing `monthly_spend_cap_micro`/per-feature-limit controls (§15), which are always evaluated against `retail_rate_micro`, never `provider_cost_micro`.
- Feeds a scheduled alert (not an enforcement action) when a Business's rolling internal cost crosses the operator's configured threshold — exact enforcement mechanics, if any are ever added, remain an explicit open decision (§39 mirrors Human product requirement 7's third clarification here).

---

## 35. Exact test strategy

Every test class below is a **future milestone's** responsibility to write (this RFC does not ship a test); listed here to bind those milestones to a concrete, complete plan, per the design contract's explicit requirement:

- **Money/precision** — `UsageMoneyPrecisionTest`: boundary-value assertions (largest representable micro-unit amount, smallest nonzero amount, a rate whose retail/provider cost differ only in the sixth decimal place) never round incorrectly or silently truncate.
- **Rate snapshot immutability** — `UsageRateSnapshotTest`: creating a new active rate version never changes `retail_rate_micro`/`provider_cost_micro` on an already-posted ledger entry or an already-open reservation.
- **Reservation/commit/release lifecycle** — `UsageReservationLifecycleTest`: reserve→commit (exact/over/under actual quantity), reserve→release, reserve→expire (via the scheduled job), double-commit no-op, double-release no-op.
- **Cap enforcement** — `UsageBusinessSpendCapTest`, `UsageFeatureLimitTest`, `UsagePlatformSafetyLimitTest`: each control denies independently with its own stable reason; a customer-configured limit above the platform safety limit is rejected at write time (§15).
- **Concurrency** — `UsageWalletConcurrencyTest`: real cross-process forced races (§31's scenario list), reusing `EntitlementManagerConcurrencyTest.php`'s exact pattern — no `RefreshDatabase`, `Symfony\Component\Process\Process`-spawned independent PHP processes, a dedicated `Support/` runner script, explicit fixture teardown, and at least one unrelated-Business-unaffected assertion per scenario (§3).
- **Billing-contact isolation** — `BusinessBillingContactIsolationTest`: one Business's contact is never resolvable from another Business's or Workspace's authorized read path.
- **Credit-type distinction** — `UsageCreditLedgerTest`: manual, promotional, and paid-top-up credits are never conflated in a ledger query filtered by `entry_type`.
- **Add-on idempotency** — `UsageAddonPurchaseIdempotencyTest`: a duplicated webhook/retry for the same `idempotency_key` never double-fulfills a `wallet_credit` or `direct_deliverable` add-on.
- **Provider-cost non-disclosure** — `UsageProviderCostNonDisclosureTest`: every non-admin-authorized HTTP response/view/export is asserted to never include `provider_cost_micro` in any form (mirrors `NoRawEntitlementTableQueryTest.php`'s boundary-test shape, §3/§24).
- **Invoice/receipt boundary** — `UsageReceiptBoundaryTest`: whichever of (Stripe-hosted authoritative) or (explicitly deferred with stated boundary) the M3/tax-decision milestone adopts (§23), a test proves that stated boundary actually holds (either the receipt pointer resolves correctly, or the deferred-scope documentation's claim is mechanically verified true).
- **Mechanical source-boundary test** — extends or supersedes `WorkspaceM1BBoundaryTest::test_no_rfc005_concepts_exist_yet`'s current narrow (`Workspace`-named-files-only) scope (§3) with an RFC-005-specific boundary test (mirroring `NoRawEntitlementTableQueryTest.php`'s own precedent) once RFC-005 concepts are authorized to exist — the exact new scope is an M1-contract decision, not fixed here.
- **Webhook/provider fakes** — every test in every milestone fakes `PaymentProviderGateway` entirely; **no automated test contacts Stripe, in test or live mode** (§20).
- **Database** — `ultimatesms_testing` only, never production or another database (`AGENTS.md`, inherited unchanged).
- **Gate shape** — focused tests (`tests/Unit/Usage`, `tests/Feature/Usage`) before the aggregate Entitlement/Workspace/Business/Opportunity/Usage and full-suite regression gates, mirroring RFC-004 §22's exact five-gate shape (§3).

---

## 36. Milestone decomposition

Each milestone below requires its own separately drafted, human-reviewed, merged implementation contract before work begins (design-contract §1) — none may start automatically from this document or from the prior milestone's merge.

1. **M1 — Wallet & Ledger Foundation.** Schema: `business_usage_wallets`, `business_usage_ledger_entries`, `business_usage_reservations`, `business_usage_rates`, `platform_feature_usage_classifications`. Models, repositories, `UsageWalletManager` (reserve/commit/release/expire, rate versioning). Real `RealUsageAuthorizationGateway` bound (§14), but every feature classified non-metered at launch (no behavior change for any existing feature). Backfill. No HTTP surface, no Stripe. Focused + concurrency tests (§35).
2. **M2 — Budgets, Limits, Payer, and Billing Contact.** Schema: `business_feature_usage_limits`, `platform_feature_usage_safety_limits`, `business_billing_contacts`, `business_payer_assignments`, `business_payer_transitions`, `business_payment_instruments` (storage/attach scaffolding only, no live charge yet). `BillingProfileManager`. Authorization matrix (§24), new permission category. Backfill of payer defaults.
3. **M3 — Stripe Integration.** `PaymentProviderGateway`/`StripePaymentProviderGateway`. SetupIntent/instrument attachment. Manual top-up (Checkout Session). Webhook endpoint, signature verification, `payment_provider_events`, reconciliation job. Auto-recharge initiation + completion (`business_auto_recharge_attempts`). Low-balance/failed-payment notifications. Tax/VAT decision resolved here or explicitly deferred with stated boundary (§23).
4. **M4 — Additional-Slot Checkout and Add-ons.** `additional_business_slot_purchases`, `business_usage_addon_catalog`, `business_usage_addon_purchases`. Customer/admin credit-adjustment surfaces (§18). Calls into RFC-004's existing `setAdditionalBusinessSlots()` (never reimplemented).
5. **M5 — Metered Feature Classification.** The first real feature(s) classified `is_metered = true` (exact candidate feature is not named by this RFC — an open decision, §39), wired to real reserve/commit calls in that feature's own execution path. Full `decide()`-integrated usage-authorization gate live for that feature.
6. **M6 — Conformance, Deployment, and Tag.** Full conformance matrix against this RFC and every milestone contract, deployment guide (including the §32 rollback-danger disclosure), full regression, post-merge exact-tag-candidate gate, and — only after separate explicit human authorization — the proposed annotated tag `rfc-005-business-usage-billing-and-wallets`. No tag is created by this document or by any milestone before M6's own explicitly authorized step.

---

## 37. Acceptance criteria

Each milestone's own contract defines its exact acceptance criteria (mirroring RFC-004 §27's per-milestone shape); at the RFC level, RFC-005 as a whole is acceptance-complete only when: every table in §25 exists and is backfilled where required; `NullUsageAuthorizationGateway` has been replaced by a real, tested implementation; at least one milestone's conformance document shows every §35 test class passing; no existing feature's accessibility changed as a side effect of any RFC-005 milestone shipping (§32); and the M6 conformance matrix shows every item in §40's coverage table resolved, not merely addressed.

---

## 38. Release/tag gate

Identical discipline to RFC-004 §29/§30: no tag before M6; M6's own post-merge exact-tag-candidate gate (full regression against the exact merged SHA, re-verification of every locked decision in §7) must pass before a human separately, explicitly authorizes the annotated tag `rfc-005-business-usage-billing-and-wallets` on that exact SHA. This document proposes that tag name; it does not create it, and no milestone before M6 may create any tag.

---

## 39. Open human decisions

Per the design-contract's gap rule (its §6, restated as category 4 here): each of the following is a genuine human/commercial decision this RFC does not invent a value for.

1. **Exact initial retail usage rates** per eventually-metered feature. *Options:* per-call flat rate; tiered/volume rate; pass-through-plus-margin off `provider_cost_micro`. *Recommendation:* pass-through-plus-margin, set per feature at M5 classification time, reviewed against the $10–$15/Business/month target (§34). **Marks §11/§14 NON-IMPLEMENTATION-READY for any specific feature until resolved.**
2. **Exact default Business monthly spend cap.** *Options:* no cap by default (opt-in only); a conservative platform-wide default (e.g., an amount near the $10–$15 target). *Recommendation:* no cap by default in v1, since no feature ships metered at M1–M4 anyway; revisit at M5. **Marks §15 NON-IMPLEMENTATION-READY for a concrete default value.**
3. **Exact default per-feature limits.** Same shape as item 2 — deferred to M5, per-feature, alongside item 1's rate decision.
4. **Exact auto-recharge default threshold** within RFC-003 §26.2's locked "below $10." *Recommendation:* $5.00. **Marks §19 NON-IMPLEMENTATION-READY for the exact figure.**
5. **Owner/operator complimentary Agency Workspace's metered-usage subsidy.** *Options:* fully subsidized (unlimited promotional credit, auto-issued); partially subsidized (a capped monthly promotional credit); not subsidized (owner pays like any Agency Business). *Recommendation:* a capped, auditable monthly `PromotionalCredit` (§18) — controlled and visible in the ledger, never an invisible bypass — with the exact cap amount itself part of this same open decision.
6. **Invoice/tax/VAT operational provider.** *Options:* Stripe Tax (automated); explicit no-tax v1 with a stated operational boundary. *Recommendation:* explicit no-tax v1 unless the operator has an active near-term compliance need, given Stripe Tax adds its own configuration/cost surface. **Marks §23 NON-IMPLEMENTATION-READY.**
7. **Timing of Agency client rebilling.** *Recommendation:* not before a dedicated future RFC/milestone; the `agency_rebill` payer-type value (§16) exists now purely as a schema seam, never activated by any RFC-005 milestone.
8. **Exact v1 add-on roster and pricing** beyond the one worked example (a purchasable audit, recommended `direct_deliverable`, §18). *Recommendation:* ship with zero seeded `business_usage_addon_catalog` rows at M4 launch and add the first real add-on as a small, separately reviewed follow-up once its exact deliverable and price are decided.
9. **Exact initial per-feature platform safety-limit ceilings.** Deferred to M5 alongside items 1 and 3, since no feature is metered before then.
10. **v1 settlement currency and multi-currency scope** (§10). *Recommendation:* USD only, `decimal_places = 2`; a Business whose `businesses.currency_code` is not `USD` has usage billing gated off until multi-currency is separately designed. **Marks §10's currency-scale resolution NON-IMPLEMENTATION-READY for any Business outside the chosen currency until this is confirmed.**

---

## 40. Contract coverage matrix

Maps every mandatory area from the merged design contract's §5 (A–L) and every human product requirement to the exact section(s) of this RFC that resolve it.

| Contract area / requirement | RFC-005 section(s) |
|---|---|
| A. Scope and terminology | §5, §6 |
| B. Money and accounting invariants | §10, §11, §12 |
| C. Metering and authorization | §14 |
| D. Payer, payment instruments, billing contact | §16, §17 |
| E. Stripe/provider boundary; invoices/tax/receipts; SDK version audit | §20, §21, §23 |
| F. Auto-recharge and usage controls | §15, §19 |
| G. Additional-slot purchase; credits and add-ons | §18, §22 |
| H. Authority and isolation | §24 |
| I. Concurrency, idempotency, events | §29, §31 |
| J. Schema and migration safety | §25, §32 |
| K. HTTP/UI and operational surfaces | §30 |
| L. Testing and release plan | §35, §36, §37, §38 |
| Human requirement 1 — billing contact/config per Business | §17 |
| Human requirement 2 — adjustable monthly usage budget/cap | §15 |
| Human requirement 3 — per-feature limits | §15 |
| Human requirement 4 — platform safety limit overrides customer limit | §15, §24 |
| Human requirement 5 — credits without cross-Business pooling | §18, §32 |
| Human requirement 6 — discrete paid add-ons designed or deferred | §18, §39 item 8 |
| Human requirement 7 — internal unit-economics target, non-retail, non-suspension | §11, §34, §39 item 1 |
| Owner/operator Agency metered-usage subsidy question | §39 item 5 |
| §4 items 1–16 (locked decisions) | §7 (per-item table) |
| Design-contract §6 gap rule (four-category classification) | Applied throughout; open items collected in §39 |

No area in the merged contract's §5 A–L, and no human product requirement, is unaddressed by this table.

---

*End of RFC-005 design document. Every milestone named in §36 requires its own separate, human-reviewed, merged implementation contract before any code, migration, test, route, view, or Stripe/provider change may be written.*
