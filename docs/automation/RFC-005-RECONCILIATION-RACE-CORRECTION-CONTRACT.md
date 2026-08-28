# RFC-005 ReconcileProviderPendingState Reconciliation-Race Correction Contract

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

This document authorizes drafting one thing: a bounded, independently governed correction that closes the `ReconcileProviderPendingState` stale-in-memory-object reconciliation race — a genuine, pre-existing gap in already-merged M3 code, first discovered during the Job/Event Dispatch Completion correction's own audit, explicitly disclosed there as **not fixed** and named as a **mandatory pre-M6 blocker**, and independently re-confirmed and re-audited from scratch in this contract. Human merge of this contract does **not** itself start implementation — a human must separately, explicitly instruct that implementation begin on the branch named in §0, exactly as every RFC-005 milestone and correction contract before it (most recently the Job/Event Dispatch Completion correction, merged PR [#141](https://github.com/os-creator1/os-ai/pull/141)) has required.

This is **contract-authoring only**. No product code, test code, schema, route, config, or RFC-source file is touched by this branch. Reservation Admission, Funding Provider-Flow, Receipt Boundary, and Job/Event Dispatch Completion are closed corrections and are not reopened, contradicted, or reinterpreted by anything below. The separate, independently-discovered low-balance-notification-after-successful-auto-recharge observation (Job/Event Dispatch Completion's own §2 audit finding) is explicitly **not** absorbed into this correction — it is recorded only as a deferred finding in §9.

---

## Correction Round 1 record

Independent pre-merge review of the initial draft (head `992beb062370caff9359279d53f808524ca953f5`) found six defects, all resolved below by direct mechanical re-audit of `UsageBillingCheckoutManager.php`, `EloquentBusinessFundingAttemptRepository.php`, `app/Http/Controllers/Customer/Business/UsageBillingTopUpController.php`, and `app/Jobs/Usage/ProcessPaymentProviderEvent.php`. **1 of 2 ordinary correction rounds consumed by this round; 1 ordinary round remains.**

Exact issues resolved this round:

1. **Overstated "entirely a caller-staleness problem" framing.** The initial draft's §1/§2 implied the race was fully understood and fully closed as a property of `ReconcileProviderPendingState` alone. Re-audited: this is true only for the *common* manifestation (a collection loaded once, iterated with real elapsed time). A narrower, residual, genuinely-simultaneous race between any two independently-fresh-loading callers of `confirmSucceeded()` is a separate, pre-existing gap — corrected to a two-tier explanation in §1/§2.
2. **Residual collision's committed side effects were not documented.** Direct trace of `confirmSucceeded()` (`UsageBillingCheckoutManager.php:628-677`) shows the losing caller's own `attemptRepository->update()` and `recordTransition()` calls commit *before* the line that throws — the correction's `try`/`catch` prevents duplicate financial credit but does not prevent a redundant transition row or an unconditional overwrite opportunity on `payment_method_display_snapshot`. Corrected: §3 now states this explicitly, and no longer describes the residual collision as a complete idempotent no-op.
3. **Test 1 did not test what it claimed to.** The listener-triggered resolution technique causes the correction's own eligibility re-check to skip the concurrently-resolved attempt *before* `confirmAttemptFromReturn()` is ever called a second time — the `try`/`catch` is never reached. Corrected: renamed to state precisely what it proves, and a transition-count assertion added proving `confirmSucceeded()` was not re-entered.
4. **Test 2 bypassed `confirmSucceeded()` entirely.** The initial draft pre-seeded the ledger credit via a direct `creditFromFunding()` call, never exercising the actual attempt-update/transition-write/ledger-collision sequence `confirmSucceeded()` itself performs. Corrected: redesigned to drive the collision through two independently-fetched, genuinely pre-race snapshots of the same attempt, calling `confirmAttemptFromReturn()` on each — the mechanically honest way to force this exact exception deterministically, given that this correction's own re-fetch design makes the exception unreachable via `ReconcileProviderPendingState::handle()`'s own collection loop in a single-threaded reproduction (disclosed precisely in the test's own design note rather than glossed over).
5. **Test 3's completeness reasoning was left implicit.** Added the explicit mechanical statement that no *different* uniqueness-constraint injection is possible, since the ledger `correlation_key` is the only reachable uniqueness violation in this call graph (already established in §2 item 5, now cross-referenced directly from §5).
6. **A newly-discovered, unscoped instance of the same root cause was found and had not been disclosed.** Direct read of `app/Http/Controllers/Customer/Business/UsageBillingTopUpController.php:75-86` confirms it also loads the attempt fresh immediately before calling `confirmAttemptFromReturn()`, with **zero exception handling** around that call — unlike `ProcessPaymentProviderEvent::handle()`, which already wraps its own dispatch in `catch (\Throwable $e)` (`app/Jobs/Usage/ProcessPaymentProviderEvent.php:76-83`). A customer's own browser return racing a concurrent webhook can produce an uncaught `UniqueConstraintViolationException` surfacing as an HTTP 500, despite the underlying payment having succeeded. Added as a second deferred finding in §9. **No authoritative evidence (RFC-005 text or any merged contract) classifies this specific finding's M6 disposition, so it is not classified as non-blocking — it is left as an explicit open human decision in §12**, distinct from §9's other, already-classified deferred finding.

No genuinely new blocking product/schema question was found this round beyond what §12 discloses.

---

## 0. Governance

- Drafted on branch `chore/rfc-005-reconciliation-race-correction-contract`, in an isolated linked worktree (`../rfc-005-reconciliation-race-correction-contract-worktree`), based on `origin/main` at `6a0456b5606113eca8f9b3dce12af7d97d0fae38` — the Job/Event Dispatch Completion correction's own merge commit (PR [#141](https://github.com/os-creator1/os-ai/pull/141)), confirmed via `git fetch origin && git rev-parse origin/main` at the start of this contract.
- **Future implementation branch (authorized only after this contract's human merge, and only after a further, separate, explicit human instruction to begin implementation): `agent/rfc-005-reconciliation-race-correction`.**
- Confirmed at drafting: no such branch exists on `origin`; `git status --short` in this worktree is empty; no product/test/config/route/RFC-source file has been touched by this branch at any point.
- Locked:
  - `human_only_merge: true`
  - `maximum_correction_rounds: 2`
  - **Correction rounds: 1 of 2 consumed; 1 ordinary round remains.**
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

**Confirmed this is a genuinely different failure mode than the two `finalizeAddonPurchaseIfPending()`-internal races Correction Round 2 of the Job/Event Dispatch Completion contract already investigated:** that method's own `wallet_credit` fulfillment branch (`UsageBillingCheckoutManager.php:708-720`) already wraps its own `creditFromFunding()` call in `try { ... } catch (UniqueConstraintViolationException $exception) { /* already credited */ }` — a self-contained, already-safe idempotency guard, entirely internal to that one method, requiring no change here. **The unguarded path is specifically `confirmSucceeded()`'s non-`AddonPurchase` branch (`ManualTopUp`/`AutoRecharge`, lines 654-664).**

**Two-tier framing, corrected in Correction Round 1 — the initial draft's "entirely a caller-staleness problem" claim was an overstatement, independently re-audited and narrowed here:**

- **Tier 1 — the common manifestation, fully closed by this correction.** `ReconcileProviderPendingState`'s own collection-loaded-once-then-iterated-with-real-elapsed-time shape is a genuine caller-staleness problem: a webhook completing an attempt at any point after `$stuck` is loaded but before the loop reaches that member is the realistic, everyday shape of this race, and §2's re-fetch closes it entirely for this caller.
- **Tier 2 — a narrower, residual, genuinely-simultaneous race, not fully eliminated by this correction, and not unique to `ReconcileProviderPendingState`.** `UsageBillingCheckoutManager::confirmSucceeded()` itself has no persisted-state guard, transaction, or conditional-update protecting it against two independent callers that both freshly load the same attempt at nearly the same instant, before either has written anything. This is confirmed to reach at least two other callers: `ProcessPaymentProviderEvent::processFundingAttempt()` (`app/Jobs/Usage/ProcessPaymentProviderEvent.php:107`), which loads the attempt fresh with no staleness window but already tolerates the resulting exception via its own outer `catch (\Throwable $e)` (`ProcessPaymentProviderEvent.php:76-83`); and `UsageBillingTopUpController::confirmFromReturn()` (`app/Http/Controllers/Customer/Business/UsageBillingTopUpController.php:75-86`), which also loads the attempt fresh but has **no exception handling at all** around its own `confirmAttemptFromReturn()` call — see §9's second deferred finding. This residual race exists today, independent of `ReconcileProviderPendingState` entirely, and is not created, and cannot be fully closed, by a correction scoped to this one file (§2's own "`UsageBillingCheckoutManager.php` unchanged" design lock is deliberate and remains correct for *this* contract's bounded scope — but it means Tier 2 is reduced from "crashes" to "safely caught, with a disclosed non-financial side effect," never to "fully eliminated," §3).

---

## 2. Design — the narrowest correction that satisfies all seven stated guarantees

**Locked design: the entire fix lives inside `ReconcileProviderPendingState::handle()` alone.** `UsageBillingCheckoutManager.php` is **not modified** — `confirmAttemptFromReturn()`, `confirmSucceeded()`, `confirmAttemptFromWebhook()`, `markFailed()`, `finalizeAddonPurchaseIfPending()`, every existing webhook-validation/funding-provider-flow/receipt-generation/event-dispatch/financial-invariant behavior already locked by the four prior corrections, remain byte-for-byte unchanged — this is the strongest possible reading of requirement 6. This is achievable, and fully closes the race this contract was commissioned for, because Tier 1 (§1) — the specific, common, everyday manifestation named in the original instruction ("`ReconcileProviderPendingState` iterates previously loaded attempt models") — is entirely a property of *this one caller's* staleness window. It is **not** achievable for Tier 2 (§1) without touching `UsageBillingCheckoutManager.php`; this correction does not attempt to close Tier 2, and says so precisely rather than implying otherwise.

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
2. **A concurrently completed/terminal attempt becomes an idempotent no-op — for Tier 1 (§1).** The re-fetch is followed by an explicit eligibility check against the fresh `state` — `continue` (skip, no confirmation attempt at all, no re-entry into `confirmSucceeded()`, genuinely idempotent) unless the persisted state is still exactly `ProviderPending` or `RequiresAction`. This deliberately covers **more** than `confirmAttemptFromReturn()`'s own existing `Succeeded`-only early return (line 479): a concurrently `Failed`- or `Canceled`-resolved attempt is also correctly skipped here, before ever reaching the checkout manager. **For the narrower Tier 2 residual — see item 3 and §3 — "idempotent no-op" is not the honest claim; "safely caught, non-crashing, financially exactly-once, but not free of a redundant transition row" is.**
3. **A duplicate-credit race cannot crash the scheduled reconciliation run.** The Tier 2 residual race — two independent callers each freshly observing `ProviderPending` before either commits — is caught by the `try`/`catch (UniqueConstraintViolationException)` around the confirmation call itself, mirroring `finalizeAddonPurchaseIfPending()`'s own already-merged, already-proven catch shape exactly (bare catch on the type, no further constraint-name narrowing — the closer, more directly analogous precedent in this same file, chosen over `UsageWalletManager::isDuplicateRace()`'s narrower constraint-name matching, which exists for a different constraint in a different context). **This guarantee is exactly "cannot crash," not "produces no side effect at all" — §3 documents precisely what is and is not left behind.**
4. **Processing continues to later attempts after this specifically recognized race.** Both the eligibility-check `continue` and the exception-catch `continue` return control to the top of the `foreach` loop, not out of the method — every subsequent member of `$stuck` is still processed in the same run.
5. **Genuine unrelated provider, validation, or database failures are not broadly swallowed.** The catch is scoped to exactly `UniqueConstraintViolationException` — a distinct Laravel exception subtype, never a bare `\Exception`, `\Throwable`, or the broader `QueryException`. Mechanically re-audited: every reachable write inside `confirmAttemptFromReturn()`'s call graph that could plausibly throw this exact exception type is the ledger `correlation_key` insert this correction targets (`finalizeAddonPurchaseIfPending()`'s own `wallet_credit` branch already self-catches its own instance of the identical race internally, so that one never reaches this new outer catch at all). A different failure — `UsageWalletNotFoundException` (wallet deleted/missing), a provider-gateway exception, a genuine unrelated `QueryException` — is a different exception type entirely and propagates out of `handle()` unchanged, exactly as it does today.
6. **Existing webhook validation, funding-provider flow, receipt generation, event dispatch, and financial invariants remain unchanged.** `UsageBillingCheckoutManager.php` has zero lines changed. `BusinessFundingAttemptSucceeded`/`BusinessFundingAttemptFailed`, `BusinessWalletCredited`/`Debited`/`DebtCleared`/`DebtIncurred`, and every other Job/Event Dispatch Completion event still dispatch from exactly the sites that contract locked, exactly as many times, under exactly the same idempotency guards — this correction changes only which *stale-collection* attempts ever reach those call sites, never how those call sites themselves behave.
7. **Deterministic tests reproduce the interleaving.** §5 below locks the exact test design — no real subprocess, no wall-clock timing dependency, no flakiness risk, using this repository's own synchronous event system as the deterministic interleaving hook.

---

## 3. Transaction and locking boundaries — explicit

- **No new transaction is opened by this correction.** `findById()` is a plain, unlocked `SELECT`. `confirmAttemptFromReturn()`'s own downstream transaction boundaries (`creditFromFunding()`'s internal `DB::transaction()`, unchanged) are untouched.
- **No new lock is acquired.** `findForUpdateById()` (already present on `BusinessFundingAttemptRepository`, confirmed unused by this correction) is deliberately **not** used, per §2 item 1's reasoning — introducing a held row lock across the full confirmation call (including an outbound provider HTTP call) would risk lock-hold duration turning a rare race into a availability/contention concern, and would require touching `UsageBillingCheckoutManager.php`'s own transaction shape to be meaningful, which requirement 6 forbids.
- **The exception boundary is exactly one `try`/`catch`, scoped to exactly one call** (`$checkoutManager->confirmAttemptFromReturn($attempt);`), catching exactly `Illuminate\Database\UniqueConstraintViolationException`, with a body of exactly `continue;`. No `finally`, no logging requirement beyond what §5's tests assert, no retry loop invented.
- **`confirmSucceeded()`'s own pre-existing lack of atomicity across its own steps is observed, not altered.** Confirmed by direct read: `confirmSucceeded()` is never wrapped in its own outer `DB::transaction()`; `$this->attemptRepository->update($attempt, ['state' => Succeeded->value, ...])` (line 638) and `recordTransition()` (line 639, its own unconditional `INSERT`, `:839-850`) each commit independently and immediately, *before* `creditFromFunding()` (line 658) opens its own separate transaction and only *there* throws. This is a pre-existing characteristic of already-merged M3 code, unrelated to and unmodified by this correction.

