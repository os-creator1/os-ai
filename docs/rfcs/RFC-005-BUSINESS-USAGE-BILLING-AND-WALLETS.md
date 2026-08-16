# RFC-005 — Business Usage Billing and Wallets

**Status: DRAFT — NOT IMPLEMENTATION-AUTHORIZED**
**Version: 1.2 (Correction Round 2 — final allowed round)**

- Base SHA: `6ae00f8f88b1963c6d05a045f99f0ce42651d2eb` (`main`)
- Governing contract: `docs/automation/RFC-005-DESIGN-CONTRACT.md`, merged commit `186a82393577e9afc240d40b0ad8ade4c99d27d4`
- Merging this design document does **not** authorize RFC-005 Milestone 1 or any implementation, migration, test, route, view, Stripe/provider call, or billing behavior. Every milestone in §36 requires its own separately drafted, human-reviewed, merged implementation contract before any such work may begin.
- This document is written to be implementation-grade: each future milestone contract is expected to **reproduce** the relevant sections below, not redesign them.
- **Implementation readiness, corrected in this round.** Four gates must each be independently satisfied before production payment collection under this design; this round resolves one of them (the recurring provider model) and leaves three open:
  1. **Additional-slot allocation authority** — a structural cross-RFC blocker (below), unresolved. `NON-IMPLEMENTATION-READY`.
  2. **RFC-004 catalog-pricing operator surface** — a repository-confirmed gap (§22), unresolved.
  3. **Production tax/VAT legal sufficiency** — a legal/compliance gate (§23), unresolved; this RFC is not legal advice.
  4. **Recurring additional-slot provider model** — **resolved this round** (§22 now names Option A explicitly, with a full state/proration/dunning design).
  Every other `NON-IMPLEMENTATION-READY` marker in this document (exact rates, exact caps, exact thresholds, and the remaining items collected in §39) is an ordinary open product/commercial decision, resolvable by a human decision alone — distinct from the four structural/legal gates above.

---

## Cross-RFC implementation blocker

**Discovered in the prior correction round. Not resolved here — recorded, with options, for separate human authorization. Preserved unchanged in substance this round; no RFC-004 amendment is authorized or performed by this document.**

RFC-004 requires that additional-Business-slot allocation occur **only** through `EntitlementManager::setAdditionalBusinessSlots(Workspace $workspace, int $count, int $actorUserId, ?string $reason = null): WorkspacePlanAssignment` — the sole authoritative allocation mutation (locked decision 9, RFC-004 §17/§19). That method's first authority check, immediately after acquiring the Workspace row lock, is `$this->assertPlatformAdministrator($actorUserId)` — throwing unless `$actorUserId` resolves to a user with `is_admin = true`. RFC-004 §20/§18 is explicit that ordinary Workspace customers may **inspect** their allocation but may **never self-grant** a slot.

A customer-paid checkout whose webhook-driven completion calls `setAdditionalBusinessSlots()` using the purchasing customer's own action as the trigger has no real platform-administrator `$actorUserId` available to it — that call would fail authorization in production, exactly as RFC-004 designed.

This RFC does **not** invent a fake platform-administrator actor, bypass `EntitlementManager`, pass an unrelated admin's identity, or silently weaken RFC-004's authority check. Concrete options remain:

1. **Customer pays, then a real platform administrator manually reviews and allocates the slot** — via §22's saga (`payment_succeeded` → `allocation_pending` → an explicit admin action, §22/§30, → `completed`). Requires zero RFC-004 change; immediately implementable once §22's saga model exists.
2. **Keep the checkout/platform allocation platform-admin-initiated only** — no customer self-service purchase flow at all in v1.
3. **Recommended: a separate, explicitly human-authorized amendment to RFC-004** introducing a narrowly scoped, payment-proof-backed internal allocation entry point (e.g., `allocateAdditionalBusinessSlotsFromVerifiedPayment()`), preserving `EntitlementManager` as sole allocation authority, requiring a verified idempotent successful-payment record, recording the requesting customer separately from the system/payment actor, writing the existing audit trail unchanged, preventing arbitrary customer self-grant through any other path, and receiving its own contract/tests/review/tag decision entirely separate from this document.

**This RFC-005 design document does not authorize or perform that amendment.** No RFC-005 additional-slot implementation contract (M4, §36) may be drafted until a human explicitly chooses and authorizes one of these options. §22 designs the rest of the additional-slot payment flow in full and is implementation-ready **up to** the allocation step, which remains `NON-IMPLEMENTATION-READY`. Recorded in §39 as open item 14.

---

## 1. Purpose and problem statement

RFC-003 forward-declared, and RFC-004 explicitly deferred, a Business-scoped usage wallet, usage ledger, billing configuration, and monthly usage budget (RFC-003 §8), together with payer selection, auto-recharge, and Stripe usage-billing changes (RFC-003 §26.2; RFC-004 §19, §31). RFC-004 shipped the entitlement structure and reserved exactly one seam for this RFC: `UsageAuthorizationGateway::check(Business $business, PlatformFeature $feature): UsageAuthorizationResult`, called as the final step of `EntitlementManager::decide()`, currently bound to `NullUsageAuthorizationGateway` (always-authorized). RFC-005 designs the system that will eventually bind a real implementation to that seam: a Business-scoped wallet and append-only ledger, a versioned usage-rate catalog, reservation/commit/release for uncertain-cost metered operations, payer selection with Workspace fallback, a billing contact, payment instruments, manual/paid/promotional credits, auto-recharge, additional-Business-slot payment collection, and the Stripe provider boundary — without ever weakening RFC-003/RFC-004's already-locked tenancy, entitlement, or isolation guarantees.

The problem this RFC solves is narrower than "billing" in general: the legacy SMS-plan/subscription billing stack already exists and is explicitly out of scope; RFC-005 is a **new, Business-scoped, Stripe-first, usage-metered** billing system that must coexist with, and never be confused with, that legacy stack.

---

## 2. Governing RFC/contract evidence

This design is bound by, in descending specificity:

1. `docs/automation/RFC-005-DESIGN-CONTRACT.md` (commit `186a823...`) — sixteen inherited locked decisions (its §4), human product requirements, mandatory A–L contents (its §5), the open-decision/gap rule (its §6), and its governance restrictions.
2. RFC-004 (Plans and Business Feature Entitlements) — tag `rfc-004-plans-and-business-feature-entitlements` at `221e18f0...` — specifically §13/§17/§18/§19/§20/§21/§31, `EntitlementManager::setAdditionalBusinessSlots()`'s exact authority gate, and — newly re-read this round — `BusinessManager`/`WorkspaceManager`'s two distinct Business-creation paths and their event-dispatch shapes.
3. RFC-003 (Workspace and Business Account Core) — specifically §4, §8, §14.1, §19, §26.2, §27.
4. `AGENTS.md` — "Workspace authorization" and verification rules.

`docs/automation/AI-AUTONOMY-STATE.json` remains stale/historical and carries no authorization weight; left untouched.

---

## 3. Repository audit findings

**New this round — the sole trigger of correction item 11 (§32):** a direct search for `BusinessCreated::dispatch` across `app/` returns **exactly one** call site: `App\Library\Business\BusinessManager::applyIdentity()`'s CREATE branch (`BusinessManager.php:62`). A second, independent Business-creation code path exists and does **not** go through it: `App\Library\Workspace\WorkspaceManager::createBusinessInWorkspace()` (confirmed by direct read, `WorkspaceManager.php:907-936`) calls the same underlying `$this->businessRepository->createForCustomerInWorkspace(...)` repository method, but dispatches a **different** event, `BusinessAssignedToWorkspace::dispatch($business->id, $lockedWorkspace->id, $actorUserId)`, and never dispatches `BusinessCreated`. **This is a real, repository-confirmed gap**, not a hypothetical one: any single-event listener keyed only to `BusinessCreated` would silently miss every Business created through the Workspace-flow path. §32 designs the fix directly from this evidence.

- **`EntitlementManager::setAdditionalBusinessSlots()`'s authority gate** — re-confirmed unchanged from the prior round: `assertPlatformAdministrator($actorUserId)` is the first authority check after the Workspace lock; no lower-privilege entry point exists. The cross-RFC blocker's evidence is unchanged.
- **`EntitlementManager::decide()`'s exact nine denial keys** — re-confirmed unchanged: `platform_feature_unknown`, `platform_feature_unavailable`, `workspace_plan_unassigned`, `not_entitled_by_plan`, `denied_by_workspace_override`, `disabled_for_business`, `plan_suspended`, `plan_inactive`, `usage_unauthorized`. `decide()`'s own code passes through whatever non-null `$reason` a bound gateway returns — the nine-key discipline remains self-imposed by `RealUsageAuthorizationGateway`, not mechanically enforced by `EntitlementManager` itself.
- **PHP/BCMath availability** — this repository targets PHP 8.2/8.3 (per `CLAUDE.md`'s own description); `bcround()` (PHP 8.4+) is **not** available at this target, confirmed by the stated platform version — §10's rounding algorithm is written to be PHP 8.2/8.3-compatible using only `bcadd`/`bcmul`/`bcdiv`/`bcpow`, never `bcround()`.
- Every other repository fact recorded in the prior round's §3 (Stripe SDK version, Checkout Session precedent, absence of webhook-signature precedent, `businesses.currency_code`, `workspace_plan_catalog.price`'s `decimal(16,2)` shape, the `currencies` table's missing decimal-scale metadata, the actor-column-vs-tenancy-column convention, route-naming convention, `WorkspaceMembership`'s three authorization axes, `config/permissions.php`'s existing categories, the real cross-process concurrency-test pattern, and the `ShouldDispatchAfterCommit`/`ShouldQueueAfterCommit` distinction) remains accurate at this base and is carried forward unchanged.

No new conflict beyond the one recorded in the blocker above was found between the merged design contract, RFC-003, RFC-004, and repository reality.

---

## 4. Goals

1. Give every Business an isolated usage wallet, append-only ledger, billing contact, and payer — never Workspace-pooled, never cross-Business-visible.
2. Meter only explicitly classified features; every non-metered, already-entitled feature continues working with an empty or non-existent wallet.
3. Give the Business, and the platform, independent, non-collapsible controls over spend.
4. Make Stripe the sole v1 payment provider, behind a narrow boundary.
5. Never let this system reuse the legacy multi-gateway `PaymentController`/`invoices`/`subscription_transactions` stack by inference from a similar name.
6. Preserve a seam for Agency client rebilling without building it now.
7. Never invent a customer retail rate, a default cap value, or provider behavior; every commercially significant figure not received as an explicit human product requirement is named as an open decision in §39.
8. Represent the wallet's own accounting state (available, reserved, debt) with a model the ledger can actually reconstruct.
9. Never expand `EntitlementManager::decide()`'s locked nine-key denial surface.
10. **Added this round:** every table, counter, transition, actor, and route this document proposes is fully and consistently specified — no shorthand deferred to "elsewhere" that does not in fact exist elsewhere.
11. **Added this round:** every commercially significant mutable state has a durable, append-only transition history; every charge-causing action requires the actual payer's own consent, never merely "some authorized role."

---

## 5. Non-goals and explicit deferrals

- Agency client rebilling execution (schema/service seam only — §16).
- Any second payment provider besides Stripe (§20).
- Any change to the legacy `Plan`/`Subscription` SMS-quota billing stack, `PaymentController.php`, `PaymentMethods`, `Invoices`, or `SubscriptionTransaction`.
- Automated tax/VAT calculation as a legally-sufficient solution (§23 marks production tax posture `NON-IMPLEMENTATION-READY`).
- Multi-currency wallets in v1 (§39 item 10).
- A concrete v1 add-on roster beyond the schema seam and one worked example (§39 item 8).
- Selecting exact retail rates, exact default caps, or an exact auto-recharge threshold value (§39).
- Actual allocation of a paid additional Business slot through any customer-triggered code path, until the cross-RFC blocker is resolved (§22, §39 item 14).
- Stripe Billing's native Subscription/Invoice object model — **explicitly decided against this round** (§22 chooses Option A: SetupIntent/Checkout-with-saved-instrument plus off-session PaymentIntent renewals, not Stripe's own subscription primitive).

---

## 6. Terminology

