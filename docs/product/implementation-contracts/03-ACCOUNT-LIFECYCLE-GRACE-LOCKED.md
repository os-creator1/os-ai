# Implementation Contract 03 — Account Lifecycle Grace/Locked

**Status:** Planning contract only. Does not authorize implementation.

## 1. Objective

Extend `CustomerAccountAccessResolver` (the sole consumer-facing account
access authority) to derive the canonical Trial → Active → Grace → Locked →
Inactive lifecycle from `WorkspacePlanAssignment`'s existing base `status`
column plus new nullable lifecycle timestamps — never a second resolver,
never redefining `Suspended` to mean Grace or Locked (Addendum §7).

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

## 4. Delta from current state to target

**Changes:** three new nullable timestamp columns on
`workspace_plan_assignments` (`trial_ends_at`, `grace_started_at`,
`locked_at` — final, per §5); one new `CustomerAccountAccessState` case
(`Locked`; Trial and Grace are both non-blocking DTO hints, not new
states); `CustomerAccountAccessResolver::resolve()`'s internal branching
extended per §5's canonical truth table (still the same public method
signatures); `EntitlementManager::assignFirstPlan()` extended to accept
and set `trial_ends_at` when a trial is granted (confirmed exact write
site, §5); new decision copy for the new states.

**Explicitly does NOT change:** the `WorkspacePlanAssignmentStatus` enum
itself (stays 3 cases, per Addendum §7); the legacy
`Subscription`/Cashier trial mechanism (read by nothing this slice adds —
§5 point 5); any of the five consumers' own method *signatures* (`resolve()`
still takes `?Workspace`, still returns `CustomerAccountAccessDecision` —
callers do not need to change merely because the resolver's internal logic
grew richer, though each caller's *tests* must be re-verified against the
new states per §13).

## 5. Data model contract

**`workspace_plan_assignments` — new columns (final, canonical — no open
option remains):**

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `trial_ends_at` | `timestamp` | Yes | `NULL` | Set once, at first-plan-assignment time, by `EntitlementManager::assignFirstPlan()` (confirmed exact write site, full signature read: `assignFirstPlan(Workspace $workspace, WorkspacePlanTier $tier, int $actorUserId, string $reason, bool $isComplimentary = false, int $additionalBusinessSlots = 0)`) when the signup/plan-selection flow grants a trial (Blueprint §6: "Plan selection → Payment method + trial start"). `NULL` means **not currently trialing** — either no trial was granted, or the trial has already ended and been resolved (converted or otherwise) — never "currently in Trial." |
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
4. **The resolver derives Trial as Usable + trial metadata** — Trial is
   not a new blocking state, exactly like Grace; it surfaces via the same
   kind of optional, non-blocking `CustomerAccountAccessDecision` hint
   (§5 below), never a new `CustomerAccountAccessState` case.
