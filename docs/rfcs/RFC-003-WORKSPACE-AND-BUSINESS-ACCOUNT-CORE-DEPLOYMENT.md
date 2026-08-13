# RFC-003 — Workspace and Business Account Core: Deployment & Operations

**Companion to:** `docs/rfcs/RFC-003-WORKSPACE-AND-BUSINESS-ACCOUNT-CORE.md`
**Scope:** Migration order, legacy adoption/backfill, integrity constraints, deployment verification, and rollback posture for the Workspace and Business Account Core feature (M1A through Milestone 5, all shipped as of this document).

This document contains no real credentials, database identifiers, API keys, or customer data. Every value below is grounded in direct inspection of this repository's actual migrations, classes, and routes at the time of writing — nothing here is copied from the RFC-001 or RFC-002 deployment guides without independent verification.

---

## 1. Scope

This guide covers deployment of everything RFC-003 has shipped to date: the `workspaces`/`workspace_memberships`/`workspace_membership_businesses`/`workspace_transitions` schema and the legacy-Business backfill (M1A), the creation-path compatibility fix and final `NOT NULL` enforcement (M1B), the full `WorkspaceManager` domain layer (Milestone 2), the read-only customer HTTP surfaces (Milestone 3), the mutation customer HTTP surfaces (Milestone 4), and the read-only platform-administrator inspection surface (Milestone 5).

**Milestone 6 itself — this document, `docs/automation/RFC-003-M6-CONFORMANCE.md`, the final regression pass, and the eventual annotated tag — adds no product code.** There is nothing new to deploy for Milestone 6 beyond these two documents landing on `main`; the tag, once created, marks a commit that was already deployable.

Out of scope: RFC-004 (Plans and Business Feature Entitlements) and RFC-005 (Business Usage Billing and Wallets) — neither exists in this codebase, and this guide documents no billing, plan, entitlement, or wallet deployment step of any kind.

---

## 2. Prerequisites

- Laravel 12 / PHP 8.2, matching this application's existing baseline — RFC-003 introduces no new PHP version or package requirement.
- A database that already has RFC-001's `businesses`, `business_locations`, `business_services`, `customers`, `users` tables (RFC-003 §8's dependency) — this is any environment already running Ultimate SMS's existing codebase.
- No new third-party service, API key, or queue connection. **Direct inspection finding:** no file under `app/Library/Workspace`, `app/Http/Controllers/Customer/Workspace`, or `app/Http/Controllers/Admin/WorkspaceController.php` implements `ShouldQueue` or calls `env()`. RFC-003 has no environment variable of its own and nothing here is queue-dependent.

---

## 3. Upgrade posture

**RFC-003 ships with no feature flag.** Unlike RFC-001's `BUSINESS_ONBOARDING_ENABLED`/`BUSINESS_ONBOARDING_REQUIRE_NEW_CUSTOMERS`, direct inspection found no `config/workspace.php` (or equivalent) and no `env()` call anywhere in the Workspace codebase. There is no way to deploy RFC-003's code disabled-but-present the way RFC-001's rollout procedure did — once the migrations run and the code is deployed, the customer Workspace routes, the admin Workspace routes, and the backfilled data are live. Plan the deployment window accordingly (see §4's quiescence note for migration 5, and §9's window note).

This is an **additive, backward-compatible** upgrade for every existing customer: RFC-003 §20 confirms no existing RFC-001/RFC-002 table, column, route, controller, or view is dropped, renamed, or altered in a breaking way. A customer with no Workspace-aware activity before this deploy is unaffected in what they already had access to; they simply gain a backfilled Workspace containing their existing Business(es).

---

## 4. Migration sequence

