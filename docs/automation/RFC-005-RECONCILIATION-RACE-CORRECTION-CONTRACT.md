# RFC-005 ReconcileProviderPendingState Reconciliation-Race Correction Contract

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

This document authorizes drafting one thing: a bounded, independently governed correction that closes the `ReconcileProviderPendingState` stale-in-memory-object reconciliation race — a genuine, pre-existing gap in already-merged M3 code, first discovered during the Job/Event Dispatch Completion correction's own audit, explicitly disclosed there as **not fixed** and named as a **mandatory pre-M6 blocker**, and independently re-confirmed and re-audited from scratch in this contract. Human merge of this contract does **not** itself start implementation — a human must separately, explicitly instruct that implementation begin on the branch named in §0, exactly as every RFC-005 milestone and correction contract before it (most recently the Job/Event Dispatch Completion correction, merged PR [#141](https://github.com/os-creator1/os-ai/pull/141)) has required.

This is **contract-authoring only**. No product code, test code, schema, route, config, or RFC-source file is touched by this branch. Reservation Admission, Funding Provider-Flow, Receipt Boundary, and Job/Event Dispatch Completion are closed corrections and are not reopened, contradicted, or reinterpreted by anything below. The separate, independently-discovered low-balance-notification-after-successful-auto-recharge observation (Job/Event Dispatch Completion's own §2 audit finding) is explicitly **not** absorbed into this correction — it is recorded only as a deferred, non-blocking finding in §9.

---

## 0. Governance

- Drafted on branch `chore/rfc-005-reconciliation-race-correction-contract`, in an isolated linked worktree (`../rfc-005-reconciliation-race-correction-contract-worktree`), based on `origin/main` at `6a0456b5606113eca8f9b3dce12af7d97d0fae38` — the Job/Event Dispatch Completion correction's own merge commit (PR [#141](https://github.com/os-creator1/os-ai/pull/141)), confirmed via `git fetch origin && git rev-parse origin/main` at the start of this contract.
- **Future implementation branch (authorized only after this contract's human merge, and only after a further, separate, explicit human instruction to begin implementation): `agent/rfc-005-reconciliation-race-correction`.**
- Confirmed at drafting: no such branch exists on `origin`; `git status --short` in this worktree is empty; no product/test/config/route/RFC-source file has been touched by this branch at any point.
- Locked:
  - `human_only_merge: true`
  - `maximum_correction_rounds: 2`
  - `correction_rounds_consumed: 0 of 2`
  - `advance_automatically: false`
  - `start_automatically_after_contract_merge: false`
  - `M6 remains frozen` — untouched, not resumed, no M6 document created or modified.
  - `AI-AUTONOMY-STATE.json` is untouched by this branch.
  - No tag is created or moved. No live Stripe/rate/meter/pilot activation occurs.
  - **Implementation requires a separate, explicit human instruction issued after this contract itself is human-merged.**
- This is a new, independently bounded pre-M6 correction contract — not a correction round against M1–M6's own contracts, and not a correction round against any of the four already-merged corrections (Reservation Admission, Funding Provider-Flow, Receipt Boundary, Job/Event Dispatch Completion). Its `maximum_correction_rounds: 2` budget is its own; no counter is borrowed or altered on any other contract.
- This governance branch changes exactly one tracked file: `docs/automation/RFC-005-RECONCILIATION-RACE-CORRECTION-CONTRACT.md`.
- **Sequence position:** this correction is the mandatory pre-M6 correction named by the Job/Event Dispatch Completion contract's own §14, sitting immediately after remediation #4 (Job/Event Dispatch Completion) and before remediation #5 (Admin Usage Billing Surface) in the RFC-005 remediation sequence. Referred to by name, not by a renumbered position, so no existing cross-reference in any of the four already-merged contracts is disturbed.
- **Required reading completed before drafting, independently re-audited fresh in this pass:** `app/Jobs/Usage/ReconcileProviderPendingState.php` (full file, 39 lines); `app/Library/Usage/UsageBillingCheckoutManager.php`'s `confirmAttemptFromReturn()` (lines 477-515), `confirmAttemptFromWebhook()` (lines 526+), `confirmSucceeded()` (lines 628-677), `finalizeAddonPurchaseIfPending()` (lines 698-739), `markFailed()`, `recordTransition()`; `app/Repositories/Contracts/BusinessFundingAttemptRepository.php` (full contract); `app/Library/Usage/UsageWalletManager.php`'s `creditFromFunding()` (ledger `correlation_key` unique-constraint interaction); `database/migrations/2026_08_16_120007_create_business_usage_ledger_entries_table.php` (confirmed `correlation_key` is `unique()`); `tests/Feature/Usage/ReconcileProviderPendingStateTest.php` (full file, all 5 existing methods and all 4 fixture helpers); RFC-005 §21 (webhook claim/lease idempotency), §31 ("effectively exactly-once local accounting effect under at-least-once delivery — unchanged"); the Funding Provider-Flow, Receipt Boundary, and Job/Event Dispatch Completion correction contracts in full. None of the four prior corrections is reopened, contradicted, or referenced as needing amendment by anything below.

---

## 1. The race — mechanically re-established from scratch

**Confirmed by direct, fresh read of `app/Jobs/Usage/ReconcileProviderPendingState.php` (unchanged since the Job/Event Dispatch Completion correction shipped it, 39 lines):**

```php
public function handle(
    BusinessFundingAttemptRepository $attemptRepository,
    UsageBillingCheckoutManager $checkoutManager,
): void {
    $cutoff = now()->subMinutes(self::STUCK_AFTER_MINUTES);

    $stuck = \App\Models\BusinessFundingAttempt::query()
        ->whereIn('state', [FundingAttemptState::ProviderPending->value, FundingAttemptState::RequiresAction->value])
        ->where('updated_at', '<', $cutoff)
        ->whereNotNull('provider_session_or_intent_reference')
        ->get();

    foreach ($stuck as $attempt) {
        $checkoutManager->confirmAttemptFromReturn($attempt);
    }
}
```

**Step-by-step mechanical trace of the race, each step directly re-confirmed against current code:**

1. `$stuck` is loaded **once**, via a single, unlocked `->get()` (line 33). Every `BusinessFundingAttempt` model instance in this collection is a snapshot of the row's state at that exact moment.
2. The `foreach` loop then processes these already-loaded, increasingly stale models one at a time. Each iteration performs a real outbound provider call (`retrieveCheckoutSession()`/`retrievePaymentIntent()`) inside `confirmAttemptFromReturn()`, so meaningful wall-clock time elapses between when `$stuck` was loaded and when any given later member of the collection is actually processed.
3. `confirmAttemptFromReturn()` (`UsageBillingCheckoutManager.php:477-515`) begins with `if ($attempt->state === FundingAttemptState::Succeeded) { ...; return ...; }` (line 479) — **this check reads the in-memory `$attempt` object's own `state` property, never a fresh database read.** If a webhook (via `ProcessPaymentProviderEvent` → `confirmAttemptFromWebhook()`) has already moved the *persisted* row to `Succeeded` (or `Failed`/`Canceled`) sometime after `$stuck` was loaded but before the loop reaches this member, the in-memory object still reads its stale pre-race value — this guard is bypassed, and execution falls through to the provider-verification branch as if the attempt were still genuinely pending.
4. If the provider (correctly) reports success, `confirmSucceeded()` (`UsageBillingCheckoutManager.php:628-677`) runs a **second time** for an attempt already durably confirmed: `$this->attemptRepository->update($attempt, $updateAttributes)` (line 638) is an unconditional update, not a `WHERE state = X`-guarded one; `creditFromFunding()` (line 658, for the `ManualTopUp`/`AutoRecharge` branch — the `AddonPurchase` branch's own `wallet_credit` fulfillment path already self-protects, see §4) is called with **no surrounding `try`/`catch`**.
5. `creditFromFunding()`'s own ledger insert uses `correlation_key = $attempt->local_idempotency_key.':credit'` — identical to the key the first, already-committed confirmation used. `business_usage_ledger_entries.correlation_key` is `unique()` (confirmed: `database/migrations/2026_08_16_120007_create_business_usage_ledger_entries_table.php:39`), so this second insert throws `Illuminate\Database\UniqueConstraintViolationException`. **The RFC-005 §31 "effectively exactly-once local accounting effect under at-least-once delivery" guarantee holds — no double financial credit is ever persisted.**
6. That exception is **not caught anywhere between the ledger insert and `ReconcileProviderPendingState::handle()`'s own `foreach` loop.** It propagates out of `creditFromFunding()`, out of `confirmSucceeded()`, out of `confirmAttemptFromReturn()`, and out of the `foreach` body — terminating the entire scheduled job execution. Every attempt after the raced one in `$stuck`'s own iteration order is left unreconciled for that run, with no record of which attempts were skipped.

**Confirmed this is a genuinely different failure mode than the two `finalizeAddonPurchaseIfPending()`-internal races Correction Round 2 of the Job/Event Dispatch Completion contract already investigated:** that method's own `wallet_credit` fulfillment branch (`UsageBillingCheckoutManager.php:708-720`) already wraps its own `creditFromFunding()` call in `try { ... } catch (UniqueConstraintViolationException $exception) { /* already credited */ }` — a self-contained, already-safe idempotency guard, entirely internal to that one method, requiring no change here. **The unguarded path is specifically `confirmSucceeded()`'s non-`AddonPurchase` branch (`ManualTopUp`/`AutoRecharge`, lines 654-664), reachable only through a caller — `ReconcileProviderPendingState` — that itself holds a stale collection member long enough for a genuine race window to open.** The synchronous, single-attempt customer-return-flow caller of `confirmAttemptFromReturn()` (loaded fresh in the same request, `Http/Controllers` — not touched by this correction) has no comparable staleness window and is unaffected.

---

## 2. Design — the narrowest correction that satisfies all seven stated guarantees

**Locked design: the entire fix lives inside `ReconcileProviderPendingState::handle()` alone.** `UsageBillingCheckoutManager.php` is **not modified** — `confirmAttemptFromReturn()`, `confirmSucceeded()`, `confirmAttemptFromWebhook()`, `markFailed()`, `finalizeAddonPurchaseIfPending()`, every existing webhook-validation/funding-provider-flow/receipt-generation/event-dispatch/financial-invariant behavior already locked by the four prior corrections, remain byte-for-byte unchanged — this is the strongest possible reading of requirement 6, and it is achievable because the race is entirely a property of *this one caller's* staleness window, not of the shared confirmation methods themselves.

**Exact corrected method body (locks the implementation phase's required diff precisely):**

```php
public function handle(
    BusinessFundingAttemptRepository $attemptRepository,
    UsageBillingCheckoutManager $checkoutManager,
): void {
    $cutoff = now()->subMinutes(self::STUCK_AFTER_MINUTES);

    $stuck = \App\Models\BusinessFundingAttempt::query()
        ->whereIn('state', [FundingAttemptState::ProviderPending->value, FundingAttemptState::RequiresAction->value])
        ->where('updated_at', '<', $cutoff)
        ->whereNotNull('provider_session_or_intent_reference')
        ->get();

    foreach ($stuck as $staleAttempt) {
        $attempt = $attemptRepository->findById($staleAttempt->id);

        if ($attempt === null) {
            continue;
        }

        if (! in_array($attempt->state, [FundingAttemptState::ProviderPending, FundingAttemptState::RequiresAction], true)) {
            continue;
        }

        try {
            $checkoutManager->confirmAttemptFromReturn($attempt);
        } catch (UniqueConstraintViolationException) {
            continue;
        }
    }
}
```

One new import required: `use Illuminate\Database\UniqueConstraintViolationException;` (the exact FQCN already imported and used identically in `UsageWalletManager.php` for its own, separate race-detection discipline — reused here verbatim, not reinvented).

**Exact mapping from this design to the seven required guarantees:**

1. **Persisted state, not a stale caller-held model, is authoritative immediately before confirmation.** The `$attemptRepository->findById($staleAttempt->id)` re-fetch, immediately before the `confirmAttemptFromReturn()` call, replaces the initial query's own snapshot with a fresh read taken at the last possible moment. This is a **plain, non-locking read** (`findById()`, not `findForUpdateById()`) — deliberately: acquiring a row lock here would require wrapping the entire confirmation call (including `creditFromFunding()`'s own nested transaction, receipt retrieval, and event dispatch) in an outer transaction, widening this correction's blast radius into `UsageBillingCheckoutManager.php` and its own transaction boundaries, which requirement 6 explicitly forbids. A plain re-fetch closes the overwhelmingly common manifestation of the race (a collection member resolved at any point *before* the loop reaches it, which is the realistic shape of this race given real outbound provider calls between iterations) without touching anything outside this one job.
2. **A concurrently completed/terminal attempt becomes an idempotent no-op.** The re-fetch is followed by an explicit eligibility check against the fresh `state` — `continue` (skip, no confirmation attempt at all) unless the persisted state is still exactly `ProviderPending` or `RequiresAction`. This deliberately covers **more** than `confirmAttemptFromReturn()`'s own existing `Succeeded`-only early return (line 479): a concurrently `Failed`- or `Canceled`-resolved attempt is also correctly skipped here, before ever reaching the checkout manager.
3. **A duplicate-credit race cannot crash the scheduled reconciliation run.** The residual race — a concurrent resolution landing in the narrow window *between* this method's own re-fetch and `confirmAttemptFromReturn()`'s own credit-insert — is caught by the `try`/`catch (UniqueConstraintViolationException)` around the confirmation call itself, mirroring `finalizeAddonPurchaseIfPending()`'s own already-merged, already-proven catch shape exactly (bare catch on the type, no further constraint-name narrowing — the closer, more directly analogous precedent in this same file, chosen over `UsageWalletManager::isDuplicateRace()`'s narrower constraint-name matching, which exists for a different constraint in a different context).
4. **Processing continues to later attempts after this specifically recognized race.** Both the eligibility-check `continue` and the exception-catch `continue` return control to the top of the `foreach` loop, not out of the method — every subsequent member of `$stuck` is still processed in the same run.
5. **Genuine unrelated provider, validation, or database failures are not broadly swallowed.** The catch is scoped to exactly `UniqueConstraintViolationException` — a distinct Laravel exception subtype, never a bare `\Exception`, `\Throwable`, or the broader `QueryException`. Mechanically re-audited: every reachable write inside `confirmAttemptFromReturn()`'s call graph that could plausibly throw this exact exception type is the ledger `correlation_key` insert this correction targets (`finalizeAddonPurchaseIfPending()`'s own `wallet_credit` branch already self-catches its own instance of the identical race internally, so that one never reaches this new outer catch at all). A different failure — `UsageWalletNotFoundException` (wallet deleted/missing), a provider-gateway exception, a genuine unrelated `QueryException` — is a different exception type entirely and propagates out of `handle()` unchanged, exactly as it does today.
6. **Existing webhook validation, funding-provider flow, receipt generation, event dispatch, and financial invariants remain unchanged.** `UsageBillingCheckoutManager.php` has zero lines changed. `BusinessFundingAttemptSucceeded`/`BusinessFundingAttemptFailed`, `BusinessWalletCredited`/`Debited`/`DebtCleared`/`DebtIncurred`, and every other Job/Event Dispatch Completion event still dispatch from exactly the sites that contract locked, exactly as many times, under exactly the same idempotency guards — this correction changes only which *stale-collection* attempts ever reach those call sites, never how those call sites themselves behave.
7. **Deterministic tests reproduce the interleaving.** §5 below locks the exact test design — no real subprocess, no wall-clock timing dependency, no flakiness risk, using this repository's own synchronous event system as the deterministic interleaving hook.

---

## 3. Transaction and locking boundaries — explicit

- **No new transaction is opened by this correction.** `findById()` is a plain, unlocked `SELECT`. `confirmAttemptFromReturn()`'s own downstream transaction boundaries (`creditFromFunding()`'s internal `DB::transaction()`, unchanged) are untouched.
- **No new lock is acquired.** `findForUpdateById()` (already present on `BusinessFundingAttemptRepository`, confirmed unused by this correction) is deliberately **not** used, per §2 item 1's reasoning — introducing a held row lock across the full confirmation call (including an outbound provider HTTP call) would risk lock-hold duration turning a rare race into a availability/contention concern, and would require touching `UsageBillingCheckoutManager.php`'s own transaction shape to be meaningful, which requirement 6 forbids.
- **The exception boundary is exactly one `try`/`catch`, scoped to exactly one call** (`$checkoutManager->confirmAttemptFromReturn($attempt);`), catching exactly `Illuminate\Database\UniqueConstraintViolationException`, with a body of exactly `continue;`. No `finally`, no logging requirement beyond what §5's tests assert, no retry loop invented.
- **`confirmSucceeded()`'s own pre-existing lack of atomicity across its own steps is observed, not altered.** Confirmed by direct read: `confirmSucceeded()` is never wrapped in its own outer `DB::transaction()`; `$this->attemptRepository->update($attempt, ['state' => Succeeded->value, ...])` (line 638) commits independently and immediately, *before* `creditFromFunding()` (line 658) opens its own separate transaction. Consequently, in the exact residual race this correction's `try`/`catch` handles, the attempt's own `state` column will already show `Succeeded` in the database by the time the ledger-insert exception is thrown and caught — a pre-existing characteristic of already-merged M3 code, unrelated to and unmodified by this correction, and accounted for precisely in §5's test 2 design (which asserts the ledger stays at exactly one credit, not that the attempt's state stays `ProviderPending`).

---

## 4. `finalizeAddonPurchaseIfPending()` — re-confirmed unaffected, not touched

Re-audited fresh (`UsageBillingCheckoutManager.php:698-739`): the `AddonPurchase` branch of `confirmSucceeded()` (lines 641-651) calls `finalizeAddonPurchaseIfPending()`, which itself already wraps its own `wallet_credit`-fulfillment `creditFromFunding()` call in a self-contained `try { ... } catch (UniqueConstraintViolationException $exception) { /* already credited */ }` (lines 709-720) — this method is already idempotent against exactly the same class of race this correction addresses, entirely on its own, and requires no change. The `direct_deliverable` fulfillment mode performs no wallet mutation at all and cannot hit this constraint. **This correction's own new outer `try`/`catch` in `ReconcileProviderPendingState::handle()` therefore only ever has an opportunity to catch the exception from the `ManualTopUp`/`AutoRecharge` branch's unguarded `creditFromFunding()` call (line 658) — the one genuinely unprotected path.**

---

## 5. Exact test allow-list, methods, and assertions

**Locked file: `tests/Feature/Usage/ReconcileProviderPendingStateTest.php` (existing, modified — no new test file).** All 5 currently-merged methods (`test_reconciles_a_stuck_provider_pending_attempt_to_succeeded_after_local_accounting_completes`, `test_does_not_reconcile_an_attempt_updated_within_the_stuck_window`, `test_does_not_mutate_a_still_pending_attempt_the_provider_confirms_as_unresolved`, `test_skips_an_attempt_with_no_provider_session_or_intent_reference`, `test_the_reconciliation_query_never_selects_an_already_succeeded_attempt`) are re-confirmed, mechanically, to remain unaffected by this correction and require **zero changes**: each of their fixtures produces an attempt whose state either never changes during the test (methods 1-3) or is excluded at the original query's own `whereIn`/`whereNotNull` level before this correction's own re-fetch loop is ever reached (methods 4-5) — the new eligibility re-check and `try`/`catch` are structurally unreachable-different for all five.

**Exactly 3 new methods, added to the same file, reusing its existing fixture helpers (`businessWithProviderCustomer()`, `registerVerifiedCheckoutOutcome()`, `markStuck()`) verbatim — no new fixture helper is required:**

1. **`test_a_stale_collection_member_already_resolved_before_its_turn_is_skipped_without_reconfirmation`** — the common-case interleaving proof (guarantees 1, 2, 4). Creates three stuck, eligible attempts (`$attemptA`, `$attemptB`, `$attemptC`, in that creation order), each independently registered with a verified checkout outcome via `registerVerifiedCheckoutOutcome()`. Registers `Event::listen(BusinessFundingAttemptSucceeded::class, ...)` **before** running the job, guarded by a local `$triggered` flag so it fires exactly once: the first time any attempt succeeds during this test, the listener directly re-fetches and confirms whichever of `$attemptB`/`$attemptC` has not yet succeeded (via `app(BusinessFundingAttemptRepository::class)->findById(...)` + `app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn(...)`), deterministically simulating "a concurrent webhook resolved this attempt while reconciliation was still processing an earlier member of its own already-loaded collection" — reproducing the exact interleaving named in §1 without real concurrency, using this repository's own synchronous event dispatch as the deterministic hook. Runs `$job->handle(...)` (via the file's existing `runJob()` helper). Asserts: the job call itself does not throw; all three attempts are `Succeeded`; `business_usage_ledger_entries` has **exactly one** row per attempt's own `local_idempotency_key.':credit'` correlation key (proving no duplicate credit for the concurrently-resolved one); the attempt never touched by the simulated race is also correctly `Succeeded` (proving reconciliation genuinely continued past the raced entry to a later, unaffected one).
2. **`test_a_true_duplicate_credit_race_is_caught_and_reconciliation_continues_to_later_attempts`** — the tight, database-constraint-level proof (guarantees 1 [residual], 3, 4). Creates two stuck, eligible attempts (`$attemptA`, `$attemptB`). Before running the job, directly calls `app(UsageWalletManager::class)->creditFromFunding($attemptA->business_id, ..., (int) $attemptA->id, $attemptA->local_idempotency_key.':credit')` — simulating a genuinely concurrent process that has already committed the ledger credit for `$attemptA` (mirroring the exact "crash between credit and completion" scenario `finalizeAddonPurchaseIfPending()`'s own docblock already documents as a recognized possibility in this codebase) while leaving `$attemptA`'s own `state` column still `ProviderPending` — so this correction's own eligibility re-check does **not** skip it, and the race is forced to reach the `try`/`catch` specifically. Registers `$attemptB`'s own verified checkout outcome normally. Runs the job. Asserts: the job call itself does not throw `UniqueConstraintViolationException` (proving guarantee 3 directly); `business_usage_ledger_entries` has exactly one credit row for `$attemptA`'s correlation key (the pre-seeded one, not a duplicate); `$attemptB` is correctly reconciled to `Succeeded` with its own exactly-one credit row (proving guarantee 4 for the tightest form of the race, not only the collection-staleness form).
3. **`test_a_genuinely_unrelated_exception_is_not_caught_and_still_propagates`** — the negative proof (guarantee 5). Creates one stuck, eligible attempt, registers its verified checkout outcome, then deletes that business's `business_usage_wallets` row directly via `DB::table('business_usage_wallets')->where('business_id', $business->id)->delete()` before running the job — forcing `creditFromFunding()` to throw `App\Exceptions\Usage\UsageWalletNotFoundException`, an exception type wholly unrelated to `UniqueConstraintViolationException`. Asserts, via `$this->expectException(UsageWalletNotFoundException::class)`, that calling the job's `handle()` **does** throw — proving the new narrow catch does not silently absorb a genuinely unrelated failure.

**Required new imports for the test file:** `Illuminate\Support\Facades\Queue` is not required (no job dispatch assertions are added); `App\Exceptions\Usage\UsageWalletNotFoundException` is required for test 3. `App\Library\Usage\UsageWalletManager` is already imported. `App\Repositories\Contracts\BusinessFundingAttemptRepository` is already imported. No other new import is needed.

---

## 6. Exact production allow-list

| # | Path | Status | Reason |
|---|---|---|---|
| 1 | `app/Jobs/Usage/ReconcileProviderPendingState.php` | REQUIRED | The entire correction (§2) — one new re-fetch + eligibility check per iteration, one `try`/`catch` around the existing confirmation call, one new `use` import (`Illuminate\Database\UniqueConstraintViolationException`). |

**NOT_REQUIRED, explicitly confirmed:**

| Path | Reason |
|---|---|
| `app/Library/Usage/UsageBillingCheckoutManager.php` | Zero lines changed — the race is entirely a property of the reconciliation caller's staleness window, not of the shared confirmation methods (§2 design lock, requirement 6). |
| `app/Repositories/Contracts/BusinessFundingAttemptRepository.php` / its Eloquent implementation | `findById()` already exists and is reused verbatim; no new repository method is authorized or required. |
| Any migration or schema file | No schema change — `correlation_key`'s existing `UNIQUE` constraint is the entire mechanism this correction relies on. |
| Any event, job, or notification class | No new domain event, job, or notification is introduced; every existing one (Job/Event Dispatch Completion's own seven wallet/reservation events, two funding-terminal events, `SendLowBalanceNotification`, `SendAutoRechargeDisabledNotification`) is unaffected. |
| Any route, controller, or config file | No HTTP/admin surface, no config value, is touched. |

**Exactly 1 production path.**

## 7. Exact test allow-list

| # | Path | Status | Reason |
|---|---|---|---|
| 1 | `tests/Feature/Usage/ReconcileProviderPendingStateTest.php` | REQUIRED (modified existing) | 5 existing methods unchanged, re-confirmed unaffected (§5); exactly 3 new methods added (§5), reusing existing fixture helpers verbatim. |

**Exactly 1 test path.**

---

## 8. Targeted regression commands

- `php artisan test tests/Feature/Usage/ReconcileProviderPendingStateTest.php` — the file itself; expected 8 methods (5 unchanged + 3 new), all passing.
- `php artisan test tests/Feature/Usage tests/Unit/Usage` — the complete Usage domain suite, to confirm zero regression against every event/job/notification the Job/Event Dispatch Completion correction shipped, none of which this correction's own file touches.
- `php artisan test --filter=FundingAttemptTerminalEventDispatchTest` — direct re-confirmation that `BusinessFundingAttemptSucceeded`/`Failed`'s own corrected dispatch ordering (Job/Event Dispatch Completion §7.1) is unaffected, since this correction's new eligibility check sits directly upstream of the same `confirmAttemptFromReturn()` call that test suite exercises.
- `php artisan test --filter=TopUpStateMachineTest` / `--filter=AddonPurchaseTransitionAuditTest` — direct re-confirmation that the synchronous, single-attempt customer-return-flow callers of `confirmAttemptFromReturn()` (unaffected by this correction, per §1's own scoping) remain unchanged.
- The repository's own documented six-command regression gate (RFC-005 M1 contract §14): `tests/Unit/Usage tests/Feature/Usage`; `tests/Unit/Entitlement tests/Feature/Entitlement`; `tests/Unit/Workspace tests/Feature/Workspace`; `tests/Feature/Business`; `tests/Feature/Opportunity`; `php artisan test --stop-on-failure` (full suite).

---

## 9. Deferred, non-blocking finding — explicitly not absorbed into this correction

The Job/Event Dispatch Completion PR #141 audit's own MEDIUM finding — a business whose `reserve()`/`commit()` call simultaneously qualifies for both `EvaluateBusinessAutoRecharge` and `SendLowBalanceNotification` can, under `QUEUE_CONNECTION=sync`, receive a "balance is low" notification email in the same request a successful auto-recharge already resolved the condition — is **not** a reconciliation race, does not involve `ReconcileProviderPendingState` or `UsageBillingCheckoutManager`'s confirmation paths, and is not fixed, absorbed, or redesigned by this correction. It remains exactly what the prior audit recorded it as: a disclosed, contract-faithful (not contract-violating) behavior, deferred for a separate, future human decision. This contract does not widen scope to include it.

---

## 10. Excluded scopes — restated

This correction does not implement, design, or absorb any of the following:

- Any change to `UsageBillingCheckoutManager.php`, `confirmAttemptFromReturn()`, `confirmAttemptFromWebhook()`, `confirmSucceeded()`, `markFailed()`, `finalizeAddonPurchaseIfPending()`, or `recordTransition()` — all confirmed unaffected and unmodified (§2, §4).
- Any new row-level locking mechanism (`findForUpdateById()` remains available but deliberately unused, §3).
- The deferred low-balance-notification finding (§9).
- Admin Usage Billing Surface (remediation #5), Provider Refund/Dispute Outcome Handling (remediation #6), residual §35-only cleanup (remediation #7) — all remain untouched, in their existing sequence position after this correction.
- `AdvanceUsagePeriodBoundaries` — remains OPTIONAL_NON_BLOCKING / NOT IMPLEMENTED per the Job/Event Dispatch Completion contract's own §2, unaffected here.
- M6 conformance/deployment docs; the release tag; Conversations pilot activation; tax/VAT implementation; legacy invoices.
- Any migration or schema change.

Do not reopen Reservation Admission, Funding Provider-Flow, Receipt Boundary, or Job/Event Dispatch Completion — none is touched, contradicted, or reinterpreted anywhere above.

---

## 11. Confirmations

- **No schema/migration change is required or authorized by this correction.** The `correlation_key` `UNIQUE` constraint this correction relies on already exists, already shipped, unmodified.
- `AI-AUTONOMY-STATE.json` is untouched by this branch.
- M6 remains frozen.
- No product, test, config, route, or RFC-source file is touched by this branch. This governance branch changes exactly one file.
- No implementation has occurred. Correction rounds: 0 of 2 consumed.
- Reservation Admission, Funding Provider-Flow, Receipt Boundary, and Job/Event Dispatch Completion are not reopened, contradicted, or reinterpreted anywhere above.

---

## 12. Open items

None found during this audit that block authorizing this correction's own bounded scope. §9's deferred finding is explicitly out of scope by design, not an open item within this correction.

---