- **Business wallet** — the single row per Business holding `available_balance`, `reserved_balance`, `debt_balance`, and the Business's caps/auto-recharge configuration, including its current accounting **period** (§12).
- **Usage account** — informal synonym for a Business's wallet + ledger + rate history taken together; not a separate table.
- **Available balance** — spendable balance: funded balance not currently reserved or owed as debt.
- **Reserved balance** — the sum of open (uncommitted, unreleased, unexpired) reservations.
- **Debt balance** — a non-negative, auditable record of value the wallet owes but has not yet had funded.
- **Period key** — **new this round.** An immutable snapshot, taken once at reservation-creation time, of which accounting period (e.g., `'2026-08'` in the Business's timezone) a reservation — and everything it later commits — belongs to for cap-accounting purposes, regardless of when it actually commits (§15).
- **Payer** — which of Business/Workspace/(later) Agency-rebill funds a Business's wallet.
- **Effective payer / payer consent** — the payer whose instrument a charge-causing action actually debits; only that payer's own authorized actor (or a platform administrator, with mandatory reason) may authorize the action (§16, §24 — corrected this round to apply to every charge-causing action, not only an explicit payer change).
- **Funding source / payment instrument** — a stored Stripe PaymentMethod reference (safe display metadata only), owned via a **provider customer record** (§17.B).
- **Funding attempt** — a durable local record of one attempt to move money between a payer's instrument and a Business's wallet, tracked through Stripe-accurate states.
- **Top-up / Recharge / Charge / Reservation / Capture-commit / Release** — unchanged from the prior round's definitions (§13).
- **Refund / Usage charge reversal / Correction reversal / Chargeback-dispute** — four structurally distinct concepts (§12).
- **Metered feature** — a `PlatformFeature` explicitly classified as cost-bearing.
- **Additional-slot agreement** — the recurring billing relationship for a paid Core/Growth additional Business slot, modeled this round as **customer-present initial authorization with a saved instrument, followed by off-session renewal PaymentIntents** (§22, Option A) — never a Stripe Billing subscription, never a one-time purchase.
- **Claim lease — new this round.** A time-bounded exclusive hold a worker takes on a `payment_provider_events` row while processing it, allowing a crashed worker's stale claim to be safely recovered after the lease expires (§21).

---

## 7. Locked product decisions (inherited — this RFC may only refine, never reopen)

| # | Locked decision | Implemented in |
|---|---|---|
| 1 | Workspace remains the universal tenant container; Agency is a plan tier | §9, §16 |
| 2 | Usage wallets/ledger are Business-scoped | §12 |
| 3 | Default payer: Core/Growth → Workspace; Agency → Business | §16 |
| 4 | Payer concepts: Business/Workspace/later Agency-rebill | §16 |
| 5 | Payer change affects future charges only | §16, §32 |
| 6 | The usage ledger is append-only | §12 |
| 7 | Auto-recharge defaults inherited from RFC-003 §26.2 | §19 |
| 8(a) | Business's own monthly spend budget/cap, distinct from 8(b) | §15 |
| 8(b) | Monthly auto-recharge cap, distinct from 8(a) | §15, §19 |
| 9 | RFC-005 owns payment collection; RFC-004's mutation remains sole allocation authority | §22, cross-RFC blocker |
| 10 | Complimentary Workspace status waives recurring/slot charges, not metered usage | §14, §39 item 5 |
| 11 | Grandfathered complimentary slots never become debt | §22 |
| 12 | Billing state never deactivates a Workspace or hides tenancy | §12, §14, §24 |
| 13 | `PlatformFeatureRegistry::isAvailable()` remains a floor | §14 |
| 14 | Integration is exclusively through `UsageAuthorizationGateway` | §14 |
| 15 | The legacy `Plan`/`Subscription` stack remains untouched | §5, §23 |
| 16 | RFC-003 §8's Business-scoped modules, distinct from 8(b) | §12, §15 |

---

## 8. Human product requirements (supplied for RFC-005, not repository facts)

1. Every Business has its own billing contact and billing configuration → §17.
2. Every Business has an adjustable monthly usage budget/cap → §15.
3. Authorized users can configure per-feature usage limits → §15.
4. A platform safety limit always overrides a customer-configured higher limit → §15.
5. Credits can be added to a specific Business without pooling another's balance → §18, §32.
6. Discrete paid add-ons must be designed or explicitly deferred with justification → §18.
7. Owner's unit-economics target: ~$10–$15/Business/month internal AI/API provider spend, internal cost-control only → §11, §34.
8. Owner/operator complimentary Agency Workspace's metered-usage subsidy is undecided → §39 item 5.

---

## 9. Recommended architecture

Five manager classes (unchanged from the prior round), each with a sole write authority (§28), each `DB::transaction()` + row lock + audit-trail + after-commit event on every mutation: `UsageWalletManager`, `BillingProfileManager`, `PaymentInstrumentManager`, `UsageBillingCheckoutManager`. A narrow interface, `PaymentProviderGateway` (§20), is the entire Stripe boundary, implemented only by `StripePaymentProviderGateway`.

**New this round:** a sixth, minimal component — `App\Listeners\Usage\InitializeUsageWalletForNewBusiness` — subscribed to both `BusinessCreated` and `BusinessAssignedToWorkspace` (§32), calling `UsageWalletManager::initializeForNewBusiness()` idempotently.

Repository-per-table, exactly RFC-004's convention: one contract + one Eloquent implementation per table in §25.

---

## 10. Money representation and currency rules

**Recommendation unchanged: signed 64-bit integer "micro-units"** (1 unit = 1⁄1,000,000 of the currency's major unit), stored in `BIGINT` columns. PHP `float` is forbidden for any persisted or in-flight authoritative money value.

**Wallet buckets and ledger deltas — unchanged from the prior round's corrected model:** three independent, non-negative cached buckets (`available_balance_micro`, `reserved_balance_micro`, `debt_balance_micro`); every ledger entry carries three signed delta columns (`available_delta_micro`, `reserved_delta_micro`, `debt_delta_micro`); each bucket is independently reconstructable as the signed sum of its matching delta column; net wallet position (`available + reserved - debt`) is informational only.

**Exact delta behavior per event — unchanged:** debt-clearing-first credits; reserve/release/commit/overage/refund/chargeback/usage-charge-reversal/correction-reversal deltas exactly as the prior round defined (§12's ledger entry table is authoritative).

**Outstanding debt denies new reservations — unchanged: yes**, evaluated immediately after `billing_status`.

**Currency scale — unchanged:** v1 scopes every Business wallet to exactly one settlement currency (recommendation: USD, `decimal_places = 2`, §39 item 10).

### Exact integer arithmetic — corrected this round with a concrete, PHP 8.2/8.3-compatible algorithm

**Prerequisite, stated explicitly:** this arithmetic requires the `bcmath` PHP extension enabled (a standard, common extension, but not universal by default — the M1 contract confirms it is enabled in the deployment target, mirroring the same caution already applied to MySQL `CHECK` constraints, §25). **`bcround()` is a PHP 8.4+ function and is not available at this repository's confirmed PHP 8.2/8.3 target (§3)** — no algorithm below relies on it.

**Round-half-up via BCMath, PHP 8.2/8.3-compatible, applied only to non-negative magnitudes** (quantity, rate, and every computed charge in this design are non-negative by construction — sign/direction is applied separately via ledger delta assignment, never baked into a rounded magnitude, so no negative-number rounding ambiguity exists anywhere in this design):

```php
/**
 * Round a non-negative bcmath numerator/denominator quotient to $scale
 * decimal places, half-up, without bcround() (unavailable pre-8.4).
 */
function bcRoundHalfUp(string $numerator, string $denominator, int $scale = 0): string
{
    $extraPrecision = $scale + 4;
    $rawQuotient = bcdiv($numerator, $denominator, $extraPrecision);
    $shift = bcpow('10', (string) $scale, 0);
    $shifted = bcmul($rawQuotient, $shift, $extraPrecision);
    // bcadd truncates to the given scale (0) after adding 0.5 — this is
    // exactly round-half-up for a non-negative operand.
    return bcadd($shifted, '0.5', 0);
}
```

**Applied arithmetic:**

- `quantity_micro = bcRoundHalfUp(bcmul($quantity, '1000000', 10), '1', 0)` — the caller-supplied exact quantity (never a PHP `float`; accepted as a decimal string) converted to an integer micro-quantity.
- `charge_micro = bcRoundHalfUp(bcmul($quantity_micro, $retail_rate_micro), '1000000', 0)` — using `bcmul`/`bcdiv` throughout; PHP's native `*`/`/` on values that could exceed the safe integer range is never used, since it can silently produce a float.
- **Overflow/sanity ceiling:** any input `quantity` or resulting `charge_micro` exceeding `10^15` micro-units (≈ $1 billion at the v1 USD scale — a category-3 recommendation, far below `BIGINT`'s real ~`9.2 × 10^18` ceiling) is rejected before computation, with the stable reason `quantity_exceeds_sanity_ceiling`.
- **Rounding point:** `bcRoundHalfUp()` is applied exactly once, to the final `charge_micro` — never to an intermediate product, never re-applied on read.
- **Stripe-cent conversion:** `stripe_amount_cents = bcRoundHalfUp($retail_amount_micro, '10000', 0)` for the v1 USD/2-decimal scale. An amount whose rounded cent-equivalent is `0` for a genuinely positive charge is rejected before an outbound Stripe call (`amount_below_provider_minimum`).

**Stripe minimum/maximum payment handling — corrected this round: the prior round's "effectively unbounded" maximum claim is withdrawn.** Stripe's PaymentIntent `amount` currently supports up to **eight digits** in the currency's smallest unit (i.e., for a 2-decimal currency, up to `99,999,999` minor units ≈ `$999,999.99`) — a real, currently-documented Stripe API constraint, not an assumption. `UsageBillingCheckoutManager` validates every outbound top-up/checkout/recharge/renewal amount against **both** Stripe's documented minimum (on the order of $0.50 for USD) **and** this eight-digit maximum, for the pinned Stripe API version (§20) and the wallet's settlement currency, **before** calling `PaymentProviderGateway` — returning `amount_below_provider_minimum` or `amount_exceeds_provider_maximum` rather than allowing the Stripe API call itself to fail. **The exact numeric minimum/maximum values are re-confirmed against Stripe's own current documentation for whichever API version is actually pinned at M3 implementation time** — this RFC records the currently-known constraint shape, not a value frozen forever; a future Stripe change to these limits is the M3 contract's own responsibility to re-verify, not silently assumed unchanging by this design. This check applies only to amounts actually sent to Stripe — never to an internal per-call metered ledger charge, which may be arbitrarily small in micro-units since it is never itself submitted to Stripe individually.

---

## 11. Rate catalog, customer charges, provider costs, and rate snapshots

**Corrected in this round: rate rows are fully immutable, activation is resolved via a lockable classification-row pointer, and historical lookup is now deterministic under a timestamp tie.**

**`business_usage_rates`** (fully immutable; every row, once inserted, is never updated):

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `feature_key` | `string(64)` | No | — | `PlatformFeature`-backed value |
| `version` | `unsigned int` | No | — | starts at 1 per `feature_key`, computed under the classification-row lock |
| `retail_rate_micro` | `bigint unsigned` | No | — | customer-facing price per unit |
| `provider_cost_micro` | `bigint unsigned` | No | — | internal estimated provider cost per unit — admin-only (§34) |
| `unit_label` | `string(64)` | No | — | e.g. `"per message"` |
| `rounding_rule` | `string(32)`, enum-backed | No | `round_half_up` | only value defined for v1 |
| `currency_id` | `unsignedBigInteger`, FK `currencies.id`, `restrictOnDelete()` | No | — | must match the wallet's v1 settlement currency |
| `created_by_user_id` | `unsigned bigint`, no FK | No | — | actor column convention |
| `created_at` | `timestamp` | No | `now()` | the only timestamp this table has — no `updated_at`; nothing on this row is ever mutated |

Indexes: `UNIQUE (feature_key, version)`. Sole write authority: `UsageWalletManager`.

**`business_usage_rate_activations`** — append-only, the sole record of "what rate was active for a feature, and when":

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK) | No | — | |
| `feature_key` | `string(64)` | No | — | indexed |
| `rate_id` | `unsignedBigInteger`, FK `business_usage_rates.id`, `restrictOnDelete()` | No | — | |
| `activated_at` | `timestamp` | No | `now()` | |
| `activated_by_user_id` | `unsigned bigint`, no FK | No | — | |
| `reason` | `text` | No | — | mandatory |
| `created_at` | `timestamp` | No | `now()` | |

**Deterministic historical lookup — new this round.** "What rate was active for feature X at time T" is answered by: `SELECT ... WHERE feature_key = X AND activated_at <= T ORDER BY activated_at DESC, id DESC LIMIT 1`. **`id` (a monotonic auto-increment sequence) is the explicit, required tiebreaker** whenever two activation rows share the same `activated_at` value (possible under coarse database timestamp precision even though the classification-row lock, below, always serializes the writes themselves) — never left to an unspecified/database-default ordering.

**`UsageWalletManager::setActiveRate()` — the race-free algorithm, unchanged from the prior round:**

1. `DB::transaction()`.
2. Lock `platform_feature_usage_classifications`' row for `feature_key` (`findForUpdate()`) — this row **always exists** post-backfill.
3. Compute the next `version` under the same lock.
4. Insert the new, immutable `business_usage_rates` row.
5. Insert a `business_usage_rate_activations` row.
6. Update **only** the classification row's `active_rate_id` pointer.
7. Commit.

**Metering activation** (`UsageWalletManager::activateMetering()`) combines `setActiveRate()` with setting `is_metered = true`, in one transaction, requiring an active rate, a supported currency, an already-configured platform safety limit, and a mandatory actor/reason — **and, new this round, also inserts a `platform_feature_usage_classification_transitions` row (§14.1) recording the `is_metered` flip itself, independently of the rate-activation record**, per the explicit requirement that metering activation/deactivation be audited separately from immutable rate activation.

**Snapshotting — unchanged:** every reservation and every final ledger entry involving a rate stores its own denormalized copies of `rate_id`, `rate_version`, `retail_rate_micro`, `provider_cost_micro`, `unit_label`, `rounding_rule`, `currency_id`, and `quantity`.

**Cost/margin visibility — unchanged:** `provider_cost_micro` is readable only by the platform-administrator authorization path (§24), enforced by a boundary test (§35).

---

## 12. Business wallet and append-only ledger invariants

**`business_usage_wallets`** — one row per Business:

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK) | No | — | |
| `business_id` | `unsignedBigInteger`, FK `businesses.id`, unique, `restrictOnDelete()` | No | — | 1:1, Business-scoped |
| `currency_id` | `unsignedBigInteger`, FK `currencies.id`, `restrictOnDelete()` | No | — | wallet's single settlement currency |
| `available_balance_micro` | `bigint` | No | `0` | never negative (manager-enforced) |
| `reserved_balance_micro` | `bigint` | No | `0` | never negative |
| `debt_balance_micro` | `bigint` | No | `0` | never negative |
| `monthly_spend_cap_micro` | `bigint`, nullable | Yes | `NULL` | null = platform-safety-limit-bounded only |
| `spend_period_key` | `string(7)`, nullable | Yes | `NULL` | **new this round** — e.g. `'2026-08'`, in the Business's configured timezone; the wallet's currently-cached period identity (§15) |
| `spend_period_start_utc` / `spend_period_end_utc` | `timestamp` | No | set at wallet creation | exact UTC instants bounding `spend_period_key` |
| `auto_recharge_enabled` | `boolean` | No | `false` | |
| `auto_recharge_threshold_micro` | `bigint`, nullable | Yes | `NULL` | required if enabled |
| `auto_recharge_amount_micro` | `bigint`, nullable | Yes | `NULL` | required if enabled |
| `monthly_recharge_cap_micro` | `bigint`, nullable | Yes | `NULL` | distinct from `monthly_spend_cap_micro` |
| `recharge_period_key` / `recharge_period_start_utc` / `recharge_period_end_utc` | see above | — | — | independent period identity from the spend cap's |
| `committed_spend_this_period_micro` | `bigint` | No | `0` | cached, **current-period-only** (§15) |
| `reserved_spend_this_period_micro` | `bigint` | No | `0` | cached, **current-period-only** (§15) |
| `recharged_this_period_micro` | `bigint` | No | `0` | cached, **current-period-only** (§15) |
| `consecutive_recharge_failures` | `unsigned smallint` | No | `0` | **new this round** — the actual schema backing §19's disable-after-N-failures rule; incremented on each `failed`/`requires_action` auto-recharge funding-attempt outcome, reset to `0` on any `succeeded` outcome |
| `low_balance_notified_at` | `timestamp`, nullable | Yes | `NULL` | dedup window (§19) |
| `billing_status` | `string(16)`, enum-backed | No | `active` | `active` \| `suspended` |
| `created_at` / `updated_at` | `timestamp` | No | `now()` | the one legitimately mutable RFC-005 table |

Indexes: `UNIQUE (id, business_id)` — enables the composite foreign key on child tables (below).

**`billing_status` mechanism — unchanged:** `active → suspended` set automatically on a `DisputeChargeback` entry, or explicitly by a platform administrator (mandatory reason); `suspended → active` is admin-only, mandatory reason, never automatic. Every transition is recorded in **`business_usage_wallet_billing_status_transitions`**:

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK) | No | — | |
| `wallet_id` / `business_id` | FK, composite-protected (below) | No | — | |
| `from_status` / `to_status` | `string(16)` | No | — | |
| `source` | `string(24)`, enum-backed | No | — | `dispute_webhook` \| `admin_action` |
| `actor_user_id` | `unsigned bigint`, nullable, no FK | Yes | `NULL` | null for `dispute_webhook` |
| `reason` | `text` | No | — | mandatory |
| `created_at` | `timestamp` | No | `now()` | |

`billing_status` remains entirely distinct from `workspace_plan_assignments.status`.

Sole write authority: `UsageWalletManager`.

**Tenancy-ID integrity ("composite-protected") — unchanged mechanism, scope corrected this round.** Every child table that carries **both** `business_id` and `wallet_id` declares a composite foreign key `(wallet_id, business_id) → business_usage_wallets(id, business_id)`. **Corrected this round: the prior draft mislabeled several `business_id`-only tables (no `wallet_id` column at all) as "composite-protected." That wording is removed from every table that does not carry both columns** — `business_feature_usage_limits`, `business_billing_contacts`, `business_payer_assignments`, `business_usage_addon_purchases`, and `business_billing_receipts` each carry a plain `business_id` FK (`restrictOnDelete()`) only, with no composite protection to describe, since there is no second, redundant tenancy column on those tables to protect against disagreeing with the first. Tables that genuinely carry both columns and remain composite-protected: `business_usage_ledger_entries`, `business_usage_reservations`, `business_usage_wallet_billing_status_transitions`, `business_funding_attempts`.

**`business_usage_ledger_entries`** — append-only, never updated or deleted after insert:

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK) | No | — | |
| `business_id` / `wallet_id` | FK, composite-protected | No | — | |
| `entry_type` | `string(32)`, enum-backed | No | — | twelve values, see table below |
| `available_delta_micro` | `bigint` (signed) | No | `0` | |
| `reserved_delta_micro` | `bigint` (signed) | No | `0` | |
| `debt_delta_micro` | `bigint` (signed) | No | `0` | |
| `gross_amount_micro` | `bigint unsigned`, nullable | Yes | `NULL` | informational only, never authoritative |
| `currency_id` | `unsignedBigInteger`, FK `currencies.id`, `restrictOnDelete()` | No | — | |
| `feature_key` | `string(64)`, nullable | Yes | `NULL` | usage-related entries only |
| `period_key` | `string(7)`, nullable | Yes | `NULL` | **new this round** — for `UsageCharge`/`UsageOverageCharge` entries, copied from the originating reservation's own `period_key` (§15); null for entries with no reservation origin |
| `quantity` | `decimal(14,6)`, nullable | Yes | `NULL` | the caller-supplied exact quantity |
| `rate_id` / `rate_version` / `retail_rate_micro` / `provider_cost_micro` / `unit_label` / `rounding_rule` | see §11 | Yes | `NULL` | populated for rate-involving entries only |
| `reservation_id` | `unsignedBigInteger`, FK `business_usage_reservations.id`, nullable | Yes | `NULL` | |
| `funding_attempt_id` | `unsignedBigInteger`, FK `business_funding_attempts.id`, nullable | Yes | `NULL` | set for `PaidTopUp`/`AutoRecharge` entries |
| `correlation_key` | `string(191)`, unique | No | — | idempotency |
| `provider_reference` | `string(191)`, nullable | Yes | `NULL` | Stripe object id, when applicable |
| `actor_user_id` | `unsigned bigint`, nullable, no FK | Yes | `NULL` | null = system-generated |
| `reason` | `text`, nullable | Yes | `NULL` | mandatory (manager-enforced) for `ManualCredit`, `UsageChargeReversal`, `CorrectionReversal`, `Refund` |
| `reversed_entry_id` | `unsignedBigInteger`, self-referencing FK, nullable, `restrictOnDelete()` | Yes | `NULL` | set on `UsageChargeReversal`/`CorrectionReversal` rows |
| `created_at` | `timestamp` | No | `now()` | immutable; the only timestamp this table has |

