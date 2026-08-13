# RFC-003 Milestone 6 Conformance

## Status

**RELEASE-READINESS PASS — M6 PRE-MERGE REGRESSION GATE SATISFIED; M6 NOT YET COMPLETE.** This document is an evidence-based conformance audit of RFC-003 as actually implemented in this repository. Every row below cites concrete file, method, migration, event, exception, or test evidence found by direct inspection during this pass — no row is marked PASS merely because an expected file exists or "sounds correct." All four regression commands required by the governing contract have now been run by the human developer against this branch and **all four passed** (see "Manual regression" below for the exact recorded evidence). This satisfies the M6 pre-merge regression gate, but **RFC-003 is not fully released yet**: this M6 documentation PR has not been merged, the post-merge exact-tag-candidate regression has not run, and no tag has been created.

**No product or test conformance gap was discovered during this audit.**

## Contract / base information

- Governing contract: [`docs/automation/RFC-003-M6-CONTRACT.md`](RFC-003-M6-CONTRACT.md), status PROPOSED at drafting, authorizing this pass upon its own human merge.
- This pass's branch: `agent/rfc-003-m6`, base `4d1d6e54bf852f8f0346a66430cc422ec4e26564`.
- RFC document audited: [`docs/rfcs/RFC-003-WORKSPACE-AND-BUSINESS-ACCOUNT-CORE.md`](../rfcs/RFC-003-WORKSPACE-AND-BUSINESS-ACCOUNT-CORE.md), version 1.3.
- Prior milestone governance evidence relied on: `RFC-003-M4-CLOSURE.md`, `RFC-003-M5-CLOSURE.md` — cross-checked against actual repository state below rather than trusted at face value, per the contract's "do not assume prior milestone completion automatically means conformance" instruction.

---

## Evidence-based conformance matrix

### Workspace schema and invariants (§9.1)

**PASS.** `database/migrations/2026_07_30_120001_create_workspaces_table.php`: `id`, unique `uuid` `uid`, `name`, `owner_user_id` FK → `users` `restrictOnDelete()`, `is_active` boolean default `true`, timestamps, indexes on `owner_user_id` and `is_active` — matches §9.1 column-for-column, including the `restrictOnDelete()` policy.

### Business → Workspace association (§9.4, §10.7)

**PASS.** `database/migrations/2026_07_30_120004_add_nullable_workspace_id_to_businesses.php` adds `workspace_id` nullable, no FK yet (M1A). `database/migrations/2026_07_30_120006_enforce_business_workspace_constraint.php` makes it `NOT NULL`, adds `businesses_workspace_id_index` and `businesses_workspace_id_status_index`, and adds the `restrictOnDelete()` FK — exactly the M1B enforcement sequence in §10.6 step 5/§9.4. This migration also runs two precondition `SELECT`s before any DDL (`workspace_id IS NULL` count, and a dangling-`workspace_id` existence check) and throws `WorkspaceBackfillIncompleteException`/`DanglingWorkspaceReferenceException` rather than relying on a raw constraint-violation error — stronger than the RFC's literal minimum, not a gap.

### Legacy Business adoption/backfill (§10.2–§10.5)

**PASS.** `app/Library/Workspace/Migration/WorkspaceBackfillV1.php` inspected directly: uses only `DB::table(...)` calls (no Eloquent model, no model event, `uid` generated via `Str::uuid()`), processes distinct `customer_id`s in bounded pages (`CHUNK_SIZE = 500`) via `nextCustomerIdPage()`, each group inside its own `DB::transaction()` in `processCustomerGroup()`, locking the `users` row with `lockForUpdate()` before re-reading Business state — matches §10.3/§10.4's algorithm exactly, including the "more than one distinct non-null `workspace_id`" conflict path (`WorkspaceBackfillConflictException`) and the final global null-count check (`WorkspaceBackfillIncompleteException`). The naming policy in `resolveWorkspaceName()` follows §10.5's exact priority order (`customers.company` → primary Business name → first Business by id → `"{first_name} {last_name}'s Workspace"`), with one additional deterministic fallback (`"Customer #{id}'s Workspace"`) for the edge case where even `first_name`/`last_name` are blank — an addition beyond the RFC's literal four-step list, not a contradiction of it; still deterministic and non-blank. `app/Console/Commands/BackfillWorkspacesCommand.php` (`workspaces:backfill`, no arguments/options) is confirmed to be a thin wrapper with no algorithm of its own, matching §10.3.

