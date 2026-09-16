# Implementation Contract 01 — Agency↔Client Workspace Relationship

**Status:** Planning contract only. Does not authorize implementation.
Grounded in `origin/main` as of this contract's writing (see §16 for the
exact prerequisite state); re-verify before implementing if `main` has
advanced.

## 1. Objective

Add the canonical, explicit Agency↔Client Workspace management relationship
(Addendum §2): a new table/model plus a narrow manager class exposing
create/terminate/lookup operations, plus a separate migration-only create
primitive for Contract 10's operator-run migration (§6, "Migration-only
establishment"). This slice is **purely additive** — no
existing controller, route, or authorization check changes. Nothing
consumes this relationship for real authorization until Contract 04
(Slice 2).

## 2. Governing authority

- Addendum §2 (the relationship itself — cardinality, required fields,
  termination authority, no inference from Client-Workspace membership).
- Addendum §18 step 1 (this is the first roadmap step).
- Blueprint §2 (actor authority — corrected in the Phase A pass: Agency
  team members, not just the owner, get normal Agency-management actions;
  only AgencyRebill consent and relationship *termination* stay owner-only).
- Blueprint §28 (Clients surface consumes this relationship).
- Roadmap Slice 1 (`V1-IMPLEMENTATION-ROADMAP.md`).

## 3. Current repository reality

Mechanically confirmed on `origin/main`:

- **No relationship table exists.** `git grep` for `agency_client`,
  `managed_workspace`, `workspace_relationship` across the full repository
  returns zero files (re-confirmed from the original architecture audit;
  not re-run in this pass since nothing has changed the schema since).
- **`app/Models/Workspace.php`**: fillable `['uid', 'name', 'owner_user_id',
  'is_active']`; relations `owner(): BelongsTo`, `memberships(): HasMany`,
  `businesses(): HasMany`. No Agency-specific column or relation.
- **`app/Enums/Entitlement/WorkspacePlanTier.php`**: `Core | Growth |
  Agency` — Agency is a plan tier on the same `Workspace` row, not a
  separate model (re-confirms the original audit finding).
- **`app/Repositories/Contracts/WorkspaceRepository.php`** /
  **`app/Repositories/Eloquent/EloquentWorkspaceRepository.php`**: the
  established Contract-interface + Eloquent-implementation pattern this
  slice's new repository must follow exactly. `findById()`, `findForUpdate()`
  (row-locking variant), `findByUid()` are the precedent methods.
- **`app/Repositories/Contracts/WorkspaceTransitionRepository.php`**: the
  established **append-only audit pattern** — `create(array $attributes)`
  and a `for*()` read method, deliberately no `update()`. This slice's
  relationship-lifecycle audit trail should follow the same shape rather
  than inventing a new one.
- **`app/Library/Workspace/WorkspaceManager.php`**:
  `assertActorIsOwnerOrActiveAdmin(int $actorUserId, Workspace $workspace)`
  (private, line 306) — checks `workspace.owner_user_id === $actorUserId`
  OR an active `WorkspaceMembership` with `role === WorkspaceMembershipRole::Admin`.
  **This check excludes Staff entirely** and is the wrong shape to reuse
  verbatim for this slice's authority (§6) — the corrected Blueprint §2
  locked rule requires Staff-with-permission to also qualify for ordinary
  Agency-management actions, which `assertActorIsOwnerOrActiveAdmin` cannot
  express.
- **`app/Enums/Workspace/WorkspaceMembershipRole.php`**: `Admin | Staff`
  only — no third role exists to represent "Agency-management-permitted
  Staff"; that must be an ordinary feature permission (Gate-based, matching
  the pattern `CrmOpportunitiesController::VIEW_PERMISSION` /
  `CustomerMenuBuilder`'s `Gate::forUser($user)->any($permissions)` already
  use elsewhere in this codebase), not a new role.
- **`app/Events/Workspace/`**: flat event classes per transition
  (`WorkspaceCreated`, `WorkspaceRenamed`, `BusinessReassignedToWorkspace`,
  `WorkspaceMembershipBusinessUnassigned`, etc.) — one class per semantic
  event, dispatched via `Event::class::dispatch(...)` with plain scalar
  arguments (IDs, not models). This slice's two events
  (`AgencyClientRelationshipEstablished`, `AgencyClientRelationshipTerminated`)
  follow the same shape.
- **`app/Providers/AppServiceProvider.php`** (~lines 188–198): the exact
  binding-array location for every `Contracts\*Repository::class =>
  Eloquent\Eloquent*Repository::class` pair. This slice adds one line here.
