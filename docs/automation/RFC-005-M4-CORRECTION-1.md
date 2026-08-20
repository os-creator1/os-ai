# RFC-005 Milestone 4 — Correction Round 1

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

**This document authorizes drafting itself only.** Merging it authorizes six
narrowly scoped corrections to the merged M4 contract's own §21 manager
method surface, §18 schema, §11/§13 mid-period-increase algorithm, §15
webhook dispatch, and §27 test contract — closing two genuine structural
gaps the implementation attempt found before writing a single line of
manager, job, or HTTP code — to be made as part of that same,
still-unimplemented (past its first 41 paths), explicitly bounded
implementation once this document is merged. **No implementation commit
exists yet for M4**: the attempt correctly stopped before committing or
pushing anything, immediately after the first 41 allowlisted paths
(migrations, enums, DTOs, exceptions, models, repositories, DI bindings),
and reported the discrepancy for explicit human authorization rather than
inventing manager behavior the merged contract never named. Merging this
document does **not** itself change any `app/`, `database/`, `routes/`,
`config/`, or `tests/` file, and does not itself run any gate — that remains
the corrected implementation's own, later, separate obligation (§H).

---

## 0. Governance

- Drafted on branch `chore/rfc-005-m4-correction-1`, in an isolated linked
  worktree (`../rfc-005-m4-correction-1-worktree`), based on `origin/main`
  at `0c1da280bb049e2e459dfe93d5006c5aebaddd21` (merge of PR #103, the M4
  contract itself) — `origin/main` has not moved since (confirmed by direct
  `git log origin/main -1` before drafting).
- **This is Correction Round 1 of a maximum of 2**, matching the M4
  contract's own `maximum_correction_rounds: 2` (M4 contract §0), which
  this document does not change. One further correction round remains
  available to M4's implementation after this one, should it be needed; a
  third would require its own new, separately authorized document.
- This document amends only the merged M4 contract's own §21 (manager
  method surface), §18 (schema — one new column), §11/§13 (mid-period-increase
  algorithm and success/failure semantics), §15 (webhook dispatch), §25
  (allowlist — reconciled, count unchanged), and §27 (test contract), in
  the six narrow ways §A–§F below state exactly. Every other section of
  the merged M4 contract — §0/§1 (governance/preflight), §4/§5 (status
  model/open decisions), §6/§7 (scope/exclusions), §8/§8a (allocation
  boundary/refund non-goal), §9 (catalog-pricing boundary), §10
  (add-ons: structural only), §12 (cancellation), §14 (administrator
  authority), §15a/§15b/§15c (Checkout Session gateway extension, webhook
  amount normalization, payment-instrument reconciliation), §16/§17
  (authorization/HTTP surfaces), §19/§20 (enums/DTOs/models, repository
  contracts), §22 (jobs/events/scheduling), §23 (concurrency/attempt-ordinal
  algorithm), §24 (test-mode preview), §28/§29/§30 (acceptance
  criteria/stop conditions/self-audit) — is **unchanged and remains fully
  in force**. This document closes two structural gaps in the manager's
  own contracted method surface; it does not revise M4's design.