Run in the order they were authored (Laravel's migrator does this automatically by timestamp; the dependency order below is worth stating explicitly since a manual/partial rollback must reverse it). Confirmed directly against the seven migration files present in `database/migrations/`:

1. `2026_07_30_120001_create_workspaces_table.php` — DDL only. `workspaces`: `id`, unique `uid` (UUID), `name`, `owner_user_id` (FK → `users`, `restrictOnDelete()`), `is_active` (default `true`), timestamps.
2. `2026_07_30_120002_create_workspace_memberships_table.php` — DDL only. `workspace_memberships`: `role`, `business_access_scope` (no default), `is_active`, unique `(workspace_id, user_id)`, FKs `restrictOnDelete()`.
3. `2026_07_30_120003_create_workspace_membership_businesses_table.php` — DDL only. `workspace_membership_businesses`: unique `(workspace_membership_id, business_id)`, FKs `restrictOnDelete()`.
4. `2026_07_30_120004_add_nullable_workspace_id_to_businesses.php` — DDL only. Adds `businesses.workspace_id`, nullable, no FK yet.
5. `2026_07_30_120005_backfill_business_workspaces.php` — **data operation only, no DDL.** Directly instantiates and calls `App\Library\Workspace\Migration\WorkspaceBackfillV1::run()` — confirmed by direct file inspection, not the `workspaces:backfill` console command. Assigns every pre-existing Business a Workspace, grouped by `customer_id` (RFC-003 §10.4's per-group-transactional algorithm), and fails the whole migration if any `businesses.workspace_id` remains null afterward.
6. `2026_07_30_120006_enforce_business_workspace_constraint.php` — DDL only, **but runs two `SELECT` preconditions before any schema change**: a `workspace_id IS NULL` count (throws `WorkspaceBackfillIncompleteException` if non-zero) and a dangling-`workspace_id` existence check (throws `DanglingWorkspaceReferenceException` if any Business references a `workspace_id` with no matching `workspaces` row). Only after both pass does it apply `NOT NULL`, the `businesses_workspace_id_index`/`businesses_workspace_id_status_index` indexes, and the `businesses_workspace_id_foreign` `restrictOnDelete()` FK.
7. `2026_07_31_120001_create_workspace_transitions_table.php` — DDL only. The durable audit table for ownership transfer and cross-Workspace Business reassignment (RFC-003 §19), added in Milestone 2. `restrictOnDelete()` FKs to `workspaces` and (nullable) `businesses`; `actor_user_id`/`from_owner_user_id`/`to_owner_user_id` are plain unsigned-bigint columns with no FK, by design (users are not subject to RFC-003's restrictive-delete policy).

**Rollback** reverses this order. Migration 7 and migrations 1–4 roll back cleanly (`dropIfExists`/`dropColumn`). Migration 6's `down()` drops the FK, indexes, and reverts the column to nullable — **it does not delete backfilled data**. Migration 5's `down()` is an intentional no-op, documented in the migration file itself: reverting a completed backfill is a separate, explicitly reviewed operation, never an automatic rollback (RFC-003 §10.1; see §9 below).

**Deployment-window note (RFC-003 §10.4's "deployment note"):** because each `customer_id` group in migration 5 commits independently, a Business created concurrently with that migration (via the still-nullable-tolerant path) could in principle land with a null `workspace_id` after its group has already been processed. For a fresh deploy of a repository that already includes M1B (this repository's current state — `createForCustomer()` no longer exists, so there is no nullable-tolerant creation path left to race against migration 5 in the first place), this window does not apply. It is documented here only for completeness against the RFC's own text, and is not an operational concern for deploying this repository as it exists today.

---

## 5. Legacy Business → Workspace adoption/backfill

`workspaces:backfill` (`App\Console\Commands\BackfillWorkspacesCommand`, confirmed signature: no arguments, no options) wraps `WorkspaceBackfillV1` and is safe to run repeatedly — the underlying action is idempotent (RFC-003 §10.4): a `customer_id` group already fully assigned makes no writes and reports zero remaining nulls; a partially-assigned group reuses its existing Workspace rather than creating a duplicate.

```
php artisan workspaces:backfill
```

Output on success:

```
Workspace backfill complete.
groups processed=<n>
workspaces created=<n>
workspaces reused=<n>
businesses assigned=<n>
remaining null count=0
```

A non-zero exit code and an error message (not a silent success) is produced if the run fails on a conflicting group (`WorkspaceBackfillConflictException` — two Businesses under one `customer_id` already reference two different Workspaces, a data-inconsistency condition) or on a non-zero remaining null count after the run (`WorkspaceBackfillIncompleteException`).

For a repository already on this Milestone-6-ready state, migration 5 has already run as part of the original M1A deploy and migration 6 has already enforced the invariant — running `workspaces:backfill` again on an already-consistent database is a safe no-op verification, not a required step. It remains useful as an on-demand consistency check if `businesses.workspace_id` nullability is ever reintroduced by a future migration (not something RFC-003 itself does).

---

## 6. Database constraints / integrity enforcement

Confirmed present in the current schema by direct migration inspection:

| Table | Constraint |
|---|---|
| `workspaces` | unique `uid`; `owner_user_id` → `users.id`, `restrictOnDelete()` |
| `workspace_memberships` | unique `(workspace_id, user_id)`; `workspace_id` → `workspaces.id`, `restrictOnDelete()`; `user_id` → `users.id`, `restrictOnDelete()` |
| `workspace_membership_businesses` | unique `(workspace_membership_id, business_id)`; both FKs `restrictOnDelete()` |
| `businesses` | `workspace_id` `NOT NULL`, → `workspaces.id`, `restrictOnDelete()` |
| `workspace_transitions` | `workspace_id`/`business_id`(nullable)/`from_workspace_id`(nullable) all `restrictOnDelete()` |

Every foreign key this feature introduces is restrictive, never cascading — nothing in this schema silently deletes tenancy history as a side effect of deleting something else (RFC-003 §17). No repository exposes a hard-delete method for `Workspace`, `WorkspaceMembership`, or `Business` — deactivation (`is_active = false`) is the only supported lifecycle-removal operation for those three entities. `WorkspaceMembershipBusinessRepository::unassign()`/`removeAllForBusinessInWorkspace()` are the sole exception, and they delete only a scoped-access **grant** row, not a tenancy entity.

---

## 7. Cache and queue considerations

**Config cache: not applicable.** Direct inspection found no `config/*.php` file specific to RFC-003 and no `env()` call anywhere in its codebase (§2/§3 above) — there is no RFC-003-specific config value that a `php artisan config:cache` would need to pick up. A routine `config:cache` as part of your normal deployment process is unaffected by this feature either way, but this document does not add a new required cache-clearing step, because there is nothing RFC-003-specific to clear.

**Queue/worker restart: not applicable.** Direct inspection (`grep -rl ShouldQueue` across every Workspace-related directory) found zero jobs. Every Workspace mutation — creation, membership changes, Business creation/reassignment, ownership transfer — is synchronous, inside the same request/transaction that performs it. No queue worker or Horizon supervisor needs to be restarted for this deploy.

---

## 8. Deployment procedure

1. **Deploy code.** No feature flag to set (§3) — this step alone makes the RFC-003 classes/routes present but does not yet migrate the database.
2. **Run migrations:**
   ```
   php artisan migrate
   ```
   This runs all seven migrations in §4's order, including the backfill (migration 5) and the `NOT NULL` enforcement (migration 6) in the same pass, since this repository is already past the M1A/M1B split in its current `main` state — there is no separate "M1A now, M1B later" deploy window remaining to manage for a fresh environment adopting this code today.
3. **Run the regression suite** (§10) against the deployed build before relying on it in production.
4. **Manual smoke checks** (§11) in a staging environment.
5. **Verify the platform-admin inspection permission** (§12) is assigned to the intended admin role(s) before relying on it operationally.

For an environment that already ran M1A separately in the past (i.e. an older deploy that stopped after migration 5 and is only now catching up to M1B), `php artisan migrate` still applies cleanly — migration 6 simply runs its own independent precondition checks (§4) and enforces the constraint at that point instead.

---

## 9. Rollback / recovery posture

- **Code rollback** is safe at any point with respect to existing RFC-001/RFC-002 behavior — RFC-003 touches no existing route, controller, or view from either prior RFC (§20), and every RFC-003 table/column is additive.
- **Migration rollback** (`php artisan migrate:rollback`) is supported through migration 7 down to migration 1, in reverse order, **except**:
  - **Migration 6's rollback reverts the constraint, not the data** — it drops the FK/indexes and reverts `workspace_id` to nullable, but every already-backfilled `workspace_id` value on every `businesses` row remains exactly as it was. This is intentional (RFC-001 §30's "prefer forward fixes over dropping populated tables," reused here).
  - **Migration 5's rollback is a documented no-op.** It does not null out `workspace_id`, does not delete backfilled `Workspace` rows, and does not delete `Business`-to-`Workspace` assignments. **Do not attempt to hand-write a rollback for this migration.** Reverting a completed backfill — if ever genuinely required — is a separate, explicitly reviewed data operation with its own review, never an automatic `migrate:rollback` side effect.
- **What must not be rolled back destructively:** any already-committed `Workspace`, `WorkspaceMembership`, `WorkspaceMembershipBusiness`, or `workspace_transitions` row. Every FK in this schema is `restrictOnDelete()` specifically so that a destructive attempt (dropping a `Workspace` that still has attached Businesses, for example) fails loudly at the database level rather than silently cascading data loss.
- **Disabling instead of rolling back:** since there is no feature flag (§3), the only per-Workspace "disable" mechanism is the existing `is_active` deactivation already exposed through the customer/admin surfaces — this preserves all data and is reversible (reactivation), unlike a migration rollback.
- **Operational failure handling:** if `workspaces:backfill` (or migration 5, on first deploy) reports a non-zero exit and a remaining/conflict error, do not proceed to migration 6 (or, on a fresh `migrate` run, migration 6 will itself independently detect and block on the same condition — see §4). Investigate and resolve the specific `customer_id` group named in the error before re-running; the action is safe to re-run any number of times per its documented idempotence guarantees.

---

## 10. Final regression commands

Locked by `docs/automation/RFC-003-M6-CONTRACT.md` §6, confirmed against the actual test directories present in this repository (`tests/Unit/Workspace`, `tests/Feature/Workspace`, `tests/Unit/Business`, `tests/Feature/Business`, `tests/Unit/Opportunity`, `tests/Feature/Opportunity` — all present and non-empty):

```
php artisan test tests/Unit/Workspace tests/Feature/Workspace
php artisan test tests/Unit/Business tests/Feature/Business
php artisan test tests/Unit/Opportunity tests/Feature/Opportunity
php artisan test --stop-on-failure
```

Every command must exit successfully and discover a positive test count. Run by a human developer locally; see `docs/automation/RFC-003-M6-CONFORMANCE.md` for the actual recorded results once supplied.

---

## 11. Manual smoke checks

**Customer Workspace smoke checks:**

- Log in as an existing customer, visit the Workspace switcher (`customer.workspaces.index`) — confirm their backfilled Workspace appears.
- Open the Workspace overview (`customer.workspaces.show`) — confirm the owned Business(es) appear in the Business list.
- As the Workspace owner, create a new Business inside the Workspace, rename the Workspace, deactivate then reactivate it — confirm each action succeeds and the overview reflects the change.
- As the owner, add a member with `selected` scope and one assigned Business — confirm that member sees only the assigned Business, not every Business in the Workspace, after logging in as them.

**Business access/isolation smoke checks:**

- As a Workspace member with `selected` scope and zero assignments, confirm the Business list is empty.
- Deactivate the Workspace — confirm the owner, a direct Business owner, and every member all lose access to its Businesses (RFC-003 §14.1's inactive-Workspace gate), then reactivate and confirm access returns unchanged.
- Confirm a customer with no relationship to a given Workspace cannot reach its overview or Business list by URL (404, not a permission error that would leak existence).

**Platform-admin inspection smoke checks:**

- As an authorized admin (with `access backend` and `view workspace` — see §12), visit `admin/workspaces` — confirm the cross-tenant list, search, and active/inactive filter all render.
- Open a Workspace the admin does not own and has no membership in — confirm full inspection (owner, Businesses, all memberships including inactive ones, `business_access_scope`, selected assignments) renders regardless.
- Attempt `admin/workspaces/{numeric-id}` (substituting a real Workspace's database id for its uid) — confirm 404, not a server error, proving the `whereUuid()` route constraint holds.
- As a customer (not an admin), confirm `admin/workspaces` is inaccessible.
- As an admin with `access backend` but without `view workspace`, confirm `admin/workspaces` is inaccessible.

---

## 12. Platform-admin permission

One new permission key exists in `config/permissions.php` (existing app convention — every key in that file becomes a `Gate::define()` ability automatically via `AuthServiceProvider`, resolved through the existing `AccountRepository::hasPermission()` check, confirmed unmodified by this feature):

- `view workspace` — permits `admin.workspaces.index` and `admin.workspaces.show` (list and inspect Workspaces). There is no `edit workspace`/`create workspace`/`delete workspace` permission — Milestone 5 is read-only by RFC-003 design, and no write action exists for a write-level permission to gate.

**Deploying this code does not automatically grant `view workspace` to any admin, including existing roles.** Administrators must assign it to the appropriate role(s) using the application's existing permission-management screen (Admin Roles), the same way `view business`/`edit business` were assigned for RFC-001. Ordinary customers remain blocked before this permission is even considered, by the existing `can:access backend` gate already applied to the whole admin route group, and independently by the `EnsureUserIsAdministrator` middleware's `users.is_admin` check layered on top of it.

---

## 13. Release/tag verification

Per `docs/automation/RFC-003-M6-CONTRACT.md` §8/§9: after the Milestone 6 documentation PR (this guide plus `RFC-003-M6-CONFORMANCE.md`) is human-merged, the exact post-merge `main` HEAD becomes the tag candidate only after a final `php artisan test --stop-on-failure` passes against that exact commit. The eventual annotated tag is `rfc-003-workspace-and-business-account-core`, created and pushed only after explicit human authorization — no tag command is executed as part of this document's creation, and none should be executed by any automated process at any point in this sequence. Tag verification (existence on the `origin` remote, annotated-not-lightweight, exact name, exact commit, annotation present) is required before RFC-003 is considered released; see the M6 contract for the full procedure.

---

## 14. Out of scope

Per RFC-003 §4 and §26, this deployment does not include, and this document does not cover: RFC-004 plans/entitlements/Business-slot limits, RFC-005 usage wallets/ledgers/billing/Stripe integration, an `Agency` model, or any `businesses.agency_id` — none of these exist in this codebase yet.
