# RFC-005 Design Contract

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE**

This document authorizes governance for **designing** RFC-005 — Business Usage Billing and Wallets. It does **not** authorize the RFC-005 design document itself, any implementation, any migration, any test, any Stripe/provider change, or any billing behavior. Human merge of this contract authorizes exactly one thing: that a human may separately instruct that manual drafting of the single RFC-005 design document begin, on the branch and at the path locked in §2 below. Merging this contract does not start that drafting automatically.

---

## Purpose

RFC-004 (Plans and Business Feature Entitlements) is complete — annotated tag `rfc-004-plans-and-business-feature-entitlements`, dereferencing to `221e18f06441b1399cb2b4ee47ffbb8dbb644b80`, verified pushed to `origin`. RFC-004 §19 names its own successor precisely: *"RFC-005 will own Business usage wallets, balances, payer selection, the usage ledger, reservation/release for uncertain-cost operations, auto-recharge, monthly usage caps, low-balance notifications, zero-balance usage suspension, usage webhooks/idempotency, Agency client rebilling, and any Stripe usage-billing change, including the actual payment collection for a Core/Growth additional Business slot."* This contract exists to govern how that successor RFC gets **designed** — following the identical discipline RFC-003 used before RFC-004 existed, and RFC-004 used before its own first milestone: **RFC design PR → bounded implementation milestones, each its own contract → PR → human merge, no automatic next-milestone start → final conformance/deployment/tag milestone** (RFC-004 §29's own governing sentence, inherited here for RFC-005).

---

## Repository state verified before drafting (base `221e18f06441b1399cb2b4ee47ffbb8dbb644b80`, `main`)

### RFC-004's own usage/billing boundary (already governing, not proposed)

- RFC-004 §19, "RFC-005 usage-authorization boundary," read directly: the seam is `UsageAuthorizationGateway::check(Business $business, PlatformFeature $feature): UsageAuthorizationResult` (`app/Library/Entitlement/Contracts/UsageAuthorizationGateway.php`), called as the **final** step of `EntitlementManager::decide()`'s eight-step algorithm (RFC-004 §14 step 7) — confirmed directly in `app/Library/Entitlement/EntitlementManager.php::decide()`, where the call happens only after every structural gate (feature known, available, Workspace assigned, Workspace-entitled, not Business-toggled, plan not suspended/inactive) has already passed.
- The **only** bound implementation today is `App\Library\Entitlement\NullUsageAuthorizationGateway`, confirmed by direct reading of the class itself and its `AppServiceProvider::register()` binding (`UsageAuthorizationGateway::class => NullUsageAuthorizationGateway::class`): it unconditionally returns `new UsageAuthorizationResult(authorized: true)` for every call, with no Business/feature inspection of any kind.
- `UsageAuthorizationResult` (`app/Library/Entitlement/UsageAuthorizationResult.php`) is a two-field readonly value object: `bool $authorized`, `?string $reason`.
- The reserved, never-yet-returned denial key is `usage_unauthorized` (RFC-004 §14) — the ninth of RFC-004's nine stable `EntitlementDecision` reasons, already reserved in `EntitlementManager::decide()`'s own code (`$usageResult->reason ?? 'usage_unauthorized'`), confirmed directly.
- RFC-004 §17 additionally confirms the additional-Business-slot **allocation quantity** mutation (`EntitlementManager::setAdditionalBusinessSlots()`, and `changePlan()`'s Agency→Core/Growth allocation path) is already the sole authoritative mutation point RFC-004 ships; RFC-004 explicitly states its own future checkout flow "is expected to call this exact method once payment succeeds, so slot enforcement is never redesigned when RFC-005 ships."
- RFC-004 §13/§18 lock the complimentary-Workspace semantics RFC-005 must not silently reinterpret: a complimentary assignment waives the recurring plan charge **and** any charge corresponding to already-allocated additional Business slots, never the allocation limits themselves, never an explicit `inactive`/`suspended` status, and — stated explicitly in RFC-004 §13 — *"RFC-005 must never later interpret those grandfathered complimentary slot allocations as unpaid recurring-slot debt."*
- RFC-004 §31 ("Explicit RFC-005 deferrals") and RFC-003 §26.2 (read directly, reproduced below) are the two authoritative deferral lists this contract's §5 (below) is built from — cross-checked against each other and found consistent, with no contradiction between the two RFCs' deferral language.
- RFC-003 §26.2, read directly in full:

  > Deferred to RFC-005 — Business Usage Billing and Wallets: Business payment methods. Workspace payment-method fallback. Selected Business payer (Business/client pays, Workspace pays, or — later, Agency-only — Workspace pays and rebills client). Default payer by tier: Core/Growth defaults to Workspace pays; Agency defaults to Business/client pays. Usage accounts and balances. Append-only usage ledger. Manual top-ups. Auto-recharge, with a default threshold below $10 and a default recharge amount of $10, both editable, with the ability to disable auto-recharge entirely. Monthly automatic recharge limits. Low-balance notifications. Zero-balance usage suspension. Payment webhooks and idempotency. Reservation/release for uncertain-cost operations. Agency client rebilling. Stripe integration changes.
  >
  > The payer-change invariant that RFC-005 must implement, documented here only so RFC-003's schema does not foreclose it: changing a Business's payer must never delete credits, move credits to another Business, reset the wallet, or rewrite historical ledger entries — a payer change affects future charges and recharges only.

- `docs/automation/RFC-004-M4-CONFORMANCE.md` (the completed, verified conformance record) and `docs/rfcs/RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS-DEPLOYMENT.md` both confirmed present on `main` at the base commit, both cross-checked for any RFC-005-relevant operational note — the deployment guide's §19 explicitly restates the same deferral boundary and confirms `NullUsageAuthorizationGateway` is the only bound implementation as of RFC-004's own release.

### Existing legacy payment/billing architecture — classified, not assumed reusable

Direct inspection, this pass, at the base commit:

- **`composer.json`** requires four separate payment-gateway SDKs: `stripe/stripe-php` (`^7.76`), `braintree/braintree_php` (`^5.5`), `paypal/paypal-server-sdk` (`0.6.1`), `razorpay/razorpay` (`^2.8.3`) — confirming a genuinely multi-gateway legacy stack, not a Stripe-only one.
- **`app/Models/PaymentMethods.php`**: `$fillable = ['name', 'options', 'status', 'type', 'is_default']`, with 30+ `TYPE_*` constants naming specific gateways (`TYPE_STRIPE`, `TYPE_PAYPAL`, `TYPE_BRAINTREE`, `TYPE_RAZORPAY`, `TYPE_COINPAYMENTS`, etc.) and an `options` JSON blob accessed via `getOption($name)`. **This is a platform-level gateway-configuration row (API keys/credentials per enabled gateway), not a stored customer/Business payment instrument** — it carries no `user_id`, `customer_id`, `business_id`, card/token reference, or any per-tenant scoping column of any kind, confirmed by direct reading of the full model and its migration (`database/migrations/2018_07_27_112022_create_payment_methods_table.php`, plus `2025_08_25_160521_add_is_default_to_payment_methods_table.php`). RFC-004 §5 finding 6 already recorded the same conclusion for the same table; this pass re-confirms it directly rather than trusting that prior finding at face value.
- **`app/Models/Invoices.php`** and its migration (`database/migrations/2021_03_25_135511_create_invoices_table.php`): `amount` is a plain `string` column (not `decimal`, not an integer minor-unit column); `user_id`/`currency_id`/`payment_method` foreign keys all use `onDelete('cascade')`.
- **`app/Models/SubscriptionTransaction.php`** and its migration (`database/migrations/2020_05_31_175740_create_subscription_transactions_table.php`): `amount` is likewise a plain nullable `string` column; `subscription_id`'s foreign key is `onDelete('cascade')`; `status`/`type` are freeform `string`/`string(100)` columns with class-constant conventions (`STATUS_SUCCESS`/`STATUS_FAILED`/`STATUS_PENDING`; `TYPE_SUBSCRIBE`/`TYPE_RENEW`/`TYPE_PLAN_CHANGE`/`TYPE_AUTO_CHARGE`) rather than a cast PHP enum.
- **Conclusion, stated explicitly rather than assumed:** neither `invoices.amount` nor `subscription_transactions.amount` is a safe existing money representation for a new authoritative wallet/ledger — a raw `string` column guarantees neither exact-decimal precision nor a defined minor-unit convention, and both tables' `cascade` foreign-key policy is the opposite of RFC-003/RFC-004's `restrictOnDelete()` posture this repository has consistently used for every tenancy-adjacent table since RFC-003. **A future RFC-005 design must not reuse either column's shape by inference from its name alone.**
- **`app/Http/Controllers/Customer/PaymentController.php` is 12,887 lines** (confirmed by direct line count) — a single controller handling top-up and subscription-purchase flows across every one of the legacy gateway integrations. `routes/customer.php` registers dozens of gateway-specific routes through it: `payment/top-up/*` (success/cancel/braintree/authorize-net/vodacommpesa), `callback/{paystack,paynow,razorpay,sslcommerz,aamarpay,flutterwave,coinpayments,nowpayments,easypay,fedapay}/*` (per-gateway webhook/IPN endpoints, several further split by `senderid`/`numbers`/`keywords`/`subscriptions`/`top_up` sub-purpose), and `subscriptions/*` (renew/cancel/logs/change-plan/purchase/preferences, plus `subscriptions/{plan}/{success,cancel,braintree,authorize-net,vodacommpesa}` for the payment leg). **All of this is legacy SMS-plan/subscription billing, entirely `user_id`-scoped, with zero `workspace_id`/`business_id` awareness anywhere in this controller or its routes** — confirmed by the same route-scan this session already used to establish the RFC-004 legacy-boundary finding.
- **`app/Http/Controllers/Admin/PaymentMethodController.php`** is the admin-side gateway-configuration surface (`view payment_gateways`/`update payment_gateways` permissions, `config/permissions.php`, category `Payment Gateways`) — confirmed to be the counterpart write surface for the `PaymentMethods` rows described above.
- **`config/permissions.php`** already carries legacy `Subscriptions` (`view subscription`/`new subscription`/`manage subscription`/`delete subscription`), `Payment Gateways` (`view payment_gateways`/`update payment_gateways`), and a standalone `view invoices` key — all under categories with no Workspace/Business scoping concept. A commented-out `manage gateway_wise_billing` key exists (dead, unused). **None of these collide by name with any RFC-004 key, but RFC-005 must choose its own distinct category exactly as RFC-004 did (§5 finding 5, §22) — reusing `Subscriptions`/`Payment Gateways` would conflate the legacy SMS-billing surface with the new Workspace/Business one.**
- **Queue/job precedent**: 15 existing files implement `ShouldQueue` (`app/Jobs/Base.php` as a shared base class, `app/Jobs/{Domain}/*` naming, `App\Library\Traits\TrackJobs`) — an established convention RFC-005 may reuse for reconciliation/notification work, in clear contrast to RFC-004, which shipped zero queued jobs (every RFC-004 mutation is synchronous inside its own transaction).
- **No existing "reservation," "hold," or "authorization-then-capture" money concept was found anywhere in this repository** — confirmed by a targeted search; RFC-005's reservation/release requirement (RFC-003 §26.2, RFC-004 §19) has no existing primitive to reuse and must be designed from scratch.

### Existing RFC-005-premature-concept boundary test

`tests/Feature/Workspace/WorkspaceM1BBoundaryTest.php::test_no_rfc005_concepts_exist_yet` (read directly, in full): scans every `app_path()` PHP file whose **basename contains `"Workspace"`** for the terms `Wallet`, `Ledger`, or `Stripe`, and asserts zero matches. Its own docblock states the rationale precisely: scoped to `Workspace`-named files specifically, "rather than a blanket scan of `app/` for those terms alone," because the legacy `App\Models\Plan`/`Admin\PlanController` SMS-plan stack already legitimately uses "Plan"-shaped vocabulary unrelated to RFC-004/RFC-005. **This test's scope is narrow, not a blanket repository-wide ban**: it currently only forbids a Workspace-prefixed class named with a wallet/ledger/Stripe term (e.g. a hypothetical `WorkspaceWalletManager`) — it would not, as currently written, flag a Business-scoped or top-level class such as `BusinessUsageWallet` or `App\Library\Billing\UsageLedger`. The eventual RFC-005 implementation milestones remain responsible for updating this test's own scope (or superseding it with a purpose-built RFC-005 boundary test, matching RFC-004 M1's own precedent of introducing `NoRawEntitlementTableQueryTest.php`) once RFC-005 concepts are actually authorized to exist.

### Governance/automation-state finding

`docs/automation/AI-AUTONOMY-STATE.json`, read directly in full: `active_rfc: "RFC-003"`, `active_milestone: "Milestone 4"`, `status: "automation_ready_pending_next_locked_contract"`, `expected_head_sha: "db7829d2c33ff3eb77a5bd42820eb39e349f6d94"` — a commit far behind the current `main` (`221e18f0...`). **This file is confirmed stale/historical automation state from before RFC-003 Milestone 5 was even contracted; it does not reflect RFC-004's completion and carries no authorization weight for this task.** It is read and recorded here exactly as instructed, and is left completely untouched by this contract.

If a future step of this work discovers repository reality conflicting with any claim above, that is a STOP-and-report condition, not something to silently reconcile.

---

## 1. Contract status / authorization model

Before human merge: **PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

After this exact one-file contract PR is human-reviewed and merged: a human may separately, explicitly instruct that manual drafting of the RFC-005 design document begin. **Merging this contract does not itself start that drafting.** The full intended sequence, mirroring RFC-003→RFC-004's own precedent exactly:

```
this design-contract PR → human merge → separate explicit human instruction to draft →
one RFC-005 design-document PR → human merge →
RFC-005 Milestone 1's own separately drafted, human-reviewed, merged implementation contract → ...
```

Locked:

- `human_only_merge: true`
- `advance_automatically: false`
- `start_automatically_after_contract_merge: false`
- `maximum_correction_rounds: 2`
- `codex_review_required_for_completion: false`
- `automatic_model_handoff_required: false`

Also locked:

- No target-marker PR, no inert implementation PR, no automation-state authorization PR at any point in this sequence.
- No paid model API or usage-credit requirement is authorized at any step.
- No force push, and no direct push to `main`, at any step.
- No tag is created during this contract's drafting or during the eventual RFC-005 design document's drafting — RFC-005 tagging (if any) is exclusively a matter for RFC-005's own eventual final milestone, exactly as RFC-004 §30 reserved tagging exclusively for RFC-004's own M4.
- **This contract authorizes design documentation only.** Neither this contract's merge nor the eventual RFC-005 design document's merge authorizes any implementation, migration, test, route, view, Stripe/provider call, or billing behavior of any kind. RFC-005 Milestone 1 requires its own separately drafted, human-reviewed, merged implementation contract before any such work may begin — identical to the discipline RFC-004 itself followed before its own Milestone 1.
- No automatic progression into RFC-005 Milestone 1 or any later milestone from either this contract or the eventual design document.

---

## 2. Future design-document identity (locked now, drafted later)

- Future branch: `chore/rfc-005-design`, created only after a human separately instructs that drafting begin, from the then-current `main`.
- Future document: `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md` — exactly one new file.
- The future design PR may create that one RFC file only, unless a human-reviewed amendment to **this** contract explicitly authorizes an additional path first. No migration, model, controller, route, view, test, or configuration file is authorized by the design PR under any circumstance — a design document is documentation, not implementation, exactly as RFC-003 and RFC-004's own design-stage PRs were.

---

## 3. This contract's own exact file scope

This contract-creation branch (`chore/rfc-005-design-contract`) may change **exactly one file**: `docs/automation/RFC-005-DESIGN-CONTRACT.md`. Nothing else.

Explicitly forbidden by this contract, now and by the eventual design-document PR alike (unless a human-reviewed amendment first authorizes otherwise):

- `docs/automation/AI-AUTONOMY-STATE.json`
- any existing RFC or milestone/closure/conformance contract under `docs/rfcs/` or `docs/automation/`
- `app/**`, `config/**`, `database/**`, `resources/**`, `routes/**`, `tests/**`
- `.github/**`
- any dependency or environment file (`composer.json`, `composer.lock`, `.env*`, etc.)

---

## 4. Inherited product decisions the future design may not reopen

The eventual RFC-005 design document must preserve every decision below exactly, refining implementation detail only — never silently reversing, narrowing, or omitting one. Each is traced to its governing source:

1. **Workspace remains the universal tenant/account container; Agency is a plan tier, not a separate tenant model** (RFC-003 §4/§27; RFC-004 §3, "no `Agency` model or `businesses.agency_id` column," re-verified absent as recently as RFC-004 M4's own conformance pass).
2. **Usage wallets and historical usage accounting are Business-scoped**, not Workspace-scoped and not user-scoped (RFC-003 §26.2's "Business payment methods... Business's payer" framing, consistent with RFC-004's own Business-level `business_feature_toggles`/usage-authorization-per-Business shape at RFC-004 §19's `check(Business $business, ...)` signature).
3. **Default payer policy:** Core/Growth → Workspace pays; Agency → Business/client pays (RFC-003 §26.2, quoted in full above).
4. **Supported payer concepts must cover:** Business/client pays; Workspace pays; and, later, Agency-only Workspace-pays-and-rebills-client (RFC-003 §26.2).
5. **Changing payer affects future charges/recharges only.** It must never delete credits, move credits between Businesses, reset a wallet, or rewrite/reattribute historical ledger entries (RFC-003 §26.2's exact payer-change invariant, quoted in full above).
6. **The usage ledger is append-only** (RFC-003 §26.2).
7. **Auto-recharge defaults, inherited exactly from RFC-003 §26.2:** threshold below $10; default recharge amount $10; both editable; auto-recharge can be disabled entirely.
8. **The design must address:** monthly automatic-recharge caps, low-balance notifications, zero-balance usage suspension, manual top-ups, payment webhooks/idempotency, and reservation/release for uncertain-cost work (RFC-003 §26.2, RFC-004 §19).
9. **RFC-005 owns actual payment collection for paid Core/Growth additional Business-slot allocation. RFC-004 remains authoritative for the allocation mutation itself** (`EntitlementManager::setAdditionalBusinessSlots()`/`changePlan()`, RFC-004 §13/§17/§19) — RFC-005's future checkout flow calls that existing method after payment succeeds; RFC-005 does not reimplement or bypass it.
10. **Complimentary Workspace status waives recurring plan charges and charges corresponding to already-allocated additional slots. It does not waive metered usage** unless RFC-005 explicitly makes and justifies a new, separate product decision to extend that waiver (RFC-004 §13/§18).
11. **Grandfathered complimentary additional slots must never be converted into unpaid debt** (RFC-004 §13's explicit instruction to RFC-005, quoted above).
12. **Billing state must never deactivate a Workspace or silently change tenancy visibility.** It may block an explicitly paid execution while ordinary login/view access remains — the identical posture RFC-004 §18 already locked for its own `status`/entitlement gate, inherited here unchanged for RFC-005's own billing-state gate.
13. **No unavailable or merely `Planned` platform feature becomes executable because RFC-005 introduces billing** — `PlatformFeatureRegistry::isAvailable()` remains checked, and remains a floor no override or billing state can bypass, exactly as RFC-004 §11/§14 already lock for the entitlement chain generally.
14. **RFC-005 must integrate through the existing `UsageAuthorizationGateway` seam** (`app/Library/Entitlement/Contracts/UsageAuthorizationGateway.php`) **without redesigning `EntitlementManager` or requiring its callers to bypass it.** A real gateway implementation replaces `NullUsageAuthorizationGateway` in the `AppServiceProvider` binding; `EntitlementManager::decide()`'s own algorithm, call signature, and every existing caller are unaffected.
15. **The legacy `Plan`/`Subscription` SMS-quota billing stack remains a distinct, untouched system** (RFC-004 §6's Decision B, re-confirmed still true at RFC-004 M4's own conformance pass) **unless the future RFC-005 design proves, with direct evidence, and explicitly specifies a safe, bounded integration** — never assumed compatible or reused by inference from a similar name (§ "Existing legacy payment/billing architecture" above records exactly why `invoices.amount`/`subscription_transactions.amount` in particular must not be reused by inference).

---

## 5. Mandatory contents of the future RFC-005 design document

The eventual `RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md` must be implementation-grade and evidence-based — specific enough that its own future milestone contracts **reproduce** it rather than redesign it, exactly matching RFC-004's own standard. It must resolve, recommend, and justify at least the following, organized under the letter headings below (the future document may use its own section numbering, but must address every item):

**A. Scope and terminology** — precise definitions for: Business wallet, usage account, available/posted/reserved balance; payer, funding source, payment instrument, top-up, recharge, charge, reservation, capture/commit, release, refund/reversal, adjustment; metered versus non-metered features; additional-slot checkout versus metered feature usage. Exact v1 scope and explicitly deferred later scope, including Agency client rebilling.

**B. Money and accounting invariants** — authoritative currency ownership and whether a Business wallet is single-currency; integer-minor-unit or exact-decimal representation (PHP `float` forbidden for any persisted or authoritative money value — direct evidence above already shows why the legacy `string`-column pattern is unsafe too, and must not be repeated); ledger entry types, signs, immutable fields, correlation keys, actor/source, external provider references, timestamps; whether balance is derived, cached, or both, including reconciliation; append-only correction via reversal/adjustment entries only; negative-balance policy; cross-Business and cross-currency transfer prohibition; refund/dispute/chargeback/failed-payment/provider-fee posture; database constraints that make invalid money states difficult or impossible to represent.

**C. Metering and authorization** — explicit billing classification/cost source per platform feature; the safe default for a non-metered or unknown billing classification (must not accidentally make every entitled feature balance-gated, per §4 item 13's inherited floor and the explicit new-fact recorded above); the exact real `UsageAuthorizationGateway::check()` behavior and its denial reasons; the relationship between structural entitlement (RFC-004 §14 steps 1–6), Business toggle, billing state, and usage authorization (step 7); where usage estimates and final actual costs originate; the reservation/commit/release lifecycle for uncertain-cost work; expired/stuck reservation reconciliation; atomicity between usage execution and its accounting effect; prevention of double-charge and of free duplicate execution.

**D. Payer and payment instruments** — Business/client instruments versus Workspace fallback instruments; default selection by tier (§4 item 3); explicit payer changes and their authorization; fallback and missing-instrument behavior; PCI-safe storage (provider identifiers/tokens only, **never** raw card data — the existing `PaymentMethods` gateway-configuration table is not a precedent for this, per the audit finding above); provider-customer ownership and cross-tenant isolation; deletion/detachment behavior when an instrument is still referenced; how a payer change preserves the locked historical-wallet invariant (§4 item 5).

**E. Stripe/provider boundary** — whether v1 is Stripe-only or uses a provider abstraction; exactly which legacy gateway configuration (if any) may safely be reused versus what must remain fully isolated from the legacy `PaymentController`/multi-gateway checkout flow (the audit above found zero Workspace/Business awareness anywhere in that 12,887-line controller — treat that isolation as the default, not an assumption to be casually crossed); Checkout Session versus PaymentIntent/SetupIntent choices; signature verification from the raw webhook payload; provider-event persistence, replay handling, and idempotency keys; duplicated/reordered/delayed/conflicting webhook behavior; outbound provider calls kept outside database lock/transaction windows; retry/reconciliation behavior when provider and local outcomes diverge; secret/configuration ownership and test-mode safety.

**F. Auto-recharge and controls** — trigger semantics, threshold comparison, recharge amount (§4 item 7); monthly cap calculation, timezone, period reset, and concurrency; one active recharge attempt per Business/policy; failed-payment retries and disabling/suspending rules; low-balance notification deduplication and reset behavior; manual top-up behavior; zero-balance and reserved-balance authorization behavior; customer-configurable limits that can never weaken a platform-enforced safety limit.

**G. Additional Business-slot purchase** — quote/payment/allocation sequence; price and currency sourced from RFC-004's existing `workspace_plan_catalog` (never re-derived or duplicated); idempotent success handling; no allocation before confirmed successful payment unless the design proves an equally safe compensating model; the safe call into RFC-004's existing authoritative allocation mutation (§4 item 9); duplicate webhook/callback and customer-retry behavior; complimentary and Agency-unlimited-slot behavior (§4 items 10–11); price changes occurring between quote and payment.

**H. Authority and isolation** — sole manager/repository authorities for every new billing table, with no controller-owned business rule (matching RFC-004 §20's own absolute rule, inherited here); which customer roles may view balances, configure billing, top up, change payer, or view the ledger; platform-admin inspection/mutation permissions, under a distinct new permission category (§4's legacy-collision finding above — never `Subscriptions`/`Payment Gateways`); Staff, active Admin, owner, direct Business owner/customer, and platform administrator treated as genuinely distinct authority paths, exactly as `AGENTS.md`'s own "Workspace authorization" section already requires repository-wide; unrelated Workspace/Business resources fail closed without information leaks; no raw billing-table query outside the named authorities and immutable migration/backfill exceptions (mirroring RFC-004 §20's exact posture and its `NoRawEntitlementTableQueryTest.php` precedent).

**I. Concurrency, idempotency, and events** — canonical row-lock order across Workspace, Business, wallet, reservation, ledger, recharge-attempt, and entitlement/slot rows (extending, never contradicting, RFC-004 §17.2's already-locked ascending-Workspace-ID ordering and its documented legacy-onboarding-vs-`transferOwnership()` inverse-order deadlock/retry precedent — re-verified as still accurate in the RFC-004 M4 correction round); deadlock/retry posture; stable operation/idempotency keys; unique constraints supporting idempotency; exactly-once accounting effect even under at-least-once provider delivery; events carrying IDs/scalars only, dispatched after commit (RFC-003 §19/RFC-004 §21's shared convention, inherited); queued work using the existing `ShouldQueue`/`App\Jobs\Base` convention where applicable (a real precedent this repository already has, unlike RFC-004, which needed none); forced-race test scenarios, including an unrelated-Business-progress assertion, mirroring `EntitlementManagerConcurrencyTest.php`'s own proven pattern.

**J. Schema and migration safety** — proposed tables, columns, foreign keys, indexes, uniqueness, check constraints, deletion rules, and enums; DDL/data-operation separation (RFC-003 §10.1/RFC-004 §25.1's shared convention); backfill/initialization for every existing Business; rollout behavior that does not unexpectedly block all existing entitled, non-metered use; compatibility with non-metered features during deployment; reversibility, reconciliation, and destructive-rollback warnings (matching the exact rollback-danger discipline RFC-004's own deployment guide had to correct and now documents in full); no native database `ENUM` unless repository precedent and portability are explicitly reconsidered and justified (RFC-003/RFC-004 both use string-backed PHP enums exclusively — treat that as the default, not `ENUM`).

**K. HTTP/UI and operational surfaces** — minimum customer and admin routes/controllers/requests/views; route-model scoping and authorization; balance, ledger, payer, instruments, recharge-policy, and failure states; webhooks kept fully separated from authenticated browser routes; CSRF/signature/replay posture; scheduled reconciliation and notification work; a support/admin adjustment workflow with mandatory actor and reason (matching RFC-004 §18's `changePlanStatus()` mandatory-actor/reason precedent); observability without exposing secrets or sensitive payment details.

**L. Testing and release plan** — unit, HTTP, repository, migration/backfill, webhook, provider-fake, authorization, money-precision, idempotency, and concurrency tests; mechanical source-boundary tests (extending or superseding `WorkspaceM1BBoundaryTest::test_no_rfc005_concepts_exist_yet`'s current narrow scope, per the audit finding above); Stripe/provider calls fully faked in every automated test — never a real network call; `ultimatesms_testing` only, never production or another database; focused tests before the aggregate Entitlement/Workspace/Business/Opportunity and full-suite gates, mirroring RFC-004 §22's exact five-gate shape; milestone decomposition where each milestone has its own future contract, exactly as RFC-004 §29 decomposed M1–M4; a conformance/deployment final milestone, exactly as RFC-004 M4; a post-merge exact-tag-candidate gate and a proposed annotated-tag name, if and when RFC-005 reaches its own final milestone; **no tag authorization inside the design document itself**, matching RFC-004 §30's identical restraint.

---

## 6. Open-decision and gap rule

The future design document must distinguish, for every substantive claim it makes, exactly which of these four categories it belongs to:

1. **Inherited locked decision** — one of §4's fifteen items above, or another decision an existing merged RFC/contract has already made and RFC-005 may only refine, never reverse.
2. **Repository fact** — something directly verified against actual code, cited by file/method/migration, never assumed from a name or a prior document's claim alone.
3. **Recommended new design decision** — the designer's own proposed resolution to a genuinely open RFC-005-specific question, stated with its reasoning and tradeoffs.
4. **Genuine human product decision** — a question repository evidence and inherited requirements cannot safely resolve (e.g., exact provider choice beyond what §5.E already constrains, exact monthly-cap default figures, whether v1 ships Agency client rebilling at all).

**If a future design question falls into category 4**, the designer must, for that exact question:

1. state the exact unresolved decision;
2. provide concrete options and tradeoffs;
3. give a recommendation;
4. mark that part of the design **non-implementation-ready** until a human resolves it — the document as a whole is not blocked by one open item, but that item must not be silently defaulted.

**The designer must never**, at any point: silently choose a commercially significant policy; invent a price; invent provider behavior; weaken an accounting guarantee (append-only, exact-decimal, no cross-Business/cross-currency transfer, etc.); or paper over an apparent conflict with RFC-003/RFC-004.

**A discovered conflict between what RFC-005 would need and what RFC-003/RFC-004 already lock is itself a STOP-and-report condition** — it is reported in the design document as an explicit, named contradiction with both sides quoted, never silently resolved by editing RFC-003, RFC-004, or any product code. Amending an already-tagged, complete RFC is a separate, explicitly human-authorized action outside this contract's scope entirely.

---

## 7. Contract-branch verification and publish (this document only)

After drafting this one file:

- `git diff --check` must be clean.
- `git status --short` must show exactly `?? docs/automation/RFC-005-DESIGN-CONTRACT.md`.
- The changed-path set must be exactly that one path — verified via both `git status --short` and `git diff --name-only`.
- `git diff --cached --name-only` must be empty before staging.
- The complete contract is reviewed for contradictions, accidental implementation authorization, or any wording implying automatic progression, before it is committed.
- Stage the one file by its exact path only (never `git add -A`/`git add .`).
- Commit exactly as: `docs: define RFC-005 design contract`.
- Push normally to `origin chore/rfc-005-design-contract`. No force push. Do not push `main`. Do not create or push a tag.
- If a PR cannot be opened because the required GitHub tooling is unavailable in this environment, do not install tooling merely to open it — report the ready-made GitHub comparison URL instead.

PHP tests are not required for this exact one-file docs-only change and must not be run — report them honestly as not run, without fabricating any count.

---

## Forbidden governance / automation (summary)

- No automatic implementation start, ever, from this document alone.
- No automatic merge.
- No force push. No direct push to `main`.
- No paid model API or usage-credit requirement.
- No Codex completion requirement. No automatic model handoff.
- No tag of any kind created by this contract or by the eventual RFC-005 design-document PR.
- No RFC-005 implementation, migration, test, route, view, or Stripe/provider work of any kind under this document.
- `advance_automatically` remains `false`. `start_automatically_after_contract_merge` remains `false`.
- `docs/automation/AI-AUTONOMY-STATE.json` is not modified by this contract and is not treated as an authorization source for it.

**Design drafting is not authorized under this document until it is human-reviewed and merged, and even then only after a separate, explicit human instruction to begin drafting.**
