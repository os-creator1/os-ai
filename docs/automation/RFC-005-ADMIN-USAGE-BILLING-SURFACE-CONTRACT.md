# RFC-005 Admin Usage Billing Surface Contract

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

This document authorizes drafting one thing: a bounded, independently governed contract that builds the last remaining platform-administrator capabilities RFC-005 §24/§30 require and that no milestone (M1–M5) or correction contract has yet built — named "Admin Usage Billing Surface," remediation #5 of the seven pre-M6 remediations RFC-005 Milestone 6's own static conformance audit discovered. It is the mandatory pre-M6 correction sitting immediately after the RFC-005 Funding Confirmation Concurrency Correction Contract (merged PR [#144](https://github.com/os-creator1/os-ai/pull/144), its own exceptional post-merge implementation correction merged PR [#145](https://github.com/os-creator1/os-ai/pull/145), and its own implementation merged PR [#146](https://github.com/os-creator1/os-ai/pull/146)) and before remediation #6 (Provider Refund/Dispute Outcome Handling) and remediation #7 (RFC-005 §35 Test-Coverage Completion) in the master remediation sequence — confirmed, by name, in every one of those five prior remediation contracts' own exclusion sections (`RFC-005-RESERVATION-ADMISSION-CORRECTION-CONTRACT.md:254`, `RFC-005-FUNDING-PROVIDER-FLOW-CORRECTION-CONTRACT.md:596`, `RFC-005-RECEIPT-BOUNDARY-CORRECTION-CONTRACT.md:416`, `RFC-005-JOB-EVENT-DISPATCH-COMPLETION-CORRECTION-CONTRACT.md:389`, `RFC-005-RECONCILIATION-RACE-CORRECTION-CONTRACT.md:267`).

**This is contract-authoring only.** No product code, test code, schema, route, config, or RFC-source file is touched by this branch. It creates exactly one file: `docs/automation/RFC-005-ADMIN-USAGE-BILLING-SURFACE-CONTRACT.md`.

---

## Exceptional post-review correction

**This is not Correction Round 3.** This contract's own ordinary correction-round budget is unchanged by this pass: `maximum_correction_rounds: 2`, `2 of 2 consumed, 0 remain` (§0, §11) — exactly as Correction Round 2 left it. This pass uses the same, separate exceptional-correction mechanism already established and used elsewhere in this engagement (the Reconciliation-Race Correction's own exceptional post-review correction; the Funding Confirmation Concurrency Correction's own exceptional post-merge implementation correction): it exists precisely for a narrowly-scoped, independently-confirmed defect surfaced *after* the ordinary correction-round budget is exhausted, and it does not consume, reset, or otherwise touch the ordinary counter.

**The confirmed defect.** Correction Round 2's own fix to §2.5's margin aggregate (head `874ad202d91a4b69084b1b35eb6026f9b7476db0`) correctly widened the SQL-side cast to `DECIMAL(20,0)`, closing the overflow risk *inside MySQL* — but then cast both aggregate results to PHP `int` before computing `margin_micro` by native subtraction:

```php
$row->retail_revenue_micro = (int) $row->retail_revenue_micro;
$row->provider_cost_micro = (int) $row->provider_cost_micro;
$row->margin_micro = $row->retail_revenue_micro - $row->provider_cost_micro;
```

PHP's native `int` is signed and 64-bit — its maximum value, `9223372036854775807`, is roughly half of `provider_cost_micro`'s own schema-valid maximum as an `unsignedBigInteger`, `18446744073709551615`. A single schema-valid `provider_cost_micro` value already overflows a PHP `int` on cast; a `SUM()` across multiple rows (the entire point of an *aggregate*) can legitimately exceed even the unsigned-64-bit range Correction Round 2's own SQL-side fix was written to accommodate. **Correction Round 2's own claimed "no overflow for schema-legal 20-digit values" (§4 guarantee 5, as it read after that round) was true only at the SQL layer — the PHP-side `(int)` cast silently reintroduced the identical class of defect one layer higher.**

**Corrected: §2.5 no longer casts either aggregate through PHP `int` (or `float`) anywhere.** Both `retail_revenue_micro` and `provider_cost_micro` are preserved as the exact integer-micro strings MySQL's own `DECIMAL` result type and PDO's own string-returning behavior for `DECIMAL` columns already provide — confirmed by direct reasoning from MySQL's own documented `SUM()`/`ROUND()` result-type rules (an exact-value argument produces an exact-value, i.e. `DECIMAL`, result) and PDO_MySQL's own well-established behavior of returning `DECIMAL` column values as PHP strings, never as a native numeric type, specifically because no native PHP numeric type can losslessly represent the full range such a result can hold. `margin_micro` is computed via exact `bcmath` string subtraction (`bcsub()`), never native `-` on cast values. `bcmath` is already an RFC-005 runtime prerequisite (`UsageWalletManager::bcRoundHalfUp()` and every existing wallet-arithmetic code path already depend on it) — this correction adds no new dependency and no new production path, only a new call site inside an already-authorized repository method.

**The controller-side formatter is corrected to match** — it accepts signed/unsigned integer-micro strings and must never convert any of them through `float` or PHP `int`, the identical failure mode one layer higher (e.g. a naive `number_format()` call on a value this large silently loses precision the same way the repository's own prior `(int)` cast did).

**The large-value aggregate test is renamed and substantially strengthened**, per this correction's own exact requirements: it now uses the literal `unsignedBigInteger` maximum (`18446744073709551615`), supplied as a database-safe decimal string; asserts it round-trips as the exact same string; seeds **two** such rows so the aggregate `SUM()` itself exceeds a single `unsignedBigInteger`'s own maximum, not merely one stored value; asserts `retail_revenue_micro`, `provider_cost_micro`, and the signed `margin_micro` are all returned as exact strings (`is_string(...)`, never `is_int(...)`); and every assertion against these values is now `bccomp()`/`bcsub()`-based, never a native `===`/`-` comparison against a PHP-integer literal. Every other margin-aggregate test's own assertions are corrected the same way, for the same reason — the return type is exact strings for every row this method ever returns, not only the ones large enough to matter today.

**Every stale claim elsewhere in this document that the aggregate's own outputs are "PHP integers," "already-computed integers," or similar has been corrected to "exact integer-micro strings."** No production or test path count changes as a result of this correction — the only production file touched (`EloquentBusinessUsageLedgerEntryRepository.php`) was already REQUIRED (modified) on the allow-list for this exact method; the only test file touched (`AdminUsageBillingControllerTest.php`) keeps its own 32-method count, since this correction strengthens and renames existing test descriptions rather than adding new ones.

No genuinely new blocking product/schema question was found this round. §2.1 through §2.4, §2.6 through §2.11, and every other section not naming §2.5's own aggregate output type are unchanged and re-confirmed unaffected.

---

## Correction Round 2 record — FINAL ORDINARY ROUND

A focused review of Correction Round 1's own output (head `37765b1c2488cd3126b8480718ffcf25340e5a47`) found four further defects in §2.3/§2.5's own idempotency and margin-aggregate design, three of them blocking, one an assertion-completeness/wording gap. **2 of 2 ordinary correction rounds consumed by this round; 0 ordinary rounds remain.**

Exact issues resolved this round:

1. **The idempotent-replay same-payload comparison in §2.3 compared an enum instance to a raw string and was therefore always false.** `BusinessUsageLedgerEntry::$casts` casts `entry_type` to `UsageLedgerEntryType` (confirmed by direct read, `app/Models/BusinessUsageLedgerEntry.php:52`) — so `$existing->entry_type === $entryType->value` compares a `UsageLedgerEntryType` enum instance against a plain string and can never be `true`, meaning `issueManualCredit()`'s own conflict branch would fire on every replay, including a genuinely identical one. Every other field in the same comparison was re-audited against the model's own casts: `gross_amount_micro` casts `integer` and is compared via an explicit `(int)` cast on both sides; `actor_user_id` and `business_id` are uncast but already compared via explicit `(int)` casts on both sides; `reason` is uncast, compared as a plain string. **Only `entry_type` was affected. Corrected: §2.3's comparison is now `$existing->entry_type === $entryType` (enum-to-enum)** — `UsageLedgerEntryType` is a backed enum, and PHP's own enum-case singleton guarantee makes `===` the correct, exact comparison. The existing identical-replay test (§5.2 item 8) is retained unchanged in intent but its own description now explicitly notes it is the test that catches this exact cast-sensitive defect.
2. **The manager trusted an arbitrary caller-supplied `operationId` string with no validation of its own, relying solely on the FormRequest's `uuid` rule.** Every other `issueManualCredit()` parameter (`entryType`, `amountMicro`, `reason`) is already independently re-validated inside the manager, matching this codebase's own established defense-in-depth discipline — `operationId` was the one exception. **Corrected: §2.3 now trims and lowercases `operationId`, validates it via `Str::isUuid()`, and throws a new `InvalidAdminCreditOperationIdException` on failure, all before the correlation key is ever constructed** — normalizing to lowercase first also closes a real duplicate-credit path (an uppercase- and lowercase-rendered form of the identical UUID would otherwise produce two different correlation keys and two real credits). The resulting key's maximum length (`'admin_credit:'` [13] + a `business_id` up to the unsigned-bigint maximum of 20 digits + `':'` [1] + a 36-character UUID = 70 characters) is stated exactly and confirmed to fit `business_usage_ledger_entries.correlation_key`'s own `VARCHAR(191)` column with substantial headroom. The new exception is added to the production allow-list by exact path — no "TBD" naming.
3. **The margin aggregate's own `DECIMAL(20,6)` cast of `provider_cost_micro` left only 14 integer digits for a column that is schema-valid up to 20 integer digits (an `unsignedBigInteger`), risking overflow for large, schema-legal values; and its own independently-rounded `margin_micro` SQL expression could diverge by one micro from `retail_revenue_micro − provider_cost_micro` at a half-micro rounding boundary.** **Corrected: §2.5 now casts `provider_cost_micro` to `DECIMAL(20,0)`** (all 20 digits available, matching the column's own genuinely integer, no-fractional-part nature exactly) **before multiplying by `quantity`, and computes `margin_micro` as a plain integer subtraction of the two already-rounded aggregate values, performed once per feature-key row after the SQL query returns, not as a third, independently-computed SQL expression** — this guarantees `margin_micro === retail_revenue_micro − provider_cost_micro` by construction, for every row, always. `retail_revenue_micro` no longer needs its own `ROUND()` wrapper at all: `gross_amount_micro` is already an integer column, so `SUM(gross_amount_micro)` is already exact.
4. **Assertion completeness and one imprecise sentence.** §2.3's own manual-credit event tests proved `BusinessWalletCredited` and `BusinessWalletDebtCleared` fire individually, under separately-fixtured conditions, but nothing proved they fire *together*, exactly once each, carrying the *same* ledger-entry id, for the single realistic case that matters most — one credit larger than the Business's own outstanding debt. **Corrected: §5.2 adds one new combined-dispatch test.** Separately, §2.3's own prose claimed `lowBalanceMarkerUpdate()` "signals a marker reset" — its own body (confirmed by direct re-read, `UsageWalletManager.php:1545-1568`) can set a fresh marker, leave an existing marker untouched, or clear an existing marker, not merely "reset" one; §2.3's own wording is corrected to describe all three outcomes accurately.

No genuinely new blocking product/schema question was found this round beyond what is resolved above. Correction Round 1's own six corrections are unchanged and re-confirmed unaffected by anything in this round, except where items 1–4 above directly touch §2.3/§2.5's own text.

---

## Correction Round 1 record

A focused review of the initial draft (head `5d0fc3db78aaef2ca34a8c050e5194542aa4938b`) found six defects, four of them blocking, one an efficiency blocker, and one an internal-consistency cleanup. **1 of 2 ordinary correction rounds consumed by this round; 1 ordinary round remains.**

Exact issues resolved this round:

1. **§1.3's own claim that `UsageBillingCheckoutManager::retryFundingAttemptAsAdministrator()` already calls `assertPlatformAdministrator()` and independently validates its own `reason` argument was false.** Confirmed by direct re-read of `app/Library/Usage/UsageBillingCheckoutManager.php:584-619` at this exact base: the method's own `$reason` parameter is accepted but never read anywhere in its body — no admin check, no blank-reason rejection, no persistence of the reason anywhere. **Corrected: §2.1.2 (new) locks changes to `UsageBillingCheckoutManager.php` itself** — the method now calls `assertPlatformAdministrator($actorUserId)` and rejects a blank trimmed reason, both before any provider-gateway call; the normalized reason is threaded through `confirmSucceeded()`/`finalizeFundingAttemptState()`/`recordTransition()` (each widened with one new, trailing, backward-compatible `?string $reason = null` parameter) and persisted on the resulting `business_funding_attempt_transitions` row. A new, nullable `reason` column and its migration are mechanically derived and locked (§2.1.2), along with the corresponding `BusinessFundingAttemptTransition` model change. `UsageBillingCheckoutManager.php` moves from NOT_REQUIRED to REQUIRED on the production allow-list as a direct consequence.
2. **§2.9's own claim that "every write action's own manager method re-validates that the resolved model genuinely belongs to the id the URL named" was false for `retryFundingAttempt` specifically.** `retryFundingAttemptAsAdministrator()` receives only a `BusinessFundingAttempt`, never a `Business` — it has no way to know which `{business}` appeared in the URL, so it cannot perform this check itself. **Corrected: §2.1.2 locks an explicit controller-level guard** — `(int) $attempt->business_id !== (int) $business->id` → `abort(404)`, executed before the manager or the payment-provider gateway is ever reached — and §2.9 is corrected to describe this accurately instead of claiming a manager-level check that cannot exist for this one action.
3. **The manual-credit correlation-key design was a genuine financial-idempotency defect, not a disclosed trade-off.** A fresh, random correlation key per call, combined with the claim that "duplicate form submissions... produce two independent, genuinely-intended-as-separate ledger entries," meant a double form submission would double-credit a wallet — a disabled submit button is UI polish, not an idempotency boundary. **Corrected: §2.3 is redesigned around a client-supplied, form-preserved `operation_id` UUID**, a deterministic correlation key derived from it (Business ID + operation ID), a new ledger lookup by correlation key, and an explicit, locked conflict rule: an identical replay (same normalized Business/type/amount/actor/reason) returns the original result unchanged; a reused `operation_id` with a different payload throws a new, explicitly allow-listed exception and mutates nothing. The pre-existing `UNIQUE` constraint on `business_usage_ledger_entries.correlation_key` remains the database-level backstop underneath this application-level check.
4. **The Job/Event Dispatch Completion contract's own exclusion was read too narrowly — the ledger-entry producer this contract builds must dispatch the two wallet-balance domain events that already exist and already govern every other credit-type ledger entry, not stay silent.** `creditFromFunding()` already dispatches `BusinessWalletCredited`/`BusinessWalletDebtCleared` (existing, `ShouldDispatchAfterCommit`, unmodified) whenever a credit increases available balance or clears debt, respectively. **Corrected: §2.3 locks the identical dispatch behavior for `issueManualCredit()`** — both may fire for one credit, both carry the created ledger-entry id, and the method mirrors `creditFromFunding()`'s own low-balance-marker reset/re-evaluation semantics exactly, while still never dispatching `SendReceiptNotification` (no provider evidence exists for an admin-issued credit — unchanged). No new event class is introduced.
5. **The proposed transition-history repository methods were unbounded, and the margin aggregate loaded every matching ledger row into PHP for aggregation there — both contradicted this contract's own bounded-read claims and were needlessly expensive.** **Corrected: §2.4's transition-history methods are now explicitly limited** (`recentForBusiness(int $businessId, int $limit = 20)`, `recentPlatformSafetyLimitHistory(int $limit = 50)`), and **§2.5's margin computation now happens entirely inside the Eloquent repository, in SQL**, via a single `GROUP BY feature_key` aggregate query using exact `DECIMAL` arithmetic, returning aggregate rows (`retail_revenue_micro`, `provider_cost_micro`, `margin_micro` per feature) instead of raw ledger rows. The rounding rule (SQL `ROUND()` to the nearest whole micro) and the already-locked per-month/per-feature grouping are both stated exactly, and the controller-side responsibility is narrowed to formatting only, via a locked controller-private method — no value-object path is added.
6. **Internal-consistency cleanup, six items:** §2.1.1's own "one manager/repository call each" claim was corrected to accurately describe `show()` as a multi-repository read (never a write, still zero raw queries); §0's own required-reading bullet and §5.3's own boundary-test description were both corrected from "four" to the actually-correct five Eloquent repository implementation files (unchanged count, only the prose was wrong, in two places); every "TBD at implementation"/"left to implementation discretion" exception-naming and value-object-path phrase is removed and replaced with an exact, locked name or path; §12's four previously-open items are resolved and locked by this round's own corrections 3–5 above and are removed from §12 rather than carried forward; and every production-path count, test-path count, method count, import list, guarantee-mapping cross-reference, and regression-command list is recalculated below to match the redesign.

No genuinely new blocking product/schema question was found this round beyond what is resolved above. §1.1, §1.2, and §1.4 are unchanged and re-confirmed unaffected; only §1.3 required correction (item 1).

---

## 0. Governance

