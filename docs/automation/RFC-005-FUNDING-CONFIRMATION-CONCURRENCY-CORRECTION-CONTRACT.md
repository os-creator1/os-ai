# RFC-005 Funding Confirmation Concurrency Correction Contract

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

This document authorizes drafting one thing: a bounded, independently governed correction that closes the shared-root funding-confirmation concurrency defect first disclosed as `UsageBillingTopUpController::confirmFromReturn()`'s own uncaught-exception exposure in the merged [RFC-005 Reconciliation-Race Correction Contract](RFC-005-RECONCILIATION-RACE-CORRECTION-CONTRACT.md) §9 Finding B, and named there as a binding, mandatory pre-M6 correction (§0, §12). That contract's own implementation (merged PR [#143](https://github.com/os-creator1/os-ai/pull/143)) deliberately did not touch this defect — it closed only `ReconcileProviderPendingState`'s own caller-staleness manifestation (Tier 1), leaving `UsageBillingCheckoutManager::confirmSucceeded()`'s own lack of a persisted-state guard (Tier 2) untouched, and explicitly, honestly documented a **two-transition residual side effect** as the accepted cost of that narrower fix (PR #143 §3 item 2). **This correction exists specifically to eliminate that residual defect at its shared root — not to preserve it.**

This is **contract-authoring only**. No product code, test code, schema, route, config, or RFC-source file is touched by this branch. Reservation Admission, Funding Provider-Flow, Receipt Boundary, Job/Event Dispatch Completion, and the Reconciliation-Race Correction are closed corrections and are not reopened, contradicted, or reinterpreted by anything below — this contract narrows and completes what Reconciliation-Race deliberately left open, on its own terms. The separate, already-classified, non-blocking low-balance-notification-timing observation (Job/Event Dispatch Completion's own §2 audit finding, carried forward unchanged through every subsequent contract) is explicitly **not** absorbed here either.

---

## 0. Governance

- Drafted on branch `chore/rfc-005-funding-confirmation-concurrency-correction-contract`, in an isolated linked worktree (`../rfc-005-funding-confirmation-concurrency-correction-contract-worktree`), based on `origin/main` at `ccee46b6197dfd70980091cae97ecb283a52aed7` — the Reconciliation-Race Correction's own merge commit (PR [#143](https://github.com/os-creator1/os-ai/pull/143)), confirmed via `git fetch origin && git rev-parse origin/main` at the start of this contract.
- **Future implementation branch (authorized only after this contract's human merge, and only after a further, separate, explicit human instruction to begin implementation): `agent/rfc-005-funding-confirmation-concurrency-correction`.**
- Confirmed at drafting: no such branch exists on `origin`; `git status --short` in this worktree is empty; no product/test/config/route/RFC-source file has been touched by this branch at any point.
- Locked:
  - `human_only_merge: true`
  - `maximum_correction_rounds: 2`
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

**Locked design: both `confirmSucceeded()` and `finalizeAddonPurchaseIfPending()` are reordered to credit-first, with a row-lock-protected recovery branch for the loser/crash-recovery case. No caller of either method changes at all** — `confirmAttemptFromReturn()`, `confirmAttemptFromWebhook()`, `retryFundingAttemptAsAdministrator()`, `UsageBillingTopUpController::confirmFromReturn()`, `ProcessPaymentProviderEvent`, and `ReconcileProviderPendingState` are **all** byte-for-byte unchanged. This is deliberate and is what "not a controller-only try/catch" requires: the exception this contract's predecessor left the controller to face is now fully absorbed at its own shared root, so no caller ever needs to handle it — proving guarantee 1 is a direct, mechanical consequence of guarantee 6, not a separate patch.

### 2.1 `confirmSucceeded()` — ManualTopUp / AutoRecharge branch (the non-AddonPurchase path)

```php
private function confirmSucceeded(BusinessFundingAttempt $attempt, TransitionSource $source, ?int $providerEventId, ?int $actorUserId = null, ?string $verifiedPaymentMethodDisplay = null): void
{
    if ($attempt->purpose === FundingAttemptPurpose::AddonPurchase) {
        $this->finalizeAddonPurchaseIfPending($attempt, $source, $providerEventId); // now itself credit-first + lock-protected, §2.2
        BusinessFundingAttemptSucceeded::dispatch(...); // unchanged dispatch shape — see §2.3 for why this remains correct
        return;
    }

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
        $this->finalizeFundingAttemptStateUnderLock($attempt, $source, $providerEventId, $actorUserId, $verifiedPaymentMethodDisplay);

        return;
    }

    $this->finalizeFundingAttemptState($attempt, $source, $providerEventId, $actorUserId, $verifiedPaymentMethodDisplay);
}
```

**New private helper, the winner path (no lock — the ledger's own unique constraint already exclusively serialized this caller as the credit's one true owner):**

```php
private function finalizeFundingAttemptState(BusinessFundingAttempt $attempt, TransitionSource $source, ?int $providerEventId, ?int $actorUserId, ?string $verifiedPaymentMethodDisplay): void
{
    $fromState = $attempt->state;
    $updateAttributes = ['state' => FundingAttemptState::Succeeded->value];
    if ($verifiedPaymentMethodDisplay !== null) {
        $updateAttributes['payment_method_display_snapshot'] = $verifiedPaymentMethodDisplay;
    }

    DB::transaction(function () use ($attempt, $updateAttributes, $fromState, $source, $providerEventId, $actorUserId) {
        $this->attemptRepository->update($attempt, $updateAttributes);
        $this->recordTransition($attempt, $fromState, FundingAttemptState::Succeeded, $source, $providerEventId, $actorUserId);
    });

    BusinessFundingAttemptSucceeded::dispatch((int) $attempt->id, (int) $attempt->business_id, $attempt->purpose->value, (int) $attempt->expected_amount_micro);
}
```

**New private helper, the loser / crash-recovery path (row-locked, disambiguates "someone else already finished" from "the credit exists but no one ever finished finalizing it"):**

```php
private function finalizeFundingAttemptStateUnderLock(BusinessFundingAttempt $attempt, TransitionSource $source, ?int $providerEventId, ?int $actorUserId, ?string $verifiedPaymentMethodDisplay): void
{
    $didFinalize = DB::transaction(function () use ($attempt, $source, $providerEventId, $actorUserId, $verifiedPaymentMethodDisplay) {
        $locked = $this->attemptRepository->findForUpdateById((int) $attempt->id);

        if ($locked === null || $locked->state === FundingAttemptState::Succeeded) {
            return false; // a genuine concurrent winner (or an earlier recovery caller) already fully finished
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

    if ($didFinalize) {
        BusinessFundingAttemptSucceeded::dispatch((int) $attempt->id, (int) $attempt->business_id, $attempt->purpose->value, (int) $attempt->expected_amount_micro);
    }
}
```

One new import required: `use Illuminate\Support\Facades\DB;` — already imported in this file (confirmed: `UsageBillingCheckoutManager.php:55`), no change needed there.

### 2.2 `finalizeAddonPurchaseIfPending()` — the identical pattern, one level down

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
            // Falls through to the same lock-protected completion below,
            // exactly like the winner path — deliberately not a no-op:
            // see §1's crash-recovery finding.
        }
    }

    $this->completeAddonPurchaseUnderLock($purchase, $source, $providerEventId);
}
```

**New private helper — applied uniformly to every fulfillment mode, including `direct_deliverable` (which has no credit call at all, and therefore no unique-constraint signal to gate on; the lock is the only available serialization point for that mode, so this contract applies it unconditionally rather than only to `wallet_credit`):**

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

**Deliberate asymmetry with §2.1, explained:** §2.1's own funding-attempt-level winner path (`finalizeFundingAttemptState()`) skips the lock entirely, because the ledger's own unique constraint already exclusively serializes that caller as the credit's one true owner. Every AddonPurchase completion — winner and loser alike — instead routes through the same lock-protected `completeAddonPurchaseUnderLock()`. This is not an oversight: `wallet_credit`'s own winner *is* already uniquely serialized by its own credit success, exactly like §2.1's winner — but `direct_deliverable` never reaches a credit call at all, so it has no equivalent unlocked fast path available. Forking into a third, always-locked branch for `direct_deliverable` alone (while giving `wallet_credit`'s own winner an unlocked fast path, mirroring §2.1 exactly) would be the more "optimized" design, but it means two different completion code paths for the same method, gated on fulfillment mode. This contract chooses the single, uniform, always-locked path instead — the cost is one extra, uncontended, single-row `SELECT ... FOR UPDATE` per `wallet_credit` completion (negligible next to the outbound provider call that already precedes it in every caller), traded for one code path instead of two.

### 2.3 Why the dispatch sites need no further change

`confirmSucceeded()`'s AddonPurchase branch dispatches `BusinessFundingAttemptSucceeded` **unconditionally**, immediately after calling `finalizeAddonPurchaseIfPending()` — this is unchanged from the pre-correction code and remains correct: it is the *funding attempt's own* terminal event, and by the time `confirmSucceeded()`'s own top-of-method credit-or-recovery branch (§2.1) is entered for a purpose other than AddonPurchase, or reaches this exact line for an AddonPurchase, the caller reaching it has already durably won the *attempt-level* race (§2.1's own `finalizeFundingAttemptState()`/`finalizeFundingAttemptStateUnderLock()` are what gate this dispatch for the non-AddonPurchase branch; for AddonPurchase, `confirmSucceeded()` itself is only ever reached once per attempt in the first place, since `confirmAttemptFromReturn()`/`confirmAttemptFromWebhook()`'s own top-of-method `state === Succeeded` guard already excludes a second call once *any* caller — winner or later recovery finalizer — has completed §2.1's non-AddonPurchase branch; the AddonPurchase branch's own race is entirely *inside* `finalizeAddonPurchaseIfPending()`, at the purchase level, not the attempt level, since `confirmSucceeded()` itself sets `attempt.state = Succeeded` unconditionally before ever branching on purpose — confirmed still true and unmodified by this design). This is the one place this contract's design deliberately does **not** touch — it is already correct, and touching it would widen scope without closing anything.

---

## 3. Transaction and locking boundaries — explicit

- **The credit attempt itself opens no new transaction.** `creditFromFunding()`'s own internal `DB::transaction()` (unchanged, `UsageWalletManager.php:886-954`) remains the sole transaction boundary for the credit step, exactly as before. The ledger `correlation_key` unique constraint is the *entire* serialization mechanism for distinguishing winner from loser — no lock is acquired for this step, by design: acquiring one here would require widening the transaction to span the credit's own nested transaction and any outbound provider evidence retrieval that may precede it in the calling method, which requirement 6 (byte-for-byte-unchanged callers) forbids.
- **A new lock is acquired only in the loser/crash-recovery branch, and only there** — `findForUpdateById()` (`BusinessFundingAttemptRepository`, already present, confirmed unused by any prior correction) and the new `findForUpdateByFundingAttemptId()` (`BusinessUsageAddonPurchaseRepository`, added by this correction). Both are single-row, primary-key-or-unique-key-scoped `SELECT ... FOR UPDATE` calls — the cheapest possible row lock, held only for the duration of a two-statement local transaction (state/status update + transition insert), never across an outbound provider HTTP call. This directly mirrors the reasoning the Reconciliation-Race Correction Contract already established for *not* locking its own read (§3 of that contract) — the difference here is that this lock is acquired only in the rare, already-contended loser path, never on the common, uncontended winner path, so the "lock-hold-duration-across-a-provider-call" risk that reasoning warned against never arises.
- **Winner/loser disambiguation, explicit:** the credit call's own `UniqueConstraintViolationException` is the *first* signal — but it does not itself distinguish "someone else already fully finished" from "the credit exists but finalization was never completed by anyone" (the crash-recovery case, §1). That disambiguation is performed **only** by the lock-protected re-read (`$locked->state === Succeeded` / `$locked->status === Completed`) — the only point in this design that is genuinely exclusive across every concurrent or replayed caller, since it is the only step guarded by a row lock. This is what guarantees exactly one finalization (§4 guarantee 3) regardless of how many callers raced or how many replays occur.
- **Crash-recovery behavior, explicit (guarantee 7):** a crash after the credit's own transaction commits but before the finalization transaction commits leaves the attempt/purchase in a state a fresh caller's own top-of-method guard does **not** treat as terminal (`ProviderPending`/`RequiresAction`, or `Pending`) — so any later replay (a customer's own retried return, a redelivered webhook, or `ReconcileProviderPendingState`'s own periodic sweep) re-enters `confirmSucceeded()`/`finalizeAddonPurchaseIfPending()`, hits the same `UniqueConstraintViolationException` on its own now-redundant credit attempt, and — critically, unlike today's code — reaches the lock-protected recovery branch instead of being silently blocked by an already-`Succeeded` in-memory guard. Exactly one such replay ever performs the recovery finalization; every subsequent replay sees the now-terminal persisted state under the same lock and no-ops.
- **A crash between the finalization transaction's two statements (state/status update, then transition insert) is not newly introduced by this correction and is not claimed to be closed by it** — wrapping both in one local `DB::transaction()` (new in this design, for both the winner and recovery paths) makes this specific two-statement gap atomic where it previously was not, as a direct, low-cost consequence of the restructuring — but this is a bounded strengthening bundled with the fix, not a separately-scoped guarantee.
- **AddonPurchase's own `direct_deliverable` fulfillment mode is protected by the lock alone**, with no credit-based first-pass filter (§2.2) — this is a deliberate, uniform design choice: the alternative (leaving `direct_deliverable` unprotected because it "has no financial credit at stake") would leave a known race in a method this contract was already committed to correcting for its sibling `wallet_credit` mode, for the sake of avoiding one single-row lock acquisition in the common case.

---

## 4. Guarantee-by-guarantee mapping

1. **A customer return racing a webhook never produces an HTTP 500 merely because the payment was already confirmed.** `UsageBillingTopUpController::confirmFromReturn()` is unmodified and still has zero exception handling — this guarantee holds because `confirmSucceeded()` (§2.1) never lets `UniqueConstraintViolationException` propagate past itself for *any* caller. Proven directly at the controller/HTTP layer in §5.1 item 4 (below), not merely inferred.
2. **Exactly one financial credit exists.** Unchanged — the ledger `correlation_key` unique constraint (§1, §3) is the same, already-existing mechanism; this correction does not touch it.
3. **Exactly one genuine terminal Succeeded transition exists.** New, and the specific defect this correction eliminates: the loser/recovery branch (§2.1, §2.2) performs its finalization only under the row lock, and only if the persisted state/status is not already terminal — so at most one caller, ever, for a given attempt or purchase, executes the transition-insert statement. §1's own two-transition finding is fully closed, not merely relabeled as accepted.
4. **Exactly one `BusinessFundingAttemptSucceeded` dispatch decision exists.** A direct consequence of guarantee 3: the dispatch sites (§2.1's two helpers, §2.3) are reached only by whichever single caller performs the finalization.
5. **Exactly one `SendReceiptNotification` dispatch decision exists.** Unchanged mechanism — `creditFromFunding()`'s own internal `->afterCommit()` dispatch (`UsageWalletManager.php:951-952`) already only ever fires for the winning credit call, since the losing call's own transaction never commits far enough to reach it. This was already true before this correction (PR #143 §3 item 5 already established it) and remains true, untouched.
6. **Every concurrent/replayed caller receives or derives the correct final persisted result.** `confirmAttemptFromReturn()`'s and `confirmAttemptFromWebhook()`'s own return-value/no-return-value construction after calling `confirmSucceeded()` is unmodified — both already unconditionally treat a call that didn't throw as a success, using `FundingAttemptState::Succeeded` directly rather than re-reading the (possibly stale) `$attempt` object. Since this correction's entire effect is "never throw, ever, for a caller that ends up on the losing or recovery side," this guarantee is a mechanical consequence of guarantees 1 and 3-5, requiring zero caller-level change — confirmed by direct read of every caller's own post-call code.
7. **A crash between financial credit and attempt-state finalization remains recoverable on replay.** The specific, additional defect §1 independently identified (today's code finalizes state *before* crediting, so a crash in that window is unrecoverable and silent). Reordering to credit-first (§2.1, §2.2) is what makes the *replay* path — not a new job, not a new caller — the same, already-existing three callers, land in the recovery branch instead of being blocked by the top-of-method guard.
8. **AddonPurchase, ManualTopUp, and AutoRecharge semantics remain correct.** ManualTopUp/AutoRecharge: §2.1's design produces byte-identical observable behavior for the non-racing case (single caller, no crash) — the credit still happens, the state still transitions, the event still dispatches, in every test already covering this path (§5). AddonPurchase: §2.2 explicitly re-verified against every existing crash/replay/idempotency test in `AddonPurchaseTransitionAuditTest.php` (§5) and confirmed compatible without modifying any of their own assertions. `direct_deliverable`'s own no-wallet-mutation, no-invented-delivery-mechanism behavior is unchanged — only its completion-mutation step gained lock protection (§2.2, §3).
9. **Genuine unrelated failures are not broadly swallowed.** Both new catches (§2.1, §2.2) are scoped to exactly `Illuminate\Database\UniqueConstraintViolationException` — the same precedent `finalizeAddonPurchaseIfPending()`'s own pre-existing catch and the Reconciliation-Race Correction's own job-level catch both already establish. No bare `\Exception`/`\Throwable` is introduced anywhere by this design. A different failure (a provider-gateway exception, `UsageWalletNotFoundException`, a genuine unrelated `QueryException`) is a different exception type entirely and propagates out of `confirmSucceeded()`/`finalizeAddonPurchaseIfPending()` exactly as it does today, unchanged.
10. **`ReconcileProviderPendingState`'s existing catch remains as defense-in-depth, no longer relied upon to tolerate duplicate terminal mutations.** `app/Jobs/Usage/ReconcileProviderPendingState.php` is not on this contract's own production allow-list (§6) — its `try { confirmAttemptFromReturn($attempt); } catch (UniqueConstraintViolationException) { continue; }` (unchanged since PR #143) remains exactly as written. Its role is re-characterized, not its code: before this correction, it was one of the only things standing between a genuinely simultaneous collision and an aborted reconciliation run *and* the only thing preventing that specific collision from producing more than the (previously accepted) two transition rows; after this correction, the collision it catches can no longer produce a duplicate terminal mutation at all — regardless of whether this job's own catch fires — because §2.1's own lock-protected recovery branch already guarantees that outcome for every caller, including this one.

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
$this->assertSame(1, $succeededTransitionCount, 'Exactly one succeeded transition row must exist for the raced attempt — the Funding Confirmation Concurrency Correction closes the residual duplicate-transition defect PR #143 disclosed; the job\'s own re-fetch skip and confirmSucceeded()\'s own lock-protected recovery branch now jointly guarantee this.');
```

The surrounding test docblock/method-comment language referencing "the known, accepted Tier 2 residual side effect" is corrected in the same pass to describe the now-closed defect in the past tense, cross-referencing this contract. No other assertion in this file changes — the job's own eligibility-recheck skip (test 1), the exactly-one-ledger-credit assertion (test 2), the exactly-one-event/exactly-one-receipt assertions (test 2), the final-`Succeeded`-state assertion (test 2), the later-attempt-still-reconciles assertion (test 2), and test 3's unrelated-exception-propagates design are all independently re-confirmed, by direct trace against §2's design, to remain correct and unaffected.

**No other existing test file's assertions were found to be stale.** `AddonPurchaseTransitionAuditTest.php`'s three existing crash/replay/idempotency tests were each independently traced against §2.2's new design (§1's own "genuinely different failure mode" note) and confirmed to produce byte-identical outcomes — none is modified.