### Migration safety/idempotence (§10.3, §10.4, §21.2)

**PASS.** Migration 5 (`2026_07_30_120005_backfill_business_workspaces.php`) instantiates and calls `(new WorkspaceBackfillV1())->run()` directly, not the console command — confirmed by direct file inspection. Its `down()` is a documented no-op per §10.1. Test evidence: `tests/Feature/Workspace/WorkspaceBackfillMigrationTest.php`, `WorkspaceBackfillV1Test.php`, `WorkspaceBackfillV1ConcurrencyTest.php`, `BackfillWorkspacesCommandTest.php` all exist.

### Workspace ownership (§7.2, §7.3)

**PASS.** `owner_user_id` is the sole authoritative owner column; `WorkspaceManager::userCanAccessBusiness()` (line 96) checks `workspace->owner_user_id === userId` independently of any membership row, matching §7.3's strict precedence order. Ownership transfer evidence: §15 below. Test evidence: `WorkspaceLifecycleTest.php`, `WorkspaceManagerTest.php`.

### Memberships (§9.2, §7.4)

**PASS.** `database/migrations/2026_07_30_120002_create_workspace_memberships_table.php`: `role` varchar(32), `business_access_scope` varchar(32) with no default, `is_active` boolean default `true`, unique `(workspace_id, user_id)`, index on `user_id`, composite `(workspace_id, is_active)` — matches §9.2 exactly, including the no-default requirement on `business_access_scope`. `WorkspaceMembershipRepository` contract (`app/Repositories/Contracts/WorkspaceMembershipRepository.php`) requires `WorkspaceBusinessAccessScope $scope` as a non-optional constructor-style parameter on `create()`, so an omitted scope is a type error, not a silent default — confirmed by direct inspection. Multi-Workspace membership (§7.4) has no uniqueness constraint on `user_id` alone — confirmed absent from the migration.

### Admin/staff roles (§7.5, §9.2)

**PASS.** `App\Enums\Workspace\WorkspaceMembershipRole` (`admin`/`staff`, never `owner`). `role` and `business_access_scope` are independent columns on the same row, both required at write time — confirmed via `WorkspaceMembershipRepository::create()`'s signature above.

### Active/inactive membership semantics (§14.2, §17)

**PASS.** `userCanAccessBusiness()`: `if ($membership === null || ! $membership->is_active) { return false; }` — inactive membership confers no access. Test evidence: `WorkspaceEffectiveAccessTest.php`, `WorkspaceMembershipLifecycleTest.php`.

### `business_access_scope` (§7.5, §9.2)

**PASS.** No database default (confirmed: migration column has no `->default()` call). `App\Enums\Workspace\WorkspaceBusinessAccessScope` (`all`/`selected`). `userCanAccessBusiness()` reads it only after the owner/membership checks, confirming role never implies scope.

### Selected Business assignments (§9.3, §12.3)

