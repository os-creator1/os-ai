# RFC-005 Milestone 3 Contract — Provider Customers, Instruments, and Stripe Integration

**Status: READY_FOR_TEST_MODE_IMPLEMENTATION — BLOCKED_FOR_LIVE_CHARGING**

**This contract authorizes drafting this one document only. It does not authorize RFC-005 Milestone 3 implementation, any live Stripe charge, any production deployment, or use of production credentials.** A separate, explicit human instruction is required before any migration, model, repository, manager, gateway, job, event, exception, configuration, route, controller, request, view, or test named below may be written. Merging this contract does not automatically start implementation.

**M3 is designed test-mode-first.** Every requirement below that could be satisfied only by real production money movement is instead satisfied, for this milestone, by Stripe's own test mode — real API calls, real webhook delivery, real signature verification, against Stripe's test-mode endpoint and test-mode keys, with test card numbers, never a live charge. Production live-charging readiness is tracked as a **separate, independent status** from test-mode implementation readiness (§4), and remains blocked regardless of how complete the test-mode implementation is.

---

## 0. Governance

- Verified base SHA: `a25809d7bceef54a66fe55f3eb6a6dd03b9f92ec` (`main`, confirmed `HEAD == origin/main` at drafting time, preflight §1 below).
- Governing RFC-005 design document: `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md`, version 1.4, governed by `docs/automation/RFC-005-DESIGN-CONTRACT.md` (merged commit `0e74f199bcf13eaf86e0770858c13901323b0eab`, confirmed an ancestor of the base SHA).
- Governing M1 contract: `docs/automation/RFC-005-M1-CONTRACT.md`, M1 implementation commit `9b53097c9f1571aee65f2b522d56f94d74341b6f` (confirmed an ancestor).
- Governing M2 contract: `docs/automation/RFC-005-M2-CONTRACT.md`, final merged correction commit `2442e65fc9a7c01e3e904e425bc85a684fd6b5f0` (confirmed an ancestor); M2 implementation commit `3178f6d255d83fe9e15a534af6d85ea635e03195` (confirmed an ancestor, merged to `main` via PR #78).
- Branch: `chore/rfc-005-m3-contract`.
- Contract status: **READY_FOR_TEST_MODE_IMPLEMENTATION.** No open RFC-005 decision prevents a deterministic, fully test-mode-verifiable implementation of M3's own exact scope (§6). **BLOCKED_FOR_LIVE_CHARGING** — production payment collection under this design remains gated by all four RFC-level implementation-readiness gates (RFC §"Implementation readiness"), of which two are still unresolved at M3 drafting time (additional-slot allocation authority; RFC-004 catalog-pricing operator surface) and one is a legal gate outside M3's own scope entirely (production tax/VAT sufficiency, §23) — **none of the three is M3's own responsibility to resolve, since M3 owns no additional-slot or tax behavior at all (§7)** — plus every live-specific gate this contract itself defines (§4).
- Merge policy: **human-only**. This contract's own merge does not automatically start implementation — a separate, explicit human instruction is required, exactly as M1 and M2 each required after their own contracts merged.
- `maximum_correction_rounds: 2`, identical discipline to every prior RFC-004/RFC-005 contract in this repository, reset for this new milestone contract.
- `docs/automation/AI-AUTONOMY-STATE.json` carries no authorization weight for this contract and is not modified by it (confirmed stale/historical, still referencing RFC-003 Milestone 4, read only).
- No RFC document, RFC-004 document, or automation-state file is modified by this contract. No tag is created. No direct push to `main`.

---

## 1. Mandatory preflight — verified

1. `git checkout main` → `git fetch origin` → `git pull --ff-only` — fast-forwarded `e6a6336..a25809d` (4 commits, including the merged M2 implementation PR #78), working tree and staging area confirmed clean (`git status --short` empty) both before and after.
2. `git rev-parse HEAD` == `git rev-parse origin/main` == `a25809d7bceef54a66fe55f3eb6a6dd03b9f92ec` — **YES**.
3. `git merge-base --is-ancestor 3178f6d255d83fe9e15a534af6d85ea635e03195 HEAD` → **YES** (M2 implementation).
4. `git merge-base --is-ancestor 2442e65fc9a7c01e3e904e425bc85a684fd6b5f0 HEAD` → **YES** (final M2 contract).
5. `git merge-base --is-ancestor 9b53097c9f1571aee65f2b522d56f94d74341b6f HEAD` → **YES** (M1 implementation).
6. `git merge-base --is-ancestor 0e74f199bcf13eaf86e0770858c13901323b0eab HEAD` → **YES** (RFC-005 design document).
7. All 86 final M2 implementation/remediation paths (the merged M2 contract's own §12 allowlist) mechanically confirmed present on `main` at the base SHA — verified by testing existence of every literal path, and a glob match for the 8 date-stamped migration filenames (`2026_08_16_1300*`). Zero missing.
8. `git branch --list chore/rfc-005-m3-contract` (local) and `git ls-remote --heads origin chore/rfc-005-m3-contract` (remote) — both empty before this branch was created.
9. `docs/automation/RFC-005-M3-CONTRACT.md` did not exist before this file.
10. `git tag -l | grep -i rfc-005` → empty. No RFC-005 release tag exists.

All conditions satisfied. Proceeding.

---

## 2. This contract's own exact file scope

This branch (`chore/rfc-005-m3-contract`) changes **exactly one file**: `docs/automation/RFC-005-M3-CONTRACT.md`. No other path is modified, created, deleted, formatted, or staged. No `.env` file is touched. No dependency file (`composer.json`/`composer.lock`) is touched — the Stripe SDK version audit (§3) is read-only for this contract; any future upgrade, if ever proven necessary, is an M3 *implementation*-time action, never a contract-drafting-time one.

---

## 3. Mandatory repository audit — findings

All findings below are from direct repository inspection at the base SHA, this drafting pass — not carried over from any prior report without re-verification.

**3.1 Installed Stripe SDK.** `composer.lock`: `stripe/stripe-php` **`v7.128.0`** (`composer.json` constraint `^7.76`, satisfied). Stripe PHP SDK v7.x fully supports every API M3 needs: Checkout Sessions, SetupIntents, PaymentIntents, saved PaymentMethods, off-session confirmation (`confirmation_method: 'automatic'`, `off_session: true`), `Stripe\Webhook::constructEvent()` signature verification, idempotency keys (`Stripe\StripeClient`'s `idempotency_key` request option), and `metadata` on every relevant object. Refunds are supported by the SDK but **M3 does not own refund initiation** (§7) — no refund-issuing code path is authorized by this contract. **No SDK upgrade is authorized or required.** The installed version is locked as-is for M3.

**3.2 Legacy Stripe/payment classes, controllers, routes, configuration, tables — classified, not assumed reusable.**
- `composer.json` still requires four separate gateway SDKs (`stripe/stripe-php`, `braintree/braintree_php`, `paypal/paypal-server-sdk`, `razorpay/razorpay`) — a genuinely multi-gateway legacy stack, unchanged since the RFC-005 design-contract audit.
- **Raw Stripe SDK calls exist today, scattered directly inside Eloquent repository classes**, confirmed by direct grep: `Stripe::setApiKey($secret_key)` followed by `\Stripe\Checkout\Session::create([...])` and `\Stripe\TaxRate::create([...])` in `app/Repositories/Eloquent/EloquentAccountRepository.php` (senderid/keyword/number/subscription top-up flows), and the identical `Stripe::setApiKey()` pattern in `EloquentKeywordRepository.php`, `EloquentPhoneNumberRepository.php`, `EloquentSenderIDRepository.php`, `EloquentSubscriptionRepository.php` (two call sites). **This is exactly the anti-pattern §9/§25.3 below forbids repeating: direct SDK calls with no gateway boundary, scattered across unrelated repository classes, keyed off `PaymentMethods::TYPE_STRIPE` gateway-configuration rows.** M3 introduces its own fully isolated `StripePaymentProviderGateway` and touches none of these five legacy files.
- `app/Models/PaymentMethods.php` (`database/migrations/2018_07_27_112022_create_payment_methods_table.php`) is a **platform-level gateway-configuration row** (API keys per enabled legacy gateway, `TYPE_STRIPE`/`TYPE_PAYPAL`/etc. constants, an `options` JSON blob) — it carries no Business/Workspace/customer scoping column of any kind. **Not reused by M3** — `payment_provider_customers`/`business_payment_instruments` are new, Business/Workspace-scoped tables (§21).
- `app/Http/Controllers/Customer/PaymentController.php` (12,887 lines) handles every legacy top-up/subscription-purchase flow across every legacy gateway; `routes/customer.php` registers its routes (`payment/top-up/*`, `callback/{gateway}/*`, `subscriptions/*`) with zero Workspace/Business scoping. **M3 touches none of this file or its routes.** Confirmed unmodified by M1/M2 (`git diff --stat` against every prior contract's own base SHA was empty for this file every round); M3 continues that isolation.
- `app/Models/Invoices.php`/`app/Models/SubscriptionTransaction.php`: plain `string` `amount` columns, `onDelete('cascade')` foreign keys — **not reused** as a money representation or an FK-delete-behavior precedent; M3's own tables use signed/unsigned `bigint` micro-unit columns and `restrictOnDelete()` throughout, exactly matching M1/M2's own established convention.
- `config/cashier.php` exists (Stripe key/secret/webhook config shaped for Laravel Cashier) but **`laravel/cashier` is not present anywhere in `composer.lock`** — this file is orphaned/dead configuration for a package that was never actually installed. **M3 does not reuse or modify `config/cashier.php`.**
- `config/services.php` already carries a `'stripe'` array: `key` (`env('STRIPE_KEY')`), `secret` (`env('STRIPE_SECRET')`), `webhook.secret` (`env('STRIPE_WEBHOOK_SECRET')`), `webhook.tolerance` (`env('STRIPE_WEBHOOK_TOLERANCE', 300)`) — **already-established env-var names this repository uses for Stripe**, though currently consumed by no real code path (the legacy repositories above resolve their Stripe secret key from a `PaymentMethods` row's `options` JSON, not from this config array). **M3 reuses these exact env-var names** for its own gateway configuration (§19), adding the additional keys M3 specifically needs (mode/environment, safety-ceiling config) as new, clearly-namespaced keys rather than inventing parallel Stripe key names.
- **No real webhook-signature-verification precedent exists anywhere in this repository** — confirmed by a direct search for `constructEvent`/`Webhook::` across `app/`: zero matches. The legacy gateway callback routes (`callback/{razorpay,sslcommerz,aamarpay,flutterwave,coinpayments,nowpayments,easypay,fedapay}/*`) exist, but none of them perform cryptographic signature verification of the kind M3's own Stripe webhook must. **M3 is the first real signature-verified webhook in this repository.**

**3.3 Queue and job conventions.** `app/Jobs/Base.php` implements `ShouldQueue` (`Dispatchable`, `InteractsWithQueue`, `Queueable`, `SerializesModels`, `failOnTimeout: true`, `tries: 1`, `maxExceptions: 1`) — the shared job base class every `App\Jobs\{Domain}\*` job extends. `app/Jobs/Usage/ExpireStaleUsageReservations.php` (M1) is the existing precedent: `extends Base`, a thin `handle()` delegating to a manager method, `ShouldQueue` inherited, no `ShouldQueueAfterCommit` (it is not dispatched from inside a request-handling transaction). `App\Jobs\Business\BuildInitialBusinessSnapshot` additionally `implements ShouldQueue, ShouldQueueAfterCommit` — the precedent for a job dispatched from within a transaction that must not run before that transaction commits. `.env.testing`/`.env.example` both set `QUEUE_CONNECTION=sync` — every queued job in this repository, including M3's, executes synchronously and inline in both the test suite and local development, with no separate queue worker process required.

**3.4 Event convention.** Every M1/M2 event (`BusinessWalletBillingStatusChanged`, `BusinessPayerChanged`, `BusinessBillingContactChanged`, etc.) `implements ShouldDispatchAfterCommit`, carries IDs/scalars only, lives under `App\Events\Usage\*`. M3's own events (§14 below) follow this identically.

**3.5 M1/M2 transaction and lock ordering.** `UsageWalletManager::reserve()`/`commit()`/`release()` each: `DB::transaction()`, `findForUpdateByBusinessId()` the wallet row first, then perform every write inside that one transaction, dispatching any after-commit event only once the transaction closes. `BillingProfileManager::changePayer()` locks the `business_payer_assignments` row (`findForUpdateByBusinessId()`) before writing. **No outbound network call of any kind occurs inside any M1/M2 transaction** — confirmed, since neither manager makes one. M3 must preserve this exactly: every outbound Stripe call happens strictly outside any open database transaction/lock (§8, §16).

**3.6 M2 payer-consent and billing-contact rules — direct code re-read, not assumed.** `BillingProfileManager::assertPayerConsent()` (private): `payer_type = 'workspace'` requires `(int) $business->workspace->owner_user_id === $actorUserId`; `payer_type = 'business'` requires `(int) $business->customer_id === $actorUserId`; any other actor is denied via `UnauthorizedPayerAssignmentException`. `BillingProfileManager::assertCanManageBusinessUsageBilling()` (private, non-payer-consent-gated actions — billing contact, per the M2 contract): direct Business owner, OR Workspace owner, OR an active Admin whose `business_access_scope` covers the Business (`WorkspaceBusinessAccessScope::All` or an explicit `WorkspaceMembershipBusiness` grant) — Staff is never authorized. `UsageWalletManager::assertCanManageBusinessUsageBilling()` (a separately-implemented private method with the identical rule, used for `setSpendCap()`/`setFeatureLimit()`) mirrors this exactly. **M3 extends this identical two-tier model**: non-charge-causing configuration (view payment settings, inspect funding history) uses the broader `assertCanManageBusinessUsageBilling()`-shaped authority; every charge-causing action (SetupIntent creation, top-up initiation, auto-recharge enablement) uses the narrower `assertPayerConsent()`-shaped authority, with **no platform-administrator override for origination** (§16 of the RFC, re-stated in §17 below) — confirmed as the exact RFC requirement, not a new invention.

**3.7 Current Usage & Billing dashboard and route structure.** `app/Http/Controllers/Customer/Business/UsageBillingController.php` (M2): `show()`, `updatePayer()`, `updateBillingContact()`, `updateSpendCap()`, `updateFeatureLimit()`, all behind `resolveViewableBusiness()` (404-never-403, `WorkspaceManager::userCanAccessBusiness()`). `resources/views/customer/business/usage-billing/show.blade.php` (M2): renders wallet/ledger/payer/billing-contact/spend-cap/feature-limit sections, and the literal, currently-honest placeholder text **`"Payment methods and top-ups are not yet configured."`** (confirmed present, `NoFakePaymentControlsRenderedTest.php` passing, zero fake payment controls rendered). Five routes exist in `routes/customer.php`, inside the existing `workspaces.` prefix group: `GET .../usage-billing` (`customer.workspaces.businesses.usage-billing.show`), plus four `POST` mutation routes. `resources/views/customer/workspaces/show.blade.php` carries the "Usage & Billing" nav link, JS-constructed href via the page's own established `data-business-action` convention (never a literal `route()`-resolved URL in server-rendered HTML — confirmed, this convention must be preserved by any M3 nav change). **M3 extends `UsageBillingController.php` and `show.blade.php` with real payment-method/top-up/auto-recharge functionality, and removes the placeholder text only once real functionality replaces it (§18).**

**3.8 `UsageWalletManager.php`'s own explicit deferral, confirmed by direct read.** The class docblock states: *"No credit/top-up/debt-clearing/billing-status-transition method exists here — those are M2+/M3+/M4 scope (M1 contract §5.7). No auto-recharge dispatch of any kind occurs from any method in this class (Correction Round 1, §5.7)."* `reserve()`'s own docblock repeats: *"No `EvaluateBusinessAutoRecharge` dispatch of any kind (Correction Round 1, §5.7)."* Confirmed directly: `reserve()` inserts a `Reservation` ledger entry with `available_delta_micro: -$reservedAmountMicro` (negative) and dispatches nothing; `commit()`'s overage branch inserts a `UsageOverageCharge` entry with a negative `available_delta_micro` component and likewise dispatches nothing. **No centralized ledger-insert helper exists today** — each write site calls the ledger repository directly and independently. **M3 is the first milestone authorized to add the `EvaluateBusinessAutoRecharge` dispatch call**, which requires modifying `UsageWalletManager.php` (an existing M1/M2 production file) at both negative-`available_delta_micro` write sites (`reserve()`'s reservation insert; `commit()`'s overage-charge insert) — §15/§23 item 84.

**3.9 Encrypted-cast, secret/configuration, and CSRF precedents.** No genuine Laravel `encrypted` Eloquent cast exists anywhere in `app/Models` today (a stray `'encrypted' => false` array key in an unrelated payload structure was the only false-positive match) — **M3 is the first real use of the `encrypted` cast**, for `payment_provider_events.payload_encrypted` (§21). `app/Http/Middleware/VerifyCsrfToken.php`'s `$except` array already exempts several legacy webhook/callback URI patterns (`/callback/sslcommerz/*`, `/callback/aamarpay/*`, `/callback/flutterwave/*`, `/callback/razorpay/*`, `/callback/paytech/*`, `inbound/*`, `/payment/*`, `dlr/*`) — **the established mechanism for exempting an unauthenticated, signature-verified webhook route from CSRF.** M3's Stripe webhook route is added to this same array (§23 item 85, a modified-file allowlist entry, matching the same "narrow, additive, pre-existing-file" pattern the M2 contract already used for `routes/customer.php`). Rate-limiting precedent: `throttle:5,60`/`throttle:60,1` already used on `routes/customer.php`'s onboarding-analysis routes — reused for M3's own SetupIntent/top-up-initiation routes (§18).

**3.10 RFC-005 design objects assigned to M3 (§36 item 3 of the RFC, quoted in full):** *"M3 — Provider Customers, Instruments, and Stripe Integration. Schema: `payment_provider_customers` (with `UNIQUE(id, provider)`), `business_payment_instruments` (with the composite provider-consistency FK), `business_funding_attempts` (with unique `provider_session_or_intent_reference`), `business_funding_attempt_transitions`, `payment_provider_events` (with the corrected claim-lease fields, `completed_at`). `PaymentInstrumentManager`. `PaymentProviderGateway`/`StripePaymentProviderGateway`, pinning an explicit Stripe API version, validating Stripe's documented minimum **and** eight-digit maximum. The corrected, uniformly-bounded claim/lease/exhaustion algorithm. `ProcessPaymentProviderEvent` and `PurgeExpiredWebhookPayloads` jobs. Auto-recharge as the centralized after-commit trigger, including the narrowed administrator resume-only posture. Production tax posture remains gated by §23 regardless of what this milestone resolves or defers."* — five tables, one explicitly-named manager, the gateway pair, two explicitly-named jobs, the webhook algorithm, and auto-recharge activation. §6 below derives the complete, closed scope from this passage plus the resolution of the manager-authority tension identified in §3.11.

**3.11 A genuine internal RFC tension, resolved as a category-3 decision (not silently, not guessed).** §25's schema table lists `business_funding_attempts`' sole write authority as **"`UsageWalletManager` / `UsageBillingCheckoutManager`"** (a slash, dual-authority notation), and §28 assigns "funding attempts' provider-facing leg" specifically to `UsageBillingCheckoutManager` — a manager whose *other* named responsibilities (additional-slot agreements/renewals, add-on purchases, §28) are unambiguously M4+ scope (§36 item 4). §36's own M3 description, however, explicitly lists `business_funding_attempts`/`business_funding_attempt_transitions` as M3 schema and `ProcessPaymentProviderEvent` as an M3-scoped job — meaning M3 must itself drive a funding attempt from creation through webhook-confirmed wallet credit, which requires *some* class owning `business_funding_attempts`' write authority for M3's own scope. **Resolution, directly mirroring this RFC's own repeatedly-used "ordinary incremental extension across milestones" precedent** (`UsageWalletManager` extended M1→M2; `BillingProfileManager` introduced at M2 and never touched by M1; `InitializeBusinessUsageProfile` extended M1→M2, §9/§32 of the RFC): **`UsageBillingCheckoutManager` is introduced now, at M3, scoped exclusively to the funding-attempt/provider-event responsibilities M3's own schema and job list require** — `business_funding_attempts`, `business_funding_attempt_transitions`, and `payment_provider_events` write authority; `ProcessPaymentProviderEvent`'s webhook-driven state transitions. **Every additional-slot-agreement, slot-renewal-charge, and add-on-purchase responsibility §28 also assigns to this same class name remains completely out of M3's scope** (§7) — a future M4 contract extends this same class incrementally, exactly as M2 extended `UsageWalletManager` rather than inventing a parallel class. This is a **category-3 recommended resolution** (§5), not a silent choice: the tension is named explicitly here, the resolution is justified by direct citation of this RFC's own established cross-milestone pattern, and no M4-scoped responsibility is implemented as a side effect.

---

## 4. Contract status model

Two independent, separately tracked states:

**M3 test-mode implementation readiness: READY.** Every requirement in this contract can be deterministically implemented and verified — schema, gateway boundary, webhook algorithm, funding-attempt state machine, auto-recharge activation, authorization, dashboard UI, automated tests (100% faked/mocked provider responses, zero live network calls in any regression gate), and a safe local Stripe-test-mode preview using Stripe's own publicly documented test-mode keys and test card numbers. No open RFC-005 decision (§5) blocks any of this.

**Production live-charging readiness: BLOCKED.** Regardless of test-mode completeness, live charging remains blocked until **every one** of the following is separately, explicitly satisfied — none may be inferred from test-mode success:
1. Live Stripe API keys are provisioned and stored via the platform operator's own secret-management process — never generated, requested, or handled by an implementation session.
2. A human explicitly authorizes switching the configured `mode`/`environment` (§19) from `test` to `live` in a real deployment environment.
3. Production tax/VAT legal sufficiency is separately resolved (RFC §23, RFC open item 6) — this RFC is explicitly not legal advice, and M3 makes no tax determination of any kind.
4. The two structural RFC-level implementation-readiness gates unrelated to M3's own scope (additional-slot allocation authority; RFC-004 catalog-pricing operator surface) are resolved **before any M4 work**, though they do **not** block M3's own test-mode implementation, since M3 owns no slot-agreement behavior at all (§7).
5. A separate, explicit human decision authorizes production use for this specific deployment, distinct from and in addition to any contract merge.

**Contract merge never authorizes production Stripe keys, live charges, or a live-mode deployment, under any circumstance.**

---

## 5. Open-decision classification (all 14 RFC-005 open items vs. M3)

| # | RFC-005 open item | Classification vs. M3 | Resolution |
|---|---|---|---|
| 1 | Exact initial retail usage rates per metered feature | Not M3 scope | M3 activates zero metered features (M5 scope). No effect on M3. |
| 2 | Exact default Business monthly spend cap | Not M3 scope | Already resolved at M2 as "no invented default, nullable, explicit configuration only." M3 adds no new cap concept. |
| 3 | Exact default per-feature limits | Not M3 scope | Same as item 2 — M2-owned, unaffected by M3. |
| 4 | Exact auto-recharge default threshold (RFC-003 §26.2's "below $10," recommendation $5.00) | **Blocks test-mode implementation if defaulted; does not block if left unconfigured** | M3 does not invent a threshold. `auto_recharge_threshold_micro`/`auto_recharge_amount_micro` remain the M1-shipped nullable wallet columns, `auto_recharge_enabled` remains `false` by default (M1). M3 ships the **mechanism** (evaluation, provider charge, wallet credit) fully test-mode-verifiable with an explicit, human/test-configured non-null threshold+amount **per test fixture** — never a hard-coded application default. Test-mode implementation is READY; a genuine customer-facing default value remains a live-launch product decision, listed as a live gate (§4 item 5). |
| 5 | Owner/operator complimentary Agency Workspace metered-usage subsidy | Not M3 scope | M5/policy scope — no metered feature exists yet. No effect on M3. |
| 6 | Invoice/tax/VAT operational provider and legal sufficiency | **Live-production gate only** | `NON-IMPLEMENTATION-READY` for production launch (RFC §23); does not block M3 test-mode implementation, since M3 causes no real charge. Explicit live gate (§4 item 3). |
| 7 | Timing of Agency client rebilling | Not M3 scope | `agency_rebill` remains an inert `PayerType` enum case (M2-shipped, never activated in v1). M3 makes no rebilling change. |
| 8 | Exact v1 add-on roster and pricing | Not M3 scope | Add-ons are `FundingAttemptPurpose::AddonPurchase` at the enum level only (defined but never set by any M3 code path) — full add-on scope is M4 (§7). |
| 9 | Exact initial per-feature platform safety-limit ceilings | Not M3 scope | M2-owned (`platform_feature_usage_safety_limits`), unaffected by M3, no metered feature exists yet to need a ceiling. |
| 10 | v1 settlement currency and multi-currency scope (recommendation: USD only) | **Blocks nothing in M3 if left exactly as M1 already resolved it** | M1 already scopes every wallet to exactly one settlement currency (`business_usage_wallets.currency_id`). M3 introduces no new currency concept — every M3 amount is validated against the wallet's own already-resolved `currency_id`/`currencies.code` (§12). No new default is invented by M3. |
| 11 | The first actual metered feature(s) | Not M3 scope | M5-named. No effect. |
| 12 | Exact default monthly auto-recharge cap | Same treatment as item 4 | `monthly_recharge_cap_micro` remains the M1-shipped nullable column; no default invented; test-mode mechanism fully verifiable with explicit test fixtures. |
| 13 | Additional-slot `payment_lapsed` grandfathered-capacity revocation policy | Not M3 scope | M4-owned (`additional_business_slot_agreements` does not exist until M4). No effect on M3. |
| 14 | Cross-RFC additional-slot allocation authority blocker | **Not M3 scope; blocks only M4** | M3 allocates no additional Business slot and calls `EntitlementManager::setAdditionalBusinessSlots()` from nowhere in its own code. Explicitly out of M3's scope (§7). Must be resolved before M4 is contracted, not before M3. |

**No item above prevents M3's own deterministic test-mode implementation.** Items 4 and 12 are the only ones with any bearing on M3 at all, and both are satisfied by M3 shipping the mechanism with no invented default — exactly the same "no financial default invented" discipline M1/M2 already applied to `monthly_spend_cap_micro`/`business_feature_usage_limits`.

---

## 6. Exact M3 scope

Derived exclusively from RFC §36 item 3 (§3.10 above) plus the resolved manager-authority tension (§3.11):

- **Provider customer ownership** — `payment_provider_customers`, exactly one of `business_id`/`workspace_id` set (manager-enforced), `UNIQUE(id, provider)` enabling the composite child FK, `active_business_id`/`active_workspace_id` generated columns.
- **Provider payment-method storage** — `business_payment_instruments`, composite FK `(provider_customer_id, provider) → payment_provider_customers(id, provider)`, one-default-per-provider-customer serialization.
- **SetupIntent/payment-method setup** — `PaymentInstrumentManager`, the full authorization/consent/creation/attachment/confirmation/duplicate-safety flow (§10).
- **Manual Business-wallet top-up** — `UsageBillingCheckoutManager::initiateTopUp()`, `business_funding_attempts` with `purpose = manual_top_up`.
- **Workspace-payer versus Business-payer charging** — every charge-causing action gated by the wallet's current `payer_type`, exactly per RFC §16 (§17 below).
- **Funding attempts and transitions** — `business_funding_attempts`, `business_funding_attempt_transitions`, full state machine (§11).
- **Payment-provider event intake** — `payment_provider_events`, `ProcessPaymentProviderEvent` job.
- **Webhook signature verification** — before any JSON parsing or trust of the payload (§13).
- **Claim/lease/replay/disposition processing** — the corrected, uniformly-bounded algorithm (§14), including terminal `disposed` state and payload purge (`PurgeExpiredWebhookPayloads`).
- **Provider-to-local object validation** — the untrusted-metadata-hint resolution algorithm (§13 step 3).
- **Local wallet credit after confirmed payment** — via `UsageWalletManager`'s existing M1 ledger-insert mechanism (a `PaidTopUp`/`AutoRecharge` entry, both entry types already defined at the RFC schema level since M1/§13, never written by any M1/M2 code path today — M3 is the first to actually insert one).
- **Auto-recharge evaluation and execution** — activating the final-state trigger; `EvaluateBusinessAutoRecharge` job (§15).
- **Recharge counters and caps** — `monthly_recharge_cap_micro`/`recharged_this_period_micro` (both M1-shipped columns, both formula-derived, both untouched in write-authority terms — M3 only ever increments the counter via the exact same `AutoRecharge` ledger-entry mechanism `commit()`-adjacent code already establishes, never a direct write).
- **Failure/dunning behavior assigned to M3** — `consecutive_recharge_failures` increment/reset (M1-shipped column, first written by M3), `requires_action` handling, low-balance notification dedup (M1-shipped `low_balance_notified_at`).
- **Payment-method detach/replace behavior** — `PaymentInstrumentManager::detachInstrument()`/re-attach, never a hard delete.
- **Usage & Billing dashboard payment sections** — extending the existing M2 dashboard (§18).
- **Queued provider operations** — `ProcessPaymentProviderEvent`, `PurgeExpiredWebhookPayloads`, `EvaluateBusinessAutoRecharge` (all `App\Jobs\Usage\*`, `extends Base`).
- **Local test-mode preview** — §20.

**Design tables remaining deferred to M4–M6** (explicitly, not silently): `additional_business_slot_agreements`, `additional_business_slot_agreement_transitions`, `additional_business_slot_renewal_charges`, `additional_business_slot_renewal_charge_transitions`, `business_usage_addon_catalog`, `business_usage_addon_purchases`, `business_usage_addon_purchase_transitions` (all M4); no M5/M6 table exists yet. **`business_billing_receipts` (RFC §23) is also deferred** — confirmed by direct re-read of RFC §36: it is named in neither M3's nor M4's own schema list, and RFC §23 itself states "Stripe-hosted receipts authoritative for v1," meaning no local receipt record is operationally required for M3's own test-mode functionality. This is recorded here as a **category-3 resolved scope gap**, mirroring the identical M2-round precedent (the RFC's own §36 never separately assigning HTTP/UI to a milestone) — `business_billing_receipts` is not created by M3 and is left for a future milestone's own contract to schedule, once a genuine local-receipt requirement (beyond the Stripe-hosted receipt URL) is identified.

---

## 7. Explicit M3 exclusions

Excluded, since the RFC does not assign them to M3:

- Additional Business-slot agreements, recurring slot renewals, add-on purchases, and every M4-owned table/manager-responsibility named in §6's deferral list.
- Agency rebilling (inert `PayerType::AgencyRebill` enum case only, unchanged from M2, never activated).
- Metered-feature classification activation (`platform_feature_usage_classifications.is_metered` flips to `true` for any feature) — M5 scope, zero features become metered by M3.
- Any M4/M5/M6 work of any kind.
- Production tax/legal certification — RFC §23 remains a legal gate this contract does not resolve.
- Production live-key activation, or any live Stripe API call from any code path this contract authorizes.
- Legacy payment-stack refactoring — `PaymentController.php`, `PaymentMethods`, the five Eloquent repositories with raw Stripe calls (§3.2), and every legacy gateway route/controller/config remain completely untouched.
- RFC-004 additional-Business-slot authority changes — `EntitlementManager::setAdditionalBusinessSlots()` is called from nowhere in M3's own code.
- RFC-004 pricing-operator work.
- Release/tag work of any kind — no tag, no conformance document, no deployment guide (M6 scope).
- Refund/reversal initiation — the `Refund`/`DisputeChargeback` ledger entry types already exist at the schema level (M1) and are never written by any M3 code path; a Stripe-initiated refund/dispute webhook is explicitly out of scope for M3 to *process into a mutation* (§13 step 5's "unknown event type" branch routes it to reconciliation, never a silent accounting effect) unless directly re-authorized by a future contract.

**No production money movement is authorized by this contract.**

---

## 8. Provider boundary

Locked exactly:

- **`App\Library\Usage\Contracts\PaymentProviderGateway`** — the sole interface any manager may depend on. Methods (illustrative signature shape, exact signatures finalized at implementation time within this contract's own constraints): `createOrRetrieveCustomer()`, `createSetupIntent()`, `attachPaymentMethod()`, `detachPaymentMethod()`, `createOffSessionPaymentIntent()`, `retrievePaymentIntent()`, `verifyWebhookSignature()` — every method returns a normalized readonly DTO (§8 below), never a raw Stripe SDK object.
- **`App\Library\Usage\StripePaymentProviderGateway`** — the sole class permitted to reference any `Stripe\*` SDK class. Constructs its own `Stripe\StripeClient` (or sets `Stripe\Stripe::setApiKey()` scoped to its own instance construction — never the legacy pattern of a bare static call from inside a repository) from configuration (§19), never a `PaymentMethods` row.
- **`App\Library\Usage\FakePaymentProviderGateway`** — the sole test double, deterministic and in-memory, never making a real HTTP call, bound only inside the automated test suite (via container override in each test's own `setUp()`, never as `AppServiceProvider`'s default binding, so test-mode local preview always exercises the real `StripePaymentProviderGateway` against Stripe's real test-mode endpoint).
- **No direct `Stripe\*` SDK call is permitted outside `StripePaymentProviderGateway`** — enforced by mechanical search (§24).
- **Provider-agnostic manager code** — `PaymentInstrumentManager`/`UsageBillingCheckoutManager` depend only on `PaymentProviderGateway`, never `Stripe\*` directly.
- **Normalized DTOs/value objects**, all `App\Library\Usage\*`, readonly: `ProviderCustomerResult` (provider customer id, created/reused flag), `SetupIntentResult` (provider SetupIntent id, client secret, status), `PaymentMethodResult` (provider PaymentMethod id, brand, last four, expiry), `PaymentIntentResult` (provider PaymentIntent id, status, client secret where applicable), `WebhookVerificationResult` (verified event id, event type, provider object id, raw verified payload, metadata array). None of these DTOs is ever serialized directly into an HTTP response, log line, or exception message in a way that leaks a secret (§24).
- **Exact typed provider exceptions**, `App\Exceptions\Usage\*`: `ProviderAuthenticationException`, `ProviderRateLimitException`, `ProviderCardDeclinedException`, `ProviderInvalidRequestException`, `ProviderApiUnavailableException`, `WebhookSignatureVerificationException` — the gateway catches every `Stripe\Exception\*` internally and re-throws one of these, so no manager or controller ever catches a `Stripe\*` exception class directly.
- **Idempotency keys generated from immutable local operation IDs** — every outbound `StripeClient` call that creates a provider-side object passes an idempotency key derived deterministically from the local row's own `local_idempotency_key` column (§11), never a fresh UUID per attempt, so a genuine client/network retry of the same logical operation is absorbed by Stripe itself as a no-op.
- **Secret values only through configuration/environment** (§19) — never a database row, never a request parameter, never a `PaymentMethods.options` blob.
- **No secrets in logs, exceptions, database payload summaries, HTML, or tests** — enforced by mechanical search (§24); every DTO/exception above carries no raw secret field by construction.

**Manager-duplication audit (§3.11 resolved):** `PaymentInstrumentManager` is wholly new (no existing manager owns provider-customer/instrument tables). `UsageBillingCheckoutManager` is introduced now, narrowly (§3.11) — it does not duplicate any responsibility `UsageWalletManager`/`BillingProfileManager` already own; it calls into `UsageWalletManager`'s own existing ledger-write methods for the actual wallet-credit effect (never re-implementing ledger accounting itself), and into `PaymentInstrumentManager` for instrument resolution, keeping each manager's sole-write-authority table set disjoint.

---

## 9. Provider-customer and payment-method ownership

- **Business-owned versus Workspace-owned provider customers** — exactly one of `payment_provider_customers.business_id`/`workspace_id` is set per row (manager-enforced at insert; `CHECK` constraint where MySQL 8+ confirmed, mirroring the RFC's own stated fallback posture, §21). A Business-owned customer backs `payer_type = 'business'` charge-causing actions for that Business; a Workspace-owned customer backs `payer_type = 'workspace'` actions for every Business under that Workspace whose current payer is `workspace`.
- **Provider consistency** — the composite FK `(provider_customer_id, provider) → payment_provider_customers(id, provider)` on `business_payment_instruments` makes a provider-mismatched instrument a schema-level impossibility (§21), never a manager-only convention.
- **Active/detached lifecycle** — `payment_provider_customers.status` (`active`|`detached`) and `business_payment_instruments.status` (`active`|`detached`); detach never deletes a row, only flips `status`/sets `detached_at`. `active_business_id`/`active_workspace_id` (generated, stored) are `NULL` whenever `status != 'active'`, so a detached customer's unique-index slot is freed for a future re-creation without violating `UNIQUE(provider, active_business_id)`/`UNIQUE(provider, active_workspace_id)`.
- **Unique provider customer ID** — `UNIQUE(provider, provider_customer_id)`.
- **Unique provider payment-method ID** — `UNIQUE(provider, provider_payment_method_id)`.
- **Generated active-owner columns** — `active_business_id`/`active_workspace_id`, exactly as the RFC's own §17.B specifies (`CASE WHEN status = 'active' THEN business_id ELSE NULL END`, and the Workspace equivalent).
- **No payment-method sharing across unauthorized Businesses/Workspaces** — an instrument's owning `payment_provider_customers` row is the sole source of which Business/Workspace may reference it; `PaymentInstrumentManager` never accepts an instrument ID from a request without first confirming its owning customer row matches the acting Business/Workspace's own current payer scope.
- **No cross-payer reuse without explicit consent** — a Business-owned instrument is never attachable to a Workspace-owned customer or vice versa; each `payment_provider_customers` row is independently created/reused per Business or per Workspace, never shared.
- **Payer change never silently migrates instruments** — `BillingProfileManager::changePayer()` (M2, unmodified by M3) already never touches `business_payment_instruments`/`payment_provider_customers`; M3 adds no code path that would. After a payer change, the *new* payer's own existing instruments (if any) become available; the *previous* payer's instruments remain exactly where they were, untouched, unreferenced by the new payer's future charges unless that payer independently already owns/creates its own.
- **Detach behavior preserves historical references** — `business_funding_attempts.payment_method_display_snapshot` (a frozen string, e.g. `"visa •••• 4242, exp 12/26"`) is captured at attempt-creation time and never re-derived from the (possibly since-detached) live instrument row.
- **Billing-contact updates do not rewrite old payment/funding snapshots** — `business_funding_attempts.billing_contact_name_snapshot`/`billing_contact_email_snapshot` are frozen at attempt-creation time (mirroring the RFC's own explicit `additional_business_slot_renewal_charges.requesting_customer_email_snapshot` "frozen, never re-snapshotted" rule, §22 of the RFC), independent of any later `BillingProfileManager::updateBillingContact()` call.
- **No platform administrator may originate a new stored-instrument debit** — a platform administrator may view any instrument (cross-Business), and may resume/retry an already-created, payer-authorized funding attempt (§17), but may never create a new SetupIntent, attach a new instrument as a fresh authorization, or initiate a new top-up/auto-recharge-enablement on a payer's behalf.

---

## 10. SetupIntent and payment-method flow

1. **Authorization** — the acting user must satisfy the payer-consent authority for the wallet's *current* `payer_type` (§3.6/§17) before a SetupIntent may be created at all — this is a charge-*adjacent* action (it authorizes future off-session charges), gated identically to a charge-causing action, never the broader non-payment authority.
2. **Payer consent** — re-evaluated live at the moment of the request (never cached from a page load), exactly matching `BillingProfileManager::assertPayerConsent()`'s own "evaluated against the wallet's current `payer_type`" pattern.
3. **Provider-customer creation/reuse** — `PaymentInstrumentManager::resolveProviderCustomer(Business|Workspace $owner)`: looks up an existing `active` `payment_provider_customers` row scoped to the owner; if none exists, calls `PaymentProviderGateway::createOrRetrieveCustomer()` (outside any transaction), then inserts the local row, idempotency-keyed by a deterministic key derived from the owner's own type+ID (so a genuine double-submission never creates two provider customers for the same owner).
4. **SetupIntent creation** — `PaymentProviderGateway::createSetupIntent(providerCustomerId)`, outside any transaction, with the same outbound `metadata` routing-hint convention §13 defines (`app_subject_kind: 'payment_provider_customer'`, `app_subject_id`, though a SetupIntent's own confirmation is resolved synchronously in step 6 below, not solely via webhook).
5. **Client-secret exposure rules** — the SetupIntent's `client_secret` is returned to the browser exactly once, over the authenticated HTTPS session, never logged, never stored in any database column, never included in any server-side redirect URL query string.
6. **Success/cancel return behavior** — the browser return (Stripe.js's own `confirmSetup()` promise resolution, or a return-URL redirect) is **never treated as authoritative proof of success** (§10 of this contract's own numbered requirements). On return, the server-side handler calls `PaymentProviderGateway::retrieveSetupIntent()` (a synchronous provider-retrieval confirmation, outside any transaction) to independently verify the SetupIntent's own `status`, before ever attaching the resulting PaymentMethod locally.
7. **Webhook or provider-retrieval confirmation** — the local attachment (`business_payment_instruments` insert) happens only after either (a) the synchronous retrieval in step 6 confirms `status: succeeded`, or (b) a later `setup_intent.succeeded` webhook independently confirms it, via the identical untrusted-metadata-hint-then-validate algorithm §13 defines — whichever occurs first; the second confirmation of the same already-attached instrument is a no-op (idempotency via `UNIQUE(provider, provider_payment_method_id)`).
8. **Payment-method attachment validation** — the attached PaymentMethod's own provider customer ID must match the `payment_provider_customers` row the SetupIntent was created against; a mismatch is rejected, never silently attached to the wrong owner.
9. **Duplicate callback/idempotency behavior** — a repeated browser return or repeated webhook for the same SetupIntent is absorbed as a no-op via `UNIQUE(provider, provider_payment_method_id)` and the SetupIntent's own already-`succeeded` local state.
10. **Metadata validation** — identical to §13's general rule; a SetupIntent confirmation whose metadata hint does not resolve to the expected local owner causes zero local mutation.
11. **Detach/replace behavior** — `PaymentInstrumentManager::detachInstrument()`: calls `PaymentProviderGateway::detachPaymentMethod()` (outside any transaction), then updates the local row's `status: detached`/`detached_at` inside a short transaction; never deletes the row. Replacing a default instrument locks the owning `payment_provider_customers` row (`PaymentInstrumentManager::setDefaultInstrument()`, §17.B of the RFC) before clearing the prior default and setting the new one, one transaction.

---

## 11. Funding and top-up flow

A durable `business_funding_attempts` state machine (`created` → `provider_pending` → `requires_action`|`processing` → `succeeded`|`failed`|`canceled`; `refunded`/`disputed` reachable only via a future webhook this contract does not itself drive into a mutation, §7):

1. **Local attempt creation before provider action** — `UsageBillingCheckoutManager::initiateTopUp()` inserts the `business_funding_attempts` row (`state: created`) inside its own short transaction, **before** any Stripe call is made.
2. **Immutable snapshots** — `payer_type_snapshot`, `billing_contact_name_snapshot`/`_email_snapshot`, `provider_customer_external_id_snapshot`, `payment_method_display_snapshot`, `expected_currency_id`, `expected_amount_micro` — all frozen at attempt-creation time, exactly per §17.C of the RFC (§21 schema).
3. **Provider request outside database locks/transactions** — the outbound `PaymentProviderGateway::createOffSessionPaymentIntent()` (or, for a customer-present top-up, a Checkout Session — both code paths share the identical attempt-row shape) call happens strictly after the creation transaction has committed, never inside it.
4. **Provider reference persistence** — a short follow-up transaction sets `provider_session_or_intent_reference` (unique) and `state: provider_pending`, or `requires_action`/`failed` if the provider call itself failed synchronously.
5. **States** — `created`, `provider_pending`, `requires_action`, `processing`, `succeeded`, `failed`, `canceled` are the states M3 code paths transition through; `refunded`/`disputed` exist at the schema/enum level (matching the RFC exactly) but no M3 code path ever writes them.
6. **Append-only transitions** — every state change inserts a `business_funding_attempt_transitions` row (`source`: `sync_response`|`webhook_event`|`admin_action`|`reconciliation_job`); the attempt row's own `state`/`updated_at` is the current-state cache, the transitions table is its full history.
7. **Wallet credit only after authoritative provider confirmation** — a `PaidTopUp` ledger entry (via `UsageWalletManager`'s existing M1 ledger-write mechanism, called by `UsageBillingCheckoutManager` after confirming success) is inserted **only** when the attempt reaches `succeeded`, confirmed either by webhook (§13) or, for the synchronous Checkout/PaymentIntent-confirmation code path, an independent `PaymentProviderGateway::retrievePaymentIntent()` call — never from a browser redirect alone.
8. **One local accounting effect per successful attempt** — enforced by `business_funding_attempts.id` uniquely determining at most one `business_usage_ledger_entries.funding_attempt_id` value; a second confirmation of an already-`succeeded` attempt is a no-op (checked before any ledger insert).
9. **Duplicate webhook/callback safety** — `UNIQUE(provider, provider_event_id)` on `payment_provider_events` (§13) is the true-duplicate guard at the event layer; the attempt's own already-`succeeded` state is the guard at the accounting layer — two independent layers, neither relying solely on the other.
10. **Reconciliation for provider success with delayed local mutation** — `App\Jobs\Usage\ReconcileProviderPendingState`-shaped periodic job (named at the RFC level, §29; M3 implements the funding-attempt-scoped slice of it) finds attempts stuck in `provider_pending`/`processing` past a bounded age and independently re-queries the provider for their true current state, rather than waiting indefinitely for a webhook that may never arrive.
11. **No credit on redirect alone** — the browser return handler never inserts a ledger entry itself; it only ever triggers the synchronous confirmation-retrieval path (step 7), which itself independently verifies with Stripe before crediting.
12. **Refund/reversal behavior** — explicitly **not** assigned to M3 (§7); `Refund`/`DisputeChargeback` ledger-entry types exist at the schema level only, never written by M3 code.

**Internal wallet credit versus provider refund, explicitly distinguished:** a `PaidTopUp`/`AutoRecharge` ledger entry increases `available_balance_micro` from a confirmed provider charge; a `Refund` ledger entry (not M3's to write) would instead *decrease* it in response to money actually returned to the payer by the provider — the two are structurally opposite operations sharing no code path.

---

## 12. Amount and currency conversion

- **Internal representation** — signed 64-bit integer micro-units (1 unit = 1/1,000,000 of the currency's major unit), identical to every M1/M2 money column.
- **Provider minor units** — Stripe's own smallest-currency-unit integer (e.g., USD cents).
- **Exact conversion** — `§10`'s already-defined `bcRoundHalfUp()` (BCMath, non-negative magnitudes, half-up), applied identically to M3's own outbound-amount conversion: `bcRoundHalfUp($amount_micro, '10000', 0)` for a currency with `decimal_places = 2` (USD), generalized to the wallet's actual configured currency exponent — **never hard-coded to USD's own 10,000 divisor** when the wallet's `currency_id` resolves to a different exponent; the divisor is derived from `currencies.decimal_places` (or the currency's own confirmed exponent), computed once per conversion, never assumed.
- **BCMath/integer arithmetic only** — no PHP `float`/`double` at any point in the conversion, matching M1's own `bcmath` extension requirement (re-confirmed enabled, §1 preflight equivalent at implementation time).
- **Explicit currency exponent handling** — the wallet's `currencies` row (already resolved by M1) is the sole source of the exponent; no assumption of "2 decimal places" for any currency not explicitly confirmed to use that exponent.
- **Rounding policy** — round-half-up via `bcRoundHalfUp()`, single application only, exactly matching the RFC's own §10 rule — never re-rounded a second time downstream.
- **Provider minimum validation** — every outbound Stripe amount is checked against Stripe's own currently-documented minimum charge amount for the target currency, **re-confirmed against Stripe's own current documentation at implementation time** (never assumed from this contract's own drafting-time knowledge, which may be stale by the time implementation occurs).
- **Stripe's applicable maximum amount validation** — Stripe's PaymentIntent `amount` currently supports up to eight digits in the currency's smallest unit; validated identically, re-confirmed at implementation time.
- **No float/double** — enforced by mechanical search (§24).
- **No silent currency conversion** — M3 never converts between currencies; every charge is created and confirmed in the wallet's own single settlement currency, matching the Stripe customer/PaymentIntent's own currency exactly; a mismatch between the wallet's currency and the confirmed provider object's currency is a hard validation failure (§13 step 3), never silently coerced.
- **Revalidation at webhook processing** — the webhook processor re-validates the confirmed provider object's `amount`/`currency` against the local attempt's own frozen `expected_amount_micro`/`expected_currency_id` before crediting (§13 step 3) — a mismatch causes zero mutation.
- **Provider settlement-currency scope left open where unresolved** — RFC open item 10 (USD-only recommendation) is not silently defaulted by M3; M3's own code is currency-*parametric* (driven by whatever `currency_id` the wallet already carries from M1), never hard-coded to USD specifically, so no M3 code path forecloses a future multi-currency decision — but M3 also does not itself implement multi-currency wallet support, since no wallet in this repository is ever assigned more than the one settlement currency M1 already resolved for it.
- **No hard-coded USD fallback** — confirmed by construction above; enforced by mechanical search (§24).

---

## 13. Webhook security and routing

Exact algorithm, `App\Http\Controllers\Customer\Business\StripeWebhookController` (or equivalently-named single-purpose webhook controller — no browser session, no CSRF token, no authenticated user):

1. **Receive the raw request body** — `$request->getContent()` (the exact raw bytes), never `$request->all()`/parsed JSON, since Stripe's signature is computed over the exact raw byte sequence.
2. **Verify Stripe signature before JSON trust or mutation** — `PaymentProviderGateway::verifyWebhookSignature($rawBody, $signatureHeader, $configuredSecret)`, internally `Stripe\Webhook::constructEvent()`; a verification failure returns HTTP `400` immediately, with zero row inserted, zero mutation of any kind, and a non-sensitive log line (event/signature failure only, never the raw payload).
3. **Use the configured test/live webhook secret appropriate to environment** — resolved from `config('services.stripe.webhook.secret')` (§19), whichever secret matches the currently-configured `mode`; a mode/secret mismatch fails closed (§19).
4. **Persist provider event identity with `UNIQUE(provider, provider_event_id)`** — insert `payment_provider_events` (`state: received`, `attempts: 0`) immediately after signature verification succeeds; the unique index is the true-duplicate guard — a genuine Stripe redelivery of an already-seen `provider_event_id` fails this insert (caught, narrow duplicate-key match only, treated as an already-processed no-op, HTTP `200` returned so Stripe stops retrying).
5. **Store encrypted payload and payload hash** — `payload_encrypted` (Laravel `encrypted` cast, `LONGTEXT`), `payload_hash` (`hash('sha256', $rawBody)`, permanent, survives purge).
6. **Atomically claim using the bounded lease/attempt algorithm** — exactly the RFC §21 `UPDATE ... WHERE state = 'received' OR (state = 'failed' AND attempts < 5) OR (state = 'processing' AND lease_expires_at < NOW() AND attempts < 5)` statement, verbatim (§14).
7. **Treat provider event type only as provider-object lifecycle information** — `event_type` (e.g. `payment_intent.succeeded`) determines only which lifecycle transition occurred on the *provider's own object*; it is never used to select which local table/purpose the event concerns.
8. **Treat provider metadata only as an untrusted local routing hint** — the outbound `metadata` this system itself attached (`app_subject_kind`, `app_subject_id`, `app_operation_id`) is echoed back on the event; consulted only to select which one local table+row to load.
9. **Load exactly one hinted local record** — `app_subject_kind` selects the table (`funding_attempt` is the only value M3 code paths ever set — `slot_agreement`/`slot_renewal_charge` are RFC-level values M3 never produces, since M3 owns no such tables), `app_subject_id` selects the row; never a cross-table search, never a scan for "any row matching this reference."
10. **Validate all applicable persisted expectations** against the verified Stripe object, before any mutation: provider object ID (`provider_session_or_intent_reference` equality), provider customer, amount (§12), currency (§12), payer (`payer_type_snapshot` consistency), Business/Workspace scope, purpose (`purpose` matches the expected funding-attempt kind), local operation/idempotency identifier (`local_idempotency_key` matches `app_operation_id`), expected local state (the transition must be a valid forward transition from the record's current `state`).
11. **Perform no accounting mutation on missing, ambiguous, malformed, or mismatched data** — any single validation failure in step 10 causes zero wallet/ledger/reservation/instrument mutation of any kind; the event is marked `failed` (§14) and routed to reconciliation.
12. **Route failures to reconciliation/dead-letter review** — a `failed` event surfaces in the admin exhausted-events queue once it reaches `attempts >= 5` (§14); it is never silently dropped, and never silently retried past the bound.
13. **Effectively exactly-once local accounting under at-least-once delivery** — the combination of `UNIQUE(provider, provider_event_id)`, the attempt's own state-machine idempotency, and `UNIQUE(provider, provider_session_or_intent_reference)` together make a genuine Stripe redelivery, or a worker crash mid-processing, incapable of producing two accounting effects for the same real-world payment.
14. **Preserve permanent non-sensitive idempotency/audit evidence after payload purge** — `id`, `provider`, `provider_event_id`, `event_type`, `provider_object_id`, `payload_hash`, `state`, `attempts`, every timestamp, and (for `disposed` rows) `disposed_at`/`disposed_by_user_id`/`disposition_note` all survive `payload_encrypted` being nulled (§14).

**No invented provider event name** — M3 never references a fictional event type such as `auto_recharge_intent`; only real Stripe event types (`setup_intent.succeeded`, `payment_intent.succeeded`, `payment_intent.payment_failed`, `payment_intent.requires_action`, `payment_method.detached`, etc., re-confirmed against Stripe's own current documentation at implementation time) drive any transition.

---

## 14. Claim, retry, disposition, and retention

Exact, verbatim (matching RFC §21's own corrected algorithm, no deviation):

```sql
UPDATE payment_provider_events
SET state = 'processing',
    processing_started_at = NOW(),
    lease_expires_at = NOW() + INTERVAL 5 MINUTE,
    attempts = attempts + 1,
    last_attempt_at = NOW()
WHERE id = ?
  AND (
    state = 'received'
    OR (state = 'failed' AND attempts < 5)
    OR (state = 'processing' AND lease_expires_at < NOW() AND attempts < 5)
  )
```

- `processing_started_at` — set on every claim.
- `lease_expires_at` — the 5-minute claim lease (category-3 recommended duration, matching the RFC); `NULL` outside `state = 'processing'`.
- `last_attempt_at` — set on every claim, fresh or reclaimed.
- **Bounded attempts** — `5` (category-3 recommended max, matching the RFC) — identically bounds both the `failed` retry branch and the stale-`processing` reclaim branch, the exact fix RFC §21 records.
- **Stale-processing reclaim** — a lease past `lease_expires_at` with `attempts < 5` re-enters `processing` via the same claim statement; **once `attempts >= 5`, neither a `failed` row nor a stale-`processing` row matches any branch** — both become permanently unreclaimed by the automated worker.
- **Terminal exhaustion** — `WHERE (state = 'failed' AND attempts >= 5) OR (state = 'processing' AND lease_expires_at < NOW() AND attempts >= 5)` is the exact admin exhausted-events review query.
- **Disposed/dead-letter state** — `UPDATE payment_provider_events SET state = 'disposed', disposed_at = NOW(), disposed_by_user_id = ?, disposition_note = ? WHERE id = ? AND state IN ('failed', 'processing') AND attempts >= 5` — the identical exhaustion guard, so only a genuinely exhausted row can ever be dispositioned.
- **Review/disposition fields** — `disposed_at`, `disposed_by_user_id` (nullable — `NULL` if an automated bounded process dispositions it rather than a human reviewer), `disposition_note` (non-sensitive resolution text only, never the payload).
- **Retry authority** — a platform administrator may manually re-trigger the claim statement against a specific exhausted row's `id` (`admin_retry`, mandatory reason) **before** disposition, which is the sole mechanism to give a genuinely-worth-retrying exhausted event one more bounded attempt window — this itself still respects the `attempts < 5` gate, so an administrator-triggered reclaim after already reaching 5 has no effect until the row is separately reset (a distinct, explicitly-authorized future capability this contract does not itself grant — M3 ships only the standard automated claim path plus disposition, not an attempts-reset capability).
- **Payload retention** — configurable retention window (§19); `App\Jobs\Usage\PurgeExpiredWebhookPayloads` finds `processed`/`ignored`/`disposed` events past the window and nulls `payload_encrypted`, sets `payload_purged_at`.
- **Payload purge after retention/disposition** — a merely-exhausted-but-**not**-yet-`disposed` `failed`/stale-`processing` row is **never** purged, regardless of age — an unreviewed dead letter is never silently erased.
- **Permanent event ID/hash/state/error metadata** — preserved exactly per §13 step 14.
- **No silent retry after disposition** — `disposed` matches no branch of the claim `WHERE` clause (§14's own statement above); a disposed event can never re-enter processing under any circumstance, enforced structurally by the SQL itself, not by application-level convention.

**No sensitive payload may remain indefinitely merely because processing failed** — the disposition path is the explicit, bounded answer to this requirement; an exhausted event always has a terminal path to eventual purge, gated only by a human (or automated, bounded) review action, never left open-ended.

---

## 15. Auto-recharge

M3 owns activation of the final-state trigger (§3.8):

- **Trigger** — modifying `UsageWalletManager.php` (§23 item 84) to add `EvaluateBusinessAutoRecharge::dispatch($businessId)` immediately after every ledger-entry insert whose `available_delta_micro < 0` — the `reserve()` reservation insert and `commit()`'s overage-charge insert are the two existing negative-delta write sites; both gain the dispatch call. The dispatch call itself is placed **after** the `DB::transaction()` closure returns (Laravel's own after-commit dispatch semantics via the job's `ShouldQueueAfterCommit` interface — the dispatch statement may syntactically appear inside the closure, but the job's own interface guarantees it does not actually run until the transaction commits), never inside an open transaction/lock.
- **After-commit dispatch** — `App\Jobs\Usage\EvaluateBusinessAutoRecharge implements ShouldQueue, ShouldQueueAfterCommit` (§3.3's `BuildInitialBusinessSnapshot` precedent), `extends Base`.
- **No provider call inside the wallet transaction** — the job's own `handle()` runs entirely after `reserve()`/`commit()`'s own transaction has already committed; every Stripe call the job makes is therefore already outside any wallet lock.
- **Current payer and consent revalidation** — the job re-resolves the wallet's *current* `payer_type` and re-confirms an active, non-detached payment instrument exists for that payer at *evaluation* time (not cached from whenever auto-recharge was originally enabled) — a payer change or instrument detach between enablement and trigger correctly blocks the recharge rather than charging a stale/wrong instrument.
- **Valid active payment method** — required; if none exists, the job exits without creating a funding attempt (no error state — auto-recharge silently cannot fire without an instrument, exactly as if it were disabled, with the existing `SendLowBalanceNotification`-shaped notification still firing per M1/M2's own low-balance mechanism).
- **Threshold** — `auto_recharge_threshold_micro`; the job compares the wallet's current `available_balance_micro` against this value (both already-existing M1 columns).
- **Recharge amount** — `auto_recharge_amount_micro` (M1 column); no default invented (§5 item 4).
- **Monthly recharge cap** — `monthly_recharge_cap_micro` (M1 column); the job denies the recharge attempt (no funding attempt created) if `recharged_this_period_micro + auto_recharge_amount_micro` would exceed the configured cap.
- **Current-period recharge counter** — `recharged_this_period_micro`, incremented only via the same ledger-entry-driven mechanism `commit()`'s own current-period-only counter update already establishes (§13 of the RFC) — never a direct write outside that pattern.
- **Outstanding attempt idempotency** — before creating a new `purpose: auto_recharge` funding attempt, the job checks for an already-`created`/`provider_pending`/`processing`/`requires_action` auto-recharge attempt for the same Business and, if one exists, does not create a second — preventing a burst of negative-delta ledger entries from spawning concurrent duplicate recharge attempts.
- **Concurrent low-balance events** — two negative-delta ledger entries dispatched in quick succession both evaluate independently; the outstanding-attempt check (above) is the mechanism that collapses them to at most one in-flight attempt, verified by a forced-race concurrency test (§17, §25).
- **Successful funding accounting** — identical to a manual top-up's own `AutoRecharge` ledger-entry insert (§11 step 7), via `UsageWalletManager`'s existing mechanism.
- **Failed payment behavior** — `consecutive_recharge_failures` incremented (M1 column, first written by M3); at `3` (category-3 recommended, matching the RFC), `auto_recharge_enabled` is **not** silently flipped to `false` by M3's own job — the RFC does not specify auto-disable as part of M3's own owned behavior beyond the counter itself; the counter alone is sufficient for M3's scope, with any customer-facing "auto-recharge failed repeatedly" notification (`SendAutoRechargeDisabledNotification`, named at the RFC level) firing once the threshold is reached, without this contract inventing an undocumented auto-disable side effect.
- **Retry/dunning rules** — a `requires_action` outcome is surfaced (customer must complete additional authentication); a `failed` outcome increments the counter and stops for that trigger event — no automatic immediate retry loop within the same job execution.
- **Disabled/unconfigured behavior** — `auto_recharge_enabled = false` (M1 default) short-circuits the job to a no-op before any provider call.
- **No headroom reopening from refunds or corrections** — identical to §13 of the RFC; `recharged_this_period_micro` is never decremented by a `Refund`/`DisputeChargeback`/`CorrectionReversal` entry, matching M1/M2's own already-established formula-derived-counter discipline exactly.
- **Loop prevention after positive recharge credit** — the `AutoRecharge` ledger entry itself has `available_delta_micro > 0`, so it never re-triggers `EvaluateBusinessAutoRecharge` (the dispatch condition is strictly `< 0`); the job cannot recursively re-invoke itself from its own successful outcome.
- **Platform administrator resume/retry-only authority** — an administrator may `admin_retry` an already-created, stuck auto-recharge attempt (mandatory reason); an administrator may never unilaterally *enable* auto-recharge for a Business (§17).

**No threshold, amount, or cap default is invented by this contract or by M3's implementation** (§5 item 4/12) — every M3 test exercises these mechanisms against explicit, test-fixture-supplied values.

---

## 16. Transactions and concurrency

- **Creating attempts** — one short transaction, wallet/attempt-owning row not locked beyond the insert itself (no provider call inside).
- **Provider calls outside transactions** — every `PaymentProviderGateway` call, without exception, happens with no open `DB::transaction()`/row lock held by the calling code.
- **Claiming webhooks** — the single atomic `UPDATE` (§14) is itself the entire "transaction" for a claim — no broader lock held around it.
- **Applying successful funding** — a short transaction: load the attempt row (`findForUpdate`-shaped lock on the attempt), verify it is still in a state where crediting is valid, insert the ledger entry (which itself locks the wallet row, exactly matching `UsageWalletManager`'s own existing internal locking), mark the attempt `succeeded`, insert the transition row — one transaction, no provider call inside it.
- **Ledger credit** — via `UsageWalletManager`'s own existing `findForUpdateByBusinessId()` wallet-row lock, unmodified locking behavior.
- **Recharge counters** — updated inside the same wallet-locked transaction as the ledger credit, never a separate racing update.
- **Duplicate events** — `UNIQUE(provider, provider_event_id)` plus the claim algorithm's own atomicity (§14).
- **Duplicate user submissions** — `local_idempotency_key` (`UNIQUE`) absorbs a genuine double form-submission for the same logical top-up request.
- **Concurrent manual top-up and auto-recharge** — both ultimately serialize through the same wallet-row lock at the moment either actually credits the ledger; a forced-race test proves exactly one of two simultaneous crediting attempts is not double-counted (they are not mutually exclusive operations — both may legitimately succeed — but each must independently produce exactly one correct ledger effect, never a corrupted combined one).
- **Payer change during an attempt** — an in-flight `business_funding_attempts` row's `payer_type_snapshot` is frozen at creation; a payer change mid-flight does not retroactively invalidate an already-`provider_pending` attempt, but **does** block a *new* attempt from being initiated under the old payer's authority once the change has committed (re-checked live, §10 item 2).
- **Payment-method detach during charging** — detaching an instrument that a `provider_pending`/`processing` attempt already references does not retroactively fail that in-flight attempt (Stripe itself is already mid-confirmation with that PaymentMethod); it does prevent that instrument from being selected for any *new* attempt going forward.
- **Unrelated-Business isolation** — every M3 concurrency test includes an explicit unrelated-Business progress assertion, mirroring `EntitlementManagerConcurrencyTest.php`'s and `UsageWalletManagerConcurrencyTest.php`'s own already-proven pattern (§17, §25).

**Causal synchronization for concurrency tests, never fragile elapsed-time limits** — every M3 concurrency test uses the exact deterministic causal-barrier/handshake pattern the M2 Correction Round 1 already established and proved (`UsageWalletManagerConcurrencyTest::test_concurrent_reserve_for_a_different_business_is_unaffected`'s `hold-until-signal` mechanism) — a holder process signals lock acquisition, a waiter process's own completion is observed by the parent *before* the holder is released, making "the waiter was never blocked" a matter of causal/sequential proof rather than a `<N seconds` wall-clock assertion. **No M3 test may use an `assertLessThan($seconds, $elapsed)`-shaped assertion as its substantive proof of non-blocking**, matching the exact discipline the M2 Correction Round 1 record locks in as a repository-wide precedent going forward.

---

## 17. Authorization and isolation

Exact actor/action matrix (extending, never contradicting, RFC §24 and the M2-established two-tier consent model, §3.6):

| Capability | Workspace owner | Active Workspace Admin | Staff | Direct Business owner/customer | Unrelated Workspace member | Platform administrator | Webhook caller |
|---|---|---|---|---|---|---|---|
| View payment settings (instruments, funding history, provider-event status — for Businesses/Workspaces within their own authority) | Yes, own Workspace | Yes, if `business_access_scope` covers the Business | Yes, if scope covers it (view only) | Yes, own Business | No — 404 | Yes, any Business/Workspace | n/a |
| Create SetupIntent, when `payer_type = 'workspace'` | Yes | No | No | No | No | No | n/a |
| Create SetupIntent, when `payer_type = 'business'` | No | No | No | Yes, own Business | No | No | n/a |
| Attach/detach an instrument (non-payment-causing lifecycle action, same consent gate as SetupIntent creation) | Per `payer_type`, as above | No | No | Per `payer_type`, as above | No | No | n/a |
| Initiate top-up, when `payer_type = 'workspace'` | Yes | No | No | No | No | No | n/a |
| Initiate top-up, when `payer_type = 'business'` | No | No | No | Yes, own Business | No | No | n/a |
| Configure (newly enable) auto-recharge | Same payer-consent split as top-up initiation | No | No | Same payer-consent split | No | No | n/a |
| Retry/resume an already-created, payer-authorized failed/stuck funding attempt | Yes, own attempt | No | No | Yes, own attempt | No | Yes, mandatory reason (never origination) | n/a |
| Inspect funding-attempt history (read-only) | Yes, own Workspace | Yes, if scope covers it | Yes, if scope covers it | Yes, own Business | No — 404 | Yes, any | n/a |
| Inspect provider-event status (read-only, non-sensitive fields only — never the raw payload) | No | No | No | No | No | Yes only | n/a |
| Dispose/retry an exhausted `payment_provider_event` | No | No | No | No | No | Yes only, mandatory reason | n/a |
| Access cross-Business/cross-Workspace resources of any kind | No — 404 | No — 404 (outside scope) | No — 404 (outside scope) | No — 404 (other Business) | No — 404 | Yes, any (explicit admin surface only) | n/a |
| Anonymous user — any of the above | No — 401/redirect | | | | | | n/a |
| Webhook caller — POST to the webhook endpoint | n/a | | | | | | Yes, signature-verified only (§13); no session, no CSRF, no user-scoped authorization concept applies |

**Preserved from M2 exactly:** neither payer direction can volunteer the other party's money — a Workspace owner can never authorize a charge against a Business-owned instrument when `payer_type = 'business'`, and a direct Business owner can never authorize a charge against a Workspace-owned instrument when `payer_type = 'workspace'`, under any circumstance, including a platform administrator's own action (an administrator may only *resume*, never originate, per RFC §16's narrowing, §3.6/§9 above). Unrelated Workspace/Business resources fail closed with a 404-shaped response, never 403, matching `UsageBillingController::resolveViewableBusiness()`'s own already-established M2 pattern exactly.

---

## 18. HTTP/UI contract

Extends the existing `resources/views/customer/business/usage-billing/show.blade.php` (M2) with real M3 functionality:

- **Payment-method status** — brand/last-four/expiry display only (never a raw PaymentMethod ID, never a client secret) for the current default instrument, if any.
- **Add/replace/remove payment method** — a "Set up payment method" action (creates a SetupIntent, Stripe.js-driven client-side confirmation), a "Replace" action (creates a new SetupIntent, then detaches the prior default once the new one is confirmed), a "Remove" action (`detachInstrument()`).
- **Manual top-up** — an amount-entry form (major-unit display, e.g. `"$25.00"`, converted to micro-units server-side per §12), submitting to `UsageBillingController::initiateTopUp()`-shaped new controller action.
- **Auto-recharge configuration** — enable/disable toggle, threshold, amount, monthly cap — three-field form, each required together when enabling (manager-enforced, matching `UpdateBusinessSpendCapRequest`'s own M2 validation-class precedent).
- **Funding-attempt history** — a paginated list (mirroring `UsageBillingLedgerPaginationTest`'s own M2 pagination precedent), showing state, amount, purpose, timestamp — never the raw provider payload.
- **Pending/succeeded/failed states** — rendered from the attempt's own `state` column, never inferred from a browser redirect parameter.
- **Safe return/cancel messaging** — after a Stripe redirect return, the page re-fetches the attempt's own current server-side state before displaying success/failure — never trusts a query-string `?success=1`-shaped parameter as authoritative.

**Requirements:**
- **Exact routes, controllers, FormRequests, middleware, CSRF behavior, and presenter keys** — all new customer-facing routes (SetupIntent creation, top-up initiation, auto-recharge configuration, instrument detach) sit inside the existing `workspaces.` prefix group, following the identical uid-addressable, 404-never-403 pattern `UsageBillingController` already establishes; the webhook route sits **outside** that group entirely, with no CSRF middleware (§3.9, §23 item 85), no session, no authenticated-user assumption.
- **No secret key exposure** — the Stripe secret key is never sent to the browser, never included in any Blade-rendered attribute, never logged.
- **Publishable/client secret only where necessary** — the Stripe publishable key (safe, public by design) and a SetupIntent/PaymentIntent's own per-request `client_secret` are the only Stripe-related values ever sent to the browser, each exactly where Stripe.js itself requires them.
- **No internal provider payload display** — no raw Stripe object, no webhook payload, ever rendered in any customer-facing view.
- **No raw micro-units** — every displayed amount is formatted through the presenter layer (extending `UsageBillingPresenter`, M2), major-unit display only, matching the existing dashboard's own established convention.
- **No fake success before webhook confirmation** — a "pending" or "processing" state is rendered honestly as such; success is rendered only once the attempt's own server-side `state` is genuinely `succeeded`.
- **No cross-Business leakage** — every new controller action re-uses `resolveViewableBusiness()` (M2, unmodified), so cross-Business isolation is structurally inherited, not re-implemented.
- **Existing UI style and navigation preserved** — the same Blade layout/CSS classes/`data-business-action` JS convention already established (§3.7); no new navigation pattern invented.
- **Placeholder removal** — the M2 placeholder text *"Payment methods and top-ups are not yet configured."* is removed **only** from the specific dashboard sections real M3 functionality now serves (payment method, top-up); any dashboard section M3 does not implement (auto-recharge, if M3's own scope narrows it further at implementation time — it does not, per §6 — or any M4+ concept) retains an honest, equivalently-worded placeholder rather than a fabricated one.

**Admin provider-event/reconciliation UI** — included, since RFC §30 explicitly assigns "review and disposition webhook events that have exhausted their claim/retry attempts" to the admin surface, and M3 is the milestone that first makes this operationally necessary (the claim/lease/disposition algorithm exists nowhere before M3). A minimal `Admin\PaymentProviderEventController`-shaped surface: list exhausted events, view non-sensitive fields (never the raw payload while still `failed`/`processing` — and never at all once purged), dispose with a mandatory note.

---

## 19. Configuration and environments

Exact configuration keys, `config/services.php`'s existing `'stripe'` array extended (§3.2 — reusing the already-established env-var names, adding new ones only where genuinely new):

| Key | Env var | Purpose |
|---|---|---|
| `services.stripe.key` | `STRIPE_KEY` | Publishable key (already-established name, reused) |
| `services.stripe.secret` | `STRIPE_SECRET` | Secret key (already-established name, reused) |
| `services.stripe.webhook.secret` | `STRIPE_WEBHOOK_SECRET` | Webhook signing secret (already-established name, reused) |
| `services.stripe.webhook.tolerance` | `STRIPE_WEBHOOK_TOLERANCE` (default `300`) | Signature timestamp tolerance, seconds (already-established name, reused) |
| `services.stripe.mode` | `STRIPE_MODE` (`test`\|`live`) | **New.** Selects which key pair is authoritative; `test` is the only mode any M3 implementation/preview may use. |
| `services.stripe.api_version` | `STRIPE_API_VERSION` | **New.** The pinned, explicit Stripe API version string `StripePaymentProviderGateway` requests on every call (never "whatever the account default happens to be"). |
| `usage_billing.webhook_event.lease_minutes` | `USAGE_BILLING_WEBHOOK_LEASE_MINUTES` (default `5`) | **New.** Claim lease duration (§14). |
| `usage_billing.webhook_event.max_attempts` | `USAGE_BILLING_WEBHOOK_MAX_ATTEMPTS` (default `5`) | **New.** Bounded attempts ceiling (§14). |
| `usage_billing.webhook_event.retention_days` | `USAGE_BILLING_WEBHOOK_RETENTION_DAYS` | **New.** Payload retention window (§14); no default invented — must be explicitly configured (a missing value fails closed, below). |

**No safety-ceiling default is invented for `retention_days`** — RFC open items 1–14 do not resolve a specific retention period, and this contract does not silently choose one; it must be explicitly configured in every environment, including the test-mode local preview (§20).

**Fail-closed startup/runtime behavior** — `StripePaymentProviderGateway`'s constructor (or the `AppServiceProvider` binding closure that constructs it) throws immediately, before any request is served, if: `services.stripe.mode` is unset or not one of `test`/`live`; the secret key configured for the *current* mode is empty; `services.stripe.webhook.secret` is empty; `services.stripe.api_version` is empty. A **mode/key mismatch** (e.g., a key whose own prefix — `sk_test_`/`sk_live_` — disagrees with the configured `mode`) also fails closed at construction time, never silently proceeding with a live key while `mode: test` is configured or vice versa.

**Never print secrets in reports** — no future implementation report, this contract, or any log line ever includes a raw secret-key or webhook-secret value; configuration is confirmed present/absent by name only (§21 preview requirement, item "required test-mode environment variables by name only").

**No `.env` file may be committed** — enforced by this contract's own scope (§2) and, at implementation time, by the repository's already-existing `.gitignore` coverage of `.env*`.

---

## 20. Test-mode preview requirement

M3 implementation must produce a safe local preview after merge. The future implementation report must provide:

- **Verified local/Laragon URL** — the confirmed working `APP_URL` (this repository's own confirmed value, `https://jazminenterprise.fyi`, is a **production** URL and must **not** be used for this preview unless separately authorized — the implementation report must instead confirm and state whichever local/Laragon development URL is actually used, e.g. `http://ultimatesms.test` or equivalent, verified working before being reported).
- **Safe preview database** — `ultimatesms_testing` or an explicitly-confirmed local development database, never a production database, confirmed by the same `resolvedDatabase !== EXPECTED_DATABASE` guard pattern the existing concurrency-runner scripts already use (M1/M2 precedent).
- **Required test-mode environment variables by name only** — `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_MODE=test`, `STRIPE_API_VERSION`, listed by name, with an instruction to obtain the actual test-mode key values from the Stripe Dashboard's own test-mode API keys page — **no key value is ever generated, guessed, or included by the implementation session itself.**
- **Stripe CLI webhook-forwarding command, if required** — `stripe listen --forward-to <local-url>/customer/workspaces/webhooks/stripe` (exact path finalized at implementation time), and the exact resulting `whsec_...` value's env-var name (`STRIPE_WEBHOOK_SECRET`) it must be placed into for local forwarding to verify correctly.
- **Exact webhook URL** — the final, exact route path M3 registers (§18), stated explicitly once implemented.
- **Exact customer route/click path** — starting from `customer.workspaces.show`, the "Usage & Billing" nav link, through to the payment-method/top-up UI, stated as an exact click sequence.
- **Stripe test card numbers sourced from official Stripe test documentation** — e.g. `4242 4242 4242 4242` (generic success), `4000 0025 0000 3155` (`requires_action`/3DS), `4000 0000 0000 9995` (decline) — the implementation report must cite these against Stripe's own current test-card documentation at implementation time, never invented or misremembered.
- **Expected UI states from setup through successful top-up** — a numbered walkthrough: no payment method configured → SetupIntent created → Stripe.js confirmation → instrument attached and displayed → top-up initiated → `provider_pending` displayed → webhook (or CLI-forwarded event) received → `succeeded` displayed, wallet balance visibly increased.
- **Cleanup/reset steps** — how to detach the test instrument and clear test-mode funding-attempt rows from the local/testing database without touching any other data.
- **Confirmation that no live key or production database is used** — an explicit, affirmative statement in the implementation report, not merely an absence of contrary evidence.

**The preview must not be performed against `jazminenterprise.fyi` production unless separately authorized** — this contract does not authorize that; a future implementation report proposing it must obtain explicit separate authorization first.

---

## 21. Schema contract

Five tables, exact columns (mirroring RFC §17.B/§17.C/§21 verbatim, expanded to the full per-column contract style M2 already established):

### 21.1 `payment_provider_customers`

| Column | Type | Signed | Nullable | Default | Index/Constraint | FK/Delete | Encrypted/Sensitive |
|---|---|---|---|---|---|---|---|
| `id` | `bigint unsigned` | n/a | No | auto-increment | PK | — | No |
| `provider` | `string(16)`, enum-backed (`PaymentProvider`, new) | n/a | No | `stripe` | — | — | No |
| `business_id` | `unsignedBigInteger` | unsigned | Yes | `NULL` | — | `businesses.id`, `restrictOnDelete()` | No |
| `workspace_id` | `unsignedBigInteger` | unsigned | Yes | `NULL` | — | `workspaces.id`, `restrictOnDelete()` | No |
| `provider_customer_id` | `string(191)` | n/a | No | — | — | Stripe Customer id, token reference only | No |
| `status` | `string(16)`, enum-backed (`ProviderCustomerStatus`, new) | n/a | No | `active` | — | `active` \| `detached` | No |
| `active_business_id` | `unsignedBigInteger`, generated/stored | unsigned | Yes | computed | — | `CASE WHEN status='active' THEN business_id ELSE NULL END` | No |
| `active_workspace_id` | `unsignedBigInteger`, generated/stored | unsigned | Yes | computed | — | `CASE WHEN status='active' THEN workspace_id ELSE NULL END` | No |
| `created_at` | `timestamp` | n/a | No | `now()` | — | — | No |
| `updated_at` | `timestamp` | n/a | No | `now()` | — | mutable — `status` only | No |

Indexes: `UNIQUE(provider, provider_customer_id)`; `UNIQUE(provider, active_business_id)`; `UNIQUE(provider, active_workspace_id)`; `UNIQUE(id, provider)` (composite-FK enabler). `CHECK` (MySQL 8+ confirmed): exactly one of `business_id`/`workspace_id` non-null, else manager-enforced. Sole write authority: `PaymentInstrumentManager`.

### 21.2 `business_payment_instruments`

| Column | Type | Signed | Nullable | Default | Index/Constraint | FK/Delete | Encrypted/Sensitive |
|---|---|---|---|---|---|---|---|
| `id` | `bigint unsigned` | n/a | No | auto-increment | PK | — | No |
| `provider_customer_id` | `unsignedBigInteger` | unsigned | No | — | composite FK (with `provider`) | — | No |
| `provider` | `string(16)`, enum-backed (`PaymentProvider`, reused) | n/a | No | `stripe` | composite FK (with `provider_customer_id`) | `(provider_customer_id, provider) → payment_provider_customers(id, provider)` | No |
| `provider_payment_method_id` | `string(191)` | n/a | No | — | `UNIQUE(provider, provider_payment_method_id)` | Stripe PaymentMethod id, token reference only | No |
| `type` | `string(24)`, enum-backed (`PaymentInstrumentType`, new) | n/a | No | `card` | — | — | No |
| `brand` | `string(24)`, nullable | n/a | Yes | `NULL` | — | display metadata only | No |
| `last_four` | `string(4)`, nullable | n/a | Yes | `NULL` | — | display metadata only | No |
| `expiry_month` | `unsigned tinyint`, nullable | unsigned | Yes | `NULL` | — | card only | No |
| `expiry_year` | `unsigned smallint`, nullable | unsigned | Yes | `NULL` | — | card only | No |
| `is_default` | `boolean` | n/a | No | `false` | — | one-default-per-provider-customer (locked update) | No |
| `status` | `string(16)`, enum-backed (`PaymentInstrumentStatus`, new) | n/a | No | `active` | — | `active` \| `detached` | No |
| `created_at` | `timestamp` | n/a | No | `now()` | — | — | No |
| `detached_at` | `timestamp`, nullable | n/a | Yes | `NULL` | — | never deleted, detach only | No |

Sole write authority: `PaymentInstrumentManager`. No raw card number, CVC, or full PAN is ever stored — only Stripe's own token reference plus safe display metadata.

### 21.3 `business_funding_attempts`

| Column | Type | Signed | Nullable | Default | Index/Constraint | FK/Delete | Encrypted/Sensitive |
|---|---|---|---|---|---|---|---|
| `id` | `bigint unsigned` | n/a | No | auto-increment | PK | — | No |
| `business_id` | `unsignedBigInteger` | unsigned | No | — | composite FK (with `wallet_id`) | composite-protected, `(wallet_id, business_id) → business_usage_wallets(id, business_id)` | No |
| `wallet_id` | `unsignedBigInteger` | unsigned | No | — | composite FK (with `business_id`) | composite-protected | No |
| `purpose` | `string(24)`, enum-backed (`FundingAttemptPurpose`, new) | n/a | No | — | — | `manual_top_up` \| `auto_recharge` \| `addon_purchase` (inert at M3 — no M3 code path ever sets `addon_purchase`) | No |
| `payer_type_snapshot` | `string(16)`, enum-backed (`PayerType`, reused from M2) | n/a | No | — | — | — | No |
| `billing_contact_name_snapshot` | `string(191)`, nullable | n/a | Yes | `NULL` | — | frozen, never re-derived | No |
| `billing_contact_email_snapshot` | `string(191)`, nullable | n/a | Yes | `NULL` | — | frozen, never re-derived | No |
| `provider_customer_external_id_snapshot` | `string(191)` | n/a | No | — | — | Stripe Customer id at attempt time | No |
| `provider_customer_id` | `unsignedBigInteger` | unsigned | No | — | — | `payment_provider_customers.id`, `restrictOnDelete()`, traceability only | No |
| `payment_method_display_snapshot` | `string(64)` | n/a | No | — | — | e.g. `"visa •••• 4242, exp 12/26"` | No |
| `requesting_actor_user_id` | `unsigned bigint`, nullable | unsigned | Yes | `NULL` | — | no FK; `NULL` for `purpose=auto_recharge` | No |
| `expected_currency_id` | `unsignedBigInteger` | unsigned | No | — | — | `currencies.id`, `restrictOnDelete()` | No |
| `expected_amount_micro` | `bigint` | signed | No | — | — | — | No |
| `local_idempotency_key` | `string(191)` | n/a | No | — | `UNIQUE` | deterministic outbound-call key | No |
| `provider_session_or_intent_reference` | `string(191)`, nullable | n/a | Yes | `NULL` | `UNIQUE` | resolves to exactly one local attempt once populated | No |
| `state` | `string(16)`, enum-backed (`FundingAttemptState`, new) | n/a | No | `created` | — | `created`\|`provider_pending`\|`requires_action`\|`processing`\|`succeeded`\|`failed`\|`canceled`\|`refunded`\|`disputed` (M3 never writes `refunded`/`disputed`) | No |
| `failure_reason` | `text`, nullable | n/a | Yes | `NULL` | — | — | No |
| `created_at` | `timestamp` | n/a | No | `now()` | — | — | No |
| `updated_at` | `timestamp` | n/a | No | `now()` | — | mutable — full transition history in the transitions table | No |

Sole write authority: `UsageBillingCheckoutManager` (provider-facing leg) working through `UsageWalletManager`'s existing ledger-write methods for the actual wallet-crediting effect (§3.11) — no other class writes this table.

### 21.4 `business_funding_attempt_transitions`

| Column | Type | Signed | Nullable | Default | Index/Constraint | FK/Delete | Encrypted/Sensitive |
|---|---|---|---|---|---|---|---|
| `id` | `bigint unsigned` | n/a | No | auto-increment | PK | — | No |
| `funding_attempt_id` | `unsignedBigInteger` | unsigned | No | — | — | `business_funding_attempts.id`, `restrictOnDelete()` | No |
| `from_state` | `string(16)`, enum-backed (`FundingAttemptState`, reused) | n/a | No | — | — | — | No |
| `to_state` | `string(16)`, enum-backed (`FundingAttemptState`, reused) | n/a | No | — | — | — | No |
| `source` | `string(24)`, enum-backed (`TransitionSource`, new) | n/a | No | — | — | `sync_response`\|`webhook_event`\|`admin_action`\|`reconciliation_job` | No |
| `provider_event_id` | `unsignedBigInteger`, nullable | unsigned | Yes | `NULL` | — | `payment_provider_events.id`, no `restrictOnDelete()` stated by RFC (plain nullable FK) | No |
| `actor_user_id` | `unsigned bigint`, nullable | unsigned | Yes | `NULL` | — | no FK | No |
| `created_at` | `timestamp` | n/a | No | `now()` | — | append-only | No |

Sole write authority: `UsageBillingCheckoutManager`.

### 21.5 `payment_provider_events`

| Column | Type | Signed | Nullable | Default | Index/Constraint | FK/Delete | Encrypted/Sensitive |
|---|---|---|---|---|---|---|---|
| `id` | `bigint unsigned` | n/a | No | auto-increment | PK | — | No |
| `provider` | `string(16)`, enum-backed (`PaymentProvider`, reused) | n/a | No | `stripe` | — | — | No |
| `provider_event_id` | `string(191)` | n/a | No | — | `UNIQUE(provider, provider_event_id)` | Stripe's own event id | No |
| `event_type` | `string(64)` | n/a | No | — | — | e.g. `payment_intent.succeeded` | No |
| `provider_object_id` | `string(191)` | n/a | No | — | — | the Checkout Session/PaymentIntent/SetupIntent id | No |
| `payload_encrypted` | `LONGTEXT`, nullable | n/a | Yes | — | — | **`encrypted` Laravel cast** — first real use in this repository (§3.9); purged after retention | **Yes — encrypted at rest** |
| `payload_hash` | `string(64)` | n/a | No | — | — | SHA-256 of the raw verified payload, permanent, survives purge | No |
| `payload_purged_at` | `timestamp`, nullable | n/a | Yes | `NULL` | — | set when `payload_encrypted` is nulled | No |
| `state` | `string(16)`, enum-backed (`ProviderEventState`, new) | n/a | No | `received` | — | `received`\|`processing`\|`processed`\|`failed`\|`ignored`\|`disposed` | No |
| `attempts` | `unsigned smallint` | unsigned | No | `0` | — | incremented on every claim | No |
| `processing_started_at` | `timestamp`, nullable | n/a | Yes | `NULL` | — | set on every claim | No |
| `lease_expires_at` | `timestamp`, nullable | n/a | Yes | `NULL` | — | `NULL` outside `processing` | No |
| `last_attempt_at` | `timestamp`, nullable | n/a | Yes | `NULL` | — | set on every claim | No |
| `last_error` | `text`, nullable | n/a | Yes | `NULL` | — | non-sensitive classification only | No |
| `received_at` | `timestamp` | n/a | No | `now()` | — | — | No |
| `completed_at` | `timestamp`, nullable | n/a | Yes | `NULL` | — | set for both `processed` and `ignored` | No |
| `disposed_at` | `timestamp`, nullable | n/a | Yes | `NULL` | — | set on terminal disposition | No |
| `disposed_by_user_id` | `unsigned bigint`, nullable | unsigned | Yes | `NULL` | — | no FK; `NULL` if automated | No |
| `disposition_note` | `text`, nullable | n/a | Yes | `NULL` | — | non-sensitive resolution note only | No |

Sole write authority: `UsageBillingCheckoutManager`. **No generated active-owner column, no circular FK, no ambiguous provider identity** — `provider_object_id` is informational context only, never itself a uniqueness key (`provider_event_id` is the sole uniqueness guard).

**Migration order (DDL before data, matching M1/M2's own established discipline):** 1. `create_payment_provider_customers_table`. 2. `create_business_payment_instruments_table` (depends on 1's `UNIQUE(id, provider)`). 3. `create_business_funding_attempts_table` (depends on 1, and on M1's `business_usage_wallets`/`currencies`). 4. `create_business_funding_attempt_transitions_table` (depends on 3). 5. `create_payment_provider_events_table` (no FK dependency on 1–4; `provider_event_id`'s referencing from transition tables is a plain nullable FK, not the reverse). No data-only migration is needed at M3 — no backfill creates a Stripe customer eagerly (§22).

**Rollback safety** — each `down()` is `Schema::dropIfExists()`, in reverse order, matching M1/M2's exact pattern; no `down()` performs a network call of any kind.

**No shorthand, grouped fields, float money, native ENUM, circular FK, or ambiguous provider identity** — confirmed by the fully-expanded tables above, matching RFC §11–§23's own "every remaining shorthand expanded" v1.4 correction discipline exactly.

---

## 22. Initialization and backfill

- **Existing payer assignments** — no M3 change; M2's `business_payer_assignments` remain exactly as they are.
- **Provider-customer rows** — **not** created eagerly for every existing Business/Workspace during migration/backfill. No migration or backfill calls Stripe. A `payment_provider_customers` row is created only lazily, the first time `PaymentInstrumentManager::resolveProviderCustomer()` is actually invoked by an authorized payment-setup action (§10 step 3) — exactly matching this contract's own explicit instruction and the RFC's own "creation upon an authorized payment setup action" preference.
- **Payment instruments** — none exist until a customer/Workspace owner actually completes a SetupIntent; no backfill of any kind.
- **Auto-recharge configuration** — no change; `auto_recharge_enabled` remains `false` (M1 default) for every existing wallet; M3 introduces no new backfill for this column.
- **Existing wallet state** — untouched; M3 adds no new wallet column.

**No migration or backfill may call Stripe** — enforced structurally: none of the five M3 migrations perform any HTTP call, confirmed by their own `up()`/`down()` bodies containing schema DDL only.

---

## 23. Exact implementation allowlist

**Closed, numbered, path-level. Any additional path required during implementation is a STOP-and-report condition (§27).**

### Migrations (5 new)

1. `database/migrations/{impl_date}_create_payment_provider_customers_table.php`
2. `database/migrations/{impl_date}_create_business_payment_instruments_table.php`
3. `database/migrations/{impl_date}_create_business_funding_attempts_table.php`
4. `database/migrations/{impl_date}_create_business_funding_attempt_transitions_table.php`
5. `database/migrations/{impl_date}_create_payment_provider_events_table.php`

### Enums (8 new)

6. `app/Enums/Usage/PaymentProvider.php`
7. `app/Enums/Usage/PaymentInstrumentType.php`
8. `app/Enums/Usage/PaymentInstrumentStatus.php`
9. `app/Enums/Usage/ProviderCustomerStatus.php`
10. `app/Enums/Usage/FundingAttemptPurpose.php`
11. `app/Enums/Usage/FundingAttemptState.php`
12. `app/Enums/Usage/TransitionSource.php`
13. `app/Enums/Usage/ProviderEventState.php`

### Value objects / DTOs (9 new)

14. `app/Library/Usage/ProviderCustomerResult.php`
15. `app/Library/Usage/SetupIntentResult.php`
16. `app/Library/Usage/PaymentMethodResult.php`
17. `app/Library/Usage/PaymentIntentResult.php`
18. `app/Library/Usage/WebhookVerificationResult.php`
19. `app/Library/Usage/FundingAttemptResult.php`
20. `app/Library/Usage/AutoRechargeEvaluationResult.php`
21. `app/Library/Usage/WebhookClaimResult.php`
22. `app/Library/Usage/FundingBillingSnapshot.php`

### Models (5 new)

23. `app/Models/PaymentProviderCustomer.php`
24. `app/Models/BusinessPaymentInstrument.php`
25. `app/Models/BusinessFundingAttempt.php`
26. `app/Models/BusinessFundingAttemptTransition.php`
27. `app/Models/PaymentProviderEvent.php`

### Repository contracts (5 new)

28. `app/Repositories/Contracts/PaymentProviderCustomerRepository.php`
29. `app/Repositories/Contracts/BusinessPaymentInstrumentRepository.php`
30. `app/Repositories/Contracts/BusinessFundingAttemptRepository.php`
31. `app/Repositories/Contracts/BusinessFundingAttemptTransitionRepository.php`
32. `app/Repositories/Contracts/PaymentProviderEventRepository.php`

### Eloquent repositories (5 new)

33. `app/Repositories/Eloquent/EloquentPaymentProviderCustomerRepository.php`
34. `app/Repositories/Eloquent/EloquentBusinessPaymentInstrumentRepository.php`
35. `app/Repositories/Eloquent/EloquentBusinessFundingAttemptRepository.php`
36. `app/Repositories/Eloquent/EloquentBusinessFundingAttemptTransitionRepository.php`
37. `app/Repositories/Eloquent/EloquentPaymentProviderEventRepository.php`

### Gateway interface/adapters/fakes (4 new)

38. `app/Library/Usage/Contracts/PaymentProviderGateway.php`
39. `app/Library/Usage/StripePaymentProviderGateway.php`
40. `app/Library/Usage/FakePaymentProviderGateway.php`
41. `app/Library/Usage/StripeApiVersion.php` (a tiny, single-purpose constant/value holder for the pinned API version string, kept out of the gateway class itself for easy test-time reference)

### Managers (2 new)

42. `app/Library/Usage/PaymentInstrumentManager.php`
43. `app/Library/Usage/UsageBillingCheckoutManager.php` — narrowly scoped per §3.11/§8; no additional-slot/add-on responsibility.

### Events/jobs/listeners (6 new)

44. `app/Events/Usage/BusinessFundingAttemptSucceeded.php`
45. `app/Events/Usage/BusinessFundingAttemptFailed.php`
46. `app/Jobs/Usage/EvaluateBusinessAutoRecharge.php`
47. `app/Jobs/Usage/ProcessPaymentProviderEvent.php`
48. `app/Jobs/Usage/PurgeExpiredWebhookPayloads.php`
49. `app/Jobs/Usage/ReconcileProviderPendingState.php`

### Exceptions (7 new)

50. `app/Exceptions/Usage/ProviderAuthenticationException.php`
51. `app/Exceptions/Usage/ProviderRateLimitException.php`
52. `app/Exceptions/Usage/ProviderCardDeclinedException.php`
53. `app/Exceptions/Usage/ProviderInvalidRequestException.php`
54. `app/Exceptions/Usage/ProviderApiUnavailableException.php`
55. `app/Exceptions/Usage/WebhookSignatureVerificationException.php`
56. `app/Exceptions/Usage/FundingAttemptNotResumableException.php`

### Configuration (1 new)

57. `config/usage_billing.php` — the new `usage_billing.webhook_event.*` keys (§19); `config/services.php`'s existing `stripe` array is *modified*, not created new (item 87 below).

### Routes/controllers/FormRequests (9 new)

58. `app/Http/Controllers/Customer/Business/UsageBillingPaymentMethodController.php` (SetupIntent creation, attach confirmation, detach)
59. `app/Http/Controllers/Customer/Business/UsageBillingTopUpController.php` (initiate top-up, return/cancel handling)
60. `app/Http/Controllers/Customer/Business/UsageBillingAutoRechargeController.php` (configure auto-recharge)
61. `app/Http/Controllers/StripeWebhookController.php` (unauthenticated, signature-verified)
62. `app/Http/Controllers/Admin/PaymentProviderEventController.php` (exhausted-event review/disposition)
63. `app/Http/Requests/Customer/Business/InitiateTopUpRequest.php`
64. `app/Http/Requests/Customer/Business/ConfigureAutoRechargeRequest.php`
65. `app/Http/Requests/Customer/Business/CreateSetupIntentRequest.php`
66. `app/Http/Requests/Admin/DispositionPaymentProviderEventRequest.php`

### Views/navigation (2 new)

67. `resources/views/customer/business/usage-billing/partials/payment-method.blade.php`
68. `resources/views/admin/usage-billing/provider-events/index.blade.php`

### Backfill/support (0 new)

*(None — §22 explicitly authorizes no backfill script; a fake gateway is a test double, item 40 above, not a "support" script in the M1/M2 sense.)*

### Composer paths (0 — no upgrade proven necessary, §3.1)

*(None.)*

### Tests (30 new + 4 modified M1/M2 regression files)

69. `tests/Unit/Usage/PaymentProviderEnumsTest.php`
70. `tests/Feature/Usage/PaymentProviderCustomerSchemaTest.php`
71. `tests/Feature/Usage/BusinessPaymentInstrumentSchemaTest.php`
72. `tests/Feature/Usage/BusinessFundingAttemptSchemaTest.php`
73. `tests/Feature/Usage/BusinessFundingAttemptTransitionSchemaTest.php`
74. `tests/Feature/Usage/PaymentProviderEventSchemaTest.php`
75. `tests/Feature/Usage/StripePaymentProviderGatewayCompatibilityTest.php`
76. `tests/Feature/Usage/FakePaymentProviderGatewayTest.php`
77. `tests/Feature/Usage/SetupIntentAuthorizationTest.php`
78. `tests/Feature/Usage/ProviderCustomerOwnershipTest.php`
79. `tests/Feature/Usage/PaymentInstrumentAttachDetachTest.php`
80. `tests/Feature/Usage/TopUpStateMachineTest.php`
81. `tests/Feature/Usage/FundingAttemptPayerConsentTest.php`
82. `tests/Feature/Usage/AmountCurrencyConversionTest.php`
83. `tests/Feature/Usage/StripeAmountMinMaxValidationTest.php`
84. `tests/Feature/Usage/WebhookSignatureRejectionTest.php`
85. `tests/Feature/Usage/WebhookDuplicateEventReplayTest.php`
86. `tests/Feature/Usage/WebhookStaleLeaseRecoveryTest.php`
87. `tests/Feature/Usage/WebhookClaimExhaustionTest.php`
88. `tests/Feature/Usage/WebhookEventDispositionAndPurgeTest.php`
89. `tests/Feature/Usage/WebhookMetadataSpoofMismatchTest.php`
90. `tests/Feature/Usage/WebhookAmountCurrencyCustomerMismatchTest.php`
91. `tests/Feature/Usage/FundingAttemptExactlyOnceWalletCreditTest.php`
92. `tests/Feature/Usage/RedirectBeforeWebhookConfirmationTest.php`
93. `tests/Feature/Usage/ConcurrentTopUpConcurrencyTest.php`
94. `tests/Feature/Usage/AutoRechargeThresholdAndCapTest.php`
95. `tests/Feature/Usage/AutoRechargeLoopPreventionTest.php`
96. `tests/Feature/Usage/AutoRechargeFailedPaymentRetryTest.php`
97. `tests/Feature/Usage/PayerChangeDuringPendingAttemptTest.php`
98. `tests/Feature/Usage/InstrumentDetachDuringPendingChargeTest.php`
99. `tests/Feature/Usage/CrossBusinessPaymentIsolationTest.php`

### Modified M1/M2 regression paths (4 — all pre-existing, none new)

100. `app/Library/Usage/UsageWalletManager.php` — **modified, not new**: exactly the `EvaluateBusinessAutoRecharge::dispatch()` addition at the two existing negative-`available_delta_micro` ledger-insert sites (`reserve()`'s reservation insert, `commit()`'s overage-charge insert), each an additive one-line call plus its constructor-injection-free static dispatch (no new constructor dependency required — the job resolves `UsageWalletManager`/`PaymentInstrumentManager`/`UsageBillingCheckoutManager` itself via the container). No existing method signature, existing business logic, or existing M1/M2 test-covered behavior changes. §15.
101. `config/services.php` — **modified, not new**: the existing `'stripe'` array gains exactly the `mode`/`api_version` keys (§19); every existing key (`key`, `secret`, `webhook.secret`, `webhook.tolerance`) is unchanged in name, position, or default.
102. `app/Http/Middleware/VerifyCsrfToken.php` — **modified, not new**: exactly one additive entry to the existing `$except` array for the new Stripe webhook route's exact URI pattern (§3.9). No existing `$except` entry is added, removed, reordered, or altered.
103. `app/Providers/AppServiceProvider.php` — **modified, not new**: exactly the new repository/gateway/manager bindings this milestone's classes require (5 repository pairs + `PaymentProviderGateway => StripePaymentProviderGateway`), appended as one new contiguous group immediately following the M2 Usage group, matching M1/M2's own established binding-block convention exactly. No existing binding line changes.

**Counts:** 5 migrations + 8 enums + 9 value objects + 5 models + 5 repository contracts + 5 Eloquent repositories + 4 gateway files + 2 managers + 6 events/jobs + 7 exceptions + 1 configuration file + 9 HTTP files + 2 views = **68 new production**. + 4 modified production (items 100–103) = **72 production total.** 31 new tests (items 69–99) + 0 modified tests = **31 test total.** **72 + 31 = 103 total paths.**

Any path discovered necessary beyond this list during implementation is a STOP-and-report condition (§27) — the stop threshold is any required **104th path**.

---

## 24. Mechanical searches

Run from repository root, PHP 8.3.30, against `ultimatesms_testing` only, once implementation exists:

1. `grep -rln "Stripe\\\\" app --include="*.php" | grep -v "app/Library/Usage/StripePaymentProviderGateway.php\|app/Repositories/Eloquent/Eloquent\(Account\|Keyword\|PhoneNumber\|SenderID\|Subscription\)Repository.php"` → zero matches — every `Stripe\*` SDK reference lives either in the new gateway or the five already-existing (unmodified) legacy repositories, never anywhere new.
2. `grep -rn "STRIPE_SECRET\|STRIPE_WEBHOOK_SECRET\|sk_test_\|sk_live_\|whsec_" app resources tests --include="*.php" --include="*.blade.php"` → zero matches outside `config/services.php`'s own `env()` calls (no literal secret value, no secret-shaped variable name leaking into a log/exception/view).
3. `grep -rn "getPayload\|rawPayload\|payload_encrypted" resources/views app/Http/Controllers/Admin --include="*.php" --include="*.blade.php"` → zero matches rendering a raw provider payload; the admin event view (item 68) displays only non-sensitive columns.
4. `grep -rn "constructEvent\|verifyWebhookSignature" app --include="*.php"` → exactly the calls inside `StripePaymentProviderGateway.php`/`StripeWebhookController.php`, confirming no code path processes a webhook body before signature verification.
5. `grep -rn "PaidTopUp\|AutoRecharge" app/Library/Usage/UsageBillingCheckoutManager.php` cross-checked against `app/Http/Controllers/StripeWebhookController.php` and the synchronous confirmation-retrieval code paths → every ledger-crediting call site is preceded, in the same call graph, by either a webhook-verified event or an independent `retrievePaymentIntent()`/`retrieveSetupIntent()` call — never a bare browser-redirect handler.
6. `grep -rn "app_subject_kind\|event_type" app/Http/Controllers/StripeWebhookController.php app/Library/Usage/UsageBillingCheckoutManager.php` → confirms local-record resolution branches on the metadata hint, never on `event_type`.
7. `grep -rn "(float)\|(double)\|floatval" app/Library/Usage/PaymentInstrumentManager.php app/Library/Usage/UsageBillingCheckoutManager.php app/Library/Usage/StripePaymentProviderGateway.php` → zero matches (no float money).
8. `grep -rn "->enum(" database/migrations/*payment_provider* database/migrations/*business_funding_attempt* database/migrations/*business_payment_instrument*` → zero matches (no native `ENUM`).
9. `grep -rln "DB::table('payment_provider_customers'\|DB::table('business_payment_instruments'\|DB::table('business_funding_attempts'\|DB::table('business_funding_attempt_transitions'\|DB::table('payment_provider_events'" app --include="*.php" | grep -v "Repositories/Eloquent\|database/migrations"` → zero matches (no raw M3-table access outside repositories/migrations).
10. `git diff --stat -- routes/customer.php` (against the M3 base SHA) shows only additive lines for the new customer routes; `git diff --stat -- app/Http/Controllers/Customer/PaymentController.php app/Models/PaymentMethods.php app/Repositories/Eloquent/EloquentAccountRepository.php app/Repositories/Eloquent/EloquentKeywordRepository.php app/Repositories/Eloquent/EloquentPhoneNumberRepository.php app/Repositories/Eloquent/EloquentSenderIDRepository.php app/Repositories/Eloquent/EloquentSubscriptionRepository.php` → empty (no legacy payment file touched).
11. `git diff --stat -- database/migrations` cross-checked for any M4-named table (`additional_business_slot*`, `business_usage_addon*`) → zero matches (no M4 slot/add-on implementation).
12. `grep -rn "is_metered.*true\|activateMetering" database/migrations app/Library/Usage/PaymentInstrumentManager.php app/Library/Usage/UsageBillingCheckoutManager.php` → zero matches (no M5 metering change).
13. `php artisan test tests/Unit/Usage tests/Feature/Usage` (pre-existing M1/M2 files only, excluding M3's own new files) → unchanged pass count from the M2 implementation's own last confirmed regression-gate result, proving M1/M2 invariants unaffected.
14. `grep -n "platform_feature_unknown\|platform_feature_unavailable\|workspace_plan_unassigned\|denied_by_workspace_override\|not_entitled_by_plan\|disabled_for_business\|plan_suspended\|plan_inactive\|usage_unauthorized" app/Library/Entitlement/EntitlementManager.php | wc -l` → `15` (unchanged from every prior round).
15. `git diff --stat app/Library/Entitlement/EntitlementManager.php` → empty (unmodified).
16. Final changed-path set (`git diff --name-only` + `git ls-files --others --exclude-standard`, sorted, `{impl_date}` normalized in the five migration filenames) equals §23's exact 103-path list.

Run `git diff --check` as part of every gate.

---

## 25. Test contract

Every file named in §23's Tests section, with its required proof:

- **69 `PaymentProviderEnumsTest`** — all 8 new enums' exact case sets and backing values.
- **70–74 (schema, 5 files)** — every column type/nullable/default/index exactly as §21; the composite FK on `business_payment_instruments` rejects a provider-mismatched insert at the database level; `payment_provider_events.payload_encrypted` round-trips correctly through the `encrypted` cast.
- **75 `StripePaymentProviderGatewayCompatibilityTest`** — confirms the installed SDK (`v7.128.0`) exposes every method `StripePaymentProviderGateway` calls, without a live network call (constructor/method-existence-shaped assertions, or a fully mocked HTTP client at the SDK's own transport boundary — never a real Stripe API call).
- **76 `FakePaymentProviderGatewayTest`** — the fake gateway's own deterministic behavior (configurable success/decline/requires_action responses) is itself tested, proving it is a faithful stand-in for the real gateway's return-shape contract.
- **77 `SetupIntentAuthorizationTest`** — the full payer-consent matrix for SetupIntent creation (§17); an unrelated actor is denied; a platform administrator cannot originate one.
- **78 `ProviderCustomerOwnershipTest`** — Business-owned vs. Workspace-owned isolation; no cross-tenant reuse; `UNIQUE(provider, active_business_id)`/`UNIQUE(provider, active_workspace_id)` enforcement including the detach-then-recreate case.
- **79 `PaymentInstrumentAttachDetachTest`** — attach validation (provider customer match), detach (never a hard delete), one-default-per-provider-customer locking.
- **80 `TopUpStateMachineTest`** — every state transition, append-only transition-row creation, idempotent repeat-commit no-op.
- **81 `FundingAttemptPayerConsentTest`** — the payer-consent split for top-up initiation, including the narrowed platform-administrator posture (resume-only, never origination) — directly asserting both the denial and the resume-success cases.
- **82 `AmountCurrencyConversionTest`** — `bcRoundHalfUp()` correctness at representative and boundary values, currency-exponent-parametric (never hard-coded to USD's own divisor).
- **83 `StripeAmountMinMaxValidationTest`** — Stripe's documented minimum and eight-digit maximum are both enforced before every outbound call.
- **84 `WebhookSignatureRejectionTest`** — an invalid/missing signature is rejected before any row is inserted or any mutation occurs.
- **85 `WebhookDuplicateEventReplayTest`** — a genuine Stripe redelivery of an already-`processed` event is absorbed as a no-op via `UNIQUE(provider, provider_event_id)`.
- **86 `WebhookStaleLeaseRecoveryTest`** — a stale `processing` lease is reclaimed, bounded by `attempts < 5`, via forced-race two-worker contention (causal barrier, §16 — never elapsed-time).
- **87 `WebhookClaimExhaustionTest`** — a payload that reliably fails every claim is reclaimed at most 5 times, then becomes permanently unreclaimed and surfaces in the exhausted-events queue.
- **88 `WebhookEventDispositionAndPurgeTest`** — an exhausted event can be dispositioned only from an exhausted state; a disposed event never re-enters processing under any circumstance (direct assertion against the claim `WHERE` clause); a disposed, past-retention-window event's payload is purged while `id`/`provider_event_id`/`payload_hash`/`state`/`attempts`/timestamps/`disposed_by_user_id`/`disposition_note` remain intact; a merely-exhausted-but-undispositioned event is **not** purged even past the same window.
- **89 `WebhookMetadataSpoofMismatchTest`** — missing/malformed/unknown/ambiguous/mismatched metadata produces zero mutation, marks the event `failed`, routes to reconciliation.
- **90 `WebhookAmountCurrencyCustomerMismatchTest`** — a verified Stripe object whose amount/currency/customer disagrees with the loaded local record's own frozen expectations produces zero mutation.
- **91 `FundingAttemptExactlyOnceWalletCreditTest`** — two independent confirmation paths (synchronous retrieval + a later webhook) for the same successful attempt produce exactly one `PaidTopUp`/`AutoRecharge` ledger entry, never two.
- **92 `RedirectBeforeWebhookConfirmationTest`** — a browser return alone, with no webhook and no successful synchronous retrieval, never credits the wallet; the UI renders `pending`, never a fabricated success.
- **93 `ConcurrentTopUpConcurrencyTest`** — two simultaneous top-up initiations for the same Business each independently produce exactly one correct ledger effect once confirmed (causal-barrier concurrency, never elapsed-time), plus an unrelated-Business progress assertion.
- **94 `AutoRechargeThresholdAndCapTest`** — threshold-crossing triggers evaluation; the monthly cap denies a recharge that would exceed it; no default value is asserted anywhere in this file (every fixture supplies its own explicit threshold/amount/cap).
- **95 `AutoRechargeLoopPreventionTest`** — a successful `AutoRecharge` ledger entry (positive `available_delta_micro`) never re-triggers `EvaluateBusinessAutoRecharge`.
- **96 `AutoRechargeFailedPaymentRetryTest`** — `consecutive_recharge_failures` increments on failure, resets on success; an outstanding in-flight auto-recharge attempt prevents a duplicate concurrent one (forced-race).
- **97 `PayerChangeDuringPendingAttemptTest`** — an in-flight attempt's frozen `payer_type_snapshot` is unaffected by a concurrent payer change; a new attempt under the old payer's authority is blocked once the change commits.
- **98 `InstrumentDetachDuringPendingChargeTest`** — detaching an instrument referenced by an already-`provider_pending` attempt does not retroactively fail that attempt; it does block that instrument from any new attempt.
- **99 `CrossBusinessPaymentIsolationTest`** — Business A's instruments/funding history/provider-event status are never visible from Business B's own dashboard request or repository lookup, even within the same Workspace, mirroring `CrossBusinessBillingIsolationTest`'s own M2 pattern exactly.

**Every test above uses `FakePaymentProviderGateway` (or an equivalently fully-faked/mocked provider boundary) — zero live Stripe network access in any automated test or regression gate.**

**Six mandatory regression gates** (`ultimatesms_testing` only, never a predicted count, every count reported only after the gate actually runs):

1. `php artisan test tests/Unit/Usage tests/Feature/Usage` — RFC-005/M3-focused (includes every M1/M2 Usage test unmodified, plus all 30 new M3 files).
2. `php artisan test tests/Unit/Entitlement tests/Feature/Entitlement` — Entitlement.
3. `php artisan test tests/Unit/Workspace tests/Feature/Workspace` — Workspace.
4. `php artisan test tests/Feature/Business` — Business.
5. `php artisan test tests/Feature/Opportunity` — Opportunity.
6. `php artisan test --stop-on-failure` — full suite.

Gates must be run **sequentially, never concurrently against the shared test database** — a lesson directly re-confirmed by this same engagement's own M2 resume round, where two gates run in the background/foreground simultaneously against `ultimatesms_testing` produced a spurious "migrations table doesn't exist" collision, a process-management artifact, not a code defect, resolved only by re-running sequentially.

---

## 26. Acceptance criteria and implementation sequence

**Acceptance criteria:**

1. All 5 tables in §21 exist, migrated, with exactly the columns/types/constraints specified — proven by tests 70–74.
2. No direct `Stripe\*` SDK reference exists outside `StripePaymentProviderGateway.php` — proven by mechanical search 1.
3. No webhook mutation occurs before signature verification — proven by test 84 and mechanical search 4.
4. `event_type` never determines local billing purpose; only the metadata hint does — proven by tests 89–90 and mechanical search 6.
5. Wallet credit occurs effectively exactly once per successful attempt, under both single and duplicate/racing confirmation paths — proven by tests 91, 93.
6. The claim/lease/exhaustion/disposition algorithm matches RFC §21 verbatim — proven by tests 86–88.
7. Auto-recharge is after-commit, provider-call-free inside any wallet transaction, loop-free, and bounded by cap/threshold with no invented default — proven by tests 94–96 and mechanical search 5.
8. No secret value is ever logged, rendered, or committed — proven by mechanical search 2.
9. No M4/M5 concept is implemented — proven by mechanical searches 11–12.
10. `EntitlementManager.php` and the nine RFC-004 denial keys are unchanged — proven by mechanical searches 14–15 and regression gate 2.
11. All six regression gates pass with exact reported counts, run sequentially.
12. The final changed-path set equals §23's 103 paths exactly — proven by mechanical search 16.
13. The local test-mode preview (§20) is verified working, using only test-mode keys and a non-production database/URL.

**Implementation sequence:**

1. Configuration, enums, value objects (§23 items 6–22, 57).
2. Migrations (items 1–5), run and roll back cleanly against `ultimatesms_testing`.
3. Models, repository contracts/implementations (items 23–37).
4. Provider boundary and fake (items 38–41).
5. Managers and state machines (items 42–43).
6. Webhook verification/persistence/claim-lease algorithm (part of items 43, 47, 61).
7. Jobs, events, listeners (items 44–49).
8. Authorization (§17, enforced inside the manager methods and the controllers' role resolution).
9. HTTP/UI (items 58–68).
10. Tests (items 69–99), plus the four modified M1/M2 regression files (items 100–103).
11. Local test-mode preview preparation and verification (§20).
12. Mechanical searches (§24) and all six regression gates (§25), run sequentially.

---

## 27. Stop conditions

Future implementation must stop, leave the working tree unstaged, and report rather than proceed, if:

- Any path beyond §23's 103 is required (i.e., any required 104th path).
- Live credentials of any kind are needed for any step this contract authorizes.
- Production database access is required.
- Any unresolved financial default (auto-recharge threshold/amount, monthly cap, retention window) must be invented rather than left explicitly configured per-fixture/per-environment.
- Tax/legal behavior must be guessed — remains explicitly RFC §23's own separate legal gate.
- Provider settlement currency must be silently chosen beyond what the wallet's own already-resolved `currency_id` (M1) already determines.
- Webhook signature verification cannot be implemented with the installed SDK (`v7.128.0`) — not expected, but if discovered, implementation stops rather than working around it with an unverified-payload code path.
- Any legacy payment path (`PaymentController.php`, `PaymentMethods`, the five raw-Stripe-call repositories, any legacy route) must change.
- An RFC-004 amendment is required.
- Any additional-slot-agreement, add-on-purchase, or metered-feature-classification concept becomes necessary to satisfy a requirement believed to be M3 scope.
- Any of the six regression gates fails for a reason not fixable within the 103-path allowlist.
- `ultimatesms_testing` cannot be confirmed as the effective test database.
- A live Stripe API call, or a request to obtain/generate a live secret key, appears necessary for any reason.

---

## 28. Contract self-audit

1. **M3 test-mode and live-production readiness are separate** — §4 states both independently; contract status is `READY_FOR_TEST_MODE_IMPLEMENTATION` / `BLOCKED_FOR_LIVE_CHARGING`, never a single combined status.
2. **No live charging is authorized** — §0, §2, §4, §7, §19, §27 each independently restate this; no code path this contract authorizes can reach a live Stripe endpoint (mode fails closed, §19).
3. **Every open decision is classified** — §5's table covers all 14 RFC-005 items explicitly, none silently skipped.
4. **No financial default is invented** — §5 items 4/12, §15, §19 (`retention_days`), all explicitly require configuration, never a hard-coded value.
5. **Every provider call is behind the gateway** — §8, enforced by mechanical search 1.
6. **Webhooks verify signatures before trust** — §13 steps 1–2, enforced by mechanical search 4.
7. **Metadata is only a routing hint** — §13 steps 7–10, enforced by mechanical search 6.
8. **Wallet credit occurs once after authoritative confirmation** — §11 steps 7–11, §13 step 13, proven by test 91.
9. **Auto-recharge is after-commit and bounded** — §15, proven by tests 94–96.
10. **Provider/customer/instrument ownership is isolated** — §9, proven by tests 78, 99.
11. **Payload retention is bounded** — §14, no unbounded retention path exists; disposition is the sole gate to purge for exhausted events.
12. **M4/M5 scope is absent** — §7, §22 (no eager Stripe-customer creation), enforced by mechanical searches 11–12.
13. **Every path is individually allowlisted** — §23, numbered 1–103, all 103 authorized (the stop threshold is any required 104th path).
14. **Counts match** — §23: 72 production + 31 test = 103.
15. **Six gates are exact** — §25, identical shape to M1/M2's own six gates, sequential execution explicitly required.
16. **Local test-mode preview requirements are complete** — §20 covers URL, database, env-var names, Stripe CLI command, webhook URL, click path, test card numbers, expected states, cleanup, and an explicit no-live-no-production confirmation.
17. **Contract merge does not start implementation** — §0, stated explicitly, matching M1/M2's own identical restraint.
18. **No production/test file changed** — confirmed by §29's own verification commands before staging.

---

## 29. Verification and publication (this document only)

- `git diff --check` — clean.
- `git status --short` — exactly `?? docs/automation/RFC-005-M3-CONTRACT.md`.
- `git diff --name-only` — empty (untracked file, not a diff of a tracked one).
- `git diff --cached --name-only` — empty before staging.
- Stage the one file by its exact path only (never `git add -A`/`git add .`).
- Commit exactly: `docs: prepare RFC-005 Milestone 3 contract`.
- Push normally to `origin chore/rfc-005-m3-contract`. No force push. Do not push `main`.
- If `gh` is available, open a PR into `main`. Otherwise report the exact GitHub comparison URL.
- PHP tests are not required for this one-file docs-only change and are not run — reported honestly as not run, no count fabricated.

---

*End of RFC-005 Milestone 3 contract. Implementation requires a separate, explicit human instruction. This contract's own merge does not start it.*