### 5.1 New file: `tests/Feature/Usage/FundingConfirmationConcurrencyCorrectionTest.php`

1. **`test_a_true_duplicate_credit_race_between_two_confirmation_callers_produces_exactly_one_transition_event_and_receipt_dispatch`** — the direct, single-process proof of guarantees 1, 3, 4, 5, 6. No repository mock is needed here (unlike PR #143's own reconciliation-job test, which needed one because the job's own loop performs its own internal re-fetch) — this test calls the shared method directly. Creates one stuck-eligible ManualTopUp attempt with a registered verified checkout outcome; fetches `$winner` and `$loser` as two **separately-fetched** `BusinessFundingAttempt` instances (confirmed necessary for the same reason established in the Reconciliation-Race Correction: `EloquentBusinessFundingAttemptRepository::update()`'s own `fill()`+`save()` mutates its input in place). `Event::fake([BusinessFundingAttemptSucceeded::class])`, `Queue::fake()`. Calls `$checkoutManager->confirmAttemptFromReturn($winner)`, then `$checkoutManager->confirmAttemptFromReturn($loser)` — **both calls made with no test-owned `try`/`catch` around either** — asserting directly that the second call does not throw. Asserts: exactly one `business_usage_ledger_entries` row; **exactly one** `business_funding_attempt_transitions` row with `to_state = 'succeeded'` (the corrected invariant); `Event::assertDispatchedTimes(BusinessFundingAttemptSucceeded::class, 1)`; `Queue::assertPushed(SendReceiptNotification::class, 1)`; the loser call's own returned `FundingAttemptResult->state === FundingAttemptState::Succeeded`; the attempt's final persisted state is `Succeeded`.
2. **`test_a_crash_between_credit_and_state_finalization_is_completed_exactly_once_on_replay`** — the direct proof of guarantee 7, and of §1's own independently-discovered crash-recovery finding. Creates one stuck-eligible ManualTopUp attempt; calls `app(UsageWalletManager::class)->creditFromFunding(...)` **directly**, bypassing `confirmSucceeded()` entirely, using the attempt's own exact `local_idempotency_key.':credit'` correlation key — this durably commits the credit while leaving the attempt's own persisted `state` at `ProviderPending`, precisely simulating a crash in the exact window §1 identified. Registers a verified checkout outcome, then calls `$checkoutManager->confirmAttemptFromReturn($attempt)` as a normal replay (a real customer retry, or a redelivered webhook, would look identical). Asserts: the call does not throw; exactly one `business_usage_ledger_entries` row (still — the replay's own credit attempt collides and is absorbed); **exactly one** succeeded transition row (written by *this* replay's own recovery branch, since none existed before it); exactly one `BusinessFundingAttemptSucceeded` dispatch; the attempt's final persisted state is `Succeeded` — proving the attempt does **not** remain permanently stuck, which is what today's code would do.
3. **`test_a_genuinely_simultaneous_double_confirmation_of_an_addon_purchase_produces_exactly_one_completion_transition`** — the AddonPurchase-branch sibling of test 1, at the same level of directness. Creates one stuck-eligible `wallet_credit` AddonPurchase attempt with a registered verified checkout outcome; fetches `$winner`/`$loser` as two separately-fetched instances; calls `confirmAttemptFromReturn($winner)` then `confirmAttemptFromReturn($loser)` directly, no test-owned `try`/`catch`. Asserts: the second call does not throw; exactly one ledger credit row; exactly one row in `business_usage_addon_purchase_transitions` for this purchase (via `app(BusinessUsageAddonPurchaseTransitionRepository::class)->forPurchase($purchaseId)`, the same helper `AddonPurchaseTransitionAuditTest.php` already uses); the purchase's final persisted `status` is `Completed`; exactly one `SendReceiptNotification` dispatch (`Queue::fake()`).
4. **`test_the_customer_return_controller_endpoint_returns_the_normal_success_redirect_when_the_attempt_was_already_confirmed_by_a_concurrent_caller`** — the end-to-end, HTTP-layer proof of guarantee 1 specifically, not merely a transitive inference from test 1. Uses the same constructor-initialized Mockery partial-mock technique the exceptional post-review correction established for PR #143's own test 2 (`Mockery::mock(EloquentBusinessFundingAttemptRepository::class, [new BusinessFundingAttempt()])->makePartial()`, bound as `BusinessFundingAttemptRepository::class`, `findById()` stubbed for the one attempt's own id to confirm a separately-fetched winner for real before returning a still-stale loser) — but this time the *controller's own* `findById()` call (`UsageBillingTopUpController.php:80`) is what receives the stale loser, and the HTTP request itself — `$this->get(route('customer.workspaces.businesses.usage-billing.top-up.confirm', [$workspaceUid, $businessUid, $attempt->id]))`, the exact route-name-and-parameter-order pattern `UsageBillingDashboardAuthorizationTest::test_top_up_confirm_route_rejects_a_cross_business_attempt()` already establishes verbatim — is the thing under test. Asserts: the HTTP response is the normal success redirect (`assertRedirect(...)`) carrying the `flash_success` session key, **not** a 500-class response and not the `flash_error` key; exactly one ledger credit; exactly one succeeded transition row.

### 5.2 `tests/Feature/Usage/AddonPurchaseTransitionAuditTest.php` — 1 new method added

5. **`test_a_genuinely_simultaneous_double_confirmation_of_a_direct_deliverable_addon_purchase_completes_exactly_once`** — proves §2.2's own uniform lock protection for the fulfillment mode with no credit-based first-pass filter. Creates one stuck-eligible `direct_deliverable` AddonPurchase attempt (mirroring `test_direct_deliverable_addon_purchase_dispatches_no_receipt_notification`'s own fixture); fetches `$winner`/`$loser` as two separately-fetched instances; calls `confirmAttemptFromReturn($winner)` then `confirmAttemptFromReturn($loser)` directly. Asserts: the second call does not throw; the purchase's final `status` is `Completed`, `completed_at` set exactly once; exactly one row in `business_usage_addon_purchase_transitions` for this purchase; zero ledger entries and zero receipt dispatches (unchanged — `direct_deliverable` still performs no wallet mutation of any kind, per guarantee 8).

