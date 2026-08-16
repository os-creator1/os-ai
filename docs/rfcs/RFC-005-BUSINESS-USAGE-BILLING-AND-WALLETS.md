# RFC-005 — Business Usage Billing and Wallets

**Status: DRAFT — NOT IMPLEMENTATION-AUTHORIZED**
**Version: 1.1 (Correction Round 1)**

- Base SHA: `6ae00f8f88b1963c6d05a045f99f0ce42651d2eb` (`main`)
- Governing contract: `docs/automation/RFC-005-DESIGN-CONTRACT.md`, merged commit `186a82393577e9afc240d40b0ad8ade4c99d27d4`
- Merging this design document does **not** authorize RFC-005 Milestone 1 or any implementation, migration, test, route, view, Stripe/provider call, or billing behavior. Every milestone in §36 requires its own separately drafted, human-reviewed, merged implementation contract before any such work may begin — identical to the discipline RFC-004 followed before its own Milestone 1, and identical to the discipline the merged RFC-005 design contract itself already locked in its §1.
- This document is written to be implementation-grade: each future milestone contract is expected to **reproduce** the relevant sections below, not redesign them, exactly matching RFC-004's own standard (design-contract §5 preamble).
- **Implementation readiness, corrected in this round:** beyond the blanket DRAFT status above, one area carries a **structural** blocker rather than an ordinary open product decision — **additional-Business-slot payment-triggered allocation (§22) cannot be implemented as drafted until a human resolves the cross-RFC authority conflict described immediately below.** Every other `NON-IMPLEMENTATION-READY` marker in this document (§11/§14 rate figures, §15 cap defaults, §19 auto-recharge threshold, §23 tax/VAT posture, and the items collected in §39) is an ordinary open product/commercial decision, not a structural blocker — those may be resolved by a human decision alone, whereas §22's blocker requires either a scoped RFC-004 amendment or a different operational model before M4 can be contracted.

---

## Cross-RFC implementation blocker

**Discovered in this correction round. Not resolved here — recorded, with options, for separate human authorization.**

RFC-004 requires that additional-Business-slot allocation occur **only** through `EntitlementManager::setAdditionalBusinessSlots(Workspace $workspace, int $count, int $actorUserId, ?string $reason = null): WorkspacePlanAssignment` — the sole authoritative allocation mutation (locked decision 9, RFC-004 §17/§19). Direct re-read of that method's current implementation this round confirms: its very first authority check, immediately after acquiring the Workspace row lock, is `$this->assertPlatformAdministrator($actorUserId)` — which throws `AuthorizationException` unless `$actorUserId` resolves to a user with `is_admin = true` (confirmed directly: `assertPlatformAdministrator()` reads `whereKey($actorUserId)->value('is_admin')`). RFC-004 §20/§18 is explicit and unambiguous that ordinary Workspace customers may **inspect** their allocation but may **never self-grant** a slot — this restriction is not an oversight; it is RFC-004's own deliberately locked authority boundary.

The RFC-005 draft as originally written (§22, prior version) proposed a customer-paid Stripe Checkout flow whose webhook-driven completion step called `setAdditionalBusinessSlots()` directly, using the **purchasing customer's own action** as the trigger. No `$actorUserId` available to a webhook handler in that flow is a real platform administrator. **That call would fail authorization in production**, exactly as designed — RFC-004's own gate would correctly reject it.

This RFC does **not** invent a fake platform-administrator actor, bypass `EntitlementManager`, pass an unrelated admin's identity, or silently weaken RFC-004's authority check to make the flow "work." Any of those would be a silent violation of a locked, already-tagged RFC's own security boundary. Instead, this is recorded as a genuine, unresolved cross-RFC conflict, with concrete options:

