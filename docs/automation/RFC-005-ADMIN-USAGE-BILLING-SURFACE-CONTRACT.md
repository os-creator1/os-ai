# RFC-005 Admin Usage Billing Surface Contract

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

This document authorizes drafting one thing: a bounded, independently governed contract that builds the last remaining platform-administrator capabilities RFC-005 §24/§30 require and that no milestone (M1–M5) or correction contract has yet built — named "Admin Usage Billing Surface," remediation #5 of the seven pre-M6 remediations RFC-005 Milestone 6's own static conformance audit discovered. It is the mandatory pre-M6 correction sitting immediately after the RFC-005 Funding Confirmation Concurrency Correction Contract (merged PR [#144](https://github.com/os-creator1/os-ai/pull/144), its own exceptional post-merge implementation correction merged PR [#145](https://github.com/os-creator1/os-ai/pull/145), and its own implementation merged PR [#146](https://github.com/os-creator1/os-ai/pull/146)) and before remediation #6 (Provider Refund/Dispute Outcome Handling) and remediation #7 (RFC-005 §35 Test-Coverage Completion) in the master remediation sequence — confirmed, by name, in every one of those five prior remediation contracts' own exclusion sections (`RFC-005-RESERVATION-ADMISSION-CORRECTION-CONTRACT.md:254`, `RFC-005-FUNDING-PROVIDER-FLOW-CORRECTION-CONTRACT.md:596`, `RFC-005-RECEIPT-BOUNDARY-CORRECTION-CONTRACT.md:416`, `RFC-005-JOB-EVENT-DISPATCH-COMPLETION-CORRECTION-CONTRACT.md:389`, `RFC-005-RECONCILIATION-RACE-CORRECTION-CONTRACT.md:267`).

**This is contract-authoring only.** No product code, test code, schema, route, config, or RFC-source file is touched by this branch. It creates exactly one file: `docs/automation/RFC-005-ADMIN-USAGE-BILLING-SURFACE-CONTRACT.md`.

---

## 0. Governance

