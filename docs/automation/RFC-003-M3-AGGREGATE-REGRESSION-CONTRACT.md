# RFC-003 Milestone 3 — Aggregate Regression Contract

## Status and authority

This is the human-approved, verification-only contract for the RFC-003
Milestone 3 aggregate regression on pull request #2
(`feature/rfc-003-m3` -> `main`). It is read together with:

- `docs/rfcs/RFC-003-WORKSPACE-AND-BUSINESS-ACCOUNT-CORE.md`;
- `docs/automation/AI-AUTONOMY-STATE.json`;
- the approved Milestone 3 Slice 3A, 3B, and 3C contracts and evidence; and
- `CLAUDE.md` and `AGENTS.md`.

The trusted copy on `main` is authoritative. Pull request #2 contains a stale
pre-Slice-3C copy of `AI-AUTONOMY-STATE.json`; that branch copy is historical
input only and must not be used to reinterpret the completed Milestone 3
scope, request a correction, or widen this contract.

This contract authorizes one deterministic regression run. It authorizes no
product, test, route, migration, dependency, workflow, environment, or
database-configuration change on pull request #2. A failure stops at
`ai:needs-human` and requires a new human-approved correction contract. It
does not authorize Claude, another Codex review, a merge, a tag, Milestone 4,
or automatic advancement.

## Locked aggregate regression contract

### Pinned target

The regression may run only when all of these facts are simultaneously true:

- repository: `os-creator1/os-ai`;
- pull request: `#2`, open and still draft;
- base branch: `main`;
- head branch: `feature/rfc-003-m3` in the same repository;
- exact head SHA: `019f3dc65a40b338e353906de9ed481f05323d05`;
- merge policy: `human_only`;
- automatic advancement: disabled; and
- the only active managed terminal state is `ai:ready-for-human`.

If the head moves, the PR ceases to be draft, a conflicting automation label
appears, or any identity differs, the run is stale or unauthorized and must
make no success claim.

### Exact cumulative Milestone 3 scope

The trusted workflow compares the merge-base delta from the then-current
`main` branch to the pinned PR head. The delta must contain exactly these nine
paths, neither a subset nor a superset:

- `app/Helpers/Helper.php`
- `app/Http/Controllers/Customer/Workspace/WorkspaceController.php`
- `resources/lang/en/locale.php`
- `resources/views/customer/workspaces/index.blade.php`
- `resources/views/customer/workspaces/show.blade.php`
- `routes/customer.php`
- `tests/Feature/Workspace/WorkspaceBusinessListHttpTest.php`
- `tests/Feature/Workspace/WorkspaceOverviewHttpTest.php`
- `tests/Feature/Workspace/WorkspaceSwitcherHttpTest.php`

The controller, both views, and all three HTTP test classes must exist at the
pinned head. The regression does not create, modify, commit, or push any file.

### Required command order

After a clean Composer install and a forced migration of only the disposable
`ultimatesms_testing` MySQL database, run these commands in this exact order:

```text
php artisan test --filter=WorkspaceBusinessListHttpTest
php artisan test --filter=WorkspaceOverviewHttpTest
php artisan test --filter=WorkspaceSwitcherHttpTest
php artisan test --filter=WorkspaceEffectiveAccessTest
php artisan test tests/Feature/Workspace
php tests/Feature/Workspace/Support/run_historical_m1a_suite.php
php tests/Feature/Workspace/Support/run_workspace_enforcement_suite.php
```

The first four commands re-prove the completed Slice 3C HTTP and effective-
access boundary before broader regression. The directory command runs every
ordinary Workspace feature test, covering the final-schema Milestone 1,
Milestone 2, and Milestone 3 surface. The final two repository-owned runners
cover the PHPUnit groups deliberately excluded from the ordinary suite:

- `historical-m1a` plus `workspace-pre-enforcement`, using a uniquely named
  `ultimatesms_testing_historical_*` database; and
- `workspace-enforcement`, using a uniquely named
  `ultimatesms_testing_enforcement_*` database.

Those runners must independently verify the base connection is
`ultimatesms_testing`, strictly validate their generated database names, run
in child processes against only their temporary databases, and drop those
databases in cleanup. The workflow may use only the disposable service
container's fixed local administrator to grant `ultimatesms_test` privileges
only on the two strict temporary-database prefixes. The application test
connection remains `ultimatesms_test`; the administrator credential must
never be reused outside that one grant step or the ephemeral service. No
production-looking database or credential is permitted.

Every command must exit zero and report a recognizable positive test count.
`No tests found`, zero tests, an unrecognized summary, timeout, setup error,
cleanup error, migration error, or non-zero exit is a regression failure.

### Coverage boundary

The aggregate evidence must include all RFC-003 Milestone 1 through
Milestone 3 Workspace tests, including:

- schema, restrictive foreign keys, database uniqueness, model relations,
  repository scoping, and `users.parent_id` isolation;
- query-builder-only historical backfill behavior, chunking, partial-state
  safety, retry/idempotency, concurrency, exact zero-null failure, and the
  migration/command single-implementation boundary;
- explicit-Workspace Business creation, legacy resolver ambiguity and
  concurrency, final `NOT NULL` enforcement, and creation-path compatibility;
- Workspace lifecycle, membership lifecycle, selected/all Business scope,
  effective access, ownership transfer, durable transitions, and
  cross-Workspace leakage prevention; and
- the complete read-only Workspace index, overview, membership directory,
  access-filtered Business list, navigation, escaping, no-identifier-leak,
  no-write, and no-mutation-route surface from Milestone 3.

The regression must not introduce plans, subscriptions, entitlements,
Business-slot limits, billing, wallets, usage accounting, Stripe, or other
RFC-004/RFC-005 work. It does not claim full RFC-001/RFC-002 regression or the
Milestone 6 release/tag gate.

## Evidence and terminal behavior

On success, the trusted workflow must:

- re-read PR #2 and prove the head is still the pinned SHA;
- post one `[AI-M3-AGGREGATE-REGRESSION PASSED]` comment authored by
  `github-actions[bot]`;
- include the exact tested head, exact nine changed files, each literal test
  command, and each positive reported test count;
- leave `ai:ready-for-human` in place; and
- leave PR #2 open, draft, and unmerged.

On an authorized run failure, the trusted workflow must remove
`ai:ready-for-human`, add `ai:needs-human`, link the failed Actions run, and
state that no merge occurred. A stale run whose target head has moved must
not mutate labels.

The workflow must never mention `@codex`, create an implementation or
correction lease, fire the Claude Routine, use a metered model credential,
push, merge, approve, tag, or change the PR from draft. After a successful
run, the only next action is the human review and merge decision for RFC-003
Milestone 3.