- **Both gaps corrected here were discovered by the implementation attempt
  itself** (not yet committed or pushed — no PR exists for M4's
  implementation), which correctly stopped before writing the manager
  extension (§21's own next unimplemented item) rather than inventing
  uncontracted method names/signatures/allocation semantics, and reported
  both findings for explicit human authorization before any further code
  was written. Each finding below is reproduced from that stop report, not
  re-derived.
- The implementation's working tree, in the isolated worktree
  `../rfc-005-m4-impl-worktree` on branch `agent/rfc-005-m4`, remains
  exactly as left when the stop was reported: the first 41 allowlisted
  paths (migrations, enums, DTOs, exceptions, models, repository contracts,
  Eloquent repositories, the `AppServiceProvider` DI-binding widening)
  created/modified, all lint-clean, nothing staged, nothing committed,
  nothing pushed. This document does not touch that branch or worktree in
  any way (§I).

---

## A. Correction A — renewal-charge webhook authority

**Finding, verbatim from the implementation attempt:** the merged M4
contract's §15 requires `ProcessPaymentProviderEvent` to dispatch the
canonical `slot_renewal_charge` subject kind, and states plainly that this
kind "routes every renewal/mid-period-increase charge's own off-session
PaymentIntent webhook confirmation" — but §21's own closed, 13-method list
names no manager method capable of resolving an already-existing renewal
charge from a webhook event. The four renewal-related methods
(`createScheduledRenewalCharge()`, `requestSlotAgreementIncrease()`,
`retrySlotRenewalAsAdministrator()`, `retrySlotRenewalAsOwner()`) each
*drive a new attempt*; none *confirms an already-in-flight charge from a
webhook*, the exact `confirmAttemptFromWebhook()`/
`markAttemptFailedFromWebhook()` role M3 already established for funding
attempts. Without it, a renewal charge that returns `requires_action`
(§11's own explicitly named case) has no way to ever resolve.

**Required correction — exact:**

Two new public methods are authorized on the already-allowlisted
`app/Library/Usage/UsageBillingCheckoutManager.php` (§25 item 41 — no new
path; item 41's own "thirteen new public methods" description is amended
to **fifteen**):

```php
public function confirmSlotRenewalChargeFromWebhook(
    AdditionalBusinessSlotRenewalCharge $charge,
    PaymentProviderEvent $event,
): void

public function markSlotRenewalChargeFailedFromWebhook(
    AdditionalBusinessSlotRenewalCharge $charge,
    string $failureReason,
    PaymentProviderEvent $event,
): void
```

Exact-equivalent naming is acceptable only if the semantics below remain
identical.

- `ProcessPaymentProviderEvent`'s own `slot_renewal_charge` branch (already
  allowlisted, §25 item 46, already contracted by M4 §15) must, after
  performing the already-contracted full persisted-evidence validation
  (provider object id, operation id, amount, currency, customer — all
  against the charge's own frozen expectations, identical discipline to
  every other branch): on verified provider success call
  `confirmSlotRenewalChargeFromWebhook()`; on verified provider failure
  call `markSlotRenewalChargeFailedFromWebhook()`. **The job itself never
  mutates a renewal-charge or agreement row** — identical discipline to
  every other M4 webhook branch (M4 contract §16).
- **A single shared private/internal renewal-result-application routine**
  (new, inside the same already-allowlisted manager file) must apply a
  verified provider outcome (success or failure) to a renewal charge, and
  must be the **sole** place that logic exists. It is reused, not
  duplicated, by:
  - `confirmSlotRenewalChargeFromWebhook()`/
    `markSlotRenewalChargeFailedFromWebhook()` (this correction);
  - the synchronous outcome `createScheduledRenewalCharge()` and
    `requestSlotAgreementIncrease()` already observe when their own
    attempt-1 `createOffSessionPaymentIntent()` call resolves immediately
    (mirroring `initiateCharge()`'s own existing synchronous-`succeeded`
    handling, M3 contract §11);
  - the synchronous outcome `retrySlotRenewalAsOwner()`/
    `retrySlotRenewalAsAdministrator()` already observe when their own
    `confirmPaymentIntent()` call resolves immediately.

  This is the exact convergence pattern the merged M4 contract's own §21
  already establishes for `confirmSucceeded()` (funding attempts) and for
  `performVerifiedAllocation()` (slot-agreement allocation) — one internal
  routine, several external triggers, never two different state machines
  for the identical logical outcome.
- **A renewal charge already in `Succeeded` state must still run any
  idempotent mid-period allocation completion (§D below) before
  returning**, exactly mirroring the M3-established, M4-already-contracted
  `AddonPurchase` replay-hole fix (M4 contract §21) — a crash after the
  charge's own payment-state persists but before entitlement allocation
  completes must never strand a paid increase. This applies to
  `confirmSlotRenewalChargeFromWebhook()` and to the shared routine's own
  synchronous callers alike — the identical "already-Succeeded early
  return still finalizes pending downstream work" shape, applied here to
  allocation instead of wallet-credit/add-on finalization.

---

## B. Correction B — durable allocation delta for mid-period increases

**Finding, verbatim from the implementation attempt:** the merged schema
for `additional_business_slot_renewal_charges` (M4 contract §18) has no
durable value recording how many additional slots a particular
`mid_period_increase` charge purchased. Reverse-deriving a slot count from
`amount_micro_snapshot` is unsafe — proration (§11's own exact-second
arithmetic) and `bcRoundHalfUp()` rounding make the amount-to-quantity
mapping lossy and non-invertible in general.

**Required correction — exact:**

Add exactly one column to `additional_business_slot_renewal_charges`:

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `allocation_delta` | `unsigned tinyint` | Yes | `NULL` | `NULL` for `scheduled_renewal`; required, `> 0`, for `mid_period_increase` |

Semantics, locked:

- `charge_kind === 'scheduled_renewal'` → `allocation_delta = NULL`,
  always — a scheduled renewal charges for slots already allocated; it
  never changes the allocated count.
- `charge_kind === 'mid_period_increase'` → `allocation_delta` is
  **required** and **must be `> 0`** at charge-creation time.
- `allocation_delta` is **frozen at charge creation and never recomputed
  from money later** — identical discipline to every other `*_snapshot`
  column M4 already freezes (M4 contract §9 item 4).

**Authorized paths for this correction — no new path, all three already
allowlisted:**

- `database/migrations/{impl_date}_create_additional_business_slot_renewal_charges_table.php`
  (§25 item 3) — add the one column above to the existing `Schema::create()`
  call; no other column, index, or foreign key on this table changes.
- `app/Models/AdditionalBusinessSlotRenewalCharge.php` (§25 item 22) — add
  `allocation_delta` to `$fillable` and cast it `integer` (nullable-safe,
  matching every other nullable integer column already cast this way on
  M4 models).
- `app/Repositories/Contracts/AdditionalBusinessSlotRenewalChargeRepository.php`/
  `app/Repositories/Eloquent/EloquentAdditionalBusinessSlotRenewalChargeRepository.php`
  (§25 items 29/36) — no interface change is required; `allocation_delta`
  is written and read through the existing `create()`/`update()` methods'
  own `$attributes` array, identically to every other column on this
  table.

---

## C. Correction C — exact increase creation algorithm

`requestSlotAgreementIncrease()` (M4 contract §21, already allowlisted,
§25 item 41) is corrected to lock the agreement **before** deriving the
paid delta, and to reserve that delta against the agreement's own
`target_allocation_count` — not its `current_allocation_count` — so two
distinct increases requested while an earlier one is still pending never
collide or double-reserve.

**Exact algorithm, locked:**

1. Lock the agreement row (`findForUpdateById()`-equivalent, the agreement/
   charge-row lock M4 contract §23 already establishes as canonical order).
2. If a renewal-charge row already exists for this exact
   `change_operation_id` (`AdditionalBusinessSlotRenewalChargeRepository::findByChangeOperationId()`,
   already contracted, M4 contract §11/§20): **return the existing logical
   charge unchanged** — no new delta is reserved, `target_allocation_count`
   is not advanced a second time. This is the exact, already-contracted
   `change_operation_id`-anchored idempotency M4 §11 requires, now stated
   precisely against the locked row.
3. Otherwise, for a genuine new increase:
   - Require `requested_target_allocation_count >
     locked_agreement.target_allocation_count` — **the locked
     `target_allocation_count` is the reservation frontier for new paid
     increases, never the stale `current_allocation_count`.** A second
     increase requested while an earlier one is still pending (current 2 →
     target 3 pending → a further request for target 4) computes
     `allocation_delta = 4 - 3 = 1`, composing correctly with the first
     increase's own already-reserved delta of `1` (current 2 → target 3),
     never re-deriving from the stale `current_allocation_count = 2`.
   - `allocation_delta = requested_target_allocation_count -
     locked_agreement.target_allocation_count` (§B's new column).
   - Atomically, in one transaction, in this exact order:
     1. snapshot that positive `allocation_delta` onto the new
        renewal-charge row (alongside every other already-contracted
        `mid_period_increase` snapshot column — proration amount, pricing
        snapshots, `change_operation_id`, `local_idempotency_key`);
     2. update the agreement's own `target_allocation_count` to
        `requested_target_allocation_count`;
     3. calculate the prorated `amount_micro_snapshot` from that frozen
        delta, using the exact-second arithmetic M4 contract §11 already
        locks (`price_per_slot_micro_snapshot × allocation_delta`,
        proportioned by `remaining_seconds`/`total_seconds`, rounded via
        `bcRoundHalfUp()`);
     4. commit the local transaction;
   - **Only then** perform the outbound `createOffSessionPaymentIntent()`
     call, strictly outside any open transaction/lock — identical
     discipline to every other M4/M3 outbound provider call (M3 contract
     §8/§16, M4 contract §21).

This makes two distinct increases in the same period genuinely composable
(the worked example above), and guarantees a genuine retry of the *same*
increase (identical `change_operation_id`) is absorbed as a pure no-op at
step 2 — never advancing `target_allocation_count` and never creating a
second delta — exactly as M4 contract §11 already requires, now made
mechanically precise.

**No new implementation path** — this correction is entirely internal
logic inside the already-allowlisted `requestSlotAgreementIncrease()`
method (§25 item 41).

---

## D. Correction D — verified mid-period allocation seam

One new shared private/internal manager routine is authorized (exact-
equivalent naming acceptable), inside the same already-allowlisted
`UsageBillingCheckoutManager.php` (§25 item 41 — no new path):

```php
private function performVerifiedRenewalChargeAllocation(
    AdditionalBusinessSlotRenewalCharge $charge,
): WorkspacePlanAssignment
```

Used **only** for `charge.charge_kind === 'mid_period_increase'`, and only
after that charge's own payment has been durably, provider-verified
successful. It must:

1. Load and lock the parent agreement (`findForUpdateById()`-equivalent —
   M4 contract §23's own canonical lock order).
2. Require `charge.allocation_delta > 0` — fail closed (a `scheduled_renewal`
   charge, or a `mid_period_increase` row somehow missing its delta, must
   never reach this routine; a defensive guard, not a reachable path under
   correct upstream discipline).
3. Require `charge.provider_session_or_intent_reference` is non-empty —
   fail closed otherwise.
4. Use the **parent agreement's** real `workspace_id` — loaded fresh
   (`$agreement->load('workspace')`, mirroring M4 contract §8 item 3's own
   "loaded fresh, never a caller-supplied instance" discipline), never a
   caller-supplied Workspace.
5. Use the **parent agreement's** original `requesting_customer_user_id`
   — never the charge's own `actor_user_id` (which may be `null` for a
   `scheduled_job`-adjacent context or the owner/administrator who
   triggered a *retry* of this charge, not who originally agreed to the
   increase) — identical "the payer's own identity is always what
   RFC-004's own audit trail records" discipline M4 contract §8 item 3
   already locks for the initial-agreement allocation.
6. Call **only**
   `EntitlementManager::allocateAdditionalBusinessSlotsFromVerifiedPayment()`
   (M4 contract §8 item 1 — the sole authorized RFC-004 mutation seam),
   with:
   - `$workspace` — the agreement's own, freshly loaded (item 4 above).
   - `$additionalSlotsToAdd = $charge->allocation_delta`.
   - `$requestingCustomerUserId` — the agreement's own
     `requesting_customer_user_id` (item 5 above).
   - `$paymentVerifiedForWorkspaceId = $agreement->workspace_id`.
   - `$paymentIdempotencyKey` — a deterministic key derived from the
     **renewal charge's own** immutable `local_idempotency_key`, **never**
     the parent agreement's own initial-payment key (a distinct namespace
     is required precisely because a single agreement may have many
     renewal charges, each its own independent allocation event):
     `sha256('slot-renewal-allocation:' . $charge->local_idempotency_key)`.
     **This exact literal namespace is locked by this correction** —
     confirmed valid against `workspace_entitlement_transitions.payment_idempotency_key`'s
     own `string(191)` unique column (a sha256 hex digest is always exactly
     64 characters, well within 191, regardless of the 24-character prefix
     or the input `local_idempotency_key`'s own length) — no invention
     required or permitted during implementation.
   - `$paymentProviderReference = $charge->provider_session_or_intent_reference`.
   - `$reason` — `null` (this is never an administrator-originated action
     merely because an administrator triggered the *retry* that happened
     to succeed — the payer's own original consent is what's being
     fulfilled, identical reasoning to M4 contract §8 item 3's own
     `$reason: null` case for the ordinary payment-triggered path).
7. After the RFC-004 call returns, **synchronize**
   `agreement.current_allocation_count = returned WorkspacePlanAssignment.additional_business_slots`
   — **never** increment the local count blindly by `allocation_delta`.
   This is what makes replay/crash recovery safe: RFC-004 itself owns
   payment-allocation idempotency (a genuine replay of the identical
   `$paymentIdempotencyKey` returns the current assignment unchanged, M4
   contract §8 item 4, Amendment 1's own corrected comparison), and M4
   re-synchronizes its own billing-side display count from that
   authoritative return value rather than trusting local arithmetic.
8. **Does not mutate any RFC-004 repository or table directly** — the
   single call in item 6 is the only RFC-004-facing effect, identical
   discipline to `performVerifiedAllocation()` (M4 contract §8 item 1).

---

## E. Correction E — success/failure behavior, exact

The shared renewal-result-application routine (§A) branches on
`charge.charge_kind`:

**`scheduled_renewal`, provider success:**

- Performs the already-contracted renewal/lapse/next-renewal updates (M4
  contract §11/§13) — advances `next_renewal_at`, clears `payment_lapsed`
  if this success is a post-lapse recovery, etc.
- **Performs no slot allocation of any kind** — a scheduled renewal
  charges for slots already allocated at the original agreement (or a
  prior increase); it never itself changes the allocated count.

**`mid_period_increase`, provider success:**

- Durably records the charge's own payment success (state transition to
  `Succeeded`, identical mechanics to every other renewal-charge success).
- **Then invokes `performVerifiedRenewalChargeAllocation()` (§D).**
- **A replay where the charge's own state is already `Succeeded` must
  still idempotently invoke `performVerifiedRenewalChargeAllocation()`
  before returning** (§A's own replay-hole-fix requirement) — RFC-004's
  own idempotency guarantee (§D item 6) makes a repeated call against an
  already-completed allocation a safe no-op that still re-synchronizes
  `current_allocation_count` (§D item 7), so a crash strictly between
  "charge marked Succeeded" and "allocation completed" can never strand a
  paid increase.
- After successful allocation, the **agreement** remains the active/
  `completed` agreement (a mid-period increase never changes the parent
  agreement's own `state` column — only `current_allocation_count`, item
  7 above) — no separate agreement-level state transition is authorized
  or required by this correction.

**Provider failure (either `charge_kind`):**

- Writes the append-only renewal-charge failure transition
  (`to_state: 'failed'`) — the exact durable fact the already-contracted
  attempt-ordinal algorithm (M4 contract §23) reads.
- Participates in the already-authorized durable attempt-ordinal/dunning
  rules (M4 contract §13/§23) — completely unchanged by this correction.
- **Never allocates** — a failed charge, of either kind, never reaches
  `performVerifiedRenewalChargeAllocation()`.

**If the allocation call itself fails** (an `EntitlementManager` exception
for any reason, after the charge's own payment already succeeded): **do
not roll the charge's payment state back to failed, and do not invent a
refund** (M4 contract §8a remains fully in force, unaffected by this
correction). The charge remains durably paid; a subsequent webhook
delivery or explicit re-entry of the same already-`Succeeded` charge must
retry the idempotent allocation completion (§A's own replay-hole-fix
requirement, §D item 6's own idempotency guarantee) — never a silent
inline retry loop, never a swallowed failure. This mirrors, exactly, how
`performVerifiedAllocation()`'s own already-contracted failure path (M4
contract §8 item 5) leaves the initial agreement in `allocation_failed`
for `ReconcileSlotAgreementAllocation`/administrator recovery to resolve —
the equivalent recovery path for a mid-period increase's own allocation
failure is the identical webhook-replay/re-entry mechanism just described,
since a renewal charge has no separate `allocation_failed`-shaped state of
its own (its `state` column reuses `FundingAttemptState`, M4 contract §18,
which has no such value) — this is a deliberate, narrower recovery
surface than the initial-agreement case, sufficient because
`performVerifiedRenewalChargeAllocation()`'s own idempotent re-entry (item
6 above) is reachable from the very next webhook delivery or owner/admin
retry action without inventing a new state or a new manager method.

---

## F. Correction F — tests

Every required coverage item below is satisfied by strengthening an
**already-authorized** M4 test path (M4 contract §25 items 74–87) — no new
test path is required or authorized by this correction:

| Required coverage | Authorized test file (§25 item) |
|---|---|
| `slot_renewal_charge` webhook success reaches the manager, never direct repository mutation | `WebhookSlotAgreementSubjectRoutingTest.php` (83) |
| Webhook failure reaches the manager's own failure method | `WebhookSlotAgreementSubjectRoutingTest.php` (83) |
| Immediate synchronous success and later webhook success converge on the identical routine | `SlotAgreementConcurrencyTest.php` (84) |
| `scheduled_renewal` success never calls RFC-004 allocation | `SlotAgreementAllocationSagaTest.php` (80) |
| `mid_period_increase` snapshots its exact positive `allocation_delta` | `AdditionalBusinessSlotAgreementProrationTest.php` (75) |
| Two distinct pending increases reserve deltas from the locked `target_allocation_count`, never the stale `current_allocation_count` | `AdditionalBusinessSlotAgreementRepeatedIncreaseTest.php` (76) |
| Retry of the same `change_operation_id` does not reserve another delta | `AdditionalBusinessSlotAgreementRepeatedIncreaseTest.php` (76) |
| A paid increase calls RFC-004 with exactly that charge's own frozen delta | `SlotAgreementAllocationSagaTest.php` (80) |
| Two paid increase charges use distinct payment-allocation idempotency keys | `SlotAgreementAllocationSagaTest.php` (80) |
| Crash/replay after charge `Succeeded` but before allocation re-runs allocation idempotently | `SlotAgreementAllocationSagaTest.php` (80) |
| Agreement `current_allocation_count` is synchronized from the authoritative returned RFC-004 assignment, never incremented locally | `SlotAgreementAllocationSagaTest.php` (80) |
| No monetary reverse-calculation is ever used to infer slot quantity | `AdditionalBusinessSlotAgreementProrationTest.php` (75) |

No existing assertion in any of these four files is authorized to be
removed or weakened by this correction — only strengthened with the
additional assertions above, identical discipline to every prior M4
contract-refinement's own test-strengthening entries (M4 contract §25).

---

## G. Updated allowlist / stop threshold

**This correction changes no implementation path count.** The corrected
implementation allowlist remains exactly **95 paths**; the stop threshold
remains the **96th** required path — every implementation change §A–§F
authorize lands inside a path the merged M4 contract already allowlisted:

- `database/migrations/{impl_date}_create_additional_business_slot_renewal_charges_table.php`
  (§25 item 3) — §B's one new column.
- `app/Models/AdditionalBusinessSlotRenewalCharge.php` (§25 item 22) —
  §B's cast/fillable widening.
- `app/Library/Usage/UsageBillingCheckoutManager.php` (§25 item 41) —
  §A/§C/§D/§E's new methods and internal logic; item 41's own method count
  is amended from thirteen to **fifteen**.
- `app/Jobs/Usage/ProcessPaymentProviderEvent.php` (§25 item 46) — §A's
  `slot_renewal_charge` branch completion.
- `WebhookSlotAgreementSubjectRoutingTest.php` (83),
  `SlotAgreementConcurrencyTest.php` (84),
  `SlotAgreementAllocationSagaTest.php` (80),
  `AdditionalBusinessSlotAgreementProrationTest.php` (75),
  `AdditionalBusinessSlotAgreementRepeatedIncreaseTest.php` (76) — §F's
  strengthened coverage.

**If drafting the actual implementation proves a 96th path is genuinely
required** (a case this correction's own bounded review found no evidence
for), the implementation must STOP AND REPORT again rather than expanding
this correction silently — identical discipline to the merged M4
contract's own §29.

---

## H. Required re-verification

The entire M4 contract §27 gate sequence must be run **from the
beginning** — no gate has run yet for M4's own implementation — once the
corrected implementation (§A–§F, resuming on the existing
`agent/rfc-005-m4` branch/worktree) lands:

1. Every test in the (unchanged-count, five-file-strengthened) focused M4
   test groups (§25 items 67–87, 92–93, 95) — zero failures required,
   exact passing/assertion count reported, never assumed.
2. `php artisan test tests/Unit/Usage tests/Feature/Usage` — zero failures
   required, exact count reported.
3. `php artisan test tests/Unit/Entitlement tests/Feature/Entitlement
   tests/Unit/Workspace tests/Feature/Workspace` — zero failures required
   — the cross-RFC seam (§D's own `EntitlementManager` call) requires this
   exact regression, identical reasoning to the merged M4 contract's own
   §27.
4. `php artisan test --stop-on-failure` — must exit `0`.
5. A dedicated re-confirmation of §F's own eleven coverage rows, each
   independently passing, not merely implied by the aggregate counts above.

Before any of the above: `.env.testing`'s `APP_NAME` and any branding
overrides confirmed neutral first; a real `.env` file confirmed present in
the implementation worktree; built frontend assets
(`public/mix-manifest.json` and its referenced files) confirmed present,
restored — never committed — if the worktree lacks them — the identical
three environment-only precautions the merged M4 contract's own §27
already states, unchanged by this correction.

---

## I. Explicit non-scope statement

This correction document authorizes exactly §A–§F above and nothing else.
**Every other M4 invariant and non-goal is preserved unchanged and
restated here by reference** — in particular, this document does **not**:

- perform, alter, or re-scope any Checkout Session, `confirmPaymentIntent()`,
  or webhook-amount-normalization work (M4 contract §15a/§15b, unchanged);
- alter the payment-instrument reconciliation seam,
  `PaymentInstrumentManager::syncWorkspaceCheckoutPaymentMethod()` (M4
  contract §15c, unchanged);
- alter the price × ratio quote/renewal formula, the frozen-snapshot
  discipline, or catalog-pricing boundary (M4 contract §9, unchanged);
- alter the pre-lapse dunning cap (1 initial + 2 retries = 3 total), the
  attempt-ordinal algorithm, or the post-lapse manual-recovery mechanism
  for `scheduled_renewal`/owner-or-admin-retry charges (M4 contract
  §13/§23, unchanged) — §A's shared renewal-result-application routine
  reuses this existing mechanism for `scheduled_renewal`/retry outcomes
  without modifying it;
- add, seed, or invent any `addon_key`, display name, price, currency, or
  fulfillment product — `business_usage_addon_catalog` remains zero rows
  (M4 contract §10, unchanged);
- add an executable refund trigger, job, gateway method, or manager
  method reaching `refund_pending`/`refunded` — these remain reserved
  schema states only (M4 contract §8a, unchanged);
- widen administrator authority, add a broad charge-origination
  capability, or alter the `assertPlatformAdministrator()` helper (M4
  contract §14, unchanged);
- change any scheduler interval (`InitiateSlotAgreementRenewal`/
  `FinalizeSlotAgreementCancellation` every 5 minutes,
  `ReconcileSlotAgreementAllocation` hourly — M4 contract §22, unchanged);
- modify any RFC-004 file, repository, or table — §D's own
  `EntitlementManager::allocateAdditionalBusinessSlotsFromVerifiedPayment()`
  call is the sole RFC-004-facing effect, identical boundary to M4
  contract §8, unaltered;
- implement M5 (metered feature classification) or M6 (conformance/tag)
  work of any kind;
- extend `maximum_correction_rounds` beyond the 2 the M4 contract §0
  already set — this document consumes round 1 of that existing budget, it
  does not grant a new one.

---

## J. Contract self-audit

1. Both structural gaps are addressed by name, with exact required
   correction detail for each (§A, §B/§C/§D/§E). ✓
2. Correction A's two new manager methods and shared internal routine are
   exact-named (or exact-equivalent), with `ProcessPaymentProviderEvent`'s
   own required behavior stated precisely — validate first, never mutate
   directly, dispatch to exactly the right manager method (§A). ✓
3. The replay-hole requirement (already-`Succeeded` charge still
   idempotently completes allocation) is stated explicitly for both the
   webhook path and every synchronous caller, mirroring the
   already-contracted `AddonPurchase` precedent (§A, §E). ✓
4. Correction B adds exactly one nullable column with an exact, locked
   nullability rule by `charge_kind` — no other schema change (§B). ✓
5. Correction C locks the exact increase algorithm against the agreement's
   own `target_allocation_count` (never the stale `current_allocation_count`),
   with a worked two-increases-composing example and the exact
   transaction-then-provider-call ordering (§C). ✓
6. Correction D names every one of `performVerifiedRenewalChargeAllocation()`'s
   required checks, exact parameter provenance (workspace, requesting
   customer, idempotency key, provider reference), the locked idempotency-
   key namespace with its own length-validity confirmation, and the
   synchronize-not-increment `current_allocation_count` rule (§D). ✓
7. Correction E states the exact, distinct success/failure behavior for
   `scheduled_renewal` vs. `mid_period_increase`, and the exact recovery
   posture when allocation fails after payment succeeds — no rollback, no
   invented refund (§E). ✓
8. Correction F maps every required coverage item onto an already-
   authorized test file by name — no new test path (§F). ✓
9. The allowlist count (95) and stop threshold (96th) are confirmed
   unchanged, with every touched path traced to an already-allowlisted
   item (§G). ✓
10. Re-verification requires the entire §27 gate sequence run from the
    beginning — no gate has run yet for M4, and no count is fabricated by
    this document itself (§H). ✓
11. Every other M4 invariant and non-goal is restated as unchanged,
    matching the merged contract's own section list exactly (§I). ✓
12. No commercial/financial default (add-on key/price/currency, refund
    policy, revocation policy) is invented anywhere in this document. ✓
13. `maximum_correction_rounds: 2` is preserved, not extended; this is
    explicitly recorded as round 1 of that existing budget (§0/§I). ✓
14. The existing implementation worktree (`agent/rfc-005-m4`, 41 paths,
    unstaged, uncommitted) is confirmed untouched by this document (§0). ✓
15. This document itself changes exactly one file, verified mechanically
    before commit (§K). ✓

---

## K. Verification and publication (this document only)

Performed, in order, before commit:

1. `git status --short` — exactly one untracked path,
   `docs/automation/RFC-005-M4-CORRECTION-1.md`, nothing else, nothing
   staged.
2. Confirm, via a separate `git status --short` inside
   `../rfc-005-m4-impl-worktree`, that the existing implementation branch's
   41 paths remain exactly as left — untouched by this document's own
   drafting.
3. `git diff --check` — clean.
4. Stage the one file by its exact path only
   (`git add docs/automation/RFC-005-M4-CORRECTION-1.md`), never
   `git add -A`/`.`.
5. Commit exactly: `docs: authorize RFC-005 Milestone 4 correction round 1`.
6. Push normally to `origin chore/rfc-005-m4-correction-1`. No force push.
   Do not push `main`.
7. Open a Draft PR into `main` if tooling permits; otherwise report the
   exact GitHub comparison URL.
8. **Do not merge. Do not resume the M4 implementation this document
   corrects.** Both require this document to be merged first; applying
   §A–§F against the still-uncommitted implementation worktree remains its
   own separate, later, explicitly bounded action — not this commit.
9. PHP/JS tests are not required for this one-file docs-only change and
   are not run — reported honestly as not run, no count fabricated.

---

*End of RFC-005 Milestone 4 — Correction Round 1. This document authorizes
drafting itself only. The six corrections it specifies (§A–§F) require
their own separate, later, explicitly bounded implementation commit against
the still-uncommitted M4 implementation worktree (`agent/rfc-005-m4`), after
which the full §27 gate set must be run for the first time in full (§H).
The M4 contract's own merged document (PR #103) is unmoved and unmodified
by this document except for the exact §11/§13/§15/§18/§21/§25/§27
amendments stated above.*
