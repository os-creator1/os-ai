# RFC-005 — Business Usage Billing and Wallets

**Status: DRAFT — NOT IMPLEMENTATION-AUTHORIZED**
**Version: 1.4 (Final Surgical Patch)**

- Base SHA: `6ae00f8f88b1963c6d05a045f99f0ce42651d2eb` (`main`)
- Governing contract: `docs/automation/RFC-005-DESIGN-CONTRACT.md`, merged commit `186a82393577e9afc240d40b0ad8ade4c99d27d4`
- **This version was produced under a second one-time human governance override** — after the two normal correction rounds the merged design contract permits (`maximum_correction_rounds: 2`), and after a first remediation exception (v1.3, eleven defects, recorded below), a human authorized one final, narrowly scoped surgical patch covering four additional independently verified defects, recorded in the surgical-patch record immediately after the v1.3 remediation record below. Neither exception is "another correction round" — each is a distinct, one-time governance act.
- Merging this design document does **not** authorize RFC-005 Milestone 1 or any implementation, migration, test, route, view, Stripe/provider call, or billing behavior. Every milestone in §36 requires its own separately drafted, human-reviewed, merged implementation contract before any such work may begin.
- **Implementation readiness.** Four gates must each be independently satisfied before production payment collection under this design:
  1. **Additional-slot allocation authority** — a structural cross-RFC blocker (below), unresolved. `NON-IMPLEMENTATION-READY`.
  2. **RFC-004 catalog-pricing operator surface** — a repository-confirmed gap (§22), unresolved.
  3. **Production tax/VAT legal sufficiency** — a legal/compliance gate (§23), unresolved; this RFC is not legal advice.
  4. **Recurring additional-slot provider model** — resolved (Option A, §22).
  Every other `NON-IMPLEMENTATION-READY` marker in this document is an ordinary open product/commercial decision (§39), resolvable by a human decision alone — distinct from the four structural/legal gates above.

---

## Cross-RFC implementation blocker

**Preserved unchanged in substance across every round, including this remediation. No RFC-004 amendment is authorized or performed by this document.**

RFC-004 requires that additional-Business-slot allocation occur **only** through `EntitlementManager::setAdditionalBusinessSlots(Workspace $workspace, int $count, int $actorUserId, ?string $reason = null): WorkspacePlanAssignment` — the sole authoritative allocation mutation (locked decision 9). Re-confirmed by direct code read this round: that method's first authority check, immediately after acquiring the Workspace row lock, is `$this->assertPlatformAdministrator($actorUserId)` (`(bool) $this->userRepository->query()->whereKey($actorUserId)->value('is_admin')`, throwing `AuthorizationException` if false) — no lower-privilege entry point exists. RFC-004 §20/§18 is explicit that ordinary Workspace customers may **inspect** their allocation but may **never self-grant** a slot.

A customer-paid checkout whose webhook-driven completion calls `setAdditionalBusinessSlots()` using the purchasing customer's own action as the trigger has no real platform-administrator `$actorUserId` available to it — that call would fail authorization in production, exactly as RFC-004 designed.

This RFC does **not** invent a fake platform-administrator actor, bypass `EntitlementManager`, pass an unrelated admin's identity, or silently weaken RFC-004's authority check. Concrete options remain:

1. **Customer pays, then a real platform administrator manually reviews and allocates the slot** — via §22's saga. Requires zero RFC-004 change; immediately implementable.
2. **Keep the checkout/platform allocation platform-admin-initiated only** — no customer self-service purchase flow at all in v1.
3. **Recommended: a separate, explicitly human-authorized amendment to RFC-004** introducing a narrowly scoped, payment-proof-backed internal allocation entry point, preserving `EntitlementManager` as sole allocation authority, requiring a verified idempotent successful-payment record, recording the requesting customer separately from the system/payment actor, receiving its own contract/tests/review/tag decision entirely separate from this document.

**This RFC-005 design document does not authorize or perform that amendment.** No RFC-005 additional-slot implementation contract (M4, §36) may be drafted until a human explicitly chooses and authorizes one of these options. §22 designs the rest of the additional-slot payment flow in full and is implementation-ready **up to** the allocation step, which remains `NON-IMPLEMENTATION-READY`. Recorded in §39 as open item 14.

---

## Human-authorized remediation record

This round corrected eleven independently verified defects, under an explicit one-time human governance exception (not a third normal correction round):

1. **Committed-spend reconciliation formula corrected** (§13, §15) — the prior formula referenced `UsageCharge.available_delta_micro`, which is always `0`; the actual authoritative per-entry committed amount is now defined exactly.
2. **Calendar-month rollover replaces fixed-duration arithmetic** (§15) — months are 28–31 days and cross DST boundaries; period boundaries are now derived from the Business's timezone using genuine calendar-month construction, never a fixed `period_length`.
3. **Webhook claim termination mechanics completed** (§21) — the stale-`processing` reclaim branch now requires `attempts < max_attempts` like every other retryable branch; exact atomic updates defined for every terminal/retry outcome; `completed_at` replaces the ambiguous overload of `processed_at`.
4. **Business-initialization milestone ordering corrected** (§9, §28–§32) — wallet initialization (M1) and payer-assignment initialization (M2) are now separate idempotent operations, each introduced only by the milestone that creates its own table, reconciled by an M2 backfill and an extended listener.
5. **Provider consistency for payment instruments enforced at the schema level** (§17.B) — a composite FK now makes `business_payment_instruments.provider` disagreeing with its parent `payment_provider_customers.provider` a schema-level impossibility, not a manager-only convention; nullable-unique provider-object-reference indexes added (the resolution algorithm they support was itself corrected in the v1.4 surgical patch below, §17.C).
6. **Every remaining schema shorthand expanded** (§11–§23) — grouped `/`-columns with differing types, "see above"/"see §X" deferrals, and untyped nullable columns are now individually specified; every `id` column explicitly marked auto-increment.
7. **Enum-backed fields mechanically reconciled** (§26) — three previously-unnamed enum-backed concepts (`PaymentProvider`, `PaymentInstrumentType`, `SlotAgreementBillingCadence`) are now named and counted; the shared 4-value transition-source enum is consolidated and renamed `TransitionSource`.
8. **Add-on purchase state auditing completed** (§18) — `business_usage_addon_purchase_transitions` added; the document's "every mutable state has an append-only audit" claim is narrowed accurately for the small number of fields where a full transition table was not added.
9. **Recurring slot-agreement schema and idempotency finished** (§22) — an initial total-charge snapshot, a frozen requesting-contact snapshot on every renewal, an exact `cancel_at_period_end`/`cancellation_requested_at`/`cancellation_effective_at` cancellation model, exact-second proration arithmetic, a `charge_kind` dimension distinct from `initiated_by`, and a deterministic per-operation idempotency key preventing same-period collision on repeated mid-period increases.
10. **Platform-administrator charge authority narrowed** (§16, §19, §22, §24) — an administrator may resume/reconcile an already-payer-authorized attempt or issue an auditable credit, but may never originate a fresh stored-instrument debit solely by virtue of being an administrator.
11. **Acceptance/conformance wording corrected** (§37) — the impossible claim that any single milestone's conformance document proves every §35 test class is replaced with the accurate per-milestone/M6-aggregate framing.

---

## Human-authorized final surgical patch record (v1.4)

This patch corrected four additional, independently verified defects, under a second explicit one-time human governance exception. Localized edits only — no rewrite:

1. **Webhook subject routing corrected** (§17.C, §21, §35) — the design previously implied Stripe's own `event_type` could distinguish local billing purpose (auto-recharge vs. slot renewal, etc.), even naming fictional event types (`auto_recharge_intent`, `slot_initial_checkout`, `slot_renewal_intent`) that do not exist in Stripe's API. Corrected: `event_type` now determines only the provider-object's own lifecycle transition; an outbound Stripe `metadata` hint (`app_subject_kind`/`app_subject_id`/`app_operation_id`, set by the local system itself) is used, as an **untrusted routing hint only**, to load exactly one local record, which is then validated in full against the verified Stripe object before any mutation; missing/malformed/unknown/ambiguous/mismatched metadata causes zero mutation and routes to reconciliation. The prior round's cross-table uniqueness claim is retracted — per-table `UNIQUE` indexes remain, but never claimed to guarantee cross-table non-collision, which resolution never actually depends on.
2. **Schema expansion finished** (§11–§23) — every remaining grouped `/`-column (≈30 instances across 21 tables) split into individually specified rows; the one remaining ambiguous type, `payment_provider_events.payload_encrypted` (`text`/`blob`), resolved to one exact type, `LONGTEXT`. No table/model/repository count changed, since no design object changed — only presentation.
3. **Unmodeled counter-adjustment exception removed** (§13, §19, §35) — `committed_spend_this_period_micro` and `recharged_this_period_micro` are now stated as formula-derived cached values, **never** directly or manually mutated by anyone, including a platform administrator; the prior round's "an explicit `business_usage_limit_transitions` correction may adjust the cached counter" is withdrawn as a category error (that table audits *configured limit values*, not *derived usage counters*, and any direct edit would be overwritten by the next reconciliation pass regardless). An administrator's real, correctly-scoped lever remains changing the *configured* cap/limit value itself, which affects only future headroom, never rewrites history.
4. **Bounded retention for exhausted webhook payloads** (§21, §24, §30, §33, §35) — a new terminal `disposed` state (`ProviderEventState`, no new enum, one new case), with `disposed_at`/`disposed_by_user_id`/`disposition_note` columns, gives an exhausted (never-successfully-processed) event an explicit, bounded path to eventual payload purge; a `disposed` event can never re-enter processing (it matches no branch of the claim `WHERE` clause); a merely-exhausted-but-undispositioned event is never purged before an operator has had the chance to review it.

---

## 1. Purpose and problem statement

RFC-003 forward-declared, and RFC-004 explicitly deferred, a Business-scoped usage wallet, usage ledger, billing configuration, and monthly usage budget (RFC-003 §8), together with payer selection, auto-recharge, and Stripe usage-billing changes (RFC-003 §26.2; RFC-004 §19, §31). RFC-004 shipped the entitlement structure and reserved exactly one seam for this RFC: `UsageAuthorizationGateway::check(Business $business, PlatformFeature $feature): UsageAuthorizationResult`, called as the final step of `EntitlementManager::decide()`, currently bound to `NullUsageAuthorizationGateway`. RFC-005 designs the system that will eventually bind a real implementation to that seam.

The problem this RFC solves is narrower than "billing" in general: the legacy SMS-plan/subscription billing stack already exists and is explicitly out of scope; RFC-005 is a **new, Business-scoped, Stripe-first, usage-metered** billing system that must coexist with, and never be confused with, that legacy stack.

---

## 2. Governing RFC/contract evidence

1. `docs/automation/RFC-005-DESIGN-CONTRACT.md` — sixteen inherited locked decisions, human product requirements, mandatory A–L contents, the open-decision/gap rule, and governance restrictions.
2. RFC-004 — tag `rfc-004-plans-and-business-feature-entitlements` at `221e18f0...` — §13/§17/§18/§19/§20/§21/§31, `setAdditionalBusinessSlots()`'s authority gate (re-confirmed this round), and `BusinessManager`/`WorkspaceManager`'s two distinct Business-creation paths.
3. RFC-003 — §4, §8, §14.1, §19, §26.2, §27.
4. `AGENTS.md` — "Workspace authorization" and verification rules.

`docs/automation/AI-AUTONOMY-STATE.json` remains stale/historical and carries no authorization weight; left untouched.

---

## 3. Repository audit findings