1. **Customer pays, then a real platform administrator manually reviews and allocates the slot.** The payment/checkout flow (§22) completes and durably records `payment_succeeded`; a platform administrator (a real one, via the existing RFC-004 M3 admin-panel path already authorized to call `setAdditionalBusinessSlots()`) reviews the payment record and manually performs the allocation, which then marks the agreement `completed`. Slowest for the customer; requires zero RFC-004 change; immediately implementable once §22's saga model (below) exists.
2. **Keep the checkout/platform allocation platform-admin-initiated only** — i.e., do not build a customer self-service purchase flow at all in v1; a customer requests additional slots, an administrator collects payment (or approves an already-collected payment) and allocates manually through the existing admin surface. Functionally similar to option 1 but frames the whole flow as admin-initiated rather than customer-initiated-then-admin-completed.
3. **Recommended: a separate, explicitly human-authorized amendment to RFC-004** introduces a narrowly scoped, payment-proof-backed internal allocation entry point inside `EntitlementManager` (e.g., a new method such as `allocateAdditionalBusinessSlotsFromVerifiedPayment()`, not a change to `setAdditionalBusinessSlots()`'s own existing signature or authority check). That new entry point must, at minimum:
   - preserve `EntitlementManager` as the sole allocation authority — no other class ever mutates `workspace_plan_assignments.additional_business_slots`;
   - require a verified, idempotent successful-payment record (this RFC's own `additional_business_slot_agreements`/`business_funding_attempts`-shaped evidence, §22) as its precondition, not a bare actor claim;
   - record the requesting **customer** separately from the **system/payment actor** that technically invokes the method (so the audit trail never conflates "who paid" with "what internal process performed the write");
   - write the existing `workspace_transitions`/event audit exactly as `setAdditionalBusinessSlots()` already does, unchanged;
   - continue to prevent arbitrary customer self-grant through any other path (i.e., the new entry point is reachable only from the verified-payment saga, never from a bare customer HTTP action);
   - receive its own contract, its own tests, its own human review, and its own tag-governance decision — entirely separate from this RFC-005 design document and from RFC-004's already-tagged, complete state.

**This RFC-005 design document does not authorize or perform that amendment.** It records the conflict, the evidence, and the options. **No RFC-005 additional-slot implementation contract (M4, §36) may be drafted until a human explicitly chooses and authorizes one of these options** (or another option a human proposes). §22 below designs the rest of the additional-slot payment flow (quoting, checkout, durable payment records, refunds, recurring renewal) in full, and is implementation-ready **up to** the allocation step itself, which remains marked `NON-IMPLEMENTATION-READY` pending this decision. This conflict is also recorded in §39 as open item 14.

---

## 1. Purpose and problem statement

RFC-003 forward-declared, and RFC-004 explicitly deferred, a Business-scoped usage wallet, usage ledger, billing configuration, and monthly usage budget (RFC-003 §8), together with payer selection, auto-recharge, and Stripe usage-billing changes (RFC-003 §26.2; RFC-004 §19, §31). RFC-004 shipped the entitlement structure — plan tiers, Business feature toggles, complimentary status, additional-Business-slot allocation — and reserved exactly one seam for this RFC: `UsageAuthorizationGateway::check(Business $business, PlatformFeature $feature): UsageAuthorizationResult`, called as the final step of `EntitlementManager::decide()`, currently bound to `NullUsageAuthorizationGateway` (always-authorized). RFC-005 designs the system that will eventually bind a real implementation to that seam: a Business-scoped wallet and append-only ledger, a versioned usage-rate catalog, reservation/commit/release for uncertain-cost metered operations, payer selection with Workspace fallback, a billing contact, payment instruments, manual/paid/promotional credits, auto-recharge, additional-Business-slot payment collection, and the Stripe provider boundary — without ever weakening RFC-003/RFC-004's already-locked tenancy, entitlement, or isolation guarantees.

The problem this RFC solves is narrower than "billing" in general: the legacy SMS-plan/subscription billing stack (four gateways, a 12,887-line controller, `string`-typed money columns) already exists and is explicitly out of scope; RFC-005 is a **new, Business-scoped, Stripe-first, usage-metered** billing system that must coexist with, and never be confused with, that legacy stack.

---

## 2. Governing RFC/contract evidence

This design is bound by, in descending specificity:

1. `docs/automation/RFC-005-DESIGN-CONTRACT.md` (commit `186a823...`) — sixteen inherited locked decisions (its §4), human product requirements (its own top-level section), mandatory A–L contents (its §5), the open-decision/gap rule (its §6), and its governance restrictions (its §1, §7, and closing summary). Every one of those is treated as binding on this document; §7 below (Locked product decisions) restates each with its exact section-cross-reference.
2. RFC-004 (Plans and Business Feature Entitlements) — tag `rfc-004-plans-and-business-feature-entitlements` at `221e18f0...` — specifically §13/§17/§18/§19/§20/§21/§31, and, newly re-read this round, `EntitlementManager::setAdditionalBusinessSlots()`'s exact authority gate (see the blocker above).
3. RFC-003 (Workspace and Business Account Core) — specifically §4, §8, §14.1, §19, §26.2, §27.
4. `AGENTS.md` — "Workspace authorization" (Staff/Admin/owner/direct-owner/platform-admin as separate authorization paths) and verification rules (only `ultimatesms_testing`, focused-before-broad tests, exact evidence reporting).

`docs/automation/AI-AUTONOMY-STATE.json` was re-read in full during this round's preflight (still `active_rfc: "RFC-003"`, `active_milestone: "Milestone 4"`, `expected_head_sha: db7829d2c33ff3eb77a5bd42820eb39e349f6d94`) — confirmed unchanged, confirmed stale/historical, confirmed to carry no authorization weight, and left untouched by this document.

---

## 3. Repository audit findings

Re-verification this round found the branch's own HEAD (`b3f0ffeb2dd622e506eb46366c9931dbcbdb05b5`) an exact match to its expected value, `6ae00f8f...` confirmed as merge-base ancestor, and the working tree otherwise unchanged since the prior draft — no new repository drift to reconcile beyond what is recorded below.

- **`EntitlementManager::setAdditionalBusinessSlots()`, re-read in full this round**: `DB::transaction()` → `workspaceRepository->findForUpdate($workspace->id)` → **`$this->assertPlatformAdministrator($actorUserId)`** (the blocker's exact evidence, quoted above) → assignment lookup → slot-count validation → repository update → `workspace_transitions` audit row → `WorkspaceAdditionalBusinessSlotsChanged::dispatch()`. `assertPlatformAdministrator()` itself: `(bool) $this->userRepository->query()->whereKey($actorUserId)->value('is_admin')`, throwing `AuthorizationException` if false. No alternate, lower-privilege entry point into this method exists anywhere in the class.
- **`EntitlementManager::decide()`'s exact nine denial keys, re-confirmed by direct read this round**: `platform_feature_unknown`, `platform_feature_unavailable`, `workspace_plan_unassigned`, `not_entitled_by_plan`, `denied_by_workspace_override`, `disabled_for_business`, `plan_suspended`, `plan_inactive`, and the gateway-supplied `usage_unauthorized` (via `$usageResult->reason ?? 'usage_unauthorized'` — note this expression means the gateway's own `$reason` string, if non-null, is what `decide()` actually returns, **not** a hardcoded fallback alone). **This is itself a second, related finding this round surfaces explicitly: `EntitlementManager::decide()`'s own code will pass through *whatever* non-null `$reason` a bound `UsageAuthorizationGateway` returns — it does not itself restrict the gateway to only ever return `usage_unauthorized`.** RFC-004's own locked nine-key set is therefore a **discipline this RFC-005 gateway implementation must self-impose**, not a mechanical guarantee `EntitlementManager::decide()` enforces on its caller's behalf. §14 below designs `RealUsageAuthorizationGateway` to always return exactly `usage_unauthorized` (never a detailed reason) as its own `UsageAuthorizationResult::$reason`, and §35 adds a regression test asserting this holds even as RFC-005's own internal capacity-evaluation logic grows more detailed reasons over time.
- Every other repository fact recorded in the prior draft's §3 (Stripe SDK version, Checkout Session precedent, absence of webhook-signature precedent, `businesses.currency_code`, `workspace_plan_catalog.price`'s `decimal(16,2)` shape, the `currencies` table's missing decimal-scale metadata, the actor-column-vs-tenancy-column convention, route-naming convention, `WorkspaceMembership`'s three authorization axes, `config/permissions.php`'s existing categories, the real cross-process concurrency-test pattern, and the `ShouldDispatchAfterCommit`/`ShouldQueueAfterCommit` distinction) remains accurate at this base and is carried forward unchanged into this corrected draft.

No new conflict beyond the one recorded in the blocker above was found between the merged design contract, RFC-003, RFC-004, and repository reality. If a future milestone's own re-verification finds another, that milestone's contract stops and reports it rather than silently resolving it here (design-contract §6).

---

## 4. Goals

1. Give every Business an isolated usage wallet, append-only ledger, billing contact, and payer — never Workspace-pooled, never cross-Business-visible (RFC-003 §8, §26.2).
2. Meter only explicitly classified features; every non-metered, already-entitled feature continues working with an empty or non-existent wallet (design-contract §4 item 13, product direction 10).
3. Give the Business, and the platform, independent, non-collapsible controls over spend: a monthly Business spend cap, per-feature limits, and a platform safety ceiling that a customer can tighten but never loosen past (Human product requirements 2–4).
4. Make Stripe the sole v1 payment provider, behind a narrow boundary that never spreads Stripe-specific code through managers or controllers, so a second provider could be added later without redesigning the ledger (product direction 3).
5. Never let this system reuse the legacy multi-gateway `PaymentController`/`invoices`/`subscription_transactions` stack by inference from a similar name (design-contract §4 item 15).
6. Preserve a seam for Agency client rebilling without building it now (design-contract §4 item 4; product direction 5).
7. Never invent a customer retail rate, a default cap value, or provider behavior; every commercially significant figure this RFC does not receive as an explicit human product requirement is named as an open decision in §39, not silently defaulted (design-contract §6).
8. **Added in this correction round:** represent the wallet's own accounting state (available, reserved, and now debt) with an accounting model the ledger can actually reconstruct, never a model whose own stated reconciliation equation is unsatisfiable from its own data (§10, §12).
9. **Added in this correction round:** never expand `EntitlementManager::decide()`'s locked nine-key denial surface — RFC-005's own richer internal denial detail stays internal (§14).

---

## 5. Non-goals and explicit deferrals

Deferred out of RFC-005 v1 entirely, per RFC-003 §26.2 and RFC-004 §31 (both already cross-checked consistent by the design contract):

- Agency client rebilling execution (schema/service seam only — §16).
- Any second payment provider besides Stripe (§20).
- Any change to the legacy `Plan`/`Subscription` SMS-quota billing stack, `PaymentController.php`, `PaymentMethods`, `Invoices`, or `SubscriptionTransaction` (design-contract §4 item 15; §23).
- Automated tax/VAT calculation as a legally-sufficient solution (§23 marks production tax posture `NON-IMPLEMENTATION-READY` pending the operator's own legal/accounting determination — this RFC does not decide it and is not legal advice).
- Multi-currency wallets in v1 — v1 targets a single settlement currency; see §39 item 10 for the exact open decision this creates.
- A concrete v1 add-on roster beyond the schema seam and one worked example (§18; §39 item 8).
- Selecting exact retail rates, exact default caps, or an exact auto-recharge threshold value (§39).
- **Added in this correction round:** actual allocation of a paid additional Business slot through any customer-triggered code path, until the cross-RFC blocker above is resolved (§22, §39 item 14).

---

## 6. Terminology

- **Business wallet** — the single row per Business holding `available_balance`, `reserved_balance`, **`debt_balance`** (new this round), and the Business's caps/auto-recharge configuration (§12).
- **Usage account** — informal synonym for a Business's wallet + ledger + rate history taken together; not a separate table.
- **Available balance** — spendable balance: funded balance not currently reserved or owed as debt.
- **Reserved balance** — the sum of open (uncommitted, unreleased, unexpired) reservations; not spendable until released back to available or committed into a final charge.
- **Debt balance — new this round.** A non-negative, auditable record of value the Business's wallet owes but has not yet had funded — created only when a refund/chargeback/overage exceeds what the wallet currently holds in available balance (§10, §12). Distinct from a negative balance, which this design never permits.
- **Payer** — which of Business/Workspace/(later) Agency-rebill funds a Business's wallet (§16).
- **Funding source / payment instrument** — a stored Stripe PaymentMethod reference (safe display metadata only — brand/last4/expiry — token itself never rendered), owned via a **provider customer record** (new this round, §17.B) rather than directly by a Business/Workspace row.
- **Funding attempt — new this round.** A durable local record of one attempt to move money between a payer's instrument and a Business's wallet (manual top-up, auto-recharge, or an add-on purchase's payment leg), tracked through Stripe-accurate states from creation through terminal outcome (§17.C, §19).
- **Top-up** — a manually initiated, customer-paid wallet credit.
- **Recharge** — an automatically initiated, threshold-triggered wallet credit (auto-recharge, §19).
- **Charge** — the final, committed ledger debit for a completed metered operation.
- **Reservation** — a provisional hold against available balance for an operation whose exact cost is not yet known (§13).
- **Capture / commit** — turning a reservation into a final charge once the actual cost is known.
- **Release** — returning a reservation's held amount to available balance without a charge (denial, cancellation, or expiry).
- **Refund** — money returned to the payer, distinct from a usage-charge reversal, a correction, or a chargeback (§12 defines all four distinctly this round).
- **Usage charge reversal — new this round.** An admin action crediting a previously committed usage charge back to available balance, without any real money movement to/from Stripe.
- **Correction/reversal (generic)** — an explicit, auditable entry correcting an erroneous prior entry; never an edit or delete of that entry.
- **Chargeback/dispute** — a provider-initiated clawback of previously funded money, distinct from a refund the platform itself initiates.
- **Adjustment** — an admin- or system-issued correction entry with a mandatory actor and reason (§18).
- **Metered feature** — a `PlatformFeature` explicitly classified as cost-bearing (§14); every other feature is non-metered and never wallet-gated.
- **Additional-slot agreement — renamed this round from "additional-slot checkout."** The recurring billing relationship for a paid Core/Growth additional Business slot (RFC-004 §17/§19; §22 below), explicitly modeled as an ongoing, renewable charge, never a one-time purchase — see the cross-RFC blocker above for its allocation-step status.

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
| 9 | RFC-005 owns payment collection for paid additional-Business-slot allocation; RFC-004's `setAdditionalBusinessSlots()`/`changePlan()` remain the sole allocation mutation | §22, cross-RFC blocker |
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

Four new manager classes (one more than the prior draft — split this round for cleaner authority boundaries, §16.B/§17.C), mirroring `EntitlementManager`'s own shape (one manager per bounded authority, no controller-owned business rule, `DB::transaction()` + row lock + audit-trail + after-commit event on every mutation):

- **`App\Library\Usage\UsageWalletManager`** — sole write authority for wallets (including `debt_balance_micro`, new this round), ledger entries, reservations, the rate catalog and its activation log, feature classifications, monthly Business caps, per-feature limits, and their transition audit (§10, §11, §12, §13, §14, §15). Implements the real `UsageAuthorizationGateway` binding (§14) via a thin adapter, `RealUsageAuthorizationGateway`, that delegates to this manager's own coarse, non-mutating capacity check.
- **`App\Library\Usage\BillingProfileManager`** — sole write authority for billing contact and payer assignment/transitions (§16, §17.A).
- **`App\Library\Usage\PaymentInstrumentManager`** — **new this round**, split out of the prior draft's `BillingProfileManager` — sole write authority for provider-customer records and payment-instrument attach/detach/default-selection (§17.B), enforcing the payer-consent rules §16 defines (a Business-owned instrument is never manageable by Workspace-side authority alone, and vice versa).
- **`App\Library\Usage\UsageBillingCheckoutManager`** — orchestrates provider-facing flows that must call out to Stripe *outside* any database transaction: manual top-up, auto-recharge initiation, additional-slot agreement checkout/renewal, add-on purchase (§17.C, §19, §22). Never performs the actual ledger/allocation mutation itself — it calls into `UsageWalletManager`/`EntitlementManager` only after a provider call has already succeeded and been durably recorded.

A fifth, narrow interface — **`App\Library\Usage\Contracts\PaymentProviderGateway`** — is the entire Stripe boundary (§20): `createSetupIntent()`, `attachPaymentMethod()`, `createTopUpCheckout()`/`createTopUpPaymentIntent()`, `chargeOffSession()`, `verifyWebhookSignature()`. `App\Library\Usage\StripePaymentProviderGateway` is the only implementation in v1; no manager or controller ever references the `Stripe\*` namespace directly — only this one adapter class does.

Repository-per-table, exactly RFC-004's convention: one contract + one Eloquent implementation per new table listed in §25, bound in `AppServiceProvider` the same way the six RFC-004 M1 repositories already are.

---

## 10. Money representation and currency rules

**Recommendation unchanged: signed 64-bit integer "micro-units"** (1 unit = 1⁄1,000,000 of the currency's major unit), stored in `BIGINT` columns, for every money field in every RFC-005 table. PHP `float` is forbidden for any persisted or in-flight authoritative money value.

**Corrected in this round — the wallet has three buckets, not two, and the ledger must be able to reconstruct all three.** The prior draft's reconciliation equation (`available + reserved` reconstructed from a single `balance_effect` enum plus one magnitude column) could not represent a reservation's own two-bucket movement (available decreases *and* reserved increases from one event) or an unfunded overage/refund (which must become a new, positive, auditable **debt** rather than an impossible negative balance). Corrected model:

- `business_usage_wallets` carries three independent, non-negative cached buckets: `available_balance_micro >= 0`, `reserved_balance_micro >= 0`, `debt_balance_micro >= 0` (§12).
- Every `business_usage_ledger_entries` row carries **three signed delta columns** — `available_delta_micro`, `reserved_delta_micro`, `debt_delta_micro` (all signed `BIGINT`) — instead of one `balance_effect` enum plus one unsigned magnitude. A single entry may move more than one bucket at once (e.g., a reservation moves available *and* reserved in the same row; a top-up applied against existing debt moves debt *and* available in the same row). An optional unsigned `gross_amount_micro` is retained on entry types where it aids readability (e.g., the full paid amount before any debt-clearing split), but it is never authoritative — the three deltas are.
- **Invariant, corrected:** each of the wallet's three cached buckets equals the signed sum of its matching delta column across every ledger entry for that Business — `available_balance_micro = Σ available_delta_micro`, `reserved_balance_micro = Σ reserved_delta_micro`, `debt_balance_micro = Σ debt_delta_micro` — each reconstructable **independently**, not only as a combined total.
- **Net wallet position** (an informational, derived figure only — never itself a gate) = `available_balance_micro + reserved_balance_micro - debt_balance_micro`.
- Every wallet-bucket mutation and its ledger entry are written under the same wallet row lock, in the same transaction, as required by the original draft — unchanged.
- The scheduled reconciliation job (§31) independently recomputes all **three** buckets from the ledger (not one combined figure) and alerts on any bucket's drift; it never auto-corrects a drift.

**Exact delta behavior per funding/consumption event, corrected and made precise this round:**

- **Paid top-up / manual credit / promotional credit** — if `debt_balance_micro > 0` at the time of credit, the credit **clears existing debt first**: `debt_delta_micro = -min(credit_amount, debt_balance_micro)`, and only the remainder credits available: `available_delta_micro = credit_amount - min(credit_amount, debt_balance_micro)`. A credit smaller than existing debt reduces debt only, with zero available-balance effect.
- **Reservation** — `available_delta_micro = -amount`, `reserved_delta_micro = +amount`, `debt_delta_micro = 0`.
- **Reservation release** — `available_delta_micro = +amount`, `reserved_delta_micro = -amount`, `debt_delta_micro = 0`.
- **Committed usage (within the reserved amount)** — `reserved_delta_micro = -committed_portion`, `available_delta_micro = 0`, `debt_delta_micro = 0`.
- **Usage overage (actual cost above what was reserved)** — the overage first consumes remaining available balance, and only what available cannot cover becomes debt: `available_delta_micro = -min(overage, available_balance_micro)`, `debt_delta_micro = +max(0, overage - available_balance_micro)`.
- **Refund (money returned to the payer)** — consumes available balance up to what exists; any remainder is a debt (money already left the wallet's funded balance conceptually but the wallet cannot go negative, so the shortfall becomes an owed, auditable debt): `available_delta_micro = -min(refund_amount, available_balance_micro)`, `debt_delta_micro = +max(0, refund_amount - available_balance_micro)`.
- **Dispute/chargeback (provider claws back funds)** — identical delta shape to a refund, plus the unique side effect of setting the wallet's `billing_status = 'suspended'` (§12).
- **Usage charge reversal (admin credits a previously committed charge back to available, no real money movement)** — `available_delta_micro = +amount`, referencing the original `UsageCharge`/overage entry via `reversed_entry_id`; distinct from a `Refund` because no Stripe-side money moves.
- **Correction/reversal (generic, for an erroneous entry unrelated to a refund/chargeback/usage-reversal)** — signed by whatever the correction requires, always via `reversed_entry_id`, mandatory reason; never edits or deletes the original.

**Whether outstanding debt itself denies new metered reservations — resolved this round: yes.** A reserve attempt against a wallet with `debt_balance_micro > 0` is denied with the internal reason `outstanding_debt` (§14's `UsageCapacityDecision`), evaluated immediately after the `billing_status` check and before any cap check — a Business cannot keep incurring new metered cost while it owes the platform money it has not yet funded. This is a **recommended design decision** (category 3), not an open item, because it follows directly from treating debt as a real, serious accounting state; the generic RFC-004 `usage_unauthorized` key is still all `EntitlementManager::decide()` ever sees (§14).

**Currency scale gap and v1 resolution — unchanged from the prior draft:** the `currencies` table carries no decimal-places/zero-decimal metadata; v1 scopes every Business wallet to exactly one settlement currency with a fixed `decimal_places` constant defined in this RFC's own code (recommendation: USD, `decimal_places = 2`, §39 item 10), never read from `currencies`.

**Exact integer arithmetic for `quantity × rate_micro` — added this round, since the prior draft never specified it:**

- Quantity is accepted from the caller as an exact value (never a PHP `float`) and immediately converted to an integer **micro-quantity** (`quantity_micro = round(quantity * 1_000_000)`, using `BCMath`/`bcmul` — never native float multiplication) before any further arithmetic — this keeps both operands of the core multiplication as plain integers.
- The charge computation is `charge_micro = round( (quantity_micro * retail_rate_micro) / 1_000_000 )`, computed via `BCMath` (`bcmul`/`bcdiv`) or `GMP`, never native PHP arithmetic on values that could exceed safe integer range from an intermediate multiplication — PHP's native `*` on two large 64-bit-range integers can silently overflow into a float, which this design forbids.
- **Overflow/sanity ceiling:** any input `quantity` or resulting `charge_micro` exceeding an application-level sanity ceiling (recommended: `10^15` micro-units, ≈ $1 billion at the v1 USD scale — far below `BIGINT`'s actual ~`9.2 × 10^18` ceiling) is rejected before computation, with a stable reason (`quantity_exceeds_sanity_ceiling`) — this catches a fat-fingered or malformed quantity long before it could threaten real overflow, and the ceiling itself is a category-3 recommendation, adjustable by a future milestone with justification.
- **Rounding point:** rounding (`round_half_up`, §11) is applied exactly once, to the final `charge_micro` value — never to an intermediate product, never re-applied on a later read.
- **Stripe-cent conversion:** `stripe_amount_cents = round(retail_amount_micro / 10_000)` for the v1 USD/2-decimal scale (`1_000_000 micro-units ÷ 100 cents = 10,000 micro-units per cent`), computed via `BCMath`, never float division. Any amount whose rounded cent-equivalent is `0` for a genuinely positive charge is rejected before an outbound Stripe call (`amount_below_provider_minimum` — see below) rather than sent as a zero-amount request.
- **Stripe minimum/maximum payment handling:** Stripe enforces a documented per-currency minimum charge amount (on the order of $0.50 for USD) and an effectively unbounded practical maximum for this platform's scale. `UsageBillingCheckoutManager` validates any outbound top-up/checkout/recharge amount against Stripe's documented minimum **before** calling `PaymentProviderGateway`, returning the stable local reason `amount_below_provider_minimum` rather than allowing the Stripe API call itself to fail — this check applies only to amounts actually sent to Stripe (top-ups, recharges, slot/add-on checkouts), never to an internal per-call metered ledger charge, which may be arbitrarily small in micro-units since it is never itself submitted to Stripe individually.

---

## 11. Rate catalog, customer charges, provider costs, and rate snapshots

**Corrected in this round: rate rows are now fully immutable (insert-only, no field ever updated), and "which rate is active" is resolved by a lockable pointer on the classification row, not by scanning for a null `effective_to`.** The prior draft's "lock the active rate row" algorithm had no row to lock when a feature had no active rate yet, allowing two concurrent first-time activations to both succeed. Since `platform_feature_usage_classifications` is fully backfilled with one row per `PlatformFeature` case at M1 (§32) — meaning a classification row for any given `feature_key` **always exists**, even before that feature ever has a rate — the lock target for rate activation is now that always-present classification row, never the rate row itself.

**`business_usage_rates`** (fully immutable; every row, once inserted, is never updated):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `feature_key` | string(64) | `PlatformFeature`-backed value |
| `version` | unsigned int | starts at 1 per `feature_key`, computed under the classification-row lock (below) — never racy |
| `retail_rate_micro` | bigint | customer-facing price per unit |
| `provider_cost_micro` | bigint | internal estimated provider cost per unit — never exposed to any customer surface (§34) |
| `unit_label` | string(64) | e.g. `"per message"`, `"per 1,000 tokens"` |
| `rounding_rule` | string(32), enum-backed | `round_half_up` only, for v1 |
| `currency_id` | FK `currencies`, `restrictOnDelete()` | must match the wallet's single v1 settlement currency (§10) |
| `created_by_user_id` | unsigned bigint, no FK | actor column convention |
| `created_at` | timestamp | the only timestamp this table has — no `updated_at`, and correctly so this round: nothing on this row is ever mutated, including no `effective_to` |

Unique constraint: `(feature_key, version)`.

**`business_usage_rate_activations`** — new this round, append-only, the sole record of "what rate was active for a feature, and when":

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `feature_key` | string(64) | |
| `rate_id` | FK `business_usage_rates`, `restrictOnDelete()` | |
| `activated_at` | timestamp | |
| `activated_by_user_id` | unsigned bigint, no FK | |
| `reason` | text | mandatory |
| `created_at` | timestamp | |

"What rate was active for feature X at time T" is answered by the latest activation row for `feature_key` with `activated_at <= T` — the prior activation's effective end is implicit in the next activation's start, so no row is ever updated to record an "end."

**`UsageWalletManager::setActiveRate()` — the corrected, race-free algorithm:**

1. `DB::transaction()`.
2. Lock `platform_feature_usage_classifications`' row for `feature_key` (`findForUpdate()`) — this row **always exists** post-backfill, so this is never a "no row to lock" situation, even for a feature's very first rate.
3. Compute the next `version` for `feature_key` (under the same lock — no race).
4. Insert the new, immutable `business_usage_rates` row.
5. Insert a `business_usage_rate_activations` row.
6. Update **only** the classification row's `active_rate_id` pointer (and, if this call is also the feature's metering activation, `is_metered`) — the rate rows themselves are never touched again.
7. Commit.

Two concurrent calls to activate a feature's first rate now serialize on the same classification-row lock — exactly one wins, the other proceeds only after seeing the first's committed `active_rate_id`, and both insert distinct, valid rate/activation rows (never two simultaneously "active" rates for one feature). **Metering activation** (`UsageWalletManager::activateMetering()`) is the higher-level operation combining `setActiveRate()` with setting `is_metered = true`, in one transaction, requiring: an active rate (via the algorithm above), a supported currency, an already-configured platform safety limit for that feature (§15), and a mandatory actor/reason — never metered without all four present at once.

**Snapshotting — unchanged in shape from the prior draft:** every reservation and every final ledger entry that involves a rate stores its own denormalized copies of `rate_id` (FK, traceability only), `rate_version`, `retail_rate_micro`, `provider_cost_micro`, `unit_label`, `rounding_rule`, `currency_id`, and `quantity` — so that entry remains fully interpretable regardless of later rate activity. A later rate activation never recalculates any historical entry.

**Cost/margin visibility — unchanged:** `provider_cost_micro` (rate catalog and every ledger/reservation entry) is readable only by the platform-administrator authorization path (§24) — enforced by a boundary test (§35), mirroring RFC-004 §20's own posture.

---

## 12. Business wallet and append-only ledger invariants

**`business_usage_wallets`** — one row per Business (columns corrected this round to reflect the three-bucket model, §10, and the composite-key tenancy protection below):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `business_id` | FK `businesses`, unique, `restrictOnDelete()` | 1:1, Business-scoped (locked decision 2) |
| `currency_id` | FK `currencies`, `restrictOnDelete()` | the wallet's single settlement currency (§10) |
| `available_balance_micro` | bigint, default 0 | never negative |
| `reserved_balance_micro` | bigint, default 0 | never negative |
| `debt_balance_micro` | bigint, default 0 | **new this round** — never negative; see §10 |
| `monthly_spend_cap_micro` | bigint, nullable | Business's own cap (§15); null = platform-safety-limit-bounded only |
| `spend_period_start_utc` / `spend_period_end_utc` | timestamp | **corrected this round from a bare `date`** to an exact UTC instant pair, so a Business-timezone rollover is unambiguous (§15) |
| `auto_recharge_enabled` | boolean, default false | |
| `auto_recharge_threshold_micro` | bigint, nullable | required if enabled (§19) |
| `auto_recharge_amount_micro` | bigint, nullable | required if enabled |
| `monthly_recharge_cap_micro` | bigint, nullable | distinct from `monthly_spend_cap_micro` (locked decision 8) |
| `recharge_period_start_utc` / `recharge_period_end_utc` | timestamp | independent period pair from the spend cap's (§15) |
| `committed_spend_this_period_micro` | bigint, default 0 | **new this round** — cached counter, reconciled against the ledger (§31) |
| `reserved_spend_this_period_micro` | bigint, default 0 | **new this round** — cached counter, reconciled against open reservations |
| `recharged_this_period_micro` | bigint, default 0 | **new this round** — cached counter for the recharge cap |
| `low_balance_notified_at` | timestamp, nullable | dedup window (§19) |
| `billing_status` | string(16), enum-backed, default `active` | `active` \| `suspended` — see below |
| `created_at` / `updated_at` | timestamp | the one legitimately mutable RFC-005 table — a cached aggregate + configuration row, not itself the historical record |

Additionally: `UNIQUE (id, business_id)` on this table (trivially satisfiable, since `id` is already the primary key) — this composite unique key is what makes the **composite foreign key** on every child table below possible (see "Tenancy-ID integrity," below).

**`billing_status`** — unchanged mechanism from the prior draft: `active → suspended` set automatically on a `DisputeChargeback` ledger entry, or explicitly by a platform administrator (mandatory reason); `suspended → active` is admin-only, mandatory reason, never automatic. Every transition is now recorded in a dedicated append-only table (closing a gap the prior draft's self-audit missed):

**`business_usage_wallet_billing_status_transitions`** — new this round:

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `wallet_id` | FK `business_usage_wallets`, `restrictOnDelete()` | |
| `business_id` | FK `businesses`, `restrictOnDelete()` | denormalized for query convenience, protected by the composite-FK pattern below |
| `from_status` / `to_status` | string(16) | |
| `source` | string(24), enum-backed | `dispute_webhook` \| `admin_action` |
| `actor_user_id` | unsigned bigint, nullable, no FK | null for `dispute_webhook` |
| `reason` | text | mandatory |
| `created_at` | timestamp | |

`billing_status` remains entirely distinct from `workspace_plan_assignments.status`'s own `suspended`/`inactive` values (RFC-004 §18) — never conflated. Auto-recharge's repeated-failure consequence (§19) remains deliberately unwired to `billing_status` — it only disables `auto_recharge_enabled`.

Sole write authority: `UsageWalletManager`. No controller ever writes to this table directly.

**Tenancy-ID integrity — new this round, resolving the "duplicate tenancy ID" requirement.** Every child table below that carries both `business_id` and `wallet_id` (redundant, since `business_usage_wallets.business_id` is unique/1:1) declares a **composite foreign key** `(wallet_id, business_id) REFERENCES business_usage_wallets (id, business_id)`, referencing the composite unique key declared above — this makes a row whose `business_id` and `wallet_id` point to two different Businesses a **schema-level impossibility** in InnoDB (MySQL 8+; the M1 contract confirms engine/version before relying on this, §25), not merely a manager-level convention. Where this composite FK cannot be confirmed available, `UsageWalletManager` still enforces the invariant by **always deriving `business_id` from the already-locked `wallet_id` row internally** — no public method ever accepts both as independent, unchecked caller-supplied values — and a dedicated regression test (§35) asserts no code path can construct a mismatched pair.

**`business_usage_ledger_entries`** — append-only, never updated or deleted after insert:

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `business_id` / `wallet_id` | FK, composite-protected (above) | |
| `entry_type` | string(32), enum-backed | see table below — twelve types this round, corrected from eleven |
| `available_delta_micro` | signed bigint | **new this round**, replaces `balance_effect` + magnitude |
| `reserved_delta_micro` | signed bigint | **new this round** |
| `debt_delta_micro` | signed bigint | **new this round** |
| `gross_amount_micro` | bigint, unsigned, nullable | informational only, never authoritative (§10) |
| `currency_id` | FK `currencies`, `restrictOnDelete()` | |
| `feature_key` | string(64), nullable | usage-related entries only |
| `quantity` | decimal(14,6), nullable | the caller-supplied exact quantity, before micro-quantity conversion (§10) |
| `rate_id` / `rate_version` / `retail_rate_micro` / `provider_cost_micro` / `unit_label` / `rounding_rule` | see §11 | nullable; populated for rate-involving entries only |
| `reservation_id` | FK `business_usage_reservations`, nullable | |
| `funding_attempt_id` | FK `business_funding_attempts`, nullable | **new this round** — set for `PaidTopUp`/`AutoRecharge` entries (§17.C) |
| `correlation_key` | string(191), unique | idempotency (§31) |
| `provider_reference` | string(191), nullable | Stripe object id, when applicable |
| `actor_user_id` | unsigned bigint, nullable, no FK | null = system-generated |
| `reason` | text, nullable | mandatory (manager-enforced) for `ManualCredit`, `UsageChargeReversal`, `CorrectionReversal`, `Refund` |
| `reversed_entry_id` | self-referencing FK, nullable, `restrictOnDelete()` | set on `UsageChargeReversal`/`CorrectionReversal` rows |
| `created_at` | timestamp | immutable; the only timestamp this table has |

**Entry types and their delta behavior — corrected and expanded this round (twelve types, four of them now distinguishing what the prior draft collapsed into one generic `Refund`):**

| `entry_type` | `available_delta` | `reserved_delta` | `debt_delta` | When |
|---|---|---|---|---|
| `PaidTopUp` | `+remainder after debt-clear` | `0` | `-min(amt, debt)` | customer-initiated top-up succeeds |
| `AutoRecharge` | `+remainder after debt-clear` | `0` | `-min(amt, debt)` | auto-recharge succeeds |
| `ManualCredit` | `+remainder after debt-clear` | `0` | `-min(amt, debt)` | admin/customer adds credit (§18) |
| `PromotionalCredit` | `+remainder after debt-clear` | `0` | `-min(amt, debt)` | promotional/complimentary credit (§18, §39 item 5) |
| `Reservation` | `-amt` | `+amt` | `0` | reserve step (§13) |
| `ReservationRelease` | `+amt` | `-amt` | `0` | release/expiry, no charge |
| `UsageCharge` | `0` | `-committed_portion` | `0` | commit step, within the reserved amount |
| `UsageOverageCharge` | `-min(overage, avail)` | `0` | `+max(0, overage-avail)` | actual cost exceeds the reservation (§10) |
| `Refund` | `-min(amt, avail)` | `0` | `+max(0, amt-avail)` | **money returned to the payer** — distinct from the three below |
| `DisputeChargeback` | `-min(amt, avail)` | `0` | `+max(0, amt-avail)` | **provider clawback**; also sets `billing_status = 'suspended'` |
| `UsageChargeReversal` | `+amt` | `0` | `0` | **admin reverses a prior usage charge into wallet credit** — no real money moves |
| `CorrectionReversal` | signed by context | signed by context | signed by context | **corrects an erroneous prior entry** — generic, always via `reversed_entry_id` |

`Refund`, `DisputeChargeback`, `UsageChargeReversal`, and `CorrectionReversal` are four structurally distinct entry types, per this round's explicit correction — never collapsed into one.

Each of `available_balance_micro`, `reserved_balance_micro`, and `debt_balance_micro` on the wallet row is maintained as an always-consistent cached aggregate of its matching delta column (§10). No cross-Business or cross-currency transfer is ever expressible: every ledger entry is scoped to exactly one `business_id` and one `currency_id`, and no manager method accepts two different Businesses' wallets in the same mutation.

---

## 13. Reservation/commit/release/reversal state machines

Unchanged in overall shape from the prior draft (reservations as their own mutable-but-audited table, every transition also recorded as an immutable ledger row) — corrected this round only where the delta model (§10/§12) and the new `outstanding_debt`/`billing_suspended` checks (§14/§15) change the algorithms' exact steps.

**`business_usage_reservations`** (composite-FK protected on `(wallet_id, business_id)`, §12):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `business_id` / `wallet_id` | FK, composite-protected | |
| `feature_key` | string(64) | |
| `status` | string(16), enum-backed | `pending` \| `committed` \| `released` \| `expired` |
| `reserved_amount_micro` | bigint | the held amount |
| `estimated_quantity` | decimal(14,6), nullable | |
| `rate_id` / `rate_version` / `retail_rate_micro` / `provider_cost_micro` / `rounding_rule` | see §11 | snapshot at reservation time |
| `idempotency_key` | string(191), unique | caller-supplied |
| `correlation_key` | string(191) | ties `Reservation`/`ReservationRelease`/`UsageCharge`/`UsageOverageCharge` ledger rows together |
| `reserved_at` | timestamp | |
| `expires_at` | timestamp | operation-defined TTL |
| `committed_at` / `released_at` | timestamp, nullable | exactly one set on a terminal row |
| `final_quantity` / `final_amount_micro` | nullable | set only on commit |

Once `status` is `committed`, `released`, or `expired`, the row is never reopened.

**Algorithms, corrected this round:**

- **Reserve** — `UsageWalletManager::reserve()`. `DB::transaction()`, wallet row `findForUpdate()`. Idempotency: caller-supplied `idempotency_key`. Steps: look up the active rate via `platform_feature_usage_classifications.active_rate_id` (§11) → compute `reserved_amount_micro` from the exact-integer arithmetic in §10 → evaluate, **in the corrected §15 order**: `billing_status` → `outstanding_debt` → per-feature limit → Business spend cap → platform safety limit → available-balance sufficiency → insert the reservation row (`pending`) → insert the `Reservation` ledger entry (`available_delta: -amt`, `reserved_delta: +amt`) → update wallet aggregates, same transaction. Result: the reservation id, or a stable denial reason (`billing_suspended`, `outstanding_debt`, `feature_limit_exceeded`, `monthly_spend_cap_exceeded`, `platform_safety_limit_exceeded`, `insufficient_available_balance`) — evaluated strictly in that order.
- **Commit/finalize** — `UsageWalletManager::commit()`. Wallet row `findForUpdate()`. Steps: load the `pending` reservation → compare `final_amount_micro` (from the reservation's own snapshotted rate, never a re-read) against `reserved_amount_micro` → insert `UsageCharge` for `min(final, reserved)` → if `final > reserved`, additionally insert `UsageOverageCharge` for the overage (§10's delta rule — consumes available, then debt) → if `final < reserved`, additionally insert `ReservationRelease` for the unused portion → mark `committed`, set `committed_at`/`final_quantity`/`final_amount_micro` → update wallet aggregates → **after commit, dispatch `EvaluateBusinessAutoRecharge` if this mutation reduced `available_balance_micro`** (§19's corrected centralized trigger). Idempotency: repeat commit on an already-`committed` reservation is a no-op.
- **Release** — `UsageWalletManager::release()`. Steps: load `pending` reservation → insert `ReservationRelease` (`available_delta: +amt`, `reserved_delta: -amt`) → mark `released`. Idempotency: releasing a terminal reservation is a no-op.
- **Reservation expiry reconciliation** — `App\Jobs\Usage\ExpireStaleUsageReservations` finds `pending` reservations past `expires_at` and calls `release()`, setting `status = 'expired'`, `actor_user_id = null`. Never auto-commits a stale reservation.

**Atomicity / no double-charge — unchanged:** the caller reserves before work, commits or releases after. A crash between "work succeeded" and "commit called" leaves a `pending` reservation that expiry reconciliation eventually releases — favoring an unbilled operation over a double-charge.

---

## 14. Metered-feature classification and usage authorization

**Corrected this round: the gateway is now strictly a coarse, non-mutating, non-amount-specific gate, and its public denial reason is always exactly `usage_unauthorized` — never a detailed internal reason.** The prior draft had `RealUsageAuthorizationGateway` return detailed reasons (`billing_suspended`, `feature_limit_exceeded`, etc.) directly as `UsageAuthorizationResult::$reason`, which `EntitlementManager::decide()` passes through verbatim (§3's re-confirmed finding) — silently expanding RFC-004's locked nine-key `EntitlementDecision` denial surface to an unbounded, RFC-005-controlled set. This is corrected structurally:

**`App\Library\Usage\RealUsageAuthorizationGateway implements UsageAuthorizationGateway`** — its `check(Business $business, PlatformFeature $feature): UsageAuthorizationResult`:

1. If `$feature` is not classified as metered, return `authorized: true` immediately — the safe default for a non-metered or unknown classification is always "authorized."
2. If metered, delegate to `UsageWalletManager::evaluateCoarseCapacity()` (below), which returns an **internal-only** `UsageCapacityDecision` value object.
3. If `UsageCapacityDecision::$authorized` is `false`, return `new UsageAuthorizationResult(authorized: false, reason: 'usage_unauthorized')` — **always exactly this string, never `$capacityDecision->reason`**. The detailed internal reason is discarded at this exact boundary, by construction, not by convention.
4. If `true`, return `new UsageAuthorizationResult(authorized: true)`.

**`UsageCapacityDecision`** — new internal-only readonly value object (`bool $authorized`, `?string $reason`), whose `$reason` may be one of: `wallet_unavailable`, `unsupported_currency`, `billing_suspended`, `outstanding_debt`, `insufficient_available_balance`, `monthly_spend_cap_exceeded`, `feature_limit_exceeded`, `platform_safety_limit_exceeded`. Consumed by: the direct `reserve()` API (which needs the exact reason to return to its own caller, §13), logs, and authorized UI/admin surfaces (a "why was this denied" detail view, §24) — **never** by anything upstream of the gateway boundary in step 3 above.

**`UsageWalletManager::evaluateCoarseCapacity(Business $business, PlatformFeature $feature): UsageCapacityDecision`** — corrected this round to be genuinely coarse, since `UsageAuthorizationGateway::check()` receives no quantity/estimated cost and therefore cannot perform an amount-specific evaluation the way `reserve()` does:

1. Wallet must exist for the Business → else `wallet_unavailable`.
2. Wallet's currency must be the supported v1 settlement currency → else `unsupported_currency`.
3. `billing_status` must be `active` → else `billing_suspended`.
4. `debt_balance_micro` must be `0` → else `outstanding_debt`.
5. `available_balance_micro` must be `> 0` → else `insufficient_available_balance`.
6. Remaining headroom under the monthly Business spend cap must be `> 0` → else `monthly_spend_cap_exceeded`.
7. Remaining headroom under the applicable per-feature limit must be `> 0` → else `feature_limit_exceeded`.
8. Remaining headroom under the platform safety limit must be `> 0` → else `platform_safety_limit_exceeded`.

**The exact quantity/rate/cap/balance decision — whether a *specific* reservation amount fits within remaining headroom — occurs only in `reserve()` (§13), never here.** A caller that both checks `decide()` and then reserves is expected and correct; `decide()`'s own check is a fast, coarse, non-mutating pre-check.

**14.1 Feature classification.** `platform_feature_usage_classifications`: `feature_key` (unique, `PlatformFeature`-backed), `is_metered` (boolean, default false), `active_rate_id` (FK `business_usage_rates`, nullable — **new/renamed this round**, replaces the prior draft's `default_rate_id`, and is the sole pointer §11's activation algorithm maintains), `updated_by_user_id`, `updated_at`. Every `PlatformFeature` case defaults `is_metered = false` on backfill — no existing feature becomes metered as a side effect of this RFC shipping.

---

## 15. Monthly Business budget, per-feature limits, and platform safety limits

Three genuinely distinct, non-collapsible controls (Human product requirements 2–4; locked decisions 8(a)/16), each now with its own append-only transition audit table (closing a gap the prior draft's self-audit missed):

1. **Monthly Business spend cap** (`business_usage_wallets.monthly_spend_cap_micro`) — null = uncapped, bounded only by the platform safety limit.
2. **Per-feature limits** (`business_feature_usage_limits`: `business_id` FK composite-protected, `feature_key`, `monthly_limit_micro` nullable, `updated_by_user_id`, `updated_at`, unique `(business_id, feature_key)`).
3. **Platform safety limit** (`platform_feature_usage_safety_limits`: `feature_key` unique, `max_monthly_limit_micro`, `updated_by_user_id`, `updated_at`) — a customer-supplied per-feature limit greater than the matching safety limit is rejected at write time.

**`business_usage_limit_transitions`** — new this round (referenced by the prior draft's §15 prose but never actually added to the table list — corrected):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `business_id` | FK `businesses`, `restrictOnDelete()` | null-able only if `limit_type = platform_safety_limit` (platform-scoped, not Business-scoped) |
| `limit_type` | string(24), enum-backed | `business_spend_cap` \| `feature_limit` \| `platform_safety_limit` |
| `feature_key` | string(64), nullable | set only for `feature_limit`/`platform_safety_limit` rows |
| `from_value_micro` / `to_value_micro` | bigint, nullable | |
| `actor_user_id` | unsigned bigint, no FK | |
| `reason` | text | mandatory |
| `created_at` | timestamp | |

**Evaluation order, corrected this round to include the new `outstanding_debt` gate (both at reserve-time, §13, and at `decide()`'s coarse pre-check, §14):** structural entitlement (RFC-004 steps 1–6) → Business toggle → `billing_status` → `outstanding_debt` → per-feature limit → Business monthly spend cap → platform safety limit → (reserve path only) available-balance sufficiency. Each has its own stable internal reason; no two are ever collapsed.

**Reservation-near-cap / concurrency — unchanged in mechanism:** the reserve algorithm's cap check evaluates the reservation's amount against remaining headroom under all controls, serialized via the wallet row's `findForUpdate()` lock — exactly one of two racing reservations succeeds.

**Period/timezone — corrected this round to be lazy and authoritative, never scheduler-dependent for correctness.** `spend_period_start_utc`/`spend_period_end_utc` and `recharge_period_start_utc`/`recharge_period_end_utc` (§12) are exact UTC instants. **Every** cap/recharge evaluation (a `reserve()` call, an auto-recharge eligibility check) first locks the wallet row, then checks whether `now() >= period_end_utc`; if so, it resets the matching cached period counters (`committed_spend_this_period_micro`, `reserved_spend_this_period_micro`, or `recharged_this_period_micro`) to zero and advances the period boundary to the next window (computed from the Business's configured timezone, falling back to the platform default) — **all within the same lock/transaction as the operation being evaluated, before any cap check runs.** The scheduled `AdvanceUsagePeriodBoundaries` job becomes **optional proactive maintenance only** — it keeps dormant wallets' cached period fields fresh for read-only display purposes, but is never required for correctness: a wallet with zero activity for months still rolls over correctly the moment its next real reservation attempt evaluates its period. **A Business timezone change affects the next period only** — it never retroactively recomputes an already-in-progress period's boundaries.

**Override/adjustment — unchanged:** a platform administrator may adjust any of the three limits with mandatory actor/reason, now recorded in `business_usage_limit_transitions` (above) rather than a described-but-unbuilt table.

**Isolation test requirement — unchanged:** one Business's cap/limit/budget state must never affect a different Business's, proven by a direct concurrency test (§35).

---

## 16. Payer selection and Workspace fallback

**Corrected in this round: payer selection now requires the *target* payer's own consent, not merely "some authorized actor changed it."** The prior draft's authorization matrix allowed a direct Business owner to switch a Business's payer to `workspace` (volunteering a Workspace-owned instrument without that Workspace's consent) and allowed a Workspace owner to switch a Business's payer to `business` (volunteering that Business's own instrument without its owner's consent) — both real gaps, since "who may call `changePayer()` at all" is a different question from "who may authorize *that specific target payer's* money being used."

**`business_payer_assignments`** (current pointer, one row per Business, mutable): `business_id` FK unique composite-protected, `payer_type` (`business` \| `workspace` \| `agency_rebill`, the third never activated in v1), `effective_payment_instrument_id` (FK `business_payment_instruments`, nullable), `updated_at`.

**`business_payer_transitions`** (append-only history — **columns expanded this round** to snapshot the instrument, not only the payer type): `id`, `business_id` FK, `from_payer_type` / `to_payer_type`, `from_instrument_id` / `to_instrument_id` (nullable FK, **new this round**), `actor_user_id` (no FK), `reason`, `created_at`.

**Corrected payer-consent rules:**

- Setting `payer_type = 'workspace'` (or pointing `effective_payment_instrument_id` at a Workspace-owned instrument) may be authorized **only** by: the Workspace owner, or a platform administrator (mandatory reason).
- Setting `payer_type = 'business'` (or pointing at a Business-owned instrument) may be authorized **only** by: the direct Business owner/customer, or a platform administrator (mandatory reason).
- **Active Workspace Admin and Staff can never change payer, in either direction** — unchanged from the prior draft, but now explicit that this applies to *both* target directions, not just generically.
- **No actor may select or cause a charge against an instrument owned by a payer other than the one being set** — enforced structurally: `PaymentInstrumentManager` validates the instrument's owner (via its `payment_provider_customers` row, §17.B) matches the `payer_type` being written, in the same transaction as the payer change.
- **Plan-tier defaults are system-authored at backfill/creation** (unchanged — Core/Growth defaults to `workspace`, Agency defaults to `business`), **but this never auto-attaches an instrument or authorizes a charge** — `effective_payment_instrument_id` starts `null` regardless of the defaulted `payer_type`, and no top-up/recharge/checkout proceeds until the relevant payer has actually supplied/approved an instrument (the existing `no_payment_instrument` denial, below, is unchanged in shape but now understood to be the *normal*, expected state immediately after backfill, not an edge case).

**Effective payer resolution algorithm — unchanged in shape:** read `payer_type` → resolve the corresponding owner's default `business_payment_instruments` row (via that owner's `payment_provider_customers` row, §17.B) → if `agency_rebill`, unresolved (out of v1 scope) → if no instrument found, fail with `no_payment_instrument`.

**Payer change algorithm — `BillingProfileManager::changePayer()`, corrected this round:** `DB::transaction()`, wallet row `findForUpdate()`, **the target-consent check above** (rejecting an actor attempting to volunteer a payer type they are not authorized to set), mandatory `actor_user_id`/`reason`, validates any supplied instrument's ownership matches the target `payer_type`, updates `business_payer_assignments`, inserts one `business_payer_transitions` row snapshotting both the payer-type and instrument change, dispatches `BusinessPayerChanged implements ShouldDispatchAfterCommit`. Unchanged: never touches wallet balances, never touches any ledger entry, never moves any row between Businesses.

---

## 17. Billing contact and payment instruments

### A. Billing contact — unchanged in shape, with historical-snapshot behavior corrected (§17.C)

**`business_billing_contacts`**: `business_id` FK unique composite-protected, `contact_user_id` (FK `users`, nullable), `contact_name` / `contact_email` (nullable if `contact_user_id` set), `notification_opt_in` (boolean, default true), `updated_by_user_id` (no FK), `updated_at`. Exactly one of (`contact_user_id`) or (`contact_name`+`contact_email`) is required — manager-enforced.

**Privacy/isolation — unchanged:** `business_billing_contacts` is `business_id`-scoped; no Workspace-level read path lists every Business's contact in one query without per-Business authorization.

**Notification recipient selection — unchanged:** `contact_email` if opted-in and present → else the Business's direct owner/customer email → else no notification.

### B. Provider customers and payment instruments — redesigned this round

**Corrected: the prior draft stored a duplicated Stripe Customer ID directly on every instrument row while separately claiming "one Stripe Customer per owner" — an unenforced, drift-prone claim.** Provider-customer ownership is now its own table, and instruments reference it rather than embedding ownership themselves — which also **structurally eliminates** the "exactly one of `business_id`/`workspace_id`" duplicate-tenancy-ID risk the prior instrument table carried, since ownership now lives in exactly one place.

**`payment_provider_customers`** — new this round:

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `provider` | string(16), enum-backed | `stripe` only, v1 |
| `business_id` | FK `businesses`, nullable, `restrictOnDelete()` | exactly one of `business_id`/`workspace_id` set (manager-enforced; `CHECK` constraint where MySQL 8+ is confirmed) |
| `workspace_id` | FK `workspaces`, nullable, `restrictOnDelete()` | |
| `provider_customer_id` | string(191) | the Stripe Customer id |
| `status` | string(16), enum-backed | `active` \| `detached` |
| `created_at` / `updated_at` | timestamp | mutable — `status` may change |

`UNIQUE (provider, business_id)` and `UNIQUE (provider, workspace_id)` — MySQL's unique-index treatment of `NULL` (multiple `NULL`s permitted) means a Business-owned row's `NULL` `workspace_id` never collides with a Workspace-owned row's `NULL` `business_id`, giving "at most one active Stripe Customer per owner" as a real, enforced constraint rather than a prose claim.

**`business_payment_instruments`** — redesigned to reference a provider customer rather than embed ownership:

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `provider_customer_id` | FK `payment_provider_customers`, `restrictOnDelete()` | ownership derives transitively — no separate `business_id`/`workspace_id` on this table at all |
| `provider_payment_method_id` | string(191) | Stripe PaymentMethod id — token reference only, never raw card data |
| `type` | string(24), enum-backed | e.g. `card`; extensible |
| `brand` | string(24), nullable | safe display metadata only |
| `last_four` | string(4), nullable | |
| `expiry_month` / `expiry_year` | unsigned tinyint / unsigned smallint, nullable | card only |
| `is_default` | boolean | one-default-per-provider-customer, enforced under that row's own lock, below |
| `status` | string(16), enum-backed | `active` \| `detached` |
| `created_at` / `detached_at` | timestamp / nullable | never deleted — detach only |

**One-default-instrument serialization:** `PaymentInstrumentManager::setDefaultInstrument()` locks the owning `payment_provider_customers` row (`findForUpdate()`) before clearing any other instrument's `is_default` and setting the new default, all in one transaction — prevents a race producing two simultaneous defaults.

**Detach behavior — unchanged in effect:** detaching an instrument still referenced as a payer's `effective_payment_instrument_id` clears that pointer and sets `status = 'detached'`, in the same transaction, via `PaymentInstrumentManager`; the row itself is never deleted (financial audit trail — a past charge's own snapshot, §17.C below, is the durable record, not a live join to this table).

**Authority split, corrected this round (also reflected in §24):** managing a **Business-owned** instrument requires the direct Business owner/customer or a platform administrator; managing a **Workspace-owned, shared** instrument requires the Workspace owner or a platform administrator — **a Workspace Admin's `business_access_scope` covering a given Business never grants authority over the Workspace's own shared instrument**, since Business-scoped access and Workspace-shared-resource access are deliberately different authorization surfaces (`AGENTS.md`'s "Workspace authorization" separation, applied here).

### C. Durable payment/funding-attempt model and historical snapshots — new this round

**The prior draft had no local pending record for a manual top-up before creating a Checkout Session, so a webhook had no trustworthy local expected Business/amount/currency/payer to validate against.** Corrected with a durable, Stripe-state-accurate attempt table used by every Business-wallet-scoped payment flow (top-up, auto-recharge, add-on purchase — the Workspace-scoped additional-slot agreement, §22, uses its own parallel shape since it is not wallet-scoped):

**`business_funding_attempts`** — new this round:

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `business_id` / `wallet_id` | FK, composite-protected (§12) | |
| `purpose` | string(24), enum-backed | `manual_top_up` \| `auto_recharge` \| `addon_purchase` |
| `addon_purchase_id` | FK `business_usage_addon_purchases`, nullable | set only when `purpose = addon_purchase` |
| **Historical snapshot fields, added this round per the contract's payer-history requirement:** | | |
| `payer_type_snapshot` | string(16) | |
| `billing_contact_name_snapshot` / `billing_contact_email_snapshot` | string, nullable | the contact **as of this attempt**, never a live lookup |
| `provider_customer_external_id_snapshot` | string(191) | the Stripe Customer id at attempt time, denormalized alongside the FK below |
| `provider_customer_id` | FK `payment_provider_customers`, `restrictOnDelete()` | traceability only — the snapshot column above is authoritative for display even if this row is ever archived |
| `payment_method_display_snapshot` | string(64) | e.g. `"visa •••• 4242, exp 12/26"` — a display string only, never a token |
| `requesting_actor_user_id` | unsigned bigint, no FK | |
| **Expected/verification fields:** | | |
| `expected_currency_id` | FK `currencies`, `restrictOnDelete()` | |
| `expected_amount_micro` | bigint | |
| `local_idempotency_key` | string(191), unique | the deterministic key derived for the outbound Stripe call (§21) |
| `provider_session_or_intent_reference` | string(191), nullable | |
| `state` | string(16), enum-backed | `created` \| `provider_pending` \| `requires_action` \| `processing` \| `succeeded` \| `failed` \| `canceled` \| `refunded` \| `disputed` |
| `failure_reason` | text, nullable | |
| `created_at` / `updated_at` | timestamp | mutable — state transitions happen here, with full history in the table below |

**`business_funding_attempt_transitions`** — new this round, append-only, the durable transition history required for every mutable commercially-significant state (self-audit requirement):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `funding_attempt_id` | FK `business_funding_attempts`, `restrictOnDelete()` | |
| `from_state` / `to_state` | string(16) | |
| `source` | string(24), enum-backed | `sync_response` \| `webhook_event` \| `admin_action` \| `reconciliation_job` |
| `provider_event_id` | FK `payment_provider_events`, nullable | set when `source = webhook_event` |
| `actor_user_id` | unsigned bigint, nullable, no FK | |
| `created_at` | timestamp | |

**Historical snapshot rule, applied consistently:** a later change to a Business's billing contact, payer, or payment instrument **never** alters an already-created `business_funding_attempts` row's snapshot fields — each attempt permanently records who/what it actually billed at the time. **Retention/privacy:** snapshot fields (contact name/email, payment-method display string) are retained for the funding attempt's own lifetime plus the platform's standard financial-record retention period (an exact duration is an M3 implementation/operational detail, not fixed here); they are never more sensitive than data already visible to the Business's own authorized users, so no additional encryption-at-rest is required for these fields specifically (contrast with full webhook payloads, §33, which do require it).

---

## 18. Manual credit, paid top-up, promotional credit, and add-ons

Unchanged in overall shape from the prior draft, with delta behavior corrected per §10/§12 (debt-clearing-first) and top-up now routed through the durable funding-attempt model (§17.C):

- **Manual credit** — `UsageWalletManager::addManualCredit()`, platform-administrator-only (mandatory actor+reason), restricted to exactly one named Business (Human product requirement 5) — never a batch/pooled operation.
- **Paid top-up** — `UsageBillingCheckoutManager::initiateTopUp()` creates a `business_funding_attempts` row (`purpose: manual_top_up`, `state: created`) → creates the Stripe Checkout Session/PaymentIntent (§20, outside any transaction) → on the attempt reaching `succeeded` (webhook-authoritative, §21; see also §20's note on the browser-redirect UX nicety), `UsageWalletManager::recordPaidTopUp()` inserts the `PaidTopUp` ledger entry (debt-clearing-first, §10), referencing `funding_attempt_id`.
- **Promotional credit** — same mechanics as manual credit, distinct `entry_type`, used for the owner/operator Agency subsidy mechanism (§39 item 5); always actor+reason attributed, `actor_user_id = null` and an automated-policy reason for system-issued grants.
- **Reversal/expiry policy — unchanged:** v1 credits do not expire and are not automatically reversed; removal is a `UsageChargeReversal`-adjacent admin correction (in practice, a `CorrectionReversal` with mandatory reason), never a silent overwrite.

**Add-ons** (Human product requirement 6) — unchanged table shapes, with the "seeded" wording corrected: `business_usage_addon_catalog` (`addon_key` unique, `display_name`, `price_micro`, `currency_id`, `fulfillment_mode` `wallet_credit` \| `direct_deliverable`, `is_active`) — **not seeded at M4 launch (zero rows)**, corrected from the prior draft's self-contradictory "seeded with zero rows" phrasing; and `business_usage_addon_purchases` (`business_id` FK composite-protected, `addon_key`, `price_micro` snapshot, `funding_attempt_id` FK — **new this round**, replacing the ad-hoc `provider_reference`/`idempotency_key` columns the prior draft duplicated here, since the payment leg now lives entirely in `business_funding_attempts`, `status` `pending`\|`completed`\|`failed`, `requested_by_user_id`, `completed_at` nullable, `created_at`). A `wallet_credit` add-on's completion posts a ledger entry; a `direct_deliverable` add-on's completion dispatches a fulfillment job with no wallet interaction. The one worked v1 example (a purchasable audit) is recommended `direct_deliverable` (§39 item 8 leaves the exact v1 roster/pricing open).

**Cross-Business isolation — unchanged:** no manager method accepts two Business ids in one credit/purchase call; pooling/transfer across Businesses is structurally inexpressible in v1.

---

## 19. Auto-recharge and low-balance behavior

**Corrected this round: auto-recharge is no longer triggered synchronously inside or at the end of a locked usage-commit transaction — it is dispatched as an after-commit job, and the trigger is centralized across every balance-decreasing mutation, not only `commit()`.**

**`App\Jobs\Usage\EvaluateBusinessAutoRecharge`** (`ShouldQueue` + `ShouldQueueAfterCommit`, `App\Jobs\Base` convention) — dispatched after **every** `UsageWalletManager` mutation that decreases `available_balance_micro`: `commit()`'s `UsageCharge`/`UsageOverageCharge`, an admin `CorrectionReversal` debit, a `Refund`, a `DisputeChargeback` (though this one will also find `billing_status = 'suspended'` and correctly decline to recharge, below). This closes the prior draft's gap where only `commit()` could trigger a check — refunds, chargebacks, and admin debits could silently leave a Business below threshold with no recharge evaluation ever firing.

**Job algorithm:**

1. Cheap, lock-free pre-check: is `auto_recharge_enabled`, is `available_balance_micro < auto_recharge_threshold_micro`, is there no already-`created`/`provider_pending`/`requires_action`/`processing` `business_funding_attempts` row of `purpose: auto_recharge` for this Business? If any fails, exit (no-op).
2. If eligible: `DB::transaction()`, wallet row `findForUpdate()`, **re-check `billing_status = 'active'` and re-check the eligibility conditions above under the lock** (closing the prior draft's race where a second dispatched job could duplicate an attempt) → compute the recharge amount bounded by remaining `monthly_recharge_cap_micro` headroom (lazily rolled over, §15) → if the cap leaves zero headroom, skip the attempt and only fire the low-balance notification path → else create the `business_funding_attempts` row (`purpose: auto_recharge`, `state: created`) → commit, releasing the lock.
3. **Outside any transaction**, call `PaymentProviderGateway::chargeOffSession()`.
4. Record the synchronous outcome via `UsageWalletManager::recordFundingAttemptOutcome()` — the same method webhook processing (§21) also calls, idempotent on `local_idempotency_key`/`provider_session_or_intent_reference`, inserting a `business_funding_attempt_transitions` row and, on `succeeded`, the `AutoRecharge` ledger entry.

**Failed-payment handling — unchanged in shape, now keyed to the funding-attempt state model:** a `failed` terminal state applies the retry/disable rule — a recommended (category-3) **3 consecutive failures** disables `auto_recharge_enabled` and sends a distinct "auto-recharge disabled" notification; the exact count remains adjustable by a future milestone with justification, not fixed as a hard requirement of this document.

**`requires_action` handling — new this round, since the prior draft's `pending/succeeded/failed` shape had no room for it.** An off-session charge that returns `requires_action` (e.g., 3-D Secure) cannot complete without the customer present — `EvaluateBusinessAutoRecharge` treats this identically to a `failed` attempt for the purpose of the consecutive-failure counter (an off-session recharge that needs customer action is not a successful automated recharge), but records the distinct `requires_action` state and sends a notification directing the customer to complete a manual top-up instead, rather than silently retrying an off-session charge that will predictably fail the same way.

**Low-balance notification dedup — locked this round as an explicit category-3 recommendation (was previously left as "an M3 implementation detail"):** `low_balance_notified_at` is set the first time a period crosses below threshold; not re-sent until either the balance recovers above threshold or 24 hours have elapsed, whichever comes first. This exact interval is a recommended default, adjustable by a future milestone with justification, not an open human decision requiring §39.

**Zero-balance / reserved-balance behavior — unchanged:** a reserve request that would take `available_balance_micro` negative is denied (`insufficient_available_balance`); never blocks reads, never deactivates tenancy, never blocks a non-metered feature.

**Customer-configurable limits vs. platform safety limit — unchanged:** recharge threshold/amount/cap remain customer-configurable within RFC-003 §26.2's inherited bounds, never a channel to bypass §15's platform safety limit (a separate control governing spend, not recharge).

---

## 20. Stripe/provider boundary

**Resolution unchanged: Stripe-only v1**, behind `PaymentProviderGateway` (§9). **Stripe Customer ownership corrected this round** to live in `payment_provider_customers` (§17.B), not duplicated on every instrument row.

**SetupIntent / instrument attachment — unchanged:** `PaymentProviderGateway::createSetupIntent()` for adding an instrument without an immediate charge.

**Checkout Session vs. PaymentIntent — unchanged per-flow resolution:** Checkout Session for customer-present, one-time flows (top-up, add-on purchase, additional-slot agreement's initial and renewal charges); PaymentIntent with `off_session: true`/`confirm: true` for auto-recharge. The browser-redirect `sessions->retrieve()` pattern (the legacy precedent, §3) is kept only as a synchronous UX nicety; **webhook-based confirmation, landing in `business_funding_attempts`' state machine, is the sole authoritative source of truth** (§21).

**Webhook verification — corrected this round to also use the already-configured tolerance value:** `PaymentProviderGateway::verifyWebhookSignature()` wraps `\Stripe\Webhook::constructEvent($payload, $sigHeader, config('services.stripe.webhook.secret'), config('services.stripe.webhook.tolerance'))` — the prior draft omitted the fourth `$tolerance` argument despite `config('services.stripe.webhook.tolerance')` already existing and being unused; this round wires it in, using both previously-inert configuration values Stripe's own signature-verification API accepts. Reads the raw request body, never framework-parsed JSON. The webhook route is a new `VerifyCsrfToken` `$except` entry, never behind session auth.

**Stripe API version posture — new this round, distinguished from the PHP SDK version:** the installed **PHP SDK** is `v7.128.0` (within the pinned `^7.76` composer constraint) — but Stripe's own **API version** (the versioned request/response *shape*, set via `Stripe::setApiVersion()` or the Stripe account dashboard's mutable default) is an entirely independent concept this RFC does not conflate with the SDK version. **Recommendation:** the future implementation explicitly pins a specific Stripe API version string via `Stripe::setApiVersion()` in code, rather than relying on the Stripe account's own mutable dashboard default — so a later, unrelated dashboard change can never silently alter webhook payload shape underneath this integration. The exact version string to pin is an M3 implementation detail, not a product decision.

**Event persistence, replay, and idempotency — corrected this round with an exact schema and a corrected algorithm (§21).**

**Reconciliation — extended this round** to also sweep stuck `business_funding_attempts` (not only the prior draft's generic "pending local attempt/purchase") and stuck additional-slot agreements (§22).

**No outbound Stripe call ever occurs while a database row lock is held — unchanged, strengthened by the job-based auto-recharge rework (§19), which makes this separation structural rather than merely described.**

**Test-mode separation — unchanged:** every automated test fakes `PaymentProviderGateway` entirely; no automated test ever calls real Stripe.

**SDK version recommendation — unchanged: retain `stripe/stripe-php ^7.76` (`v7.128.0`) for v1.** This document does not perform or choose any upgrade.

**Wording correction, applied throughout this document:** every prior reference to "exactly-once accounting" is replaced with **"effectively exactly-once local accounting effect under at-least-once delivery"** — provider/network delivery itself is not exactly-once; only this platform's own idempotent local handling makes the *effect* exactly-once.

---

## 21. Webhook verification, persistence, replay, and reconciliation

**`payment_provider_events`** — given its complete schema this round (the prior draft deferred it entirely):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `provider` | string(16), enum-backed | `stripe` |
| `provider_event_id` | string(191), unique | Stripe's own event id — the idempotency key |
| `event_type` | string(64) | |
| `provider_object_id` | string(191) | the Checkout Session/PaymentIntent/etc. id the event concerns |
| `payload_encrypted` | text/blob, encrypted at rest | **minimized/encrypted, not a raw indefinite payload** — see §33 |
| `payload_hash` | string(64) | SHA-256 of the raw verified payload, for integrity/dedup checks independent of decrypting the payload |
| `state` | string(16), enum-backed | `received` \| `processing` \| `processed` \| `failed` \| `ignored` |
| `attempts` | unsigned smallint, default 0 | |
| `last_error` | text, nullable | |
| `received_at` / `processed_at` | timestamp / nullable | |

**Corrected replay/processing algorithm:**

1. On receipt, insert the event row (`state: received`) — the unique constraint on `provider_event_id` is the true-duplicate guard; a genuine replay's insert fails/conflicts and is recognized as already-known **immediately**, without assuming "already known" means "already processed" (see step 4).
2. **Insertion alone does not mean the event was processed.** A worker claims an event via an atomic conditional update (`state: received → processing`, or `state: failed AND attempts < max → processing`) — never a bare `SELECT` followed by a separate `UPDATE`.
3. Processing resolves the local subject: finds the matching `business_funding_attempts`/`additional_business_slot_agreements` row by `provider_session_or_intent_reference` — **never trusts webhook metadata as authoritative on its own** — and validates the verified Stripe object's customer, amount, currency, and purpose against the local attempt's own expected values. A mismatch marks the event `failed` with a descriptive `last_error` and triggers **reconciliation** (re-fetching the current object from Stripe) rather than blindly applying the event.
4. On match, the event drives the local `state` transition only if it is a **valid forward transition** per the state table in §17.C/§19 (`created → provider_pending → {requires_action, processing, succeeded, failed, canceled}`; `requires_action → {processing, canceled}`; `processing → {succeeded, failed}`; `succeeded → {refunded, disputed}`; any state `→ disputed` (chargebacks may arrive at any time)). An event implying an **invalid** transition (e.g., a `succeeded` event arriving after a `refunded` one already applied) does **not** blindly overwrite — it triggers reconciliation against Stripe's current object state and is otherwise held for admin review.
5. If a worker crashes after step 1 (event durably inserted) but before step 3 completes, the event row remains `received` or `processing` — a scheduled sweep (`App\Jobs\Usage\ReconcileProviderPendingState`, extended this round) resumes any `received`/stuck-`processing`/`failed`-with-attempts-remaining row; **only `processed` or intentionally `ignored` events short-circuit as done.**
6. Downstream mutations (`recordPaidTopUp`, `recordFundingAttemptOutcome`, additional-slot allocation) remain independently idempotent on their own keys regardless of event arrival order — idempotency and ordering are two separate concerns, and idempotency alone does not solve out-of-order delivery (the valid-transition check in step 4 does).

---

## 22. Additional Business-slot agreement (renamed and rebuilt this round — a recurring saga, not a one-time purchase)

**RFC-004 explicitly describes charges corresponding to already-allocated additional Business slots as *recurring* (RFC-004 §5/§18/§13's "recurring charge" language, re-confirmed by direct read this round). The prior draft modeled a single one-time Checkout Session permanently granting capacity — an outright mismatch with what RFC-004 itself says this charge is.** Corrected: an ongoing agreement with its own initial-purchase saga and a separate, repeating renewal-charge record.

**Also carrying the cross-RFC blocker, above: the allocation step of this section remains `NON-IMPLEMENTATION-READY` until a human resolves that conflict.** Everything else in this section — quoting, checkout, durable payment records, refunds, recurring renewal bookkeeping — is implementation-ready on its own.

**Operational prerequisite, restated here from the RFC-004 M4 deployment guide's own finding:** RFC-004 currently ships **no** normal HTTP/operator surface for mutating `workspace_plan_catalog` pricing (`updateCatalogPricing()` has no route) — an authorized pricing tool/surface is a prerequisite for this section's own M4 to be operational, independent of the allocation blocker.

**`additional_business_slot_agreements`** — renamed and restructured from the prior draft's `additional_business_slot_purchases`:

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `workspace_id` | FK `workspaces`, `restrictOnDelete()` | Workspace-scoped, not Business/wallet-scoped — additional slots are a Workspace-level entitlement (RFC-004), paid via a **Workspace-owned** instrument only, never Business payer resolution (§16) |
| `current_allocation_count` / `target_allocation_count` | unsigned tinyint | this table's own billing-side bookkeeping view; RFC-004's `workspace_plan_assignments.additional_business_slots` remains the sole authoritative entitlement value |
| `paid_delta` | unsigned tinyint | `target - current` at agreement-creation time |
| `price_per_slot_micro_snapshot` / `currency_id_snapshot` / `ratio_snapshot` | bigint / FK / decimal(6,4) | from `workspace_plan_catalog.price`/`additional_business_slot_price_ratio` at quote time — read, never re-derived |
| `plan_catalog_id_snapshot` / `plan_tier_snapshot` | FK / string | which catalog/tier was quoted against |
| `requesting_customer_user_id` | unsigned bigint, no FK | **distinguished from the system/payment actor**, per the blocker's option 3 requirement, even though this document does not build option 3 |
| `billing_cadence` | string(16), enum-backed | `monthly` (matching the Workspace plan's own `billing_cycle`) |
| `next_renewal_at` | timestamp, nullable | null once no further renewal is scheduled (e.g., `canceled`) |
| `payment_lapsed` | boolean, default false | see renewal-failure handling below |
| **Initial-purchase payment-leg fields (mirroring §17.C's snapshot shape, inlined here since this table is Workspace- not Business-scoped):** | | |
| `provider_customer_id` (Workspace-owned) / `payment_method_display_snapshot` / `local_idempotency_key` (unique) / `provider_session_or_intent_reference` | — | same meaning as §17.C |
| `state` | string(20), enum-backed | `quote_created` \| `checkout_pending` \| `payment_succeeded` \| `allocation_pending` \| `completed` \| `payment_failed` \| `allocation_failed` \| `refund_pending` \| `refunded` \| `canceled` |
| `created_at` / `updated_at` | timestamp | mutable — transitions recorded in `business_funding_attempt_transitions`-equivalent audit, below |

**`additional_business_slot_renewal_charges`** — new this round, append-only, one row per period renewal attempt:

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `agreement_id` | FK `additional_business_slot_agreements`, `restrictOnDelete()` | |
| `period_start` / `period_end` | timestamp | |
| `amount_micro_snapshot` | bigint | |
| `local_idempotency_key` | string(191), unique | |
| `provider_session_or_intent_reference` | string(191), nullable | |
| `state` | string(16), enum-backed | same shape as §17.C's funding-attempt states |
| `failure_reason` | text, nullable | |
| `created_at` | timestamp | |

**Saga rule, exact per this round's correction: `payment_succeeded` and `completed` are distinct states, and the agreement is never marked `completed` before the allocation step actually succeeds.** This is precisely how the cross-RFC blocker's Option 1 operates in practice: a customer's successful payment lands the agreement in `payment_succeeded` → `allocation_pending`; a **real** platform administrator then performs the allocation via RFC-004's existing, already-authorized admin-panel path to `setAdditionalBusinessSlots()`, and only that success moves the agreement to `completed`. If allocation cannot proceed (the blocker, or any other failure), the agreement lands in `allocation_failed` — a durable, admin-actionable state, never a silently lost payment.

**Sequence:** quote (`UsageBillingCheckoutManager::quoteAdditionalSlots()`, read-only) → Checkout Session created for the quoted price, agreement `state: checkout_pending` → webhook-authoritative confirmation moves it to `payment_succeeded` → `allocation_pending` → (pending the blocker's resolution) `completed`.

**Idempotency — unchanged in principle:** `local_idempotency_key` absorbs duplicate webhook delivery/customer retry; `setAdditionalBusinessSlots()` itself remains idempotent on an unchanged count.

**Complimentary/Agency-unlimited behavior — unchanged:** a complimentary Workspace or an Agency-unlimited catalog never reaches checkout at all (`quoteAdditionalSlots()` returns "no purchase needed").

**Edge cases, added this round per the explicit requirement list:**

- **Two concurrent slot checkouts for one Workspace** — serialized via a lock on the Workspace's own row (or a dedicated per-Workspace lock target) before a second concurrent `checkout_pending` agreement may be created; the second attempt is denied or queued behind the first's resolution.
- **Plan change or Workspace-becomes-complimentary/Agency-unlimited during checkout** — the quoted price is fixed into the already-created Checkout Session (Stripe's own session amount is immutable), so payment still completes at the quoted price; if the Workspace's status changed such that this purchase is no longer necessary or valid by the time allocation is attempted, the allocation step's own validation (`assertValidAdditionalBusinessSlots`, or the discovery that the Workspace is now complimentary/unlimited) surfaces the mismatch, and the agreement moves toward `refund_pending` → `refunded` rather than a forced, inappropriate allocation.
- **Payment success followed by allocation failure** — `allocation_failed`, the blocker's core scenario, always durable and admin-actionable.
- **Retry/reconciliation** — the same reconciliation sweep (§21) extended to stuck `allocation_pending`/`allocation_failed` agreements.
- **Refund** — `refund_pending` → `refunded`, admin- or Stripe-dispute-initiated; this Workspace-scoped flow does not maintain a parallel full ledger the way the Business wallet does (locked decision 2 scopes the ledger requirement to Business-level usage, not Workspace-level slot billing) — the `additional_business_slot_renewal_charges`/agreement transition audit plus Stripe's own record together serve as this flow's audit trail, a deliberately narrower scope than §12's wallet ledger, stated explicitly to avoid ambiguity.
- **Failed renewal** — a failed `additional_business_slot_renewal_charges` row triggers retry/dunning (mirroring §19's consecutive-failure pattern); after a bounded number of failures, `payment_lapsed = true` is set on the agreement. **Per the explicit instruction that revoking paid allocation never deletes, deactivates, or hides existing Businesses:** a lapsed agreement's *already-allocated* slots are **not** automatically revoked by this design — the Workspace may remain grandfathered over its now-unpaid capacity, blocked only from *further* growth while lapsed. **Whether and how to eventually revoke grandfathered-over-capacity slots after a prolonged lapse is an open human decision, not resolved here** (§39 item 13) — this document does not invent a silent auto-revocation policy.

---

## 23. Refunds, disputes, chargebacks, invoices, receipts, and tax/VAT boundary

**Refunds, disputes, and reversals — corrected this round to use the four distinct entry types §10/§12 define**, rather than a single `Refund` type: a `Refund` (money to the payer) is posted for admin-initiated or Stripe-initiated refunds; a `DisputeChargeback` (provider clawback, also suspending `billing_status`) for chargebacks; a `UsageChargeReversal` for an admin crediting a previously committed usage charge back to available balance with no real money movement; and a `CorrectionReversal` for fixing an unrelated erroneous entry. v1 does not attempt automated dispute-response logic; disputes are recorded and surfaced to a platform administrator (§24).

**Invoices/receipts — unchanged recommendation: Stripe-hosted receipts are authoritative for v1.** `business_billing_receipts` (`business_id` FK composite-protected, `ledger_entry_id` FK, `provider_receipt_url`, `provider_reference`, `created_at`) stores only a pointer/cache, never a new authoritative fiscal record. The legacy `invoices` table remains explicitly unreused (`string`-typed `amount`, `cascade` FKs — the opposite of this design's posture).

**Tax/VAT posture — corrected this round: not asserted safe by default.** The prior draft's "explicit no-tax v1 is the safe recommendation" framing is withdrawn. **Production payment collection under this design is marked `NON-IMPLEMENTATION-READY` until the platform operator obtains its own accounting/legal determination of tax/VAT obligations for its actual selling entity and the actual jurisdictions of its customers.** This RFC may recommend Stripe Tax as an *operationally* convenient integration point (§20's provider boundary can accommodate it with a bounded addition), but makes **no claim of legal sufficiency** for any tax posture, and Stripe-hosted receipts are **not** automatically asserted to be legally sufficient tax invoices in any given jurisdiction. No production-collection milestone may silently defer a legally required invoice/tax obligation by treating "we chose not to collect tax" as a resolved, safe default. **This is design documentation, not legal advice**, and the exact operational tax posture remains §39 item 6, now explicitly framed as a legal/compliance gate on production launch rather than an ordinary feature-scope decision.

**Refund/credit-note relationship — unchanged:** a `Refund` ledger entry is this design's credit-note-equivalent record; v1 does not generate a separate formal document titled "credit note" — if a future tax/VAT decision requires one as a legal document, that is scoped into whichever milestone resolves §39 item 6, not invented here.

---

## 24. Authorization and tenant isolation

Five genuinely distinct authority paths, per `AGENTS.md`'s "Workspace authorization" section and RFC-004 §20's inherited absolute rule — one path's grant never implies another's. **Table corrected this round: "Manage payment instruments" is split by ownership (§17.B), and "Change payer" is split by target direction (§16), closing the consent gap this round's correction identified.**

| Capability | Workspace owner | Active Workspace Admin | Staff | Direct Business owner/customer | Platform administrator |
|---|---|---|---|---|---|
| View balance/ledger for a Business in their Workspace | Yes | Yes, if `business_access_scope` covers that Business | Yes, if scope covers it | Yes, for their own Business only | Yes, any Business |
| Manage billing contact | Yes | Yes, if scope covers it | No | Yes, own Business | Yes |
| **Manage a Business-owned payment instrument** | No — Business-owned, not Workspace-owned | No | No | Yes, own Business | Yes |
| **Manage a Workspace-owned (shared) payment instrument** | Yes | No — `business_access_scope` does not extend to the Workspace's own shared instrument | No | No | Yes |
| **Set payer to `workspace`** | Yes | No | No | No | Yes, mandatory reason |
| **Set payer to `business`** | No | No | No | Yes, own Business | Yes, mandatory reason |
| Initiate top-up | Yes | Yes, if scope covers it | No | Yes, own Business | Yes |
| Configure Business spend cap / per-feature limits | Yes | Yes, if scope covers it, bounded by platform safety limit | No | Yes, own Business, bounded by platform safety limit | Yes, including the platform safety limit itself |
| Configure auto-recharge | Yes | Yes, if scope covers it | No | Yes, own Business | Yes |
| Issue manual/promotional credit | No | No | No | No | Yes only |
| Set/clear `billing_status = 'suspended'` | No | No | No | No | Yes only |
| View internal provider cost (`provider_cost_micro`) | No | No | No | No | Yes only |

Unrelated Workspace/Business resources fail closed with a 404-shaped response, never a 403 that would confirm existence (RFC-004's own `WorkspaceBusinessNotFoundException`/`BusinessWorkspaceMismatchException` precedent). No raw query against any new billing table is permitted outside its owning manager and repository, except an immutable migration/backfill script — enforced by a mechanical boundary test mirroring `NoRawEntitlementTableQueryTest.php` (§35).

**Permission category — unchanged:** `Business Usage Billing` (`view business usage billing`, `manage business usage billing`) — no collision with `Workspace`, `Workspace Plans`, `Subscriptions`, or `Payment Gateways`.

---

## 25. Schema

**Full table list, corrected and completed this round — 23 tables (up from 17: one renamed, one removed/folded in, nine added, net +6 from removal/folding one out).** All `restrictOnDelete()` on tenancy-scoping foreign keys, never `cascade`; no native `ENUM` anywhere; composite foreign keys `(wallet_id, business_id) → business_usage_wallets(id, business_id)` applied wherever both columns co-occur (§12).

| Table | New/changed this round | Backfilled? | Sole write authority |
|---|---|---|---|
| `business_usage_wallets` | columns extended (§12) | Yes — one row per existing Business | `UsageWalletManager` |
| `business_usage_ledger_entries` | delta columns replace `balance_effect` (§12) | No | `UsageWalletManager` |
| `business_usage_reservations` | composite-FK protected | No | `UsageWalletManager` |
| `business_usage_rates` | fully immutable now (§11) | No | `UsageWalletManager` |
| `business_usage_rate_activations` | **new** | No | `UsageWalletManager` |
| `platform_feature_usage_classifications` | `active_rate_id` replaces `default_rate_id` | Yes — one row per `PlatformFeature` case | `UsageWalletManager` |
| `business_feature_usage_limits` | composite-FK protected | No | `UsageWalletManager` |
| `platform_feature_usage_safety_limits` | unchanged | No | `UsageWalletManager` |
| `business_usage_limit_transitions` | **new** (was referenced, never listed) | No | `UsageWalletManager` |
| `business_usage_wallet_billing_status_transitions` | **new** | No | `UsageWalletManager` |
| `business_billing_contacts` | unchanged | No | `BillingProfileManager` |
| `business_payer_assignments` | unchanged | Yes — one row per existing Business, default by tier | `BillingProfileManager` |
| `business_payer_transitions` | instrument snapshot columns added | No | `BillingProfileManager` |
| `payment_provider_customers` | **new** | No | `PaymentInstrumentManager` |
| `business_payment_instruments` | redesigned to reference provider customer (§17.B) | No | `PaymentInstrumentManager` |
| `business_funding_attempts` | **new**, replaces `business_auto_recharge_attempts` | No | `UsageWalletManager` / `UsageBillingCheckoutManager` |
| `business_funding_attempt_transitions` | **new** | No | `UsageWalletManager` |
| `additional_business_slot_agreements` | renamed/rebuilt from `additional_business_slot_purchases` (§22) | No | `UsageBillingCheckoutManager` |
| `additional_business_slot_renewal_charges` | **new** | No | `UsageBillingCheckoutManager` |
| `business_usage_addon_catalog` | "seeded" wording corrected — zero rows at launch | No | `UsageBillingCheckoutManager` |
| `business_usage_addon_purchases` | `funding_attempt_id` replaces ad-hoc payment columns | No | `UsageBillingCheckoutManager` |
| `business_billing_receipts` | unchanged | No | `UsageWalletManager` |
| `payment_provider_events` | full schema given this round (§21) | No | `UsageBillingCheckoutManager` |

**Removed this round:** `business_auto_recharge_attempts` — fully superseded by `business_funding_attempts` (`purpose: auto_recharge`), avoiding a duplicate near-identical schema.

Exact columns/types/constraints for each table are given in the section that introduced it: §11 (rates, activations), §12 (wallets, ledger, billing-status transitions), §13 (reservations), §14.1 (classifications), §15 (limits, limit transitions), §16 (payer), §17 (contact, provider customers, instruments, funding attempts, funding-attempt transitions), §18 (add-ons), §21 (provider events), §22 (slot agreements, renewal charges), §23 (receipts). DDL and any data-only backfill operation remain separate migrations (RFC-003 §10.1/RFC-004 §25.1's shared convention).

`CHECK` constraints (e.g., "exactly one of `business_id`/`workspace_id` set" on `payment_provider_customers`; the composite tenancy-ID FKs, §12) are recommended where the target MySQL version is confirmed 8.0+; the M1 contract confirms this before relying on any `CHECK` constraint or composite FK, falling back to manager-level enforcement (already the primary enforcement mechanism throughout this design) if it cannot be confirmed.

---

## 26. PHP enums/value objects/models

**Enums, corrected this round — 16 (net +5 from the prior draft's 11: removed `UsageBalanceEffect` and `AutoRechargeAttemptStatus`, added seven):** `UsageLedgerEntryType` (now twelve values, §12), `UsageReservationStatus`, `WalletBillingStatus`, `UsageLimitType` (**new**), `PayerType`, `PaymentInstrumentStatus`, `ProviderCustomerStatus` (**new**), `FundingAttemptPurpose` (**new**), `FundingAttemptState` (**new**, replaces `AutoRechargeAttemptStatus`), `FundingAttemptTransitionSource` (**new**), `BillingStatusTransitionSource` (**new**), `AddonFulfillmentMode`, `AddonPurchaseStatus`, `SlotAgreementState` (**renamed** from `SlotPurchaseStatus`), `ProviderEventState` (**new**), `RoundingRule`.

**New readonly value objects:** `ReservationResult`, `CommitResult`, `EffectivePayer`, `CapEvaluation`, and — **new this round** — `UsageCapacityDecision` (internal-only, §14; never crosses the `UsageAuthorizationGateway` boundary).

**New Eloquent models — 23, one per table in §25**, each `casts` its enum columns, none exposes a `fillable` wider than its manager actually writes.

---

## 27. Repository contracts

One contract + one Eloquent implementation per table in §25 — **23 pairs, 46 files** (corrected from the prior draft's 17 pairs/34 files), bound in `AppServiceProvider` identically to RFC-004 M1's six-repository pattern. No repository method returns a raw query builder to a caller outside its owning manager.

---

## 28. Manager/domain authority

**Corrected this round to five authorities (was four; `PaymentInstrumentManager` split out of `BillingProfileManager`, §9):** `UsageWalletManager` (wallets, ledger, reservations, rates/activations, classifications, limits/limit-transitions, billing-status/transitions, receipts), `BillingProfileManager` (billing contact, payer assignment/transitions), `PaymentInstrumentManager` (provider customers, payment instruments), `UsageBillingCheckoutManager` (funding attempts' provider-facing leg, additional-slot agreements/renewals, add-on purchases, provider-event ingestion), `StripePaymentProviderGateway` (the only class referencing the `Stripe\*` namespace). No controller, job, or event listener ever writes to a table in §25 directly.

---

## 29. Jobs, events, notifications, and scheduling

**Jobs — corrected this round to nine (was six; three added):** `ExpireStaleUsageReservations`, `AdvanceUsagePeriodBoundaries` (**now explicitly proactive-maintenance-only, never required for correctness**, §15), `EvaluateBusinessAutoRecharge` (**new**, §19's centralized after-commit trigger), `ProcessPaymentProviderEvent` (**new**, the claim-and-process worker §21 describes), `ReconcileProviderPendingState` (extended scope, §20/§21), `ReconcileSlotAgreementAllocation` (**new**, sweeps stuck `allocation_pending`/`allocation_failed` agreements, §22), `SendLowBalanceNotification`, `SendAutoRechargeDisabledNotification`, `SendReceiptNotification`. All `App\Jobs\Usage\*`, extending `App\Jobs\Base`, `ShouldQueue` + `ShouldQueueAfterCommit` where dispatched from within a request-handling transaction.

**Events — corrected this round to fourteen (was ten):** `BusinessWalletCredited`, `BusinessWalletDebited`, `BusinessWalletDebtIncurred` (**new**), `BusinessWalletDebtCleared` (**new**), `BusinessUsageReserved`, `BusinessUsageCommitted`, `BusinessUsageReservationReleased`, `BusinessPayerChanged`, `BusinessBillingContactChanged`, `BusinessFundingAttemptSucceeded` (**renamed/generalized** from the auto-recharge-specific event, now covers top-up/auto-recharge/add-on), `BusinessFundingAttemptFailed` (**renamed/generalized**), `BusinessWalletBillingStatusChanged` (**new**), `AdditionalBusinessSlotAgreementCompleted` (**renamed**), `AdditionalBusinessSlotAllocationFailed` (**new**, the blocker scenario's own event). All `App\Events\Usage\*`, `implements ShouldDispatchAfterCommit`, carrying IDs/scalars only.

**Scheduling — unchanged in shape, corrected in emphasis per §15:** `AdvanceUsagePeriodBoundaries` is proactive maintenance only; `ExpireStaleUsageReservations`, `ProcessPaymentProviderEvent`, `ReconcileProviderPendingState`, and `ReconcileSlotAgreementAllocation` each run on a bounded interval (exact cadences are M1/M3 implementation details).

---

## 30. HTTP/admin/customer surfaces and permissions

**Customer surface — unchanged route shape**, nested under the existing `workspaces` prefix/name group: `workspaces/{workspaceUid}/businesses/{businessUid}/usage` (GET) plus `.../usage/{top-up,payer,billing-contact,instruments,limits,auto-recharge}` (POST/DELETE) and `.../usage/slots/{quote,checkout}`, `.../usage/addons/{addonKey}/purchase` (GET/POST) — every mutating route a `POST`, every route scoped by `{workspaceUid}`/`{businessUid}`.

**Webhook route — unchanged:** `POST /webhooks/stripe/usage-billing`, a new `VerifyCsrfToken` `$except` entry, never behind session auth.

**Admin surface — extended this round:** `Admin\UsageBillingController` (or similar), under the `Business Usage Billing` permission category: read balance/ledger/caps for any Business, issue manual/promotional credit, set/clear `billing_status`, set the platform safety limit, view (never edit) `provider_cost_micro` aggregates, **and — new this round, directly operationalizing the cross-RFC blocker's Option 1 — review `allocation_pending`/`allocation_failed` additional-slot agreements and perform the manual allocation via RFC-004's existing, already-authorized admin path to `setAdditionalBusinessSlots()`.**

**Observability — unchanged:** admin views never render a raw payment-instrument token or a full webhook payload; logs redact sensitive fields.

---

## 31. Concurrency, lock order, idempotency, and retry rules

**Canonical lock order, extended this round** to include the new tables: Workspace (only when a path already needs one, e.g. additional-slot allocation) → Business → wallet → reservation → funding attempt → additional-slot agreement. Ledger inserts remain append-only, taking no row lock of their own beyond the wallet row already held by their enclosing transaction. The additional-slot-agreement completion step continues to acquire the Workspace lock first (inside `setAdditionalBusinessSlots()`, unchanged) and only afterward, in a separate transaction, updates the agreement row — unchanged from the prior draft's deadlock-avoidance reasoning.

**Idempotency keys — corrected to reflect the new tables:** reservation (`idempotency_key`), ledger `correlation_key`, `business_funding_attempts.local_idempotency_key`, `additional_business_slot_agreements.local_idempotency_key`/`additional_business_slot_renewal_charges.local_idempotency_key`, add-on purchase (via its `funding_attempt_id`), and `payment_provider_events.provider_event_id` — each a real unique constraint, not merely an application-level check.

**"Effectively exactly-once local accounting effect under at-least-once delivery"** (§20's corrected wording) — guaranteed by (a) `payment_provider_events.provider_event_id`'s unique constraint rejecting a true replay at insert, and (b) every downstream `record*()` method being independently idempotent on its own key, so a double-delivery that somehow reaches processing twice is still absorbed.

**Forced-race test scenarios (§35 names the concrete tests), extended this round:** two reservations racing the last remaining spend-cap headroom; two `EvaluateBusinessAutoRecharge` dispatches racing the "one open funding attempt" rule; a reservation racing a manual admin credit; two concurrent additional-slot checkouts for one Workspace; an unrelated-Business reservation proceeding unaffected during another Business's race — all via the real cross-process pattern already established by `EntitlementManagerConcurrencyTest.php`.

---

## 32. Backfill, rollout, compatibility, and rollback safety

**Backfill — unchanged in intent, extended to the new tables:** one `business_usage_wallets` row and one `business_payer_assignments` row per existing Business, zero balance/debt, null caps, payer defaulted by Workspace tier (§16 — **with no instrument auto-attached**, per this round's consent correction). One `platform_feature_usage_classifications` row per existing `PlatformFeature` case, `is_metered = false`, `active_rate_id = null` for every one. **Rollout introduces zero newly-gated features and zero newly-required payment setup** — every feature remains exactly as accessible after this RFC ships as before.

**DDL/data separation — unchanged:** every table's `CREATE TABLE` migration is separate from its backfill migration.

**Rollback safety — unchanged, and now applying to a larger table set:** a `migrate:rollback` proceeding in exact FK-safe reverse order will mechanically drop every RFC-005 table (now 23, not 17) and silently destroy any live wallet/ledger/funding-attempt/agreement data accumulated since deploy — `restrictOnDelete()`/composite FKs protect row-level deletes, never a `DROP TABLE`. The eventual deployment guide (final milestone, §36) states this explicitly and offers the same three recovery paths RFC-004's guide established.

---

## 33. Security and PCI posture

**Corrected this round: webhook payloads are no longer persisted in full plaintext indefinitely merely because the table is admin-only.** `payment_provider_events.payload_encrypted` is encrypted at rest (application-level encryption using Laravel's own encrypted-cast facility, or equivalent); `payload_hash` (SHA-256 of the raw verified payload) supports integrity/dedup checks without decrypting the payload for routine operations. A retention/deletion period for encrypted payloads is an M3 implementation/operational detail (recommended: retained only as long as needed for dispute-window reconciliation, then purged — the exact duration is not fixed here). Access to decrypt/view a payload remains platform-administrator-only (§24); ordinary logs never include a raw webhook body, only the event's `provider_event_id`/`event_type`/`state`.

**Payment-method display — corrected to reflect §17.B's redesign:** stored instrument metadata is limited to brand/last-four/expiry — never a raw token — and is never rendered to any surface, customer or admin, as anything other than that safe display metadata. No raw card data is ever received, stored, or logged by this platform; every instrument is a Stripe-issued token attached server-side only by reference.

Secrets (`STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`) remain environment-only, per the already-established `config/services.php` pattern.

---

## 34. Observability and internal unit-economics controls

Unchanged from the prior draft: `provider_cost_micro` is aggregated (never per-transaction, never customer-facing) into an admin-only dashboard implementing Human product requirement 7's internal cost-control target — never an automatic suspension/throttle trigger, never substituted for the customer-facing spend-cap/limit controls (which are always evaluated against `retail_rate_micro`), feeding a scheduled alert rather than an enforcement action.

---

## 35. Exact test strategy

Every test class below is a **future milestone's** responsibility to write. **List substantially expanded this round** to cover every correction:

- **Money/precision** — `UsageMoneyPrecisionTest`: boundary-value assertions on the `BCMath`-based `quantity × rate` arithmetic (§10), including the sanity-ceiling rejection and the Stripe-cent rounding/zero-cent-rejection behavior.
- **Rate snapshot immutability** — `UsageRateSnapshotTest`: a rate row is never updated after insert; a new activation never changes an already-posted entry's snapshot.
- **Concurrent initial rate activation — new this round** — `UsageRateActivationConcurrencyTest`: two concurrent first-time `setActiveRate()` calls for one feature (no prior rate) never produce two simultaneously-active rates; the classification-row lock serializes them correctly (§11).
- **Reservation/commit/release lifecycle** — `UsageReservationLifecycleTest`: reserve→commit (exact/over/under), reserve→release, reserve→expire, double-commit/double-release no-ops.
- **Reservation bucket delta/reconciliation — new this round** — `UsageLedgerReconciliationTest`: after a mixed sequence of top-ups, reservations, commits, overages, refunds, and debt-clearing credits, each of the three wallet buckets independently reconstructs correctly from the ledger's three delta columns (§10).
- **Overage debt — new this round** — `UsageOverageDebtTest`: an actual cost exceeding a reservation, itself exceeding remaining available balance, correctly produces a positive `debt_balance_micro`, never a negative `available_balance_micro`.
- **Refund/chargeback exceeding available balance — new this round** — `UsageRefundExceedsAvailableTest`: a refund/chargeback larger than current available balance correctly produces debt for the shortfall.
- **Top-up clears debt first — new this round** — `UsageTopUpClearsDebtTest`: a credit smaller than existing debt reduces debt only, zero available-balance effect; a credit larger than debt clears debt and credits the remainder.
- **Outstanding debt denies reservations — new this round** — asserts `outstanding_debt` is evaluated before cap checks and correctly denies.
- **Exact RFC-004 nine-key set unchanged — new this round** — a regression test asserting `EntitlementManager::decide()`'s denial-key set is still exactly the original nine plus no others, after `RealUsageAuthorizationGateway` is bound, across every RFC-005 internal denial reason (§14).
- **Missing-wallet/currency coarse gateway behavior — new this round** — asserts `evaluateCoarseCapacity()` correctly returns `wallet_unavailable`/`unsupported_currency` and that these never leak past the gateway boundary as anything but `usage_unauthorized`.
- **Cap enforcement** — `UsageBusinessSpendCapTest`, `UsageFeatureLimitTest`, `UsagePlatformSafetyLimitTest`: independent denial per control; a customer limit above the safety limit is rejected at write time.
- **Concurrency** — `UsageWalletConcurrencyTest`: real cross-process forced races (§31), reusing `EntitlementManagerConcurrencyTest.php`'s exact pattern.
- **Payer consent in both directions — new this round** — `BusinessPayerConsentTest`: a direct Business owner cannot set payer to `workspace`; a Workspace owner cannot set payer to `business`; neither can select/charge an instrument owned by the other side.
- **Workspace instrument isolation — new this round** — `WorkspaceSharedInstrumentIsolationTest`: `business_access_scope` covering a Business does not grant authority over the Workspace's own shared instrument.
- **Billing-contact isolation** — unchanged.
- **Historical billing-contact snapshot immutability — new this round** — `BusinessFundingAttemptSnapshotTest`: a later billing-contact/instrument change never alters an already-created funding attempt's snapshot fields.
- **Credit-type distinction** — unchanged, now covering all twelve entry types.
- **Add-on idempotency** — unchanged.
- **Webhook crash after durable receipt, before processing — new this round** — `PaymentProviderEventReplayTest`: an event inserted but not yet processed (simulated crash) is correctly resumed by the claim-and-process worker, never treated as already-done.
- **Failed-event replay/resume — new this round** — a `failed` event with attempts remaining is correctly re-claimed and retried.
- **Out-of-order/conflicting events — new this round** — an event implying an invalid state transition triggers reconciliation rather than corrupting local state.
- **Provider/local amount/currency/customer mismatch — new this round** — a webhook event whose verified object disagrees with the local funding attempt's expected values is rejected and flagged, never blindly applied.
- **`requires_action` auto-recharge behavior — new this round** — correctly treated as a non-success for the consecutive-failure counter, with its own distinct notification.
- **Period rollover without a scheduler — new this round** — `UsagePeriodLazyRolloverTest`: a dormant wallet's first reservation attempt after its period has technically expired correctly rolls the period over inline, with no scheduled job having run.
- **Auto-recharge after every balance-decreasing mutation — new this round** — asserts `EvaluateBusinessAutoRecharge` is dispatched after `commit()`, admin debits, refunds, and chargebacks alike, not only `commit()`.
- **Slot payment/allocation saga — new this round** — `AdditionalBusinessSlotAgreementSagaTest`: every state transition in §22's saga, including `payment_succeeded` without `completed` when allocation is blocked.
- **Slot authority blocker — new this round** — a direct assertion that a customer-actor webhook call cannot itself invoke `setAdditionalBusinessSlots()` successfully, documenting the exact failure this RFC's blocker describes, so a future fix's regression coverage has a concrete starting assertion to replace.
- **Cross-table Business/wallet mismatch rejection — new this round** — asserts the composite FK (or manager-level equivalent) rejects a ledger/reservation row whose `business_id`/`wallet_id` disagree.
- **Sensitive payload retention/redaction — new this round** — asserts `payment_provider_events.payload_encrypted` is never queryable/renderable in plaintext outside the platform-administrator path, and that ordinary logs never include a raw webhook body.
- **Provider-cost non-disclosure** — unchanged.
- **Invoice/receipt boundary** — unchanged.
- **Mechanical source-boundary test** — unchanged, extends/supersedes `WorkspaceM1BBoundaryTest::test_no_rfc005_concepts_exist_yet`.
- **Webhook/provider fakes** — unchanged: every test fakes `PaymentProviderGateway`; no automated test contacts Stripe.
- **Database** — `ultimatesms_testing` only.
- **Gate shape — corrected this round.** The prior draft called six regression commands "an exact five-gate shape," an internal miscount. The actual gate sequence is: (1) focused Usage tests, (2) Entitlement, (3) Workspace, (4) Business, (5) Opportunity, (6) full suite — **six commands**, run in that order, mirroring RFC-004 §22's own layered-gate discipline without misnaming its count.

---

## 36. Milestone decomposition

Each milestone below requires its own separately drafted, human-reviewed, merged implementation contract before work begins — none may start automatically. **Content updated this round for the corrected table/state model; milestone count unchanged at six.**

1. **M1 — Wallet & Ledger Foundation.** Schema: `business_usage_wallets`, `business_usage_ledger_entries` (delta model), `business_usage_reservations`, `business_usage_rates`, `business_usage_rate_activations`, `platform_feature_usage_classifications`. `UsageWalletManager` (reserve/commit/release/expire, rate activation with the corrected classification-lock algorithm, coarse-capacity evaluation). Real `RealUsageAuthorizationGateway` bound, but every feature classified non-metered at launch. Backfill. No HTTP surface, no Stripe. Focused + concurrency tests, including the corrected nine-denial-key regression test and the concurrent-rate-activation test.
2. **M2 — Budgets, Limits, Payer, and Billing Contact.** Schema: `business_feature_usage_limits`, `platform_feature_usage_safety_limits`, `business_usage_limit_transitions`, `business_usage_wallet_billing_status_transitions`, `business_billing_contacts`, `business_payer_assignments`, `business_payer_transitions`. `BillingProfileManager`. Payer-consent authorization (§16/§24). New permission category. Backfill of payer defaults.
3. **M3 — Provider Customers, Instruments, and Stripe Integration.** Schema: `payment_provider_customers`, `business_payment_instruments`, `business_funding_attempts`, `business_funding_attempt_transitions`, `payment_provider_events`. `PaymentInstrumentManager`. `PaymentProviderGateway`/`StripePaymentProviderGateway`, pinning an explicit Stripe API version. SetupIntent/instrument attachment. Manual top-up. Webhook endpoint with the corrected claim-and-process/reconciliation algorithm (§21). Auto-recharge as an after-commit job (§19), including `requires_action` handling. Tax/VAT posture resolved or explicitly deferred with the corrected, non-safe-by-default framing (§23).
4. **M4 — Additional-Slot Agreement and Add-ons.** Schema: `additional_business_slot_agreements`, `additional_business_slot_renewal_charges`, `business_usage_addon_catalog`, `business_usage_addon_purchases`. Requires, as a precondition, both (a) a human-authorized resolution to the cross-RFC allocation blocker (or scoping this milestone to stop at `allocation_pending` with manual admin completion, Option 1) and (b) an authorized RFC-004 catalog-pricing operator surface. Customer/admin credit-adjustment surfaces.
5. **M5 — Metered Feature Classification.** The first real feature(s) classified `is_metered = true` (candidate not named by this RFC — §39 item 11), wired to real reserve/commit calls. Full `decide()`-integrated gate live for that feature, still surfacing only `usage_unauthorized` externally.
6. **M6 — Conformance, Deployment, and Tag.** Full conformance matrix, deployment guide (including rollback-danger disclosure across all 23 tables), full six-gate regression, post-merge exact-tag-candidate gate, and — only after separate explicit human authorization — the proposed annotated tag `rfc-005-business-usage-billing-and-wallets`.

---

## 37. Acceptance criteria

**Corrected this round: the prior draft's blanket "no existing feature's accessibility may change in any milestone" directly contradicted M5's own stated purpose.** Corrected:

- M1–M4 introduce no feature-accessibility change of any kind — every feature remains exactly as accessible as before RFC-005 ships.
- **M5, and only M5,** changes accessibility for the explicitly selected, human-approved metered feature(s) named in that milestone's own contract — never silently, never for any feature not named there.
- Every feature not explicitly named by M5's contract remains non-metered indefinitely.
- Activating a feature as metered always requires: its own milestone/correction contract, an active rate (§11), configured limits (§15), a rollout plan, and passing tests (§35) — never a bare classification-row flip without all of the above.

At the RFC level, RFC-005 as a whole is acceptance-complete only when: every table in §25 exists and is backfilled where required; `NullUsageAuthorizationGateway` has been replaced by a real, tested implementation; the cross-RFC blocker (above) has been resolved by explicit human decision before M4 allocates any slot; at least one milestone's conformance document shows every §35 test class passing; and the M6 conformance matrix shows every item in §40 resolved.

---

## 38. Release/tag gate

Unchanged discipline: no tag before M6; M6's own post-merge exact-tag-candidate gate must pass before a human separately, explicitly authorizes the annotated tag `rfc-005-business-usage-billing-and-wallets` on that exact SHA. This document proposes that tag name; it does not create it.

---

## 39. Open human decisions

Items 1–10 are carried forward from the prior draft (renumbering avoided so every existing cross-reference in this document remains valid); items 11–14 are added this round.

1. **Exact initial retail usage rates** per eventually-metered feature. *Recommendation:* pass-through-plus-margin off `provider_cost_micro`, set per feature at M5. **NON-IMPLEMENTATION-READY** for any specific feature until resolved.
2. **Exact default Business monthly spend cap.** *Recommendation:* no cap by default in v1; revisit at M5.
3. **Exact default per-feature limits.** Deferred to M5 alongside item 1.
4. **Exact auto-recharge default threshold** within RFC-003 §26.2's locked "below $10." *Recommendation:* $5.00.
5. **Owner/operator complimentary Agency Workspace's metered-usage subsidy.** *Recommendation:* a capped, auditable monthly `PromotionalCredit` — exact cap amount is part of this same decision.
6. **Invoice/tax/VAT operational provider and legal sufficiency** — **corrected framing this round: this is now a production-launch legal/compliance gate, not an ordinary feature-scope preference** (§23). *Recommendation:* obtain the operator's own legal/accounting determination before any production payment collection; Stripe Tax may be adopted operationally once that determination is made, but this RFC asserts no legal sufficiency for any posture. **NON-IMPLEMENTATION-READY for production launch** regardless of which option is chosen, until that determination exists.
7. **Timing of Agency client rebilling.** *Recommendation:* not before a dedicated future RFC/milestone; the `agency_rebill` payer-type value exists now purely as a schema seam.
8. **Exact v1 add-on roster and pricing** beyond the one worked example. *Recommendation:* ship with zero seeded rows at M4 launch.
9. **Exact initial per-feature platform safety-limit ceilings.** Deferred to M5 alongside items 1 and 3.
10. **v1 settlement currency and multi-currency scope.** *Recommendation:* USD only, `decimal_places = 2`; a Business whose `currency_code` isn't USD has usage billing gated off until multi-currency is separately designed.
11. **The first actual metered feature(s) — added this round, per §37's corrected acceptance criteria.** No candidate is named by this RFC; M5's own contract must name it explicitly and separately, with its own human authorization.
12. **Exact default monthly auto-recharge cap — added this round.** RFC-003 §26.2 locks the per-attempt threshold/amount defaults but never named a period cap default; no value is invented here.
13. **Additional-slot agreement `payment_lapsed` grandfathered-capacity revocation policy — added this round (§22).** Whether, and after how long, a lapsed agreement's already-allocated (grandfathered) slots may ever be revoked is not decided here; no silent auto-revocation is implemented absent an explicit future decision.
14. **The cross-RFC additional-slot allocation authority blocker itself — added this round, restated from its own prominent section above.** A human must choose among the blocker's three options (or propose another) before M4's allocation step may be contracted; this RFC does not choose on its own.

---

## 40. Contract coverage matrix

Maps every mandatory area from the merged design contract's §5 (A–L) and every human product requirement to the exact section(s) of this RFC that resolve it. **Updated this round for every renamed/added section.**

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
| §4 items 1–16 (locked decisions) | §7 (per-item table) |
| Design-contract §6 gap rule (four-category classification) | Applied throughout; open items collected in §39 |
| **Cross-RFC allocation authority blocker (added this round)** | Cross-RFC implementation blocker section; §22; §39 item 14 |
| **Corrected accounting/debt model (added this round)** | §10, §12 |
| **Corrected denial-key discipline (added this round)** | §3, §14, §35 |

No area in the merged contract's §5 A–L, and no human product requirement, is unaddressed by this table.

---

*End of RFC-005 design document. Every milestone named in §36 requires its own separate, human-reviewed, merged implementation contract before any code, migration, test, route, view, or Stripe/provider change may be written.*
