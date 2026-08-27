# RFC-005 Job/Event Dispatch Completion Correction Contract

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

This document authorizes drafting one thing: a bounded, independently governed correction that closes exactly the five RFC-005 §29 job/event dispatch gaps named below — the two missing jobs (`SendLowBalanceNotification`, `SendAutoRechargeDisabledNotification`), the seven missing wallet/reservation domain events, the two already-defined-but-dead funding-attempt events, `ExpireStaleUsageReservations`'s unreachability, and `ReconcileProviderPendingState`'s **test-coverage** gap specifically. **Narrowed statement, corrected in the exceptional post-review pass below: this correction closes those five gaps; it does not close the separate, independently-discovered `ReconcileProviderPendingState` reconciliation race (§9/§12/§14) — that is a different kind of defect (a pre-existing correctness/robustness gap in already-merged M3 code, not a dispatch-reachability or test-coverage gap) and requires its own separately governed correction before M6 may resume, described below.** Human merge of this contract does **not** itself start implementation — a human must separately, explicitly instruct that implementation begin on the branch named in §0, exactly as every RFC-005 milestone and correction contract before it (most recently the Receipt Boundary correction, [`RFC-005-RECEIPT-BOUNDARY-CORRECTION-CONTRACT.md`](./RFC-005-RECEIPT-BOUNDARY-CORRECTION-CONTRACT.md), merged PR [#139](https://github.com/os-creator1/os-ai/pull/139)) has required.

This correction exists because the M6 static conformance audit found Job/Event Dispatch Completion to be a real blocking gap: two of thirteen RFC-005 §29 jobs are missing entirely, seven of seventeen §29 events are missing entirely, two more events exist as dead code with zero producers, one existing job is built but never scheduled (permanently unreachable), and one existing scheduled job has zero test coverage. This contract is remediation #4 of 7; it does not by itself unblock M6. **A sixth, newly-discovered mandatory pre-M6 correction — the `ReconcileProviderPendingState` reconciliation-race correction — is now also required before M6 may resume, in addition to remediations #5–#7 (§12/§14).**

This is **contract-authoring only**. No product code, test code, schema, route, config, or RFC-source file is touched by this branch. Reservation Admission, Funding Provider-Flow, and Receipt Boundary are closed corrections and are not reopened, contradicted, or reinterpreted by anything below.

---

## Correction Round 1 record

Independent pre-merge review of the initial draft (head `bea514b83a355a1fe1ed02d45d2e875074ae6d59`) found seven defects, all resolved below by direct mechanical re-audit of `UsageWalletManager.php`, `UsageBillingCheckoutManager.php`, `EvaluateBusinessAutoRecharge.php`, `app/Console/Kernel.php`, and the existing `AutoRechargeFailedPaymentRetryTest.php`/`OpportunitySnoozeSweepScheduleTest.php` test files, plus explicit human resolutions received for the three previously-open product decisions. **1 of 2 ordinary correction rounds consumed by this round; 1 ordinary round remains.**

Exact issues resolved this round:

1. **False `ShouldDispatchAfterCommit` ordering reasoning for `BusinessFundingAttemptSucceeded`.** The initial draft claimed an event dispatched immediately after `recordTransition()` (before `creditFromFunding()`/`finalizeAddonPurchaseIfPending()` run) would still correctly defer to those later operations' own transactions. Direct re-read of `confirmSucceeded()` disproves this: no transaction is open at that point, so the event fires immediately, before the purpose-specific local finalization has committed. Corrected in §7.1 — the dispatch point moves to after `creditFromFunding()`/`finalizeAddonPurchaseIfPending()` each return.
2. **False "exact-once delivery" claim for both notification jobs.** The initial draft claimed `SendLowBalanceNotification`/`SendAutoRechargeDisabledNotification` achieve "exact-once delivery." Re-audited against `SendReceiptNotification` and Laravel's actual queue/notification semantics: there is no transactional outbox and no provider-level idempotency key for mail delivery anywhere in this repository. The durable marker/transition only guarantees at most one automatic dispatch *decision* per episode — it says nothing about whether the queued job later executes, or whether the mail transport actually delivers. Corrected throughout §4/§5.
3. **Explicit resolution of the RFC-005 §19 vs. M3-code `requires_action` conflict**, previously left as an open item. Per explicit human authorization, both `Failed` and `RequiresAction` now count as consecutive auto-recharge failures, matching RFC-005 §19's own text; this human-authorized correction is disclosed as superseding both the current `EvaluateBusinessAutoRecharge.php` docblock's stated design ("`requires_action`... does not increment it") and the current passing test `AutoRechargeFailedPaymentRetryTest::test_a_requires_action_outcome_does_not_increment_the_failure_counter`. Neither old behavior is described as still authoritative anywhere below. Corrected in §5.
4. **`ExpireStaleUsageReservations`'s previously-unresolved schedule cadence**, now explicitly resolved by human authorization to `->everyFiveMinutes()`, matching the repository's own existing five-minute cadence for comparable RFC-005 reconciliation/renewal jobs (`ReconcileProviderPendingState`, `InitiateSlotAgreementRenewal`, `FinalizeSlotAgreementCancellation`). Corrected in §3; `app/Console/Kernel.php` added to the production allowlist; a dedicated scheduler-reachability test locked in §11.
5. **Low-balance applicability rule tightened to a mechanically safe episode model.** The initial draft's "must mathematically cross from above to below" rule could miss a notification when configuration changed while the wallet was already low. Replaced, per explicit human resolution, with a marker-null-check rule: notify whenever an eligible negative mutation leaves the wallet at-or-below threshold AND the marker is currently null, regardless of whether the previous balance was itself above or below threshold. Corrected in §4.
6. **Stale allowlist headers.** The initial draft's §10 header claimed "Count: 7" while listing 13 paths, and §14's open-items list still described three items this round's human resolutions now close. Every count and heading below is recomputed from the corrected content, not carried over.
7. **`recordAutoRechargeFailure()`'s system-disable path was found, on re-audit, to risk erasing `auto_recharge_threshold_micro`/`auto_recharge_amount_micro`/`monthly_recharge_cap_micro` if it were implemented by reusing `configureAutoRecharge(enabled: false)` internally** — that method's own current code (`UsageWalletManager.php:1237-1242`) unconditionally nulls both threshold and amount whenever `enabled` is `false`. This is correct and unchanged for a **deliberate** owner disable, but would be wrong for a **system** disable, which must preserve configuration so a human can decide whether to simply re-enable at the same threshold later. Corrected in §5: the system-disable path writes `auto_recharge_enabled = false` directly via the wallet repository, never through `configureAutoRecharge()`.

Two additional, previously-undiscovered mechanical facts found during this round's audit, disclosed per this contract's own zero-discretion standard:

- **`configureAutoRecharge()` does not currently reset `consecutive_recharge_failures` on any transition**, including disabled→enabled. Human resolution B.6 requires a reset on that specific edge; this requires one further, minimal widening of the same already-modified method, locked in §5.
- **This worktree has no `vendor/` directory** (dependencies are not installed here), so the exact `Illuminate\Console\Scheduling\Event` property that identifies a `$schedule->job(new X())` registration (as opposed to a `$schedule->command(...)` registration, which the one existing scheduling test in this repository, `OpportunitySnoozeSweepScheduleTest.php`, identifies via its `->command` string) could not be confirmed against the actual installed `laravel/framework` source in this pass. The scheduler test design in §11 discloses this precisely rather than asserting false certainty about the exact property name; the interval assertion (`->expression === '*/5 * * * *'`) does not depend on this and is unaffected.

No genuinely new blocking product/schema question was found this round beyond what §14 discloses.

---

## Correction Round 2 record

Independent post-Round-1 review of head `3a6a1b6cd1053033a972d1dc78508cd84169843a` found one genuine remaining defect, resolved below by direct mechanical re-audit of `ReconcileProviderPendingState.php`, `confirmAttemptFromReturn()`, and `confirmSucceeded()`. Every other item raised in this round's review request (notification-delivery wording, allowlist counts, the `requires_action` conflict, low-balance applicability, the expiry cadence, the low-balance/auto-recharge-disable transaction semantics, the `BusinessFundingAttemptSucceeded` emission point, the seven wallet/reservation events' semantic map, and the production/test allowlist recomputation) was independently re-verified against the current file this round and found **already correctly resolved by Correction Round 1** — restated below rather than reworded by inertia. **This is the final ordinary correction round: 2 of 2 consumed; 0 ordinary rounds remain.**

Exact issue resolved this round:

1. **`ReconcileProviderPendingState`'s test method 5 described an impossible scenario.** The prior wording, `test_reconciliation_never_duplicates_accounting_for_an_already_succeeded_attempt`, fed an already-`Succeeded` attempt directly into a code path the job's own query (`whereIn('state', [ProviderPending, RequiresAction])`) can never select it through. Re-audited and replaced with `test_the_reconciliation_query_never_selects_an_already_succeeded_attempt`, which proves the one guarantee this job's actual query structure honestly supports. Corrected in §9.

One additional, previously-undiscovered mechanical fact found while designing that method's replacement, disclosed per this contract's own zero-discretion standard rather than silently absorbed or silently ignored:

- **A genuine, pre-existing race-condition robustness gap in already-merged M3 code**, unrelated to this correction's own scope: `ReconcileProviderPendingState.handle()` iterates already-loaded, potentially-stale in-memory attempt models rather than re-fetching each one immediately before confirmation, and `confirmSucceeded()`'s main branch has no guard against being invoked twice for the same attempt (unlike its `AddonPurchase` branch, which does catch `UniqueConstraintViolationException` around its own credit call). A concurrent webhook racing a reconciliation pass on the same stuck attempt could, in the current code, cause an uncaught `UniqueConstraintViolationException` to crash the entire scheduled run. **Not fixed by this correction** (out of the locked "does not redesign the reconciliation flow" boundary) — disclosed in §9/§12/§14 for a future, separately-governed decision.

No other genuinely new blocking product/schema question was found this round.

---

## Exceptional post-review correction

**This is NOT Correction Round 3.** `maximum_correction_rounds: 2` is unchanged. Ordinary correction rounds remain **2 of 2 consumed; 0 ordinary rounds remain** — this exceptional pass neither consumes nor creates an ordinary round, matching the same exceptional-correction mechanism used twice before in this engagement (the Funding Provider-Flow correction's and Receipt Boundary correction's own post-review exceptions).

Prior head: `5a4cdc99e98a8b3eaf598c4b81b0e9637dea5917`.

Authorized solely to correct two independently-verified post-review findings:

1. **The event-ordering test proof in §9 method 1 and §11's `FundingAttemptTerminalEventDispatchTest.php` row was factually invalid.** `Event::fake()` intercepts a dispatch and only records it; a database assertion made later, inside an `Event::assertDispatched(..., function (...) { ... })` callback, runs after the operation under test has already finished and observes end-of-test state, not the state that existed at the instant the event actually fired — it proves nothing about temporal ordering. Corrected: the ordering-sensitive test methods now register a real, synchronous `Event::listen()` listener *before* invoking the confirmation path (the event itself is not faked in those methods), assert the required durable local state from *inside* that listener — which genuinely executes at dispatch time, including correctly through a `ShouldDispatchAfterCommit` deferral — and assert the listener ran exactly once. The three multiplicity-only test methods (replay/non-redispatch, `Failed`'s immediate dispatch) are unaffected, since `Event::fake()` + `assertDispatchedTimes()`/`assertNotDispatched()` is the correct tool for proving *whether/how many times* an event fired, not *when* relative to other state. The production dispatch points locked in Correction Round 1 (§7.1: after `creditFromFunding()`/`finalizeAddonPurchaseIfPending()` return) are unchanged — re-audited and re-confirmed, not altered, since this finding was about the test mechanism only, never the production code. The production allowlist is unchanged for the same reason.
2. **The `ReconcileProviderPendingState` reconciliation race (disclosed, not fixed, in Correction Round 2) was under-classified.** The prior §14 wording described it as merely "flagged... for a human decision on whether a future correction should" address it — too weak for a mechanically-known correctness gap. Corrected: §1 (opening), §12, and §14 now state explicitly that this correction does not fix the race, that this correction's own implementation may still proceed once separately authorized, and that RFC-005 M6 may **not** resume until the race receives its own separately drafted, human-reviewed, merged correction contract, implementation, and verification — inserted into the remediation sequence immediately after remediation #4 (this correction) and before remediation #5 (Admin Usage Billing Surface), referred to by name rather than by a renumbered position so no existing cross-reference in this or any other merged contract is disturbed.

