# Implementation Contract 03 — Account Lifecycle Grace/Locked

**Status:** Planning contract only. Does not authorize implementation.

## 1. Objective

Extend `CustomerAccountAccessResolver` (the sole consumer-facing account
access authority, kept strictly **read-only**) to derive the canonical
Trial → Active → Grace → Locked → Inactive lifecycle from
`WorkspacePlanAssignment`'s existing base `status` column plus new
nullable lifecycle timestamps — never a second resolver, never
redefining `Suspended` to mean Grace or Locked (Addendum §7). **This
contract also owns the durable writers that enter/exit those states**
(§4/§6) — it does not merely teach the resolver to read timestamps
someone else is assumed to write; it defines exactly who writes them,
mechanically grounded in this codebase's own existing authority pattern
for this table (§3), so this contract is implementable end-to-end from
its own prompt without a second lifecycle contract.

## 2. Governing authority

- Addendum §7 (the locked representation choice: base status + timestamps,
  one authority).
- Blueprint §27 (product lifecycle behavior: 3-day Grace, immediate unlock
  on payment, six-month Inactive recovery).
- Roadmap Slice 4.

## 3. Current repository reality

**`app/Library/Entitlement/CustomerAccountAccessResolver.php`** (full file
read, 180 lines): `resolve(?Workspace)`, `resolveAmbiguous(array
$workspaces)`, `resolveForContext(CustomerContext)`. `resolve()` delegates
the status read to `EntitlementManager::getWorkspaceEntitlementSummary()`
(never queries `workspace_plan_assignments` directly — this is the one rule
every new branch below must keep). Returns `usable()` immediately when
`! $summary->isAssigned` (no plan assignment row at all yet). Otherwise a
`match` on `WorkspacePlanAssignmentStatus`: `Active → usable()`, `Inactive
→ LockedInactive` decision, `Suspended → LockedSuspended` decision — **no
`default` arm**, so this `match` is currently exhaustive over exactly three
cases and will hard-fail (uncaught `\UnhandledMatchError`) the moment a
fourth status value is ever introduced to the enum, which is exactly why
this contract adds *timestamps*, not new enum cases, matching the
Addendum's own instruction.

**`app/Enums/Entitlement/WorkspacePlanAssignmentStatus.php`**: `Active |
Inactive | Suspended` — confirmed unchanged, three cases only.

**`app/Enums/Entitlement/CustomerAccountAccessState.php`**: `Usable |
LockedInactive | LockedSuspended`, with `isLocked(): bool { return $this
!== self::Usable; }`. This enum **is** extended by this slice (two new
cases — see §5) since it represents the *externally visible* decision
shape, distinct from the internal `WorkspacePlanAssignmentStatus` DB
column the Addendum says stays untouched.

**`app/Library/Entitlement/CustomerAccountAccessDecision.php`**: readonly
DTO — `state`, `reason` (machine-readable, reusing `EntitlementManager::decide()`'s
own vocabulary, e.g. `'plan_inactive'`, `'plan_suspended'`), `heading`,
`message`, `recoveryRouteName`, `recoveryLabel`. `usable()` static factory.
This shape does not need to change structurally — new decisions for Grace/
Locked reuse the same five optional fields, just with new `reason` strings
(`'plan_grace'`, `'plan_locked'`) and copy.

**Five real consumers, confirmed via `git grep`:**
- `app/Http/Controllers/Customer/AccountLockedController.php` — renders the
  locked screen from a decision.
