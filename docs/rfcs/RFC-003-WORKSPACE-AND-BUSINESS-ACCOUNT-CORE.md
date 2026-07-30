# RFC-003 — Workspace and Business Account Core

**Status:** Ready for M1A implementation
**Version:** 1.3 (approved specification; see §1)
**Priority:** P0
**Target framework:** Laravel 12 / PHP 8.2
**Architecture constraint:** Extend the existing Ultimate SMS controller → repository → library → model structure. Do not introduce a new generic service-layer convention.
**Depends on:** RFC-001 Business Core (`businesses`, `business_locations`, `business_services`, `Customer`, `User`), existing authentication and platform-administrator authorization
**Enables:** RFC-004 Plans and Business Feature Entitlements, RFC-005 Business Usage Billing and Wallets

---

## 1. Status and purpose

RFC-001 established `Business` as the aggregate root for business identity, locations, and services, owned directly by a customer through `businesses.customer_id`. RFC-002 built the Opportunity Engine on top of that single-Business-per-customer shape.

Neither RFC gives the platform a way to represent more than one Business under a shared organizational, commercial, and team-access container. Today every Business is an island: one direct owner, no shared team membership, no way to group multiple Business accounts under one paying entity.

RFC-003 introduces that container: the **Workspace**. A Workspace is the universal top-level account structure for every customer, from a single-location Business Core customer to a multi-location Growth customer to an Agency managing many client Businesses. "Agency" is a plan/capability tier layered on top of this same structure in a later RFC, not a distinct tenant type.

**This revision (v1.1) corrects blockers raised in architecture review of v1.0.** The universal Workspace architecture is unchanged; what changed is sequencing, migration safety, and access-control granularity: v1.0 made `businesses.workspace_id` `NOT NULL` in the same milestone that also claimed to touch no existing write path — inconsistent, since the one existing Business-creation write path doesn't set `workspace_id`, so immediate enforcement would break it; §10/§23 now split that work into **M1A** (nullable schema foundation) and **M1B** (creation-path compatibility, then enforcement). v1.0 implied schema DDL and the data backfill could share one atomic transaction, which MySQL does not support for DDL the way it does DML; §10.1 now separates them. v1.0 granted every active Workspace member access to every Business in the Workspace — rejected as too coarse; §7.5/§9.3/§14 now add per-membership scoping (`all` vs. `selected`). v1.0 also understated which existing files this RFC touches; §6/§11.3 now name them explicitly.

**This revision (v1.2) corrects a second round of blockers.** v1.1's `WorkspaceRepository::findOrCreateForOwner()` silently assumed one Workspace per owner, contradicting §7.4's "a User may own multiple Workspaces." It is removed; §10.6/§13 now define a narrowly-scoped `WorkspaceManager::resolveLegacyOnboardingWorkspace()` used only by the legacy onboarding path, with a defined 10-step, lock-and-recheck algorithm that refuses to guess among multiple candidate Workspaces. v1.1 also had `EloquentBusinessRepository` resolve/create its own Workspace — a repository depending on Workspace-provisioning logic; §10.6/§12 now add an additive `BusinessRepository::createForCustomerInWorkspace()` that accepts an explicit `Workspace`, with `BusinessManager` (not the repository) calling the resolver first. The backfill's `customer_id`-grouping is now explicitly scoped as a one-time migration-compatibility policy, not a permanent domain rule (§10.7); the backfill algorithm now lives in a versioned, immutable action class that a thin console command wraps, invoked directly by the migration (§10.3); a non-zero post-backfill null count now fails loudly rather than being silently tolerated (§10.4); `business_access_scope` has no database default, so every membership-creation path must supply it explicitly (§9.2); and ownership transfer now unambiguously deactivates, never deletes, an incoming owner's prior membership row (§15).

Version 1.3 incorporates the final consistency review: per-customer transactional backfill processing, post-M1B removal of `createForCustomer()`, and milestone-aligned access testing.

This specification is approved for M1A implementation. M1A must stop and report independently before M1B begins. Approval of this specification does not authorize implementing M1B or later milestones in the same pass.

---

## 2. Context

```text
Platform owner
└── Workspace
    ├── Workspace members
    │   ├── Owner
    │   ├── Admin
    │   └── Staff
    └── Business accounts / locations
        ├── Business A
        ├── Business B
        └── Business C
```

A one-Business Core customer, a multi-location Growth customer, and an Agency customer all use this same structure. The UI may hide Workspace terminology for a simple one-Business customer, but the domain structure underneath remains universal — there is no separate "simple" schema and "Agency" schema.

RFC-003 is deliberately narrow. It answers one question: **how does a Business account attach to a shared, ownable, team-accessible container that can hold more than one Business, without breaking anything RFC-001 or RFC-002 already built?** Plans, entitlements, billing, and wallets all depend on this container existing first, which is why they are split into RFC-004 and RFC-005.

---

## 3. Scope

