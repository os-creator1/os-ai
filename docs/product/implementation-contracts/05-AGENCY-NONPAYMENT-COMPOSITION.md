# Implementation Contract 05 — Agency Non-Payment Composition

**Status:** Planning contract only. Does not authorize implementation.
Depends on Contracts 01 and 03 being merged first.

## 1. Objective

Compose a managing Agency Workspace's own effective access as an upstream
prerequisite for its linked Client Workspaces' effective access, **without
ever mutating** a Client Workspace's own lifecycle row (Addendum §8) —
extending `CustomerAccountAccessResolver`'s inputs, never a second
authority.

## 2. Governing authority

- Addendum §8 (the exact composition rule and its "never overwrite" guarantee).
- Blueprint §27, §28 ("Client lifecycle isolation and Agency delinquency composition").
- Roadmap Slice 5.
- Contract 01 (relationship source), Contract 03 (lifecycle states this composes).

## 3. Current repository reality

Building directly on Contract 03's evidence (not re-derived here):
`CustomerAccountAccessResolver::resolve(?Workspace $workspace)` is
**single-Workspace-scoped** — it has no parameter or internal lookup for
"is there an upstream Workspace whose state also matters." Its three public
methods (`resolve()`, `resolveAmbiguous()`, `resolveForContext()`) all
ultimately call `resolve()` per-Workspace and combine results only across
*multiple candidate Workspaces the same actor might mean* (the
`resolveAmbiguous()` case) — never across a *relationship* between two
specific Workspaces. This is a structurally different composition need:
`resolveAmbiguous()` asks "which of these Workspaces is usable," while this
slice asks "is this *one* specific Workspace's access gated by *another*
specific Workspace's state."

No existing method reads `AgencyClientWorkspaceRelationship` (Contract 01,
not yet on `main` at the time this contract was written) — this slice adds
the first consumer of that table beyond Contract 01/04's own scope.

## 4. Delta from current state to target

**Corrected design — no resolver recursion.** The original draft had
`resolve()` call `resolve($agencyWorkspace)` on itself to get the Agency's
own decision — a structurally recursive public method, safe only because
of a *data-model* argument (Contract 01 forbids multi-agency chains), not
because the *code* itself is incapable of recursing. This is fixed by
introducing an explicit, non-composing primitive:

1. **Extract** `CustomerAccountAccessResolver`'s existing per-Workspace
   truth-table logic (Contract 03's `match`/derivation — the pure, non-
   composing "what does this one Workspace's own status/timestamps mean"
   computation) into a new **private** method:
   ```php
   private function resolveOwnWorkspaceDecision(Workspace $workspace): CustomerAccountAccessDecision
   ```
   This is a pure refactor of Contract 03's own logic — same behavior,
   new method boundary, zero output change. It never calls `resolve()`,
   never calls itself, and never looks at any Agency relationship — it
   answers exactly one question: "what does *this* Workspace's own
   `workspace_plan_assignments` row mean," nothing upstream.
2. **`resolve(?Workspace $workspace): CustomerAccountAccessDecision`**
   becomes a thin orchestrator with no recursive call anywhere in its own
   body:
   ```php
   public function resolve(?Workspace $workspace): CustomerAccountAccessDecision
   {
       if ($workspace === null) {
           return CustomerAccountAccessDecision::usable();
       }

       $ownDecision = $this->resolveOwnWorkspaceDecision($workspace);

       $relationship = $this->relationshipRepository->findActiveForClient($workspace);

       if ($relationship === null) {
           return $ownDecision;
       }

       $agencyWorkspace = $this->workspaceRepository->findById($relationship->agency_workspace_id);
       $agencyDecision = $agencyWorkspace === null
           ? CustomerAccountAccessDecision::usable()
           : $this->resolveOwnWorkspaceDecision($agencyWorkspace);

       return $this->compose($ownDecision, $agencyDecision);
   }
   ```
   The Agency lookup calls `resolveOwnWorkspaceDecision()` — **never**
   `resolve()`. There is no code path, anywhere in this class, by which
   `resolve()` can call itself, directly or indirectly — this is now a
   structural guarantee, not a data-model assumption. `resolveAmbiguous()`
   and `resolveForContext()` are unaffected — they still call the public
   `resolve()` per candidate, exactly as before, and get the composed
   result automatically.

