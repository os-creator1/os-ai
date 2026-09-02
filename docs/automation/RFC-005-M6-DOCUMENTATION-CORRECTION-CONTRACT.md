# RFC-005 M6 Documentation Correction Contract

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

This document authorizes drafting one thing: a bounded, documentation-only correction to the two documents already open on draft PR [#164](https://github.com/os-creator1/os-ai/pull/164) (`agent/rfc-005-m6`) — `docs/automation/RFC-005-M6-CONFORMANCE.md` and `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS-DEPLOYMENT.md`. It is authorized by an exhaustive, independently re-verified audit performed in this pass, superseding the narrow spot-check `docs/automation/RFC-005-WEBHOOK-PAYLOAD-RETENTION-CORRECTION-CONTRACT.md` §7 explicitly deferred. **This is not a new remediation against production behavior** — the audit found zero non-documentation defects (§3 below) — and it does not reopen, contradict, or modify any already-merged RFC-005 contract, including the webhook payload retention correction contract (Remediation 10, PR [#165](https://github.com/os-creator1/os-ai/pull/165)) or its test-alignment scope-extension (PR [#166](https://github.com/os-creator1/os-ai/pull/166)).

Human merge of this contract does **not** itself resume implementation — a human must separately, explicitly instruct that correction work resume on the existing branch named in §0.

This is **contract-authoring only**. No product code, test code, config, schema, route, or view file is touched by this branch. **PR #164 remains frozen** — not merged, not closed, not edited, not marked ready, no tag created or moved — by this contract or by anything it authorizes.

---

## 0. Governance

- Drafted on branch `chore/rfc-005-m6-documentation-correction-contract`, in an isolated linked worktree (`../rfc-005-m6-documentation-correction-contract-worktree`), based on `origin/main` at `592438c72635aa25f78f5b117cd22872e5e97318` — the RFC-005 Remediation 10 implementation's own merge commit (PR #167), confirmed via `git fetch origin && git rev-parse origin/main` at the start of this pass.
- **PR #164's exact current state, confirmed this pass:** branch `agent/rfc-005-m6`, head `434721c085e78169d69be9183c489b75dcb32329`, open and DRAFT, unchanged since it was last pushed. `origin/agent/rfc-005-m6` was fetched directly and both documents were read exactly as they exist there — not as previously drafted in any local session's memory.
- **Existing implementation target for the future correction (unchanged, resumed only after this contract's human merge and a further, separate, explicit human instruction): the existing branch `agent/rfc-005-m6` / PR #164 itself.** This contract does not create a new implementation branch or a replacement PR — it authorizes correcting the existing, already-open PR #164 in place, per §6 below. No replacement M6 PR is authorized unless a later, separate, explicit human instruction changes this decision.
- Locked:
  - `human_only_merge: true`
  - `maximum_correction_rounds: 2`
  - `advance_automatically: false`
  - `start_automatically_after_contract_merge: false`
  - **PR #164 remains frozen** — not merged, not closed, not edited, not marked ready, no tag created or moved, no live Stripe/rate/meter/pilot activation occurs.
  - `AI-AUTONOMY-STATE.json` is untouched by this branch (confirmed unchanged since `edf5e88`, `status: "remediation_7_closed_pending_m6_contract_reaudit"`, unaffected by Remediation 10 or this pass).
  - **Resuming correction work requires a separate, explicit human instruction issued after this contract itself is human-merged.**
- This governance branch changes exactly one tracked file: `docs/automation/RFC-005-M6-DOCUMENTATION-CORRECTION-CONTRACT.md`. It does **not** modify `docs/automation/RFC-005-M6-CONFORMANCE.md`, `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS-DEPLOYMENT.md`, `docs/automation/RFC-005-M6-CONTRACT.md`, `docs/automation/RFC-005-WEBHOOK-PAYLOAD-RETENTION-CORRECTION-CONTRACT.md`, or `docs/automation/RFC-005-WEBHOOK-PAYLOAD-RETENTION-TEST-ALIGNMENT-CORRECTION-CONTRACT.md` in any way.
- **This audit was performed by three independent research passes over the current repository** (not by trusting the documents' own prior claims, and not by treating the Remediation 10 contract's own §7 spot-check as sufficient): one exhaustively re-verifying every citation in `RFC-005-M6-CONFORMANCE.md`, one exhaustively re-verifying every citation in the deployment guide, and one independently re-deriving the exact Remediation 10 PR/SHA governance history from raw `git log`/`git show` evidence. **Every contested or uncertain finding from those passes was independently spot-checked a second time in this contract's own drafting** (§1 note on the file-count discrepancy below is the clearest example — one research pass's own recount was itself wrong, and was caught and corrected before being written into this contract).

---

## 1. Exhaustive audit — `docs/automation/RFC-005-M6-CONFORMANCE.md` (as it exists on `origin/agent/rfc-005-m6`)

**Methodology note on trustworthiness of this section:** every finding below was independently confirmed by direct `Read`/`Grep`/`git log`/`git show` evidence against `origin/main` at `592438c`, not inferred from the document's own text or from any prior session's memory. One specific example of why this mattered: an initial research pass claimed the document's test-directory file-count table (line 322) was stale (128→130, 30→31, 37→53, 11→12, 37→39). This was independently re-checked in this pass via `find <dir> -maxdepth 1 -name "*Test.php" | wc -l` for all ten directories — **every original count in the document is exactly correct** (4/128/1/30/4/37/3/11/10/37). The inflated recount was an artifact of a flawed methodology (counting recursively into `Support/`/`Concerns/` helper subdirectories that contain non-test fixture classes, some with "Test" in an unrelated path segment) in the research pass that produced it, not a real defect. **This finding is explicitly withdrawn and must not be carried into any future correction of the document.**

### Confirmed defects (independently verified, safe to correct)

**D1 — literal unresolved placeholder.** Line 324 contains the literal, uncorrected text: `` `git diff --check`: **GIT_DIFF_CHECK_PLACEHOLDER** ``, directly contradicting the same document's own "Release-gate status" section (line 341), which separately claims `git diff --check` is "clean (recorded above)." Confirmed present verbatim in the current PR #164 branch content.

**D2 — six cited test files do not exist anywhere in the repository.** Confirmed via `find tests -iname` returning zero matches for all six, cross-checked against the real file listing of `tests/Feature/Usage/` and `tests/Unit/Usage/`. Real coverage, confirmed by reading actual test file contents (not filename similarity):

| Cited (nonexistent) | Real file(s) / method(s) |
|---|---|
| `WebhookEventDispositionTest.php` + `WebhookExhaustedPayloadPurgeTest.php` | Both are actually **`tests/Feature/Usage/WebhookEventDispositionAndPurgeTest.php`** — disposition and purge combined in one file, 8 methods post-Remediation-10 (4 original + 4 new: `test_unset_retention_never_purges_any_terminal_event`, `test_zero_retention_never_purges`, `test_negative_retention_never_purges`, `test_malformed_retention_never_purges`) |
| `WebhookTerminalOutcomeTest.php` | `PaymentProviderEventSchemaTest.php::test_marking_processed_sets_completed_at_never_processed_at_and_clears_the_lease` / `::test_marking_ignored_sets_completed_at_never_processed_at_and_clears_the_lease` |
| `ProviderIdentityUniquenessTest.php` | `PaymentProviderEventSchemaTest.php::test_provider_and_provider_event_id_is_unique`; `PaymentProviderCustomerSchemaTest.php::test_provider_and_provider_customer_id_is_unique`; `ProviderPaymentIdentifierResolutionTest.php::test_both_provider_reference_columns_enforce_uniqueness` |
| `ProviderObjectResolutionTest.php` | **`tests/Feature/Usage/ProviderPaymentIdentifierResolutionTest.php`** (12 methods) |
| `WebhookMetadataMismatchTest.php` | **`tests/Feature/Usage/WebhookMetadataSpoofMismatchTest.php`** (5 methods) and **`WebhookAmountCurrencyCustomerMismatchTest.php`** (4 methods) |

Additionally uncited anywhere in the document: **`tests/Feature/Usage/PaymentProviderEventRetryReclaimTest.php`** (45 methods) — this is the actual, real test file exercising the fair round-robin retry/reclaim behavior the document's §21 evidence row discusses; it should be added as evidence, not merely used to replace a wrong citation.

**D3 — nonexistent method citation.** Line 112 cites `PaymentProviderEventRetryPolicy::resolvedMaxAttempts()`. Confirmed via direct read of `app/Library/Usage/PaymentProviderEventRetryPolicy.php`: the only public method is **`normalizedMaxAttempts(): int`** (line 24). Confirmed as the actual call site used by `ProcessPaymentProviderEvent.php:64`, `RetryStuckPaymentProviderEvents.php:29`, `PaymentProviderEventController.php:28,39`. A full-repository grep for `resolvedMaxAttempts` returns zero matches anywhere, at any point in git history.

**D4 — misattributed remediation for the fair round-robin retry/reclaim algorithm.** Lines 110/112 attribute this to "§21, M3, R5, R8" — R5 (Reconciliation-Race Correction) is wrong. Confirmed via `git log --follow -- app/Repositories/Eloquent/EloquentPaymentProviderEventRepository.php` (the file implementing `retryable()`): exactly two touching commits exist, the original M3 implementation and the Provider Refund/Dispute Outcome Handling implementation (`920e13b2d806505123cda35937cc27adda4d586f`, PR #153) — R5 never touches this file. `git show --stat` on R5's own merge commit (`ccee46b6197dfd70980091cae97ecb283a52aed7`) confirms it touched only `app/Jobs/Usage/ReconcileProviderPendingState.php` and its test. `docs/automation/RFC-005-RECONCILIATION-RACE-CORRECTION-CONTRACT.md`, read in full, confirms it concerns exclusively a stale-in-memory-object race in `ReconcileProviderPendingState`/`UsageBillingCheckoutManager::confirmSucceeded()` — unrelated to webhook claim/lease/retry/reclaim. The real source is confirmed two ways: the code's own comments (`EloquentPaymentProviderEventRepository.php:15-19,183-185`; `RetryStuckPaymentProviderEvents.php:10-17`) attribute the design explicitly to *"RFC-005 Remediation #6 §19"*, and `docs/automation/RFC-005-PROVIDER-REFUND-DISPUTE-OUTCOME-HANDLING-CONTRACT.md`'s own title is literally *"RFC-005 Remediation #6 — Provider Refund/Dispute Outcome Handling Contract"* (its own self-declared numbering — mechanically R8 in the ten-remediation sequence). **Correct attribution: R8 (Provider Refund/Dispute Outcome Handling) only, never R5.**

**D5 — margin-aggregate evidence cites the wrong files entirely.** Line 151 cites `app/Library/Usage/UsageBillingPresenter.php`, `UsageBillingCheckoutManager.php`, `UsageWalletManager.php` as bcmath margin-computation evidence. A grep for `"margin"` across `UsageBillingCheckoutManager.php`/`UsageWalletManager.php` returns zero matches — neither file contains the word "margin" anywhere. The real computation, confirmed by direct read: **`app/Repositories/Eloquent/EloquentBusinessUsageLedgerEntryRepository.php::marginAggregateForBusiness()`** (line 103), computing `$row->margin_micro = bcsub($row->retail_revenue_micro, $row->provider_cost_micro, 0)` (line 118) against raw, uncast `DECIMAL` query-builder results — **only `bcsub()` is used, never `bcadd`/`bcmul`**. Display formatting is **`app/Http/Controllers/Admin/UsageBillingController::formatMicroDisplay()`** (private method, line 265), called at lines 73–75. (`UsageBillingPresenter.php` does use `bcadd`/`bcsub`, but for the unrelated customer-dashboard spend-cap-remaining calculation — not admin margin reporting.) Real test coverage: `AdminUsageBillingControllerTest.php`, `UsageBillingDashboardViewDataTest.php`.

**D6 — nonexistent method citation in the receipt-issuance evidence.** Line 124 cites `UsageWalletManager::ensureFundingReceipt()`. Confirmed by direct read: `UsageWalletManager.php` has only **`findFundingReceipt()`** (line 1052). `ensureFundingReceipt()` exists exclusively on **`UsageBillingCheckoutManager.php`** (line 1162), called from `app/Jobs/Usage/SendReceiptNotification.php:63`.

**D7–D11 — five stale test-method-count parentheticals, independently re-verified via `grep -c "public function test_" <file>` against the actual current file, not against any prior session's estimate:**

| File cited | Document claims | Actual (re-confirmed) |
|---|---|---|
| `UsageMeterBackfillPreflightTest.php` | 8 methods | **7 methods** |
| `UsageWalletManagerFeatureLimitTest.php` | 16 methods | **17 methods** |
| `UsageWalletManagerSafetyLimitTest.php` | 8 methods | **7 methods** |
| `TopUpStateMachineTest.php` | 18 methods | **19 methods** |
| `NewBusinessPayerAssignmentInitializationTest.php` | 6 methods | **5 methods** |

**D12 — manual-regression counts (lines 301–318: 919/317/779/251/1117 per-domain, 3514 full-suite) are stale.** Remediation 10 added 4 new test methods to `WebhookEventDispositionAndPurgeTest.php` (part of the Usage domain), which post-dates this document's own recorded regression run. The Usage-domain count (previously 919) and the full-suite count (previously 3514) are provably at least 4 short. **These must not be copied forward under any circumstance — the future correction must re-run all six locked commands from scratch and record only the fresh, actual output (§6 below).**

**D13 — governance-history table and "nine remediations" language throughout is now incomplete.** The document's governance-history table (lines 20–37, independently re-confirmed accurate for all nine existing rows — every PR number and merge SHA checked directly, all correct) needs a tenth row. §2/§3 below give the exact, independently-re-derived facts for that row.

**D14 — the document's own narrative does not account for its own history.** The document as currently drafted presents M6 as a clean, single, uninterrupted conformance pass. In fact, this M6 documentation pass's own first draft (the current PR #164 content) is what a later Codex review of that draft found the Remediation 10 defect in — invoking the M6 contract's own gap rule, freezing M6, and requiring a separately governed remediation (contracted, authorized, implemented, merged) before M6 could resume. The corrected document must state this plainly as part of its own history, not silently omit it — **no statement in the corrected documents may imply M6 was technically clean on first pass.** This is itself part of what M6's own conformance-and-gap-rule discipline is supposed to produce, and belongs in the record, not smoothed over.

### Confirmed PASS (high-value spot checks, independently re-verified, not exhaustively listed since this is already the largest fraction of the document)

Governance-history table (all 9 rows/PR numbers/SHAs), full 49-file migration inventory and its group-by-group breakdown, the six-composite-FK claim, `AppServiceProvider.php:168`, `routes/admin.php:722`, `Kernel.php` import/schedule lines, `stripe/stripe-php v7.128.0`, `StripePaymentProviderGateway::__construct()` validation logic, the 15-case `PlatformFeature` enum, `BusinessUsageLedgerEntryRepository`'s append-only contract shape, all R8 migration/column claims, the exact §37 RFC quotation, `AI-AUTONOMY-STATE.json`'s untouched status, and the remaining ~25 test-file existence citations not listed as defects above — all independently re-confirmed accurate.

---

## 2. Exhaustive audit — `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS-DEPLOYMENT.md` (as it exists on `origin/agent/rfc-005-m6`)

### Confirmed defects (independently verified, safe to correct)

**E1 — §9 wrongly claims Stripe signature verification occurs in two places.** Current text: *"Signature verification is performed inside `StripeWebhookController@handle` / `ProcessPaymentProviderEvent`."* Confirmed by direct read of both files: `StripeWebhookController::handle()` (`app/Http/Controllers/StripeWebhookController.php:32-45`) calls `$this->gateway->verifyWebhookSignature($rawBody, $signatureHeader, ...)` before any row is persisted, returning HTTP 400 with zero side effects on failure. `app/Jobs/Usage/ProcessPaymentProviderEvent.php` (870 lines, read in full) contains **no call to `verifyWebhookSignature()` anywhere** — it operates entirely on the already-persisted, already-verified row. **Correct statement: signature verification happens exactly once, in `StripeWebhookController@handle`, delegated to `StripePaymentProviderGateway::verifyWebhookSignature()`. `ProcessPaymentProviderEvent` performs no signature verification of any kind.**

**E2 — §10 cites the same nonexistent method as D3 above.** `PaymentProviderEventRetryPolicy::resolvedMaxAttempts()` → real: **`normalizedMaxAttempts()`**.

**E3 — §10 attributes fair round-robin retry/reclaim to "the Reconciliation-Race Correction remediation."** Same misattribution as D4 above, independently re-confirmed against this document's own text by a separate research pass. **Correct attribution: RFC-005 Remediation #6 (Provider Refund/Dispute Outcome Handling), §19 — mechanically R8, never Reconciliation-Race Correction (R5).**

**E4 — §3/§10's retention-unset-never-purges claim is now true, but its own history is undisclosed, and the document reads as though this was always the case.** Current text (§3): *"No retention default is invented..."*; (§10): *"This job never purges anything if `USAGE_BILLING_WEBHOOK_RETENTION_DAYS` is left unset."* **This claim is TRUE on current `origin/main`** — confirmed by direct read of the current `app/Jobs/Usage/PurgeExpiredWebhookPayloads.php` (52 lines): `resolvedRetentionDays(): ?int` (line 39) rejects (returns `null`) anything that is not `is_int()` and not (`is_string()` and `ctype_digit()`), then rejects any resulting value `<= 0`; `handle()` returns immediately before any repository call whenever this resolves to `null`. **But the claim was FALSE in the code that existed when this document was originally drafted** — confirmed via `git show ce74b29` (Remediation 10's implementation commit): the pre-fix code was `$retentionDays = (int) config(...)`, and since an unset env value resolves to PHP `null`, `(int) null === 0`, and `purgeable(0)` computed a cutoff of "now," causing **every already-terminal webhook payload to be purged on the very next hourly run** — the exact opposite of what this document claims. **The corrected document must (a) cite the exact current mechanism (`resolvedRetentionDays()`'s exact validation rule) rather than asserting the behavior as self-evident, and (b) explicitly disclose that this fail-closed behavior is the result of RFC-005 Remediation 10 (Webhook Payload Retention Correction, PR #167), which fixed a real, discovered production defect where unset retention caused immediate purge instead of disabling purging — not a behavior the deployment guide's own first draft ever verified was actually true.**

**E5 — minor off-by-one line-number citation.** §8 cites "the same `else` branch (line 76)" in `app/Console/Kernel.php`. Confirmed: the `else {` is actually at **line 77**; line 76 is `$schedule->command('demo:update')->daily();`, inside the `if` branch. Trivial, but must be corrected for citation accuracy.

### Confirmed PASS (high-value spot checks, independently re-verified)

`config/services.php` stripe block (exact match, lines 39–50), `StripePaymentProviderGateway`'s five fail-closed conditions, `config/usage_billing.php` (exact match), `MAX_ATTEMPTS_CEILING=20` clamp, `stripe/stripe-php v7.128.0`, `EntitlementManager::decide()`'s final-step gateway call, `AppServiceProvider.php:168` binding, `InitializeBusinessUsageProfile`'s full described behavior, the full 49-migration inventory and grouping, all backfill migration filenames and the `UsageMeterBackfillIncompleteException` preflight, all 7 scheduled-job/cadence pairs, the webhook route definition and CSRF exemption entry, the `encrypted` cast on `payment_provider_events.payload_encrypted`, `payment_provider_customers`/`business_payment_instruments` schema/FK claims, the (re-confirmed correct, per §1's methodology note) test-directory file counts, 35+ `restrictOnDelete()` FK confirmations, the legacy `Plan`/`Subscription`/`EnsureUserIsAdministrator` isolation claims (confirmed via `git log --oneline` showing zero RFC-005 commits touching any of the three), the Conversations pilot command's existence, and the confirmed absence of any `rfc-005-*` tag anywhere.

**Non-documentation defect check performed specifically for this document:** independently re-traced the Reconciliation-Race Correction contract's own disclosed §9 Finding B (`UsageBillingTopUpController::confirmFromReturn()` potentially surfacing an uncaught exception under a customer-browser-return-vs-webhook race) against current code. Confirmed the exception is now caught inside `UsageBillingCheckoutManager::confirmSucceeded()` itself (the shared, always-row-locked idempotent finalizer the Funding Confirmation Concurrency Correction contract introduced), which never propagates to the controller. **This race is closed at the manager layer — no live, undisclosed defect found here.**

---

## 3. Remediation 10 — exact governance-history facts, independently re-derived from raw `git log`/`git show` evidence, not from any document's own prior text

Confirmed via `git log --oneline --all`, `git show <sha> --no-patch --format='%H %P %s'`, and direct diff/stat inspection — every figure below is a directly observed commit-graph fact, not an inference:

| Item | PR | Merge commit (full SHA) | Content commit (full SHA) |
|---|---|---|---|
| Remediation 10 **contract**'s own merge | **#165** | `e658ab2c52fde809b757da7d7df829079eee6183` | `80efbb65a8442f06402aa7ff2524e1f0c2c7f54d` ("RFC-005 Webhook Payload Retention Correction Contract (Remediation 10)") |
| Test-alignment **scope-extension contract**'s own merge | **#166** | `9d08c9bf6f20ce84c7510b25239efdfdeebf34af` | `1fd0b0c929b7ec75428d09a31626c22a31f33004` ("RFC-005 Webhook Payload Retention — Test Alignment Correction Contract") |
| **Implementation** (fulfills both contracts in a single commit — see note below) | **#167** | `592438c72635aa25f78f5b117cd22872e5e97318` | `ce74b292d93d80cd562fc73228ff322792eacba1` ("RFC-005 Remediation 10: fix-closed webhook payload retention") |

**No dedicated authorization PR exists for Remediation 10** — confirmed by full `git log --all` search finding none — matching the convention of Remediations 1 through 7 (per `RFC-005-M6-CONTRACT.md`'s own governance-history table), not the dedicated-authorization-PR convention of Remediations 8/9. Both Remediation 10 contracts' own §0/§1 state this explicitly (manual human authorization, no dedicated PR required).

**No dedicated closure PR exists for Remediation 10 as of this audit** — confirmed by full `git log --all` search finding none, and `origin/main` confirmed still at exactly `592438c` (no commit beyond PR #167's merge). This is an accurate, current, "not yet closed" state — the future corrected M6 documents must record it as such, not invent a closure PR number.

**Important structural note the corrected documents must preserve:** there is no separate "test-alignment implementation" PR — PR #167's single commit (`ce74b29`) implements *both* the original Remediation 10 production fix (`app/Jobs/Usage/PurgeExpiredWebhookPayloads.php`, adding `resolvedRetentionDays(): ?int` and its 4 new regression tests in `WebhookEventDispositionAndPurgeTest.php`) *and* the one-line test-alignment correction to `PaymentProviderEventDurableAuditTest.php` that PR #166's contract separately authorized. This deviates from the strict one-remediation-one-contract-PR shape of Remediations 1–7 and should be called out explicitly in the corrected governance-history table, exactly as `RFC-005-M6-CONTRACT.md`'s own "Corrected classification" paragraph already does for Remediations 8/9's own irregularities — **the distinction between the original production-defect scope and the narrow test-alignment scope-extension must remain visible in the corrected documents' own history, not collapsed into an undifferentiated single line.**

**Confirmed current implementation shape**, for direct citation by the corrected documents:

- `app/Jobs/Usage/PurgeExpiredWebhookPayloads.php` (52 lines): `handle()` calls `resolvedRetentionDays()` first and returns immediately (zero repository calls) if `null`; `private function resolvedRetentionDays(): ?int` (line 39) mirrors `Kernel::opportunitySnoozeSweepCronMinutes()`'s validation idiom — accepts a positive int or a digit-only string representing one, rejects everything else (unset, blank, non-digit string, zero, negative) to `null`.
- `tests/Feature/Usage/WebhookEventDispositionAndPurgeTest.php`: exactly 8 methods (4 pre-existing, unmodified; 4 new — `test_unset_retention_never_purges_any_terminal_event`, `test_zero_retention_never_purges`, `test_negative_retention_never_purges`, `test_malformed_retention_never_purges`).
- `tests/Feature/Usage/PaymentProviderEventDurableAuditTest.php::test_normalized_attribution_survives_payload_purge()`: exactly one new line, `config(['usage_billing.webhook_event.retention_days' => 30]);`, immediately before the existing `PurgeExpiredWebhookPayloads::handle()` call — no other line of this file touched.

**No non-documentation defect was found by either audit pass (§1, §2). The gap rule (§8 below) is not triggered by this contract.**

---

## 4. Exact corrections required — `docs/automation/RFC-005-M6-CONFORMANCE.md`

The future correction, once resumed (§6), must apply exactly the following, and no other behavioral rewrite:

1. Replace the literal `GIT_DIFF_CHECK_PLACEHOLDER` (D1) with the actual `git diff --check` output from the fresh verification pass (§6).
2. Replace all six nonexistent test-file citations (D2) with the real files/methods listed in §1's D2 table, and add `PaymentProviderEventRetryReclaimTest.php` as additional cited evidence for the fair-retry-reclaim behavior.
3. Correct `resolvedMaxAttempts()` → `normalizedMaxAttempts()` (D3), everywhere it appears.
4. Correct the fair-round-robin-retry-reclaim attribution to R8 only, removing R5 (D4), everywhere it appears.
5. Correct the margin-aggregate evidence citation to `EloquentBusinessUsageLedgerEntryRepository::marginAggregateForBusiness()` / `UsageBillingController::formatMicroDisplay()` (D5).
6. Correct `UsageWalletManager::ensureFundingReceipt()` → `UsageBillingCheckoutManager::ensureFundingReceipt()` (and confirm `UsageWalletManager::findFundingReceipt()` remains correctly cited) (D6).
7. Correct the five stale test-method-count parentheticals (D7–D11) to their confirmed real values (7, 17, 7, 19, 5 respectively) — **re-verify each fresh at correction time via `grep -c "public function test_" <file>` rather than copying the values in this contract**, since further test changes could occur between this contract's merge and implementation resuming.
8. Replace the entire "Manual regression — actual results" section (D12) with freshly re-run, actual output from all six locked commands (§6) — never the stale 919/317/779/251/1117/3514 figures.
9. Add a tenth governance-history-table row for Remediation 10, using exactly the facts in §3 above (re-verified fresh at correction time, not copied blindly, in case anything changes before implementation resumes — e.g., if a closure PR has since been opened) — and correct every "nine remediations"/"7 of 9" fractional-count statement throughout the document to reflect ten remediations (8 of 10 used no dedicated authorization PR, matching the corrected fraction).
10. Add explicit narrative (D14) stating that this M6 documentation pass's own first draft is what a Codex review found the Remediation 10 defect in, that M6 was frozen as a result, and that the defect was separately governed, contracted, authorized, and merged before M6 correction resumed — **stated as part of the document's own history, never omitted or smoothed over.**

## 5. Exact corrections required — `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS-DEPLOYMENT.md`

1. Correct §9's signature-verification claim to name only `StripeWebhookController@handle` (E1).
2. Correct `resolvedMaxAttempts()` → `normalizedMaxAttempts()` (E2).
3. Correct the fair-round-robin-retry-reclaim attribution to R8 only (E3).
4. Rewrite the retention-unset-never-purges passage (§3/§10) to cite `resolvedRetentionDays(): ?int`'s exact validation rule and explicitly disclose Remediation 10's own history — that this behavior was fixed, not always true, and what the pre-fix defect actually did (E4). Add a new subsection (or extend §16/§20's existing separation/out-of-scope framing) naming Remediation 10 explicitly, mirroring how the document already names every other remediation's contribution.
5. Correct the off-by-one line-number citation in §8 (line 76 → line 77) (E5).

No other line of either document may be rewritten beyond what is required to apply the above corrections — this is a citation-and-attribution accuracy pass, not a rewrite, restructuring, or re-scoping of either document's own content, tone, or conclusions. The technical-conformance conclusion itself (no product/schema/security/accounting/provider/deployment/acceptance gap found) is **not** altered by this correction — the audit performed in this contract (§1, §2, §3) independently confirms that conclusion remains accurate on current `origin/main`, with every underlying architectural PASS row in `RFC-005-M6-CONFORMANCE.md` re-confirmed correct except for the specific citation defects listed above.

---

## 6. Future implementation procedure — locked, for the record

This contract does not perform implementation. Once merged and correction work is separately, explicitly resumed:

1. Work on the **existing** `agent/rfc-005-m6` branch / PR #164 — no new branch, no replacement PR.
2. `git fetch origin`.
3. **Bring the existing M6 branch forward to the then-current `origin/main` using a normal, non-forced merge** (`git merge origin/main` from within the `agent/rfc-005-m6` branch/worktree) — **no rebase, no force push.** Preserve the existing M6 documentation commit and its history exactly. Resolve conflicts only if genuinely necessary (unlikely, since Remediation 10 never touched either M6 document). PR #164 must remain the same PR — its number, its branch name, and its commit history up to this point are not replaced.
4. After that merge, relative to the then-current `origin/main`, the only intended PR #164 changed paths must remain exactly:
   - `docs/automation/RFC-005-M6-CONFORMANCE.md`
   - `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS-DEPLOYMENT.md`
5. Apply exactly the corrections in §4 and §5 above to those two documents. **Re-verify every citation fresh at correction time** — do not copy any specific numeric value, file existence claim, or method name from this contract's own §1–§5 without re-confirming it against the exact commit being corrected, since additional time may have passed and additional commits may have landed on `origin/main` since this contract was drafted.
6. Re-run the complete M6 regression evidence from scratch. If `php` is not on `PATH`, use the explicit Windows path: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`.

   ```
   php artisan test tests/Unit/Usage tests/Feature/Usage
   php artisan test tests/Unit/Entitlement tests/Feature/Entitlement
   php artisan test tests/Unit/Workspace tests/Feature/Workspace
   php artisan test tests/Unit/Business tests/Feature/Business
   php artisan test tests/Unit/Opportunity tests/Feature/Opportunity
   php artisan test --stop-on-failure
   git diff --check
   ```

   **Record only actual, fresh, observed test totals and assertion counts — do not hard-code any count from this contract or from any prior document.** Every command must discover a positive test count and finish with zero failures.
7. Re-audit the corrected documents after editing (a second, self-contained citation-integrity pass, not a rubber stamp) to confirm:
   - every cited path exists
   - every cited class/method exists
   - every cited test exists
   - every remediation/PR attribution matches actual current history
   - no placeholder text remains anywhere in either document
   - no obsolete pre-Remediation-10 count, fraction, or governance-history state remains
   - no statement anywhere implies M6 was technically clean before the Remediation 10 defect was discovered and closed
8. Push the updated `agent/rfc-005-m6` branch normally — **no force push.**
9. PR #164 remains **Draft** after the correction. Do not mark it ready for review. Do not merge it. Do not tag anything. Do not deploy anything. Stop for human review.

**Explicit stop condition:** if this future correction pass discovers any additional citation defect beyond what §4/§5 above name, correcting it is within scope (this is a citation-accuracy pass, and the goal is a fully accurate document, not merely the specific items already found). **But if it discovers ANY new product, schema, security, accounting, provider, deployment, or required-test defect — anything beyond a documentation-citation problem — it must STOP immediately, must not attempt to fix it, and must not absorb it into this documentation-only correction.** Record it precisely (exact file, exact expected-vs-actual behavior, exact evidence) and report it as a blocker requiring a separate, newly-contracted, human-governed remediation — following the identical discipline that produced Remediation 10 itself.

---

## 7. Exact future two-file allow-list

| # | Path | Status | Reason |
|---|---|---|---|
| 1 | `docs/automation/RFC-005-M6-CONFORMANCE.md` | Authorized correction (§4) | Existing PR #164 document, corrected in place per the exact items in §4. |
| 2 | `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS-DEPLOYMENT.md` | Authorized correction (§5) | Existing PR #164 document, corrected in place per the exact items in §5. |

**Exactly 2 paths. No product, test, config, schema, route, or view path is authorized by this contract.** `docs/automation/RFC-005-M6-CONTRACT.md`, `docs/automation/RFC-005-WEBHOOK-PAYLOAD-RETENTION-CORRECTION-CONTRACT.md`, `docs/automation/RFC-005-WEBHOOK-PAYLOAD-RETENTION-TEST-ALIGNMENT-CORRECTION-CONTRACT.md`, and `docs/automation/AI-AUTONOMY-STATE.json` are explicitly **not** authorized for modification by this contract or by the correction it authorizes.

---

## 8. Gap rule

If the exhaustive audit performed in this contract (§1, §2, §3) had discovered any genuine, current, non-documentation product, schema, security, accounting, provider, deployment, or required-test defect, this contract would record it here as a STOP condition rather than proceed to author a documentation-only correction. **No such defect was found.** Both independent audit passes explicitly checked for one (§1's methodology note on withdrawing its own false-positive finding; §2's dedicated non-documentation-defect check on the Reconciliation-Race Correction's own disclosed Finding B) and confirmed none exists. This gap rule remains active for the future correction pass itself (§6's own explicit stop condition) — if that pass discovers something this contract's own audit missed, it must stop and report rather than silently fix it.

---

## 9. Excluded scopes — restated

This correction does not implement, design, or absorb any of the following:

- Any change to `docs/automation/RFC-005-M6-CONTRACT.md`, `docs/automation/RFC-005-WEBHOOK-PAYLOAD-RETENTION-CORRECTION-CONTRACT.md`, or `docs/automation/RFC-005-WEBHOOK-PAYLOAD-RETENTION-TEST-ALIGNMENT-CORRECTION-CONTRACT.md` — none is reopened, contradicted, or reinterpreted.
- Any change to `app/`, `database/`, `config/`, `routes/`, `resources/`, `tests/`, or any workflow/CI file.
- Any merge, close, "ready for review" mark, tag, or deployment action against PR #164.
- Any RFC-005 §39 open commercial/product decision, the tax/VAT item, or Conversations pilot activation.
- Any resolution of the M6 post-merge exact-tag-candidate gate or the tag itself — those remain gated by `RFC-005-M6-CONTRACT.md` §8/§9, entirely unaffected by this contract.
- A replacement M6 PR — the existing PR #164 is corrected in place; no new PR is authorized unless a later, separate, explicit human instruction changes this decision.
- Any change to `docs/automation/AI-AUTONOMY-STATE.json`.

---

## 10. Confirmations

- **No schema/migration/config/product/test change is required or authorized by this correction.**
- `AI-AUTONOMY-STATE.json` is untouched by this branch.
- **PR #164 remains frozen** — unmerged, unclosed, unedited, not marked ready, no tag, no deployment.
- No product, test, config, or route file is touched by this branch. This governance branch changes exactly one file.
- No implementation has occurred under this contract. This is its first draft: `0 of 2` ordinary correction rounds consumed.
- `docs/automation/RFC-005-M6-CONTRACT.md`, `docs/automation/RFC-005-WEBHOOK-PAYLOAD-RETENTION-CORRECTION-CONTRACT.md`, and `docs/automation/RFC-005-WEBHOOK-PAYLOAD-RETENTION-TEST-ALIGNMENT-CORRECTION-CONTRACT.md` are not reopened, contradicted, or reinterpreted anywhere above.
- Zero non-documentation defects were found by this pass's exhaustive audit (§1, §2, §3, §8).

---

## 11. Open items

**No open item blocks authorizing this correction's own bounded scope** (§4/§5/§7).

**Whether a Remediation 10 closure PR exists by the time correction work resumes is not knowable from this contract's own drafting pass** — §6 step 5 requires the future correction to re-verify this fresh, not assume the "not yet closed" state recorded in §3 remains accurate.

**Resuming correction work is not authorized by this contract's own merge alone** (§0, §6) — it requires a further, separate, explicit human instruction, exactly matching Remediation 10's own contract's authorization model.
