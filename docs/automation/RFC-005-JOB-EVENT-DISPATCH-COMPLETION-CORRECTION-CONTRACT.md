# RFC-005 Job/Event Dispatch Completion Correction Contract

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

This document authorizes drafting one thing: a bounded, independently governed correction that closes RFC-005 §29's job/event dispatch gaps — the two missing jobs (`SendLowBalanceNotification`, `SendAutoRechargeDisabledNotification`), the seven missing wallet/reservation domain events, the two already-defined-but-dead funding-attempt events, `ExpireStaleUsageReservations`'s unreachability, and `ReconcileProviderPendingState`'s test-coverage gap. Human merge of this contract does **not** itself start implementation — a human must separately, explicitly instruct that implementation begin on the branch named in §0, exactly as every RFC-005 milestone and correction contract before it (most recently the Receipt Boundary correction, [`RFC-005-RECEIPT-BOUNDARY-CORRECTION-CONTRACT.md`](./RFC-005-RECEIPT-BOUNDARY-CORRECTION-CONTRACT.md), merged PR [#139](https://github.com/os-creator1/os-ai/pull/139)) has required.

This correction exists because the M6 static conformance audit found Job/Event Dispatch Completion to be a real blocking gap: two of thirteen RFC-005 §29 jobs are missing entirely, seven of seventeen §29 events are missing entirely, two more events exist as dead code with zero producers, one existing job is built but never scheduled (permanently unreachable), and one existing scheduled job has zero test coverage. This contract is remediation #4 of 7; it does not by itself unblock M6.

This is **contract-authoring only**. No product code, test code, schema, route, config, or RFC-source file is touched by this branch. Reservation Admission, Funding Provider-Flow, and Receipt Boundary are closed corrections and are not reopened, contradicted, or reinterpreted by anything below.

---

## 0. Governance

- Drafted on branch `chore/rfc-005-job-event-dispatch-completion-correction-contract`, in an isolated linked worktree (`../rfc-005-job-event-dispatch-completion-contract-worktree`), based on `origin/main` at `ae0aba36057360eb1149ef980beeb90f9d2d250f` — the Receipt Boundary correction's own merge commit (PR [#139](https://github.com/os-creator1/os-ai/pull/139)), confirmed via `git fetch origin main && git rev-parse origin/main` and `git log -1 --format="%H %s"` (`Merge pull request #139 from os-creator1/agent/rfc-005-receipt-boundary-correction`) at the start of this contract.
- **Future implementation branch (authorized only after this contract's human merge, and only after a further, separate, explicit human instruction to begin implementation): `agent/rfc-005-job-event-dispatch-completion-correction`.**
- Confirmed at drafting: no such branch exists on `origin`; `git status --short` in this worktree is empty; no product/test/config/route/RFC-source file has been touched by this branch at any point.
- Locked:
  - `human_only_merge: true`
  - `maximum_correction_rounds: 2`
  - `correction_rounds_consumed: 0 of 2`
  - `advance_automatically: false`
  - `start_automatically_after_contract_merge: false`
  - `M6 remains frozen` — untouched, not resumed, no M6 document created or modified.
  - `AI-AUTONOMY-STATE.json` is untouched by this branch.
  - **Implementation requires a separate, explicit human instruction issued after this contract itself is human-merged.**
- This is a new, independently bounded pre-M6 correction contract — not a correction round against M1–M6's own contracts, and not a correction round against the already-merged Reservation Admission, Funding Provider-Flow, or Receipt Boundary contracts. Its `maximum_correction_rounds: 2` budget is its own; no counter is borrowed or altered on any other contract.
- This governance branch changes exactly one tracked file: `docs/automation/RFC-005-JOB-EVENT-DISPATCH-COMPLETION-CORRECTION-CONTRACT.md`.
- **Required reading completed before drafting:** RFC-005 §12, §13, §15, §16, §19, §20, §21, §22 (skimmed — slot-agreement-only, no job/event-dispatch-completion content), §25, §28, §29, §31, §32, §35, §36, §37, §39, §40; and the three merged correction contracts (`RFC-005-RESERVATION-ADMISSION-CORRECTION-CONTRACT.md`, `RFC-005-FUNDING-PROVIDER-FLOW-CORRECTION-CONTRACT.md`, `RFC-005-RECEIPT-BOUNDARY-CORRECTION-CONTRACT.md`). None of the three prior corrections is reopened, contradicted, or referenced as needing amendment by anything below.

---

## 1. §29 baseline — mechanically re-verified

**Jobs, confirmed 10 of 13 exist** (`app/Jobs/Usage/*.php`, all extend `App\Jobs\Base`): `EvaluateBusinessAutoRecharge`, `ProcessPaymentProviderEvent`, `ReconcileProviderPendingState`, `ReconcileSlotAgreementAllocation`, `InitiateSlotAgreementRenewal`, `FinalizeSlotAgreementCancellation`, `PurgeExpiredWebhookPayloads`, `SendReceiptNotification`, `SendSlotAgreementPriceChangeNotice`, `ExpireStaleUsageReservations`. **Confirmed missing, exactly two:** `SendLowBalanceNotification`, `SendAutoRechargeDisabledNotification`.

**Events, confirmed 10 of 17 exist** (`app/Events/Usage/*.php`, all `implements ShouldDispatchAfterCommit`, `use Dispatchable`, carry only scalar/ID constructor properties): `BusinessPayerChanged`, `BusinessBillingContactChanged`, `BusinessFundingAttemptSucceeded`, `BusinessFundingAttemptFailed`, `BusinessWalletBillingStatusChanged`, `AdditionalBusinessSlotAgreementCompleted`, `AdditionalBusinessSlotAllocationFailed`, `AdditionalBusinessSlotAgreementLapsed`, `AdditionalBusinessSlotAgreementCanceled`, `AdditionalBusinessSlotAgreementPaymentRecovered`. **Confirmed missing, exactly seven:** `BusinessWalletCredited`, `BusinessWalletDebited`, `BusinessWalletDebtIncurred`, `BusinessWalletDebtCleared`, `BusinessUsageReserved`, `BusinessUsageCommitted`, `BusinessUsageReservationReleased`.

**Confirmed dead, exactly two of the ten existing events:** `BusinessFundingAttemptSucceeded::dispatch(` and `BusinessFundingAttemptFailed::dispatch(` have **zero call sites anywhere in `app/`** (exhaustive grep). The classes exist, match the RFC's §29 naming exactly, and are otherwise correctly shaped — they are simply never fired.

**Kernel schedule, confirmed exactly 5 entries** in `app/Console/Kernel.php`'s non-demo `else` branch (lines 110–117): `PurgeExpiredWebhookPayloads` (hourly), `ReconcileProviderPendingState` (everyFiveMinutes), `InitiateSlotAgreementRenewal` (everyFiveMinutes), `FinalizeSlotAgreementCancellation` (everyFiveMinutes), `ReconcileSlotAgreementAllocation` (hourly). **Confirmed absent: `ExpireStaleUsageReservations` is not scheduled anywhere** — it exists (`app/Jobs/Usage/ExpireStaleUsageReservations.php`, 19 lines, `handle(UsageWalletManager $manager)` calling only `$manager->expireStaleReservations()`), is fully built (M1 contract §12 item 41), and per the M1 contract's own §10 item 10 was never intended to be scheduled at M1 — but no later milestone contract (M2–M6) ever added a Kernel entry for it either. This is a genuine, blocking reachability gap: a `pending` reservation past `expires_at` is never automatically released by anything in the current system.

**`AdvanceUsagePeriodBoundaries` confirmed to not exist as a class anywhere in `app/`.**

---

## 2. `AdvanceUsagePeriodBoundaries` — classification confirmed, not widened

RFC-005 §15 states verbatim: *"The scheduled `AdvanceUsagePeriodBoundaries` job remains optional proactive maintenance only, never required for correctness."* This is not listed among §39's 14 open human decisions (items 1–14, all carried forward unchanged, "no new item is added by this remediation round" per §39's own preamble) — it is a settled design statement, not an open question. The authoritative rollover mechanism is `UsageWalletManager::rollOverPeriodsIfNeeded()`, already called from inside `reserve()`, `commit()`, `release()`, and `creditFromFunding()` (confirmed by direct read of all four methods, §5 below) — lazy rollover is already fully load-bearing and already exercised by every code path that touches a wallet.