**Entry types and their delta behavior — twelve types, unchanged in shape from the prior round:**

| `entry_type` | `available_delta` | `reserved_delta` | `debt_delta` | When |
|---|---|---|---|---|
| `PaidTopUp` | `+remainder after debt-clear` | `0` | `-min(amt, debt)` | customer-initiated top-up succeeds |
| `AutoRecharge` | `+remainder after debt-clear` | `0` | `-min(amt, debt)` | auto-recharge succeeds |
| `ManualCredit` | `+remainder after debt-clear` | `0` | `-min(amt, debt)` | admin/customer adds credit |
| `PromotionalCredit` | `+remainder after debt-clear` | `0` | `-min(amt, debt)` | promotional/complimentary credit |
| `Reservation` | `-amt` | `+amt` | `0` | reserve step |
| `ReservationRelease` | `+amt` | `-amt` | `0` | release/expiry, no charge |
| `UsageCharge` | `0` | `-committed_portion` | `0` | commit step, within the reserved amount |
| `UsageOverageCharge` | `-min(overage, avail)` | `0` | `+max(0, overage-avail)` | actual cost exceeds the reservation |
| `Refund` | `-min(amt, avail)` | `0` | `+max(0, amt-avail)` | money returned to the payer |
| `DisputeChargeback` | `-min(amt, avail)` | `0` | `+max(0, amt-avail)` | provider clawback; also sets `billing_status = 'suspended'` |
| `UsageChargeReversal` | `+amt` | `0` | `0` | admin reverses a prior usage charge into wallet credit; no real money moves |
| `CorrectionReversal` | signed by context | signed by context | signed by context | corrects an erroneous prior entry |

**Centralized auto-recharge trigger — corrected this round (§19).** Every ledger-entry insert flows through one shared internal `UsageWalletManager` method; that method dispatches `EvaluateBusinessAutoRecharge` after commit whenever the entry's own `available_delta_micro < 0` — this rule is now structural (baked into the one shared insert path), not an enumerated, easy-to-miss list of call sites, and correctly includes `Reservation` (the prior round's missed case) alongside `UsageOverageCharge`/`Refund`/`DisputeChargeback`/negative `CorrectionReversal`, while correctly excluding a normal within-reservation `UsageCharge` (`available_delta = 0`) and `ReservationRelease` (`available_delta > 0`). The same shared method also **clears `low_balance_notified_at`** whenever an entry's `available_delta_micro > 0` brings `available_balance_micro` back above `auto_recharge_threshold_micro`.

Each of the three wallet buckets remains an always-consistent cached aggregate of its matching delta column. No cross-Business or cross-currency transfer is ever expressible.

---

## 13. Reservation/commit/release/reversal state machines

**`business_usage_reservations`** (composite-FK protected on `(wallet_id, business_id)`):

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK) | No | — | |
| `business_id` / `wallet_id` | FK, composite-protected | No | — | |
| `feature_key` | `string(64)` | No | — | |
| `period_key` | `string(7)` | No | — | **new this round** — snapshotted once at creation from the wallet's then-current `spend_period_key` (§15); immutable for this reservation's lifetime |
| `status` | `string(16)`, enum-backed | No | `pending` | `pending` \| `committed` \| `released` \| `expired` |
| `reserved_amount_micro` | `bigint` | No | — | |
| `estimated_quantity` | `decimal(14,6)`, nullable | Yes | `NULL` | |
| `rate_id` / `rate_version` / `retail_rate_micro` / `provider_cost_micro` / `rounding_rule` | see §11 | No | — | snapshot at reservation time |
| `idempotency_key` | `string(191)`, unique | No | — | caller-supplied |
| `correlation_key` | `string(191)` | No | — | ties `Reservation`/`ReservationRelease`/`UsageCharge`/`UsageOverageCharge` rows together |
| `reserved_at` | `timestamp` | No | `now()` | |
| `expires_at` | `timestamp` | No | — | operation-defined TTL |
| `committed_at` / `released_at` | `timestamp`, nullable | Yes | `NULL` | exactly one set on a terminal row |
| `final_quantity` / `final_amount_micro` | nullable | Yes | `NULL` | set only on commit |

Once `status` is `committed`, `released`, or `expired`, the row is never reopened.

**Algorithms, corrected this round for the `period_key` and centralized-trigger rules:**

- **Reserve** — `UsageWalletManager::reserve()`. `DB::transaction()`, wallet row `findForUpdate()`. **The wallet's period is lazily rolled over first (§15), then `period_key` is read from the now-current `spend_period_key` and stamped onto the new reservation immutably.** Idempotency: caller-supplied `idempotency_key`. Steps: look up the active rate → compute `reserved_amount_micro` (§10's exact arithmetic) → evaluate, in order: `billing_status` → `outstanding_debt` → per-feature limit → Business spend cap → platform safety limit → available-balance sufficiency → insert the reservation row → insert the `Reservation` ledger entry (period-keyed) → update wallet aggregates, including `reserved_spend_this_period_micro += reserved_amount_micro` (always valid, since a brand-new reservation's `period_key` always equals the just-rolled-over current period) → dispatch `EvaluateBusinessAutoRecharge` after commit (the centralized trigger, §12). Result: the reservation id, or a stable denial reason.
- **Commit/finalize** — `UsageWalletManager::commit()`. Wallet row `findForUpdate()`. Steps: load the `pending` reservation → compare `final_amount_micro` against `reserved_amount_micro` (using the reservation's own snapshotted rate) → insert `UsageCharge` (period-keyed from the reservation) for `min(final, reserved)` → if `final > reserved`, additionally insert `UsageOverageCharge` (same `period_key`) → if `final < reserved`, additionally insert `ReservationRelease` for the unused portion → mark `committed` → **update `committed_spend_this_period_micro` and decrement `reserved_spend_this_period_micro` ONLY IF the reservation's `period_key` equals the wallet's CURRENT `spend_period_key`** — a stale reservation from an already-rolled-over period commits its ledger entry with its own correct historical `period_key` (a permanent, queryable financial fact) but does **not** perturb the wallet's current-period cached counters, which never represented that older period in the first place. Idempotency: repeat commit on an already-`committed` reservation is a no-op.
- **Release** — `UsageWalletManager::release()`. Same current-period-only counter-decrement rule as commit.
- **Reservation expiry reconciliation** — `App\Jobs\Usage\ExpireStaleUsageReservations` finds `pending` reservations past `expires_at` and calls `release()`. Never auto-commits a stale reservation.

**Atomicity / no double-charge — unchanged.**

---

## 14. Metered-feature classification and usage authorization

**Unchanged in mechanism from the prior round:** `RealUsageAuthorizationGateway::check()` is a coarse, non-mutating gate, always returning exactly `usage_unauthorized` externally on denial, delegating internally to `UsageWalletManager::evaluateCoarseCapacity()`, which returns an internal-only `UsageCapacityDecision` (`wallet_unavailable`, `unsupported_currency`, `billing_suspended`, `outstanding_debt`, `insufficient_available_balance`, `monthly_spend_cap_exceeded`, `feature_limit_exceeded`, `platform_safety_limit_exceeded`) — never surfaced past the gateway boundary.

**14.1 Feature classification.** `platform_feature_usage_classifications` — full schema, corrected this round (was shorthand-only):

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK) | No | — | |
| `feature_key` | `string(64)`, unique | No | — | `PlatformFeature`-backed |
| `is_metered` | `boolean` | No | `false` | |
| `active_rate_id` | `unsignedBigInteger`, FK `business_usage_rates.id`, nullable, `restrictOnDelete()` | Yes | `NULL` | sole pointer §11's activation algorithm maintains |
| `updated_by_user_id` | `unsigned bigint`, no FK | No | — | |
| `created_at` / `updated_at` | `timestamp` | No | `now()` | mutable only via `setActiveRate()`/`activateMetering()` |

Backfill: one row per existing `PlatformFeature` case, `is_metered = false`, `active_rate_id = null`. Sole write authority: `UsageWalletManager`.

**`platform_feature_usage_classification_transitions`** — new this round, append-only, tracking metering activation/deactivation **independently from** rate activation (§11's `business_usage_rate_activations` remains the rate-specific record; this table is the metering-on/off record):

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK) | No | — | |
| `feature_key` | `string(64)` | No | — | |
| `from_is_metered` / `to_is_metered` | `boolean` | No | — | |
| `from_active_rate_id` / `to_active_rate_id` | `unsignedBigInteger`, nullable, FK `business_usage_rates.id` | Yes | `NULL` | recorded for context, never this table's own authority (§11 remains authoritative for rate activation itself) |
| `actor_user_id` | `unsigned bigint`, no FK | No | — | |
| `reason` | `text` | No | — | mandatory |
| `created_at` | `timestamp` | No | `now()` | |

---

## 15. Monthly Business budget, per-feature limits, and platform safety limits

Three genuinely distinct, non-collapsible controls:

1. **Monthly Business spend cap** (`business_usage_wallets.monthly_spend_cap_micro`).
2. **Per-feature limits** — `business_feature_usage_limits`, full schema (composite-protected wording removed this round — this table carries `business_id` only, no `wallet_id`):

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK) | No | — | |
| `business_id` | `unsignedBigInteger`, FK `businesses.id`, `restrictOnDelete()` | No | — | plain FK — no `wallet_id` column on this table |
| `feature_key` | `string(64)` | No | — | |
| `monthly_limit_micro` | `bigint`, nullable | Yes | `NULL` | |
| `updated_by_user_id` | `unsigned bigint`, no FK | No | — | |
| `created_at` / `updated_at` | `timestamp` | No | `now()` | |

Indexes: `UNIQUE (business_id, feature_key)`.

3. **Platform safety limit** — `platform_feature_usage_safety_limits`, full schema:

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK) | No | — | |
| `feature_key` | `string(64)`, unique | No | — | platform-scoped, not Business-scoped |
| `max_monthly_limit_micro` | `bigint` | No | — | |
| `updated_by_user_id` | `unsigned bigint`, no FK | No | — | |
| `created_at` / `updated_at` | `timestamp` | No | `now()` | |

**`business_usage_limit_transitions`** — full schema, corrected this round:

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK) | No | — | |
| `business_id` | `unsignedBigInteger`, FK `businesses.id`, nullable, `restrictOnDelete()` | Yes | `NULL` | null only for `limit_type = platform_safety_limit` rows |
| `limit_type` | `string(24)`, enum-backed | No | — | `business_spend_cap` \| `feature_limit` \| `platform_safety_limit` |
| `feature_key` | `string(64)`, nullable | Yes | `NULL` | set only for `feature_limit`/`platform_safety_limit` rows |
| `from_value_micro` / `to_value_micro` | `bigint`, nullable | Yes | `NULL` | |
| `actor_user_id` | `unsigned bigint`, no FK | No | — | |
| `reason` | `text` | No | — | mandatory |
| `created_at` | `timestamp` | No | `now()` | |

**Evaluation order — unchanged:** structural entitlement → Business toggle → `billing_status` → `outstanding_debt` → per-feature limit → Business monthly spend cap → platform safety limit → (reserve path only) available-balance sufficiency.

### Period accounting — fully resolved this round (was self-contradictory in the prior round)

**The exact policy: a reservation's period assignment is fixed permanently at creation time, via `period_key` (§13); everything that reservation later commits is attributed to that same period, never the period the commit itself happens in.** This resolves the prior round's contradiction (resetting `reserved_spend_this_period_micro` to zero at rollover, while also claiming it "reconciles against open reservations" — a claim that only holds if every open reservation belongs to the current period, which is false for a reservation still open across a rollover).

