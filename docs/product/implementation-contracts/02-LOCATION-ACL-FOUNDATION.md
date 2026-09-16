# Implementation Contract 02 — Location ACL Foundation

**Status:** Planning contract only. Does not authorize implementation.

## 1. Objective

Add `location_access_scope` (All\|Selected) and the canonical equivalent of
`workspace_membership_locations` (Addendum §4) as a **sibling** authority
to the existing Business-tenancy check — a new column, new pivot table,
new repository, and a new fail-closed authorization class mirroring
`WorkspaceManager::userCanAccessBusiness()`'s exact algorithm. This slice
is additive only: no controller is wired to consume it yet (Contract 08B
does that).

## 2. Governing authority

- Addendum §4 (Location staff ACL — the locked design).
- Blueprint §4, §26 (product-level staff/Location model).
- Roadmap Slice 3.

## 3. Current repository reality

This is designed as a deliberate structural mirror of the existing,
proven Business-access-scope mechanism — every design choice below cites
the exact existing file it mirrors, confirmed on `origin/main`:

- **`app/Enums/Workspace/WorkspaceBusinessAccessScope.php`**: `enum
  WorkspaceBusinessAccessScope: string { case All = 'all'; case Selected =
  'selected'; }` — the two-case shape this slice's `LocationAccessScope`
  enum mirrors exactly.
- **`database/migrations/2026_07_30_120003_create_workspace_membership_businesses_table.php`**:
  `id, workspace_membership_id (FK->workspace_memberships, restrictOnDelete),
  business_id (FK->businesses, restrictOnDelete), timestamps`, unique
  `(workspace_membership_id, business_id)`, index `business_id` — the exact
  pivot shape this slice's `workspace_membership_locations` table mirrors,
  substituting `business_location_id` for `business_id`.
- **`app/Repositories/Contracts/WorkspaceMembershipBusinessRepository.php`**:
  `assignedBusinessIds()`, `isAssigned()`, `assign()` (cross-Workspace
  validated, idempotent), `syncForMembership()` (all-or-nothing bulk sync),
  `unassign()` (grant-row-only delete), `removeAllForBusinessInWorkspace()`
  (bulk cleanup on reassignment) — the exact method surface this slice's
  new repository mirrors.
- **`app/Library/Workspace/WorkspaceManager::userCanAccessBusiness()`**
  (full body read, lines 97–135): re-derives the Business fresh from the
  repository (never trusts a passed-in model), checks the Workspace exists
  and is active, checks direct-owner (`customer_id`) bypass, checks
  Workspace-owner bypass, checks active membership, then branches on
  `business_access_scope === All ? true : isAssigned(...)`. This is the
  **exact fail-closed shape** the new `userCanAccessLocation()` mirrors —
  re-derive, never trust, default to false at every branch.
- **`app/Models/BusinessLocation.php`**: `business(): BelongsTo`,
  `lifecycle_state` cast to `BusinessLocationLifecycleState` (`Active |
  Archived`), no existing `workspace_id` shortcut column — every
  Workspace-boundary check must go through `location->business->workspace_id`,
  a two-hop resolution, not a direct column.
- **`app/Enums/Workspace/WorkspaceMembershipRole.php`**: `Admin | Staff`
  only, unaffected by this slice (Location scope is an orthogonal axis to
  role, exactly as `business_access_scope` already is — Addendum §4 states
  this explicitly: "two independent axes," matching RFC-003 §7.5's existing
  language for the Business case, now extended to Location).
- **`app/Models/WorkspaceMembership.php`**: currently has
  `business_access_scope` cast to `WorkspaceBusinessAccessScope`, no
  Location-related column. This slice adds `location_access_scope`
  (nullable at the schema level initially, enforced NOT NULL after
  backfill — see §8) as a **new, separate column on the same table**, not
  a new membership-like entity — Addendum §4's own wording ("Staff
  operational access uses `location_access_scope = All\|Selected` plus...")
  places it at the membership level, exactly parallel to
  `business_access_scope`.
- **Transitional-period fact, mechanically important (see §5's cross-check
  note):** as of this slice, `main` has **not yet** enforced 1
  Workspace = 1 Business (that is Contract 13/Slice 13, near the end of
  the roadmap) — a Workspace can still legitimately hold multiple
  Businesses when this slice lands. A Location ACL grant must therefore
  not be usable to reach a Business the membership was never granted
  access to under the still-live `business_access_scope`/
  `workspace_membership_businesses` mechanism.