**No RFC or merged-contract evidence contradicts M6's prior OPTIONAL/NON-BLOCKING classification.** Locked: `AdvanceUsagePeriodBoundaries` is **OPTIONAL_NON_BLOCKING / NOT IMPLEMENTED BY THIS CORRECTION**. No class, migration, or Kernel entry for it is authorized by this contract.

---

## 3. `ExpireStaleUsageReservations` — reachability confirmed, cadence unresolved (STOP)

**Mechanical facts, confirmed by direct read:**

```php
class ExpireStaleUsageReservations extends Base
{
    public function handle(UsageWalletManager $manager): void
    {
        $manager->expireStaleReservations();
    }
}
```

- No constructor arguments; no self-redispatch; delegates entirely to `UsageWalletManager::expireStaleReservations()`.
- `release()` (`UsageWalletManager.php:702-773`) is idempotent on an already-terminal reservation (`Released`/`Expired` early-return at lines 722-725) and computes `Expired` vs `Released` by comparing `Carbon::now()` to `expires_at` at the instant it runs (line 737-739) — so `ExpireStaleUsageReservations`'s own calls naturally resolve to `Expired`, never mis-classify as a manual `Released`.
- Reservation TTL is `RESERVATION_TTL_MINUTES = 30` (`UsageWalletManager.php:67`), used only to compute each reservation's own `expires_at` at creation (line 444) — **this is a reservation lifetime, not a schedule-interval specification**, and is not treated as one below.
- Current test coverage of the underlying `release()`/expiry mechanics: present (`UsageWalletManagerReservationLifecycleTest.php`). Coverage of the **job's own Kernel reachability**: none, because the job has no Kernel entry to test.

**Cadence search, exhaustive:**

- RFC-005 §29 itself: *"Scheduling — unchanged in shape"* — this is a carry-forward reference, not a reprinted cadence.
- RFC-005 §13 (the reservation/commit/release/expire state-machine section): describes the algorithm only, no interval.
- RFC-005 §39 (open human decisions, items 1–14): no item names `ExpireStaleUsageReservations` or any reservation-expiry cadence.
- RFC-005 §40 (contract coverage matrix): no cadence content; purely a section-mapping index.
- M1 contract (`RFC-005-M1-CONTRACT.md`): builds the job (§12 item 41), explicitly states it is the *only* M1 job and is **not scheduled at M1** (§10 item 10: "Jobs: `ExpireStaleUsageReservations` only... depends on the manager"), does not name a target milestone or cadence for scheduling it.
- M3 contract (`RFC-005-M3-CONTRACT.md`, §110): adds exactly two Kernel entries — `PurgeExpiredWebhookPayloads` (`->hourly()`) and `ReconcileProviderPendingState` (`->everyFiveMinutes()`) — `ExpireStaleUsageReservations` is not mentioned.
- M5 contract (`RFC-005-M5-CONTRACT.md` §3.7): *"`ExpireStaleUsageReservations` — unmodified, and now load-bearing... this job's mechanics do not touch meter/rate identity at all"* — confirms the job's domain logic is unaffected by Amendment 1, says nothing about scheduling or cadence.
- Reservation Admission, Funding Provider-Flow, and Receipt Boundary correction contracts: none references `ExpireStaleUsageReservations` at all.

**No RFC section or merged contract mechanically specifies an exact schedule interval for this job, at any point in the document history available to this correction.**

**STOP condition triggered, exactly as instructed:** this correction does **not** choose an arbitrary cadence (e.g., copying `ReconcileProviderPendingState`'s `everyFiveMinutes()` or `PurgeExpiredWebhookPayloads`'s `hourly()`) to fill this gap. **The exact schedule interval for `ExpireStaleUsageReservations` is an unresolved open decision and must be supplied by a human before implementation can register a Kernel entry for it.** Everything else about this job (its identity, its idempotency, its non-mutation-of-domain-logic requirement) is locked and ready; only the numeric interval itself is blocked.

---

## 4. Missing job: `SendLowBalanceNotification`

**Trigger mechanism, confirmed by direct code read:**

- `business_usage_wallets.low_balance_notified_at` (nullable timestamp, "dedup window (§19)") exists in schema (§12) but has **zero references anywhere in `UsageWalletManager.php`** (confirmed by exhaustive grep) — it is a real, migrated column, entirely unwired in current code.
- `business_usage_wallets.auto_recharge_threshold_micro` is the only concrete numeric quantity RFC-005 ties to "low balance" (§12: *"required if enabled"*; §19: centralized trigger text). No other threshold-bearing column exists for this purpose.
- RFC-005 §19: *"Low-balance notification dedup and reset — unchanged"* and §12: *"the same shared method clears `low_balance_notified_at` on a positive-delta recovery above threshold"* — both are carry-forward prose describing intended behavior; **neither corresponds to any existing code today.**