- **Which period owns a reservation and its eventual committed spend:** the period the reservation was **created** in (`period_key`, snapshotted once, immutable) — never the period it happens to commit or release in.
- **What period snapshot is stored where:** `business_usage_reservations.period_key` (§13) and `business_usage_ledger_entries.period_key` (§12, copied from the originating reservation for `UsageCharge`/`UsageOverageCharge` rows).
- **How current-period counters change:** `reserve()` always increments `reserved_spend_this_period_micro` (a new reservation's `period_key` always equals the just-rolled-over current period, by construction). `commit()`/`release()`/expiry decrement `reserved_spend_this_period_micro` and adjust `committed_spend_this_period_micro` **only when** the reservation's own `period_key` still equals the wallet's current `spend_period_key` — a stale reservation terminating late does not touch the current period's cached counters, since it was never part of them.
- **How rollover handles still-open prior-period reservations:** it does nothing to them. They remain `pending`, fully valid, continuing to hold real `reserved_balance_micro`; they simply no longer contribute to the wallet's *current-period* cached counters (which only ever track the current period) — their prior admission was already validated against their own period's cap at creation time and is not re-litigated.
- **How reconciliation reconstructs the counters:** `committed_spend_this_period_micro` = `SUM(available_delta contributions of UsageCharge/UsageOverageCharge WHERE period_key = wallet's current spend_period_key)`; `reserved_spend_this_period_micro` = `SUM(reserved_amount_micro) WHERE business_usage_reservations.status = 'pending' AND period_key = wallet's current spend_period_key`. Both independently recomputable from the ledger/reservations tables alone, at any time, with no dependency on the cached values themselves.
- **Multi-month dormancy — corrected this round.** The lazy rollover check is no longer a single "one period forward" step (which would leave a wallet dormant for 3 periods still 2 periods behind). It computes `periods_elapsed = floor((now() - period_start) / period_length)` **arithmetically, in one step**, then sets `new_period_start = period_start + periods_elapsed × period_length`, `new_period_end = new_period_start + period_length` — a single `UPDATE`, landing exactly in the current period regardless of how many periods were skipped, never a loop of per-period writes.
- **A Business timezone change affects the next period only** — unchanged.
- The scheduled `AdvanceUsagePeriodBoundaries` job remains optional proactive maintenance only, never required for correctness.

**Override/adjustment — unchanged:** mandatory actor/reason, recorded in `business_usage_limit_transitions`.

**Isolation test requirement — unchanged.**

---

## 16. Payer selection and Workspace fallback

**Corrected this round: consent now gates every charge-causing action, not only an explicit payer change (§24 extends this consistently).**

**`business_payer_assignments`** — full schema, corrected this round (composite-protected wording removed — no `wallet_id` on this table):

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK) | No | — | |
| `business_id` | `unsignedBigInteger`, FK `businesses.id`, unique, `restrictOnDelete()` | No | — | plain FK, no `wallet_id` |
| `payer_type` | `string(16)`, enum-backed | No | — | `business` \| `workspace` \| `agency_rebill` (never activated in v1) |
| `effective_payment_instrument_id` | `unsignedBigInteger`, FK `business_payment_instruments.id`, nullable, `restrictOnDelete()` | Yes | `NULL` | starts null at backfill/creation regardless of `payer_type` default (§32) |
| `created_at` / `updated_at` | `timestamp` | No | `now()` | |

**`business_payer_transitions`** — full schema:

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK) | No | — | |
| `business_id` | `unsignedBigInteger`, FK `businesses.id`, `restrictOnDelete()` | No | — | |
| `from_payer_type` / `to_payer_type` | `string(16)` | No | — | |
| `from_instrument_id` / `to_instrument_id` | `unsignedBigInteger`, nullable, FK `business_payment_instruments.id` | Yes | `NULL` | |
| `actor_user_id` | `unsigned bigint`, no FK | No | — | |
| `reason` | `text` | No | — | mandatory |
| `created_at` | `timestamp` | No | `now()` | |

**Payer-consent rules — unchanged from the prior round:** setting `payer_type = 'workspace'` requires the Workspace owner or a platform administrator (mandatory reason); setting `payer_type = 'business'` requires the direct Business owner/customer or a platform administrator (mandatory reason); Active Workspace Admin and Staff can never change payer in either direction; no actor may select/charge an instrument owned by a different payer than the one being set; plan-tier defaults are system-authored at backfill/creation but never auto-attach an instrument or authorize a charge.

### Consent extended to every charge-causing action — new this round (resolving the gap in §24's authority table)

**The prior round's authorization table gated "who may change payer" correctly, but still let a Workspace owner/Admin initiate a top-up or configure auto-recharge even when the *current* effective payer is Business-owned, and vice versa.** Corrected: **any action that causes an actual charge attempt against a stored instrument — initiating a top-up, enabling or changing auto-recharge configuration (threshold/amount/cap/enabled flag), or initiating an add-on-purchase checkout — requires the actor to hold the *same* target-consent authority §16 already defines for payer changes, evaluated against the wallet's *current* `payer_type`, not a static role grant:**

- If `payer_type = 'workspace'`: only the Workspace owner, or a platform administrator (mandatory reason), may initiate a charge-causing action. Active Workspace Admin, Staff, and the direct Business owner may **not** — even though the direct Business owner can still view/configure non-payment settings for their own Business (spend cap, per-feature limits, notification preferences — none of which themselves cause a charge).
- If `payer_type = 'business'`: only the direct Business owner/customer, or a platform administrator (mandatory reason), may initiate a charge-causing action. Workspace owner/Admin may **not**.
- **Active Workspace Admin and Staff may never authorize a stored-instrument charge, under any `payer_type`** — extending the "never, either direction" rule already locked for payer changes themselves.
- **A platform administrator may initiate a charge-causing action on behalf of either payer type, but only with a mandatory reason** — an explicit, audited support/override capability, never treated as itself constituting the payer's own consent; its purpose is narrow support scenarios (e.g., manually completing a stuck payment on a customer's behalf), not a routine path around consent.
- This distinction — viewing/configuring non-payment limits versus authorizing money movement — is structural in this design: `UsageWalletManager::setFeatureLimit()`/`setSpendCap()` (no charge caused) are gated by the existing broader Business-scope authority (§24, unchanged rows); `UsageBillingCheckoutManager::initiateTopUp()`/`configureAutoRecharge()`/`initiateAddonPurchase()` (each causes or enables a charge) are gated by the new payer-consent rule above.

**Effective payer resolution algorithm — unchanged.**

**Payer change algorithm — `BillingProfileManager::changePayer()` — unchanged.**

---

## 17. Billing contact and payment instruments

### A. Billing contact — full schema (was shorthand-only)

**`business_billing_contacts`** (composite-protected wording removed this round — no `wallet_id` on this table):

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK) | No | — | |
| `business_id` | `unsignedBigInteger`, FK `businesses.id`, unique, `restrictOnDelete()` | No | — | plain FK |
| `contact_user_id` | `unsignedBigInteger`, FK `users.id`, nullable, `restrictOnDelete()` | Yes | `NULL` | nullable specifically to support independent contact data |
| `contact_name` / `contact_email` | `string(191)`, nullable | Yes | `NULL` | required together if `contact_user_id` is null (manager-enforced) |
| `notification_opt_in` | `boolean` | No | `true` | |
| `updated_by_user_id` | `unsigned bigint`, no FK | No | — | |
| `created_at` / `updated_at` | `timestamp` | No | `now()` | |

Sole write authority: `BillingProfileManager`. Privacy/isolation and notification-recipient-selection rules unchanged from the prior round.

### B. Provider customers and payment instruments — corrected this round for uniqueness and detach/replace lifecycle

**Corrected: the prior round's `UNIQUE(provider, business_id)`/`UNIQUE(provider, workspace_id)` constraints, together with an owner-row's `status` permitting `detached`, created an unenforceable contradiction — a genuinely `detached` owner could never get a fresh `active` provider-customer row, since the unique index doesn't care about `status`, only that the owner id is non-null and already used.** Resolved with generated columns, the standard MySQL pattern for a partial/filtered unique index:

**`payment_provider_customers`:**

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK) | No | — | |
| `provider` | `string(16)`, enum-backed | No | `stripe` | |
| `business_id` | `unsignedBigInteger`, FK `businesses.id`, nullable, `restrictOnDelete()` | Yes | `NULL` | exactly one of `business_id`/`workspace_id` set (manager-enforced; `CHECK` where MySQL 8+ confirmed) |
| `workspace_id` | `unsignedBigInteger`, FK `workspaces.id`, nullable, `restrictOnDelete()` | Yes | `NULL` | |
| `provider_customer_id` | `string(191)` | No | — | the Stripe Customer id |
| `status` | `string(16)`, enum-backed | No | `active` | `active` \| `detached` |
| `active_business_id` | `unsignedBigInteger`, nullable, **generated/stored**: `CASE WHEN status = 'active' THEN business_id ELSE NULL END` | Yes | — | **new this round** — the actual unique-index target |
| `active_workspace_id` | `unsignedBigInteger`, nullable, **generated/stored**: `CASE WHEN status = 'active' THEN workspace_id ELSE NULL END` | Yes | — | **new this round** |
| `created_at` / `updated_at` | `timestamp` | No | `now()` | mutable — `status` may change; `business_id`/`workspace_id` themselves are **never** cleared on detach, preserving the historical owner mapping permanently |

Indexes, corrected this round:
- `UNIQUE (provider, provider_customer_id)` — **new this round**, per the explicit requirement — the same Stripe Customer id is never attached to more than one owner row, ever, across the table's full history.
- `UNIQUE (provider, active_business_id)` and `UNIQUE (provider, active_workspace_id)` — **replace** the prior round's `UNIQUE (provider, business_id)`/`UNIQUE (provider, workspace_id)`. Because a detached row's generated `active_business_id`/`active_workspace_id` is `NULL` (and MySQL's unique index permits unlimited `NULL`s), at most one **active** provider-customer row can ever exist per `(provider, owner)` at a time — while the owner's full detached history remains permanently intact via the never-cleared `business_id`/`workspace_id` columns. This is the coherent lifecycle the correction requires: exactly one active customer per owner, enforced; every historical mapping preserved, never destroyed.

**`business_payment_instruments`** — corrected this round with its own provider-scoped uniqueness:

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK) | No | — | |
| `provider_customer_id` | `unsignedBigInteger`, FK `payment_provider_customers.id`, `restrictOnDelete()` | No | — | ownership derives transitively |
| `provider` | `string(16)`, enum-backed | No | `stripe` | **new this round** — denormalized from the parent `payment_provider_customers.provider`, purely to support the direct uniqueness constraint below without a join |
| `provider_payment_method_id` | `string(191)` | No | — | Stripe PaymentMethod id — token reference only |
| `type` | `string(24)`, enum-backed | No | — | e.g. `card` |
| `brand` | `string(24)`, nullable | Yes | `NULL` | safe display metadata only |
| `last_four` | `string(4)`, nullable | Yes | `NULL` | |
| `expiry_month` / `expiry_year` | `unsigned tinyint` / `unsigned smallint`, nullable | Yes | `NULL` | card only |
| `is_default` | `boolean` | No | `false` | one-default-per-provider-customer (§17.B, unchanged locking algorithm) |
| `status` | `string(16)`, enum-backed | No | `active` | `active` \| `detached` |
| `created_at` / `detached_at` | `timestamp` / nullable | No / Yes | `now()` / `NULL` | never deleted — detach only |

Indexes, new this round: `UNIQUE (provider, provider_payment_method_id)` — the same Stripe PaymentMethod id is never attached under two different instrument rows, platform-wide, for that provider.

**One-default-instrument serialization, detach behavior, and the ownership-authority split — unchanged from the prior round.**

### C. Durable payment/funding-attempt model and historical snapshots

**`business_funding_attempts`** (composite-protected on `(wallet_id, business_id)`):

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK) | No | — | |
| `business_id` / `wallet_id` | FK, composite-protected | No | — | |
| `purpose` | `string(24)`, enum-backed | No | — | `manual_top_up` \| `auto_recharge` \| `addon_purchase` |
| `payer_type_snapshot` | `string(16)` | No | — | |
| `billing_contact_name_snapshot` / `billing_contact_email_snapshot` | `string(191)`, nullable | Yes | `NULL` | the contact **as of this attempt**, never a live lookup |
| `provider_customer_external_id_snapshot` | `string(191)` | No | — | the Stripe Customer id at attempt time |
| `provider_customer_id` | `unsignedBigInteger`, FK `payment_provider_customers.id`, `restrictOnDelete()` | No | — | traceability only |
| `payment_method_display_snapshot` | `string(64)` | No | — | e.g. `"visa •••• 4242, exp 12/26"` |
| `requesting_actor_user_id` | `unsigned bigint`, nullable, no FK | Yes | `NULL` | **explicitly nullable this round** — `NULL` for genuine system-initiated attempts (`purpose = auto_recharge`); the authorizing policy for those remains fully traceable via the Business's own auto-recharge configuration, itself set by a consented actor at configuration time (§16) |
| `expected_currency_id` | `unsignedBigInteger`, FK `currencies.id`, `restrictOnDelete()` | No | — | |
| `expected_amount_micro` | `bigint` | No | — | |
| `local_idempotency_key` | `string(191)`, unique | No | — | the deterministic key derived for the outbound Stripe call |
| `provider_session_or_intent_reference` | `string(191)`, nullable | Yes | `NULL` | |
| `state` | `string(16)`, enum-backed | No | `created` | `created` \| `provider_pending` \| `requires_action` \| `processing` \| `succeeded` \| `failed` \| `canceled` \| `refunded` \| `disputed` |
| `failure_reason` | `text`, nullable | Yes | `NULL` | |
| `created_at` / `updated_at` | `timestamp` | No | `now()` | mutable — full transition history in the table below |

**`business_funding_attempt_transitions`** — append-only:

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK) | No | — | |
| `funding_attempt_id` | `unsignedBigInteger`, FK `business_funding_attempts.id`, `restrictOnDelete()` | No | — | |
| `from_state` / `to_state` | `string(16)` | No | — | |
| `source` | `string(24)`, enum-backed | No | — | `sync_response` \| `webhook_event` \| `admin_action` \| `reconciliation_job` |
| `provider_event_id` | `unsignedBigInteger`, FK `payment_provider_events.id`, nullable | Yes | `NULL` | set when `source = webhook_event` |
| `actor_user_id` | `unsigned bigint`, nullable, no FK | Yes | `NULL` | |
| `created_at` | `timestamp` | No | `now()` | |

**Circular-FK correction — new this round.** The prior round's `business_funding_attempts.addon_purchase_id` (pointing at `business_usage_addon_purchases`) formed a circular reference against `business_usage_addon_purchases.funding_attempt_id` (pointing back at `business_funding_attempts`) — two tables each holding a live FK to the other. **`business_funding_attempts.addon_purchase_id` is removed entirely.** `business_usage_addon_purchases.funding_attempt_id` (§18) is retained as the **sole authoritative direction** — a purchase points to its own payment attempt, not the reverse — matching the natural ownership shape (the purchase is what was bought; the funding attempt is how it was paid for). Finding the purchase from a given funding attempt, when needed, is a simple indexed reverse query (`business_usage_addon_purchases WHERE funding_attempt_id = ?`), supported by `funding_attempt_id`'s own unique constraint (§18) rather than a second stored FK.

**Historical snapshot rule and retention/privacy — unchanged from the prior round.**

---

## 18. Manual credit, paid top-up, promotional credit, and add-ons

Unchanged in overall shape, with entry-type delta behavior per §12 and top-up routed through the funding-attempt model (§17.C). **Consent for initiating a top-up is now gated by §16's charge-causing-action rule, not a flat role grant.**