RFC-003 (Milestones **M1A** and **M1B**, this document's implementation boundary — see §23) covers:

- `workspaces` and `workspace_memberships` tables, the latter including `business_access_scope`.
- `workspace_membership_businesses`, the scoped Business-assignment table (§7.5, §9.3).
- `businesses.workspace_id`: added nullable in M1A, backfilled, and enforced `NOT NULL` only in M1B after the existing Business-creation write path is adapted (§10).
- `Workspace`, `WorkspaceMembership`, and `WorkspaceMembershipBusiness` models.
- `WorkspaceMembershipRole` and `WorkspaceBusinessAccessScope` enums.
- Relationships on `Workspace`, `WorkspaceMembership`, `User`, and `Business` (§11).
- `WorkspaceRepository`, `WorkspaceMembershipRepository`, and `WorkspaceMembershipBusinessRepository` contracts and Eloquent implementations, bound in the existing repository provider (§12).
- The M1B adaptation of the existing Business-creation write path — an additive `BusinessRepository::createForCustomerInWorkspace()` method plus a narrowly-scoped `WorkspaceManager::resolveLegacyOnboardingWorkspace()` (§10.6, §12.4, §13) — **additive modifications to existing files**, not new-file-only work.
- Schema, model, and repository tests, including creation-path-compatibility, backfill-safety, and scoped-access tests (§21).

RFC-003 also **documents** (without implementing in M1A/M1B) the domain rules that later milestones must honor: authorization algorithm, ownership transfer, Business creation/reassignment orchestration, deactivation, concurrency rules, and events. These are specified here so the schema and relationships built in M1A/M1B do not have to be redesigned when Milestone 2 (`WorkspaceManager`) is implemented.

---

## 4. Non-goals

RFC-003 does not implement: full `WorkspaceManager` orchestration beyond the one narrow M1B resolver (§13.1); HTTP controllers, routes, requests, or views for Workspaces; admin Workspace screens; plans, Workspace plan assignment, or Business slot limits (RFC-004); per-Business feature toggles or a platform feature registry (RFC-004); Business usage wallets, ledgers, payers, or auto-recharge (RFC-005); Stripe or any payment integration changes; an `Agency` model or `businesses.agency_id`; changes to `businesses.customer_id`, its meaning, or RFC-001/RFC-002 retrofits; changes to `users.parent_id` or legacy sub-account behavior; CRM, conversations, phone numbers, calendars, automations, or website isolation per Business (§8); and a generic tagging/annotated-tag release for RFC-003 (deferred to Milestone 6, §25).

---

## 5. Terminology

| Term | Meaning |
|---|---|
| Workspace | The universal top-level account container. Owns zero-or-more Businesses and has zero-or-more members. |
| Workspace owner | The single user identified by `workspaces.owner_user_id`. Not a membership row. |
| Workspace member | A user with an active `workspace_memberships` row, role `admin` or `staff`. |
| Business-access scope | Per-membership setting (`all` or `selected`) controlling which Businesses in the Workspace that member can see/act on, independent of role (§7.5). |
| Workspace-derived access | Authorization granted because a user is the Workspace owner or an active, in-scope Workspace member, as opposed to direct Business ownership. |
| Business | The GHL-like location/sub-account boundary (RFC-001 aggregate root). Belongs to exactly one Workspace once the M1B invariant is enforced (§10.7). |
| Direct Business ownership | The existing RFC-001 relationship via `businesses.customer_id`, unrelated to Workspace membership. |
| Legacy sub-account | A `users.parent_id` delegation relationship, unrelated to Workspaces (§6, §22). |
| Platform administrator | An existing backend account with `users.is_admin = true`, unrelated to Workspace roles. |

---

## 6. Current-state findings

Findings from reading the current implementation before drafting this RFC:

1. **`businesses.customer_id` references `users.id`**, not `customers.id` — the established Ultimate SMS tenant-ownership convention (RFC-001 AD-002). `Business::customer()` is `belongsTo(Customer::class, 'customer_id', 'user_id')`. RFC-003 preserves this exactly; `businesses.workspace_id` is an independent column added alongside it.
2. **`users.parent_id`** exists (added in `2025_05_29_183312_add_parent_id_to_users_table.php`) as a plain nullable `unsignedBigInteger` with no foreign-key constraint, exposed via `User::parent()`. It is legacy sub-account delegation and today has no relationship to Business ownership, billing, or team access. RFC-003 must not wire it into Workspace logic (§14, §22).
3. **Repository contract naming does not use an `Interface` suffix.** The actual convention is `App\Repositories\Contracts\BusinessRepository extends BaseRepository`, bound in `AppServiceProvider::register()` to `App\Repositories\Eloquent\EloquentBusinessRepository`. RFC-003 follows this exact convention (`WorkspaceRepository`, `WorkspaceMembershipRepository`, `WorkspaceMembershipBusinessRepository`) rather than the illustrative `...RepositoryInterface` naming used only as prose in RFC-001's narrative sections.
4. **`HasUid` (`App\Library\Traits\HasUid`)** auto-generates a `uid` on `creating()` and is used for routable/shareable entities (`Business`, `BusinessLocation`, `BusinessService`). `CustomerOnboarding` deliberately omits it as an internal, non-independently-routable state record. RFC-003 applies the same test: `Workspace` gets `uid` (it will be independently routable in later milestones); `WorkspaceMembership` and `WorkspaceMembershipBusiness` do not (both are always addressed through their parent Workspace/membership, mirroring `CustomerOnboarding`'s reasoning). **Migrations and the backfill command must not rely on `HasUid`'s model-event mechanism** — see §10.3.
5. **Platform-administrator access** is the existing `users.is_admin` flag, enforced independently in multiple layers (e.g. `EnsureUserIsAdministrator` middleware, `UserPolicy::before()`). RFC-003 does not add a new admin mechanism; §14 explicitly keeps platform-admin access as a path evaluated upstream of, and independently from, Workspace-derived access.
6. **`customers.company`** (text, nullable) exists and is used as an input to the Workspace-naming policy during backfill (§10.5). **`User::displayName()`** (`first_name . ' ' . last_name`) exists as an Eloquent model method, but per §10.3 the backfill and migrations must reconstruct the equivalent string from raw `users.first_name`/`users.last_name` columns via the query builder rather than calling that method, so backfill code has no dependency on the `User` Eloquent model.
7. **No `Workspace` or `Agency` model, migration, or table exists today.** This is greenfield within the existing `businesses`/`customers`/`users` structure.
8. **Exactly one production code path creates a `Business` row**: `App\Http\Controllers\Customer\BusinessOnboardingController` → `App\Library\Business\OnboardingManager::completeStep()`/related → `App\Library\Business\BusinessManager::createOrUpdateOnboardingBusiness()` → `App\Repositories\Eloquent\EloquentBusinessRepository::createForCustomer()`. The admin `BusinessController` has no create action (RFC-001 §16 — admin may view/edit/update-status only). This finding is what grounds the exact M1B file list in §10.6 — there is one write path to adapt, not several.
9. RFC-003's implementation **additively modifies existing files** in addition to adding new ones — it does not "add new files only." At minimum: `app/Models/Business.php` (add `workspace()` relationship), `app/Models/User.php` (add Workspace-ownership/membership relationships), `app/Providers/AppServiceProvider.php` (add repository bindings), and, in M1B specifically, `app/Repositories/Contracts/BusinessRepository.php` and `app/Repositories/Eloquent/EloquentBusinessRepository.php` (add `createForCustomerInWorkspace()`) plus `app/Library/Business/BusinessManager.php` (call the new resolver, §10.6, §12.4). §11.3 gives the complete file list.
10. RFC-001 and RFC-002 are complete and tagged (`rfc-001-business-core`, `rfc-002-opportunity-engine`); the working tree is otherwise clean at the time of this revision.

---

## 7. Universal Workspace model

### 7.1 One structure for every customer shape

There is exactly one domain shape, regardless of how many Businesses a customer has or what plan they are on:

```text
User (owner_user_id) ──owns──> Workspace ──has many──> Business
User (membership)    ──member of──> Workspace ──scoped to──> Business (subset or all)
```

A single-Business Core customer has one Workspace with one Business and no additional members. A multi-location Growth customer has one Workspace with several Businesses. An Agency customer has one Workspace with many unrelated client Businesses and several staff members, each possibly scoped to a subset of those Businesses. All three are the same tables, same relationships, same invariants. The UI is free to hide the word "Workspace" for the single-Business case, but it must not fork the underlying model to do so.

### 7.2 Ownership vs. membership vs. tenancy

Three distinct relationships must not collapse into one:

| Relationship | Column | Meaning |
|---|---|---|
| Workspace ownership | `workspaces.owner_user_id` | Sole authoritative owner. Not derived from membership. |
| Workspace membership | `workspace_memberships.(workspace_id, user_id, role, business_access_scope)` | Team access. `role` is management authority (`admin`/`staff`); `business_access_scope` is Business visibility (`all`/`selected`) — two independent axes (§7.5). Never includes an `owner` role row. |
| Direct Business ownership | `businesses.customer_id` | RFC-001's existing owner/contact authority for a specific Business. Independent of Workspace. |

A Workspace owner is not required to own a Business directly, and is not required to have a Customer billing profile merely to hold tenancy — owning a Workspace is sufficient standing on its own (§9.1).

### 7.3 Effective role hierarchy

For a given `(user, workspace)` pair, the effective Workspace role is resolved in this strict order:

1. **Owner** — `workspaces.owner_user_id === user.id`. This check alone is authoritative; no membership row is consulted or required. The owner always has access to every Business in the Workspace (§7.5) — an owner is never subject to `business_access_scope`, which only applies to membership rows.
2. **Admin** — an active (`is_active = true`) `workspace_memberships` row with `role = admin`.
3. **Staff** — an active `workspace_memberships` row with `role = staff`.
4. **No role** — none of the above.

A user can hold at most one effective role per Workspace. If the owner also happens to hold a membership row (which transfer logic must resolve, §15), the owner check still wins — role is never "upgraded" or "downgraded" by a coexisting membership row.

### 7.4 Multi-Workspace membership

A User may belong to multiple Workspaces — as owner of one, admin of another, staff of a third. Nothing in this schema constrains a user to a single Workspace. `workspace_memberships` has no uniqueness constraint on `user_id` alone, only on `(workspace_id, user_id)` (§9.2).

### 7.5 Business-scoped Workspace membership access

Architecture review rejected the v1.0 shape, in which every active admin/staff member automatically saw every Business in the Workspace. **Role and Business scope are two independent axes**, both living on the membership row and its scoped-assignment children: **`role`** (`admin`/`staff`, unchanged from v1.0) determines *management authority* — what a member may configure, invite, or change; **`business_access_scope`** (`all`/`selected`, new in v1.1) determines *Business visibility/access* — which Businesses a member can see or act on at all, independent of role.

A `staff` member with scope `all` sees every Business; an `admin` member with scope `selected` sees only Businesses explicitly assigned via `workspace_membership_businesses` (§9.3). Role never implies scope in either direction — both must be set explicitly per membership.

This scoping applies **only to membership-derived access**. It has no effect on the Workspace owner (always full access, §7.3) or on direct Business ownership via `businesses.customer_id` (an independent access path, §14).

---

## 8. Business-account boundary

A Business (RFC-001's `Business` model) is the GHL-like location/sub-account boundary. RFC-003 does not change what a Business *is* — it changes what a Business *belongs to* in addition to its existing direct owner.

Each Business will eventually have isolated CRM and contacts, conversations, phone numbers, calendars, automations, websites, feature settings, usage wallet, usage ledger, billing configuration, and monthly usage budget. None of these isolated modules are implemented by RFC-003. RFC-003 establishes only the account and tenancy boundary — the fact that a Business has exactly one Workspace and one direct owner — so that later RFCs can build those modules against a stable foreign key rather than guessing at tenancy later.

`BusinessLocation` (RFC-001 §8.2) remains an address/service-area **child of a Business**. It is not the SaaS sub-account boundary and RFC-003 does not change its relationship to `Business`. Do not confuse `BusinessLocation` (an address record) with "a location" in the informal sense of "a Growth customer's second location," which in this domain model is a second `Business` row under the same Workspace.

---

## 9. Database schema

All new tables use `id`, `created_at`, and `updated_at`. No database-native `ENUM` columns — string columns cast to string-backed PHP enums, matching RFC-001 AD-004. Enums live under `App\Enums\Workspace`, matching the existing `App\Enums\Business` convention: `App\Enums\Workspace\WorkspaceMembershipRole`, `App\Enums\Workspace\WorkspaceBusinessAccessScope`.

### 9.1 `workspaces`

| Column | Type | Null | Default | Notes |
|---|---|---:|---|---|
| `id` | bigint unsigned | no | auto | Primary key |
| `uid` | uuid | no | auto | Public identifier for route binding (`App\Library\Traits\HasUid`), matching the `Business`/`BusinessLocation`/`BusinessService` convention |
| `name` | varchar(255) | no | — | Display name. See §10.5 for the backfill naming policy; user-created Workspaces (later milestones) supply this directly |
| `owner_user_id` | bigint unsigned FK | no | — | References `users.id`. Sole authoritative owner (§7.2) |
| `is_active` | boolean | no | true | Deactivation flag (§17) |
| timestamps | — | no | — | |

Indexes: unique `uid` (database-level, since migration/backfill code bypasses the Eloquent model, §10.3, and must not rely on `HasUid`'s generation alone), `owner_user_id`, `is_active`.

Foreign key: `owner_user_id` references `users.id`, `restrictOnDelete()` — a user with an owned Workspace cannot be deleted out from under it; ownership must be transferred first (§15), mirroring RFC-001's "no destructive deletion of populated aggregates" posture for `businesses`.

### 9.2 `workspace_memberships`

| Column | Type | Null | Default | Notes |
|---|---|---:|---|---|
| `id` | bigint unsigned | no | auto | Primary key |
| `workspace_id` | bigint unsigned FK | no | — | Parent Workspace |
| `user_id` | bigint unsigned FK | no | — | References `users.id` |
| `role` | varchar(32) | no | — | `WorkspaceMembershipRole`: `admin` or `staff`. Never `owner` (§7.2, §9.1) |
| `business_access_scope` | varchar(32) | no | **none** | `WorkspaceBusinessAccessScope`: `all` or `selected` (§7.5). **No database default (corrected from v1.1)** — every membership-creation path must supply it explicitly; no omitted value may silently grant `all`-Business access |
| `is_active` | boolean | no | true | Inactive membership confers no Workspace-derived access (§14) |
| timestamps | — | no | — | |

Indexes and constraints: unique `(workspace_id, user_id)` (one membership row per user per Workspace; role/scope changes update the existing row rather than inserting a second one), `user_id` (§7.4's "which Workspaces is this user a member of" lookups), composite `(workspace_id, is_active)` (active-members lookups).

Foreign keys (**revised from v1.0** — see §17 for the history-preservation rationale):

- `workspace_id` references `workspaces.id`, `restrictOnDelete()` — a database-level backstop since no hard-delete repository method exists for `Workspace` (§17); deactivation is the supported lifecycle operation.
- `user_id` references `users.id`, `restrictOnDelete()` — no concrete existing user-hard-deletion requirement was found during review that would justify `cascadeOnDelete()`/`nullOnDelete()`; restrict is the safe default until one is documented.

`workspace_memberships` deliberately has no `uid` column — always addressed through its parent Workspace, the same reasoning RFC-001 applied to `customer_onboardings` (§6, finding 4).

### 9.3 `workspace_membership_businesses` (new — scoped Business assignment)

Populated only for memberships with `business_access_scope = selected`; harmless but unused for `all`-scope memberships (application code never reads it for those rows, §14).

| Column | Type | Null | Default | Notes |
|---|---|---:|---|---|
| `id` | bigint unsigned | no | auto | Primary key |
| `workspace_membership_id` | bigint unsigned FK | no | — | The scoped membership this grant belongs to |
| `business_id` | bigint unsigned FK | no | — | The Business this membership is granted access to |
| timestamps | — | no | — | |

Indexes and constraints: unique `(workspace_membership_id, business_id)` (a Business cannot be assigned twice to the same membership), `business_id` ("which memberships can see this Business" lookups).

Foreign keys: `workspace_membership_id` → `workspace_memberships.id` and `business_id` → `businesses.id`, both `restrictOnDelete()` rather than cascade, consistent with §17 — a hard delete of a membership or Business is unsupported, so nothing should silently cascade-remove assignment rows either.

**Cross-Workspace leakage prevention.** No plain FK can express "this `business_id` must belong to the same Workspace as this membership's Workspace" — a cross-table equality, not a referential, constraint, and this schema uses no database triggers. This is enforced at the application layer inside the write that creates the assignment (§12.3) and covered by a dedicated test (§21) — a documented, deliberate trade-off, not an oversight.

### 9.4 `businesses` (alteration)

Add:

| Column | Type | Null | Default | Notes |
|---|---|---:|---|---|
| `workspace_id` | bigint unsigned FK | yes in M1A → no in M1B (§10) | — | References `workspaces.id`. Nullable through the entirety of M1A; only becomes `NOT NULL` in M1B, after the creation-path fix and re-verification (§10.6) |

Indexes (added by the M1B enforcement migration, §10.1): `workspace_id`, composite `workspace_id, status` (mirrors the existing `customer_id, status` index, RFC-001 §8.1, for Workspace-scoped Business listing).

Foreign key (added by the M1B enforcement migration, not earlier): `workspace_id` references `workspaces.id`. **Not** `nullOnDelete()` — Workspace deletion must never silently detach Businesses. Use `restrictOnDelete()`, consistent with §9.1, §9.2, and §17: a Workspace with attached Businesses cannot be deleted at the database level regardless of what application code attempts.

`businesses.customer_id` is not modified, renamed, or reinterpreted by this migration. See §11.2 for how the two coexist.

---

## 10. Existing-data migration and backfill

### 10.1 Migration sequence

Six migrations. DDL and the data backfill are kept **separate** — MySQL DDL is not part of the surrounding transaction the way DML is, so this RFC does not claim, and implementation must not rely on, one atomic transaction spanning both.

1. `create_workspaces_table` — DDL only. `workspaces` per §9.1, including the unique `uid` index.
2. `create_workspace_memberships_table` — DDL only. `workspace_memberships` per §9.2, including `business_access_scope`.
3. `create_workspace_membership_businesses_table` — DDL only. Per §9.3.
4. `add_nullable_workspace_id_to_businesses` — DDL only. Adds `businesses.workspace_id` **nullable**, no FK, no new index yet.
5. `backfill_business_workspaces` — **data operations only, no DDL.** Directly invokes the versioned backfill action (§10.3), not a console command, once as part of the M1A deploy; the same action is safely re-invoked later via its command wrapper (§10.6).
6. `enforce_business_workspace_constraint` — DDL only. `NOT NULL`, the `restrictOnDelete()` FK, and the two indexes from §9.4. **Ships only as part of M1B**, after the creation-path fix is deployed and the backfill re-verified to produce zero nulls — not part of the M1A deploy.

Rollback for migration 6 drops the constraint/indexes and reverts the column to nullable — it does not delete backfilled rows (RFC-001 §30: prefer forward fixes over dropping populated tables). Migration 5's `down()` is a no-op — reverting backfilled data is a separate, explicitly reviewed operation, not an automatic rollback. Migrations 1–4 roll back in reverse.

### 10.2 M1A — nullable persistence foundation

M1A ships migrations 1–5 above, plus models, relationships, and repositories. M1A explicitly does **not** enforce that every Business has a Workspace, and does **not** touch the existing Business-creation write path — enforcing the invariant before that path is adapted would break Business creation, which is exactly the inconsistency this revision corrects.

M1A scope:

- `workspaces`, `workspace_memberships`, `workspace_membership_businesses` schema (migrations 1–3).
- `businesses.workspace_id` added nullable (migration 4).
- An initial idempotent backfill run (migration 5, algorithm in §10.4) that assigns a Workspace to every Business that already exists at M1A deploy time.
- `Workspace`, `WorkspaceMembership`, `WorkspaceMembershipBusiness` models; `WorkspaceMembershipRole`, `WorkspaceBusinessAccessScope` enums.
- Relationships on `Workspace`, `WorkspaceMembership`, `User`, and `Business` (§11).
- `WorkspaceRepository`, `WorkspaceMembershipRepository`, `WorkspaceMembershipBusinessRepository` contracts and Eloquent implementations (§12.1–§12.3), plus provider bindings.
- Schema, model, and repository tests (§21.1–§21.4, §21.6).

**Existing Business creation must keep working unmodified throughout M1A.** Since `workspace_id` is nullable in M1A, `EloquentBusinessRepository::createForCustomer()` continues to insert rows exactly as it does today (RFC-001 behavior, unchanged) — those new rows simply have a null `workspace_id` until either the next backfill run or M1B's fix. M1A does not add any code to that method; §10.6 (M1B) is where it changes.

M1A stops and reports independently of M1B (§23).

### 10.3 Migration and backfill implementation safety

**Versioned action, not a console-command dependency.** The actual query-builder-only backfill algorithm lives in `App\Library\Workspace\Migration\WorkspaceBackfillV1`, a plain PHP class with no framework console dependency. Migration 5 instantiates and invokes `WorkspaceBackfillV1` **directly** — a historical migration must never invoke a console-command class, since command wiring can change independently of a migration that has already shipped. `WorkspaceBackfillV1` is treated as **immutable once migration 5 ships**: a future backfill correction is a new versioned class (`WorkspaceBackfillV2`, etc.), never an edit to `V1`, so replaying migration history always reproduces the same result. `App\Console\Commands\BackfillWorkspacesCommand` (`workspaces:backfill`) is only a **thin operator-facing wrapper** around `WorkspaceBackfillV1` — it parses CLI input/output and reports the action's result, and contains no algorithm of its own. The algorithm is implemented exactly once; neither the migration nor the command duplicates it.

`WorkspaceBackfillV1` must not depend on mutable Eloquent models (`Business`, `Workspace`, `User`, `Customer`) or their scopes/accessors, model events (including `HasUid::boot()`'s `creating()` hook), or `User::displayName()`/any other Eloquent instance method. Instead: use `DB`'s query builder (`DB::table('businesses')`, `'workspaces'`, `'customers'`, `'users'`) for every read/write; generate each new Workspace's `uid` explicitly with `Str::uuid()->toString()`, inserted directly rather than relying on `HasUid`; construct the deterministic Workspace name (§10.5) from raw `first_name`/`last_name`/`company`/business-`name` columns fetched via the query builder, reproducing `User::displayName()`'s output without calling it; and process customer groups in bounded chunks (e.g. `chunkById(500, ...)` over distinct `customer_id`) rather than loading the whole `businesses` table into memory.

### 10.4 Partial-state-safe, per-group transactional backfill algorithm

`WorkspaceBackfillV1`'s algorithm (§10.3). It groups pre-existing Businesses by `customer_id` as a **one-time migration-compatibility policy** for data that predates explicit Workspace-aware creation — not a permanent domain rule (§10.7 states the boundary explicitly). It must be safe to run against a database that already contains a partially-completed prior attempt (e.g. an earlier run that succeeded for some customers and was interrupted before finishing others).

**Each `customer_id` group is processed inside its own database transaction — corrected from v1.2, which described the per-group logic without stating the transaction/locking boundary.** For each `customer_id` that owns at least one Business with a null `workspace_id`, processed in chunks (§10.3):

1. Start a transaction for this group.
2. Lock the stable `users` row for `customer_id` with `SELECT ... FOR UPDATE` — the same serialization point `resolveLegacyOnboardingWorkspace()` uses (§13.1, §18), so a concurrent legacy-onboarding call and a concurrent backfill run for the same owner cannot race each other either.
3. Re-read, inside the lock, every Business owned by this `customer_id` and every **non-null** `workspace_id` currently present among them — this is what detects a prior partial run.
4. **Exactly one distinct Workspace found** → reuse it; assign it to every currently-null Business under this `customer_id`. **More than one distinct Workspace found** → the group is already inconsistent; throw a dedicated safe exception naming the offending `customer_id` and the conflicting `workspace_id` values, and roll back this group's transaction only — do not touch other groups. **None found** → create exactly one new Workspace, named per §10.5, owned by this `customer_id` (already `users.id`, RFC-001 AD-002).
5. When creating, the Workspace insert and every null-Business assignment happen inside the same transaction as step 4 — never assign to a Workspace whose insert hasn't yet committed.
6. Commit only after every assignment for this group has succeeded. Move to the next chunked group.

**Guarantees.** A failure after the Workspace insert but before all assignments complete rolls back the whole group (step 6) — it cannot leave an orphan Workspace with no Businesses assigned. A retry re-enters at step 3 and finds the Workspace already committed from a prior successful attempt (reuse path), so it **never creates a duplicate** merely because an earlier run partially completed. Two concurrent `WorkspaceBackfillV1` runs for the same `customer_id` serialize on the `users`-row lock (step 2) exactly as two concurrent `resolveLegacyOnboardingWorkspace()` calls do (§21.4 tests this explicitly). If a later group aborts on inconsistency (step 4), groups already committed earlier are unaffected and remain committed — the next run safely re-checks every group, including already-committed ones, via step 3's non-null read, and does nothing to a group that is already consistent. The final **global** null-count failure (below) is unchanged by this correction — it still runs once, after every group has been attempted, not per-group.

**Null-count failure (corrected from v1.1).** At the end of every run, `WorkspaceBackfillV1` executes `SELECT COUNT(*) FROM businesses WHERE workspace_id IS NULL`. A **non-zero result is a failed run**: the action throws/returns failure carrying the remaining count, and `BackfillWorkspacesCommand` surfaces it as a non-zero exit with that count — never a silent success. A customer that owns zero Businesses produces no null-Business row and is not a justification for any non-zero count; the count is either zero or the run has failed, with no excused middle case. M1B still performs its own **independent** zero-null pre-flight check before running the `NOT NULL` migration (§10.6) — defense in depth, not a substitute for this check.

**Deployment note.** Because each group commits independently, a Business created concurrently with migration 5 (via the still-nullable-tolerant `createForCustomer()`, §10.2) could in principle land with a null `workspace_id` after its group has already been processed. M1A's deployment procedure must either quiesce Business creation for the duration of migration 5, or treat any null-row appearing after the run as a failed migration to be safely retried (re-running `WorkspaceBackfillV1` is always safe per the guarantees above) — never as an accepted, permanent gap.

### 10.5 Deterministic Workspace naming policy

Backfilled Workspace names must be deterministic and documented, not invented ad hoc per row. For the Business owner identified by a given `customer_id`, resolve the name in this order (via query-builder reads, §10.3), taking the first non-blank result:

1. `customers.company` for the `customers` row where `customers.user_id = businesses.customer_id`, trimmed.
2. The `name` of that owner's primary Business (`is_primary = true`, RFC-001 §6.2) among the Businesses being grouped into this Workspace.
3. The `name` of the first Business being grouped into this Workspace, ordered by `businesses.id`.
4. `"{first_name} {last_name}'s Workspace"`, built from `users.first_name`/`users.last_name` fetched via the query builder — functionally equivalent to `User::displayName()`, but without invoking that Eloquent method (§10.3) — used only when none of the above yield a usable string.

This order prefers an existing organizational name (`company`) over a Business's trading name, and only falls back to a person's name when no business-identifying string exists anywhere in the owner's data. The same policy applies uniformly on every run of the routine — there is no per-row manual override.

### 10.6 M1B — creation-path compatibility and final enforcement

M1B is not implemented by this RFC document; it is specified here so M1A's schema does not need to change shape when M1B ships. **Generic Business creation requires an explicit target Workspace** (§7.4 — a User may own multiple Workspaces, so no generic "the owner's Workspace" resolution can exist). Only the one existing legacy onboarding path (§6, finding 8), which has no Workspace selector in its UI/request today, gets a narrow compatibility resolver. **M1B must complete, in this order, before `businesses.workspace_id` becomes `NOT NULL`:**

**1. Add an explicit-Workspace creation method, not repository-owned provisioning.** Exact files: `BusinessRepository.php`/`EloquentBusinessRepository.php` — add and implement `createForCustomerInWorkspace(Customer $customer, Workspace $workspace, array $attributes = []): Business`, additive alongside `createForCustomer()` (temporarily — see step 2); the repository takes `Workspace` as a plain argument, gaining **no new dependency** on `WorkspaceRepository`/`WorkspaceManager` — deliberate, to avoid a repository → manager dependency. `WorkspaceManager.php` — add the single narrowly-scoped `resolveLegacyOnboardingWorkspace(int $ownerUserId): Workspace` (full algorithm in §13). `BusinessManager.php` — modify `createOrUpdateOnboardingBusiness()` to call the resolver, then pass the resolved `Workspace` to `createForCustomerInWorkspace()`; the controller and `OnboardingManager` flow are unchanged. If a later audit finds a second creation path finding 8 didn't catch, add it here too, with its own explicit Workspace source, before step 2 proceeds.

**2. Verify and remove `createForCustomer()`.** Confirm by code search that no production caller remains — finding 8 (§6) establishes there was exactly one, now migrated. Then **remove it entirely** from `BusinessRepository.php` and `EloquentBusinessRepository.php`. This is the one place RFC-003 removes rather than only adds to an RFC-001 file (§11.3): a method that can still insert a Business without a Workspace has no safe purpose once every caller is migrated, and leaving it as reintroducible dead code is the unsafe state this step closes off. `createForCustomerInWorkspace()` is the sole supported creation method from here on.

**3. Re-run the backfill** (`php artisan workspaces:backfill`, wrapping the same `WorkspaceBackfillV1` action as §10.4) to sweep up any Business created during the M1A→M1B deploy window or missed by an incomplete audit. A non-zero remaining count fails the run (§10.4).

**4. Verify** `SELECT COUNT(*) FROM businesses WHERE workspace_id IS NULL` returns zero — an **independent** hard gate, separate from step 3's own internal check; step 5 does not run otherwise, regardless of how small the remainder.

**5. Enforce** — only after step 4 passes, run migration 6 (`enforce_business_workspace_constraint`, §10.1): `NOT NULL`, the `restrictOnDelete()` FK, and the `workspace_id`/`(workspace_id, status)` indexes (§9.4). Only after this does "every Business has exactly one Workspace" become database-enforced (§10.7) rather than an M1A-era best-effort backfill.

M1B stops and reports independently of M1A (§23).

### 10.7 Grouping guarantee, migration-only scope, and post-backfill invariant

Businesses sharing the same `customer_id` are grouped into exactly one Workspace, never split across two, on the initial run and every re-run — `WorkspaceBackfillV1` keys strictly off `businesses.customer_id` (existing, already-populated, non-nullable) and always re-checks existing assignments before creating (§10.4 step 1).

**This grouping is a migration-compatibility policy, not a permanent domain invariant.** It exists only to give pre-RFC-003 data a deterministic starting shape. Once Workspace-aware Business creation exists (M1B, §10.6), it is no longer true in general: one User may own multiple Workspaces (§7.4); Businesses sharing a `customer_id` may legitimately end up in different Workspaces, because explicit Workspace context — not `customer_id` — determines where a new Business is placed. `WorkspaceBackfillV1` and `BackfillWorkspacesCommand` are **versioned migration/deployment tools for the pre-M1B data set**, not a general-purpose tenancy reconciler to be re-run against future multi-Workspace data; nothing in this RFC re-applies customer_id-grouping logic after M1B ships.

"Every Business has exactly one Workspace" is a **best-effort, re-verifiable condition throughout M1A**, and becomes a **database-enforced invariant only once M1B's enforcement step (§10.6, step 5) has run.** Before that point a null `workspace_id` on a new Business is expected and safe; after, it is impossible by constraint. `businesses.customer_id` is read throughout but never written by any part of §10 (verified in testing, §21.4).

---

## 11. Relationships

### 11.1 New relationships

`Workspace`:

```php
public function owner(): BelongsTo; // belongsTo(User::class, 'owner_user_id')
public function memberships(): HasMany; // hasMany(WorkspaceMembership::class)
public function activeMemberships(): HasMany; // memberships()->where('is_active', true)
public function businesses(): HasMany; // hasMany(Business::class)
```

`WorkspaceMembership`:

```php
public function workspace(): BelongsTo;
public function user(): BelongsTo; // belongsTo(User::class, 'user_id')
public function businessAssignments(): HasMany; // hasMany(WorkspaceMembershipBusiness::class)
public function assignedBusinesses(): BelongsToMany; // belongsToMany(Business::class, 'workspace_membership_businesses')
```

`assignedBusinesses()` is meaningful only when `business_access_scope = selected` (§7.5); application code must not read it for `all`-scope memberships (§14) even though the relationship itself does not enforce that.

`WorkspaceMembershipBusiness`:

```php
public function membership(): BelongsTo; // belongsTo(WorkspaceMembership::class, 'workspace_membership_id')
public function business(): BelongsTo;
```

`Business` (addition):

```php
public function workspace(): BelongsTo; // belongsTo(Workspace::class)
```

This is added alongside the existing `Business::customer()` relationship (RFC-001 §9); neither replaces the other.

`User` (additions):

```php
public function ownedWorkspaces(): HasMany; // hasMany(Workspace::class, 'owner_user_id')
public function workspaceMemberships(): HasMany; // hasMany(WorkspaceMembership::class, 'user_id')
public function activeWorkspaceMemberships(): HasMany; // workspaceMemberships()->where('is_active', true)
```

A convenience `workspaces()` (all Workspaces this user is either owner or active member of) is deferred to Milestone 2, where it can be implemented against the authorization algorithm in §14 rather than as a raw Eloquent relationship that would need to union two different foreign keys.

### 11.2 How ownership and Workspace membership coexist

`businesses.customer_id` and `businesses.workspace_id` answer different questions and RFC-003 does not make one imply the other:

- **"Who is the direct owner/contact for this Business?"** → `businesses.customer_id` (RFC-001, unchanged).
- **"What organizational/commercial/team-access container is this Business inside?"** → `businesses.workspace_id` (this RFC).

They are not required to be consistent with each other beyond the invariant that both exist (post-M1B). A Business's direct owner (`customer_id`) is not required to be the Workspace owner (`workspaces.owner_user_id`), nor a Workspace member, of the Workspace that Business belongs to — a Business's `customer_id` could point at a different user than the Workspace's `owner_user_id` points at (e.g. a Business created by platform-admin tooling on a client's behalf inside an Agency's Workspace). RFC-003 does not forbid this shape; it only requires that authorization logic (§14) never conflate the two paths. RFC-004/RFC-005 may later add product-level rules constraining this relationship further, but no such constraint exists in RFC-003.

### 11.3 Complete file list

Corrects the v1.0 claim that this RFC "adds new files only" (§6, finding 9).

**New files** (M1A and M1B combined): `app/Enums/Workspace/{WorkspaceMembershipRole,WorkspaceBusinessAccessScope}.php`; `app/Models/{Workspace,WorkspaceMembership,WorkspaceMembershipBusiness}.php`; `app/Repositories/Contracts/{WorkspaceRepository,WorkspaceMembershipRepository,WorkspaceMembershipBusinessRepository}.php` and their `Eloquent*` implementations under `app/Repositories/Eloquent/`; `app/Library/Workspace/Migration/WorkspaceBackfillV1.php` (versioned backfill action, §10.3); `app/Console/Commands/BackfillWorkspacesCommand.php` (thin wrapper around it); `app/Library/Workspace/WorkspaceManager.php` (M1B ships only `resolveLegacyOnboardingWorkspace()`, §13); `app/Exceptions/Workspace/WorkspaceContextRequiredException.php` (§13); and the six migrations listed in §10.1.

**Modified existing files:**

```text
app/Models/Business.php                                    — add workspace() relationship (M1A)
app/Models/User.php                                         — add ownedWorkspaces()/workspaceMemberships()/
                                                                activeWorkspaceMemberships() (M1A)
app/Providers/AppServiceProvider.php                        — add repository bindings (M1A)
app/Repositories/Contracts/BusinessRepository.php           — add createForCustomerInWorkspace(); remove
                                                                createForCustomer() once unused (M1B, §10.6)
app/Repositories/Eloquent/EloquentBusinessRepository.php    — implement createForCustomerInWorkspace(), no
                                                                new constructor dependency; remove
                                                                createForCustomer() (M1B, §10.6)
app/Library/Business/BusinessManager.php                    — createOrUpdateOnboardingBusiness() calls the
                                                                resolver, then createForCustomerInWorkspace() (M1B)
```

No other existing file is required. A file discovered mid-implementation is a deviation to report at milestone stop, not something to do silently. This is the one file pair (`BusinessRepository`/`EloquentBusinessRepository`) where RFC-003 **removes** an RFC-001 method rather than only adding to it — see §10.6 step 2 for why.

---

## 12. Repository contracts

Following the established naming convention (§6, finding 3): no `Interface` suffix, contracts extend `BaseRepository`, bound in `AppServiceProvider::register()`'s `$bindings` array to `Eloquent*` implementations in `App\Repositories\Eloquent`.

### 12.1 `WorkspaceRepository`

```php
namespace App\Repositories\Contracts;

interface WorkspaceRepository extends BaseRepository
{
    public function findById(int $id): ?Workspace;

    public function findForUpdate(int $id): ?Workspace;

    public function findByUid(string $uid): ?Workspace;

    public function findOwnedBy(int $userId): Collection;

    public function create(array $attributes): Workspace;

    public function update(Workspace $workspace, array $attributes): Workspace;

    public function setActive(Workspace $workspace, bool $isActive): Workspace;

    public function businessesForWorkspace(Workspace $workspace): Collection;
}
```

`findForUpdate()` mirrors `BusinessRepository::findForUpdate()` (RFC-002 read-modify-write pattern, §6 finding 3) — Milestone 2's `WorkspaceManager` will need row-locked reads for ownership transfer and membership mutations (§18). Every method in this contract ships in M1A.

**Removed from v1.1: `findOrCreateForOwner(int $ownerUserId): Workspace`.** §7.4 permits a User to own multiple Workspaces, so no repository method can generically resolve "the" owner's Workspace — that framing assumed a permanent one-Workspace-per-owner invariant this RFC never actually establishes. The one caller that needs owner-scoped resolution is the legacy onboarding path, and it now uses the narrowly-scoped `WorkspaceManager::resolveLegacyOnboardingWorkspace()` instead (§10.6, §13) — a manager method with its own locking/recheck algorithm, not a general-purpose repository lookup.

### 12.2 `WorkspaceMembershipRepository`

```php
namespace App\Repositories\Contracts;

interface WorkspaceMembershipRepository extends BaseRepository
{
    public function findById(int $id): ?WorkspaceMembership;

    public function findByWorkspaceAndUser(Workspace $workspace, int $userId): ?WorkspaceMembership;

    public function activeForWorkspace(Workspace $workspace): Collection;

    public function activeForUser(int $userId): Collection;

    public function create(Workspace $workspace, int $userId, WorkspaceMembershipRole $role, WorkspaceBusinessAccessScope $scope): WorkspaceMembership;

    public function updateRole(WorkspaceMembership $membership, WorkspaceMembershipRole $role): WorkspaceMembership;

    public function updateBusinessAccessScope(WorkspaceMembership $membership, WorkspaceBusinessAccessScope $scope): WorkspaceMembership;

    public function setActive(WorkspaceMembership $membership, bool $isActive): WorkspaceMembership;
}
```

`create()` must be idempotent-safe against the `(workspace_id, user_id)` unique constraint (§9.2) — Milestone 2's `WorkspaceManager` is responsible for catching the constraint violation and treating a duplicate `create()` as "update the existing row" rather than surfacing a raw database exception to a controller (§14 security invariant: "duplicate membership creation must be idempotent or fail safely").

### 12.3 `WorkspaceMembershipBusinessRepository` (new)

```php
namespace App\Repositories\Contracts;

interface WorkspaceMembershipBusinessRepository extends BaseRepository
{
    public function assignedBusinessIds(WorkspaceMembership $membership): Collection;

    public function isAssigned(WorkspaceMembership $membership, int $businessId): bool;

    // Must verify $business->workspace_id === $membership->workspace_id before writing, and throw
    // a safe, typed exception otherwise (§7.5, §9.3) — no plain FK expresses that cross-table equality.
    public function assign(WorkspaceMembership $membership, Business $business): WorkspaceMembershipBusiness;

    // @param array<int,int> $businessIds — every ID must belong to the membership's Workspace (same check as assign()).
    public function syncForMembership(WorkspaceMembership $membership, array $businessIds): Collection;

    public function unassign(WorkspaceMembership $membership, int $businessId): void;
}
```

`unassign()` deletes an assignment (grant) row, not a `Workspace`, `Business`, or `WorkspaceMembership` row. Revoking a scoped grant is ordinary mutable state, not a tenancy-history deletion — it does not conflict with "no hard-delete repository methods" (§17), which targets the core tenancy entities specifically, not revocable join-table grants.

### 12.4 M1B: `BusinessRepository::createForCustomerInWorkspace()` added, `createForCustomer()` removed

`App\Repositories\Contracts\BusinessRepository` (RFC-001) gains one method, per §10.6:

```php
public function createForCustomerInWorkspace(Customer $customer, Workspace $workspace, array $attributes = []): Business;
```

`EloquentBusinessRepository` implements it by setting `$business->workspace_id = $workspace->id` before `save()`. The repository takes the `Workspace` as a plain method argument — it gains **no new constructor dependency** on `WorkspaceRepository` or `WorkspaceManager`. Resolving *which* Workspace to pass is entirely the caller's responsibility: `BusinessManager` calls `WorkspaceManager::resolveLegacyOnboardingWorkspace()` first (legacy path, §10.6) or receives an explicit Workspace from a Milestone-2 HTTP surface (§16), then calls this method.

`createForCustomer()` (RFC-001) exists only through M1A, where `workspace_id` is still nullable (§10.2's M1A guarantee). **It is not carried forward past M1B** — once the sole caller is migrated (§10.6 step 1) and confirmed to be the only one (step 2), `createForCustomer()` is removed from both this contract and `EloquentBusinessRepository`. `createForCustomerInWorkspace()` is the sole supported creation method from M1B onward; no method on this contract can create a Business without an explicit Workspace.

### 12.5 M1A/M1B boundary for repositories

M1A implements all of §12.1, §12.2, and §12.3 as plain data-access methods. It does **not** implement step-order/business-rule validation beyond the single cross-Workspace check in §12.3 (e.g. "can this user be removed" is Milestone 2), transactional multi-row operations spanning `Workspace`/`WorkspaceMembership`, or any event-dispatching method — those belong to `WorkspaceManager` (§13, Milestone 2), exactly as RFC-001 split `CustomerOnboardingRepository` (data access) from `OnboardingManager` (state machine) — RFC-001 §34. `BusinessRepository::createForCustomerInWorkspace()` (§12.4) and `WorkspaceManager::resolveLegacyOnboardingWorkspace()` (§13) both ship in M1B, as the one narrow exception to "no manager code before Milestone 2."

Bind all three interfaces in the existing `AppServiceProvider::register()` `$bindings` array. Do not create a competing provider.

---

## 13. WorkspaceManager responsibilities

`WorkspaceManager` (`app/Library/Workspace/WorkspaceManager.php`) is specified here so M1A/M1B's schema and relationships do not need to change shape when it is built in Milestone 2. **No part of §13 is implemented in M1A. M1B implements exactly one narrowly-scoped method — `resolveLegacyOnboardingWorkspace()` below — as a compatibility exception; every other responsibility in this section remains Milestone 2.**

### 13.1 `resolveLegacyOnboardingWorkspace()` (M1B)

```php
public function resolveLegacyOnboardingWorkspace(int $ownerUserId): Workspace;
```

Exists **only** because the current onboarding path (§6, finding 8) has no explicit Workspace selector in its UI or request — it is a compatibility shim for that one path, not a general "get my Workspace" convenience. Every other Business-creation surface accepts an explicit Workspace (§16) and never calls this method. Algorithm:

1. Start a DB transaction.
2. Lock the `users` row for `ownerUserId` with `SELECT ... FOR UPDATE` — there is no Workspace row to lock when none exists yet, so the stable `users` row is the serialization point instead (§18).
3. Re-read the owner's existing Business/Workspace state inside the transaction (post-lock, not from a pre-lock read).
4. If the owner's existing onboarding record or primary Business already identifies exactly one Workspace, reuse it.
5. Else, if the owner has no Business-linked Workspace and owns no Workspace at all, create the owner's first Workspace (named per §10.5's policy).
6. Else, if exactly one other otherwise-valid candidate Workspace exists for this owner, reuse it.
7. If **more than one** candidate Workspace exists, throw `WorkspaceContextRequiredException` — a safe, typed failure, not a fallback.
8. Never pick the first of several candidates arbitrarily (step 7 exists precisely to prevent that).
9. Never treat "the owner has multiple Workspaces" by itself as an inconsistency to repair — §7.4 makes that a valid, permanent state; only step 7's *ambiguous-for-this-call* case is an error, not the underlying data.
10. Commit the transaction and return the resolved Workspace only after it completes — nothing is returned from a still-open transaction.

### 13.2 Full Milestone-2 responsibilities

Create a Workspace (optionally with its first Business, one transaction); rename and deactivate/reactivate a Workspace (§17); add a member (idempotent, §12.2) including initial `business_access_scope`; change a member's role or scope, including clearing/populating `workspace_membership_businesses` rows as scope changes between `all` and `selected`; assign/unassign a Business to a member's scoped-access list, validating same-Workspace membership (§7.5, §12.3); deactivate/reactivate a member; create a Business inside an explicit target Workspace; assign/reassign a Business to a different Workspace (§16); transfer ownership (§15); and assert Workspace access, Workspace management authority, and Business access through a Workspace — the last composed with, not replacing, direct Business-ownership checks and `business_access_scope` (§14).

Every mutating method re-checks ownership/authority even when the caller already authorized the request, matching RFC-001's `BusinessManager` posture (RFC-001 §11.1).

---

## 14. Authorization and access algorithm

Access is evaluated along three paths, but — **corrected from v1.0** — they are no longer independent of Workspace state. An inactive Workspace now blocks all customer-side access to its Businesses, including direct ownership. Platform-administrator access remains the one path evaluated entirely outside this algorithm.

1. **Platform-administrator access** — `users.is_admin` (§6, finding 5), governed entirely by existing backend authorization (`EnsureUserIsAdministrator`, `UserPolicy::before()`, the `can:access backend` gate). Checked upstream of, and independently from, everything below. RFC-003 does not add to, wrap, or duplicate this path.
2. **Direct Business-owner access** — `businesses.customer_id === current user's id` — **now gated behind the Workspace being active** (see §14.1). This is a deliberate behavior change from v1.0, where direct ownership always short-circuited to `true` regardless of Workspace state.
3. **Workspace-derived access** — the current user is either the Workspace owner, or holds an active membership whose `business_access_scope` covers this Business (§7.5) — likewise gated behind the Workspace being active.

### 14.1 Business-access algorithm (specified for Milestone 2, not implemented in M1A/M1B)

```text
function userCanAccessBusiness(user, business):
    if user.is_admin:
        return true  // platform administrator; governed upstream by existing backend authorization

    workspace = business.workspace
    if workspace is null or workspace.is_active == false:
        return false  // inactive (or, pre-M1B, still-unassigned) Workspace blocks ALL
                       // customer-side access to this Business, including direct ownership

    if business.customer_id == user.id:
        return true  // direct ownership — evaluated only once the Workspace gate above has passed

    if workspace.owner_user_id == user.id:
        return true  // Workspace owner always has full access, never scope-limited (§7.3)

    membership = activeMembership(workspace, user)
    if membership is null:
        return false

    if membership.business_access_scope == ALL:
        return true

    // business_access_scope == SELECTED
    return WorkspaceMembershipBusiness.exists(membership, business)
```

`workspace is null` only occurs during M1A, before every Business is guaranteed to have a Workspace (§10.7); post-M1B it cannot happen, but the algorithm checks defensively rather than assuming the invariant always held for every historical row.

### 14.2 Rules that must hold

- An inactive Workspace blocks all customer-side access, including the direct owner and the Workspace owner (§14.1) — corrected from v1.0's "direct ownership always wins." Platform-administrator access is unaffected by any Workspace's `is_active` state, being checked entirely upstream.
- Inactive memberships confer no Workspace-derived access. `business_access_scope = selected` only narrows access, never expands it beyond what `all`-scope or the owner would have; role never implies a particular scope — the two are independent and both must be read.
- UI visibility is not authorization; Workspace plan name is not authorization (plans don't exist yet — forward-documented for RFC-004).
- `workspaces.is_active` is a **tenancy-access** flag, not billing state. **Billing cancellation is not represented by `is_active`** (§17) — RFC-004's entitlement rule may disable paid execution while `is_active` stays `true` and ordinary account access continues; conflating the two would let a billing lapse lock a customer out of their own data.
- Every mutating operation locks and rechecks authoritative rows (`findForUpdate()`, §12.1) inside its transaction. Duplicate membership creation (§12.2) and a scoped assignment outside its membership's Workspace (§9.3, §12.3) must both fail safely, enforced at write time.
- Cross-Workspace Business reassignment must be explicit and audited (§16, §19). Platform-admin behavior remains governed entirely by existing backend authorization; RFC-003 adds nothing to that path.

---

## 15. Ownership-transfer rules

Specified for Milestone 2; not implemented in M1A/M1B.

Transferring a Workspace changes exactly one column **on the `workspaces` table**: `owner_user_id`. Membership rows are a separate matter — the algorithm below may deactivate or update `workspace_memberships` rows transactionally as part of the same operation (steps 2 and 4), which is expected and does not contradict this single-column-on-`workspaces` scope. Transfer must never change:

- `businesses.customer_id`
- `businesses.workspace_id`
- Any Business data
- Usage balances (not yet implemented, but the rule holds prospectively for RFC-005)
- Any future billing configuration (RFC-005)
- Any existing `workspace_membership_businesses` scoped-assignment rows other than the incoming/outgoing owner's own membership row (§7.5's owner exemption means the owner never had scoped rows to begin with, so this is normally a no-op, not a data migration)

Transfer algorithm:

1. Lock the Workspace row (`findForUpdate()`).
2. If the incoming owner already holds an active `workspace_memberships` row for this Workspace, it is **deactivated transactionally** as part of the same operation that sets `owner_user_id` — **never deleted**. The incoming owner cannot simultaneously be a membership row and the `owner_user_id`, since owner status is derived solely from `owner_user_id` (§7.2, never a membership role), but the row itself is history and stays, consistent with the restrictive-deletion policy (§17) — deactivation, not deletion, is unambiguous here.
3. Set `owner_user_id` to the incoming owner.
4. Resolve treatment of the previous owner **explicitly**, per the caller's specified intent — one of:
   - Deactivate the previous owner's Workspace access entirely (no membership row created; if one already existed for some other reason, it stays deactivated, not deleted).
   - Convert the previous owner to an active `admin` membership row, only when specifically requested by the caller — the caller must also specify the resulting `business_access_scope` (§7.5); there is no implicit default for a converted-owner membership.

   `WorkspaceManager::transferOwnership()` must not silently default either choice — the caller supplies both. Scoped grant rows (`workspace_membership_businesses`) tied to any deactivated membership may still be revoked as ordinary mutable assignment state (§12.3, §17) — that carve-out is unaffected by this section.
5. Dispatch a Workspace-ownership-transferred event after commit (§19).

---

## 16. Business creation and reassignment

Specified for Milestone 2's full orchestration; not implemented in M1A/M1B. This is distinct from, and builds on top of, the minimal M1B fix in §10.6/§13.1 — that fix only makes the existing single legacy write path Workspace-aware, via one narrow resolver plus an additive repository method, so the schema invariant can be enforced; it does not add events, audit records, or a general "create Business inside any Workspace I choose" capability. Those are Milestone 2 features, specified here so §10.6's shape does not conflict with them later.

### 16.1 Creation

- Creating a Workspace may create its first Business in the same transaction (`WorkspaceManager`, §13).
- Creating additional Businesses later must not require restructuring the Workspace — no schema change, no re-parenting of existing Businesses, no migration.
- The same Workspace shape supports, without modification: branches of one brand, unrelated Agency clients, and internal Agency Businesses (§7.1). RFC-003 does not distinguish these cases in the schema; they are the same row shape with different data.
- Plan rules governing how many Businesses may be created under a Workspace are deferred to RFC-004 (§26). M1A, M1B, and Milestone 2 impose no numeric limit.

### 16.2 Reassignment

- Reassigning an existing Business to a different Workspace is an explicit operation (`WorkspaceManager::reassignBusiness()`), never an implicit side effect of another operation.
- It changes only `businesses.workspace_id`. It does not change `businesses.customer_id`.
- Any `workspace_membership_businesses` assignment rows that referenced this Business under its *old* Workspace's memberships become invalid (§9.3's Workspace-equality rule) and must be removed as part of the same transaction — reassignment must not leave a dangling scoped grant pointing at a membership in a different Workspace than the Business now belongs to.
- It must be audited (§19) — cross-Workspace Business reassignment is exactly the kind of event the durable transition/audit trail exists for.
- It must go through the same locking discipline as any other mutating Workspace operation (§18).

---

## 17. Deactivation and deletion

- Deactivation uses `workspaces.is_active` (a boolean flag, matching RFC-001 AD-004/§8.1's `status`-flag-over-native-enum posture, though here a simple boolean is sufficient — there are only two states). It preserves Businesses, memberships, and history; it is not a delete and must not cascade any data removal.
- **`workspaces.is_active` represents tenancy/access state, not billing state.** Do not repurpose it to represent subscription cancellation. RFC-004's plan/entitlement layer and RFC-005's billing layer may independently disable *paid feature execution* for a Workspace (e.g. a lapsed subscription blocking new sends) while `is_active` stays `true` and the customer can still log in and view their account. Setting `is_active = false` is reserved for an intentional, tenancy-level deactivation (e.g. an administrator or the owner deliberately suspending the whole Workspace), not an automatic side effect of a billing event.
- **Foreign-key policy is restrictive, not cascading, across every table introduced by this RFC** (§9.1, §9.2, §9.3, §9.4) — `businesses.workspace_id`, `workspace_memberships.workspace_id`, `workspace_memberships.user_id`, `workspace_membership_businesses.workspace_membership_id`, and `workspace_membership_businesses.business_id` all use `restrictOnDelete()`. Nothing in this schema silently deletes tenancy history as a side effect of deleting something else.
- **No repository in §12 exposes a hard-delete method for `Workspace`, `WorkspaceMembership`, or `Business`.** Deactivation (`setActive(false)`) is the only supported lifecycle-removal operation for those entities. The one exception is `WorkspaceMembershipBusinessRepository::unassign()` (§12.3), which deletes a scoped-access *grant* row — not a tenancy entity — and is ordinary revocable state, not history.
- An inactive Workspace confers no customer-side access at all, including to the direct Business owner and the Workspace owner (§14.1) — a correction from v1.0, where inactive Workspaces blocked only Workspace-*derived* access and left direct ownership untouched. Its data — Businesses, memberships, history — remains fully intact and reactivatable regardless.

---

## 18. Concurrency and transaction rules

Specified for Milestone 2's mutating operations; M1A/M1B have no full `WorkspaceManager`, so most of this section constrains future implementation. The exceptions are §13.1's `resolveLegacyOnboardingWorkspace()` (M1B) and §10.4's per-group `WorkspaceBackfillV1` processing (M1A), both of which must follow the locking discipline below today, not only in Milestone 2.

- Every mutating Workspace or membership operation runs inside a database transaction.
- Every mutating operation locks the authoritative row(s) it modifies via `findForUpdate()` before reading state used in a subsequent write (RFC-002's read-modify-write pattern, reused rather than reinvented, §6 finding 3).
- **Locking an absent Workspace query is insufficient** — when an owner has no Workspace yet, there is no Workspace row to lock, so a query-only guard cannot serialize concurrent first-Workspace provisioning. `resolveLegacyOnboardingWorkspace()` (§13.1) and `WorkspaceBackfillV1`'s per-group processing (§10.4) both instead lock the stable `users` row for the owner with `SELECT ... FOR UPDATE`, then re-read Workspace/Business state inside that same transaction, so two simultaneous attempts — legacy-onboarding, backfill, or one of each — for the same never-before-seen owner cannot both decide to create a first Workspace. There is deliberately **no unique constraint on `workspaces.owner_user_id`** (§9.1) — a User may legitimately own multiple Workspaces (§7.4) — so uniqueness is not the serialization mechanism; the `users`-row lock is.
- Ownership transfer (§15) locks the Workspace row for the duration of the operation, including the membership-deactivation step.
- Membership creation (§12.2) must handle the `(workspace_id, user_id)` unique-constraint race safely — either the lock prevents the race, or the resulting constraint violation is caught and treated as "already exists," never surfaced as an unhandled exception.
- Scoped-assignment creation (`WorkspaceMembershipBusinessRepository::assign()`, §12.3) must handle the `(workspace_membership_id, business_id)` unique-constraint race the same way — idempotent-safe, not an unhandled exception.
- Business reassignment (§16.2) locks the Business row being reassigned.

---

## 19. Events and audit requirements

Specified for Milestone 2; no events are dispatched by M1A or M1B — neither M1A's plain repository writes nor M1B's narrow `resolveLegacyOnboardingWorkspace()`/`createForCustomerInWorkspace()` compatibility path dispatch anything. Future events, following RFC-001's event conventions (immutable, IDs and scalar metadata only, dispatched after commit, RFC-001 §15): `WorkspaceCreated`, `WorkspaceRenamed`, `WorkspaceDeactivated`, `WorkspaceReactivated`, `WorkspaceOwnershipTransferred`, `WorkspaceMembershipCreated`, `WorkspaceMembershipRoleChanged`, `WorkspaceMembershipBusinessAccessScopeChanged`, `WorkspaceMembershipDeactivated`, `WorkspaceMembershipReactivated`, `WorkspaceMembershipBusinessAssigned`, `WorkspaceMembershipBusinessUnassigned`, `BusinessAssignedToWorkspace`, `BusinessReassignedToWorkspace`.

Durable audit requirement: cross-Workspace Business reassignment (§16.2) and ownership transfer (§15) must be recoverable from a durable record, not only the dispatched event (events can be missed by a listener). RFC-002's durable-transition-audit pattern (`opportunity_transitions`, RFC-002 §41) should be reused for a `workspace_transitions`-equivalent table rather than inventing a new mechanism. The concrete audit table is out of scope for M1A/M1B and specified in full when Milestone 2 is implemented.

---

## 20. Backward compatibility

- `businesses.customer_id` is unchanged in column, meaning, foreign key, and every existing query built against it (RFC-001's `BusinessRepository::findOwnedByCustomer()`, `findPrimaryByCustomer()`, etc. continue to work exactly as before).
- `users.parent_id` is unchanged. RFC-003 does not read, write, or branch on it.
- Every RFC-001 relationship (`Customer::businesses()`, `Customer::primaryBusiness()`, `Business::customer()`, `Business::locations()`, `Business::services()`) continues to function unmodified; RFC-003 only **adds** `Business::workspace()` alongside them.
- Every RFC-002 relationship and query (`Business::opportunityRuns()`, `Business::opportunities()`) is unaffected — RFC-002 code paths do not reference `workspace_id` and are not required to.
- No existing table is dropped, renamed, or has a column removed.
- **Existing Business-creation behavior is identical throughout M1A** (§10.2) — `workspace_id` stays nullable, so `createForCustomer()` is untouched and every existing caller sees no behavior change. It is **not** claimed identical after M1B ships: M1B migrates the one caller to `createForCustomerInWorkspace()` and then **removes `createForCustomer()`** from `BusinessRepository`/`EloquentBusinessRepository` (§10.6 step 2, §12.4) — this is the one place RFC-003 removes rather than only extends an RFC-001 method, and it is deliberate, not an oversight, so that no code path can attempt Business creation without an explicit Workspace once the constraint that depends on it (§10.6 step 5) is applied.
- No HTTP route, controller, or view from RFC-001/RFC-002 is touched.
- This RFC modifies existing files additively (§11.3) in addition to adding new ones, with the single removal noted above — see §6, finding 9 for the correction to v1.0's "new files only" claim.

---

## 21. Testing strategy

Use the project's existing PHPUnit/Pest convention (RFC-001 §31: do not mix). Tests are grouped by the milestone that introduces them; no manager/HTTP/UI tests exist yet because no manager/HTTP/UI code exists yet (§23).

### 21.1 Schema/migration (M1A)

- Workspace UID generation and database-level uniqueness on `workspaces.uid` (§9.1) — insert a duplicate directly via the query builder and assert rejection, not just that the app avoids generating one.
- Unique `(workspace_id, user_id)` on `workspace_memberships` and `(workspace_membership_id, business_id)` on `workspace_membership_businesses` enforced at the database level.
- All `restrictOnDelete()` FKs (§17) reject deletion of a referenced parent while child rows exist.
- `businesses.workspace_id` stays nullable throughout M1A's migrations (no premature `NOT NULL`).

### 21.2 Migration and versioned-action implementation safety (M1A)

- `WorkspaceBackfillV1` uses only `DB::table(...)` calls — no `Business`/`Workspace`/`User` Eloquent model is instantiated or queried, no Eloquent model event fires, and `uid` values are valid UUIDs from `Str::uuid()`, not `HasUid`'s model-event path.
- The action correctly processes more customer groups than fit in one chunk (§10.3's chunking is exercised, not just a small fixture set).
- **Migration 5 invokes `WorkspaceBackfillV1` directly**, not `BackfillWorkspacesCommand` (asserted via the migration's dependencies/instantiation), and **the command wraps that same class/instance** — running both against identical seed data produces identical results, proving no second implementation exists.
- **A non-zero remaining null count fails the run**: seed a row the action cannot resolve (or stub the final count check), and assert the action/command surfaces failure with the exact remaining count, not a silent success.

### 21.3 Model (M1A)

- `Workspace::owner()` resolves the correct `User`; a Workspace owner with no Business still loads.
- `role` casts to `WorkspaceMembershipRole`; `business_access_scope` casts to `WorkspaceBusinessAccessScope` with **no default** — an unset value on a freshly-instantiated model attribute is `null`, not `all` (§9.2).
- Active vs. inactive memberships are distinguishable via `is_active`.
- **A `User` owning more than one Workspace is valid, unremarkable model state** — own two Workspaces, assert both resolve via `User::ownedWorkspaces()` with no error, warning, or implied inconsistency (§7.4).
- `Business::workspace()` resolves correctly alongside `Business::customer()` resolving to a *different* user (§11.2 — independent relationships).
- `WorkspaceMembership::assignedBusinesses()` returns only Businesses present in `workspace_membership_businesses` for that membership.

### 21.4 Repository and backfill-specific tests (M1A)

- `WorkspaceRepository::findOwnedBy()` returns only Workspaces owned, not merely joined, by the user; `businessesForWorkspace()` and `WorkspaceMembershipRepository::activeForWorkspace()`/`activeForUser()` are explicitly scoped and exclude inactive rows.
- Every pre-existing Business receives exactly one non-null Workspace after the M1A backfill; Businesses sharing a `customer_id` are grouped into exactly one Workspace, not one each; `businesses.customer_id` is byte-for-byte unchanged before/after.
- The naming policy (§10.5) resolves in priority order: `customers.company` → primary Business name → first Business by ID → constructed `"{first_name} {last_name}'s Workspace"`.
- **Partial backfill reuses an existing Workspace**: seed a `customer_id` group with some Businesses already assigned and others null; re-running assigns the null ones to the *same* Workspace and creates no new one. **Aborts on inconsistency** when two Businesses in a group already reference two *different* non-null `workspace_id` values, throwing the documented exception with no further writes for that group and no silent skip-and-continue (§10.4). A `customer_id` with zero Businesses gets no Workspace created for it.
- **The `customer_id`-grouping rule is migration-only, not a permanent owner invariant** (§10.7): seed M1B-era data where two Businesses share a `customer_id` but were created into two different explicit Workspaces; re-running `WorkspaceBackfillV1` must not touch either row (both already non-null) or attempt to merge them — it only ever fills nulls, never reconciles already-assigned rows.
- **Per-group atomicity** (§10.4): force a failure after a group's Workspace insert but before its Business assignments commit — the whole group rolls back, leaving no orphan Workspace and no partially-assigned Businesses. **Concurrency**: two simultaneous `WorkspaceBackfillV1` runs for the same legacy `customer_id` create **exactly one** Workspace for that group — the `users`-row lock (§10.4 step 2, §18) serializes them.
- **Retry after a failed group creates no duplicate**: interrupt a run after one group commits and another aborts; re-run and assert the committed group's Workspace is reused, not duplicated, and the previously-aborted group resolves cleanly. **Previously committed groups remain safe to re-run**: re-running against a database where every group is already non-null makes no writes and reports zero remaining nulls.

### 21.5 M1B Workspace-context and legacy-resolver tests

- **Generic Business creation requires an explicit target Workspace**: `createForCustomerInWorkspace()`'s signature has no path to omit `Workspace`, and `EloquentBusinessRepository` never resolves one on its own. `createForCustomer()` exists and works during M1A (§10.2), producing a null `workspace_id`, against M1A-only migrations/code — asserted before the M1B removal ships.
- **`createForCustomer()` is absent from the post-M1B contract and implementation** (§10.6 step 2, §12.4): assert the method does not exist on `BusinessRepository`/`EloquentBusinessRepository` (e.g. via reflection), not merely that nothing calls it. **No database constraint exception is used as normal Workspace-context validation** — enforcement is asserted at the type/method-availability level (a missing parameter, a removed method), never by attempting an insert without `workspace_id` and catching the resulting `NOT NULL` violation.
- **Every post-M1B production Business-creation path requires an explicit Workspace**: trace `BusinessOnboardingController` → `OnboardingManager` → `BusinessManager::createOrUpdateOnboardingBusiness()` → `createForCustomerInWorkspace()` and assert every hop either supplies or resolves a `Workspace` before the repository is called.
- **Legacy resolver reuses** the primary/onboarding Business's Workspace when exactly one exists; **creates the first Workspace** when a first-time owner has none, returning the same Workspace on a second call rather than creating another; and **fails safely** with `WorkspaceContextRequiredException` — creating/mutating nothing — when two otherwise-valid candidates exist, never picking the first arbitrarily.
- **Concurrency**: two simultaneous `resolveLegacyOnboardingWorkspace()` calls for the same never-before-seen owner create **exactly one** first Workspace between them — the `users`-row lock (§13.1, §18) serializes them; assert only one Workspace row exists afterward, not merely that neither call errored.
- **After the M1B fix**, `BusinessManager::createOrUpdateOnboardingBusiness()` resolves via the legacy resolver and calls `createForCustomerInWorkspace()`, so no path — controller, manager, or repository — can create a Business with a null `workspace_id`; `enforce_business_workspace_constraint` then succeeds against zero nulls and fails loudly if even one remains.

### 21.6 Scoped-assignment persistence tests (M1A — corrected from v1.2, which misplaced effective-access tests here before §14 is implemented)

M1A implements no part of the §14 access algorithm, so M1A tests assert **persistence and validation only**, never "who can see what":

- `workspace_membership_businesses` rows persist correctly through `WorkspaceMembershipBusinessRepository` (`assign()`, `isAssigned()`, `assignedBusinessIds()`, `syncForMembership()`), and `WorkspaceMembership::assignedBusinesses()` (§11.1) returns exactly those rows — a relationship-correctness assertion, not an access-evaluation one.
- `assign()`/`syncForMembership()` accept a Business belonging to the membership's own Workspace (same-Workspace validation) and reject one belonging to a *different* Workspace (§12.3).
- `role` and `business_access_scope` enum values round-trip correctly through persistence (§9.2).
- **`business_access_scope` has no database default**: inserting a `workspace_memberships` row via the query builder with `role` set but scope omitted fails at the database level; at the application layer, `WorkspaceMembershipRepository::create()`'s required, non-nullable `$scope` parameter means an omitted scope is a type error, not a runtime default to `all`.

### 21.7 Effective-access, deactivation, and ownership-transfer tests (specified for Milestone 2, written when §14.1/§15 are implemented)

**Moved here from v1.2's §21.6**, since they exercise `userCanAccessBusiness()` (§14.1), which M1A does not implement:

- Owner sees every Business regardless of any membership row; `all`-scope active membership sees every Business in the Workspace; `selected`-scope sees only its explicit assignments, none of the Workspace's others — `staff`/`admin` with `selected` scope are both still limited, role never expanding scope.
- An inactive Workspace blocks the direct owner, the Workspace owner, and every active member (`all`- and `selected`-scope alike) — one test matrix covering all four; reactivation restores exactly the prior access with no scope/role silently changed while inactive. Inactive membership alone (Workspace still active) blocks that member's access. Platform-administrator behavior is unaffected by any Workspace's `is_active` value.
- **Ownership transfer deactivates, never deletes, the incoming owner's pre-existing membership row** (§15 step 2): seed an active membership for the incoming owner, transfer ownership, and assert the row still exists with `is_active = false` — not absent.

### 21.8 Legacy-boundary tests (M1A)

- `users.parent_id` having any value creates, implies, or influences no `workspace_memberships` row, role, or scope.
- Existing RFC-001 ownership queries (`findOwnedByCustomer()`, `findPrimaryByCustomer()`, `Customer::businesses()`, `Customer::primaryBusiness()`) return identical results before/after M1A's migrations, given identical `customer_id` data.

---

## 22. Security invariants

- Every Business belongs to exactly one Workspace once M1B's enforcement has run (§10.7); during M1A this is a best-effort, re-verified condition, not yet a hard constraint. A Workspace may contain many Businesses; a User may belong to multiple Workspaces.
- Workspace ownership is never inferred from membership role (§7.3); Workspace membership never changes `businesses.customer_id`; Business ownership never implicitly creates Workspace membership.
- `users.parent_id` grants neither Workspace role nor Business-access scope (§21.8). Inactive membership grants no Workspace access (§14.2).
- **An inactive Workspace blocks all customer-side access, including direct Business ownership and Workspace ownership** — corrected from v1.0 (§14, §17).
- `business_access_scope = selected` never grants access beyond its explicit assignments; it can only narrow, never widen, what role alone would imply (§14.2). `business_access_scope` has no database default and no membership row may omit it (§9.2, §21.6).
- A `workspace_membership_businesses` assignment is valid only when its Business belongs to its membership's Workspace, enforced at write time (§9.3, §12.3).
- No generic "the owner's Workspace" resolution exists; generic Business creation always takes an explicit target Workspace (§10.6). The one narrow exception (`resolveLegacyOnboardingWorkspace()`, §13.1) never guesses among multiple candidates — it fails safely instead (§21.5).
- The pre-RFC-003 `customer_id`-grouping backfill is a versioned, one-time migration policy, not a rule ever re-applied to reconcile future multi-Workspace data (§10.7).
- Ownership transfer deactivates, never deletes, an incoming owner's pre-existing membership row (§15, §21.7).
- UI visibility is not authorization; Workspace plan name is not authorization (§14.2 — forward-documented for RFC-004).
- `workspaces.is_active` represents tenancy access, not billing/entitlement state (§14.2, §17).
- Every mutating operation must lock and recheck authoritative rows (§18); duplicate membership creation and duplicate scoped-assignment creation must both be idempotent or fail safely (§12.2, §12.3, §18).
- Cross-Workspace Business reassignment must be explicit and audited, and must not leave dangling scoped assignments pointing at the old Workspace (§16.2, §19).
- Foreign keys across every table this RFC introduces are restrictive, not cascading (§17); no repository exposes a hard-delete method for `Workspace`, `WorkspaceMembership`, or `Business` — only for scoped-assignment grant rows (§12.3, §17).
- Platform-admin behavior remains governed by existing backend authorization (§14, path 1).

---

## 23. Milestones

### M1A — Nullable persistence foundation

- `workspaces`, `workspace_memberships`, `workspace_membership_businesses` migrations (DDL only), and `businesses.workspace_id` added nullable (DDL only)
- Initial idempotent backfill run (data-only migration 5, invoking `WorkspaceBackfillV1` directly — not `BackfillWorkspacesCommand`, which remains only a thin operator-facing wrapper, §10.3–§10.5)
- `Workspace`, `WorkspaceMembership`, `WorkspaceMembershipBusiness` models; `WorkspaceMembershipRole`, `WorkspaceBusinessAccessScope` enums; relationships on `Workspace`, `WorkspaceMembership`, `User`, and `Business` (§11)
- `WorkspaceRepository`, `WorkspaceMembershipRepository`, `WorkspaceMembershipBusinessRepository` contracts and Eloquent implementations (§12.1–§12.3), plus provider bindings in `AppServiceProvider`
- Schema, migration-safety, model, repository, backfill, and legacy-boundary tests (§21.1–§21.4, §21.6, §21.8)

Excluded from M1A: `NOT NULL`/FK enforcement on `businesses.workspace_id`; any change to the existing Business-creation write path; `WorkspaceManager` mutations (§13); HTTP; UI; plans/feature toggles (RFC-004); billing/wallets/Stripe/usage (RFC-005); changes to `users.parent_id` or `businesses.customer_id`; RFC-001/RFC-002 retrofits; tagging (§25).

**M1A stops and reports independently, before M1B begins.**

### M1B — Creation-path compatibility and final enforcement

- Add `BusinessRepository::createForCustomerInWorkspace()` and `WorkspaceManager::resolveLegacyOnboardingWorkspace()`; adapt `BusinessManager::createOrUpdateOnboardingBusiness()` to use both (§10.6, §12.4, §13.1)
- Verify no remaining caller of `createForCustomer()`, then **remove it** from `BusinessRepository` and `EloquentBusinessRepository` (§10.6 step 2)
- Re-run the idempotent backfill (`workspaces:backfill`, wrapping `WorkspaceBackfillV1`); verify zero null `workspace_id` rows (independent pre-flight, §10.6 step 4)
- Run `enforce_business_workspace_constraint` (`NOT NULL`, foreign key, final indexes)
- Workspace-context, legacy-resolver, removal, and concurrency tests (§21.5)

Excluded from M1B: the rest of `WorkspaceManager` (§13.2, full orchestration/events/audit remain Milestone 2, §16); HTTP; UI; plans/feature toggles/billing/wallets/Stripe/usage; tagging (§25).

**M1B stops and reports independently. It does not begin until M1A has been reviewed and accepted.**

### Milestone 2 — Workspace domain operations

- `WorkspaceManager` (§13)
- Workspace lifecycle (create, rename, deactivate/reactivate)
- Membership lifecycle (add/change role/change scope/deactivate/reactivate)
- Scoped Business-assignment lifecycle (assign/unassign, §7.5)
- Business creation/assignment/reassignment (§16)
- Ownership transfer (§15)
- Locking (§18)
- Safe exceptions
- Events/audit (§19)
- Effective-access algorithm implementation (§14.1) and its tests (§21.7)

### Milestone 3 — Read-only customer HTTP surfaces

Workspace switcher, membership list (with role and scope shown), Business list under a Workspace filtered by the current user's effective access — read-only, no mutation routes.

### Milestone 4 — Mutation customer HTTP surfaces

Create/rename/deactivate Workspace, manage members (role, scope, assignments), create/reassign Business, transfer ownership — wired to `WorkspaceManager`.

### Milestone 5 — Platform-administrator inspection and controls

Admin Workspace listing/inspection, following the existing `EnsureUserIsAdministrator` boundary pattern (§6 finding 5). No new admin mechanism.

### Milestone 6 — Conformance, deployment guide, final regression and annotated tag

Full regression against RFC-001/RFC-002 suites, deployment guide, `rfc-003-workspace-and-business-account-core` annotated tag gate (§25).

Do not implement all milestones in one uncontrolled pass — each stops and reports independently, matching RFC-001's and RFC-002's milestone discipline, with M1A/M1B now explicitly split for the reasons in §1.

---

## 24. Acceptance criteria

### Database/domain — M1A

Five DDL/data migrations (1–5, §10.1) apply and roll back cleanly against representative data; migration 5 invokes `WorkspaceBackfillV1` directly, not the console command (§21.2). `businesses.workspace_id` remains nullable, no `NOT NULL`/FK yet. `createForCustomer()` is provably unmodified, still producing a null `workspace_id`; `EloquentBusinessRepository` has no Workspace-provisioning dependency (§21.5). The initial backfill processes each `customer_id` group in its own transaction (§10.4): a forced failure mid-group rolls back cleanly with no orphan Workspace, concurrent runs for one group serialize and produce one Workspace, retries create no duplicates, and the run fails loudly on any non-zero remaining null count (§21.4). `businesses.customer_id` is provably unchanged. Models/relationships work per §11, including a User validly owning multiple Workspaces (§21.3); `business_access_scope` casts with no default. Unique constraints on `workspaces.uid`, `(workspace_id, user_id)`, `(workspace_membership_id, business_id)`, and every `restrictOnDelete()` FK in §9 are verified at the database level. **Effective Business-access evaluation (§14.1) is not part of M1A's acceptance surface** — only scoped-assignment persistence (§21.6) is; access evaluation ships with Milestone 2.

### Database/domain — M1B

`createForCustomerInWorkspace()` and `resolveLegacyOnboardingWorkspace()` exist and are wired into `BusinessManager`; the resolver reuses, creates, or safely fails per its 10-step algorithm (§13.1, §21.5), and concurrent first-Workspace provisioning for one owner is proven serialized. `createForCustomer()` is confirmed unused and removed from `BusinessRepository`/`EloquentBusinessRepository` (§10.6 step 2, §12.4) — verified by its absence from the contract, not by catching a database constraint exception. `enforce_business_workspace_constraint` applies only after the creation-path fix is live and independent re-verification shows zero nulls. `businesses.workspace_id` ends up `NOT NULL` with a `restrictOnDelete()` FK and the §9.4 indexes.

### Architecture/quality

Existing repository/library conventions followed exactly (no `Interface` suffix, `BaseRepository` extension, provider-array binding, HasUid usage per §6 finding 4); no new generic service layer. Migration/backfill code uses only the query builder — no Eloquent model, model event, or `User::displayName()` dependency (§10.3, §21.2). Implementation additively modifies the exact files in §11.3 in addition to adding new ones — no "new files only" claim. Tests and lint/static-analysis pass; no RFC-001/RFC-002 test regresses.

### Documentation

This RFC accurately reflects §6's current-state findings at time of writing. RFC-004/RFC-005 deferrals (§26) are unambiguous — no plan, entitlement, wallet, or billing concept leaks into M1A, M1B, or Milestone 2. M1A and M1B are documented as independently stoppable milestones, not one combined "Milestone 1."

---

## 25. Release and tag gate

Tagging follows the same posture as RFC-001/RFC-002 (`rfc-001-business-core`, `rfc-002-opportunity-engine`): an annotated tag is created only at the end of Milestone 6, after full regression, not at the end of M1A or M1B.

**No tag is created, applied, or proposed as part of drafting or revising this RFC document.** Drafting/revising RFC-003 is documentation only and gates nothing by itself; the tag gate applies to the eventual Milestone 6 implementation, not to this document's creation or revision.

---

## 26. RFC-004 and RFC-005 deferrals

### 26.1 Deferred to RFC-004 — Plans and Business Feature Entitlements

- Core/Growth/Agency plan definitions.
- Workspace plan assignment.
- Included and additional Business slots.
- Per-Business feature toggles.
- Platform feature registry.
- Workspace entitlement overrides.
- Business entitlement overrides.
- Complimentary Workspace subscriptions.
- Agency white-label/package capabilities.

The forward-looking entitlement rule that RFC-004 must implement, documented here only so RFC-003's schema does not foreclose it:

```text
platform support
AND Workspace plan entitlement
AND Business feature toggle
AND billing state
AND usage authorization
```

A Business feature toggle may narrow Workspace-level access but may never grant a feature excluded by the Workspace's plan. Disabling a feature must preserve historical data. RFC-003 defines no feature-entitlement schema or implementation — this is stated only to confirm the M1A/M1B schema (a plain `businesses.workspace_id` foreign key with no entitlement columns anywhere) does not need to change shape when RFC-004 is written.

### 26.2 Deferred to RFC-005 — Business Usage Billing and Wallets

- Business payment methods.
- Workspace payment-method fallback.
- Selected Business payer (Business/client pays, Workspace pays, or — later, Agency-only — Workspace pays and rebills client).
- Default payer by tier: Core/Growth defaults to Workspace pays; Agency defaults to Business/client pays.
- Usage accounts and balances.
- Append-only usage ledger.
- Manual top-ups.
- Auto-recharge, with a default threshold below $10 and a default recharge amount of $10, both editable, with the ability to disable auto-recharge entirely.
- Monthly automatic recharge limits.
- Low-balance notifications.
- Zero-balance usage suspension.
- Payment webhooks and idempotency.
- Reservation/release for uncertain-cost operations.
- Agency client rebilling.
- Stripe integration changes.

The payer-change invariant that RFC-005 must implement, documented here only so RFC-003's schema does not foreclose it: changing a Business's payer must never delete credits, move credits to another Business, reset the wallet, or rewrite historical ledger entries — a payer change affects future charges and recharges only. RFC-003 defines no wallet, ledger, or payer schema — this is stated only to confirm the M1A/M1B schema does not need to change shape when RFC-005 is written.

---

## 27. Product decisions locked by this RFC

- The Workspace is the universal top-level account container for every customer shape; "Agency" is a plan tier, not a separate tenant model, and no `Agency` model or `businesses.agency_id` is created.
- `workspaces.owner_user_id` is the sole authoritative Workspace-owner relationship; ownership is never derived from a membership role.
- Workspace membership roles are `admin` and `staff` only; Business-access scope (`all`/`selected`) is a separate, independent axis from role (§7.5).
- `businesses.customer_id` (direct owner/contact authority) and `businesses.workspace_id` (organizational/commercial/team-access container) are independent relationships that coexist without either implying the other.
- `users.parent_id` remains legacy sub-account delegation, wholly outside Workspace logic, and grants neither role nor Business-access scope.
- The invariant "every Business belongs to exactly one Workspace" is enforced only after the existing Business-creation write path is made Workspace-aware (M1B) — it is never enforced by a schema constraint that a live write path cannot satisfy (M1A ships nullable).
- Migration and backfill code is written against the query builder, not mutable Eloquent models or their events, so it does not depend on application-layer behavior that could change independently of the schema; the algorithm lives in a versioned, immutable action (`WorkspaceBackfillV1`) that the migration invokes directly and the operator command only wraps.
- No repository resolves "the" owner's Workspace generically — a User may own multiple. Generic Business creation always takes an explicit Workspace; the one legacy compatibility path resolves via a locked, recheck-before-write algorithm that fails safely rather than guessing among candidates.
- The pre-RFC-003 `customer_id`-grouping backfill is a versioned migration policy for existing data only, never re-applied as a permanent reconciliation rule once explicit-Workspace creation exists.
- `business_access_scope` has no database default; every membership-creation path must supply it explicitly.
- An inactive Workspace blocks all customer-side access to its Businesses, including direct ownership, but never represents billing/entitlement state.
- Foreign keys across every table this RFC introduces are restrictive; no repository exposes a hard-delete method for `Workspace`, `WorkspaceMembership`, or `Business` — deactivation is the supported lifecycle operation (ownership transfer deactivates rather than deletes a prior membership row), and only scoped-assignment grant rows are ever hard-deleted.
- This specification is approved for M1A implementation only; M1B remains independently gated behind successful M1A implementation, testing and review.