- **`app/Exceptions/Workspace/`**: flat, semantically-named exception
  classes (`WorkspaceNotFoundException`, `UnauthorizedWorkspaceManagementException`,
  `CrossWorkspaceAssignmentException`, etc.) — this slice's exceptions
  follow the same naming and directory convention.
- **`tests/Feature/Workspace/`**: the established test-directory home for
  Workspace-domain features (`WorkspaceManagerTest.php`,
  `WorkspaceLifecycleTest.php`, `WorkspaceEffectiveAccessTest.php`, etc.).
- **Migration date convention**: most recent migrations on `main` are dated
  `2026_09_19_*`; this slice's migration should use the next available date
  stamp at actual implementation time (e.g. `2026_09_2x_100001_...`), not a
  hardcoded date guessed now.
- **`app/Library/Entitlement/EntitlementManager.php`**: already branches on
  `$tier === WorkspacePlanTier::Agency` (lines 1851, 2064, 2100) for
  existing Agency-only capacity rules — the same `$lockedCatalog->tier`
  read is the precedent for this slice's "is the Agency Workspace actually
  on the Agency tier" check.
- **Platform Owner vs. generic Platform Administrator — mechanically
  inspected, not assumed.** `User.is_admin` (`app/Models/User.php`, cast
  `boolean`) is the **only** platform-level flag on the primary `User`
  model — a single, flat boolean with no graduated Owner/Administrator
  split. Separately, the legacy admin panel has its own RBAC layer:
  `app/Models/Admin.php` (`admins` table, `user_id`, `creator_id`,
  `admin_role` — this last column is fillable but confirmed **unused**
  elsewhere in the codebase via `git grep`, i.e. vestigial, not an active
  Owner/Administrator distinction) plus `app/Models/Role.php` +
  `RoleUser.php`, a real Role→Permission RBAC system backed by
  `config/permissions.php` (the **admin-side** permission registry —
  distinct from `config/customer-permissions.php`, which is the
  **customer/Workspace-side** one; see §6 — no customer permission is part
  of V1 Agency authority). **No existing single flag or role value
  already means "the one Platform Owner"** as opposed to "any admin-panel
  user with some role." Treating bare `is_admin === true` as satisfying
  Addendum §2's "Platform Owner" exception would silently admit every
  admin-panel account, including narrowly-scoped support-Role holders —
  exactly the over-broadening this remediation must not do. §6 states the
  minimum safe rule this evidence supports.

## 4. Delta from current state to target

**Changes:** one new table, one new Eloquent model, one new
Contract-interface + Eloquent repository pair, one new manager class
(`AgencyClientRelationshipManager`), two new domain events, a handful of
new exceptions, one new DI binding line, new focused tests.

**Explicitly does NOT change:** `Workspace`, `Business`, `WorkspaceMembership`,
`WorkspaceManager`, any controller, any route, any middleware, `ViewAsManager`,
`EntitlementManager`'s existing methods (only reads `WorkspacePlanTier`,
does not modify it). No existing authorization path is touched — this
relationship is inert until Contract 04 consumes it.

## 5. Data model contract