**`business_usage_addon_catalog`** — full schema (was shorthand-only):

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK) | No | — | |
| `addon_key` | `string(64)`, unique | No | — | |
| `display_name` | `string(191)` | No | — | |
| `price_micro` | `bigint` | No | — | |
| `currency_id` | `unsignedBigInteger`, FK `currencies.id`, `restrictOnDelete()` | No | — | |
| `fulfillment_mode` | `string(24)`, enum-backed | No | — | `wallet_credit` \| `direct_deliverable` |
| `is_active` | `boolean` | No | `true` | |
| `created_at` / `updated_at` | `timestamp` | No | `now()` | |

**Not seeded at M4 launch (zero rows)** — corrected wording, no self-contradiction with "seeded" language.

**`business_usage_addon_purchases`** — full schema, corrected this round (composite-protected wording removed — no `wallet_id`; `funding_attempt_id` is now the sole authoritative direction, above):

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK) | No | — | |
| `business_id` | `unsignedBigInteger`, FK `businesses.id`, `restrictOnDelete()` | No | — | plain FK |
| `addon_key` | `string(64)` | No | — | |
| `price_micro` | `bigint` | No | — | snapshot at purchase time |
| `funding_attempt_id` | `unsignedBigInteger`, FK `business_funding_attempts.id`, unique, `restrictOnDelete()` | No | — | sole authoritative direction (§17.C); `UNIQUE` enforces the intended 1:1 |
| `status` | `string(16)`, enum-backed | No | `pending` | `pending` \| `completed` \| `failed` |
| `requested_by_user_id` | `unsigned bigint`, no FK | No | — | |
| `completed_at` | `timestamp`, nullable | Yes | `NULL` | |
| `created_at` | `timestamp` | No | `now()` | |

`fulfillment_mode` makes the wallet-debit-vs-separate-SKU choice data-driven per add-on; the one worked v1 example (a purchasable audit) is recommended `direct_deliverable` (§39 item 8).

**Cross-Business isolation — unchanged.**

---

## 19. Auto-recharge and low-balance behavior

**Trigger — corrected this round to be structural, not an enumerated call-site list (§12's shared ledger-insert method is now the single source of truth): any ledger entry with `available_delta_micro < 0` dispatches `EvaluateBusinessAutoRecharge` after commit — correctly including `Reservation` (the prior round's missed case, since reservation is the normal operation that decreases available balance), `UsageOverageCharge`, `Refund`, `DisputeChargeback`, and a negative `CorrectionReversal` — and correctly excluding a normal within-reservation `UsageCharge` (`available_delta = 0`, since that money was already decremented at reserve time) and `ReservationRelease` (`available_delta > 0`).**

**`App\Jobs\Usage\EvaluateBusinessAutoRecharge`** algorithm — unchanged in shape from the prior round:

1. Cheap, lock-free pre-check: `auto_recharge_enabled`, `available_balance_micro < auto_recharge_threshold_micro`, no already-open `business_funding_attempts` row of `purpose: auto_recharge`.
2. If eligible: `DB::transaction()`, wallet row `findForUpdate()`, re-check eligibility under lock, lazily roll the recharge period over if needed (§15), compute the recharge amount bounded by remaining `monthly_recharge_cap_micro` headroom, create the `business_funding_attempts` row.
3. Outside any transaction, call `PaymentProviderGateway::chargeOffSession()`.
4. Record the outcome via `UsageWalletManager::recordFundingAttemptOutcome()` (idempotent), inserting a `business_funding_attempt_transitions` row and, on `succeeded`, the `AutoRecharge` ledger entry.

**Consecutive-failure counter — corrected this round with an actual schema.** `business_usage_wallets.consecutive_recharge_failures` (§12, new this round) is incremented on each `failed`/`requires_action` auto-recharge outcome and reset to `0` on any `succeeded` outcome — the prior round referenced "3 consecutive failures" with no backing schema; this round defines exactly where that count lives and how it changes. **3** remains the recommended (category-3) threshold at which `auto_recharge_enabled` is set `false` in the same transaction, with a distinct "auto-recharge disabled" notification.

**`requires_action` handling — unchanged:** treated identically to `failed` for the consecutive-failure counter, with its own distinct notification.

**Low-balance notification dedup and reset — corrected this round with an exact mechanism.** `low_balance_notified_at` is set the first time a period crosses below threshold (via the same shared ledger-insert method, §12); **cleared automatically the moment any entry's `available_delta_micro > 0` brings `available_balance_micro` back above threshold** (not merely "resets," but a concrete, structural clearing rule now); otherwise not re-sent until 24 hours have elapsed. Category-3 recommendation.

**`recharged_this_period_micro` and refunds/chargebacks — new this round, resolving the previously unstated interaction.** A `Refund` or `DisputeChargeback` entry **never decrements `recharged_this_period_micro`** — the monthly recharge-cap headroom consumed by an auto-recharge that is later refunded or charged back is **not** automatically reopened. Only an explicit admin correction (via `business_usage_limit_transitions`, mandatory reason) may adjust it, if ever needed. Category-3 recommendation, explicitly stated rather than left implicit.

**Zero-balance / reserved-balance behavior — unchanged.**

**Customer-configurable limits vs. platform safety limit — unchanged.**

---

## 20. Stripe/provider boundary

**Resolution unchanged: Stripe-only v1**, behind `PaymentProviderGateway`. Provider-customer ownership lives in `payment_provider_customers` (§17.B, corrected lifecycle this round).

**SetupIntent / instrument attachment — unchanged.**

**Checkout Session vs. PaymentIntent — corrected this round for the additional-slot agreement's own flow (§22 now names an exact model):** top-up and add-on purchase remain one-time Checkout Sessions; auto-recharge remains an off-session PaymentIntent; **the additional-slot agreement's initial charge uses a Checkout Session in `payment` mode with `setup_future_usage: 'off_session'`** (saving the Workspace's payment method and charging the initial amount in one step), and **every subsequent renewal uses an off-session PaymentIntent against that saved instrument** — never a second customer-present Checkout Session per renewal, and never Stripe's own Subscription/Invoice object (§22 states the reasoning).

**Webhook verification — unchanged, using both previously-inert config values.**

**Stripe API version posture — unchanged: pinned explicitly via `Stripe::setApiVersion()`, distinct from the PHP SDK version.**

**Event persistence, replay, claim/lease mechanics, and idempotency — corrected this round with an exact schema and algorithm (§21).**

**Reconciliation — extended this round** to also sweep stuck renewal charges (§22) alongside funding attempts and slot-agreement allocation.

**No outbound Stripe call ever occurs while a database row lock is held — unchanged.**

**Test-mode separation — unchanged.**

**SDK version recommendation — unchanged: retain `stripe/stripe-php ^7.76` (`v7.128.0`) for v1.**

**Payment amount limits — corrected this round (§10): Stripe's documented maximum (currently eight digits in the currency's smallest unit) is validated alongside the documented minimum, for the pinned API version and currency, before every outbound payment — never assumed unbounded.**

**Wording — unchanged: "effectively exactly-once local accounting effect under at-least-once delivery," never "exactly-once accounting."**

---

## 21. Webhook verification, persistence, replay, and reconciliation

**Corrected this round: `payment_provider_events` gains explicit claim-lease fields distinguishing an actively-processing event from a crashed/stuck one, a provider-scoped unique constraint, and an executable payload-purge design.**

**`payment_provider_events`** — full corrected schema:

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK) | No | — | |
| `provider` | `string(16)`, enum-backed | No | `stripe` | |
| `provider_event_id` | `string(191)` | No | — | Stripe's own event id |
| `event_type` | `string(64)` | No | — | |
| `provider_object_id` | `string(191)` | No | — | the Checkout Session/PaymentIntent/etc. id the event concerns |
| `payload_encrypted` | `text`/`blob`, encrypted at rest, **nullable** | Yes | — | **corrected this round** — nullable, since it is purged after retention (below) |
| `payload_hash` | `string(64)` | No | — | SHA-256 of the raw verified payload — permanent, survives purge |
| `payload_purged_at` | `timestamp`, nullable | Yes | `NULL` | **new this round** — set when `payload_encrypted` is nulled out by the retention job; `id`/`provider_event_id`/`payload_hash`/`state` remain permanently available for idempotency/audit even after purge |
| `state` | `string(16)`, enum-backed | No | `received` | `received` \| `processing` \| `processed` \| `failed` \| `ignored` |
| `attempts` | `unsigned smallint` | No | `0` | |
| `processing_started_at` | `timestamp`, nullable | Yes | `NULL` | **new this round** |
| `lease_expires_at` | `timestamp`, nullable | Yes | `NULL` | **new this round** — the claim lease |
| `last_attempt_at` | `timestamp`, nullable | Yes | `NULL` | **new this round** |
| `last_error` | `text`, nullable | Yes | `NULL` | |
| `received_at` / `processed_at` | `timestamp` / nullable | No / Yes | `now()` / `NULL` | |

Indexes, corrected this round: **`UNIQUE (provider, provider_event_id)`** — replacing a bare unique on `provider_event_id` alone, since an event ID is only meaningfully unique **within** a given provider's own ID space, not across providers in general.

**Corrected claim/lease algorithm:**

1. On receipt, insert the event row (`state: received`, `attempts: 0`). The `UNIQUE (provider, provider_event_id)` constraint is the true-duplicate guard — a genuine replay's insert conflicts and is recognized as already-known immediately, without assuming "already known" means "already processed."
2. **Claim (atomic, single statement, no separate `SELECT` then `UPDATE`):**
   ```sql
   UPDATE payment_provider_events
   SET state = 'processing',
       processing_started_at = NOW(),
       lease_expires_at = NOW() + INTERVAL 5 MINUTE,   -- category-3 recommended lease duration
       attempts = attempts + 1,
       last_attempt_at = NOW()
   WHERE id = ?
     AND (
       state = 'received'
       OR (state = 'failed' AND attempts < 5)          -- category-3 recommended max attempts
       OR (state = 'processing' AND lease_expires_at < NOW())  -- stale-lease recovery
     )
   ```
   Only a worker whose `UPDATE` actually affects a row has genuinely claimed it — InnoDB's row-level locking during the `UPDATE`'s own WHERE evaluation makes this compare-and-swap atomic against concurrent claim attempts, without a separate lock statement.
3. Processing resolves the local subject via `provider_session_or_intent_reference` — never trusting webhook metadata as authoritative on its own — and validates the verified Stripe object's customer, amount, currency, and purpose against the local attempt's own expected values. A mismatch marks the event `failed` (via the same claim-shaped `UPDATE` pattern, setting `state='failed'`, `last_error=...`) and triggers reconciliation rather than blindly applying the event.
4. On match, the event drives the local `state` transition only if it is a valid forward transition per the funding-attempt/renewal-charge state table (§17.C/§19/§22). An invalid transition (e.g., a `succeeded` event after a `refunded` one already applied) triggers reconciliation against Stripe's current object state rather than blindly overwriting.
5. **Stale-processing recovery — new this round, the direct answer to the claim-lease requirement:** a worker that crashes after claiming (`state = 'processing'`) but before finishing leaves `lease_expires_at` in the past once that lease's duration elapses — the claim `UPDATE`'s own `OR (state = 'processing' AND lease_expires_at < NOW())` clause picks it back up on the next sweep, with `attempts` incremented again, no manual intervention required.
6. **Max-attempt exhaustion:** once `attempts >= 5` while still `failed`, the claim `UPDATE`'s `WHERE` clause no longer matches that row (`state = 'failed' AND attempts < 5` fails) — the row becomes **permanently unreclaimed by the automated worker**, a real terminal state distinguishable from an ordinary retryable failure purely by its own `attempts` count, surfaced to a platform-administrator review queue (§30) rather than retried forever.
7. **Terminal `processed`/`ignored` events are never reclaimed** — neither matches any branch of the claim `WHERE` clause.
8. Downstream mutations (`recordPaidTopUp`, `recordFundingAttemptOutcome`, slot-agreement allocation/renewal recording) remain independently idempotent on their own keys regardless of event arrival order.

**Executable payload purge — new this round.** `App\Jobs\Usage\PurgeExpiredWebhookPayloads` (a scheduled job) finds `processed`/`ignored` events past the retention window (recommended: retained only as long as needed for dispute-window reconciliation, exact duration an M3 operational detail) and sets `payload_encrypted = NULL`, `payload_purged_at = NOW()` — leaving `id`, `provider`, `provider_event_id`, `event_type`, `provider_object_id`, `payload_hash`, and `state` permanently intact, so the row continues to serve its idempotency-dedup and audit-trail role indefinitely even with the sensitive payload itself gone.

---

## 22. Additional Business-slot agreement — recurring provider model resolved this round

**RFC-004 explicitly describes charges corresponding to already-allocated additional Business slots as *recurring*. This round resolves exactly how: Option A — customer-present initial authorization with a saved Workspace instrument, followed by off-session PaymentIntent renewal charges — chosen over Stripe's own native Billing Subscription object.**

**Why Option A over Option B (Stripe Billing subscriptions):** this design already builds, for auto-recharge (§19), the exact SetupIntent-save-then-off-session-PaymentIntent-charge primitive Option A needs — reusing it here keeps the entire `PaymentProviderGateway` boundary (§9/§20) narrow and uniform, with one off-session-charge code path serving both auto-recharge and slot renewals, rather than introducing Stripe's own Subscription/Invoice webhook model as a second, structurally different integration this design would otherwise never need. Option B is not chosen; it remains available as a future alternative if Option A's manual renewal/dunning logic proves insufficient at scale — but that is not a decision this document makes now.

**Also carrying the cross-RFC blocker: the allocation step remains `NON-IMPLEMENTATION-READY`.** Everything else in this section — quoting, initial checkout, renewal charges, proration, dunning, refunds — is implementation-ready on its own.

**Operational prerequisite — unchanged:** RFC-004 ships no HTTP/operator surface for `workspace_plan_catalog` pricing; an authorized pricing tool is a prerequisite for this section's own M4, independent of the allocation blocker.

**`additional_business_slot_agreements`** — full schema, corrected this round:

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK) | No | — | |
| `workspace_id` | `unsignedBigInteger`, FK `workspaces.id`, `restrictOnDelete()` | No | — | Workspace-scoped, paid via a Workspace-owned instrument only |
| `current_allocation_count` / `target_allocation_count` | `unsigned tinyint` | No | — | billing-side bookkeeping view; RFC-004's `workspace_plan_assignments.additional_business_slots` remains sole authoritative entitlement value |
| `paid_delta` | `unsigned tinyint` | No | — | `target - current` at agreement-creation time |
| `price_per_slot_micro_snapshot` | `bigint` | No | — | from `workspace_plan_catalog.price`/`additional_business_slot_price_ratio` at quote time |
| `currency_id_snapshot` | `unsignedBigInteger`, FK `currencies.id`, `restrictOnDelete()` | No | — | |
| `ratio_snapshot` | `decimal(6,4)` | No | — | |
| `plan_catalog_id_snapshot` / `plan_tier_snapshot` | `unsignedBigInteger` FK / `string(16)` | No | — | which catalog/tier was quoted against, at initial purchase |
| `requesting_customer_user_id` | `unsigned bigint`, no FK | No | — | distinguished from the system/payment actor per the blocker's option 3 requirement, even though this document does not build option 3 |
| `requesting_customer_email_snapshot` | `string(191)` | No | — | **new this round** — this flow's billing-contact-equivalent snapshot (additional-slot agreements are Workspace-scoped and have no separate Workspace-level billing-contact concept the way Business wallets do, §17.A; the requesting owner's own account email serves this role) |
| `provider_customer_id` | `unsignedBigInteger`, FK `payment_provider_customers.id` (Workspace-owned), `restrictOnDelete()` | No | — | |
| `payment_method_display_snapshot` | `string(64)` | No | — | |
| `local_idempotency_key` | `string(191)`, unique | No | — | |
| `provider_session_or_intent_reference` | `string(191)`, nullable | Yes | `NULL` | the initial Checkout Session id |
| `billing_cadence` | `string(16)`, enum-backed | No | `monthly` | matching the Workspace plan's own `billing_cycle` |
| `next_renewal_at` | `timestamp`, nullable | Yes | `NULL` | null once no further renewal is scheduled (`canceled`) |
| `payment_lapsed` | `boolean` | No | `false` | see renewal-failure handling below |
| `state` | `string(20)`, enum-backed | No | `quote_created` | `quote_created` \| `checkout_pending` \| `payment_succeeded` \| `allocation_pending` \| `completed` \| `payment_failed` \| `allocation_failed` \| `refund_pending` \| `refunded` \| `canceled` |
| `created_at` / `updated_at` | `timestamp` | No | `now()` | mutable — transitions recorded in `additional_business_slot_agreement_transitions` (below) |

