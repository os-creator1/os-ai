# RFC-005 Milestone 2 Contract — Budgets, Payer, Billing Contact, and Usage Dashboard

**Status: READY**

**This contract authorizes drafting this one document only. It does not authorize RFC-005 Milestone 2 implementation.** A separate, explicit human instruction is required before any migration, model, repository, manager, listener, controller, request, route, view, or test named below may be written. Merging this contract does not automatically start implementation.

---

## 0. Governance

- Verified base SHA: `7ce3f9dbac7de4bc45ad788e46bee503b6362cbe` (`main`, confirmed `HEAD == origin/main` at drafting time).
- Governing RFC-005 design document: `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md`, version 1.4, governed by `docs/automation/RFC-005-DESIGN-CONTRACT.md` (merged commit `0e74f199bcf13eaf86e0770858c13901323b0eab`, confirmed an ancestor of the base SHA above).
- Governing M1 contract: `docs/automation/RFC-005-M1-CONTRACT.md`, final merged commit `2462492e5e584173ee2cd283f510d9b7cfdf6487` (confirmed an ancestor of the base SHA above).
- M1 implementation: commit `9b53097c9f1571aee65f2b522d56f94d74341b6f` (confirmed an ancestor of the base SHA above), plus the human-authorized remediation commit `9b53097` fixed forward on `main` — all 70 contract-authorized M1 paths and the 3 remediation paths (`tests/Feature/Entitlement/EntitlementManagerDecisionTest.php`, `tests/Feature/Entitlement/NullUsageAuthorizationGatewayTest.php`, `tests/Feature/Entitlement/EntitlementManagerConcurrencyTest.php`) confirmed present and tracked on `main` at the base SHA, verified mechanically (§1 below).
- Branch: `chore/rfc-005-m2-contract`.
- Contract status: **READY** — every design-defined M2 object is scoped or explicitly deferred (§8 self-audit), no financial default is invented, and a real customer-visible dashboard is included without contradicting the merged RFC (§3).
- Merge policy: **human-only**. This contract's own merge does not automatically start implementation. Implementation requires a separate, explicit human instruction, exactly as M1 required after its own contract merged.
- `maximum_correction_rounds: 2` — identical discipline to every prior RFC-004/RFC-005 contract in this repository.
- `docs/automation/AI-AUTONOMY-STATE.json` carries no authorization weight for this contract and is not modified by it (confirmed stale/historical, still referencing RFC-003 Milestone 4, read only).

---

## 1. Mandatory preflight — verified

1. Branch checked out from `main` at `7ce3f9dbac7de4bc45ad788e46bee503b6362cbe`, working tree and staging area confirmed clean before any edit (`git status --short` empty).
2. `git merge-base --is-ancestor 9b53097c9f1571aee65f2b522d56f94d74341b6f HEAD` → **YES**.
3. `git merge-base --is-ancestor 2462492e5e584173ee2cd283f510d9b7cfdf6487 HEAD` → **YES**.
4. `git merge-base --is-ancestor 0e74f199bcf13eaf86e0770858c13901323b0eab HEAD` → **YES**.
5. All 70 M1 contract-allowlisted paths (§12 of the M1 contract) confirmed present and tracked via `git ls-files --error-unmatch` for each, plus the 3 remediation-exception test paths — mechanically verified, zero missing.
6. `git branch --list chore/rfc-005-m2-contract` and `git ls-remote --heads origin chore/rfc-005-m2-contract` both empty before this branch was created.
7. `docs/automation/RFC-005-M2-CONTRACT.md` confirmed absent before this file was written.
8. `git tag --list` confirmed no `rfc-005*` tag exists.

---

## 2. This contract's own exact file scope

This contract-drafting branch may change **exactly one file**: `docs/automation/RFC-005-M2-CONTRACT.md`. No other path is modified, created, deleted, formatted, or staged by this branch.

---

## 3. Mandatory repository and design audit — findings

**Audit item 1 (every RFC-005 object assigned to M2).** RFC-005 §36 item 2, read directly and in full: *"M2 — Budgets, Limits, Payer, and Billing Contact. Schema: `business_feature_usage_limits`, `platform_feature_usage_safety_limits`, `business_usage_limit_transitions`, `business_usage_wallet_billing_status_transitions`, `business_billing_contacts`, `business_payer_assignments`, `business_payer_transitions`. `BillingProfileManager`, including `initializePayerAssignmentForBusiness()` (§32) and the one-time backfill migration covering every Business existing at M2 deploy time. `App\Listeners\Usage\InitializeBusinessUsageProfile` extended with the payer-assignment call. The full payer-consent authorization model (§16/§24), including the narrowed platform-administrator posture. New permission category."* This is the RFC's own authoritative M2 boundary — seven tables, one new manager, one modified M1 listener, one new permission category. Everything else below is derived from this boundary plus the mandatory content areas (§5 A–P of the design contract) applied specifically to it.

**Audit item 2 (27-table split).** M1 already shipped 7 of the RFC's 27 tables (`business_usage_wallets`, `business_usage_ledger_entries`, `business_usage_reservations`, `business_usage_rates`, `business_usage_rate_activations`, `platform_feature_usage_classifications`, `platform_feature_usage_classification_transitions` — confirmed against the M1 contract's own §12 allowlist and the merged migrations on `main`). M2 adds exactly the 7 named in audit item 1. The remaining 13 tables (`payment_provider_customers`, `business_payment_instruments`, `business_funding_attempts`, `business_funding_attempt_transitions`, `additional_business_slot_agreements` and its 3 sibling tables, `business_usage_addon_catalog` and its 2 sibling tables, `business_billing_receipts`, `payment_provider_events`) are M3/M4 scope and are explicitly out of scope for M2 (§4 below).

**Audit item 3 (manager architecture).** RFC-005 §28, read directly: *"Unchanged at five authorities... `UsageWalletManager` (wallets, ledger, reservations, rates/activations, classifications/classification-transitions, limits/limit-transitions, billing-status/transitions, receipts), `BillingProfileManager` (billing contact, payer assignment/transitions...)."* M2 does **not** fold its new tables into `UsageWalletManager` wholesale — the RFC itself splits authority: `business_feature_usage_limits`/`platform_feature_usage_safety_limits`/`business_usage_limit_transitions`/`business_usage_wallet_billing_status_transitions` are `UsageWalletManager`'s own responsibility (an M1 class, extended with new methods at M2 — the identical "ordinary incremental extension across milestones" reasoning the RFC's own §9 already applies to the listener); `business_billing_contacts`/`business_payer_assignments`/`business_payer_transitions` belong to the new `BillingProfileManager`. This contract locks that exact split (§6 below) — `UsageWalletManager` is a **modified M1 path**, not a new M2 path; `BillingProfileManager` is the sole new manager class.