**Table `agency_client_workspace_relationships`:**

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint unsigned` (PK, auto-increment) | No | — | |
| `uid` | `uuid` | No | generated | `HasUid` trait, matching `Workspace`/`Business`/`BusinessLocation`'s own convention |
| `agency_workspace_id` | `unsignedBigInteger`, FK → `workspaces.id`, `restrictOnDelete()` | No | — | |
| `client_workspace_id` | `unsignedBigInteger`, FK → `workspaces.id`, `restrictOnDelete()` | No | — | |
| `status` | `string(16)`, enum-backed | No | — | `AgencyClientRelationshipStatus`: `Active \| Terminated` |
| `established_by_user_id` | `unsignedBigInteger`, no FK (deliberate — mirrors `workspace_transitions.actor_user_id`'s own no-FK precedent for actor columns) | No | — | |
| `established_at` | `timestamp` | No | `now()` | |
| `terminated_by_user_id` | `unsignedBigInteger`, no FK | Yes | `NULL` | |
| `terminated_at` | `timestamp` | Yes | `NULL` | |
| `termination_reason` | `text` | Yes | `NULL` | mandatory at the application layer whenever `terminated_at` is set (mirrors `business_payer_transitions.reason`'s "mandatory reason" pattern) |
| `created_at` / `updated_at` | `timestamp` | No | `now()` | |

**Constraints:**
- **Self-link prevention:** application-layer assertion in the manager
  (`agency_workspace_id !== client_workspace_id`) — not database-enforceable
  as a portable MySQL `CHECK` given this codebase's migration conventions
  observed elsewhere (no existing migration uses a raw `CHECK` constraint;
  none is introduced here either, to stay consistent).
- **Active uniqueness (no multi-agency co-management, Addendum §2):** a
  **partial unique index** is not natively expressible in MySQL 8 the way
  Postgres does it; the safe, precedented pattern for this codebase is a
  **generated/stored column** `active_client_workspace_id` that is
  `client_workspace_id` when `status = 'active'` and `NULL` otherwise, with
  a unique index on that generated column — this gives the database itself
  a hard guarantee that at most one `Active` row exists per
  `client_workspace_id`, without blocking multiple `Terminated` history rows
  for the same client. (This generated-column-unique-index pattern is new
  to this codebase; it is the minimum safe mechanism to satisfy "no
  multi-agency co-management" at the DB layer rather than the application
  layer alone — flag for review before implementation, since it is not
  copied from an existing precedent file the way every other choice in this
  contract is.)
- **Indexes:** `agency_workspace_id`, `client_workspace_id` (plain, for the
  history/lookup queries below), composite `(client_workspace_id, status)`.
- **Delete behavior:** no hard-delete method on the repository — matches
  RFC-003 §27's "no repository exposes a hard-delete method for `Workspace`,
  `WorkspaceMembership`, or `Business`" posture, extended here by the same
  principle: history is never destroyed, only status-transitioned.

**Enum `App\Enums\Workspace\AgencyClientRelationshipStatus`:**
```php
enum AgencyClientRelationshipStatus: string
{
    case Active = 'active';
    case Terminated = 'terminated';
}
```
Two states only, matching `WorkspaceTransitionType`'s own minimal-enum
precedent — no `Pending`/`Suspended` state is needed since relationship
*creation* is a single atomic action, not a multi-step negotiation, per
Addendum §2's own text.

**Model `App\Models\AgencyClientWorkspaceRelationship`:** `use HasUid;`
fillable per the column list above (minus `id`/`uid`/timestamps);
`agencyWorkspace(): BelongsTo(Workspace::class, 'agency_workspace_id')`;
`clientWorkspace(): BelongsTo(Workspace::class, 'client_workspace_id')`;
`status` cast to the enum.

## 6. Authority / security contract

| Actor | Create relationship | Terminate relationship | Read/list |
|---|---|---|---|
| Workspace owner of the Agency Workspace | **Yes** | **Yes** | Yes |
| Active Admin of the Agency Workspace | **Yes** — by active membership of exactly this Agency Workspace | **No** — owner-only per Addendum §2 | Yes |
| Active Staff of the Agency Workspace | **Yes** — by active membership of exactly this Agency Workspace | **No** | Yes |
| Inactive member of the Agency Workspace, a member of a different Agency Workspace only, or a User with no membership | No | No | No |
| Client Workspace owner/staff | **No** | **No** — cannot remove its own managing relationship (Addendum §2) | No (not this manager's concern — the client never queries this table directly) |
| Platform Owner / platform relationship operator (minimum safe rule, §3) | **No authority from platform status** through normal `create()` (never originates on a customer's behalf — a User who is also genuinely the Agency owner or a permitted Agency member still creates, but only through that Agency-side authority); **Yes** only through the migration-only `createForMigration()` — see "Migration-only establishment" below | **Yes**, through the separate platform authority path (Addendum §2's second owner-only exception) | Yes (admin surface) |
| Any other admin-panel user (generic Platform Administrator) | No authority from platform status — neither entry point | **No** | Yes (admin surface, read-only) |

**Canonical V1 authority rule (the same language
`V1-ACCEPTANCE-MATRIX.md` uses):**
- **Create / ordinary management:** the Agency Workspace owner, or an
  ACTIVE Admin or Staff member of exactly that Agency Workspace — and only
  while the Agency holds management eligibility (Agency tier AND a usable
  account, below). Nothing else — no customer permission, `is_admin`, admin
  Role permission or user-id-1 convention adds **any** authority to normal
  `create()`, and none of them erases a User's genuine Agency ownership or
  active membership.
- **Termination:** the Agency Workspace owner only, on the Agency side; and
  the Platform Owner / platform relationship operator, through the separate
  platform authority path below.
- **Money:** AgencyRebill consent, payer selection and funding configuration
  remain Agency Workspace owner only (Addendum §10; Contract 09, not this
  slice).

**Ordinary Agency authority — no customer permission (Contract 04
authority correction, superseding this contract's original
`manage_agency_clients` design).** The original draft required an Agency
Admin/Staff member to also hold a new customer-side Gate permission,
`manage_agency_clients`, registered in `config/customer-permissions.php`.
That permission is **removed** and is **not** V1 Agency authority: the
customer permission list is **User-global**, never Workspace-scoped
(`EloquentAccountRepository::hasPermission()` resolves one list per User,
from the session, the Customer row or admin Roles), so a grant meant for
one Agency Workspace would leak into every Agency Workspace the same User
belongs to (Addendum §3), and user id 1 would bypass it entirely. V1 Agency
Admin/Staff have ordinary Agency-management access **by active
membership**; the financial and ownership exceptions stay owner-only.

The one shared authority primitive is the public, read-only
`AgencyClientRelationshipManager::actorHasAgencyAuthority(int $actorUserId,
Workspace $agencyWorkspace): bool`, used by both this contract's `create()`
(through the private `assertActorMayManageAgencyRelationships()`, which
throws `UnauthorizedAgencyRelationshipManagementException`) and Contract
04's View As. It derives authority **only** from:
1. the exact Agency Workspace `owner_user_id`, or
2. an ACTIVE `WorkspaceMembership` for this actor in exactly this Agency
   Workspace, whose role is `Admin` or `Staff`.

It never calls a Gate, never reads `customers.permissions` or session
permissions, never reads `is_admin` / admin Roles / user id 1, and never
infers authority from another Workspace's membership or from Client
Workspace membership. It is deliberately **not**
`WorkspaceManager::assertActorIsOwnerOrActiveAdmin()`, which excludes Staff.

**Management eligibility — the Agency itself must be able to act.** Every
Agency product action, including establishing a relationship through either
entry point, additionally requires
`AgencyClientRelationshipManager::agencyWorkspaceHasManagementEligibility(Workspace $agencyWorkspace): bool`:
the Agency Workspace's current plan tier is `WorkspacePlanTier::Agency`
**and** its effective account access is usable, as decided by
`CustomerAccountAccessResolver` (Contract 03/05's one authority — never
re-derived from status or lifecycle timestamps). Trial, Active and Grace are
eligible; Locked, Inactive and Suspended are not, and are refused with
`AgencyWorkspaceNotEligibleException` (carrying the tier and, when the tier
was right, the account access state found).

**Termination authority — minimum safe rule (§3's evidence):** a separate,
narrower `assertActorMayTerminateAgencyRelationship()`: the Agency
Workspace owner, **or** an admin-panel actor holding a **new, dedicated**
admin-side permission — `manage agency relationships` — registered in
`config/permissions.php` (the admin-side registry, distinct from the
customer-side one above) and read **directly** from the account's admin
`Role`/`Permission`/`RoleUser` grants (not through `User.is_admin` alone —
see "Platform relationship operator predicate" below for why this
deliberately bypasses the generic Gate). This is the minimum safe stand-in for
"Platform Owner" until/unless this codebase ever adds a real graduated
Owner-vs-Administrator distinction: it is **strictly narrower** than "any
`is_admin = true` account," since an admin-panel user must additionally
hold this specific new Role permission, matching how every other
admin-side capability in this codebase is already gated by Role, never by
the bare `is_admin` flag alone. **Do not** substitute a bare `is_admin`
check for this — that would silently admit every admin-panel account
regardless of their assigned Role's actual permissions.

**Migration-only establishment — a distinct, non-surface operation
(correction, recorded on the Slice 1 implementation branch).** Contract 10's
operator-run legacy migration must create the Agency → new Client Workspace
relationship through this contract's domain primitive **and** record the
real human migration operator as `established_by_user_id` — never a
fabricated Agency-owner actor, since the Agency owner did not take that
action (Contract 10 §6/§10). Normal `create()` cannot serve that caller: it
is authorized only through Agency-side authority, platform status adds
nothing to it, and that rule is correct and **unchanged**. So the manager
exposes a second, narrowly-named entry
point, `createForMigration(int $operatorUserId, Workspace $agencyWorkspace,
Workspace $clientWorkspace)`:
- **Not a product action.** No route, controller, middleware or UI reaches
  it in this slice or any product slice; its only intended caller is
  Contract 10's migration logic.
- **Operator authority, and nothing else:** the operator must be a
  platform relationship operator (predicate below). There is no
  Agency-owner or Agency-team branch: an Agency owner or member, whatever
  customer permissions they hold (including a customer permission list
  naming `manage agency relationships`), is refused and uses normal
  `create()` instead. One private predicate defines
  "platform relationship operator" for both termination and migration
  establishment, so the two platform-reserved acts cannot drift apart.
- **Records the real operator** as `established_by_user_id` and as the
  `AgencyClientRelationshipEstablished` event's actor (§10).
- **Identical structural invariants.** `create()` and `createForMigration()`
  differ only in the authority assertion; both delegate to one private
  establishment routine that performs self-link refusal, both Workspace row
  locks in ascending id order, authority, the Agency-tier gate (authority is
  still asserted before tier), the locking active-relationship read, the
  single-active-Agency rule (backed by the generated-column unique index),
  the same row shape and the same event. One-active-Agency uniqueness is
  therefore one rule across both entry points (§7).
- **Normal `create()` authority does not widen.** An admin-panel actor who
  holds `manage agency relationships` — the exact actor `createForMigration()`
  accepts — is still refused by `create()` unless they independently are the
  Agency Workspace owner or a permitted active Agency member.

**Platform relationship operator predicate — deliberately bypasses the
generic Gate.** Every other admin capability asks `Gate::allows()`, which
resolves through `EloquentAccountRepository::hasPermission()`: user id 1
always passes, then a session permission list wins when present, then —
for an account also flagged `is_customer` — that account's **customer**
permission list, and only otherwise its admin Roles. That precedence is not
acceptable as the load-bearing authority for the two platform-reserved acts
(migration-only establishment and platform termination): a session or
customer list could satisfy the check by name, while a dual admin+customer
account's genuine Role grant could be ignored. So the manager does **not**
use that Gate here; it reads admin Role permissions directly:

```php
$user !== null
    && $user->is_admin === true
    && ((int) $user->id === 1
        || $user->getPermissions()->contains('manage agency relationships'))