- Drafted on branch `chore/rfc-005-admin-usage-billing-surface-contract`, in an isolated linked worktree (`../rfc-005-admin-usage-billing-surface-contract-worktree`), based on `origin/main` at `376fda52ecf449bbb622d2dd0ec40f4411587cc5` — PR [#146](https://github.com/os-creator1/os-ai/pull/146)'s own merge commit (the RFC-005 Funding Confirmation Concurrency Correction's implementation) — confirmed via `git fetch origin && git rev-parse origin/main` at the start of this contract, unchanged this round.
- **Future implementation branch (authorized only after this contract's human merge, and only after a further, separate, explicit human instruction to begin implementation): `agent/rfc-005-admin-usage-billing-surface`.**
- Confirmed at drafting and re-confirmed this round: `git status --short` in this worktree is empty at every point except this one file; no product/test/config/route/RFC-source file has been touched by this branch at any point.
- Locked:
  - `human_only_merge: true`
  - `maximum_correction_rounds: 2`
  - **Correction rounds: 2 of 2 consumed; 0 ordinary rounds remain. This is the final ordinary correction round available to this contract.**
  - `advance_automatically: false`
  - `start_automatically_after_contract_merge: false`
  - `M6 remains frozen` — untouched, not resumed, no M6 document created or modified. Confirmed by direct read: `docs/automation/RFC-005-M6-CONTRACT.md` (366 lines) contains zero mentions of any of the seven remediations, including this one — it was merged (PR #133) before M6's own release-readiness attempt discovered the remediation-sequence gap, and has never been amended to reflect it. Its own two required deliverables, `docs/automation/RFC-005-M6-CONFORMANCE.md` and `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS-DEPLOYMENT.md`, do not exist anywhere in this repository (confirmed via direct `ls`, both `No such file or directory`) — M6 remains genuinely open/blocked, not merely paused.
  - `AI-AUTONOMY-STATE.json` is untouched by this branch. (Confirmed stale relative to the actual remediation sequence — its own `"status": "m5_closed_pending_next_locked_contract"` / `"active_milestone": "Milestone 5"` fields have never been updated since M5 closed; this is consistent with every prior remediation contract's own confirmation that this file is untouched, not a new finding.)
  - No tag is created or moved. No live Stripe/rate/meter/pilot activation occurs.
  - **Implementation requires a separate, explicit human instruction issued after this contract itself is human-merged.**
- This is a new, independently bounded pre-M6 remediation contract — not a correction round against M1–M5's own contracts, and not a correction round against any of the six already-merged corrections in this sequence (Reservation Admission, Funding Provider-Flow, Receipt Boundary, Job/Event Dispatch Completion, Reconciliation-Race, Funding Confirmation Concurrency). Its own `maximum_correction_rounds: 2` budget is its own; no counter is borrowed or altered on any other contract.
- This governance branch changes exactly one tracked file: `docs/automation/RFC-005-ADMIN-USAGE-BILLING-SURFACE-CONTRACT.md`.
- **Required reading completed before drafting, independently audited fresh in this pass; re-verified directly again in both correction rounds and this exceptional correction wherever their own items required it (`UsageBillingCheckoutManager.php:577-619,655-729,916-927,2206-2213`, `database/migrations/2026_08_16_140005_create_business_funding_attempt_transitions_table.php`, `app/Models/BusinessFundingAttemptTransition.php`, `app/Events/Usage/BusinessWalletCredited.php`, `.../BusinessWalletDebtCleared.php`, `UsageWalletManager.php:879-963,1501-1508,1545-1568`, `app/Models/BusinessUsageLedgerEntry.php:52-63` (confirming `entry_type`'s own enum cast), the `business_usage_ledger_entries.correlation_key` column's own `VARCHAR(191)` definition, and MySQL's own `DECIMAL` multiplication/precision rules for `CAST(... AS DECIMAL(20,0))`; and — this exceptional correction — PHP's own signed 64-bit `int` range (`PHP_INT_MAX = 9223372036854775807`) against `unsignedBigInteger`'s own schema-valid range (`0` to `18446744073709551615`), PDO_MySQL's own documented behavior of returning `DECIMAL` column results as PHP strings, and `UsageWalletManager::bcRoundHalfUp()`'s own existing reliance on the `bcmath` extension as confirmation it is already an RFC-005 runtime prerequisite):**
  - `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md` — §15 (lines 476–547, platform safety limit / limit-transition schema), §16 (549–601, the narrowed platform-administrator charge-origination rule), §18 (734–793, manual/promotional credit and add-on schema), §21 (845–908, webhook exhaustion/disposition design), §24 (1052–1082, the authorization/tenant-isolation capability table), §28 (1142–1146, manager/domain authority), §30 (1160–1170, the HTTP/admin surface blueprint), §34 (1210–1214), §35 (1216–1250), §36–§39 (1253–1300).
  - Every merged RFC-005 correction/remediation contract naming "Admin Usage Billing Surface" as remaining, unbuilt scope: `RFC-005-RESERVATION-ADMISSION-CORRECTION-CONTRACT.md`, `RFC-005-FUNDING-PROVIDER-FLOW-CORRECTION-CONTRACT.md`, `RFC-005-RECEIPT-BOUNDARY-CORRECTION-CONTRACT.md`, `RFC-005-JOB-EVENT-DISPATCH-COMPLETION-CORRECTION-CONTRACT.md`, `RFC-005-RECONCILIATION-RACE-CORRECTION-CONTRACT.md`, and the already-known-in-full `RFC-005-FUNDING-CONFIRMATION-CONCURRENCY-CORRECTION-CONTRACT.md` (including its exceptional post-merge implementation correction and both of its own focused review fixes).
  - `RFC-005-M2-CONTRACT.md` and `RFC-005-M3-CONTRACT.md` and `RFC-005-M4-CONTRACT.md` — the three milestones that built (M2) or exposed the manager-layer methods this contract wires an HTTP surface onto, or (M3/M4) built the two narrow admin sub-surfaces this contract explicitly does not duplicate.
  - `routes/admin.php` (full file, 767 lines); `app/Http/Controllers/Admin/PaymentProviderEventController.php`, `app/Http/Controllers/Admin/AdditionalBusinessSlotAgreementController.php`, `app/Http/Controllers/Admin/BusinessController.php`, `app/Http/Controllers/Admin/WorkspaceEntitlementController.php`, `app/Http/Controllers/Admin/WorkspacePlanCatalogController.php` (full files); their FormRequests, views, and every existing Feature test governing an Admin controller in this codebase (`tests/Feature/Workspace/AdminWorkspaceEntitlementControllerTest.php`, `tests/Feature/Workspace/AdminWorkspacePlanCatalogControllerTest.php`, `tests/Feature/Business/AdminBusinessControllerTest.php`, `tests/Feature/Usage/SlotAgreementAdminAuthorityTest.php`, `tests/Feature/Usage/EntitlementCatalogSourceBoundaryTest.php`).
  - `app/Http/Middleware/EnsureUserIsAdministrator.php` (full file); `app/Helpers/Helper.php`'s `menuData()` (lines 550–902); `app/Providers/MenuServiceProvider.php`; `app/Providers/RouteServiceProvider.php` (the `mapWebRoutes()` group wrapping `routes/admin.php`).
  - `app/Library/Usage/UsageWalletManager.php`, `app/Library/Usage/BillingProfileManager.php`, `app/Library/Usage/PaymentInstrumentManager.php`, `app/Library/Usage/UsageBillingCheckoutManager.php` (full files, all public and relevant private methods).
  - Every repository contract under `app/Repositories/Contracts/` whose name contains Usage/Wallet/Funding/Ledger/Limit/BillingReceipt/AddonPurchase (19 files); the **five** repositories this contract widens, in full, with their Eloquent implementations (corrected this round from an earlier miscount of "four," §0's own Correction Round 1 record item 6); the underlying migrations for `business_usage_wallets`, `business_usage_ledger_entries`, `business_usage_wallet_billing_status_transitions`, `business_usage_limit_transitions`, `platform_feature_usage_safety_limits`, `usage_meters`, `business_funding_attempt_transitions` — each confirmed by direct read this pass, not merely cited from a prior contract.
  - `app/Enums/Usage/*.php` (every enum in the Usage namespace); `app/Exceptions/Usage/*.php` (every existing exception in the Usage namespace, to confirm reuse opportunities before naming new ones — confirmed `UnauthorizedSlotAgreementActionException`, `UnauthorizedUsageBillingManagementException`, `FundingAttemptNotResumableException`, `UsageWalletNotFoundException` all already exist and are reused, §2.1.2, §2.3); `app/Policies/UserPolicy.php` (confirmed the only Policy class in the repository — no Usage/Wallet/Business Policy exists).
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

### 1.3 What already exists at the manager layer — two methods fully built; one requires this contract's own correction

**Corrected this round (§0's own Correction Round 1 record, item 1).** Two manager methods are fully implemented, already platform-administrator-gated, already RFC-compliant, and confirmed by direct read to have no admin-controller caller anywhere in the repository today. A third — `retryFundingAttemptAsAdministrator()` — was previously, incorrectly, described the same way; it is not:

- **`UsageWalletManager::setBillingStatus(Business $business, WalletBillingStatus $status, BillingStatusTransitionSource $source, ?int $actorUserId, string $reason): void`** (`app/Library/Usage/UsageWalletManager.php:1284`). Gated via `assertPlatformAdministrator()` when `$source === BillingStatusTransitionSource::AdminAction` (requiring a non-null `actorUserId`). Records a `BusinessUsageWalletBillingStatusTransition` row (`wallet_id`, `business_id`, `from_status`, `to_status`, `source`, `actor_user_id` nullable, `reason` — **NOT NULL**, `created_at`; confirmed directly from `database/migrations/2026_08_16_130004_create_business_usage_wallet_billing_status_transitions_table.php:18-26`), updates `wallets.billing_status`, dispatches `BusinessWalletBillingStatusChanged`. Its own docblock (`UsageWalletManager.php:1276-1283`) states it "ships as a fully functional, tested capability with zero calling production code path at M2 — no admin HTTP route exists yet."
- **`UsageWalletManager::setSafetyLimit(string $featureKey, string $maxMonthlyLimitMicro, int $actorUserId, string $reason): void`** (`app/Library/Usage/UsageWalletManager.php:1240`). Platform-administrator-only (`assertPlatformAdministrator()` at line 1242). Upserts the `platform_feature_usage_safety_limits` row keyed by `feature_key` (`unique`, `varchar(64)`, `NOT NULL`; confirmed from `database/migrations/2026_08_16_130002_create_platform_feature_usage_safety_limits_table.php:18`), records a `BusinessUsageLimitTransition` with `business_id: null`, `limit_type: platform_safety_limit`, `feature_key` populated, `actor_user_id`/`reason` both **NOT NULL** (confirmed from `database/migrations/..._create_business_usage_limit_transitions_table.php:18-26`).
- **`UsageBillingCheckoutManager::retryFundingAttemptAsAdministrator(BusinessFundingAttempt $attempt, int $actorUserId, string $reason): FundingAttemptResult`** (`app/Library/Usage/UsageBillingCheckoutManager.php:584-619`). Confirmed by direct read at this exact base: throws `FundingAttemptNotResumableException` unless state is `ProviderPending`/`RequiresAction`/`Failed` and a provider reference exists; re-verifies with the provider (never trusts local state); on success calls the shared `confirmSucceeded()` finalizer with `TransitionSource::AdminAction`. **Its own `$reason` and `$actorUserId` parameters, however, are confirmed unused for authorization or audit purposes as the method stands today: `assertPlatformAdministrator()` is never called anywhere in this method's own body, and `$reason` is accepted but never read.** This contract's own §2.1.2 corrects both, mechanically deriving the exact production changes required. Confirmed by repo-wide grep: its only callers today are `tests/Feature/Usage/FundingAttemptPayerConsentTest.php` and this method's own docs — **no controller under `app/Http/Controllers/Admin` references it.**

The first two require no change. **The third requires the correction locked in §2.1.2 before this contract's own HTTP entry point can safely rely on it** — this contract's design (§2) wires an HTTP entry point to all three, but only after §2.1.2's own production change is applied to the third.

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

**This controller does not touch, wrap, or extend `PaymentProviderEventController` or `AdditionalBusinessSlotAgreementController`.** Those remain exactly as shipped; this contract's only interaction with them is a read-only navigation cross-reference (§2.7).

#### 2.1.1 Seven actions — one manager call per write action; `show` reads via multiple repositories

**Corrected this round (§0's own Correction Round 1 record, item 6):** the prior heading claimed "one manager/repository call each" for every action — false for `show`, which is read-only and reads via six repository methods, never a write query.

| Action | Verb + URI | What it does | Calls |
|---|---|---|---|
| `show` | `GET businesses/{business}/usage-billing` | Renders the wallet/ledger/limits/funding-attempts dashboard for one Business — reads via multiple repositories, never a write. | `BusinessUsageWalletRepository::findByBusinessId()`, `BusinessFeatureUsageLimitRepository::forBusiness()`, `BusinessUsageLedgerEntryRepository::forBusinessPaginated()` (new), `BusinessUsageLedgerEntryRepository::marginAggregateForBusiness()` (new, §2.5), `BusinessFundingAttemptRepository::recentForBusiness()` (new), `BusinessUsageWalletBillingStatusTransitionRepository::recentForBusiness()` (new), `BusinessUsageLimitTransitionRepository::recentForBusiness()` (new) |
| `issueManualCredit` | `POST businesses/{business}/usage-billing/credit` | Issues an auditable, idempotent manual or promotional credit. | `UsageWalletManager::issueManualCredit()` (new, §2.3) |
| `suspendBilling` | `POST businesses/{business}/usage-billing/suspend` | Sets `billing_status = Suspended`. | `UsageWalletManager::setBillingStatus()` (existing, unmodified) |
| `resumeBilling` | `POST businesses/{business}/usage-billing/resume` | Sets `billing_status = Active`. | `UsageWalletManager::setBillingStatus()` (existing, unmodified) |
| `retryFundingAttempt` | `POST businesses/{business}/usage-billing/funding-attempts/{attempt}/retry` | Resumes an already-created, payer-authorized attempt, after an explicit controller-level cross-business ownership guard (§2.1.2, new this round). | `UsageBillingCheckoutManager::retryFundingAttemptAsAdministrator()` (corrected this round, §2.1.2) |
| `safetyLimits` | `GET usage-billing/safety-limits` | Platform-wide (not Business-scoped): lists every configured platform feature-usage safety limit. | `PlatformFeatureUsageSafetyLimitRepository::all()` (new), `BusinessUsageLimitTransitionRepository::recentPlatformSafetyLimitHistory()` (new) |
| `setSafetyLimit` | `POST usage-billing/safety-limits` | Sets or updates one platform-wide safety limit. | `UsageWalletManager::setSafetyLimit()` (existing, unmodified) |

Every write action follows the identical shape already established by `AdditionalBusinessSlotAgreementController` (§1.2): resolve the target model (404 if not found, before any manager call — RFC §24 line 1078's "fail closed with a 404-shaped response, never a 403" for unrelated resources), call exactly one manager method with `(int) Auth::id()` as the actor and the FormRequest's validated `reason`, catch only the specific typed exception(s) that method can throw and map to `flash_error`, otherwise `flash_success` redirect back to `show`. `retryFundingAttempt` additionally performs the plain conditional guard locked in §2.1.2 before its own single manager call — that guard is not itself a manager or repository call, so "exactly one manager call" still holds for every write action. No action ever contains a raw `DB::table(...)` call, a raw Eloquent write against `BusinessUsageWallet`/`BusinessUsageLedgerEntry`/any Usage-domain model, or more than one manager call.

`{business}` route-model-binds by primary key `id` — confirmed by direct read of `app/Models/Business.php` (no `getRouteKeyName()` override) and `routes/admin.php:597-600` (the existing `Route::resource('businesses', ...)` and `businesses/{business}/status` route both already bind this way with no `whereUuid`/`whereNumber` constraint needed). `{attempt}` binds by primary key `id`, constrained `->whereNumber('attempt')`, matching the existing `additional-business-slot-agreements/{agreement}/renewals/{charge}/retry` precedent (`routes/admin.php:710`) exactly.

#### 2.1.2 Funding-attempt retry — authorization, mandatory reason, and audit correction

**New this round (§0's own Correction Round 1 record, items 1 and 2).** `retryFundingAttemptAsAdministrator()`'s current body (confirmed by direct read at this contract's own base SHA, §1.3) neither authorizes nor audits its own caller — `assertPlatformAdministrator()` is never called, and `$reason` is accepted but never used. This contract locks the following change to `app/Library/Usage/UsageBillingCheckoutManager.php`:

```php
public function retryFundingAttemptAsAdministrator(BusinessFundingAttempt $attempt, int $actorUserId, string $reason): FundingAttemptResult
{
    $this->assertPlatformAdministrator($actorUserId);

    $normalizedReason = trim($reason);
    if ($normalizedReason === '') {
        throw new UnauthorizedSlotAgreementActionException($actorUserId, null, 'retry a funding attempt without a reason');
    }

    if (! in_array($attempt->state, [FundingAttemptState::ProviderPending, FundingAttemptState::RequiresAction, FundingAttemptState::Failed], true)) {
        throw new FundingAttemptNotResumableException($attempt->id, $attempt->state->value);
    }

    if ($attempt->provider_session_or_intent_reference === null) {
        throw new FundingAttemptNotResumableException($attempt->id, $attempt->state->value);
    }

    if ($attempt->purpose === FundingAttemptPurpose::ManualTopUp || $attempt->purpose === FundingAttemptPurpose::AddonPurchase) {
        $session = $this->gateway->retrieveCheckoutSession($attempt->provider_session_or_intent_reference);

        if ($this->fundingAttemptCheckoutVerified($attempt, $session)) {
            $this->confirmSucceeded($attempt, TransitionSource::AdminAction, null, $actorUserId, $this->resolveVerifiedPaymentMethodDisplay($attempt, $session), $normalizedReason);

            return new FundingAttemptResult($attempt->id, FundingAttemptState::Succeeded, null);
        }

        return new FundingAttemptResult($attempt->id, $attempt->state, null);
    }

    $paymentIntent = $this->gateway->retrievePaymentIntent($attempt->provider_session_or_intent_reference);

    if ($paymentIntent->status === 'succeeded') {
        $this->confirmSucceeded($attempt, TransitionSource::AdminAction, null, $actorUserId, null, $normalizedReason);

        return new FundingAttemptResult($attempt->id, FundingAttemptState::Succeeded, null);
    }

    return new FundingAttemptResult($attempt->id, $attempt->state, null);
}
```

Locked design points:
- **`assertPlatformAdministrator()` (already present on this exact class, `UsageBillingCheckoutManager.php:2206`, throwing `UnauthorizedSlotAgreementActionException`) and the blank-reason check both run first, before either gateway call** — matching this class's own established M4 convention (`retrySlotRenewalAsAdministrator()`/`allocateSlotAgreementAsAdministrator()` already perform an identical pair of checks, `UsageBillingCheckoutManager.php:1547-1562,2131-2140`). No new exception class is introduced for either check — both reuse the existing `UnauthorizedSlotAgreementActionException`, exactly as this class's own sibling M4 methods already do for their own blank-reason rejection.
- **`confirmSucceeded()`, `finalizeFundingAttemptState()`, and `recordTransition()` each gain one new, trailing, optional `?string $reason = null` parameter** — confirmed backward-compatible with every existing call site (`confirmAttemptFromReturn()`, `confirmAttemptFromWebhook()`, and every other internal caller, confirmed by direct grep of every call site of all three methods at this base) since PHP permits omitting a trailing optional parameter; no other call site is modified.
- **`recordTransition()`'s own `$this->transitionRepository->create([...])` array gains one new key, `'reason' => $reason`**, persisting the normalized reason on the resulting `business_funding_attempt_transitions` row only when one is supplied (`null` for every non-admin transition source, exactly as today).
- **New, nullable migration required and locked**: `database/migrations/2026_08_28_120001_add_reason_to_business_funding_attempt_transitions_table.php` — adds `$table->text('reason')->nullable()->after('actor_user_id');` to `business_funding_attempt_transitions`. Mechanically derived: every other transition-style table in this domain that records a mandatory-reason admin action already has its own `reason` column (`business_usage_wallet_billing_status_transitions.reason`, `business_usage_limit_transitions.reason`, both confirmed NOT NULL since both are exclusively admin-actor tables); `business_funding_attempt_transitions` is shared by four transition sources (`SyncResponse`, `WebhookEvent`, `ReconciliationJob`, and now `AdminAction`) so its own `reason` column must be **nullable**, storing `null` for every existing, non-admin-actor row, unlike the two admin-only tables.
- **`app/Models/BusinessFundingAttemptTransition.php` gains `'reason'` in its own `$fillable` array** — no new cast (plain nullable text), no relation change.
- **Explicit controller-level cross-business guard, locked in `UsageBillingController::retryFundingAttempt()`** — `retryFundingAttemptAsAdministrator()` receives only a `BusinessFundingAttempt`, never a `Business`, so it has no way to validate which `{business}` the URL named; the manager cannot perform this check for itself. The controller therefore performs it explicitly, before calling the manager or the payment-provider gateway:
  ```php
  if ((int) $attempt->business_id !== (int) $business->id) {
      abort(404);
  }
  ```
  This corrects §2.9's own prior, inaccurate claim that every write action's manager method re-validates URL ownership — that claim is true for `issueManualCredit`/`suspendBilling`/`resumeBilling`/`setSafetyLimit` (each receives the resolved `Business` directly) but was never true for `retryFundingAttempt`, and §2.9 is corrected below to say so.

### 2.2 Routes — exact addition to `routes/admin.php`

Unchanged this round. Added inside the **same** `EnsureUserIsAdministrator` group already wrapping the `businesses` resource (`routes/admin.php:596-601`), immediately after the existing `businesses/{business}/status` route:

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

### 2.3 New manager method: `UsageWalletManager::issueManualCredit()` — idempotent, event-dispatching

**Corrected this round (§0's own Correction Round 2 record, items 1, 2, and 4) and last round (Correction Round 1 record, items 3 and 4).**

```php
public function issueManualCredit(Business $business, UsageLedgerEntryType $entryType, int $amountMicro, int $actorUserId, string $reason, string $operationId): BusinessUsageLedgerEntry
{
    $this->assertPlatformAdministrator($actorUserId);

    if (! in_array($entryType, [UsageLedgerEntryType::ManualCredit, UsageLedgerEntryType::PromotionalCredit], true)) {
        throw new InvalidAdminCreditEntryTypeException($entryType->value);
    }

    if ($amountMicro <= 0) {
        throw new InvalidAdminCreditAmountException($amountMicro);
    }

    $normalizedReason = trim($reason);
    if ($normalizedReason === '') {
        throw new InvalidAdminCreditReasonException((int) $business->id);
    }

    $normalizedOperationId = strtolower(trim($operationId));
    if (! Str::isUuid($normalizedOperationId)) {
        throw new InvalidAdminCreditOperationIdException($operationId);
    }

    $correlationKey = 'admin_credit:'.$business->id.':'.$normalizedOperationId;

    return DB::transaction(function () use ($business, $entryType, $amountMicro, $actorUserId, $normalizedReason, $correlationKey) {
        $wallet = $this->walletRepository->findForUpdateByBusinessId((int) $business->id);

        if ($wallet === null) {
            throw new UsageWalletNotFoundException((int) $business->id);
        }

        $existing = $this->ledgerRepository->findByCorrelationKey($correlationKey);

        if ($existing !== null) {
            $samePayload = (int) $existing->business_id === (int) $business->id
                && $existing->entry_type === $entryType
                && (int) $existing->gross_amount_micro === $amountMicro
                && (int) $existing->actor_user_id === $actorUserId
                && $existing->reason === $normalizedReason;

            if (! $samePayload) {
                throw new ManualCreditOperationConflictException($correlationKey);
            }

            return $existing; // idempotent replay: zero balance change, zero events, zero new row
        }

        $wallet = $this->rollOverPeriodsIfNeeded($wallet, $business);

        $debtCleared = min($amountMicro, max(0, $wallet->debt_balance_micro));
        $creditedToAvailable = $amountMicro - $debtCleared;

        $ledgerEntry = $this->ledgerRepository->create([
            'business_id' => $business->id,
            'wallet_id' => $wallet->id,
            'entry_type' => $entryType->value,
            'available_delta_micro' => $creditedToAvailable,
            'reserved_delta_micro' => 0,
            'debt_delta_micro' => -$debtCleared,
            'gross_amount_micro' => $amountMicro,
            'currency_id' => $wallet->currency_id,
            'actor_user_id' => $actorUserId,
            'reason' => $normalizedReason,
            'correlation_key' => $correlationKey,
            'created_at' => Carbon::now(),
        ]);

        $walletUpdate = [
            'available_balance_micro' => $wallet->available_balance_micro + $creditedToAvailable,
            'debt_balance_micro' => $wallet->debt_balance_micro - $debtCleared,
        ];

        $shouldDispatchLowBalanceNotification = false;
        $lowBalanceFragment = $creditedToAvailable > 0
            ? $this->lowBalanceMarkerUpdate($wallet, $wallet->available_balance_micro + $creditedToAvailable, $shouldDispatchLowBalanceNotification)
            : [];

        $this->walletRepository->update($wallet, array_merge($walletUpdate, $lowBalanceFragment));

        if ($creditedToAvailable > 0) {
            \App\Events\Usage\BusinessWalletCredited::dispatch($business->id, (int) $wallet->id, (int) $ledgerEntry->id, $creditedToAvailable);
        }

        if ($debtCleared > 0) {
            \App\Events\Usage\BusinessWalletDebtCleared::dispatch($business->id, (int) $wallet->id, (int) $ledgerEntry->id, $debtCleared);
        }

        if ($shouldDispatchLowBalanceNotification) {
            \App\Jobs\Usage\SendLowBalanceNotification::dispatch($business->id)->afterCommit();
        }

        return $ledgerEntry;
    });
}
```

Locked design points:
- **Return type, locked: `BusinessUsageLedgerEntry`** — the created row on a fresh credit, or the original, already-existing row on an idempotent replay. No new value object/DTO is introduced.
- **Five new, single-purpose exceptions, each explicitly on the production allow-list (§6) — no "TBD" naming is left to implementation:** `InvalidAdminCreditEntryTypeException`, `InvalidAdminCreditAmountException`, `InvalidAdminCreditReasonException`, `InvalidAdminCreditOperationIdException` (new this round), `ManualCreditOperationConflictException` (all `app/Exceptions/Usage/`). Non-admin actor rejection reuses the existing `UnauthorizedUsageBillingManagementException` (`UsageWalletManager`'s own established convention, e.g. `setBillingStatus()`'s identical reuse) — no new file for that guard.
- **`operationId` is supplied by the caller, not generated inside the manager** (§2.6 locks exactly how the controller/view produce and preserve it), **but the manager never trusts it as-is — corrected this round (§0's own Correction Round 2 record, item 2).** The FormRequest's own `uuid` validation rule is not relied upon alone: the manager independently trims and lowercases `operationId`, validates the result via `Str::isUuid()`, and throws `InvalidAdminCreditOperationIdException` before ever constructing a correlation key — the identical defense-in-depth discipline `entryType`/`amountMicro`/`reason` already receive. **Lowercasing before validation is not merely defensive — it closes a real duplicate-credit path**: an uppercase- and a lowercase-rendered form of the identical UUID would otherwise produce two different correlation keys and two genuinely separate credits; normalizing first guarantees both resolve to the same key. The correlation key is **deterministic** — `'admin_credit:'.$business->id.':'.$normalizedOperationId` — not random, so the identical HTTP request replayed (a double form submission, a retried request after a timeout, a browser back-button resubmission) always produces the identical correlation key. **The resulting key always fits `business_usage_ledger_entries.correlation_key`'s own `VARCHAR(191)` column**: `'admin_credit:'` (13 characters) + a `business_id` up to the unsigned-bigint maximum of 20 digits + `':'` (1 character) + a 36-character lowercase UUID = 70 characters maximum, well under 191.
- **Idempotency is checked inside the wallet-row-locked transaction, before any balance mutation, any event dispatch, or any low-balance-marker update** — `BusinessUsageLedgerEntryRepository::findByCorrelationKey()` (new, §2.4) is the lookup. An existing row with an **identical normalized payload** (Business id, entry type, gross amount, actor, normalized reason — the same five fields this correction round named) is treated as the same operation and its own already-persisted row is returned unchanged: no second `create()`, no wallet update, no event dispatch, no low-balance re-evaluation. **Corrected this round (§0's own Correction Round 2 record, item 1): the `entry_type` comparison is `$existing->entry_type === $entryType` — enum-to-enum, not enum-to-string** — `BusinessUsageLedgerEntry::$casts` casts `entry_type` to `UsageLedgerEntryType` (confirmed, `app/Models/BusinessUsageLedgerEntry.php:52`), so comparing against `$entryType->value` (a raw string) was always `false` and would have misclassified every genuinely identical replay as a conflict. Every other field in this comparison was re-audited against the model's own casts and confirmed correct as already written: `gross_amount_micro` casts `integer`, compared via explicit `(int)` casts on both sides; `business_id`/`actor_user_id` are uncast, likewise compared via explicit `(int)` casts on both sides; `reason` is uncast, compared as a plain string. An existing row with **any of those five fields different** is treated as a genuine conflict — `ManualCreditOperationConflictException` is thrown, and nothing is mutated (the throw happens before `rollOverPeriodsIfNeeded()`/`create()`/`update()` are ever reached).
- **The pre-existing `UNIQUE` constraint on `business_usage_ledger_entries.correlation_key` remains the database-level backstop** underneath this application-level check, exactly as it already is for `creditFromFunding()`'s own funding-attempt-keyed correlation keys — this contract does not relax, bypass, or duplicate that constraint.
- **Debt-clears-first, then credits `available_balance_micro`, mirroring `creditFromFunding()`'s own existing arithmetic exactly** (`UsageWalletManager.php:879-954`) — `$debtCleared = min($amountMicro, max(0, $wallet->debt_balance_micro))`, `$creditedToAvailable = $amountMicro - $debtCleared`. As before, this is new, independent code — `creditFromFunding()` itself is not modified, and `issueManualCredit()` never calls it (enforced by §5.3's boundary test).
- **Dispatches the two already-shipped wallet-balance domain events `creditFromFunding()` already dispatches for the identical arithmetic shape** — `BusinessWalletCredited::dispatch($businessId, $walletId, $ledgerEntryId, $creditedToAvailable)` when `$creditedToAvailable > 0`, and `BusinessWalletDebtCleared::dispatch($businessId, $walletId, $ledgerEntryId, $debtCleared)` when `$debtCleared > 0` — both may fire for the same credit (a credit larger than the outstanding debt), both carry the created ledger-entry id, and both are `ShouldDispatchAfterCommit` (unmodified event classes, `app/Events/Usage/BusinessWalletCredited.php`, `.../BusinessWalletDebtCleared.php`) — their own existing after-commit deferral behavior is preserved unmodified, exactly as it already works for `creditFromFunding()`. **No new event class is introduced.**
- **Mirrors `creditFromFunding()`'s own low-balance-marker reset/re-evaluation semantics exactly** — the same private `lowBalanceMarkerUpdate()` helper (`UsageWalletManager.php:1545`), called only when `$creditedToAvailable > 0`. **Corrected this round (§0's own Correction Round 2 record, item 4): this helper does not merely "signal a marker reset."** Confirmed by direct re-read of its own body (`UsageWalletManager.php:1545-1568`): it can set a fresh `low_balance_notified_at` marker (crossing the auto-recharge threshold downward for the first time), leave an existing marker untouched (already below threshold, already marked), or clear an existing marker (balance has risen back above threshold) — `SendLowBalanceNotification::dispatch(...)->afterCommit()` follows only the first of these three outcomes, exactly as `creditFromFunding()`'s own identical call already behaves.
- **`SendReceiptNotification` is never dispatched** — unchanged from the initial draft; a manual/promotional credit has no provider-side evidence to attach a receipt to.
- **An idempotent replay dispatches zero events of any kind and touches no wallet column** — the early `return $existing;` happens before every event-dispatch statement and before the wallet-update call in the method body, confirmed directly by the code block's own control flow above.

### 2.4 New repository methods — exact, per contract, all bounded

**Corrected this round (§0's own Correction Round 1 record, item 5's bounded-reads half).** Every new method is a plain, non-locking read, and every history/listing method is now explicitly bounded — no unbounded `Collection` read is introduced anywhere in this contract's own scope:

- **`BusinessUsageLedgerEntryRepository::forBusinessPaginated(int $businessId, int $perPage, array $filters = []): LengthAwarePaginator`** — unchanged from the initial draft; `$filters` supports `entry_type` (exact match) and `from`/`to` (inclusive `created_at` date-range bounds), ordered `orderByDesc('id')`. Already bounded by construction (a real paginator, §2.11).
- **`BusinessUsageLedgerEntryRepository::findByCorrelationKey(string $correlationKey): ?BusinessUsageLedgerEntry`** — new this round, the idempotency lookup `issueManualCredit()` requires (§2.3): `where('correlation_key', $correlationKey)->first()`.
- **`BusinessUsageLedgerEntryRepository::marginAggregateForBusiness(int $businessId, string $periodKey): Collection`** — new, replacing the initial draft's unbounded `marginAggregateRowsForBusiness()`; full design in §2.5.
- **`BusinessFundingAttemptRepository::recentForBusiness(int $businessId, int $limit = 20): Collection`** — unchanged from the initial draft; already bounded by its own explicit `$limit`. Plain (no `FOR UPDATE`), `orderByDesc('id')->limit($limit)->get()`. Deliberately distinct from, and never delegates to, the existing locking `findOutstandingForBusiness()` (§1.4).
- **`PlatformFeatureUsageSafetyLimitRepository::all(): Collection`** — unchanged; `orderBy('feature_key')->get()`. Not bounded by a `$limit`, but deliberately so: this table holds at most one row per platform feature key, a genuinely small, operator-controlled set (M2 contract §6.C: zero rows until a feature is actually metered — only Conversations today, per M5), not an unbounded, ever-growing history table — the same category `PaymentProviderEventController::index()`'s own unbounded-but-genuinely-small exhausted-event read already establishes as an acceptable pattern in this exact domain.
- **`BusinessUsageWalletBillingStatusTransitionRepository::recentForBusiness(int $businessId, int $limit = 20): Collection`** — **renamed and bounded this round** (was `forBusiness()`, unbounded, in the initial draft): `where('business_id', $businessId)->orderByDesc('id')->limit($limit)->get()`.
- **`BusinessUsageLimitTransitionRepository::recentForBusiness(int $businessId, int $limit = 20): Collection`** — **renamed and bounded this round** (was `forBusiness()`): `where('business_id', $businessId)->orderByDesc('id')->limit($limit)->get()` (per-Business spend-cap/feature-limit history).
- **`BusinessUsageLimitTransitionRepository::recentPlatformSafetyLimitHistory(int $limit = 50): Collection`** — **renamed and bounded this round** (was `platformSafetyLimitHistory()`): `whereNull('business_id')->where('limit_type', UsageLimitType::PlatformSafetyLimit->value)->orderByDesc('id')->limit($limit)->get()`, confirmed correct against the migration's own nullable `business_id`/`feature_key` columns (§1.3).

**No new method is added to `BusinessUsageWalletRepository` or `BusinessFeatureUsageLimitRepository`** — unchanged from the initial draft; `findByBusinessId()` and `forBusiness()` already exist and are reused verbatim.

### 2.5 Provider-cost/margin aggregate — SQL-computed, bounded, locked

**Corrected in Correction Round 1 (aggregation moved into SQL), again in Correction Round 2 (§0's own record, item 3: precision and internal consistency), and now in this Exceptional post-review correction (§0's own record: exact string arithmetic, not PHP `int`).** The initial draft's `marginAggregateRowsForBusiness()` returned one raw row per matching ledger entry for PHP-side summation — for a Business with a large volume of metered usage in one period, this loads every matching row into memory merely to add them up. Correction Round 1 moved the aggregation into SQL. Correction Round 2 fixed two further defects that survived that move: `provider_cost_micro` is an `unsignedBigInteger`, schema-valid up to 20 integer digits, but Round 1's own `CAST(... AS DECIMAL(20,6))` left only 14 integer digits available (`20 − 6`), risking overflow for large, schema-legal values; and Round 1's own `margin_micro` was computed as its own, independently-rounded SQL expression, which can diverge by one micro from `retail_revenue_micro − provider_cost_micro` at a half-micro rounding boundary. Round 2's own fix cast the resulting aggregates to PHP `int` before subtracting — **this exceptional correction fixes that: PHP's native `int` is signed and 64-bit, so its maximum value (`9223372036854775807`) is roughly half of `unsignedBigInteger`'s own maximum (`18446744073709551615`), and a `SUM()` across multiple rows can legitimately exceed even that — a schema-valid `provider_cost_micro` value, or a genuine multi-row aggregate, can silently overflow or corrupt on `(int)` cast.** **Redesigned: the aggregation itself still happens inside `EloquentBusinessUsageLedgerEntryRepository::marginAggregateForBusiness()`, in SQL, returning one row per `feature_key`** — never one row per ledger entry — **but the two aggregate values are now preserved as exact integer-micro strings (MySQL's own PDO-returned string representation of a `DECIMAL` result, never cast through PHP `int` or `float`), and `margin_micro` is computed via exact `bcmath` string subtraction:**

```php
public function marginAggregateForBusiness(int $businessId, string $periodKey): Collection
{
    return $this->query()
        ->selectRaw('feature_key')
        ->selectRaw('SUM(gross_amount_micro) AS retail_revenue_micro')
        ->selectRaw('ROUND(SUM(CAST(provider_cost_micro AS DECIMAL(20,0)) * quantity), 0) AS provider_cost_micro')
        ->where('business_id', $businessId)
        ->where('period_key', $periodKey)
        ->whereIn('entry_type', [UsageLedgerEntryType::UsageCharge->value, UsageLedgerEntryType::UsageOverageCharge->value])
        ->whereNotNull('provider_cost_micro')
        ->groupBy('feature_key')
        ->get()
        ->map(function ($row) {
            $row->retail_revenue_micro = (string) $row->retail_revenue_micro;
            $row->provider_cost_micro = (string) $row->provider_cost_micro;
            $row->margin_micro = bcsub($row->retail_revenue_micro, $row->provider_cost_micro, 0);

            return $row;
        });
}
```

Locked design points:
- **`retail_revenue_micro = SUM(gross_amount_micro)`, no `ROUND()` needed or applied.** `gross_amount_micro` is itself an integer column (`unsignedBigInteger`) — summing integers is exact, with no fractional component ever introduced, so wrapping it in `ROUND()` (as Correction Round 1 did) was harmless but unnecessary.
- **`provider_cost_micro = ROUND(SUM(CAST(provider_cost_micro AS DECIMAL(20,0)) * quantity), 0)`, unchanged since Correction Round 2.** `provider_cost_micro` is schema-valid up to 20 integer digits (`unsignedBigInteger`); `DECIMAL(20,0)` reserves all 20 digits for the integer part, matching the column's own genuinely integer, no-fractional-part nature exactly. The multiplication against `quantity` (`decimal(14,6)`) remains exact `DECIMAL` arithmetic performed by MySQL, never an implicit float — MySQL's own decimal-multiplication rule (`DECIMAL(M1,D1) × DECIMAL(M2,D2) → DECIMAL(M1+M2, D1+D2)`, capped at MySQL's own 65-digit maximum) yields `DECIMAL(34,6)` here (`20+14=34` total digits, `0+6=6` decimal digits), comfortably within that cap, and `SUM()` across multiple such rows stays comfortably within it too.
- **Neither aggregate is ever cast to PHP `int` — corrected by this exceptional correction.** MySQL's own `SUM()`/`ROUND()` over an exact (integer or `DECIMAL`) argument returns a `DECIMAL` result, and PDO's MySQL driver returns `DECIMAL` column values as PHP strings, never as a native numeric type, precisely because no native PHP numeric type can losslessly represent the full range a `DECIMAL(34,6)` (or larger, after `SUM()`) result can hold. `(string) $row->retail_revenue_micro` / `(string) $row->provider_cost_micro` are defensive normalizations of an already-string value, not a narrowing cast — they exist to guarantee the type contract explicitly, not to convert anything. **`retail_revenue_micro` and `provider_cost_micro` are always non-negative (unsigned) integer-micro strings** — both are sums of non-negative columns (`gross_amount_micro`, and `provider_cost_micro × quantity`, both `unsignedBigInteger`/non-negative `decimal`) — **`margin_micro` may be negative (signed)**, whenever a feature's own provider cost exceeds its retail revenue for the period, and is likewise always an exact integer-micro string, never a PHP `int` or `float`.
- **`margin_micro` is computed once per feature-key row, in PHP, immediately after the query returns, via exact `bcmath` string subtraction — `bcsub($row->retail_revenue_micro, $row->provider_cost_micro, 0)`, never native `-` on cast integers.** This is a light, per-row finalization on an already-small, already-aggregated result set (at most one row per feature key), not a return to loading raw ledger rows or to per-row PHP summation — the aggregation itself still happens entirely in SQL; only the final one-row-per-feature subtraction happens in PHP, and it happens in exact-precision string arithmetic, not native integer or float arithmetic. **This guarantees the invariant holds for every returned row, always, verifiable via exact string comparison:** `bccomp($row->margin_micro, bcsub($row->retail_revenue_micro, $row->provider_cost_micro, 0), 0) === 0`.
- **`bcmath` is already an RFC-005 runtime prerequisite** — `UsageWalletManager::bcRoundHalfUp()` and every wallet-arithmetic code path already depend on the `bcmath` PHP extension being present; this correction adds no new dependency and no new production path, only a new call site (`bcsub()`) inside an already-authorized repository method.
- **Rounding rule, locked: SQL `ROUND(..., 0)` to the nearest whole micro (MySQL's own default round-half-away-from-zero for `DECIMAL` values), applied only to `provider_cost_micro`** — `retail_revenue_micro` needs no rounding (already an exact integer sum) and `margin_micro` is an exact `bcsub()` of two already-rounded integer-micro strings, not an independently-rounded value.
- **Grouping, locked and unchanged since the initial draft: one row per `feature_key`, for one Business and one `period_key` (format `YYYY-MM`, matching `business_usage_ledger_entries.period_key`'s own existing shape)** — scoped to `entry_type IN ('usage_charge', 'usage_overage_charge')` (the only two entry types ever populated with a rate/cost basis, confirmed §1.4) and `provider_cost_micro IS NOT NULL`.
- **The controller-side responsibility is formatting only, via a locked controller-private method — no dedicated value object is introduced. The formatter accepts signed/unsigned integer-micro strings and never converts any of them through `float` or PHP `int`** — corrected by this exceptional correction; a naive `number_format()`/numeric-cast call on a value this large would silently lose precision the same way the repository's own prior `(int)` cast did. `UsageBillingController` receives the already-aggregated `Collection` of rows (`feature_key`, `retail_revenue_micro`, `provider_cost_micro`, `margin_micro`, all already-computed exact integer-micro strings) from the repository and formats them for display via string-safe formatting only; it performs no summation, no subtraction, no numeric cast, and no per-row computation of its own — the repository's own `.map()` step (above) is the only place `margin_micro` is ever computed.
- **Never edits `provider_cost_micro`.** This is a pure read aggregation; no write path to that column is added anywhere (§3).

### 2.6 Views

All new views live under `resources/views/admin/usage-billing/` and use the `x-*` design-system component idiom (`x-card`, `x-table`, `x-badge`, `x-alert`, `x-empty-state`, `x-button`) — confirmed as the current, consistently-applied convention within the Usage domain specifically (`provider-events/index.blade.php`, `additional-business-slot-agreements/index.blade.php` and `show.blade.php`), not the older raw-Bootstrap idiom still used by `workspace-plan-catalog/index.blade.php`.

- **`resources/views/admin/usage-billing/businesses/show.blade.php`** — the single per-Business dashboard: wallet snapshot (available/reserved/debt balances, spend cap, committed/reserved spend this period, `billing_status` badge, auto-recharge configuration shown **read-only**, never editable here — §3), configured per-feature limits (`x-table`), the SQL-computed margin aggregate (§2.5, formatted by a controller-private method), a paginated/filterable ledger listing (`{{ $ledgerEntries->links() }}`, entry-type and date-range filter inputs as a `GET` form, mirroring `AdditionalBusinessSlotAgreementController::index()`'s own `->paginate(25)` convention), recent funding attempts with an inline "Retry" form per resumable row (`state` in `ProviderPending`/`RequiresAction`/`Failed`, mirroring the show page's own conditional-form-per-state pattern already established at `additional-business-slot-agreements/show.blade.php`), billing-status suspend/resume forms (each a single required `reason` text input, no confirmation modal — matching the established no-JS-modal convention), a manual-credit issuance form (`entry_type` select constrained to Manual Credit/Promotional Credit, an amount input, a required `reason`, and a hidden `operation_id` input — **new this round (§2.3)**: `value="{{ old('operation_id', $operationId) }}"`, where `$operationId` is a fresh UUID `show()` generates on every `GET` render; a validation-failure redirect preserves the identical UUID via Laravel's own default old-input mechanism, so a corrected-and-resubmitted form reuses the same operation, while a genuinely fresh page load always receives a new one), and billing-status/limit-change history tables (bounded, §2.4). If no wallet exists for the Business (an edge case; every Business should have one via `initializeWalletForNewBusiness()` at creation, M1), the page renders an explicit `x-empty-state` rather than throwing.
- **`resources/views/admin/usage-billing/safety-limits/index.blade.php`** — platform-wide: a table of every currently configured safety limit (`x-table`, one row per `feature_key`), an inline set/update form per row plus one "configure a new feature key" form, and the platform-safety-limit-scoped transition history (bounded, §2.4).
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

**`access: 'access backend'` on every child (no new permission string) is locked this round (§0's own Correction Round 1 record item 6, resolving the initial draft's own open item 1): this contract continues the established Usage-admin convention** (`PaymentProviderEventController.php:14-15`'s own "no new config/permissions.php entry is authorized," and the Dashboard entry's own identical `'access' => 'access backend'` at `Helper.php:560`) of relying on `access backend` + `EnsureUserIsAdministrator` alone, with no dedicated permission string introduced by this contract.

### 2.8 Read-model/query ownership — no raw billing-table access from controllers

**Locked, and enforced by a static source-boundary test (§5.3):** `UsageBillingController` contains zero raw `DB::table(...)`/`DB::select(...)` calls and zero direct Eloquent query-builder calls against `BusinessUsageWallet`, `BusinessUsageLedgerEntry`, `BusinessFeatureUsageLimit`, `PlatformFeatureUsageSafetyLimit`, `BusinessUsageWalletBillingStatusTransition`, or `BusinessUsageLimitTransition`. Every read and every write goes through the repositories and managers named in §2.1's table — the controller's own body is a sequence of "resolve route params → call exactly one repository or manager method per line of work → pass the result to the view / redirect," identical in shape to `AdditionalBusinessSlotAgreementController`'s own established discipline (§1, "one manager call per action").

### 2.9 Authorization model — reused, not reinvented

The identical four-layer defense-in-depth already established for `AdditionalBusinessSlotAgreementController` applies unchanged, with no new layer invented:

1. **Route-group Gate**: `can:access backend` (`RouteServiceProvider.php:67`), applied to every route in `routes/admin.php` including this contract's own.
2. **`EnsureUserIsAdministrator` middleware** (`app/Http/Middleware/EnsureUserIsAdministrator.php`), wrapping this contract's own new route block exactly as it wraps the existing `businesses` resource (§2.2) — a direct `$request->user()->is_admin` check, independent of any permission string, throwing `AuthorizationException` on failure.
3. **No controller-level `$this->authorize(...)` call** — matching the M3/M4 Usage-domain convention (not the RFC-004 Workspace convention), since no dedicated permission string is introduced (§2.7).
4. **Manager-level independent re-verification**: every write action's underlying manager method (`issueManualCredit`, `setBillingStatus`, `setSafetyLimit`, and — corrected this round, §2.1.2 — `retryFundingAttemptAsAdministrator`) calls `assertPlatformAdministrator()` itself, so a mutation is safe even if the HTTP-layer boundary were somehow bypassed — proven the same way `SlotAgreementAdminAuthorityTest.php`'s `test_a_non_admin_actor_directly_invoking_...` methods already prove it for the existing controller (§5.2 and §5.4 add the equivalent tests for `issueManualCredit` and `retryFundingAttemptAsAdministrator` respectively).

**FormRequest `authorize()` returns `true` unconditionally on every new FormRequest**, matching the M3/M4 Usage-domain convention exactly (not the RFC-004 controller-level-Gate convention) — since this contract's entire scope lives inside the Usage domain, it follows that domain's own already-established pattern rather than introducing a third one (§1, "General platform-admin UI/controller conventions").

**Cross-tenant fail-closed behavior, corrected this round (§0's own Correction Round 1 record, item 2):** every action resolves `{business}` via implicit route-model binding (a 404 before any manager call, for a nonexistent Business id). For `issueManualCredit`/`suspendBilling`/`resumeBilling`/`setSafetyLimit`, the resolved `Business` model is passed directly into the underlying manager method, so there is no separate id to spoof. **For `retryFundingAttempt` specifically — the underlying manager method receives only the resolved `BusinessFundingAttempt`, not the `Business`, so it cannot itself validate which `{business}` the URL named; the controller performs this check explicitly and first** (§2.1.2's own `(int) $attempt->business_id !== (int) $business->id → abort(404)` guard, executed before the manager or the payment-provider gateway is ever reached). No action ever accepts a Business id from the request body, only from the route, so one Business's mutation can never be redirected at another's data by a tampered form field.

### 2.10 Pagination/filtering

`forBusinessPaginated()` (§2.4) defaults to 25 rows/page (matching `AdditionalBusinessSlotAgreementController::index()`'s own `->paginate(25)`), accepts `entry_type` and `from`/`to` filters via `GET` query-string parameters, and the view renders `{{ $ledgerEntries->appends($request->query())->links() }}` so filters survive pagination. `recentForBusiness()` (funding attempts, billing-status transitions, limit transitions) and `recentPlatformSafetyLimitHistory()` (§2.4) are deliberately **not** paginated — each bounded by an explicit `$limit` (default 20 or 50) instead, matching `PaymentProviderEventController::index()`'s own precedent of skipping pagination for a small, bounded list rather than over-engineering pagination for a screen that never needs it. Business discovery itself is not duplicated (§2.6) — the existing `admin.businesses.index` (already paginated/filterable, per `WorkspaceController`'s own analogous `paginateForAdmin()` precedent, not modified by this contract) remains the sole Business-discovery entry point.

---

## 3. Excluded/forbidden capabilities — explicit, per this contract's own instruction

This contract's design (§2) never, under any code path:

- **Originates a fresh customer charge.** `UsageBillingController` never calls `initiateTopUp()`, `initiateAutoRecharge()`, `initiateAddonPurchase()`, `quoteAdditionalSlotAgreement()`, or any other charge-originating manager method — confirmed absent from every action's own call table (§2.1.1) and enforced by a static source-boundary test (§5.3).
- **Enables or configures auto-recharge.** `configureAutoRecharge()` is never called; the dashboard's own auto-recharge display (§2.6) is read-only.
- **Directly edits any derived/formula counter** (`committed_spend_this_period_micro`, `reserved_spend_this_period_micro`, `recharged_this_period_micro`, `consecutive_recharge_failures`, or any wallet balance column) — every balance mutation happens exclusively inside `issueManualCredit()`'s own `UsageWalletManager`-owned transaction (§2.3), and every other wallet field this contract's views display is read-only.
- **Mutates any ledger row directly.** `BusinessUsageLedgerEntryRepository::create()` is called at most once per `operation_id`, exclusively from inside `issueManualCredit()` — never on an idempotent replay (§2.3) — and no `update()`/`delete()` is ever called against a ledger entry anywhere in this contract's scope (the repository itself, unmodified in this respect by this contract, still exposes no such method at all, §1.4).
- **Bypasses manager/repository authority.** §2.8's boundary is absolute — no raw query against a billing table from the controller, ever.
- **Edits `provider_cost_micro`.** §2.5's aggregate is read-only by construction; no write path to that column is added anywhere.
- **Duplicates `PaymentProviderEventController`'s or `AdditionalBusinessSlotAgreementController`'s own mutations.** §2.7's integration is link-only.
- **Touches `PaymentInstrumentManager` at all.** No action in this contract's scope creates, attaches, detaches, or sets a default payment instrument — confirmed consistent with `PaymentInstrumentManager`'s own documented boundary ("No platform-administrator override exists for origination," citing RFC §9/§17 in its own docblock), which this contract does not attempt to change.

---

## 4. Guarantee-by-guarantee mapping (mirrors RFC §24's own capability-table rows)

1. **View any Business's wallet balance, ledger, and configured limits.** `show()` (§2.1.1) reads via `BusinessUsageWalletRepository::findByBusinessId()`, `BusinessFeatureUsageLimitRepository::forBusiness()`, `BusinessUsageLedgerEntryRepository::forBusinessPaginated()` (new) — no write, any Business, platform-administrator only (§2.9).
2. **Issue auditable manual/promotional credit.** `issueManualCredit()` (§2.1.1) calls `UsageWalletManager::issueManualCredit()` (§2.3, redesigned in Correction Round 1 for idempotency and event dispatch, further corrected in Correction Round 2 for an enum-cast-correct identical-payload comparison and manager-level operation-id normalization/validation) — writes `actor_user_id`/`reason` on the ledger row, platform-administrator-gated, mandatory reason, and a repeated submission of the identical operation credits the wallet at most once, including when the operation id is submitted with different letter casing.
3. **Set or clear `billing_status` suspension through the manager boundary.** `suspendBilling()`/`resumeBilling()` (§2.1.1) call the existing, unmodified `UsageWalletManager::setBillingStatus()` — no new mutation logic, only a new HTTP entry point.
4. **Configure the platform feature-usage safety limit.** `safetyLimits()`/`setSafetyLimit()` (§2.1.1) read via the new `PlatformFeatureUsageSafetyLimitRepository::all()` and write via the existing, unmodified `UsageWalletManager::setSafetyLimit()`.
5. **View provider-cost/margin aggregates without editing `provider_cost_micro`.** §2.5 — a new, bounded, SQL-computed, read-only aggregate, corrected in Correction Round 2 to use exact `DECIMAL(20,0)` precision (no overflow for schema-legal 20-digit values) and an internally-consistent `margin_micro = retail_revenue_micro − provider_cost_micro` derivation that cannot diverge from that invariant at a rounding boundary, and corrected again by this exceptional correction to preserve both aggregates as exact integer-micro strings and compute `margin_micro` via `bcmath` string arithmetic rather than PHP `int`, since PHP's signed 64-bit `int` cannot represent the full `unsignedBigInteger` range `provider_cost_micro` is schema-valid for, let alone a multi-row `SUM()` that exceeds it; §3 confirms no write path exists.
6. **Resume/retry an already-created, payer-authorized funding attempt where not already exposed.** `retryFundingAttempt()` (§2.1.1) calls `UsageBillingCheckoutManager::retryFundingAttemptAsAdministrator()`, corrected in Correction Round 1 (§2.1.2) to genuinely enforce platform-administrator authorization and a mandatory reason and to persist that reason on the resulting transition row — confirmed unexposed by any admin controller before this contract (§1.3), and confirmed **not** already self-authorizing before that correction.
7. **Integrate links/read-only visibility for already-shipped provider-event and additional-slot-agreement admin surfaces without duplicating their mutations.** §2.7 — one nav group, three links, zero new controller actions for either existing surface.
8. **Preserve mandatory reasons and the administrator's real identity wherever the RFC requires them.** Every write action in §2.1.1's table requires a `reason` (validated `required|string|max:5000` at the FormRequest layer, re-validated inside the manager layer per §2.3's `issueManualCredit()` and — corrected in Correction Round 1 — §2.1.2's `retryFundingAttemptAsAdministrator()`) and passes `(int) Auth::id()` as the actor — never a synthetic or session-derived alternate identity, matching RFC §24's own "using the administrator's own real identity" language (line 1074) and the split-provenance precedent already proven correct for the M4 slot-agreement surface (`SlotAgreementAdminAuthorityTest::test_manual_allocation_records_identity_provenance_correctly`). After Correction Round 1's own fix, the funding-attempt retry path's own reason is genuinely validated and durably persisted rather than silently accepted and discarded.
9. **Never originate a fresh customer charge, enable auto-recharge, directly edit derived counters, mutate ledger rows directly, or bypass manager/repository authority.** §3, enforced by §5.3's static boundary test.

---

## 5. Test plan

### 5.1 New file: `tests/Feature/Usage/AdminUsageBillingControllerTest.php` — 32 methods

HTTP-level tests, following `tests/Feature/Workspace/AdminWorkspaceEntitlementControllerTest.php`'s own established template (§0's required reading) — the richest existing precedent in this codebase for exactly this shape of test. Items 1–27 unchanged since Correction Round 1. **+5 methods this round (items 28–32), the exact aggregate tests Correction Round 2's own item 3 requires** — kept in this file rather than a new one, since it is the narrowest existing path that already exercises the dashboard this aggregate feeds and already imports `BusinessUsageLedgerEntryRepository`'s sibling methods; these five call `app(BusinessUsageLedgerEntryRepository::class)->marginAggregateForBusiness()` directly for exact numeric precision, rather than scraping rendered HTML.

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
23. `test_retrying_a_funding_attempt_for_an_unrelated_business_is_not_found` — **strengthened this round** (§0's own Correction Round 1 record, item 2): additionally asserts the funding attempt's own `state` is unchanged and that neither the payment-provider gateway nor `retryFundingAttemptAsAdministrator()` was ever reached, proving the controller-level cross-business guard (§2.1.2) executes before either.
24. `test_mutating_one_businesss_wallet_never_affects_an_unrelated_businesss_wallet` — cross-tenant isolation, direct database assertions on both Businesses.
25. `test_an_administrator_can_view_and_set_the_platform_feature_usage_safety_limit`
26. `test_setting_the_platform_safety_limit_requires_a_mandatory_reason`
27. `test_a_repeated_manual_credit_submission_with_the_same_operation_id_creates_exactly_one_ledger_entry` — submits the same `issueManualCredit` form twice with an identical `operation_id`, asserts exactly one `business_usage_ledger_entries` row and one balance increase.
28. `test_the_margin_aggregate_sums_multiple_usage_charge_and_usage_overage_charge_rows_correctly` — Seeds two ledger rows for the same Business, period, and `feature_key` — one `usage_charge`, one `usage_overage_charge` — each with its own `gross_amount_micro`/`provider_cost_micro`/`quantity`. Calls `marginAggregateForBusiness()` directly. **Corrected by this exceptional post-review correction to assert exact-string equality, not PHP-integer equality** — `retail_revenue_micro`, `provider_cost_micro`, and `margin_micro` are each asserted `===` against the hand-computed sum expressed as a string (e.g. `'30'`, not `30`), and `is_string($row->margin_micro)` (and the same for the other two) is asserted explicitly.
29. `test_the_margin_aggregate_rounds_a_half_micro_boundary_consistently_between_cost_and_margin` — the direct proof of Correction Round 2's own item 3 fix. Seeds two rows for the same feature key: row A (`provider_cost_micro = 1`, `quantity = 0.5`, `gross_amount_micro = 10`), row B (`provider_cost_micro = 2`, `quantity = 0.5`, `gross_amount_micro = 10`) — the combined cost contribution sums to exactly `1.5`, a genuine half-micro boundary. **Corrected by this exceptional post-review correction to compare exact strings/`bccomp()`, not PHP integers** — asserts `provider_cost_micro === '2'` (MySQL's own round-half-away-from-zero), `retail_revenue_micro === '20'`, and `bccomp($row->margin_micro, '18', 0) === 0`. This is the case that would have returned `'19'` under the pre-Round-2 design (`ROUND(20 − 1.5, 0) = 19`, one micro more than `retail_revenue_micro(20) − provider_cost_micro(2) = 18`) — this test would have failed against that design and passes against the corrected one.
30. `test_the_margin_aggregate_does_not_overflow_or_lose_precision_for_values_and_sums_exceeding_the_unsigned_bigint_maximum` — **renamed and substantially strengthened by this exceptional post-review correction** (was `test_the_margin_aggregate_does_not_overflow_for_a_provider_cost_micro_value_exceeding_fourteen_integer_digits`, which only proved `DECIMAL(20,0)` avoided overflow at the *SQL* layer — it never proved the *PHP* layer was safe, and Correction Round 2's own `(int)` cast was exactly the layer this test failed to cover). Seeds **two** `usage_charge` rows for the same feature key, each with `provider_cost_micro = '18446744073709551615'` — the exact `unsignedBigInteger` maximum, supplied as a database-safe decimal string — and `quantity = 1`, `gross_amount_micro = 20`. The aggregate `SUM(provider_cost_micro × quantity)` is therefore `36893488147419103230` — deliberately **larger than a single `unsignedBigInteger` can ever hold**, and roughly four times PHP's own signed 64-bit `int` maximum (`9223372036854775807`), proving this is a genuine multi-row `SUM()` overflow scenario, not merely a single large stored value. Asserts: `$row->provider_cost_micro === '36893488147419103230'` (returned exactly as that string — not truncated, not turned into scientific notation, not silently wrapped by a native-int overflow); `$row->retail_revenue_micro === '40'`; `is_string($row->provider_cost_micro)`, `is_string($row->retail_revenue_micro)`, and `is_string($row->margin_micro)` are all true; and `bccomp($row->margin_micro, '-36893488147419103190', 0) === 0` — a large, genuinely negative signed margin, computed and compared entirely in exact `bcmath` string arithmetic, never PHP-native `-`, `(int)`, or `==`/`===` against a numeric literal.
31. `test_the_margin_aggregate_is_isolated_by_business_and_period` — Seeds ledger rows for the target Business/period, a different period for the same Business, and the same period for a different Business. Asserts only the target Business/period's own rows are reflected in the returned aggregate.
32. `test_the_margin_aggregate_always_satisfies_margin_equals_revenue_minus_cost` — the general invariant proof Correction Round 2's own item 3 requires. Seeds several rows across multiple feature keys with varied `gross_amount_micro`/`provider_cost_micro`/`quantity` combinations, including at least one that rounds unevenly and at least one whose values exceed PHP's own signed-`int` range. **Corrected by this exceptional post-review correction to verify the invariant via exact string arithmetic, not native subtraction** — asserts, for every row `marginAggregateForBusiness()` returns, `bccomp($row->margin_micro, bcsub($row->retail_revenue_micro, $row->provider_cost_micro, 0), 0) === 0`, and that `retail_revenue_micro`/`provider_cost_micro`/`margin_micro` are each `is_string(...)`, never `is_int(...)`.

Every mandatory-reason test follows the established pattern exactly: post with `reason` omitted, `assertSessionHasErrors('reason')`, then assert the affected row/state is unchanged from before the (failed) request — proving the FormRequest validation failure happens before any mutation.

### 5.2 New file: `tests/Feature/Usage/UsageWalletManagerManualCreditTest.php` — 15 methods

Manager-layer tests for `issueManualCredit()` (§2.3), mirroring `SlotAgreementAdminAuthorityTest.php`'s own established manager-layer pattern. Items 1–12 unchanged since Correction Round 1, except item 8's own description below. **+3 methods this round (13–15).**

1. `test_issuing_a_manual_credit_increases_available_balance_and_records_the_ledger_entry`
2. `test_issuing_a_manual_credit_clears_existing_debt_first`
3. `test_issuing_a_promotional_credit_records_the_correct_entry_type`
4. `test_a_non_admin_actor_directly_invoking_issue_manual_credit_is_denied_even_bypassing_http_middleware`
5. `test_issuing_a_credit_with_a_disallowed_entry_type_is_rejected`
6. `test_issuing_a_credit_requires_a_mandatory_reason`
7. `test_issuing_a_credit_requires_a_positive_amount`
8. `test_replaying_the_same_operation_id_with_an_identical_payload_returns_the_original_ledger_entry_without_a_second_credit` — **the direct proof of Correction Round 2's own item 1 fix.** Because this test's own fixture replays the identical `entry_type` on both calls, it exercises exactly the comparison branch that was silently always-false before that fix (`BusinessUsageLedgerEntry::$casts` casts `entry_type` to `UsageLedgerEntryType`, so comparing the cast enum against a raw string via `$entryType->value` could never match) — this test would have failed against the pre-Round-2 code, misclassifying a genuinely identical replay as a conflict and throwing `ManualCreditOperationConflictException` instead of returning the original row.
9. `test_reusing_the_same_operation_id_with_a_different_payload_is_rejected_and_changes_nothing`
10. `test_issuing_a_manual_credit_dispatches_business_wallet_credited_when_available_balance_increases`
11. `test_issuing_a_manual_credit_dispatches_business_wallet_debt_cleared_when_debt_is_cleared`
12. `test_an_idempotent_replay_dispatches_no_additional_events_and_causes_no_balance_change`
13. `test_a_credit_larger_than_existing_debt_dispatches_both_wallet_events_exactly_once_with_the_same_ledger_entry_id` — **new this round — the direct proof of Correction Round 2's own item 4 fix.** Seeds a wallet with existing debt smaller than the credit amount being issued, issues one credit. Asserts `Event::assertDispatchedTimes(BusinessWalletCredited::class, 1)` and `Event::assertDispatchedTimes(BusinessWalletDebtCleared::class, 1)`, and that each dispatched event's own `ledgerEntryId` property equals the single created ledger entry's id — proving both fire together, exactly once each, for the one realistic case that matters (a credit larger than the outstanding debt), not merely individually under separately-fixtured conditions as items 10/11 alone establish.
14. `test_a_malformed_operation_id_is_rejected_with_no_ledger_or_wallet_mutation` — **new this round** (Correction Round 2, item 2): calls `issueManualCredit()` directly with a non-UUID `operationId` string, asserts `InvalidAdminCreditOperationIdException` is thrown, and that no `business_usage_ledger_entries` row exists and the wallet's own balance is unchanged from before the call.
15. `test_differently_cased_representations_of_the_same_uuid_resolve_to_one_idempotent_operation` — **new this round** (Correction Round 2, item 2): calls `issueManualCredit()` twice with an identical payload but an uppercase-rendered `operationId` on the first call and a lowercase-rendered form of the identical UUID on the second. Asserts exactly one `business_usage_ledger_entries` row exists and the second call returns the same row the first call created — proving normalization, not the caller's own casing discipline, is what guarantees idempotency.

### 5.3 New file: `tests/Feature/Usage/AdminUsageBillingSurfaceBoundaryTest.php` — 5 methods

Static source-boundary tests, mirroring `EntitlementCatalogSourceBoundaryTest.php`'s own established grep-the-source-text technique (§0) — enforcing §3's exclusions mechanically, not merely by convention. Unchanged count; method 4's own description is corrected this round (§0's own Correction Round 1 record, item 6).

1. `test_the_admin_usage_billing_controller_never_calls_a_charge_originating_manager_method` — greps `UsageBillingController.php`'s own source text for `initiateTopUp|initiateAutoRecharge|initiateAddonPurchase|quoteAdditionalSlotAgreement`, asserts zero matches.
2. `test_the_admin_usage_billing_controller_never_calls_configure_auto_recharge` — greps for `configureAutoRecharge`, asserts zero matches.
3. `test_issue_manual_credit_never_calls_credit_from_funding` — greps `UsageWalletManager.php`'s own `issueManualCredit()` method body text for `creditFromFunding`, asserts zero matches.
4. `test_no_admin_usage_billing_production_file_contains_a_raw_billing_table_query` — greps every file on this contract's own production allow-list (§6) for `DB::table('business_usage_|DB::table('platform_feature_usage_safety_limits`, asserts zero matches outside the **five** repository Eloquent-implementation files (corrected this round from an incorrect "four" — the allow-list has always contained five: `EloquentBusinessUsageLedgerEntryRepository`, `EloquentBusinessFundingAttemptRepository`, `EloquentPlatformFeatureUsageSafetyLimitRepository`, `EloquentBusinessUsageWalletBillingStatusTransitionRepository`, `EloquentBusinessUsageLimitTransitionRepository`), which are expected/authorized to contain them.
5. `test_the_admin_usage_billing_controller_never_references_payment_instrument_manager` — greps `UsageBillingController.php`'s own source text for `PaymentInstrumentManager`, asserts zero matches.

### 5.4 New file: `tests/Feature/Usage/FundingAttemptRetryAsAdministratorAuthorityTest.php` — 4 methods

**New this round** (§0's own Correction Round 1 record, item 1), manager-layer tests for the corrected `retryFundingAttemptAsAdministrator()` (§2.1.2), mirroring `SlotAgreementAdminAuthorityTest.php`'s own established manager-layer pattern for the sibling M4 admin methods on the same class:

1. `test_a_non_admin_actor_directly_invoking_retry_as_administrator_is_denied_before_any_gateway_call` — asserts the payment-provider gateway is never invoked (via a Mockery expectation/spy bound in place of the fake gateway) when the actor is not a platform administrator.
2. `test_a_blank_reason_retry_is_denied_before_any_gateway_call` — identical gateway-not-invoked proof for a blank/whitespace-only reason.
3. `test_a_successful_admin_retry_records_the_actor_and_the_normalized_reason_on_the_transition` — asserts the resulting `business_funding_attempt_transitions` row has `actor_user_id` equal to the admin and `reason` equal to the trimmed input.
4. `test_existing_non_admin_transition_sources_still_persist_a_null_reason` — confirms a `SyncResponse`/`WebhookEvent`/`ReconciliationJob`-sourced transition (produced through the ordinary, non-admin confirmation path, unmodified by this contract) still persists `reason: null`.

### 5.5 Required new imports, by file

- `AdminUsageBillingControllerTest.php`: `App\Enums\Usage\UsageLedgerEntryType`, `App\Enums\Usage\WalletBillingStatus`, `App\Enums\Usage\FundingAttemptState`, `App\Enums\Usage\FundingAttemptPurpose`, `App\Library\Usage\UsageBillingCheckoutManager`, `App\Library\Usage\UsageWalletManager`, `App\Models\Business`, `App\Repositories\Contracts\BusinessFundingAttemptRepository`, `App\Repositories\Contracts\BusinessUsageLedgerEntryRepository` (new this round, for items 28–32's own direct `marginAggregateForBusiness()` calls), `App\Repositories\Contracts\BusinessUsageWalletRepository`, `App\Repositories\Contracts\PlatformFeatureUsageSafetyLimitRepository`, `Illuminate\Support\Facades\DB`, `Illuminate\Support\Str`, `Tests\Feature\Business\Concerns\CreatesBusinessTestData`, `Tests\TestCase`, plus the same `actingAsAdmin()`/`ensureRequiredAppConfigRowsExist()` private-helper pattern `AdminWorkspaceEntitlementControllerTest.php` already establishes (reused by structure, not by cross-file dependency — each Feature test file owns its own private copies of these helpers, matching this codebase's own existing convention of duplicating rather than sharing test fixtures across files).
- `UsageWalletManagerManualCreditTest.php`: `App\Enums\Usage\UsageLedgerEntryType`, `App\Events\Usage\BusinessWalletCredited`, `App\Events\Usage\BusinessWalletDebtCleared`, `App\Exceptions\Usage\InvalidAdminCreditEntryTypeException`, `App\Exceptions\Usage\InvalidAdminCreditAmountException`, `App\Exceptions\Usage\InvalidAdminCreditReasonException`, `App\Exceptions\Usage\InvalidAdminCreditOperationIdException` (new this round, item 14), `App\Exceptions\Usage\ManualCreditOperationConflictException`, `App\Library\Usage\UsageWalletManager`, `App\Repositories\Contracts\BusinessUsageLedgerEntryRepository`, `Illuminate\Support\Facades\Event`, `Illuminate\Support\Str`, `Tests\Feature\Business\Concerns\CreatesBusinessTestData`, `Tests\TestCase`.
- `AdminUsageBillingSurfaceBoundaryTest.php`: no new imports beyond `Tests\TestCase` — a pure static-file-read test, matching `EntitlementCatalogSourceBoundaryTest.php`'s own minimal import list.
- `FundingAttemptRetryAsAdministratorAuthorityTest.php`: `App\Enums\Usage\FundingAttemptPurpose`, `App\Enums\Usage\FundingAttemptState`, `App\Enums\Usage\TransitionSource`, `App\Library\Usage\Contracts\PaymentProviderGateway`, `App\Library\Usage\FakePaymentProviderGateway`, `App\Library\Usage\UsageBillingCheckoutManager`, `App\Repositories\Contracts\BusinessFundingAttemptRepository`, `App\Repositories\Contracts\BusinessFundingAttemptTransitionRepository`, `Mockery`, `Tests\Feature\Business\Concerns\CreatesBusinessTestData`, `Tests\TestCase`.

---

## 6. Exact production allow-list

| # | Path | Status | Reason |
|---|---|---|---|
| 1 | `app/Http/Controllers/Admin/UsageBillingController.php` | REQUIRED (new file) | The one unified controller, 7 thin actions (§2.1), including the cross-business retry guard (§2.1.2) and the operation-id-generating `show()` action (§2.3, §2.6). |
| 2 | `app/Http/Requests/Admin/IssueManualWalletCreditRequest.php` | REQUIRED (new file) | Validates `operation_id` (uuid), `entry_type`, `amount_micro`, `reason` (§2.3). |
| 3 | `app/Http/Requests/Admin/SuspendBusinessWalletBillingRequest.php` | REQUIRED (new file) | Validates `reason` (§2.1.1). |
| 4 | `app/Http/Requests/Admin/ResumeBusinessWalletBillingRequest.php` | REQUIRED (new file) | Validates `reason` (§2.1.1). |
| 5 | `app/Http/Requests/Admin/RetryFundingAttemptAsAdministratorRequest.php` | REQUIRED (new file) | Validates `reason` (§2.1.1, §2.1.2). |
| 6 | `app/Http/Requests/Admin/SetPlatformFeatureUsageSafetyLimitRequest.php` | REQUIRED (new file) | Validates `feature_key`, `max_monthly_limit_micro`, `reason` (§2.1.1). |
| 7 | `app/Library/Usage/UsageWalletManager.php` | REQUIRED (modified) | One new method, `issueManualCredit()` — further corrected in Correction Round 2 for enum-cast-correct idempotency comparison, operation-id normalization/validation, and event/low-balance wording (§2.3). No existing method changed. |
| 8 | `app/Library/Usage/UsageBillingCheckoutManager.php` | REQUIRED (modified) — **moved from NOT_REQUIRED this round** | `retryFundingAttemptAsAdministrator()` corrected to call `assertPlatformAdministrator()` and validate/persist its own reason; `confirmSucceeded()`, `finalizeFundingAttemptState()`, `recordTransition()` each gain one new trailing optional `?string $reason = null` parameter (§2.1.2). |
| 9 | `app/Exceptions/Usage/InvalidAdminCreditEntryTypeException.php` | REQUIRED (new file) | Guard exception for `issueManualCredit()` (§2.3). |
| 10 | `app/Exceptions/Usage/InvalidAdminCreditAmountException.php` | REQUIRED (new file) | Guard exception for `issueManualCredit()` (§2.3). |
| 11 | `app/Exceptions/Usage/InvalidAdminCreditReasonException.php` | REQUIRED (new file) | Guard exception for `issueManualCredit()` (§2.3). |
| 12 | `app/Exceptions/Usage/ManualCreditOperationConflictException.php` | REQUIRED (new file) | Thrown when an `operation_id` is reused with a different normalized payload (§2.3). |
| 13 | `app/Repositories/Contracts/BusinessUsageLedgerEntryRepository.php` | REQUIRED (modified) | Three new methods: `forBusinessPaginated()`, `findByCorrelationKey()`, `marginAggregateForBusiness()` — the last corrected in Correction Round 2 for exact `DECIMAL(20,0)` precision and internally-consistent rounding, and corrected again by this exceptional correction to return exact integer-micro strings (never PHP `int`) and compute `margin_micro` via `bcmath` (§2.4, §2.5). |
| 14 | `app/Repositories/Eloquent/EloquentBusinessUsageLedgerEntryRepository.php` | REQUIRED (modified) | Implements all three. |
| 15 | `app/Repositories/Contracts/BusinessFundingAttemptRepository.php` | REQUIRED (modified) | One new method: `recentForBusiness()` (§2.4). |
| 16 | `app/Repositories/Eloquent/EloquentBusinessFundingAttemptRepository.php` | REQUIRED (modified) | Implements it. |
| 17 | `app/Repositories/Contracts/PlatformFeatureUsageSafetyLimitRepository.php` | REQUIRED (modified) | One new method: `all()` (§2.4). |
| 18 | `app/Repositories/Eloquent/EloquentPlatformFeatureUsageSafetyLimitRepository.php` | REQUIRED (modified) | Implements it. |
| 19 | `app/Repositories/Contracts/BusinessUsageWalletBillingStatusTransitionRepository.php` | REQUIRED (modified) | One new, bounded method: `recentForBusiness()` (§2.4). |
| 20 | `app/Repositories/Eloquent/EloquentBusinessUsageWalletBillingStatusTransitionRepository.php` | REQUIRED (modified) | Implements it. |
| 21 | `app/Repositories/Contracts/BusinessUsageLimitTransitionRepository.php` | REQUIRED (modified) | Two new, bounded methods: `recentForBusiness()`, `recentPlatformSafetyLimitHistory()` (§2.4). |
| 22 | `app/Repositories/Eloquent/EloquentBusinessUsageLimitTransitionRepository.php` | REQUIRED (modified) | Implements both. |
| 23 | `app/Models/BusinessFundingAttemptTransition.php` | REQUIRED (modified) — **new this round** | Adds `'reason'` to `$fillable` (§2.1.2). |
| 24 | `database/migrations/2026_08_28_120001_add_reason_to_business_funding_attempt_transitions_table.php` | REQUIRED (new file) — **new this round** | Adds nullable `reason` text column to `business_funding_attempt_transitions` (§2.1.2). |
| 25 | `routes/admin.php` | REQUIRED (modified) | 7 new route declarations (§2.2). |
| 26 | `resources/views/admin/usage-billing/businesses/show.blade.php` | REQUIRED (new file) | Per-Business dashboard, including the operation-id hidden field (§2.6). |
| 27 | `resources/views/admin/usage-billing/safety-limits/index.blade.php` | REQUIRED (new file) | Platform-wide safety-limit screen (§2.6). |
| 28 | `resources/views/admin/businesses/show.blade.php` | REQUIRED (modified, 1 line) | One link to the new dashboard (§2.6) — the sole justified integration point into an existing, unrelated view. |
| 29 | `app/Helpers/Helper.php` | REQUIRED (modified) | One new nav group, `menuData()`'s `'admin'` array (§2.7). |
| 30 | `app/Exceptions/Usage/InvalidAdminCreditOperationIdException.php` | REQUIRED (new file) — **new this round** | Thrown when `issueManualCredit()`'s own manager-level `Str::isUuid()` re-validation of the (trimmed, lowercased) `operation_id` fails (§2.3). Appended rather than inserted into the #9–12 exception sequence, to avoid renumbering every already-locked row above it. |

**NOT_REQUIRED, explicitly confirmed:**

| Path | Reason |
|---|---|
| `app/Http/Controllers/Admin/PaymentProviderEventController.php` | Zero lines changed — not duplicated, only linked (§2.7). |
| `app/Http/Controllers/Admin/AdditionalBusinessSlotAgreementController.php` | Zero lines changed — not duplicated, only linked (§2.7). |
| `app/Http/Controllers/Admin/BusinessController.php` | Zero lines changed — Business discovery/detail is reused, not rebuilt (§2.6). |
| `app/Library/Usage/PaymentInstrumentManager.php` | Zero lines changed, never called (§3). |
| `app/Library/Usage/BillingProfileManager.php` | Zero lines changed — billing-contact/payer management is not among this contract's own locked capabilities (opening scope paragraph; not one of the nine guarantees §4 maps). |
| Any migration or schema file other than #24 above | No other schema change — every other column and table this contract reads from or writes to already exists, already shipped, unmodified (§1). |
| `config/permissions.php` | No new permission string is introduced this contract (§2.7, §2.9, locked this round). |
| Any new event, job, or notification class | `issueManualCredit()` dispatches only the two already-shipped `BusinessWalletCredited`/`BusinessWalletDebtCleared` events (§2.3, §9) — no new event/job/notification class is introduced. |
| `app/Http/Controllers/Customer/**` or `routes/customer.php` | No customer-facing surface is touched. |

**Exactly 30 production paths: 14 new files (#1–6, #9–12, #24, #26–27, #30) + 16 modified files (#7–8, #13–23, #25, #28–29).**

## 7. Exact test allow-list

| # | Path | Status | Reason |
|---|---|---|---|
| 1 | `tests/Feature/Usage/AdminUsageBillingControllerTest.php` | REQUIRED (new file) | 32 methods (§5.1), proving guarantees 1–8. |
| 2 | `tests/Feature/Usage/UsageWalletManagerManualCreditTest.php` | REQUIRED (new file) | 15 methods (§5.2), proving guarantee 2's own manager-layer authority, idempotency, and event dispatch. |
| 3 | `tests/Feature/Usage/AdminUsageBillingSurfaceBoundaryTest.php` | REQUIRED (new file) | 5 methods (§5.3), proving guarantee 9. |
| 4 | `tests/Feature/Usage/FundingAttemptRetryAsAdministratorAuthorityTest.php` | REQUIRED (new file) | 4 methods (§5.4), proving guarantee 6's own manager-layer authorization and audit correction. |

**Exactly 4 test paths, 56 total new test methods.** No existing test file is modified — every capability this contract adds is net-new HTTP/manager/repository surface with no prior test coverage to correct (unlike the correction contracts in this sequence, this is new construction, not a fix to an existing, already-tested design).

---

## 8. Regression commands — streamlined, per this contract's own explicit verification policy

- `php artisan test tests/Feature/Usage/AdminUsageBillingControllerTest.php` — the new HTTP-level file; expected 32 methods, all passing.
- `php artisan test tests/Feature/Usage/UsageWalletManagerManualCreditTest.php` — the new manual-credit manager-level file; expected 15 methods, all passing.
- `php artisan test tests/Feature/Usage/AdminUsageBillingSurfaceBoundaryTest.php` — the new boundary file; expected 5 methods, all passing.
- `php artisan test tests/Feature/Usage/FundingAttemptRetryAsAdministratorAuthorityTest.php` — the new funding-attempt-retry authority file; expected 4 methods, all passing.
- `php artisan test tests/Feature/Usage tests/Unit/Usage` — the complete Usage domain suite (covers every already-existing test this contract's changes could plausibly affect: every existing test exercising `confirmSucceeded()`/`finalizeFundingAttemptState()`/`recordTransition()`, since all three gain a new optional parameter this round; `UsageWalletManager`'s own existing test files, since one new method is added to that class; every repository's own existing tests, since methods are added to five of them).
- One complete `php artisan test --stop-on-failure` run (full suite) — catches any unexpected interaction with the Business/Workspace domains this contract's own view/route/nav/migration changes touch.
- `git diff --check`.

Per this contract's own explicit instruction, no separate unrelated domain-suite run (Entitlement, Workspace, Business, Opportunity) is run on its own — the full-suite gate already covers them, and this contract touches nothing in those domains beyond the one linked line in `resources/views/admin/businesses/show.blade.php` and the one nav-array addition in `app/Helpers/Helper.php`, both trivial, additive, and non-behavioral for any existing Business/Workspace test.

---

## 9. Deferred findings — explicitly not absorbed into this contract

**Finding A (carried forward, unchanged, already classified non-blocking).** The Job/Event Dispatch Completion PR #141 audit's own low-balance-notification-after-successful-auto-recharge timing observation remains exactly what every prior contract in this lineage recorded it as: disclosed, contract-faithful, deferred for a separate, future human decision. This contract does not widen scope to include it.

**Finding B (revised this round — no longer a gap).** The initial draft deferred both the ledger-entry producer and the event dispatch for `ManualCredit`/`PromotionalCredit`. This round's own correction (§2.3, §0's Correction Round 1 record item 4) closes the dispatch half using the two already-shipped wallet-balance events (`BusinessWalletCredited`, `BusinessWalletDebtCleared`) — no new event class was needed, since `RFC-005-JOB-EVENT-DISPATCH-COMPLETION-CORRECTION-CONTRACT.md:389`'s own exclusion named "producers or dispatch" broadly, and the events these two ledger-entry types need already exist and already govern every other credit-type entry. `UsageLedgerEntryType::UsageChargeReversal`/`::CorrectionReversal`, also named in that same excluded-scope sentence, remain entirely unproduced by any code path — this contract still does not build a reversal/correction-issuance capability of any kind (§3, §10), since neither RFC §24 nor §30 names it as an admin capability this remediation must close.

---

## 10. Excluded scopes — restated

This contract does not implement, design, or absorb any of the following:

- Provider Refund/Dispute Outcome Handling (remediation #6), RFC-005 §35 Test-Coverage Completion (remediation #7) — both remain untouched, in their existing sequence position after this contract.
- The low-balance-notification timing observation (§9 Finding A) — carried forward, unresolved, non-blocking.
- `UsageChargeReversal`/`CorrectionReversal` ledger-entry production of any kind — no reversal/correction-issuance capability exists in this contract's scope.
- Any change to `creditFromFunding()`, `configureAutoRecharge()`, `initiateTopUp()`, `initiateAutoRecharge()`, `initiateAddonPurchase()`, `quoteAdditionalSlotAgreement()`, or any other charge-originating or auto-recharge-configuring method — confirmed unaffected and unmodified (§1.3, §3, §6). `confirmSucceeded()`/`finalizeFundingAttemptState()`/`recordTransition()` gain one new, trailing, backward-compatible optional parameter each (§2.1.2) but are otherwise unmodified — every existing call site's own behavior is unaffected.
- Any change to `PaymentInstrumentManager`, `BillingProfileManager`, `ProcessPaymentProviderEvent`, `ReconcileProviderPendingState`, or any webhook/provider-verification code path.
- Any change to `PaymentProviderEventController` or `AdditionalBusinessSlotAgreementController` themselves, or their own routes/views/FormRequests — integration is link-only (§2.7).
- A dedicated `config/permissions.php` entry for this domain — locked this round as **not** introduced (§2.7, §2.9, §12).
- M6 conformance/deployment docs; the release tag; Conversations pilot activation; tax/VAT implementation; legacy invoices.
- Any migration or schema change beyond the single, narrowly-scoped `reason` column addition locked in §2.1.2 (production allow-list #24).

Do not reopen Reservation Admission, Funding Provider-Flow, Receipt Boundary, Job/Event Dispatch Completion, Reconciliation-Race, or the Funding Confirmation Concurrency Correction (including its exceptional post-merge implementation correction and both of its own focused review fixes) — none is touched, contradicted, or reinterpreted by anything above.

---

## 11. Confirmations

- **No schema/migration change is required or authorized by this contract beyond the single, narrowly-scoped `reason` column addition locked in §2.1.2** (production allow-list #24). Every other column this contract reads from or writes to (`business_usage_ledger_entries.actor_user_id`/`.reason`/`.correlation_key`, `business_usage_wallet_billing_status_transitions.*`, `business_usage_limit_transitions.*`, `platform_feature_usage_safety_limits.*`) already exists, already shipped, unmodified — confirmed by direct migration read this pass (§0, §1).
- `AI-AUTONOMY-STATE.json` is untouched by this branch.
- M6 remains frozen; still genuinely open/blocked, not merely paused (§0).
- No product, test, config, route, or RFC-source file is touched by this branch. This governance branch changes exactly one file.
- No implementation has occurred. **Correction rounds: 2 of 2 consumed; 0 ordinary rounds remain. This contract has exhausted its ordinary correction-round budget.**
- **This exceptional post-review correction does not consume or alter that budget.** It is recorded in its own top-of-document section, distinct from Correction Round 1 and Correction Round 2, exactly as the Reconciliation-Race Correction's own exceptional post-review correction and the Funding Confirmation Concurrency Correction's own exceptional post-merge implementation correction were each recorded against their own contracts without touching those contracts' own ordinary-round counters.
- Reservation Admission, Funding Provider-Flow, Receipt Boundary, Job/Event Dispatch Completion, Reconciliation-Race, and the Funding Confirmation Concurrency Correction (and its own exceptional post-merge implementation correction and focused review fixes) are not reopened, contradicted, or reinterpreted anywhere above.

---

## 12. Open items — resolved across both correction rounds

**All four items the initial draft flagged as open were resolved and locked by Correction Round 1 (§0's own Correction Round 1 record):**

1. **Permission string vs. `access backend` convention** — locked: continue the established Usage-admin convention, no new permission string (§2.7, §2.9).
2. **Whether `ManualCredit`/`PromotionalCredit` should dispatch a domain event** — locked: yes, using the two already-shipped wallet-balance events, no new event class (§2.3, §9 Finding B).
3. **Margin aggregate grouping granularity** — locked: per-month, per-feature, unchanged from the initial draft's own default, now computed in SQL rather than PHP (§2.5).
4. **Manual-credit double-submission idempotency** — locked: a client-supplied, form-preserved `operation_id`, a deterministic correlation key, and an explicit conflict rule (§2.3).

**Correction Round 2 introduced no new open item.** Every one of its own four fixes (§0's own Correction Round 2 record) was a mechanically-derivable correction — a cast-comparison defect confirmed by direct model read, a defense-in-depth gap matching an already-established codebase convention, an arithmetic-precision/consistency defect confirmed by direct schema and MySQL-decimal-rule analysis, and an imprecise sentence confirmed against the helper method's own body — none required a judgment call this contract deferred rather than made.

**No genuinely open human decision remains.** Every design choice in §2 is now derivable mechanically from RFC-005's own text, the six already-merged remediation contracts' own repeated exclusion language, this contract's own initial-draft evidence, and both correction rounds' own direct re-verification of the exact current-repository code, schema, and MySQL semantics each round's own fixes touch.

---