**Exact, itemized committed side effects of the residual (Tier 2) collision — corrected in this round to replace the initial draft's vaguer "accounted for precisely in the test design" claim, which the test design did not in fact account for:**

1. **The losing caller's own `attemptRepository->update()` call commits.** Both the winning and losing caller write `state => 'succeeded'` — the same target value — so the attempt's own final persisted `state` is unambiguous and correct either way. No correctness defect here.
2. **The losing caller writes its own, redundant terminal transition row before the ledger collision is ever reached.** `recordTransition()` (line 639) runs *before* `creditFromFunding()` (line 658) in `confirmSucceeded()`'s own statement order — the losing caller's `? → Succeeded` transition row commits, permanently, regardless of what happens next. **The persisted result is two `business_funding_attempt_transitions` rows recording the same net state change for one attempt, not one.** This is a genuine, disclosed, non-idempotent artifact — not a hidden defect, but not "no side effect" either.
3. **The losing caller may overwrite `payment_method_display_snapshot` with its own, independently-resolved value.** `$updateAttributes` (line 632-636) conditionally includes this field whenever `$verifiedPaymentMethodDisplay !== null`; the `update()` call itself is unconditional (no `WHERE state = X` guard), so if the two racing callers' own provider-side lookups happened to resolve a different display string (a low-probability but not impossible scenario — e.g., a payment method updated between the two provider calls), the second writer's value silently wins. This correction does not add a guard against this — doing so would require modifying `confirmSucceeded()`, which §2's own design lock excludes.
4. **The ledger's `correlation_key` uniqueness constraint prevents duplicate financial credit — this remains true and is the one guarantee this correction directly targets.** Exactly one `business_usage_ledger_entries` row exists per genuine credit; the losing caller's own attempted second insert is rejected at the database level and never committed.
5. **The losing caller does not dispatch a second `BusinessFundingAttemptSucceeded` event or a second `SendReceiptNotification` job.** Both dispatch sites (`UsageBillingCheckoutManager.php:671-676` and, inside `creditFromFunding()`, after its own ledger insert) sit *after* the line that throws — the losing caller's own execution never reaches either. This is a genuine, positive guarantee, not merely an absence of a defect, and is asserted directly in §5's redesigned test 2.