**PASS.** `database/migrations/2026_07_30_120003_create_workspace_membership_businesses_table.php`: unique `(workspace_membership_id, business_id)`, index on `business_id`, both FKs `restrictOnDelete()` — matches §9.3. `WorkspaceMembershipBusinessRepository` contract confirmed to require the same-Workspace check before writing (`assign()`'s docblock cites `CrossWorkspaceAssignmentException`), and `app/Exceptions/Workspace/CrossWorkspaceAssignmentException.php` exists. Cross-Workspace rejection is directly exercised by `tests/Feature/Workspace/WorkspaceOwnershipTransferHttpTest.php` and prior-slice reassignment/member-management HTTP tests (Milestone 4).

### Customer Workspace authorization (§14, §14.1)

**PASS.** `userCanAccessBusiness()` (`app/Library/Workspace/WorkspaceManager.php:96-131`) reproduces the §14.1 pseudocode step-for-step: Workspace-null/inactive gate first, then direct `customer_id` ownership, then Workspace owner, then active membership with scope evaluation. One structural note recorded here rather than glossed over: the method itself does **not** contain an inline `if user.is_admin: return true` check the way the RFC's pseudocode literally shows it. Evidence shows this is not a gap — platform-administrator inspection (Milestone 5) is routed through an entirely separate controller (`app/Http/Controllers/Admin/WorkspaceController.php`) and repository method (`WorkspaceRepository::paginateForAdmin()`) that never call `userCanAccessBusiness()` at all, satisfying §14 rule 1 ("checked upstream of, and independently from, everything below... RFC-003 does not add to, wrap, or duplicate this path") via structural separation rather than an inline bypass inside this specific method. Test evidence: `WorkspaceEffectiveAccessTest.php`.

### Business access isolation (§14.1, §14.2)

**PASS.** Same evidence as above; `WorkspaceBusinessListHttpTest.php` and `WorkspaceOverviewHttpTest.php` directly test that a customer sees only Businesses their effective access covers.

### Workspace overview/listing (Milestone 3)

**PASS.** `routes/customer.php` registers `customer.workspaces.index`/`customer.workspaces.show`; `WorkspaceController::index()`/`show()` (customer) implement the read-only switcher/overview. Test evidence: `WorkspaceSwitcherHttpTest.php`, `WorkspaceOverviewHttpTest.php`.

### Business listing (Milestone 3)

**PASS.** Same controller's `businesses` view data, filtered via `userCanAccessBusiness()`. Test evidence: `WorkspaceBusinessListHttpTest.php`.

### Business creation (§16.1, Milestone 4 Slice 4D)

**PASS.** `customer.workspaces.businesses.store` route → `WorkspaceController::storeBusiness()` → `WorkspaceManager::createBusinessInWorkspace()`. Test evidence: `WorkspaceBusinessCreationHttpTest.php`, `WorkspaceBusinessOrchestrationTest.php`. Closure evidence: `RFC-003-M4-CLOSURE.md`, Slice 4D section (PR #44, merge `c590cfe78f929bed328c9ae775e789f06322641c`).

### Business reassignment (§16.2, Milestone 4 Slice 4E)

**PASS.** `customer.workspaces.businesses.reassign` route → `WorkspaceManager::reassignBusiness()`, which removes stale `workspace_membership_businesses` grants via `WorkspaceMembershipBusinessRepository::removeAllForBusinessInWorkspace()` and writes a `workspace_transitions` audit row. Test evidence: `WorkspaceBusinessReassignmentHttpTest.php`, `WorkspaceBusinessOrchestrationTest.php`. Closure evidence: `RFC-003-M4-CLOSURE.md`, Slice 4E section (PR #48, merge `2327d9c1c3cd56d473ca770318c627f397c2b534`).

### Ownership transfer (§15, Milestone 4 Slice 4F)

**PASS.** `customer.workspaces.ownership.transfer` route → `WorkspaceManager::transferOwnership()`, directly inspected in an earlier implementation pass this session: locks the Workspace row, deactivates (never deletes) the incoming owner's pre-existing active membership before flipping `owner_user_id`, reconciles the previous owner's disposition (`deactivate`/`convert_to_admin`, via `WorkspaceOwnershipTransferDisposition`, which has no default and requires the caller to choose explicitly), writes exactly one `workspace_transitions` row, then dispatches `WorkspaceOwnershipTransferred`. Test evidence: `WorkspaceOwnershipTransferTest.php` (domain), `WorkspaceOwnershipTransferHttpTest.php` (HTTP), `tests/Unit/Workspace/WorkspaceOwnershipTransferDispositionTest.php` (DTO). Closure evidence: `RFC-003-M4-CLOSURE.md`, "Slice 4F final evidence" (PR #52, merge `db7829d2c33ff3eb77a5bd42820eb39e349f6d94`).

### Lifecycle/state handling (§17, Milestone 4 Slices 4A/4C)

**PASS.** Create/rename/deactivate (`customer.workspaces.store`/`rename`/`deactivate`, Slice 4A) and reactivate (`customer.workspaces.reactivate`, Slice 4C) routes all delegate to `WorkspaceManager`. Test evidence: `WorkspaceLifecycleTest.php`, `WorkspaceReactivationHttpTest.php`, `WorkspaceMutationHttpTest.php`.

### Platform-admin inspection (Milestone 5)

**PASS.** `app/Http/Controllers/Admin/WorkspaceController.php` — exactly two `GET` routes (`admin.workspaces.index`/`admin.workspaces.show`), no `WorkspaceManager` dependency, `$this->authorize('view workspace')` on both actions. Test evidence: `AdminWorkspaceControllerTest.php`. Closure evidence: `RFC-003-M5-CLOSURE.md` (contract PR #55/merge `d481ad3613552c1dfc947ebab7a67cbc72c8b085`; implementation PR #56/merge `4d8f1554d0c2f49f8952eb6291d443e2f774cfcf`).

### `EnsureUserIsAdministrator` independence (§6 finding 5, §14 path 1)

**PASS.** `app/Http/Middleware/EnsureUserIsAdministrator.php` checks only `users.is_admin`, independent of any permission-string content — confirmed unmodified by RFC-003 (no RFC-003 slice's authorized file scope ever included this file, per every M4/M5 contract's "explicitly forbidden" list). Regression proof: `AdminWorkspaceControllerTest::test_a_non_admin_account_is_blocked_even_with_workspace_permissions_in_session()` and the equivalent pre-existing `AdminBusinessControllerTest` test.

### uid/UUID routing assumptions (`HasUid`, Milestone 5 `whereUuid()`)

**PASS.** `Workspace` uses `App\Library\Traits\HasUid` (confirmed in `app/Models/Workspace.php`), overriding `generateUid()` to use `Str::uuid()` since `workspaces.uid` is a database `uuid` column (§9.1) rather than `HasUid`'s default `uniqid()`. `routes/admin.php`'s `admin.workspaces.show` route additionally constrains `{workspace}` with `->whereUuid('workspace')`, added during Milestone 5's correction round specifically so a numeric database id cannot reach Eloquent's binding lookup and produce a 500 — confirmed present in the current `routes/admin.php`. Customer-side Workspace routes use plain string uid parameters resolved via `resolveAccessibleWorkspace()`, not implicit route-model binding, so the same constraint does not apply there and is not needed there.

### Manager/repository authority boundaries (§12.5, §13)

**PASS.** `WorkspaceManager` (1,590 lines) owns every business-rule/authority/locking/event-dispatch responsibility; `WorkspaceRepository`/`WorkspaceMembershipRepository`/`WorkspaceMembershipBusinessRepository` remain plain data-access contracts with no authority logic beyond §12.3's documented same-Workspace check — confirmed by direct inspection of all three contracts and their Eloquent implementations. The admin `WorkspaceController` (Milestone 5) has no `WorkspaceManager` dependency at all, confirming the read-only/no-manager boundary that milestone's contract required.

### Required events/side effects (§19)

**PASS.** All 14 events §19 lists exist under `app/Events/Workspace/`, confirmed by directory listing: `WorkspaceCreated`, `WorkspaceRenamed`, `WorkspaceDeactivated`, `WorkspaceReactivated`, `WorkspaceOwnershipTransferred`, `WorkspaceMembershipCreated`, `WorkspaceMembershipRoleChanged`, `WorkspaceMembershipBusinessAccessScopeChanged`, `WorkspaceMembershipDeactivated`, `WorkspaceMembershipReactivated`, `WorkspaceMembershipBusinessAssigned`, `WorkspaceMembershipBusinessUnassigned`, `BusinessAssignedToWorkspace`, `BusinessReassignedToWorkspace`. Durable audit table (§19's "`workspace_transitions`-equivalent"): `database/migrations/2026_07_31_120001_create_workspace_transitions_table.php`, scoped to exactly the two operations §19 requires durable audit for (ownership transfer, cross-Workspace reassignment) — confirmed via its `transition_type`/`from_owner_user_id`/`to_owner_user_id`/`business_id`/`from_workspace_id` columns. Test evidence: `WorkspaceTransitionSchemaTest.php`, `WorkspaceTransitionsMigrationSchemaTest.php`, `WorkspaceTransitionRepositoryTest.php`.

### RFC-001 compatibility (§20)

**PASS.** `businesses.customer_id` unchanged in column/meaning/FK. `Business::customer()` relationship confirmed unmodified (`belongsTo(Customer::class, 'customer_id', 'user_id')`). `createForCustomer()` is confirmed **absent** from both `app/Repositories/Contracts/BusinessRepository.php` and `app/Repositories/Eloquent/EloquentBusinessRepository.php` (grepped directly — only `createForCustomerInWorkspace()` exists), matching §10.6 step 2/§12.4/§21.5's explicit-removal requirement precisely. `BusinessManager::createOrUpdateOnboardingBusiness()` confirmed to call `resolveLegacyOnboardingWorkspace()` then `createForCustomerInWorkspace()` (grepped directly), matching §10.6 step 1's wiring exactly. No RFC-001 admin Business route/controller/view is touched by any RFC-003 milestone's authorized scope (cross-checked against every M4/M5 contract's file lists).

### RFC-002 compatibility (§20)

**PASS.** `Business::opportunityRuns()`/`Business::opportunities()` relationships untouched; no RFC-003 slice's authorized scope ever included any Opportunity file. `AdminBusinessControllerTest` and the Opportunity test suites were named as required regression commands by every M4/M5 contract precisely to catch a regression here, and no M4/M5 closure records a failure against them.

### RFC-004/RFC-005 deferrals (§26)

**PASS.** No `Agency` model, `businesses.agency_id` column, plan/entitlement column, wallet/ledger table, or Stripe integration file exists anywhere under `app/`, `database/migrations/`, or `config/` relating to Workspace. No RFC-003 contract (M1A through M6) authorized any such file. `businesses.workspace_id` remains a plain foreign key with no entitlement columns, confirmed by direct migration inspection above — the schema does not foreclose RFC-004/RFC-005 as §26 requires.

---

## §24 Acceptance-criteria mapping

### Database/domain — M1A

**PASS.** Migrations 1–5 confirmed to exist and match their §10.1 descriptions exactly (above). Migration 5 confirmed to invoke `WorkspaceBackfillV1` directly. `businesses.workspace_id` confirmed nullable in migration 4, with `NOT NULL`/FK deferred to migration 6 only. `createForCustomer()` confirmed present in the M1A-era contract shape and removed only in the M1B-boundary sense described above (its current absence is the post-M1B state; M1A-era behavior is covered by `WorkspaceM1BBoundaryTest.php`/`WorkspaceManagerPreEnforcementTest.php`, confirmed to exist). Per-group transactional backfill, conflict/incomplete failure modes, and concurrency all confirmed directly in `WorkspaceBackfillV1.php`, with dedicated test coverage (`WorkspaceBackfillV1ConcurrencyTest.php`). Unique constraints on `workspaces.uid`, `(workspace_id, user_id)`, `(workspace_membership_id, business_id)`, and every `restrictOnDelete()` FK confirmed directly in the migration files above.

### Database/domain — M1B

**PASS.** `createForCustomerInWorkspace()` and `resolveLegacyOnboardingWorkspace()` both confirmed to exist and be wired into `BusinessManager` (above). `createForCustomer()` confirmed absent from the contract and implementation via direct grep, not inferred. `enforce_business_workspace_constraint` confirmed to run its own independent zero-null precondition check before any DDL (above), matching §10.6 step 4's "independent" requirement. `businesses.workspace_id` confirmed `NOT NULL` with the `restrictOnDelete()` FK and both §9.4 indexes in the current migration file.

### Architecture/quality

**PASS.** No `Interface` suffix on any Workspace repository contract (confirmed: `WorkspaceRepository`, `WorkspaceMembershipRepository`, `WorkspaceMembershipBusinessRepository`, `WorkspaceTransitionRepository`). `BaseRepository` extension and provider-array binding follow the existing convention (unchanged from prior milestone inspections). `HasUid` used on `Workspace` only, per §6 finding 4's "independently routable" test — `WorkspaceMembership`/`WorkspaceMembershipBusiness` correctly omit it. `WorkspaceBackfillV1` confirmed query-builder-only with no Eloquent/model-event/`displayName()` dependency. No new generic service layer was introduced anywhere in this session's implementation history (`WorkspaceManager` follows the existing Library/Manager convention).

### Documentation

**PASS.** RFC-003 v1.3's §6 current-state findings remain accurate against the repository as it exists today (no drift detected during this audit). M1A/M1B are documented as independently-stoppable milestones in §23, and every subsequent milestone (2 through 6) was in fact separately contracted and closed, per the governance trail (`RFC-003-M4-SLICE-*-CONTRACT.md`/`-CLOSURE.md`, `RFC-003-M5-CONTRACT.md`/`-CLOSURE.md`, this M6 contract) — confirmed to exist in `docs/automation/`.

---

## §25 Release prerequisites relevant before tagging

- "Tagging follows the same posture as RFC-001/RFC-002... an annotated tag is created only at the end of Milestone 6, after full regression, not at the end of M1A or M1B." — **Partially satisfied**: full regression against this branch has now passed (see "Manual regression" below), but the "end of Milestone 6" condition additionally requires the M6 documentation PR to be merged and a further post-merge exact-tag-candidate regression to pass (`RFC-003-M6-CONTRACT.md` §8) before tagging — neither has happened yet. No tag has been created or proposed by this pass.
- "No tag is created, applied, or proposed as part of drafting or revising this RFC document." — **Satisfied**: this pass does not touch RFC-003 itself, and does not create any tag.
- Prior-tag precedent (`rfc-001-business-core`, `rfc-002-opportunity-engine`) confirmed, in the governing M6 contract's own drafting evidence, to be real **annotated** tag objects (verified via `git cat-file -p`) — the eventual RFC-003 tag must match that convention exactly (annotated, not lightweight), per the contract's §9.

---

## Manual regression

The governing contract locks four regression commands. **All four have now been run by the human developer against this branch, and all four passed.** PHP is unavailable in this environment (confirmed: `php -v` → command not found, consistent with every prior turn in this session), so none of these commands were run by Claude — every result below is exactly as the human reported it, with no invented count:

| Command | Result |
|---|---|
| `php artisan test tests/Unit/Workspace tests/Feature/Workspace` | **PASS** — confirmed by the human. No exact test/assertion count was supplied for this individual command, and none is recorded here. |
| `php artisan test tests/Unit/Business tests/Feature/Business` | **PASS** — confirmed by the human. No exact test/assertion count was supplied for this individual command, and none is recorded here. |
| `php artisan test tests/Unit/Opportunity tests/Feature/Opportunity` | **PASS** — confirmed by the human. No exact test/assertion count was supplied for this individual command, and none is recorded here. |
| `php artisan test --stop-on-failure` | **PASS.** Exact human-supplied final output: `Tests: 2093 passed (6449 assertions)` · `Duration: 216.72s`. The last visible test immediately before that summary was `Tests\Feature\Workspace\WorkspaceTransitionsMigrationSchemaTest` › `migration up down up cycle is clean on an isolated database`. |

This satisfies the M6 pre-merge regression gate (`docs/automation/RFC-003-M6-CONTRACT.md` §6/§7) in full. **It does not, by itself, complete Milestone 6 or RFC-003.** Per the contract's §8/§9, a separate final regression must still be run against the exact commit that results after this M6 documentation PR is human-merged into `main` — the run recorded above was against this branch (`agent/rfc-003-m6`), not against that future post-merge commit — before any tag authorization can occur. No count beyond what the human explicitly supplied is inferred or extrapolated here.

Test directories confirmed to exist and be non-empty before locking these commands (no substitution needed): `tests/Unit/Workspace` (5 files), `tests/Feature/Workspace` (55 files, including the two Milestone 5 files added since the M6 contract was drafted), `tests/Unit/Business` (3 files), `tests/Feature/Business` (12 files), `tests/Unit/Opportunity` (10 files), `tests/Feature/Opportunity` (39 files).

---

## Tag-gate status

**NOT CREATED.** No tag command was executed during this pass, per the governing contract's explicit prohibition, and none is authorized until every remaining gate below passes. Post-merge exact-tag-candidate regression (`RFC-003-M6-CONTRACT.md` §8: `git checkout main` → `pull --ff-only` → exact-SHA capture → doc-presence verification → a further `php artisan test --stop-on-failure` against that exact post-merge commit) is **still PENDING** and must remain pending until this M6 documentation PR is actually merged — the passing full-suite run recorded above was against this pre-merge branch, not against the eventual merged `main` commit, so it does not itself satisfy §8. Explicit human tag authorization has not occurred.

---

## Release-gate status

Recorded per gate, exactly:

- **Static RFC-003 conformance audit:** PASS.
- **Unresolved GAP/BLOCKED items:** none.
- **Targeted Workspace regression** (`tests/Unit/Workspace tests/Feature/Workspace`): PASS.
- **RFC-001 Business regression** (`tests/Unit/Business tests/Feature/Business`): PASS.
- **RFC-002 Opportunity regression** (`tests/Unit/Opportunity tests/Feature/Opportunity`): PASS.
- **Complete application regression** (`php artisan test --stop-on-failure`): PASS — 2093 tests / 6449 assertions / 216.72s.
- **M6 pre-merge regression gate** (`RFC-003-M6-CONTRACT.md` §6/§7): **SATISFIED.**
- **Tag:** NOT CREATED.
- **Post-M6-merge exact-tag-candidate regression** (`RFC-003-M6-CONTRACT.md` §8): still **PENDING** — must remain pending until the M6 documentation PR actually merges and a fresh regression runs against that exact post-merge commit.

**RFC-003 Milestone 6 is NOT complete yet**, and RFC-003 as a whole is not fully released: this two-document M6 PR has not merged, and the post-merge exact-tag-candidate regression/tag gate has not happened. No product or test conformance gap blocks progression — every architectural area audited above is PASS with concrete evidence, and the only remaining work is the merge and post-merge gate sequence itself.