## 4. Delta from current state to target

**Changes:** one new nullable-then-enforced column on `workspace_memberships`;
one new pivot table; one new enum; one new repository (Contract + Eloquent);
one new authorization class (`LocationAccessGuard`, mirroring
`WorkspaceManager`'s internal shape but not added as more methods on
`WorkspaceManager` itself, to avoid growing that already-large class and to
keep this a genuinely separate, sibling authority per Addendum §4's own
"sibling authority, not a second tenancy system" framing).

**Explicitly does NOT change:** `WorkspaceManager::userCanAccessBusiness()`
itself (read, not modified); `business_access_scope`/
`workspace_membership_businesses` (untouched, still fully live — retired
only in Contract 14); any controller (Contacts, Opportunities, Calendar,
Conversations — wired in Contract 08B, not here); any route.

## 5. Data model contract

**`workspace_memberships` — one new column:**

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `location_access_scope` | `string(16)`, enum-backed | Yes at first, enforced NOT NULL after backfill (see §8) | none — mirrors `business_access_scope`'s own "no database default; every membership-creation path must supply it explicitly" rule (RFC-003 §27) once enforcement lands | `LocationAccessScope`: `All \| Selected` |

**Table `workspace_membership_locations`:**

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK) | No | — | |
| `workspace_membership_id` | FK → `workspace_memberships.id`, `restrictOnDelete()` | No | — | |
| `business_location_id` | FK → `business_locations.id`, `restrictOnDelete()` | No | — | **`restrictOnDelete`, not `cascade`** — deliberately different from `business_locations.business_id`'s own `onDelete('cascade')`, because a grant row is Location-scoping metadata belonging to the *membership*, not data that should vanish silently if the Location record itself is ever hard-deleted (which, per §3's Archived-not-deleted convention, should not normally happen anyway) |
| `created_at`/`updated_at` | `timestamp` | No | `now()` | |

