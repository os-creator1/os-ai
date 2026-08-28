# RFC-005 Funding Confirmation Concurrency Correction Contract

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

This document authorizes drafting one thing: a bounded, independently governed correction that closes the shared-root funding-confirmation concurrency defect first disclosed as `UsageBillingTopUpController::confirmFromReturn()`'s own uncaught-exception exposure in the merged [RFC-005 Reconciliation-Race Correction Contract](RFC-005-RECONCILIATION-RACE-CORRECTION-CONTRACT.md) §9 Finding B, and named there as a binding, mandatory pre-M6 correction (§0, §12). That contract's own implementation (merged PR [#143](https://github.com/os-creator1/os-ai/pull/143)) deliberately did not touch this defect — it closed only `ReconcileProviderPendingState`'s own caller-staleness manifestation (Tier 1), leaving `UsageBillingCheckoutManager::confirmSucceeded()`'s own lack of a persisted-state guard (Tier 2) untouched, and explicitly, honestly documented a **two-transition residual side effect** as the accepted cost of that narrower fix (PR #143 §3 item 2). **This correction exists specifically to eliminate that residual defect at its shared root — not to preserve it.**

This is **contract-authoring only**. No product code, test code, schema, route, config, or RFC-source file is touched by this branch. Reservation Admission, Funding Provider-Flow, Receipt Boundary, Job/Event Dispatch Completion, and the Reconciliation-Race Correction are closed corrections and are not reopened, contradicted, or reinterpreted by anything below — this contract narrows and completes what Reconciliation-Race deliberately left open, on its own terms. The separate, already-classified, non-blocking low-balance-notification-timing observation (Job/Event Dispatch Completion's own §2 audit finding, carried forward unchanged through every subsequent contract) is explicitly **not** absorbed here either.

---

## Exceptional post-merge implementation correction

**This is not Correction Round 3.** This contract's own ordinary correction-round budget is unchanged by this pass: `maximum_correction_rounds: 2`, `2 of 2 consumed, 0 remain` (§0, §11) — exactly as Correction Round 2 left it, and exactly as it was at merge. This pass uses the same, separate exceptional-correction mechanism already established and used twice earlier in this engagement (the Reconciliation-Race Correction's own exceptional post-review correction, and Correction Round 1/2 of this same contract's own drafting phase): it exists precisely for a narrowly-scoped, independently-confirmed defect surfaced *after* the ordinary correction-round budget is exhausted, and it does not consume, reset, or otherwise touch the ordinary counter. The distinguishing feature of *this* instance is that the defect was discovered only during a genuine, faithful implementation attempt against real code and a real database — something neither Correction Round 1 nor Correction Round 2 could have caught by design-level or single-method mechanical review alone, since it is a defect in the *interaction* between this contract's own design and a job class this contract's own design never modifies.

**The independently reproduced regression.** An implementation attempt against the merged design (§2, as it stood immediately before this pass) reproduced a genuine, confirmed regression:

- `confirmSucceeded()`'s own credit-first design (§2.1) lets `UsageWalletManager::creditFromFunding()` commit — as its own, independent, top-level transaction — *before* the funding attempt's own state is ever set to `Succeeded` by the shared `finalizeFundingAttemptState()` (a separate, later statement).
- `SendReceiptNotification` implements `ShouldQueueAfterCommit` and is explicitly dispatched from inside `creditFromFunding()`'s own transaction via `->afterCommit()` (`UsageWalletManager.php:951-952`, unmodified by this contract, confirmed not on its own production allow-list, §6).
- Under `QUEUE_CONNECTION=sync` — this repository's own standing test configuration (`.env.testing`), used by the overwhelming majority of this repository's own existing tests that do not explicitly fake the queue — the job executes **immediately**, inline, the instant `creditFromFunding()`'s own transaction commits.
- `SendReceiptNotification::handle()`'s own correct precondition (`if ($attempt->state !== FundingAttemptState::Succeeded) { throw new \RuntimeException(...); }`) is reached at that exact moment — before `finalizeFundingAttemptState()` has ever run — and correctly rejects the still-`ProviderPending`/`RequiresAction` attempt it observes, since that finalization genuinely has not happened yet.
- Reproduced broadly: running this repository's own complete Usage regression suite against the implementation attempt produced **49 failures**, spanning far beyond this contract's own four allow-listed test files — `TopUpStateMachineTest`, `ReceiptBoundaryTest` (9 of its own methods), `FundingAttemptTerminalEventDispatchTest`, `AutoRechargeFailedPaymentRetryTest`, `AutoRechargeThresholdAndCapTest`, `CrossBusinessPaymentIsolationTest`, `FundingAttemptExactlyOnceWalletCreditTest`, `FundingAttemptPayerConsentTest`, `InstrumentDetachDuringPendingChargeTest`, `PayerChangeDuringPendingAttemptTest`, `RedirectBeforeWebhookConfirmationTest`, `UsageBillingDashboardStripeIntegrationTest`, `WebhookAmountCurrencyCustomerMismatchTest`, and this contract's own four allow-listed files — every one with the identical `RuntimeException`.
- **Confirmed not a pre-existing issue.** The original, pre-correction code (§1) set `attempt.state` to `Succeeded` *before* crediting — so by the time the sync-run job fired, the precondition already, correctly held. It is specifically the credit-first reordering this contract's own design requires (for guarantee 7, crash recovery) that opens this window. Neither Correction Round 1 nor Correction Round 2 exercised the real `SendReceiptNotification` job against real, non-faked queue execution while also driving the credit-first ordering to completion, so neither round's own regression evidence could have surfaced this.

**Corrected: `confirmSucceeded()` opens one outer `DB::transaction()` around all of its own local success-side effects** — full design in §2.1 below, with the exact same design carried through §3 and §4. `SendReceiptNotification.php` is not modified, and is not required to be: its own precondition was never wrong — the design that let it observe a genuinely partial state was.

**No genuinely new blocking product/schema question was found this round.** No path outside `docs/automation/RFC-005-FUNDING-CONFIRMATION-CONCURRENCY-CORRECTION-CONTRACT.md` is touched by this branch. This governance branch is drafted in its own isolated worktree, entirely separate from the implementation attempt that surfaced this finding — that attempt is deliberately left exactly as it was found (uncommitted, unpushed, untouched) in its own worktree, not discarded and not part of this repository's own committed history; nothing about it is modified, cleaned, committed, or pushed by this correction.

**Focused review fix, within this same exceptional correction — not a new correction round, not a second exceptional correction.** A review of this exceptional correction's own first pass found that §5.1 item 2's own transaction-mechanics description no longer matched the corrected design once `confirmSucceeded()`'s own body was wrapped in the new outer `DB::transaction()` (§2.1) earlier in this very same pass:

- Under the corrected design, caller A now enters that outer transaction *before* the mocked `creditFromFunding()` interception ever fires.
- A's own delegated credit therefore executes as a nested transaction/savepoint inside A's own still-open outer transaction — it is not independently committed at the point caller B is invoked.
- Caller B's own entire confirmation, invoked synchronously from inside that same interception closure on the same connection, also runs nested one level deeper still, inside A's own outer transaction — not as a separate, already-committed sequence.
- §5.1 item 2's own prior wording — "caller A's own credit and caller B's own entire confirmation each commit as genuinely independent, sequential transactions on the same connection, never nested inside one another" — is therefore false under the corrected design and is removed.

**Corrected: §5.1 item 2 is recharacterized below as a deterministic ordering/idempotency proof, not an independent-transaction proof.** In outline: caller A's own credit is inserted first, as a nested savepoint write; caller B is then run to completion and reaches the shared finalizer first, performing the funding attempt's own terminal transition; caller A then resumes and reaches the same shared finalizer second, observes the now-terminal state — visible in-transaction on the same connection regardless of outer-commit status — and safely no-ops. Exactly one transition and one success-event dispatch decision still result; only the mechanism by which each caller's writes become visible to the other is corrected, from "independent commit" to "same-connection, in-transaction visibility across nested savepoints."

**§5.3 item 8 — not §5.1 item 2 — is this contract's own genuine independent-transaction, genuine-concurrency proof.** It races two real, independent OS processes against separate database connections; nothing about it changes as part of this fix.

**This fix explicitly supersedes Correction Round 2's own "genuinely independent, sequential transactions" characterization of §5.1 item 2 (Correction Round 2 record, item 1, below), which was accurate against the design as Correction Round 2 left it but was made stale by this same exceptional correction's own later addition of the outer transaction (§2.1).** The Correction Round 2 record below is left unmodified as an accurate historical account of what was true at that time; this fix, not that record, governs the current, corrected wording of §5.1 item 2.

This fix does not consume, reset, or otherwise touch the ordinary correction-round counter (§0, §11: still 2 of 2 consumed, 0 remaining). It changes no production design, test method count, assertion set, allow-list, regression command, or ordinary counter — only §5.1 item 2's own prose and its cross-references, corrected below.

**A second, separate focused review fix, within this same exceptional correction and this same pass — not a new correction round, not a second exceptional correction.** A further review found that §2.2's own "Crash recovery, explicit" paragraph likewise still described the design as it stood *before* this exceptional correction introduced `confirmSucceeded()`'s own outer `DB::transaction()` (§2.1): it claimed a purchase could become durably `Completed` by `finalizeAddonPurchaseIfPending()` while the funding attempt's own state remained non-terminal, pending a still-separate commit by `finalizeFundingAttemptState()`. Under the corrected design, `finalizeAddonPurchaseIfPending()` and `finalizeFundingAttemptState()` both execute inside the same single outer transaction, so that specific window — a durably `Completed` purchase alongside a durably non-terminal attempt, produced by *this* corrected path — no longer exists. **Corrected: §2.2's own crash-recovery paragraph is rewritten below** to describe the purchase's own completion and the funding attempt's own terminal transition as committing together, atomically, at the outer transaction boundary — while still explicitly preserving the pre-existing recovery path for a purchase left `Completed` by *other* means (PR #143's own predecessor design, or explicitly seeded test data) alongside a still-eligible attempt, which remains handled identically to before. This fix does not consume, reset, or otherwise touch the ordinary correction-round counter (still 2 of 2 consumed, 0 remaining), and changes no production design, test, count, allow-list, regression command, or governance counter — only §2.2's own prose.

---

## Correction Round 2 record — FINAL ORDINARY ROUND

A focused review of Correction Round 1's own output (head `a1c6ed93c08a63f9a200e0d88d3be6bdca6e3fbb`) found the §5.1 item 2 deterministic interleaving test itself — not the production design, which the review explicitly approved and left unchanged — mechanically unable to prove what it claimed to. **2 of 2 ordinary correction rounds consumed by this round; 0 ordinary rounds remain.**

Exact issue resolved this round:

1. **The test's own interception point (`findForUpdateById()`) exists only inside the corrected, always-locked finalizer, and the nested call it drove ran inside caller A's own already-open transaction.** Against Correction Round 1's own "original unlocked-winner design" (credit-first, but the winner's own finalization unlocked and unconditional), caller A's winner path never called `findForUpdateById()` at all — so the test's own interception would never fire, caller B would never be scheduled, and the test could not have distinguished the buggy design from the fixed one, contradicting the test's own claim that it "would have failed against the initial draft's own unlocked winner path." Separately, because the interception sat inside `finalizeFundingAttemptState()`'s own `DB::transaction()` closure, caller B's entire confirmation ran as a nested savepoint within caller A's own still-open transaction rather than as genuinely independent, already-committed writes, which does not accurately model the credit-committed/finalization-not-started boundary the interleaving is meant to represent. **Corrected: §5.1 item 2 is redesigned to intercept `UsageWalletManager::creditFromFunding()` instead** — a call every version of `confirmSucceeded()`, round-0 or round-1, has always made, and one that sits *before* any finalization-level transaction opens, so caller A's own credit and caller B's own entire confirmation each commit as genuinely independent, sequential transactions on the same connection. Mechanically re-verified, step by step, against both the round-0 and round-1 designs (§5.1 item 2's own new closing paragraph): the redesigned test now genuinely produces two transition rows and two event dispatches against round-0, and exactly one of each against the current, approved round-1 design.

No other section of this contract changes this round. §2 (the production design) is explicitly out of scope, per the review's own instruction, and is unmodified. §5.1 items 1, 3, 4, 5, §5.2, §5.3, and §5.4 (except the new imports item 2 itself requires) are unchanged. The production and test allow-lists (§6, §7) are unchanged — the redesigned test lives in the same already-allow-listed file, with no new production or test path.

No genuinely new blocking product/schema question was found this round.

---

## Correction Round 1 record

A focused review of the initial draft (head `75ab99ee2c19d7a0a612d68be7880704ae12d35b`) found two blocking defects in §2's design, both resolved below by a full redesign of §2's funding-attempt-level finalization. **1 of 2 ordinary correction rounds consumed by this round; 1 ordinary round remains.**

Exact issues resolved this round:

1. **The successful-credit winner was not safely serialized during funding-attempt finalization.** The initial draft's `finalizeFundingAttemptState()` (the winner path) performed its own state update and transition insert **without acquiring a lock or re-checking persisted state** — its own reasoning ("the ledger's own unique constraint already exclusively serializes this caller") conflated exclusive ownership of the *credit* with exclusive ownership of *finalization*, which are two separate steps with a real time gap between them. A genuine, confirmed interleaving breaks this: caller A's credit commits; before A reaches its own (unlocked) finalization, caller B's own credit attempt collides and B enters the *locked* recovery branch, finalizes the attempt, inserts the transition, and dispatches the event; A then resumes and *unconditionally* re-finalizes its own stale attempt — a second transition row and a second event dispatch, the exact defect this contract exists to eliminate. **Corrected: §2 is redesigned around a single, shared, always-row-locked, idempotent funding-attempt finalizer, invoked identically by every path — the credit winner, the credit loser/recovery caller, and AddonPurchase after its own fulfillment step — with no separate unlocked fast path for any of them.** The "deliberate asymmetry" reasoning the initial draft used to justify AddonPurchase's own uniform locking, by contrast with the (then-incorrect) unlocked funding-attempt winner path, is removed in full — there is no longer an asymmetry to justify, since every path now locks.
2. **The AddonPurchase design contradicted itself and could leave the funding attempt permanently unfinished.** §2.1's own redesigned `confirmSucceeded()` branched into `finalizeAddonPurchaseIfPending()` and then unconditionally dispatched `BusinessFundingAttemptSucceeded` — but never called anything that updated the *funding attempt's own* `state` column or inserted a *funding-attempt* transition row for that branch. §2.3 nonetheless claimed "`confirmSucceeded()` itself sets `attempt.state = Succeeded` unconditionally before ever branching on purpose" — a claim that was true of the *original, pre-correction* code (§1) but false of §2.1's own redesign, which had silently dropped that statement when the non-AddonPurchase branch's state/transition logic was extracted into a helper. Under the actual §2.1 code, an AddonPurchase attempt's own `state` would never reach `Succeeded` at all: its own top-of-method `state === Succeeded` guard would never fire, every replay would re-run the full confirmation and unconditionally dispatch a fresh `BusinessFundingAttemptSucceeded` event (unbounded duplicate dispatches, not merely two), and `ReconcileProviderPendingState`'s own query would treat the attempt as perpetually eligible. **Corrected: for AddonPurchase, `confirmSucceeded()` now performs the idempotent add-on fulfillment/completion step first (`finalizeAddonPurchaseIfPending()`, itself unchanged, §2.2), then invokes the same shared, locked funding-attempt finalizer named in item 1, and dispatches `BusinessFundingAttemptSucceeded` only if that finalizer reports it performed the terminal transition.** This applies uniformly to both `wallet_credit` and `direct_deliverable`.

Both corrections are carried through §2, §3, §4 (guarantees 1, 3, 4, 7, 8, 10), and §5 (a new deterministic interleaving test added, two existing AddonPurchase test designs strengthened with funding-attempt-level assertions) below. §1 (the original, pre-existing production code's own defect trace) is unchanged — it was never the source of either finding; both were defects in this contract's own *proposed* design, not in the code the design was correcting. The production and test allow-lists (§6, §7) are unchanged — no new path was mechanically required by either correction.

No genuinely new blocking product/schema question was found this round.

---

## 0. Governance

- Drafted on branch `chore/rfc-005-funding-confirmation-concurrency-correction-contract`, in an isolated linked worktree (`../rfc-005-funding-confirmation-concurrency-correction-contract-worktree`), based on `origin/main` at `ccee46b6197dfd70980091cae97ecb283a52aed7` — the Reconciliation-Race Correction's own merge commit (PR [#143](https://github.com/os-creator1/os-ai/pull/143)), confirmed via `git fetch origin && git rev-parse origin/main` at the start of this contract.
- **Future implementation branch (authorized only after this contract's human merge, and only after a further, separate, explicit human instruction to begin implementation): `agent/rfc-005-funding-confirmation-concurrency-correction`.**
- Confirmed at drafting: no such branch exists on `origin`; `git status --short` in this worktree is empty; no product/test/config/route/RFC-source file has been touched by this branch at any point.
- Locked:
  - `human_only_merge: true`
  - `maximum_correction_rounds: 2`
  - **Correction rounds: 2 of 2 consumed; 0 ordinary rounds remain. This is the final ordinary correction round available to this contract.**
  - `advance_automatically: false`
  - `start_automatically_after_contract_merge: false`
  - `M6 remains frozen` — untouched, not resumed, no M6 document created or modified.
  - `AI-AUTONOMY-STATE.json` is untouched by this branch.
  - No tag is created or moved. No live Stripe/rate/meter/pilot activation occurs.
  - **Implementation requires a separate, explicit human instruction issued after this contract itself is human-merged.**
- This is a new, independently bounded pre-M6 correction contract — not a correction round against M1–M6's own contracts, and not a correction round against any of the five already-merged corrections (Reservation Admission, Funding Provider-Flow, Receipt Boundary, Job/Event Dispatch Completion, Reconciliation-Race). Its `maximum_correction_rounds: 2` budget is its own; no counter is borrowed or altered on any other contract.
- This governance branch changes exactly one tracked file: `docs/automation/RFC-005-FUNDING-CONFIRMATION-CONCURRENCY-CORRECTION-CONTRACT.md`.
- **Sequence position:** this correction is the mandatory pre-M6 correction named by the Reconciliation-Race Correction Contract's own §0/§9/§12 (Finding B), sitting immediately after that correction (PR #143) and still before remediation #5 (Admin Usage Billing Surface) in the RFC-005 remediation sequence. Referred to by name, not by a renumbered position, so no existing cross-reference in any already-merged contract is disturbed. **M6 remains frozen until this correction is completed** — the binding condition the Reconciliation-Race Correction Contract itself set (§0, §12).
- **Required reading completed before drafting, independently audited fresh in this pass:** `app/Library/Usage/UsageBillingCheckoutManager.php`'s `confirmAttemptFromReturn()`, `confirmAttemptFromWebhook()`, `confirmSucceeded()`, `finalizeAddonPurchaseIfPending()`, `retryFundingAttemptAsAdministrator()`, `markFailed()`, `recordTransition()`; `app/Repositories/Contracts/BusinessFundingAttemptRepository.php` and its Eloquent implementation (full contract, all locking methods); `app/Repositories/Contracts/BusinessUsageAddonPurchaseRepository.php` and its Eloquent implementation (full contract); `app/Library/Usage/UsageWalletManager.php`'s `creditFromFunding()` (full method, transaction boundary, ledger `correlation_key` unique-constraint interaction); `app/Http/Controllers/Customer/Business/UsageBillingTopUpController.php` (full file); `app/Jobs/Usage/ProcessPaymentProviderEvent.php` (full file, all three dispatch branches, its own outer `catch (\Throwable $e)`); `app/Jobs/Usage/ReconcileProviderPendingState.php` (full file, current post-PR-#143 state); `database/migrations` for `business_funding_attempts`, `business_funding_attempt_transitions`, `business_usage_ledger_entries`, `business_usage_addon_purchases`, `business_usage_addon_purchase_transitions`, `business_billing_receipts` (every unique constraint confirmed directly, not assumed); `tests/Feature/Usage/ReconcileProviderPendingStateTest.php`, `tests/Feature/Usage/ConcurrentTopUpConcurrencyTest.php`, `tests/Feature/Usage/AddonPurchaseTransitionAuditTest.php`, `tests/Feature/Usage/TopUpStateMachineTest.php`, `tests/Feature/Usage/UsageBillingDashboardAuthorizationTest.php` (full files); the Funding Provider-Flow, Receipt Boundary, Job/Event Dispatch Completion, and Reconciliation-Race correction contracts in full. None of the five prior corrections is reopened, contradicted, or referenced as needing amendment by anything below.

---

## 1. The shared-root defect — mechanically re-established from scratch

**Confirmed by direct, fresh read of `UsageBillingCheckoutManager::confirmSucceeded()` (private, lines 628-677, unchanged since M4):**

```php
private function confirmSucceeded(BusinessFundingAttempt $attempt, TransitionSource $source, ?int $providerEventId, ?int $actorUserId = null, ?string $verifiedPaymentMethodDisplay = null): void
{
    $fromState = $attempt->state;
    $updateAttributes = ['state' => FundingAttemptState::Succeeded->value];
    if ($verifiedPaymentMethodDisplay !== null) {
        $updateAttributes['payment_method_display_snapshot'] = $verifiedPaymentMethodDisplay;
    }

    $this->attemptRepository->update($attempt, $updateAttributes);          // (A) state → Succeeded, committed immediately
    $this->recordTransition($attempt, $fromState, FundingAttemptState::Succeeded, $source, $providerEventId, $actorUserId); // (B) transition row, committed immediately

    if ($attempt->purpose === FundingAttemptPurpose::AddonPurchase) {
        $this->finalizeAddonPurchaseIfPending($attempt, $source, $providerEventId); // (C)
        BusinessFundingAttemptSucceeded::dispatch(...);
        return;
    }

    $entryType = ...;
    $this->walletManager->creditFromFunding(...); // (D) opens its own DB::transaction(); throws UniqueConstraintViolationException on a duplicate correlation_key

    BusinessFundingAttemptSucceeded::dispatch(...); // (E)
}
```

**Three confirmed callers reach this exact method, all through the identical unguarded shape:**

1. `confirmAttemptFromReturn()` (public, `UsageBillingCheckoutManager.php:477-515`) — called synchronously by `UsageBillingTopUpController::confirmFromReturn()` (`app/Http/Controllers/Customer/Business/UsageBillingTopUpController.php:75-97`), with **zero exception handling of any kind** around the call at line 86.
2. `confirmAttemptFromWebhook()` (public, `UsageBillingCheckoutManager.php:526-565`) — called by `ProcessPaymentProviderEvent::processFundingAttempt()` (`app/Jobs/Usage/ProcessPaymentProviderEvent.php:99-205`), itself wrapped in `handle()`'s own broad `catch (\Throwable $e) { ...; $eventRepository->markFailed($event->id, $e::class); }` (`ProcessPaymentProviderEvent.php:74-81`) — this caller already tolerates the exception, but mislabels the webhook event permanently `failed` with the classification `UniqueConstraintViolationException`, even though the underlying payment genuinely succeeded.
3. `retryFundingAttemptAsAdministrator()` (public, `UsageBillingCheckoutManager.php:583-618`) — the platform-administrator resume path, with the identical unguarded call at lines 601/612.

**Step-by-step mechanical trace of the race, each step directly re-confirmed against current code (`origin/main` at `ccee46b6197dfd70980091cae97ecb283a52aed7`):**

1. Two independent callers (any combination of the three above — the same attempt confirmed by a browser return and a webhook at nearly the same instant is the realistic shape; `ReconcileProviderPendingState`'s own post-PR-#143 re-fetch closes the *stale-collection* variant of this but not the underlying method-level gap) each independently load a fresh, genuinely `ProviderPending`/`RequiresAction` snapshot of the same attempt, before either has written anything.
2. Both reach `confirmSucceeded()`. Statement (A) commits for both — writing the identical target value (`state => 'succeeded'`), so this alone is harmless.
3. Statement (B) commits for **both** — `recordTransition()` is an unconditional `INSERT` with no uniqueness constraint on `(funding_attempt_id, to_state)` (confirmed: `database/migrations` for `business_funding_attempt_transitions` carries no `unique()` of any kind). **This is the exact, confirmed source of PR #143's own documented two-transition residual side effect** — it happens *before* either caller ever reaches the ledger, so nothing downstream of it can prevent it.
4. Statement (D) — `creditFromFunding()` — is called by both. Its own ledger `INSERT` uses `correlation_key = $attempt->local_idempotency_key.':credit'`, identical for both callers; `business_usage_ledger_entries.correlation_key` is `unique()` (confirmed: `database/migrations/2026_08_16_120007_create_business_usage_ledger_entries_table.php:39`), so the second caller's insert throws `Illuminate\Database\UniqueConstraintViolationException` — **the only step in this entire method that is genuinely, already, self-protecting.**
5. That exception propagates out of `creditFromFunding()`, out of `confirmSucceeded()`, and out of whichever of the three callers triggered it. For caller 1 (`confirmAttemptFromReturn()`, reached via the HTTP controller with zero handling), it propagates all the way to an **uncaught HTTP 500**, despite the customer's own payment having genuinely succeeded — the winning caller, whichever it was, already committed the credit correctly.
6. Statement (E) — the `BusinessFundingAttemptSucceeded` dispatch — sits *after* (D), so the losing caller never reaches it; only the winner's own call dispatches it. This is the one other guarantee (besides the ledger's own uniqueness) that already holds today, independent of this correction.

**`finalizeAddonPurchaseIfPending()` (private, `UsageBillingCheckoutManager.php:698-739`) has the structurally identical defect, one level down, confirmed by the same direct trace:**

```php
private function finalizeAddonPurchaseIfPending(BusinessFundingAttempt $attempt, TransitionSource $source, ?int $providerEventId): void
{
    $purchase = $this->addonPurchaseRepository->findByFundingAttemptId((int) $attempt->id);
    if ($purchase === null || $purchase->status === AddonPurchaseStatus::Completed) {
        return; // (F) top-of-method guard — reads a freshly-fetched $purchase within THIS call, but not serialized against a second, near-simultaneous call
    }

    $catalogRow = $this->addonCatalogRepository->findByAddonKey($purchase->addon_key);
    if ($catalogRow !== null && $catalogRow->fulfillment_mode->value === 'wallet_credit') {
        try {
            $this->walletManager->creditFromFunding(...); // (G) already self-protected by the same correlation_key constraint
        } catch (UniqueConstraintViolationException $exception) {
            // Already credited on an earlier pass; only the purchase record's own completion was lost to the crash.
        }
    }

    $fromStatus = $purchase->status;
    $purchase = $this->addonPurchaseRepository->update($purchase, ['status' => AddonPurchaseStatus::Completed->value, 'completed_at' => now()]); // (H) unconditional, reached regardless of whether (G) won or lost
    $this->addonPurchaseTransitionRepository->create([...]); // (I) unconditional — the identical duplicate-transition pattern as (B) above, on business_usage_addon_purchase_transitions instead of business_funding_attempt_transitions
}
```

**This is a second, independently-discovered instance of the identical root-cause shape** — `finalizeAddonPurchaseIfPending()` is called from *inside* `confirmSucceeded()`'s own AddonPurchase branch, so it inherits the same two-independently-fresh-callers exposure. Confirmed: `business_usage_addon_purchases.funding_attempt_id` is `unique()` (one purchase per attempt — no ambiguity for locking, see §2), and `business_usage_addon_purchase_transitions` carries no unique constraint of any kind (confirmed directly against its migration). Because this method sits inside the exact call graph this contract was commissioned to audit (§0's required reading), and guarantee 8 below explicitly requires AddonPurchase semantics to remain correct, this correction closes both instances of the same defect — not only the one PR #143's own contract happened to name first.

**Confirmed this genuinely different failure mode from the crash-recovery scenarios `AddonPurchaseTransitionAuditTest.php` already covers (`test_a_crash_between_attempt_succeeded_and_purchase_completed_is_repaired_by_a_later_webhook`, `test_a_crash_before_any_credit_or_completion_is_repaired_by_a_later_webhook`):** those tests exercise a *sequential* crash-then-replay (one call completes, a fixture corrupts persisted state, a second, later call repairs it) — a scenario this correction's own design (§2) is verified, by direct trace, to still satisfy identically (§5). The defect this correction targets is a *genuinely simultaneous* pair of callers, neither of which is a "replay" of the other.

**A second, independently-confirmed gap: crash recovery is currently backwards.** Statement (A) — the state mutation to `Succeeded` — commits **before** statement (D) — the financial credit. `confirmAttemptFromReturn()`'s and `confirmAttemptFromWebhook()`'s own top-of-method guards (`if ($attempt->state === Succeeded) { ...; return; }`, lines 479/528) read only the *persisted* state on every fresh call. If a crash occurs between (A)'s commit and (D)'s commit, the attempt is left **permanently marked `Succeeded` with no credit ever issued**, and no caller will ever retry the credit — the guard itself prevents it, forever. This is confirmed, by direct trace, to be strictly worse than the two-transition side effect PR #143 already disclosed: it is a silent, permanent loss of a customer's own payment, not a merely-redundant audit row. **Guarantee 7 (§4) requires this reordered so a crash in this window is recoverable on replay — this contract's design (§2) makes credit-first the mechanism that closes both defects with one change.**

---

## 2. Design — the narrowest shared-root correction

**Locked design, corrected in Correction Round 1: one shared, always-row-locked, idempotent funding-attempt finalizer, invoked identically by every path.** There is no unlocked "winner" fast path any more (Correction Round 1 item 1) — the ledger's own unique constraint proves exclusive ownership of the *credit*, not of *finalization*, and only a lock can prove the latter. `finalizeAddonPurchaseIfPending()` performs its own idempotent purchase-level fulfillment first, then defers to the exact same shared finalizer for the funding attempt's own state (Correction Round 1 item 2) — there is no longer a separate, AddonPurchase-only completion helper for the attempt-level concern. **No caller of `confirmSucceeded()` changes at all** — `confirmAttemptFromReturn()`, `confirmAttemptFromWebhook()`, `retryFundingAttemptAsAdministrator()`, `UsageBillingTopUpController::confirmFromReturn()`, `ProcessPaymentProviderEvent`, and `ReconcileProviderPendingState` are **all** byte-for-byte unchanged. This is deliberate and is what "not a controller-only try/catch" requires: the exception this contract's predecessor left the controller to face is now fully absorbed at its own shared root, so no caller ever needs to handle it — proving guarantee 1 is a direct, mechanical consequence of guarantee 6, not a separate patch.

### 2.1 `confirmSucceeded()` — unified across every purpose, now wrapped in one outer transaction

**Corrected by this exceptional post-merge implementation correction: the credit-or-fulfillment step and the shared finalizer call are now wrapped in a single outer `DB::transaction()`, and the event dispatch is moved to after that outer transaction has committed.** This is the only change this correction makes to this method's own shape; every guarantee §2's own prior rounds locked (§2's own "Why this closes..." notes, unchanged below) still holds.

```php
private function confirmSucceeded(BusinessFundingAttempt $attempt, TransitionSource $source, ?int $providerEventId, ?int $actorUserId = null, ?string $verifiedPaymentMethodDisplay = null): void
{
    $didFinalize = DB::transaction(function () use ($attempt, $source, $providerEventId, $actorUserId, $verifiedPaymentMethodDisplay) {
        if ($attempt->purpose === FundingAttemptPurpose::AddonPurchase) {
            $this->finalizeAddonPurchaseIfPending($attempt, $source, $providerEventId); // idempotent purchase-level fulfillment, §2.2 — unchanged from the initial draft
        } else {
            $entryType = $attempt->purpose === FundingAttemptPurpose::AutoRecharge
                ? UsageLedgerEntryType::AutoRecharge
                : UsageLedgerEntryType::PaidTopUp;

            try {
                $this->walletManager->creditFromFunding(
                    (int) $attempt->business_id,
                    $entryType,
                    (int) $attempt->expected_amount_micro,
                    (int) $attempt->id,
                    $attempt->local_idempotency_key.':credit',
                );
            } catch (UniqueConstraintViolationException) {
                // A concurrent/replayed caller already committed this
                // exact credit. Fall through to the same shared finalizer
                // below — it alone determines, under lock, whether this
                // caller must still perform the attempt's own terminal
                // transition.
            }
        }

        return $this->finalizeFundingAttemptState($attempt, $source, $providerEventId, $actorUserId, $verifiedPaymentMethodDisplay);
    });

    // Dispatched only after the outer transaction above has genuinely
    // committed, and only by whichever single caller performed the
    // terminal transition — unchanged from Correction Round 1's own
    // design intent, now correctly enforced by the outer transaction
    // boundary rather than merely by statement order (see this
    // correction's own record above and §3's own new explanation).
    if ($didFinalize) {
        BusinessFundingAttemptSucceeded::dispatch((int) $attempt->id, (int) $attempt->business_id, $attempt->purpose->value, (int) $attempt->expected_amount_micro);
    }
}
```

**The one shared finalizer — every path routes through this, always locked, no exceptions:**

```php
private function finalizeFundingAttemptState(BusinessFundingAttempt $attempt, TransitionSource $source, ?int $providerEventId, ?int $actorUserId, ?string $verifiedPaymentMethodDisplay): bool
{
    return DB::transaction(function () use ($attempt, $source, $providerEventId, $actorUserId, $verifiedPaymentMethodDisplay) {
        $locked = $this->attemptRepository->findForUpdateById((int) $attempt->id);

        if ($locked === null || $locked->state === FundingAttemptState::Succeeded) {
            return false; // missing, or a genuine concurrent winner (or an earlier recovery caller) already performed this exact terminal transition
        }

        $updateAttributes = ['state' => FundingAttemptState::Succeeded->value];
        if ($verifiedPaymentMethodDisplay !== null) {
            $updateAttributes['payment_method_display_snapshot'] = $verifiedPaymentMethodDisplay;
        }

        $fromState = $locked->state;
        $this->attemptRepository->update($locked, $updateAttributes);
        $this->recordTransition($locked, $fromState, FundingAttemptState::Succeeded, $source, $providerEventId, $actorUserId);

        return true;
    });
}
```

One new import required: `use Illuminate\Support\Facades\DB;` — already imported in this file (confirmed: `UsageBillingCheckoutManager.php:55`), no change needed there.

**Why this closes Correction Round 1 item 1 exactly.** Every caller — the credit winner, the credit loser/recovery caller, and (per item 2 below) AddonPurchase after its own fulfillment step — calls the identical `finalizeFundingAttemptState()`, and every call acquires the same row lock via `findForUpdateById()` before touching anything. Whichever caller's own lock acquisition genuinely happens first, for a given attempt, is the one and only caller whose re-read observes a non-terminal state and performs the transition; every other caller — regardless of whether its own *credit* attempt won or lost, and regardless of *when* relative to the winning credit its own finalization call happens to run — observes the now-terminal state under the same lock and returns `false`. There is no code path left in which a caller finalizes without first proving, under lock, that no one else already has. The specific interleaving the review identified (A credits, B collides and finalizes first, A resumes) is exactly what this design is built to survive: whichever of A or B acquires the lock second simply sees `Succeeded` and no-ops — regardless of which one that is.

### 2.2 `finalizeAddonPurchaseIfPending()` — idempotent fulfillment first, then the same shared finalizer

**Unchanged from the initial draft — this method's own internal design was never the source of either Correction Round 1 finding; only how `confirmSucceeded()` used its result was wrong.**

```php
private function finalizeAddonPurchaseIfPending(BusinessFundingAttempt $attempt, TransitionSource $source, ?int $providerEventId): void
{
    $purchase = $this->addonPurchaseRepository->findByFundingAttemptId((int) $attempt->id);
    if ($purchase === null || $purchase->status === AddonPurchaseStatus::Completed) {
        return;
    }

    $catalogRow = $this->addonCatalogRepository->findByAddonKey($purchase->addon_key);

    if ($catalogRow !== null && $catalogRow->fulfillment_mode->value === 'wallet_credit') {
        try {
            $this->walletManager->creditFromFunding(
                (int) $purchase->business_id,
                UsageLedgerEntryType::PaidTopUp,
                (int) $purchase->price_micro,
                (int) $attempt->id,
                $attempt->local_idempotency_key.':credit',
            );
        } catch (UniqueConstraintViolationException) {
            // Falls through to the same lock-protected purchase-level
            // completion below, exactly like the winner path — the
            // purchase's own fulfillment record must still be finalized
            // even when this caller lost the credit race.
        }
    }

    $this->completeAddonPurchaseUnderLock($purchase, $source, $providerEventId);
}
```

**Purchase-level completion helper — unchanged from the initial draft, applied uniformly to every fulfillment mode, including `direct_deliverable` (which has no credit call at all, and therefore no unique-constraint signal to gate on; the lock is the only available serialization point for that mode):**

```php
private function completeAddonPurchaseUnderLock(BusinessUsageAddonPurchase $purchase, TransitionSource $source, ?int $providerEventId): void
{
    DB::transaction(function () use ($purchase, $source, $providerEventId) {
        $locked = $this->addonPurchaseRepository->findForUpdateByFundingAttemptId((int) $purchase->funding_attempt_id);

        if ($locked === null || $locked->status === AddonPurchaseStatus::Completed) {
            return; // a genuine concurrent winner (or an earlier recovery caller) already completed it
        }

        $fromStatus = $locked->status;
        $locked = $this->addonPurchaseRepository->update($locked, ['status' => AddonPurchaseStatus::Completed->value, 'completed_at' => now()]);

        $this->addonPurchaseTransitionRepository->create([
            'purchase_id' => $locked->id,
            'from_status' => $fromStatus->value,
            'to_status' => AddonPurchaseStatus::Completed->value,
            'source' => $source->value,
            'provider_event_id' => $providerEventId,
            'actor_user_id' => null,
            'failure_reason' => null,
            'created_at' => now(),
        ]);
    });
}
```

One new repository method required (§6): `findForUpdateByFundingAttemptId(int $fundingAttemptId): ?BusinessUsageAddonPurchase`, added to `BusinessUsageAddonPurchaseRepository` and its Eloquent implementation, mirroring `BusinessFundingAttemptRepository::findForUpdateById()`'s own existing shape exactly (`return $this->query()->where('funding_attempt_id', $fundingAttemptId)->lockForUpdate()->first();`). Safe by construction: `business_usage_addon_purchases.funding_attempt_id` is `unique()` (confirmed, §1), so this can never match more than one row.

**Why this closes Correction Round 1 item 2 exactly.** `confirmSucceeded()`'s AddonPurchase branch (§2.1) now calls `finalizeAddonPurchaseIfPending()` — completing the *purchase's own* fulfillment, idempotently, exactly as it always did — and then, unconditionally, also calls the shared `finalizeFundingAttemptState()` to complete the *funding attempt's own* state, exactly like every other purpose. There is no longer any purpose for which `confirmSucceeded()` fails to reach the attempt-level finalizer.

**Crash recovery, explicit — corrected by this exceptional post-merge implementation correction's own focused review fix.** The description this paragraph previously carried ("if execution stops after `finalizeAddonPurchaseIfPending()` returns, purchase durably `Completed`, but before `finalizeFundingAttemptState()`'s own transaction commits") described the design as it stood *before* this exceptional correction introduced `confirmSucceeded()`'s own outer transaction (§2.1) and is no longer accurate; it is superseded below, exactly as §5.1 item 2's own transaction-mechanics description was superseded by this same correction's earlier focused review fix, above.

`finalizeAddonPurchaseIfPending()` and `finalizeFundingAttemptState()` now both execute inside `confirmSucceeded()`'s single outer `DB::transaction()` (§2.1) — `completeAddonPurchaseUnderLock()`'s own internal transaction (unchanged in shape) is, like every other internal transaction in this method's own call graph, a nested savepoint within that outer transaction, not an independent commit boundary. **The purchase can no longer become durably `Completed` while the funding attempt's own state remains non-terminal through this corrected path.** If execution fails before the outer transaction commits, the applicable wallet credit (for `wallet_credit`), the purchase's own completion and transition, and the funding attempt's own state change and transition all roll back together — none is left durably committed while another is not — and no `afterCommit()`-deferred job or event (`SendReceiptNotification`, `BusinessFundingAttemptSucceeded`) ever runs, since the outermost transaction never reached commit. A later replay therefore retries from the same, previously-committed state it started from, with nothing partial left to reconcile. If the outer transaction does commit, the purchase's own `Completed` status and the funding attempt's own `Succeeded` state become durable together, in that same commit, before any after-commit work runs.

**Legacy or explicitly seeded partial data remains recoverable.** A purchase already persisted `Completed` while its own funding attempt is still eligible — the exact shape this correction's own predecessor (PR #143) could still leave behind, or a state deliberately seeded by a test — is handled identically to before: `finalizeAddonPurchaseIfPending()`'s own top-of-method guard sees `status === Completed` and returns immediately, a genuine no-op, since fulfillment already happened; the same call then still reaches `finalizeFundingAttemptState()` for the first time, which finds the attempt non-terminal and safely performs the one remaining transition. Exactly one funding-attempt transition and one `BusinessFundingAttemptSucceeded` dispatch result, regardless of how many replays occur. This holds identically for both `wallet_credit` and `direct_deliverable`.

---

## 3. Transaction and locking boundaries — explicit

- **`confirmSucceeded()` now opens one outer `DB::transaction()`, added by this exceptional post-merge implementation correction, around the credit-or-fulfillment step and the shared finalizer call together.** `creditFromFunding()`'s own internal `DB::transaction()` (unchanged, `UsageWalletManager.php:886-954`) and `finalizeFundingAttemptState()`'s own internal `DB::transaction()` (§2.1, unchanged in shape) are both, unmodified, still literally present in their own methods — but because they now execute *inside* this new outer transaction, MySQL/InnoDB (via Laravel's own transaction manager) treats them as **nested savepoints**, not independent top-level transactions. Confirmed by direct read of `Illuminate\Database\DatabaseTransactionsManager::commit()`: `afterCommit()` callbacks — the exact mechanism `creditFromFunding()` uses to dispatch `SendReceiptNotification` (`UsageWalletManager.php:951-952`, `->afterCommit()`) — are deferred until the transaction *level* returns to `0`, i.e., until the true, outermost transaction genuinely commits, never at a nested savepoint's own release. This is the entire mechanism of this correction: the job can no longer fire until the funding attempt's own state has *also* been finalized, because both now share the same outermost commit.
- **Provider verification and every outbound provider call remain outside this transaction, unchanged.** `confirmAttemptFromReturn()`/`confirmAttemptFromWebhook()`'s own `retrieveCheckoutSession()`/`retrievePaymentIntent()` calls happen *before* `confirmSucceeded()` is ever invoked — they are, structurally, already outside `confirmSucceeded()`'s own scope, so wrapping that method's own body in a transaction does not touch them, widen their own exposure, or hold any lock across them.
- **The ledger `correlation_key` unique constraint remains the entire serialization mechanism for distinguishing credit winner from loser** — nothing about this changes; the constraint is checked at `INSERT` time regardless of transaction nesting, and a losing caller's own credit attempt (now nested one level deeper than before) still throws `UniqueConstraintViolationException` exactly as it always has.
- **Disclosed, minor side effect of the outer transaction: the wallet row lock `creditFromFunding()` itself acquires (`findForUpdateByBusinessId()`, `UsageWalletManager.php`, unmodified) is now held for the small additional duration of the shared finalizer's own two local writes, rather than being released the instant the credit's own (now-nested) transaction reaches its savepoint.** This is a genuine, honest trade-off, not a defect: the addition is a two-statement, purely-local operation (no outbound call, no additional lock acquisition beyond the funding attempt's own row, already accounted for below) — the same category of cost the Reconciliation-Race Correction Contract's own reasoning about "lock-hold-duration-across-a-provider-call" was concerned with never arises here, since nothing outbound is ever nested inside this transaction.
- **A new lock is acquired by every call to the shared finalizer, unconditionally — corrected in Correction Round 1 (item 1); there is no longer an unlocked fast path for any caller.** `findForUpdateById()` (`BusinessFundingAttemptRepository`, already present, confirmed unused by any prior correction) and the new `findForUpdateByFundingAttemptId()` (`BusinessUsageAddonPurchaseRepository`, added by this correction). Both are single-row, primary-key-or-unique-key-scoped `SELECT ... FOR UPDATE` calls — the cheapest possible row lock, held only for the duration of a two-statement local transaction (state/status update + transition insert), never across an outbound provider HTTP call. This directly mirrors the reasoning the Reconciliation-Race Correction Contract already established for *not* locking its own read (§3 of that contract) — the difference here is that this lock is genuinely single-row and held only across two local writes, never across the provider call that always precedes it in every caller, so the "lock-hold-duration-across-a-provider-call" risk that reasoning warned against never arises, regardless of how many callers happen to contend for it.
- **Winner/recovery disambiguation, explicit — corrected in Correction Round 1: this is no longer performed by branching on the credit call's own outcome.** The credit call's own `UniqueConstraintViolationException` (or its absence) determines only whether *this* caller also needs to run its own credit attempt — it is never used to decide whether this caller must finalize. That decision is made **only** by the lock-protected re-read inside `finalizeFundingAttemptState()` (`$locked->state === Succeeded` / `completeAddonPurchaseUnderLock()`'s own `$locked->status === Completed`) — the only point in this design that is genuinely exclusive across every concurrent or replayed caller, since it is the only step guarded by a row lock, and every caller — regardless of its own credit outcome — passes through it. This is what guarantees exactly one finalization (§4 guarantee 3) regardless of how many callers raced, in what order, or how many replays occur.
- **Crash-recovery behavior, explicit (guarantee 7) — strengthened by this exceptional correction, per its own point 6:** because credit (or add-on fulfillment), attempt-state mutation, and transition insertion are now atomic at the outermost transaction boundary, the specific window guarantee 7 originally named — "a crash *between* financial credit and attempt-state finalization" — **no longer exists at all**: there is no longer any point at which credit can be durably committed while finalization is not. A crash before the outer transaction commits rolls **everything** back — the credit included — and a later replay observes a genuinely fresh, non-terminal attempt and retries the entire sequence normally, with no unique-key collision to absorb at all. A crash after the outer transaction commits exposes the credit and the `Succeeded` attempt **together**, durably, with nothing left to recover. The one remaining, pre-existing scenario the shared-finalizer/unique-collision path still exists to handle is a **genuinely concurrent** caller — one whose own outer transaction commits independently, in full, while another caller's own outer transaction is still in flight: that caller's own credit attempt collides against the already-committed row exactly as before, is caught, and its own call to the shared finalizer correctly observes the now-terminal state under lock and defers. This is guarantee 3's own race, not guarantee 7's own crash window — the two are no longer the same concern under this design, where they were conflated before.
- **A crash between the finalization transaction's two statements (state/status update, then transition insert) is not newly introduced by this correction and is not claimed to be closed by it** — wrapping both in one local `DB::transaction()` (new in this design, for every call to the shared finalizer) makes this specific two-statement gap atomic where it previously was not, as a direct, low-cost consequence of the restructuring — but this is a bounded strengthening bundled with the fix, not a separately-scoped guarantee.
- **AddonPurchase's own `direct_deliverable` fulfillment mode is protected by the lock alone**, with no credit-based first-pass filter (§2.2) — this is a deliberate, uniform design choice: the alternative (leaving `direct_deliverable` unprotected because it "has no financial credit at stake") would leave a known race in a method this contract was already committed to correcting for its sibling `wallet_credit` mode, for the sake of avoiding one single-row lock acquisition in the common case.

---

## 4. Guarantee-by-guarantee mapping

1. **A customer return racing a webhook never produces an HTTP 500 merely because the payment was already confirmed.** `UsageBillingTopUpController::confirmFromReturn()` is unmodified and still has zero exception handling — this guarantee holds because `confirmSucceeded()` (§2.1) never lets `UniqueConstraintViolationException` propagate past itself for *any* caller. Proven directly at the controller/HTTP layer in §5.1 item 5 (below), not merely inferred.
2. **Exactly one financial credit exists.** Unchanged — the ledger `correlation_key` unique constraint (§1, §3) is the same, already-existing mechanism; this correction does not touch it.
3. **Exactly one genuine terminal Succeeded transition exists.** New, and the specific defect this correction eliminates: **every** caller — winner, loser, and AddonPurchase after its own fulfillment step alike — performs its finalization only through the one shared `finalizeFundingAttemptState()`, only under its row lock, and only if the persisted state is not already terminal (§2.1, corrected in Correction Round 1 item 1) — so at most one caller, ever, for a given attempt, executes the transition-insert statement, regardless of the relative timing between any caller's own credit outcome and any other caller's own finalization call. §1's own two-transition finding is fully closed, not merely relabeled as accepted.
4. **Exactly one `BusinessFundingAttemptSucceeded` dispatch decision exists.** A direct consequence of guarantee 3: `confirmSucceeded()` (§2.1) dispatches only when `finalizeFundingAttemptState()` returns `true`, for every purpose including AddonPurchase (corrected in Correction Round 1 item 2) — so the dispatch site is reached only by whichever single caller performs the finalization.
5. **Exactly one `SendReceiptNotification` dispatch decision exists, and — corrected by this exceptional post-merge implementation correction — that dispatch decision now genuinely succeeds when it runs.** The *dispatch-count* half of this guarantee was already correct and remains unchanged: `creditFromFunding()`'s own internal `->afterCommit()` dispatch (`UsageWalletManager.php:951-952`) already only ever fires for the winning credit call, since the losing call's own transaction never commits far enough to reach it (PR #143 §3 item 5 already established this). What earlier rounds of this contract did not verify — because doing so requires running the real job against a real, non-faked queue, not merely counting dispatches — is whether the job, once it does run, observes a persisted attempt state consistent with its own correct precondition. §3's own new outer-transaction explanation is what closes that gap: `->afterCommit()`'s own callback is deferred until the *outermost* transaction commits, so by the time `SendReceiptNotification::handle()` ever runs, `finalizeFundingAttemptState()`'s own write has already committed in the same, single commit — the attempt is genuinely `Succeeded` before the job can possibly observe it.
6. **Every concurrent/replayed caller receives or derives the correct final persisted result.** `confirmAttemptFromReturn()`'s and `confirmAttemptFromWebhook()`'s own return-value/no-return-value construction after calling `confirmSucceeded()` is unmodified — both already unconditionally treat a call that didn't throw as a success, using `FundingAttemptState::Succeeded` directly rather than re-reading the (possibly stale) `$attempt` object. Since this correction's entire effect is "never throw, ever, for a caller that ends up on the losing or recovery side," this guarantee is a mechanical consequence of guarantees 1 and 3-5, requiring zero caller-level change — confirmed by direct read of every caller's own post-call code.
7. **A crash between financial credit and attempt-state finalization remains recoverable on replay — strengthened by this exceptional correction: that specific window no longer exists at all.** The original defect §1 independently identified (the pre-existing production code finalizes state *before* crediting, so a crash in that window is unrecoverable and silent) is closed by reordering to credit-first. This exceptional correction goes further: because credit (or add-on fulfillment) and finalization are now atomic at one outer transaction boundary (§2.1, §3), a crash can no longer land *between* them at all — it either rolls back everything (nothing committed, a replay retries fresh, no collision to absorb) or nothing is lost (the outer transaction fully committed). The shared, locked finalizer's own recovery path (§2.1, §2.2) remains exactly as important as before for the *separate* concern it was always built for — a genuinely concurrent caller whose own credit collides against another caller's own, independently-committed row — but it is no longer the mechanism recovering from a crash inside this specific window, because that window is gone.
8. **AddonPurchase, ManualTopUp, and AutoRecharge semantics remain correct.** ManualTopUp/AutoRecharge: §2.1's design produces byte-identical observable behavior for the non-racing case (single caller, no crash) — the credit still happens, the state still transitions, the event still dispatches, in every test already covering this path (§5). AddonPurchase: §2.2's own fulfillment step is unchanged from the initial draft; Correction Round 1 item 2 additionally ensures the funding attempt's own state is now genuinely finalized for this purpose too (never true of the initial draft's own design, §2's own record above) — re-verified against every existing crash/replay/idempotency test in `AddonPurchaseTransitionAuditTest.php` (§5) and confirmed compatible without modifying any of their own assertions. `direct_deliverable`'s own no-wallet-mutation, no-invented-delivery-mechanism behavior is unchanged — only its completion-mutation step is lock-protected (§2.2, §3), exactly as in the initial draft.
9. **Genuine unrelated failures are not broadly swallowed.** Both new catches (§2.1, §2.2) are scoped to exactly `Illuminate\Database\UniqueConstraintViolationException` — the same precedent `finalizeAddonPurchaseIfPending()`'s own pre-existing catch and the Reconciliation-Race Correction's own job-level catch both already establish. No bare `\Exception`/`\Throwable` is introduced anywhere by this design. A different failure (a provider-gateway exception, `UsageWalletNotFoundException`, a genuine unrelated `QueryException`) is a different exception type entirely and propagates out of `confirmSucceeded()`/`finalizeAddonPurchaseIfPending()` exactly as it does today, unchanged.
10. **`ReconcileProviderPendingState`'s existing catch remains as defense-in-depth, no longer relied upon to tolerate duplicate terminal mutations.** `app/Jobs/Usage/ReconcileProviderPendingState.php` is not on this contract's own production allow-list (§6) — its `try { confirmAttemptFromReturn($attempt); } catch (UniqueConstraintViolationException) { continue; }` (unchanged since PR #143) remains exactly as written. Its role is re-characterized, not its code: before this correction, it was one of the only things standing between a genuinely simultaneous collision and an aborted reconciliation run *and* the only thing preventing that specific collision from producing more than the (previously accepted) two transition rows; after this correction, the collision it catches can no longer produce a duplicate terminal mutation at all — regardless of whether this job's own catch fires — because the shared `finalizeFundingAttemptState()` (§2.1) already guarantees that outcome for every caller, including this one.

---

## 5. Test plan — stale assertions identified, exact new coverage locked

**Stale assertion identified, per this contract's own explicit instruction: `tests/Feature/Usage/ReconcileProviderPendingStateTest.php`'s `test_a_true_duplicate_credit_race_is_caught_by_the_jobs_own_exception_boundary_and_reconciliation_continues_to_later_attempts()` (added by PR #143) currently asserts:**

```php
$succeededTransitionCount = DB::table('business_funding_attempt_transitions')
    ->where('funding_attempt_id', $racedAttemptId)
    ->where('to_state', FundingAttemptState::Succeeded->value)
    ->count();
$this->assertSame(2, $succeededTransitionCount, 'Exactly two succeeded transition rows must exist for the raced attempt — the known, accepted Tier 2 residual side effect (§3 item 2), not a hidden defect.');
```

**Corrected under this contract's own implementation to:**

```php
$succeededTransitionCount = DB::table('business_funding_attempt_transitions')
    ->where('funding_attempt_id', $racedAttemptId)
    ->where('to_state', FundingAttemptState::Succeeded->value)
    ->count();
$this->assertSame(1, $succeededTransitionCount, 'Exactly one succeeded transition row must exist for the raced attempt — the Funding Confirmation Concurrency Correction closes the residual duplicate-transition defect PR #143 disclosed; the job\'s own re-fetch skip and confirmSucceeded()\'s own shared, always-locked finalizer now jointly guarantee this.');
```

The surrounding test docblock/method-comment language referencing "the known, accepted Tier 2 residual side effect" is corrected in the same pass to describe the now-closed defect in the past tense, cross-referencing this contract. No other assertion in this file changes — the job's own eligibility-recheck skip (test 1), the exactly-one-ledger-credit assertion (test 2), the exactly-one-event/exactly-one-receipt assertions (test 2), the final-`Succeeded`-state assertion (test 2), the later-attempt-still-reconciles assertion (test 2), and test 3's unrelated-exception-propagates design are all independently re-confirmed, by direct trace against §2's design, to remain correct and unaffected.

**No other existing test file's assertions were found to be stale.** `AddonPurchaseTransitionAuditTest.php`'s three existing crash/replay/idempotency tests were each independently traced against §2.2's new design (§1's own "genuinely different failure mode" note) and confirmed to produce byte-identical outcomes — none is modified.

### 5.1 New file: `tests/Feature/Usage/FundingConfirmationConcurrencyCorrectionTest.php`

1. **`test_a_true_duplicate_credit_race_between_two_confirmation_callers_produces_exactly_one_transition_event_and_receipt_dispatch`** — the direct, single-process proof of guarantees 1, 3, 4, 5, 6, using two plain sequential calls (no interleaving mock). No repository mock is needed here (unlike PR #143's own reconciliation-job test, which needed one because the job's own loop performs its own internal re-fetch) — this test calls the shared method directly. Creates one stuck-eligible ManualTopUp attempt with a registered verified checkout outcome; fetches `$winner` and `$loser` as two **separately-fetched** `BusinessFundingAttempt` instances (confirmed necessary for the same reason established in the Reconciliation-Race Correction: `EloquentBusinessFundingAttemptRepository::update()`'s own `fill()`+`save()` mutates its input in place). `Event::fake([BusinessFundingAttemptSucceeded::class])`, `Queue::fake()`. Calls `$checkoutManager->confirmAttemptFromReturn($winner)`, then `$checkoutManager->confirmAttemptFromReturn($loser)` — **both calls made with no test-owned `try`/`catch` around either** — asserting directly that the second call does not throw. Asserts: exactly one `business_usage_ledger_entries` row; **exactly one** `business_funding_attempt_transitions` row with `to_state = 'succeeded'` (the corrected invariant); `Event::assertDispatchedTimes(BusinessFundingAttemptSucceeded::class, 1)`; `Queue::assertPushed(SendReceiptNotification::class, 1)`; the loser call's own returned `FundingAttemptResult->state === FundingAttemptState::Succeeded`; the attempt's final persisted state is `Succeeded`. **This test alone does not exercise the Correction Round 1 item 1 interleaving** — in this ordering, A's own finalization runs to completion (uncontended) before B's own call even begins, so it cannot, by itself, distinguish the corrected shared-locked design from the initial draft's own buggy one. Item 2 below closes that gap.
2. **`test_the_credit_winner_safely_defers_when_the_recovery_caller_finalizes_first`** — the deterministic proof of the exact interleaving the review identified in Correction Round 1, **redesigned in Correction Round 2** to intercept `UsageWalletManager::creditFromFunding()` instead of `BusinessFundingAttemptRepository::findForUpdateById()`, and **further corrected by this exceptional post-merge implementation correction's own focused review fix** (see this correction's own record, above) to accurately describe its transaction mechanics under the new outer `DB::transaction()` (§2.1). The prior interception point (`findForUpdateById()`) existed only inside the corrected, always-locked finalizer — against the round-0 design's own unlocked winner path (which never calls `findForUpdateById()` at all), the interception would never have fired, so caller B would never have been scheduled. Intercepting `creditFromFunding()` instead — a call every version of `confirmSucceeded()`, round-0, round-1, or the current design, has always made — closes that gap. **Under the current, corrected design, this single-process Mockery test proves the shared finalizer's own deterministic ordering/idempotency behavior across nested transaction/savepoint writes on one connection — it is not, and is no longer characterized as, a proof of genuinely independent, separately-committed transactions.** Because `confirmSucceeded()`'s own body (§2.1) now opens one outer `DB::transaction()` before ever reaching this interception, caller A's own delegated credit executes as a nested savepoint inside that still-open outer transaction, and caller B's own entire confirmation — invoked synchronously from inside the same interception closure, on the same connection — runs nested one level deeper still, inside A's own outer transaction, never as a separately committed sequence. **§5.3 item 8, which races two independent OS processes on separate database connections, is this contract's own genuine independent-transaction concurrency proof; this test proves ordering/idempotency, not independent commit.**

   **Container-resolution order, explicit:** (1) `$realWalletManager = app(UsageWalletManager::class)` — resolved *first*, while the container's own default binding is still in effect, giving a fully real, working instance used as the delegate for every genuine credit call. (2) Each of `UsageWalletManager`'s own twelve constructor dependencies is resolved individually via the container, in exactly the order its own constructor declares them (confirmed by direct read, `UsageWalletManager.php:69-83`): `BusinessUsageWalletRepository`, `BusinessUsageRateRepository`, `BusinessUsageRateActivationRepository`, `UsageMeterRepository`, `UsageMeterTransitionRepository`, `BusinessUsageReservationRepository`, `BusinessUsageLedgerEntryRepository`, `BusinessFeatureUsageLimitRepository`, `PlatformFeatureUsageSafetyLimitRepository`, `BusinessUsageLimitTransitionRepository`, `BusinessUsageWalletBillingStatusTransitionRepository`, `BusinessBillingReceiptRepository` — all twelve resolved via `app(InterfaceName::class)`. (3) The mock is constructed as `Mockery::mock(UsageWalletManager::class, [/* the twelve resolved dependencies above, in that exact order */])->makePartial()`. **Valid Mockery constructor initialization, per the exceptional post-review correction's own established precedent:** passing the constructor-argument array routes `Mockery\Container::_getInstance()` through `(new ReflectionClass($mockName))->newInstanceArgs($constructorArgs)` rather than the constructor-skipping `Instantiator` path used when no array is supplied, so every one of the twelve injected properties is genuinely set — required here because, unlike the single-dependency `EloquentBusinessFundingAttemptRepository` case the exceptional correction addressed, an incorrectly-constructed mock would leave twelve properties null, and any accidentally-unstubbed method call on this mock (there should be none, but `makePartial()`'s own safety net exists precisely for that case) would otherwise fail against a null property instead of delegating correctly. (4) The mock is bound via `$this->app->instance(UsageWalletManager::class, $mock)` — **before** anything resolves `UsageBillingCheckoutManager` — so its own constructor-injected `$walletManager`, for both caller A's own outer checkout manager and caller B's own, separately resolved inside the interception closure, is this same mock.

   **Recursion guard, explicit:** a single, closure-captured `$hasIntercepted = false` flag, set to `true` on the stub's first invocation, before caller B is ever run. `$mock->shouldReceive('creditFromFunding')->andReturnUsing(function (...$args) use (&$hasIntercepted, $realWalletManager, $racedAttemptId) { ... })` — no `->with()`/`->once()` argument-matching is needed; the single closure branches on the flag. **If already `true`** (caller B's own nested credit call, or any call after the first), it delegates directly to `$realWalletManager->creditFromFunding(...$args)` and returns — this is what prevents B's own nested credit attempt from re-triggering "run caller B" recursively. **On the first call only** (caller A's own): (a) sets the flag; (b) delegates to `$realWalletManager->creditFromFunding(...$args)`, inserting that credit as a nested savepoint write within A's own still-open outer transaction — visible, in-transaction, on this same connection, though not yet durably committed until A's own outer transaction itself later commits; (c) fetches `$loser = app(BusinessFundingAttemptRepository::class)->findById($racedAttemptId)` — a fresh, independent, still-`ProviderPending` snapshot, since nothing has touched `attempt.state` yet in either the round-0, round-1, or current design at this point in the sequence; (d) runs caller B's **entire** confirmation to completion — `app(UsageBillingCheckoutManager::class)->confirmAttemptFromReturn($loser)` — whose own nested credit call hits this same stub, sees the flag already `true`, delegates to the real manager, and collides against A's own already-inserted (not yet outer-committed) row on the ledger's own `correlation_key`: MySQL/InnoDB's own duplicate-key check honors a connection's own uncommitted writes, so this collision is genuine even though A's own outer transaction has not yet committed. It throws `UniqueConstraintViolationException`, is caught by `confirmSucceeded()`'s own catch, and falls through to the shared `finalizeFundingAttemptState()`, which finds the attempt still non-terminal (B's own view, same connection, sees no state write yet — A has not reached its own finalizer call at this point) and finalizes it for real, itself as a nested savepoint write; (e) returns, letting control flow back to caller A's own, still-paused, outer call.

   Creates one stuck-eligible ManualTopUp attempt (`$racedAttemptId`) with a registered verified checkout outcome. `Event::fake([BusinessFundingAttemptSucceeded::class])`, `Queue::fake()`. Calls `$checkoutManager->confirmAttemptFromReturn($winner)` for caller A — a single, self-contained call: A's own credit is inserted, as a nested savepoint within A's own still-open outer transaction, inside the interception; caller B's entire confirmation runs and finalizes to completion, itself nested inside that same outer transaction, before the interception returns; A then resumes its own `confirmSucceeded()`, reaches the shared `finalizeFundingAttemptState($winner, ...)`, and — **under the current, approved design** — finds the row already `Succeeded` under lock (visible in-transaction, on the same connection, regardless of outer-commit status) and safely defers. Asserts: the call does not throw; exactly one `business_usage_ledger_entries` row; **exactly one** `business_funding_attempt_transitions` row with `to_state = 'succeeded'`; `Event::assertDispatchedTimes(BusinessFundingAttemptSucceeded::class, 1)`; `Queue::assertPushed(SendReceiptNotification::class, 1)`; the attempt's final persisted state is `Succeeded`.

   **Retroactive validation against the round-0 design, preserved and clarified by this fix.** Under round 0 — before this exceptional correction ever introduced the outer transaction — `confirmSucceeded()` opened no outer transaction of its own, so this same interception would instead fire only after A's own credit had already, genuinely, independently committed, exactly as this test originally described. Caller B's own locked recovery path finalizes first (state `Succeeded`, one transition, one dispatch) exactly as in round-1, since round-0's own loser/recovery path was already locked — the two designs differ only in the *winner's* own path. Caller A then resumes into round-0's own *unlocked* `finalizeFundingAttemptState()`, which reads only its own stale in-memory `$winner->state` (still `ProviderPending` — `$winner` was fetched before any of this happened and is never refreshed) and unconditionally writes a second transition and dispatches a second event — the identical sequence produces two transition rows and two event dispatches against round-0. **Under the current, corrected design, by contrast, the identical interception instead proves the shared finalizer's own deterministic ordering/idempotency behavior inside the new atomic outer transaction boundary (§2.1) — A's credit and B's full confirmation both execute as nested writes within A's own single outer transaction, and it is the shared finalizer's own lock-protected, state-checked no-op, not any independent commit boundary, that produces exactly one transition and one dispatch.** This is what keeps this test's own retroactive comparison against round-0 meaningful even though the underlying transaction mechanics it exercises have changed: round-0 fails this test for its own, independent reason (the unlocked winner path re-finalizes unconditionally) regardless of which transaction model — independent commits or nested savepoints — is in effect.
3. **`test_a_crash_between_credit_and_state_finalization_is_completed_exactly_once_on_replay`** — the direct proof of guarantee 7, and of §1's own independently-discovered crash-recovery finding. Creates one stuck-eligible ManualTopUp attempt; calls `app(UsageWalletManager::class)->creditFromFunding(...)` **directly**, bypassing `confirmSucceeded()` entirely, using the attempt's own exact `local_idempotency_key.':credit'` correlation key — this durably commits the credit while leaving the attempt's own persisted `state` at `ProviderPending`, precisely simulating a crash in the exact window §1 identified. Registers a verified checkout outcome, then calls `$checkoutManager->confirmAttemptFromReturn($attempt)` as a normal replay (a real customer retry, or a redelivered webhook, would look identical). Asserts: the call does not throw; exactly one `business_usage_ledger_entries` row (still — the replay's own credit attempt collides and is absorbed); **exactly one** succeeded transition row (written by *this* replay's own call to the shared finalizer, since none existed before it); exactly one `BusinessFundingAttemptSucceeded` dispatch; the attempt's final persisted state is `Succeeded` — proving the attempt does **not** remain permanently stuck, which is what today's code would do.
4. **`test_a_genuinely_simultaneous_double_confirmation_of_an_addon_purchase_produces_exactly_one_completion_transition`** — the AddonPurchase-branch sibling of test 1, at the same level of directness; strengthened in Correction Round 1 to also assert the funding-attempt-level outcome Correction Round 1 item 2 introduces. Creates one stuck-eligible `wallet_credit` AddonPurchase attempt with a registered verified checkout outcome; fetches `$winner`/`$loser` as two separately-fetched instances. `Event::fake([BusinessFundingAttemptSucceeded::class])`, `Queue::fake()`. Calls `confirmAttemptFromReturn($winner)` then `confirmAttemptFromReturn($loser)` directly, no test-owned `try`/`catch`. Asserts: the second call does not throw; exactly one ledger credit row; exactly one row in `business_usage_addon_purchase_transitions` for this purchase (via `app(BusinessUsageAddonPurchaseTransitionRepository::class)->forPurchase($purchaseId)`, the same helper `AddonPurchaseTransitionAuditTest.php` already uses); the purchase's final persisted `status` is `Completed`; exactly one `SendReceiptNotification` dispatch — **and, new this round:** the funding attempt's own final persisted `state` is `Succeeded`; exactly one `business_funding_attempt_transitions` row with `to_state = 'succeeded'` for this attempt; `Event::assertDispatchedTimes(BusinessFundingAttemptSucceeded::class, 1)`.
5. **`test_the_customer_return_controller_endpoint_returns_the_normal_success_redirect_when_the_attempt_was_already_confirmed_by_a_concurrent_caller`** — the end-to-end, HTTP-layer proof of guarantee 1 specifically, not merely a transitive inference from test 1. Uses the same constructor-initialized Mockery partial-mock technique the exceptional post-review correction established for PR #143's own test 2 (`Mockery::mock(EloquentBusinessFundingAttemptRepository::class, [new BusinessFundingAttempt()])->makePartial()`, bound as `BusinessFundingAttemptRepository::class`, `findById()` stubbed for the one attempt's own id to confirm a separately-fetched winner for real before returning a still-stale loser) — but this time the *controller's own* `findById()` call (`UsageBillingTopUpController.php:80`) is what receives the stale loser, and the HTTP request itself — `$this->get(route('customer.workspaces.businesses.usage-billing.top-up.confirm', [$workspaceUid, $businessUid, $attempt->id]))`, the exact route-name-and-parameter-order pattern `UsageBillingDashboardAuthorizationTest::test_top_up_confirm_route_rejects_a_cross_business_attempt()` already establishes verbatim — is the thing under test. Asserts: the HTTP response is the normal success redirect (`assertRedirect(...)`) carrying the `flash_success` session key, **not** a 500-class response and not the `flash_error` key; exactly one ledger credit; exactly one succeeded transition row.
6. **`test_a_normally_confirmed_funding_attempt_dispatches_its_receipt_notification_only_after_the_attempt_is_durably_succeeded`** — added by the exceptional post-merge implementation correction; the direct regression proof for that correction itself. Proves `SendReceiptNotification`'s own `->afterCommit()` dispatch, under this test suite's own standing `QUEUE_CONNECTION=sync` (`.env.testing`), only ever executes once the outer transaction from §2.1 has committed the credit and the finalized `Succeeded` state together — not merely that a matching dispatch count is fired (items 1, 3, and 4 above already assert that), but that the job that fires actually succeeds when it runs. Deliberately does **not** call `Queue::fake()`, unlike every other test in this file — the whole point is to let the real sync-queue job execute inline. Creates one normal, single, uncontested, eligible ManualTopUp attempt via `businessWithProviderCustomer()`, registers a verified checkout outcome via `registerVerifiedCheckoutOutcome($attempt)` — the same fixture/provider-evidence helper items 1-5 above already use, no new mechanism — then calls `$checkoutManager->confirmAttemptFromReturn($attempt)` directly, exactly once, with no concurrent second caller. Asserts: the call does not throw (specifically, no `RuntimeException` from `SendReceiptNotification::handle()`'s own precondition — the exact exception this correction's own regression produced); the attempt's final persisted `state` is `Succeeded`; exactly one `business_usage_ledger_entries` row for the attempt; exactly one `business_funding_attempt_transitions` row with `to_state = 'succeeded'`; exactly one `business_billing_receipts` row for the credited ledger entry (`ReceiptBoundaryTest.php`'s own existing assertion shape), proving `SendReceiptNotification::handle()` genuinely ran to completion, synchronously, inline, and observed the attempt already `Succeeded`.

### 5.2 `tests/Feature/Usage/AddonPurchaseTransitionAuditTest.php` — 1 new method added

7. **`test_a_genuinely_simultaneous_double_confirmation_of_a_direct_deliverable_addon_purchase_completes_exactly_once`** — proves §2.2's own uniform lock protection for the fulfillment mode with no credit-based first-pass filter; strengthened in Correction Round 1 with the same funding-attempt-level assertions as item 4. Creates one stuck-eligible `direct_deliverable` AddonPurchase attempt (mirroring `test_direct_deliverable_addon_purchase_dispatches_no_receipt_notification`'s own fixture). `Event::fake([BusinessFundingAttemptSucceeded::class])`. Fetches `$winner`/`$loser` as two separately-fetched instances; calls `confirmAttemptFromReturn($winner)` then `confirmAttemptFromReturn($loser)` directly. Asserts: the second call does not throw; the purchase's final `status` is `Completed`, `completed_at` set exactly once; exactly one row in `business_usage_addon_purchase_transitions` for this purchase; zero ledger entries and zero receipt dispatches (unchanged — `direct_deliverable` still performs no wallet mutation of any kind, per guarantee 8) — **and, new this round:** the funding attempt's own final persisted `state` is `Succeeded`; exactly one `business_funding_attempt_transitions` row with `to_state = 'succeeded'` for this attempt; `Event::assertDispatchedTimes(BusinessFundingAttemptSucceeded::class, 1)`.

### 5.3 `tests/Feature/Usage/ConcurrentTopUpConcurrencyTest.php` — 1 new method added

8. **`test_two_genuinely_concurrent_processes_confirming_the_same_attempt_produce_exactly_one_ledger_credit_and_transition`** — the true, OS-level-concurrency complement to §5.1 test 1, reusing this file's own already-established subprocess/signal-file infrastructure verbatim (`baseRunnerPreamble()`, `confirmRunnerScript()`, `phpBinary()`, the `WAITING`-handshake barrier pattern from `test_two_concurrent_confirmations_for_the_same_business_each_credit_exactly_once`) — the **only** change from that existing test's own pattern is pointing **both** child processes at the **same** `$attemptId` instead of two different ones, so they race the identical row across two real, independent OS processes rather than a single-process Mockery interleaving. Both processes call `confirmRunnerScript()` unmodified (it already independently fetches its own fresh `BusinessFundingAttempt` instance and registers its own deterministic verified checkout outcome per process). Asserts, after both processes report `DONE` (proving neither crashed or exited non-zero — the genuine, unsimulated proof that neither call ever surfaced the exception): exactly one `business_usage_ledger_entries` row for the attempt; exactly one `business_funding_attempt_transitions` row with `to_state = 'succeeded'`; the wallet's own `available_balance_micro` reflects the credit exactly once. Because durable DB state, not in-memory fakes, is the only thing observable across a process boundary, this test does not attempt to assert exact event/queue dispatch counts (§5.1 test 1 and test 3 already do, deterministically, in-process) — it exists specifically to prove the design holds under genuine, not simulated, simultaneity.

### 5.4 Required new imports, by file

- `FundingConfirmationConcurrencyCorrectionTest.php` (new file): `App\Enums\Usage\FundingAttemptState`, `App\Enums\Usage\PayerType`, `App\Events\Usage\BusinessFundingAttemptSucceeded`, `App\Jobs\Usage\SendReceiptNotification`, `App\Library\Usage\BillingProfileManager`, `App\Library\Usage\CheckoutSessionResult`, `App\Library\Usage\Contracts\PaymentProviderGateway`, `App\Library\Usage\FakePaymentProviderGateway`, `App\Library\Usage\PaymentInstrumentManager`, `App\Library\Usage\PaymentMethodResult`, `App\Library\Usage\UsageBillingCheckoutManager`, `App\Library\Usage\UsageWalletManager`, `App\Models\BusinessFundingAttempt`, `App\Models\Currency`, `App\Repositories\Contracts\BusinessFundingAttemptRepository`, `App\Repositories\Contracts\BusinessUsageAddonPurchaseTransitionRepository`, `App\Repositories\Eloquent\EloquentBusinessFundingAttemptRepository`, `Illuminate\Database\UniqueConstraintViolationException`, `Illuminate\Foundation\Testing\RefreshDatabase`, `Illuminate\Support\Facades\DB`, `Illuminate\Support\Facades\Event`, `Illuminate\Support\Facades\Queue`, `Mockery`, `Tests\Feature\Business\Concerns\CreatesBusinessTestData`, `Tests\TestCase` — **plus, added in Correction Round 2 for item 2's own redesign**, the twelve `App\Repositories\Contracts\` interfaces `UsageWalletManager`'s own constructor declares, each needed to construct its properly-initialized partial mock: `BusinessUsageWalletRepository`, `BusinessUsageRateRepository`, `BusinessUsageRateActivationRepository`, `UsageMeterRepository`, `UsageMeterTransitionRepository`, `BusinessUsageReservationRepository`, `BusinessUsageLedgerEntryRepository`, `BusinessFeatureUsageLimitRepository`, `PlatformFeatureUsageSafetyLimitRepository`, `BusinessUsageLimitTransitionRepository`, `BusinessUsageWalletBillingStatusTransitionRepository`, `BusinessBillingReceiptRepository` (all confirmed, `App\Repositories\Contracts\` namespace, by direct read of `UsageWalletManager.php:69-83` and `app/Providers/AppServiceProvider.php`'s own binding array) — **plus, added by this exceptional post-merge implementation correction for item 6's own new method: no new imports at all.** Item 6 calls only `businessWithProviderCustomer()`, `registerVerifiedCheckoutOutcome()`, and `$checkoutManager->confirmAttemptFromReturn()` — all already-imported, already-used symbols — and reads `business_billing_receipts` via the already-imported `Illuminate\Support\Facades\DB` facade, the same shape `ReceiptBoundaryTest.php` already uses.
- `AddonPurchaseTransitionAuditTest.php`: no new imports — every symbol the new method needs (`AddonPurchaseStatus`, `BusinessUsageAddonPurchaseTransitionRepository`, `Queue`) is already imported.
- `ConcurrentTopUpConcurrencyTest.php`: no new imports — the new method reuses `confirmRunnerScript()`, `baseRunnerPreamble()`, `phpBinary()`, and every fixture helper verbatim.
- `ReconcileProviderPendingStateTest.php`: no new imports — only the one assertion's expected value and its message string change.

---

## 6. Exact production allow-list

| # | Path | Status | Reason |
|---|---|---|---|
| 1 | `app/Library/Usage/UsageBillingCheckoutManager.php` | REQUIRED | `confirmSucceeded()` unified across every purpose around one shared, always-locked finalizer (§2.1, corrected in Correction Round 1) — one new private helper, `finalizeFundingAttemptState()`, invoked identically by the credit winner, the credit loser/recovery caller, and AddonPurchase after its own fulfillment step, no unlocked fast path for any of them; `finalizeAddonPurchaseIfPending()` unchanged from the initial draft (§2.2) — one new private helper, `completeAddonPurchaseUnderLock()`, used uniformly by both its winner and loser paths. **Further corrected by this exceptional post-merge implementation correction:** `confirmSucceeded()`'s own body is now wrapped in one outer `DB::transaction()` around the credit/completion call and the `finalizeFundingAttemptState()` call together (§2.1), closing the independently reproduced `SendReceiptNotification` precondition regression without touching any other file. |
| 2 | `app/Repositories/Contracts/BusinessUsageAddonPurchaseRepository.php` | REQUIRED | One new interface method, `findForUpdateByFundingAttemptId(int $fundingAttemptId): ?BusinessUsageAddonPurchase` (§2.2, §3). |
| 3 | `app/Repositories/Eloquent/EloquentBusinessUsageAddonPurchaseRepository.php` | REQUIRED | Implements the new method (§2.2), mirroring `EloquentBusinessFundingAttemptRepository::findForUpdateById()`'s own existing shape. |

**NOT_REQUIRED, explicitly confirmed:**

| Path | Reason |
|---|---|
| `app/Http/Controllers/Customer/Business/UsageBillingTopUpController.php` | Zero lines changed — the entire point of a shared-root correction (§2). `confirmFromReturn()` never receives `UniqueConstraintViolationException` any more; no try/catch is ever needed. |
| `app/Jobs/Usage/ProcessPaymentProviderEvent.php` | Zero lines changed — its own pre-existing broad `catch (\Throwable $e)` remains, unmodified, as general-purpose defense-in-depth; it is simply never triggered by this specific cause any more. |
| `app/Jobs/Usage/ReconcileProviderPendingState.php` | Zero lines changed — per guarantee 10 (§4), its own `catch (UniqueConstraintViolationException) { continue; }` (PR #143) remains exactly as written, re-characterized as defense-in-depth only, not relied upon. |
| `app/Repositories/Contracts/BusinessFundingAttemptRepository.php` / its Eloquent implementation | `findForUpdateById()` already exists (confirmed, §0's required reading) and is reused verbatim; no new method needed here. |
| `app/Library/Usage/UsageWalletManager.php` | Zero lines changed — `creditFromFunding()`'s own transaction, ledger insert, and `correlation_key` uniqueness are the same, already-correct, already-relied-upon mechanism this correction builds on top of, not inside of. |
| Any migration or schema file | No schema change — the ledger `correlation_key` `UNIQUE` constraint and the addon-purchase `funding_attempt_id` `UNIQUE` constraint this correction relies on both already exist, already shipped, unmodified. |
| Any event, job, or notification class | No new domain event, job, or notification is introduced; `BusinessFundingAttemptSucceeded`, `SendReceiptNotification`, and every other Job/Event Dispatch Completion event dispatch from exactly the sites already locked by that contract — this correction changes only *which single caller* ever reaches those sites for a given attempt/purchase, never how the sites themselves behave. |
| Any route, controller (other than the one confirmed above), or config file | No HTTP/admin surface, no config value, is touched. |

**Exactly 3 production paths.**

## 7. Exact test allow-list

| # | Path | Status | Reason |
|---|---|---|---|
| 1 | `tests/Feature/Usage/FundingConfirmationConcurrencyCorrectionTest.php` | REQUIRED (new file) | 6 methods (§5.1: one added in Correction Round 1 — the deterministic winner-defers-to-recovery-caller interleaving; one added by this exceptional post-merge implementation correction — item 6, the direct `SendReceiptNotification`-after-durable-`Succeeded` regression proof), proving guarantees 1, 3, 4, 5, 6, 7 directly and deterministically, in-process. |
| 2 | `tests/Feature/Usage/AddonPurchaseTransitionAuditTest.php` | REQUIRED (modified existing) | 1 new method added (§5.2); all 10 existing methods unchanged, re-confirmed unaffected (§1, §5). |
| 3 | `tests/Feature/Usage/ConcurrentTopUpConcurrencyTest.php` | REQUIRED (modified existing) | 1 new method added (§5.3), reusing all existing infrastructure verbatim; all 3 existing methods unchanged. |
| 4 | `tests/Feature/Usage/ReconcileProviderPendingStateTest.php` | REQUIRED (modified existing) | 1 stale assertion corrected (§5); all 7 other existing methods unchanged. |

**Exactly 4 test paths.**

---

## 8. Regression commands — streamlined, per this contract's own testing policy

- `php artisan test tests/Feature/Usage/FundingConfirmationConcurrencyCorrectionTest.php` — the new file itself; expected 6 methods, all passing.
- `php artisan test tests/Feature/Usage/AddonPurchaseTransitionAuditTest.php` — expected 11 methods (10 unchanged + 1 new), all passing.
- `php artisan test tests/Feature/Usage/ConcurrentTopUpConcurrencyTest.php` — expected 4 methods (3 unchanged + 1 new), all passing.
- `php artisan test tests/Feature/Usage/ReconcileProviderPendingStateTest.php` — expected 8 methods (unchanged count from PR #143; only one assertion's expected value changes), all passing.
- `php artisan test tests/Feature/Usage tests/Unit/Usage` — the complete Usage domain suite.
- One complete `php artisan test --stop-on-failure` run (full suite).
- `git diff --check`.

Per this contract's own explicit instruction, the Entitlement, Workspace, Business, and Opportunity suites are **not** run separately — the full-suite gate already covers them, and this correction touches nothing in those domains. The repository's own documented six-command aggregate regression gate remains reserved for M6, not run here.

---

## 9. Deferred findings — explicitly not absorbed into this correction

**Finding A (carried forward, unchanged, already classified non-blocking).** The Job/Event Dispatch Completion PR #141 audit's own low-balance-notification-after-successful-auto-recharge timing observation remains exactly what every prior contract in this lineage recorded it as: disclosed, contract-faithful, deferred for a separate, future human decision. This contract does not widen scope to include it.

**§9 Finding B of the Reconciliation-Race Correction Contract is resolved by this contract, not deferred by it.** That finding's own binding disposition (M6 frozen until this correction is completed) is what authorized drafting this document in the first place (§0). No further disposition decision is required — completing and merging this correction, once separately authorized for implementation, satisfies it in full.

---

## 10. Excluded scopes — restated

This correction does not implement, design, or absorb any of the following:

- Admin Usage Billing Surface (remediation #5), Provider Refund/Dispute Outcome Handling (remediation #6), residual §35-only cleanup (remediation #7) — all remain untouched, in their existing sequence position after this correction.
- The low-balance-notification timing observation (§9 Finding A) — carried forward, unresolved, non-blocking.
- Any change to `creditFromFunding()`'s own internal transaction, wallet-row locking, or ledger-entry shape — confirmed unaffected and unmodified (§2, §3, §6).
- Any change to webhook validation, provider-object verification, Checkout Session/PaymentIntent retrieval, or receipt evidence retrieval — all confirmed unaffected (§2's design touches only the post-verification finalization sequence).
- `AdvanceUsagePeriodBoundaries` — remains OPTIONAL_NON_BLOCKING / NOT IMPLEMENTED per the Job/Event Dispatch Completion contract's own §2, unaffected here.
- M6 conformance/deployment docs; the release tag; Conversations pilot activation; tax/VAT implementation; legacy invoices.
- Any migration or schema change.

**Explicitly, for this exceptional post-merge implementation correction:** no change to `SendReceiptNotification.php`, `UsageWalletManager.php`, any schema/migration, any queue configuration (`QUEUE_CONNECTION` or otherwise), any controller, any job other than the unmodified `SendReceiptNotification`, any event, any route, or any path whatsoever outside the exact 3 production paths already locked in §6 is authorized by this correction. §6 and §7's allow-lists are unchanged in membership from the already-merged contract; only §6 row 1's own reason text and §7 row 1's own method count are updated to describe the outer-transaction fix and the new test method.

Do not reopen Reservation Admission, Funding Provider-Flow, Receipt Boundary, Job/Event Dispatch Completion, or the Reconciliation-Race Correction — none is touched, contradicted, or reinterpreted anywhere above.

---

## 11. Confirmations

- **No schema/migration change is required or authorized by this correction.** Both unique constraints this correction relies on (`business_usage_ledger_entries.correlation_key`, `business_usage_addon_purchases.funding_attempt_id`) already exist, already shipped, unmodified.
- `AI-AUTONOMY-STATE.json` is untouched by this branch.
- M6 remains frozen.
- No product, test, config, route, or RFC-source file is touched by this branch. This governance branch changes exactly one file.
- No implementation has occurred. **Correction rounds: 2 of 2 consumed; 0 ordinary rounds remain. This contract has exhausted its ordinary correction-round budget.**
- **This exceptional post-merge implementation correction does not consume or alter that budget.** It is recorded in its own top-of-document section, distinct from Correction Round 1 and Correction Round 2, exactly as the Reconciliation-Race Correction's own exceptional post-review correction was recorded against its contract without touching that contract's own ordinary-round counters.
- Reservation Admission, Funding Provider-Flow, Receipt Boundary, Job/Event Dispatch Completion, and the Reconciliation-Race Correction are not reopened, contradicted, or reinterpreted anywhere above.

---

## 12. Open items

**No open item blocks authorizing or implementing this correction's own bounded scope** (§6/§7's allow-lists, §5's test design, §2's design). Both §9 findings are explicitly out of *this correction's* scope by design — Finding A remains deferred and non-blocking; the Reconciliation-Race Correction's own Finding B is resolved by this contract's own existence and eventual implementation, not by any further decision here.

**The regression independently discovered during real implementation and full regression execution against the merged contract (49 failures, `SendReceiptNotification`'s precondition rejecting a still-non-terminal attempt under credit-first ordering) is resolved by this exceptional post-merge implementation correction, not left open by it.** The outer-transaction fix (§2.1) is locked, mechanically re-verified against Laravel's own `DatabaseTransactionsManager::commit()` source, and carried through §3/§4/§5 consistently. No further disposition decision is required before implementation resumes.

**No genuine open human decision was identified during this audit.** Every design choice in §2 was derivable mechanically from the ten stated guarantees and the current, directly-read code and schema — none required a judgment call this contract deferred rather than made. Correction Round 1's own two findings were confirmed production-design defects, now closed; Correction Round 2's own finding was a confirmed defect in the test's own mechanics, not the design, and is also now closed (§5.1 item 2's own new closing paragraphs). Two smaller design choices remain, unaffected by either round, that a human reviewer could still reasonably disagree with (applying the lock uniformly to `direct_deliverable`, §2.2/§3; or wrapping the two-statement finalization in its own local transaction, §2.1/§3, as a bundled strengthening beyond the letter of guarantee 7) — either would be a disagreement to raise against this document, not an unresolved question this document itself leaves open, and not something an ordinary correction round remains available to address (§0, §11).

---
