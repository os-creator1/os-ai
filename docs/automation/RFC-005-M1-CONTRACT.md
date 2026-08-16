# RFC-005 Milestone 1 Contract

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

This contract authorizes drafting one thing: a bounded RFC-005 Milestone 1 implementation branch/PR — the Wallet & Ledger Foundation. Human merge of this contract does **not** itself start implementation. A human must separately, explicitly instruct that M1 implementation begin, exactly as every RFC-003/RFC-004 milestone contract before it required.

## Governing document

[`docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md`](../rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md), **version 1.4 (Final Surgical Patch)**, confirmed by direct full read before drafting this contract. This contract reproduces v1.4's authoritative M1-scoped schema, algorithms, and milestone boundary (§36 item 1) — it does not redesign any of it. Where v1.4's own M1 milestone summary (§36) is a compressed bullet rather than a fully worked-out cross-milestone dependency graph, this contract resolves the resulting ordering questions narrowly and conservatively (§5.7 below), the same way the RFC-004 M1 contract resolved gaps its own governing RFC left compressed.

## Base/branch assumptions

- Contract-drafting branch: `chore/rfc-005-m1-contract`.
- Base/HEAD verified via `git rev-parse HEAD` before writing, working tree clean: `763e78f1156fa350962eb073dd2db872a8ef93ed` (`main`).
- RFC-005 design-document merge commit `0e74f199bcf13eaf86e0770858c13901323b0eab` (`docs: finalize RFC-005 surgical design fixes`, PR #71) confirmed an ancestor of this base via `git merge-base --is-ancestor`.
- After this contract is human-merged, the M1 implementation branch (`agent/rfc-005-m1`, following RFC-003/RFC-004's naming convention) is created from the then-current `main` containing this contract's merge, only after a separate explicit human instruction to begin implementation.

---

## Correction Round 1 record

This round corrected two independently verified defects in this contract's initial draft, under the normal `maximum_correction_rounds: 2` correction discipline (this is correction round 1 of at most 2 — not a governance exception):

1. **Silent default-currency decision removed** (§5.5, §5.8, §6.1, §6.2, §6.7, §7, §8, §9.2, §9.3, §10.5, §12) — the initial draft resolved a wallet's `currency_id` via a new, invented `config('usage.default_currency_code')` platform-wide fallback, silently substituting a platform default whenever a Business's own currency was unresolvable. This risked a wallet being denominated in a currency different from the Business's own recorded `currency_code` with no operator visibility. Corrected: M1 wallet initialization now resolves the Business's own stored `currency_code` (normalized via the same `strtoupper()` rule `App\Models\Currency::boot()` already applies to every currency row, confirmed by direct read) against the authoritative `currencies` table, requiring exactly one active match; on any resolution failure (missing, malformed, unknown, inactive, or ambiguous/multiple-match), wallet initialization fails closed — no wallet, no ledger entry, no partial state — raising a specific typed exception and recording a retryable, non-sensitive failure. No platform-wide fallback currency is ever substituted. `config/usage.php` is removed from the allowlist entirely, since it existed only to support the now-removed fallback.
2. **Inert `EvaluateBusinessAutoRecharge` M1 stub removed** (§2, §3, §5.7, §8, §12, §13, §14, §15, §16) — the initial draft shipped an M1-scoped, intentionally-inert `App\Jobs\Usage\EvaluateBusinessAutoRecharge` stub, reasoning that RFC-005 §12's "any ledger-entry insert with `available_delta_micro < 0` dispatches `EvaluateBusinessAutoRecharge`" rule was an unconditional M1 obligation. Direct re-read of RFC-005 §36 found this reasoning wrong: §36 item 3 (M3) explicitly and unambiguously assigns **"Auto-recharge as the centralized after-commit trigger"** to Milestone 3, not M1. Corrected: M1's `reserve()`/`commit()`/`release()` algorithms no longer dispatch, reference, bind, or test `EvaluateBusinessAutoRecharge` in any form — the job class, its dispatch call, and its configuration are entirely M3's own responsibility to introduce (an ordinary incremental extension of M1-authored manager code by a later milestone, exactly the same accepted pattern RFC-005 §9/§32 already establishes for M2 extending the M1 listener). RFC-005 §12's own trigger statement is preserved unchanged as the correct final-state invariant M3 must satisfy — this contract does not weaken or delete it, only removes M1's premature, incorrect attempt to partially implement it.

---

## Correction Round 2 record (final)

This is **Correction Round 2 of the `maximum_correction_rounds: 2` limit — the final ordinary correction round.** No further correction round may be opened against this contract; any subsequent defect requires either a separately human-authorized exception (mirroring the RFC-005 design document's own remediation-exception precedent) or a fresh contract. This round corrected two independently verified defects discovered only once real implementation work began — both self-identified gaps in this contract's own text, not conflicts with RFC-005 itself:

1. **Listener-registration file omitted from the allowlist — added `app/Providers/EventServiceProvider.php`** (§5.4, §9.3, §10.5, §12, §13, §14, §15, §16). §9.3 always required `InitializeBusinessUsageProfile` to be "subscribed to both `BusinessCreated` and `BusinessAssignedToWorkspace`," but the contract never named the one file this repository's own established convention requires to make that subscription real: direct read of `app/Providers/EventServiceProvider.php` confirms this repository registers every listener through an explicit `$listen` array on that provider (`Registered::class => [SendEmailVerificationNotification::class]` is the sole existing entry) — no event auto-discovery is configured anywhere (`shouldDiscoverEvents()` is not overridden, so Laravel's default `false` applies). Confirmed by direct attempted implementation: the listener class alone, with no `$listen` entry, never fires. Corrected: `app/Providers/EventServiceProvider.php` is added to the allowlist, authorized for exactly two additive `$listen` mappings (`BusinessCreated::class => [InitializeBusinessUsageProfile::class]`, `BusinessAssignedToWorkspace::class => [InitializeBusinessUsageProfile::class]`) plus the two corresponding `use` imports — the existing `Registered`/`SendEmailVerificationNotification` mapping is untouched, and event discovery remains disabled.
2. **`updated_by_user_id` nullability contradiction resolved — nullable** (§6.4, §9.1, §14, §15). §6.4's schema table declared `platform_feature_usage_classifications.updated_by_user_id` `NOT NULL`, while §9.1's own backfill algorithm explicitly writes `updated_by_user_id = null` for the system-authored bootstrap row — an internal contradiction between the schema and the algorithm the schema is supposed to govern. Resolved in favor of the already-designed system-authored behavior, which is the more specific, deliberately-reasoned statement (citing the RFC-004 `complimentary_granted_by_user_id` nullable/no-FK precedent by name) rather than the schema table's apparent carry-over notation. `updated_by_user_id` is now specified nullable, `NULL` meaning exclusively "system/bootstrap/backfill-authored," never a fake or arbitrary actor.

---

## 1. Purpose

Implement RFC-005 §36 Milestone 1's exact scope: the **wallet and ledger data/domain foundation only** — seven authoritative tables, their models and enums, `UsageWalletManager`'s reserve/commit/release/expire/rate-activation/coarse-capacity surface, the real `UsageAuthorizationGateway` binding (behaviorally inert until a feature is ever metered — no feature is metered at M1), the Business-initialization listener (wallet-only at this milestone), a deterministic backfill for every Business existing before M1 deploys, and M1-scoped tests. M1 introduces **no** budgets/limits/safety-limit tables (M2), **no** payer/billing-contact tables (M2), **no** Stripe/payment-instrument/webhook code (M3), **no** additional-slot/add-on code (M4), **no** actually-metered feature (M5), and **no** HTTP/admin/customer surface of any kind.

---

## 2. Exact M1 scope

- Seven tables (§6 below): `business_usage_wallets`, `business_usage_rates`, `business_usage_rate_activations`, `platform_feature_usage_classifications`, `platform_feature_usage_classification_transitions`, `business_usage_reservations`, `business_usage_ledger_entries`.
- Four enums (§6.9 below): `WalletBillingStatus`, `RoundingRule`, `UsageReservationStatus`, `UsageLedgerEntryType`.
- Three new readonly value objects: `ReservationResult`, `CommitResult`, `UsageCapacityDecision`.
- Seven models, one per table.
- Seven repository contracts + seven Eloquent implementations, bound in the existing `AppServiceProvider` `$bindings` array.
- `App\Library\Entitlement\RealUsageAuthorizationGateway`, replacing `NullUsageAuthorizationGateway` in the `AppServiceProvider` binding (§11 — this is an explicit, narrow, behavior-preserving exception to "preserve Null," required because RFC-005 §36 M1 itself names this binding change).
- `App\Library\Usage\UsageWalletManager`, scoped exactly to the method surface named in RFC-005 §36 M1's own text (§10 below) — reserve/commit/release/expire, rate activation, coarse-capacity evaluation, wallet initialization. No credit/top-up/debt-clearing/billing-status-transition method (§5.7 explains why those are M2+, not M1).
- `App\Listeners\Usage\InitializeBusinessUsageProfile`, subscribed to both confirmed Business-creation events, calling only `UsageWalletManager::initializeWalletForNewBusiness()` at this milestone (RFC-005 §9/§32).
- `App\Jobs\Usage\ExpireStaleUsageReservations` (full M1 implementation). **`App\Jobs\Usage\EvaluateBusinessAutoRecharge` is not introduced at M1** — RFC-005 §36 explicitly assigns "auto-recharge as the centralized after-commit trigger" to M3 (§5.7, corrected this round).
- Backfill: a wallet for every Business existing before M1 deploys.
- M1-scoped tests (§14 below).

## 3. Exact out-of-scope

Not authorized in M1, matching RFC-005 §36's own milestone boundary exactly:

- `business_feature_usage_limits`, `platform_feature_usage_safety_limits`, `business_usage_limit_transitions`, `business_usage_wallet_billing_status_transitions`, `business_billing_contacts`, `business_payer_assignments`, `business_payer_transitions` — all M2 tables (RFC-005 §36 item 2).
- `BillingProfileManager`, `initializePayerAssignmentForBusiness()`, the payer-consent authorization model, any new permission category, any billing-status mutation/transition method (§5.7).
- `payment_provider_customers`, `business_payment_instruments`, `business_funding_attempts`, `business_funding_attempt_transitions`, `payment_provider_events` — all M3 tables.
- `PaymentInstrumentManager`, `PaymentProviderGateway`/`StripePaymentProviderGateway`, any Stripe SDK call, any webhook route/controller, `ProcessPaymentProviderEvent`, `PurgeExpiredWebhookPayloads`.
- Any real auto-recharge charge attempt, the `EvaluateBusinessAutoRecharge` job/dispatch itself, any low-balance notification delivery, any receipt/invoice generation (`SendLowBalanceNotification`, `SendAutoRechargeDisabledNotification`, `SendReceiptNotification` are not dispatched by any M1 code path; `EvaluateBusinessAutoRecharge` is M3 scope per RFC-005 §36 item 3, corrected this round — §5.7).
- **No platform-wide/config-based default or fallback wallet currency of any kind** — a wallet's `currency_id` always resolves from that specific Business's own valid, active `currency_code`; resolution failure fails closed, never substitutes another currency (§5.5, corrected this round).
- `additional_business_slot_agreements` and its four sibling tables, `business_usage_addon_catalog`/`business_usage_addon_purchases`/`business_usage_addon_purchase_transitions`, `business_billing_receipts` — all M4 tables.
- `UsageBillingCheckoutManager`, `retryFundingAttemptAsAdministrator()`, `retrySlotRenewalAsAdministrator()`, `InitiateSlotAgreementRenewal`, `FinalizeSlotAgreementCancellation`, `SendSlotAgreementPriceChangeNotice`.
- Activating metering for any real feature (`is_metered = true` for any row) — M5 scope exclusively; M1's own classification backfill seeds every row `is_metered = false` and no M1 code path ever flips it.
- Any customer/admin HTTP controller, route, request, view, or permission-category change of any kind.
- The cross-RFC additional-slot allocation-authority blocker, the RFC-004 catalog-pricing-operator gap, and the tax/VAT legal gate remain unresolved and are not addressed by M1 — none of the three has a direct M1 dependency (§5.6).
- Any `EntitlementManager::decide()` signature, algorithm-step-ordering, or denial-key change (§11) — the nine existing keys are reproduced unchanged, and `usage_unauthorized` is structurally reachable but never actually returned by any M1-era code path (§11).
- Any `WorkspaceManager`/`BusinessManager` change beyond nothing at all — M1 only adds a new listener subscribed to their already-existing events; neither manager class itself is modified.
- Any legacy `plans`/`subscriptions`/`invoices`/`PaymentMethods`/`SubscriptionTransaction`/`CustomerBasedPricingPlan`/`PlanSendingCreditPrice` schema, model, route, or controller change.
- Unrelated CRM/messaging/Opportunity changes.
- `docs/automation/AI-AUTONOMY-STATE.json` or any other workflow/autonomy-state file.
- Any RFC-005 M2–M6 work of any kind.
- Any RFC-005 tag.

---

## 4. RFC dependency

RFC-005 v1.4 depends on RFC-001, RFC-002, RFC-003 (tagged `rfc-003-workspace-and-business-account-core`), and RFC-004 (tagged `rfc-004-plans-and-business-feature-entitlements`, `221e18f06441b1399cb2b4ee47ffbb8dbb644b80`) — all complete. M1 additionally depends directly on RFC-003's `businesses`/`workspaces` tables (FK targets and event sources) and on the existing legacy `currencies` table (`currency_id` FK target, §5.5).

---

## 5. Repository audit findings

Direct inspection performed during this contract's own drafting, scoped to what M1 actually needs.

### 5.1 Business-creation paths — exactly two, re-confirmed by direct code read

- **`App\Library\Business\BusinessManager::createOrUpdateOnboardingBusiness()`** → private `applyIdentity()` → on the CREATE branch (`$business === null`), dispatches `App\Events\Business\BusinessCreated::dispatch($outcome['business']->id, $customer->user_id)` (`app/Library/Business/BusinessManager.php:61-62`). `BusinessCreated implements ShouldDispatchAfterCommit` (confirmed by direct read of `app/Events/Business/BusinessCreated.php`).
- **`App\Library\Workspace\WorkspaceManager::createBusinessInWorkspace()`** → inside its own `DB::transaction()` closure, calls `$this->businessRepository->createForCustomerInWorkspace(...)` then dispatches `App\Events\Workspace\BusinessAssignedToWorkspace::dispatch($business->id, $lockedWorkspace->id, $actorUserId)` (`app/Library/Workspace/WorkspaceManager.php:930-932`) — **never** `BusinessCreated`. `BusinessAssignedToWorkspace implements ShouldDispatchAfterCommit` (confirmed by direct read).
- **No third path found.** A repository-wide search for `Business::create(`, `Business::query()->create(`, `->businessRepository->create`, and `new Business(` returns matches only inside these same two manager classes. A search for `DB::table('businesses')` finds matches only inside `WorkspaceEntitlementBackfillV1.php` (a read-only `COUNT(*)` query) and `WorkspaceBackfillV1.php` (a read-only `SELECT` and an `UPDATE ... SET workspace_id` — never an `INSERT`). **No Business row is ever created outside the two manager methods named above.**
- `App\Repositories\Contracts\BusinessRepository` exposes exactly one create-shaped method reachable from these paths: `createForCustomerInWorkspace()` (used by `WorkspaceManager`); `BusinessManager`'s own `applyIdentity()` uses a separate onboarding-specific repository create path. Both ultimately insert into the same `businesses` table under the same schema (§5.2).

### 5.2 `businesses` schema and model evidence

Direct read of `database/migrations/2026_07_18_120001_create_businesses_table.php` and the three RFC-003 workspace-linkage migrations:

- `id` (PK), `uid` (`uuid`, unique — `HasUid` trait, `App\Library\Traits\HasUid`, generates via `uniqid()` on `creating`, not a real RFC 4122 UUID despite the column type — an existing, unrelated inconsistency, not something M1 touches or must correct).
- `customer_id` FK → `users.id`, **`onDelete('cascade')`** — a pre-RFC-003 legacy convention, distinct from RFC-003/RFC-004/RFC-005's own `restrictOnDelete()` posture.
- `workspace_id` (added nullable by `2026_07_30_120004_...`, backfilled by `..._120005_...`, then made `NOT NULL` with FK → `workspaces.id`, **`restrictOnDelete()`**, by `..._120006_enforce_business_workspace_constraint.php` — confirmed by direct read). Every Business has exactly one Workspace, enforced at the database level.
- `timezone` — `string(64)`, **not nullable**, fillable on the `Business` model (confirmed `app/Models/Business.php:31` `$fillable` array includes `'timezone'`) — grounds RFC-005 §15's calendar-month rollover algorithm in a real, always-populated column.
- `currency_code` — `char(3)`, **not nullable**, fillable on the `Business` model — an ISO-4217-shaped code, but **not a foreign key to the `currencies` table**, and semantically distinct from a wallet's own settlement currency (§5.5 resolves this).
- No `SoftDeletes` trait on `Business` (confirmed — no `SoftDeletes` import/use in `app/Models/Business.php`); no `destroy()`/delete method found in `BusinessManager` or the Business repository contracts. A Business row is never directly deleted by any application code path found; it can only ever be removed as a `cascade` side effect of its owning `users` row being deleted.
- **Deletion-behavior interaction, recorded here rather than silently assumed away:** because `businesses.customer_id` cascades, and `business_usage_wallets.business_id` is required by RFC-005 §12 to use `restrictOnDelete()`, a customer-row deletion that would otherwise cascade-delete a Business now becomes **blocked** the moment that Business has a wallet (which, after M1 backfill, is every Business). This is the **intended, correct** consequence of RFC-005's own accounting-integrity requirement — a Business's financial history must never silently vanish via an unrelated cascade — and is not a defect this contract must fix; it is recorded here so the M1 implementation and its schema tests knowingly assert this blocking behavior rather than discover it as a surprise.

### 5.3 RFC-004 entitlement integration — re-confirmed by direct code read

- `App\Library\Entitlement\Contracts\UsageAuthorizationGateway::check(Business $business, PlatformFeature $feature): UsageAuthorizationResult` — unchanged signature.
- `App\Library\Entitlement\NullUsageAuthorizationGateway` — confirmed still the sole bound implementation today (`AppServiceProvider.php:158`), `check()` unconditionally returns `new UsageAuthorizationResult(authorized: true)`.
- `App\Library\Entitlement\EntitlementManager::decide()` — direct read of `app/Library/Entitlement/EntitlementManager.php:94-160` confirms the exact eight-step algorithm and **exactly nine** denial keys, in this exact order: `platform_feature_unknown` → `platform_feature_unavailable` → (Business/Workspace consistency check, throws typed exceptions, not a denial key) → `workspace_plan_unassigned` → `denied_by_workspace_override` / `not_entitled_by_plan` → `disabled_for_business` → `plan_suspended` → `plan_inactive` → `$usageResult->reason ?? 'usage_unauthorized'`. The usage-gateway call (`$this->usageAuthorizationGateway->check($currentBusiness, $feature)`) is confirmed the unconditional final step (line 153), reached only after every structural gate above has already passed.
- **This is the exact ordering and key set M1 must reproduce unchanged** (§11).

### 5.4 Architectural conventions

- Repository-per-table: one contract (`App\Repositories\Contracts\*`, no `Interface` suffix, extending `BaseRepository`, confirmed present at `app/Repositories/Contracts/BaseRepository.php`) + one Eloquent implementation (`App\Repositories\Eloquent\*`), bound in `AppServiceProvider`'s single `$bindings` array (confirmed, `app/Providers/AppServiceProvider.php:113-159`) — RFC-004's exact, unbroken convention.
- Manager classes: `DB::transaction()` + row lock (`findForUpdate()`/lock-then-mutate) + after-commit event dispatch, matching `BusinessManager`/`WorkspaceManager`/`EntitlementManager`'s own shape.
- Enum casting: string-backed PHP enums exclusively, no native database `ENUM`, cast via each model's `$casts` array — confirmed the unbroken convention across every RFC-003/RFC-004 table.
- Events: `App\Events\{Domain}\*`, `implements ShouldDispatchAfterCommit`, carrying IDs/scalars only (confirmed for `BusinessCreated`/`BusinessAssignedToWorkspace` directly, §5.1).
- **Listener registration — confirmed this round (Correction Round 2), previously unexamined.** Direct read of `app/Providers/EventServiceProvider.php` confirms this repository's sole listener-registration mechanism is its explicit `$listen` array (currently one entry, `Registered::class => [SendEmailVerificationNotification::class]`); `shouldDiscoverEvents()` is not overridden, so Laravel's own default of `false` applies and no event auto-discovery occurs anywhere in this codebase. A listener class alone, with no corresponding `$listen` entry, never fires — confirmed by direct attempted implementation of `InitializeBusinessUsageProfile` without this registration.
- Jobs: `App\Jobs\Base` is the shared job base class; `App\Jobs\Business\BuildInitialBusinessSnapshot` and RFC-002's opportunity jobs implement both `ShouldQueue` **and** `ShouldQueueAfterCommit` — the established, real precedent for RFC-005's own queued reconciliation work (already recorded in the RFC-005 design contract's own audit, re-confirmed unchanged this round).
- Migration naming: `{date}_120001_create_{table}_table.php`-style same-day sequential batches, DDL strictly before data-operation migrations, matching RFC-003's seven-migration and RFC-004's eight-migration precedent exactly (§6.10).
- Concurrency-test precedent: `EntitlementManagerConcurrencyTest.php`'s forced-race pattern (a controlled lock-hold window proving genuine contention, an unrelated-Business/Workspace progress assertion) and `WorkspaceEntitlementBackfillV1ConcurrencyTest.php`'s two-simultaneous-run backfill pattern — both directly reused for M1's own reservation-race and wallet-initialization-race tests (§14).
- Backfill/action precedent: `App\Library\Entitlement\Migration\WorkspaceEntitlementBackfillV1` (query-builder-only, chunked, per-unit transaction, idempotent via unique-constraint-catch, a final zero-remaining assertion throwing a typed exception) — directly reused for M1's own wallet backfill (§9).

### 5.5 Money, currency, and precision conventions

- `composer.json` requires PHP `^8.2`; CI (`shivammathur/setup-php@v2`, both `.github/workflows/ai-subscription-gate.yml:214` and `.github/workflows/rfc-003-m3-aggregate-regression.yml:186`) explicitly installs `extensions: mbstring, bcmath, pdo_mysql, intl, xml, curl, zip` for PHP 8.2 — **the `bcmath` extension is confirmed enabled in this repository's own real test/CI runtime**, satisfying RFC-005 §10's prerequisite. (PHP CLI itself is not installed in this drafting sandbox — `php -v` → command not found — consistent with every prior round's environment; this does not affect the CI-runtime finding above, which is what governs actual test execution.)
- CI provisions MySQL `8.0` (`mysql:8.0` service image, `rfc-003-m3-aggregate-regression.yml:38`) — satisfies RFC-005 §25's "MySQL 8.0+ confirmed" precondition for generated/composite-FK columns.
- **Currency resolution — corrected this round. A financial wallet must never silently receive a currency different from the Business's own recorded currency.** The initial draft of this contract resolved a wallet's `currency_id` via a new, invented `config('usage.default_currency_code')` platform-wide fallback (defaulting to `'USD'`), which could silently denominate a Business's wallet in a currency it never recorded. **This is withdrawn.** Direct re-read of `App\Models\Currency` (`app/Models/Currency.php`) confirms the repository's own actual, real currency-normalization rule, which M1 must reuse rather than invent a second one: `Currency::boot()` uppercases `code` on both `creating` and `updating` (`$item->code = strtoupper($item->code);`), so every currency row's `code` is canonically uppercase in the database, by an existing, already-enforced convention — not a rule this contract introduces. `status` is cast `boolean` (`$casts = ['status' => 'boolean']`) and is the repository's own real active/inactive semantics, confirmed by the model's own `enable()`/`disable()` methods (`$this->status = true/false`) and by every existing admin controller's `Currency::where('status', true)->get()` precedent (`KeywordController`, `PhoneNumberController`, `PlanController`, `SenderIDController`, confirmed by direct search) — `status = true` means active/usable, `status = false` means disabled. `currencies.code` carries **no unique constraint** (confirmed, §5.5's original migration read), and `App\Repositories\Contracts\CurrencyRepository` exposes no `findByCode()`-shaped method (confirmed by direct read of both the contract and `EloquentCurrencyRepository`) — there is no existing single-row lookup to call as-is; M1 must query directly, applying the model's own normalization rule.

  **Resolved for M1:** `UsageWalletManager::initializeWalletForNewBusiness()` resolves a wallet's `currency_id` **from that specific Business's own `businesses.currency_code`, normalized identically to the repository's own convention** (`strtoupper($business->currency_code)`), matched against `currencies.code` with `status = true`. Wallet creation may proceed **only when exactly one** matching, active `currencies` row is found:
  - **Zero matches** (no active `currencies` row has that code — covers missing/unknown/malformed/inactive cases, since an inactive row is excluded by the `status = true` filter and therefore indistinguishable from "no row" at the query level) → resolution fails.
  - **Two or more matches** (`currencies.code` carries no unique constraint, so more than one active row can share the same normalized code — e.g. a legacy per-`user_id` custom currency row coexisting with the platform-seeded one) → resolution is genuinely ambiguous and **also** fails; M1 never arbitrarily picks the first/oldest/any match.
  - **On any resolution failure:** no wallet row is inserted, no ledger entry is inserted, no partial accounting state of any kind is left behind (the entire attempt runs inside one transaction that rolls back completely on failure, §8/§9.2) — `App\Exceptions\Usage\BusinessCurrencyUnresolvableException` (replacing the withdrawn `UsageDefaultCurrencyNotConfiguredException`) is thrown, carrying the Business id and a non-sensitive classification (`not_found` or `ambiguous`) — never the raw currency string in a way that could be mistaken for a user-facing message, and never any payment/financial secret (there is none to leak here). Correction requires an operator to fix the Business's `currency_code` or the `currencies` table data; M1 never substitutes another currency to "unblock" the Business.
  - **Never a per-Business guess, never a platform-wide config default, never a silently invented currency row.**

  **Multi-currency-rate consequence, recorded but not resolved by M1.** Because a wallet's currency now comes from that Business's own record rather than one assumed platform-wide value, two Businesses can end up with wallets in different currencies. `business_usage_rates` keys a rate by `(feature_key, version)` only (§6.2) — a single rate row carries one `currency_id`. Reconciling a globally-activated rate's currency against a mismatched wallet's own currency is a `reserve()`-time concern that can only arise once a real rate is actually activated — which no M1 code path ever does (§5.8 item 1: every feature stays `is_metered = false` at M1). This is correctly left for M5 (the first metered feature) to resolve, not decided here.

### 5.6 Cross-RFC blockers — confirmed non-blocking for M1

- The additional-slot allocation-authority blocker (`EntitlementManager::setAdditionalBusinessSlots()`'s `assertPlatformAdministrator()` gate) concerns M4 only — no M1 table, method, or test touches slot allocation.
- The RFC-004 catalog-pricing-operator gap concerns M4's additional-slot pricing only — no M1 dependency.
- The tax/VAT legal gate concerns M3/M4/§23 receipts/invoices only — no M1 dependency.
- **No new conflict beyond these three (already recorded, unresolved, and correctly out of M1's own critical path) was found.**

### 5.7 The M1/M2/M3 method-surface boundary — resolved narrowly, per RFC-005 §36's own literal M1 bullet

RFC-005 §36 item 1 names M1's own `UsageWalletManager` surface exactly: *"reserve/commit/release/expire with the corrected committed-amount formula and calendar-month rollover, rate activation, coarse-capacity evaluation, `initializeWalletForNewBusiness()` only — never a payer assignment."* Taken literally (the same literal-reading discipline the RFC-004 M1 contract applied to its own governing RFC's M1 bullet), this **excludes** every ledger-entry-producing method the RFC's `UsageLedgerEntryType` enum otherwise structurally defines beyond `Reservation`/`ReservationRelease`/`UsageCharge`/`UsageOverageCharge`:

- **`ManualCredit`/`PaidTopUp`/`PromotionalCredit`/`AutoRecharge`/`Refund`/`DisputeChargeback`/`UsageChargeReversal`/`CorrectionReversal`-producing manager methods are M2+/M3+/M4+ scope, not M1** — each requires either a payer/billing-contact (M2), a Stripe funding attempt (M3), or admin/customer HTTP authority (M2+, none of which exists at M1). `UsageLedgerEntryType` is still defined with **all twelve** cases at M1 (RFC-005 §13's own delta table is the enum's authoritative source and is not itself milestone-partitioned) — mirroring RFC-004 M1's own "structurally supported, not yet exercised" precedent for eight of `WorkspaceEntitlementTransitionType`'s nine cases — but M1's own code only ever writes `Reservation`, `ReservationRelease`, `UsageCharge`, and `UsageOverageCharge` rows.
- **A direct consequence:** since no M1 code path ever credits a wallet, `available_balance_micro` remains `0` for every wallet for the entire M1 deployment window in any real, unmodified environment. M1's own tests exercise the reserve/commit/release/expire lifecycle against a wallet balance seeded **directly via the model/repository in test setup** (never through a production manager method, since none exists yet to add balance) — exactly mirroring RFC-004 M1's own precedent of seeding `Currency`/`WorkspacePlanCatalog` rows directly in test fixtures rather than through a not-yet-built manager API.
- **`billing_status` mutation is M2 scope, not M1.** `business_usage_wallets.billing_status` (column, RFC-005 §12) is set once, to its default `'active'`, at wallet creation, and is never mutated by any M1 code path — because its own append-only transition table, `business_usage_wallet_billing_status_transitions`, is an explicit M2 table (RFC-005 §36 item 2). `BusinessWalletBillingStatusChanged` is therefore **not** dispatched by any M1 code. M1's coarse-capacity evaluation (§10.5 below) still **reads** `billing_status`, which is a safe, always-`'active'`, no-op check at M1 — reading a column is not the same as owning its mutation.
- **`EvaluateBusinessAutoRecharge` is not introduced at M1 — corrected this round.** RFC-005 §12's own ledger-insert rule is unconditional and structural at the **final-state** level: *"any ledger-entry insert with `available_delta_micro < 0` dispatches `EvaluateBusinessAutoRecharge` after commit."* Because a `Reservation` entry's own `available_delta_micro` is `-amt` (negative, by RFC-005 §13's own delta table), that rule, read in isolation, would appear to require the dispatch call at M1 already. **This contract's initial draft made exactly that reading and shipped an intentionally-inert M1 stub job — which direct re-read of RFC-005 §36 shows was wrong.** §36 item 3 (Milestone 3 — Provider Customers, Instruments, and Stripe Integration) states explicitly: *"Auto-recharge as the centralized after-commit trigger, including the narrowed administrator resume-only posture"* — RFC-005's own milestone decomposition assigns building this trigger mechanism to M3, not M1. §12's statement is the **final-state invariant** the *whole system* must satisfy once every relevant milestone has shipped; §36 is the authority on **which milestone builds which piece** of that final state. Reading both together, correctly: M1's `reserve()`/`commit()`/`release()` do not dispatch `EvaluateBusinessAutoRecharge` at all — that dispatch call is wired into these same M1-authored methods **by M3's own implementation**, exactly the same kind of incremental, later-milestone extension of M1-authored code RFC-005 §9/§32 already establishes as an accepted pattern for M2 extending the M1 listener. No M1 code references, dispatches, binds, or tests `EvaluateBusinessAutoRecharge` in any form; a direct boundary test proves this (§14). **M3's own future contract must connect the trigger to every M1-vintage ledger-entry-producing code path** (`Reservation`, `ReservationRelease`, `UsageCharge`, `UsageOverageCharge`) as well as its own new entry types — going forward from M3's own deployment instant only; M3 must not retroactively treat any M1-era historical ledger entry as a recharge trigger, since no recharge policy (`auto_recharge_enabled`, threshold, amount) can ever be configured before M2/M3 ship the surfaces that set them, so no M1-era entry could have been a genuine trigger candidate in the first place. M1's own reserve/commit/release/expire correctness does not depend on auto-recharge in any way — every M1 test and invariant is provable with the trigger entirely absent.
- **`SendLowBalanceNotification`, `SendAutoRechargeDisabledNotification`, `SendReceiptNotification`, `SendSlotAgreementPriceChangeNotice`, `AdvanceUsagePeriodBoundaries`, `ReconcileProviderPendingState`, `ReconcileSlotAgreementAllocation`, `InitiateSlotAgreementRenewal`, `FinalizeSlotAgreementCancellation`, `ProcessPaymentProviderEvent`, `PurgeExpiredWebhookPayloads` are none of them dispatched by any M1 code path.** Every notification job requires a resolved billing contact (M2) or a Stripe receipt (M3); `AdvanceUsagePeriodBoundaries` is RFC-005's own explicitly-named "optional proactive maintenance only, never required for correctness" (§15) — M1's own lazy, on-read rollover (§10.3 below) is sufficient without it, so it is deferred entirely, not merely stubbed.

No conflict between this evidence and RFC-005 v1.4 was found beyond the compressed-bullet ordering questions this section resolves narrowly and conservatively. **The stop/gap rule does not trigger** (§17).

### 5.8 RFC-005 §39's fourteen open human decisions — reviewed for M1 impact

Every item is carried forward exactly as RFC-005 v1.4 leaves it; this contract resolves none of them (no M1 code path needs them resolved), except where marked.

| # | Open decision | Affects M1? |
|---|---|---|
| 1 | Exact initial retail usage rates per eventually-metered feature | **No** — M1 activates no rate for any real feature; `business_usage_rates`/`business_usage_rate_activations` exist as empty, correctly-shaped tables at M1 end. |
| 2 | Exact default Business monthly spend cap | **No** — `monthly_spend_cap_micro` exists as a column (§6.1) but is never set by M1; the configuration surface is M2. |
| 3 | Exact default per-feature limits | **No** — `business_feature_usage_limits` is an M2 table. |
| 4 | Exact auto-recharge default threshold | **No** — `auto_recharge_threshold_micro` exists as a column but is never set by M1 (§5.7). |
| 5 | Owner/operator complimentary Agency Workspace's metered-usage subsidy | **No** — no feature is ever metered at M1 (§3), so no subsidy question can arise in practice yet. |
| 6 | Invoice/tax/VAT operational provider and legal sufficiency | **No** — M1 has no receipts, invoices, or Stripe integration at all (§3). |
| 7 | Timing of Agency client rebilling | **No** — out of scope for the entire RFC-005 v1 design, unaffected by any milestone this early. |
| 8 | Exact v1 add-on roster and pricing | **No** — `business_usage_addon_*` tables are M4. |
| 9 | Exact initial per-feature platform safety-limit ceilings | **No** — `platform_feature_usage_safety_limits` is an M2 table. |
| 10 | v1 settlement currency and multi-currency scope | **Indirectly yes — reclassified this round; still not decided for the RFC as a whole.** M1 denominates each wallet using **that specific Business's own existing, valid `currency_code`** (§5.5, corrected this round) — never a platform-wide default. This resolves nothing about RFC-005 §39 item 10 itself: it does not decide whether the RFC as a whole is USD-only or multi-currency, and it does not decide whether Stripe/provider settlement-currency support is single- or multi-currency — those are provider-facing questions RFC-005 §20/§39 item 10 leave open for their own proper milestone (M3+). M1 only establishes that each wallet's own denomination is a faithful reflection of the Business that owns it, which is true under either a future single-currency or multi-currency resolution of item 10 and forecloses neither. |
| 11 | The first actual metered feature | **No** — M5 names it; M1's own classification backfill deliberately marks every feature `is_metered = false`. |
| 12 | Exact default monthly auto-recharge cap | **No** — `monthly_recharge_cap_micro` exists as a column but is never set by M1. |
| 13 | Additional-slot agreement `payment_lapsed` grandfathered-capacity revocation policy | **No** — `additional_business_slot_agreements` is an M4 table. |
| 14 | The cross-RFC additional-slot allocation authority blocker | **No** — concerns M4's own allocation step exclusively (§5.6). |

**No item in this table is resolved by this contract as a final human product decision.** Item 10's per-Business currency resolution is the one place this contract makes a concrete implementation choice in an area §39 leaves open — done narrowly, using each Business's own already-recorded data rather than a new invented default, and forecloses no future single- or multi-currency resolution of the open item itself.

---

## 6. Exact schema contract

Seven tables, reproducing RFC-005 §11/§12/§13/§14.1 exactly (no redesign, no shorthand). All migrations DDL-only except the two data-operation migrations (§6.10). No native database `ENUM` anywhere. No `float`/`double` for any money column. Every FK on a tenancy-scoping or accounting-history column uses `restrictOnDelete()`, never `cascade`.

### 6.1 `business_usage_wallets`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `business_id` | `unsignedBigInteger`, FK `businesses.id`, **unique**, `restrictOnDelete()` | No | — | 1:1, Business-scoped |
| `currency_id` | `unsignedBigInteger`, FK `currencies.id`, `restrictOnDelete()` | No | — | resolved per §5.5, from that Business's own `currency_code`; **an immutable accounting snapshot once set — never rewritten by any code path, including a later change to `businesses.currency_code`** (§7 invariant 11) |
| `available_balance_micro` | `bigint` | No | `0` | never negative (manager-enforced) |
| `reserved_balance_micro` | `bigint` | No | `0` | never negative |
| `debt_balance_micro` | `bigint` | No | `0` | never negative |
| `monthly_spend_cap_micro` | `bigint`, nullable | Yes | `NULL` | column exists at M1 (RFC-005 §12); **no M1 code path ever sets it non-null** — the configuration surface is M2 (§5.7); always `NULL` in any real M1-era wallet |
| `spend_period_key` | `string(7)` | No | set at creation | e.g. `'2026-08'`, Business-timezone calendar month |
| `spend_period_start_utc` | `timestamp` | No | set at creation | |
| `spend_period_end_utc` | `timestamp` | No | set at creation | |
| `auto_recharge_enabled` | `boolean` | No | `false` | column exists at M1; **never set `true` by any M1 code path** (§5.7) |
| `auto_recharge_threshold_micro` | `bigint`, nullable | Yes | `NULL` | always `NULL` at M1 |
| `auto_recharge_amount_micro` | `bigint`, nullable | Yes | `NULL` | always `NULL` at M1 |
| `monthly_recharge_cap_micro` | `bigint`, nullable | Yes | `NULL` | always `NULL` at M1 |
| `recharge_period_key` | `string(7)` | No | set at creation | independent calendar-month identity |
| `recharge_period_start_utc` | `timestamp` | No | set at creation | |
| `recharge_period_end_utc` | `timestamp` | No | set at creation | |
| `committed_spend_this_period_micro` | `bigint` | No | `0` | formula-derived (RFC-005 §13), never directly mutated |
| `reserved_spend_this_period_micro` | `bigint` | No | `0` | |
| `recharged_this_period_micro` | `bigint` | No | `0` | always `0` at M1 — no `AutoRecharge` entry is ever written (§5.7) |
| `consecutive_recharge_failures` | `unsigned smallint` | No | `0` | always `0` at M1 |
| `low_balance_notified_at` | `timestamp`, nullable | Yes | `NULL` | always `NULL` at M1 |
| `billing_status` | `string(16)`, enum-backed (`WalletBillingStatus`) | No | `active` | never mutated by M1 code (§5.7) |
| `created_at` | `timestamp` | No | `now()` | |
| `updated_at` | `timestamp` | No | `now()` | |

Indexes: `UNIQUE (id, business_id)` (composite-FK enabler for §6.6/§6.7). Sole write authority: `UsageWalletManager`.

### 6.2 `business_usage_rates`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `feature_key` | `string(64)` | No | — | `PlatformFeature`-backed value |
| `version` | `unsigned int` | No | — | starts at 1 per `feature_key`, computed under the classification-row lock |
| `retail_rate_micro` | `bigint unsigned` | No | — | |
| `provider_cost_micro` | `bigint unsigned` | No | — | admin-only visibility (deferred enforcement, no admin surface exists at M1 — §5.7's HTTP exclusion) |
| `unit_label` | `string(64)` | No | — | |
| `rounding_rule` | `string(32)`, enum-backed (`RoundingRule`) | No | `round_half_up` | |
| `currency_id` | `unsignedBigInteger`, FK `currencies.id`, `restrictOnDelete()` | No | — | one currency per `(feature_key, version)`; reconciling this against a per-Business wallet currency (§5.5) is deferred to M5 — no rate is ever activated at M1 |
| `created_by_user_id` | `unsigned bigint`, no FK | No | — | |
| `created_at` | `timestamp` | No | `now()` | the only column on this row — fully immutable |

Indexes: `UNIQUE (feature_key, version)`. Sole write authority: `UsageWalletManager`.

### 6.3 `business_usage_rate_activations`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `feature_key` | `string(64)` | No | — | indexed |
| `rate_id` | `unsignedBigInteger`, FK `business_usage_rates.id`, `restrictOnDelete()` | No | — | |
| `activated_at` | `timestamp` | No | `now()` | |
| `activated_by_user_id` | `unsigned bigint`, no FK | No | — | |
| `reason` | `text` | No | — | mandatory |
| `created_at` | `timestamp` | No | `now()` | |

Sole write authority: `UsageWalletManager`.

### 6.4 `platform_feature_usage_classifications`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `feature_key` | `string(64)`, unique | No | — | `PlatformFeature`-backed |
| `is_metered` | `boolean` | No | `false` | always `false` for every row at M1 (§3) |
| `active_rate_id` | `unsignedBigInteger`, FK `business_usage_rates.id`, nullable, `restrictOnDelete()` | Yes | `NULL` | always `NULL` at M1 — no rate is ever activated without an M5-authorized metered feature |
| `updated_by_user_id` | `unsigned bigint`, nullable, no FK | Yes | `NULL` | **corrected this round — resolved nullable, closing the contradiction with §9.1's own algorithm.** `NULL` means exclusively "this row's current state was last written by a system/bootstrap/backfill process, never a human actor" — never a fake/system `User` record, never an arbitrary platform administrator attributed by convenience. No FK, mirroring RFC-004's `workspace_plan_assignments.complimentary_granted_by_user_id` precedent exactly (a plain scalar historical-actor column that must never block a legitimate user-deletion feature elsewhere in the system). A human-authored change — `setActiveRate()`/`activateMetering()` (§10.1), the only two methods that ever mutate an existing classification row post-backfill — must supply its own real `$actorUserId` and set this column to it; since no other code path ever mutates an already-classified row (the backfill only ever inserts a brand-new row, never updates an existing one, §9.1), a known actor already recorded here can never be silently overwritten back to `NULL` by any M1 code path. The column's own value (`NULL` vs. a real id) is itself the auditable system-vs-human-authorship signal — no separate source/type column is needed on this table for that purpose, unlike the append-only transition tables elsewhere in this schema which use an explicit `source` enum instead. |
| `created_at` | `timestamp` | No | `now()` | |
| `updated_at` | `timestamp` | No | `now()` | |

Backfilled: one row per existing `PlatformFeature` case (currently fifteen, RFC-004 §11), `is_metered = false`, `active_rate_id = null`, `updated_by_user_id = null` (§9.1). Sole write authority: `UsageWalletManager`.

### 6.5 `platform_feature_usage_classification_transitions`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `feature_key` | `string(64)` | No | — | |
| `from_is_metered` | `boolean` | No | — | |
| `to_is_metered` | `boolean` | No | — | |
| `from_active_rate_id` | `unsignedBigInteger`, nullable, FK `business_usage_rates.id` | Yes | `NULL` | |
| `to_active_rate_id` | `unsignedBigInteger`, nullable, FK `business_usage_rates.id` | Yes | `NULL` | |
| `actor_user_id` | `unsigned bigint`, no FK | No | — | |
| `reason` | `text` | No | — | mandatory |
| `created_at` | `timestamp` | No | `now()` | append-only, no `updated_at` |

**Never written by any M1 code path** — the backfill (§9) writes classification rows directly, not through `activateMetering()`, and inserts no transition row (mirroring RFC-004 M1's own backfill, which writes its structural audit table directly rather than through an actor-driven mutation method). The table and its repository/model exist at M1 (RFC-005 assigns it to M1's own schema list) so that M5's `activateMetering()` has a ready target; a direct test asserts it has zero rows after M1's own backfill (§14).

### 6.6 `business_usage_reservations`

Composite-protected on `(wallet_id, business_id)` — declares `(wallet_id, business_id) → business_usage_wallets(id, business_id)`.

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `business_id` | `unsignedBigInteger`, composite FK (with `wallet_id`) | No | — | |
| `wallet_id` | `unsignedBigInteger`, composite FK (with `business_id`) | No | — | |
| `feature_key` | `string(64)` | No | — | |
| `period_key` | `string(7)` | No | — | snapshotted once at creation from the wallet's then-current `spend_period_key`; immutable |
| `status` | `string(16)`, enum-backed (`UsageReservationStatus`) | No | `pending` | `pending` \| `committed` \| `released` \| `expired` |
| `reserved_amount_micro` | `bigint` | No | — | |
| `estimated_quantity` | `decimal(14,6)`, nullable | Yes | `NULL` | |
| `rate_id` | `unsignedBigInteger`, FK `business_usage_rates.id`, `restrictOnDelete()` | No | — | snapshot at reservation time |
| `rate_version` | `unsigned int` | No | — | |
| `retail_rate_micro` | `bigint unsigned` | No | — | |
| `provider_cost_micro` | `bigint unsigned` | No | — | |
| `rounding_rule` | `string(32)`, enum-backed (`RoundingRule`) | No | — | |
| `idempotency_key` | `string(191)`, unique | No | — | caller-supplied |
| `correlation_key` | `string(191)` | No | — | |
| `reserved_at` | `timestamp` | No | `now()` | |
| `expires_at` | `timestamp` | No | — | operation-defined TTL |
| `committed_at` | `timestamp`, nullable | Yes | `NULL` | exactly one of `committed_at`/`released_at` set on a terminal row |
| `released_at` | `timestamp`, nullable | Yes | `NULL` | |
| `final_quantity` | `decimal(14,6)`, nullable | Yes | `NULL` | set only on commit |
| `final_amount_micro` | `bigint`, nullable | Yes | `NULL` | set only on commit |

Once `status` is `committed`, `released`, or `expired`, the row is never reopened. Sole write authority: `UsageWalletManager`.

### 6.7 `business_usage_ledger_entries`

Composite-protected on `(wallet_id, business_id)`. Append-only — never updated or deleted after insert.

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `business_id` | `unsignedBigInteger`, composite FK (with `wallet_id`) | No | — | |
| `wallet_id` | `unsignedBigInteger`, composite FK (with `business_id`) | No | — | |
| `entry_type` | `string(32)`, enum-backed (`UsageLedgerEntryType`) | No | — | twelve values defined; M1 code writes only `Reservation`/`ReservationRelease`/`UsageCharge`/`UsageOverageCharge` (§5.7) |
| `available_delta_micro` | `bigint` (signed) | No | `0` | |
| `reserved_delta_micro` | `bigint` (signed) | No | `0` | |
| `debt_delta_micro` | `bigint` (signed) | No | `0` | |
| `gross_amount_micro` | `bigint unsigned`, nullable | Yes | `NULL` | informational only |
| `currency_id` | `unsignedBigInteger`, FK `currencies.id`, `restrictOnDelete()` | No | — | |
| `feature_key` | `string(64)`, nullable | Yes | `NULL` | |
| `period_key` | `string(7)`, nullable | Yes | `NULL` | copied from the originating reservation's own `period_key` for `UsageCharge`/`UsageOverageCharge` |
| `quantity` | `decimal(14,6)`, nullable | Yes | `NULL` | |
| `rate_id` | `unsignedBigInteger`, FK `business_usage_rates.id`, nullable, `restrictOnDelete()` | Yes | `NULL` | |
| `rate_version` | `unsigned int`, nullable | Yes | `NULL` | |
| `retail_rate_micro` | `bigint unsigned`, nullable | Yes | `NULL` | |
| `provider_cost_micro` | `bigint unsigned`, nullable | Yes | `NULL` | |
| `unit_label` | `string(64)`, nullable | Yes | `NULL` | |
| `rounding_rule` | `string(32)`, enum-backed (`RoundingRule`), nullable | Yes | `NULL` | |
| `reservation_id` | `unsignedBigInteger`, FK `business_usage_reservations.id`, nullable | Yes | `NULL` | |
| `funding_attempt_id` | `unsignedBigInteger`, nullable | Yes | `NULL` | **no FK at M1** — `business_funding_attempts` does not exist until M3; column is a plain nullable `unsignedBigInteger` at M1, always `NULL` (no M1 entry type sets it), with the FK constraint itself added by M3's own migration once its target table exists |
| `correlation_key` | `string(191)`, unique | No | — | idempotency |
| `provider_reference` | `string(191)`, nullable | Yes | `NULL` | always `NULL` at M1 |
| `actor_user_id` | `unsigned bigint`, nullable, no FK | Yes | `NULL` | `NULL` = system-generated; always `NULL` at M1, since every M1 entry type is system-driven (reserve/commit/release/expire), never an admin/customer action |
| `reason` | `text`, nullable | Yes | `NULL` | mandatory (manager-enforced) for `ManualCredit`/`UsageChargeReversal`/`CorrectionReversal`/`Refund` — none written at M1, so always `NULL` in practice |
| `reversed_entry_id` | `unsignedBigInteger`, self-referencing FK, nullable, `restrictOnDelete()` | Yes | `NULL` | always `NULL` at M1 |
| `created_at` | `timestamp` | No | `now()` | immutable |

Sole write authority: `UsageWalletManager`. **Deferred-FK note:** `funding_attempt_id` is the one column in M1's schema whose eventual FK constraint is added by a *later* milestone's migration (M3) rather than M1's own — the column itself must exist at M1 because `business_usage_ledger_entries` is fully specified in one shot per RFC-005 §12, but M1 cannot reference a table (`business_funding_attempts`) that will not exist until M3. This is the same "structurally present, not yet exercised" pattern already established for §6.4's `active_rate_id` and §6.5's entire table — recorded explicitly here, and asserted directly by a schema test (§14), so it is a documented milestone-sequencing decision, not a silently missing constraint.

### 6.8 Composite foreign keys

`(wallet_id, business_id) → business_usage_wallets(id, business_id)`, declared on `business_usage_reservations` and `business_usage_ledger_entries` — the exact same pattern RFC-004 never needed and RFC-005 introduces, verified buildable on MySQL 8.0 (§5.5) referencing `business_usage_wallets`' own `UNIQUE (id, business_id)` index (§6.1).

### 6.9 Enums — exactly four at M1

All under `App\Enums\Usage`, string-backed, no native DB `ENUM` column anywhere:

- `WalletBillingStatus`: `Active = 'active'`, `Suspended = 'suspended'`.
- `RoundingRule`: `RoundHalfUp = 'round_half_up'` — the only value defined for v1 (RFC-005 §11).
- `UsageReservationStatus`: `Pending = 'pending'`, `Committed = 'committed'`, `Released = 'released'`, `Expired = 'expired'`.
- `UsageLedgerEntryType`: all **twelve** RFC-005 §13 values — `Reservation`, `ReservationRelease`, `UsageCharge`, `UsageOverageCharge`, `PaidTopUp`, `AutoRecharge`, `ManualCredit`, `PromotionalCredit`, `Refund`, `DisputeChargeback`, `UsageChargeReversal`, `CorrectionReversal` — defined in full at M1 (the enum is not itself milestone-partitioned, §5.7), with only the first four ever written by M1's own code.

### 6.10 Exact migration filenames

Following §5.4's exact naming/sequencing precedent. **The date prefix is the actual implementation date, unknowable at contract-drafting time** — the narrowest deterministic rule this contract can lock: the `HHMMSS` suffix and `description` are exact and fixed; all nine migrations share one real implementation date; DDL (1–7) strictly precedes the data operations (8–9), and DDL is itself ordered to satisfy every FK dependency identified in §6.1–§6.8:

1. `{impl_date}_120001_create_business_usage_wallets_table.php`
2. `{impl_date}_120002_create_business_usage_rates_table.php`
3. `{impl_date}_120003_create_business_usage_rate_activations_table.php`
4. `{impl_date}_120004_create_platform_feature_usage_classifications_table.php`
5. `{impl_date}_120005_create_platform_feature_usage_classification_transitions_table.php`
6. `{impl_date}_120006_create_business_usage_reservations_table.php`
7. `{impl_date}_120007_create_business_usage_ledger_entries_table.php`
8. `{impl_date}_120008_backfill_platform_feature_usage_classifications.php`
9. `{impl_date}_120009_backfill_business_usage_wallets.php`

Migrations 1–7 are DDL-only (`Schema::create`, no data write). Migrations 8–9 are data-operations-only, mirroring RFC-004 migrations 7–8's exact DDL/data separation. Migration 8 seeds one classification row per existing `PlatformFeature` case (§9.1); migration 9 directly instantiates and invokes the wallet-backfill action (§9.2), never a console-command wrapper, mirroring RFC-003/RFC-004's own `(new *BackfillV1())->run()` precedent exactly.

---

## 7. Accounting invariants

Locked exactly from RFC-005 §10/§12/§13, unchanged by this contract:

1. Each of the three wallet buckets (`available_balance_micro`/`reserved_balance_micro`/`debt_balance_micro`) is an always-consistent cached aggregate of its own matching ledger delta column, reconstructable independently from `business_usage_ledger_entries`.
2. A `Reservation` entry moves `-amt` available / `+amt` reserved / `0` debt, atomically, in the same transaction as the reservation row insert and the wallet aggregate update.
3. A `ReservationRelease` entry (release or expiry, never a charge) exactly reverses a `Reservation` entry's bucket effect: `+amt` available / `-amt` reserved / `0` debt.
4. A `UsageCharge` entry moves `0` available / `-committed_portion` reserved / `0` debt — the committed amount is `-reserved_delta_micro` (RFC-005 §13's corrected formula).
5. A `UsageOverageCharge` entry moves `-min(overage, avail)` available / `0` reserved / `+max(0, overage-avail)` debt — its committed amount is `(-available_delta_micro) + debt_delta_micro`, exactly the overage (RFC-005 §13's corrected formula, algebraically verified: `min(overage,avail) + max(0,overage-avail) = overage`).
6. `committed_spend_this_period_micro`/`reserved_spend_this_period_micro` are cached, current-period-only, formula-derived values — **never directly or manually mutated by any code path, under any circumstance**, including by a platform administrator (RFC-005 §13's withdrawn counter-adjustment exception — no administrator surface exists at M1 anyway, §3).
7. No counter or balance is ever recomputed using PHP `float` — every arithmetic step uses BCMath (`bcRoundHalfUp()`, RFC-005 §10) or plain integer arithmetic on `bigint` columns.
8. Ledger entries are immutable once inserted — a correction is represented by a new entry (`CorrectionReversal`, not written at M1), never an `UPDATE`/`DELETE` on an existing row.
9. No M1 code path ever expresses a cross-Business or cross-currency transfer — every ledger entry's `business_id`/`wallet_id` pair is fixed at insert time via the composite FK (§6.8), and every entry's `currency_id` is fixed to the wallet's own single settlement currency (§5.5).
10. Outstanding debt (`debt_balance_micro > 0`) denies new reservations, evaluated immediately after `billing_status` (RFC-005 §10/§15's evaluation order, M1's own narrowed slice of it — §10.5 below).
11. **New this round.** A wallet's `currency_id`, once resolved and set at wallet-creation time (§5.5), is an immutable accounting snapshot — no code path ever updates it, including if the owning Business's `currency_code` is later changed. A later `currency_code` change affects nothing about the already-created wallet or any historical ledger entry's denomination; it can only ever affect a *future* wallet that does not yet exist (a scenario M1 does not itself construct, since every M1-era wallet is created at most once per Business, §9).

---

## 8. Transaction, locking, and concurrency contract

0. **Fail-closed precondition, every M1 mutation method**: `reserve()`/`commit()`/`release()` each begin by loading the Business's wallet; if none exists (wallet initialization never ran, or previously failed — §9.2/§9.3), the method throws `UsageWalletNotFoundException` immediately, before acquiring any lock or evaluating any other condition. **Until wallet initialization succeeds for a given Business, every wallet/accounting operation for that Business fails closed this way** — there is no partial-capability mode.
1. **Reserve** (`UsageWalletManager::reserve()`): `DB::transaction()`, wallet row `findForUpdate()`. Lazily rolls the wallet's period over first (§10.3), reads `period_key` from the now-current `spend_period_key`, looks up the active rate (denying with `NoActiveRateForFeatureException` if none — §12), computes `reserved_amount_micro`, evaluates in order: `billing_status` → `outstanding_debt` → *(per-feature limit, Business spend cap, platform safety limit — skipped at M1: their tables do not exist yet, §5.7)* → available-balance sufficiency → inserts the reservation row → inserts the `Reservation` ledger entry → updates wallet aggregates including `reserved_spend_this_period_micro += reserved_amount_micro`. **No `EvaluateBusinessAutoRecharge` dispatch of any kind occurs at M1** (corrected this round — §5.7: RFC-005 §36 assigns that trigger to M3). Idempotency: a repeat call with an already-used `idempotency_key` returns the existing reservation's own `ReservationResult`, detected via the unique-constraint-catch pattern (`WorkspaceMembershipRepository::create()`'s precedent), never a raw database exception surfaced to the caller.
2. **Commit** (`UsageWalletManager::commit()`): wallet row `findForUpdate()`. Loads the `pending` reservation (`UsageReservationNotFoundException` if absent or already terminal in a way that makes commit invalid — `InvalidReservationStateTransitionException` if already `released`/`expired`). Compares `final_amount_micro` to `reserved_amount_micro`; inserts `UsageCharge` for `min(final, reserved)`; if `final > reserved`, additionally inserts `UsageOverageCharge`; if `final < reserved`, additionally inserts `ReservationRelease` for the unused portion; marks `committed`; updates `committed_spend_this_period_micro`/decrements `reserved_spend_this_period_micro` **only if** the reservation's own `period_key` equals the wallet's **current** `spend_period_key` (a stale, already-rolled-over reservation's own historical `period_key` is preserved on its ledger entries regardless). Idempotent: a repeat commit on an already-`committed` reservation is a no-op, returning the original `CommitResult`.
3. **Release** (`UsageWalletManager::release()`): same current-period-only counter-decrement rule as commit, applied only to `reserved_spend_this_period_micro`.
4. **Expire** (`App\Jobs\Usage\ExpireStaleUsageReservations`): finds `pending` reservations past `expires_at`, calls `release()` for each — never auto-commits a stale reservation.
5. **Lock order**: wallet row lock is acquired before any reservation/ledger insert within the same transaction; no M1 operation ever acquires a second wallet's lock within the same transaction (no cross-Business operation exists at M1), so no new deadlock-ordering rule beyond RFC-004 §17.2's already-locked ascending-ID convention is introduced.
6. **Duplicate-request behavior**: `reserve()`'s `idempotency_key` uniqueness and `commit()`/`release()`'s natural no-op-on-already-terminal behavior together make every M1 mutation effectively exactly-once under retry.
7. **Unrelated-Business concurrency isolation**: two concurrent `reserve()` calls for two different Businesses never contend for the same wallet row lock and must both succeed independently — a direct forced-race test asserts this (§14), mirroring `EntitlementManagerConcurrencyTest.php`'s own unrelated-progress assertion pattern.
8. **Reconciliation safety**: `commit()`'s current-period-only guard (item 2) is the mechanism that keeps a wallet's cached counters correct even when a reservation outlives its own period — no separate reconciliation job is required at M1 (RFC-005's own `AdvanceUsagePeriodBoundaries` is explicitly optional and deferred, §5.7).

---

## 9. Business initialization and backfill

### 9.1 Classification backfill (migration 8, `{impl_date}_120008_backfill_platform_feature_usage_classifications.php`)

Query-builder-only (`DB::table('platform_feature_usage_classifications')`, no Eloquent model dependency), one row per `App\Enums\Entitlement\PlatformFeature::cases()` (currently fifteen, per RFC-004 §11/M1's own registry) not already present, `is_metered = false`, `active_rate_id = null`, `updated_by_user_id = null` — **`updated_by_user_id`'s nullability is now the schema's own explicit, non-contradictory declaration (§6.4, corrected this round), not merely an algorithmic detail the schema table disagreed with.** The RFC-004 M1 precedent for `complimentary_granted_by_user_id` on a system-authored row establishes the same `NULL`/no-FK convention for a migration-authored actor column this contract reuses. Idempotent (checks for an existing row per `feature_key` first, safe under partial rerun). No transition row written (§6.5). **This is the only classification-row write M1's own backfill ever performs — an `INSERT` of a brand-new row, never an `UPDATE` of an existing one** — so a backfill rerun can never overwrite a real human actor id a later `setActiveRate()`/`activateMetering()` call (§10.1) may have already recorded on a row that already existed from an earlier backfill pass.

### 9.2 Wallet backfill (migration 9, `{impl_date}_120009_backfill_business_usage_wallets.php`, invoking `App\Library\Usage\Migration\UsageWalletBackfillV1` directly — never a console-command wrapper, mirroring RFC-003 migration 5 / RFC-004 migration 8's exact precedent)

**Algorithm, adapted from `WorkspaceEntitlementBackfillV1` (§5.4), corrected this round for per-Business currency resolution and its own failure mode:**

- Query-builder-only (`DB::table('businesses')`, `'business_usage_wallets'`, `'currencies'`) — no Eloquent model, no model event.
- Bounded/chunked traversal over Businesses lacking a `business_usage_wallets` row (left-join / not-exists query, chunked by `businesses.id`, `CHUNK_SIZE = 500`, mirroring `WorkspaceBackfillV1`/`WorkspaceEntitlementBackfillV1`'s identical precedent).
- **Per-Business transaction** — each Business's own currency resolution (§5.5) **and** wallet insert happen inside the same transaction, committing or rolling back together, independently of every other Business.
- **Currency resolution is now a per-Business step, not a single upfront platform-wide step** (corrected this round — §5.5 withdraws the platform-default fallback this algorithm previously relied on). For each Business in the current chunk: resolve `currency_id` from that Business's own `currency_code` (§5.5); if resolution fails (zero or multiple active matches), roll back that Business's own transaction (no wallet, no partial state), record the failure (`business_id`, the Business's own `currency_code` value, and a classification of `not_found`/`ambiguous`) in the run's own in-memory failure list, and **continue to the next Business in the chunk** — one Business's unresolvable currency never stops or corrupts another Business's initialization.
  - **Continue-vs-stop, resolved from existing repository deployment precedent (per this correction's own instruction to decide this explicitly):** `WorkspaceBackfillV1`/`WorkspaceEntitlementBackfillV1` both already establish the same shape — bounded, chunked, per-unit-transaction processing that runs to completion over every candidate row, followed by one final assertion that reports whatever remains unprocessed, rather than aborting the whole run at the first per-unit problem. This backfill reproduces that same shape: it **continues** through every Business in every chunk, and only *after* attempting all of them does it evaluate the final assertion below. A currency-resolution failure is simply a new *kind* of "this unit did not complete" outcome, handled with the same continue-and-report discipline the existing precedent already applies to unexpected per-unit conditions.
- **Idempotent, safe under partial rerun** — a Business that already has a wallet is skipped (detected by the same left-join/not-exists query); a Business whose currency previously failed to resolve is naturally retried on the next run (it still has no wallet, so the same left-join/not-exists query selects it again) — correcting that Business's `currency_code` or the `currencies` table data before the next run is what allows it to succeed.
- **Concurrency-safe** — two simultaneous backfill runs racing the same Business serialize on `business_usage_wallets.business_id`'s unique constraint; the losing insert's constraint violation is caught and treated as "already initialized," mirroring `WorkspaceMembershipRepository::create()`'s idempotent-create pattern.
- **Final assertion, extended this round** — a `SELECT COUNT(*)` over Businesses still lacking a wallet must be zero at the end of a run; a non-zero count throws `UsageWalletBackfillIncompleteException`, now carrying both the exact remaining count **and** the run's own currency-resolution failure list (Business id + classification), so an operator can immediately distinguish "genuinely not yet reached" from "reached, but its currency needs correction," mirroring `WorkspaceBackfillIncompleteException`/`WorkspaceEntitlementBackfillIncompleteException`'s exact-count discipline, extended with this additional detail.
- **Per-Business wallet values**: `currency_id` → resolved per §5.5, from that specific Business's own `currency_code`; `available_balance_micro`/`reserved_balance_micro`/`debt_balance_micro` → `0`; `spend_period_key`/`spend_period_start_utc`/`spend_period_end_utc` and `recharge_period_key`/`recharge_period_start_utc`/`recharge_period_end_utc` → constructed via §10.3's calendar-month algorithm, evaluated against **that Business's own `timezone` column** (confirmed non-nullable, §5.2), at the backfill run's own execution instant; `auto_recharge_enabled` → `false`; every other nullable column → `NULL`; `billing_status` → `active`.
- **No Eloquent event dispatched** by the migration/backfill action itself — matching `WorkspaceBackfillV1`'s explicit RFC-003 §10.3 constraint and RFC-004 M1's identical discipline.

**Supporting files** (mirroring `WorkspaceEntitlementBackfillResult`/`BackfillWorkspaceEntitlementsCommand` exactly): `App\Library\Usage\Migration\UsageWalletBackfillV1` (final class), `App\Library\Usage\Migration\UsageWalletBackfillResult` (final readonly class: `businessesProcessed: int`, `walletsCreated: int`, `remainingUnwalletedCount: int` (always `0` only when every Business resolved cleanly), **`currencyResolutionFailures: array` — new this round, one entry per Business whose currency could not be resolved during this run, each carrying the Business id and a non-sensitive `not_found`/`ambiguous` classification, never a raw exception trace**), `App\Console\Commands\BackfillUsageWalletsCommand` (thin wrapper, signature `usage:backfill-wallets`, no algorithm of its own, surfaces the failure list alongside the exact remaining count on a forced-incomplete scenario).

### 9.3 Ongoing (post-rollout) Business creation

`App\Listeners\Usage\InitializeBusinessUsageProfile`, subscribed to both `BusinessCreated` and `BusinessAssignedToWorkspace` (§5.1's two confirmed events, both `ShouldDispatchAfterCommit`). At M1, its handler calls only `UsageWalletManager::initializeWalletForNewBusiness(int $businessId): void` — idempotent (checks for an existing wallet first, via the same unique-constraint-catch pattern as §9.2), creating exactly one wallet using §9.2's identical per-Business value construction, evaluated at the moment of the listener's own invocation rather than the backfill's execution instant. **The listener class is introduced fresh at M1**; RFC-005 §9/§32 anticipates M2 extending this same class with one additional idempotent call — that extension is explicitly **not** authorized by this contract (§3) and is M2's own responsibility.

**Registration mechanism — corrected this round, previously unspecified.** Direct read of `app/Providers/EventServiceProvider.php` confirms this repository's sole listener-registration mechanism is its explicit `$listen` array — no event auto-discovery is configured (`shouldDiscoverEvents()` is not overridden; Laravel's own default of `false` applies), so a listener class with no `$listen` entry never fires, confirmed by direct attempted implementation. **`app/Providers/EventServiceProvider.php` is authorized (§12) for exactly two additive `$listen` entries and their two corresponding `use` imports**:

```php
use App\Events\Business\BusinessCreated;
use App\Events\Workspace\BusinessAssignedToWorkspace;
use App\Listeners\Usage\InitializeBusinessUsageProfile;

protected $listen = [
    Registered::class => [
        SendEmailVerificationNotification::class,
    ],
    BusinessCreated::class => [
        InitializeBusinessUsageProfile::class,
    ],
    BusinessAssignedToWorkspace::class => [
        InitializeBusinessUsageProfile::class,
    ],
];
```

The existing `Registered::class => [SendEmailVerificationNotification::class]` mapping is untouched. No `boot()` change, no `shouldDiscoverEvents()` override, no provider restructuring, no unrelated formatting change — the two new mappings and their two imports are the entire authorized diff to this file. Each mapping is registered **exactly once** — a duplicate `$listen` entry for the same event would cause the listener to fire twice per event, which would itself violate the wallet-initialization idempotency guarantee's own single-attempt framing (even though the guarantee would still technically hold via the unique-constraint-catch, a duplicate registration is never intentional and is explicitly excluded); a mechanical search proves this (§13).

**Duplicate event delivery, restated precisely for this correction:** if the same event instance is somehow delivered twice (queue redelivery, an at-least-once delivery guarantee, or any other cause), the listener's second invocation calls `initializeWalletForNewBusiness()` again; that method's own idempotent existing-wallet check (or, on a genuine simultaneous race, the unique-constraint-catch, §9.2) means at most one wallet and its exactly-one opening ledger-entry-free row (M1 never writes an opening ledger entry — no credit method exists, §5.7) are ever created — never two.

**Currency-resolution failure during ongoing Business creation — corrected this round, described honestly against the listener's real event timing.** Both `BusinessCreated` and `BusinessAssignedToWorkspace` are `ShouldDispatchAfterCommit` (§5.1, re-confirmed by direct read) — by the time `InitializeBusinessUsageProfile` runs, the Business row is **already committed** to the database. **The listener cannot roll back an already-committed Business, and this contract does not pretend it can.** If `initializeWalletForNewBusiness()` throws `BusinessCurrencyUnresolvableException` (§5.5) for a newly created Business:

1. The Business itself remains created and fully usable for every non-wallet purpose — nothing about its creation is undone or retried.
2. The listener **catches its own exception** rather than letting it propagate to the request/queue-worker context that dispatched the event — a wallet-initialization failure must never surface as an unrelated failure of the Business-creation request that already succeeded.
3. The failure is logged with a non-sensitive classification (Business id, `not_found`/`ambiguous`) — never a raw exception trace exposed to the end user, matching §5.5's own non-sensitive-failure requirement.
4. The Business is left in a **wallet-uninitialized** state, fully consistent with §8 item 0's fail-closed rule: every wallet/accounting operation for that Business fails closed with `UsageWalletNotFoundException` until initialization succeeds.
5. **No new retry mechanism is introduced for this path.** `UsageWalletBackfillV1`/`BackfillUsageWalletsCommand` (§9.2) already find and process *any* Business lacking a wallet — pre-existing or newly created, and regardless of why it lacks one — so the same backfill command an operator would already run doubles as the retry path here: correct the Business's `currency_code` (or the `currencies` table data), then re-run `php artisan usage:backfill-wallets`.

**Residual-risk statement, re-verified this round (§5.1):** no third Business-creation path was found. If M1's own implementation discovers one, it is a stop/gap condition (§17), not something to silently route around.

### 9.4 Rollout and rollback safety

Zero newly-gated features at M1 — `RealUsageAuthorizationGateway` is bound (§11), but with every feature `is_metered = false`, its behavior is provably identical to `NullUsageAuthorizationGateway`'s (§11's own regression test proves this directly). No existing entitled, non-metered feature execution is affected. DDL/data operation separation (§6.10) matches RFC-003 §10.1/RFC-004 §25.1. Rollback (`down()`) for every DDL migration is a plain `Schema::dropIfExists()`, in reverse dependency order; the two data-operation migrations' `down()` methods delete only the rows they themselves inserted (classification rows backfilled by migration 8; wallet rows backfilled by migration 9), never an unrelated row, matching RFC-004 M1's own idempotent-`up()`/safe-`down()` discipline (a lesson this exact engagement already had to correct once, in the RFC-004 M1 round — re-applied here proactively).

---

## 10. Architecture boundaries

### 10.1 Manager

`App\Library\Usage\UsageWalletManager` — sole write authority for all seven M1 tables. Constructor-injected with the seven M1 repository contracts. Public methods, exactly:

- `initializeWalletForNewBusiness(int $businessId): void`
- `reserve(Business $business, string $featureKey, string $idempotencyKey, ?string $estimatedQuantity = null): ReservationResult`
- `commit(int $reservationId, ?string $finalQuantity = null): CommitResult`
- `release(int $reservationId): void`
- `setActiveRate(string $featureKey, string $retailRateMicro, string $providerCostMicro, string $unitLabel, int $currencyId, int $actorUserId, string $reason): void` — **corrected this round:** also sets the classification row's `updated_by_user_id = $actorUserId`, the one and only way that column is ever set to a non-`NULL` value (§6.4/§9.1).
- `activateMetering(string $featureKey, int $actorUserId, string $reason): void` — present at M1 (the method the RFC's own §11 names), but **never called by any M1 code path** (no HTTP surface, no M5 feature yet) — a direct test asserts it is unreachable from any M1-authorized route/console command. **Corrected this round:** also sets `updated_by_user_id = $actorUserId` on the classification row it mutates, identically to `setActiveRate()`.
- `evaluateCoarseCapacity(Business $business, PlatformFeature $feature): UsageCapacityDecision` (internal-facing; called by `RealUsageAuthorizationGateway`, never returns past that boundary) — M1's own narrowed evaluation order: `is_metered` (always `false` at M1, short-circuits to authorized) → *(nothing further is ever reached at M1, since no feature is ever metered)*.

### 10.2 Gateway

`App\Library\Entitlement\RealUsageAuthorizationGateway implements UsageAuthorizationGateway` — `check(Business $business, PlatformFeature $feature): UsageAuthorizationResult` delegates to `UsageWalletManager::evaluateCoarseCapacity()`; on denial, always returns exactly `usage_unauthorized` externally (RFC-005 §14), never a finer-grained reason. **No `EntitlementManager` code changes** — only the `AppServiceProvider` binding target changes (§11).

### 10.3 Calendar-month rollover

Implemented as a private `UsageWalletManager` method, invoked lazily at the top of `reserve()` (and, defensively, at the top of `commit()`/`release()` — reading, never re-rolling a wallet mid-operation once its period has been read for that call), reproducing RFC-005 §15's exact eight-step algorithm: resolve `businesses.timezone` (confirmed non-nullable, §5.2) with a platform-default fallback (`config('app.timezone')`) only for the theoretical case of a `NULL` value the schema itself forbids (defensive, never actually reachable); convert `now()` via Carbon; construct local calendar-month first-instant/next-month-first-instant boundaries; convert to UTC; set `_key`/`_start_utc`/`_end_utc`; reset the matching cached counters to zero; applied independently to the spend and recharge periods.

### 10.4 Value objects

`App\Library\Usage\ReservationResult` (readonly: reservation id or denial reason), `App\Library\Usage\CommitResult` (readonly: final amounts, any overage/release entry produced), `App\Library\Usage\UsageCapacityDecision` (readonly, internal-only: authorized bool + reason, never surfaced past the gateway). `EffectivePayer`/`CapEvaluation` (RFC-005 §26) are **not** introduced at M1 — the first requires a payer assignment (M2), the second requires the per-feature/spend-cap/safety-limit tables (M2); both are deferred to M2's own contract.

### 10.5 Authority boundaries

No controller, job, or event listener writes to any M1 table directly — every mutation passes through `UsageWalletManager`. Repositories are plain data-access contracts (matching RFC-004 M1's own "no business-rule/authority logic" boundary, §11 of that contract) — no repository computes a reservation decision, a rate activation, or a coarse-capacity result. No M1 job or listener may create a duplicate wallet or ledger effect — enforced by the idempotency mechanisms in §8/§9. No legacy payment/billing code (`PaymentController.php`, `PaymentMethods`, `Invoices`, `SubscriptionTransaction`) is read, written, or referenced by any M1 code. **Fail-closed until initialized, restated here — §8 item 0:** a Business without a wallet (currency resolution never attempted, or previously failed, §9.2/§9.3) has zero usable wallet/accounting capability at M1 — `reserve()`/`commit()`/`release()` all refuse via `UsageWalletNotFoundException` rather than silently no-op or substitute default state. **Event registration boundary — corrected this round:** `app/Providers/EventServiceProvider.php` is the one exception to "no M1 file besides `AppServiceProvider` is modified" — authorized narrowly for exactly the two additive `$listen` mappings and their imports described in §9.3, never event discovery, never a restructured provider (§12).

---

## 11. RFC-004 compatibility

**M1 changes the `UsageAuthorizationGateway` binding** — `AppServiceProvider`'s `\App\Library\Entitlement\Contracts\UsageAuthorizationGateway::class` entry moves from `\App\Library\Entitlement\NullUsageAuthorizationGateway::class` to `\App\Library\Entitlement\RealUsageAuthorizationGateway::class`. **This is not a default-preserving exception this contract grants itself — it is what RFC-005 v1.4 §36 M1 itself explicitly authorizes** ("Real `RealUsageAuthorizationGateway` bound, every feature non-metered at launch"), the merged design's own affirmative instruction, which this contract must follow rather than override.

**Provably zero behavior change**, locked as a mandatory regression requirement:

- `EntitlementManager::decide()`'s own eight-step algorithm, call signature, and every existing caller are byte-for-byte unmodified — `EntitlementManager.php` is not touched by M1 at all.
- The exact nine denial keys (§5.3) are reproduced unchanged: `platform_feature_unknown`, `platform_feature_unavailable`, `workspace_plan_unassigned`, `denied_by_workspace_override`, `not_entitled_by_plan`, `disabled_for_business`, `plan_suspended`, `plan_inactive`, `usage_unauthorized`. No key is added, removed, or renamed.
- `usage_unauthorized` becomes **reachable in principle** (a real gateway is now bound) but is **never actually returned** by any code path in an M1-era deployment, because `RealUsageAuthorizationGateway::check()`'s very first evaluated condition inside `evaluateCoarseCapacity()` is the feature's `is_metered` flag, which is `false` for all fifteen `PlatformFeature` cases after M1's own backfill (§9.1) and stays `false` for the entire M1 deployment window (§5.7) — every call short-circuits to `authorized: true` before touching any wallet/reservation state.
- A direct regression test (`EntitlementManagerNineKeySurfaceUnchangedTest` or equivalent, §14) proves this: for every one of the fifteen `PlatformFeature` cases, `decide()`'s outcome with `RealUsageAuthorizationGateway` bound is asserted identical to its outcome with `NullUsageAuthorizationGateway` bound, for the same Workspace/Business/entitlement-state fixture — a direct behavioral-equivalence proof, not merely an assertion that the nine key strings exist somewhere in the codebase.
- No `EntitlementManagerConcurrencyTest.php`, `EntitlementManagerDecisionTest.php`, or any other existing RFC-004 test file is modified by M1 — the full existing RFC-004 Entitlement test suite must pass unmodified (§14's regression gate).

---

## 12. Exact implementation allowlist

**Rebuilt mechanically this correction round — 70 unique paths total (53 production + 17 test), not the prior round's 69 (recalculated, not preserved for convenience).** Category subtotals sum exactly to the total; no path appears in more than one category: 9 migrations + 4 enums + 3 value objects + 7 models + 7 repository contracts + 7 Eloquent repositories + 1 gateway + 1 manager + 1 listener + 1 job + 5 exceptions + **7 backfill/support** (was 6 — `app/Providers/EventServiceProvider.php` added this round, §5.4/§9.3/§10.5) + 17 tests = **70**.

No path in this list is a glob or pattern matching more than one file. The nine migration entries are the same partial exception §6.10 already explains (date component only). All other entries are complete, literal, exact paths.

### Migrations (9 new)

1. `database/migrations/{impl_date}_120001_create_business_usage_wallets_table.php`
2. `database/migrations/{impl_date}_120002_create_business_usage_rates_table.php`
3. `database/migrations/{impl_date}_120003_create_business_usage_rate_activations_table.php`
4. `database/migrations/{impl_date}_120004_create_platform_feature_usage_classifications_table.php`
5. `database/migrations/{impl_date}_120005_create_platform_feature_usage_classification_transitions_table.php`
6. `database/migrations/{impl_date}_120006_create_business_usage_reservations_table.php`
7. `database/migrations/{impl_date}_120007_create_business_usage_ledger_entries_table.php`
8. `database/migrations/{impl_date}_120008_backfill_platform_feature_usage_classifications.php`
9. `database/migrations/{impl_date}_120009_backfill_business_usage_wallets.php`

**Deterministic filename rule** (§6.10): exact directory `database/migrations/`; exact semantic names and `_120001`–`_120009` suffix ordering fixed as written; DDL (1–7) strictly precedes data operations (8–9); the date prefix resolves to exactly one real calendar value per migration, not a range.

### Enums (4 new)

10. `app/Enums/Usage/WalletBillingStatus.php`
11. `app/Enums/Usage/RoundingRule.php`
12. `app/Enums/Usage/UsageReservationStatus.php`
13. `app/Enums/Usage/UsageLedgerEntryType.php`

### Value objects (3 new)

14. `app/Library/Usage/ReservationResult.php`
15. `app/Library/Usage/CommitResult.php`
16. `app/Library/Usage/UsageCapacityDecision.php`

### Models (7 new)

17. `app/Models/BusinessUsageWallet.php`
18. `app/Models/BusinessUsageRate.php`
19. `app/Models/BusinessUsageRateActivation.php`
20. `app/Models/PlatformFeatureUsageClassification.php`
21. `app/Models/PlatformFeatureUsageClassificationTransition.php`
22. `app/Models/BusinessUsageReservation.php`
23. `app/Models/BusinessUsageLedgerEntry.php`

### Repository contracts (7 new)

24. `app/Repositories/Contracts/BusinessUsageWalletRepository.php`
25. `app/Repositories/Contracts/BusinessUsageRateRepository.php`
26. `app/Repositories/Contracts/BusinessUsageRateActivationRepository.php`
27. `app/Repositories/Contracts/PlatformFeatureUsageClassificationRepository.php`
28. `app/Repositories/Contracts/PlatformFeatureUsageClassificationTransitionRepository.php`
29. `app/Repositories/Contracts/BusinessUsageReservationRepository.php`
30. `app/Repositories/Contracts/BusinessUsageLedgerEntryRepository.php`

### Eloquent repositories (7 new)

31. `app/Repositories/Eloquent/EloquentBusinessUsageWalletRepository.php`
32. `app/Repositories/Eloquent/EloquentBusinessUsageRateRepository.php`
33. `app/Repositories/Eloquent/EloquentBusinessUsageRateActivationRepository.php`
34. `app/Repositories/Eloquent/EloquentPlatformFeatureUsageClassificationRepository.php`
35. `app/Repositories/Eloquent/EloquentPlatformFeatureUsageClassificationTransitionRepository.php`
36. `app/Repositories/Eloquent/EloquentBusinessUsageReservationRepository.php`
37. `app/Repositories/Eloquent/EloquentBusinessUsageLedgerEntryRepository.php`

### Gateway (1 new)

38. `app/Library/Entitlement/RealUsageAuthorizationGateway.php`

### Manager (1 new)

39. `app/Library/Usage/UsageWalletManager.php`

### Listener (1 new)

40. `app/Listeners/Usage/InitializeBusinessUsageProfile.php`

### Jobs (1 new — `EvaluateBusinessAutoRecharge` removed this round, §5.7)

41. `app/Jobs/Usage/ExpireStaleUsageReservations.php`

### Exceptions (5 new — one renamed this round)

42. `app/Exceptions/Usage/UsageWalletNotFoundException.php`
43. `app/Exceptions/Usage/UsageReservationNotFoundException.php`
44. `app/Exceptions/Usage/InvalidReservationStateTransitionException.php`
45. `app/Exceptions/Usage/NoActiveRateForFeatureException.php`
46. `app/Exceptions/Usage/BusinessCurrencyUnresolvableException.php` — **renamed this round** from the withdrawn `UsageDefaultCurrencyNotConfiguredException`; thrown when a Business's own `currency_code` resolves to zero or multiple active `currencies` rows (§5.5).

### Backfill/support (7 new/modified — `EventServiceProvider.php` added this round)

47. `app/Library/Usage/Migration/UsageWalletBackfillV1.php`
48. `app/Library/Usage/Migration/UsageWalletBackfillResult.php` — content corrected the prior round: adds `currencyResolutionFailures: array` (§9.2).
49. `app/Console/Commands/BackfillUsageWalletsCommand.php`
50. `app/Exceptions/Usage/UsageWalletBackfillIncompleteException.php` — content corrected the prior round: now also carries the run's currency-resolution failure list (§9.2).
51. `app/Exceptions/Usage/PlatformFeatureUsageClassificationBackfillIncompleteException.php` — mirrors §9.1's own final-assertion discipline; if migration 8's classification backfill is interrupted, this exception carries the exact remaining count, matching §9.2's wallet-backfill exception exactly.
52. `app/Providers/AppServiceProvider.php` — **modified, not new**: (a) the existing `UsageAuthorizationGateway::class` binding line's implementation target changes from `NullUsageAuthorizationGateway::class` to `RealUsageAuthorizationGateway::class`; (b) exactly seven additive lines appended to the `$bindings` array, one per M1 repository pair, in a new contiguous group immediately following the existing Entitlement group. No other line in this file changes.
53. `app/Providers/EventServiceProvider.php` — **new this round (§5.4/§9.3/§10.5, Correction Round 2)**, **modified, not new as a file, but newly authorized for M1's own purposes**: exactly two additive `$listen` array entries (`BusinessCreated::class => [InitializeBusinessUsageProfile::class]`, `BusinessAssignedToWorkspace::class => [InitializeBusinessUsageProfile::class]`) and their two corresponding `use` imports. The existing `Registered::class => [SendEmailVerificationNotification::class]` mapping is untouched. No `boot()` change. No `shouldDiscoverEvents()` override — event discovery remains disabled. No other line in this file changes.

### Tests (17 new)

54. `tests/Unit/Usage/UsageEnumsTest.php`
55. `tests/Feature/Usage/BusinessUsageWalletSchemaTest.php`
56. `tests/Feature/Usage/BusinessUsageRateSchemaTest.php`
57. `tests/Feature/Usage/PlatformFeatureUsageClassificationSchemaTest.php`
58. `tests/Feature/Usage/BusinessUsageReservationLedgerSchemaTest.php`
59. `tests/Feature/Usage/UsageWalletManagerReservationLifecycleTest.php`
60. `tests/Feature/Usage/UsageWalletManagerCommittedSpendFormulaTest.php`
61. `tests/Feature/Usage/UsageCalendarMonthRolloverTest.php`
62. `tests/Feature/Usage/UsageWalletManagerConcurrencyTest.php`
63. `tests/Feature/Usage/UsageWalletBackfillV1Test.php`
64. `tests/Feature/Usage/UsageWalletBackfillV1ConcurrencyTest.php`
65. `tests/Feature/Usage/BackfillUsageWalletsCommandTest.php`
66. `tests/Feature/Usage/NewBusinessWalletInitializationTest.php` — **content requirement strengthened this round (§14)**: must prove both real Business-creation paths (via `BusinessManager::createOrUpdateOnboardingBusiness()` and `WorkspaceManager::createBusinessInWorkspace()`, never a hand-rolled event dispatch standing in for them) each result in exactly one wallet through the now-registered listener, and that a genuinely duplicated event delivery for the same Business never creates a second wallet.
67. `tests/Feature/Usage/NoAutoRechargeDispatchAtM1Test.php` — renamed the prior round from `EvaluateBusinessAutoRechargeStubTest.php`, whose original purpose (proving an inert M1 stub never calls Stripe) no longer applies since the job itself is removed from M1; this file now proves the opposite and stronger claim directly (§14).
68. `tests/Feature/Usage/BusinessCurrencyResolutionTest.php` — new the prior round (§14).
69. `tests/Feature/Usage/RealUsageAuthorizationGatewayTest.php`
70. `tests/Feature/Entitlement/EntitlementManagerNineKeySurfaceUnchangedTest.php`

**Seventy total paths (items 1–70 above): 53 production + 17 test, matching §12's own opening count exactly.** **No unrelated path may be authorized.** If M1's own implementation discovers a genuine need for a path not listed above, that is a STOP-and-report condition for a bounded contract amendment (§17) — not something to add silently.

---

## 13. Mechanical searches

Exact commands and expected classifications, run before the M1 implementation PR is considered ready:

1. **Raw M1 table access outside authorized layers**: `rg "DB::table\('(business_usage_wallets|business_usage_rates|business_usage_rate_activations|platform_feature_usage_classifications|platform_feature_usage_classification_transitions|business_usage_reservations|business_usage_ledger_entries)'" app/ --files-with-matches` — expected: only `UsageWalletBackfillV1.php` and the seven Eloquent repository implementations (which may use the query builder internally within their own bounded scope); zero controller/job/listener matches.
2. **Direct model access outside authorized layers**: `rg "(BusinessUsageWallet|BusinessUsageRate|BusinessUsageReservation|BusinessUsageLedgerEntry|PlatformFeatureUsageClassification)::(create|update|delete|save)\(" app/` restricted to files outside `app/Repositories/Eloquent/` and `app/Library/Usage/` — expected: zero matches.
3. **Floating-point money arithmetic**: `rg "\(float\)|floatval\(" app/Library/Usage/ app/Models/BusinessUsage*.php` — expected: zero matches.
4. **Native database enums**: `rg "->enum\(" database/migrations/{impl_date}_120001*.php database/migrations/{impl_date}_120002*.php database/migrations/{impl_date}_120003*.php database/migrations/{impl_date}_120004*.php database/migrations/{impl_date}_120005*.php database/migrations/{impl_date}_120006*.php database/migrations/{impl_date}_120007*.php` — expected: zero matches (all enum-backed columns are `->string(...)`).
5. **Entitlement denial-key drift**: `rg "'(platform_feature_unknown|platform_feature_unavailable|workspace_plan_unassigned|denied_by_workspace_override|not_entitled_by_plan|disabled_for_business|plan_suspended|plan_inactive|usage_unauthorized)'" app/Library/Entitlement/EntitlementManager.php` — expected: exactly the same nine matches present before M1, none added/removed (diffed against the pre-M1 file).
6. **`UsageAuthorizationGateway` binding drift**: `rg "UsageAuthorizationGateway::class =>" app/Providers/AppServiceProvider.php` — expected: exactly one match, target `RealUsageAuthorizationGateway::class`.
7. **Legacy payment-file changes**: `git diff --name-only <base>...<head> | rg "PaymentController|PaymentMethods|Invoices|SubscriptionTransaction|CustomerBasedPricingPlan|PlanSendingCreditPrice"` — expected: zero matches.
8. **Business-creation path coverage**: `rg "BusinessCreated::dispatch|BusinessAssignedToWorkspace::dispatch" app/` — expected: exactly the same two call sites present before M1 (§5.1) — never a third dispatch site.
9. **Synchronous pre-commit event dispatch**: `rg "class (InitializeBusinessUsageProfile)" -A5 app/Listeners/Usage/` confirmed listening only to events already confirmed `ShouldDispatchAfterCommit` (§5.1) — no new event class is introduced by M1 that lacks `ShouldDispatchAfterCommit`.
10. **Controllers/routes/views accidentally introduced**: `git diff --name-only <base>...<head> | rg "app/Http/Controllers|routes/|resources/views"` — expected: zero matches.
11. **RFC-005 concepts outside the authorized allowlist**: `rg "class (BillingProfileManager|PaymentInstrumentManager|UsageBillingCheckoutManager|StripePaymentProviderGateway)" app/` — expected: zero matches (none of these M2/M3/M4 managers exist yet).
12. **`WorkspaceM1BBoundaryTest` scope check**: confirm `tests/Feature/Workspace/WorkspaceM1BBoundaryTest.php::test_no_rfc005_concepts_exist_yet` still passes unmodified — its own scope (Workspace-named files only, RFC-005-DESIGN-CONTRACT.md's own audit finding) does not flag any Business-scoped M1 class, so no update to that test is required or authorized by this contract.
13. **No `EvaluateBusinessAutoRecharge` reference at M1 — new this round**: `rg "EvaluateBusinessAutoRecharge" app/` — expected: zero matches anywhere in `app/` (the class, any dispatch call, and any binding are all M3 scope, §5.7); the same command against `tests/Feature/Usage/` is expected to find matches only inside `NoAutoRechargeDispatchAtM1Test.php`, which asserts the class's absence/non-dispatch rather than exercising it.
14. **No default/fallback currency path remains**: `rg "default_currency_code|UsageDefaultCurrencyNotConfiguredException|config\('usage" app/ config/` — expected: zero matches anywhere (the withdrawn config key, its exception, and the `config/usage.php` file itself must not exist, §5.5).
15. **Listener registration exists exactly once per event — new this round (Correction Round 2, §9.3)**: `rg "InitializeBusinessUsageProfile::class" app/Providers/EventServiceProvider.php` — expected: **exactly two matches**, one inside the `BusinessCreated::class` mapping and one inside the `BusinessAssignedToWorkspace::class` mapping; a third match, or a match count of zero or one, is a failure. `rg "shouldDiscoverEvents"` (repository-wide) — expected: zero matches (event discovery is never enabled). `rg "Registered::class" app/Providers/EventServiceProvider.php` — expected: exactly one match, confirming the pre-existing mapping is untouched.

---

## 14. Required tests and gates

Per-file coverage requirements:

- **`UsageEnumsTest.php`** (Unit): every case of all four M1 enums round-trips its string value; `UsageLedgerEntryType` has exactly twelve cases.
- **`BusinessUsageWalletSchemaTest.php`** (Feature): every column, nullable/default rule, the `UNIQUE (business_id)` and `UNIQUE (id, business_id)` indexes, and `restrictOnDelete()` on `business_id`/`currency_id` rejecting parent deletion while a wallet row exists.
- **`BusinessUsageRateSchemaTest.php`** (Feature): `business_usage_rates` (full immutability — no `updated_at` column exists to even attempt an update through) and `business_usage_rate_activations` schema/constraints, including `UNIQUE (feature_key, version)`.
- **`PlatformFeatureUsageClassificationSchemaTest.php`** (Feature): `platform_feature_usage_classifications` (`UNIQUE (feature_key)`) and `platform_feature_usage_classification_transitions` schema; a direct assertion that the transitions table has zero rows after the M1 backfill runs.
- **`BusinessUsageReservationLedgerSchemaTest.php`** (Feature): `business_usage_reservations`/`business_usage_ledger_entries` full column set, the composite FK `(wallet_id, business_id)` on both tables rejecting a mismatched pair, `UNIQUE (idempotency_key)`/`UNIQUE (correlation_key)`, and a direct assertion that `business_usage_ledger_entries.funding_attempt_id` has no FK constraint at M1 (§6.7's documented deferred-FK note) while still accepting `NULL`.
- **`UsageWalletManagerReservationLifecycleTest.php`** (Feature): `reserve()`→`commit()` (exact-match, under-reservation, and overage paths) and `reserve()`→`release()`, each verified against a directly-seeded wallet balance (§5.7); `expire()` via `ExpireStaleUsageReservations` releasing a `pending` reservation past `expires_at` without committing it; repeat `reserve()` with the same `idempotency_key` returns the original result; repeat `commit()` on an already-committed reservation is a no-op; `commit()`/`release()` on an already-`released`/`expired` reservation throws `InvalidReservationStateTransitionException`; `reserve()` with no active rate throws `NoActiveRateForFeatureException`; outstanding debt denies a new reservation.
- **`UsageWalletManagerCommittedSpendFormulaTest.php`** (Feature): the corrected `-reserved_delta_micro` / `(-available_delta_micro)+debt_delta_micro` formula (RFC-005 §13) is verified exactly against a mixed sequence of exact-match, under-reservation, and overage-with-debt commits, independently cross-checked against a from-scratch ledger recomputation.
- **`UsageCalendarMonthRolloverTest.php`** (Feature): explicit cases for a February rollover (28/29 days), a 31-day month, and a DST spring-forward/fall-back boundary in a seeded Business's own `timezone`, each asserting genuine local calendar-month boundaries; a wallet dormant 3+ months lands directly in the correct current calendar month in one step; a Business timezone change affects only the next period, never retroactively.
- **`UsageWalletManagerConcurrencyTest.php`** (Feature): two workers racing `reserve()` against the same wallet for the final remaining available balance resolve to exactly one winner, mirroring `EntitlementManagerConcurrencyTest.php`'s controlled lock-hold pattern; a simultaneous `reserve()` for a **different** Business's wallet succeeds independently and is unaffected (the unrelated-Business-isolation proof, §8 item 7).
- **`UsageWalletBackfillV1Test.php`** (Feature): a wallet is created for every existing Business, each with the exact backfilled column values (§9.2), **each resolved from that specific Business's own `currency_code`**; a Business with an unresolvable currency (missing/unknown/inactive/ambiguous match) is skipped with no partial wallet/ledger state, its failure recorded in `UsageWalletBackfillResult::$currencyResolutionFailures`, and processing **continues** to the remaining Businesses in the run (§9.2's continue-and-report discipline) rather than aborting; the final assertion fails with the exact remaining count and failure list when any Business is left unwalleted; no legacy payment/billing table is touched.
- **`UsageWalletBackfillV1ConcurrencyTest.php`** (Feature): two simultaneous backfill runs create exactly one wallet per never-before-walleted Business; idempotent full-rerun; partial-rerun safety.
- **`BackfillUsageWalletsCommandTest.php`** (Feature): the command wraps the same action the migration uses; non-zero exit with the exact remaining count on a forced-incomplete scenario.
- **`NewBusinessWalletInitializationTest.php`** (Feature) — **strengthened this round (Correction Round 2, §9.3)**: both `App\Library\Business\BusinessManager::createOrUpdateOnboardingBusiness()` (dispatching real `BusinessCreated`) and `App\Library\Workspace\WorkspaceManager::createBusinessInWorkspace()` (dispatching real `BusinessAssignedToWorkspace`) — the genuine production call paths, never a hand-rolled `Event::dispatch()` standing in for them — each result in exactly one wallet, through the now-registered `InitializeBusinessUsageProfile` listener, never zero, never two; a genuinely duplicated delivery of the same event for the same Business (not merely a second direct manager call) never creates a second wallet.
- **`NoAutoRechargeDispatchAtM1Test.php`** (Feature) — **renamed and redefined this round**: a successful `reserve()` (whose `Reservation` entry has a negative `available_delta_micro`, per RFC-005 §13) dispatches **no job of any kind** related to auto-recharge; `EvaluateBusinessAutoRecharge` is asserted to not exist as a referenced/bound class anywhere in M1's own code (mirroring mechanical search 13, §13); the regression test for §5.7's corrected conclusion that M3, not M1, owns this trigger.
- **`BusinessCurrencyResolutionTest.php`** (Feature) — **new this round**: a valid, active Business currency resolves to the correct `currency_id`; a Business whose `currency_code` matches no `currencies` row fails closed (`BusinessCurrencyUnresolvableException`, classification `not_found`); a Business whose `currency_code` matches an inactive-only `currencies` row (`status = false`) fails closed identically (also `not_found`, since the `status = true` filter excludes it); a Business whose `currency_code` matches two or more active `currencies` rows fails closed with classification `ambiguous`; no fallback/default currency is ever substituted in any of these cases; no wallet row, no ledger entry, and no other partial accounting state exists after any failure (verified by a direct row-count assertion); correcting the Business's `currency_code` (or the `currencies` data) and retrying `initializeWalletForNewBusiness()`/re-running the backfill command succeeds and creates **exactly one** wallet, never a duplicate from the earlier failed attempt; one Business's currency failure during a backfill run does not prevent a different Business in the same run from succeeding (cross-contamination isolation); a later change to `businesses.currency_code` after a wallet already exists does not alter that wallet's `currency_id` or any existing ledger entry's `currency_id` (§7 invariant 11).
- **`RealUsageAuthorizationGatewayTest.php`** (Feature): for every one of the fifteen `PlatformFeature` cases, `check()` returns `authorized: true` given the post-M1-backfill classification state (`is_metered = false` universally); `evaluateCoarseCapacity()`'s internal `UsageCapacityDecision` never leaks past the gateway boundary (return-type assertion).
- **`EntitlementManagerNineKeySurfaceUnchangedTest.php`** (Feature): `decide()`'s outcome is identical between `NullUsageAuthorizationGateway` and `RealUsageAuthorizationGateway`, for the same fixture, across every one of the fifteen `PlatformFeature` cases — the direct regression test for §11's zero-behavior-change claim.

**Six regression commands, locked exactly per RFC-005's own §35 gate shape and this repository's established convention:**

```
php artisan test tests/Unit/Usage tests/Feature/Usage
php artisan test tests/Unit/Entitlement tests/Feature/Entitlement
php artisan test tests/Unit/Workspace tests/Feature/Workspace
php artisan test tests/Feature/Business
php artisan test tests/Feature/Opportunity
php artisan test --stop-on-failure
```

Purpose, in order: (1) M1's own targeted suite; (2) RFC-004 Entitlement regression, confirming the gateway-binding change and additive `AppServiceProvider`/`platform_feature_usage_classifications` changes break nothing already shipped, including the nine-key surface; (3) RFC-003 Workspace regression, confirming the new listener's subscription to `BusinessAssignedToWorkspace` breaks nothing; (4) RFC-001/RFC-003 Business regression, confirming the new listener's subscription to `BusinessCreated` breaks nothing; (5) RFC-002 Opportunity regression, confirming no unrelated cross-domain break; (6) complete application regression, the same final gate every prior RFC-003/RFC-004 milestone used.

Every command must exit successfully and discover a positive test count — a zero/"no tests found" result is a failure. **The human runs these locally; Claude must never claim any of them passed if PHP is unavailable in its own environment** (confirmed unavailable throughout this entire engagement — `php -v` → command not found), **and must never invent a test count.** `ultimatesms_testing` only, never production or another database (`AGENTS.md`).

---

## 15. Acceptance criteria

M1 is acceptance-complete only when each of the following is proven, with implementation evidence (the exact file/method), test evidence (the exact test method), and mechanical-search evidence (§13) where applicable:

1. All seven tables exist exactly as specified in §6, with every column, nullable/default rule, index, and FK verified by `BusinessUsageWalletSchemaTest`/`BusinessUsageRateSchemaTest`/`PlatformFeatureUsageClassificationSchemaTest`/`BusinessUsageReservationLedgerSchemaTest`.
2. `UsageWalletManager::reserve()`/`commit()`/`release()`/expire-via-job all behave exactly per §7/§8, proven by `UsageWalletManagerReservationLifecycleTest`/`UsageWalletManagerCommittedSpendFormulaTest`.
3. Calendar-month rollover is genuinely calendar-correct, proven by `UsageCalendarMonthRolloverTest`.
4. Concurrent reservation races resolve to exactly one winner, and unrelated Businesses are provably isolated, proven by `UsageWalletManagerConcurrencyTest`.
5. Every existing Business, and every newly created Business via both confirmed creation paths — **through the actually-registered `InitializeBusinessUsageProfile` listener** (§9.3, Correction Round 2) — has exactly one wallet **denominated in that Business's own valid currency** — or, for any Business whose currency was unresolvable, no wallet and no partial state at all, with a recorded, retryable failure; a duplicated event delivery for the same Business never creates a second wallet — proven by `UsageWalletBackfillV1Test`/`UsageWalletBackfillV1ConcurrencyTest`/`NewBusinessWalletInitializationTest`/`BusinessCurrencyResolutionTest`.
6. `RealUsageAuthorizationGateway` is bound, and `EntitlementManager::decide()`'s nine-key surface and overall behavior are byte-for-byte unchanged, proven by `RealUsageAuthorizationGatewayTest`/`EntitlementManagerNineKeySurfaceUnchangedTest` and mechanical search 5/6 (§13).
7. **No `EvaluateBusinessAutoRecharge` reference, dispatch, or binding exists anywhere in M1's own code** — that trigger is M3's own responsibility per RFC-005 §36 item 3 — proven by `NoAutoRechargeDispatchAtM1Test` and mechanical search 13 (§13).
8. No legacy payment/billing file, no controller/route/view, no default/fallback currency path, and no M2–M6 RFC-005 concept was touched, proven by mechanical searches 1, 7, 10, 11, 13, 14 (§13) and §16 exact-scope verification.
9. `EventServiceProvider.php` carries exactly the two authorized `$listen` mappings, event discovery remains disabled, and the pre-existing `Registered` mapping is untouched, proven by mechanical search 15 (§13).
10. All six regression gates (§14) pass, with actual results honestly recorded.
11. No unresolved GAP/BLOCKED item exists (§17).

---

## 16. Implementation sequence

Respecting migration/container-binding/event/deployment dependencies:

1. **Enums/value objects** (§12 items 10–16) — no dependency, safe first.
2. **Migrations 1–7 (DDL)** (§12 items 1–7), in the exact §6.10 dependency order.
3. **Models** (§12 items 17–23), depends on migrations existing.
4. **Repository contracts and Eloquent implementations** (§12 items 24–37), depends on models.
5. **Exceptions** (§12 items 42–46) — no dependency beyond PHP itself; may be built alongside step 4. **No config file** — `config/usage.php` was removed this round along with the fallback it supported.
6. **`UsageWalletManager`, `RealUsageAuthorizationGateway`** (§12 items 38–39) — depends on repositories, exceptions, value objects.
7. **`AppServiceProvider` binding update** (§12 item 52) — depends on the gateway and all seven repository pairs existing.
8. **Events/listeners**: `InitializeBusinessUsageProfile` (§12 item 40) — depends on the manager. **`EventServiceProvider` registration** (§12 item 53, new this round) — depends on the listener class existing; the two `$listen` mappings/imports are the step that actually makes the listener fire (§9.3).
9. **Initialization and backfill**: `UsageWalletBackfillV1`/`UsageWalletBackfillResult`/`BackfillUsageWalletsCommand`/its two exceptions (§12 items 47–51), migrations 8–9 (§12 items 8–9) — depends on the manager and the classification/wallet tables; migration 8 before migration 9 (classification rows are independent of wallets, but keeping the documented order avoids any ambiguity).
10. **Jobs**: `ExpireStaleUsageReservations` only (§12 item 41) — depends on the manager. **`EvaluateBusinessAutoRecharge` is not built at M1** (removed a prior round, §5.7).
11. **Tests** (§12 items 54–70) — written alongside each corresponding step above, not deferred to the end; schema tests immediately after their migration, manager/lifecycle tests after the manager, backfill and currency-resolution tests after the backfill action, the gateway/nine-key tests after the `AppServiceProvider` change, `NewBusinessWalletInitializationTest` only after step 8's `EventServiceProvider` registration actually exists (otherwise the real event paths it exercises cannot pass).
12. **Mechanical searches and full regression** (§13/§14) — last, after every prior step is complete.

---

## 17. Stop conditions

Implementation must stop and report if:

- the §12 allowlist needs expansion beyond a listed test/support file turning out genuinely unnecessary;
- a human open decision is required (e.g., a large share of existing Businesses turn out to have genuinely unresolvable currencies in a target deployment environment, indicating a data-quality problem beyond what §5.5's per-Business fail-closed handling alone can remediate);
- any RFC-004 behavior (`EntitlementManager::decide()`, its nine keys, `WorkspaceManager`/`BusinessManager`) must change beyond the one authorized `AppServiceProvider` binding-target edit;
- any legacy payment boundary (`PaymentController.php`, `PaymentMethods`, `Invoices`, `SubscriptionTransaction`, `CustomerBasedPricingPlan`, `PlanSendingCreditPrice`) must be touched;
- schema differs from §6 as specified;
- a third Business-creation path is discovered (§9.3's residual-risk statement);
- the unrelated-Business concurrency isolation invariant (§8 item 7) cannot be demonstrated;
- any of the six regression gates (§14) fails;
- the disposable `ultimatesms_testing` database cannot be confirmed as the target;
- repository evidence is found that conflicts materially with RFC-005 v1.4 or with this contract as specified, making M1 unsafe or impossible as written.

**Do not silently revise the RFC. Do not implement around the gap.** Minor implementation-detail choices this contract or the RFC intentionally leaves to ordinary repository convention (exact PHPDoc style, exact private-method decomposition inside a manager) may be resolved directly during implementation without triggering this rule.

No unresolved conflict remains after this correction round. Both correction rounds' corrections (see "Correction Round 1 record" and "Correction Round 2 record" above) were self-identified defects in this contract's own drafting — Round 1's two (currency-fallback design; the auto-recharge stub) from closer reading of already-available repository/RFC evidence; Round 2's two (the missing `EventServiceProvider.php` allowlist entry; the `updated_by_user_id` nullability contradiction between the schema table and the backfill algorithm) discovered once real implementation was attempted against this contract's own text. None of the four is a conflict with RFC-005 itself requiring a BLOCKED status or a design correction — each was this contract's own drafting getting a narrow, mechanical detail wrong, corrected against evidence that was available at drafting time but not fully applied.

---

## 18. Correction-round policy and governance

`maximum_correction_rounds: 2` — matching every prior RFC-003/RFC-004/RFC-005 contract's bounded-correction discipline exactly. A correction round stays inside the exact §12 path list (extended, not exceeded, by this round's own single narrowly-justified addition, §12); it does not otherwise expand scope. **This document has now used both of its two ordinary correction rounds** (Round 1: currency-fallback and auto-recharge-stub removal; Round 2, this round: listener-registration allowlist gap and the `updated_by_user_id` nullability contradiction) — this is the final ordinary correction round. Any further defect discovered after this round requires either a separately human-authorized exception (mirroring the RFC-005 design document's own remediation-exception precedent, not an ordinary correction round) or a fresh contract.

Locked:

- `human_only_merge: true`
- `advance_automatically: false`
- `start_automatically_after_contract_merge: false`
- `codex_review_required_for_completion: false`
- `automatic_model_handoff_required: false`
- No paid model API or usage-credit requirement at any step.
- No force push. No push directly to `main`. No RFC-005 tag during M1, at any point.
- `docs/automation/AI-AUTONOMY-STATE.json` is not modified by this contract and is not treated as an authorization source for it (confirmed stale/historical, still referencing RFC-003 Milestone 4, carrying no authorization weight for RFC-005 work).

**Implementation authorization semantics after contract merge:** human merge of this contract authorizes exactly one bounded M1 implementation branch/PR (`agent/rfc-005-m1`), created from the then-current `main` containing this merge — but **only after a separate, explicit human instruction to begin implementation**, mirroring this exact engagement's own established two-step pattern (contract merge, then a separate authorization message) rather than RFC-003 Milestone 5/6's fully automatic-on-merge shortcut. `start_automatically_after_contract_merge`/`advance_automatically` both remain `false` throughout.

**M1 completion must not automatically start M2.** M2 requires its own separate, human-reviewed, bounded contract.

---

## 19. M1 completion criteria

M1 is complete only when:

- this contract is human-merged;
- a separate, explicit human instruction to begin M1 implementation has been given;
- the M1 implementation PR stayed inside the exact 70 authorized paths (§12), with no unrelated file touched;
- all seven tables, their models, four enums, three value objects, seven repository contracts + Eloquent implementations, the manager, the gateway, the listener, the one job (`ExpireStaleUsageReservations` — `EvaluateBusinessAutoRecharge` is not built at M1, §5.7), the five exceptions, the `AppServiceProvider` binding/repository additions, and the `EventServiceProvider` listener registration (§9.3, Correction Round 2) are complete and match §6/§10/§12 exactly;
- the classification backfill (§9.1) and the wallet backfill (§9.2) are complete, with each backfill's own final zero-remaining assertion having passed against real data;
- no unresolved GAP/BLOCKED item exists (§17);
- all six required human-run regression commands (§14) pass, with actual results honestly recorded — not fabricated, not assumed;
- `git diff --check` is clean;
- human review is complete and the implementation PR is human-merged.

**No RFC-005 tag is created at any point during M1.** **No automatic M2 start occurs.** No separate M1 closure PR is required by default, mirroring RFC-004 M1's own identical stance.

## 20. M2 non-authorization statement

This contract authorizes **M1 only** — the wallet and ledger data/domain foundation described above. It does not authorize, propose, or select RFC-005 Milestone 2 (budgets, per-feature limits, platform safety limits, billing status transitions, billing contact, payer assignment, the payer-consent authorization model, or any new permission category). M2 requires its own separate, human-reviewed, bounded contract, drafted only after M1 is itself complete — the same discipline this entire multi-RFC engagement has applied between every one of its own milestones.

**Implementation is not authorized under this document until it is human-reviewed and merged, and even then only after a separate, explicit human instruction to begin drafting.**
