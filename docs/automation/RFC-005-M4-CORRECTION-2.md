# RFC-005 Milestone 4 — Correction Round 2

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

**This is Correction Round 2 of 2.** This is the final correction round
permitted by the merged M4 contract's own `maximum_correction_rounds: 2`
(M4 contract §0). **After this correction, no correction rounds remain** —
any further structural gap found once this is merged and implemented would
require a new milestone slice or an explicit, separately authorized
governance exception; it cannot be handled as a third M4 correction round.

**This document authorizes drafting itself only.** It contains **no
implementation code**. Merging it authorizes a bounded set of corrections
to the already-implemented, already-Draft-PR'd M4 codebase, to be applied
by resuming implementation on the existing `agent/rfc-005-m4` branch only
after this document is human-merged.

**Draft PR #105 (`agent/rfc-005-m4`, head `a1700c104cd8725d8b916c58fde494bb32b4805e`)
remains Draft and completely unmodified by this document.** This
correction was drafted read-only against that worktree/branch — no file in
it was written, staged, committed, reset, rebased, or amended while
drafting this document (§I).

---

## 0. Governance

- Drafted on branch `chore/rfc-005-m4-correction-2`, in a fresh isolated
  linked worktree (`../rfc-005-m4-correction-2-worktree`), created from
  `origin/main` at `13876e4bbedcead6fe358a4163984da666b48f14` (the same
  base Correction Round 1 merged onto, and the base the existing
  `agent/rfc-005-m4` implementation branched from) — confirmed via
  `git rev-parse origin/main` before drafting.
- **This is Correction Round 2 of a maximum of 2**, per M4 contract §0's
  `maximum_correction_rounds: 2`. Correction Round 1 (merged as PR #104,
  `docs/automation/RFC-005-M4-CORRECTION-1.md`) consumed round 1 of 2. This
  document consumes round 2 of 2. **Zero correction rounds remain after
  this one merges.**
- This document amends the merged M4 contract's own §8 (allocation
  boundary — recoverable states, reload/lock discipline, `current_allocation_count`
  synchronization), §11/§13 (mid-period-increase reservation terminal
  policy), §15 (webhook evidence validation), §21 (manager method
  behavior — hard-decline recovery, definitive-vs-transient failure,
  add-on crash ordering, scheduled-renewal replay), §22 (jobs —
  reconciliation selection, price-change comparison), §23 (concurrency —
  real transaction-scoped locking, forced-race test requirement), and §25
  (allowlist — two new paths, below), in the six ways §A–§F state exactly.
  Every other section of the merged M4 contract, and every correction
  already locked by Correction Round 1 not restated here, is **unchanged
  and remains fully in force**.
- **Every finding below was produced by a final implementation review of
  Draft PR #105**, not re-derived by this document. Each finding is
  reproduced from that review, narrowed only to the exact locked fix.