```

`User::getPermissions()` is the admin `Role` → `Permission` read. The
predicate never consults `Session` permissions, `customers.permissions`, or
any customer-side permission. User id 1
keeps the repository's existing "first user is always super admin"
convention, but only while it is still `is_admin`.
`EloquentAccountRepository::hasPermission()` itself is unchanged for every
other caller.

**Forbidden actors, explicitly tested (§13):** an inactive Agency member, a
member of a different Agency Workspace only, and a User with no membership
attempting create; user id 1 that neither owns nor belongs to the Agency
attempting create; any Agency Admin or Staff member attempting terminate;
the Client Workspace's own owner attempting either;
an unrelated third Workspace's owner attempting either; any admin-panel
user without the new `manage agency relationships` Role permission
attempting terminate; any admin-panel user attempting normal `create()` on
an Agency they neither own nor are a permitted active member of, including
one holding `manage agency relationships` (platform status never grants it
— Addendum §10's "administrator never originates on a customer's behalf"
posture extends here by the same reasoning, even though §2 doesn't name
creation explicitly, treating creation and termination symmetrically for
platform-admin exclusion is the conservative, non-guessing choice; the
operator-run migration is the one sanctioned exception, isolated in its own
entry point above — while a User who is both an admin and genuinely the
Agency owner or an active member still creates through that Agency-side
authority); for `createForMigration()`: a non-admin user, an admin-panel
user lacking the dedicated permission, and any Agency owner/member or
customer actor; and, for both platform-reserved acts: a
dual admin+customer account whose permission text sits only in its
customer permission list, and an admin whose session permission list — but
no admin Role — names `manage agency relationships`.

**Relationship existence is structural, not entitlement proof — the
distinction this remediation requires be explicit.** `create()` and
`createForMigration()` assert `$lockedAgencyWorkspace`'s management
eligibility — Agency plan tier (via the same `workspace_plan_assignments` →
`workspace_plan_catalog` read `EntitlementManager` already performs) **and**
a usable account per `CustomerAccountAccessResolver` — **only at creation
time**, before inserting the row. This is a one-time gate on *establishing*
the relationship, not a standing guarantee. **An
`Active` relationship row, by itself, is never sufficient proof that the
managing Workspace currently holds Agency entitlement** — if that
Workspace's tier is later downgraded away from Agency, the relationship
row stays `Active` (this manager has no downgrade-triggered termination
logic, and none is added here), but every Agency-only *capability* that
consumes the relationship (Contract 04's View As, Contract 09's
AgencyRebill, Contract 08A's Clients UI) **must independently re-check
current Agency management eligibility each time it acts**, via
`agencyWorkspaceHasManagementEligibility()`, not infer it from the
relationship's mere existence.
This mirrors how `WorkspaceMembership.role` isn't proof of a live
permission grant either — the relationship row is the *link*, entitlement
is a *separate, currently-true fact* downstream consumers must check for
themselves. §4/§6 of Contract 04 and Contract 09 apply this rule
explicitly at their own consumption points.

## 7. Transaction / concurrency boundary

`create()`: `DB::transaction()`, lock the Agency Workspace row via
`WorkspaceRepository::findForUpdate()` (existing method, reused, not
duplicated) to serialize concurrent relationship-creation attempts for the
same Agency, then lock the Client Workspace row the same way, in ascending
`id` order (matching `WorkspaceManager::reassignBusiness()`'s own
documented lock-ordering discipline for exactly this two-Workspace-lock
shape) to avoid a cross-operation deadlock symmetric with any other
two-Workspace lock this codebase takes. Within the transaction: assert no
existing `Active` relationship for the Client Workspace (the generated-
column unique index is the hard backstop; the transaction plus row locks
prevent the race from ever reaching that constraint under normal operation).
`terminate()`: locks only the relationship row itself via `findForUpdate()`-
style locking on the new repository, plus the Agency Workspace row (to
authorize), in that order.

**Race case:** two concurrent `create()` calls for the same Client
Workspace from two different Agencies — the row lock on the Client
Workspace (taken in both transactions) serializes them; the second to
commit sees the first's row via its own lock wait, re-checks "no existing
Active relationship," and fails cleanly with a new
`ClientWorkspaceAlreadyManagedException` rather than relying solely on the
unique-index exception path. `createForMigration()` (§6) runs through the
same establishment routine, so this boundary is identical for it, and a
normal `create()` racing a `createForMigration()` for the same Client
Workspace is serialized by the same lock and refused by the same rule.

**Idempotency:** `create()` is not itself retried/idempotent-keyed — it is
a single human-initiated action (Addendum §2's own framing, "explicitly
authorized manual lane"-style single action), not a paid or automated
side effect. A duplicate `create()` call for an already-actively-managed
Client Workspace throws rather than silently succeeding.

## 8. Migration / backfill

**Policy:** none. This is a net-new, empty-at-creation table — no existing
data maps to it, since no Agency↔Client relationship exists in any form
today (confirmed §3). No preflight/dry-run/resumability apparatus is
needed for *this* slice; Contract 10 (Slice 10) is where real data starts
populating this table, with its own full migration contract.

## 9. Backwards compatibility

No old path exists to preserve — this is additive. The one
forward-compatibility note: Contract 10's migration will be the first real
writer of rows here. It calls the migration-only
`createForMigration()` primitive (§6) — **not** normal `create()`, which
refuses the platform actor a migration operator is — passing an
already-known `agency_workspace_id`/`client_workspace_id` pair (no
interactive/UI-only assumption) and the real human operator's user id, so
Contract 10 reuses this slice's establishment logic rather than duplicating
relationship creation, and records the operator honestly.

## 10. Events / audit

- `AgencyClientRelationshipEstablished::dispatch($relationshipId, $agencyWorkspaceId, $clientWorkspaceId, $actorUserId)`.
- `AgencyClientRelationshipTerminated::dispatch($relationshipId, $agencyWorkspaceId, $clientWorkspaceId, $actorUserId, $reason)`.

Both carry the **real acting `User`'s ID**, never a viewed/impersonated
identity (this slice has no View As concept yet, but the convention is set
here for Contract 04 to inherit). A relationship established through
`createForMigration()` dispatches the same `AgencyClientRelationshipEstablished`
event with the real migration operator as actor — no separate event type. The relationship row itself
(`established_by_user_id`/`terminated_by_user_id`/`established_at`/
`terminated_at`/`termination_reason`) is the durable audit record — no
separate `agency_client_relationship_transitions` table is needed at this
slice's scope, since there are only two possible transitions total
(establish, terminate) and both are fully captured on the one row; this
differs from `workspace_transitions`, which needed a separate table because
it audits a much larger, repeating transition surface (Business
reassignment, ownership transfer) against Workspaces that already have
many other columns. Re-evaluate this if a future slice needs re-assigning
a Client Workspace between Agencies (not in scope here — Addendum §2 states
0-or-1 managing Agency, with no "reassign" concept defined).

## 11. Billing/provider safety

Not applicable to this slice. No payer, wallet, cap, entitlement-gated
paid action, STOP/DND, or provider call is touched — this is a pure
relationship/authorization primitive. (Contract 09/AgencyRebill is where
billing safety becomes load-bearing.)

## 12. Exact implementation allowlist

**New files:**
- `database/migrations/2026_09_20_100006_create_agency_client_workspace_relationships_table.php` (final merged V1 sequence position: after Contract 02's `2026_09_20_100001`–`100004` and Contract 03's `100005`)
- `app/Models/AgencyClientWorkspaceRelationship.php`
- `app/Enums/Workspace/AgencyClientRelationshipStatus.php`
- `app/Repositories/Contracts/AgencyClientWorkspaceRelationshipRepository.php`
- `app/Repositories/Eloquent/EloquentAgencyClientWorkspaceRelationshipRepository.php`
- `app/Library/Workspace/AgencyClientRelationshipManager.php`
- `app/Events/Workspace/AgencyClientRelationshipEstablished.php`
- `app/Events/Workspace/AgencyClientRelationshipTerminated.php`
- `app/Exceptions/Workspace/UnauthorizedAgencyRelationshipManagementException.php`
- `app/Exceptions/Workspace/ClientWorkspaceAlreadyManagedException.php`
- `app/Exceptions/Workspace/AgencyClientSelfLinkException.php`
- `app/Exceptions/Workspace/AgencyWorkspaceNotEligibleException.php` (wrong tier, or an unusable Agency account)
- `tests/Feature/Workspace/AgencyClientRelationshipManagerTest.php`
- `tests/Feature/Workspace/AgencyClientRelationshipConcurrencyTest.php` — test-only. §13's concurrency proof needs committed rows visible to a second connection and a second OS process, which `RefreshDatabase`'s open transaction hides, so it cannot share the class above (the same split `WorkspaceManagerTest` / `WorkspaceManagerConcurrencyTest` already use).
- `tests/Feature/Workspace/Support/concurrent_agency_client_relationship_runner.php` — test-only child-process runner for that concurrency test (mirrors `concurrent_workspace_resolver_runner.php`, including its disposable-database guard).

**Existing files modified:**
- `app/Providers/AppServiceProvider.php` — one new binding line (~line 188–198 block).
- `config/customer-permissions.php` — originally added a `manage_agency_clients` entry; **removed again by the Contract 04 authority correction** (§6), leaving this file identical to its pre-Contract-01 content. No customer permission is part of V1 Agency authority.
- `config/permissions.php` — add the new admin-side `manage agency relationships` permission entry (mirroring this file's own existing `'view customer'`/`'edit customer'`-style key format), for the platform-reserved authority in §6: termination, and migration-only establishment through `createForMigration()`.

**No migrations to any existing table. No test file modified — only new
test files added.**

**Central-file flag:** `app/Providers/AppServiceProvider.php` is touched by
this slice and will also be touched by Contract 02 (Slice 3, Location ACL)
and Contract 03 (Slice 4 touches `CustomerAccountAccessResolver`, not this
file, so no conflict there) — see §17.

## 13. Required tests

`tests/Feature/Workspace/AgencyClientRelationshipManagerTest.php`:
- Happy path: Agency owner creates a relationship to a Client Workspace; row exists, `Active`, correct actor/timestamp.
- Authorization matrix: each forbidden actor in §6's table, individually.
- Adversarial: self-link rejected (`agency_workspace_id === client_workspace_id`); creating a second Active relationship for an already-managed Client Workspace rejected; creating from a non-Agency-tier Workspace rejected.
- Management eligibility (§6, Contract 04 correction): a Grace Agency can establish; a Locked, Inactive or Suspended Agency — still on the Agency tier — cannot, through either `create()` or `createForMigration()`.
- Ordinary authority (§6, Contract 04 correction): active Admin and Staff members establish by membership alone; an inactive member, a member of a different Agency Workspace only, and user id 1 without ownership/membership are refused.
- Termination: owner succeeds; Platform Owner succeeds; Admin/Staff/client fail; terminated row is never hard-deleted and is excluded from "find active" lookups.
- History: a terminated relationship's row remains queryable (audit read), distinct from "no relationship" for a never-managed Client Workspace.
- Migration-only establishment (§6): an admin-panel operator holding `manage agency relationships` succeeds through `createForMigration()` and is recorded as `established_by_user_id` and as the event actor; that same operator is still refused by normal `create()`; an admin lacking the permission, a non-admin whose own permission list names it, and any Agency owner/member or customer actor are refused by `createForMigration()`; self-link, wrong-tier, missing-Workspace and one-active-Agency protections apply identically to migration creation and across both entry points; migration creation takes the same locks in the same order; termination authority is unchanged for a migrated relationship.
- Platform relationship operator predicate (§6) reads admin Role permissions directly: a dual admin+customer account whose admin Role holds `manage agency relationships` may migrate even though the generic Gate misses the grant; the same dual account with the text only in its customer permission list may neither migrate nor terminate; an admin whose session permission list — but no Role — names it has no platform authority; a Role grant succeeds when the session and customer lists lack it; user id 1 keeps super-admin platform authority only while `is_admin`.
- Dual-role normal `create()`: an admin+customer User creates through genuine Agency ownership and through genuine active Agency membership, and is refused on an Agency where it has neither, whatever admin Role permissions it holds.

`tests/Feature/Workspace/AgencyClientRelationshipConcurrencyTest.php`:
- Concurrency: two concurrent `create()` calls for the same Client Workspace from different Agencies — exactly one succeeds; and a normal `create()` racing a `createForMigration()` for the same Client Workspace — exactly one succeeds, recording the winning real actor.

## 14. Acceptance criteria

1. Migration runs clean on a fresh test database.
2. `AgencyClientRelationshipManager::create()`/`createForMigration()`/`terminate()`/`findActiveFor*()` all pass their authorization and adversarial tests.
3. Zero existing files outside §12's allowlist are modified.
4. No existing test's behavior changes.
5. `git diff --check` clean; only the allowlisted files appear in the diff.

## 15. Non-goals

Does **not**: wire this relationship into `ViewAsManager` or any
controller (Contract 04); build the Agency "Clients" UI (Contract 07's
provisioning flow and its own UI slice); touch `PayerType`/AgencyRebill
(Contract 09); touch Location ACL (Contract 02); migrate any existing data
(Contract 10). Does not invent a "reassign managing Agency" operation —
out of scope per Addendum §2's own 0-or-1 framing.

## 16. Merge prerequisites

None beyond the Addendum, Blueprint, Traceability Matrix, Roadmap, and
Acceptance Matrix being merged to `main` (Roadmap Wave 0 gate). This is the
first implementation slice — no other slice's commits are required first.

## 17. Conflict map

| Other contract | Shared file/table | Posture |
|---|---|---|
| Contract 02 (Slice 3, Location ACL) | `app/Providers/AppServiceProvider.php` (new binding lines, different lines) | **Safe concurrent** — additive lines in the same file, low real conflict risk, but coordinate the merge order to avoid a trivial rebase |
| Contract 03 (Slice 4, lifecycle) | none | Safe concurrent |
| Contract 04 (Slice 2, View As) | consumes this slice's model/manager | **Serialize** — hard dependency, not concurrent |
| Contract 10 (Slice 10, migration) | writes rows into this slice's table | **Serialize** — hard dependency (this slice must be merged first) |

## 18. Implementation prompt

```
You are implementing Slice 1 of the V1 architecture migration for the
os-creator1/os-ai repository: the Agency<->Client Workspace relationship
foundation, per docs/product/implementation-contracts/
01-AGENCY-CLIENT-WORKSPACE-RELATIONSHIP.md on the agent/v1-master-product-
blueprint branch (or wherever that contract has since been merged to
main -- check both).