**`additional_business_slot_agreement_transitions`** — new this round, append-only:

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK) | No | — | |
| `agreement_id` | `unsignedBigInteger`, FK `additional_business_slot_agreements.id`, `restrictOnDelete()` | No | — | |
| `from_state` / `to_state` | `string(20)` | No | — | |
| `source` | `string(24)`, enum-backed | No | — | `sync_response` \| `webhook_event` \| `admin_action` \| `reconciliation_job` |
| `provider_event_id` | `unsignedBigInteger`, FK `payment_provider_events.id`, nullable | Yes | `NULL` | |
| `actor_user_id` | `unsigned bigint`, nullable, no FK | Yes | `NULL` | |
| `created_at` | `timestamp` | No | `now()` | |

**`additional_business_slot_renewal_charges`** — full schema, corrected this round with its own complete historical snapshot (not merely inherited via the parent agreement, since a later renewal may legitimately snapshot a *different*, then-current catalog price, per the proration/price-change rules below):

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK) | No | — | |
| `agreement_id` | `unsignedBigInteger`, FK `additional_business_slot_agreements.id`, `restrictOnDelete()` | No | — | |
| `period_start` / `period_end` | `timestamp` | No | — | |
| `amount_micro_snapshot` | `bigint` | No | — | |
| `payer_type_snapshot` | `string(16)` | No | `workspace` | stated explicitly for completeness/consistency with §17.C, though always `workspace` for this flow |
| `provider_customer_external_id_snapshot` | `string(191)` | No | — | |
| `payment_method_display_snapshot` | `string(64)` | No | — | |
| `currency_id_snapshot` | `unsignedBigInteger`, FK `currencies.id`, `restrictOnDelete()` | No | — | |
| `plan_catalog_id_snapshot` / `plan_tier_snapshot` / `ratio_snapshot` | see above | No | — | re-snapshotted **at this renewal's own creation time**, independent of the parent agreement's original values — a price change applies starting from the next renewal created after it (below) |
| `initiated_by` | `string(16)`, enum-backed | No | `scheduled_job` | `scheduled_job` \| `admin_retry` |
| `actor_user_id` | `unsigned bigint`, nullable, no FK | Yes | `NULL` | set only when `initiated_by = admin_retry` |
| `local_idempotency_key` | `string(191)`, unique | No | — | **deterministic**, derived as `sha256(agreement_id . ':' . period_start_iso8601)` — a retried job invocation for the same period never double-charges even before the DB constraint is checked |
| `provider_session_or_intent_reference` | `string(191)`, nullable | Yes | `NULL` | the renewal's own PaymentIntent id |
| `state` | `string(16)`, enum-backed | No | `created` | same shape as §17.C's funding-attempt states |
| `failure_reason` | `text`, nullable | Yes | `NULL` | |
| `created_at` | `timestamp` | No | `now()` | |

**`additional_business_slot_renewal_charge_transitions`** — new this round, append-only, structurally identical in shape to the agreement-transitions table above but kept as its own table rather than a shared polymorphic one (a polymorphic `subject_type`/`subject_id` pointer without a real, enforced FK would be a real regression against this document's own established discipline of enforcing every reference with an actual foreign key — so two small, properly-FK'd tables are used instead of one unenforced shared one):

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK) | No | — | |
| `renewal_charge_id` | `unsignedBigInteger`, FK `additional_business_slot_renewal_charges.id`, `restrictOnDelete()` | No | — | |
| `from_state` / `to_state` | `string(16)` | No | — | |
| `source` | `string(24)`, enum-backed | No | — | same values as above |
| `provider_event_id` | `unsignedBigInteger`, FK `payment_provider_events.id`, nullable | Yes | `NULL` | |
| `actor_user_id` | `unsigned bigint`, nullable, no FK | Yes | `NULL` | |
| `created_at` | `timestamp` | No | `now()` | |

**Saga rule — unchanged: `payment_succeeded` and `completed` are distinct; the agreement is never marked `completed` before allocation succeeds.**

**Sequence, corrected this round for Option A:** quote (`UsageBillingCheckoutManager::quoteAdditionalSlots()`, read-only) → Checkout Session created in `payment` mode with `setup_future_usage: 'off_session'` for the quoted initial price, agreement `state: checkout_pending` → webhook-authoritative confirmation (the session both charges the initial amount **and** saves the Workspace's payment method in one step) moves the agreement to `payment_succeeded` → `allocation_pending` → (pending the blocker's resolution) `completed`, with `next_renewal_at` set to one billing cadence after the initial purchase.

**Initial authorization and consent — new this round:** the initial Checkout Session may be created only by the Workspace owner (or a platform administrator, mandatory reason) — §16's target-consent rule applied to this Workspace-scoped flow directly, since the instrument saved and charged is always Workspace-owned.

**Renewal initiation and `requires_action` handling — new this round.** A scheduled job (`App\Jobs\Usage\InitiateSlotAgreementRenewal`) finds agreements with `next_renewal_at <= now()`, mirroring §19's auto-recharge job pattern exactly: lock the agreement row, create the `additional_business_slot_renewal_charges` row (`state: created`, `initiated_by: scheduled_job`), release the lock, call `chargeOffSession()` outside any transaction, record the outcome via the same claim/idempotency discipline as §21. A `requires_action` outcome is treated as a renewal failure requiring the Workspace owner's manual intervention (a notification directing them to complete authentication or provide a new payment method) — mirroring §19's `requires_action` handling exactly.

**Cancellation — new this round.** A Workspace owner (or platform admin, mandatory reason) may cancel an agreement; cancellation is **effective at the end of the current already-paid period** (`next_renewal_at`), never an immediate mid-period revocation of already-paid-for capacity. `next_renewal_at` is set to `null`; the agreement's state moves toward `canceled` once the current period elapses without a further renewal being initiated. Already-allocated slots for the current period remain until that period genuinely ends.