No product, test, schema, config, or route change is authorized or made by this exceptional pass. No implementation occurred. `AI-AUTONOMY-STATE.json` is untouched. M6 remains frozen. This governance branch still changes exactly one file.

---

## 0. Governance

- Drafted on branch `chore/rfc-005-job-event-dispatch-completion-correction-contract`, in an isolated linked worktree (`../rfc-005-job-event-dispatch-completion-contract-worktree`), based on `origin/main` at `ae0aba36057360eb1149ef980beeb90f9d2d250f` — the Receipt Boundary correction's own merge commit (PR [#139](https://github.com/os-creator1/os-ai/pull/139)). Reconfirmed unchanged this round via `git fetch origin && git rev-parse origin/main` and `git merge-base origin/main HEAD` (both returned `ae0aba36057360eb1149ef980beeb90f9d2d250f`) — this branch remains legitimately based on current `origin/main`, no rebase needed.
- **Future implementation branch (authorized only after this contract's human merge, and only after a further, separate, explicit human instruction to begin implementation): `agent/rfc-005-job-event-dispatch-completion-correction`.**
- Confirmed this round: no such branch exists on `origin`; `git status --short` in this worktree is empty before and after this round's edit (only the one governance file is modified); no product/test/config/route/RFC-source file has been touched by this branch at any point.
- Locked:
  - `human_only_merge: true`
  - `maximum_correction_rounds: 2`
  - **Correction rounds: 2 of 2 consumed; 0 ordinary rounds remain.**
  - `advance_automatically: false`
  - `start_automatically_after_contract_merge: false`
  - `M6 remains frozen` — untouched, not resumed, no M6 document created or modified.
  - `AI-AUTONOMY-STATE.json` is untouched by this branch.
  - **Implementation requires a separate, explicit human instruction issued after this contract itself is human-merged.**
- This is a new, independently bounded pre-M6 correction contract — not a correction round against M1–M6's own contracts, and not a correction round against the already-merged Reservation Admission, Funding Provider-Flow, or Receipt Boundary contracts. Its `maximum_correction_rounds: 2` budget is its own; no counter is borrowed or altered on any other contract.
- This governance branch changes exactly one tracked file: `docs/automation/RFC-005-JOB-EVENT-DISPATCH-COMPLETION-CORRECTION-CONTRACT.md`.
- **Re-audited this round, independently, before editing** (not preserved merely because already asserted in the prior draft): `UsageWalletManager.php`'s `reserve()`, `commit()`, `release()`, `creditFromFunding()`, `recordAutoRechargeFailure()`, `configureAutoRecharge()` (full bodies, fresh reads); `UsageBillingCheckoutManager.php`'s `confirmSucceeded()`, `markFailed()`, `confirmAttemptFromReturn()`, `confirmAttemptFromWebhook()`, `retryFundingAttemptAsAdministrator()` (full bodies, fresh reads); `EvaluateBusinessAutoRecharge.php` (full file, fresh read); `app/Console/Kernel.php` (full file, fresh read — unchanged since the initial draft); `app/Enums/Usage/FundingAttemptState.php` (case list); `AutoRechargeFailedPaymentRetryTest.php`'s existing three test methods (full read); `OpportunitySnoozeSweepScheduleTest.php` (full read, the repository's own existing scheduling-test precedent); RFC-005 §19 (re-read verbatim).

---

## 1. §29 baseline — mechanically re-verified, unchanged since the initial draft

**Jobs, confirmed 10 of 13 exist** (`app/Jobs/Usage/*.php`, all extend `App\Jobs\Base`): `EvaluateBusinessAutoRecharge`, `ProcessPaymentProviderEvent`, `ReconcileProviderPendingState`, `ReconcileSlotAgreementAllocation`, `InitiateSlotAgreementRenewal`, `FinalizeSlotAgreementCancellation`, `PurgeExpiredWebhookPayloads`, `SendReceiptNotification`, `SendSlotAgreementPriceChangeNotice`, `ExpireStaleUsageReservations`. **Confirmed missing, exactly two:** `SendLowBalanceNotification`, `SendAutoRechargeDisabledNotification`.

**Events, confirmed 10 of 17 exist**, all `implements ShouldDispatchAfterCommit`, `use Dispatchable`, carry only scalar/ID constructor properties: `BusinessPayerChanged`, `BusinessBillingContactChanged`, `BusinessFundingAttemptSucceeded`, `BusinessFundingAttemptFailed`, `BusinessWalletBillingStatusChanged`, `AdditionalBusinessSlotAgreementCompleted`, `AdditionalBusinessSlotAllocationFailed`, `AdditionalBusinessSlotAgreementLapsed`, `AdditionalBusinessSlotAgreementCanceled`, `AdditionalBusinessSlotAgreementPaymentRecovered`. **Confirmed missing, exactly seven:** `BusinessWalletCredited`, `BusinessWalletDebited`, `BusinessWalletDebtIncurred`, `BusinessWalletDebtCleared`, `BusinessUsageReserved`, `BusinessUsageCommitted`, `BusinessUsageReservationReleased`.