Before writing any code:
1. Fetch latest origin/main.
2. Verify the V1 Architecture Decision Addendum, Master Product Blueprint,
   and this implementation contract are present on main (or on the
   contract's own source branch if not yet merged -- if the contract
   itself is not reachable, STOP and report that prerequisite is missing).
3. Create a fresh branch for this slice only (e.g.
   agent/v1-slice-01-agency-client-relationship).
4. Re-read the full contract end to end.
5. Inspect the actual current state of every file named in the contract's
   "Current repository reality" and "Exact implementation allowlist"
   sections -- do not assume the contract's evidence is still accurate;
   if anything has changed (a method renamed, a pattern no longer used),
   STOP and report the contradiction rather than guessing which is
   authoritative.

Implement exactly the scope in this contract -- no more. Specifically:
do NOT wire this relationship into ViewAsManager, any controller, or any
route (that is a later slice). Do NOT implement Location ACL, AgencyRebill,
or any data migration. If you find yourself needing to touch a file
outside the contract's allowlist, STOP and report why before proceeding.

After implementing:
- Run the new focused test file(s) for this slice.
- Run the broader Workspace-domain regression (tests/Feature/Workspace/)
  to confirm nothing existing broke.
- Run git diff --check.
- Verify the diff touches only the contract's allowlisted files.
- Commit with a clear message describing exactly this slice's scope.
- Push the branch.

Do NOT create a pull request yourself if GitHub tooling is unavailable --
ChatGPT will review the pushed branch and create the PR through the GitHub
integration.

Return a full report: starting and final SHA, exact files changed, exact
tests run and their counts, and confirmation this slice's scope was not
exceeded. Do NOT begin or authorize any later slice (Location ACL, View As,
lifecycle, provisioning, AgencyRebill, or any migration) -- this prompt
covers Slice 1 only.
```