**Proration for increases/decreases — new this round.** Increasing `target_allocation_count` mid-period creates an immediate, separate, one-time prorated `additional_business_slot_renewal_charges` row scoped only to the remainder of the current period (`period_start`/`period_end` narrowed accordingly), with `amount_micro_snapshot = bcRoundHalfUp(price_per_slot × additional_slots × remaining_days, total_days_in_period)` (§10's exact rounding algorithm). Decreasing `target_allocation_count` mid-period does **not** retroactively refund the current period's already-paid charge, but **does** reduce the amount charged at the next renewal. Both directions are category-3 recommendations, explicitly adjustable by a future milestone with justification.

**Retries/dunning and `payment_lapsed` — new this round.** A failed renewal charge triggers retry attempts (recommended: 3 attempts over a bounded window, category-3, mirroring §19's own consecutive-failure count for consistency). After the final retry fails, `payment_lapsed = true` is set on the agreement. **Recovery is automatic** the moment any subsequent renewal charge for that agreement succeeds (including a manually-triggered `admin_retry` after the Workspace owner updates their payment method) — no separate admin action required to clear the flag beyond a successful payment.

**Price changes apply only to future, properly-notified renewals — new this round.** A catalog price change never alters an already-created renewal charge's snapshotted `amount_micro_snapshot` (immutable once created). It applies starting from the next renewal charge created *after* the price change — and the Workspace owner **must** receive a notification of the upcoming price change before that next renewal is attempted (the exact notice period is a category-3/M4 implementation detail; the *requirement* that notice precedes the price-changed renewal is locked here, not left implicit).

**Provider references, deterministic idempotency, reconciliation — new this round, as specified above** (each renewal's own `provider_session_or_intent_reference`; the deterministic `local_idempotency_key`; the extended reconciliation sweep, §20).

**Idempotency (initial purchase), complimentary/Agency-unlimited behavior, and the edge-case list (concurrent checkouts, plan change/complimentary transition mid-checkout, payment-success-then-allocation-failure, retry/reconciliation, refund) — unchanged from the prior round, now grounded in the corrected saga/table shapes above.**

**Admin allocation action for the blocker's Option 1 — sharpened this round.** `UsageBillingCheckoutManager::completeSlotAllocationAsAdministrator(AdditionalBusinessSlotAgreement $agreement, int $realAdministratorActorUserId, string $reason): void` calls `EntitlementManager::setAdditionalBusinessSlots($workspace, $agreement->target_allocation_count, $realAdministratorActorUserId, $reason)` **using the real, currently-authenticated platform administrator's own user id — never a synthetic "system admin" identity, and never a customer's or the scheduled job's own identity.** The agreement is marked `completed` **only after** that call returns successfully; if it throws, the agreement remains `allocation_pending` (or moves to `allocation_failed`), never silently marked complete.

---

## 23. Refunds, disputes, chargebacks, invoices, receipts, and tax/VAT boundary

Unchanged from the prior round: four structurally distinct entry types (`Refund`, `DisputeChargeback`, `UsageChargeReversal`, `CorrectionReversal`); Stripe-hosted receipts authoritative for v1 via `business_billing_receipts` (composite-protected wording removed this round — no `wallet_id` on this table; full schema below); the legacy `invoices` table remains unreused; production tax/VAT posture remains `NON-IMPLEMENTATION-READY`, a legal/compliance gate, not an ordinary feature-scope preference — this RFC is not legal advice and asserts no tax posture as safe by default.

**`business_billing_receipts`** — full schema (was shorthand-only):

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK) | No | — | |
| `business_id` | `unsignedBigInteger`, FK `businesses.id`, `restrictOnDelete()` | No | — | plain FK |
| `ledger_entry_id` | `unsignedBigInteger`, FK `business_usage_ledger_entries.id`, `restrictOnDelete()` | No | — | |
| `provider_receipt_url` | `string(2048)` | No | — | |
| `provider_reference` | `string(191)` | No | — | |
| `created_at` | `timestamp` | No | `now()` | |

---

## 24. Authorization and tenant isolation

Five genuinely distinct authority paths — one path's grant never implies another's. **Table corrected this round: "Initiate top-up" and "Configure auto-recharge" are now payer-consent-gated (§16), matching the same target-direction discipline already applied to "Set payer to X" and "Manage [X]-owned payment instrument."**

| Capability | Workspace owner | Active Workspace Admin | Staff | Direct Business owner/customer | Platform administrator |
|---|---|---|---|---|---|
| View balance/ledger for a Business in their Workspace | Yes | Yes, if `business_access_scope` covers that Business | Yes, if scope covers it | Yes, for their own Business only | Yes, any Business |
| Manage billing contact (non-payment) | Yes | Yes, if scope covers it | No | Yes, own Business | Yes |
| Manage a Business-owned payment instrument | No | No | No | Yes, own Business | Yes |
| Manage a Workspace-owned (shared) payment instrument | Yes | No | No | No | Yes |
| Set payer to `workspace` | Yes | No | No | No | Yes, mandatory reason |
| Set payer to `business` | No | No | No | Yes, own Business | Yes, mandatory reason |
| **Initiate top-up, when `payer_type = 'workspace'`** | Yes | No | No | No | Yes, mandatory reason |
| **Initiate top-up, when `payer_type = 'business'`** | No | No | No | Yes, own Business | Yes, mandatory reason |
| **Configure/enable auto-recharge, when `payer_type = 'workspace'`** | Yes | No | No | No | Yes, mandatory reason |
| **Configure/enable auto-recharge, when `payer_type = 'business'`** | No | No | No | Yes, own Business | Yes, mandatory reason |
| **Initiate an additional-slot agreement checkout/renewal (always Workspace-owned instrument)** | Yes | No | No | No | Yes, mandatory reason |
| Configure Business spend cap / per-feature limits (non-payment) | Yes | Yes, if scope covers it, bounded by the platform safety limit | No | Yes, own Business, bounded by the platform safety limit | Yes, including the platform safety limit itself |
| Issue manual/promotional credit | No | No | No | No | Yes only |
| Set/clear `billing_status = 'suspended'` | No | No | No | No | Yes only |
| Perform the manual additional-slot allocation action (§22, blocker Option 1) | No | No | No | No | Yes only, mandatory reason, own identity — never a synthetic actor |
| View internal provider cost (`provider_cost_micro`) | No | No | No | No | Yes only |
| Review exhausted (max-attempts) webhook events | No | No | No | No | Yes only |

**Distinguishing viewing/configuring non-payment limits from authorizing money movement — new this round, restated from §16:** the spend-cap/per-feature-limit row above causes no charge and uses the existing, unchanged broader Business-scope authority; every row above marked with a `payer_type`-conditional split causes or enables an actual charge and uses the new consent-gated rule.

Unrelated Workspace/Business resources fail closed with a 404-shaped response, never a 403 that would confirm existence. No raw query against any new billing table is permitted outside its owning manager and repository, except an immutable migration/backfill script — enforced by a mechanical boundary test (§35).

**Permission category — unchanged:** `Business Usage Billing`.

---

## 25. Schema

**Full table list — 26 tables this round (up from 23: three new append-only transition tables added; no tables removed; one column-level circular-FK fix, not a table-count change).** All `restrictOnDelete()` on tenancy-scoping foreign keys, never `cascade`; no native `ENUM` anywhere; composite foreign keys `(wallet_id, business_id) → business_usage_wallets(id, business_id)` applied **only** to tables that actually carry both columns (§12, corrected this round).

| Table | Change this round | Backfilled? | Sole write authority |
|---|---|---|---|
| `business_usage_wallets` | `period_key`/`consecutive_recharge_failures` columns added | Yes | `UsageWalletManager` |
| `business_usage_ledger_entries` | `period_key` column added | No | `UsageWalletManager` |
| `business_usage_reservations` | `period_key` column added | No | `UsageWalletManager` |
| `business_usage_rates` | exact types finalized | No | `UsageWalletManager` |
| `business_usage_rate_activations` | deterministic tie-break ordering specified | No | `UsageWalletManager` |
| `platform_feature_usage_classifications` | full schema given | Yes | `UsageWalletManager` |
| `platform_feature_usage_classification_transitions` | **new** | No | `UsageWalletManager` |
| `business_feature_usage_limits` | full schema given; "composite-protected" wording removed (no `wallet_id`) | No | `UsageWalletManager` |
| `platform_feature_usage_safety_limits` | full schema given | No | `UsageWalletManager` |
| `business_usage_limit_transitions` | full schema given | No | `UsageWalletManager` |
| `business_usage_wallet_billing_status_transitions` | unchanged | No | `UsageWalletManager` |
| `business_billing_contacts` | full schema given; "composite-protected" wording removed | No | `BillingProfileManager` |
| `business_payer_assignments` | full schema given; wording removed | Yes | `BillingProfileManager` |
| `business_payer_transitions` | full schema given | No | `BillingProfileManager` |
| `payment_provider_customers` | generated-column active-uniqueness lifecycle fix; `UNIQUE(provider, provider_customer_id)` added | No | `PaymentInstrumentManager` |
| `business_payment_instruments` | `provider` column + `UNIQUE(provider, provider_payment_method_id)` added | No | `PaymentInstrumentManager` |
| `business_funding_attempts` | `addon_purchase_id` removed (circular-FK fix) | No | `UsageWalletManager` / `UsageBillingCheckoutManager` |
| `business_funding_attempt_transitions` | unchanged | No | `UsageWalletManager` |
| `additional_business_slot_agreements` | Option A fields added (`next_renewal_at`, `payment_lapsed`, email snapshot) | No | `UsageBillingCheckoutManager` |
| `additional_business_slot_agreement_transitions` | **new** | No | `UsageBillingCheckoutManager` |
| `additional_business_slot_renewal_charges` | full historical snapshot added | No | `UsageBillingCheckoutManager` |
| `additional_business_slot_renewal_charge_transitions` | **new** | No | `UsageBillingCheckoutManager` |
| `business_usage_addon_catalog` | full schema given | No | `UsageBillingCheckoutManager` |
| `business_usage_addon_purchases` | full schema given; `funding_attempt_id` unique, wording removed | No | `UsageBillingCheckoutManager` |
| `business_billing_receipts` | full schema given; wording removed | No | `UsageWalletManager` |
| `payment_provider_events` | claim-lease fields, `UNIQUE(provider, provider_event_id)`, purge fields added | No | `UsageBillingCheckoutManager` |

Exact columns/types/constraints for each table are given in the section that introduced it (§11–§23, as cross-referenced above). DDL and any data-only backfill operation remain separate migrations.

**`CHECK` constraints and generated columns** (the composite tenancy-ID FKs, §12; `payment_provider_customers.active_business_id`/`active_workspace_id`, §17.B) are recommended where the target MySQL version is confirmed 8.0+; the M1 contract confirms this — including, new this round, confirming the `bcmath` PHP extension is enabled (§10) — before relying on any of them, falling back to manager-level enforcement (already the primary enforcement mechanism throughout this design) where confirmation is not possible.

---

## 26. PHP enums/value objects/models

**Enums — corrected this round to 17 (net +1: one new enum for the slot-renewal-charge initiator; no enums removed):** `UsageLedgerEntryType`, `UsageReservationStatus`, `WalletBillingStatus`, `UsageLimitType`, `PayerType`, `PaymentInstrumentStatus`, `ProviderCustomerStatus`, `FundingAttemptPurpose`, `FundingAttemptState`, `FundingAttemptTransitionSource`, `BillingStatusTransitionSource`, `AddonFulfillmentMode`, `AddonPurchaseStatus`, `SlotAgreementState`, `SlotRenewalChargeInitiatedBy` (**new**, §22 — `scheduled_job` \| `admin_retry`), `ProviderEventState`, `RoundingRule`. (The new transition tables in §14.1/§22 reuse the existing `FundingAttemptTransitionSource`-shaped `source` values — `sync_response`/`webhook_event`/`admin_action`/`reconciliation_job` — rather than each minting its own duplicate enum; `platform_feature_usage_classification_transitions` carries `actor_user_id`/`reason` directly with no `source` enum at all, since a classification change is always an explicit, authored action, never system-triggered.)

**New readonly value objects — unchanged: `ReservationResult`, `CommitResult`, `EffectivePayer`, `CapEvaluation`, `UsageCapacityDecision`.**

**New Eloquent models — 26, one per table in §25**, each `casts` its enum columns.

---

## 27. Repository contracts

One contract + one Eloquent implementation per table in §25 — **26 pairs, 52 files** (corrected from the prior round's 23 pairs/46 files), bound in `AppServiceProvider` identically to RFC-004 M1's six-repository pattern.

---

## 28. Manager/domain authority

**Unchanged at five authorities:** `UsageWalletManager` (wallets, ledger, reservations, rates/activations, classifications/classification-transitions, limits/limit-transitions, billing-status/transitions, receipts), `BillingProfileManager` (billing contact, payer assignment/transitions), `PaymentInstrumentManager` (provider customers, payment instruments), `UsageBillingCheckoutManager` (funding attempts' provider-facing leg, additional-slot agreements/renewals/their transition audits, add-on purchases, provider-event ingestion), `StripePaymentProviderGateway`. No controller, job, or event listener ever writes to a table in §25 directly.

---

## 29. Jobs, events, notifications, and scheduling

**Jobs — corrected this round to twelve (was nine; three added):** `ExpireStaleUsageReservations`, `AdvanceUsagePeriodBoundaries` (proactive maintenance only), `EvaluateBusinessAutoRecharge` (centralized trigger, §12/§19), `ProcessPaymentProviderEvent` (the claim-and-process worker, §21), `ReconcileProviderPendingState` (extended), `ReconcileSlotAgreementAllocation`, `InitiateSlotAgreementRenewal` (**new**, §22), `PurgeExpiredWebhookPayloads` (**new**, §21), `SendLowBalanceNotification`, `SendAutoRechargeDisabledNotification`, `SendReceiptNotification`, `SendSlotAgreementPriceChangeNotice` (**new**, §22's mandatory pre-renewal price-change notice). All `App\Jobs\Usage\*`, extending `App\Jobs\Base`, `ShouldQueue` + `ShouldQueueAfterCommit` where dispatched from within a request-handling transaction.

**Events — corrected this round to fifteen (was fourteen; one added):** `BusinessWalletCredited`, `BusinessWalletDebited`, `BusinessWalletDebtIncurred`, `BusinessWalletDebtCleared`, `BusinessUsageReserved`, `BusinessUsageCommitted`, `BusinessUsageReservationReleased`, `BusinessPayerChanged`, `BusinessBillingContactChanged`, `BusinessFundingAttemptSucceeded`, `BusinessFundingAttemptFailed`, `BusinessWalletBillingStatusChanged`, `AdditionalBusinessSlotAgreementCompleted`, `AdditionalBusinessSlotAllocationFailed`, `AdditionalBusinessSlotAgreementLapsed` (**new**, §22's `payment_lapsed` transition). All `App\Events\Usage\*`, `implements ShouldDispatchAfterCommit`, carrying IDs/scalars only.

**Listener — new this round:** `App\Listeners\Usage\InitializeUsageWalletForNewBusiness`, subscribed to **both** `BusinessCreated` and `BusinessAssignedToWorkspace` (§32) — calls `UsageWalletManager::initializeForNewBusiness()` idempotently.

**Scheduling — unchanged in shape:** `AdvanceUsagePeriodBoundaries` proactive-only; every other job runs on a bounded interval (exact cadences M1/M3 implementation details).

---

## 30. HTTP/admin/customer surfaces and permissions

**Customer surface — corrected this round: additional-slot routes moved out of the Business-nested group, since slot purchases are Workspace-scoped and must never require an arbitrary `businessUid`.**

- Business-scoped (nested under `workspaces/{workspaceUid}/businesses/{businessUid}/usage`, unchanged): balance/ledger view (GET), top-up, payer, billing-contact, instruments, limits, auto-recharge (POST/DELETE), add-on purchase (GET/POST).
- **Workspace-scoped, corrected this round — moved to `workspaces/{workspaceUid}/slots/{quote,checkout,cancel}` (GET/POST), directly under the `workspaces` prefix, never nested under any particular Business** — matching the actual scope of an additional-slot agreement (a Workspace-level entitlement, RFC-004), never requiring or accepting a `businessUid` at all.

**Webhook route — unchanged:** `POST /webhooks/stripe/usage-billing`, a `VerifyCsrfToken` `$except` entry, never behind session auth.

**Admin surface — extended this round:** `Admin\UsageBillingController` (or similar): read balance/ledger/caps for any Business, issue manual/promotional credit, set/clear `billing_status`, set the platform safety limit, view (never edit) `provider_cost_micro` aggregates, perform the manual additional-slot allocation action (§22, using the administrator's own real identity), and — **new this round** — review webhook events that have exhausted their claim/retry attempts (§21).

**Observability — unchanged.**

---

## 31. Concurrency, lock order, idempotency, and retry rules

**Canonical lock order — unchanged in shape, extended to the new tables:** Workspace (only when a path already needs one) → Business → wallet → reservation → funding attempt → additional-slot agreement → renewal charge.

**Idempotency keys — extended this round:** reservation `idempotency_key`, ledger `correlation_key`, `business_funding_attempts.local_idempotency_key`, `additional_business_slot_agreements.local_idempotency_key`, `additional_business_slot_renewal_charges.local_idempotency_key` (**now deterministically derived**, §22), add-on purchase (via its unique `funding_attempt_id`), `payment_provider_events`'s `UNIQUE (provider, provider_event_id)` (**corrected this round to be provider-scoped**), `payment_provider_customers`'s `UNIQUE (provider, provider_customer_id)` and active-owner uniqueness (**new this round**), `business_payment_instruments`'s `UNIQUE (provider, provider_payment_method_id)` (**new this round**).

**"Effectively exactly-once local accounting effect under at-least-once delivery" — unchanged wording, now also grounded in the corrected claim-lease algorithm (§21), which makes "at-least-once processing attempt, exactly-once accounting effect" concretely true rather than merely asserted.**

**Forced-race test scenarios — extended this round:** two reservations racing the last remaining spend-cap headroom; two `EvaluateBusinessAutoRecharge` dispatches racing the "one open funding attempt" rule; a reservation racing a manual admin credit; two concurrent additional-slot checkouts for one Workspace; two workers racing to claim the same `payment_provider_events` row (only one succeeds); an unrelated-Business reservation proceeding unaffected during another Business's race — all via the real cross-process pattern already established by `EntitlementManagerConcurrencyTest.php`.

---

## 32. Backfill, ongoing Business creation, rollout, compatibility, and rollback safety

**Backfill for existing Businesses — unchanged in intent:** one `business_usage_wallets` row and one `business_payer_assignments` row per existing Business, zero balance/debt, null caps, payer defaulted by Workspace tier, no instrument auto-attached. One `platform_feature_usage_classifications` row per existing `PlatformFeature` case. Zero newly-gated features, zero newly-required payment setup.

### Ongoing (post-rollout) Business creation — new this round, resolving a real, repository-confirmed gap

**§3's direct evidence this round: exactly one call site dispatches `BusinessCreated`** (`BusinessManager::applyIdentity()`'s CREATE branch), while a second, genuinely distinct Business-creation code path — `WorkspaceManager::createBusinessInWorkspace()` — dispatches a **different** event, `BusinessAssignedToWorkspace`, and never dispatches `BusinessCreated`. A listener wired only to `BusinessCreated` would silently miss every Business created through the Workspace-flow path — exactly the "no newly created Business may be silently omitted" failure this correction item exists to prevent.

**The sole initialization path: `UsageWalletManager::initializeForNewBusiness(int $businessId): void`** — creates the wallet, payer assignment (defaulted by the owning Workspace's current tier, §16), and initial period boundaries for one Business, in one transaction, **idempotently**: it first checks whether a wallet already exists for `$businessId` and no-ops if so, making it safe to invoke more than once for the same Business without effect.

**Invocation — a proven idempotent after-commit mechanism, not a single fragile call site:** `App\Listeners\Usage\InitializeUsageWalletForNewBusiness` (§29) subscribes to **both** `BusinessCreated::class` and `BusinessAssignedToWorkspace::class` — the two confirmed, currently-real Business-creation events — and calls `initializeForNewBusiness()` from each, relying on the method's own idempotency to make a Business created via one path (dispatching only one of the two events) fully covered, and a hypothetical future path that somehow dispatched both events for the same Business safe rather than double-initializing.

**Explicit residual-risk statement, honestly recorded rather than silently assumed away:** this design covers every Business-creation path confirmed by direct code read at this round's base commit. **The M1/M2 implementation contract is explicitly required to re-verify, via its own fresh code audit at implementation time, that no third Business-creation path exists that dispatches neither `BusinessCreated` nor `BusinessAssignedToWorkspace`** — if one is found, that path must call `initializeForNewBusiness()` directly, inline, within its own creation transaction, as an explicit additional invocation, not merely assumed covered by the listener alone. This document does not claim broader coverage than what it directly verified.

**DDL/data separation — unchanged.**

**Rollback safety — unchanged, now applying to 26 tables:** a `migrate:rollback` proceeding in exact FK-safe reverse order will mechanically drop every RFC-005 table and silently destroy any live data accumulated since deploy. The eventual deployment guide states this explicitly and offers RFC-004's three established recovery paths.

---

## 33. Security and PCI posture

**Corrected this round: payload retention is now executable, not merely described.** `payment_provider_events.payload_encrypted` is encrypted at rest and **nullable**, purged by `PurgeExpiredWebhookPayloads` (§21) after the retention window, leaving `payload_hash`/`state`/`provider_event_id` etc. permanently intact for idempotency/audit. Access to any still-present payload remains platform-administrator-only; ordinary logs never include a raw webhook body.

**Payment-method display — unchanged:** brand/last-four/expiry only, never a raw token, never rendered to any surface as anything else.

**Provider identity integrity — new this round:** `UNIQUE (provider, provider_customer_id)` and `UNIQUE (provider, provider_payment_method_id)` (§17.B) prevent the same Stripe Customer or PaymentMethod id from ever being attached to more than one local owner row — a real security/data-integrity property, not only a business-logic convention.

Secrets remain environment-only, per the already-established `config/services.php` pattern.

---

## 34. Observability and internal unit-economics controls

Unchanged from the prior round: `provider_cost_micro` is aggregated (never per-transaction, never customer-facing) into an admin-only dashboard implementing Human product requirement 7's internal cost-control target — never an automatic suspension/throttle trigger, never substituted for the customer-facing spend-cap/limit controls.

---

## 35. Exact test strategy

Every test class below is a **future milestone's** responsibility to write. **List expanded this round with every correction's own required coverage:**

- **Money/precision** — `UsageMoneyPrecisionTest`: the exact `bcRoundHalfUp()` algorithm (§10) at boundary values, including the sanity-ceiling rejection and zero-cent-rejection behavior.
- **Rate snapshot immutability** — unchanged.
- **Concurrent initial rate activation** — unchanged.
- **Deterministic tied-activation-timestamp lookup — new this round** — `UsageRateActivationTieBreakTest`: two activation rows sharing the same `activated_at` resolve deterministically via the `id`-descending tiebreaker (§11).
- **Classification transition audit — new this round** — `PlatformFeatureUsageClassificationTransitionTest`: `is_metered`/`active_rate_id` flips are recorded independently of `business_usage_rate_activations`, with mandatory actor/reason.
- **Reservation/commit/release lifecycle** — unchanged.
- **Cross-period reservations and counter reconciliation — new this round** — `UsagePeriodCrossBoundaryTest`: a reservation opened in period N and committed in period N+1 posts its `UsageCharge` with period N's `period_key`, never perturbs period N+1's cached counters, and is correctly reconstructed by reconciliation querying by `period_key` alone.
- **Multi-month dormancy rollover — new this round** — `UsagePeriodMultiMonthDormancyTest`: a wallet dormant 3+ periods jumps directly to the current period in one arithmetic step, never incrementally.
- **Reservation bucket delta/reconciliation, overage debt, refund/chargeback exceeding available, top-up clears debt first, outstanding debt denies reservations** — unchanged.
- **Reservation-triggered auto-recharge — new this round** — `ReservationTriggersAutoRechargeTest`: `reserve()` itself (not only `commit()`) correctly dispatches `EvaluateBusinessAutoRecharge` when it decreases available balance below threshold; a normal within-reservation `commit()` (no overage) does **not** re-trigger it.
- **Low-balance notification reset — new this round** — asserts `low_balance_notified_at` is cleared the moment a positive-delta entry brings the balance back above threshold.
- **Consecutive-recharge-failure counter — new this round** — `ConsecutiveRechargeFailureCounterTest`: asserts `business_usage_wallets.consecutive_recharge_failures` increments/resets exactly per §19's rule and correctly disables `auto_recharge_enabled` at the threshold.
- **Refund/chargeback never reopens recharge-cap headroom — new this round** — asserts `recharged_this_period_micro` is unaffected by a `Refund`/`DisputeChargeback` entry absent an explicit admin correction.
- **Exact RFC-004 nine-key set unchanged** — unchanged, retained.
- **Missing-wallet/currency coarse gateway behavior** — unchanged.
- **Cap enforcement, concurrency** — unchanged.
- **Payer-owner authorization for every charge-causing action — new this round, generalized from the prior round's narrower payer-change-only test** — `PayerConsentForChargeActionsTest`: a Workspace owner cannot initiate a top-up or enable auto-recharge when `payer_type = 'business'`; a direct Business owner cannot do either when `payer_type = 'workspace'`; Active Workspace Admin and Staff can do neither under any `payer_type`; a platform administrator can, only with a mandatory reason recorded.
- **Workspace instrument isolation** — unchanged.
- **Historical billing-contact snapshot immutability** — unchanged.
- **Credit-type distinction, add-on idempotency** — unchanged.
- **Webhook active lease vs. stale lease recovery — new this round** — `PaymentProviderEventClaimLeaseTest`: a worker holding a valid, unexpired lease blocks a second worker's claim of the same event; once the lease expires, a second worker's claim `UPDATE` correctly picks the row back up, with `attempts` incremented.
- **Failed-event replay/resume, out-of-order/conflicting events, provider/local amount/currency/customer mismatch** — unchanged, now asserted against the corrected claim-lease algorithm.
- **Max-attempt exhaustion — new this round** — asserts a `failed` event with `attempts >= 5` is never reclaimed by the automated worker and surfaces in the admin exhausted-events review queue (§24/§30).
- **Payload purge while preserving replay/idempotency state — new this round** — `WebhookPayloadPurgeTest`: after `PurgeExpiredWebhookPayloads` runs, `payload_encrypted` is null and `payload_purged_at` is set, while `provider_event_id`/`payload_hash`/`state` remain intact and the `UNIQUE (provider, provider_event_id)` constraint still correctly rejects a genuine replay of the now-purged event.
- **Provider-customer/payment-method uniqueness — new this round** — `ProviderIdentityUniquenessTest`: `UNIQUE (provider, provider_customer_id)` rejects attaching the same Stripe Customer id to a second owner; `UNIQUE (provider, provider_payment_method_id)` rejects the same PaymentMethod id under two instrument rows; detaching a `payment_provider_customers` row and creating a fresh `active` one for the same owner succeeds (the generated-column lifecycle fix, §17.B), while the detached row's `business_id`/`workspace_id` remain permanently intact.
- **`requires_action` auto-recharge behavior** — unchanged.
- **Stripe minimum and maximum enforcement — new this round** — `StripeAmountLimitsTest`: an amount below Stripe's documented minimum or above the eight-digit maximum is rejected locally (`amount_below_provider_minimum`/`amount_exceeds_provider_maximum`) before any outbound Stripe call.
- **Recurring renewal, `requires_action`, cancellation, proration, and recovery — new this round** — `AdditionalBusinessSlotAgreementRenewalTest`: a scheduled renewal correctly charges the saved Workspace instrument off-session; `requires_action` on a renewal behaves identically to §19's own handling; cancellation takes effect only at the current period's end; a mid-period increase produces a correctly-prorated one-time charge (asserted against the exact `bcRoundHalfUp()` computation); a `payment_lapsed` agreement automatically recovers on the next successful charge, whether scheduled or `admin_retry`.
- **Slot and renewal transition audits — new this round** — `AdditionalBusinessSlotTransitionAuditTest`: every state change in both `additional_business_slot_agreement_transitions` and `additional_business_slot_renewal_charge_transitions` is recorded with the correct `source`.
- **Slot payment/allocation saga, slot authority blocker** — unchanged.
- **Post-rollout Business initialization — new this round** — `NewBusinessUsageWalletInitializationTest`: a Business created via `BusinessManager`'s legacy-onboarding path (dispatching `BusinessCreated`) receives a wallet; a Business created via `WorkspaceManager::createBusinessInWorkspace()` (dispatching `BusinessAssignedToWorkspace`, not `BusinessCreated`) **also** receives a wallet; `initializeForNewBusiness()` invoked twice for the same Business (simulating both events firing) creates exactly one wallet, not two.
- **Cross-table Business/wallet mismatch rejection** — unchanged, now also covering `business_usage_wallet_billing_status_transitions`/`business_funding_attempts`.
- **Sensitive payload retention/redaction, provider-cost non-disclosure, invoice/receipt boundary, mechanical source-boundary test, webhook/provider fakes, database** — unchanged.
- **Gate shape — unchanged, retained correctly as six commands:** (1) focused Usage tests, (2) Entitlement, (3) Workspace, (4) Business, (5) Opportunity, (6) full suite.

---

## 36. Milestone decomposition

Each milestone below requires its own separately drafted, human-reviewed, merged implementation contract before work begins. **Content updated this round for the corrected table/state model; milestone count unchanged at six.**

1. **M1 — Wallet & Ledger Foundation.** Schema: `business_usage_wallets` (with `period_key`/`consecutive_recharge_failures`), `business_usage_ledger_entries` (delta model, `period_key`), `business_usage_reservations` (`period_key`), `business_usage_rates`, `business_usage_rate_activations`, `platform_feature_usage_classifications`, `platform_feature_usage_classification_transitions`. `UsageWalletManager` (reserve/commit/release/expire with the corrected period-key accounting, rate activation with the classification-lock algorithm, coarse-capacity evaluation, `initializeForNewBusiness()`). `App\Listeners\Usage\InitializeUsageWalletForNewBusiness`, subscribed to both confirmed Business-creation events. Real `RealUsageAuthorizationGateway` bound, every feature non-metered at launch. Backfill. `bcmath` extension confirmed enabled. No HTTP surface, no Stripe. Focused + concurrency tests, including the nine-denial-key regression test, concurrent-rate-activation test, and cross-period-reservation test.
2. **M2 — Budgets, Limits, Payer, and Billing Contact.** Schema: `business_feature_usage_limits`, `platform_feature_usage_safety_limits`, `business_usage_limit_transitions`, `business_usage_wallet_billing_status_transitions`, `business_billing_contacts`, `business_payer_assignments`, `business_payer_transitions`. `BillingProfileManager`. The full payer-consent authorization model (§16/§24), including the charge-causing-action gating. New permission category.
3. **M3 — Provider Customers, Instruments, and Stripe Integration.** Schema: `payment_provider_customers` (generated-column active-uniqueness lifecycle), `business_payment_instruments`, `business_funding_attempts`, `business_funding_attempt_transitions`, `payment_provider_events` (claim-lease fields). `PaymentInstrumentManager`. `PaymentProviderGateway`/`StripePaymentProviderGateway`, pinning an explicit Stripe API version, validating Stripe's documented minimum **and** eight-digit maximum. SetupIntent/instrument attachment. Manual top-up. Webhook endpoint with the corrected claim-lease/reconciliation algorithm. `ProcessPaymentProviderEvent` and `PurgeExpiredWebhookPayloads` jobs. Auto-recharge as the centralized after-commit trigger. **This milestone must not imply production tax posture may simply be deferred as a routine choice: whatever this milestone ships, production payment collection remains gated by §23's legal-determination requirement regardless of what M3 itself resolves or defers.**
4. **M4 — Additional-Slot Agreement and Add-ons.** Schema: `additional_business_slot_agreements`, `additional_business_slot_agreement_transitions`, `additional_business_slot_renewal_charges`, `additional_business_slot_renewal_charge_transitions`, `business_usage_addon_catalog`, `business_usage_addon_purchases`. Option A's full renewal/proration/dunning model. Requires, as preconditions: (a) a human-authorized resolution to the cross-RFC allocation blocker (or scoping to `allocation_pending` with manual admin completion, Option 1) and (b) an authorized RFC-004 catalog-pricing operator surface.
5. **M5 — Metered Feature Classification.** The first real feature(s) classified `is_metered = true` (candidate not named by this RFC — §39 item 11).
6. **M6 — Conformance, Deployment, and Tag.** Full conformance matrix, deployment guide (rollback-danger disclosure across all 26 tables), full six-gate regression, post-merge exact-tag-candidate gate, and — only after separate explicit human authorization — the proposed annotated tag `rfc-005-business-usage-billing-and-wallets`.

---

## 37. Acceptance criteria

Unchanged from the prior round: M1–M4 introduce no feature-accessibility change; M5, and only M5, changes accessibility for its own explicitly-named, human-approved metered feature(s); every other feature remains non-metered indefinitely; activating a feature always requires its own contract, an active rate, configured limits, a rollout plan, and passing tests.

At the RFC level, acceptance-complete only when: every table in §25 exists and is backfilled where required; `NullUsageAuthorizationGateway` has been replaced; the cross-RFC blocker has been resolved before M4 allocates any slot; at least one milestone's conformance document shows every §35 test class passing; and the M6 conformance matrix shows every item in §40 resolved.

---

## 38. Release/tag gate

Unchanged: no tag before M6; M6's post-merge exact-tag-candidate gate must pass before separate, explicit human authorization of the annotated tag `rfc-005-business-usage-billing-and-wallets`.

---

## 39. Open human decisions

Items 1–14 are carried forward unchanged from the prior round (renumbering avoided so every existing cross-reference remains valid); no new item is added this round — every item this round's corrections might otherwise have added (exact lease duration, exact max-attempts, exact retry counts, exact proration formula parameters) was instead resolved as an explicit category-3 recommendation directly in its own section, per the instruction to choose and fully define policy rather than leave it open wherever the evidence and inherited requirements permit a defensible default.

1. **Exact initial retail usage rates** per eventually-metered feature. **NON-IMPLEMENTATION-READY** until resolved.
2. **Exact default Business monthly spend cap.**
3. **Exact default per-feature limits.**
4. **Exact auto-recharge default threshold** within RFC-003 §26.2's locked "below $10." *Recommendation:* $5.00.
5. **Owner/operator complimentary Agency Workspace's metered-usage subsidy.**
6. **Invoice/tax/VAT operational provider and legal sufficiency** — a production-launch legal/compliance gate. **NON-IMPLEMENTATION-READY for production launch.**
7. **Timing of Agency client rebilling.**
8. **Exact v1 add-on roster and pricing.**
9. **Exact initial per-feature platform safety-limit ceilings.**
10. **v1 settlement currency and multi-currency scope.** *Recommendation:* USD only, `decimal_places = 2`.
11. **The first actual metered feature(s).** No candidate named by this RFC; M5's own contract names it.
12. **Exact default monthly auto-recharge cap.**
13. **Additional-slot agreement `payment_lapsed` grandfathered-capacity revocation policy.**
14. **The cross-RFC additional-slot allocation authority blocker itself.** A human must choose among the blocker's three options before M4's allocation step may be contracted.

---

## 40. Contract coverage matrix

Maps every mandatory area from the merged design contract's §5 (A–L) and every human product requirement to the exact section(s) of this RFC that resolve it. **Updated this round.**

| Contract area / requirement | RFC-005 section(s) |
|---|---|
| A. Scope and terminology | §5, §6 |
| B. Money and accounting invariants | §10, §11, §12 |
| C. Metering and authorization | §14 |
| D. Payer, payment instruments, billing contact | §16, §17 |
| E. Stripe/provider boundary; invoices/tax/receipts; SDK version audit | §20, §21, §23 |
| F. Auto-recharge and usage controls | §15, §19 |
| G. Additional-slot agreement; credits and add-ons | §18, §22 |
| H. Authority and isolation | §24 |
| I. Concurrency, idempotency, events | §29, §31 |
| J. Schema and migration safety | §25, §32 |
| K. HTTP/UI and operational surfaces | §30 |
| L. Testing and release plan | §35, §36, §37, §38 |
| Human requirement 1 — billing contact/config per Business | §17.A |
| Human requirement 2 — adjustable monthly usage budget/cap | §15 |
| Human requirement 3 — per-feature limits | §15 |
| Human requirement 4 — platform safety limit overrides customer limit | §15, §24 |
| Human requirement 5 — credits without cross-Business pooling | §18, §32 |
| Human requirement 6 — discrete paid add-ons designed or deferred | §18, §39 item 8 |
| Human requirement 7 — internal unit-economics target, non-retail, non-suspension | §11, §34, §39 item 1 |
| Owner/operator Agency metered-usage subsidy question | §39 item 5 |
| §4 items 1–16 (locked decisions) | §7 |
| Design-contract §6 gap rule | Applied throughout; open items in §39 |
| Cross-RFC allocation authority blocker | Cross-RFC implementation blocker section; §22; §39 item 14 |
| Corrected accounting/debt model | §10, §12 |
| Corrected denial-key discipline | §3, §14, §35 |
| **Complete schema contract (this round)** | §11–§23, §25 |
| **Missing transition-audit structures (this round)** | §14.1, §22, §25 |
| **Webhook claim/lease mechanics (this round)** | §21 |
| **Provider identity constraints/lifecycle (this round)** | §17.B |
| **Exact payment-limit and rounding arithmetic (this round)** | §10 |
| **Auto-recharge trigger/failure-state correctness (this round)** | §12, §19 |
| **Period/reservation accounting resolution (this round)** | §13, §15 |
| **Payer consent for every charge-causing action (this round)** | §16, §24 |
| **Recurring additional-slot provider model (this round)** | §22 |
| **Rate/classification determinism (this round)** | §11, §14.1 |
| **Ongoing Business creation coverage (this round)** | §32 |

No area in the merged contract's §5 A–L, and no human product requirement, is unaddressed by this table.

---

*End of RFC-005 design document. Every milestone named in §36 requires its own separate, human-reviewed, merged implementation contract before any code, migration, test, route, view, or Stripe/provider change may be written.*