- **The existing implementation branch/worktree
  (`agent/rfc-005-m4` / `../rfc-005-m4-impl-worktree`, head
  `a1700c104cd8725d8b916c58fde494bb32b4805e`, Draft PR #105) is untouched by
  this document** — confirmed via a separate `git status --porcelain` /
  `git rev-parse HEAD` check inside that worktree immediately before
  drafting began, and again immediately before this document's own commit
  (§I).
- **Implementation may resume only after this document is human-merged**,
  exactly as Correction Round 1's own implementation resumption required
  its own prior human merge.
- Bounded reads only were performed to lock the corrections below (the
  specific manager methods, repository methods, jobs, gateway/exception
  classes, and tests each finding names) — no broad repository audit was
  undertaken.

---

## A. Correction A — repair the initial allocation saga; make every contracted recovery state executable

**Finding.** The merged contract names `allocation_failed` as a real state
and names both `ReconcileSlotAgreementAllocation` and administrator manual
allocation as its recovery paths. The current implementation does not
honor this:

- `performVerifiedAllocation()` (`UsageBillingCheckoutManager.php`, §25
  item 41) accepts only `payment_succeeded` and `allocation_pending` —
  `allocation_failed` is unrecoverable by any authorized path.
- It never reloads/locks the agreement row; it mutates the caller-supplied
  Eloquent model directly (`$agreement->load('workspace')`, no
  `findForUpdateById()` call anywhere in the method).
- `ReconcileSlotAgreementAllocation`'s own selection query,
  `findStuckInAllocationPending()` (`EloquentAdditionalBusinessSlotAgreementRepository.php`,
  §25 item 34), selects only `allocation_pending`.
- A second crash window exists independently of the above: checkout
  verification writes `payment_succeeded` and the process dies before
  `performVerifiedAllocation()` runs. A later browser return/webhook
  delivery for the same agreement now sees `state !== checkout_pending`
  and returns immediately (`confirmSlotAgreementFromReturn()`/
  `confirmSlotAgreementFromWebhook()`'s own top-of-method guard) — the
  agreement is silently abandoned, since reconciliation does not select
  `payment_succeeded` either.

**Locked correction — three explicit crash-safe phases.**
`performVerifiedAllocation()` is split into three explicit phases, closing
this correction's own original ambiguity about how long the agreement
transaction stays open, and removing an RFC-004 repository-boundary
violation that original wording introduced (an already-`completed` replay
must never resolve `WorkspacePlanAssignmentRepository` directly — M4
reaches RFC-004 only through the three already-authorized
`EntitlementManager` seams; `EntitlementCatalogSourceBoundaryTest.php`
(§25 item 86)'s own already-authorized, unchanged forbidden-class list
already names `WorkspacePlanAssignmentRepository`/
`EloquentWorkspacePlanAssignmentRepository` explicitly, and already scans
`UsageBillingCheckoutManager.php` by name — the original wording would
have failed that already-merged test).

1. **Recoverable initial-allocation states.** `performVerifiedAllocation()`
   accepts exactly `payment_succeeded`, `allocation_pending`, and
   `allocation_failed` — never an unverified payment state
   (`quote_created`, `checkout_pending`). The existing
   `blank(...->provider_session_or_intent_reference)` guard is unchanged
   and still applies to all three.

2. **Phase 1 — local preparation transaction.**
   1. Begin `DB::transaction(...)`.
   2. Reload/lock the persisted agreement by id:
      `$this->agreementRepository->findForUpdateById((int) $agreement->id)`.
      The caller-supplied Eloquent object's own in-memory state is never
      authoritative from this point on — every subsequent read in this
      method uses the locked row.
   3. If the locked row's state is already `completed`, this phase writes
      nothing and this method proceeds straight to Phase 2 using this
      agreement's own frozen, already-durable evidence — RFC-004's own
      idempotency guarantee is what produces the no-op there, never a
      second, parallel M4-side check against a repository M4 is not
      authorized to touch.
   4. Otherwise require the locked state to be exactly one of
      `payment_succeeded`, `allocation_pending`, or `allocation_failed` —
      anything else (an unverified payment state, or `canceled`) throws
      `UnauthorizedSlotAgreementActionException` immediately, unchanged
      from this correction's own original wording.
   5. Require the durable provider payment reference on the locked row
      (unchanged guard, re-run against the locked row rather than the
      caller-supplied one).
   6. Transition to `allocation_pending` only when a transition is
      actually required — i.e., only when the locked state is exactly
      `payment_succeeded`; `allocation_pending` and `allocation_failed`
      are left exactly as they are, unchanged from the existing
      implementation's own behavior for those two states.
   7. Commit.

3. **Phase 2 — RFC-004 allocation call.** Outside the M4 agreement
   transaction (no transaction open), invoke the one already-authorized
   `EntitlementManager::allocateAdditionalBusinessSlotsFromVerifiedPayment()`
   seam with the agreement's own exact, frozen evidence — identical
   whether Phase 1 found a genuinely resumable state or an already-`completed`
   row to replay: the same `Workspace`, the same `paid_delta`, the same
   original `requesting_customer_user_id`, the same `workspace_id` for
   verification, the same deterministic
   `'slot-agreement-allocation-'.$agreement->local_idempotency_key`
   payment-allocation idempotency key, and the same
   `provider_session_or_intent_reference`. Amendment 1's own idempotency
   guarantee (the exact-replay comparison already locked in RFC-004) is
   what makes a replay call for an already-completed agreement a true
   no-op: RFC-004 returns the existing `WorkspacePlanAssignment`, writes
   no second `workspace_entitlement_transitions` row, and dispatches no
   second RFC-004 event.

4. **Phase 3 — local result transaction.**

   *On success* (the call returns a `WorkspacePlanAssignment`, whether
   freshly allocated or an idempotent replay result):
   1. Re-lock the persisted agreement.
   2. If it is already `completed` — true both for a genuine replay and
      for a race where another caller completed it between Phase 1 and
      here — do not duplicate its transition or event: return the
      returned assignment as-is.
   3. Otherwise set `current_allocation_count = $assignment->additional_business_slots`
      (the returned value — never blindly from `target_allocation_count`,
      the identical rule Correction Round 1 §D already locked for
      `performVerifiedRenewalChargeAllocation()`) and `state = completed`.
   4. Record exactly one completion transition and dispatch
      `AdditionalBusinessSlotAgreementCompleted` exactly once.
   5. Commit.

   *On an RFC-004 exception*:
   1. Start/re-enter a local transaction.
   2. Re-lock the persisted agreement.
   3. If it is still incomplete (not already `completed` — protecting
      against regressing an already-completed agreement if a replay call
      somehow raised `PaymentAllocationIdempotencyConflictException` after
      the fact), persist `allocation_failed` and record its
      transition/event.
   4. Commit that failure state.
   5. Only *after* the transaction commits, rethrow the original
      exception.

   This ordering is required so `allocation_failed` is durably committed
   and therefore recoverable (this section, plus reconciliation/
   administrator recovery below) even though the method still rethrows —
   a caller that lets the exception propagate (as every current caller
   does) does not silently roll back the very failure state this
   correction exists to make recoverable.

5. **`ReconcileSlotAgreementAllocation` selection.** Rename
   `findStuckInAllocationPending()` to `findRequiringAllocationRecovery()`
   (same file, same repository — a rename/widen, not a new method) to
   select every agreement whose downstream allocation is provider-verified
   but incomplete:
   - `payment_succeeded`, age-bounded (`updated_at <= now() - STUCK_AFTER_MINUTES`,
     the existing 30-minute threshold, unchanged) — bounded so this job
     never races an in-flight synchronous return/webhook confirmation.
   - `allocation_pending`, age-bounded — identical, already-existing
     behavior.
   - `allocation_failed` — unconditional, no age bound. This state is only
     ever reached after Phase 3's own exception branch has already
     committed and returned control to its caller, so there is never a
     synchronous call still in flight for a row in this state; gating it
     would only delay recovery with no corresponding safety benefit.

   `ReconcileSlotAgreementAllocation`'s own `handle()` body is otherwise
   unchanged: it still calls `performVerifiedAllocation($agreement)` with
   both `administratorActorUserId` and `reason` left `null`, never a fresh
   charge, never a synthetic administrator, never a new idempotency key —
   allocation-only, exactly as already contracted.
6. **Administrator recovery.** `allocateSlotAgreementAsAdministrator()` is
   unchanged (it is already a thin wrapper around
   `performVerifiedAllocation()` after its own real-admin + mandatory-reason
   checks) and automatically inherits the three-phase structure and
   corrected recoverable-state set through that delegation — no separate
   fix is needed in that method itself.
7. **RFC-004 boundary — explicitly preserved.** `performVerifiedAllocation()`
   resolves RFC-004 state exclusively through
   `EntitlementManager::allocateAdditionalBusinessSlotsFromVerifiedPayment()`
   in Phase 2 — no direct `WorkspacePlanAssignmentRepository`/
   `EloquentWorkspacePlanAssignmentRepository` dependency is introduced or
   authorized anywhere in this correction. `EntitlementCatalogSourceBoundaryTest.php`'s
   existing `test_no_m4_production_file_imports_an_rfc004_repository_contract`
   is retained exactly as-is — no change to that test is authorized or
   needed; it already proves this boundary, and every path in this
   correction is written to keep passing it.

**Tests.** Strengthen `SlotAgreementAllocationSagaTest.php` (§25 item 80)
and `SlotAgreementConcurrencyTest.php` (§25 item 84) to prove: an
`allocation_failed` agreement is recoverable via both reconciliation-style
direct call and `allocateSlotAgreementAsAdministrator()`; a
`payment_succeeded` agreement stuck past the crash window is discovered by
`findRequiringAllocationRecovery()`; a stale caller-held `allocation_pending`
model racing an already-`completed` persisted row produces zero additional
RFC-004 transitions and zero additional `completed` transitions/events
purely through the Phase-3 already-`completed` check and RFC-004's own
idempotency guarantee, never a direct `WorkspacePlanAssignmentRepository`
read; a successful allocation's `current_allocation_count` matches the
returned assignment exactly, never `target_allocation_count`; an RFC-004
exception during Phase 2 leaves `allocation_failed` durably committed
(queryable in a fresh connection) even though the triggering exception is
still observed by the caller. Explicitly re-run (unchanged)
`EntitlementCatalogSourceBoundaryTest.php` (§25 item 86) as part of this
correction's own required coverage, confirming it still passes with zero
modification to that test file.

---

## B. Correction B — fix attempt-1 hard-decline recovery; distinguish definitive payment failure from infrastructure failure

**Finding.** `StripePaymentProviderGateway::createOffSessionPaymentIntent()`
(§25 item 89) calls Stripe with `confirm: true`. A synchronous decline
raises the SDK's `\Stripe\Exception\CardException`. Bounded read of the
installed SDK
(`vendor/stripe/stripe-php/lib/Exception/CardException.php`,
`ApiErrorException.php`, `ErrorObject.php`) confirms `CardException`
carries no PaymentIntent id directly on the exception object itself, but
its own `getError()` accessor returns a `\Stripe\ErrorObject` whose
documented `payment_intent` property (`@property PaymentIntent
$payment_intent`) is the full declined PaymentIntent object, including its
`id` — Stripe itself durably creates and persists the PaymentIntent before
the decline; the id is real and confirmable again.

The current gateway translates `CardException` into
`ProviderCardDeclinedException` (`app/Exceptions/Usage/ProviderCardDeclinedException.php`,
a pre-existing M3 exception, carrying only a `declineCode`), discarding
this id entirely. `UsageBillingCheckoutManager::driveRenewalChargeAttempt1()`
catches it and calls `applyRenewalChargeFailure()` without ever writing
`provider_session_or_intent_reference`. `driveRenewalChargeRetry()`
requires exactly that reference (`$charge->provider_session_or_intent_reference`,
passed as `confirmPaymentIntent()`'s first argument) to attempt ordinals
2–3. A genuine hard decline on attempt 1 — the single most common
real-world funding outcome — is therefore currently permanently
unretryable. `FakePaymentProviderGatewayTest`'s own fixtures could paper
over this with a `requires_action` outcome instead of `declined` on attempt
1, but that hides the defect rather than fixing it; this correction fixes
the underlying mechanism instead.

**Locked correction — hard-decline mechanism.**

1. Widen `ProviderCardDeclinedException`'s constructor with one additional
   optional parameter, `?string $providerPaymentIntentId = null`, plus a
   `public readonly` property and matching getter — fully backward
   compatible; every existing M1–M3 call site that constructs or catches
   this exception without the new parameter is unaffected.
2. `StripePaymentProviderGateway::createOffSessionPaymentIntent()`'s
   `CardException` catch extracts
   `$e->getError()?->payment_intent?->id ?? null` and passes it into the
   widened exception.
3. `FakePaymentProviderGateway::createOffSessionPaymentIntent()` passes its
   own already-generated `$id` (currently generated, then discarded, before
   the outcome check) into the widened exception on a `'declined'` outcome,
   instead of discarding it — so fixture-level tests can exercise the real
   mechanism without a `requires_action` workaround.
4. `UsageBillingCheckoutManager::driveRenewalChargeAttempt1()`'s
   `ProviderCardDeclinedException` catch: when
   `$e->providerPaymentIntentId !== null`, persists
   `provider_session_or_intent_reference` on the charge (a single
   repository `update()` call) *before* calling `applyRenewalChargeFailure()`
   — so the definitive `failed` transition this correction's second half
   requires is written against a charge that already carries its real,
   confirmable reference.
5. `createScheduledRenewalCharge()` and `requestSlotAgreementIncrease()`'s
   own "existing charge found via idempotency/change-operation key" early
   returns are widened: if the found existing charge is still in `Created`
   state (meaning attempt 1 never reached a definitive outcome — see the
   non-definitive-failure rule below, which deliberately leaves `Created`
   charges in that state rather than transitioning them), re-drive
   `driveRenewalChargeAttempt1()` against it instead of returning it
   unchanged. This is what makes a transient/unknown attempt-1 outcome
   genuinely retryable, reusing the exact same `local_idempotency_key` and
   therefore the same Stripe-side idempotency guarantee, with no new
   mechanism invented.

**Locked correction — definitive vs. non-definitive failure.** M4 §23
requires a durable `failed` transition only after a *definitive* provider
payment failure. The current implementation treats
`ProviderCardDeclinedException`, `ProviderApiUnavailableException`, and
`ProviderInvalidRequestException` identically in both
`driveRenewalChargeAttempt1()` and `driveRenewalChargeRetry()` — all three
call `applyRenewalChargeFailure()`. This is corrected to:

- **`ProviderCardDeclinedException` is definitive.** Unchanged: writes a
  `failed` transition, advances the durable attempt ordinal, participates
  in the pre-lapse/lapse and (per §D below) the terminal-increase-release
  count exactly as already locked.
- **`ProviderApiUnavailableException` and `ProviderInvalidRequestException`
  are non-definitive.** Neither calls `applyRenewalChargeFailure()`.
  Charge state is left exactly as it was on entry (`Created` for attempt 1,
  `Failed`/`RequiresAction` for a retry attempt that hit a transient
  provider/transport error instead of a card response) — no transition is
  recorded, no attempt ordinal is consumed, `maybeSetPaymentLapsed()`/the
  new terminal-increase-release check (§D) are never reached. The caller
  receives a `SlotRenewalChargeResult` reflecting the *unchanged* state
  with the transient reason surfaced for logging/alerting only, never
  persisted as `failure_reason`.
- Because the ordinal is derived purely by counting recorded `failed`
  transitions (Correction Round 1 §A's own formula, unchanged), a
  transient outcome leaves the ordinal exactly where it was — the next
  genuine retry recomputes the identical ordinal and therefore the
  identical Stripe idempotency key, making it safely reconcilable/retryable
  under the existing mechanism with no new one invented. "We don't know"
  is never silently promoted to "the card was definitively declined."

**Locked correction — hard-decline evidence null case.** A second, bounded
re-read of `ErrorObject.php`'s own docblock
(`@property PaymentIntent $payment_intent The PaymentIntent object for
errors returned on a request involving a PaymentIntent`) confirms the SDK
*documents* this field as populated for a PaymentIntent-involving error —
exactly what a `create`-with-`confirm: true` decline is — but declares it
as an ordinary, nullable object property, not a type-enforced guarantee;
it is populated from whatever the server's own JSON error body actually
contains, not synthesized or defaulted by the client SDK. A bounded read of
vendored source cannot itself prove the server always includes it for
every card-error variant. This correction therefore does **not** claim the
id is guaranteed, and locks an explicit fail-closed behavior for its
absence instead of assuming it:

- If `ProviderCardDeclinedException::$providerPaymentIntentId` is `null`
  despite a definitive card decline (the evidence Stripe's own response
  was expected, but did not in this instance, carry), the manager must
  **not** write a definitive `failed` transition — doing so would recreate
  exactly the original bug this correction exists to fix (a `failed`
  charge with no reference, permanently unretryable).
- Instead, `driveRenewalChargeAttempt1()`'s catch treats this exact
  combination identically to a non-definitive outcome: no state
  transition, charge left in `Created`, no ordinal consumed, the decline
  reason surfaced for logging/alerting only (this is a genuine anomaly —
  Stripe returning a card decline without its own documented reference —
  worth operational visibility even though it is handled safely).
- This composes with no new mechanism: item 5 above (re-driving
  `driveRenewalChargeAttempt1()` against a still-`Created` charge on the
  next scheduled/owner-triggered call) already covers exactly this case,
  and re-issuing the identical, ordinal-1 Stripe idempotency key on that
  next attempt is safe under Stripe's own idempotency guarantee even
  though no reference was ever captured the first time.
- No reference is ever invented, guessed, or derived from any other field.

**New/authorized path.** `app/Exceptions/Usage/ProviderCardDeclinedException.php`
is added to the M4 allowlist (§F) — a pre-existing M3 file, not previously
M4-authorized, now genuinely requiring modification to carry this evidence.
No already-authorized path can satisfy this: the PaymentIntent id is only
observable at the moment the gateway catches the SDK's own `CardException`,
and must flow from there to the manager through some typed channel: the
exception class itself is the smallest, most surgical carrier, and widening
it (rather than, for example, changing `createOffSessionPaymentIntent()`'s
return contract to never throw) keeps the change confined to the evidence
this correction actually needs, without touching the shared method's
behavior for M3's own funding-attempt callers (`initiateCharge()`), whose
own separate, already-established retry mechanism
(`retryFundingAttemptAsAdministrator()`) is explicitly out of this
correction's scope and is not modified.

**Tests.** Extend `StripePaymentProviderGatewayCompatibilityTest.php`
(§25 item 89's own test) with a source-level assertion that the gateway
extracts `payment_intent->id` from a caught `CardException`'s error object.
Extend `FakePaymentProviderGatewayTest.php` (§25 item 67) to prove a
`'declined'` outcome's exception carries the fake gateway's own generated
id. Add/extend a renewal-charge test proving: (1) a Stripe-confirmed hard
decline on attempt 1 durably preserves `provider_session_or_intent_reference`;
(2) exactly one definitive `failed` transition is written for that attempt;
(3) `retrySlotRenewalAsOwner()`/`retrySlotRenewalAsAdministrator()`
subsequently perform attempt 2 against that identical PaymentIntent id
(asserted via the fake gateway's own `confirmPaymentIntentCalls` recording,
matching Correction Round 1's own established assertion pattern); (4) no
`requires_action` intermediary outcome is used anywhere in that test. Add a
test proving a `ProviderApiUnavailableException` during a retry writes zero
new transitions, leaves the ordinal (and therefore the next retry's
idempotency key) unchanged, and never sets `payment_lapsed`/never releases
a mid-period-increase reservation. Add a test proving a
`ProviderCardDeclinedException` whose `providerPaymentIntentId` is `null`
writes zero transitions, leaves the charge `Created`, consumes no ordinal,
and that a subsequent `createScheduledRenewalCharge()`/
`requestSlotAgreementIncrease()` call for the same period/change-operation
re-drives attempt 1 against the identical idempotency key.

---

## C. Correction C — repair add-on wallet-credit crash ordering

**Finding.** `finalizeAddonPurchaseIfPending()`
(`UsageBillingCheckoutManager.php`) currently transitions the purchase to
`completed` (and records that transition) *before* attempting the
`wallet_credit` fulfillment's ledger credit. For `fulfillment_mode =
wallet_credit`, this is not crash-safe: if the process dies after the
`completed` transition commits but before `creditFromFunding()` runs, the
credit never happens — and every future replay's own
`$purchase->status === Completed` early-return guard causes the purchase to
be treated as fully and correctly fulfilled forever, permanently losing
the wallet credit with no further repair path.

**Locked correction.** For `fulfillment_mode = wallet_credit`, durable
fulfillment must be established *before* the purchase is allowed to become
`completed`:

1. Load the pending purchase (unchanged: null or already-`Completed` is
   still an immediate no-op).
2. Resolve the catalog row's snapshotted/authorized `fulfillment_mode`
   exactly as already contracted (unchanged).
3. For `wallet_credit`: attempt `creditFromFunding()` using the existing
   deterministic `$attempt->local_idempotency_key.':credit'` correlation
   key, *before* any purchase-row mutation. Reuse the identical
   `UniqueConstraintViolationException` catch this codebase's own current
   implementation already established for this exact purpose (the ledger's
   own `correlation_key` unique constraint proving the credit already
   happened on an earlier pass) — that catch block itself does not move,
   only its position relative to the purchase-completion write does.
4. Only after fulfillment is known durable (freshly credited, or proven
   already-credited via the caught unique-constraint violation) does the
   purchase transition to `completed` and record its transition.
5. For `direct_deliverable`: entirely unchanged — no wallet mutation, no
   invented delivery mechanism, state-machine-only completion exactly as
   already contracted.

This yields the required three crash directions: crash before credit →
replay attempts the credit again and succeeds (purchase was never marked
completed, so the top-of-method guard does not short-circuit); crash after
credit but before purchase completion → replay's credit attempt hits the
ledger's own unique-constraint proof of prior fulfillment, is absorbed, and
the purchase then completes; crash after completion → replay's top-of-method
guard no-ops immediately, exactly as today.

**No new implementation path.** This correction is a pure reordering
within the already-authorized `UsageBillingCheckoutManager.php` (§25 item
41); no new file, method signature, or schema is required.

**Tests.** Strengthen `AddonPurchaseTransitionAuditTest.php` (§25 item 79)
to prove both crash directions explicitly: (a) simulate a crash *before*
the ledger credit (purchase left `pending`, no ledger row yet) — replay
credits and completes, exactly one ledger row, exactly one `completed`
transition; (b) simulate a crash *after* the ledger credit but *before*
purchase completion (purchase left `pending`, ledger row already present
with the deterministic correlation key) — replay's credit attempt is
absorbed via the unique-constraint catch, no second ledger row, purchase
still reaches `completed` with exactly one transition recorded for that
completion.

---

## D. Correction D — resolve the terminal mid-period-increase reservation hole

**Finding.** Correction Round 1 made `target_allocation_count` the
reservation frontier so multiple pending increases compose safely — correct
while those reservations remain potentially payable. But a
`mid_period_increase` charge that exhausts its retry allowance (three total
attempts; ordinal 4+ is permanently blocked for this charge kind since
`maybeSetPaymentLapsed()` only acts on `charge_kind === ScheduledRenewal`,
per Correction Round 1's own documented, correct design) becomes
permanently `Failed` with its reserved `target_allocation_count` bump never
released. A subsequent, independent increase then computes its own delta
against this inflated, unpaid target — the worked example in the review
(current=1, target=1 → increase A reserves target=2/delta=1 → A
permanently fails, target stays 2 → increase B requests target=3,
delta computed as 1 against the poisoned baseline of 2 → B succeeds,
RFC-004's authoritative count becomes 2, but `target_allocation_count`
reads 3) is reproduced and confirmed against the current, unmodified
`requestSlotAgreementIncrease()`/`applyRenewalChargeFailure()` code exactly
as reported.

Re-reading RFC-005's own increase/dunning wording finds no explicit
resolution for this exact terminal-reservation case — the RFC establishes
the price × ratio formula, the cadence, and the general dunning/lapse
posture for *scheduled renewals*, but does not address a mid-period
increase's own reservation lifecycle on terminal failure. This correction
therefore states, explicitly, that the following is this correction's own
necessary technical policy, not a pre-existing RFC directive:

**Locked policy — release, not permanent poisoning.** When a
`mid_period_increase` charge's failure count reaches the same maximum
attempts threshold used for pre-lapse dunning (`PRE_LAPSE_MAX_ATTEMPTS`,
currently 3 — the constant is reused, not duplicated, since it already
means "the number of attempts this system allows before treating a renewal
charge as exhausted," a definition that applies unchanged to an increase
charge), the agreement's `target_allocation_count` is decremented by
exactly that charge's own frozen `allocation_delta`, tied atomically to the
corrected §E.2 result-application dedup rule rather than to any separate
assumption about how many times a method can be entered:

1. Add `maybeReleaseTerminalIncreaseReservation(AdditionalBusinessSlotRenewalCharge $charge)`,
   directly mirroring `maybeSetPaymentLapsed()`'s own shape: returns
   immediately unless `$charge->charge_kind === SlotRenewalChargeKind::MidPeriodIncrease`;
   returns immediately if the failure count is below the threshold.
2. **Atomic tie to the unique terminal failure.** Per §E.2's corrected
   result-application rule, a `failed` transition is only ever actually
   inserted once per attempt ordinal — the branch that inserts the
   threshold-reaching (3rd) `failed` transition is the *only* branch that
   ever calls this method; the no-op branch (an already-applied,
   racing-duplicate result) never does. Call
   `maybeReleaseTerminalIncreaseReservation()` from inside that same
   locked result-application transaction, immediately after the
   threshold-reaching `failed` transition is inserted — never from a
   separate, later transaction, and never on a replay/duplicate result
   where that exact transition already exists. This is what makes the
   release exactly-once: it is not a property of `applyRenewalChargeFailure()`
   being entered only once (which §E.2 explicitly does not assume), but a
   property of the specific transition insert it is atomically paired
   with, which §E.2 already guarantees happens exactly once.
3. Within that same transaction, lock the parent agreement
   (`findForUpdateById((int) $charge->agreement_id)`) and decrement
   `target_allocation_count` by exactly `(int) $charge->allocation_delta` —
   never more, never derived from any other value.
4. **Floor guard.** The decremented `target_allocation_count` must never
   fall below the agreement's own authoritative, currently-allocated
   capacity (`current_allocation_count`, or the workspace's own RFC-004
   `additional_business_slots` if that is ever the stricter of the two) —
   assert this invariant when applying the decrement. Since the released
   delta is exactly the amount this specific charge itself reserved and
   never allocated, and other still-pending reservations remain untouched
   by this release (composability, below), this floor is expected to hold
   by construction; the assertion exists to fail loudly rather than
   silently under-report capacity if that invariant is ever violated by a
   future change.
5. Later, still-pending reservations (an independent increase charge
   created before or after this one, not yet resolved) remain fully
   composable: the decrement is this charge's own frozen delta, applied
   once, under lock, independent of any other charge's own reservation or
   resolution order.
6. No agreement-state transition is recorded for the release itself,
   consistent with the reservation bump (`requestSlotAgreementIncrease()`'s
   own `target_allocation_count` update) never having been transition-logged
   either — both are plain field mutations on the agreement row, not
   state-machine transitions.
7. `current_allocation_count` is never touched by this release — it
   already only ever advances via a successful RFC-004 allocation (§A.4),
   and a terminally-failed increase never reached that step.

This preserves every required property: composability (a still-pending,
independent increase B's own reservation bump is untouched by A's release,
since the decrement is A's own frozen delta, applied additively/order-
independently under lock); exact payment→allocation correspondence
(unchanged — RFC-004 allocation always uses a charge's own frozen
`allocation_delta`); no free slots (a terminally-failed charge never
allocates, and its phantom reservation is removed); no permanently-poisoned
frontier (this is exactly what the release fixes); `change_operation_id`
idempotency (unaffected — a replay of the same `change_operation_id` still
finds and returns the same, already-resolved charge). Slot quantity is
never derived from money anywhere in this correction.

Re-walking the review's own example under this policy: current=1,
target=1. Increase A requests target=2 (delta=1) → target bumped to 2. A
exhausts 3 attempts → release fires → target decremented by 1 → target
back to 1. A later, independent increase B requests target=2 (delta=1,
computed against the now-correct baseline of 1) → B succeeds → RFC-004
allocates delta=1 → authoritative `additional_business_slots` becomes 2 →
`current_allocation_count` synchronizes to 2 (§A.4) → `target_allocation_count`
is 2. Target and the authoritative RFC-004 count agree exactly.

**Tests.** Extend `AdditionalBusinessSlotAgreementRepeatedIncreaseTest.php`
(§25 item 76) and/or `SlotAgreementAllocationSagaTest.php` (§25 item 80)
to prove: (1) a mid-period increase that exhausts three attempts releases
its exact reserved delta; (2) a second, independent increase created
*before* the first's terminal failure (still pending/in-flight) is
unaffected by the first's later release — its own reservation composes
correctly; (3) a subsequent new increase created *after* the release
computes its delta against the corrected (post-release) baseline; (4) the
final RFC-004 `additional_business_slots` value matches exactly the sum of
every successfully-paid delta, never including the failed one; (5)
`current_allocation_count`/`target_allocation_count` are coherent (equal)
once every in-flight charge has resolved; (6) a racing duplicate
application of the exact same terminal (3rd) failed result — the §E.2
no-op branch — releases the reservation exactly once, never twice, proving
the release is tied to the unique transition insert and not merely to the
threshold check running.

---

## E. Mandatory implementation fixes already required by the existing contract

These are not new design expansions — the final review of Draft PR #105
found the implementation does not yet satisfy requirements the merged
contract (as already amended by Correction Round 1) already states.
Recorded here as mandatory remediation so none is omitted when PR #105 is
amended after this document merges.

**E.1 — Real transaction-scoped locks.** `findForUpdateById()` provides an
effective row lock only inside an open transaction. Bounded read of every
`findForUpdateById` call site in `UsageBillingCheckoutManager.php` finds
exactly one already correctly wrapped
(`requestSlotAgreementIncrease()`, inside its own `DB::transaction(...)`
closure) and confirms the following are **not** currently locked at all,
in violation of §23's canonical-row-lock guarantee:

- `performVerifiedAllocation()` — never calls `findForUpdateById` at all
  (§A fixes this).
- `driveRenewalChargeRetry()` / the retry ordinal computation
  (`currentAttemptOrdinal()`) — operates on the caller-supplied `$charge`,
  never reloaded or locked (E.2 below).
- `applyScheduledRenewalSuccess()` — calls `findForUpdateById` on the
  agreement, but not inside an open `DB::transaction(...)`, so the lock is
  not actually effective.
- `performVerifiedRenewalChargeAllocation()` — same defect as above.
- `requestSlotAgreementCancellation()` / `finalizeSlotAgreementCancellation()`
  — no lock of any kind; operate directly on the caller-supplied model.

Each of these must be corrected to genuinely execute its lock/read/decide
step inside `DB::transaction(...)`, following the pattern
`requestSlotAgreementIncrease()` already establishes correctly: **lock,
read, and prepare locally; commit; make the provider network call outside
any open transaction; then lock, apply the result, and commit again.** No
provider/Stripe network call may ever execute while a transaction is open.

**E.2 — Retry race.** Two concurrent retries against the same charge must
never both consume the same Stripe-idempotent provider result and each
append their own transition — the durable attempt ordinal must remain
exactly one transition per actual provider attempt. This correction's own
original wording proposed persisting `FundingAttemptState::ProviderPending`
as an in-flight claim before the provider call — that is removed: it
contradicts this same document's own non-definitive-failure rule (§B) and
opens its own crash window (state changed to `ProviderPending`, transaction
commits, process dies before the Stripe call ever happens, and the charge
is now stuck outside the `[Failed, RequiresAction]` states
`driveRenewalChargeRetry()`'s own top-of-method guard requires — permanently
unretryable with no provider attempt ever having occurred). No pre-provider
state mutation of any kind is authorized. Concurrency is instead locked
using the attempt ordinal, Stripe's own idempotency key, and dedup at
result-application time:

1. In a short transaction, lock/reload the charge and its agreement,
   validate authority/state against the locked rows, and compute the
   attempted ordinal `N` (`currentAttemptOrdinal()`, unchanged formula).
   Commit **without changing the charge's state**.
2. Call Stripe outside the transaction using
   `hash('sha256', $charge->local_idempotency_key.':attempt:'.$N)` —
   unchanged from Correction Round 1's own formula.
3. Apply the result in another transaction, after re-locking the charge.

**Applying a definitive failure at attempted ordinal `N`:**
- Re-count durable `failed` transitions for this charge while locked.
- If that count is already `>= N`, this exact Stripe-idempotent result was
  already applied by a racing caller — no-op; return the current
  (already-applied) result.
- Otherwise the count must be exactly `N - 1` (the expected pre-attempt
  count); append exactly one `failed` transition.
- Run `maybeSetPaymentLapsed()`/`maybeReleaseTerminalIncreaseReservation()`
  (§D) **only** inside the branch that actually inserted this new `failed`
  transition — never on the no-op branch. This applies identically to
  pre-lapse ordinals 2–3 and to an explicit post-lapse ordinal 4+ recovery
  attempt: the same count-and-compare rule is the sole gate in both cases,
  with no separate mechanism for either.

**Applying success:** if the re-locked charge is already `Succeeded`,
perform only the already-contracted downstream replay repair
(`applyScheduledRenewalSuccess()`/`performVerifiedRenewalChargeAllocation()`,
§E.3) and do not append a second `succeeded` transition. Otherwise apply
success once, exactly as already contracted.

**Applying `requires_action`:** if the re-locked charge is already
`RequiresAction`, this exact result was already applied by a racing
caller — no-op, no second transition. Otherwise persist the state and
record the transition once.

**Applying a transient/non-definitive outcome (§B):** write no state
transition whatsoever; leave the charge exactly in its pre-call state;
the durable `failed`-transition count is therefore unchanged, so the next
invocation recomputes the identical ordinal `N` and reuses the identical
Stripe idempotency key — the same reconciliation this document's §B
already locks.

This makes the durable count-vs-`N` comparison the single source of
truth for "has this exact attempt's result already been applied," rather
than a fragile assumption that `applyRenewalChargeFailure()`/
`applyRenewalChargeSuccess()` can never be entered twice for the same
attempt — they can, under real concurrency, and each must independently
converge on the identical, single durable outcome.

`SlotAgreementConcurrencyTest.php` (§25 item 84) is strengthened, using the
real cross-process concurrency harness (§E.7), to prove: two genuinely
concurrent retries against the same charge, both configured to receive the
identical Stripe-idempotent declined result, produce at most one real
provider attempt (asserted via the fake gateway's own call recording in
the single-process variant, and via the real harness's own process-level
proof for the forced-race variant), exactly one durable `failed`
transition, and exactly one ordinal advancement — never two.

**E.3 — Scheduled-renewal success replay.** `applyRenewalChargeSuccess()`
currently calls `applyScheduledRenewalSuccess()` only inside the
`if ($charge->state !== Succeeded)` branch — a crash between the charge's
own `Succeeded` write and `applyScheduledRenewalSuccess()`'s own agreement
update leaves a replay that finds the charge already `Succeeded`, skips
the entire block, and never advances the agreement. Corrected: call
`applyScheduledRenewalSuccess($charge)` unconditionally, exactly mirroring
`performVerifiedRenewalChargeAllocation()`'s own already-unconditional call
for `mid_period_increase`. For this to be safe on replay,
`applyScheduledRenewalSuccess()` itself must become forward-only/idempotent:
rather than unconditionally writing its computed `next_renewal_at`, it
computes the target exactly as today (the `wasLapsed`-branched formula,
unchanged) and then writes
`max($computedTarget, $agreement->next_renewal_at ?? $computedTarget)` —
never regressing an already-correctly-advanced date backward on a
redundant replay. `payment_lapsed`/`payment_lapsed_cleared_at` are
unaffected (already naturally idempotent — clearing an already-cleared
flag is a no-op).

**E.4 — Price-change notice compares against the real prior period.**
`createScheduledRenewalCharge()` looks up `findByLocalIdempotencyKey($localIdempotencyKey)`
twice: once (correctly) to detect an existing charge for this exact period
and return early if found, and again — after that first check has already
returned `null` for this call to proceed at all — to derive `$priorAmount`,
which is therefore always `null` and always falls back to the agreement's
own original checkout amount forever. Corrected: add
`findPreviousScheduledRenewalCharge(int $agreementId, int $excludingChargeId): ?AdditionalBusinessSlotRenewalCharge`
to `AdditionalBusinessSlotRenewalChargeRepository`'s contract and Eloquent
implementation (§25 items 29/36 — both already-authorized files; a new
method on an existing repository, not a new path), querying
`charge_kind = 'scheduled_renewal' AND agreement_id = ? AND id != ? ORDER
BY id DESC LIMIT 1`. `createScheduledRenewalCharge()` calls this (after the
new charge's own row exists, so it has an id to exclude) and compares
against `$previousCharge?->amount_micro_snapshot ?? $agreement->total_amount_micro_snapshot` —
falling back to the checkout amount only for the genuinely first-ever
scheduled renewal, comparing against the real prior renewal for every one
after that.

**E.5 — Persisted-state authority, not stale-model authority.** General
principle restated: every replay-sensitive manager operation (§A, §D, E.2,
E.3 above) must reload/lock the authoritative row before deciding whether
to mutate — never trust a caller-supplied model's own in-memory state for
that decision. Explicit stale-model tests are required (extending
`SlotAgreementConcurrencyTest.php`/`SlotAgreementAllocationSagaTest.php`)
proving: RFC-004 allocation happens exactly once, the M4 `completed`
transition is recorded exactly once, and the `AdditionalBusinessSlotAgreementCompleted`
event is dispatched exactly once, in each case even when the calling code
holds a model object that predates the row's own most recent persisted
state.

**E.6 — Webhook evidence must fail closed.** `ProcessPaymentProviderEvent.php`'s
(§25 item 46) three branches each validate `amount`/`currency`/`customer`
via `array_key_exists('field', $object) && (...) !== $expected` — meaning
a genuinely *absent* field is silently treated as "check not applicable,"
not as missing required evidence. For a `payment_intent.*` event (the
`funding_attempt` and `slot_renewal_charge` branches), Stripe's own
PaymentIntent object always carries `amount`/`currency`/`customer`; a real
delivery missing one is malformed/incomplete evidence, not a legitimately
optional field. Corrected: for each of the three branches, each required
field is checked for presence first — `markFailed($event->id,
'missing_required_evidence')` and return if absent — before the existing
mismatch comparison runs. The `slot_agreement` (Checkout Session) branch's
existing `amount ?? amount_total` fallback is preserved, but now requires
*at least one* of the two to be present (currently, if both are absent, the
whole amount check is silently skipped exactly like the other branches).
`app_subject_kind` remains an untrusted routing hint only, unchanged —
this correction only tightens the already-contracted post-routing
validation, never trusts the hint itself further.

**E.7 — Concurrency tests.** `SlotAgreementConcurrencyTest.php`'s (§25 item
84) own docblock states plainly that it exercises sequential
double-invocation, not real forced concurrency, while M4 §23 requires
forced-race scenarios. This codebase already has an established, working
pattern for genuine cross-process concurrency:
`tests/Feature/Entitlement/Support/concurrent_business_slot_runner.php` (a
standalone script, invoked as a separate OS process, that boots the app
against the shared `ultimatesms_testing` database and dispatches to named
production manager methods, plus a generic `hold-then` primitive that
takes an explicit `SELECT ... FOR UPDATE` lock, signals `LOCKED`, sleeps,
then runs its delegate inside the same transaction — letting a parent
PHPUnit test start a genuinely blocked "waiter" process only after
confirming the "holder" process has the row locked). That file is scoped
to Entitlement/Workspace manager methods and is not M4-owned; reusing or
extending it would mix an unrelated milestone's own authorized file into
M4's own scope. A new, M4-scoped file mirroring its exact pattern —
`tests/Feature/Usage/Support/concurrent_slot_agreement_runner.php`,
dispatching to `performVerifiedAllocation`, `retrySlotRenewalAsOwner`,
`retrySlotRenewalAsAdministrator`, and `allocateSlotAgreementAsAdministrator`
by name, with the identical `hold-then` primitive — is genuinely necessary
and is authorized by this correction (§F) rather than silently added
during implementation. `SlotAgreementConcurrencyTest.php` is strengthened
to use it for at least: two genuinely concurrent `performVerifiedAllocation()`
callers racing the same `allocation_pending` agreement (proving E.2/E.5's
own single-transition guarantee under real, not simulated, concurrency),
and two genuinely concurrent retries against the same renewal charge
(proving E.2's claim-then-provider-call mechanism actually serializes
real racing processes, not just sequential calls in one process).

---

## F. Allowlist and final correction budget

Mechanically reconciled against the existing implementation's own 95-path
allowlist (Correction Round 1 §G confirmed this count unchanged from the
merged M4 contract's own §25). Every fix in §A–§E above is confirmed to
land inside an already-authorized path **except exactly two**, both
genuinely necessary and both explicitly authorized here rather than added
silently:

1. `app/Exceptions/Usage/ProviderCardDeclinedException.php` — widened with
   optional PaymentIntent-id evidence (§B). No already-authorized path can
   carry this evidence: it is only observable at the moment the gateway
   catches the SDK's own `CardException`, and the exception class is the
   smallest, most surgical channel to carry it from there to the manager
   without altering `createOffSessionPaymentIntent()`'s own return
   contract (which would also affect M3's separate, out-of-scope
   funding-attempt retry mechanism).
2. `tests/Feature/Usage/Support/concurrent_slot_agreement_runner.php` — a
   new real cross-process concurrency harness for M4's own manager
   methods (§E.7). The existing, established pattern
   (`tests/Feature/Entitlement/Support/concurrent_business_slot_runner.php`)
   cannot be reused without mixing an unrelated, not-M4-owned milestone's
   own authorized file into this milestone's scope; a new file mirroring
   its exact, already-proven pattern is the smallest correct solution.

**Every other correction in §A–§E lands inside an already-authorized
path**: `app/Library/Usage/UsageBillingCheckoutManager.php` (§25 item 41),
`app/Library/Usage/StripePaymentProviderGateway.php` (item 89),
`app/Library/Usage/FakePaymentProviderGateway.php` (item 90),
`app/Jobs/Usage/ProcessPaymentProviderEvent.php` (item 46),
`app/Repositories/Contracts/AdditionalBusinessSlotAgreementRepository.php`/`EloquentAdditionalBusinessSlotAgreementRepository.php`
(items 27/34),
`app/Repositories/Contracts/AdditionalBusinessSlotRenewalChargeRepository.php`/`EloquentAdditionalBusinessSlotRenewalChargeRepository.php`
(items 29/36), and the already-authorized test files named throughout
(`SlotAgreementAllocationSagaTest.php`, `SlotAgreementConcurrencyTest.php`,
`AddonPurchaseTransitionAuditTest.php`,
`AdditionalBusinessSlotAgreementRepeatedIncreaseTest.php`,
`StripePaymentProviderGatewayCompatibilityTest.php`,
`FakePaymentProviderGatewayTest.php`).

**Final implementation allowlist: 97 paths (95 + 2). Stop threshold: the
98th path.** This is the final correction round — if the bounded
implementation work this document authorizes discovers a genuine need for
a 98th path, it must STOP AND REPORT rather than expand this budget
silently; with zero correction rounds remaining after this one, that would
require a new milestone slice or explicit separate governance action, not
a third M4 correction.

No RFC-004 modification is authorized by this document. No M5/M6 work is
authorized. No refund mechanism is authorized. No lapse-capacity
revocation policy is authorized. No commercial add-on values (key, price,
currency) are authorized or invented anywhere in this document.

---

## G. Required re-verification

Once this document is human-merged and implementation resumes on the
existing `agent/rfc-005-m4` branch/worktree, the entire corrected gate
sequence must be run from the beginning — no gate result from before this
correction may be reused or assumed:

1. Every focused M4 test file (the existing ~24, plus the new/strengthened
   coverage §A–§E each require) — zero failures, exact passing/assertion
   count reported.
2. `php artisan test tests/Unit/Usage tests/Feature/Usage` — zero failures.
3. `php artisan test tests/Unit/Entitlement tests/Feature/Entitlement tests/Unit/Workspace tests/Feature/Workspace` — zero failures.
4. `php artisan test --stop-on-failure` (full suite) — zero failures, exit 0.
5. Independently reconfirmed: the corrected allocation-recovery tests (§A),
   the corrected hard-decline/definitive-failure tests (§B), the
   corrected add-on crash-ordering tests (§C), the corrected
   terminal-increase-release tests (§D), the corrected
   transaction-scoped-lock and real-concurrency tests (E.1/E.2/E.7), the
   corrected scheduled-renewal-replay test (E.3), the corrected
   price-change-notice test (E.4), and the corrected webhook
   fail-closed tests (E.6) — each independently passing, not merely
   included in an aggregate count.

---

## H. Explicit non-scope statement

Unchanged from Correction Round 1 §I and the merged M4 contract's own
scope: no RFC-004 modification; no M5/M6; no refund mechanism; no
lapse-capacity revocation policy; no commercial add-on catalog values. This
document adds no new non-goal beyond what §F already states explicitly for
its own two new paths.

---

## I. Contract self-audit

1. Every finding in the final review of Draft PR #105 is addressed by name
   with an exact locked correction (§A–§E). ✓
2. §A names the exact corrected recoverable-state set, the exact
   reload/lock discipline, the exact idempotent-no-op rule, the exact
   `current_allocation_count` synchronization rule, and the exact
   reconciliation-query widening — no allocation semantics invented beyond
   what the merged contract already named `allocation_failed` as requiring.
   ✓
3. §B names the exact SDK evidence path (`ErrorObject::$payment_intent->id`),
   the exact minimal exception widening, the exact manager-side
   persist-before-fail ordering, and the exact definitive-vs-transient
   exception classification — with the one genuinely new path explicitly
   authorized, not silently added. ✓
4. §C states the exact corrected crash-safe ordering for `wallet_credit`
   add-ons and confirms `direct_deliverable` is unchanged, with all three
   crash directions enumerated. ✓
5. §D states, explicitly, that the RFC does not itself decide the terminal
   mid-period-increase reservation policy, and locks exactly one
   deterministic policy (release by the charge's own frozen delta) that
   preserves every required property, with the worked example re-walked
   under the corrected policy. Slot quantity is never derived from money.
   ✓
6. §E enumerates all seven mandatory remediation items with an exact
   locked mechanism for each, naming every call site the bounded read
   found non-compliant. ✓
7. §F reconciles the allowlist mechanically, authorizes exactly two new
   paths with a stated reason each existing path cannot satisfy, and sets
   the new count (97) and stop threshold (98). ✓
8. §G requires the entire gate sequence re-run from the beginning; no
   count is fabricated by this document. ✓
9. This is explicitly recorded as Correction Round 2 of 2, with zero
   rounds remaining after merge (§0). ✓
10. Draft PR #105 / branch `agent/rfc-005-m4` / commit
    `a1700c104cd8725d8b916c58fde494bb32b4805e` is confirmed untouched by
    this document, both before drafting and immediately before commit
    (§0, §J). ✓
11. This document itself contains no implementation code and changes
    exactly one file, verified mechanically before commit (§J). ✓
12. No commercial/financial default is invented anywhere in this document.
    ✓

**Post-refinement additions** (this document was refined once, in place, on
`chore/rfc-005-m4-correction-2` / Draft PR #106, before any human merge —
still Correction Round 2 of 2, not a third round):

13. §A no longer authorizes any direct `WorkspacePlanAssignmentRepository`/
    `EloquentWorkspacePlanAssignmentRepository` dependency — the
    already-`completed` replay case resolves its assignment exclusively by
    replaying `EntitlementManager::allocateAdditionalBusinessSlotsFromVerifiedPayment()`
    with the agreement's own frozen evidence, relying on RFC-004's own
    idempotency guarantee; `EntitlementCatalogSourceBoundaryTest.php`
    remains unmodified and still passes. ✓
14. §A's `performVerifiedAllocation()` is locked into three explicit
    phases with an exact transaction boundary for each, including the
    exact durable-commit-before-rethrow ordering on an RFC-004 exception.
    ✓
15. §E.2 no longer authorizes any persisted pre-provider state mutation
    (`ProviderPending` or otherwise); concurrency is locked entirely via
    the attempt ordinal, Stripe's own idempotency key, and an exact
    result-application dedup rule, with transient/non-definitive outcomes
    explicitly leaving state and ordinal unchanged. ✓
16. §D's terminal-increase-reservation release is now atomically tied to
    the unique result-application branch that inserts the threshold-
    reaching `failed` transition (never to a separate assumption about
    call count), with an explicit floor guard against reducing
    `target_allocation_count` below authoritative allocated capacity. ✓
17. §B locks an explicit fail-closed behavior for a definitive card
    decline whose `providerPaymentIntentId` evidence is unexpectedly
    absent — no reference is ever invented; the charge is left exactly as
    a non-definitive outcome would leave it. ✓
18. The implementation allowlist remains unchanged by this refinement:
    97 paths, stop threshold the 98th — no new path is required by any of
    §13–§17's fixes; every one lands inside the same two newly-authorized
    paths (or already-authorized paths) §F already named. ✓

---

## J. Verification and publication (this document only)

**Publication history.** Initially committed as `2ad3343e2f80d2971d61f9957990a9e91b8c1122`
on `chore/rfc-005-m4-correction-2` (Draft PR #106). Refined once, in
place, per the review that produced §13–§18 above — a new commit on the
same branch/PR, still unmerged, still Correction Round 2 of 2.

Performed, in order, before this refinement's own commit:

1. `git status --porcelain` inside `../rfc-005-m4-impl-worktree` (the
   existing implementation worktree, branch `agent/rfc-005-m4`) — confirms
   `HEAD` is exactly `a1700c104cd8725d8b916c58fde494bb32b4805e` and the
   working tree carries only the four already-known, already-excluded
   environment-only artifacts (`bootstrap/cache/packages.php`,
   `bootstrap/cache/services.php`, `public/mix-manifest.json`,
   `public/images/branding/logo_compact/...`), nothing else — confirming
   both this document's original drafting and this refinement touched
   neither the branch nor the worktree, and PR #105 remains exactly at
   that commit.
2. `git status --porcelain` inside this document's own worktree
   (`../rfc-005-m4-correction-2-worktree`) — exactly one modified path,
   `docs/automation/RFC-005-M4-CORRECTION-2.md`, nothing else, nothing
   staged.
3. `git diff --check` — clean.
4. Stage the one file by its exact path only
   (`git add docs/automation/RFC-005-M4-CORRECTION-2.md`), never
   `git add -A`/`.`.
5. Commit exactly: `docs: refine RFC-005 Milestone 4 correction round 2`.
6. Push normally to `origin chore/rfc-005-m4-correction-2`. No force push —
   a new commit on top of the existing branch, never a rewrite.
7. PR #106 already exists on this branch; no new PR is opened. **Do not
   merge either PR.**