### 5.3 `tests/Feature/Usage/ConcurrentTopUpConcurrencyTest.php` — 1 new method added

6. **`test_two_genuinely_concurrent_processes_confirming_the_same_attempt_produce_exactly_one_ledger_credit_and_transition`** — the true, OS-level-concurrency complement to §5.1 test 1, reusing this file's own already-established subprocess/signal-file infrastructure verbatim (`baseRunnerPreamble()`, `confirmRunnerScript()`, `phpBinary()`, the `WAITING`-handshake barrier pattern from `test_two_concurrent_confirmations_for_the_same_business_each_credit_exactly_once`) — the **only** change from that existing test's own pattern is pointing **both** child processes at the **same** `$attemptId` instead of two different ones, so they race the identical row across two real, independent OS processes rather than a single-process Mockery interleaving. Both processes call `confirmRunnerScript()` unmodified (it already independently fetches its own fresh `BusinessFundingAttempt` instance and registers its own deterministic verified checkout outcome per process). Asserts, after both processes report `DONE` (proving neither crashed or exited non-zero — the genuine, unsimulated proof that neither call ever surfaced the exception): exactly one `business_usage_ledger_entries` row for the attempt; exactly one `business_funding_attempt_transitions` row with `to_state = 'succeeded'`; the wallet's own `available_balance_micro` reflects the credit exactly once. Because durable DB state, not in-memory fakes, is the only thing observable across a process boundary, this test does not attempt to assert exact event/queue dispatch counts (§5.1 test 1 and test 3 already do, deterministically, in-process) — it exists specifically to prove the design holds under genuine, not simulated, simultaneity.