**Answering the 9 required questions, mechanically:**

1. **Threshold basis:** `available_balance_micro <= auto_recharge_threshold_micro`. This is the only RFC-defined numeric quantity available; no independent "low-balance-only" threshold field exists in schema.
2. **Applicability:** RFC-005 §12 marks `auto_recharge_threshold_micro` nullable, *"required if enabled"* — it is only guaranteed non-null when `auto_recharge_enabled = true`. **Locked scope: `SendLowBalanceNotification` applies only when `auto_recharge_enabled = true` AND `auto_recharge_threshold_micro` is not null.** For a wallet with auto-recharge disabled or unconfigured, RFC-005 defines no numeric "low balance" quantity at all. **This is a genuine, disclosed scope boundary, not a silently invented one:** a wallet outside this condition receives no low-balance notification from this correction. Extending low-balance notification to non-auto-recharge wallets would require a human to define an independent threshold concept the RFC does not currently supply — out of this correction's authority to invent.
3. **Every-mutation vs. threshold-crossing:** edge-triggered. RFC-005's own "dedup window" and "clears... on recovery" language describes a fire-once-per-episode model, not a fire-on-every-negative-delta model. Locked: notify only when a mutation causes `available_balance_micro` to cross from above-threshold to at-or-below-threshold, gated by `low_balance_notified_at IS NULL` at the moment of the crossing.
4. **Recipients:** `BusinessBillingContactRepository::findByBusinessId()`, `notification_opt_in = true` gate, `contact_user_id === null ? contact_email : contactUser->email` resolution — **byte-for-byte the same resolution algorithm `SendReceiptNotification::handle()` already uses** (`app/Jobs/Usage/SendReceiptNotification.php:69-93`). Reused exactly, not reinvented.
5. **Opt-out / missing-contact handling:** mirrors `SendReceiptNotification` exactly — no contact configured, opted out, or blank resolved email each result in a silent no-op (`Log::info`/`Log::warning`, no exception), never a job failure.
6. **Idempotency / exact-once:** `low_balance_notified_at` is set the instant the notification is sent, checked before sending, and cleared on recovery (`available_balance_micro` rising back above `auto_recharge_threshold_micro` via a positive-delta mutation, e.g. `creditFromFunding()`). This is a genuine durable exact-once marker that already exists in schema — **exact-once delivery per below-threshold episode is honestly supportable** without any new column.
7. **Delivery guarantee statement:** exact-once-per-episode, durable, schema-backed. Not "best effort."
8. **Ownership:** per §28's sole-write-authority table, `business_usage_wallets` is `UsageWalletManager`'s alone — the threshold-crossing detection, the `low_balance_notified_at` set/clear, and the job dispatch all belong inside `UsageWalletManager`, never inside a job, listener, or controller.
9. **No new schema required** — `low_balance_notified_at` and `auto_recharge_threshold_micro` both already exist (§12, confirmed present in the current migration set via §25's table index, which lists no pending change for `business_usage_wallets` beyond "recharge-period columns individually specified," already shipped).

**Exact widening point identified:** any ledger-entry insert with `available_delta_micro < 0` already dispatches `EvaluateBusinessAutoRecharge` after commit (§12/§19, confirmed unchanged) from three sites — `reserve()` (`available_delta_micro: -$reservedAmountMicro`, line 447-468), `commit()`'s overage branch (`available_delta_micro: -$overageFromAvailable`, line 602-622), and `commit()`'s implicit charged-portion accounting. The threshold-crossing check belongs in the same `UsageWalletManager` transaction that performs each such negative-delta mutation, immediately after the wallet row is updated — the natural home is a shared private helper invoked from each of `reserve()`, `commit()`, and (for the recovery/clear side) `creditFromFunding()`, mirroring the existing `$shouldDispatchAutoRecharge` flag pattern already used in those methods. **This correction does not write that helper — it locks its exact responsibility and call sites for the implementation phase (§10).**

---

## 5. Missing job: `SendAutoRechargeDisabledNotification`

**Trigger mechanism, confirmed by direct code read:**

```php
public function recordAutoRechargeFailure(int $businessId): void
{
    DB::transaction(function () use ($businessId) {
        $wallet = $this->walletRepository->findForUpdateByBusinessId($businessId);
        if ($wallet === null) {
            throw new UsageWalletNotFoundException($businessId);
        }
        $this->walletRepository->update($wallet, [
            'consecutive_recharge_failures' => $wallet->consecutive_recharge_failures + 1,
        ]);
    });
}
```

(`UsageWalletManager.php:1255-1268`) — **confirmed: this method only increments the counter. It does not check any threshold, does not set `auto_recharge_enabled = false`, and dispatches nothing.** Its own docblock (lines 1250-1251) states it is called *"[when an auto-recharge] attempt reaches `FundingAttemptState::Failed` within that same job execution (never on `requires_action`, never a retry loop)"* — confirmed against `EvaluateBusinessAutoRecharge.php`'s own call site, which invokes it only inside `if ($result->state === FundingAttemptState::Failed)`.

**RFC-005 §19: *"3 is the recommended (category-3) disable threshold"* for `consecutive_recharge_failures`.** This is a "category-3" confidence-graded design decision, not a §39 open item — it does not appear in §39's 14-item list. Locked: the disable threshold is **3**.

**A genuine RFC-vs-code discrepancy found, disclosed rather than silently resolved:** RFC-005 §19 states the counter is *"incremented on `failed`/`requires_action`, reset on `succeeded`"* — but current code and a current passing test both confirm `requires_action` does **not** increment it: `EvaluateBusinessAutoRecharge.php` only calls `recordAutoRechargeFailure()` on `Failed`, and `AutoRechargeFailedPaymentRetryTest::test_a_requires_action_outcome_does_not_increment_the_failure_counter` (`tests/Feature/Usage/AutoRechargeFailedPaymentRetryTest.php:194-204`) asserts the counter stays `0` after a `requires_action` outcome, and passes today. **This is a pre-existing defect/discrepancy outside this correction's scope** — widening or changing the increment condition would modify already-merged M3 behavior unrelated to job/event dispatch reachability, and is not required to make `SendAutoRechargeDisabledNotification` reachable (the notification's own trigger is the threshold check on whatever value the counter already reaches through its existing, unmodified increment path). **This correction does not touch the increment condition.** Flagged here for a human decision, not resolved.