- Drafted on branch `chore/rfc-005-admin-usage-billing-surface-contract`, in an isolated linked worktree (`../rfc-005-admin-usage-billing-surface-contract-worktree`), based on `origin/main` at `376fda52ecf449bbb622d2dd0ec40f4411587cc5` — PR [#146](https://github.com/os-creator1/os-ai/pull/146)'s own merge commit (the RFC-005 Funding Confirmation Concurrency Correction's implementation) — confirmed via `git fetch origin && git rev-parse origin/main` at the start of this contract.
- **Future implementation branch (authorized only after this contract's human merge, and only after a further, separate, explicit human instruction to begin implementation): `agent/rfc-005-admin-usage-billing-surface`.**
- Confirmed at drafting: `git status --short` in this worktree is empty at every point except this one new file; no product/test/config/route/RFC-source file has been touched by this branch at any point.
- Locked:
  - `human_only_merge: true`
  - `maximum_correction_rounds: 2`
  - `advance_automatically: false`
  - `start_automatically_after_contract_merge: false`
  - `M6 remains frozen` — untouched, not resumed, no M6 document created or modified. Confirmed by direct read: `docs/automation/RFC-005-M6-CONTRACT.md` (366 lines) contains zero mentions of any of the seven remediations, including this one — it was merged (PR #133) before M6's own release-readiness attempt discovered the remediation-sequence gap, and has never been amended to reflect it. Its own two required deliverables, `docs/automation/RFC-005-M6-CONFORMANCE.md` and `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS-DEPLOYMENT.md`, do not exist anywhere in this repository (confirmed via direct `ls`, both `No such file or directory`) — M6 remains genuinely open/blocked, not merely paused.
  - `AI-AUTONOMY-STATE.json` is untouched by this branch. (Confirmed stale relative to the actual remediation sequence — its own `"status": "m5_closed_pending_next_locked_contract"` / `"active_milestone": "Milestone 5"` fields have never been updated since M5 closed; this is consistent with every prior remediation contract's own confirmation that this file is untouched, not a new finding.)
  - No tag is created or moved. No live Stripe/rate/meter/pilot activation occurs.
  - **Implementation requires a separate, explicit human instruction issued after this contract itself is human-merged.**
- This is a new, independently bounded pre-M6 remediation contract — not a correction round against M1–M5's own contracts, and not a correction round against any of the six already-merged corrections in this sequence (Reservation Admission, Funding Provider-Flow, Receipt Boundary, Job/Event Dispatch Completion, Reconciliation-Race, Funding Confirmation Concurrency). Its `maximum_correction_rounds: 2` budget is its own; no counter is borrowed or altered on any other contract.
- This governance branch changes exactly one tracked file: `docs/automation/RFC-005-ADMIN-USAGE-BILLING-SURFACE-CONTRACT.md`.
- **Required reading completed before drafting, independently audited fresh in this pass:**
  - `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md` — §15 (lines 476–547, platform safety limit / limit-transition schema), §16 (549–601, the narrowed platform-administrator charge-origination rule), §18 (734–793, manual/promotional credit and add-on schema), §21 (845–908, webhook exhaustion/disposition design), §24 (1052–1082, the authorization/tenant-isolation capability table), §28 (1142–1146, manager/domain authority), §30 (1160–1170, the HTTP/admin surface blueprint), §34 (1210–1214), §35 (1216–1250), §36–§39 (1253–1300).
  - Every merged RFC-005 correction/remediation contract naming "Admin Usage Billing Surface" as remaining, unbuilt scope: `RFC-005-RESERVATION-ADMISSION-CORRECTION-CONTRACT.md`, `RFC-005-FUNDING-PROVIDER-FLOW-CORRECTION-CONTRACT.md`, `RFC-005-RECEIPT-BOUNDARY-CORRECTION-CONTRACT.md`, `RFC-005-JOB-EVENT-DISPATCH-COMPLETION-CORRECTION-CONTRACT.md`, `RFC-005-RECONCILIATION-RACE-CORRECTION-CONTRACT.md`, and the already-known-in-full `RFC-005-FUNDING-CONFIRMATION-CONCURRENCY-CORRECTION-CONTRACT.md` (including its exceptional post-merge implementation correction and both of its own focused review fixes).
  - `RFC-005-M2-CONTRACT.md` and `RFC-005-M3-CONTRACT.md` and `RFC-005-M4-CONTRACT.md` — the three milestones that built (M2) or exposed the manager-layer methods this contract wires an HTTP surface onto, or (M3/M4) built the two narrow admin sub-surfaces this contract explicitly does not duplicate.
  - `routes/admin.php` (full file, 767 lines); `app/Http/Controllers/Admin/PaymentProviderEventController.php`, `app/Http/Controllers/Admin/AdditionalBusinessSlotAgreementController.php`, `app/Http/Controllers/Admin/BusinessController.php`, `app/Http/Controllers/Admin/WorkspaceEntitlementController.php`, `app/Http/Controllers/Admin/WorkspacePlanCatalogController.php` (full files); their FormRequests, views, and every existing Feature test governing an Admin controller in this codebase (`tests/Feature/Workspace/AdminWorkspaceEntitlementControllerTest.php`, `tests/Feature/Workspace/AdminWorkspacePlanCatalogControllerTest.php`, `tests/Feature/Business/AdminBusinessControllerTest.php`, `tests/Feature/Usage/SlotAgreementAdminAuthorityTest.php`, `tests/Feature/Usage/EntitlementCatalogSourceBoundaryTest.php`).
  - `app/Http/Middleware/EnsureUserIsAdministrator.php` (full file); `app/Helpers/Helper.php`'s `menuData()` (lines 550–902); `app/Providers/MenuServiceProvider.php`; `app/Providers/RouteServiceProvider.php` (the `mapWebRoutes()` group wrapping `routes/admin.php`).
  - `app/Library/Usage/UsageWalletManager.php`, `app/Library/Usage/BillingProfileManager.php`, `app/Library/Usage/PaymentInstrumentManager.php`, `app/Library/Usage/UsageBillingCheckoutManager.php` (full files, all public and relevant private methods).
  - Every repository contract under `app/Repositories/Contracts/` whose name contains Usage/Wallet/Funding/Ledger/Limit/BillingReceipt/AddonPurchase (19 files); the four repositories this contract widens, in full, with their Eloquent implementations; the underlying migrations for `business_usage_wallets`, `business_usage_ledger_entries`, `business_usage_wallet_billing_status_transitions`, `business_usage_limit_transitions`, `platform_feature_usage_safety_limits`, `usage_meters` — each confirmed by direct read this pass, not merely cited from a prior contract.
  - `app/Enums/Usage/*.php` (every enum in the Usage namespace); `app/Policies/UserPolicy.php` (confirmed the only Policy class in the repository — no Usage/Wallet/Business Policy exists).
  - None of the six already-merged corrections in this sequence is reopened, contradicted, or referenced as needing amendment by anything below.

---

## 1. Current state — mechanically audited

### 1.1 What the RFC itself specifies (§30, line 1166, verbatim)

> "Admin surface — corrected this round to reflect the narrowed charge-origination authority: `Admin\UsageBillingController` (or similar): read balance/ledger/caps for any Business, issue manual/promotional credit, set/clear `billing_status`, set the platform safety limit, view (never edit) `provider_cost_micro` aggregates, resume/retry an already-created, payer-authorized funding attempt or slot renewal (never originate a fresh one, §16/§19/§22), perform the manual additional-slot allocation action (using the administrator's own real identity), cancel an additional-slot agreement (mandatory reason), and review and disposition webhook events that have exhausted their claim/retry attempts... — with disposition (`disposed_at`/`disposed_by_user_id`/`disposition_note`) being the sole path to that event's eventual payload purge (§21)."

The RFC names this provisionally as **one** unified controller. §24's own capability table (lines 1058–1076) locks the exact Platform-administrator column for every relevant row: view balance/ledger for any Business (**Yes, any Business**), manage billing contact (Yes), manage a payment instrument (Yes), set payer (Yes, mandatory reason), originate a fresh top-up/auto-recharge/slot-agreement checkout (**No**, unconditionally), resume/retry an already-created attempt (**Yes, mandatory reason — the sole surviving admin charge-adjacent capability**), configure Business spend cap/per-feature/platform-safety limits (**Yes, including the platform safety limit itself**), issue manual/promotional credit (**Yes only**), set/clear `billing_status = 'suspended'` (**Yes only**), view `provider_cost_micro` (**Yes only**), review/disposition exhausted webhook events (Yes only).

### 1.2 What M3/M4 actually built, and explicitly, on record, declined to build

M3 built `Admin\PaymentProviderEventController` (§21's webhook-exhaustion review/disposition slice only) and M4 built `Admin\AdditionalBusinessSlotAgreementController` (its own three slot-agreement-scoped admin capabilities only). **Both are confirmed live and unmodified in this codebase today** — `app/Http/Controllers/Admin/PaymentProviderEventController.php` (66 lines: `index()`, `dispose()`) and `app/Http/Controllers/Admin/AdditionalBusinessSlotAgreementController.php` (107 lines: `index()`, `show()`, `retryRenewal()`, `allocate()`, `cancel()`), with routes at `routes/admin.php:695-696` and `:708-712` respectively, both inside the `EnsureUserIsAdministrator` group opened at `routes/admin.php:666`.

Both milestones explicitly declined to build the rest, on record. `RFC-005-M4-CONTRACT.md:354-358`, verbatim: *"No admin Usage Billing controller of any kind exists yet (confirmed by a targeted search — `Admin\UsageBillingController`, named provisionally by RFC-005 §30, was never created by M1–M3). M4 does not create that broader, all-milestone unified controller — doing so would pull in M1/M2/M3 [scope]."* And M4's own forbidden-scope list, `RFC-005-M4-CONTRACT.md:2828-2830`: *"A unified, all-milestone `Admin\UsageBillingController` (or any M1–M3 admin capability — balance view, credit issuance, billing-status toggle, webhook-event disposition) is found necessary to satisfy an M4..."* — named and refused.

**This contract is exactly that refused scope: the remaining M1–M3 admin capability set.** It does not touch, rebuild, or duplicate either existing controller.

### 1.3 What already exists at the manager layer, fully built, with zero HTTP caller

Three manager methods are **fully implemented, already platform-administrator-gated, already RFC-compliant, and confirmed by direct read to have no admin-controller caller anywhere in the repository today**:

- **`UsageWalletManager::setBillingStatus(Business $business, WalletBillingStatus $status, BillingStatusTransitionSource $source, ?int $actorUserId, string $reason): void`** (`app/Library/Usage/UsageWalletManager.php:1284`). Gated via `assertPlatformAdministrator()` when `$source === BillingStatusTransitionSource::AdminAction` (requiring a non-null `actorUserId`). Records a `BusinessUsageWalletBillingStatusTransition` row (`wallet_id`, `business_id`, `from_status`, `to_status`, `source`, `actor_user_id` nullable, `reason` — **NOT NULL**, `created_at`; confirmed directly from `database/migrations/2026_08_16_130004_create_business_usage_wallet_billing_status_transitions_table.php:18-26`), updates `wallets.billing_status`, dispatches `BusinessWalletBillingStatusChanged`. Its own docblock (`UsageWalletManager.php:1276-1283`) states it "ships as a fully functional, tested capability with zero calling production code path at M2 — no admin HTTP route exists yet."
- **`UsageWalletManager::setSafetyLimit(string $featureKey, string $maxMonthlyLimitMicro, int $actorUserId, string $reason): void`** (`app/Library/Usage/UsageWalletManager.php:1240`). Platform-administrator-only (`assertPlatformAdministrator()` at line 1242). Upserts the `platform_feature_usage_safety_limits` row keyed by `feature_key` (`unique`, `varchar(64)`, `NOT NULL`; confirmed from `database/migrations/2026_08_16_130002_create_platform_feature_usage_safety_limits_table.php:18`), records a `BusinessUsageLimitTransition` with `business_id: null`, `limit_type: platform_safety_limit`, `feature_key` populated, `actor_user_id`/`reason` both **NOT NULL** (confirmed from `database/migrations/..._create_business_usage_limit_transitions_table.php:18-26`).
- **`UsageBillingCheckoutManager::retryFundingAttemptAsAdministrator(BusinessFundingAttempt $attempt, int $actorUserId, string $reason): FundingAttemptResult`** (`app/Library/Usage/UsageBillingCheckoutManager.php:584-619`). Throws `FundingAttemptNotResumableException` unless state is `ProviderPending`/`RequiresAction`/`Failed` and a provider reference exists; re-verifies with the provider (never trusts local state); on success calls the shared `confirmSucceeded()` finalizer with `TransitionSource::AdminAction`. Confirmed by repo-wide grep: its only callers today are `tests/Feature/Usage/FundingAttemptPayerConsentTest.php` and this method's own docs — **no controller under `app/Http/Controllers/Admin` references it.**

None of these three methods requires any change. This contract's design (§2) wires an HTTP entry point to each, exactly as written.

### 1.4 What is genuinely missing (net-new)

- **No manual/promotional-credit method exists anywhere.** `creditFromFunding()` (`UsageWalletManager.php:879`) is scoped exclusively to funding-attempt-backed credits (requires a `fundingAttemptId` FK and a caller-supplied `correlationKey`; takes no `actorUserId`/`reason`). A repo-wide grep for `issueManualCredit|issuePromotionalCredit` returns zero matches in `app/`. `UsageLedgerEntryType::ManualCredit` (`'manual_credit'`) and `::PromotionalCredit` (`'promotional_credit'`) exist as enum cases (`app/Enums/Usage/UsageLedgerEntryType.php:20-21`) with zero producing code path — confirmed by repo-wide grep. The ledger table's own `actor_user_id`/`reason` columns (`business_usage_ledger_entries.actor_user_id`/`.reason`, both nullable, confirmed present in the migration) are populated by nothing today.
- **No read/list repository method exists for wallets, ledger entries, or funding attempts.** `BusinessUsageWalletRepository` has `findByBusinessId`/`findForUpdateByBusinessId` only — no list-all method (not needed by this contract; Business discovery is not duplicated, §2.6). `BusinessUsageLedgerEntryRepository` is explicitly append-only with no list/paginate method (`create`, `findById`, `findForUpdateById`, `sumCommittedAmountForFeature` only). `BusinessFundingAttemptRepository::findOutstandingForBusiness(int, string): ?BusinessFundingAttempt` (`app/Repositories/Contracts/BusinessFundingAttemptRepository.php:22`) exists but is **not** suitable for an admin listing screen — confirmed by direct read of its Eloquent implementation (`EloquentBusinessFundingAttemptRepository.php:37-49`): it is a locking (`FOR UPDATE`) read, documented as "the authoritative duplicate-attempt guard `UsageBillingCheckoutManager::initiateCharge()` calls from inside its own wallet-row-locked transaction" — using it for a read-only admin display would incorrectly acquire a row lock outside that transactional context, and it returns at most one row per purpose, not a browsable history. A new, non-locking, purpose-built read method is required (§2.4).
- **`PlatformFeatureUsageSafetyLimitRepository` has no list-all method** (`findByFeatureKey`/`findForUpdateByFeatureKey` only) — an admin screen showing every currently configured platform safety limit has nothing to call.
- **`BusinessUsageWalletBillingStatusTransitionRepository` and `BusinessUsageLimitTransitionRepository` are both create-only** (append-only, no list method) — an admin "history" view for either has nothing to call.
- **No margin/provider-cost aggregate computation exists anywhere.** Confirmed by repo-wide grep for `margin` (only unrelated CSS hits) and `provider_cost_micro` (only raw column references in `ActivateConversationsUsageRate`, the three models, and `setActiveRate()`/`reserve()`/`commit()`, which merely copy the value from `BusinessUsageRate` into `BusinessUsageReservation`/`BusinessUsageLedgerEntry` — never aggregate or compare it against retail revenue).
- **No sidebar navigation entry exists for any Usage-domain, or in fact any RFC-00x admin-only, route.** `Helper::menuData()`'s `'admin'` array (`app/Helpers/Helper.php:552-902`) has no entry for Business, Opportunity, Workspace, Workspace Plan Catalog, Provider Events, or Additional Slot Agreements — a systemic, pre-existing gap across every RFC-00x admin module, not specific to Usage.
- **No dedicated permission string exists** (`config/permissions.php`) for any Usage-domain admin action — both existing controllers rely solely on the route group's blanket `can:access backend` Gate plus `EnsureUserIsAdministrator`, and both controllers' own docblocks state this is intentional (`PaymentProviderEventController.php:14-15`: "no new config/permissions.php entry is authorized").
- **No Policy class exists for Usage/Wallet/Business** — `app/Policies/UserPolicy.php` is the only Policy in the repository, scoped to self-service `User` profile updates. All existing Usage-domain admin authorization is done via a private-method convention (`assertPlatformAdministrator()`, duplicated verbatim in `UsageWalletManager` and `UsageBillingCheckoutManager`), not a Laravel Policy class.

---

## 2. Design

### 2.1 One unified `Admin\UsageBillingController` — mechanically preferable, locked

**Locked: a single new controller, `app/Http/Controllers/Admin/UsageBillingController.php`, covers every capability this contract builds.** This directly matches the RFC's own §30 naming and is mechanically preferable to narrower controllers for three independently sufficient reasons:

1. **The RFC itself already named it this way**, and the only reason M3/M4 built narrower controllers instead was that each was scoped to its own already-separately-modeled sub-domain with its own state machine (webhook-event disposition; slot-agreement lifecycle) — neither M3 nor M4 was ever chartered to build the wallet/credit/limit/funding-attempt-resume capability set this contract closes. This contract *is* chartered to build that whole set in one pass, so the narrowing rationale that produced two controllers before does not apply here.
2. **Every capability in this contract's scope operates on the same small set of manager methods** (`UsageWalletManager`, `UsageBillingCheckoutManager`) through the same authorization boundary (`assertPlatformAdministrator()`) and the same mandatory-reason convention — unlike webhook-event disposition or slot-agreement allocation, none of these capabilities has its own independent state machine that would justify a dedicated controller.
3. **Splitting it would fragment a single administrator's mental model** of "the usage-billing screen for Business X" across multiple controllers with duplicated Business-resolution logic, for no corresponding gain in cohesion — the closest precedent for a correctly-scoped split, `WorkspaceEntitlementController` vs. `WorkspacePlanCatalogController`, splits along a genuine resource-ownership boundary (Workspace-scoped entitlement mutation vs. platform-wide catalog read) that this contract's own capability set does not have, except for the one platform-wide action (§2.1.1 below), which stays in the same controller under a different, non-Business-scoped route.

**This controller does not touch, wrap, or extend `PaymentProviderEventController` or `AdditionalBusinessSlotAgreementController`.** Those remain exactly as shipped; this contract's only interaction with them is a read-only navigation cross-reference (§2.6).

#### 2.1.1 Seven actions, all thin, one manager/repository call each

| Action | Verb + URI | What it does | Calls |
|---|---|---|---|
| `show` | `GET businesses/{business}/usage-billing` | Renders the wallet/ledger/limits/funding-attempts dashboard for one Business. | `BusinessUsageWalletRepository::findByBusinessId()`, `BusinessFeatureUsageLimitRepository::forBusiness()`, `BusinessUsageLedgerEntryRepository::forBusinessPaginated()` (new), `BusinessFundingAttemptRepository::recentForBusiness()` (new), `BusinessUsageWalletBillingStatusTransitionRepository::forBusiness()` (new), `BusinessUsageLimitTransitionRepository::forBusiness()` (new) |
| `issueManualCredit` | `POST businesses/{business}/usage-billing/credit` | Issues an auditable manual or promotional credit. | `UsageWalletManager::issueManualCredit()` (new, §2.3) |
| `suspendBilling` | `POST businesses/{business}/usage-billing/suspend` | Sets `billing_status = Suspended`. | `UsageWalletManager::setBillingStatus()` (existing, unmodified) |
| `resumeBilling` | `POST businesses/{business}/usage-billing/resume` | Sets `billing_status = Active`. | `UsageWalletManager::setBillingStatus()` (existing, unmodified) |
| `retryFundingAttempt` | `POST businesses/{business}/usage-billing/funding-attempts/{attempt}/retry` | Resumes an already-created, payer-authorized attempt. | `UsageBillingCheckoutManager::retryFundingAttemptAsAdministrator()` (existing, unmodified) |
| `safetyLimits` | `GET usage-billing/safety-limits` | Platform-wide (not Business-scoped): lists every configured platform feature-usage safety limit. | `PlatformFeatureUsageSafetyLimitRepository::all()` (new), `BusinessUsageLimitTransitionRepository::platformSafetyLimitHistory()` (new) |
| `setSafetyLimit` | `POST usage-billing/safety-limits` | Sets or updates one platform-wide safety limit. | `UsageWalletManager::setSafetyLimit()` (existing, unmodified) |

Every write action follows the identical shape already established by `AdditionalBusinessSlotAgreementController` (§1.2): resolve the target model (404 if not found, before any manager call — RFC §24 line 1078's "fail closed with a 404-shaped response, never a 403" for unrelated resources), call exactly one manager method with `(int) Auth::id()` as the actor and the FormRequest's validated `reason`, catch only the specific typed exception(s) that method can throw and map to `flash_error`, otherwise `flash_success` redirect back to `show`. No action ever contains a raw `DB::table(...)` call, a raw Eloquent write against `BusinessUsageWallet`/`BusinessUsageLedgerEntry`/any Usage-domain model, or more than one manager call.

`{business}` route-model-binds by primary key `id` — confirmed by direct read of `app/Models/Business.php` (no `getRouteKeyName()` override) and `routes/admin.php:597-600` (the existing `Route::resource('businesses', ...)` and `businesses/{business}/status` route both already bind this way with no `whereUuid`/`whereNumber` constraint needed). `{attempt}` binds by primary key `id`, constrained `->whereNumber('attempt')`, matching the existing `additional-business-slot-agreements/{agreement}/renewals/{charge}/retry` precedent (`routes/admin.php:710`) exactly.

### 2.2 Routes — exact addition to `routes/admin.php`

Added inside the **same** `EnsureUserIsAdministrator` group already wrapping the `businesses` resource (`routes/admin.php:596-601`), immediately after the existing `businesses/{business}/status` route:

```php
Route::middleware(EnsureUserIsAdministrator::class)->group(function () {
    Route::resource('businesses', 'BusinessController', [
        'only' => ['index', 'show', 'edit', 'update'],
    ]);
    Route::patch('businesses/{business}/status', 'BusinessController@updateStatus')->name('businesses.status.update');

    // RFC-005 Admin Usage Billing Surface Contract — remediation #5.
    Route::prefix('businesses/{business}/usage-billing')->name('businesses.usage-billing.')->group(function () {
        Route::get('/', 'UsageBillingController@show')->name('show');
        Route::post('credit', 'UsageBillingController@issueManualCredit')->name('credit');
        Route::post('suspend', 'UsageBillingController@suspendBilling')->name('suspend');
        Route::post('resume', 'UsageBillingController@resumeBilling')->name('resume');
        Route::post('funding-attempts/{attempt}/retry', 'UsageBillingController@retryFundingAttempt')
            ->name('funding-attempts.retry')->whereNumber('attempt');
    });
    Route::get('usage-billing/safety-limits', 'UsageBillingController@safetyLimits')->name('usage-billing.safety-limits.index');
    Route::post('usage-billing/safety-limits', 'UsageBillingController@setSafetyLimit')->name('usage-billing.safety-limits.update');
});
```

Resulting full route names (with the `admin.` prefix `RouteServiceProvider.php:70` already applies to the whole file): `admin.businesses.usage-billing.show`, `admin.businesses.usage-billing.credit`, `admin.businesses.usage-billing.suspend`, `admin.businesses.usage-billing.resume`, `admin.businesses.usage-billing.funding-attempts.retry`, `admin.usage-billing.safety-limits.index`, `admin.usage-billing.safety-limits.update`. **Exactly 7 new route declarations.** Placed inside the businesses group (not the separate Workspace/Usage group at line 666) because every action but the two safety-limit routes is Business-scoped, keeping Business-admin routes textually together; the two safety-limit routes are placed adjacently in the same group rather than opening a third `EnsureUserIsAdministrator::class` wrapper, since one additional gate-wrapper per unrelated concern would fragment this file's own established convention of a small, fixed number of such groups.

### 2.3 New manager method: `UsageWalletManager::issueManualCredit()`

```php
public function issueManualCredit(Business $business, UsageLedgerEntryType $entryType, int $amountMicro, int $actorUserId, string $reason): void
{
    $this->assertPlatformAdministrator($actorUserId);

    if (! in_array($entryType, [UsageLedgerEntryType::ManualCredit, UsageLedgerEntryType::PromotionalCredit], true)) {
        throw new InvalidAdminCreditEntryTypeException($entryType->value);
    }

    if ($amountMicro <= 0) {
        throw new InvalidAdminCreditAmountException($amountMicro);
    }

    if (trim($reason) === '') {
        throw new InvalidAdminCreditReasonException($business->id); // reason-blank branch; exact exception type TBD at implementation, see note below
    }

    DB::transaction(function () use ($business, $entryType, $amountMicro, $actorUserId, $reason) {
        $wallet = $this->walletRepository->findForUpdateByBusinessId((int) $business->id);
        // ... debt-clears-first, then credits available_balance_micro,
        // identical arithmetic shape to creditFromFunding()'s own
        // existing debt-clearing logic (UsageWalletManager.php:879-963),
        // but implemented as new, independent code — creditFromFunding()
        // itself is not modified, reused, or called (§5.3's own
        // boundary test enforces this at the source level).

        $this->ledgerRepository->create([
            'business_id' => $business->id,
            'wallet_id' => $wallet->id,
            'entry_type' => $entryType->value,
            'available_delta_micro' => $creditedToAvailable, // computed after debt-clearing
            'reserved_delta_micro' => 0,
            'debt_delta_micro' => -$clearedFromDebt,
            'gross_amount_micro' => $amountMicro,
            'currency_id' => $wallet->currency_id,
            'actor_user_id' => $actorUserId,
            'reason' => $reason,
            'correlation_key' => 'admin_credit:'.$business->id.':'.(string) Str::uuid(),
            'created_at' => now(),
            // feature_key, meter_key, period_key, quantity, rate_id,
            // rate_version, retail_rate_micro, provider_cost_micro,
            // unit_label, rounding_rule, reservation_id,
            // funding_attempt_id, provider_reference, reversed_entry_id
            // all null — this entry has no rate/usage/funding-attempt/
            // provider basis, matching UsageLedgerEntryType::ManualCredit/
            // ::PromotionalCredit's own schema-level intent.
        ]);
    });
}
```

Locked design points:
- **Platform-administrator-gated via the existing private `assertPlatformAdministrator()`**, the identical convention `setSafetyLimit()`/`setBillingStatus()`/`UsageBillingCheckoutManager::assertPlatformAdministrator()` already use (a direct `DB::table('users')->where('id', $actorUserId)->value('is_admin')` read, never trusting a passed-in flag) — no new authorization mechanism is introduced.
- **`entryType` is constrained to exactly `ManualCredit`/`PromotionalCredit`**, checked inside the manager (not only the FormRequest), matching this codebase's own established defense-in-depth discipline (the FormRequest already constrains this via `in:manual_credit,promotional_credit`, and the manager independently re-checks, exactly as `retrySlotRenewalAsAdministrator()`/`allocateSlotAgreementAsAdministrator()` independently re-check their own mandatory-reason precondition even though their FormRequests already enforce it, `UsageBillingCheckoutManager.php:1547-1562,2131-2140`).
- **A fresh, per-call correlation key** (`'admin_credit:'.$business->id.':'.Str::uuid()`) satisfies `business_usage_ledger_entries.correlation_key`'s `UNIQUE` constraint. Unlike `creditFromFunding()`, this method has no funding attempt or webhook to naturally key against and no replay/redelivery scenario to guard against (a manual admin action is not retried by any queue/webhook mechanism) — so, unlike the funding-confirmation path, no unique-constraint collision is ever expected in normal operation; a double form submission would produce two independent, genuinely-intended-as-separate ledger entries rather than erroring, mitigated only by ordinary UI affordances (a disabled-after-submit button), not by manager-level idempotency. This is a disclosed, deliberate design choice, not an oversight — flagged again in §12.
- **Debt-clears-first, then credits `available_balance_micro`**, mirroring `creditFromFunding()`'s own existing arithmetic shape for consistency of wallet semantics across every credit-type ledger entry — but as **entirely new, independent code**; `creditFromFunding()` itself is not modified, and `issueManualCredit()` never calls it. `SendReceiptNotification` is never dispatched by this method — a manual/promotional credit has no provider-side evidence to attach a receipt to, and this is an intentional exclusion, not a gap (§9).
- **No domain event is dispatched by this method.** `RFC-005-JOB-EVENT-DISPATCH-COMPLETION-CORRECTION-CONTRACT.md:389` explicitly named "`ManualCredit`/`PromotionalCredit`/`UsageChargeReversal`/`CorrectionReversal` ledger-entry producers or dispatch" as excluded from that contract's own scope — deferred, by name, to this one. This contract locks the **producer** (the manager method above) but deliberately does not design a new domain event for it, since none of the RFC's own text (§28/§29/§34) names a required event for this specific action, and inventing one would exceed this contract's own bounded scope (view/credit/suspend/limit/resume — an HTTP+manager+repository surface, not a notification/observability design). This is a genuinely disclosed exclusion, restated in §9 and §12, not a silent omission.
- Exact exception-type naming for the three new guard branches (invalid entry type, non-positive amount, blank reason) is left to implementation to choose consistent, narrowly-scoped names (e.g. `InvalidAdminCreditEntryTypeException`, `InvalidAdminCreditAmountException`, `InvalidAdminCreditReasonException`) mirroring the existing `FundingAttemptNotResumableException`/`UnauthorizedSlotAgreementActionException` naming convention (one exception class per distinct guard condition, each extending a shared base if the codebase already has one for this domain — not otherwise specified by this contract, since it is a naming-only implementation detail with no governance weight).

### 2.4 New repository methods — exact, per contract

Every new method is a plain, non-locking read (the two write paths this contract adds — `issueManualCredit`, and the pre-existing `setSafetyLimit`/`setBillingStatus`/`retryFundingAttemptAsAdministrator` — already lock correctly inside their own manager methods; no new locking read is required anywhere in this contract's own scope):

- **`BusinessUsageLedgerEntryRepository::forBusinessPaginated(int $businessId, int $perPage, array $filters = []): LengthAwarePaginator`** — `$filters` supports `entry_type` (exact match) and `from`/`to` (inclusive `created_at` date-range bounds). Ordered `orderByDesc('id')`. New contract method + Eloquent implementation.
- **`BusinessFundingAttemptRepository::recentForBusiness(int $businessId, int $limit = 20): Collection`** — plain (no `FOR UPDATE`), `orderByDesc('id')->limit($limit)->get()`. Deliberately distinct from, and never delegates to, the existing locking `findOutstandingForBusiness()` (§1.4). New contract method + Eloquent implementation.
- **`PlatformFeatureUsageSafetyLimitRepository::all(): Collection`** — `orderBy('feature_key')->get()`. New contract method + Eloquent implementation.
- **`BusinessUsageWalletBillingStatusTransitionRepository::forBusiness(int $businessId): Collection`** — `where('business_id', $businessId)->orderByDesc('id')->get()`. New contract method + Eloquent implementation.
- **`BusinessUsageLimitTransitionRepository::forBusiness(int $businessId): Collection`** — `where('business_id', $businessId)->orderByDesc('id')->get()` (per-Business spend-cap/feature-limit history). **`BusinessUsageLimitTransitionRepository::platformSafetyLimitHistory(): Collection`** — `whereNull('business_id')->where('limit_type', UsageLimitType::PlatformSafetyLimit->value)->orderByDesc('id')->get()` (platform-safety-limit-only history, confirmed correct against the migration's own nullable `business_id`/`feature_key` columns, §1.2). Both new contract methods + Eloquent implementation, on the same repository.

**No new method is added to `BusinessUsageWalletRepository` or `BusinessFeatureUsageLimitRepository`** — `findByBusinessId()` and `forBusiness()` already exist and are reused verbatim (§1.4 confirms `forBusiness()` already supports the per-Business limits read this contract needs).

### 2.5 Provider-cost/margin aggregate — read-only, bounded, locked

**`BusinessUsageLedgerEntryRepository::marginAggregateRowsForBusiness(int $businessId, string $periodKey): Collection`** (new contract method + Eloquent implementation) returns raw rows — `feature_key`, `quantity`, `provider_cost_micro`, `gross_amount_micro` — for every ledger entry belonging to that Business and period whose `entry_type` is `usage_charge` or `usage_overage_charge` (the only two entry types ever populated with a rate/cost basis, confirmed §1.4) and whose `provider_cost_micro` is not null. The repository owns the query; it performs no aggregation itself.

A new, small, stateless computation — either a private method on `UsageBillingController` or a dedicated value object, left to implementation discretion since it carries no governance weight either way, but **must** use `bcmath` (matching the codebase's own existing `UsageWalletManager::bcRoundHalfUp()` static helper convention, since `quantity` is a `decimal(14,6)` and float arithmetic would silently lose precision) — sums `gross_amount_micro` as retail revenue, sums `provider_cost_micro × quantity` (via `bcmul`) as provider cost, and reports `revenue − cost` as margin, grouped by `feature_key`, for the requested Business and period. **Never edits `provider_cost_micro`** — this is a pure read aggregation; no write path to that column exists anywhere in this contract's own scope (§3).

The exact period-key/feature-key grouping granularity locked here (one month, one feature, per row) is a reasonable default derived from how every other period-scoped concept in this domain is already keyed (`period_key` format `YYYY-MM`, matching `business_usage_ledger_entries.period_key`'s own existing shape) — not an RFC-mandated grouping (§24/§30 say only "view... aggregates," without specifying granularity). This is flagged again in §12 as a minor, implementation-level choice a human could reasonably adjust without contract amendment.

### 2.6 Views

All new views live under `resources/views/admin/usage-billing/` and use the `x-*` design-system component idiom (`x-card`, `x-table`, `x-badge`, `x-alert`, `x-empty-state`, `x-button`) — confirmed as the current, consistently-applied convention within the Usage domain specifically (`provider-events/index.blade.php`, `additional-business-slot-agreements/index.blade.php` and `show.blade.php`), not the older raw-Bootstrap idiom still used by `workspace-plan-catalog/index.blade.php`.

- **`resources/views/admin/usage-billing/businesses/show.blade.php`** — the single per-Business dashboard: wallet snapshot (available/reserved/debt balances, spend cap, committed/reserved spend this period, `billing_status` badge, auto-recharge configuration shown **read-only**, never editable here — §3), configured per-feature limits (`x-table`), a paginated/filterable ledger listing (`{{ $ledgerEntries->links() }}`, entry-type and date-range filter inputs as a `GET` form, mirroring `AdditionalBusinessSlotAgreementController::index()`'s own `->paginate(25)` convention), recent funding attempts with an inline "Retry" form per resumable row (`state` in `ProviderPending`/`RequiresAction`/`Failed`, mirroring the show page's own conditional-form-per-state pattern already established at `additional-business-slot-agreements/show.blade.php`), billing-status suspend/resume forms (each a single required `reason` text input, no confirmation modal — matching the established no-JS-modal convention), manual-credit issuance form (`entry_type` select constrained to Manual Credit/Promotional Credit, an amount input, a required `reason`), and billing-status/limit-change history tables. If no wallet exists for the Business (an edge case; every Business should have one via `initializeWalletForNewBusiness()` at creation, M1), the page renders an explicit `x-empty-state` rather than throwing.
- **`resources/views/admin/usage-billing/safety-limits/index.blade.php`** — platform-wide: a table of every currently configured safety limit (`x-table`, one row per `feature_key`), an inline set/update form per row plus one "configure a new feature key" form, and the platform-safety-limit-scoped transition history.
- **`resources/views/admin/businesses/show.blade.php` (MODIFIED, one line)** — adds a single link to `admin.businesses.usage-billing.show` from the existing Business detail page (mirroring the existing `admin.businesses.edit` link already there at line 13), so an administrator reaches the new dashboard from the Business they are already viewing rather than through a new, duplicated Business-search UI. This is the one, narrowly-justified, pre-existing-file modification this contract makes outside its own new files — explicitly required because Business discovery already exists (`admin.businesses.index`) and must not be rebuilt (per this contract's own explicit instruction not to duplicate already-shipped surfaces).

### 2.7 Navigation integration — one new nav group, read-only cross-links to the two existing surfaces

**Locked: one new top-level nav entry, "Usage Billing," added to `Helper::menuData()`'s `'admin'` array** (`app/Helpers/Helper.php`, appended after the existing entries ending at line 902), shaped as a `submenu` with three children:

```php
[
    'url' => '',
    'slug' => '',
    'name' => 'Usage Billing',
    'i18n' => 'Usage Billing',
    'icon' => 'credit-card',
    'submenu' => [
        [
            'url' => url(config('app.admin_path') . '/usage-billing/safety-limits'),
            'slug' => config('app.admin_path') . '/usage-billing/safety-limits',
            'name' => 'Safety Limits', 'i18n' => 'Safety Limits',
            'access' => 'access backend', 'icon' => 'shield',
        ],
        [
            'url' => url(config('app.admin_path') . '/provider-events'),
            'slug' => config('app.admin_path') . '/provider-events',
            'name' => 'Provider Events', 'i18n' => 'Provider Events',
            'access' => 'access backend', 'icon' => 'alert-triangle',
        ],
        [
            'url' => url(config('app.admin_path') . '/additional-business-slot-agreements'),
            'slug' => config('app.admin_path') . '/additional-business-slot-agreements',
            'name' => 'Additional Slot Agreements', 'i18n' => 'Additional Slot Agreements',
            'access' => 'access backend', 'icon' => 'layers',
        ],
    ],
],
```

This is the **only** shared navigation/read-model integration point with the two already-shipped surfaces this contract touches, and it is genuinely required and explicitly justified: it is the first sidebar nav entry any RFC-00x admin-only module has ever received (§1.4 — a pre-existing, systemic gap this contract does not otherwise attempt to close for unrelated modules), and grouping it with the two existing, functionally-related Usage-domain admin surfaces gives an administrator one discoverable entry point instead of three undiscoverable ones, **without duplicating either surface's own routes, controllers, or mutations** — each child link points at the existing route name verbatim (`admin.provider-events.index`, `admin.additional-business-slot-agreements.index`); no new controller action, view, or FormRequest is created for either. The per-Business dashboard itself is deliberately **not** a sidebar destination (it has no meaning without a specific Business) and is reached only via §2.6's link on the Business detail page — consistent with not rebuilding Business discovery.

`access: 'access backend'` on every child (no new permission string) matches the established convention (`PaymentProviderEventController.php:14-15`'s own "no new config/permissions.php entry is authorized," and the Dashboard entry's own identical `'access' => 'access backend'` at `Helper.php:560`) — whether a dedicated permission string should eventually replace this is flagged as an open, genuinely undecided item (§12), not resolved here.

### 2.8 Read-model/query ownership — no raw billing-table access from controllers

**Locked, and enforced by a static source-boundary test (§5.3):** `UsageBillingController` contains zero raw `DB::table(...)`/`DB::select(...)` calls and zero direct Eloquent query-builder calls against `BusinessUsageWallet`, `BusinessUsageLedgerEntry`, `BusinessFeatureUsageLimit`, `PlatformFeatureUsageSafetyLimit`, `BusinessUsageWalletBillingStatusTransition`, or `BusinessUsageLimitTransition`. Every read and every write goes through the seven repositories/one manager named in §2.1's table — the controller's own body is a sequence of "resolve route params → call exactly one repository or manager method → pass the result to the view / redirect," identical in shape to `AdditionalBusinessSlotAgreementController`'s own established discipline (§1, "one manager call per action").

### 2.9 Authorization model — reused, not reinvented

The identical four-layer defense-in-depth already established for `AdditionalBusinessSlotAgreementController` applies unchanged, with no new layer invented:

1. **Route-group Gate**: `can:access backend` (`RouteServiceProvider.php:67`), applied to every route in `routes/admin.php` including this contract's own.
2. **`EnsureUserIsAdministrator` middleware** (`app/Http/Middleware/EnsureUserIsAdministrator.php`), wrapping this contract's own new route block exactly as it wraps the existing `businesses` resource (§2.2) — a direct `$request->user()->is_admin` check, independent of any permission string, throwing `AuthorizationException` on failure.
3. **No controller-level `$this->authorize(...)` call** — matching the M3/M4 Usage-domain convention (not the RFC-004 Workspace convention), since no dedicated permission string is introduced (§2.7, §12).
4. **Manager-level independent re-verification**: every write action's underlying manager method (`issueManualCredit`, `setBillingStatus`, `setSafetyLimit`, `retryFundingAttemptAsAdministrator`) calls `assertPlatformAdministrator()` itself, so a mutation is safe even if the HTTP-layer boundary were somehow bypassed — proven the same way `SlotAgreementAdminAuthorityTest.php`'s `test_a_non_admin_actor_directly_invoking_...` methods already prove it for the existing controller (§5.2 adds the equivalent test for `issueManualCredit`).

**FormRequest `authorize()` returns `true` unconditionally on every new FormRequest**, matching the M3/M4 Usage-domain convention exactly (not the RFC-004 controller-level-Gate convention) — since this contract's entire scope lives inside the Usage domain, it follows that domain's own already-established pattern rather than introducing a third one (§1, "General platform-admin UI/controller conventions").

**Cross-tenant fail-closed behavior**: every action resolves `{business}` via implicit route-model binding (a 404 before any manager call, for a nonexistent Business id) and every write action's own manager method re-validates that the resolved model genuinely belongs to the id the URL named — no action ever accepts a Business id from the request body, only from the route, so one Business's mutation can never be redirected at another's data by a tampered form field. This directly matches RFC §24 line 1078's "Unrelated Workspace/Business resources fail closed with a 404-shaped response, never a 403."

### 2.10 Pagination/filtering

`forBusinessPaginated()` (§2.4) defaults to 25 rows/page (matching `AdditionalBusinessSlotAgreementController::index()`'s own `->paginate(25)`), accepts `entry_type` and `from`/`to` filters via `GET` query-string parameters, and the view renders `{{ $ledgerEntries->appends($request->query())->links() }}` so filters survive pagination. `recentForBusiness()` (§2.4) is deliberately **not** paginated — bounded by an explicit `$limit` (default 20), matching `PaymentProviderEventController::index()`'s own precedent of skipping pagination for a small, bounded list rather than over-engineering pagination for a screen that never needs it. Business discovery itself is not duplicated (§2.6) — the existing `admin.businesses.index` (already paginated/filterable, per `WorkspaceController`'s own analogous `paginateForAdmin()` precedent, not modified by this contract) remains the sole Business-discovery entry point.

---

## 3. Excluded/forbidden capabilities — explicit, per this contract's own instruction

This contract's design (§2) never, under any code path:

- **Originates a fresh customer charge.** `UsageBillingController` never calls `initiateTopUp()`, `initiateAutoRecharge()`, `initiateAddonPurchase()`, `quoteAdditionalSlotAgreement()`, or any other charge-originating manager method — confirmed absent from every action's own call table (§2.1.1) and enforced by a static source-boundary test (§5.3).
- **Enables or configures auto-recharge.** `configureAutoRecharge()` is never called; the dashboard's own auto-recharge display (§2.6) is read-only.
- **Directly edits any derived/formula counter** (`committed_spend_this_period_micro`, `reserved_spend_this_period_micro`, `recharged_this_period_micro`, `consecutive_recharge_failures`, or any wallet balance column) — every balance mutation happens exclusively inside `issueManualCredit()`'s own `UsageWalletManager`-owned transaction (§2.3), and every other wallet field this contract's views display is read-only.
- **Mutates any ledger row directly.** `BusinessUsageLedgerEntryRepository::create()` is called exactly once, from inside `issueManualCredit()` — no `update()`/`delete()` is ever called against a ledger entry anywhere in this contract's scope (the repository itself, unmodified by this contract, still exposes no such method at all, §1.4).
- **Bypasses manager/repository authority.** §2.8's boundary is absolute — no raw query against a billing table from the controller, ever.
- **Edits `provider_cost_micro`.** §2.5's aggregate is read-only by construction; no write path to that column is added anywhere.
- **Duplicates `PaymentProviderEventController`'s or `AdditionalBusinessSlotAgreementController`'s own mutations.** §2.7's integration is link-only.
- **Touches `PaymentInstrumentManager` at all.** No action in this contract's scope creates, attaches, detaches, or sets a default payment instrument — confirmed consistent with `PaymentInstrumentManager`'s own documented boundary ("No platform-administrator override exists for origination," citing RFC §9/§17 in its own docblock), which this contract does not attempt to change.

---

## 4. Guarantee-by-guarantee mapping (mirrors RFC §24's own capability-table rows)

1. **View any Business's wallet balance, ledger, and configured limits.** `show()` (§2.1.1) reads via `BusinessUsageWalletRepository::findByBusinessId()`, `BusinessFeatureUsageLimitRepository::forBusiness()`, `BusinessUsageLedgerEntryRepository::forBusinessPaginated()` (new) — no write, any Business, platform-administrator only (§2.9).
2. **Issue auditable manual/promotional credit.** `issueManualCredit()` (§2.1.1) calls `UsageWalletManager::issueManualCredit()` (new, §2.3) — writes `actor_user_id`/`reason` on the ledger row, platform-administrator-gated, mandatory reason.
3. **Set or clear `billing_status` suspension through the manager boundary.** `suspendBilling()`/`resumeBilling()` (§2.1.1) call the existing, unmodified `UsageWalletManager::setBillingStatus()` — no new mutation logic, only a new HTTP entry point.
4. **Configure the platform feature-usage safety limit.** `safetyLimits()`/`setSafetyLimit()` (§2.1.1) read via the new `PlatformFeatureUsageSafetyLimitRepository::all()` and write via the existing, unmodified `UsageWalletManager::setSafetyLimit()`.
5. **View provider-cost/margin aggregates without editing `provider_cost_micro`.** §2.5 — a new, bounded, read-only aggregate; §3 confirms no write path exists.
6. **Resume/retry an already-created, payer-authorized funding attempt where not already exposed.** `retryFundingAttempt()` (§2.1.1) calls the existing, unmodified `UsageBillingCheckoutManager::retryFundingAttemptAsAdministrator()` — confirmed unexposed by any admin controller before this contract (§1.3).
7. **Integrate links/read-only visibility for already-shipped provider-event and additional-slot-agreement admin surfaces without duplicating their mutations.** §2.7 — one nav group, three links, zero new controller actions for either existing surface.
8. **Preserve mandatory reasons and the administrator's real identity wherever the RFC requires them.** Every write action in §2.1.1's table requires a `reason` (validated `required|string|max:5000` at the FormRequest layer, re-validated inside the manager layer per §2.3's `issueManualCredit()` and the pre-existing manager methods' own established duplication of this check) and passes `(int) Auth::id()` as the actor — never a synthetic or session-derived alternate identity, matching RFC §24's own "using the administrator's own real identity" language (line 1074) and the split-provenance precedent already proven correct for the M4 slot-agreement surface (`SlotAgreementAdminAuthorityTest::test_manual_allocation_records_identity_provenance_correctly`).
9. **Never originate a fresh customer charge, enable auto-recharge, directly edit derived counters, mutate ledger rows directly, or bypass manager/repository authority.** §3, enforced by §5.3's static boundary test.

---

## 5. Test plan

### 5.1 New file: `tests/Feature/Usage/AdminUsageBillingControllerTest.php` — 26 methods

HTTP-level tests, following `tests/Feature/Workspace/AdminWorkspaceEntitlementControllerTest.php`'s own established template (§0's required reading) — the richest existing precedent in this codebase for exactly this shape of test:

1. `test_all_new_routes_exist_with_expected_verbs` — route-shape regression guard for all 7 routes (§2.2).
2. `test_guest_cannot_view_a_businesss_usage_billing_dashboard`
3. `test_a_non_admin_customer_cannot_view_a_businesss_usage_billing_dashboard`
4. `test_a_non_admin_account_is_blocked_even_with_usage_billing_permissions_in_session` — fail-closed regression, mirroring `AdminBusinessControllerTest`'s own identically-purposed test (§0).
5. `test_an_administrator_can_view_a_businesss_usage_billing_dashboard`
6. `test_the_dashboard_shows_wallet_balance_debt_and_configured_limits`
7. `test_the_ledger_listing_is_paginated`
8. `test_the_ledger_listing_can_be_filtered_by_entry_type_and_date_range`
9. `test_an_unknown_business_id_returns_not_found_before_any_manager_call`
10. `test_guest_cannot_issue_manual_credit`
11. `test_a_non_admin_customer_cannot_issue_manual_credit`
12. `test_an_administrator_can_issue_a_manual_credit`
13. `test_an_administrator_can_issue_a_promotional_credit`
14. `test_issuing_manual_credit_requires_a_mandatory_reason`
15. `test_issuing_manual_credit_requires_a_positive_amount`
16. `test_issuing_manual_credit_rejects_a_disallowed_entry_type`
17. `test_an_administrator_can_suspend_a_businesss_wallet_billing_status`
18. `test_suspending_billing_status_requires_a_mandatory_reason`
19. `test_an_administrator_can_resume_a_suspended_businesss_wallet_billing_status`
20. `test_resuming_billing_status_requires_a_mandatory_reason`
21. `test_an_administrator_can_retry_an_outstanding_funding_attempt`
22. `test_retrying_a_funding_attempt_requires_a_mandatory_reason`
23. `test_retrying_a_funding_attempt_for_an_unrelated_business_is_not_found`
24. `test_mutating_one_businesss_wallet_never_affects_an_unrelated_businesss_wallet` — cross-tenant isolation, direct database assertions on both Businesses.
25. `test_an_administrator_can_view_and_set_the_platform_feature_usage_safety_limit`
26. `test_setting_the_platform_safety_limit_requires_a_mandatory_reason`

Every mandatory-reason test follows the established pattern exactly: post with `reason` omitted, `assertSessionHasErrors('reason')`, then assert the affected row/state is unchanged from before the (failed) request — proving the FormRequest validation failure happens before any mutation.

### 5.2 New file: `tests/Feature/Usage/UsageWalletManagerManualCreditTest.php` — 7 methods

Manager-layer tests for `issueManualCredit()` (§2.3), mirroring `SlotAgreementAdminAuthorityTest.php`'s own established manager-layer pattern:

1. `test_issuing_a_manual_credit_increases_available_balance_and_records_the_ledger_entry`
2. `test_issuing_a_manual_credit_clears_existing_debt_first`
3. `test_issuing_a_promotional_credit_records_the_correct_entry_type`
4. `test_a_non_admin_actor_directly_invoking_issue_manual_credit_is_denied_even_bypassing_http_middleware`
5. `test_issuing_a_credit_with_a_disallowed_entry_type_is_rejected`
6. `test_issuing_a_credit_requires_a_mandatory_reason`
7. `test_issuing_a_credit_requires_a_positive_amount`

### 5.3 New file: `tests/Feature/Usage/AdminUsageBillingSurfaceBoundaryTest.php` — 5 methods

Static source-boundary tests, mirroring `EntitlementCatalogSourceBoundaryTest.php`'s own established grep-the-source-text technique (§0) — enforcing §3's exclusions mechanically, not merely by convention:

1. `test_the_admin_usage_billing_controller_never_calls_a_charge_originating_manager_method` — greps `UsageBillingController.php`'s own source text for `initiateTopUp|initiateAutoRecharge|initiateAddonPurchase|quoteAdditionalSlotAgreement`, asserts zero matches.
2. `test_the_admin_usage_billing_controller_never_calls_configure_auto_recharge` — greps for `configureAutoRecharge`, asserts zero matches.
3. `test_issue_manual_credit_never_calls_credit_from_funding` — greps `UsageWalletManager.php`'s own `issueManualCredit()` method body text for `creditFromFunding`, asserts zero matches.
4. `test_no_admin_usage_billing_production_file_contains_a_raw_billing_table_query` — greps every file on this contract's own production allow-list (§6) for `DB::table('business_usage_|DB::table('platform_feature_usage_safety_limits`, asserts zero matches outside the four repository Eloquent-implementation files (which are expected/authorized to contain them).
5. `test_the_admin_usage_billing_controller_never_references_payment_instrument_manager` — greps `UsageBillingController.php`'s own source text for `PaymentInstrumentManager`, asserts zero matches.

### 5.4 Required new imports, by file

- `AdminUsageBillingControllerTest.php`: `App\Enums\Usage\UsageLedgerEntryType`, `App\Enums\Usage\WalletBillingStatus`, `App\Enums\Usage\FundingAttemptState`, `App\Enums\Usage\FundingAttemptPurpose`, `App\Library\Usage\UsageBillingCheckoutManager`, `App\Library\Usage\UsageWalletManager`, `App\Models\Business`, `App\Repositories\Contracts\BusinessFundingAttemptRepository`, `App\Repositories\Contracts\BusinessUsageWalletRepository`, `App\Repositories\Contracts\PlatformFeatureUsageSafetyLimitRepository`, `Illuminate\Support\Facades\DB`, `Tests\Feature\Business\Concerns\CreatesBusinessTestData`, `Tests\TestCase`, plus the same `actingAsAdmin()`/`ensureRequiredAppConfigRowsExist()` private-helper pattern `AdminWorkspaceEntitlementControllerTest.php` already establishes (reused by structure, not by cross-file dependency — each Feature test file owns its own private copies of these helpers, matching this codebase's own existing convention of duplicating rather than sharing test fixtures across files).
- `UsageWalletManagerManualCreditTest.php`: `App\Enums\Usage\UsageLedgerEntryType`, `App\Library\Usage\UsageWalletManager`, `App\Repositories\Contracts\BusinessUsageLedgerEntryRepository`, `Tests\Feature\Business\Concerns\CreatesBusinessTestData`, `Tests\TestCase`.
- `AdminUsageBillingSurfaceBoundaryTest.php`: no new imports beyond `Tests\TestCase` — a pure static-file-read test, matching `EntitlementCatalogSourceBoundaryTest.php`'s own minimal import list.

---

## 6. Exact production allow-list

| # | Path | Status | Reason |
|---|---|---|---|
| 1 | `app/Http/Controllers/Admin/UsageBillingController.php` | REQUIRED (new file) | The one unified controller, 7 thin actions (§2.1). |
| 2 | `app/Http/Requests/Admin/IssueManualWalletCreditRequest.php` | REQUIRED (new file) | Validates `entry_type`, `amount_micro`, `reason` (§2.3). |
| 3 | `app/Http/Requests/Admin/SuspendBusinessWalletBillingRequest.php` | REQUIRED (new file) | Validates `reason` (§2.1.1). |
| 4 | `app/Http/Requests/Admin/ResumeBusinessWalletBillingRequest.php` | REQUIRED (new file) | Validates `reason` (§2.1.1). |
| 5 | `app/Http/Requests/Admin/RetryFundingAttemptAsAdministratorRequest.php` | REQUIRED (new file) | Validates `reason` (§2.1.1). |
| 6 | `app/Http/Requests/Admin/SetPlatformFeatureUsageSafetyLimitRequest.php` | REQUIRED (new file) | Validates `feature_key`, `max_monthly_limit_micro`, `reason` (§2.1.1). |
| 7 | `app/Library/Usage/UsageWalletManager.php` | REQUIRED (modified) | One new method, `issueManualCredit()` (§2.3). No existing method changed. |
| 8 | `app/Repositories/Contracts/BusinessUsageLedgerEntryRepository.php` | REQUIRED (modified) | Two new methods: `forBusinessPaginated()`, `marginAggregateRowsForBusiness()` (§2.4, §2.5). |
| 9 | `app/Repositories/Eloquent/EloquentBusinessUsageLedgerEntryRepository.php` | REQUIRED (modified) | Implements both. |
| 10 | `app/Repositories/Contracts/BusinessFundingAttemptRepository.php` | REQUIRED (modified) | One new method: `recentForBusiness()` (§2.4). |
| 11 | `app/Repositories/Eloquent/EloquentBusinessFundingAttemptRepository.php` | REQUIRED (modified) | Implements it. |
| 12 | `app/Repositories/Contracts/PlatformFeatureUsageSafetyLimitRepository.php` | REQUIRED (modified) | One new method: `all()` (§2.4). |
| 13 | `app/Repositories/Eloquent/EloquentPlatformFeatureUsageSafetyLimitRepository.php` | REQUIRED (modified) | Implements it. |
| 14 | `app/Repositories/Contracts/BusinessUsageWalletBillingStatusTransitionRepository.php` | REQUIRED (modified) | One new method: `forBusiness()` (§2.4). |
| 15 | `app/Repositories/Eloquent/EloquentBusinessUsageWalletBillingStatusTransitionRepository.php` | REQUIRED (modified) | Implements it. |
| 16 | `app/Repositories/Contracts/BusinessUsageLimitTransitionRepository.php` | REQUIRED (modified) | Two new methods: `forBusiness()`, `platformSafetyLimitHistory()` (§2.4). |
| 17 | `app/Repositories/Eloquent/EloquentBusinessUsageLimitTransitionRepository.php` | REQUIRED (modified) | Implements both. |
| 18 | `routes/admin.php` | REQUIRED (modified) | 7 new route declarations (§2.2). |
| 19 | `resources/views/admin/usage-billing/businesses/show.blade.php` | REQUIRED (new file) | Per-Business dashboard (§2.6). |
| 20 | `resources/views/admin/usage-billing/safety-limits/index.blade.php` | REQUIRED (new file) | Platform-wide safety-limit screen (§2.6). |
| 21 | `resources/views/admin/businesses/show.blade.php` | REQUIRED (modified, 1 line) | One link to the new dashboard (§2.6) — the sole justified integration point into an existing, unrelated view. |
| 22 | `app/Helpers/Helper.php` | REQUIRED (modified) | One new nav group, `menuData()`'s `'admin'` array (§2.7). |

**NOT_REQUIRED, explicitly confirmed:**

| Path | Reason |
|---|---|
| `app/Http/Controllers/Admin/PaymentProviderEventController.php` | Zero lines changed — not duplicated, only linked (§2.7). |
| `app/Http/Controllers/Admin/AdditionalBusinessSlotAgreementController.php` | Zero lines changed — not duplicated, only linked (§2.7). |
| `app/Http/Controllers/Admin/BusinessController.php` | Zero lines changed — Business discovery/detail is reused, not rebuilt (§2.6). |
| `app/Library/Usage/UsageBillingCheckoutManager.php` | Zero lines changed — `retryFundingAttemptAsAdministrator()` is called as-is (§1.3). |
| `app/Library/Usage/PaymentInstrumentManager.php` | Zero lines changed, never called (§3). |
| `app/Library/Usage/BillingProfileManager.php` | Zero lines changed — billing-contact/payer management is not among this contract's own locked capabilities (opening scope paragraph; not one of the nine guarantees §4 maps). |
| Any migration or schema file | No schema change — every column and table this contract writes to or reads from already exists, already shipped, unmodified (§1). |
| `config/permissions.php` | No new permission string is introduced this contract (§2.7, §12). |
| Any event, job, or notification class | No new domain event, job, or notification is introduced (§2.3, §9). |
| `app/Http/Controllers/Customer/**` or `routes/customer.php` | No customer-facing surface is touched. |

**Exactly 22 production paths: 8 new files (#1–6, #19–20) + 14 modified files (#7–18, #21–22).**

## 7. Exact test allow-list

| # | Path | Status | Reason |
|---|---|---|---|
| 1 | `tests/Feature/Usage/AdminUsageBillingControllerTest.php` | REQUIRED (new file) | 26 methods (§5.1), proving guarantees 1–8. |
| 2 | `tests/Feature/Usage/UsageWalletManagerManualCreditTest.php` | REQUIRED (new file) | 7 methods (§5.2), proving guarantee 2's own manager-layer authority. |
| 3 | `tests/Feature/Usage/AdminUsageBillingSurfaceBoundaryTest.php` | REQUIRED (new file) | 5 methods (§5.3), proving guarantee 9. |

**Exactly 3 test paths, 38 total new test methods.** No existing test file is modified — every capability this contract adds is net-new HTTP/manager/repository surface with no prior test coverage to correct (unlike the correction contracts in this sequence, this is new construction, not a fix to an existing, already-tested design).

---

## 8. Regression commands — streamlined, per this contract's own explicit verification policy

- `php artisan test tests/Feature/Usage/AdminUsageBillingControllerTest.php` — the new HTTP-level file; expected 26 methods, all passing.
- `php artisan test tests/Feature/Usage/UsageWalletManagerManualCreditTest.php` — the new manager-level file; expected 7 methods, all passing.
- `php artisan test tests/Feature/Usage/AdminUsageBillingSurfaceBoundaryTest.php` — the new boundary file; expected 5 methods, all passing.
- `php artisan test tests/Feature/Usage tests/Unit/Usage` — the complete Usage domain suite (covers every already-existing test this contract's changes could plausibly affect: `UsageWalletManager`'s own existing test files, since one new method is added to that class; every repository's own existing tests, since methods are added to seven of them).
- One complete `php artisan test --stop-on-failure` run (full suite) — catches any unexpected interaction with the Business/Workspace domains this contract's own view/route/nav changes touch.
- `git diff --check`.

Per this contract's own explicit instruction, no separate unrelated domain-suite run (Entitlement, Workspace, Business, Opportunity) is run on its own — the full-suite gate already covers them, and this contract touches nothing in those domains beyond the one linked line in `resources/views/admin/businesses/show.blade.php` and the one nav-array addition in `app/Helpers/Helper.php`, both trivial, additive, and non-behavioral for any existing Business/Workspace test.

---

## 9. Deferred findings — explicitly not absorbed into this contract

**Finding A (carried forward, unchanged, already classified non-blocking).** The Job/Event Dispatch Completion PR #141 audit's own low-balance-notification-after-successful-auto-recharge timing observation remains exactly what every prior contract in this lineage recorded it as: disclosed, contract-faithful, deferred for a separate, future human decision. This contract does not widen scope to include it.

**Finding B (new this contract, disclosed, non-blocking).** `UsageLedgerEntryType::ManualCredit`/`::PromotionalCredit` now have a real producer (§2.3), but no domain event is dispatched when either fires, and `RFC-005-JOB-EVENT-DISPATCH-COMPLETION-CORRECTION-CONTRACT.md:389`'s own naming of this gap is only half-closed by this contract (the producer exists; the dispatch does not). This is a deliberate scope boundary (§2.3, §3), not an oversight — a future, separately-authorized contract could add event dispatch for these two entry types without touching anything this contract builds. `UsageLedgerEntryType::UsageChargeReversal`/`::CorrectionReversal`, also named in that same excluded-scope sentence, remain entirely unproduced by any code path — this contract does not build a reversal/correction-issuance capability of any kind (§3, §10), since neither RFC §24 nor §30 names it as an admin capability this remediation must close.

---

## 10. Excluded scopes — restated

This contract does not implement, design, or absorb any of the following:

- Provider Refund/Dispute Outcome Handling (remediation #6), RFC-005 §35 Test-Coverage Completion (remediation #7) — both remain untouched, in their existing sequence position after this contract.
- The low-balance-notification timing observation (§9 Finding A) — carried forward, unresolved, non-blocking.
- Domain-event dispatch for `ManualCredit`/`PromotionalCredit` (§9 Finding B) — the producer is built; the dispatch is not.
- `UsageChargeReversal`/`CorrectionReversal` ledger-entry production of any kind — no reversal/correction-issuance capability exists in this contract's scope.
- Any change to `creditFromFunding()`, `configureAutoRecharge()`, `initiateTopUp()`, `initiateAutoRecharge()`, `initiateAddonPurchase()`, `quoteAdditionalSlotAgreement()`, or any other charge-originating or auto-recharge-configuring method — confirmed unaffected and unmodified (§1.3, §3, §6).
- Any change to `PaymentInstrumentManager`, `BillingProfileManager`, `ProcessPaymentProviderEvent`, `ReconcileProviderPendingState`, or any webhook/provider-verification code path.
- Any change to `PaymentProviderEventController` or `AdditionalBusinessSlotAgreementController` themselves, or their own routes/views/FormRequests — integration is link-only (§2.7).
- A dedicated `config/permissions.php` entry for this domain — deferred as a genuine open item (§12), not decided here.
- M6 conformance/deployment docs; the release tag; Conversations pilot activation; tax/VAT implementation; legacy invoices.
- Any migration or schema change.

Do not reopen Reservation Admission, Funding Provider-Flow, Receipt Boundary, Job/Event Dispatch Completion, Reconciliation-Race, or the Funding Confirmation Concurrency Correction (including its exceptional post-merge implementation correction and both of its own focused review fixes) — none is touched, contradicted, or reinterpreted by anything above.

---

## 11. Confirmations

- **No schema/migration change is required or authorized by this contract.** Every column this contract reads from or writes to (`business_usage_ledger_entries.actor_user_id`/`.reason`/`.correlation_key`, `business_usage_wallet_billing_status_transitions.*`, `business_usage_limit_transitions.*`, `platform_feature_usage_safety_limits.*`) already exists, already shipped, unmodified — confirmed by direct migration read this pass (§0, §1).
- `AI-AUTONOMY-STATE.json` is untouched by this branch.
- M6 remains frozen; still genuinely open/blocked, not merely paused (§0).
- No product, test, config, route, or RFC-source file is touched by this branch. This governance branch changes exactly one file.
- No implementation has occurred. **Correction rounds: 0 of 2 consumed; 2 remain.**
- Reservation Admission, Funding Provider-Flow, Receipt Boundary, Job/Event Dispatch Completion, Reconciliation-Race, and the Funding Confirmation Concurrency Correction (and its own exceptional post-merge implementation correction and focused review fixes) are not reopened, contradicted, or reinterpreted anywhere above.

---

## 12. Open items — genuine human decisions this contract does not resolve

1. **Dedicated permission string vs. continued reliance on `access backend` + `EnsureUserIsAdministrator`.** Every existing Usage-domain admin controller (M3, M4) deliberately has no dedicated permission string, and both of their own docblocks state a new `config/permissions.php` entry is "not authorized." This contract's own design (§2.7, §2.9) continues that precedent for consistency, but it is a genuine, undecided permissions-model choice — not something derivable from RFC-005's own text, which never specifies a permission-string granularity for admin actions. A human could authorize introducing `view usage billing`/`manage usage billing` permission strings (mirroring the RFC-004 Workspace convention instead) in a future, separate governance step without contradicting anything this contract locks.
2. **Whether `ManualCredit`/`PromotionalCredit` should eventually dispatch a domain event** (§9 Finding B) — this contract deliberately does not decide this, leaving it as a smaller, separately-authorizable follow-on.
3. **The exact grouping granularity of the provider-cost/margin aggregate** (§2.5) — locked to a reasonable default (per-month, per-feature) derived from this domain's own existing `period_key` convention, but not RFC-mandated; a human reviewer could reasonably prefer a different granularity (e.g., per-meter instead of per-feature, given Amendment 1's own meter-identity decoupling, §0) without this being a defect in this contract's own design.
4. **Whether a double form submission of `issueManualCredit()` should be protected by an idempotency mechanism** (§2.3) — this contract deliberately relies on ordinary UI affordances only, disclosed explicitly, not a defect; a human could authorize a stronger mechanism (e.g., a client-supplied idempotency token) in a future correction without contradicting this contract's own locked design.

No other genuinely open human decision was identified during this audit. Every other design choice in §2 was derivable mechanically from RFC-005 §16/§18/§21/§24/§28/§30/§34/§35/§36–§39, the six already-merged remediation contracts' own repeated exclusion language, and the current, directly-read code, schema, and test conventions.

---