**Explicitly does NOT change:** the Client Workspace's own
`workspace_plan_assignments` row — never written by this slice, only read
(twice removed: the Client's own row, and the Agency's own row). Does not
change `resolveAmbiguous()`'s or `resolveForContext()`'s own multi-
candidate logic — this slice's composition happens *inside* what a single
`resolve($workspace)` call returns for one Workspace, so both of those
callers automatically get correct composed behavior for free, with no
changes to their own code.

## 5. Data model contract

No new table/column — this slice is pure composition logic over Contract
01's relationship table and Contract 03's derived lifecycle states. The
only "data" this contract adds is the **composition truth table** itself
(the deep-dive's required deliverable):

**Client lifecycle × Agency lifecycle → effective access**

| Client's own state (Contract 03) | Agency's own state (Contract 03, only if an Active relationship exists) | Effective (composed) state for the Client |
|---|---|---|
| Usable (Active/Grace/Trial) | Usable (Active/Grace/Trial) | **Usable** |
| Usable | `Locked` | **Locked**, reason `'agency_locked'` (new, distinct from the Client's own `'plan_locked'`) — the Client's own row is untouched; only the *effective* decision changes |
| Usable | `LockedInactive` | **Locked**, reason `'agency_inactive'` |
| Usable | `LockedSuspended` | **Locked**, reason `'agency_suspended'` |
| Usable | *(no Active relationship at all)* | **Usable** — unaffected, matches today's behavior for every Core/Growth Workspace and every not-yet-migrated Business |
| `Locked` (Client's own) | Usable | **Locked**, Client's own reason (`'plan_locked'`) — the Client's own delinquency is not hidden or overridden by a healthy Agency |
| `Locked` (Client's own) | `Locked`/`LockedInactive`/`LockedSuspended` | **Locked** — both are locked; the Client's own reason is surfaced (whichever is "closer to the customer" — do not invent a combined reason string) |
| `LockedInactive`/`LockedSuspended` (Client's own) | any | **Locked with the Client's own state** — the Client's own more-severe/administrative state is never softened by the Agency's state |

**Composition rule, stated precisely:** the effective decision is `Locked`
if **either** the Client's own decision is locked **or** the Agency's own
decision is locked (a simple logical OR over `isLocked()`), but the
*reason/copy* always prefers the Client's own reason when the Client's own
decision is already locked, and only surfaces an Agency-caused reason when
the Client's own decision would otherwise have been `Usable`. This matches
Addendum §8's "never overwrite the Client's own lifecycle state" — the
Client's own `CustomerAccountAccessDecision` object, if locked, is returned
completely unchanged; only when the Client's own decision is `Usable` does
this slice construct a **new**, Agency-caused decision object (never
mutating the Client's own).

**One-hop only, no chained composition, structurally guaranteed (§4):**
the Agency Workspace's own decision is computed via
`resolveOwnWorkspaceDecision($agencyWorkspace)`, a method that has no
awareness of Contract 01 relationships at all and therefore cannot itself
trigger a second composition step — recursion is not merely unlikely
given Addendum §2's data-model rule (a Client has 0-or-1 managing Agency,
and nothing allows an Agency to itself be "managed"), it is **impossible
by construction**, since `resolveOwnWorkspaceDecision()` never calls
`resolve()` or itself. This slice's implementation must call
`resolveOwnWorkspaceDecision($agencyWorkspace)` directly, never `resolve()`
on whatever the
Agency's own relationship (if any, which should not exist) might imply.

## 6. Authority / security contract

Not applicable in the usual sense — this slice performs no write and
introduces no new actor-facing action. The "authority" question here is
purely about **which Workspace's data may be read to compute another
Workspace's access** — and the answer is: only through an **Active**
Contract 01 relationship, the same relationship Contract 04's View As
already trusts, so this slice introduces no new trust boundary beyond
what Contract 01 already established.

## 7. Transaction / concurrency boundary

Read-only composition (`resolve()` remains read-only end to end, per
Contract 03's own "READS ONLY" invariant, preserved here). No locking
needed — a relationship terminated between the two reads this slice
performs (Client's own resolve, then Agency's own resolve) is a benign,
self-correcting race: the very next request re-runs both reads fresh,
matching every other read-only composition in this codebase (e.g.
`resolveAmbiguous()`'s own existing tolerance for a Workspace's state
changing between candidate evaluations).

## 8. Migration / backfill

None — this is pure logic, no schema change, and every existing Workspace
without an Active relationship composes to exactly its own unchanged
decision (the "no Active relationship" row in §5's table is the identity
case).

## 9. Backwards compatibility

Every Workspace with no Active Contract 01 relationship (i.e., every
Workspace that exists on `main` today, since the relationship table is new
and empty until Contract 10's migration) composes to **exactly** its
pre-existing `resolve()` output — this slice is provably a no-op for 100%
of current data until Contract 10 populates real relationships.

## 10. Events / audit

No new event — this slice adds no write. The Client's own
`CustomerAccountAccessDecision`, when returned unchanged (Client already
locked), and the new Agency-caused decision object, when constructed
(Client would otherwise be `Usable`), are both already fully described by
Contract 03's existing DTO shape; no new audit trail is needed beyond what
Contract 01's relationship row and Contract 03's lifecycle timestamps
already provide — this slice only *reads* those two existing audit
sources and combines them, it does not create a third one.

## 11. Billing/provider safety

**Critical, even though this slice itself performs no billing action**:
this composition is the exact gate Contract 09's AgencyRebill standing-
consent checks (Addendum §10: "Client Workspace effective account access,
managing Agency effective account access") depend on. An error in the §5
truth table propagates directly into whether a paid message send is
permitted. This slice must be fully tested (§13) **before** Contract 09
is implemented, not merely before it is merged.

## 12. Exact implementation allowlist

**New files:**
- `tests/Feature/Entitlement/AgencyNonPaymentCompositionTest.php`

**Existing files modified:**
- `app/Library/Entitlement/CustomerAccountAccessResolver.php` —
  (a) extract Contract 03's existing per-Workspace truth-table logic into
  a new **private** `resolveOwnWorkspaceDecision(Workspace): CustomerAccountAccessDecision`
  (pure refactor, zero behavior change); (b) rewrite `resolve()`'s body
  per §4's non-recursive orchestration, calling
  `resolveOwnWorkspaceDecision()` for both the target and (when an Active
  relationship exists) the Agency Workspace — never calling `resolve()`
  on itself. No new **public** method signature change
  (`resolve(?Workspace)` still returns `CustomerAccountAccessDecision`);
  `resolveOwnWorkspaceDecision()` itself is private/internal.
- `app/Library/Entitlement/CustomerAccountAccessDecision.php` — no
  structural change needed (existing `reason`/`heading`/`message` fields
  already accommodate the new Agency-caused reason strings); confirm at
  implementation time whether a boolean `causedByAgency` flag is useful
  for UI copy differentiation — **flagged as an optional addition**, not
  required by this contract's own logic.

**No controller, route, or migration file changed.**

## 13. Required tests

`AgencyNonPaymentCompositionTest.php`: every row of the §5 truth table, as
an explicit test with a real Contract 01 relationship fixture; proves the
Client's own `workspace_plan_assignments` row is never written by this
slice (read-only assertion — snapshot before/after, must be identical);
**proves `resolveOwnWorkspaceDecision()` never calls `resolve()`** — a
direct unit-level test of that private method (via reflection or a
protected-visibility test seam, matching this codebase's existing testing
conventions) confirming it produces the correct per-Workspace decision
with **no** relationship lookup performed at all, i.e. it is provably the
non-composing primitive §4 requires, not merely asserted to be one;
Client B unaffected when Client A or the Agency is locked (explicit
isolation test, directly citing Addendum §8's own core guarantee).

## 14. Acceptance criteria

1. Every §5 truth-table row passes as an explicit test.
2. Zero writes to any `workspace_plan_assignments` row from this slice's
   code path, proven by test.
3. A Workspace with no Active relationship composes identically to
   pre-Contract-05 `resolve()` output (regression-proven against
   Contract 03's own existing test suite, re-run unmodified).
4. `git diff --check` clean; diff matches §12's allowlist.

## 15. Non-goals

Does not implement AgencyRebill (Contract 09 — this slice is a
prerequisite for it, not the same work). Does not build any UI surfacing
the "locked because your Agency is locked" message (a later, unscoped
presentation concern). Does not handle a hypothetical multi-hop Agency
chain — structurally impossible per Contract 01's own design, not a gap
this slice needs to guard against defensively beyond the assertion in §5.

## 16. Merge prerequisites

Contracts 01 and 03 merged (hard dependencies — this slice reads both
directly).

## 17. Conflict map

| Other contract | Shared file/table | Posture |
|---|---|---|
| Contract 01 | reads relationship table | Serialize (prerequisite) |
| Contract 03 | extends the same `resolve()` method Contract 03 already modified | **Serialize** — Contract 03 must land first; this slice's edit to `CustomerAccountAccessResolver.php` builds on Contract 03's, not a parallel edit to the same lines |
| Contract 09 | consumes this slice's composed decision | Serialize, downstream |
| Contract 02, 04 | none | Safe concurrent |

## 18. Implementation prompt

```
You are implementing Slice 5 of the V1 architecture migration for the
os-creator1/os-ai repository: Agency non-payment composition, per docs/
product/implementation-contracts/05-AGENCY-NONPAYMENT-COMPOSITION.md.

Before writing any code:
1. Fetch latest origin/main.
2. Verify Contracts 01 and 03 are both merged to main -- hard
   prerequisites. If either is not merged, STOP and report.
3. Create a fresh branch for this slice only (e.g.
   agent/v1-slice-05-agency-nonpayment-composition).
4. Re-read the full contract, especially the SS5 truth table -- it is the
   authoritative specification.
5. Inspect the actual merged Contract 01 relationship model/manager and
   Contract 03's actual CustomerAccountAccessResolver changes -- if either
   differs from this contract's assumptions, STOP and report the
   contradiction rather than guessing.

Implement exactly the scope in this contract: extract the existing
per-Workspace truth-table logic into a new private
resolveOwnWorkspaceDecision(Workspace) method (pure refactor, zero
behavior change on its own), then rewrite resolve()'s body per SS4's
non-recursive orchestration -- resolve() must NEVER call itself, directly
or indirectly; the Agency lookup calls resolveOwnWorkspaceDecision()
only. Verify this yourself by reading your own final diff for any
`$this->resolve(` occurring inside resolve()'s own body before
committing -- if you find one, it is a defect, not an acceptable
implementation of this contract. Per SS5's precise composition rule:
Client's own locked state is never overwritten; an Agency-caused lock is
only constructed when the Client would otherwise be Usable. Do NOT write
to any workspace_plan_assignments row. Do NOT implement Contract 09
(AgencyRebill).

After implementing:
- Run the new focused test file, covering every SS5 truth-table row and
  the direct resolveOwnWorkspaceDecision() non-recursion test.
- Re-run Contract 03's own existing test suite to confirm zero regression
  for Workspaces without an Active relationship.
- Run git diff --check.
- Verify the diff touches only the contract's allowlisted files.
- Commit and push.

Do NOT create a pull request yourself if GitHub tooling is unavailable --
ChatGPT will create it through GitHub.

Return a full report: starting/final SHA, exact files changed, exact tests
run and counts, and explicit proof (from your test run) that no write
occurred to any workspace_plan_assignments row. Do NOT begin or authorize
Contract 09 or any other later slice.
```