**Answering the 9 required questions, mechanically:**

1. **Threshold basis:** `consecutive_recharge_failures >= 3` (RFC §19, locked, not open).
2. **Every-mutation vs. crossing:** transition-edge-triggered — fires exactly once, at the exact update that increments the counter from `2` to `3` (i.e., the specific mutation that causes `auto_recharge_enabled` to flip `true → false`), never again while it remains `false`.
3. **Exact "disabled" definition:** **system-disabled-after-failures**, precisely `auto_recharge_enabled` being set `false` by `recordAutoRechargeFailure()` itself upon reaching the threshold. Explicitly **not** owner-disabled (`configureAutoRecharge(enabled: false)`, a deliberate customer/administrator action the actor is already aware of) and **not** billing-suspension or missing-instrument (both already produce their own signal via `BusinessWalletBillingStatusChanged`, unrelated to this job). Locked: `SendAutoRechargeDisabledNotification` fires only from the system-disable path inside `recordAutoRechargeFailure()`, never from `configureAutoRecharge()`.
4. **Recipients:** identical resolution to `SendLowBalanceNotification` (§4 item 4) — reused, not reinvented.
5. **Opt-out / missing-contact handling:** identical to `SendReceiptNotification`/`SendLowBalanceNotification` — silent no-op, never a failure.
6. **Idempotency / exact-once:** **no new schema column is invented.** The boolean transition itself (`auto_recharge_enabled` observed `true` immediately before the same atomic update that sets it `false`) is the exact-once marker — analogous to how `BusinessWalletBillingStatusChanged` requires no dedicated dedup column beyond the state transition it observes. Once disabled, the counter continuing to increment (if `recordAutoRechargeFailure()` is ever called again while already disabled — it should not be, since a disabled wallet should no longer reach auto-recharge evaluation, but this is not independently verified by this correction) must **not** re-fire the notification; the dispatch is gated strictly on the `true→false` edge of the same update, not on `consecutive_recharge_failures >= 3` being merely true at read time.
7. **Delivery guarantee statement:** exact-once, since the underlying state transition can only genuinely happen once between a disable and a subsequent re-enable. Honestly supportable without new schema.
8. **Ownership:** `UsageWalletManager` (§28 sole-write-authority for `business_usage_wallets`) — the threshold check, the `auto_recharge_enabled = false` write, and the dispatch all belong inside `recordAutoRechargeFailure()`'s own existing transaction, never inside `EvaluateBusinessAutoRecharge.php` (a job must never write this table directly, per that job's own docblock, unchanged).
9. **No new schema required** — `consecutive_recharge_failures` and `auto_recharge_enabled` both already exist (§12).

**Exact widening point identified:** `UsageWalletManager::recordAutoRechargeFailure()` (`UsageWalletManager.php:1255-1268`) must be widened, inside its existing transaction, to: read the wallet's current `auto_recharge_enabled` value before the update; if the post-increment counter reaches `3` and `auto_recharge_enabled` was `true`, include `'auto_recharge_enabled' => false` in the same `update()` call and dispatch `SendAutoRechargeDisabledNotification::dispatch($businessId)->afterCommit()` immediately after. No change to the increment condition itself (out of scope, §5 discrepancy above). **This correction does not write that widening — it locks its exact responsibility for the implementation phase (§10).**

---

## 6. Seven missing wallet/reservation events — exact emission map