Re-confirmed this round by direct code read (this remediation's own required preflight step), unchanged from prior rounds:

- `EntitlementManager::setAdditionalBusinessSlots()`'s `assertPlatformAdministrator()` gate — unchanged, the blocker's evidence stands.
- `EntitlementManager::decide()`'s exact nine denial keys — unchanged; the nine-key discipline remains self-imposed by `RealUsageAuthorizationGateway`.
- Exactly one call site dispatches `BusinessCreated` (`BusinessManager::applyIdentity()`'s CREATE branch); `WorkspaceManager::createBusinessInWorkspace()` dispatches `BusinessAssignedToWorkspace` instead, never `BusinessCreated`.
- **New this round:** `App\Models\Business` carries a `timezone` fillable attribute (confirmed by direct read, `Business.php`), grounding this RFC's repeated "the Business's configured timezone" claim (§15) in an actual, already-existing column rather than an assumed one.
- **New this round:** `App\Models\WorkspacePlanCatalog::$casts` (confirmed by direct read) casts only `tier` (to `WorkspacePlanTier`), `unlimited_business_slots`, and `is_active` to typed values — `billing_cycle` is a plain, **uncast string** column with no backing PHP enum anywhere in RFC-004. This directly grounds §26's finding that RFC-005's own `SlotAgreementBillingCadence` enum is genuinely new, not a reuse of an RFC-004 concept, since RFC-004 has no such enum to reuse.
- PHP/BCMath availability, Stripe SDK version, and every other repository fact from prior rounds remain accurate and unchanged.

No new conflict beyond the one recorded in the blocker above was found.

---

## 4. Goals

1. Give every Business an isolated usage wallet, append-only ledger, billing contact, and payer.
2. Meter only explicitly classified features.
3. Give the Business, and the platform, independent, non-collapsible controls over spend.
4. Make Stripe the sole v1 payment provider, behind a narrow boundary.
5. Never reuse the legacy multi-gateway stack by inference from a similar name.
6. Preserve a seam for Agency client rebilling without building it now.
7. Never invent a customer retail rate, a default cap value, or provider behavior.
8. Represent the wallet's accounting state with a model the ledger can actually reconstruct — **corrected this round: this now means the ledger's own delta columns, correctly interpreted per entry type (§13), not a formula that silently assumed the wrong column carries a given entry type's committed amount.**
9. Never expand `EntitlementManager::decide()`'s locked nine-key denial surface.
10. Every table, counter, transition, actor, and route this document proposes is fully and consistently specified — **this round closes the remaining instances where that was not yet true.**
11. Every commercially significant mutable state has a durable, append-only transition history, **or this document explicitly and accurately narrows that claim where it does not** (§18); every charge-causing action requires the actual payer's own consent — **and, new this round, a platform administrator's role alone is never itself that consent for originating a fresh charge** (§16, §24).
12. **Added this round:** calendar-correct time handling — no accounting period boundary is ever computed from a fixed-duration assumption that ignores real calendar-month/timezone/DST variation.

---

## 5. Non-goals and explicit deferrals

- Agency client rebilling execution (schema/service seam only — §16).
- Any second payment provider besides Stripe (§20).
- Any change to the legacy `Plan`/`Subscription` SMS-quota billing stack.
- Automated tax/VAT calculation as a legally-sufficient solution (§23).
- Multi-currency wallets in v1 (§39 item 10).
- A concrete v1 add-on roster beyond the schema seam and one worked example (§39 item 8).
- Selecting exact retail rates, exact default caps, or an exact auto-recharge threshold value (§39).
- Actual allocation of a paid additional Business slot through any customer-triggered code path, until the cross-RFC blocker is resolved (§22, §39 item 14).
- Stripe Billing's native Subscription/Invoice object model (§22 chooses Option A).
- **Added this round:** an unbounded platform-administrator charge-origination capability (§16/§24 now narrow this explicitly).

---

## 6. Terminology

- **Business wallet** — the single row per Business holding `available_balance`, `reserved_balance`, `debt_balance`, and its current accounting periods (§12).
- **Available / reserved / debt balance** — unchanged definitions (§12).
- **Period key** — an immutable snapshot, taken once at reservation-creation time, of which **calendar month** (in the Business's timezone) a reservation belongs to for accounting purposes — **corrected this round from an ambiguous fixed-duration "period" concept to an explicit calendar-month one** (§15).
- **Committed amount** — **new this round**, the exact per-entry-type formula (§13) used wherever "how much did this ledger entry commit" must be computed, replacing the prior round's incorrect formula.
- **Payer / Effective payer / payer consent** — unchanged, now extended to every charge-causing action (§16), **and, new this round, explicitly bounded against platform-administrator over-reach** (§16).
- **Funding source / payment instrument** — a stored Stripe PaymentMethod reference, owned via a provider customer record, **now schema-enforced to agree on `provider` with its owning record** (§17.B).
- **Funding attempt** — unchanged (§17.C).
- **Claim lease** — a time-bounded exclusive hold a worker takes on a `payment_provider_events` row, **corrected this round so a repeatedly-crashing lease is also subject to the maximum-attempts ceiling**, never reclaimed indefinitely (§21).
- **Additional-slot agreement** — the recurring billing relationship for a paid Core/Growth additional Business slot, Option A (§22), **now with an exact cancellation-effective-date and mid-period-proration model** (§22).

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

Five manager classes, each with a sole write authority (§28), each `DB::transaction()` + row lock + audit-trail + after-commit event on every mutation: `UsageWalletManager`, `BillingProfileManager`, `PaymentInstrumentManager`, `UsageBillingCheckoutManager`. A narrow interface, `PaymentProviderGateway` (§20), is the entire Stripe boundary, implemented only by `StripePaymentProviderGateway`.

**Corrected this round for the M1/M2 ordering fix (§32):** the Business-creation listener is `App\Listeners\Usage\InitializeBusinessUsageProfile` (renamed from the prior round's `InitializeUsageWalletForNewBusiness` to accurately reflect that, from M2 onward, it initializes more than the wallet alone), subscribed to both `BusinessCreated` and `BusinessAssignedToWorkspace`. **At M1**, its handler calls only `UsageWalletManager::initializeWalletForNewBusiness()` (the only table that exists yet). **At M2**, the same listener class is extended with one additional idempotent call to `BillingProfileManager::initializePayerAssignmentForBusiness()` — an ordinary, expected kind of incremental extension across milestones, not a violation of any path restriction this design document itself is bound by (that restriction governs which paths *this document's own drafting* may touch, not what a later, separately authorized implementation milestone may do to code an earlier milestone shipped).

Repository-per-table, exactly RFC-004's convention: one contract + one Eloquent implementation per table in §25.

---

## 10. Money representation and currency rules

**Recommendation unchanged: signed 64-bit integer "micro-units"** (1 unit = 1⁄1,000,000 of the currency's major unit), stored in `BIGINT` columns. PHP `float` is forbidden for any persisted or in-flight authoritative money value.

**Wallet buckets and ledger deltas — unchanged shape:** three independent, non-negative cached buckets; every ledger entry carries three signed delta columns (`available_delta_micro`, `reserved_delta_micro`, `debt_delta_micro`). **The exact formula for deriving a "committed amount" from those deltas per entry type is corrected this round — see §13, which is now this design's single authoritative source for that formula, referenced everywhere else rather than restated inconsistently.**

**Outstanding debt denies new reservations: yes**, evaluated immediately after `billing_status`.

**Currency scale:** v1 scopes every Business wallet to exactly one settlement currency (recommendation: USD, `decimal_places = 2`, §39 item 10).

### Exact integer arithmetic

**Prerequisite:** the `bcmath` PHP extension enabled (M1 contract confirms). `bcround()` (PHP 8.4+) is not available at this repository's confirmed PHP 8.2/8.3 target — no algorithm below relies on it.

**Round-half-up via BCMath, applied only to non-negative magnitudes:**

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
    return bcadd($shifted, '0.5', 0);
}
```

**Applied arithmetic — unchanged:** `quantity_micro`/`charge_micro` computation, the `10^15` sanity ceiling, single-application rounding, Stripe-cent conversion (`bcRoundHalfUp($retail_amount_micro, '10000', 0)` for USD), zero-cent rejection.

**Stripe minimum/maximum payment handling — unchanged from the prior round's correction:** Stripe's PaymentIntent `amount` currently supports up to **eight digits** in the currency's smallest unit; `UsageBillingCheckoutManager` validates every outbound amount against both the documented minimum and this maximum, for the pinned API version and currency, before every outbound call — never assumed unbounded. The exact numeric values are re-confirmed against Stripe's own current documentation at M3 implementation time.

---

## 11. Rate catalog, customer charges, provider costs, and rate snapshots

Rate rows are fully immutable; activation is resolved via a lockable classification-row pointer; historical lookup is deterministic under a timestamp tie.

**`business_usage_rates`** (fully immutable; every row, once inserted, is never updated):

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `feature_key` | `string(64)` | No | — | `PlatformFeature`-backed value |
| `version` | `unsigned int` | No | — | starts at 1 per `feature_key`, computed under the classification-row lock |
| `retail_rate_micro` | `bigint unsigned` | No | — | customer-facing price per unit |
| `provider_cost_micro` | `bigint unsigned` | No | — | internal estimated provider cost per unit — admin-only (§34) |
| `unit_label` | `string(64)` | No | — | e.g. `"per message"` |
| `rounding_rule` | `string(32)`, enum-backed (`RoundingRule`) | No | `round_half_up` | only value defined for v1 |
| `currency_id` | `unsignedBigInteger`, FK `currencies.id`, `restrictOnDelete()` | No | — | must match the wallet's v1 settlement currency |
| `created_by_user_id` | `unsigned bigint`, no FK | No | — | actor column convention |
| `created_at` | `timestamp` | No | `now()` | the only timestamp this table has — nothing on this row is ever mutated |

Indexes: `UNIQUE (feature_key, version)`. Sole write authority: `UsageWalletManager`.

**`business_usage_rate_activations`** — append-only, the sole record of "what rate was active for a feature, and when":

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `feature_key` | `string(64)` | No | — | indexed |
| `rate_id` | `unsignedBigInteger`, FK `business_usage_rates.id`, `restrictOnDelete()` | No | — | |
| `activated_at` | `timestamp` | No | `now()` | |
| `activated_by_user_id` | `unsigned bigint`, no FK | No | — | |
| `reason` | `text` | No | — | mandatory |
| `created_at` | `timestamp` | No | `now()` | |

**Deterministic historical lookup:** `SELECT ... WHERE feature_key = X AND activated_at <= T ORDER BY activated_at DESC, id DESC LIMIT 1`. **`id` (an explicit auto-increment monotonic sequence) is the required tiebreaker** whenever two activation rows share the same `activated_at` value.

**`UsageWalletManager::setActiveRate()` — the race-free algorithm, unchanged:**

1. `DB::transaction()`. 2. Lock `platform_feature_usage_classifications`' row for `feature_key` (always exists post-backfill). 3. Compute the next `version` under the lock. 4. Insert the immutable rate row. 5. Insert an activation row. 6. Update only the classification row's `active_rate_id` pointer. 7. Commit.

**Metering activation** (`UsageWalletManager::activateMetering()`) combines `setActiveRate()` with setting `is_metered = true`, requiring an active rate, a supported currency, an already-configured platform safety limit, and a mandatory actor/reason — and inserts a `platform_feature_usage_classification_transitions` row (§14.1) recording the `is_metered` flip independently of the rate-activation record.

**Snapshotting — unchanged in shape; exact per-column types given in §12/§13 rather than deferred here (this round's schema-completion correction).**

**Cost/margin visibility — unchanged:** `provider_cost_micro` is readable only by the platform-administrator authorization path (§24), enforced by a boundary test (§35).

---

## 12. Business wallet and append-only ledger invariants

**`business_usage_wallets`** — one row per Business. **Corrected this round: the prior draft's `spend_period_key`/`recharge_period_key` design is replaced by a genuine calendar-month model (§15), and the recharge-period columns — previously deferred as "see above" — are now individually specified:**

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `business_id` | `unsignedBigInteger`, FK `businesses.id`, unique, `restrictOnDelete()` | No | — | 1:1, Business-scoped |
| `currency_id` | `unsignedBigInteger`, FK `currencies.id`, `restrictOnDelete()` | No | — | wallet's single settlement currency |
| `available_balance_micro` | `bigint` | No | `0` | never negative (manager-enforced) |
| `reserved_balance_micro` | `bigint` | No | `0` | never negative |
| `debt_balance_micro` | `bigint` | No | `0` | never negative |
| `monthly_spend_cap_micro` | `bigint`, nullable | Yes | `NULL` | null = platform-safety-limit-bounded only |
| `spend_period_key` | `string(7)` | No | set at creation | e.g. `'2026-08'` — the Business-timezone calendar month this wallet's spend counters currently track (§15) |
| `spend_period_start_utc` | `timestamp` | No | set at creation | the UTC instant of local midnight, first day of `spend_period_key`'s month, in the Business's timezone |
| `spend_period_end_utc` | `timestamp` | No | set at creation | the UTC instant of local midnight, first day of the **following** month |
| `auto_recharge_enabled` | `boolean` | No | `false` | |
| `auto_recharge_threshold_micro` | `bigint`, nullable | Yes | `NULL` | required if enabled |
| `auto_recharge_amount_micro` | `bigint`, nullable | Yes | `NULL` | required if enabled |
| `monthly_recharge_cap_micro` | `bigint`, nullable | Yes | `NULL` | distinct from `monthly_spend_cap_micro` |
| `recharge_period_key` | `string(7)` | No | set at creation | independent calendar-month identity from the spend cap's (§15) |
| `recharge_period_start_utc` | `timestamp` | No | set at creation | same construction rule as `spend_period_start_utc`, applied to the recharge period |
| `recharge_period_end_utc` | `timestamp` | No | set at creation | same construction rule as `spend_period_end_utc` |
| `committed_spend_this_period_micro` | `bigint` | No | `0` | cached, current-period-only (§15); uses the corrected committed-amount formula (§13) |
| `reserved_spend_this_period_micro` | `bigint` | No | `0` | cached, current-period-only (§15) |
| `recharged_this_period_micro` | `bigint` | No | `0` | cached, current-period-only (§15) |
| `consecutive_recharge_failures` | `unsigned smallint` | No | `0` | incremented on each `failed`/`requires_action` auto-recharge outcome, reset to `0` on `succeeded` |
| `low_balance_notified_at` | `timestamp`, nullable | Yes | `NULL` | dedup window (§19) |
| `billing_status` | `string(16)`, enum-backed (`WalletBillingStatus`) | No | `active` | `active` \| `suspended` |
| `created_at` | `timestamp` | No | `now()` | the one legitimately mutable RFC-005 table |
| `updated_at` | `timestamp` | No | `now()` | |

Indexes: `UNIQUE (id, business_id)` — enables the composite foreign key on child tables.

**`billing_status` mechanism — unchanged:** transitions recorded in **`business_usage_wallet_billing_status_transitions`**:

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `wallet_id` | `unsignedBigInteger`, composite FK (with `business_id`), composite-protected (below) | No | — | |
| `business_id` | `unsignedBigInteger`, composite FK (with `wallet_id`), composite-protected (below) | No | — | |
| `from_status` | `string(16)` | No | — | |
| `to_status` | `string(16)` | No | — | |
| `source` | `string(24)`, enum-backed (`BillingStatusTransitionSource`) | No | — | `dispute_webhook` \| `admin_action` |
| `actor_user_id` | `unsigned bigint`, nullable, no FK | Yes | `NULL` | null for `dispute_webhook` |
| `reason` | `text` | No | — | mandatory |
| `created_at` | `timestamp` | No | `now()` | |

Sole write authority: `UsageWalletManager`.

**Tenancy-ID integrity ("composite-protected"):** every child table carrying **both** `business_id` and `wallet_id` declares a composite foreign key `(wallet_id, business_id) → business_usage_wallets(id, business_id)`. Tables genuinely composite-protected: `business_usage_ledger_entries`, `business_usage_reservations`, `business_usage_wallet_billing_status_transitions`, `business_funding_attempts`. Every other table carries a plain `business_id` FK only.

**`business_usage_ledger_entries`** — append-only, never updated or deleted after insert. **Corrected this round: the rate-snapshot column bundle, previously deferred as "see §11," is individually specified:**

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `business_id` | `unsignedBigInteger`, composite FK (with `wallet_id`), composite-protected | No | — | |
| `wallet_id` | `unsignedBigInteger`, composite FK (with `business_id`), composite-protected | No | — | |
| `entry_type` | `string(32)`, enum-backed (`UsageLedgerEntryType`) | No | — | twelve values, §13 |
| `available_delta_micro` | `bigint` (signed) | No | `0` | |
| `reserved_delta_micro` | `bigint` (signed) | No | `0` | |
| `debt_delta_micro` | `bigint` (signed) | No | `0` | |
| `gross_amount_micro` | `bigint unsigned`, nullable | Yes | `NULL` | informational only, never authoritative |
| `currency_id` | `unsignedBigInteger`, FK `currencies.id`, `restrictOnDelete()` | No | — | |
| `feature_key` | `string(64)`, nullable | Yes | `NULL` | usage-related entries only |
| `period_key` | `string(7)`, nullable | Yes | `NULL` | for `UsageCharge`/`UsageOverageCharge` entries, copied from the originating reservation's own `period_key` (§15); null for entries with no reservation origin |
| `quantity` | `decimal(14,6)`, nullable | Yes | `NULL` | the caller-supplied exact quantity |
| `rate_id` | `unsignedBigInteger`, FK `business_usage_rates.id`, nullable, `restrictOnDelete()` | Yes | `NULL` | populated for rate-involving entries only |
| `rate_version` | `unsigned int`, nullable | Yes | `NULL` | |
| `retail_rate_micro` | `bigint unsigned`, nullable | Yes | `NULL` | |
| `provider_cost_micro` | `bigint unsigned`, nullable | Yes | `NULL` | |
| `unit_label` | `string(64)`, nullable | Yes | `NULL` | |
| `rounding_rule` | `string(32)`, enum-backed (`RoundingRule`), nullable | Yes | `NULL` | |
| `reservation_id` | `unsignedBigInteger`, FK `business_usage_reservations.id`, nullable | Yes | `NULL` | |
| `funding_attempt_id` | `unsignedBigInteger`, FK `business_funding_attempts.id`, nullable | Yes | `NULL` | set for `PaidTopUp`/`AutoRecharge` entries |
| `correlation_key` | `string(191)`, unique | No | — | idempotency |
| `provider_reference` | `string(191)`, nullable | Yes | `NULL` | Stripe object id, when applicable |
| `actor_user_id` | `unsigned bigint`, nullable, no FK | Yes | `NULL` | null = system-generated |
| `reason` | `text`, nullable | Yes | `NULL` | mandatory (manager-enforced) for `ManualCredit`, `UsageChargeReversal`, `CorrectionReversal`, `Refund` |
| `reversed_entry_id` | `unsignedBigInteger`, self-referencing FK, nullable, `restrictOnDelete()` | Yes | `NULL` | set on `UsageChargeReversal`/`CorrectionReversal` rows |
| `created_at` | `timestamp` | No | `now()` | immutable |

**Entry types and their delta behavior — unchanged, twelve types (full table restated in §13's committed-amount section, since that is now the authoritative location for this table).**

**Centralized auto-recharge trigger — unchanged mechanism:** any ledger-entry insert with `available_delta_micro < 0` dispatches `EvaluateBusinessAutoRecharge` after commit; the same shared method clears `low_balance_notified_at` on a positive-delta recovery above threshold.

Each of the three wallet buckets remains an always-consistent cached aggregate of its matching delta column. No cross-Business or cross-currency transfer is ever expressible.

---

## 13. Reservation/commit/release/reversal state machines, and the authoritative committed-amount formula

**`business_usage_reservations`** (composite-FK protected on `(wallet_id, business_id)`). **Corrected this round: the `rate_id`/rate-snapshot bundle and `final_quantity`/`final_amount_micro` (both previously untyped) are individually specified:**

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `business_id` | `unsignedBigInteger`, composite FK (with `wallet_id`), composite-protected | No | — | |
| `wallet_id` | `unsignedBigInteger`, composite FK (with `business_id`), composite-protected | No | — | |
| `feature_key` | `string(64)` | No | — | |
| `period_key` | `string(7)` | No | — | snapshotted once at creation from the wallet's then-current `spend_period_key` (§15); immutable for this reservation's lifetime |
| `status` | `string(16)`, enum-backed (`UsageReservationStatus`) | No | `pending` | `pending` \| `committed` \| `released` \| `expired` |
| `reserved_amount_micro` | `bigint` | No | — | |
| `estimated_quantity` | `decimal(14,6)`, nullable | Yes | `NULL` | |
| `rate_id` | `unsignedBigInteger`, FK `business_usage_rates.id`, `restrictOnDelete()` | No | — | snapshot at reservation time |
| `rate_version` | `unsigned int` | No | — | |
| `retail_rate_micro` | `bigint unsigned` | No | — | |
| `provider_cost_micro` | `bigint unsigned` | No | — | |
| `rounding_rule` | `string(32)`, enum-backed (`RoundingRule`) | No | — | |
| `idempotency_key` | `string(191)`, unique | No | — | caller-supplied |
| `correlation_key` | `string(191)` | No | — | ties `Reservation`/`ReservationRelease`/`UsageCharge`/`UsageOverageCharge` rows together |
| `reserved_at` | `timestamp` | No | `now()` | |
| `expires_at` | `timestamp` | No | — | operation-defined TTL |
| `committed_at` | `timestamp`, nullable | Yes | `NULL` | exactly one of `committed_at`/`released_at` set on a terminal row |
| `released_at` | `timestamp`, nullable | Yes | `NULL` | exactly one of `committed_at`/`released_at` set on a terminal row |
| `final_quantity` | `decimal(14,6)`, nullable | Yes | `NULL` | set only on commit |
| `final_amount_micro` | `bigint`, nullable | Yes | `NULL` | set only on commit |

Once `status` is `committed`, `released`, or `expired`, the row is never reopened.

**Entry types and their delta behavior — twelve types, restated here as the authoritative source (moved from §12 this round for locality with the committed-amount formula that depends on it):**

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
| `UsageChargeReversal` | `+amt` | `0` | `0` | admin reverses a prior usage charge into wallet credit |
| `CorrectionReversal` | signed by context | signed by context | signed by context | corrects an erroneous prior entry |

### The authoritative committed-amount formula — corrected this round

**The prior round's reconciliation formula was mathematically wrong: it reconstructed committed spend from "`available_delta` contributions of `UsageCharge`/`UsageOverageCharge`," but `UsageCharge.available_delta_micro` is always `0` (its charge is represented by `-reserved_delta_micro`), and `UsageOverageCharge` may split its value between an available debit and new debt, so neither entry type's committed contribution is simply its `available_delta`.** Corrected, exact, per-entry-type formula:

- **`UsageCharge` committed amount** = `-reserved_delta_micro` (the entry's `reserved_delta_micro` is `-committed_portion`, so negating it yields the positive committed amount).
- **`UsageOverageCharge` committed amount** = `(-available_delta_micro) + debt_delta_micro` — since `available_delta_micro = -min(overage, avail)` and `debt_delta_micro = +max(0, overage-avail)`, this sum is exactly `min(overage,avail) + max(0,overage-avail) = overage`, regardless of how the overage split between an immediate available debit and new debt.
- No other entry type contributes to committed spend.

**Current-period committed spend** = the sum of the above two formulas' results, evaluated over every `UsageCharge`/`UsageOverageCharge` entry whose `period_key` equals the wallet's current `spend_period_key`.

**This formula is the single source of truth, applied identically everywhere committed spend is computed or cached:** the `commit()` algorithm's own cached-counter update (below), the reconciliation job's independent recomputation (§15), and any read-side "how much has this Business spent this period" query.

**Whether `UsageChargeReversal`, `Refund`, `DisputeChargeback`, or `CorrectionReversal` reopens spend-cap headroom — resolved this round: no, never.** None of these four entry types decrements `committed_spend_this_period_micro` — the cap was correctly consumed by the original `UsageCharge`/`UsageOverageCharge` event, and reversing/refunding/charging-back that money afterward does not retroactively un-consume the cap headroom it used at the time.

**Corrected this round: `committed_spend_this_period_micro` is a formula-derived cached value, never directly or manually mutated by anyone, under any circumstance — not even by a platform administrator.** The prior round's "an explicit, audited limit correction via `business_usage_limit_transitions` may adjust the cached counter" is withdrawn: `business_usage_limit_transitions` is **audit history for configured limit *values*** (the spend cap, a per-feature limit, the safety limit) — it is not, and was never meant to be, an authoritative source for directly overwriting a *derived usage counter*, and any such direct edit would in any case be silently overwritten the next time the reconciliation job (§15) recomputes the counter from the ledger, since the ledger — not the cached counter — is this design's actual source of truth. An authorized administrator's real lever is **`business_usage_wallets.monthly_spend_cap_micro`** itself (via `business_usage_limit_transitions`, mandatory reason, §15) — changing the *configured cap* changes available headroom **prospectively**, for future reservations, but never rewrites the formula-derived historical `committed_spend_this_period_micro` value. **Any future requirement for a genuine accounting-headroom adjustment (e.g., "credit this Business back $X of this period's already-consumed cap") would require a separately designed, separately authoritative model — a new, explicit ledger-adjacent mechanism, not a repurposed configuration-audit table — and is out of scope for this surgical patch.**

**Algorithms, corrected this round for the `period_key`, centralized-trigger, and committed-amount-formula rules:**

- **Reserve** — `UsageWalletManager::reserve()`. `DB::transaction()`, wallet row `findForUpdate()`. The wallet's period is lazily rolled over first (§15, calendar-month algorithm), then `period_key` is read from the now-current `spend_period_key` and stamped onto the new reservation immutably. Idempotency: caller-supplied `idempotency_key`. Steps: look up the active rate → compute `reserved_amount_micro` (§10) → evaluate, in order: `billing_status` → `outstanding_debt` → per-feature limit → Business spend cap → platform safety limit → available-balance sufficiency → insert the reservation row → insert the `Reservation` ledger entry → update wallet aggregates, including `reserved_spend_this_period_micro += reserved_amount_micro` → dispatch `EvaluateBusinessAutoRecharge` after commit. Result: the reservation id, or a stable denial reason.
- **Commit/finalize** — `UsageWalletManager::commit()`. Wallet row `findForUpdate()`. Steps: load the `pending` reservation → compare `final_amount_micro` against `reserved_amount_micro` (using the reservation's own snapshotted rate) → insert `UsageCharge` (period-keyed from the reservation) for `min(final, reserved)` → if `final > reserved`, additionally insert `UsageOverageCharge` (same `period_key`) for the overage → if `final < reserved`, additionally insert `ReservationRelease` for the unused portion → mark `committed` → **update `committed_spend_this_period_micro` by exactly the committed-amount formula above, and decrement `reserved_spend_this_period_micro` by the reservation's own `reserved_amount_micro`, ONLY IF the reservation's `period_key` equals the wallet's CURRENT `spend_period_key`** — a stale reservation from an already-rolled-over period commits its ledger entry with its own correct historical `period_key` but does not perturb the wallet's current-period cached counters. Idempotency: repeat commit on an already-`committed` reservation is a no-op.
- **Release** — `UsageWalletManager::release()`. Same current-period-only counter-decrement rule as commit, applied to `reserved_spend_this_period_micro` only (a release never contributes to committed spend).
- **Reservation expiry reconciliation** — `App\Jobs\Usage\ExpireStaleUsageReservations` finds `pending` reservations past `expires_at` and calls `release()`. Never auto-commits a stale reservation.

**Atomicity / no double-charge — unchanged.**

---

## 14. Metered-feature classification and usage authorization

**Unchanged in mechanism:** `RealUsageAuthorizationGateway::check()` is a coarse, non-mutating gate, always returning exactly `usage_unauthorized` externally on denial, delegating internally to `UsageWalletManager::evaluateCoarseCapacity()`, which returns an internal-only `UsageCapacityDecision` — never surfaced past the gateway boundary.

**14.1 Feature classification.** `platform_feature_usage_classifications`:

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `feature_key` | `string(64)`, unique | No | — | `PlatformFeature`-backed |
| `is_metered` | `boolean` | No | `false` | |
| `active_rate_id` | `unsignedBigInteger`, FK `business_usage_rates.id`, nullable, `restrictOnDelete()` | Yes | `NULL` | sole pointer §11's activation algorithm maintains |
| `updated_by_user_id` | `unsigned bigint`, no FK | No | — | |
| `created_at` | `timestamp` | No | `now()` | |
| `updated_at` | `timestamp` | No | `now()` | mutable only via `setActiveRate()`/`activateMetering()` |

Backfill: one row per existing `PlatformFeature` case, `is_metered = false`, `active_rate_id = null`. Sole write authority: `UsageWalletManager`.

**`platform_feature_usage_classification_transitions`** — append-only, tracking metering activation/deactivation independently from rate activation:

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `feature_key` | `string(64)` | No | — | |
| `from_is_metered` | `boolean` | No | — | |
| `to_is_metered` | `boolean` | No | — | |
| `from_active_rate_id` | `unsignedBigInteger`, nullable, FK `business_usage_rates.id` | Yes | `NULL` | recorded for context, never this table's own authority |
| `to_active_rate_id` | `unsignedBigInteger`, nullable, FK `business_usage_rates.id` | Yes | `NULL` | recorded for context, never this table's own authority |
| `actor_user_id` | `unsigned bigint`, no FK | No | — | |
| `reason` | `text` | No | — | mandatory |
| `created_at` | `timestamp` | No | `now()` | |

---

## 15. Monthly Business budget, per-feature limits, and platform safety limits

Three genuinely distinct, non-collapsible controls:

1. **Monthly Business spend cap** (`business_usage_wallets.monthly_spend_cap_micro`).
2. **Per-feature limits** — `business_feature_usage_limits`:

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `business_id` | `unsignedBigInteger`, FK `businesses.id`, `restrictOnDelete()` | No | — | plain FK — no `wallet_id` column on this table |
| `feature_key` | `string(64)` | No | — | |
| `monthly_limit_micro` | `bigint`, nullable | Yes | `NULL` | |
| `updated_by_user_id` | `unsigned bigint`, no FK | No | — | |
| `created_at` | `timestamp` | No | `now()` | |
| `updated_at` | `timestamp` | No | `now()` | |

Indexes: `UNIQUE (business_id, feature_key)`.

3. **Platform safety limit** — `platform_feature_usage_safety_limits`:

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `feature_key` | `string(64)`, unique | No | — | platform-scoped, not Business-scoped |
| `max_monthly_limit_micro` | `bigint` | No | — | |
| `updated_by_user_id` | `unsigned bigint`, no FK | No | — | |
| `created_at` | `timestamp` | No | `now()` | |
| `updated_at` | `timestamp` | No | `now()` | |

**`business_usage_limit_transitions`:**

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `business_id` | `unsignedBigInteger`, FK `businesses.id`, nullable, `restrictOnDelete()` | Yes | `NULL` | null only for `limit_type = platform_safety_limit` rows |
| `limit_type` | `string(24)`, enum-backed (`UsageLimitType`) | No | — | `business_spend_cap` \| `feature_limit` \| `platform_safety_limit` |
| `feature_key` | `string(64)`, nullable | Yes | `NULL` | set only for `feature_limit`/`platform_safety_limit` rows |
| `from_value_micro` | `bigint`, nullable | Yes | `NULL` | |
| `to_value_micro` | `bigint`, nullable | Yes | `NULL` | |
| `actor_user_id` | `unsigned bigint`, no FK | No | — | |
| `reason` | `text` | No | — | mandatory |
| `created_at` | `timestamp` | No | `now()` | |

**Evaluation order — unchanged:** structural entitlement → Business toggle → `billing_status` → `outstanding_debt` → per-feature limit → Business monthly spend cap → platform safety limit → (reserve path only) available-balance sufficiency.

### Calendar-month period accounting — corrected this round (replaces fixed-duration arithmetic)

**The prior round's rollover formula, `periods_elapsed = floor((now - period_start) / period_length)`, is invalid for calendar months: months vary from 28 to 31 days, and timezone/DST transitions produce unequal UTC durations for what is the "same" calendar month in local time.** Corrected: every period boundary is derived from genuine calendar-month construction in the Business's own timezone, not a fixed duration.

**Exact rollover algorithm**, evaluated under the wallet row's own lock, before any cap check runs, whenever `now() >= <period>_end_utc`:

1. Resolve the Business's configured timezone (`businesses.timezone`, confirmed to exist by direct code read this round, §3) — falling back to the platform default timezone if unset.
2. Convert `now()` to that timezone (Carbon's timezone-aware conversion, the Laravel-idiomatic mechanism already used throughout this repository).
3. Read the resulting local calendar year and month directly — no arithmetic on elapsed duration at all.
4. Construct the **local first instant** of that year/month (midnight, day 1) as `<period>_start`, and the local first instant of the **following** month as `<period>_end` — both computed via genuine calendar construction (which correctly handles 28/29/30/31-day months and any DST transition within the month, since it is never expressed as a fixed duration in the first place).
5. Convert both local instants to UTC for `<period>_start_utc`/`<period>_end_utc`.
6. Set `<period>_key` from the local year/month (e.g., `'2026-08'`).
7. Reset the matching cached counter(s) (`committed_spend_this_period_micro`+`reserved_spend_this_period_micro` for the spend period; `recharged_this_period_micro` for the recharge period) to zero.
8. Apply this same rule **independently** to the spend period and the recharge period — they are separate controls (locked decision 8) and may, in principle, be on different boundaries, though in practice both are typically established together at wallet creation.

**Multi-month dormancy — resolved cleanly by this construction, not merely bounded.** Because step 3 reads the calendar month **directly from `now()`** rather than incrementing forward from the stale `period_start`, a wallet dormant for any number of months — one or twelve — lands exactly in the correct current calendar month in a single `UPDATE`, with no iteration, no fixed-duration assumption, and no possibility of landing one or more months short.

**A Business timezone change affects the next period only** — it never retroactively recomputes an already-in-progress period's boundaries, since the current period's `_start_utc`/`_end_utc` were already fixed in UTC at the time they were last rolled over.

The scheduled `AdvanceUsagePeriodBoundaries` job remains optional proactive maintenance only, never required for correctness.

**Override/adjustment — unchanged:** mandatory actor/reason, recorded in `business_usage_limit_transitions`.

**Isolation test requirement — unchanged.**

---

## 16. Payer selection and Workspace fallback

Consent gates every charge-causing action, not only an explicit payer change (§24 extends this consistently).

**`business_payer_assignments`:**

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `business_id` | `unsignedBigInteger`, FK `businesses.id`, unique, `restrictOnDelete()` | No | — | plain FK, no `wallet_id` |
| `payer_type` | `string(16)`, enum-backed (`PayerType`) | No | — | `business` \| `workspace` \| `agency_rebill` (never activated in v1) |
| `effective_payment_instrument_id` | `unsignedBigInteger`, FK `business_payment_instruments.id`, nullable, `restrictOnDelete()` | Yes | `NULL` | starts null at creation regardless of `payer_type` default (§32) |
| `created_at` | `timestamp` | No | `now()` | |
| `updated_at` | `timestamp` | No | `now()` | |

**`business_payer_transitions`:**

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `business_id` | `unsignedBigInteger`, FK `businesses.id`, `restrictOnDelete()` | No | — | |
| `from_payer_type` | `string(16)`, enum-backed (`PayerType`) | No | — | |
| `to_payer_type` | `string(16)`, enum-backed (`PayerType`) | No | — | |
| `from_instrument_id` | `unsignedBigInteger`, nullable, FK `business_payment_instruments.id` | Yes | `NULL` | |
| `to_instrument_id` | `unsignedBigInteger`, nullable, FK `business_payment_instruments.id` | Yes | `NULL` | |
| `actor_user_id` | `unsigned bigint`, no FK | No | — | |
| `reason` | `text` | No | — | mandatory |
| `created_at` | `timestamp` | No | `now()` | |

**Payer-consent rules — unchanged:** setting `payer_type = 'workspace'` requires the Workspace owner or a platform administrator (mandatory reason); setting `payer_type = 'business'` requires the direct Business owner/customer or a platform administrator (mandatory reason); Active Workspace Admin and Staff can never change payer; no actor may select/charge an instrument owned by a different payer.

### Consent extended to every charge-causing action, and platform-administrator authority narrowed — corrected this round

**Any action that causes an actual charge attempt against a stored instrument — initiating a top-up, enabling or changing auto-recharge configuration, or initiating an additional-slot agreement checkout — requires the actor to hold the target-consent authority §16 defines for payer changes, evaluated against the wallet's current `payer_type`:**

- `payer_type = 'workspace'`: only the Workspace owner may initiate a charge-causing action. Active Workspace Admin, Staff, and the direct Business owner may not.
- `payer_type = 'business'`: only the direct Business owner/customer may initiate a charge-causing action. Workspace owner/Admin may not.
- Active Workspace Admin and Staff may never authorize a stored-instrument charge, under any `payer_type`.

**Corrected this round: a platform administrator's role alone is no longer sufficient to originate a brand-new charge-causing action.** The prior round's blanket "a platform administrator may initiate a charge-causing action on behalf of either payer type, with mandatory reason" is withdrawn and replaced with a narrower, precisely bounded support posture:

- **A platform administrator may resume or reconcile an already-created, payer-authorized local attempt** (e.g., manually re-triggering a stuck `business_funding_attempts`/`additional_business_slot_renewal_charges` row via `admin_retry`, §17.C/§22) — the payer's own original action already supplied the consent; the administrator is completing it, not originating it. Mandatory reason.
- **A platform administrator may issue an auditable manual or promotional credit** (§18) — this credits the wallet from the platform's own funds and never debits a customer's stored instrument, so it carries no consent question at all; unchanged.
- **A platform administrator may never originate a fresh stored-instrument debit — a new top-up, newly enabling auto-recharge, or a new additional-slot agreement checkout — solely because they are a platform administrator.** Doing so would create a new charge against a customer's payment method without that customer's own action ever having authorized it.
- **Any exceptional operator-initiated fresh debit (e.g., a support-requested manual top-up performed on a customer's explicit verbal/written request) would require its own separately designed and human-authorized policy** — this document does not grant that capability implicitly, and does not design it.
- This distinction — viewing/configuring non-payment limits versus authorizing/resuming money movement — remains structural: `UsageWalletManager::setFeatureLimit()`/`setSpendCap()` (no charge caused) are gated by the existing broader Business-scope authority (§24); `UsageBillingCheckoutManager::initiateTopUp()`/`configureAutoRecharge()`/`initiateSlotAgreementCheckout()` (each originates a charge) are gated by the payer-consent rule above, with **no** platform-administrator override for origination; `UsageBillingCheckoutManager::retryFundingAttemptAsAdministrator()`/`retrySlotRenewalAsAdministrator()` (each resumes an already-authorized attempt) remain platform-administrator-permitted, mandatory reason.

**Effective payer resolution algorithm — unchanged.**

**Payer change algorithm — `BillingProfileManager::changePayer()` — unchanged.**

---

## 17. Billing contact and payment instruments

### A. Billing contact

**`business_billing_contacts`:**

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `business_id` | `unsignedBigInteger`, FK `businesses.id`, unique, `restrictOnDelete()` | No | — | plain FK |
| `contact_user_id` | `unsignedBigInteger`, FK `users.id`, nullable, `restrictOnDelete()` | Yes | `NULL` | nullable to support independent contact data |
| `contact_name` | `string(191)`, nullable | Yes | `NULL` | required together with `contact_email` if `contact_user_id` is null (manager-enforced) |
| `contact_email` | `string(191)`, nullable | Yes | `NULL` | required together with `contact_name` if `contact_user_id` is null (manager-enforced) |
| `notification_opt_in` | `boolean` | No | `true` | |
| `updated_by_user_id` | `unsigned bigint`, no FK | No | — | |
| `created_at` | `timestamp` | No | `now()` | |
| `updated_at` | `timestamp` | No | `now()` | |

Sole write authority: `BillingProfileManager`.

### B. Provider customers and payment instruments — provider-consistency now schema-enforced (corrected this round)

**Corrected: the prior round denormalized `provider` onto `business_payment_instruments` (to support `UNIQUE(provider, provider_payment_method_id)` without a join) but never prevented that denormalized value from disagreeing with its parent `payment_provider_customers.provider` — a manager-only convention masquerading as a schema-level guarantee.** Resolved with the same composite-FK pattern already used for wallet/ledger tenancy protection (§12):

**`payment_provider_customers`:**

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `provider` | `string(16)`, enum-backed (`PaymentProvider`, new this round — §26) | No | `stripe` | |
| `business_id` | `unsignedBigInteger`, FK `businesses.id`, nullable, `restrictOnDelete()` | Yes | `NULL` | exactly one of `business_id`/`workspace_id` set (manager-enforced; `CHECK` where MySQL 8+ confirmed) |
| `workspace_id` | `unsignedBigInteger`, FK `workspaces.id`, nullable, `restrictOnDelete()` | Yes | `NULL` | |
| `provider_customer_id` | `string(191)` | No | — | the Stripe Customer id |
| `status` | `string(16)`, enum-backed (`ProviderCustomerStatus`) | No | `active` | `active` \| `detached` |
| `active_business_id` | `unsignedBigInteger`, nullable, generated/stored: `CASE WHEN status = 'active' THEN business_id ELSE NULL END` | Yes | — | the unique-index target |
| `active_workspace_id` | `unsignedBigInteger`, nullable, generated/stored: `CASE WHEN status = 'active' THEN workspace_id ELSE NULL END` | Yes | — | |
| `created_at` | `timestamp` | No | `now()` | |
| `updated_at` | `timestamp` | No | `now()` | mutable — `status` may change; `business_id`/`workspace_id` are never cleared on detach |

Indexes: `UNIQUE (provider, provider_customer_id)`; `UNIQUE (provider, active_business_id)`; `UNIQUE (provider, active_workspace_id)`; **`UNIQUE (id, provider)`** — **new this round** — trivially satisfiable (like `business_usage_wallets`' own `UNIQUE(id, business_id)`, §12), and the composite unique key the child-table composite FK below references.

**`business_payment_instruments`** — corrected this round with the provider-consistency composite FK:

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `provider_customer_id` | `unsignedBigInteger` | No | — | |
| `provider` | `string(16)`, enum-backed (`PaymentProvider`, reused) | No | `stripe` | denormalized, but no longer merely by convention — see the composite FK below |
| `provider_payment_method_id` | `string(191)` | No | — | Stripe PaymentMethod id — token reference only |
| `type` | `string(24)`, enum-backed (`PaymentInstrumentType`, new this round — §26) | No | `card` | |
| `brand` | `string(24)`, nullable | Yes | `NULL` | safe display metadata only |
| `last_four` | `string(4)`, nullable | Yes | `NULL` | |
| `expiry_month` | `unsigned tinyint`, nullable | Yes | `NULL` | card only |
| `expiry_year` | `unsigned smallint`, nullable | Yes | `NULL` | card only |
| `is_default` | `boolean` | No | `false` | one-default-per-provider-customer (locking algorithm below) |
| `status` | `string(16)`, enum-backed (`PaymentInstrumentStatus`) | No | `active` | `active` \| `detached` |
| `created_at` | `timestamp` | No | `now()` | |
| `detached_at` | `timestamp`, nullable | Yes | `NULL` | never deleted — detach only |

**Corrected foreign key: `(provider_customer_id, provider) → payment_provider_customers (id, provider)` — a composite FK, not a plain FK to `id` alone.** This makes an instrument row whose `provider` disagrees with its parent `payment_provider_customers.provider` a **schema-level impossibility** in InnoDB, exactly mirroring the wallet/ledger tenancy-ID protection pattern already established (§12) — no longer a claim resting on manager discipline alone.

Indexes: `UNIQUE (provider, provider_payment_method_id)`.

**One-default-instrument serialization:** `PaymentInstrumentManager::setDefaultInstrument()` locks the owning `payment_provider_customers` row before clearing any other instrument's `is_default` and setting the new default, in one transaction.

**Detach behavior and the ownership-authority split — unchanged.**

### C. Durable payment/funding-attempt model and unambiguous provider-object resolution

**`business_funding_attempts`** (composite-protected on `(wallet_id, business_id)`). **Corrected this round: `provider_session_or_intent_reference`, previously a plain nullable string with no uniqueness at all, now carries an explicit nullable-unique index — a verified provider object must never match two local attempts.**

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `business_id` | `unsignedBigInteger`, composite FK (with `wallet_id`), composite-protected | No | — | |
| `wallet_id` | `unsignedBigInteger`, composite FK (with `business_id`), composite-protected | No | — | |
| `purpose` | `string(24)`, enum-backed (`FundingAttemptPurpose`) | No | — | `manual_top_up` \| `auto_recharge` \| `addon_purchase` |
| `payer_type_snapshot` | `string(16)`, enum-backed (`PayerType`, reused, snapshot value — never re-derived) | No | — | |
| `billing_contact_name_snapshot` | `string(191)`, nullable | Yes | `NULL` | the contact as of this attempt, never a live lookup |
| `billing_contact_email_snapshot` | `string(191)`, nullable | Yes | `NULL` | the contact as of this attempt, never a live lookup |
| `provider_customer_external_id_snapshot` | `string(191)` | No | — | the Stripe Customer id at attempt time |
| `provider_customer_id` | `unsignedBigInteger`, FK `payment_provider_customers.id`, `restrictOnDelete()` | No | — | traceability only |
| `payment_method_display_snapshot` | `string(64)` | No | — | e.g. `"visa •••• 4242, exp 12/26"` |
| `requesting_actor_user_id` | `unsigned bigint`, nullable, no FK | Yes | `NULL` | `NULL` for genuine system-initiated attempts (`purpose = auto_recharge`) |
| `expected_currency_id` | `unsignedBigInteger`, FK `currencies.id`, `restrictOnDelete()` | No | — | |
| `expected_amount_micro` | `bigint` | No | — | |
| `local_idempotency_key` | `string(191)`, unique | No | — | the deterministic key derived for the outbound Stripe call |
| `provider_session_or_intent_reference` | `string(191)`, nullable, **unique** | Yes | `NULL` | **new this round: `UNIQUE`** — once populated, this Stripe object reference resolves to exactly one local attempt |
| `state` | `string(16)`, enum-backed (`FundingAttemptState`) | No | `created` | `created` \| `provider_pending` \| `requires_action` \| `processing` \| `succeeded` \| `failed` \| `canceled` \| `refunded` \| `disputed` |
| `failure_reason` | `text`, nullable | Yes | `NULL` | |
| `created_at` | `timestamp` | No | `now()` | |
| `updated_at` | `timestamp` | No | `now()` | mutable — full transition history below |

**`business_funding_attempt_transitions`** — append-only:

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `funding_attempt_id` | `unsignedBigInteger`, FK `business_funding_attempts.id`, `restrictOnDelete()` | No | — | |
| `from_state` | `string(16)`, enum-backed (`FundingAttemptState`) | No | — | |
| `to_state` | `string(16)`, enum-backed (`FundingAttemptState`) | No | — | |
| `source` | `string(24)`, enum-backed (`TransitionSource`, renamed this round — §26) | No | — | `sync_response` \| `webhook_event` \| `admin_action` \| `reconciliation_job` |
| `provider_event_id` | `unsignedBigInteger`, FK `payment_provider_events.id`, nullable | Yes | `NULL` | set when `source = webhook_event` |
| `actor_user_id` | `unsigned bigint`, nullable, no FK | Yes | `NULL` | |
| `created_at` | `timestamp` | No | `now()` | |

**Circular-FK correction — unchanged from the prior round:** `business_funding_attempts.addon_purchase_id` remains removed; `business_usage_addon_purchases.funding_attempt_id` (§18) is the sole authoritative direction.

**Provider-object resolution algorithm — corrected this round: `event_type` is never the local-subject discriminator.** The prior round's algorithm incorrectly implied that Stripe's own `event_type` (e.g. `payment_intent.succeeded`) could distinguish *which local workflow* an event belongs to, going as far as naming fictional event types (`auto_recharge_intent`, `slot_initial_checkout`, `slot_renewal_intent`) that do not exist in Stripe's API. **They do not exist, and this document no longer implies they do.** A provider `event_type` describes only the **provider-object's own lifecycle transition** (e.g. "this PaymentIntent succeeded") — the identical generic event type is emitted for entirely different local workflows (an auto-recharge PaymentIntent and a slot-renewal PaymentIntent both emit `payment_intent.succeeded`), so `event_type` alone can never resolve which local table, let alone which local row, an event concerns.

**Corrected resolution algorithm:**

1. When creating any outbound Checkout Session or PaymentIntent, the local system attaches Stripe's own `metadata` parameter with a **namespaced local routing hint**: `app_subject_kind` (one of `funding_attempt` \| `slot_agreement` \| `slot_renewal_charge`), `app_subject_id` (the local row's own primary key), and `app_operation_id` (that row's own `local_idempotency_key`, or `change_operation_id` for a `mid_period_increase` renewal charge). This metadata is echoed back verbatim on every webhook event Stripe sends for that object.
2. **This metadata is never authoritative.** It is consumed only as a routing hint for which single local table and row to load.
3. The webhook processor loads **exactly** the one local record the hint names (`app_subject_kind` selects the table, `app_subject_id` selects the row) — never a cross-table search, never a scan of any `UNIQUE`-indexed reference column looking for "any row that matches." Having loaded that one record, the processor then **validates every applicable persisted expectation on that record against the verified Stripe object** before any mutation is permitted:
   - the provider object identifier (the loaded record's own `provider_session_or_intent_reference` must equal the event's `provider_object_id`);
   - the provider customer;
   - the amount;
   - the currency;
   - the Business or Workspace scope the record belongs to;
   - the payment purpose or charge kind (`purpose`/`charge_kind`);
   - the persisted idempotency/operation identifier (`local_idempotency_key`/`change_operation_id`, matching `app_operation_id`);
   - the expected local state (the transition the event implies must be a valid forward transition from the record's current `state`, §21).
4. `event_type` is used **only** to determine which provider-object lifecycle transition occurred (succeeded/failed/requires_action/etc.) — it is never described, here or anywhere else in this document, as carrying the local billing purpose.
5. **Missing, malformed, unknown, ambiguous, or mismatched metadata causes no mutation of any kind** — no wallet, ledger, reservation, slot, agreement, or accounting effect of any sort. The event is instead marked `failed` (§21's claim/lease algorithm) and routed to reconciliation/operator review, exactly as an amount/currency/customer mismatch already is.

**Corrected uniqueness claim — the prior round's cross-table guarantee is retracted.** `business_funding_attempts`, `additional_business_slot_agreements`, and `additional_business_slot_renewal_charges` each still carry their own `UNIQUE`-indexed `provider_session_or_intent_reference` (or, for renewal charges, `provider_session_or_intent_reference`) — but that uniqueness is, and can only be, **per-table**: nothing in this schema prevents the same literal reference string from theoretically appearing in two different tables' own independent unique indexes, since a `UNIQUE` constraint has no cross-table reach. This was never actually a problem in practice, and remains none, **not** because of any cross-table schema guarantee (no such guarantee exists or is claimed), but because resolution never searches for "which table contains this reference" in the first place — the metadata hint always names the correct table before any lookup occurs, and step 3's persisted-value validation independently confirms the loaded record's own reference matches before any mutation proceeds.

**Historical snapshot rule and retention/privacy — unchanged.**

---

## 18. Manual credit, paid top-up, promotional credit, and add-ons

Unchanged in overall shape, entry-type delta behavior per §13. **Consent for initiating a top-up is gated by §16's charge-causing-action rule — including its platform-administrator narrowing.**

**`business_usage_addon_catalog`:**

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `addon_key` | `string(64)`, unique | No | — | |
| `display_name` | `string(191)` | No | — | |
| `price_micro` | `bigint` | No | — | |
| `currency_id` | `unsignedBigInteger`, FK `currencies.id`, `restrictOnDelete()` | No | — | |
| `fulfillment_mode` | `string(24)`, enum-backed (`AddonFulfillmentMode`) | No | — | `wallet_credit` \| `direct_deliverable` |
| `is_active` | `boolean` | No | `true` | |
| `created_at` | `timestamp` | No | `now()` | |
| `updated_at` | `timestamp` | No | `now()` | |

Not seeded at M4 launch (zero rows).

**`business_usage_addon_purchases`:**

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `business_id` | `unsignedBigInteger`, FK `businesses.id`, `restrictOnDelete()` | No | — | plain FK |
| `addon_key` | `string(64)` | No | — | |
| `price_micro` | `bigint` | No | — | snapshot at purchase time |
| `funding_attempt_id` | `unsignedBigInteger`, FK `business_funding_attempts.id`, unique, `restrictOnDelete()` | No | — | sole authoritative direction (§17.C) |
| `status` | `string(16)`, enum-backed (`AddonPurchaseStatus`) | No | `pending` | `pending` \| `completed` \| `failed` |
| `requested_by_user_id` | `unsigned bigint`, no FK | No | — | |
| `completed_at` | `timestamp`, nullable | Yes | `NULL` | |
| `created_at` | `timestamp` | No | `now()` | |

**`business_usage_addon_purchase_transitions`** — new this round, append-only, closing the gap where the prior round's "every mutable commercially significant state has an append-only audit" claim was false for this table:

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `purchase_id` | `unsignedBigInteger`, FK `business_usage_addon_purchases.id`, `restrictOnDelete()` | No | — | |
| `from_status` | `string(16)`, enum-backed (`AddonPurchaseStatus`) | No | — | |
| `to_status` | `string(16)`, enum-backed (`AddonPurchaseStatus`) | No | — | |
| `source` | `string(24)`, enum-backed (`TransitionSource`, reused) | No | — | |
| `provider_event_id` | `unsignedBigInteger`, FK `payment_provider_events.id`, nullable | Yes | `NULL` | |
| `actor_user_id` | `unsigned bigint`, nullable, no FK | Yes | `NULL` | |
| `failure_reason` | `text`, nullable | Yes | `NULL` | |
| `created_at` | `timestamp` | No | `now()` | |

`fulfillment_mode` makes the wallet-debit-vs-separate-SKU choice data-driven per add-on; the one worked v1 example (a purchasable audit) is recommended `direct_deliverable` (§39 item 8).

**Reviewing every other "mutable commercially significant state" claim this round, per the explicit instruction to provide the promised history or narrow the claim accurately — the following are deliberately, explicitly narrowed rather than left as an implied blanket guarantee:**

- **Wallet balances** (`available_balance_micro`/`reserved_balance_micro`/`debt_balance_micro`) — their full history **is** `business_usage_ledger_entries` itself (§12/§13); no separate transitions table is needed or added, since the ledger already is the append-only audit for every balance change.
- **`business_payment_instruments.is_default`/`status` (detach)** — **not** separately transition-audited by its own table in this document. The current `is_default`/`status` values, plus `detached_at`, are the extent of this history; a full multi-event log of every default/detach change is a bounded, separately-authorized future addition if ever needed, not something this document claims to already provide.
- **`payment_provider_customers.status`** (active/detached lifecycle, §17.B) — likewise not separately transition-audited by its own table; the row's own `status`/`updated_at` plus the never-cleared `business_id`/`workspace_id` history is the extent of what this document provides.
- **`additional_business_slot_agreements.payment_lapsed`** (§22) — tracked via `payment_lapsed_at`/`payment_lapsed_cleared_at` timestamp pair on the agreement row itself (§22), recording only the most recent lapse/clear instant, not a full multi-cycle history of every lapse-and-recovery — an explicit, bounded scope, stated here rather than implied.

**Cross-Business isolation — unchanged.**

---

## 19. Auto-recharge and low-balance behavior

**Trigger — unchanged, structural:** any ledger entry with `available_delta_micro < 0` dispatches `EvaluateBusinessAutoRecharge` after commit.

**`App\Jobs\Usage\EvaluateBusinessAutoRecharge`** algorithm — unchanged in shape.

**Consecutive-failure counter — unchanged:** `business_usage_wallets.consecutive_recharge_failures`, incremented on `failed`/`requires_action`, reset on `succeeded`; **3** is the recommended (category-3) disable threshold.

**`requires_action` handling — unchanged.**

**Low-balance notification dedup and reset — unchanged.**

**`recharged_this_period_micro` and refunds/chargebacks — corrected this round to match §13's withdrawal of the counter-adjustment exception:** never reopened by a `Refund`/`DisputeChargeback`, and — like `committed_spend_this_period_micro` — **never directly or manually mutated by anyone**, since it is equally a formula-derived cached value (the sum of `AutoRecharge` entries for the current recharge period), not an authoritative source in its own right. An administrator's real lever is `business_usage_wallets.monthly_recharge_cap_micro` (via `business_usage_limit_transitions`, mandatory reason) — changing the *configured cap* affects future recharge headroom prospectively, never the historical counter.

**Platform-administrator authority — corrected this round to match §16's narrowing:** an administrator may resume/retry an already-created, stuck auto-recharge attempt (`admin_retry`, mandatory reason), but may never unilaterally **enable** auto-recharge for a Business on the customer's behalf — enabling it authorizes an ongoing series of future off-session charges, which requires the actual payer's own action, not an administrator's alone.

**Zero-balance / reserved-balance behavior — unchanged.**

**Customer-configurable limits vs. platform safety limit — unchanged.**

---

## 20. Stripe/provider boundary

**Resolution unchanged: Stripe-only v1**, behind `PaymentProviderGateway`. Provider-customer ownership lives in `payment_provider_customers`, now with the composite-FK provider-consistency guarantee (§17.B).

**SetupIntent / instrument attachment — unchanged.**

**Checkout Session vs. PaymentIntent — unchanged:** top-up/add-on purchase as one-time Checkout Sessions; auto-recharge as an off-session PaymentIntent; the additional-slot agreement's initial charge as a Checkout Session with `setup_future_usage: 'off_session'`, every renewal as an off-session PaymentIntent (§22).

**Webhook verification — unchanged.**

**Stripe API version posture — unchanged.**

**Event persistence, replay, claim/lease mechanics, and idempotency — corrected this round; §21 is now the exact, complete algorithm, including the fix to the stale-processing reclaim branch's missing attempts bound.**

**Reconciliation — unchanged in scope, extended to stuck renewal charges alongside funding attempts and slot-agreement allocation.**

**No outbound Stripe call ever occurs while a database row lock is held — unchanged.**

**Test-mode separation — unchanged.**

**SDK version recommendation — unchanged.**

**Payment amount limits — unchanged: both Stripe's documented minimum and eight-digit maximum validated before every outbound payment.**

**Wording — unchanged: "effectively exactly-once local accounting effect under at-least-once delivery."**

---

## 21. Webhook verification, persistence, replay, and reconciliation

**Corrected across the two prior rounds and this surgical patch: the stale-`processing` reclaim branch requires `attempts < max_attempts`; exact atomic updates are defined for every terminal/retry outcome; the ambiguous `processed_at` is replaced by an explicit `completed_at` shared by both `processed` and `ignored` terminal states; and — new this round — an exhausted event now has an explicit, bounded terminal-disposition path so its encrypted payload cannot be retained indefinitely.**

**`payment_provider_events`:**

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `provider` | `string(16)`, enum-backed (`PaymentProvider`, reused) | No | `stripe` | |
| `provider_event_id` | `string(191)` | No | — | Stripe's own event id |
| `event_type` | `string(64)` | No | — | |
| `provider_object_id` | `string(191)` | No | — | the Checkout Session/PaymentIntent/etc. id the event concerns |
| `payload_encrypted` | `longtext`, encrypted at rest (Laravel's `encrypted` cast), nullable | Yes | — | **corrected this round — one exact type, resolving the prior round's ambiguous `text`/`blob`**: `LONGTEXT` (generous capacity for a JSON payload plus encryption overhead); purged after retention (below) |
| `payload_hash` | `string(64)` | No | — | SHA-256 of the raw verified payload — permanent, survives purge |
| `payload_purged_at` | `timestamp`, nullable | Yes | `NULL` | set when `payload_encrypted` is nulled by the retention job |
| `state` | `string(16)`, enum-backed (`ProviderEventState`) | No | `received` | `received` \| `processing` \| `processed` \| `failed` \| `ignored` \| `disposed` (**new this round** — the terminal dead-letter state, below) |
| `attempts` | `unsigned smallint` | No | `0` | incremented on every claim, whether fresh or reclaimed |
| `processing_started_at` | `timestamp`, nullable | Yes | `NULL` | set on every claim |
| `lease_expires_at` | `timestamp`, nullable | Yes | `NULL` | the claim lease; `NULL` outside of `state = 'processing'`, never left stale from a prior processing cycle |
| `last_attempt_at` | `timestamp`, nullable | Yes | `NULL` | set on every claim |
| `last_error` | `text`, nullable | Yes | `NULL` | |
| `received_at` | `timestamp` | No | `now()` | |
| `completed_at` | `timestamp`, nullable | Yes | `NULL` | replaces the prior round's ambiguous `processed_at` — set for **both** `processed` and `ignored` terminal states |
| `disposed_at` | `timestamp`, nullable | Yes | `NULL` | **new this round** — set when an exhausted event is formally closed out (below); distinct from `completed_at`, which this column never overloads |
| `disposed_by_user_id` | `unsigned bigint`, nullable, no FK | Yes | `NULL` | **new this round** — the reviewing administrator, where applicable; `NULL` if disposition occurs via an automated bounded process rather than a human review |
| `disposition_note` | `text`, nullable | Yes | `NULL` | **new this round** — a non-sensitive resolution note only; never the sensitive payload itself |

Indexes: `UNIQUE (provider, provider_event_id)`.

**Corrected claim/lease algorithm:**

1. On receipt, insert the event row (`state: received`, `attempts: 0`). `UNIQUE (provider, provider_event_id)` is the true-duplicate guard.
2. **Claim (atomic, single statement) — corrected this round, the stale-processing branch now bounded by the same attempts ceiling as every other retryable branch:**
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
       OR (state = 'failed' AND attempts < 5)                                    -- category-3 recommended max attempts
       OR (state = 'processing' AND lease_expires_at < NOW() AND attempts < 5)   -- corrected this round: stale-processing recovery is now also bounded
     )
   ```
   **This is the exact fix: the prior round's stale-processing branch, `state = 'processing' AND lease_expires_at < NOW()`, had no `attempts` bound at all — a payload that reliably crashes the worker every time it is claimed could be reclaimed forever, its lease repeatedly expiring, `attempts` incrementing without limit but never actually gating reclaim. It now gates identically to the `failed` branch.**
3. Processing resolves the local subject via §17.C's corrected algorithm: the event's own Stripe `metadata` (echoed from the local system's own outbound `app_subject_kind`/`app_subject_id`/`app_operation_id` hint) is consulted only as an untrusted routing hint to load exactly one local record — **never** via `event_type`, which carries only the provider-object's own lifecycle transition, never the local billing purpose. The loaded record is then validated in full against the verified Stripe object (provider object identifier, provider customer, amount, currency, Business/Workspace scope, purpose/charge kind, persisted idempotency/operation identifier, expected local state, §17.C). A mismatch, or missing/malformed/unknown/ambiguous metadata, marks the event `failed` and triggers reconciliation — with no wallet, ledger, reservation, slot, agreement, or accounting mutation of any kind.
4. On match, the event drives the local `state` transition only if it is a valid forward transition (§17.C/§19/§22's state tables). An invalid transition triggers reconciliation rather than blindly overwriting.
5. **Exact atomic updates for every terminal/retry outcome — new this round:**
   - **Successful processing:** `UPDATE ... SET state = 'processed', completed_at = NOW(), lease_expires_at = NULL, last_error = NULL WHERE id = ? AND state = 'processing'`.
   - **Intentional ignore:** `UPDATE ... SET state = 'ignored', completed_at = NOW(), lease_expires_at = NULL WHERE id = ? AND state = 'processing'`.
   - **Retryable failure:** `UPDATE ... SET state = 'failed', last_error = ?, lease_expires_at = NULL WHERE id = ? AND state = 'processing'`.
   - **Stale-processing recovery** is not itself a separate outcome — it is a re-claim via step 2's own corrected `UPDATE`, which re-enters `processing` with a fresh lease and incremented `attempts`, then proceeds through one of the three outcomes above.
6. **Max-attempt exhaustion:** once `attempts >= 5`, **neither** a `failed` row **nor** a stale-`processing` row matches any branch of the claim `WHERE` clause — both become permanently unreclaimed by the automated worker, surfaced together in the admin exhausted-events review queue (`WHERE (state = 'failed' AND attempts >= 5) OR (state = 'processing' AND lease_expires_at < NOW() AND attempts >= 5)`, §24/§30).
7. **Terminal disposition — new this round, closing the previously-unbounded retention gap for exhausted events.** An exhausted row (step 6) is not itself purgeable — it must first become genuinely terminal. A platform administrator (or, where the operator configures it, an automated bounded process — "where applicable" per the disposition record's own nullable `disposed_by_user_id`) reviews the exhausted event and dispositions it: `UPDATE payment_provider_events SET state = 'disposed', disposed_at = NOW(), disposed_by_user_id = ?, disposition_note = ? WHERE id = ? AND state IN ('failed', 'processing') AND attempts >= 5` — the same exhaustion guard as the review-queue query itself, so only a genuinely exhausted row can ever be dispositioned. **A `disposed` event can never re-enter processing** — `disposed` matches no branch of the claim `WHERE` clause (§21 step 2), so it is never, under any circumstance, silently picked back up for another retry attempt.
8. **Terminal `processed`/`ignored`/`disposed` events are never reclaimed.**
9. Downstream mutations remain independently idempotent on their own keys regardless of event arrival order.

**Executable payload purge — corrected this round to also cover exhausted, dispositioned events, closing the "retained indefinitely" gap.** `App\Jobs\Usage\PurgeExpiredWebhookPayloads` finds `processed`/`ignored`/**`disposed`** events past the configured retention window and sets `payload_encrypted = NULL`, `payload_purged_at = NOW()` — **an exhausted event's payload is purgeable only once it has both (a) reached `disposed` and (b) sat past the retention window; a merely-exhausted-but-not-yet-dispositioned `failed`/stale-`processing` row is never purged, so an unreviewed dead letter is never silently erased before an operator has had the chance to see it.** Purging always preserves, permanently, regardless of terminal state: `id`, `provider`, `provider_event_id`, `event_type`, `provider_object_id`, `payload_hash`, `state`, `attempts`, every timestamp column, a sanitized classification of `last_error` (never the raw payload), and — for `disposed` rows — `disposed_at`/`disposed_by_user_id`/`disposition_note`. This is exactly the permanent evidence needed for provider-event idempotency (`UNIQUE (provider, provider_event_id)` continues to reject a genuine replay of an already-purged event), event identity/hash verification, state/attempt history, and operator disposition — with only the sensitive payload itself ever removed.

---

## 22. Additional Business-slot agreement — Option A, with corrected schema, idempotency, and cancellation model

**RFC-004 describes charges for already-allocated additional Business slots as recurring. Option A — customer-present initial authorization with a saved Workspace instrument, followed by off-session PaymentIntent renewal charges — remains chosen over Stripe's own native Billing Subscription object, for the reasons the prior round recorded (reuse of the same off-session-charge primitive §19 already needs).**

**Also carrying the cross-RFC blocker: the allocation step remains `NON-IMPLEMENTATION-READY`.**

**Operational prerequisite — unchanged:** an authorized RFC-004 catalog-pricing operator surface remains a prerequisite for this section's own M4.

**`additional_business_slot_agreements`** — full schema, corrected this round for the total-charge snapshot and the cancellation model:

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `workspace_id` | `unsignedBigInteger`, FK `workspaces.id`, `restrictOnDelete()` | No | — | Workspace-scoped, paid via a Workspace-owned instrument only |
| `current_allocation_count` | `unsigned tinyint` | No | — | billing-side bookkeeping view; RFC-004's `workspace_plan_assignments.additional_business_slots` remains sole authoritative entitlement value |
| `target_allocation_count` | `unsigned tinyint` | No | — | billing-side bookkeeping view; RFC-004's `workspace_plan_assignments.additional_business_slots` remains sole authoritative entitlement value |
| `paid_delta` | `unsigned tinyint` | No | — | `target - current` at agreement-creation time |
| `price_per_slot_micro_snapshot` | `bigint` | No | — | from `workspace_plan_catalog.price`/`additional_business_slot_price_ratio` at quote time |
| `total_amount_micro_snapshot` | `bigint` | No | — | **new this round** — `paid_delta × price_per_slot_micro_snapshot`, computed exactly once at quote time via §10's `bcRoundHalfUp()`; validated against the Checkout Session's actual confirmed amount before the agreement is allowed to advance past `payment_succeeded`; the sole value ever used for historical display — never re-derived from current catalog state |
| `currency_id_snapshot` | `unsignedBigInteger`, FK `currencies.id`, `restrictOnDelete()` | No | — | |
| `ratio_snapshot` | `decimal(6,4)` | No | — | |
| `plan_catalog_id_snapshot` | `unsignedBigInteger`, FK `workspace_plan_catalog.id`, `restrictOnDelete()` | No | — | which catalog was quoted against, at initial purchase |
| `plan_tier_snapshot` | `string(16)` | No | — | |
| `requesting_customer_user_id` | `unsigned bigint`, no FK | No | — | distinguished from the system/payment actor per the blocker's option 3 requirement |
| `requesting_customer_email_snapshot` | `string(191)` | No | — | this flow's billing-contact-equivalent snapshot, frozen at agreement creation |
| `provider_customer_id` | `unsignedBigInteger`, FK `payment_provider_customers.id` (Workspace-owned), `restrictOnDelete()` | No | — | |
| `payment_method_display_snapshot` | `string(64)` | No | — | |
| `local_idempotency_key` | `string(191)`, unique | No | — | |
| `provider_session_or_intent_reference` | `string(191)`, nullable, **unique** | Yes | `NULL` | the initial Checkout Session id |
| `billing_cadence` | `string(16)`, enum-backed (`SlotAgreementBillingCadence`, new this round — §26) | No | `monthly` | independent of RFC-004's own **uncast** `workspace_plan_catalog.billing_cycle` string (§3), which has no backing PHP enum to reuse |
| `next_renewal_at` | `timestamp`, nullable | Yes | `NULL` | **corrected this round — see the cancellation model below: no longer cleared merely by a cancellation request** |
| `cancel_at_period_end` | `boolean` | No | `false` | **new this round** |
| `cancellation_requested_at` | `timestamp`, nullable | Yes | `NULL` | **new this round** |
| `cancellation_effective_at` | `timestamp`, nullable | Yes | `NULL` | **new this round** — frozen, at the moment cancellation is requested, to whatever `next_renewal_at` already held |
| `payment_lapsed` | `boolean` | No | `false` | |
| `payment_lapsed_at` | `timestamp`, nullable | Yes | `NULL` | **new this round** — the audit for this flag, per §18's narrowed transition-history statement |
| `payment_lapsed_cleared_at` | `timestamp`, nullable | Yes | `NULL` | **new this round** |
| `state` | `string(20)`, enum-backed (`SlotAgreementState`) | No | `quote_created` | `quote_created` \| `checkout_pending` \| `payment_succeeded` \| `allocation_pending` \| `completed` \| `payment_failed` \| `allocation_failed` \| `refund_pending` \| `refunded` \| `canceled` |
| `created_at` | `timestamp` | No | `now()` | |
| `updated_at` | `timestamp` | No | `now()` | mutable — transitions recorded in `additional_business_slot_agreement_transitions` |

**`additional_business_slot_agreement_transitions`** — unchanged in shape:

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `agreement_id` | `unsignedBigInteger`, FK `additional_business_slot_agreements.id`, `restrictOnDelete()` | No | — | |
| `from_state` | `string(20)`, enum-backed (`SlotAgreementState`) | No | — | |
| `to_state` | `string(20)`, enum-backed (`SlotAgreementState`) | No | — | |
| `source` | `string(24)`, enum-backed (`TransitionSource`, renamed) | No | — | |
| `provider_event_id` | `unsignedBigInteger`, FK `payment_provider_events.id`, nullable | Yes | `NULL` | |
| `actor_user_id` | `unsigned bigint`, nullable, no FK | Yes | `NULL` | |
| `created_at` | `timestamp` | No | `now()` | |

**`additional_business_slot_renewal_charges`** — full schema, corrected this round for the frozen contact snapshot, exact-second proration inputs, `charge_kind`, and the deterministic per-operation idempotency key:

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `agreement_id` | `unsignedBigInteger`, FK `additional_business_slot_agreements.id`, `restrictOnDelete()` | No | — | |
| `charge_kind` | `string(24)`, enum-backed (`SlotRenewalChargeKind`, new this round — §26) | No | — | `scheduled_renewal` \| `mid_period_increase` — **orthogonal to `initiated_by` below**: an `admin_retry` may retry either kind |
| `period_start` | `timestamp` | No | — | for `mid_period_increase`, narrowed to the remainder of the current period; an exact UTC instant, matching the calendar-month boundaries the agreement's own governing period uses (§15's construction, applied at the Workspace level) |
| `period_end` | `timestamp` | No | — | for `mid_period_increase`, narrowed to the remainder of the current period; an exact UTC instant, matching the calendar-month boundaries the agreement's own governing period uses (§15's construction, applied at the Workspace level) |
| `amount_micro_snapshot` | `bigint` | No | — | |
| `requesting_customer_email_snapshot` | `string(191)` | No | — | **new this round** — **frozen and inherited from the parent agreement's own value at charge-creation time, never independently re-snapshotted at each renewal**, since "who originally requested this agreement" is a historical fact about the relationship's start, not something that should drift per renewal (contrast with pricing/payment-method snapshots below, which correctly reflect current state at each renewal) |
| `payer_type_snapshot` | `string(16)`, enum-backed (`PayerType`, reused) | No | `workspace` | always `workspace` for this flow, stated explicitly for completeness |
| `provider_customer_external_id_snapshot` | `string(191)` | No | — | |
| `payment_method_display_snapshot` | `string(64)` | No | — | |
| `currency_id_snapshot` | `unsignedBigInteger`, FK `currencies.id`, `restrictOnDelete()` | No | — | |
| `plan_catalog_id_snapshot` | `unsignedBigInteger`, FK `workspace_plan_catalog.id`, `restrictOnDelete()` | No | — | re-snapshotted at this renewal's own creation time, independent of the parent agreement's original values |
| `plan_tier_snapshot` | `string(16)` | No | — | |
| `ratio_snapshot` | `decimal(6,4)` | No | — | |
| `initiated_by` | `string(16)`, enum-backed (`SlotRenewalChargeInitiatedBy`, expanded this round — §26) | No | `scheduled_job` | `scheduled_job` \| `owner_initiated` \| `admin_retry` — **`owner_initiated` is new this round**, the missing initiation path for a synchronous mid-period-increase charge triggered directly by the Workspace owner's own action, distinct from a periodic `scheduled_job` renewal |
| `actor_user_id` | `unsigned bigint`, nullable, no FK | Yes | `NULL` | set for `owner_initiated`/`admin_retry` |
| `change_operation_id` | `string(191)`, nullable, **unique** | Yes | `NULL` | **new this round** — a UUID generated once by the customer-facing request handler before any charge-creation logic runs, for `mid_period_increase` charges only (`NULL` for `scheduled_renewal`); reused verbatim on any client retry of the *same* logical increase operation |
| `local_idempotency_key` | `string(191)`, unique | No | — | **corrected this round — deterministic derivation now branches on `charge_kind`:** for `scheduled_renewal`, `sha256(agreement_id . ':' . 'scheduled' . ':' . period_start_iso8601)`; for `mid_period_increase`, `sha256(agreement_id . ':' . 'increase' . ':' . change_operation_id)` — **this is the exact fix for the prior round's collision risk**: two distinct mid-period increases within the same billing period no longer risk colliding on `(agreement, period_start)` alone, since each increase's own `change_operation_id` — not a re-derived timestamp — anchors its key, while a genuine retry of the *same* increase (same `change_operation_id`, supplied by the client) correctly reuses the same key and is absorbed as a no-op |
| `provider_session_or_intent_reference` | `string(191)`, nullable, **unique** | Yes | `NULL` | the renewal's own PaymentIntent id |
| `state` | `string(16)`, enum-backed (`FundingAttemptState`, reused shape) | No | `created` | |
| `failure_reason` | `text`, nullable | Yes | `NULL` | |
| `created_at` | `timestamp` | No | `now()` | |

**`additional_business_slot_renewal_charge_transitions`** — unchanged in shape:

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `renewal_charge_id` | `unsignedBigInteger`, FK `additional_business_slot_renewal_charges.id`, `restrictOnDelete()` | No | — | |
| `from_state` | `string(16)`, enum-backed (`FundingAttemptState`, reused shape) | No | — | |
| `to_state` | `string(16)`, enum-backed (`FundingAttemptState`, reused shape) | No | — | |
| `source` | `string(24)`, enum-backed (`TransitionSource`, renamed) | No | — | |
| `provider_event_id` | `unsignedBigInteger`, FK `payment_provider_events.id`, nullable | Yes | `NULL` | |
| `actor_user_id` | `unsigned bigint`, nullable, no FK | Yes | `NULL` | |
| `created_at` | `timestamp` | No | `now()` | |

**Saga rule — unchanged:** `payment_succeeded` and `completed` are distinct; the agreement is never marked `completed` before allocation succeeds.

**Sequence — unchanged.** **Initial authorization and consent — unchanged**, only the Workspace owner (never a platform administrator originating fresh, per §16's narrowing — an administrator may only resume an already-`checkout_pending` agreement's stuck confirmation, never create a new one).

**Renewal initiation and `requires_action` handling — unchanged in shape**, now creating a `charge_kind: scheduled_renewal` row with `initiated_by: scheduled_job`.

### Cancellation — corrected this round with an exact model, replacing the prior round's premature `next_renewal_at = NULL`

**The prior round set `next_renewal_at = null` immediately upon a cancellation request — losing the already-known effective end-of-period instant and giving `InitiateSlotAgreementRenewal` no explicit signal to stop scheduling further renewals in the meantime.** Corrected:

1. On cancellation request (Workspace owner, or platform administrator with mandatory reason — **narrowed this round to a genuine cancellation of an existing, already-authorized agreement, not an origination**): set `cancel_at_period_end = true`, `cancellation_requested_at = now()`, `cancellation_effective_at = <the agreement's current next_renewal_at value>` — frozen at this exact moment, **`next_renewal_at` itself is left unchanged**.
2. `App\Jobs\Usage\InitiateSlotAgreementRenewal`'s own query is corrected to exclude `WHERE cancel_at_period_end = false` (equivalently, `AND NOT cancel_at_period_end`) — **no renewal is ever initiated for an agreement with a pending cancellation, even though `next_renewal_at` may still technically be non-null right up until the effective instant.**
3. A new job, `App\Jobs\Usage\FinalizeSlotAgreementCancellation`, finds agreements with `cancel_at_period_end = true AND cancellation_effective_at <= now() AND state != 'canceled'`, and — only then — sets `state: canceled`, `next_renewal_at: null`, recording the transition.
4. Already-allocated slots for the current, already-paid period remain until `cancellation_effective_at` genuinely passes.

### Proration for increases/decreases — corrected this round with exact-second arithmetic

**The prior round's "remaining days / total days in period" language was ambiguous and risked off-by-one/fractional-day error.** Corrected: proration uses **exact elapsed/remaining seconds** between the agreement's governing period's stored UTC boundaries (constructed via §15's calendar-month algorithm, applied at the Workspace level): `remaining_seconds = period_end_utc - now()`, `total_seconds = period_end_utc - period_start_utc`, `amount_micro_snapshot = bcRoundHalfUp(bcmul(price_per_slot_micro_snapshot × additional_slots, remaining_seconds), total_seconds)` — using §10's exact `bcRoundHalfUp()` on always-integer second counts, never a "days" approximation. This creates a `charge_kind: mid_period_increase` row (with its own `change_operation_id`-anchored idempotency key, above), `initiated_by: owner_initiated` (or `admin_retry` if a stuck attempt is later manually resumed). Decreasing `target_allocation_count` mid-period does not retroactively refund the current period's already-paid charge, but does reduce the amount charged at the next `scheduled_renewal`. Both directions are category-3 recommendations, explicitly adjustable with justification.

### Retries/dunning, `payment_lapsed`, and missed-period handling — corrected this round to close the "unbounded silent arrears" gap

A failed renewal charge triggers retry attempts (recommended: 3, category-3, mirroring §19). **While retries for a due period are in progress, `next_renewal_at` remains unchanged — still pointing at that same already-due period** — no new `scheduled_renewal` row is created for a later period until the current one either succeeds or is exhausted. After the final retry fails, `payment_lapsed = true` and `payment_lapsed_at = now()` are set, and **`next_renewal_at` is explicitly set to `null`** — no further automatic renewal attempts are scheduled while lapsed, so no unbounded sequence of missed-period charges can ever accumulate. **Recovery:** the moment any subsequent renewal charge succeeds (a manually-triggered `admin_retry`, or the Workspace owner's own `owner_initiated` retry after updating their payment method), `payment_lapsed = false` and `payment_lapsed_cleared_at = now()` are set, and **`next_renewal_at` is recomputed as one billing cadence forward from the recovery moment — never retroactively from the long-past missed period** — so a lapsed Workspace is never retroactively billed for every month it missed; missed periods are **skipped**, not accumulated, and no `additional_business_slot_renewal_charges` row is ever created for a period that was simply skipped due to lapse.

### Price changes apply only to future, properly-notified renewals — unchanged.

**Idempotency (initial purchase), complimentary/Agency-unlimited behavior, and the edge-case list — unchanged**, now grounded in the corrected schema above.

**Admin allocation action for the blocker's Option 1 — unchanged, already scoped correctly:** this action follows an already-succeeded payment and performs an allocation, not a fresh charge, so it is unaffected by this round's platform-administrator narrowing (§16) and remains platform-administrator-only, mandatory reason, the administrator's own real identity.

---

## 23. Refunds, disputes, chargebacks, invoices, receipts, and tax/VAT boundary

Unchanged: four structurally distinct entry types; Stripe-hosted receipts authoritative for v1; the legacy `invoices` table remains unreused; production tax/VAT posture remains `NON-IMPLEMENTATION-READY`, a legal/compliance gate — this RFC is not legal advice.

**`business_billing_receipts`:**

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `business_id` | `unsignedBigInteger`, FK `businesses.id`, `restrictOnDelete()` | No | — | plain FK |
| `ledger_entry_id` | `unsignedBigInteger`, FK `business_usage_ledger_entries.id`, `restrictOnDelete()` | No | — | |
| `provider_receipt_url` | `string(2048)` | No | — | |
| `provider_reference` | `string(191)` | No | — | |
| `created_at` | `timestamp` | No | `now()` | |

---

## 24. Authorization and tenant isolation

Five genuinely distinct authority paths. **Table corrected this round: every platform-administrator "Yes, mandatory reason" cell for a charge-origination action is narrowed to "No — resume/retry only" per §16/§19/§22's corrected posture; a new "resume/retry an already-authorized attempt" row makes the surviving administrator capability explicit.**

| Capability | Workspace owner | Active Workspace Admin | Staff | Direct Business owner/customer | Platform administrator |
|---|---|---|---|---|---|
| View balance/ledger for a Business in their Workspace | Yes | Yes, if `business_access_scope` covers that Business | Yes, if scope covers it | Yes, for their own Business only | Yes, any Business |
| Manage billing contact (non-payment) | Yes | Yes, if scope covers it | No | Yes, own Business | Yes |
| Manage a Business-owned payment instrument | No | No | No | Yes, own Business | Yes |
| Manage a Workspace-owned (shared) payment instrument | Yes | No | No | No | Yes |
| Set payer to `workspace` | Yes | No | No | No | Yes, mandatory reason |
| Set payer to `business` | No | No | No | Yes, own Business | Yes, mandatory reason |
| Originate a new top-up, when `payer_type = 'workspace'` | Yes | No | No | No | No — see "resume" row below |
| Originate a new top-up, when `payer_type = 'business'` | No | No | No | Yes, own Business | No |
| Newly enable/configure auto-recharge, when `payer_type = 'workspace'` | Yes | No | No | No | No |
| Newly enable/configure auto-recharge, when `payer_type = 'business'` | No | No | No | Yes, own Business | No |
| Originate a new additional-slot agreement checkout (always Workspace-owned instrument) | Yes | No | No | No | No |
| Resume/retry an already-created, payer-authorized attempt (funding attempt or slot renewal) | Yes, own attempt | No | No | Yes, own attempt | Yes, mandatory reason — the sole surviving admin charge-adjacent capability, since the payer's own prior action already supplied consent |
| Cancel an additional-slot agreement (effective at period end, §22) | Yes | No | No | No | Yes, mandatory reason |
| Configure Business spend cap / per-feature limits (non-payment) | Yes | Yes, if scope covers it, bounded by the platform safety limit | No | Yes, own Business, bounded by the platform safety limit | Yes, including the platform safety limit itself |
| Issue manual/promotional credit | No | No | No | No | Yes only |
| Set/clear `billing_status = 'suspended'` | No | No | No | No | Yes only |
| Perform the manual additional-slot allocation action (§22, blocker Option 1) — follows an already-succeeded payment, not a fresh charge | No | No | No | No | Yes only, mandatory reason, own identity — never a synthetic actor |
| View internal provider cost (`provider_cost_micro`) | No | No | No | No | Yes only |
| Review and disposition exhausted (max-attempts) webhook events — includes exhausted stale-`processing` rows alongside exhausted `failed` rows; disposition is the only path to eventual payload purge (§21) | No | No | No | No | Yes only |

Unrelated Workspace/Business resources fail closed with a 404-shaped response, never a 403. No raw query against any billing table is permitted outside its owning manager and repository, except an immutable migration/backfill script — enforced by a mechanical boundary test (§35).

**Permission category — unchanged:** `Business Usage Billing`.

---

## 25. Schema

**Full table list — 27 tables this round (up from 26: one new append-only transition table, `business_usage_addon_purchase_transitions`, item 8).** All `restrictOnDelete()` on tenancy-scoping foreign keys, never `cascade`; no native `ENUM` anywhere; composite foreign keys applied wherever genuinely needed: `(wallet_id, business_id) → business_usage_wallets(id, business_id)` (§12) and, **new this round**, `(provider_customer_id, provider) → payment_provider_customers(id, provider)` (§17.B).

| Table | Change this round | Backfilled? | Sole write authority |
|---|---|---|---|
| `business_usage_wallets` | recharge-period columns individually specified | Yes | `UsageWalletManager` |
| `business_usage_ledger_entries` | rate-snapshot columns individually specified | No | `UsageWalletManager` |
| `business_usage_reservations` | rate-snapshot/final columns individually specified | No | `UsageWalletManager` |
| `business_usage_rates` | unchanged | No | `UsageWalletManager` |
| `business_usage_rate_activations` | unchanged | No | `UsageWalletManager` |
| `platform_feature_usage_classifications` | unchanged | Yes | `UsageWalletManager` |
| `platform_feature_usage_classification_transitions` | unchanged | No | `UsageWalletManager` |
| `business_feature_usage_limits` | unchanged | No | `UsageWalletManager` |
| `platform_feature_usage_safety_limits` | unchanged | No | `UsageWalletManager` |
| `business_usage_limit_transitions` | unchanged | No | `UsageWalletManager` |
| `business_usage_wallet_billing_status_transitions` | unchanged | No | `UsageWalletManager` |
| `business_billing_contacts` | unchanged | No | `BillingProfileManager` |
| `business_payer_assignments` | unchanged | Yes, **at M2** (corrected this round, §32) | `BillingProfileManager` |
| `business_payer_transitions` | unchanged | No | `BillingProfileManager` |
| `payment_provider_customers` | `UNIQUE(id, provider)` added (§17.B) | No | `PaymentInstrumentManager` |
| `business_payment_instruments` | composite FK to `(id, provider)` replaces plain FK (§17.B) | No | `PaymentInstrumentManager` |
| `business_funding_attempts` | `provider_session_or_intent_reference` now unique | No | `UsageWalletManager` / `UsageBillingCheckoutManager` |
| `business_funding_attempt_transitions` | unchanged | No | `UsageWalletManager` |
| `additional_business_slot_agreements` | `total_amount_micro_snapshot`, cancellation-model, `payment_lapsed_at`/`_cleared_at` columns added | No | `UsageBillingCheckoutManager` |
| `additional_business_slot_agreement_transitions` | unchanged | No | `UsageBillingCheckoutManager` |
| `additional_business_slot_renewal_charges` | frozen contact snapshot, `charge_kind`, `change_operation_id`, corrected idempotency-key derivation | No | `UsageBillingCheckoutManager` |
| `additional_business_slot_renewal_charge_transitions` | unchanged | No | `UsageBillingCheckoutManager` |
| `business_usage_addon_catalog` | unchanged | No | `UsageBillingCheckoutManager` |
| `business_usage_addon_purchases` | unchanged | No | `UsageBillingCheckoutManager` |
| `business_usage_addon_purchase_transitions` | **new** | No | `UsageBillingCheckoutManager` |
| `business_billing_receipts` | unchanged | No | `UsageWalletManager` |
| `payment_provider_events` | `processed_at` renamed `completed_at`; stale-processing claim branch bounded by `attempts` | No | `UsageBillingCheckoutManager` |

Exact columns/types/constraints for each table are given in the section that introduced it (§11–§23). DDL and any data-only backfill operation remain separate migrations.

**`CHECK` constraints and generated/composite-FK columns** are recommended where the target MySQL version is confirmed 8.0+; the M1 contract confirms this, and confirms the `bcmath` PHP extension is enabled, before relying on any of them, falling back to manager-level enforcement where confirmation is not possible.

---

## 26. PHP enums/value objects/models

**Enums — corrected this round to 21 (net +4: `PaymentProvider`, `PaymentInstrumentType`, `SlotAgreementBillingCadence`, `SlotRenewalChargeKind`; one renamed for accuracy, no count change from the rename):** `UsageLedgerEntryType`, `UsageReservationStatus`, `WalletBillingStatus`, `UsageLimitType`, `PayerType`, `PaymentInstrumentStatus`, `ProviderCustomerStatus`, `FundingAttemptPurpose`, `FundingAttemptState`, `TransitionSource` (**renamed this round** from `FundingAttemptTransitionSource` — now explicitly shared, and reused as-is, by `business_funding_attempt_transitions`, `additional_business_slot_agreement_transitions`, `additional_business_slot_renewal_charge_transitions`, and `business_usage_addon_purchase_transitions`, rather than each minting its own duplicate 4-value enum), `BillingStatusTransitionSource` (kept distinct — a genuinely different 2-value set, `dispute_webhook`\|`admin_action`, never collapsible into `TransitionSource`), `AddonFulfillmentMode`, `AddonPurchaseStatus`, `SlotAgreementState`, `SlotRenewalChargeInitiatedBy` (expanded this round to three values: `scheduled_job`\|`owner_initiated`\|`admin_retry`), `ProviderEventState` (expanded this round to a sixth, terminal value: `received`\|`processing`\|`processed`\|`failed`\|`ignored`\|`disposed`, §21), `RoundingRule`, `PaymentProvider` (**new this round** — `stripe` only, v1; reused by `payment_provider_customers.provider`, `business_payment_instruments.provider`, `payment_provider_events.provider`), `PaymentInstrumentType` (**new this round** — `card` only, v1; `business_payment_instruments.type`), `SlotAgreementBillingCadence` (**new this round** — `monthly` only, v1; `additional_business_slot_agreements.billing_cadence` — confirmed genuinely new, not a reuse, since direct code read this round found RFC-004's own `workspace_plan_catalog.billing_cycle` is an uncast plain string with no backing PHP enum, §3), `SlotRenewalChargeKind` (**new this round** — `scheduled_renewal`\|`mid_period_increase`; `additional_business_slot_renewal_charges.charge_kind`, orthogonal to `initiated_by`).

**Mechanical reconciliation, per the explicit requirement:** every column marked "enum-backed" anywhere in §11–§23 now names its exact PHP enum inline, states whether that enum is new or reused, and is included in the count above — no "enum-backed" column remains unnamed.

**New readonly value objects — unchanged: `ReservationResult`, `CommitResult`, `EffectivePayer`, `CapEvaluation`, `UsageCapacityDecision`.**

**New Eloquent models — 27, one per table in §25**, each `casts` its enum columns.

---

## 27. Repository contracts

One contract + one Eloquent implementation per table in §25 — **27 pairs, 54 files** (corrected from the prior round's 26 pairs/52 files), bound in `AppServiceProvider` identically to RFC-004 M1's six-repository pattern.

---

## 28. Manager/domain authority

**Unchanged at five authorities**, with method-level additions this round: `UsageWalletManager` (wallets, ledger, reservations, rates/activations, classifications/classification-transitions, limits/limit-transitions, billing-status/transitions, receipts), `BillingProfileManager` (billing contact, payer assignment/transitions — **new this round: `initializePayerAssignmentForBusiness()`, §32**), `PaymentInstrumentManager` (provider customers, payment instruments), `UsageBillingCheckoutManager` (funding attempts' provider-facing leg, additional-slot agreements/renewals/their transition audits, add-on purchases, provider-event ingestion — **new this round: `retryFundingAttemptAsAdministrator()`, `retrySlotRenewalAsAdministrator()`, §16/§19/§22**), `StripePaymentProviderGateway`. No controller, job, or event listener ever writes to a table in §25 directly.

---

## 29. Jobs, events, notifications, and scheduling

**Jobs — corrected this round to thirteen (was twelve; one added):** `ExpireStaleUsageReservations`, `AdvanceUsagePeriodBoundaries` (proactive maintenance only), `EvaluateBusinessAutoRecharge`, `ProcessPaymentProviderEvent`, `ReconcileProviderPendingState`, `ReconcileSlotAgreementAllocation`, `InitiateSlotAgreementRenewal` (**corrected this round to exclude `cancel_at_period_end` agreements, §22**), `FinalizeSlotAgreementCancellation` (**new this round**, §22), `PurgeExpiredWebhookPayloads`, `SendLowBalanceNotification`, `SendAutoRechargeDisabledNotification`, `SendReceiptNotification`, `SendSlotAgreementPriceChangeNotice`. All `App\Jobs\Usage\*`, extending `App\Jobs\Base`, `ShouldQueue` + `ShouldQueueAfterCommit` where dispatched from within a request-handling transaction.

**Events — corrected this round to seventeen (was fifteen; two added):** `BusinessWalletCredited`, `BusinessWalletDebited`, `BusinessWalletDebtIncurred`, `BusinessWalletDebtCleared`, `BusinessUsageReserved`, `BusinessUsageCommitted`, `BusinessUsageReservationReleased`, `BusinessPayerChanged`, `BusinessBillingContactChanged`, `BusinessFundingAttemptSucceeded`, `BusinessFundingAttemptFailed`, `BusinessWalletBillingStatusChanged`, `AdditionalBusinessSlotAgreementCompleted`, `AdditionalBusinessSlotAllocationFailed`, `AdditionalBusinessSlotAgreementLapsed`, `AdditionalBusinessSlotAgreementCanceled` (**new this round**, §22's `FinalizeSlotAgreementCancellation`), `AdditionalBusinessSlotAgreementPaymentRecovered` (**new this round**, §22's lapse-recovery). All `App\Events\Usage\*`, `implements ShouldDispatchAfterCommit`, carrying IDs/scalars only.

**Listener — renamed this round for accuracy (§9/§32):** `App\Listeners\Usage\InitializeBusinessUsageProfile` (was `InitializeUsageWalletForNewBusiness`), subscribed to both `BusinessCreated` and `BusinessAssignedToWorkspace` — at M1, wallet-only; extended at M2 to also initialize the payer assignment.

**Scheduling — unchanged in shape.**

---

## 30. HTTP/admin/customer surfaces and permissions

**Customer surface — unchanged route shape.**

**Webhook route — unchanged.**

**Admin surface — corrected this round to reflect the narrowed charge-origination authority:** `Admin\UsageBillingController` (or similar): read balance/ledger/caps for any Business, issue manual/promotional credit, set/clear `billing_status`, set the platform safety limit, view (never edit) `provider_cost_micro` aggregates, **resume/retry an already-created, payer-authorized funding attempt or slot renewal (never originate a fresh one, §16/§19/§22)**, perform the manual additional-slot allocation action (using the administrator's own real identity), cancel an additional-slot agreement (mandatory reason), and review and disposition webhook events that have exhausted their claim/retry attempts — including exhausted stale-`processing` rows, not only exhausted `failed` rows — **with disposition (`disposed_at`/`disposed_by_user_id`/`disposition_note`) being the sole path to that event's eventual payload purge (§21).**

**Observability — unchanged.**

---

## 31. Concurrency, lock order, idempotency, and retry rules

**Canonical lock order — unchanged in shape.**

**Idempotency keys — extended this round:** every key from the prior round, plus `payment_provider_customers`'s `UNIQUE (id, provider)` (composite-FK enabler, §17.B), `business_payment_instruments`'s composite FK `(provider_customer_id, provider)`, `business_funding_attempts`/`additional_business_slot_agreements`/`additional_business_slot_renewal_charges`'s now-`UNIQUE` `provider_session_or_intent_reference` (§17.C/§22), and `additional_business_slot_renewal_charges.change_operation_id` (**new this round**, §22's collision fix for repeated mid-period increases).

**"Effectively exactly-once local accounting effect under at-least-once delivery" — unchanged, now additionally grounded in the corrected, uniformly-bounded claim/lease algorithm (§21).**

**Forced-race test scenarios — extended this round:** every scenario from the prior round, plus two workers racing to claim the same stale-`processing` (not only fresh-`received`) event row; two mid-period slot-allocation increases racing within the same billing period (must never collide on `local_idempotency_key`, §22).

---

## 32. Backfill, ongoing Business creation, rollout, compatibility, and rollback safety — corrected this round for the M1/M2 initialization-ordering contradiction

**Backfill for existing Businesses — corrected this round to split by milestone, resolving a real internal contradiction: the prior round's single `initializeForNewBusiness()` wrote to both `business_usage_wallets` (an M1 table) and `business_payer_assignments` (an M2 table) from a listener the M1 contract itself installs — meaning M1's own listener would attempt to write a table that does not exist until M2 deploys.**

**Corrected structure:**

1. **At M1:** `UsageWalletManager::initializeWalletForNewBusiness(int $businessId): void` — creates only `business_usage_wallets` (with its calendar-month period boundaries, §15), idempotently (checks for an existing wallet first). `App\Listeners\Usage\InitializeBusinessUsageProfile` (§9/§29) is introduced at M1, subscribed to both `BusinessCreated` and `BusinessAssignedToWorkspace`, its handler at this point calling only this one method — the only table that exists yet.
2. **At M2:** `BillingProfileManager::initializePayerAssignmentForBusiness(int $businessId): void` — creates only `business_payer_assignments` (defaulted by the owning Workspace's current tier, §16), idempotently. **The same listener class, `InitializeBusinessUsageProfile`, is extended with one additional idempotent call to this method** — an ordinary incremental extension of M1's own shipped code by the M2 milestone, not a violation of any path restriction (§9 explains this reasoning). **M2 additionally ships a one-time backfill migration** creating a `business_payer_assignments` row for every Business that already exists at M2 deploy time — covering the gap for any Business created between M1's deploy and M2's deploy, which received a wallet via the M1 listener but no payer assignment, since that table did not exist yet when it was created.
3. **After M2 deploys**, both confirmed Business-creation events invoke the one, now-fully-orchestrating listener, which idempotently ensures both the wallet and the payer assignment exist for every newly created Business — no method ever attempts to write a table before the milestone that creates it has shipped.

**Residual-risk statement — unchanged:** the M1/M2 implementation contracts are each explicitly required to re-verify, via their own fresh code audit at implementation time, that no third Business-creation path exists that dispatches neither `BusinessCreated` nor `BusinessAssignedToWorkspace`; if one is found, that path must call the relevant `initialize*()` method directly, inline, within its own creation transaction.

**Rollout — unchanged:** zero newly-gated features, zero newly-required payment setup at either M1 or M2.

**DDL/data separation — unchanged.**

**Rollback safety — unchanged, now applying to 27 tables.**

---

## 33. Security and PCI posture

Encrypted, purgeable webhook payloads; brand/last-four/expiry-only instrument display; secrets environment-only. **Corrected this round: an exhausted (never-successfully-processed) event can no longer retain its encrypted payload indefinitely.** Purging previously applied only to `processed`/`ignored` events — a repeatedly-failing or repeatedly-crashing event had no path to ever becoming purgeable at all. It now must first reach the terminal `disposed` state (an explicit review/disposition action, §21) before the standard retention-window purge applies to it — bounding retention for every event, including ones that never successfully processed, while still never purging an exhausted event before an operator has had the chance to review it. **Provider identity integrity extended this round:** `UNIQUE (provider, provider_customer_id)`, `UNIQUE (provider, provider_payment_method_id)`, and — **new this round** — the composite FK `(provider_customer_id, provider) → payment_provider_customers(id, provider)` together make it a schema-level impossibility for a stored instrument to ever be attributed to the wrong provider or the wrong owner's customer record, not merely a manager-enforced convention.

---

## 34. Observability and internal unit-economics controls

Unchanged.

---

## 35. Exact test strategy

Every test class below is a **future milestone's** responsibility to write. **List extended this round with every remediation's own required coverage:**

- **Money/precision, rate snapshot immutability, concurrent initial rate activation, deterministic tied-activation-timestamp lookup, classification transition audit** — unchanged.
- **Reservation/commit/release lifecycle** — unchanged.
- **Committed-spend formula correctness — new this round** — `UsageCommittedSpendFormulaTest`: a mixed sequence of exact-match commits, under-reservation commits, and overage commits (split across available and debt) reconstructs `committed_spend_this_period_micro` exactly via the corrected `-reserved_delta_micro` / `(-available_delta_micro)+debt_delta_micro` formula (§13), independently verified against a from-scratch ledger recomputation.
- **Spend/recharge cached counters are formula-derived and never manually mutated — corrected this round** — `UsageChargeReversalDoesNotReopenSpendCapTest`: a `UsageChargeReversal`/`Refund`/`DisputeChargeback`/`CorrectionReversal` entry never decrements `committed_spend_this_period_micro`, and no code path — including an administrator action — writes directly to `committed_spend_this_period_micro`/`recharged_this_period_micro` at all; the reconciliation job's independent recomputation from the ledger is asserted to match the cached value at every step, proving there is never a divergence a "manual correction" would need to paper over. `UsageCapConfigurationChangeIsProspectiveOnlyTest` — **new this round** — changing `monthly_spend_cap_micro`/`monthly_recharge_cap_micro` via `business_usage_limit_transitions` affects only future reservation/recharge admission decisions and never rewrites either historical cached counter.
- **Cross-period reservations and counter reconciliation** — unchanged, now asserted against the corrected formula.
- **Calendar-month rollover — corrected and expanded this round** — `UsageCalendarMonthRolloverTest`: explicit cases for a February rollover (28 and 29 days), a 31-day month, and a DST spring-forward/fall-back boundary in the Business's own timezone, each asserting the constructed `_start_utc`/`_end_utc` pair corresponds to genuine local calendar-month boundaries, never a fixed-duration approximation. `UsagePeriodMultiMonthDormancyTest`: a wallet dormant 3+ months lands directly in the correct current calendar month in one step.
- **Reservation bucket delta/reconciliation, overage debt, refund/chargeback exceeding available, top-up clears debt first, outstanding debt denies reservations** — unchanged.
- **Reservation-triggered auto-recharge, low-balance notification reset, consecutive-recharge-failure counter, recharge-cap never auto-reopened** — unchanged.
- **Exact RFC-004 nine-key set unchanged, missing-wallet/currency coarse gateway behavior, cap enforcement, concurrency** — unchanged.
- **Payer-owner authorization for every charge-causing action, including the narrowed platform-administrator posture — corrected and expanded this round** — `PayerConsentForChargeActionsTest`: a Workspace owner/direct Business owner cannot cross-authorize as before; **and, new this round, a platform administrator cannot originate a fresh top-up, auto-recharge enablement, or slot-agreement checkout under any `payer_type` — only resume an already-created attempt, asserted by attempting both and observing the origination attempt denied while the resume attempt (against a pre-existing attempt row) succeeds with a mandatory reason recorded.**
- **Workspace instrument isolation, historical billing-contact snapshot immutability, credit-type distinction, add-on idempotency** — unchanged.
- **Add-on purchase transition audit — new this round** — `AddonPurchaseTransitionAuditTest`: every `business_usage_addon_purchases.status` change is recorded in `business_usage_addon_purchase_transitions` with the correct `source`.
- **Webhook active lease vs. stale lease recovery, failed-event replay/resume, out-of-order/conflicting events, provider/local amount/currency/customer mismatch** — unchanged, now asserted against the corrected algorithm.
- **Max-attempt exhaustion, uniformly applied — corrected and expanded this round** — `WebhookClaimExhaustionTest`: a payload that reliably crashes the worker on every claim is reclaimed at most 5 times (the stale-`processing` branch's new `attempts` bound), then becomes permanently unreclaimed and surfaces in the admin exhausted-events queue — **the direct regression test for the exact defect this round fixed** (the prior round's stale-`processing` branch had no bound at all).
- **Terminal-outcome exactness — new this round** — `WebhookTerminalOutcomeTest`: `processed`/`ignored` both set `completed_at`, never overload `processed_at`'s prior ambiguous meaning; each terminal `UPDATE` clears `lease_expires_at`.
- **Terminal disposition and bounded retention for exhausted events — new this round** — `WebhookEventDispositionTest`: an exhausted `failed`/stale-`processing` event (`attempts >= 5`) can be dispositioned to `disposed` only from an exhausted state, records `disposed_at`/`disposed_by_user_id`/`disposition_note`, and — once disposed — never again matches the claim `UPDATE`'s `WHERE` clause under any circumstance (asserted directly, the regression test for "a disposed event must not silently re-enter normal retry processing"). `WebhookExhaustedPayloadPurgeTest` — a `disposed` event's encrypted payload is purged once past the retention window, exactly as `processed`/`ignored` payloads already are, while `id`/`provider_event_id`/`payload_hash`/`state`/`attempts`/every timestamp/`disposed_by_user_id`/`disposition_note` remain permanently intact and the `UNIQUE (provider, provider_event_id)` constraint still rejects a genuine replay after purge; a merely-exhausted-but-not-yet-`disposed` row is asserted **not** purged even past the same retention window.
- **Payload purge while preserving replay/idempotency state** — unchanged for `processed`/`ignored` events, now also covering `disposed` events per the test above.
- **Provider-customer/payment-method uniqueness, including composite-FK enforcement — corrected and expanded this round** — `ProviderIdentityUniquenessTest`: unchanged assertions, plus a new assertion that an attempted `business_payment_instruments` insert whose `provider` disagrees with its `provider_customer_id`'s actual `payment_provider_customers.provider` is rejected at the schema level by the composite FK, never merely by manager-side validation.
- **Provider-object resolution via untrusted metadata hint, never `event_type` — corrected this round** — `ProviderObjectResolutionTest`: a `provider_session_or_intent_reference` value is asserted unique within each of `business_funding_attempts`/`additional_business_slot_agreements`/`additional_business_slot_renewal_charges` independently (never claimed unique across tables); resolution loads exactly the one local record named by the event's `metadata` hint and never queries a second table; two identical generic `event_type`s (e.g. two `payment_intent.succeeded` events for an auto-recharge and a slot renewal) resolve to their own correct, distinct local records via the hint, never via the shared `event_type` alone. **`WebhookMetadataMismatchTest` — new this round** — missing, malformed, unknown, ambiguous, or mismatched metadata (including a hint naming a real local row whose own persisted amount/currency/customer/purpose/state disagrees with the verified Stripe object) produces zero wallet/ledger/reservation/slot/agreement/accounting mutation, marks the event `failed`, and routes it to reconciliation.
- **`requires_action` auto-recharge behavior, Stripe minimum and maximum enforcement** — unchanged.
- **Recurring renewal, `requires_action`, cancellation, proration, and recovery — substantially expanded this round:**
  - `AdditionalBusinessSlotAgreementCancellationTest`: a cancellation request sets `cancel_at_period_end`/`cancellation_requested_at`/`cancellation_effective_at` without touching `next_renewal_at`; `InitiateSlotAgreementRenewal` correctly skips a `cancel_at_period_end` agreement even while `next_renewal_at` remains non-null; `FinalizeSlotAgreementCancellation` transitions to `canceled` and nulls `next_renewal_at` only once `cancellation_effective_at` has passed.
  - `AdditionalBusinessSlotAgreementProrationTest`: a mid-period increase's `amount_micro_snapshot` matches the exact-second `bcRoundHalfUp()` computation against the agreement's real stored UTC period boundaries — never a "days" approximation.
  - `AdditionalBusinessSlotAgreementRepeatedIncreaseTest` — **new, the direct regression test for this round's collision fix** — two distinct mid-period increases within the same billing period produce two distinct `local_idempotency_key` values (via two distinct `change_operation_id`s) and are never treated as duplicates of each other, while a genuine client retry of the *same* increase (same `change_operation_id`) is correctly absorbed as a no-op.
  - `AdditionalBusinessSlotAgreementFailedPeriodTest` — **new** — while a due period's renewal is being retried, no `scheduled_renewal` row is created for a later period; after exhausting retries, `payment_lapsed`/`payment_lapsed_at` are set and `next_renewal_at` is nulled, preventing any further automatic attempt; recovery recomputes `next_renewal_at` forward from the recovery moment, never retroactively, and no renewal charge is ever created for a skipped period.
  - `AdditionalBusinessSlotAgreementRenewalContactSnapshotTest` — **new** — every renewal charge's `requesting_customer_email_snapshot` matches the parent agreement's own original value, frozen, regardless of any later (hypothetical) change to that value elsewhere.
- **Slot and renewal transition audits, slot payment/allocation saga, slot authority blocker** — unchanged.
- **Post-rollout Business initialization — corrected and split this round to match the M1/M2 ordering fix** — `NewBusinessWalletInitializationTest` (M1 scope): both confirmed Business-creation events result in exactly one wallet, never zero, never two. `NewBusinessPayerAssignmentInitializationTest` (M2 scope, **new**): both events result in exactly one payer assignment after M2 ships; a Business created between M1 and M2 deploy receives its payer assignment via the M2 backfill migration, asserted directly.
- **Cross-table Business/wallet mismatch rejection, sensitive payload retention/redaction, provider-cost non-disclosure, invoice/receipt boundary, mechanical source-boundary test, webhook/provider fakes, database** — unchanged.
- **Gate shape — unchanged, six commands.**

---

## 36. Milestone decomposition

Each milestone requires its own separately drafted, human-reviewed, merged implementation contract before work begins. **Content corrected this round for the M1/M2 initialization-ordering fix and every other schema/algorithm correction; milestone count unchanged at six.**

1. **M1 — Wallet & Ledger Foundation.** Schema: `business_usage_wallets` (calendar-month period columns), `business_usage_ledger_entries`, `business_usage_reservations`, `business_usage_rates`, `business_usage_rate_activations`, `platform_feature_usage_classifications`, `platform_feature_usage_classification_transitions`. `UsageWalletManager` (reserve/commit/release/expire with the corrected committed-amount formula and calendar-month rollover, rate activation, coarse-capacity evaluation, **`initializeWalletForNewBusiness()` only — never a payer assignment, §32**). `App\Listeners\Usage\InitializeBusinessUsageProfile`, subscribed to both confirmed Business-creation events, calling only the wallet initializer at this milestone. Real `RealUsageAuthorizationGateway` bound, every feature non-metered at launch. Backfill. `bcmath` extension confirmed enabled. No HTTP surface, no Stripe. Focused + concurrency tests, including the nine-denial-key regression test, the committed-spend-formula test, and the calendar-month rollover tests (February, 31-day, DST).
2. **M2 — Budgets, Limits, Payer, and Billing Contact.** Schema: `business_feature_usage_limits`, `platform_feature_usage_safety_limits`, `business_usage_limit_transitions`, `business_usage_wallet_billing_status_transitions`, `business_billing_contacts`, `business_payer_assignments`, `business_payer_transitions`. `BillingProfileManager`, **including `initializePayerAssignmentForBusiness()` (§32) and the one-time backfill migration covering every Business existing at M2 deploy time.** `App\Listeners\Usage\InitializeBusinessUsageProfile` extended with the payer-assignment call. The full payer-consent authorization model (§16/§24), including the narrowed platform-administrator posture. New permission category.
3. **M3 — Provider Customers, Instruments, and Stripe Integration.** Schema: `payment_provider_customers` (with `UNIQUE(id, provider)`), `business_payment_instruments` (with the composite provider-consistency FK), `business_funding_attempts` (with unique `provider_session_or_intent_reference`), `business_funding_attempt_transitions`, `payment_provider_events` (with the corrected claim-lease fields, `completed_at`). `PaymentInstrumentManager`. `PaymentProviderGateway`/`StripePaymentProviderGateway`, pinning an explicit Stripe API version, validating Stripe's documented minimum **and** eight-digit maximum. The corrected, uniformly-bounded claim/lease/exhaustion algorithm. `ProcessPaymentProviderEvent` and `PurgeExpiredWebhookPayloads` jobs. Auto-recharge as the centralized after-commit trigger, including the narrowed administrator resume-only posture. Production tax posture remains gated by §23 regardless of what this milestone resolves or defers.
4. **M4 — Additional-Slot Agreement and Add-ons.** Schema: `additional_business_slot_agreements` (with `total_amount_micro_snapshot`, the cancellation model, lapse timestamps), `additional_business_slot_agreement_transitions`, `additional_business_slot_renewal_charges` (with `charge_kind`, `change_operation_id`, the corrected idempotency-key derivation), `additional_business_slot_renewal_charge_transitions`, `business_usage_addon_catalog`, `business_usage_addon_purchases`, `business_usage_addon_purchase_transitions`. Option A's full renewal/proration/cancellation/dunning model, exact-second proration arithmetic. Requires, as preconditions: (a) a human-authorized resolution to the cross-RFC allocation blocker (or scoping to `allocation_pending` with manual admin completion) and (b) an authorized RFC-004 catalog-pricing operator surface.
5. **M5 — Metered Feature Classification.** The first real feature(s) classified `is_metered = true` (candidate not named by this RFC — §39 item 11).
6. **M6 — Conformance, Deployment, and Tag.** Full conformance matrix, deployment guide, full six-gate regression, post-merge exact-tag-candidate gate, and — only after separate explicit human authorization — the proposed annotated tag `rfc-005-business-usage-billing-and-wallets`.

---

## 37. Acceptance criteria

M1–M4 introduce no feature-accessibility change; M5, and only M5, changes accessibility for its own explicitly-named, human-approved metered feature(s); every other feature remains non-metered indefinitely; activating a feature always requires its own contract, an active rate, configured limits, a rollout plan, and passing tests.

**Corrected this round: the prior round's "at least one milestone's conformance document shows every §35 test class passing" was itself impossible — no single milestone before M6 implements the full scope §35 spans (M1 cannot test Stripe-integration behavior it doesn't yet build; M4 cannot test metered-feature classification it doesn't yet build).** Corrected framing: **each milestone's own conformance document proves the tests within that milestone's own authorized scope pass — no more, no less. M6 alone proves the full aggregate RFC-005 test set (every test class named across §35, cumulatively, once every milestone has shipped) passes together, alongside the complete six-command regression suite.**

At the RFC level, acceptance-complete only when: every table in §25 exists and is backfilled where required (per each table's own milestone, §32); `NullUsageAuthorizationGateway` has been replaced; the cross-RFC blocker has been resolved before M4 allocates any slot; M6's own conformance document shows the full aggregate §35 test set passing; and the M6 conformance matrix shows every item in §40 resolved.

---

## 38. Release/tag gate

Unchanged: no tag before M6; M6's post-merge exact-tag-candidate gate must pass before separate, explicit human authorization of the annotated tag `rfc-005-business-usage-billing-and-wallets`.

---

## 39. Open human decisions

Items 1–14 are carried forward unchanged from the prior round (renumbering avoided so every existing cross-reference remains valid). **No new item is added by this remediation round** — every defect this round addressed was a correctness/completeness defect in the design's own internal consistency, not a genuinely open commercial/product question; each was resolved with a concrete, justified fix directly in its own section, per the same "choose and fully define" discipline already applied throughout this document, rather than deferred to this list.

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

Maps every mandatory area from the merged design contract's §5 (A–L) and every human product requirement to the exact section(s) of this RFC that resolve it. **Updated for the v1.4 surgical patch; prior-round rows relabeled `(v1.3)` for clarity now that two remediation rounds exist.**

| Contract area / requirement | RFC-005 section(s) |
|---|---|
| A. Scope and terminology | §5, §6 |
| B. Money and accounting invariants | §10, §11, §12, §13 |
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
| **Committed-spend reconciliation formula (v1.3)** | §13 |
| **Calendar-month rollover (v1.3)** | §15 |
| **Webhook claim termination mechanics (v1.3)** | §21 |
| **Business-initialization milestone ordering (v1.3)** | §9, §28–§32 |
| **Provider consistency for payment instruments (v1.3)** | §17.B |
| **Complete schema contract, every remaining shorthand expanded (v1.3, finished in v1.4)** | §11–§23, §25 |
| **Enum-backed fields mechanically reconciled (v1.3)** | §26 |
| **Add-on purchase state auditing (v1.3)** | §18 |
| **Recurring slot-agreement schema and idempotency finished (v1.3)** | §22 |
| **Platform-administrator charge authority narrowed (v1.3)** | §16, §19, §22, §24 |
| **Acceptance/conformance wording corrected (v1.3)** | §37 |
| **Webhook subject routing corrected — `event_type` never the local-purpose discriminator (v1.4)** | §17.C, §21, §35 |
| **Every remaining schema shorthand mechanically expanded, including the ambiguous `text`/`blob` type (v1.4)** | §11–§23 |
| **Unmodeled counter-adjustment exception removed — cached counters never directly mutated (v1.4)** | §13, §19 |
| **Bounded retention for exhausted webhook payloads — terminal `disposed` state (v1.4)** | §21, §24, §30, §33 |

No area in the merged contract's §5 A–L, and no human product requirement, is unaddressed by this table.

---

*End of RFC-005 design document. Every milestone named in §36 requires its own separate, human-reviewed, merged implementation contract before any code, migration, test, route, view, or Stripe/provider change may be written.*