**This correction's own `try`/`catch` therefore does not make the Tier 2 residual collision a complete idempotent no-op — it makes it a safe, financially-exactly-once, non-crashing, continuation-preserving event with one disclosed, accepted, non-financial side effect (item 2 above). This is the honest characterization; §2 items 2-3 and §5's test 2 are written to match it exactly.**

---

## 4. `finalizeAddonPurchaseIfPending()` — re-confirmed unaffected, not touched

Re-audited fresh (`UsageBillingCheckoutManager.php:698-739`): the `AddonPurchase` branch of `confirmSucceeded()` (lines 641-651) calls `finalizeAddonPurchaseIfPending()`, which itself already wraps its own `wallet_credit`-fulfillment `creditFromFunding()` call in a self-contained `try { ... } catch (UniqueConstraintViolationException $exception) { /* already credited */ }` (lines 709-720) — this method is already idempotent against exactly the same class of race this correction addresses, entirely on its own, and requires no change. The `direct_deliverable` fulfillment mode performs no wallet mutation at all and cannot hit this constraint. **This correction's own new outer `try`/`catch` in `ReconcileProviderPendingState::handle()` therefore only ever has an opportunity to catch the exception from the `ManualTopUp`/`AutoRecharge` branch's unguarded `creditFromFunding()` call (line 658) — the one genuinely unprotected path.**