5. **The legacy `Subscription`/Cashier trial mechanism (`onTrial()`-style
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

**`CustomerAccountAccessState` — two new cases:**
```php
enum CustomerAccountAccessState: string
{
    case Usable = 'usable';
    case LockedInactive = 'locked_inactive';
    case LockedSuspended = 'locked_suspended';
    case LockedGracePeriod = 'locked_grace_period';   // NEW -- see note below
    case Locked = 'locked';                            // NEW
    public function isLocked(): bool { ... }
}
```
**Grace itself is deliberately NOT a locked state** — Blueprint §27: Grace
retains full access with a billing prompt. `LockedGracePeriod` above is
**mis-named if read as blocking**; the correct design is that Grace does
**not** get a new `CustomerAccountAccessState` case at all — it stays
`Usable`, with the `CustomerAccountAccessDecision` DTO carrying an
*additional, optional, non-blocking* hint (e.g. a new nullable
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
| Yes | `Active` | set, in the **future** | `NULL` | `NULL` | **Trial** | `Usable` (with trial metadata — days remaining) | No |
| Yes | `Active` | `NULL`, or set and already **past** with a successful conversion | `NULL` | `NULL` | **Active** | `Usable` | No |
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

## 6. Authority / security contract

This slice's own writes are narrow and specific:
- **Who sets `trial_ends_at`:** `EntitlementManager::assignFirstPlan()`
  only, at first-plan-assignment time (confirmed exact write site, §5) —
  never mutated after that call; a trial's natural end is read purely from
  the timestamp already being in the past, not from a separate clearing
  write. Only when a trial ends *without* conversion does the (out-of-scope,
  see below) renewal-failure path additionally set `grace_started_at`.
- **Who sets `grace_started_at`:** the billing/renewal-failure code path
  (out of this contract's scope to locate precisely — flagged: confirm
  against the actual Stripe-webhook/renewal-check code before
  implementation, since this contract's evidence-gathering focused on the
  resolver, not the renewal trigger) — never a customer action, never the
  resolver itself (resolver is read-only, per its own docblock: "READS
  ONLY... never mutates anything").
- **Who sets `locked_at`:** either the same renewal-failure code path
  (scheduled check, 3 days after `grace_started_at`) or, per the §5 truth
  table's defensive row, computed **implicitly** by the resolver without
  requiring a write at all — recommended: prefer the resolver computing
  Locked defensively from elapsed time when `locked_at` is `NULL` but
  `grace_started_at` is stale, and have the actual scheduled job write
  `locked_at` as the durable record for audit/reporting, not as the sole
  trigger the resolver depends on. This is the safer of the two designs
  since it removes a single point of failure (a missed cron run) from the
  access-blocking decision.
- **Who clears both on payment:** the payment-confirmation path (also out
  of this contract's direct evidence — flagged, same as above).
- **No customer, staff, Agency, or Platform Administrator action reads or
  writes these columns directly** — every consumer goes through
  `CustomerAccountAccessResolver`, per the Addendum's "single authority"
  rule; this is the one authority contract this slice must not weaken.

## 7. Transaction / concurrency boundary

The resolver itself performs no writes (read-only, confirmed). Whatever
code sets/clears `grace_started_at`/`locked_at` (out of this slice's
direct scope per §6) should lock the `workspace_plan_assignments` row
during the transition, mirroring the existing pattern elsewhere in
`WorkspaceManager`/`EntitlementManager` of `findForUpdate()` before a
status-changing write — this contract states the requirement but does not
design that write path's full transaction, since it belongs to the
renewal/payment code this contract did not inspect (§6).

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

No new domain event is strictly required for the *resolver* itself (it's
read-only). Whatever code sets `grace_started_at`/`locked_at` should
dispatch events consistent with this codebase's existing convention (e.g.
`WorkspaceEnteredGracePeriod`, `WorkspaceLocked`, mirroring the
`Workspace*` event-naming convention in `app/Events/Workspace/`) — named
here as a requirement on that future slice/PR, not implemented by this
contract, since the write path itself is out of scope (§6).

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
- `tests/Feature/Entitlement/CustomerAccountAccessResolverGraceLockedTest.php`

**Existing files modified:**
- `app/Enums/Entitlement/CustomerAccountAccessState.php` — add `Locked` case.
- `app/Library/Entitlement/CustomerAccountAccessResolver.php` — extend `resolve()`'s branching per §5's truth table.
- `app/Library/Entitlement/CustomerAccountAccessDecision.php` — add optional `graceEndsAt`/`isInGracePeriod` field.
- Existing tests for the five consumers listed in §3 — **re-verify, not rewrite**, that every existing Active/Inactive/Suspended test still passes unchanged (§9); add new assertions only for the new Grace/Locked paths where each consumer already has a natural place for them.

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
truth table, as an isolated resolver-level test; `Suspended` wins
regardless of stale Grace/Locked timestamps; the elapsed-time defensive
Locked computation fires correctly even with `locked_at` still `NULL`;
payment-confirmation-style clearing of both timestamps returns to `Usable`
(this specific write path is out of scope per §6, but the resolver's read
behavior *given* cleared timestamps must be tested here).

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
5. `git diff --check` clean; diff matches §12's allowlist.

## 15. Non-goals

Does **not** locate or implement the renewal-failure/payment-confirmation
write path that actually sets/clears `grace_started_at`/`locked_at` (§6,
§10 flag this as out-of-scope, requiring its own follow-up contract or
inline discovery at implementation time). Does not implement Agency
non-payment composition (Contract 05 — this slice is a hard prerequisite
for it, not the same work). Does not modify the legacy
`Subscription`/Cashier trial mechanism itself, only ensures
`CustomerAccountAccessResolver` never reads it (§5 point 5).

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
os-creator1/os-ai repository: account lifecycle Grace/Locked derivation,
per docs/product/implementation-contracts/03-ACCOUNT-LIFECYCLE-GRACE-
LOCKED.md.

Before writing any code:
1. Fetch latest origin/main.
2. Verify the Addendum, Blueprint, and this contract are present (on main
   or its source branch -- if unreachable, STOP and report).
3. Create a fresh branch for this slice only (e.g.
   agent/v1-slice-04-account-lifecycle-grace-locked).
4. Re-read the full contract, especially the SS5 truth table -- it is the
   authoritative specification for this slice's logic.
5. Locate the actual renewal-failure and payment-confirmation code paths
   this contract flags as NOT inspected (SS6) -- confirm where
   grace_started_at/locked_at should be written and cleared, including the
   trial-ends-without-conversion path setting grace_started_at (SS5's
   canonical truth table). trial_ends_at itself is NOT an open point --
   SS5 finalizes it: added, written only by EntitlementManager::
   assignFirstPlan(), read (never written) by the resolver, and the legacy
   Subscription/Cashier trial mechanism is explicitly never read by
   CustomerAccountAccessResolver. Confirm assignFirstPlan()'s actual
   current signature still matches this contract's evidence before adding
   the parameter; if it has changed, STOP and report.
6. Inspect CustomerAccountAccessResolver, CustomerAccountAccessState,
   CustomerAccountAccessDecision, and all five consumer files in their
   current actual state -- if anything has changed from this contract's
   evidence, STOP and report the contradiction.

Implement exactly the scope in this contract: the three new columns
(trial_ends_at, grace_started_at, locked_at), the new Locked state, the
resolver's extended branching per SS5's canonical truth table, the
trial/graceEndsAt-style non-blocking signals, and assignFirstPlan()'s
extended signature. Do NOT implement Contract 05's Agency/Client
composition -- that is a separate slice.

After implementing:
- Run the new focused test file for this slice.
- Re-run every existing test file for the five consumers to confirm zero
  behavior change on their existing Active/Inactive/Suspended paths.
- Run git diff --check.
- Verify the diff touches only the contract's allowlisted files.
- Commit and push.

Do NOT create a pull request yourself if GitHub tooling is unavailable --
ChatGPT will create it through GitHub.

Return a full report: starting/final SHA, exact files changed, exact tests
run and counts, what you found for the grace/locked write-path location,
and confirmation no existing consumer test changed its assertions on the
pre-existing three states. Do NOT begin or authorize Contract 05 or any
other later slice.
```