**Audit item 4 (M1 wallet fields already present).** Direct read of the merged `database/migrations/2026_08_16_120001_create_business_usage_wallets_table.php` and `app/Models/BusinessUsageWallet.php` confirms every field M2 needs already exists on the M1 wallet row and needs no new migration: `available_balance_micro`/`reserved_balance_micro`/`debt_balance_micro` (cached balances), `debt_balance_micro > 0` semantics (debt), `billing_status` (`WalletBillingStatus` enum, `active`/`suspended`, defaulted `active`), `spend_period_key`/`spend_period_start_utc`/`spend_period_end_utc` (current period), `committed_spend_this_period_micro` (committed spend), `recharged_this_period_micro`/`consecutive_recharge_failures`/`monthly_recharge_cap_micro` (recharge counters — present but **never set to a non-default value by any M1 or M2 code path**, per the M1 contract's own explicit note, re-confirmed unchanged here), `currency_id` (currency), and every column the ledger presentation needs already exists on `business_usage_ledger_entries` (confirmed by direct read of that migration). **M2 adds zero columns to `business_usage_wallets` or `business_usage_ledger_entries`.** `monthly_spend_cap_micro` also already exists on the M1 wallet row, nullable — M2 is the **first** milestone with a real code path that ever writes a non-null value into it (via the new `UsageWalletManager::setSpendCap()` method, §6.F below); M1 never wrote to it.

**Audit item 5 (Business-creation listeners).** Confirmed by direct read of `app/Listeners/Usage/InitializeBusinessUsageProfile.php` (merged M1 file): it subscribes to `BusinessCreated` and `BusinessAssignedToWorkspace` via `app/Providers/EventServiceProvider.php`'s `$listen` array, calling only `UsageWalletManager::initializeWalletForNewBusiness()` today. RFC-005 §32 requires this exact class be extended with one additional idempotent call to `BillingProfileManager::initializePayerAssignmentForBusiness()` — confirmed no third Business-creation event-dispatch site exists beyond these two (re-verified this round via `grep -rn "BusinessCreated::dispatch\|BusinessAssignedToWorkspace::dispatch" app/`, one call site each, matching the M1 contract's own prior finding and RFC-005 §3's re-confirmation). `app/Providers/EventServiceProvider.php` needs **no change** at M2 — the existing `$listen` mappings already route both events to `InitializeBusinessUsageProfile`; only the listener's own handler bodies gain a new call.

**Audit item 6 (presentation API for default payer without raw plan-table access).** `EntitlementManager::getWorkspaceEntitlementSummary(Workspace $workspace): WorkspaceEntitlementSummary` (confirmed by direct read, `app/Library/Entitlement/EntitlementManager.php:272`) already returns `?WorkspacePlanTier $tier` resolved through `WorkspacePlanCatalog`/`WorkspacePlanAssignment` repositories internally, with no raw table access required by the caller. `BillingProfileManager::initializePayerAssignmentForBusiness()` calls this exact method to resolve the owning Workspace's current tier and applies RFC-005 locked decision 3 (Core/Growth → `workspace`; Agency → `business`) — **never** queries `workspace_plan_catalog`/`workspace_plan_assignments` directly. If `getWorkspaceEntitlementSummary()->isAssigned` is `false` (no plan assigned yet — a legacy-onboarding edge case), the default resolves to `workspace` (the Core/Growth default), since an unassigned Workspace cannot yet be Agency-tier; this is recorded as a category-3 recommended resolution, not an RFC-stated rule, because the RFC does not address an unassigned-Workspace edge case explicitly.

**Audit item 7 (existing customer/admin Business authorization conventions).** Direct read of `app/Http/Controllers/Customer/Workspace/WorkspaceController.php::show()`/`rename()`/`deactivate()` confirms the established pattern this contract's own HTTP surface (§9) must follow exactly: resolve the target by UID via a repository `findByUid()` call (never route-model-binding on the Eloquent model type directly), `abort(404)` — never `403` — on a not-found or unauthorized-existence-leak condition, resolve the actor's effective role via a small `effectiveRoleKey()`-style helper, delegate all authorization/business-rule decisions to a Manager method wrapped in `try`/`catch` against named exceptions, converted to a flash-message redirect on failure. No controller in this repository re-implements Workspace/Business authorization logic inline.

**Audit item 8 (permissions).** `config/permissions.php`, read directly: RFC-004's own precedent for a new milestone-introduced Spatie permission category is `'category' => 'Workspace Plans'` with a `view <noun>`/`manage <noun>` key pair (read/manage split, confirmed at `config/permissions.php:875-884`). This category governs the **admin** surface only — the existing customer-side `WorkspaceController` uses zero Spatie permission checks, relying entirely on the Workspace/Business role model instead (confirmed by the complete absence of `Gate::`/`can(`/`@can` calls in that controller). RFC-005 §24's own authorization matrix is expressed entirely in terms of Workspace owner/Admin/Staff/Business owner/platform administrator — the identical role-based model, not a Spatie permission check, for every non-platform-administrator row. M2 therefore follows the same split RFC-004 already established: a new Spatie category (`Business Usage Billing`, `view business usage billing`/`manage business usage billing`) is added **for the platform-administrator capabilities RFC-005 §24 lists**, matching the RFC's own explicit "New permission category" instruction — but the customer-facing dashboard controller (§9) uses the existing Workspace/Business role model exclusively, zero Spatie checks, mirroring `WorkspaceController` exactly.

**Audit item 9 (sidebar/navigation).** No existing dedicated Workspace/Business sidebar entry was found anywhere in `resources/views` (mechanically searched: no `resources/menu`, no `config/*menu*`, no sidebar partial referencing `customer.workspaces.show`). The sole existing entry point into Workspace/Business-scoped functionality is `resources/views/customer/workspaces/show.blade.php` (the Workspace detail page, listing that Workspace's Businesses). **Finding, recorded honestly rather than invented:** this repository does not yet have a general-purpose customer sidebar/nav system for Workspace-scoped features to register into. M2 therefore adds its own dashboard link directly on the existing `workspaces/show.blade.php` page, next to each Business row (one link per Business, visible only when the current actor's role for that Business grants at least view access, §7 below) — **modifying** that one pre-existing, non-M1 file rather than inventing a nav system this milestone has no authorization to build. This is recorded as a category-3 recommended resolution.

**Audit item 10 (pagination/presentation patterns).** No strong existing pagination precedent exists inside the Entitlement/Workspace controllers (the one repository-wide `paginate()` example found, `ChatBoxController.php:626`, is unrelated legacy code). M2 uses Laravel's standard `LengthAwarePaginator` via `BusinessUsageLedgerEntryRepository::paginateForBusiness()` (a new repository method — the M1 ledger repository contract currently exposes only `create()`, confirmed by direct read of `app/Repositories/Contracts/BusinessUsageLedgerEntryRepository.php`), consistent with Laravel/Ultimate SMS's own idiomatic pagination rather than inventing a bespoke mechanism.

**Audit item 11 (does the merged RFC assign HTTP/UI to M2?) — the central scope finding of this audit, resolved below in §3.1.**

### 3.1 The HTTP/UI milestone-assignment gap, and this contract's resolution

**Finding, stated exactly as evidence shows it, not assumed:** RFC-005 §30 ("HTTP/admin/customer surfaces and permissions") specifies **what** the customer and admin surfaces must contain in detail, but RFC-005 §36 (the milestone-decomposition section, the RFC's own authoritative per-milestone scope boundary) **never assigns HTTP/UI implementation to any specific milestone** — not M1 (which explicitly says "No HTTP surface"), not M2, not M3, not M4, not M5, and M6 is conformance/deployment only. This is a genuine gap in the RFC's own milestone breakdown: content is fully specified (§30), but the RFC never states which milestone builds it.

This is **not** the same thing as "the RFC explicitly excludes all HTTP/UI work from M2" (the condition this contract's own governing task instruction requires marking `GAP`/`BLOCKED` for). M1's milestone text contains an **explicit** exclusion, scoped to M1 only ("No HTTP surface, no Stripe"). M2's own milestone text contains **no such exclusion** — it is simply silent on HTTP/UI, exactly as silent as M3, M4, and M5 are. Silence is not the same as exclusion, and nothing in the RFC forbids HTTP/UI work from happening at M2.

Given that:

1. M2 is the **first** milestone at which enough backing data exists to render a genuinely useful, non-trivial dashboard — M1 alone (wallet + ledger, no caps, no payer, no contact) would produce a page with no configurable content at all; M2 adds exactly the configuration surfaces (caps, limits, payer, contact) that make a dashboard meaningful;
2. §30's own content requirements (balance/ledger/payer/instruments/recharge-policy/failure-state presentation) describe **exactly** the shape of data M1+M2 jointly produce, with the payment-instrument/recharge-execution portions explicitly deferred (§30 never claims M2 has instruments — those are M3);
3. Building it now avoids shipping two consecutive milestones (M1, M2) with zero customer-visible value, which the RFC's own Goal 1 ("Give every Business an isolated usage wallet, append-only ledger, billing contact, and payer") only becomes observable to an actual customer once a real page exists;

this contract resolves the gap as a **category-3 recommended design decision**: **M2 ships a real, read-mostly, Business-scoped Usage & Billing dashboard**, scoped narrowly to exactly the data M1 and M2's own tables produce, with every payment-execution control explicitly represented as unavailable rather than rendered as a disabled fake control (§5). This does not reopen, reverse, or contradict any RFC-005 locked decision, and does not require any path the RFC did not already specify the content for. It is recorded here, transparently, as this contract's own resolution of an RFC-level milestone-assignment gap — not something the RFC itself states outright.

**No STOP/BLOCKED marker is applied to this finding**, because the condition that would require one (an explicit RFC exclusion) does not hold.

---

## 4. Exact M2 exclusions

The following are explicitly **not** implemented by M2, regardless of any adjacency to M2's own tables or managers:

- Any Stripe SDK use, `stripe/stripe-php` reference, or provider abstraction (`PaymentProviderGateway`/`StripePaymentProviderGateway`) — M3.
- `payment_provider_customers`, `business_payment_instruments`, and any provider-customer/instrument concept — M3.
- Checkout Sessions, SetupIntents, or PaymentIntents of any kind.
- Any webhook route, `payment_provider_events` table, or webhook-event processing of any kind — M3.
- `business_funding_attempts` and its transition table — M3.
- Actual auto-recharge evaluation, dispatch, or execution (`EvaluateBusinessAutoRecharge`) — explicitly excluded at M1 already (M1 contract Correction Round 1) and remains excluded at M2; the job class does not exist after M2 either.
- Top-ups, promotional credits, manual credits, or any wallet-crediting action of any kind.
- Refunds, chargebacks, or disputes.
- Invoices, tax/VAT calculation, or receipts (`business_billing_receipts`) — M3/M4, gated by §23's legal-sufficiency posture regardless.
- `additional_business_slot_agreements` and every sibling table, and any additional-Business-slot checkout/purchase flow — M4.
- `business_usage_addon_catalog`/`business_usage_addon_purchases` and any add-on purchase flow — M4.
- M3–M6 work of any kind.
- Any refactor of the legacy `PaymentController.php`/`PaymentMethods`/`Invoices`/`SubscriptionTransaction` stack.
- Any RFC-004 amendment (including the cross-RFC additional-slot allocation blocker — M4's concern, not M2's).
- Any raw pricing/operator tool beyond what RFC-004 already ships.
- Any release/tag work (RFC-005 tagging is exclusively M6's concern).
- Enabling metering for any `PlatformFeature` (`is_metered` remains `false` for all fifteen cases after M2, exactly as after M1 — M5's concern only).
- Setting `EntitlementManager.php` behavior of any kind — that file remains byte-for-byte unmodified by M2, exactly as it was unmodified by M1.

---

## 5. The M2 customer-visible surface — exact scope

Per §3.1's resolution, M2 ships **exactly one** new customer-facing page: a read-mostly, Business-scoped **Usage & Billing dashboard**, plus the narrow set of mutations RFC-005 §24 explicitly assigns to Workspace/Business-level (non-platform-administrator) actors for M2's own owned tables.

**Visible sections, each sourced only from data M1 or M2 actually produces:**

1. **Business and billing-status context** — Business name, current `billing_status` (`active`/`suspended`), and, when `suspended`, an honest static explanation ("This Business's usage wallet is suspended. Contact support.") — never a functional "resolve" control (billing-status mutation is platform-administrator-only, §7).
2. **Available / reserved / debt balances** — the three cached wallet buckets, currency-formatted from the wallet's own `currency_id`, integer micro-units retained internally end-to-end.
3. **Current calendar-month committed spend** — `committed_spend_this_period_micro`, labeled with the wallet's own `spend_period_key` (e.g. "August 2026").
4. **Monthly spend cap and remaining headroom** — `monthly_spend_cap_micro` if configured, else "No spend cap configured" (never a fabricated default); remaining headroom = `max(0, cap - committed - reserved)` when a cap is configured, computed by `UsageWalletManager`/the presenter, never recomputed in Blade.
5. **Per-feature usage limits** — every `business_feature_usage_limits` row for this Business, each feature's own configured limit or "No limit configured," plus the applicable platform safety-limit ceiling if one exists for that feature (read-only display of the ceiling; the ceiling itself is never customer-editable).
6. **Current payer assignment** — `workspace` or `business`, in plain language ("This Business's usage is billed to the Workspace" / "This Business pays for its own usage"), with a mutation control gated exactly per §7's consent matrix.
7. **Billing-contact details** — name/email (resolved from `contact_user_id` if set, else the independent `contact_name`/`contact_email` fields), with an edit control.
8. **Recent ledger activity** — a paginated, reverse-chronological list of `business_usage_ledger_entries` for this Business's wallet, showing `entry_type`, signed amount (derived from whichever delta column is non-zero for that entry type — read-only presentation, never re-deriving a balance), `created_at`, and `feature_key` when present. Since no `Reservation`/`UsageCharge` entries can exist yet (no feature is metered before M5), this section legitimately renders empty for every Business at M2 launch — an accurate, not a broken, empty state.
9. **Clear empty/unavailable states** — no wallet yet (`UsageWalletNotFoundException` surfaces as "Usage tracking has not been set up for this Business yet," never a 500 or blank page); no ledger entries yet ("No usage activity yet"); debt present (`debt_balance_micro > 0`, an honest red-flagged notice, no "pay now" control); suspended (item 1); no payer assignment yet (`BillingProfileManager` guarantees one exists post-M2-backfill/listener, but the view defends against a `null` read anyway, rendering "Not yet configured" rather than crashing).

**Explicitly, per this contract's own binding instruction, M2 renders NO functional-looking control for:** adding a card; Stripe Checkout; top-ups; refunds; auto-recharge execution (the view never renders an "enable auto-recharge" toggle at all — not even a disabled one, since no instrument exists to charge); invoices or receipts; paid add-ons; additional-slot purchases. Where later-payment information is relevant, the dashboard uses plain informational text: **"Payment methods and top-ups are not yet configured for this Business."** — never a disabled button, grayed-out form, or any element that visually implies a working control exists.

**Customer-editable at M2 (each gated per §7):** payer assignment; billing contact; monthly spend cap; per-feature limits. Nothing else on the page is mutable by a customer.

---

## 6. Exact M2 scope by concept

### 6.A Business monthly usage/spend budget

`business_usage_wallets.monthly_spend_cap_micro` (already exists, M1 schema) — M2 introduces the **first** code path that ever writes a non-null value: `UsageWalletManager::setSpendCap(Business $business, ?string $capMicro, int $actorUserId, string $reason): void`. Nullable = platform-safety-limit-bounded only (no aggregate platform safety ceiling table exists for the spend cap in this RFC's schema — only per-feature safety ceilings, §6.C below); a `null` value is a fully legitimate, permanent configuration state, never a placeholder awaiting a default. **No numeric default is ever written by any M2 code path** — every wallet's `monthly_spend_cap_micro` remains exactly what M1 left it (`NULL`) until an authorized actor explicitly sets one.

### 6.B Per-feature limits

`business_feature_usage_limits` (new M2 table) — one row per (Business, feature_key) an authorized actor has explicitly configured; absence of a row means "no limit configured for this feature," not zero. `UsageWalletManager::setFeatureLimit(Business $business, string $featureKey, ?string $limitMicro, int $actorUserId, string $reason): void`. `$featureKey` is validated through `PlatformFeatureRegistry::isAvailable()` — matching RFC-004's own floor (locked decision 13) — but the feature need **not** already be `is_metered = true`; a Business may pre-configure a limit for any available feature ahead of that feature's own future M5 metering activation, so the configuration is already in place the moment M5 flips it on. This is a category-3 recommended resolution — the RFC does not state this ordering explicitly, but nothing in the RFC forbids it, and requiring metering-first would make it impossible to ever configure a limit before the exact instant a feature goes live, an unnecessarily hostile ordering with no stated justification.

### 6.C Platform safety limits

`platform_feature_usage_safety_limits` (new M2 table, one row per feature_key an administrator has explicitly configured a ceiling for) — `UsageWalletManager::setSafetyLimit(string $featureKey, string $maxMonthlyLimitMicro, int $actorUserId, string $reason): void`, **platform-administrator-only** (re-using the exact `assertPlatformAdministrator()` pattern `EntitlementManager::setAdditionalBusinessSlots()` already established, confirmed by direct read). **M2 ships this table and method, but no M2 code path ever calls it in production** — no feature is metered until M5, so there is nothing to bound yet. This mirrors the M1 contract's own established precedent exactly: `business_usage_rates`/`business_usage_rate_activations` shipped fully functional at M1 with zero rows and zero calls from any M1 production path, proven inert by a dedicated test. M2 does the same here, proven by `UsageWalletManagerSafetyLimitTest.php` (§13) calling the method directly (never via HTTP — no admin route exists at M2, §9) and by a dedicated inert-at-M2 assertion mirroring `NoAutoRechargeDispatchAtM1Test.php`'s own precedent.

**Human requirement 4's enforcement — customer limit bounded above by platform safety limit:** `setFeatureLimit()` rejects (throws `FeatureLimitExceedsPlatformSafetyLimitException`) any attempt to set `$limitMicro` above the applicable `platform_feature_usage_safety_limits.max_monthly_limit_micro` **if a safety-limit row exists for that feature**; if none exists yet, the customer-configured value is accepted as-is (there is nothing to bound against). A customer may always tighten (lower) their own limit; never loosen it past a configured platform ceiling.

### 6.D Formula-derived counters — never manually mutated (unchanged from M1/RFC)

`committed_spend_this_period_micro` and `recharged_this_period_micro` remain exactly what the RFC's v1.4 surgical patch (§13/§19) states: formula-derived cached values, **never** directly or manually mutated by anyone, including a platform administrator, including via any M2 method. `setSpendCap()`/`setFeatureLimit()`/`setSafetyLimit()` write only to their own configuration tables (`business_usage_wallets.monthly_spend_cap_micro`, `business_feature_usage_limits`, `platform_feature_usage_safety_limits`) plus their own `business_usage_limit_transitions` audit row — never to `committed_spend_this_period_micro`/`reserved_spend_this_period_micro`/`recharged_this_period_micro`. A cap or limit change is **prospective only** — it changes future reservation-admission evaluation (a capability that has no live caller until M5 activates metering); it never rewrites, reverses, or reinterprets already-committed historical spend. **Setting a cap or limit below already-committed current-period spend is explicitly allowed, not rejected** — the new, lower value simply means zero (or reduced) headroom remains for the rest of the current period; no exception is thrown, no historical entry is touched. This is a category-3 recommended resolution, consistent with the RFC's own "prospective only" framing (§13/§15) applied to the one concrete question the RFC itself does not spell out.

### 6.E Payer assignment and transitions

`business_payer_assignments`/`business_payer_transitions` (new M2 tables) — `BillingProfileManager::initializePayerAssignmentForBusiness(int $businessId): void` (idempotent — a Business that already has an assignment is a no-op, mirroring `UsageWalletManager::initializeWalletForNewBusiness()`'s own established idempotency pattern exactly) and `BillingProfileManager::changePayer(Business $business, PayerType $payerType, int $actorUserId, string $reason): BusinessPayerAssignment` (explicit reassignment, consent-gated per §7).

**Default resolution (locked decision 3, applied via audit item 6's presentation-API-only rule):** Core/Growth → `workspace`; Agency → `business`; unassigned Workspace → `workspace` (category-3 resolution, audit item 6). `effective_payment_instrument_id` starts `null` regardless of `payer_type` — no instrument exists until M3.

**Exact behavior for every scenario the governing task requires:**

- **Initial assignment** — created exactly once, by the listener (new Business) or the backfill migration (pre-existing Business), never twice; a duplicate-delivery race resolves via the identical narrow duplicate-key-race catch pattern `UsageWalletManager::initializeWalletForNewBusiness()` already established (`unique(business_id)` on `business_payer_assignments`, MySQL driver error 1062 narrowly matched).
- **Explicit reassignment** — `changePayer()`, consent-gated (§7); always creates a `business_payer_transitions` row; never touches `effective_payment_instrument_id` (that column remains `null` until M3 regardless of `payer_type`).
- **Plan changes** — RFC-004's `EntitlementManager::changePlan()` does **not** call `BillingProfileManager` and `BillingProfileManager` does **not** call `EntitlementManager` — a plan-tier change **never** automatically re-defaults an already-assigned Business's payer. The default resolution (above) applies **only** at initial assignment; once assigned, a payer stays exactly what it is until an authorized actor explicitly reassigns it, regardless of any later plan change. This is the direct, necessary consequence of locked decision 5 ("payer change affects future charges only," implying a payer is not silently changed by anything other than an explicit, authorized, audited transition) applied to the plan-change scenario specifically — a category-3 resolution, since the RFC does not spell out this exact scenario, but no other reading is consistent with locked decision 5.
- **Ownership changes** (`transferOwnership()`, RFC-003/004) — does not touch `business_payer_assignments`; the new owner inherits whatever payer assignment already existed. If `payer_type = 'business'`, the "direct Business owner/customer" consent authority for a **future** `changePayer()` call now resolves against the new owner, evaluated live at call time (§7) — never retroactively.
- **Workspace transfers / Business reassignment** (`reassignBusiness()`, RFC-003/004) — does not touch `business_payer_assignments` or its history; the assignment (and its full transition audit) travels with the Business unchanged, exactly as locked decision 5 requires for the wallet itself.
- **Deleted/deactivated members** — a deactivated Workspace Admin/Staff member loses payer-mutation consent authority immediately (consent is evaluated live against current membership state at call time, never cached); a payer assignment whose `effective_payment_instrument_id` is `null` (always true at M2) has nothing further to invalidate.
- **Businesses without a valid wallet** (a currency-resolution failure left it wallet-uninitialized, per M1's own documented fail-closed behavior) — `initializePayerAssignmentForBusiness()` does **not** require a wallet to exist first (`business_payer_assignments` has no FK to `business_usage_wallets`, only a plain `business_id` FK to `businesses`, confirmed by direct RFC schema read, §16); a Business may have a payer assignment before, or even without ever having, a usable wallet. The dashboard (§5) independently handles the no-wallet case via its own empty state.
- **Duplicate/idempotent requests** — a repeated `changePayer()` call with the same target `payer_type` as the current value is accepted as a no-op that still records a transition row (`from_payer_type === to_payer_type`) if and only if the actor is genuinely authorized to make that call — recording an audited "reaffirmation" is deliberately not suppressed, since suppressing it would hide a legitimate, consented reaffirmation from the audit trail; this is a category-3 resolution.

### 6.F Billing contact

`business_billing_contacts` (new M2 table) — `BillingProfileManager::updateBillingContact(Business $business, ?int $contactUserId, ?string $contactName, ?string $contactEmail, bool $notificationOptIn, int $actorUserId): BusinessBillingContact`. Created lazily on first write (no listener/backfill needed — a `null`/unconfigured billing contact is a fully legitimate M2 state, distinct from payer assignment, which the RFC requires to always exist); the dashboard (§5) renders "No billing contact configured" when absent.

- **Reference model** — `contact_user_id` nullable FK to `users.id`; if set, `contact_name`/`contact_email` are ignored for **display** (the live `User` row's own name/email is read instead) but may still be independently stored (the RFC schema does not forbid storing both; this contract does not require it either — category-3: **not** storing redundant name/email when `contact_user_id` is set, to avoid the two ever silently disagreeing). If `contact_user_id` is `null`, `contact_name` **and** `contact_email` are both required together (manager-enforced via `InvalidBillingContactDataException`, defense-in-depth alongside the FormRequest's own validation).
- **Fields** — name, email (both above); no phone field exists in the RFC's own `business_billing_contacts` schema (§17.A, read directly and in full — RFC does not list a phone column), so none is added; no company/legal-name, address, or tax field exists in the RFC schema either, so none is added — the task's own instruction to "address... only if genuinely assigned to M2" is satisfied by their simple absence from the RFC's own table definition.
- **Locale/timezone** — not required; the RFC's `business_billing_contacts` schema carries no such column.
- **Notification fallback** — `notification_opt_in` (boolean, default `true`, per RFC schema) governs whether this contact receives future billing notifications (M3+'s own concern to actually send any — no notification job exists at or before M2); no fallback recipient logic is built at M2, since no notification is ever sent at M2.
- **Workspace-versus-Business authority** — Business-scoped only (§7); a Workspace owner/Admin may manage it for a Business the membership scope covers (RFC §24's own matrix), but the row itself is never Workspace-scoped — no `workspace_id` column exists on `business_billing_contacts`.
- **Cross-Business isolation** — `business_id` unique FK; a repository lookup is always scoped by the caller's own already-authorized `$business`, never by an unscoped `contact_id` alone, and the controller (§9) never accepts a raw contact ID from request input.
- **Payer changes never rewrite old snapshots** — no M2 code path ever writes to `business_funding_attempts.billing_contact_name_snapshot`/`billing_contact_email_snapshot` (that table is M3 scope entirely); `business_billing_contacts` itself has no snapshot concept — it is the **current** configuration, not a historical record, and a payer change (§6.E) never touches it.
- **Contact updates create no transition table** — the RFC's own §18 narrows this explicitly ("`payment_provider_customers.status`... likewise not separately transition-audited"); `business_billing_contacts` is not in that narrowed list, but it is also not in RFC-005 §36's M2 schema list as owning its own transition table (only `business_payer_transitions` and `business_usage_limit_transitions` are named for M2) — **M2 does not add a `business_billing_contact_transitions` table.** The current row's own `updated_at`/`updated_by_user_id` is the extent of this history, mirroring the RFC's own explicitly-narrowed precedent for `business_payment_instruments.is_default`/`payment_provider_customers.status`. This is a category-3 resolution, directly reasoned from the RFC's own established narrowing pattern rather than inventing a new table the RFC's own M2 schema list does not name.

### 6.G Wallet billing-status transitions

`business_usage_wallet_billing_status_transitions` (new M2 table, per RFC §12/§36) — `UsageWalletManager::setBillingStatus(Business $business, WalletBillingStatus $status, BillingStatusTransitionSource $source, ?int $actorUserId, string $reason): void`, platform-administrator-only for `source = admin_action` (RFC §24: "Set/clear `billing_status = 'suspended'` | No | No | No | No | Yes only"). **M2 ships this table and method, but no M2 code path ever calls it in production** — `source = dispute_webhook` requires Stripe webhook processing (M3), and no admin HTTP route exists at M2 (§9) to trigger `source = admin_action` either. Proven inert exactly like §6.C's safety-limit method, via a direct manager-level test (§13) and a dedicated inert-at-M2 regression assertion.

---

## 7. Authorization and isolation — exact actor/action matrix

Every unrelated-Workspace/unrelated-Business access **fails closed with a 404**, never a 403 — matching `WorkspaceController`'s own established convention exactly (audit item 7). Every action below is evaluated against the wallet/assignment's **actual current** Business/Workspace scope, live at call time, never cached.

| Action | Workspace owner | Active Workspace Admin | Staff | Direct Business owner/customer | Unrelated Workspace member | Platform administrator | Anonymous |
|---|---|---|---|---|---|---|---|
| View dashboard (§5) | Yes | Yes, if `business_access_scope` covers this Business | Yes, if scope covers it | Yes, own Business | 404 | Yes, any Business | Redirect to login |
| View ledger (§5 item 8) | Yes (same as dashboard) | Yes, if scope covers it | Yes, if scope covers it | Yes, own Business | 404 | Yes | Redirect to login |
| Change monthly spend cap | Yes | Yes, if scope covers it, bounded by any configured platform safety limit | No | Yes, own Business, bounded by any configured platform safety limit | 404 | Yes, including the platform safety limit itself (no HTTP route at M2, §9) | Redirect to login |
| Change per-feature limit | Yes | Yes, if scope covers it, bounded | No | Yes, own Business, bounded | 404 | Yes (no HTTP route at M2) | Redirect to login |
| Choose payer = `workspace` | Yes | No | No | No | 404 | Yes, mandatory reason (no HTTP route at M2) | Redirect to login |
| Choose payer = `business` | No | No | No | Yes, own Business | 404 | Yes, mandatory reason (no HTTP route at M2) | Redirect to login |
| Change billing contact | Yes | Yes, if scope covers it | No | Yes, own Business | 404 | Yes (no HTTP route at M2) | Redirect to login |
| View audit history (limit/payer transitions) | Yes | Yes, if scope covers it | No | Yes, own Business | 404 | Yes | Redirect to login |
| Mutate `billing_status` | No | No | No | No | 404 | Yes only, mandatory reason (no HTTP route at M2 — manager-level only, §6.G) | Redirect to login |

**Staff never mutates anything** — matching RFC §24's own "Staff: No" for every mutation row exactly; Staff may only view, and only if `business_access_scope` covers the specific Business.

**Workspace-owner-selects-`workspace`-payer / Business-owner-selects-`business`-payer is absolute** — neither side may ever set the *other* party's payer type, under any role, at M2 (no platform-administrator HTTP override exists at M2 either, since no admin route ships). This directly satisfies the task's own binding requirement: *"Neither side may volunteer the other party's money or future instrument"* — trivially true at M2 in the strongest possible sense, since no instrument of any kind exists yet to volunteer.

---

## 8. Contract self-audit

1. **Every design-defined M2 object is scoped or explicitly deferred.** §3 audit items 1–2 confirm all 7 M2 tables and the 1 new manager are scoped; §4 confirms every M3+ object is explicitly deferred by name.
2. **All 14 open decisions are classified.** §14a below.
3. **M2 contains a real visible dashboard, not `BLOCKED`.** §3.1, §5.
4. **No fake payment UI exists.** §5's explicit exclusion list; enforced by `NoFakePaymentControlsRenderedTest.php` (§13).
5. **Payer consent cannot volunteer another party's money.** §7 — structurally impossible at M2 (no instrument exists).
6. **M1/M2 initialization remains correctly split.** §3 audit item 5, §6.E — `InitializeBusinessUsageProfile` extended, never rewritten; M1's own `initializeWalletForNewBusiness()` untouched.
7. **All money remains integer micro-units.** Every new column is `bigint`/`bigint unsigned`, matching the RFC's own §10 representation; no `decimal`/`float` money column anywhere in §11.
8. **All schemas are exact.** §11 — every column individually typed, nullable, defaulted, and indexed; no grouped columns, no native `ENUM`.
9. **Authorization and 404 behavior are deterministic.** §7.
10. **Every implementation path is individually allowlisted.** §12.
11. **Counts match.** §12's own subtotal arithmetic, verified by addition.
12. **Six regression gates are exact.** §15.
13. **Contract merge does not auto-start implementation.** §0.
14. **No production/test file changed by this contract.** Verified — this branch touches exactly `docs/automation/RFC-005-M2-CONTRACT.md` (§16).

---

## 9. Usage & Billing HTTP/UI contract

**Customer routes** (in `routes/customer.php`, inside the existing `workspaces.` prefix group, nested under an existing accessible Workspace and Business exactly like every other Business-scoped RFC-003/004 route):

| Method | URI | Name | Controller action |
|---|---|---|---|
| GET | `workspaces/{workspaceUid}/businesses/{businessUid}/usage-billing` | `workspaces.businesses.usage-billing.show` | `UsageBillingController@show` |
| POST | `workspaces/{workspaceUid}/businesses/{businessUid}/usage-billing/payer` | `workspaces.businesses.usage-billing.payer` | `UsageBillingController@updatePayer` |
| POST | `workspaces/{workspaceUid}/businesses/{businessUid}/usage-billing/billing-contact` | `workspaces.businesses.usage-billing.billing-contact` | `UsageBillingController@updateBillingContact` |
| POST | `workspaces/{workspaceUid}/businesses/{businessUid}/usage-billing/spend-cap` | `workspaces.businesses.usage-billing.spend-cap` | `UsageBillingController@updateSpendCap` |
| POST | `workspaces/{workspaceUid}/businesses/{businessUid}/usage-billing/feature-limits/{featureKey}` | `workspaces.businesses.usage-billing.feature-limit` | `UsageBillingController@updateFeatureLimit` |

Every mutation route uses `POST` (never `PATCH`/`DELETE`), matching `WorkspaceController`'s own established convention exactly (`rename`/`deactivate`/`reactivate` are all `POST`, confirmed by direct read of `routes/customer.php`).

**Controller** — `App\Http\Controllers\Customer\Business\UsageBillingController`, constructor-injected with `UsageBillingPresenter`, `UsageWalletManager`, `BillingProfileManager`. Resolves `$workspaceUid`/`$businessUid` to models via repository `findByUid()` calls (never implicit route-model binding), resolves the actor's effective role via the identical `effectiveRoleKey()`-style pattern `WorkspaceController` already uses, `abort(404)` on any not-found or scope-mismatch condition — never re-implementing Workspace/Business authorization inline. Every manager-thrown exception (`UnauthorizedPayerAssignmentException`, `UnauthorizedUsageBillingManagementException`, `FeatureLimitExceedsPlatformSafetyLimitException`, `InvalidBillingContactDataException`, `UsageWalletNotFoundException`) is caught and converted to a flash-message redirect, mirroring `WorkspaceController::rename()`'s own established exception-to-flash-message pattern exactly.

**FormRequests:**

- `UpdateBusinessPayerRequest` — `payer_type` required, `in:business,workspace` (never `agency_rebill` — that value is never customer-selectable at M2, and is inert platform-wide until a future, separately authorized milestone).
- `UpdateBusinessBillingContactRequest` — `contact_user_id` nullable, exists in `users`; `contact_name`/`contact_email` each `required_without:contact_user_id` when `contact_user_id` is null, `nullable` otherwise; `contact_email` `email` format when present; `notification_opt_in` boolean.
- `UpdateBusinessSpendCapRequest` — `monthly_spend_cap_micro` nullable, `integer`, `min:0`.
- `UpdateBusinessFeatureLimitRequest` — `feature_key` required, validated against `PlatformFeatureRegistry::isAvailable()`; `monthly_limit_micro` nullable, `integer`, `min:0`.

**Validation-error behavior** — standard Laravel redirect-back-with-errors, matching every existing Customer FormRequest in this repository; no JSON/API response shape is introduced.

**CSRF** — standard Laravel session CSRF, identical to every other customer route; no exemption.

**Success/error messages** — `flash_success`/`flash_error` session keys, matching `WorkspaceController`'s own exact convention.

**Pagination** — `BusinessUsageLedgerEntryRepository::paginateForBusiness(int $businessId, int $perPage = 25): LengthAwarePaginator` (new repository method, §12); the view renders Laravel's standard paginator links.

**Presentation-data key shape** — `UsageBillingController::show()` passes exactly:

```php
[
    'business' => ['name' => ..., 'billing_status' => ...],
    'wallet' => ['available_balance_micro' => ..., 'reserved_balance_micro' => ..., 'debt_balance_micro' => ..., 'currency_code' => ..., 'spend_period_key' => ..., 'committed_spend_this_period_micro' => ..., 'monthly_spend_cap_micro' => ...] | null,
    'feature_limits' => [['feature_key' => ..., 'monthly_limit_micro' => ..., 'safety_limit_micro' => ...], ...],
    'payer' => ['payer_type' => ...] | null,
    'billing_contact' => ['name' => ..., 'email' => ..., 'notification_opt_in' => ...] | null,
    'ledger' => LengthAwarePaginator,
]
```

assembled entirely by `UsageBillingPresenter::buildDashboardViewModel()` (§12) via repository read calls only — **never** a raw `DB::table()` query in the controller or the Blade view. Blade performs currency formatting (integer micro-units → display string) and conditional empty/debt/suspended-state rendering only — **zero** balance, cap, headroom, or entitlement recomputation in the view.

**Views** — exactly one new Blade file, `resources/views/customer/business/usage-billing/show.blade.php`.

**Modified pre-existing view** — `resources/views/customer/workspaces/show.blade.php` gains one new link per visible Business row, pointing to `workspaces.businesses.usage-billing.show`, visible only when the current viewer's role for that Business grants at least view access (§7) — no other line in this file changes.

**No admin controller/route/view ships at M2** — every platform-administrator capability named in §7 (safety limit, billing status, cross-Business dashboard view) is manager-level only, directly callable and tested, with zero HTTP surface, since no milestone explicitly assigns admin HTTP surfaces to M2 (§3 audit item 11's finding, applied conservatively here since — unlike the customer dashboard — no separate binding instruction requires an admin surface to exist).

---

## 10. Manager/repository architecture

- **Transaction boundaries** — every mutation (`setSpendCap`, `setFeatureLimit`, `setSafetyLimit`, `setBillingStatus`, `changePayer`, `initializePayerAssignmentForBusiness`, `updateBillingContact`) wrapped in its own `DB::transaction()`, mirroring every M1 method exactly.
- **Row-lock order** — extends M1's own established order (wallet → reservation) with two new leaves that never co-occur with the reservation lock in the same transaction: `business_usage_wallets` (`findForUpdate`) before any write to `business_feature_usage_limits`/`business_usage_limit_transitions` for `setSpendCap()` (the wallet row itself is being written); `business_payer_assignments` (`findForUpdate` by `business_id`) before any write to `business_payer_transitions` for `changePayer()`; no method ever locks both a wallet row and a payer-assignment row in the same transaction, so no new cross-table lock-ordering question arises beyond what M1 already established.
- **Idempotency** — `initializePayerAssignmentForBusiness()` mirrors `initializeWalletForNewBusiness()`'s exact narrow duplicate-key-race catch (`business_payer_assignments`'s own `unique(business_id)`, MySQL error 1062, no other `QueryException` swallowed).
- **Events dispatched after commit** — `BusinessPayerChanged` (after `changePayer()`), `BusinessBillingContactChanged` (after `updateBillingContact()`), `BusinessWalletBillingStatusChanged` (after `setBillingStatus()`) — each `implements ShouldDispatchAfterCommit`, IDs/scalars only, matching RFC §29's own convention. **Finding, recorded honestly:** M1's own merged implementation does not dispatch any of the RFC's other named events (`BusinessWalletCredited`/`BusinessUsageReserved`/etc.) — this contract does not retroactively require M1 (already merged, outside this contract's authority) to add them, and introduces exactly the three events tied to M2's own new mutation methods, no more.
- **Zero new listeners** for these three events at M2 — dispatched for future consumers (notification jobs, none of which exist at or before M2), mirroring the established "ship the seam, no consumer yet" pattern already used for `usage_unauthorized`/`RealUsageAuthorizationGateway` at M1.
- **Zero new jobs at M2** — no M2 table requires scheduled/background processing; `AdvanceUsagePeriodBoundaries` remains optional M1-adjacent maintenance, unaffected by M2.
- **Presentation DTO/view-model behavior** — `UsageBillingPresenter` (new, `app/Library/Usage/`) is **not** a manager (no write authority, no transaction, no lock) — a plain read-assembly service calling only repository read methods (`BusinessUsageWalletRepository::findByBusinessId()`, `BusinessFeatureUsageLimitRepository::forBusiness()`, `PlatformFeatureUsageSafetyLimitRepository::findByFeatureKey()`, `BusinessPayerAssignmentRepository::findByBusinessId()`, `BusinessBillingContactRepository::findByBusinessId()`, `BusinessUsageLedgerEntryRepository::paginateForBusiness()`), assembling `UsageBillingDashboardViewModel` (readonly DTO) and `UsageLedgerEntryPresentationRow` (readonly per-row DTO). Controllers call `UsageBillingPresenter`/`UsageWalletManager`/`BillingProfileManager` only — never a repository directly (matching the established RFC-004/M1 controller convention exactly).
- **No raw M2-table query outside authorized layers** — enforced by `NoRawEntitlementTableQueryTest.php`'s own established pattern, extended (§14).

---

## 11. Complete schema contract

All seven tables, exactly as specified by RFC-005 §12/§15/§16/§17.A (read directly, reproduced here verbatim in this contract's own authoritative table form — no "see RFC §X" deferral). `restrictOnDelete()` on every tenancy FK, never `cascade`. No native `ENUM`. No float/double money column.

### 11.1 `business_feature_usage_limits`

| Column | Type | Signed | Nullable | Default | Index/Constraint | FK/Delete |
|---|---|---|---|---|---|---|
| `id` | `bigint unsigned` | n/a | No | auto-increment | PK | — |
| `business_id` | `unsignedBigInteger` | unsigned | No | — | part of `UNIQUE(business_id, feature_key)` | `businesses.id`, `restrictOnDelete()` |
| `feature_key` | `string(64)` | n/a | No | — | part of `UNIQUE(business_id, feature_key)` | — |
| `monthly_limit_micro` | `bigint` | signed | Yes | `NULL` | — | — |
| `updated_by_user_id` | `unsigned bigint` | unsigned | No | — | no FK (actor-column convention) | — |
| `created_at` | `timestamp` | n/a | No | `now()` | — | — |
| `updated_at` | `timestamp` | n/a | No | `now()` | — | — |

Sole write authority: `UsageWalletManager`.

### 11.2 `platform_feature_usage_safety_limits`

| Column | Type | Signed | Nullable | Default | Index/Constraint | FK/Delete |
|---|---|---|---|---|---|---|
| `id` | `bigint unsigned` | n/a | No | auto-increment | PK | — |
| `feature_key` | `string(64)` | n/a | No | — | `UNIQUE` | — |
| `max_monthly_limit_micro` | `bigint` | signed | No | — | — | — |
| `updated_by_user_id` | `unsigned bigint` | unsigned | No | — | no FK | — |
| `created_at` | `timestamp` | n/a | No | `now()` | — | — |
| `updated_at` | `timestamp` | n/a | No | `now()` | — | — |

Sole write authority: `UsageWalletManager`. Zero rows at M2 launch (no feature metered yet); `NOT NULL` on `max_monthly_limit_micro` is safe precisely because no row is ever inserted by any M2 code path.

### 11.3 `business_usage_limit_transitions`

| Column | Type | Signed | Nullable | Default | Index/Constraint | FK/Delete |
|---|---|---|---|---|---|---|
| `id` | `bigint unsigned` | n/a | No | auto-increment | PK | — |
| `business_id` | `unsignedBigInteger` | unsigned | Yes | `NULL` | index | `businesses.id`, `restrictOnDelete()` — null only for `limit_type = platform_safety_limit` |
| `limit_type` | `string(24)`, enum-backed (`UsageLimitType`) | n/a | No | — | — | — |
| `feature_key` | `string(64)` | n/a | Yes | `NULL` | — | set only for `feature_limit`/`platform_safety_limit` rows |
| `from_value_micro` | `bigint` | signed | Yes | `NULL` | — | — |
| `to_value_micro` | `bigint` | signed | Yes | `NULL` | — | — |
| `actor_user_id` | `unsigned bigint` | unsigned | No | — | no FK | — |
| `reason` | `text` | n/a | No | — | mandatory | — |
| `created_at` | `timestamp` | n/a | No | `now()` | — | — |

Append-only — no `updated_at`. Sole write authority: `UsageWalletManager`.

### 11.4 `business_usage_wallet_billing_status_transitions`

| Column | Type | Signed | Nullable | Default | Index/Constraint | FK/Delete |
|---|---|---|---|---|---|---|
| `id` | `bigint unsigned` | n/a | No | auto-increment | PK | — |
| `wallet_id` | `unsignedBigInteger` | unsigned | No | — | composite FK (with `business_id`) | composite-protected: `(wallet_id, business_id) → business_usage_wallets(id, business_id)`, `restrictOnDelete()` |
| `business_id` | `unsignedBigInteger` | unsigned | No | — | composite FK (with `wallet_id`) | see above |
| `from_status` | `string(16)` | n/a | No | — | — | — |
| `to_status` | `string(16)` | n/a | No | — | — | — |
| `source` | `string(24)`, enum-backed (`BillingStatusTransitionSource`) | n/a | No | — | — | — |
| `actor_user_id` | `unsigned bigint` | unsigned | Yes | `NULL` | no FK | null for `dispute_webhook` |
| `reason` | `text` | n/a | No | — | mandatory | — |
| `created_at` | `timestamp` | n/a | No | `now()` | — | — |

Append-only. Sole write authority: `UsageWalletManager`. Zero rows inserted by any M2 production code path (§6.G).

### 11.5 `business_billing_contacts`

| Column | Type | Signed | Nullable | Default | Index/Constraint | FK/Delete |
|---|---|---|---|---|---|---|
| `id` | `bigint unsigned` | n/a | No | auto-increment | PK | — |
| `business_id` | `unsignedBigInteger` | unsigned | No | — | `UNIQUE` | `businesses.id`, `restrictOnDelete()` |
| `contact_user_id` | `unsignedBigInteger` | unsigned | Yes | `NULL` | — | `users.id`, `restrictOnDelete()` |
| `contact_name` | `string(191)` | n/a | Yes | `NULL` | — | required with `contact_email` if `contact_user_id` null (manager-enforced) |
| `contact_email` | `string(191)` | n/a | Yes | `NULL` | — | required with `contact_name` if `contact_user_id` null (manager-enforced) |
| `notification_opt_in` | `boolean` | n/a | No | `true` | — | — |
| `updated_by_user_id` | `unsigned bigint` | unsigned | No | — | no FK | — |
| `created_at` | `timestamp` | n/a | No | `now()` | — | — |
| `updated_at` | `timestamp` | n/a | No | `now()` | — | — |

Sole write authority: `BillingProfileManager`. No transition table (§6.F).

### 11.6 `business_payer_assignments`

| Column | Type | Signed | Nullable | Default | Index/Constraint | FK/Delete |
|---|---|---|---|---|---|---|
| `id` | `bigint unsigned` | n/a | No | auto-increment | PK | — |
| `business_id` | `unsignedBigInteger` | unsigned | No | — | `UNIQUE` | `businesses.id`, `restrictOnDelete()` |
| `payer_type` | `string(16)`, enum-backed (`PayerType`) | n/a | No | — | — | `business` \| `workspace` \| `agency_rebill` (inert at M2) |
| `effective_payment_instrument_id` | `unsignedBigInteger` | unsigned | Yes | `NULL` | — | no FK at M2 (`business_payment_instruments` does not exist until M3 — deferred FK, matching M1's own `funding_attempt_id` precedent exactly); always `NULL` at M2 |
| `created_at` | `timestamp` | n/a | No | `now()` | — | — |
| `updated_at` | `timestamp` | n/a | No | `now()` | — | — |

Sole write authority: `BillingProfileManager`.

### 11.7 `business_payer_transitions`

| Column | Type | Signed | Nullable | Default | Index/Constraint | FK/Delete |
|---|---|---|---|---|---|---|
| `id` | `bigint unsigned` | n/a | No | auto-increment | PK | — |
| `business_id` | `unsignedBigInteger` | unsigned | No | — | index | `businesses.id`, `restrictOnDelete()` |
| `from_payer_type` | `string(16)`, enum-backed (`PayerType`) | n/a | No | — | — | — |
| `to_payer_type` | `string(16)`, enum-backed (`PayerType`) | n/a | No | — | — | — |
| `from_instrument_id` | `unsignedBigInteger` | unsigned | Yes | `NULL` | — | no FK at M2 (deferred, same as §11.6); always `NULL` |
| `to_instrument_id` | `unsignedBigInteger` | unsigned | Yes | `NULL` | — | no FK at M2; always `NULL` |
| `actor_user_id` | `unsigned bigint` | unsigned | No | — | no FK | — |
| `reason` | `text` | n/a | No | — | mandatory | — |
| `created_at` | `timestamp` | n/a | No | `now()` | — | — |

Append-only. Sole write authority: `BillingProfileManager`.

**Migration order (DDL before data, matching RFC §32/M1's own established discipline):**

1. `create_business_feature_usage_limits_table`
2. `create_platform_feature_usage_safety_limits_table`
3. `create_business_usage_limit_transitions_table`
4. `create_business_usage_wallet_billing_status_transitions_table`
5. `create_business_billing_contacts_table`
6. `create_business_payer_assignments_table`
7. `create_business_payer_transitions_table`
8. `backfill_business_payer_assignments` (data-operation migration, §12 item 8) — must run **after** migration 6 creates its target table; continue-and-report on a per-Business failure basis is not applicable here (unlike M1's currency-resolution failure case, a payer-assignment default per §6.E never fails for a Business with a valid Workspace — every Business has exactly one owning Workspace, non-nullable, confirmed by schema), but the migration still counts and reports exactly how many rows it created, mirroring M1's own backfill's reporting discipline even though it cannot itself fail per-Business.

**Rollback safety** — migrations 1–7's `down()` each `Schema::dropIfExists()`, in reverse order, matching M1's exact pattern; migration 8's `down()` is a non-destructive no-op (a backfilled `business_payer_assignments` row may already be read by dashboard code by the time a rollback is attempted — matching M1 migration 9's own established rationale exactly).

---

## 12. Exact implementation allowlist

**83 total paths: 55 production (51 new + 4 modified) + 28 test (28 new + 0 modified).** No path is a glob or directory. Any path discovered necessary beyond this list during implementation is a STOP-and-report condition (§17).

### Migrations (8 new)

1. `database/migrations/{impl_date}_create_business_feature_usage_limits_table.php`
2. `database/migrations/{impl_date}_create_platform_feature_usage_safety_limits_table.php`
3. `database/migrations/{impl_date}_create_business_usage_limit_transitions_table.php`
4. `database/migrations/{impl_date}_create_business_usage_wallet_billing_status_transitions_table.php`
5. `database/migrations/{impl_date}_create_business_billing_contacts_table.php`
6. `database/migrations/{impl_date}_create_business_payer_assignments_table.php`
7. `database/migrations/{impl_date}_create_business_payer_transitions_table.php`
8. `database/migrations/{impl_date}_backfill_business_payer_assignments.php`

### Enums (3 new)

9. `app/Enums/Usage/UsageLimitType.php`
10. `app/Enums/Usage/PayerType.php`
11. `app/Enums/Usage/BillingStatusTransitionSource.php`

### Value objects (2 new)

12. `app/Library/Usage/EffectivePayer.php`
13. `app/Library/Usage/CapEvaluation.php`

### Presentation DTOs/service (3 new)

14. `app/Library/Usage/UsageBillingPresenter.php`
15. `app/Library/Usage/UsageBillingDashboardViewModel.php`
16. `app/Library/Usage/UsageLedgerEntryPresentationRow.php`

### Models (7 new)

17. `app/Models/BusinessFeatureUsageLimit.php`
18. `app/Models/PlatformFeatureUsageSafetyLimit.php`
19. `app/Models/BusinessUsageLimitTransition.php`
20. `app/Models/BusinessUsageWalletBillingStatusTransition.php`
21. `app/Models/BusinessBillingContact.php`
22. `app/Models/BusinessPayerAssignment.php`
23. `app/Models/BusinessPayerTransition.php`

### Repository contracts (7 new)

24. `app/Repositories/Contracts/BusinessFeatureUsageLimitRepository.php`
25. `app/Repositories/Contracts/PlatformFeatureUsageSafetyLimitRepository.php`
26. `app/Repositories/Contracts/BusinessUsageLimitTransitionRepository.php`
27. `app/Repositories/Contracts/BusinessUsageWalletBillingStatusTransitionRepository.php`
28. `app/Repositories/Contracts/BusinessBillingContactRepository.php`
29. `app/Repositories/Contracts/BusinessPayerAssignmentRepository.php`
30. `app/Repositories/Contracts/BusinessPayerTransitionRepository.php`

### Eloquent repositories (7 new)

31. `app/Repositories/Eloquent/EloquentBusinessFeatureUsageLimitRepository.php`
32. `app/Repositories/Eloquent/EloquentPlatformFeatureUsageSafetyLimitRepository.php`
33. `app/Repositories/Eloquent/EloquentBusinessUsageLimitTransitionRepository.php`
34. `app/Repositories/Eloquent/EloquentBusinessUsageWalletBillingStatusTransitionRepository.php`
35. `app/Repositories/Eloquent/EloquentBusinessBillingContactRepository.php`
36. `app/Repositories/Eloquent/EloquentBusinessPayerAssignmentRepository.php`
37. `app/Repositories/Eloquent/EloquentBusinessPayerTransitionRepository.php`

### Manager (1 new)

38. `app/Library/Usage/BillingProfileManager.php`

### Exceptions (4 new)

39. `app/Exceptions/Usage/UnauthorizedPayerAssignmentException.php`
40. `app/Exceptions/Usage/UnauthorizedUsageBillingManagementException.php`
41. `app/Exceptions/Usage/FeatureLimitExceedsPlatformSafetyLimitException.php`
42. `app/Exceptions/Usage/InvalidBillingContactDataException.php`

### Events (3 new)

43. `app/Events/Usage/BusinessPayerChanged.php`
44. `app/Events/Usage/BusinessBillingContactChanged.php`
45. `app/Events/Usage/BusinessWalletBillingStatusChanged.php`

### HTTP — controller, FormRequests, views (6 new)

46. `app/Http/Controllers/Customer/Business/UsageBillingController.php`
47. `app/Http/Requests/Customer/Business/UpdateBusinessPayerRequest.php`
48. `app/Http/Requests/Customer/Business/UpdateBusinessBillingContactRequest.php`
49. `app/Http/Requests/Customer/Business/UpdateBusinessSpendCapRequest.php`
50. `app/Http/Requests/Customer/Business/UpdateBusinessFeatureLimitRequest.php`
51. `resources/views/customer/business/usage-billing/show.blade.php`

### Modified production paths (4 — all pre-existing, none new)

52. `app/Library/Usage/UsageWalletManager.php` — **modified, not new**: exactly four additive public methods (`setSpendCap()`, `setFeatureLimit()`, `setSafetyLimit()`, `setBillingStatus()`) plus their constructor-injected repository dependencies (`BusinessFeatureUsageLimitRepository`, `PlatformFeatureUsageSafetyLimitRepository`, `BusinessUsageLimitTransitionRepository`, `BusinessUsageWalletBillingStatusTransitionRepository`). No existing method's signature or body changes.
53. `app/Listeners/Usage/InitializeBusinessUsageProfile.php` — **modified, not new**: both existing handler methods (`handleBusinessCreated()`, `handleBusinessAssignedToWorkspace()`) gain exactly one additional call each, to `BillingProfileManager::initializePayerAssignmentForBusiness()`, after the existing `UsageWalletManager::initializeWalletForNewBusiness()` call. No existing line is removed or restructured.
54. `app/Providers/AppServiceProvider.php` — **modified, not new**: exactly seven additive lines appended to the `$bindings` array, one per M2 repository contract/implementation pair (§12 items 24–30 to 31–37), in a new contiguous group immediately following the M1 Usage group. No other line changes; `BillingProfileManager`/`UsageBillingPresenter` require no explicit binding (concrete classes, constructor-injected, Laravel auto-resolves — matching `UsageWalletManager`'s own unbound precedent).
55. `resources/views/customer/workspaces/show.blade.php` — **modified, not new**: exactly one new link per visible Business row (§9), gated by the viewer's existing role-resolution logic already present in that view/its controller. No other line changes.

**No change to `app/Providers/EventServiceProvider.php`** (audit item 5 — the existing `$listen` mappings already cover both Business-creation events; only the listener's own handler bodies change, and that file is item 53 above).

**No change to `config/permissions.php`** for the customer surface (audit item 8 — the customer controller uses zero Spatie permission checks). *(If, upon separate human review, the "New permission category" instruction in RFC §36 item 2 is judged to require the config file change even with no consuming admin route, that is a single additive category — two keys — and would be raised as a targeted amendment request at implementation time, not silently added here without being counted.)*

### Tests (28 new)

56. `tests/Unit/Usage/UsageBillingEnumsTest.php`
57. `tests/Feature/Usage/BusinessFeatureUsageLimitSchemaTest.php`
58. `tests/Feature/Usage/PlatformFeatureUsageSafetyLimitSchemaTest.php`
59. `tests/Feature/Usage/BusinessUsageLimitTransitionSchemaTest.php`
60. `tests/Feature/Usage/BusinessUsageWalletBillingStatusTransitionSchemaTest.php`
61. `tests/Feature/Usage/BusinessBillingContactSchemaTest.php`
62. `tests/Feature/Usage/BusinessPayerAssignmentSchemaTest.php`
63. `tests/Feature/Usage/BusinessPayerTransitionSchemaTest.php`
64. `tests/Feature/Usage/BillingProfileManagerPayerAssignmentTest.php`
65. `tests/Feature/Usage/BillingProfileManagerBillingContactTest.php`
66. `tests/Feature/Usage/PayerTransitionAuditTest.php`
67. `tests/Feature/Usage/PayerAssignmentDefaultByPlanTierTest.php`
68. `tests/Feature/Usage/PayerConsentAuthorizationTest.php`
69. `tests/Feature/Usage/PayerAssignmentTransitionScenariosTest.php`
70. `tests/Feature/Usage/UsageWalletManagerSpendCapTest.php`
71. `tests/Feature/Usage/UsageWalletManagerFeatureLimitTest.php`
72. `tests/Feature/Usage/UsageWalletManagerSafetyLimitTest.php`
73. `tests/Feature/Usage/FormulaDerivedCountersNeverManuallyMutatedM2Test.php`
74. `tests/Feature/Usage/UsageWalletBillingStatusTransitionTest.php`
75. `tests/Feature/Usage/NewBusinessPayerAssignmentInitializationTest.php`
76. `tests/Feature/Usage/BackfillBusinessPayerAssignmentsTest.php`
77. `tests/Feature/Usage/PayerAssignmentConcurrencyTest.php`
78. `tests/Feature/Usage/CrossBusinessBillingIsolationTest.php`
79. `tests/Feature/Usage/UsageBillingDashboardAuthorizationTest.php`
80. `tests/Feature/Usage/UsageBillingDashboardViewDataTest.php`
81. `tests/Feature/Usage/UsageBillingLedgerPaginationTest.php`
82. `tests/Feature/Usage/NoFakePaymentControlsRenderedTest.php`
83. `tests/Feature/Usage/NoStripeOrProviderCodeAtM2Test.php`

**No M1 test file is modified by M2.** `tests/Feature/Usage/NewBusinessWalletInitializationTest.php` continues to prove wallet-only initialization outcomes, unmodified, exactly as RFC-005 §35 itself distinguishes ("`NewBusinessWalletInitializationTest` (M1 scope)... `NewBusinessPayerAssignmentInitializationTest` (M2 scope, new)").

**Counts:** 8 migrations + 3 enums + 2 value objects + 3 presentation files + 7 models + 7 repository contracts + 7 Eloquent repositories + 1 manager + 4 exceptions + 3 events + 6 HTTP files = **51 new production**. + 4 modified production (items 52–55) = **55 production total.** 28 new tests, 0 modified = **28 test total.** **55 + 28 = 83.**

---

## 13. Test contract

Each file's required proof, beyond what its own name states:

- **56–63 (schema, 8 files)** — every column type/nullable/default/index exactly as §11; FK `restrictOnDelete()` behavior; append-only tables reject an `UPDATE`/`DELETE` at the application layer (no such method exists on their repositories).
- **64 `BillingProfileManagerPayerAssignmentTest`** — initial assignment via direct manager call; idempotent repeat `initializePayerAssignmentForBusiness()` call; explicit `changePayer()` reassignment; duplicate/idempotent reaffirmation records a transition row (§6.E).
- **65 `BillingProfileManagerBillingContactTest`** — `contact_user_id` set vs. independent name/email; `InvalidBillingContactDataException` when neither is fully supplied; `notification_opt_in` default.
- **66 `PayerTransitionAuditTest`** — every `changePayer()` call produces exactly one `business_payer_transitions` row; a payer change never rewrites `business_usage_ledger_entries`, `business_usage_reservations`, or `business_billing_contacts`.
- **67 `PayerAssignmentDefaultByPlanTierTest`** — Core/Growth → `workspace`; Agency → `business`; unassigned Workspace → `workspace`; resolved exclusively via `EntitlementManager::getWorkspaceEntitlementSummary()`, proven by a mechanical search (§14) that no raw `workspace_plan_catalog`/`workspace_plan_assignments` query exists in `BillingProfileManager.php`.
- **68 `PayerConsentAuthorizationTest`** — full §7 matrix for `changePayer()`: Workspace owner may set `workspace`; direct Business owner may set `business`; Active Admin/Staff/unrelated/either side attempting the other's payer type all denied; no platform-administrator HTTP override exists at M2.
- **69 `PayerAssignmentTransitionScenariosTest`** — plan changes never silently re-default an assigned payer; ownership changes preserve the assignment and re-resolve future consent against the new owner; Workspace transfers/Business reassignment preserve the assignment and its full transition history; a deactivated member immediately loses consent authority; a Business without a valid wallet can still have a payer assignment (no FK dependency).
- **70 `UsageWalletManagerSpendCapTest`** — set/clear/change; nullable by default, no invented value; a cap set below current committed spend is accepted, denies zero historical entries, and immediately reduces future headroom to zero.
- **71 `UsageWalletManagerFeatureLimitTest`** — set/clear; `PlatformFeatureRegistry::isAvailable()` validation; settable ahead of metering activation; bounded above by any configured safety limit.
- **72 `UsageWalletManagerSafetyLimitTest`** — platform-administrator-only; mandatory reason; direct manager-level call only (no HTTP), proving the method exists and works while remaining uncalled by any production path.
- **73 `FormulaDerivedCountersNeverManuallyMutatedM2Test`** — none of `setSpendCap`/`setFeatureLimit`/`setSafetyLimit`/`setBillingStatus` ever writes to `committed_spend_this_period_micro`/`reserved_spend_this_period_micro`/`recharged_this_period_micro`; mechanical grep confirms no M2 file references those three columns as a write target.
- **74 `UsageWalletBillingStatusTransitionTest`** — `setBillingStatus()` writes the wallet's `billing_status` plus exactly one transition row; mandatory reason; platform-administrator-only for `admin_action`; a dedicated assertion (mirroring `NoAutoRechargeDispatchAtM1Test`) that no M2 production code path calls this method.
- **75 `NewBusinessPayerAssignmentInitializationTest`** — both confirmed Business-creation events result in exactly one payer assignment, never zero, never two; genuine duplicate event redelivery is a no-op (mirroring `NewBusinessWalletInitializationTest`'s own M1 pattern exactly, including real production call paths via `BusinessManager`/`WorkspaceManager`, never a hand-rolled event dispatch standing in for them).
- **76 `BackfillBusinessPayerAssignmentsTest`** — every pre-existing Business at migration time receives exactly one assignment; a Business created between M1 and M2 deploy (simulated) is covered; rerun is idempotent with zero new writes; an explicit existing assignment is never overwritten.
- **77 `PayerAssignmentConcurrencyTest`** — real cross-process concurrency (mirroring `UsageWalletManagerConcurrencyTest.php`'s own established lock-hold-then-signal pattern): two workers racing initial default-assignment creation for the same Business resolve to exactly one row.
- **78 `CrossBusinessBillingIsolationTest`** — Business A's billing contact/payer/limits/audit history is never visible from Business B's own dashboard request or repository lookup, even within the same Workspace.
- **79 `UsageBillingDashboardAuthorizationTest`** — the full §7 matrix, every action × every actor, asserting 404 (never 403) for every unrelated/anonymous case.
- **80 `UsageBillingDashboardViewDataTest`** — exact view-data key shape (§9); empty/debt/suspended/uninitialized states render their exact expected copy; currency-formatted display while the underlying value stays integer micro-units.
- **81 `UsageBillingLedgerPaginationTest`** — `paginateForBusiness()` pagination correctness; cross-Business ledger entries never leak onto another Business's page.
- **82 `NoFakePaymentControlsRenderedTest`** — the rendered dashboard response never contains any of: "Add card", "Checkout", "Top up", "Top-up", "Refund", "Auto-recharge" as an actionable control, "Invoice", "Receipt", "Buy", "Purchase" — direct regression test for §5's exclusion list.
- **83 `NoStripeOrProviderCodeAtM2Test`** — mechanical grep across every path in §12 confirms zero references to `Stripe`, `stripe-php`, `PaymentIntent`, `CheckoutSession`, `SetupIntent`, or any M3+ table/class name.

**Six mandatory regression gates** (§15), `ultimatesms_testing` only, never a predicted count — every count is reported only after the gate actually runs.

---

## 14. Mechanical searches

Run from repository root, PHP 8.3.30, against `ultimatesms_testing` only:

1. `grep -rn "Stripe\|stripe-php\|PaymentIntent\|CheckoutSession\|SetupIntent" app/Library/Usage app/Library/Entitlement app/Http/Controllers/Customer/Business app/Models/Business*.php` → zero matches outside comments explaining the exclusion.
2. `grep -rn "committed_spend_this_period_micro\s*=\|reserved_spend_this_period_micro\s*=\|recharged_this_period_micro\s*=" app/Library/Usage/BillingProfileManager.php` and the same against the four new `UsageWalletManager` methods → zero matches (no direct write to a formula-derived counter).
3. `grep -rln "DB::table('business_feature_usage_limits'\|DB::table('platform_feature_usage_safety_limits'\|DB::table('business_usage_limit_transitions'\|DB::table('business_usage_wallet_billing_status_transitions'\|DB::table('business_billing_contacts'\|DB::table('business_payer_assignments'\|DB::table('business_payer_transitions'" app --include="*.php" | grep -v "Repositories/Eloquent\|database/migrations"` → zero matches (no raw M2-table access outside repositories/migrations).
4. `grep -rn "DB::table\|::query()" resources/views/customer/business/usage-billing/show.blade.php` → zero matches (Blade never queries).
5. `grep -c "InitializeBusinessUsageProfile::class" app/Providers/EventServiceProvider.php` → `2` (unchanged from M1 — confirms item 53's own file is the only one touched, not the provider).
6. `grep -rn "workspace_plan_catalog\|workspace_plan_assignments" app/Library/Usage/BillingProfileManager.php` → zero matches (default-payer resolution goes only through `EntitlementManager::getWorkspaceEntitlementSummary()`).
7. `git diff --stat app/Library/Entitlement/EntitlementManager.php` (against the M2 base SHA) → empty (unmodified).
8. `grep -n "platform_feature_unknown\|platform_feature_unavailable\|workspace_plan_unassigned\|denied_by_workspace_override\|not_entitled_by_plan\|disabled_for_business\|plan_suspended\|plan_inactive\|usage_unauthorized" app/Library/Entitlement/EntitlementManager.php | wc -l` → `15` (unchanged from the M1-round finding — all nine keys present, no key added/removed/renamed).
9. `git diff --stat -- routes/admin.php app/Http/Controllers/Admin config/permissions.php` (against the M2 base SHA) → empty (no admin surface, no permissions change, per §9/§12's own explicit exclusion).
10. `find database/migrations -newer <base-SHA-checkout-marker> -name "*.php"` cross-checked against §12 items 1–8 → exact set equality, no extra migration.
11. Final changed-path set (`git diff --name-only` + `git ls-files --others --exclude-standard`, sorted) equals §12's 83-path list exactly, after normalizing `{impl_date}` in the eight migration filenames.

---

## 15. Six mandatory regression gates

Run sequentially against `ultimatesms_testing`, PHP 8.3.30 (`bcmath`/`pdo_mysql` confirmed), exact command/exit-code/counts/duration reported for each — no gate result may be reused from an earlier run, no count predicted:

1. `php artisan test tests/Unit/Usage tests/Feature/Usage` — RFC-005/M2-focused (includes every M1 Usage test unmodified, plus all 28 new M2 files).
2. `php artisan test tests/Unit/Entitlement tests/Feature/Entitlement` — Entitlement (proves `EntitlementManager`/nine-key surface still unaffected).
3. `php artisan test tests/Unit/Workspace tests/Feature/Workspace` — Workspace.
4. `php artisan test tests/Feature/Business` — Business.
5. `php artisan test tests/Feature/Opportunity` — Opportunity.
6. `php artisan test --stop-on-failure` — full suite.

---

## 16. Verification and publication (this document only)

- `git diff --check` — clean.
- `git status --short` — exactly `?? docs/automation/RFC-005-M2-CONTRACT.md`.
- `git diff --name-only` — empty (untracked file, not a diff of a tracked one).
- `git diff --cached --name-only` — empty before staging.
- Stage the one file by its exact path only.
- Commit exactly: `docs: prepare RFC-005 Milestone 2 contract`.
- Push normally to `origin chore/rfc-005-m2-contract`. No force push. Do not push `main`. Do not create or push a tag.
- If `gh` is unavailable (confirmed unavailable in this environment every prior round), report the ready-made GitHub comparison URL instead of fabricating a PR.

**Tests are honestly reported as NOT RUN for this docs-only contract-drafting change** — no PHP test command is executed by this branch.

---

## 17. Stop conditions for the future implementation

Implementation must stop, leave the working tree unstaged, and report rather than proceed, if:

- Any path beyond §12's 83 is required.
- Any financial default (retail rate, spend-cap value, per-feature-limit value, safety-limit value, auto-recharge value) must be invented rather than left nullable/unconfigured.
- Any RFC-004 change is required.
- Any Stripe/provider behavior becomes necessary to satisfy a requirement believed to be M2 scope.
- Payer authority is ambiguous in a real scenario §6.E/§7 does not already resolve.
- The dashboard's content is found to conflict with any RFC-005 locked decision.
- A third Business-creation path (beyond `BusinessCreated`/`BusinessAssignedToWorkspace`) is discovered.
- Cross-Business/cross-Workspace isolation cannot be proven for any new table.
- Any of the six regression gates fails for a reason not fixable within the 83-path allowlist.
- `ultimatesms_testing` cannot be confirmed as the effective test database.

---

## 18. Open-decision classification (§39 of the RFC, all 14 items)

| # | Item | Classification | Reasoning |
|---|---|---|---|
| 1 | Exact initial retail usage rates | Irrelevant to M2 | No feature is metered until M5; rates are never read or written by any M2 code path. |
| 2 | Exact default Business monthly spend cap | Does not block M2 | `monthly_spend_cap_micro` ships nullable; M2's `setSpendCap()` never writes a default — only an explicit human/customer value, or `null`. The exact default figure remains genuinely open and is never invented by this contract or its future implementation. |
| 3 | Exact default per-feature limits | Does not block M2 | Identical reasoning to item 2, applied to `business_feature_usage_limits.monthly_limit_micro`. |
| 4 | Exact auto-recharge default threshold | Affects later payment execution only | Auto-recharge configuration requires a stored payment instrument (M3); M2 never touches `auto_recharge_threshold_micro`/`auto_recharge_amount_micro`. |
| 5 | Owner/operator Agency metered-usage subsidy | Irrelevant to M2 | Affects usage-authorization behavior once metering exists (M5); M2 introduces no metering. |
| 6 | Invoice/tax/VAT provider and legal sufficiency | Irrelevant to M2 | `NON-IMPLEMENTATION-READY` gate applies to production payment collection (M3/M4); M2 causes no charge of any kind. |
| 7 | Timing of Agency client rebilling | Irrelevant to M2 | `payer_type = 'agency_rebill'` ships as an inert enum case on the M2 schema (never customer-selectable, §9's FormRequest excludes it); no execution path exists at or before M2. |
| 8 | Exact v1 add-on roster and pricing | Irrelevant to M2 | `business_usage_addon_catalog` is M4 schema, not M2. |
| 9 | Exact initial per-feature platform safety-limit ceilings | Does not block M2 | `platform_feature_usage_safety_limits` ships as an empty table (zero rows) at M2 — no feature needs a ceiling until it is metered (M5). The manager method to set one exists and is tested, but no M2 code path ever calls it with an invented value. |
| 10 | v1 settlement currency and multi-currency scope | Irrelevant to M2 | Already resolved and implemented at M1 (per-Business currency resolved from `businesses.currency_code`, no fallback); M2 introduces no new currency-scoped column. |
| 11 | The first actual metered feature | Irrelevant to M2 | M5's own concern exclusively. |
| 12 | Exact default monthly auto-recharge cap | Affects later payment execution only | Identical reasoning to item 4 — `monthly_recharge_cap_micro` remains untouched by any M2 code path. |
| 13 | `payment_lapsed` grandfathered-capacity revocation policy | Irrelevant to M2 | `additional_business_slot_agreements` is M4 schema. |
| 14 | Cross-RFC additional-slot allocation authority blocker | Irrelevant to M2 | Blocks M4's allocation step specifically; M2 never calls `EntitlementManager::setAdditionalBusinessSlots()` or any related method. |

**No item among the 14 blocks M2's own implementation from proceeding.** Every M2-relevant financial value ships nullable/unconfigured/empty by explicit RFC design (§10/§12/§15's own "null = unconfigured" and "zero rows until metered" framing) — this contract confirms that design lets M2 proceed without resolving any of the 14 open decisions, and invents none of them.

---

## 19. Acceptance criteria and implementation sequence

**Numbered, mechanically provable acceptance criteria:**

1. All 7 tables in §11 exist, migrated, with exactly the columns/types/constraints specified — proven by tests 56–63.
2. `business_payer_assignments` is backfilled for every pre-existing Business, and both Business-creation events produce exactly one assignment for every new Business — proven by tests 75–76.
3. The payer-consent model in §7 holds for every actor/action pair — proven by test 79 (and 68 at the manager level).
4. No fake payment control is ever rendered — proven by test 82.
5. No Stripe/provider code exists anywhere in the 83-path set — proven by test 83 and mechanical search 1.
6. `EntitlementManager.php` and the nine RFC-004 denial keys are unchanged — proven by mechanical searches 7–8 and regression gate 2.
7. All six regression gates (§15) pass with exact reported counts.
8. The final changed-path set equals §12's 83 paths exactly — proven by mechanical search 11.

**Implementation sequence:**

1. Schema (§12 items 1–8, in the exact order §11 specifies).
2. Enums, value objects, presentation DTOs, models (§12 items 9–23).
3. Repository contracts and Eloquent implementations (§12 items 24–37).
4. `BillingProfileManager` and the four new `UsageWalletManager` methods, including their transactions/locking (§12 item 38, item 52).
5. Listener extension and the backfill migration's own execution proof (item 53, already covered by item 8's migration).
6. Authorization (§7, enforced inside the manager methods and the controller's role resolution).
7. Presentation DTOs' assembly logic (`UsageBillingPresenter`, item 14).
8. HTTP routes/controller/FormRequests (§9, items 46–50).
9. Views/navigation (items 51, 55).
10. Tests (items 56–83).
11. Mechanical searches (§14) and all six regression gates (§15).

---

*End of RFC-005 Milestone 2 contract. Implementation requires a separate, explicit human instruction. This contract's own merge does not start it.*