---

## 5. Exact test allow-list, methods, and assertions

**Locked file: `tests/Feature/Usage/ReconcileProviderPendingStateTest.php` (existing, modified — no new test file).** All 5 currently-merged methods (`test_reconciles_a_stuck_provider_pending_attempt_to_succeeded_after_local_accounting_completes`, `test_does_not_reconcile_an_attempt_updated_within_the_stuck_window`, `test_does_not_mutate_a_still_pending_attempt_the_provider_confirms_as_unresolved`, `test_skips_an_attempt_with_no_provider_session_or_intent_reference`, `test_the_reconciliation_query_never_selects_an_already_succeeded_attempt`) are re-confirmed, mechanically, to remain unaffected by this correction and require **zero changes**: each of their fixtures produces an attempt whose state either never changes during the test (methods 1-3) or is excluded at the original query's own `whereIn`/`whereNotNull` level before this correction's own re-fetch loop is ever reached (methods 4-5) — the new eligibility re-check and `try`/`catch` are structurally unreachable-different for all five.

**Exactly 3 new methods, added to the same file, reusing its existing fixture helpers (`businessWithProviderCustomer()`, `registerVerifiedCheckoutOutcome()`, `markStuck()`) verbatim — no new fixture helper is required. Corrected in this round per Correction Round 1 record items 3-5:**