- `app/Http/Middleware/CustomerAccountAccessGate.php` — web middleware,
  calls `resolveDecisionForRequest()`-style logic built on `resolve()`/
  `resolveForContext()` (per this session's own PR #302 work).
- `app/Http/Middleware/CustomerAccountAccessApiGate.php` — `/api/v3`
  middleware, delegates to the Guard.
- `app/Library/Entitlement/CustomerAccountAccessGuard.php` — the shared
  `/api/http` seam (`decisionForBusiness()`, `decisionForActor()`,
  `jsonError()`, `ambiguousJsonError()`, `mismatchJsonError()`), added this
  session in PR #302 Correction 3.
- `app/Enums/Entitlement/CustomerAccountAccessState.php` — self-reference
  only (the `isLocked()` method), not a functional consumer.

**No `trial_ends_at` (or equivalent) column exists on
`workspace_plan_assignments`** (confirmed — the create-table migration has
no such column, and neither does any later migration touching this table).
`EntitlementManager` writes `status = WorkspacePlanAssignmentStatus::Active`
directly when a plan is first assigned (lines ~1089, ~1133) — there is no
intermediate "Trialing" write. The only "trial" concept that currently
exists anywhere in the codebase lives in the **legacy** `app/Models/
Subscription.php` (Laravel Cashier-based, `onTrial()`-style methods at
lines ~330/~350) — a **different, pre-RFC-003/004 billing system**,
decoupled from `WorkspacePlanAssignment` entirely. This is a genuine,
mechanically-confirmed gap, not an oversight of this contract: **Trial, as
a state of the new access-lifecycle authority, does not exist in code
today in any form other than "no assignment row yet" (`! isAssigned`,
already treated as usable).**

**Full lifecycle-writer inventory (this remediation's required mechanical
trace — every real code path that controls the Workspace software
subscription lifecycle, confirmed by direct read, not assumed):**

- **`EntitlementManager::assignFirstPlan(...)`** (line 1039, full
  signature and body already read above) — the canonical initial-
  assignment writer. **Called from exactly one production site:**
  `app/Http/Controllers/Admin/WorkspaceEntitlementController.php:55`
  (`assignPlan()`), gated by `$this->authorize('manage workspace plans')`
  — a **platform-administrator-only, manual** action. No organic
  customer-signup call site exists anywhere in `app/` (confirmed by
  `git grep "assignFirstPlan(" -- app`, which returns only the method
  definition and this one admin controller). 180 further occurrences
  exist, all in `tests/` (test fixtures).
- **`EntitlementManager::changePlanStatus(Workspace $workspace,
  WorkspacePlanAssignmentStatus $status, int $actorUserId, string
  $reason): WorkspacePlanAssignment`** (line 1252, full body read) — the
  **only** existing writer of `workspace_plan_assignments.status`
  post-assignment. Locks the Workspace row, calls
  `assertPlatformAdministrator($actorUserId)` (line 1980, full body
  read: a direct `User.is_admin` check — the same broad flag identified
  elsewhere, but here it is the **existing, established** authority for
  *every* mutation on this table, not a new widening this remediation
  introduces), no-ops if the status is unchanged, otherwise writes the
  new status and a `workspace_entitlement_transitions` row
  (`WorkspaceEntitlementTransitionType::PlanStatusChanged`). **Called
  from exactly one production site**, the same admin controller
  (`WorkspaceEntitlementController.php:104`) — manual only, no automatic
  caller anywhere.
- **`app/Http/Controllers/StripeWebhookController.php`** (full file
  grepped for entitlement references) — **contains zero references** to
  `EntitlementManager`, `WorkspacePlanAssignment`, or `changePlanStatus`.
  The current Stripe webhook handler is entirely legacy-scoped; it does
  not touch the new RFC-004 entitlement layer at all.
- **`app/Console/Commands/CheckSubscription.php`** (`subscription:check`,
  scheduled hourly per `app/Console/Kernel.php:87`, full 56-line file
  read) — operates **exclusively** on the legacy `Subscription`/
  `SubscriptionLog` models (`Subscription::whereNull('end_at')->where
  ('current_period_ends_at', '<', ...)->cancelNow()`). **Zero references**
  to `EntitlementManager` or `WorkspacePlanAssignment`.
- **`database/migrations/..._create_workspace_plan_assignments_table.php`**
  (re-confirmed): no Stripe/payment-provider linkage column exists on
  this table at all (no `stripe_subscription_id`, no equivalent) — there
  is structurally no data on this table a scheduled job could use to
  detect "a recurring payment failed," only what the table itself
  already tracks (`status`, and now `trial_ends_at`/`grace_started_at`/
  `locked_at`).
- **`app/Jobs/Usage/ReconcileSlotAgreementAllocation.php`** (full file
  read) — the closest existing precedent for a **scheduled job writing a
  commercially-significant, actor-attributed row with no human actor
  present**. Its own docblock states the pattern exactly: calls
  `performVerifiedAllocation()` "with both administratorActorUserId and
  reason left null — no system-actor or fake-administrator id of any
  kind." Confirmed the underlying table
  (`workspace_entitlement_transitions.actor_user_id`, migration read in
  full) is `nullable()`, with a **second migration** explicitly adding
  `requesting_customer_user_id` specifically to separate a real
  requester from "the (always-null) system/payment actor" — this is an
  established, precedented pattern in this exact table family, not
  something this contract invents.

**Conclusion the design below is built on:** every existing mutation of
`workspace_plan_assignments` — assignment and status change alike — is
**100% platform-administrator-manual** today; no automatic/webhook/
scheduled trigger exists for the new entitlement layer at all. The one
piece of data a scheduled job *can* safely act on without any payment-
provider integration is `trial_ends_at` (this contract's own new
column) and elapsed Grace time (`grace_started_at`) — both purely
time-based, computable from data this table already owns. Detecting an
actual recurring-payment failure or a successful renewal payment
requires a real payment-provider signal this codebase does not yet wire
to the new entitlement layer (confirmed above) — that is a distinct,
separate payment-provider integration concern (money lane A), not a gap
in *this* contract's lifecycle-authority design; per §4/§6 below, this
contract makes renewal failure and payment recovery **fully writable and
testable today** through the same admin-actor-authorized call path every
other mutation on this table already uses — not a deferred stub.

## 4. Delta from current state to target

**Changes — reader side (unchanged from the prior draft):** three new
nullable timestamp columns on `workspace_plan_assignments`
(`trial_ends_at`, `grace_started_at`, `locked_at` — final, per §5); one
new `CustomerAccountAccessState` case (`Locked`; Trial and Grace are both
non-blocking DTO hints, not new states); `CustomerAccountAccessResolver::
resolve()`'s internal branching extended per §5's canonical truth table
(still the same public method signatures, still read-only).

**Changes — writer side (this remediation's required addition, §3's
inventory operationalized):**
- `EntitlementManager::assignFirstPlan()` gains one new **optional,
  trailing** parameter, `?\Carbon\CarbonInterface $trialEndsAt = null`
  — backward-compatible by construction (§5 confirms none of the 181
  existing call sites need to change).
- Three new `EntitlementManager` methods, following the exact same
  method shape (`Workspace $workspace`, lock the row, transactional,
  write a `workspace_entitlement_transitions` row, dispatch an event) as
  the existing `assignFirstPlan()`/`changePlanStatus()`:
  - `enterGracePeriod(Workspace $workspace, ?int $actorUserId = null, ?string $reason = null): WorkspacePlanAssignment`
  - `lockForNonPayment(Workspace $workspace, ?int $actorUserId = null, ?string $reason = null): WorkspacePlanAssignment`
  - `recoverAccess(Workspace $workspace, ?int $actorUserId = null, ?string $reason = null): WorkspacePlanAssignment`

  Each accepts **nullable** actor/reason, mirroring the exact,
  already-precedented `ReconcileSlotAgreementAllocation`/
  `performVerifiedAllocation()` convention (§3): a non-null `$actorUserId`
  is asserted a platform administrator (reusing the existing
  `assertPlatformAdministrator()`, unchanged); a `null` actor is the
  trusted system/scheduled-job path, exactly like the existing
  slot-allocation job — never a fake sentinel ID.
- One new scheduled command,
  `app/Console/Commands/AdvanceWorkspaceAccountLifecycle.php` (§6/§12),
  performing the two purely time-based sweeps this table's own data can
  support without any payment-provider integration: trial-expired-
  without-conversion → `enterGracePeriod()`; Grace's 3 days elapsed →
  `lockForNonPayment()`.
- `WorkspaceEntitlementTransitionType` gains three new cases:
  `GraceStarted`, `AccountLocked`, `AccessRestored` (§5/§10).
- Three new events in `app/Events/Entitlement/` (matching that
  directory's existing naming convention exactly): `WorkspaceEnteredGracePeriod`,
  `WorkspaceLocked`, `WorkspaceAccessRestored`.

**Explicitly does NOT change:** the `WorkspacePlanAssignmentStatus` enum
itself (stays 3 cases, per Addendum §7); the legacy
`Subscription`/Cashier trial mechanism (read by nothing this slice adds —
§5 point 5); `changePlanStatus()` itself (reused unchanged for the
Locked→Inactive transition, per §6 case F); `assertPlatformAdministrator()`
(reused unchanged); `StripeWebhookController`/`CheckSubscription` (neither
is touched — a real payment-provider integration wiring either of them to
these new methods is explicitly a separate, later concern, §3); any of
the five read-side consumers' own method *signatures* (`resolve()` still
takes `?Workspace`, still returns `CustomerAccountAccessDecision`).
`CustomerAccountAccessResolver` remains **read-only end to end** — every
write in this section lives in `EntitlementManager`, never the resolver.

## 5. Data model contract

**`workspace_plan_assignments` — new columns (final, canonical — no open
option remains):**

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `trial_ends_at` | `timestamp` | Yes | `NULL` | **Meaning: current outstanding trial expiry only — never a historical marker.** Set at first-plan-assignment time by `EntitlementManager::assignFirstPlan()`, extended with one new **optional, trailing** parameter: `assignFirstPlan(Workspace $workspace, WorkspacePlanTier $tier, int $actorUserId, string $reason, bool $isComplimentary = false, int $additionalBusinessSlots = 0, ?\Carbon\CarbonInterface $trialEndsAt = null)`. **Backward compatibility, mechanically proven, not assumed:** `git grep -c "assignFirstPlan(" -- app tests` returns 181 total occurrences (1 production call site, `WorkspaceEntitlementController::assignPlan()`; 180 test call sites across ~20 files) — every one uses positional arguments matching the *current* six-parameter signature, so a seventh, optional, trailing parameter requires **zero** modification to any existing call site; only new tests exercising the trial case are added (§13). Set only when the signup/plan-selection flow grants a trial (Blueprint §6: "Plan selection → Payment method + trial start"). **`NULL` means "not currently in an outstanding trial"** — either no trial was ever granted, or a trial was granted and has since been **explicitly cleared** by `recoverAccess()` (§5 below) on successful conversion — **never** left as a past timestamp once resolved. A non-`NULL`, future value means actively trialing; a non-`NULL`, past value means a trial has expired but has not yet been processed by either the scheduled sweep (§6/§7) or a conversion — this is a real, brief, transitional window, not a bug, and is read identically to the future case (§5's truth table). |
| `grace_started_at` | `timestamp` | Yes | `NULL` | Set when a renewal failure first occurs while `status = Active` — **including** a trial ending without a successful conversion, which reuses this exact same field rather than a separate trial-expiry mechanism (see the canonical truth table below). Cleared (`NULL`) on successful payment. |
| `locked_at` | `timestamp` | Yes | `NULL` | Set when Grace's 3-day window elapses without payment. Cleared on successful payment (a payment resolves straight back to `Active`/`NULL`/`NULL`, skipping back through Grace — matches Blueprint §27's "immediate unlock on confirmed payment"). |

**Canonical V1 decision (no Option A/B, no TBD, no human-confirmation gate
remains — this is final):**
1. **`trial_ends_at` is added**, on the *canonical* `workspace_plan_assignments`
   row — not a new table, not a new resolver input beyond what §5's
   `resolve()` already reads.
2. **`NULL` on `trial_ends_at` means "not trialing."** It is never used to
   mean, or read as meaning, Trial by its mere presence-or-absence of a
   *row* — that conflation (the original draft's "no assignment row =
   Trial, because it's currently usable") is explicitly wrong and is
   corrected in the truth table below: **no assignment row is its own,
   separate, non-Trial state** ("Unassigned / Pre-Plan-Selection" —
   already `usable()` today, for an entirely different reason: onboarding
   hasn't reached plan selection yet, not because a trial is running).
3. **The signup/plan-assignment flow explicitly sets `trial_ends_at`** at
   `assignFirstPlan()` time, only when a trial is actually granted (some
   plans/paths may skip a trial entirely — that is a product/commercial
   configuration decision outside this contract's scope, not something
   this contract invents a rule for).
4. **On confirmed successful trial conversion — whether before natural
   expiry or after the Workspace has already progressed into Grace/Locked
   — `trial_ends_at` MUST be cleared to `NULL` in the same write that
   confirms the conversion.** This is the one rule this remediation adds:
   an earlier draft left `trial_ends_at` "untouched" after a successful
   Grace/Locked recovery, reasoning it "already reads as in the past" and
   therefore harmless — that reasoning was mechanically wrong. A past,
   uncleared `trial_ends_at` continues to satisfy the scheduled
   trial-expiry sweep's predicate (§6/§7) indefinitely, so a converted,
   paying Workspace whose `grace_started_at` is later cleared (by this
   same conversion) would become eligible, on the *next* sweep run, to be
   incorrectly moved back into Grace for a trial that already, correctly,
   ended in payment. Clearing `trial_ends_at` to `NULL` at conversion time
   is what makes that Workspace **permanently ineligible** for the
   trial-expiry sweep, exactly as a Workspace that was never on a trial
   already is — this is the single unambiguous rule, not a special case.
5. **The resolver derives Trial as Usable + trial metadata** — Trial is
   not a new blocking state, exactly like Grace; it surfaces via the same
   kind of optional, non-blocking `CustomerAccountAccessDecision` hint
   (§5 below), never a new `CustomerAccountAccessState` case.
6. **The legacy `Subscription`/Cashier trial mechanism (`onTrial()`-style
   methods, `app/Models/Subscription.php` lines ~330/~350) is explicitly
   NOT a second access authority.** It continues to govern whatever it
   already governs on the legacy Stripe-subscription/billing side (money
   lane A mechanics), but `CustomerAccountAccessResolver` **never reads
   it** for the access-usability decision — `trial_ends_at` on
   `workspace_plan_assignments` is the **sole** source of truth for "is
   this Workspace currently in a software-access trial," fully decoupled
   from whatever the legacy Cashier trial concept separately tracks for
   billing purposes. This is the concrete application of Addendum §7's
   "no second lifecycle authority" rule to the specific trial question the
   original draft left open.

**`CustomerAccountAccessState` — final, canonical shape, exactly one new
case (`Locked`):**
```php
enum CustomerAccountAccessState: string
{
    case Usable = 'usable';
    case LockedInactive = 'locked_inactive';
    case LockedSuspended = 'locked_suspended';
    case Locked = 'locked';   // NEW -- the only new case this contract adds
    public function isLocked(): bool { ... }
}
```
**Grace itself is deliberately NOT a locked state and gets no enum case at
all** — Blueprint §27: Grace retains full access with a billing prompt. A
dedicated blocking case for Grace would be mis-named (Grace is not
blocking) and is not part of this contract's final design. Grace stays
`Usable`, with the `CustomerAccountAccessDecision`
DTO carrying an *additional, optional, non-blocking* hint (a new nullable
`$graceEndsAt` field, or reuse of `reason = 'plan_grace'` with `isLocked()`
still `false`) so consumers that want to show a billing-prompt banner can,
without every existing "is this usable" check having to special-case a new
enum value. **Corrected data model:** only **one** new blocking case is
needed — `Locked` (`'locked'`) — for the post-Grace, pre-Inactive state;
`CustomerAccountAccessDecision` gains an optional `graceEndsAt` (or
`isInGracePeriod`) field for the non-blocking Grace signal, not a new
`CustomerAccountAccessState` case.

**Final canonical truth table — every `(assignment-existence, status,
trial_ends_at, grace_started_at, locked_at)` combination, covering all six
named lifecycle labels (Trial / Active / Grace / Locked / Inactive /
Suspended) with zero remaining TBD (§4 deep-dive requirement, resolved):**

| Assignment row? | `status` | `trial_ends_at` | `grace_started_at` | `locked_at` | Effective lifecycle | `CustomerAccountAccessState` | `isLocked()` |
|---|---|---|---|---|---|---|---|
| **No row at all** | — | — | — | — | **Unassigned / Pre-Plan-Selection** — explicitly *not* Trial (§5's canonical decision, point 2) | `Usable` | No |
| Yes | `Active` | set (non-`NULL`) — future **or** past-but-not-yet-processed | `NULL` | `NULL` | **Trial** — a past-but-uncleared value is the real, brief window between natural expiry and either the scheduled sweep (§6/§7) or a conversion; it reads identically to an actively-running trial until one of those occurs | `Usable` (with trial metadata) | No |
| Yes | `Active` | `NULL` | `NULL` | `NULL` | **Active** — this is the **only** state that produces `NULL`: never trialed, **or** a trial that was explicitly cleared by `recoverAccess()` on successful conversion (§5 point 4) — a past, uncleared timestamp is never read as "Active," only `NULL` is | `Usable` | No |
| Yes | `Active` | irrelevant (past or null) | set, within 3 days | `NULL` | **Grace** — reached either from an ordinary renewal failure, or from a trial ending without conversion (both set `grace_started_at` the same way, §5 point 1's note) | `Usable` (with `graceEndsAt` hint) | No |
| Yes | `Active` | irrelevant | set, **elapsed** 3+ days, `locked_at` still `NULL` | — | **Locked** (defensive/transitional — resolver computes this from elapsed time even if a scheduled job hasn't yet written `locked_at`, so a missed job run never silently leaves a delinquent account Usable) | `Locked` | Yes |
| Yes | `Active` | irrelevant | any | set (non-`NULL`) | **Locked** | `Locked` | Yes |
| Yes | `Inactive` | irrelevant | any | any | **Inactive** (unchanged meaning — terminal state after the 6-month recoverable window, or a direct legacy-path deactivation that never went through Grace/Locked) | `LockedInactive` (unchanged) | Yes |
| Yes | `Suspended` | irrelevant | irrelevant (administrative, always wins) | irrelevant | **Suspended** | `LockedSuspended` (unchanged) | Yes |

**Trial-expiry-without-conversion is not a seventh state or a parallel
mechanism** — it is simply the ordinary Grace-entry trigger firing for a
different underlying reason (trial ended vs. renewal failed); both funnel
through the same `grace_started_at` column and the same downstream
Grace→Locked→Inactive progression, matching the "one authority" principle
this contract exists to uphold.

**`Suspended` always wins over Grace/Locked timestamps** — the `match`
checks `Suspended` first regardless of what `grace_started_at`/`locked_at`
hold, so an administrative suspension can never be masked by stale
grace/lock timestamps left over from before the suspension.

**Writer methods — the durable transitions themselves (§4's required
addition, mechanically designed from §3's precedent, not deferred):**

```php
public function enterGracePeriod(Workspace $workspace, ?int $actorUserId = null, ?string $reason = null): WorkspacePlanAssignment;
public function lockForNonPayment(Workspace $workspace, ?int $actorUserId = null, ?string $reason = null): WorkspacePlanAssignment;
public function recoverAccess(Workspace $workspace, ?int $actorUserId = null, ?string $reason = null): WorkspacePlanAssignment;
```

Each: locks the Workspace row (`findForUpdate`, matching every existing
`EntitlementManager` mutation); asserts `status === Active` (per the
truth table, Grace/Locked only ever apply to an otherwise-Active
assignment — a `Suspended` or already-`Inactive` Workspace is not a valid
target, and each method throws if called against one); is idempotent
against its own already-applied state (calling `enterGracePeriod()` on an
assignment that already has `grace_started_at` set is a no-op returning
the existing row unchanged, mirroring `changePlanStatus()`'s own
same-status no-op precedent) — never a duplicate transition row for the
same fact. `recoverAccess()` is idempotent against **all three** columns
already being `NULL` (a true no-op, e.g. calling it on an already-clean
Active Workspace). It sets `trial_ends_at`, `grace_started_at`, **and**
`locked_at` all to `NULL` in one write (§5 point 4; Blueprint §27's
"immediate unlock," all three cleared together, never a partial clear) —
this is the single method covering both an early trial conversion and a
Grace/Locked recovery (§6 case E), never two separate write behaviors.

**`WorkspaceEntitlementTransitionType` — three new cases**, added to the
existing nine-case enum (§3), following its own established naming
convention exactly:
```php
case GraceStarted = 'grace_started';
case AccountLocked = 'account_locked';
case AccessRestored = 'access_restored';
```

## 6. Authority / security contract

`CustomerAccountAccessResolver` remains strictly **read-only** — every
write below lives in `EntitlementManager` (§4/§5), matching its own
docblock ("READS ONLY... never mutates anything") unchanged. Every
lifecycle case A–F this contract must support (per the remediation's own
list) now has a named, implementable writer:

| Case | Trigger | Writer | Actor |
|---|---|---|---|
| **A. Trial granted** | Signup/plan-selection flow grants a trial | `assignFirstPlan(..., trialEndsAt: ...)` (§4/§5) | Whoever legitimately calls `assignFirstPlan()` today — currently platform-administrator-only (§3); an organic self-service signup call site, if/when Blueprint §6's own onboarding flow is built, calls the same method the same way — no new authority model invented here. |
| **B. Trial expires without conversion** | Time-based, exact predicate (§7): `status = Active` **and** `trial_ends_at IS NOT NULL` **and** `trial_ends_at <= now` **and** `grace_started_at IS NULL` **and** `locked_at IS NULL` | `enterGracePeriod(Workspace, actorUserId: null, reason: 'Trial ended without conversion')` | **System** — called by the new `AdvanceWorkspaceAccountLifecycle` scheduled command (§4/§7/§12), with `actorUserId = null` per §3's `ReconcileSlotAgreementAllocation` precedent. This is the one transition this table's own data can detect automatically without a payment-provider signal. **`trial_ends_at IS NOT NULL` is the exact condition that makes this sweep permanently skip an already-converted Workspace** (§5 point 4) — the predicate is never satisfied again once conversion clears the column. |
| **C. Renewal/payment failure** | An actual recurring-payment failure (requires a real payment-provider signal this codebase's new entitlement layer does not yet receive, §3) | `enterGracePeriod(Workspace, actorUserId: <platform admin>, reason: ...)` | **Platform administrator**, via `assertPlatformAdministrator()` (existing, unchanged) — the same manual-action model every other mutation on this table already uses (§3). Wiring a real payment-provider webhook to call this method automatically is a distinct, separate integration task (money lane A), not a gap in this contract's own authority design: the mutation itself is fully defined, authorized, and callable today. |
| **D. Grace's 3 days elapse** | Time-based: `grace_started_at` is more than 3 days old and `locked_at` is still `NULL` | `lockForNonPayment(Workspace, actorUserId: null, reason: 'Grace period elapsed without payment')` | **System** — the same scheduled command's second sweep (§4/§7/§12). The resolver's own defensive elapsed-time derivation (§5 truth table) remains a second, independent safety net for a missed job run — never the *only* mechanism, per this remediation's own instruction that a durable writer must still exist. |
| **E. Successful payment/conversion — during Grace or Locked, or directly from an active Trial before expiry** | A real payment success or trial-conversion signal (same provider-integration caveat as case C) | `recoverAccess(Workspace, actorUserId: <platform admin>, reason: ...)` — the **single canonical writer** for every "this Workspace is now confirmed, clean Active" fact, not two separate cases | **Platform administrator** today, same reasoning as case C. **Clears all three columns together, atomically, in one write: `trial_ends_at = NULL`, `grace_started_at = NULL`, `locked_at = NULL`** — corrected in this remediation from an earlier draft that left `trial_ends_at` "untouched," which was mechanically wrong (a past, uncleared value would satisfy case B's predicate again on the very next scheduled sweep, since `grace_started_at` is now `NULL` too — this is exactly the contradiction this remediation exists to close). This single method now legitimately covers **both** an early conversion straight out of an active Trial (where `grace_started_at`/`locked_at` were already `NULL`, so clearing them is a harmless no-op) **and** a recovery from Grace/Locked — there is no second method and no second writer behavior. |
| **F. Transition to Inactive** | Per this remediation's own instruction: "use the canonical base status authority" | `changePlanStatus(Workspace, WorkspacePlanAssignmentStatus::Inactive, actorUserId, reason)` — **existing, unchanged** | **Platform administrator**, exactly as this method already requires today (§3) — no new method needed for this case. |

**No customer, staff, or Agency action reads or writes these columns
directly** — every consumer goes through `CustomerAccountAccessResolver`
for reads; every write goes through one of the six named methods above,
never a raw column assignment anywhere else. This is the one authority
contract this slice must not weaken.

## 7. Transaction / concurrency boundary

The resolver itself performs no writes (read-only, confirmed).
`enterGracePeriod()`/`lockForNonPayment()`/`recoverAccess()` each lock the
`workspace_plan_assignments` row via the Workspace's own `findForUpdate()`
(matching `changePlanStatus()`'s exact existing pattern) before reading or
writing its current state, inside one `DB::transaction()` per call — no
new lock-ordering concern beyond what every other single-Workspace
`EntitlementManager` mutation already has.

`AdvanceWorkspaceAccountLifecycle` (the new scheduled command, §4/§12)
processes candidate Workspaces one at a time, each through its own
`enterGracePeriod()`/`lockForNonPayment()` call and therefore its own
transaction — a failure partway through the sweep affects only the
Workspace being processed when it occurred, not the whole run (mirroring
`ReconcileSlotAgreementAllocation`'s own per-agreement loop, §3). Each
write method's own idempotency guard (§5: a no-op if the target state is
already applied) makes the command safely re-runnable on its own schedule
without double-processing a Workspace an earlier run already advanced.

**The exact, final, two-sweep predicate this command runs — the single
canonical statement of its query logic, matching §6's case B/D rows
exactly:**
```sql
-- Sweep 1: trial expired without conversion -> enterGracePeriod()
SELECT * FROM workspace_plan_assignments
WHERE status = 'active'
  AND trial_ends_at IS NOT NULL
  AND trial_ends_at <= NOW()
  AND grace_started_at IS NULL
  AND locked_at IS NULL;

-- Sweep 2: Grace's 3 days elapsed -> lockForNonPayment()
SELECT * FROM workspace_plan_assignments
WHERE status = 'active'
  AND grace_started_at IS NOT NULL
  AND grace_started_at <= NOW() - INTERVAL 3 DAY
  AND locked_at IS NULL;
```
**Sweep 1's `trial_ends_at IS NOT NULL` clause is the entire fix this
remediation makes executable:** once `recoverAccess()` (§5/§6 case E)
clears `trial_ends_at` to `NULL` on any successful conversion, that row
can never again satisfy Sweep 1 — permanently, not merely until the next
run — closing the exact contradiction this remediation was opened to
resolve. Sweep 1 is not reordered relative to Sweep 2 in a way that
matters: a Workspace can only ever match one of the two (Sweep 1 requires
`grace_started_at IS NULL`; Sweep 2 requires it `IS NOT NULL`), so running
both in the same invocation, in either order, produces no double-processing.

## 8. Migration / backfill

**Policy:** additive nullable columns, no backfill required — every
existing row simply gets `NULL`/`NULL`/`NULL` (`trial_ends_at` included,
per §5's final decision), which the truth table above already correctly
resolves to
"whatever `status` alone already implies" (i.e. behavior for every
existing row is byte-for-byte unchanged until some future renewal event
sets a timestamp for the first time). No preflight/dry-run/stop-condition
machinery is needed — this is the same additive-column shape as
`business_payer_assignments.effective_payment_instrument_id`'s own
precedent (nullable, deferred, safe by construction).

## 9. Backwards compatibility

Every existing `Active`/`Inactive`/`Suspended` decision path is preserved
byte-for-byte (§5's truth table rows for `NULL`/`NULL` timestamps match
today's exact existing behavior). No consumer's *existing* test should
need to change — only new tests are added for the new Grace/Locked paths.

## 10. Events / audit

No new domain event is dispatched by the *resolver* itself (it's
read-only, unchanged). Each of the three new `EntitlementManager` writer
methods (§4/§5/§6) dispatches exactly one event, in
`app/Events/Entitlement/` (matching that directory's own existing
naming convention, not `app/Events/Workspace/`, since this is an
entitlement-layer mutation, not a Workspace-identity one):
`enterGracePeriod()` → `WorkspaceEnteredGracePeriod`;
`lockForNonPayment()` → `WorkspaceLocked`; `recoverAccess()` →
`WorkspaceAccessRestored`. Each carries the Workspace ID, the (nullable)
actor ID, and the reason — matching the existing
`WorkspacePlanStatusChanged`-style event shape in that same directory.
Every write additionally lands a durable
`workspace_entitlement_transitions` row via the three new
`WorkspaceEntitlementTransitionType` cases (§5) — the existing audit
table this whole subsystem already uses, not a new one.

## 11. Billing/provider safety

Not directly applicable to the resolver itself (read-only, no payer/wallet/
provider interaction). Indirectly critical: this is the exact mechanism
Contract 05 (Agency non-payment composition) and Contract 09 (AgencyRebill)
both build on — an incorrect Grace/Locked derivation here propagates
directly into money-movement decisions downstream. Get the truth table in
§5 right; downstream contracts do not re-derive it.

## 12. Exact implementation allowlist

**New files:**
- `database/migrations/2026_09_2x_100005_add_lifecycle_timestamps_to_workspace_plan_assignments_table.php`
- `app/Console/Commands/AdvanceWorkspaceAccountLifecycle.php` — the new scheduled command (§4/§6/§7).
- `app/Events/Entitlement/WorkspaceEnteredGracePeriod.php`
- `app/Events/Entitlement/WorkspaceLocked.php`
- `app/Events/Entitlement/WorkspaceAccessRestored.php`
- `tests/Feature/Entitlement/CustomerAccountAccessResolverGraceLockedTest.php`
- `tests/Feature/Entitlement/EntitlementManagerLifecycleTransitionTest.php` — the new writer methods' own test file.
- `tests/Console/AdvanceWorkspaceAccountLifecycleTest.php`

**Existing files modified:**
- `app/Enums/Entitlement/CustomerAccountAccessState.php` — add `Locked` case.
- `app/Library/Entitlement/CustomerAccountAccessResolver.php` — extend `resolve()`'s branching per §5's truth table (read-only, unchanged principle).
- `app/Library/Entitlement/CustomerAccountAccessDecision.php` — add optional `graceEndsAt`/`isInGracePeriod` field.
- **`app/Library/Entitlement/EntitlementManager.php`** — extend `assignFirstPlan()` with the new optional trailing `$trialEndsAt` parameter (§5, zero call-site impact, mechanically proven); add `enterGracePeriod()`, `lockForNonPayment()`, `recoverAccess()` (§4/§5/§6).
- `app/Enums/Entitlement/WorkspaceEntitlementTransitionType.php` — add the three new cases (§5).
- `app/Console/Kernel.php` — schedule the new `AdvanceWorkspaceAccountLifecycle` command (mirroring `subscription:check`'s own hourly cadence, §3).
- Existing tests for the five consumers listed in §3 — **re-verify, not rewrite**, that every existing Active/Inactive/Suspended test still passes unchanged (§9); add new assertions only for the new Grace/Locked paths where each consumer already has a natural place for them.

**No existing test call site of `assignFirstPlan()` requires modification**
(§5's mechanical proof — the new parameter is optional and trailing).
**No controller/route/middleware file needs a structural change** — every
consumer already calls `resolve()`/`resolveForContext()`/`resolveAmbiguous()`
and receives a `CustomerAccountAccessDecision`; the new `Locked` state
flows through the exact same `isLocked()`/`reason`/`message` surface every
consumer already handles generically. **Flag for implementation-time
verification:** confirm no consumer has its own local `match` on
`CustomerAccountAccessState` that would need a new arm (would be a genuine
Addendum-violating "second authority" if found) — this contract's evidence
pass did not find one, but the five consumer files were not read in full.

## 13. Required tests

`CustomerAccountAccessResolverGraceLockedTest.php`: every row of the §5
truth table, as an isolated resolver-level test — including the merged
Trial row (future **and** past-but-unprocessed both read as Trial) and
the corrected Active row (`NULL` only); `Suspended` wins regardless of
stale Grace/Locked timestamps; the elapsed-time defensive Locked
computation fires correctly even with `locked_at` still `NULL`.

`EntitlementManagerLifecycleTransitionTest.php`: each of
`enterGracePeriod()`/`lockForNonPayment()`/`recoverAccess()` — happy path
with a real platform-administrator actor; happy path with `actorUserId =
null` (system path); idempotency (calling a method whose target state is
already applied is a no-op, no duplicate transition row); each method
rejects a non-`Active` target (`Suspended`/`Inactive`) with the correct
exception; `recoverAccess()` clears **all three** columns
(`trial_ends_at`/`grace_started_at`/`locked_at`) together in one write;
each write lands the correct new `WorkspaceEntitlementTransitionType` row
and dispatches the correct new event.

**`AdvanceWorkspaceAccountLifecycleTest.php` — the five tests this
remediation requires explicitly, each its own test method, each directly
exercising the exact predicate in §7:**
1. **Trial still active** (`trial_ends_at` in the future) — the sweep
   makes zero calls to `enterGracePeriod()` for that Workspace; its
   `trial_ends_at`/`grace_started_at`/`locked_at` are unchanged after the
   command runs.
2. **Trial expires unpaid** (`trial_ends_at` in the past,
   `grace_started_at` still `NULL`) — the sweep calls `enterGracePeriod()`
   exactly **once**; a second command run in the same test does **not**
   call it again (idempotency, proven by asserting the transition-row
   count stays at one and `grace_started_at` is unchanged by the second
   run).
3. **Trial converts successfully before expiry** — call `recoverAccess()`
   directly (simulating a successful conversion) while `trial_ends_at` is
   still in the future; assert `trial_ends_at` becomes `NULL`
   immediately; then advance the clock **past** the original
   `trial_ends_at` value and run the scheduled command; assert
   `enterGracePeriod()` is **never** called for that Workspace, on this
   run or any subsequent run — this is the test that directly proves the
   contradiction this remediation fixes is closed.
4. **Trial expires → Grace → payment succeeds** — drive a Workspace
   through `enterGracePeriod()` (trial-originated), then call
   `recoverAccess()`; assert `trial_ends_at`, `grace_started_at`, and
   `locked_at` are **all** `NULL` immediately after; then run the
   scheduled command again (both sweeps) and assert it makes **zero**
   calls of any kind for that Workspace — proving the same old,
   already-resolved trial can never re-trigger Grace after a full
   recovery, not merely immediately after but on every subsequent run.
5. **Re-running the scheduler is idempotent** — run the full command
   twice in immediate succession against a mixed set of Workspaces
   (some eligible for Sweep 1, some for Sweep 2, some for neither);
   assert the second run produces zero additional writes, zero
   additional transition rows, and zero additional events for every
   Workspace the first run already advanced.

Additional coverage beyond the five required tests: the trial-expired
sweep calls `enterGracePeriod()` for exactly the correct Workspaces and
none other; the Grace-elapsed sweep calls `lockForNonPayment()` for
exactly the correct Workspaces and none other (mirroring the
`ReconcileSlotAgreementAllocation` precedent's own per-item loop, §7).

**End-to-end lifecycle walk** (unchanged from the prior draft, still
required): a single test driving one Workspace through
`assignFirstPlan(trialEndsAt: ...)` → (time-travel) `enterGracePeriod()`
→ `lockForNonPayment()` → `recoverAccess()` → `changePlanStatus(Inactive)`,
asserting `CustomerAccountAccessResolver::resolve()` returns the correct
`CustomerAccountAccessState` at every step, **and** that a scheduled-
command run inserted anywhere after the `recoverAccess()` step makes zero
further writes for that Workspace — this is the test that proves Contract
03 is genuinely implementable end-to-end, including the trial-conversion
correction, not merely that its pieces exist in isolation.

Per-consumer regression (existing test files, re-run not rewritten,
confirming §9): `CustomerAccountAccessGateTest`, `CustomerAccountAccessApiGateTest`,
`CustomerAccountAccessHttpGateTest` (the `/api/http` Guard's own test
file, added this session), `AccountLockedController`'s own test coverage
if it exists as a named file.

## 14. Acceptance criteria

1. Migration adds the new nullable columns cleanly.
2. Every §5 truth-table row has a passing, explicit test.
3. Every existing consumer's Active/Inactive/Suspended test still passes
   unmodified in its assertions (only new tests added).
4. `Suspended` is proven to override stale Grace/Locked timestamps.
5. Every one of cases A–F (§6's table) has a passing test exercising its
   named writer method, including the end-to-end lifecycle walk.
6. The new scheduled command correctly advances exactly the Workspaces
   its own time-based conditions target, and is safely re-runnable.
7. `recoverAccess()` is proven, by test, to clear **all three** lifecycle
   columns (`trial_ends_at`/`grace_started_at`/`locked_at`) in one write —
   never `trial_ends_at` alone left uncleared.
8. All five of §13's explicitly-required trial-conversion tests pass,
   including test 3 (early conversion) and test 4 (Grace recovery),
   each proving the scheduled sweep never re-enters Grace for an
   already-resolved trial on any subsequent run.
9. `git diff --check` clean; diff matches §12's allowlist.

## 15. Non-goals

Does **not** integrate a real payment provider (Stripe or otherwise) to
*automatically* call `enterGracePeriod()`/`recoverAccess()` for cases C/E
(§6) — those methods are fully defined, authorized, and
platform-administrator-callable today; wiring a webhook to call them
automatically is a distinct, separate payment-provider integration task
(money lane A), not a deferred piece of *this* contract's own lifecycle-
authority design. Does not implement Agency non-payment composition
(Contract 05 — this slice is a hard prerequisite for it, not the same
work). Does not modify the legacy `Subscription`/Cashier trial mechanism
itself, only ensures `CustomerAccountAccessResolver` never reads it (§5
point 5). Does not modify `StripeWebhookController` or
`CheckSubscription` (§3) — both remain exactly as they are today.

## 16. Merge prerequisites

None beyond Roadmap Wave 0. Independent of Contracts 01/02 — safe
concurrent.

## 17. Conflict map

| Other contract | Shared file/table | Posture |
|---|---|---|
| Contract 01, 02 | none | Safe concurrent |
| Contract 05 (Slice 5) | reads this slice's derived states as input | **Serialize** — hard dependency |
| Contract 09 (Slice 9, AgencyRebill) | reads effective access (composed via Contract 05) | Serialize, downstream |

## 18. Implementation prompt

```
You are implementing Slice 4 of the V1 architecture migration for the
os-creator1/os-ai repository: account lifecycle Grace/Locked, per docs/
product/implementation-contracts/03-ACCOUNT-LIFECYCLE-GRACE-LOCKED.md.
This contract is end-to-end -- it covers both the read side (the
resolver) and the write side (the durable lifecycle-transition methods
and the scheduled job that drives two of them). Do not treat any part of
it as deferred to a future contract.

Before writing any code:
1. Fetch latest origin/main.
2. Verify the Addendum, Blueprint, and this contract are present (on main
   or its source branch -- if unreachable, STOP and report).
3. Create a fresh branch for this slice only (e.g.
   agent/v1-slice-04-account-lifecycle-grace-locked).
4. Re-read the full contract, especially SS5's canonical truth table and
   SS6's case-by-case writer table -- both are the authoritative
   specification for this slice's logic; there is no open trial-design
   question left (SS5) and no deferred write path left (SS6).
5. Re-verify SS3's evidence against actual current main: confirm
   EntitlementManager::assignFirstPlan()'s current signature,
   changePlanStatus()'s current body, assertPlatformAdministrator()'s
   current body, and WorkspaceEntitlementTransitionType's current case
   list still match; confirm StripeWebhookController and
   CheckSubscription still contain zero references to EntitlementManager/
   WorkspacePlanAssignment (if either has since gained such a reference,
   STOP and report, since it would change this contract's own design
   basis); confirm ReconcileSlotAgreementAllocation still uses the
   null-actor/null-reason convention this contract's new methods mirror.
   If anything differs, STOP and report the contradiction rather than
   guessing.

Implement exactly the scope in this contract: the three new columns
(trial_ends_at, grace_started_at, locked_at), the new Locked state, the
resolver's extended read-only branching per SS5's canonical truth table,
assignFirstPlan()'s new optional trailing trialEndsAt parameter (verify
zero existing call sites need changes, per SS5's mechanical proof -- if
your own git grep finds a different count than 181, STOP and report before
proceeding), the three new EntitlementManager writer methods
(enterGracePeriod/lockForNonPayment/recoverAccess) per SS5/SS6 exactly
(nullable actor/reason, idempotent, Active-only target, correct event +
transition-type per write), and the new AdvanceWorkspaceAccountLifecycle
scheduled command per SS4/SS6/SS7's exact two-sweep SQL predicate (do not
attempt to detect an actual payment-provider event, since no such signal
exists in this codebase yet, per SS3).

**Critical, get this exactly right: `recoverAccess()` MUST clear all
three columns -- `trial_ends_at`, `grace_started_at`, AND `locked_at` --
to NULL in the same write, every time it is called, with no exception for
an early trial conversion.** An earlier draft of this contract left
trial_ends_at unmodified by recovery, which is a real bug this
remediation exists to fix: a past, uncleared trial_ends_at would make an
already-converted, paying Workspace eligible for Sweep 1 again the moment
grace_started_at is cleared. Sweep 1's `trial_ends_at IS NOT NULL` clause
depends entirely on recoverAccess() clearing that column correctly --
verify this with the specific test in SS13 (test 3: convert before
expiry, then advance the clock past the original expiry and run the
scheduler, asserting zero calls) before considering this slice done.

Keep CustomerAccountAccessResolver strictly read-only -- every write
lives in EntitlementManager. Do NOT implement Contract 05's Agency/Client
composition -- that is a separate slice. Do NOT integrate Stripe or any
payment provider -- SS15 explicitly excludes this.

After implementing:
- Run the new focused test files for this slice, including all five of
  SS13's explicitly-required trial-conversion tests (trial still active;
  trial expires unpaid; trial converts before expiry; trial expires ->
  Grace -> payment succeeds; scheduler re-run is idempotent) and the
  end-to-end lifecycle-walk test that drives one Workspace through every
  state this contract defines and asserts the resolver's output at each
  step -- this is the test that proves the contract is genuinely
  implementable end-to-end, not merely that its pieces exist in
  isolation.
- Re-run every existing test file for the five consumers to confirm zero
  behavior change on their existing Active/Inactive/Suspended paths.
- Re-run every existing assignFirstPlan() test file to confirm zero
  assertion changes.
- Run git diff --check.
- Verify the diff touches only the contract's allowlisted files.
- Commit and push.

Do NOT create a pull request yourself if GitHub tooling is unavailable --
ChatGPT will create it through GitHub.

Return a full report: starting/final SHA, exact files changed, exact tests
run and counts, explicit confirmation that recoverAccess() clears all
three lifecycle columns (not just two) proven by test, confirmation the
end-to-end lifecycle-walk and all five trial-conversion tests passed,
confirmation no existing assignFirstPlan() or five-consumer test changed
its assertions, and confirmation CustomerAccountAccessResolver remains
read-only (zero writes anywhere in that file). Do NOT begin or authorize
Contract 05 or any other later slice.
```