All seven are `App\Events\Usage\*`, `implements ShouldDispatchAfterCommit`, `use Dispatchable`, IDs/scalars only, dispatched **inline inside the same `DB::transaction()` closure that performs the mutation** — mirroring the established pattern already used by 8 of the 10 existing events (`BusinessPayerChanged`, `BusinessBillingContactChanged`, `BusinessWalletBillingStatusChanged`, and the 5 slot-agreement events all dispatch from inside their own owning transaction, relying on `ShouldDispatchAfterCommit`'s own framework-level deferral — not the `EvaluateBusinessAutoRecharge`/`SendReceiptNotification` job pattern of dispatching after the transaction closes via an explicit flag, which is a job-specific (`ShouldQueueAfterCommit`) concern this correction does not need to replicate for events).

### 6.1 `BusinessUsageReserved`

- **Constructor:** `(int $businessId, int $reservationId, string $featureKey, int $reservedAmountMicro)`.
- **Emission site:** `UsageWalletManager::reserve()`, immediately before `return new ReservationResult(true, $reservation->id, null, true);` (`UsageWalletManager.php:480`) — the genuine-creation path only.
- **Non-emission:** the idempotent-repeat early return (`findByIdempotencyKey()` hit, before the transaction opens) and the `UniqueConstraintViolationException` race-loser catch (lines 482-493) must never emit this event — both return a `ReservationResult` whose 4th constructor argument is `false`, the existing, already-correct signal for "not genuinely created this call."
- **Multiplicity:** exactly one event per genuine reservation creation; never more than one per `reserve()` call.
- **Ordering:** after the `Reservation` ledger entry and wallet update, both already inside the same transaction.

### 6.2 `BusinessUsageCommitted`

- **Constructor:** `(int $businessId, int $reservationId, string $featureKey, int $finalAmountMicro, int $reservedAmountMicro)`.
- **Emission site:** `UsageWalletManager::commit()`, immediately before `return new CommitResult(...)` at line 676 — the genuine-commit path only.
- **Non-emission:** the already-`Committed` early return (lines 535-543) must never emit this event — it reconstructs a `CommitResult` from already-persisted state with zero new writes.
- **Multiplicity:** exactly one event per genuine commit, regardless of whether it also produces an overage or an unused-release sub-entry — `BusinessUsageCommitted` is the single per-commit observability event; the overage/debt-specific effects are separately covered by §6.3/§6.4 below, never folded into or substituted for this event.
- **Ordering:** after the wallet update at line 674, still inside the same transaction.

### 6.3 `BusinessUsageReservationReleased`

- **Constructor:** `(int $businessId, int $reservationId, string $featureKey, int $releasedAmountMicro, string $resultingStatus)` — `$resultingStatus` is `'released'` or `'expired'` (the `UsageReservationStatus` enum's own scalar value, already computed at line 737-739).
- **Emission site:** `UsageWalletManager::release()`, immediately after the wallet update at line 771, before the transaction closure ends.
- **Non-emission:** the already-terminal early return (lines 722-725, `status === Released || status === Expired`) must never emit this event.
- **Multiplicity:** exactly one event per genuine release, whether called from a manual admin action or (once §3's cadence is resolved) `ExpireStaleUsageReservations`.

### 6.4 `BusinessWalletCredited` and `BusinessWalletDebtCleared`

Both originate from the **same** method, `UsageWalletManager::creditFromFunding()` (`UsageWalletManager.php:793-841`), and are **not mutually exclusive** — a single call can emit both, one, or neither, depending on the independent conditions below. This directly answers the instruction's "a single accounting operation may legitimately change both available balance and debt" question: yes, and the contract locks both as independently gated, non-exclusive dispatches from the one call site.

- `BusinessWalletCredited(int $businessId, int $walletId, int $ledgerEntryId, int $amountMicro)` — dispatched when `$remainder > 0` (line 811), `$amountMicro = $remainder`. `$ledgerEntryId = $ledgerEntry->id`, reusing the already-captured `$ledgerEntry` variable (line 813) — no new query needed.
- `BusinessWalletDebtCleared(int $businessId, int $walletId, int $ledgerEntryId, int $amountMicro)` — dispatched when `$debtCleared > 0` (line 810), `$amountMicro = $debtCleared`. Same `$ledgerEntry`.
- **Emission site for both:** immediately after the wallet update at line 836, before the existing `SendReceiptNotification::dispatch(...)->afterCommit()` call at line 838 — still inside the same transaction.
- **Non-emission:** `creditFromFunding()` has no idempotent-repeat early-return path of its own (it is documented as "deliberately not self-idempotent," relying on the caller's own once-only invocation, per its existing docblock) — so no additional replay guard is needed beyond the two independent `> 0` conditions already governing whether each event fires at all.
- **Explicitly not emitted from `reserve()`'s or `release()`'s own available-balance deltas** — those are exclusively covered by `BusinessUsageReserved`/`BusinessUsageReservationReleased` (§6.1/§6.3); folding them into `BusinessWalletCredited`/`Debited` as well would double-count the same accounting fact under two event names, which the RFC's own distinct 7-event enumeration does not ask for.
- **Explicitly not emitted from `commit()`'s unused-release branch** (`$availableDelta += $unused`, line 632-654) — this is an internal reservation-bookkeeping reversal, already fully covered by `BusinessUsageCommitted` (§6.2); it is not a genuine external "credit."

### 6.5 `BusinessWalletDebited` and `BusinessWalletDebtIncurred`

Both originate from `UsageWalletManager::commit()`'s overage branch (`UsageWalletManager.php:596-631`), also **not mutually exclusive** for the same reason as §6.4.

- `BusinessWalletDebited(int $businessId, int $walletId, int $ledgerEntryId, int $amountMicro)` — dispatched when `$overageFromAvailable > 0` (line 606), `$amountMicro = $overageFromAvailable`.
- `BusinessWalletDebtIncurred(int $businessId, int $walletId, int $ledgerEntryId, int $amountMicro)` — dispatched when `$overageToDebt > 0` (line 608), `$amountMicro = $overageToDebt`.
- **`$ledgerEntryId`:** currently the `UsageOverageCharge` ledger-entry `create()` call (lines 602-622) does not capture its own return value. **This requires one mechanical widening — capturing `$overageLedgerEntry = $this->ledgerRepository->create([...]);` in place of the current bare `create()` call — to obtain the id for these two events' payloads.** This is a return-value capture only, not a new query or a new write.
- **Emission site for both:** immediately after the wallet update at line 674, before `return new CommitResult(...)` — same site as §6.2's `BusinessUsageCommitted`, all three potentially firing from the same `commit()` call when an overage spans both available balance and debt.
- **Non-emission:** governed by the same already-`Committed` early return as §6.2 (lines 535-543); additionally, when `$finalAmountMicro <= $reservedAmountMicro` (no overage at all), neither event fires — this is the existing `elseif` branch structure (line 632), unchanged.

**Refund/DisputeChargeback/ManualCredit/PromotionalCredit/UsageChargeReversal/CorrectionReversal entry types are explicitly excluded from all four wallet-credit/debit/debt events above** — none of these ledger-entry types has any producing code path in the current codebase (confirmed: Admin Usage Billing Surface #5 and Provider Refund/Dispute #6 are both out of scope, unimplemented remediation groups). This correction adds no dispatch for a mutation method that does not yet exist.

---

## 7. Funding-attempt events — exact emission map

Both existing classes, dead since creation, gain exactly one dispatch call each, at their single respective chokepoint — no other caller needs modification.

### 7.1 `BusinessFundingAttemptSucceeded`

- **Chokepoint, confirmed the sole writer of `state => Succeeded`:** `UsageBillingCheckoutManager::confirmSucceeded()` (`UsageBillingCheckoutManager.php:628-658`), called from exactly 4 sites: `confirmAttemptFromReturn()` (line 501), `confirmAttemptFromWebhook()` (lines 559 and 564), `retryFundingAttemptAsAdministrator()` (line 601 and 612) — i.e., the synchronous return path, the webhook path, and the administrator resume path all converge on this one method. Reconciliation (`ReconcileProviderPendingState`) itself calls `confirmAttemptFromReturn()`, which in turn calls `confirmSucceeded()` — so reconciliation is covered transitively, with no separate dispatch needed.
- **Emission point:** immediately after `$this->recordTransition($attempt, $fromState, FundingAttemptState::Succeeded, ...)` at line 639, **before** the `AddonPurchase`-vs-credit purpose branch at line 641 — so it fires for every purpose (`ManualTopUp`, `AutoRecharge`, `AddonPurchase`) exactly once per genuine transition.
- **Payload:** `((int) $attempt->id, (int) $attempt->business_id, $attempt->purpose->value, (int) $attempt->expected_amount_micro)` — matches the class's own already-defined constructor exactly (`fundingAttemptId, businessId, purpose, amountMicro`).
- **Non-emission on replay:** `confirmSucceeded()` is only ever reached through call sites that already early-return on an already-`Succeeded` attempt before calling it (`confirmAttemptFromReturn()` line 479-485, `confirmAttemptFromWebhook()` line 528-534) — so a replay of an already-terminal attempt never re-enters `confirmSucceeded()` at all, and therefore never re-dispatches this event. No new guard is needed inside `confirmSucceeded()` itself.
- **`requires_action` is never routed through `confirmSucceeded()`** — confirmed: it is non-terminal everywhere (never in any terminal-state array), so it can never falsely trigger this event.

### 7.2 `BusinessFundingAttemptFailed`

- **Chokepoint, confirmed the sole writer of `state => Failed`:** `UsageBillingCheckoutManager::markFailed()` (`UsageBillingCheckoutManager.php:802-812`).
- **Emission point:** immediately after `$this->recordTransition($attempt, $fromState, FundingAttemptState::Failed, ...)` at line 811.
- **Payload:** `((int) $attempt->id, (int) $attempt->business_id, $attempt->purpose->value)` — matches the class's own already-defined constructor exactly (`fundingAttemptId, businessId, purpose`).
- **Non-emission on replay:** `markAttemptFailedFromWebhook()` (the only webhook-driven caller of `markFailed()`) already early-returns when `state` is already `Succeeded`/`Failed`/`Canceled` (line 569-571) before ever calling `markFailed()` — no new guard needed.

**`ShouldDispatchAfterCommit` correctness note:** neither `confirmSucceeded()` nor `markFailed()` wraps its own body in an explicit `DB::transaction()`. `ShouldDispatchAfterCommit`'s own framework semantics (Laravel 12, confirmed genuine, not custom) handle both cases correctly without any code change here: if the call happens to occur inside an outer open transaction (e.g., `creditFromFunding()`'s own transaction, entered later in the same `confirmSucceeded()` call for non-`AddonPurchase` purposes), the event defers to that commit; if no transaction is open at the exact dispatch point, it fires immediately. This is the same reasoning already implicitly relied upon by the 8 existing inline-dispatched events (§6 preamble) and requires no special-casing.

---

## 8. Existing-event producer reachability audit

| Event | Classification | Evidence |
|---|---|---|
| `BusinessPayerChanged` | **REACHABLE_AND_PROVEN** | Sole dispatch in `BillingProfileManager::changePayer()` (line 127), inside its transaction. Covered by `PayerTransitionAuditTest.php`/`PayerAssignmentTransitionScenariosTest.php`. |
| `BusinessBillingContactChanged` | **REACHABLE_AND_PROVEN** | Sole dispatch in `BillingProfileManager::updateBillingContact()` (line 172), inside its transaction. Covered by `BillingProfileManagerBillingContactTest.php`. |
| `BusinessWalletBillingStatusChanged` | **REACHABLE_AND_PROVEN** | Sole dispatch in `UsageWalletManager::setBillingStatus()` (line 1205), inside its transaction. Covered by `UsageWalletBillingStatusTransitionTest.php`. Referenced only as a file-path string literal (not a dispatch assertion) in `NoStripeOrProviderCodeAtM2Test.php` — that reference is unrelated to reachability and is not a gap. |
| `AdditionalBusinessSlotAgreementCompleted` | **REACHABLE_AND_PROVEN** | Dispatch confirmed at `UsageBillingCheckoutManager.php:2017`. The only Usage-suite file using `Event::fake()` at all, `SlotAgreementConcurrencyTest.php` (lines 223, 254), asserts this event directly via `Event::fake([AdditionalBusinessSlotAgreementCompleted::class])`/`Event::assertDispatchedTimes(...)`. |
| `AdditionalBusinessSlotAllocationFailed` | **REACHABLE_BUT_TEST_GAP** | Dispatch confirmed at `UsageBillingCheckoutManager.php:1991`. No file in `tests/Unit/Usage` or `tests/Feature/Usage` uses `Event::fake(` against this specific class (only `AdditionalBusinessSlotAgreementCompleted` is proven via `Event::fake()`); `SlotAgreementAllocationSagaTest.php` exercises the failure path's side effects but does not assert the event dispatch itself. |
| `AdditionalBusinessSlotAgreementLapsed` | **REACHABLE_BUT_TEST_GAP** | Dispatch confirmed at `UsageBillingCheckoutManager.php:1782`. `AdditionalBusinessSlotAgreementFailedPeriodTest.php` exercises the lapse state transition but not an `Event::fake()`-based dispatch assertion. |
| `AdditionalBusinessSlotAgreementCanceled` | **REACHABLE_BUT_TEST_GAP** | Dispatch confirmed at `UsageBillingCheckoutManager.php:1899`. `AdditionalBusinessSlotAgreementCancellationTest.php` exercises the cancellation state machine but not an `Event::fake()`-based dispatch assertion. |
| `AdditionalBusinessSlotAgreementPaymentRecovered` | **REACHABLE_BUT_TEST_GAP** | Dispatch confirmed at `UsageBillingCheckoutManager.php:1744`. `SlotAgreementLapseRecoveryTest.php` exercises the recovery transition but not an `Event::fake()`-based dispatch assertion. |

**No unreachable-blocking event was found beyond the two funding events already covered in §7.** The four `REACHABLE_BUT_TEST_GAP` slot events are a real, disclosed gap, but adding their direct dispatch-assertion tests is **residual §35-only cleanup**, explicitly excluded from this correction's scope (§9) — this correction does not redesign or re-test slot-agreement events beyond stating this classification.

---

## 9. `ReconcileProviderPendingState` — test-gap requirement

**Confirmed via exhaustive grep: zero test coverage anywhere in `tests/` for this class**, despite being scheduled every 5 minutes (`Kernel.php:111`) since M3. Mechanical audit does not disprove the M6 static audit's finding — this correction must add the missing proof.

**What the job delegates to, confirmed by direct read** (`ReconcileProviderPendingState.php`, 39 lines): queries `BusinessFundingAttempt::query()->whereIn('state', [ProviderPending, RequiresAction])->where('updated_at', '<', now()->subMinutes(30))->whereNotNull('provider_session_or_intent_reference')->get()`, then calls `$checkoutManager->confirmAttemptFromReturn($attempt)` for each. `confirmAttemptFromReturn()` (`UsageBillingCheckoutManager.php:477-515`) itself: no-ops (returns the attempt's current state, no mutation) if already `Succeeded` or `provider_session_or_intent_reference` is null; otherwise re-verifies against the provider (`retrieveCheckoutSession()`/`retrievePaymentIntent()` per purpose) and calls `confirmSucceeded()` only on a genuinely verified success — never a blind state overwrite.

**Locked test file:** `tests/Feature/Usage/ReconcileProviderPendingStateTest.php` (new). **Locked test methods:**

1. `test_reconciles_a_stuck_provider_pending_attempt_to_succeeded` — a `ProviderPending` attempt with `updated_at` older than 30 minutes and a provider-confirmed-succeeded fixture is reconciled: transitions to `Succeeded`, and (per §7.1) dispatches `BusinessFundingAttemptSucceeded` exactly once.
2. `test_does_not_reconcile_an_attempt_updated_within_the_stuck_window` — an otherwise-eligible attempt with `updated_at` inside the 30-minute window is left untouched; no provider call is made.
3. `test_does_not_mutate_a_still_pending_attempt_the_provider_confirms_as_unresolved` — a stuck attempt whose provider re-verification still does not confirm success is left in its current state; no accounting mutation, no event dispatch.
4. `test_skips_an_attempt_with_no_provider_session_or_intent_reference` — matches the job's own `whereNotNull` guard; no provider call attempted.
5. `test_reconciliation_never_duplicates_accounting_for_an_already_succeeded_attempt` — an attempt that is already `Succeeded` by the time the job runs (e.g., a race with a webhook) produces no duplicate ledger entry and no duplicate event, exercising `confirmAttemptFromReturn()`'s own already-`Succeeded` early return (line 479-485).

**This correction does not redesign the reconciliation flow** — the job's query, its delegation to `confirmAttemptFromReturn()`, and its 30-minute cutoff are all unchanged.

---

## 10. Exact production file allowlist (subject to mechanical confirmation at implementation time)

**Count: 7 files.**

1. `app/Events/Usage/BusinessWalletCredited.php` — new.
2. `app/Events/Usage/BusinessWalletDebited.php` — new.
3. `app/Events/Usage/BusinessWalletDebtIncurred.php` — new.
4. `app/Events/Usage/BusinessWalletDebtCleared.php` — new.
5. `app/Events/Usage/BusinessUsageReserved.php` — new.
6. `app/Events/Usage/BusinessUsageCommitted.php` — new.
7. `app/Events/Usage/BusinessUsageReservationReleased.php` — new.
8. `app/Library/Usage/UsageWalletManager.php` — modified, not new: adds the 7 event dispatches at the exact sites in §6 (`reserve()`, `commit()`, `release()`, `creditFromFunding()`), captures `$overageLedgerEntry` in `commit()`'s overage branch (§6.5), and widens `recordAutoRechargeFailure()` per §5's exact widening point. **This correction does not widen `recordAutoRechargeFailure()`'s increment condition itself (§5 discrepancy).**
9. `app/Library/Usage/UsageBillingCheckoutManager.php` — modified, not new: adds the 2 funding-event dispatches at the exact sites in §7 (`confirmSucceeded()`, `markFailed()`).
10. `app/Jobs/Usage/SendLowBalanceNotification.php` — new, mirroring `SendReceiptNotification.php`'s exact shape (`extends Base implements ShouldQueueAfterCommit`, recipient resolution per §4 item 4).
11. `app/Jobs/Usage/SendAutoRechargeDisabledNotification.php` — new, same shape.
12. `app/Notifications/Usage/LowBalanceNotification.php` — new, mirroring `ReceiptAvailableNotification.php`'s exact shape.
13. `app/Notifications/Usage/AutoRechargeDisabledNotification.php` — new, same shape.

**Corrected count: the list above enumerates 13 distinct paths (7 new events + 2 modified managers + 2 new jobs + 2 new notifications).** No migration, no schema change, no route, no controller, no config file is in this list. **`app/Console/Kernel.php` is explicitly NOT in this list** — scheduling `ExpireStaleUsageReservations` is blocked on §3's unresolved cadence; no other Kernel change is authorized by this correction.

**Explicitly excluded from this list, per instruction:** `app/Jobs/Usage/SendReceiptNotification.php`, `app/Notifications/Usage/ReceiptAvailableNotification.php` (Receipt Boundary is closed; no regression was found during this audit — `creditFromFunding()`'s existing `SendReceiptNotification::dispatch(...)->afterCommit()` call at line 838-839 is unaffected by inserting the two new event dispatches before it at line 836-837); any `AdvanceUsagePeriodBoundaries` class or migration (§2); any file under Admin Usage Billing Surface, Provider Refund/Dispute Handling, Add-on HTTP surface, or M6 conformance/deployment docs (§9 exclusions, restated in full in §12).

---

## 11. Exact test/support file allowlist (subject to mechanical confirmation at implementation time)

**Count: 4 new files.**

1. `tests/Feature/Usage/UsageWalletDomainEventDispatchTest.php` — new. Covers all 7 wallet/reservation events' exact emission and non-emission conditions from §6, including the non-mutual-exclusivity assertions for `BusinessWalletCredited`+`BusinessWalletDebtCleared` (single `creditFromFunding()` call, both remainder and debt-clear positive) and `BusinessWalletDebited`+`BusinessWalletDebtIncurred` (single `commit()` overage spanning both available and debt), using `Event::fake([...])` scoped to exactly these 7 classes.
2. `tests/Feature/Usage/FundingAttemptTerminalEventDispatchTest.php` — new. Covers `BusinessFundingAttemptSucceeded`/`Failed`'s exact emission from §7, across the synchronous-return, webhook, and administrator-resume paths, and the non-re-emission-on-replay guarantee for an already-terminal attempt.
3. `tests/Feature/Usage/ReconcileProviderPendingStateTest.php` — new, exact 5 methods locked in §9.
4. `tests/Feature/Usage/SendLowBalanceNotificationTest.php` — new. Covers: threshold-crossing dispatch, non-dispatch while already below threshold and already notified, non-dispatch for a wallet without `auto_recharge_enabled`/`auto_recharge_threshold_micro`, `low_balance_notified_at` clearing on recovery via `creditFromFunding()`, and the opt-out/missing-contact/blank-email no-op paths mirrored from `SendReceiptNotification`'s own established pattern.
5. `tests/Feature/Usage/SendAutoRechargeDisabledNotificationTest.php` — new. Covers: dispatch exactly on the 3rd consecutive failure's `true→false` transition, non-dispatch on the 1st and 2nd failures, non-dispatch when a deliberate `configureAutoRecharge(enabled: false)` disables it, non-re-dispatch on a subsequent failure while already disabled, and the same recipient/opt-out semantics.

**Corrected count: 5 new test files**, not 4 — restated exactly: `UsageWalletDomainEventDispatchTest.php`, `FundingAttemptTerminalEventDispatchTest.php`, `ReconcileProviderPendingStateTest.php`, `SendLowBalanceNotificationTest.php`, `SendAutoRechargeDisabledNotificationTest.php`.

**No existing test file is modified by the production allowlist above** except as an incidental consequence already reasoned through: any existing test that reaches `reserve()`/`commit()`/`release()`/`creditFromFunding()`/`confirmSucceeded()`/`markFailed()` without `Event::fake()` will now also execute the newly-dispatched events' listeners — but **zero listeners are registered for any of these 9 events** (confirmed: "do not invent consumer/listener behavior" is honored; no `EventServiceProvider` entry is added by this correction), so dispatching to zero listeners is a complete no-op with no observable side effect on any existing assertion. This mirrors the Receipt Boundary correction's own confirmed reasoning for why a new `ShouldDispatchAfterCommit` dispatch does not require touching unrelated existing tests, except where the Receipt Boundary correction's own subprocess/queue findings (below) apply.

### Test-harness / subprocess audit

Exhaustive grep of `tests/Unit/Usage` and `tests/Feature/Usage` for the 22 required strings (all 7 missing event names, both funding events, `BusinessWalletBillingStatusChanged`, `ExpireStaleUsageReservations`, `ReconcileProviderPendingState`, `SendLowBalanceNotification`, `SendAutoRechargeDisabledNotification`, `Event::fake(`, `Queue::fake(`, `Notification::fake(`, `ShouldDispatchAfterCommit`, `ShouldQueueAfterCommit`) confirms: zero existing coverage for all 7 missing events, zero for both funding events, zero for `ReconcileProviderPendingState`/`SendLowBalanceNotification`/`SendAutoRechargeDisabledNotification`; `SlotAgreementConcurrencyTest.php` is the only file using `Event::fake(`.

**Real-subprocess concurrency tests inspected:** `ConcurrentTopUpConcurrencyTest.php` calls `creditFromFunding()`-reaching confirmation paths from spawned child processes (the Receipt Boundary correction's own exceptional-correction fix already forces `QUEUE_CONNECTION=sync` explicitly in `baseRunnerPreamble()`, independent of any machine-local `.env.testing`, per that contract's §"Exceptional post-review factual/test-harness correction," unchanged by this contract). This correction's new event dispatches inside `creditFromFunding()` (§6.4) will fire inline in those same child processes exactly as `SendReceiptNotification` already does — since zero listeners are registered, this has no observable effect and requires no further child-process fix beyond what Receipt Boundary already locked. `AutoRechargeFailedPaymentRetryTest.php`'s own forced-race child-process tests (lines 220+) exercise `EvaluateBusinessAutoRecharge::dispatch()`/`recordAutoRechargeFailure()`-reaching paths directly; per the same already-established `QUEUE_CONNECTION=sync` discipline, a genuinely implemented `SendAutoRechargeDisabledNotification::dispatch()->afterCommit()` inside `recordAutoRechargeFailure()` would also need to execute correctly inline in that file's spawned children at implementation time — flagged here for the implementation phase to re-verify empirically (per the Receipt Boundary correction's own hard-won lesson: parent `phpunit.xml` settings are never assumed to govern spawned PHP processes), not resolved by this contract-only pass.

---

## 12. Excluded scopes — restated in full

This correction does not implement, design, or absorb any of the following:

- Admin Usage Billing Surface (remediation #5), including `ManualCredit`/`PromotionalCredit`/`UsageChargeReversal`/`CorrectionReversal` ledger-entry producers or dispatch.
- Provider Refund/Dispute Outcome Handling (remediation #6), including `Refund`/`DisputeChargeback` ledger writes and dispute-driven billing suspension — except the mere verification already performed in §8 that `BusinessWalletBillingStatusChanged`'s existing producer is unaffected.
- Residual §35-only cleanup (remediation #7) — except the exact tests directly required by this correction (§9, §11). The four `REACHABLE_BUT_TEST_GAP` slot events identified in §8 are explicitly left for that future remediation, not absorbed here.
- Add-on HTTP surface.
- `AdvanceUsagePeriodBoundaries` — locked OPTIONAL_NON_BLOCKING / NOT IMPLEMENTED (§2); no contradicting evidence found.
- M6 conformance/deployment docs; the release tag.
- Conversations pilot activation; tax/VAT implementation; legacy invoices.
- `SendReceiptNotification`/`ReceiptAvailableNotification` — Receipt Boundary is closed; only regression-verified (§10), never modified.
- The RFC-vs-code `requires_action` counter-increment discrepancy (§5) — disclosed, not resolved.
- `ExpireStaleUsageReservations`'s exact schedule cadence (§3) — disclosed as an unresolved STOP condition, not invented.

---

## 13. Confirmations

- **No schema/migration change is required or authorized by this correction.** Every column referenced (`low_balance_notified_at`, `auto_recharge_threshold_micro`, `auto_recharge_enabled`, `consecutive_recharge_failures`, `available_balance_micro`, `debt_balance_micro`) already exists per RFC-005 §12/§25's current, already-shipped schema.
- `AI-AUTONOMY-STATE.json` is untouched by this branch.
- M6 remains frozen — not resumed, not referenced beyond the "this is remediation #4 of 7" framing already established by the three prior merged corrections.
- No product, test, config, route, or RFC-source file is touched by this branch. This governance branch changes exactly one file.
- No implementation has occurred. Correction rounds: 0 of 2 consumed.
- Reservation Admission, Funding Provider-Flow, and Receipt Boundary are not reopened, contradicted, or reinterpreted anywhere above.

---

## 14. Open items requiring human resolution before implementation can proceed to full scope

1. **`ExpireStaleUsageReservations`'s exact schedule cadence (§3).** No RFC section or merged contract specifies one. A human must choose an exact interval (the 30-minute reservation TTL is offered only as context, not as an authoritative answer) before `app/Console/Kernel.php` can be touched.
2. **The RFC-vs-code `requires_action` counter-increment discrepancy (§5).** RFC-005 §19 says the counter increments on `failed`/`requires_action`; current merged code and a current passing test both confirm only `failed` increments it. A human must decide whether to correct the RFC text, correct the code (a change outside this correction's own scope), or explicitly ratify the current code as authoritative.
3. **Low-balance notification's non-applicability to wallets without auto-recharge configured (§4 item 2).** RFC-005 defines no independent "low balance" threshold for a wallet with `auto_recharge_enabled = false` or `auto_recharge_threshold_micro` null. If low-balance notification should also apply to such wallets, a human must define the threshold concept RFC-005 currently omits.

---