1. **`test_a_stale_collection_member_resolved_before_its_turn_is_skipped_via_the_fresh_eligibility_recheck`** — renamed from the initial draft's `test_a_stale_collection_member_already_resolved_before_its_turn_is_skipped_without_reconfirmation` to state precisely what it proves: the Tier 1 (§1) eligibility-recheck skip, **not** the `try`/`catch`. Creates three stuck, eligible attempts (`$attemptA`, `$attemptB`, `$attemptC`, in that creation order), each independently registered with a verified checkout outcome via `registerVerifiedCheckoutOutcome()`. Registers `Event::listen(BusinessFundingAttemptSucceeded::class, ...)` **before** running the job, guarded by a local `$triggered` flag so it fires exactly once: the first time any attempt succeeds during this test, the listener directly re-fetches and confirms whichever of `$attemptB`/`$attemptC` has not yet succeeded, deterministically simulating "a concurrent webhook resolved this attempt while reconciliation was still processing an earlier member of its own already-loaded collection." Runs `$job->handle(...)` (via `runJob()`). Asserts: the job call itself does not throw; all three attempts are `Succeeded`; `business_usage_ledger_entries` has **exactly one** row per attempt's own `local_idempotency_key.':credit'` correlation key; **`business_funding_attempt_transitions` has exactly one `to_state = 'succeeded'` row per attempt (new this round) — proving `confirmSucceeded()` was never re-entered for the concurrently-resolved one, since `handle()`'s own re-fetch skipped it at the eligibility check before `confirmAttemptFromReturn()` was ever called a second time**; the attempt never touched by the simulated race is also correctly `Succeeded` (reconciliation continued past the raced entry to a later, unaffected one).
2. **`test_a_true_duplicate_credit_race_is_caught_and_reconciliation_continues_to_later_attempts`** — **redesigned this round** to drive the Tier 2 (§1) residual collision through `confirmSucceeded()` itself, replacing the initial draft's direct-`creditFromFunding()` pre-seed. **Design note, disclosed precisely:** given this correction's own re-fetch-immediately-before-confirmation design, the `try`/`catch` can only genuinely be reached when two independent readers both observe the persisted row as still `ProviderPending` *before either one's own `confirmSucceeded()` call commits* — a true, sub-commit-latency simultaneity that is not deterministically reproducible by routing it through `ReconcileProviderPendingState::handle()`'s own collection-loading loop (by the time that loop's re-fetch reaches a given member, real wall-clock time has already passed since the initial `->get()`, so any genuinely concurrent writer has, for single-threaded test purposes, already committed). This test therefore exercises the identical call/catch-type/`continue` construct `handle()` itself uses, directly, against two independently-fetched, genuinely pre-race snapshots of the same attempt — the mechanically honest way to force this exact exception deterministically. Fetches `$winner = $attemptRepository->findById($attemptId)` and `$loser = $attemptRepository->findById($attemptId)` as two **separate** PHP model instances of the same stuck, eligible attempt (confirmed necessary: `EloquentBusinessFundingAttemptRepository::update()` calls `$attempt->fill($attributes); $attempt->save();`, mutating the passed-in instance in place — reusing one PHP object for both calls would make the second call hit `confirmAttemptFromReturn()`'s own already-`Succeeded` early return instead of re-entering `confirmSucceeded()`, proving nothing). Calls `$checkoutManager->confirmAttemptFromReturn($winner)` (succeeds fully), then `$checkoutManager->confirmAttemptFromReturn($loser)` wrapped in the test's own `try { ... } catch (UniqueConstraintViolationException) { $threw = true; }`. Asserts: `$threw === true` (the exception genuinely fires when driven directly, exactly as `handle()`'s own `try`/`catch` is built to catch); `business_usage_ledger_entries` has exactly one credit row for the attempt (§3 item 4); `business_funding_attempt_transitions` has **exactly two** `to_state = 'succeeded'` rows for the attempt, explicitly asserted and commented as the known, accepted Tier 2 side effect (§3 item 2), not a hidden bug; `Event::fake([BusinessFundingAttemptSucceeded::class])` + `Event::assertDispatchedTimes(BusinessFundingAttemptSucceeded::class, 1)` (§3 item 5); `Queue::fake()` + `Queue::assertPushed(SendReceiptNotification::class, 1)` (§3 item 5, only the winner's); the attempt's final persisted `state` is `Succeeded` (§3 item 1). Then, separately, creates a second, genuinely independent, genuinely stuck attempt and runs it through the job's own real `handle()` via `runJob()`, asserting it reconciles to `Succeeded` — proving the job's own `try`/`catch`, of identical shape to the one just exercised directly above, does not impair reconciliation of anything else.
3. **`test_a_genuinely_unrelated_exception_is_not_caught_and_still_propagates`** — unchanged design (guarantee 5). Creates one stuck, eligible attempt, registers its verified checkout outcome, then deletes that business's `business_usage_wallets` row directly via `DB::table('business_usage_wallets')->where('business_id', $business->id)->delete()` before running the job — forcing `creditFromFunding()` to throw `App\Exceptions\Usage\UsageWalletNotFoundException`, an exception type wholly unrelated to `UniqueConstraintViolationException`. Asserts, via `$this->expectException(UsageWalletNotFoundException::class)`, that calling the job's `handle()` **does** throw. **Added this round:** no test injects a *different* uniqueness-constraint violation, because §2 item 5's own mechanical audit (independently re-confirmed in Correction Round 1: `business_funding_attempts` has two unique constraints, `local_idempotency_key` and `provider_session_or_intent_reference`, neither touched by `confirmSucceeded()`'s own `update()`; `business_funding_attempt_transitions` and `business_billing_receipts` have zero unique constraints; `fundingAttemptCheckoutVerified()`/`resolveVerifiedPaymentMethodDisplay()` perform no writes) establishes that `business_usage_ledger_entries.correlation_key` is the *only* uniqueness violation reachable from this call graph — a different-constraint test is not constructible, not merely omitted.