### 5.4 Required new imports, by file

- `FundingConfirmationConcurrencyCorrectionTest.php` (new file): `App\Enums\Usage\FundingAttemptState`, `App\Enums\Usage\PayerType`, `App\Events\Usage\BusinessFundingAttemptSucceeded`, `App\Jobs\Usage\SendReceiptNotification`, `App\Library\Usage\BillingProfileManager`, `App\Library\Usage\CheckoutSessionResult`, `App\Library\Usage\Contracts\PaymentProviderGateway`, `App\Library\Usage\FakePaymentProviderGateway`, `App\Library\Usage\PaymentInstrumentManager`, `App\Library\Usage\PaymentMethodResult`, `App\Library\Usage\UsageBillingCheckoutManager`, `App\Library\Usage\UsageWalletManager`, `App\Models\BusinessFundingAttempt`, `App\Models\Currency`, `App\Repositories\Contracts\BusinessFundingAttemptRepository`, `App\Repositories\Contracts\BusinessUsageAddonPurchaseTransitionRepository`, `App\Repositories\Eloquent\EloquentBusinessFundingAttemptRepository`, `Illuminate\Database\UniqueConstraintViolationException`, `Illuminate\Foundation\Testing\RefreshDatabase`, `Illuminate\Support\Facades\DB`, `Illuminate\Support\Facades\Event`, `Illuminate\Support\Facades\Queue`, `Mockery`, `Tests\Feature\Business\Concerns\CreatesBusinessTestData`, `Tests\TestCase`.
- `AddonPurchaseTransitionAuditTest.php`: no new imports — every symbol the new method needs (`AddonPurchaseStatus`, `BusinessUsageAddonPurchaseTransitionRepository`, `Queue`) is already imported.
- `ConcurrentTopUpConcurrencyTest.php`: no new imports — the new method reuses `confirmRunnerScript()`, `baseRunnerPreamble()`, `phpBinary()`, and every fixture helper verbatim.
- `ReconcileProviderPendingStateTest.php`: no new imports — only the one assertion's expected value and its message string change.