Constraints: unique `(workspace_membership_id, business_location_id)`
(mirroring the Business pivot's own unique-pair index exactly); index
`business_location_id`.

**Enum `App\Enums\Workspace\LocationAccessScope`:**
```php
enum LocationAccessScope: string
{
    case All = 'all';
    case Selected = 'selected';
}
```

**Cross-check invariant (transitional period, until Contract 13 enforces
1:1):** `assign(WorkspaceMembership $membership, BusinessLocation $location)`
MUST verify, in order: (a) `$location->business->workspace_id ===
$membership->workspace_id` (the ordinary Workspace-boundary check,
permanent); (b) **while `main` has not yet enforced 1 Workspace = 1
Business**, that `$location->business_id` is itself among the Businesses
`$membership` can already reach via the still-live
`business_access_scope`/`isAssigned()` check — i.e. Location-level grants
can never be *wider* than the Business-level grants already in force during
the transition. This composed check is a deliberate, temporary
belt-and-braces rule; once Contract 13 lands (1 Business per Workspace
enforced, and by then Contract 14 has retired the Business-scope pivot per
the Roadmap), check (b) becomes vacuously true for every remaining
Workspace (exactly one Business, always reachable) and should be **removed**
in Contract 14 as dead logic, not left as permanent scaffolding.

## 6. Authority / security contract

Mirrors §6 of `WorkspaceManager::userCanAccessBusiness()` exactly, Location-
scoped:

| Actor | Access to a given Location's records |
|---|---|
| Workspace owner | Always full access, every Location, unconditionally (matches the existing owner-bypass precedent) |
| Direct Business owner (`business.customer_id === userId`) | Full access to that Business's Locations (matches the existing `customer_id` bypass) |
| Active membership, `location_access_scope = All` | Full access to every Location of every Business the membership can already reach (composed with §5's transitional check) |
| Active membership, `location_access_scope = Selected` | Only Locations with an explicit `workspace_membership_locations` grant row |
| Inactive membership | No access — fails closed identically to the existing `! $membership->is_active` check |
| No membership at all | No access |

`userCanAccessLocation(int $userId, BusinessLocation $location): bool` on
the new `LocationAccessGuard` class: re-derives `$location` fresh from its
repository (never trusts the passed-in model — exact precedent from
`userCanAccessBusiness()`'s own `$currentBusiness =
$this->businessRepository->findById(...)` re-derivation), then resolves
`business` and `workspace` the same defensive way, before applying the
table above. Also exposes `assertUserCanAccessLocation()`, a thin wrapper
matching `assertUserCanAccessBusiness()`'s own "delegates entirely, no
second algorithm" precedent.

**"Knowing a record ID never bypasses this" (Addendum §4):** enforced
structurally by `userCanAccessLocation()` re-deriving from the repository
rather than trusting any caller-supplied `BusinessLocation` instance —
identical structural guarantee to the existing Business check, not a new
mechanism.

## 7. Transaction / concurrency boundary

`assign()`/`syncForMembership()`/`unassign()` on the new repository mirror
`WorkspaceMembershipBusinessRepository`'s own transactional shape exactly
(each a single-row or small-batch write inside its own transaction, no
long-held lock — these are grant-management writes, not the large
multi-Workspace-lock operations `WorkspaceManager` itself performs). No new
race condition is introduced beyond what already exists for the Business
pivot, since this table's shape and write pattern are a direct structural
copy.

## 8. Migration / backfill

**Column addition — three-step pattern, mirroring RFC-003's own
`businesses.workspace_id` precedent (add nullable → backfill → enforce
NOT NULL) exactly, since that is the one existing precedent in this
codebase for safely adding a required column to a live table:**

1. **Migration A:** `ALTER TABLE workspace_memberships ADD COLUMN
   location_access_scope VARCHAR(16) NULL AFTER business_access_scope;`
2. **Backfill (query-builder-only migration, mirroring
   `WorkspaceBackfillV1`/the M2 payer backfill's own no-Eloquent-dependency
   convention):** every existing `WorkspaceMembership` row — **active OR
   inactive, no `WHERE is_active` filter** — is set to
   `location_access_scope = 'all'` — **not** `'selected'` — because
   defaulting to `All` preserves each membership's *current effective
   Location reach* (before this slice, a Selected-Business-scope staff
   member already implicitly saw every Location of every Business they
   were granted; defaulting the new axis to `All` keeps that reach
   unchanged until Contract 08B's consumer wiring, and Contract 08B's own
   migration note is exactly this: seed real per-Location grants before
   ever narrowing anyone from `All` to `Selected`). Defaulting to
   `Selected` with zero grants would silently **revoke** everyone's access
   the moment Contract 08B wires enforcement — the opposite of the "never
   silently widen or narrow" instruction.

   **Correction: the backfill must cover inactive rows too, explicitly.**
   An earlier draft of this contract scoped the backfill to active
   memberships only, which is a real bug: an *inactive* membership left
   `NULL` would make step 3's `NOT NULL` migration fail outright (it
   cannot satisfy the constraint for that row), and — separately —
   **reactivating** an old inactive membership later (via the existing
   `WorkspaceMembershipRepository::setActive()`, confirmed by full
   signature read to touch only `is_active`, never `business_access_scope`
   or any other column) must land the member back with the **same**
   deterministic `location_access_scope` value it already had, not a
   freshly-`NULL`/freshly-guessed one. Backfilling every row up front,
   regardless of `is_active`, is what makes that guarantee hold — there is
   no separate "on reactivation" code path needed, because by the time
   reactivation can occur, every row (active or not) already has a
   deterministic value from this backfill, and `setActive()` never
   touches it.
3. **Migration C (later, only once the backfill above is verified
   complete for 100% of existing rows, active and inactive alike):**
   `ALTER TABLE workspace_memberships MODIFY COLUMN
   location_access_scope VARCHAR(16) NOT NULL;` — mirroring the exact
   zero-violation-assertion discipline
   `2026_07_30_120006_enforce_business_workspace_constraint.php` already
   uses for `businesses.workspace_id`. The precondition query must be
   `SELECT COUNT(*) FROM workspace_memberships WHERE location_access_scope
   IS NULL` with **no** `is_active` filter, for the same reason.

**Full writer inventory (deep-dive requirement — every path that creates a
`WorkspaceMembership` row, mechanically enumerated via `git grep
"WorkspaceMembership::create(" -- app tests`, not assumed):**

| Writer | Path type | Current default handling | Required fix |
|---|---|---|---|
| `WorkspaceManager::addMember()` → `WorkspaceMembershipRepository::create()` | **Production** (the only one) | `create(Workspace, int, WorkspaceMembershipRole, WorkspaceBusinessAccessScope)` — `$scope` already required, no default (confirmed by full interface read) | Extend the signature to also require `LocationAccessScope $locationScope` (no default), mirroring the existing `$scope` parameter exactly — every production caller of `addMember()` must be updated to pass one explicitly |
| `tests/Feature/Workspace/Concerns/CreatesWorkspaceTestData.php::createMembership()` | Shared test fixture trait (confirmed, full method read) | `array_merge([..., 'business_access_scope' => WorkspaceBusinessAccessScope::All, ...], $overrides)` — bypasses the repository entirely, calls `WorkspaceMembership::create()` directly | Add `'location_access_scope' => LocationAccessScope::All` to the same default array |
| `tests/Feature/Workspace/WorkspaceModelTest.php` (line ~33) | Local `array_merge`-based helper, same shape as above | Same pattern | Same fix, same file |
| `tests/Feature/Workspace/WorkspaceOwnershipTransferTest.php` (line ~82) | Local `array_merge`-based helper | Same pattern | Same fix, same file |
| **27 further test files, each with one or more *literal* (non-`array_merge`) `WorkspaceMembership::create([...])` calls** — confirmed by exhaustive `git grep`, not sampled: `AgencyProspectingRuntimeTest.php`, `AgencyProspectingTest.php`, `CreatesAnalyticsFixtures.php`, `CreatesAutomationFixtures.php`, `InternalNotificationExecutorTest.php`, `WorkflowHttpAuthorizationTest.php`, `MessagingChannelsTest.php`, `WorkspaceBusinessExistingBehaviorPreservedTest.php`, `EntitlementManagerBusinessToggleTest.php` (5 call sites), `EntitlementManagerConcurrencyTest.php` (2 call sites), `CreatesGoogleBusinessProfileFixtures.php`, `OutreachCorrection1Test.php` (3 call sites), `RequestScopedCacheQueueLifecycleTest.php`, `MessagingProviderAuthorizationTest.php` (2 call sites), `OutreachSecurityTest.php` (3 call sites), `RelocatedAdvancedProviderAuthorizationTest.php` (2 call sites), `PayerAssignmentTransitionScenariosTest.php`, `UsageWalletManagerSpendCapTest.php`, `CreatesWebsiteFixtures.php`, `WorkspaceManagerTest.php`, `WorkspaceOwnershipTransferHttpTest.php` | Each constructs its attribute array inline, literally — none share a common default | **Each literal array individually needs `'location_access_scope' => LocationAccessScope::All'` added** — there is no single shared default to patch for this group; §12's allowlist lists every one of these files explicitly so none is discovered missing only when the `NOT NULL` migration (step 3 above, actually landing in this same slice's own migration set) fails CI |

This inventory is exhaustive as of this contract's writing — re-run the
same `git grep` at implementation time to catch any file added since.

**`workspace_membership_locations` table itself:** no backfill — starts
empty; every membership is `All`-scoped by the backfill above, so no
`Selected`-scope grant rows are needed yet. Real per-Location grants are
only created once an owner or Contract 08B's migration explicitly narrows
someone to `Selected` — out of this slice's scope.

## 9. Backwards compatibility

`business_access_scope`/`workspace_membership_businesses` remain fully
live and untouched — this slice adds a second, independent axis alongside
it, never replaces it. Both axes coexist until Contract 14 retires the
Business one. No existing controller reads `location_access_scope` yet, so
no existing behavior changes as a direct result of this slice landing.

## 10. Events / audit

No new domain events at this slice's scope — grant creation/removal is
lower-stakes, high-frequency administrative metadata (mirroring the
existing Business-pivot's own precedent: `WorkspaceMembershipBusinessAssigned`/
`Unassigned` events exist, so for consistency this slice should add the
Location-equivalent pair —
`WorkspaceMembershipLocationAssigned`/`WorkspaceMembershipLocationUnassigned`
— matching that existing precedent rather than omitting events the sibling
mechanism already has).

## 11. Billing/provider safety

Not applicable — no paid action, wallet, or provider call is touched by
this slice.

## 12. Exact implementation allowlist

**New files:**
- `database/migrations/2026_09_2x_100001_add_location_access_scope_to_workspace_memberships_table.php`
- `database/migrations/2026_09_2x_100002_backfill_workspace_membership_location_access_scope.php`
- `database/migrations/2026_09_2x_100003_enforce_workspace_membership_location_access_scope_not_null.php` (run only after backfill verification — see §8)
- `database/migrations/2026_09_2x_100004_create_workspace_membership_locations_table.php`
- `app/Enums/Workspace/LocationAccessScope.php`
- `app/Repositories/Contracts/WorkspaceMembershipLocationRepository.php`
- `app/Repositories/Eloquent/EloquentWorkspaceMembershipLocationRepository.php`
- `app/Library/Workspace/LocationAccessGuard.php`
- `app/Events/Workspace/WorkspaceMembershipLocationAssigned.php`
- `app/Events/Workspace/WorkspaceMembershipLocationUnassigned.php`
- `app/Exceptions/Workspace/CrossBusinessLocationAssignmentException.php` (mirrors `CrossWorkspaceAssignmentException`)
- `tests/Feature/Workspace/LocationAccessGuardTest.php`
- `tests/Feature/Workspace/WorkspaceMembershipLocationRepositoryTest.php`

**Existing files modified:**
- `app/Models/WorkspaceMembership.php` — add `location_access_scope` to `$fillable`/`$casts`.
- `app/Providers/AppServiceProvider.php` — one new binding line.
- `app/Repositories/Contracts/WorkspaceMembershipRepository.php` + `EloquentWorkspaceMembershipRepository.php` — extend `create()`'s signature with a required `LocationAccessScope $locationScope` parameter, mirroring the existing `$scope` parameter exactly.
- `app/Library/Workspace/WorkspaceManager.php` — `addMember()` gains the same new required parameter and passes it through to `create()`.
- Every production caller of `WorkspaceManager::addMember()` — updated to supply an explicit `LocationAccessScope` (the exact call sites must be re-enumerated via `git grep "addMember("` at implementation time, since this contract's own evidence pass focused on the repository/manager layer, not every controller invoking it).

**This slice DOES modify existing test files — 30 of them, per §8's full
writer inventory**, correcting the original "no existing test modified"
framing, which was wrong for this slice specifically (Location ACL is the
one contract in this factory whose NOT NULL column addition has a real,
large existing-writer surface, unlike every other additive slice):
`CreatesWorkspaceTestData.php`, `WorkspaceModelTest.php`,
`WorkspaceOwnershipTransferTest.php`, and the 27 further files §8 lists by
name — each gets exactly one line added (`'location_access_scope' =>
LocationAccessScope::All`) to its existing `WorkspaceMembership::create()`
call(s), no other change.

**No existing controller, route, or non-test-fixture file modified beyond
what's listed above.**

**Central-file flag:** `app/Providers/AppServiceProvider.php` also touched
by Contract 01 — see §17.

## 13. Required tests

`LocationAccessGuardTest.php`: happy path per actor row in §6; IDOR/
adversarial — a Selected-scope membership with no grant for a Location
must be refused even when handed that Location's real ID directly (not a
guessed one, to prove re-derivation, not obscurity, is the guard);
cross-Workspace isolation — a membership from Workspace X can never be
granted (or, if force-inserted directly at the DB layer for the test,
never *recognized* as valid by the guard) for a Location under Workspace Y;
transitional cross-check (§5) — a `Selected`-scope-on-Business membership
attempting to gain a Location grant for a Business it isn't Business-level-
granted for is refused even though the Location itself belongs to the same
Workspace.

`WorkspaceMembershipLocationRepositoryTest.php`: mirrors
`WorkspaceMembershipBusinessRepositoryTest.php`'s own test shape —
`assign()` idempotency, `syncForMembership()` all-or-nothing, `unassign()`
grant-only deletion, cross-Workspace rejection.

Migration tests: backfill sets every existing membership — **active and
inactive alike** — to `All`; the NOT NULL enforcement migration fails
loudly if any row (active or inactive) is still null at run time
(mirroring `WorkspaceEnforcementMigrationTest.php`'s own pattern for the
analogous `businesses.workspace_id` enforcement).

**Reactivation preserves deterministic Location access (explicit test,
per this remediation):** create a membership with `location_access_scope
= Selected` and a specific grant, deactivate it via
`WorkspaceMembershipRepository::setActive(false)`, reactivate via
`setActive(true)`, then assert `location_access_scope` and its grant
row(s) are byte-for-byte unchanged throughout — proving `setActive()`
never touches this column (confirmed by its own signature read, §8) and
that no separate "on reactivation" reset logic was accidentally
introduced.

**Writer-inventory regression (explicit, per this remediation):** every
one of the 30 test files in §8's inventory table is run as part of this
slice's own verification pass, confirming each still passes after its
`WorkspaceMembership::create()` call(s) gain the new required attribute —
not merely that the *new* Location ACL tests pass, but that the NOT NULL
migration does not break any *existing* test.

## 14. Acceptance criteria

1. Migrations run clean in the documented three-step order.
2. Every existing `WorkspaceMembership` row — active and inactive —
   has `location_access_scope = 'all'` after backfill, verified by test.
3. `LocationAccessGuard` passes every §13 test.
4. Reactivation-preserves-access test (§13) passes.
5. All 30 files in §8's writer inventory pass after their one-line fix,
   individually confirmed, not assumed from a partial sample.
6. Zero existing controller/route behavior changes (nothing consumes this
   yet).
7. `git diff --check` clean; diff matches §12's allowlist exactly.

## 15. Non-goals

Does **not** wire any controller (Contract 08B). Does not touch
`business_access_scope` itself. Does not remove or deprecate anything —
that is Contract 14, only after Contract 13's DB enforcement makes the
transitional cross-check (§5) vacuous.

## 16. Merge prerequisites

None beyond the Roadmap Wave 0 gate. Independent of Contract 01 — may be
implemented concurrently.

## 17. Conflict map

| Other contract | Shared file/table | Posture |
|---|---|---|
| Contract 01 (Slice 1) | `app/Providers/AppServiceProvider.php` (different lines) | **Safe concurrent**, coordinate merge order |
| Contract 03 (Slice 4) | none | Safe concurrent |
| Contract 07 (Slice 6, Conversations) | none directly, but benefits from landing first per Roadmap | Safe concurrent |
| Contract 08B (Slice 8b) | consumes this slice's model/repository/guard | **Serialize** — hard dependency |
| Contract 14 (Slice 14) | removes the §5 transitional cross-check and eventually the `business_access_scope` axis this slice composes with | **Serialize**, far downstream |

## 18. Implementation prompt

```
You are implementing Slice 3 of the V1 architecture migration for the
os-creator1/os-ai repository: the Location ACL foundation, per docs/
product/implementation-contracts/02-LOCATION-ACL-FOUNDATION.md.

Before writing any code:
1. Fetch latest origin/main.
2. Verify the Addendum, Blueprint, and this contract are present (on main
   or the contract's source branch -- if unreachable, STOP and report).
3. Create a fresh branch for this slice only (e.g.
   agent/v1-slice-03-location-acl-foundation).
4. Re-read the full contract end to end.
5. Inspect the actual current state of every cited file (WorkspaceBusiness
   AccessScope, the workspace_membership_businesses migration and
   repository, WorkspaceManager::userCanAccessBusiness(), BusinessLocation)
   -- if any pattern this contract relies on has changed, STOP and report
   the contradiction rather than guessing.

Implement exactly the scope in this contract -- the new column, pivot
table, enum, repository, LocationAccessGuard class, and the extended
WorkspaceMembershipRepository::create()/WorkspaceManager::addMember()
signatures, with the exact three-step migration sequence in SS8 (backfill
covers active AND inactive rows -- no is_active filter). Do NOT wire any
controller to consume this yet, do NOT touch business_access_scope, and
do NOT implement the enforce-NOT-NULL migration until you have verified
(in your own test run) that the backfill left zero null rows, counting
inactive rows too -- if it did not, STOP and report rather than forcing
the constraint.

Before touching any test file, re-run `git grep "WorkspaceMembership::
create(" -- app tests` yourself and reconcile the result against SS8's
30-file inventory -- if the count or file list differs (a file added or
removed since this contract was written), STOP and report the
discrepancy rather than silently using either list. Update every file
the reconciled list names, each with exactly the one-line fix SS8/SS12
describe -- no broader test refactor.

After implementing:
- Run the new focused test files for this slice, including the
  reactivation-preserves-access test.
- Run the broader Workspace-domain regression (tests/Feature/Workspace/)
  AND every one of the 30 writer-inventory files individually, confirming
  each passes after its one-line fix.
- Run git diff --check.
- Verify the diff touches only the contract's allowlisted files.
- Commit and push.

Do NOT create a pull request yourself if GitHub tooling is unavailable --
ChatGPT will create it through GitHub.

Return a full report: starting/final SHA, exact files changed, exact tests
run and counts, and explicit confirmation the backfill achieved 100%
coverage before the NOT NULL migration ran. Do NOT begin or authorize
Contract 08B (controller wiring) or any other later slice.
```