**Required new imports for the test file, corrected this round:** `Illuminate\Database\UniqueConstraintViolationException` (test 2's own direct `try`/`catch`); `Illuminate\Support\Facades\Queue` (test 2's `Queue::fake()`/`assertPushed`); `App\Jobs\Usage\SendReceiptNotification` (test 2's assertion target); `App\Exceptions\Usage\UsageWalletNotFoundException` (test 3). `App\Library\Usage\UsageWalletManager` is no longer required by test 2 (the direct `creditFromFunding()` pre-seed is removed) but remains imported for other purposes in the file if already present; `App\Repositories\Contracts\BusinessFundingAttemptRepository` and `Illuminate\Support\Facades\Event` are already imported.

---

## 6. Exact production allow-list

| # | Path | Status | Reason |
|---|---|---|---|
| 1 | `app/Jobs/Usage/ReconcileProviderPendingState.php` | REQUIRED | The entire correction (§2) — one new re-fetch + eligibility check per iteration, one `try`/`catch` around the existing confirmation call, one new `use` import (`Illuminate\Database\UniqueConstraintViolationException`). |

**NOT_REQUIRED, explicitly confirmed:**

| Path | Reason |
|---|---|
| `app/Library/Usage/UsageBillingCheckoutManager.php` | Zero lines changed — this correction closes only the Tier 1 (§1) caller-staleness manifestation, which is entirely a property of the reconciliation caller's own collection-loading window; the Tier 2 (§1) residual is explicitly not closed by this file and is not required to be, per §2's own design lock (requirement 6). |
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
- `php artisan test --filter=TopUpStateMachineTest` / `--filter=AddonPurchaseTransitionAuditTest` — direct re-confirmation that the synchronous, single-attempt customer-return-flow callers of `confirmAttemptFromReturn()` (not modified by this correction's own file, §6) remain unchanged; this correction does not close the Tier 2 (§1) exposure documented for one such caller in §9 Finding B, and these commands are not expected to exercise that exposure.
- The repository's own documented six-command regression gate (RFC-005 M1 contract §14): `tests/Unit/Usage tests/Feature/Usage`; `tests/Unit/Entitlement tests/Feature/Entitlement`; `tests/Unit/Workspace tests/Feature/Workspace`; `tests/Feature/Business`; `tests/Feature/Opportunity`; `php artisan test --stop-on-failure` (full suite).

---

## 9. Deferred findings — explicitly not absorbed into this correction

**Finding A (carried forward, already classified non-blocking by its own originating audit).** The Job/Event Dispatch Completion PR #141 audit's own MEDIUM finding — a business whose `reserve()`/`commit()` call simultaneously qualifies for both `EvaluateBusinessAutoRecharge` and `SendLowBalanceNotification` can, under `QUEUE_CONNECTION=sync`, receive a "balance is low" notification email in the same request a successful auto-recharge already resolved the condition — is **not** a reconciliation race, does not involve `ReconcileProviderPendingState` or `UsageBillingCheckoutManager`'s confirmation paths, and is not fixed, absorbed, or redesigned by this correction. It remains exactly what the prior audit recorded it as: a disclosed, contract-faithful (not contract-violating) behavior, deferred for a separate, future human decision. This contract does not widen scope to include it.

**Finding B (newly discovered this round; M6 disposition not classified — see §12).** `UsageBillingTopUpController::confirmFromReturn()` (`app/Http/Controllers/Customer/Business/UsageBillingTopUpController.php:75-86`) is a **third** independent caller reaching `confirmSucceeded()`'s own unguarded, non-`AddonPurchase` branch, confirmed by direct read: it loads the funding attempt fresh via `$this->attemptRepository->findById($attempt)` (line 80) immediately before calling `$this->checkoutManager->confirmAttemptFromReturn($fundingAttempt)` (line 86), with **no exception handling of any kind around that call**. If this controller's own request races a concurrent webhook (`ProcessPaymentProviderEvent`) confirming the identical attempt — both readers freshly observing `ProviderPending` before either commits, the same Tier 2 (§1) condition this correction's own `try`/`catch` handles for `ReconcileProviderPendingState` — the resulting `UniqueConstraintViolationException` propagates **uncaught**, surfacing to the customer as an HTTP 500, despite their underlying payment having genuinely succeeded (the winning caller, whichever process it is, still commits the credit correctly; only the customer's own browser-facing response is affected). This is **not** fixed, absorbed, or redesigned by this correction — `UsageBillingTopUpController.php` is not on this contract's own production allow-list (§6), and adding it would contradict §2's "`UsageBillingCheckoutManager.php`/its callers unchanged" design lock this contract was authorized under. Recorded here for a separate, future, explicitly-scoped correction to address.

---

## 10. Excluded scopes — restated

This correction does not implement, design, or absorb any of the following:

- Any change to `UsageBillingCheckoutManager.php`, `confirmAttemptFromReturn()`, `confirmAttemptFromWebhook()`, `confirmSucceeded()`, `markFailed()`, `finalizeAddonPurchaseIfPending()`, or `recordTransition()` — all confirmed unaffected and unmodified (§2, §4).
- Any new row-level locking mechanism (`findForUpdateById()` remains available but deliberately unused, §3).
- Both deferred findings in §9 (the low-balance-notification observation, and `UsageBillingTopUpController::confirmFromReturn()`'s own uncaught-exception exposure).
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
- No implementation has occurred. **Correction rounds: 1 of 2 consumed; 1 ordinary round remains.**
- Reservation Admission, Funding Provider-Flow, Receipt Boundary, and Job/Event Dispatch Completion are not reopened, contradicted, or reinterpreted anywhere above.

---

## 12. Open items

**No open item blocks authorizing or implementing this correction's own bounded scope** (§6/§7's allow-lists, §5's test design). Both §9 findings are explicitly out of *this correction's* scope by design.

**One genuine open item, requiring an explicit human decision, corrected into this list in this round:**

1. **§9 Finding B's M6 disposition is not classified.** Unlike §9 Finding A (already classified non-blocking by its own originating audit, with reasoning this contract does not disturb), no RFC-005 text and no merged correction contract classifies whether `UsageBillingTopUpController::confirmFromReturn()`'s own uncaught-exception exposure blocks RFC-005 M6 resumption. This contract does not invent that classification. A human must decide: (a) whether this newly-discovered gap requires its own separately-scoped correction contract before M6 may resume, mirroring how the `ReconcileProviderPendingState` race itself was named a mandatory pre-M6 blocker by the Job/Event Dispatch Completion contract; or (b) whether it is acceptable to defer past M6 resumption, given the winning caller's own financial correctness is never impaired and only the customer-facing HTTP response of a narrow, low-probability race is affected. This correction's own implementation may proceed once separately authorized regardless of how this question is resolved — the open item concerns only Finding B's own future disposition, not this contract's own bounded scope.

---