---

## 6. Exact production allow-list

| # | Path | Status | Reason |
|---|---|---|---|
| 1 | `app/Library/Usage/UsageBillingCheckoutManager.php` | REQUIRED | `confirmSucceeded()` reordered credit-first with a lock-protected recovery branch (§2.1) — two new private helpers, `finalizeFundingAttemptState()` (winner, unlocked) and `finalizeFundingAttemptStateUnderLock()` (loser/recovery, locked); `finalizeAddonPurchaseIfPending()` reordered identically (§2.2) — one new private helper, `completeAddonPurchaseUnderLock()`, used uniformly by both its winner and loser paths (§2.2's own asymmetry note). |
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
| 1 | `tests/Feature/Usage/FundingConfirmationConcurrencyCorrectionTest.php` | REQUIRED (new file) | 4 new methods (§5.1), proving guarantees 1, 3, 4, 5, 6, 7 directly and deterministically, in-process. |
| 2 | `tests/Feature/Usage/AddonPurchaseTransitionAuditTest.php` | REQUIRED (modified existing) | 1 new method added (§5.2); all 10 existing methods unchanged, re-confirmed unaffected (§1, §5). |
| 3 | `tests/Feature/Usage/ConcurrentTopUpConcurrencyTest.php` | REQUIRED (modified existing) | 1 new method added (§5.3), reusing all existing infrastructure verbatim; all 3 existing methods unchanged. |
| 4 | `tests/Feature/Usage/ReconcileProviderPendingStateTest.php` | REQUIRED (modified existing) | 1 stale assertion corrected (§5); all 7 other existing methods unchanged. |

**Exactly 4 test paths.**

---

## 8. Regression commands — streamlined, per this contract's own testing policy

- `php artisan test tests/Feature/Usage/FundingConfirmationConcurrencyCorrectionTest.php` — the new file itself; expected 4 methods, all passing.
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

Do not reopen Reservation Admission, Funding Provider-Flow, Receipt Boundary, Job/Event Dispatch Completion, or the Reconciliation-Race Correction — none is touched, contradicted, or reinterpreted anywhere above.

---

## 11. Confirmations

- **No schema/migration change is required or authorized by this correction.** Both unique constraints this correction relies on (`business_usage_ledger_entries.correlation_key`, `business_usage_addon_purchases.funding_attempt_id`) already exist, already shipped, unmodified.
- `AI-AUTONOMY-STATE.json` is untouched by this branch.
- M6 remains frozen.
- No product, test, config, route, or RFC-source file is touched by this branch. This governance branch changes exactly one file.
- No implementation has occurred. `maximum_correction_rounds: 2`; 0 of 2 consumed at drafting.
- Reservation Admission, Funding Provider-Flow, Receipt Boundary, Job/Event Dispatch Completion, and the Reconciliation-Race Correction are not reopened, contradicted, or reinterpreted anywhere above.

---

## 12. Open items

**No open item blocks authorizing or implementing this correction's own bounded scope** (§6/§7's allow-lists, §5's test design, §2's design). Both §9 findings are explicitly out of *this correction's* scope by design — Finding A remains deferred and non-blocking; the Reconciliation-Race Correction's own Finding B is resolved by this contract's own existence and eventual implementation, not by any further decision here.

**No genuine open human decision was identified during this audit.** Every design choice in §2 was derivable mechanically from the ten stated guarantees and the current, directly-read code and schema — none required a judgment call this contract deferred rather than made. If a human reviewer disagrees with one specific design choice (most likely candidates: applying the lock uniformly to `direct_deliverable`, §2.2/§3; or wrapping the two-statement finalization in its own local transaction, §2.1/§3, as a bundled strengthening beyond the letter of guarantee 7), that is a correction-round-scoped disagreement to raise against this document, not an unresolved question this document itself leaves open.

---