**Confirmed dead, exactly two of the ten existing events:** `BusinessFundingAttemptSucceeded::dispatch(` and `BusinessFundingAttemptFailed::dispatch(` have zero call sites anywhere in `app/`.

**Kernel schedule, re-confirmed exactly 5 entries this round** (`app/Console/Kernel.php:110-117`, byte-identical to the initial draft's citation): `PurgeExpiredWebhookPayloads` (hourly), `ReconcileProviderPendingState` (everyFiveMinutes), `InitiateSlotAgreementRenewal` (everyFiveMinutes), `FinalizeSlotAgreementCancellation` (everyFiveMinutes), `ReconcileSlotAgreementAllocation` (hourly). `ExpireStaleUsageReservations` remains absent — resolved this round, §3.

**`AdvanceUsagePeriodBoundaries` confirmed to still not exist as a class anywhere in `app/`.**

---

## 2. `AdvanceUsagePeriodBoundaries` — classification confirmed, not widened, unchanged since the initial draft

RFC-005 §15 states verbatim: *"The scheduled `AdvanceUsagePeriodBoundaries` job remains optional proactive maintenance only, never required for correctness."* Not listed among §39's 14 open human decisions. `UsageWalletManager::rollOverPeriodsIfNeeded()` is already called from inside `reserve()`, `commit()`, `release()`, and `creditFromFunding()` — lazy rollover is already fully load-bearing.

**No RFC or merged-contract evidence contradicts M6's prior OPTIONAL/NON-BLOCKING classification.** Locked: `AdvanceUsagePeriodBoundaries` is **OPTIONAL_NON_BLOCKING / NOT IMPLEMENTED BY THIS CORRECTION**.

---

## 3. `ExpireStaleUsageReservations` — cadence resolved by explicit human authorization

**Mechanical facts, re-confirmed by direct read this round:**

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
- `release()` (`UsageWalletManager.php:702-773`) is idempotent on an already-terminal reservation (lines 722-725) and computes `Expired` vs `Released` by comparing `Carbon::now()` to `expires_at` at call time (lines 737-739) — `ExpireStaleUsageReservations`'s own calls naturally resolve to `Expired`.
- Reservation TTL remains `RESERVATION_TTL_MINUTES = 30` (`UsageWalletManager.php:67`) — this is a reservation lifetime, not a schedule interval, and **is not itself the cadence** (explicitly restated per the human resolution's own rationale).

**No RFC section or merged contract mechanically specifies a cadence** (re-confirmed this round — see the initial draft's exhaustive search of §29, §13, §39, §40, the M1/M3/M5 contracts, and the three merged correction contracts; nothing has changed in any of those documents since).

**Resolved by explicit human authorization (Correction Round 1):** `ExpireStaleUsageReservations` **must** be scheduled with `->everyFiveMinutes()`. Rationale, recorded verbatim from the authorization: the 30-minute reservation TTL remains unrelated to the schedule interval itself; five minutes bounds stale-reservation overhang without changing reservation semantics; the repository already uses a five-minute cadence for the three comparable RFC-005 reconciliation/renewal jobs already in `Kernel.php` (`ReconcileProviderPendingState`, `InitiateSlotAgreementRenewal`, `FinalizeSlotAgreementCancellation`); `release()`'s own idempotent, row-locked design (confirmed above) means a five-minute cadence changes only reachability, never domain semantics.

**Locked Kernel change:** one new entry, `$schedule->job(new ExpireStaleUsageReservations())->everyFiveMinutes();`, placed in `app/Console/Kernel.php`'s non-demo `else` branch alongside the existing RFC-005 job registrations (immediately after the `ReconcileSlotAgreementAllocation` line, matching the file's existing grouping of RFC-005 scheduled jobs together, before the closing brace of the `else` block). `app/Console/Kernel.php` is added to the production allowlist (§10). No other Kernel entry, and no change to `ExpireStaleUsageReservations.php` itself, is authorized — the human resolution explicitly confirms this does not alter domain semantics.

This item is removed from the open-items list (§14).

---

## 4. Missing job: `SendLowBalanceNotification`

**Trigger mechanism, re-confirmed by direct code read this round:**

- `business_usage_wallets.low_balance_notified_at` (nullable timestamp) has zero references anywhere in `UsageWalletManager.php` (re-confirmed by fresh grep this round).
- `auto_recharge_threshold_micro` remains the only concrete numeric quantity RFC-005 ties to "low balance."

**Applicability — resolved by explicit human authorization, unchanged from the initial draft's own conclusion:** `SendLowBalanceNotification` applies only when `auto_recharge_enabled === true` AND `auto_recharge_threshold_micro !== null`. No independent general low-balance threshold is invented for a wallet outside this condition. **This item is removed from the open-items list (§14)** — the human resolution ratifies the initial draft's own scope boundary as final, rather than leaving it open.

**Episode rule — corrected this round per explicit human authorization, replacing the initial draft's stricter "must cross from above to below" rule:**

- On an eligible mutation that decreases `available_balance_micro` (only `reserve()`'s reservation-creation delta and `commit()`'s overage-from-available delta currently produce a negative `available_delta_micro` — the same two sites already gating `EvaluateBusinessAutoRecharge`'s own dispatch, confirmed by fresh re-read of both methods this round): after computing the new (post-mutation) `available_balance_micro`, if the new balance `<= auto_recharge_threshold_micro` **and** `low_balance_notified_at IS NULL`, set `low_balance_notified_at = now()` in the same wallet update and dispatch `SendLowBalanceNotification::dispatch($businessId)->afterCommit()`.
- On a mutation that increases `available_balance_micro`, if the post-mutation balance `> auto_recharge_threshold_micro`, clear `low_balance_notified_at` to `null` in the same wallet update. **Confirmed this round: three code sites produce a positive `available_delta_micro`** — `creditFromFunding()`'s `$remainder` (when positive), `commit()`'s unused-reservation-release branch (`$availableDelta += $unused`, line 632-654), and `release()`'s own `+$amount` (line 762-765, both the `Released` and `Expired` outcomes). The clear check applies at all three sites, not only `creditFromFunding()` — a reservation release genuinely raises available balance exactly as a funding credit does, and the marker's purpose (avoiding a stuck "already notified" state) is defeated if only one of the three recovery paths clears it.
- **Re-enabling auto-recharge alone does not clear the marker** — only an actual positive-balance mutation crossing back above threshold does, per explicit human resolution. `configureAutoRecharge()` is not touched by this rule.
- This marker-null-check model (rather than requiring a mathematically-provable prior-above/now-below crossing) is deliberately more permissive: it also correctly notifies once if the wallet is already at/below threshold at the moment eligibility is (re)established (e.g., threshold newly configured while balance is already low), which the initial draft's stricter crossing-only rule would have missed.

**Recipients, opt-out handling:** unchanged from the initial draft — `BusinessBillingContactRepository::findByBusinessId()`, `notification_opt_in = true` gate, `contact_user_id === null ? contact_email : contactUser->email` resolution, silent no-op on missing contact/opt-out/blank email — byte-for-byte the same algorithm `SendReceiptNotification::handle()` already uses (`app/Jobs/Usage/SendReceiptNotification.php:69-93`).

**Delivery guarantee — corrected this round, false claim removed:** `low_balance_notified_at` guarantees **at most one automatic dispatch decision per below-threshold episode** — the wallet-locked mutation that sets the marker and the wallet-locked mutation that dispatches the job are the same atomic unit, so the system will not decide to dispatch a second time while the marker is set. This is **not** a claim that the notification is delivered exactly once: the queued job itself uses this repository's normal queue/mail semantics (the same `ShouldQueueAfterCommit` + default queue connection `SendReceiptNotification` already uses, with `App\Jobs\Base`'s unchanged `$tries = 1` / `$maxExceptions = 1`), with no transactional outbox and no provider-level idempotency key for mail delivery anywhere in this codebase. If the queue fails to run the job after the marker commits (worker crash, exception, queue driver outage), the marker is already set and the email itself may never be sent at all — this is a real, disclosed limitation, not schema-guaranteed delivery. No new outbox/schema/provider-idempotency mechanism is authorized by this correction to close that gap.

**Ownership, no new schema:** unchanged from the initial draft — `UsageWalletManager` remains sole write authority for `business_usage_wallets`; the notification job performs recipient resolution and delivery only and never writes `low_balance_notified_at` itself; no migration required.

**Exact widening point:** `reserve()` (after the wallet update, `UsageWalletManager.php:474-478`) and `commit()`'s overage branch (after the wallet update, `UsageWalletManager.php:674`) each gain the set-side check; `creditFromFunding()` (after the wallet update, line 836), `commit()`'s unused-release branch (after the wallet update, line 674), and `release()` (after the wallet update, line 771) each gain the clear-side check. All five sites are inside their method's existing transaction. This correction locks these exact responsibilities and call sites for the implementation phase (§10); it does not write the code itself.

---

## 5. Missing job: `SendAutoRechargeDisabledNotification`

**Trigger mechanism, re-confirmed by direct code read this round** (`UsageWalletManager.php:1255-1268`, byte-identical to the initial draft's citation): `recordAutoRechargeFailure()` only increments the counter today; it checks no threshold, sets no disable flag, dispatches nothing.

**RFC-005 §19, re-read verbatim this round: *"Consecutive-failure counter — unchanged: `business_usage_wallets.consecutive_recharge_failures`, incremented on `failed`/`requires_action`, reset on `succeeded`; 3 is the recommended (category-3) disable threshold."*** Not a §39 open item. Threshold locked at **3**, as in the initial draft.

**The RFC-vs-M3-code `requires_action` conflict is now explicitly resolved by human authorization, not merely disclosed.** Authoritative behavior for this correction, superseding both the current `EvaluateBusinessAutoRecharge.php` docblock (lines 72-75: *"requires_action is not a failure... and does not increment it"*) and the current passing test `AutoRechargeFailedPaymentRetryTest::test_a_requires_action_outcome_does_not_increment_the_failure_counter` — **neither of those is authoritative any longer**:

1. **Both `FundingAttemptState::Failed` and `FundingAttemptState::RequiresAction` count as consecutive auto-recharge failures**, matching RFC-005 §19 exactly.
2. `consecutive_recharge_failures` resets to `0` on a successful `AutoRecharge` credit — unchanged, already implemented (`creditFromFunding()`, `UsageWalletManager.php:831-834`, gated on `entryType === AutoRecharge && recharge_period_key !== null`). This correction does not touch that reset logic.
3. **The failure-count transition `2 → 3`, while `auto_recharge_enabled` is currently `true`, is the system-disable edge.**
4. **On that same wallet-locked mutation:** set `consecutive_recharge_failures = 3`; set `auto_recharge_enabled = false`; **do not** touch `auto_recharge_threshold_micro`/`auto_recharge_amount_micro`/`monthly_recharge_cap_micro` — re-confirmed by direct read this round that `configureAutoRecharge()`'s own existing code (`UsageWalletManager.php:1237-1242`) is the method that nulls threshold/amount when `enabled` is explicitly set `false`, and that method is a **deliberate, distinct code path** the system-disable must not call into, precisely so the system-disable preserves the configured values a human may want to see when deciding whether to re-enable; dispatch `SendAutoRechargeDisabledNotification::dispatch($businessId)->afterCommit()` from inside the same transaction, mirroring `creditFromFunding()`'s own `SendReceiptNotification::dispatch(...)->afterCommit()` precedent (line 838-839) rather than the flag-and-dispatch-after-the-transaction-closure pattern `reserve()`/`commit()` use for `EvaluateBusinessAutoRecharge` — both are valid `ShouldQueueAfterCommit` patterns already present in this file; the in-closure form is chosen here because it is the closer precedent for a job dispatched conditionally from inside a single-purpose method.
5. **Counts above 3 never re-notify for the same disabled episode.** Locked condition: the disable-and-notify branch fires only when `$newFailureCount === 3 AND $wallet->auto_recharge_enabled === true` at the start of the mutation — not merely `>= 3`. In ordinary operation this can only happen once per episode, because `EvaluateBusinessAutoRecharge::handle()`'s own top-of-method guard (`if ($wallet === null || ! $wallet->auto_recharge_enabled) { return; }`, unchanged) prevents the job from ever re-entering `recordAutoRechargeFailure()` while already disabled. The exact `=== 3` (not `>= 3`) condition is additionally a defensive guard against any future or out-of-band caller of `recordAutoRechargeFailure()`.
6. **A later, explicit, payer-authorized `configureAutoRecharge(enabled: true, ...)` transition, specifically from `auto_recharge_enabled === false` to `true`, resets `consecutive_recharge_failures` to `0`**, starting a new failure episode. **Newly confirmed this round: `configureAutoRecharge()`'s current code does not reset this counter on any transition today** — this is a genuine, real widening of that method, not merely a restatement of existing behavior. Locked exactly: the reset applies only on the disabled→enabled edge (`$wallet->auto_recharge_enabled === false` immediately before the update, `$enabled === true` as passed in) — not on every call with `enabled: true`, so a benign re-save while already enabled does not spuriously reset an in-progress (but not yet disabled) failure count.
7. **Deliberate customer/administrator disablement via `configureAutoRecharge(enabled: false, ...)` does not emit `SendAutoRechargeDisabledNotification`.** Confirmed by construction: only `recordAutoRechargeFailure()` dispatches this job; `configureAutoRecharge()` is not widened to dispatch it under any resolution above.

**Delivery guarantee — corrected this round, same false-claim removal as §4:** the `true → false` transition on `auto_recharge_enabled`, captured inside the one wallet-locked mutation, guarantees **at most one automatic dispatch decision per disable episode** — not exact-once external delivery. The queued job uses ordinary queue/mail semantics; no outbox or provider-level idempotency key exists or is authorized here.

**Ownership, no new schema:** `UsageWalletManager` remains sole write authority; both `consecutive_recharge_failures` and `auto_recharge_enabled` already exist (§12); no migration required.

**Exact widening points, both inside `app/Library/Usage/UsageWalletManager.php`:**

- `recordAutoRechargeFailure()` (`UsageWalletManager.php:1255-1268`): read `$wallet->auto_recharge_enabled` before the update; compute `$newFailureCount = $wallet->consecutive_recharge_failures + 1`; if `$newFailureCount === 3 && $wallet->auto_recharge_enabled`, include `'auto_recharge_enabled' => false` in the same `update()` call and dispatch `SendAutoRechargeDisabledNotification::dispatch($businessId)->afterCommit()` immediately after that `update()` call, still inside the transaction closure.
- `configureAutoRecharge()` (`UsageWalletManager.php:1220-1244`): before the existing `update()` call, read the wallet's current `auto_recharge_enabled` value; if it is currently `false` and the incoming `$enabled` is `true`, include `'consecutive_recharge_failures' => 0` in the same `update()` call.

**Additional required production change, newly identified this round:** `app/Jobs/Usage/EvaluateBusinessAutoRecharge.php` (`EvaluateBusinessAutoRecharge.php:76-78`) must widen its conditional from `if ($result->state === FundingAttemptState::Failed)` to `if ($result->state === FundingAttemptState::Failed || $result->state === FundingAttemptState::RequiresAction)`, routing both outcomes through `recordAutoRechargeFailure()`. The method's own docblock (lines 72-75, currently asserting the opposite) must be corrected at implementation time to state the new authoritative behavior — this contract does not itself edit that file, it locks the exact required edit for the implementation phase.

This item is removed from the open-items list (§14) — resolution B is final for this correction, not merely disclosed.

---

## 6. Seven missing wallet/reservation events — exact emission map, re-audited this round

All seven remain `App\Events\Usage\*`, `implements ShouldDispatchAfterCommit`, `use Dispatchable`, IDs/scalars only, dispatched inline inside the same `DB::transaction()` closure that performs the mutation — re-confirmed against a fresh read of all four owning methods this round; nothing in this section changed as a result of that re-audit except the cross-reference to §4/§5's widened sites, since those now share some of the same transactions.

### 6.1 `BusinessUsageReserved`

- **Constructor:** `(int $businessId, int $reservationId, string $featureKey, int $reservedAmountMicro)`.
- **Emission site:** `UsageWalletManager::reserve()`, immediately before `return new ReservationResult(true, $reservation->id, null, true);` (`UsageWalletManager.php:480`).
- **Non-emission, re-confirmed:** the idempotent-repeat early return (pre-transaction `findByIdempotencyKey()` hit) and the `UniqueConstraintViolationException` race-loser catch (lines 482-493) never emit this event — both correspond to a `ReservationResult` whose 4th constructor argument is `false`.
- **Multiplicity:** exactly one per genuine reservation creation.

### 6.2 `BusinessUsageCommitted`

- **Constructor:** `(int $businessId, int $reservationId, string $featureKey, int $finalAmountMicro, int $reservedAmountMicro)`.
- **Emission site:** `UsageWalletManager::commit()`, immediately before `return new CommitResult(...)` at line 676.
- **Non-emission, re-confirmed:** the already-`Committed` early return (lines 535-543) never emits this event.
- **Multiplicity:** exactly one per genuine commit, independent of whether it also triggers §6.5's overage events or §4's low-balance check.

### 6.3 `BusinessUsageReservationReleased`

- **Constructor:** `(int $businessId, int $reservationId, string $featureKey, int $releasedAmountMicro, string $resultingStatus)`.
- **Emission site:** `UsageWalletManager::release()`, immediately after the wallet update at line 771 (the same site where §4's low-balance recovery-clear check, if applicable, also runs), before the transaction closure ends.
- **Non-emission, re-confirmed:** the already-terminal early return (lines 722-725) never emits this event.

### 6.4 `BusinessWalletCredited` and `BusinessWalletDebtCleared`

Both originate from `UsageWalletManager::creditFromFunding()` (`UsageWalletManager.php:793-841`), **re-confirmed not mutually exclusive** — a single call can emit both, one, or neither.

- `BusinessWalletCredited(int $businessId, int $walletId, int $ledgerEntryId, int $amountMicro)` — dispatched when `$remainder > 0` (line 811), reusing the already-captured `$ledgerEntry->id` (line 813).
- `BusinessWalletDebtCleared(int $businessId, int $walletId, int $ledgerEntryId, int $amountMicro)` — dispatched when `$debtCleared > 0` (line 810), same `$ledgerEntry`.
- **Emission site:** immediately after the wallet update at line 836 — now sharing this site with §4's low-balance recovery-clear check and the existing `SendReceiptNotification::dispatch(...)->afterCommit()` call at line 838. All three (the two events, the low-balance clear, and the receipt job) belong in the same transaction, in that relative order (event dispatches, being synchronous framework calls with zero registered listeners, do not need to precede or follow the low-balance clear or the receipt dispatch in any particular order for correctness — this contract does not mandate a specific sub-order among them, only that all remain inside the one transaction).
- **Explicitly not emitted from `reserve()`/`release()`'s own available-balance deltas** (owned by §6.1/§6.3) or from `commit()`'s unused-release branch (owned by §6.2) — re-confirmed, unchanged reasoning from the initial draft.

### 6.5 `BusinessWalletDebited` and `BusinessWalletDebtIncurred`

Both originate from `commit()`'s overage branch (`UsageWalletManager.php:596-631`), re-confirmed not mutually exclusive.

- `BusinessWalletDebited(int $businessId, int $walletId, int $ledgerEntryId, int $amountMicro)` — `$overageFromAvailable > 0` (line 606).
- `BusinessWalletDebtIncurred(int $businessId, int $walletId, int $ledgerEntryId, int $amountMicro)` — `$overageToDebt > 0` (line 608).
- **`$ledgerEntryId`:** still requires capturing `$overageLedgerEntry = $this->ledgerRepository->create([...]);` in place of the current bare `create()` call (lines 602-622) — unchanged from the initial draft.
- **Emission site:** immediately after the wallet update at line 674 — the same site now also hosting §4's low-balance set-side check (gated on `$overageFromAvailable > 0`, the identical condition already gating `$shouldDispatchAutoRecharge` at line 628-631) and §6.2's `BusinessUsageCommitted` dispatch.
- **Non-emission, re-confirmed:** governed by the same already-`Committed` early return as §6.2; neither fires when `$finalAmountMicro <= $reservedAmountMicro`.

**Re-confirmed this round, per the explicit re-audit instruction:** no future Admin Usage Billing or Refund/Dispute producer is invented anywhere in this section — `ManualCredit`, `PromotionalCredit`, `Refund`, `DisputeChargeback`, `UsageChargeReversal`, and `CorrectionReversal` ledger-entry types remain entirely without a producing code path in the current codebase, and this correction adds no dispatch for a mutation method that does not exist.

---

## 7. Funding-attempt events — exact emission map, corrected this round

### 7.1 `BusinessFundingAttemptSucceeded` — dispatch point corrected

**The initial draft's dispatch point and its supporting reasoning were both wrong, and are replaced here, not merely annotated.** The initial draft placed the dispatch immediately after `recordTransition()` (`confirmSucceeded()` line 639) and argued `ShouldDispatchAfterCommit` would still correctly defer to whichever transaction `creditFromFunding()` opens *afterward*. Re-reading `confirmSucceeded()` in full this round confirms this is backwards: at the line-639 dispatch point, **no transaction is open at all** (`confirmSucceeded()` itself is never wrapped in `DB::transaction()`), so the event would fire immediately — before `creditFromFunding()`'s own accounting transaction (for `ManualTopUp`/`AutoRecharge`) or `finalizeAddonPurchaseIfPending()`'s writes (for `AddonPurchase`) have even started, let alone committed. A transaction entered later in the same method cannot retroactively defer an event already dispatched and already fired.

**Corrected chokepoint and dispatch points, confirmed by direct re-read this round** (`UsageBillingCheckoutManager.php:628-658`):

```php
private function confirmSucceeded(...): void
{
    $fromState = $attempt->state;
    // ...attemptRepository->update(...); recordTransition(...);

    if ($attempt->purpose === FundingAttemptPurpose::AddonPurchase) {
        $this->finalizeAddonPurchaseIfPending($attempt, $source, $providerEventId);
        // dispatch here — after finalizeAddonPurchaseIfPending() returns
        return;
    }

    // ...$entryType = ...;
    $this->walletManager->creditFromFunding(...);
    // dispatch here — after creditFromFunding() returns
}
```

- **`ManualTopUp`/`AutoRecharge`:** dispatch `BusinessFundingAttemptSucceeded` immediately after the existing `$this->walletManager->creditFromFunding(...)` call (currently the method's last statement, lines 651-657) returns. `creditFromFunding()`'s own internal transaction has fully committed by the time it returns (it is a synchronous call, not a queued dispatch), so the event now fires only once the wallet credit is genuinely durable.
- **`AddonPurchase`:** dispatch immediately after `$this->finalizeAddonPurchaseIfPending($attempt, $source, $providerEventId);` (line 642) returns, before the branch's own `return;` (line 644). `finalizeAddonPurchaseIfPending()`'s own writes (the conditional `creditFromFunding()` call for `wallet_credit` fulfillment, then the purchase-status update and transition-audit insert, lines 679-720) are all synchronous and complete by the time it returns.
- **Payload, unchanged:** `((int) $attempt->id, (int) $attempt->business_id, $attempt->purpose->value, (int) $attempt->expected_amount_micro)`.
- **Multiplicity, re-confirmed:** exactly one dispatch per genuine transition, at exactly one of the two mutually exclusive branches.
- **Non-emission on replay, re-confirmed:** `confirmSucceeded()` is only ever reached through call sites that already early-return on an already-`Succeeded` attempt before calling it (`confirmAttemptFromReturn()` line 479-485, `confirmAttemptFromWebhook()` line 528-534) — a replay never re-enters `confirmSucceeded()`, so it never re-dispatches this event, under either the old or corrected dispatch point.
- **`ShouldDispatchAfterCommit` correctness under the corrected design:** since neither `creditFromFunding()` (already-closed transaction) nor `finalizeAddonPurchaseIfPending()` (no transaction at all) leaves a transaction open by the time execution reaches the new dispatch point, the event fires immediately at that point — which is now also the *correct* point, because the local finalization it reports on has already genuinely completed.
- **Test-proof mechanism, corrected in this exceptional pass (§9/§11 below carry the full design):** the production dispatch points above are unchanged and re-confirmed, but the *test* that proves them cannot use `Event::fake()` + a `Event::assertDispatched(..., function (...) { ... })` callback that queries the database — that callback runs later, after `Event::fake()`'s own recording has already captured the dispatch, and observes end-of-test-operation state, not the state that existed at the instant of dispatch. The valid proof registers a real, synchronous `Event::listen()` listener *before* invoking the confirmation path and asserts the required durable state exists from *inside* that listener, which genuinely executes at dispatch time.

### 7.2 `BusinessFundingAttemptFailed` — dispatch point re-confirmed unchanged

**Re-audited this round per the explicit instruction to verify "both local failure mutations have completed before dispatch."** `markFailed()` (`UsageBillingCheckoutManager.php:802-812`) performs exactly two writes — `$this->attemptRepository->update(...)` (state + failure_reason) and `$this->recordTransition(...)` — and does nothing else. Both are complete by line 811. There is no purpose-specific follow-up write analogous to `creditFromFunding()`/`finalizeAddonPurchaseIfPending()` on the failure path. **Confirmed: the dispatch point remains immediately after `recordTransition()` at line 811-812, unchanged from the initial draft.**

- **Payload, unchanged:** `((int) $attempt->id, (int) $attempt->business_id, $attempt->purpose->value)`.
- **Non-emission on replay, re-confirmed:** `markAttemptFailedFromWebhook()` already early-returns when `state` is already `Succeeded`/`Failed`/`Canceled` (line 569-571) before calling `markFailed()`.

---

## 8. Existing-event producer reachability audit — unchanged since the initial draft, re-confirmed

| Event | Classification | Evidence |
|---|---|---|
| `BusinessPayerChanged` | **REACHABLE_AND_PROVEN** | Sole dispatch in `BillingProfileManager::changePayer()` (line 127). Covered by `PayerTransitionAuditTest.php`/`PayerAssignmentTransitionScenariosTest.php`. |
| `BusinessBillingContactChanged` | **REACHABLE_AND_PROVEN** | Sole dispatch in `BillingProfileManager::updateBillingContact()` (line 172). Covered by `BillingProfileManagerBillingContactTest.php`. |
| `BusinessWalletBillingStatusChanged` | **REACHABLE_AND_PROVEN** | Sole dispatch in `UsageWalletManager::setBillingStatus()` (line 1205). Covered by `UsageWalletBillingStatusTransitionTest.php`. |
| `AdditionalBusinessSlotAgreementCompleted` | **REACHABLE_AND_PROVEN** | Dispatch at `UsageBillingCheckoutManager.php:2017`. Proven via `Event::fake()` in `SlotAgreementConcurrencyTest.php` (lines 223, 254) — the only Usage-suite file using `Event::fake()` at all. |
| `AdditionalBusinessSlotAllocationFailed` | **REACHABLE_BUT_TEST_GAP** | Dispatch at `UsageBillingCheckoutManager.php:1991`. No `Event::fake()`-based assertion exists. |
| `AdditionalBusinessSlotAgreementLapsed` | **REACHABLE_BUT_TEST_GAP** | Dispatch at `UsageBillingCheckoutManager.php:1782`. No `Event::fake()`-based assertion exists. |
| `AdditionalBusinessSlotAgreementCanceled` | **REACHABLE_BUT_TEST_GAP** | Dispatch at `UsageBillingCheckoutManager.php:1899`. No `Event::fake()`-based assertion exists. |
| `AdditionalBusinessSlotAgreementPaymentRecovered` | **REACHABLE_BUT_TEST_GAP** | Dispatch at `UsageBillingCheckoutManager.php:1744`. No `Event::fake()`-based assertion exists. |

**No unreachable-blocking event found beyond the two funding events (§7).** The four `REACHABLE_BUT_TEST_GAP` slot events remain explicitly deferred to remediation #7 (§12).

---

## 9. `ReconcileProviderPendingState` — test-gap requirement, race-realism corrected this round

**Confirmed again this round: zero test coverage anywhere in `tests/` for this class.** Delegation chain unchanged: `ReconcileProviderPendingState.handle()` → `confirmAttemptFromReturn()` → (on genuine success) `confirmSucceeded()`.

**Method 5 corrected this round — the prior wording described an impossible scenario.** The job's own query (`ReconcileProviderPendingState.php:29-33`) is `whereIn('state', [ProviderPending, RequiresAction])->where('updated_at', '<', $cutoff)->whereNotNull('provider_session_or_intent_reference')` — an attempt already in `Succeeded` state can never be selected by this query in the first place, so a test titled "never duplicates accounting for an already-succeeded attempt" that feeds a `Succeeded` row directly into the job's own selection path was proving nothing real.

**Direct re-audit this round of the actual race this class of test should prove, by reading `confirmAttemptFromReturn()` and `confirmSucceeded()` fresh** (`UsageBillingCheckoutManager.php:477-515`, `:628-658`): `ReconcileProviderPendingState.handle()` loads its `$stuck` collection once via `->get()`, then iterates the already-loaded, potentially-stale in-memory `BusinessFundingAttempt` models — it does not re-fetch each row immediately before calling `confirmAttemptFromReturn($attempt)`. If a concurrent webhook confirms the same attempt between the query and that loop iteration reaching it, the in-memory `$attempt->state` still reads its pre-race value (e.g., `ProviderPending`), so `confirmAttemptFromReturn()`'s own already-`Succeeded` early return (line 479) does not catch it on the in-memory object; execution proceeds to re-verify against the *provider* (not the local DB) and, on a provider-confirmed success, calls `confirmSucceeded()` again. **Confirmed by direct read: `confirmSucceeded()`'s main (non-`AddonPurchase`) branch has no guard against being called twice for the same attempt** — `$this->attemptRepository->update($attempt, ...)` is an unconditional update (not a `WHERE state = X` guarded one), `recordTransition()` would insert a second, redundant `Succeeded → Succeeded` audit row, and the subsequent `creditFromFunding()` call is not wrapped in a `try/catch` in this branch (unlike `finalizeAddonPurchaseIfPending()`'s own `AddonPurchase` branch, which does catch `UniqueConstraintViolationException` around its own `creditFromFunding()` call, `UsageBillingCheckoutManager.php:690-701`). The ledger's own `UNIQUE` constraint on `correlation_key` (`$attempt->local_idempotency_key.':credit'`) would reject the second credit at the database level, but **the resulting `UniqueConstraintViolationException` would propagate uncaught out of `confirmSucceeded()`, out of `confirmAttemptFromReturn()`, and out of the job's own `foreach` loop** (which also has no per-iteration `try/catch`) — crashing the entire scheduled run rather than gracefully no-opping.

**This is a genuine, newly-discovered, pre-existing gap in already-merged M3 reconciliation code, not something introduced by this correction.** Per this correction's own locked constraint ("does not redesign the reconciliation flow") and the instruction to STOP and report rather than invent a fix under scope pressure, **this correction does not add a `try/catch` to `ReconcileProviderPendingState.handle()`'s loop or to `confirmSucceeded()`'s main branch** — doing so would be a genuine, separate defect fix to already-merged M3 behavior, unrelated to making the currently-unreachable job schedulable and tested, and is disclosed here for a human decision rather than silently absorbed into this correction's scope.

**Locked test file, unchanged path:** `tests/Feature/Usage/ReconcileProviderPendingStateTest.php` (new). **Locked test methods, methods 1 and 5 both corrected this round:**

1. `test_reconciles_a_stuck_provider_pending_attempt_to_succeeded_after_local_accounting_completes` — **test mechanism corrected in this exceptional pass; the prior wording described an invalid proof.** An `Event::fake()` + `Event::assertDispatched(..., function (...) { ... })` callback runs *after* the operation under test has already finished — `Event::fake()` intercepts the real dispatch and only records it; a database assertion inside the later `assertDispatched` callback therefore observes end-of-test state, not the state that existed at the instant the event actually fired, and proves nothing about ordering. **Corrected design:** register a real, synchronous `Event::listen(BusinessFundingAttemptSucceeded::class, function ($event) use (&$listenerRan) { ...; $listenerRan = true; })` *before* invoking the reconciliation path — `BusinessFundingAttemptSucceeded` itself is **not** faked for this test, so its real listener genuinely executes at dispatch time. Inside that listener, assert the corresponding ledger entry (`ManualTopUp`/`AutoRecharge`) or completed addon purchase (`AddonPurchase`) already exists. Run the job (a `ProviderPending` attempt older than 30 minutes, with a provider-confirmed-succeeded fixture), then assert `$listenerRan === true`. The listener's own in-flight assertion is the ordering proof, because it executes when the real event is delivered — including correctly if `ShouldDispatchAfterCommit` defers it to a transaction commit, which this design accommodates rather than works around.
2. `test_does_not_reconcile_an_attempt_updated_within_the_stuck_window` — unchanged from the initial draft.
3. `test_does_not_mutate_a_still_pending_attempt_the_provider_confirms_as_unresolved` — unchanged.
4. `test_skips_an_attempt_with_no_provider_session_or_intent_reference` — unchanged.
5. `test_the_reconciliation_query_never_selects_an_already_succeeded_attempt` — **replaces** the prior draft's impossible-scenario method. Creates an attempt already in `Succeeded` state with `updated_at` older than 30 minutes and a non-null `provider_session_or_intent_reference` (otherwise matching every other selection criterion) and asserts it is absent from the job's own query result set — proving the query-level exclusion mechanically, which is the one part of "never duplicates accounting for an already-succeeded attempt" this job's actual code structure can honestly guarantee. The stale-in-memory-object race described above is **not** given a test method here, since no current code path provides the graceful behavior such a test would need to assert; it is disclosed above as a found-but-out-of-scope defect instead of being asserted as proven-safe.

**This correction does not redesign the reconciliation flow.**

---

## 10. Exact production file allowlist — recomputed from scratch this round

Every path below is marked `REQUIRED`, `PARTIALLY_REQUIRED`, or `NOT_REQUIRED`, with a reason. **Total REQUIRED/PARTIALLY_REQUIRED paths: 15.**

| # | Path | Status | Reason |
|---|---|---|---|
| 1 | `app/Events/Usage/BusinessWalletCredited.php` | REQUIRED | New event class, §6.4. |
| 2 | `app/Events/Usage/BusinessWalletDebited.php` | REQUIRED | New event class, §6.5. |
| 3 | `app/Events/Usage/BusinessWalletDebtIncurred.php` | REQUIRED | New event class, §6.5. |
| 4 | `app/Events/Usage/BusinessWalletDebtCleared.php` | REQUIRED | New event class, §6.4. |
| 5 | `app/Events/Usage/BusinessUsageReserved.php` | REQUIRED | New event class, §6.1. |
| 6 | `app/Events/Usage/BusinessUsageCommitted.php` | REQUIRED | New event class, §6.2. |
| 7 | `app/Events/Usage/BusinessUsageReservationReleased.php` | REQUIRED | New event class, §6.3. |
| 8 | `app/Library/Usage/UsageWalletManager.php` | REQUIRED | Modified: 7 event dispatches (§6); `$overageLedgerEntry` capture (§6.5); `recordAutoRechargeFailure()` widened for the 2→3 system-disable edge (§5); `configureAutoRecharge()` widened for the disabled→enabled counter reset (§5); low-balance set/clear checks at 5 sites across `reserve()`/`commit()`/`release()`/`creditFromFunding()` (§4). |
| 9 | `app/Library/Usage/UsageBillingCheckoutManager.php` | REQUIRED | Modified: `confirmSucceeded()`'s dispatch point corrected to after purpose-specific finalization (§7.1); `markFailed()`'s existing dispatch point confirmed, one dispatch call added (§7.2). |
| 10 | `app/Jobs/Usage/SendLowBalanceNotification.php` | REQUIRED | New job, §4. |
| 11 | `app/Jobs/Usage/SendAutoRechargeDisabledNotification.php` | REQUIRED | New job, §5. |
| 12 | `app/Notifications/Usage/LowBalanceNotification.php` | REQUIRED | New notification, §4. |
| 13 | `app/Notifications/Usage/AutoRechargeDisabledNotification.php` | REQUIRED | New notification, §5. |
| 14 | `app/Jobs/Usage/EvaluateBusinessAutoRecharge.php` | REQUIRED | Modified: the `Failed`-only conditional (line 76) is widened to `Failed || RequiresAction`, per human resolution B.1 (§5); its docblock's now-false claim is corrected. |
| 15 | `app/Console/Kernel.php` | REQUIRED | Modified: one new `$schedule->job(new ExpireStaleUsageReservations())->everyFiveMinutes();` entry, per the resolved cadence (§3). |

**NOT_REQUIRED, explicitly confirmed:**

| Path | Reason |
|---|---|
| `app/Jobs/Usage/ExpireStaleUsageReservations.php` | Unmodified — only its Kernel registration changes (§3), not its own code. |
| `app/Jobs/Usage/SendReceiptNotification.php` | Receipt Boundary is closed; no regression found — `creditFromFunding()`'s existing dispatch at line 838-839 is unaffected by the new event dispatches inserted before it at line 836-837. |
| `app/Notifications/Usage/ReceiptAvailableNotification.php` | Same reason. |
| Any migration or schema file | No schema change required or authorized (§13). |
| Any route, controller, or config file | No HTTP/admin surface, no config value, is touched by this correction. |
| Any `AdvanceUsagePeriodBoundaries` class/migration | Locked OPTIONAL_NON_BLOCKING / NOT IMPLEMENTED (§2). |

No path is marked with "or sibling," "if needed," or any other discretionary qualifier.

---

## 11. Exact test/support file allowlist — recomputed from scratch this round

**Total REQUIRED paths: 7** (5 new files, 1 modified existing file, 1 additional new file for the scheduler proof).

| # | Path | Status | Reason / exact methods |
|---|---|---|---|
| 1 | `tests/Feature/Usage/UsageWalletDomainEventDispatchTest.php` | REQUIRED (new) | Covers all 7 wallet/reservation events' exact emission/non-emission (§6), including the non-mutual-exclusivity assertions for `BusinessWalletCredited`+`BusinessWalletDebtCleared` and `BusinessWalletDebited`+`BusinessWalletDebtIncurred`, via `Event::fake([...])` scoped to exactly these 7 classes. |
| 2 | `tests/Feature/Usage/FundingAttemptTerminalEventDispatchTest.php` | REQUIRED (new) | **Test mechanism corrected in this exceptional pass** — the prior `Event::fake()` + `Event::assertDispatched(..., function (...) { ... })`-callback design does not prove temporal ordering, since that callback runs after `Event::fake()` has already recorded the (intercepted, non-executed) dispatch, observing end-of-test state rather than dispatch-time state. Corrected exact methods, each registering a real `Event::listen(BusinessFundingAttemptSucceeded::class, ...)` listener *before* invoking the confirmation path (`BusinessFundingAttemptSucceeded` is not faked in these three methods) and asserting the required durable state from inside that listener, then asserting the listener ran exactly once: `test_succeeded_listener_observes_the_wallet_credit_already_committed_for_a_topup`; `test_succeeded_listener_observes_the_wallet_credit_already_committed_for_an_auto_recharge`; `test_succeeded_listener_observes_the_addon_purchase_already_completed`. The remaining three methods are unaffected — they prove dispatch multiplicity, not ordering, so `Event::fake()`/`Event::assertDispatchedTimes()`/`Event::assertNotDispatched()` remain the correct, valid tool for them: `test_failed_dispatches_immediately_after_the_transition_record`; `test_replay_of_an_already_succeeded_attempt_does_not_redispatch`; `test_replay_of_an_already_terminal_failed_attempt_does_not_redispatch`. |
| 3 | `tests/Feature/Usage/ReconcileProviderPendingStateTest.php` | REQUIRED (new) | Exact 5 methods, §9. Method 5 proves the query-level exclusion of already-`Succeeded` rows (Correction Round 2). Method 1's test mechanism corrected in this exceptional pass — a real `Event::listen()` listener registered before invoking the job, asserting durable state from inside the listener, replaces the invalid `Event::fake()` + `assertDispatched()`-callback ordering claim. |
| 4 | `tests/Feature/Usage/SendLowBalanceNotificationTest.php` | REQUIRED (new) | Exact methods: `test_dispatches_when_a_reservation_drops_the_balance_to_or_below_threshold`; `test_dispatches_when_a_commit_overage_drops_the_balance_to_or_below_threshold`; `test_does_not_redispatch_while_the_marker_is_already_set`; `test_does_not_dispatch_when_auto_recharge_is_disabled`; `test_does_not_dispatch_when_no_threshold_is_configured`; `test_clears_the_marker_on_recovery_via_credit_from_funding`; `test_clears_the_marker_on_recovery_via_commits_unused_reservation_release`; `test_clears_the_marker_on_recovery_via_reservation_release`; `test_re_enabling_auto_recharge_alone_does_not_clear_the_marker`; `test_skips_when_no_billing_contact_is_configured`; `test_skips_when_the_contact_has_opted_out`; `test_skips_when_the_resolved_email_is_blank`. |
| 5 | `tests/Feature/Usage/SendAutoRechargeDisabledNotificationTest.php` | REQUIRED (new) | Exact methods (job/notification-level, recipient resolution only — the wallet-manager triggering logic is proven in file #6 below, mirroring `SendReceiptNotification`'s own separation of concerns): `test_sends_the_notification_to_the_opted_in_billing_contact`; `test_skips_when_no_billing_contact_is_configured`; `test_skips_when_the_contact_has_opted_out`; `test_skips_when_the_resolved_email_is_blank`; `test_resolves_email_via_the_contact_user_when_contact_user_id_is_set`. |
| 6 | `tests/Feature/Usage/AutoRechargeFailedPaymentRetryTest.php` | REQUIRED (modified existing) | See breakdown below. |
| 7 | `tests/Feature/Usage/UsageJobSchedulingTest.php` | REQUIRED (new) | Scheduler-reachability + exact-cadence proof, §3. See design note below. |

**Existing-file modification breakdown for `AutoRechargeFailedPaymentRetryTest.php`** (full current body re-read this round):

- `test_a_declined_recharge_increments_the_failure_counter` — **unchanged**, still valid (a single `Failed` outcome still increments to 1 under the corrected rule).
- `test_a_subsequent_successful_recharge_resets_the_failure_counter` — **unchanged**, still valid (already exercises B.2, which this correction does not touch).
- `test_a_requires_action_outcome_does_not_increment_the_failure_counter` — **corrected, not merely renamed**: this assertion (`assertSame(0, $wallet->consecutive_recharge_failures)`) is now stale per human resolution B.1 and must be replaced with `test_a_requires_action_outcome_increments_the_failure_counter`, asserting `assertSame(1, ...)`.
- **New methods added:** `test_the_third_consecutive_failure_disables_auto_recharge_and_dispatches_the_disabled_notification`; `test_the_third_consecutive_requires_action_outcome_also_disables_auto_recharge`; `test_system_disable_preserves_threshold_amount_and_monthly_cap`; `test_a_failure_recorded_while_already_disabled_does_not_redispatch_the_notification` (calls `recordAutoRechargeFailure()` directly, bypassing the job's own top-of-`handle()` guard, to defensively prove the `=== 3` gate per §5 item 5); `test_re_enabling_auto_recharge_resets_the_counter_and_permits_a_new_disable_episode`; `test_deliberate_owner_disable_does_not_dispatch_the_system_disable_notification`.

**Scheduler test design note for `UsageJobSchedulingTest.php`:** this repository already has exactly one precedent scheduling test, `tests/Feature/Opportunity/OpportunitySnoozeSweepScheduleTest.php` — confirmed by direct read this round to invoke `Kernel::schedule()` via `ReflectionMethod` against a fresh `Illuminate\Console\Scheduling\Schedule` instance, then inspect `$schedule->events()` directly, identifying its target event via `str_contains($event->command, 'opportunity:sweep-expired-snoozes')` and asserting `$event->expression`. That identification technique is specific to a `$schedule->command(...)` registration; every RFC-005 scheduled job, including `ExpireStaleUsageReservations`, is registered via `$schedule->job(new X())` instead, which — per Laravel's documented `Schedule::job()` behavior — produces a `CallbackEvent` identified by `$event->description` (internally set to the job's class name), not `$event->command`. **This worktree has no `vendor/` directory, so this could not be mechanically confirmed against the actual installed `laravel/framework` 12.x source in this pass** (disclosed in the Correction Round 1 record above). Locked test intent, independent of the exact identifying property: `test_expire_stale_usage_reservations_is_registered_in_the_schedule` (asserts a matching event is found) and `test_expire_stale_usage_reservations_runs_every_five_minutes` (asserts `$event->expression === '*/5 * * * *'`), using the same reflection-based `Kernel::schedule()` invocation as the existing precedent, filtering `$schedule->events()` by whichever property the implementation phase's mechanical check against real vendor source confirms identifies a `$schedule->job(...)` registration (`description`, expected). This is the one sub-detail in this correction that is a trivial, non-policy, implementation-time verification against installed framework code, not a product/schema question — it does not block this contract's authorization and is not listed in §14.

---

## 12. Excluded scopes — restated in full, unchanged in substance

This correction does not implement, design, or absorb any of the following:

- Admin Usage Billing Surface (remediation #5), including `ManualCredit`/`PromotionalCredit`/`UsageChargeReversal`/`CorrectionReversal` ledger-entry producers or dispatch.
- Provider Refund/Dispute Outcome Handling (remediation #6), including `Refund`/`DisputeChargeback` ledger writes and dispute-driven billing suspension.
- Residual §35-only cleanup (remediation #7) — except the exact tests required by this correction (§9, §11). The four `REACHABLE_BUT_TEST_GAP` slot events (§8) remain deferred to that remediation.
- Add-on HTTP surface.
- `AdvanceUsagePeriodBoundaries` — locked OPTIONAL_NON_BLOCKING / NOT IMPLEMENTED (§2).
- M6 conformance/deployment docs; the release tag.
- Conversations pilot activation; tax/VAT implementation; legacy invoices.
- `SendReceiptNotification`/`ReceiptAvailableNotification` — Receipt Boundary is closed; regression-verified only (§10), never modified.
- Any migration or schema change (§13).
- **The stale-in-memory-object reconciliation race disclosed in §9** — `ReconcileProviderPendingState.handle()`'s lack of per-iteration exception isolation and `confirmSucceeded()`'s lack of a guard against a second call for an already-terminal attempt are both genuine, pre-existing gaps in already-merged M3 code, unrelated to this correction's own job/event dispatch reachability scope. This correction adds the missing query-level test proof (§9 method 5) but does not add exception handling to either method — the fix itself is excluded from this correction's scope by design (§14 classifies this precisely: not a blocker to implementing this correction, but a mandatory blocker to M6 resumption, requiring its own separately governed correction).

Do not reopen Reservation Admission, Funding Provider-Flow, or Receipt Boundary — none is touched, contradicted, or reinterpreted anywhere above.

---

## 13. Confirmations

- **No schema/migration change is required or authorized by this correction.** Every column referenced (`low_balance_notified_at`, `auto_recharge_threshold_micro`, `auto_recharge_amount_micro`, `monthly_recharge_cap_micro`, `auto_recharge_enabled`, `consecutive_recharge_failures`, `available_balance_micro`, `debt_balance_micro`) already exists per RFC-005 §12/§25's current, already-shipped schema.
- `AI-AUTONOMY-STATE.json` is untouched by this branch.
- M6 remains frozen.
- No product, test, config, route, or RFC-source file is touched by this branch. This governance branch changes exactly one file.
- No implementation has occurred. **Correction rounds: 2 of 2 consumed; 0 ordinary rounds remain.**
- Reservation Admission, Funding Provider-Flow, and Receipt Boundary are not reopened, contradicted, or reinterpreted anywhere above.

---

## 14. Open items requiring human resolution before implementation can proceed to full scope

**All three items from the initial draft are resolved** (cadence — §3; the `requires_action` counter conflict — §5; low-balance applicability — §4) and remain removed from this list. Two further items found during independent review and this correction's own re-audit are disclosed below, neither of which blocks implementation authorization:

1. **The exact `Illuminate\Console\Scheduling\Event` property that identifies a `$schedule->job(...)` registration** could not be mechanically confirmed in this vendor-less worktree (no `laravel/framework` source present to read); disclosed in §11's scheduler test design note. A trivial mechanical check against the real installed framework at implementation time, not a decision requiring human policy input.
2. **The `ReconcileProviderPendingState`/`confirmSucceeded()` stale-in-memory-object race disclosed in §9 — reclassified in this exceptional pass from a discretionary future recommendation to a mechanically-known correctness gap with an explicit, mandatory disposition:**
   - A concurrent webhook racing a reconciliation pass on the same stuck attempt can, in the current already-merged M3 code, cause an uncaught `UniqueConstraintViolationException` to propagate out of `confirmSucceeded()`, out of `confirmAttemptFromReturn()`, and out of `ReconcileProviderPendingState.handle()`'s own `foreach` loop (no per-iteration isolation exists), crashing the entire scheduled reconciliation run rather than gracefully no-opping. The ledger's own `UNIQUE` constraint on `correlation_key` prevents the underlying double financial credit — the risk is an unhandled crash, not silent double-accounting.
   - **This is NOT invented or fixed by this correction, and the fix itself is not designed here.**
   - **It does NOT block implementing this correction's own bounded scope.** The five gaps this contract closes (§1) — the two missing jobs, the seven missing events, the two dead funding events, `ExpireStaleUsageReservations`'s scheduling, and `ReconcileProviderPendingState`'s test-coverage gap (proven honestly, per §9's corrected method 5, without asserting a race-safety guarantee that does not exist) — remain fully implementable and mergeable once this contract itself is human-merged and implementation is separately authorized.
   - **It DOES block RFC-005 M6 resumption.** RFC-005 M6 (conformance, deployment, tag) may not resume — regardless of whether this correction's own implementation has landed — until the `ReconcileProviderPendingState` reconciliation race receives its own separately drafted, human-reviewed, merged correction contract, its own implementation, and its own verification.
   - **Sequence position:** a new mandatory pre-M6 correction — the `ReconcileProviderPendingState` Reconciliation-Race Correction — is inserted into the remediation sequence immediately after remediation #4 (this Job/Event Dispatch Completion correction) and before remediation #5 (Admin Usage Billing Surface). The existing remediation numbers #1–#7 are **not** renumbered or otherwise disturbed, to keep every existing cross-reference in the three already-merged correction contracts and this one valid; this new correction is referred to by name, not by a number, everywhere in this document.

**Result: no open item blocks authorizing or implementing this correction's own bounded scope. One open item — the reconciliation race — is a confirmed, mandatory blocker to eventual M6 resumption, requiring its own separately governed correction; it is not a precondition for this contract's own merge or implementation.**

---
