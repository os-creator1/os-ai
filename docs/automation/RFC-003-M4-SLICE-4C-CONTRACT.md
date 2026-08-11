# RFC-003 Milestone 4 Slice 4C Contract

**Status: proposed. Not yet authorized. Implementation must not begin under this document alone.**

## Implementation contract

### Purpose

Implement the fourth bounded RFC-003 Milestone 4 customer mutation surface: Workspace reactivation, the owner-only symmetric counterpart to Slice 4A's deactivation, through the existing `WorkspaceManager::reactivateWorkspace()` domain method.

Slice 4C exposes the already-implemented RFC-003 Workspace reactivation lifecycle operation (§23 Milestone 2: "Workspace lifecycle (create, rename, deactivate/reactivate)") through the Milestone 4 customer mutation HTTP surface. Milestone 4's own bullet ("Create/rename/deactivate Workspace, manage members (role, scope, assignments), create/reassign Business, transfer ownership") does not explicitly list reactivation; Slice 4A shipped create/rename/deactivate and Slice 4B shipped member management. `WorkspaceManager::reactivateWorkspace()` is already fully implemented (owner-only authority, idempotent no-op on an already-active Workspace, `WorkspaceReactivated` event dispatch) and already covered by `tests/Feature/Workspace/WorkspaceLifecycleTest.php` at the domain level — it currently has no HTTP surface at all. This contract adds only that HTTP wiring.

This contract does **not** authorize Business creation, Business reassignment, or Workspace ownership transfer — RFC-003 §16 and §15's remaining HTTP surfaces. Those are larger, carry additional audit/event requirements (§19), and remain for later, independently reviewed and separately bounded contracts.

### Locked target

- Implementation PR: not yet opened
- Base: `main`
- Head: not yet created (expected name, following the existing per-slice convention: `agent/rfc-003-m4-slice-4c`)
- Starting SHA: not yet pinned
- Merge policy: human only
- Maximum bounded correction rounds: 2

**This contract is a proposal, not a lease.** The manual authorization sequence is exactly: (1) this Slice 4C contract is human-reviewed and merged; (2) only after that merge may a dedicated Slice 4C implementation branch be created from current `main` and a draft/baseline implementation PR be opened — solely to establish the exact target PR number, branch, and starting SHA identity; (3) at that stage no Slice 4C product/application implementation may be written yet; (4) the exact implementation PR number, head branch, and full starting SHA are then recorded in a separate, human-reviewed `docs/automation/AI-AUTONOMY-STATE.json` update that sets `implementation_authorized: true`, under the manual bounded-contract workflow described in `docs/automation/AI-SUBSCRIPTION-LOOP.md`'s manual completion path; (5) a human reviews and merges that state update; (6) only after that authorizing state update is merged may Slice 4C product implementation begin. `start_automatically_after_contract_merge` remains `false` throughout this sequence — no automatic starter is involved at any step. Product merge remains exclusively human-only.

### Authorized behavior

1. Add a customer-authenticated Workspace-reactivate HTTP action that resolves the target by Workspace `uid` (via the existing `resolveAccessibleWorkspace()` pattern already used by `rename()`/`deactivate()`) and delegates entirely to `WorkspaceManager::reactivateWorkspace()`. Owner-only authority, and the manager's existing idempotent-no-op behavior on an already-active Workspace, remain exactly as enforced by the manager — never reimplemented in the controller.
2. Unknown or inaccessible Workspace targets fail closed with the same 404 already used by every other Workspace mutation action in this controller.
3. Add a minimal control to the existing Workspace overview so the owner can trigger reactivation when the Workspace is inactive, mirroring how the existing deactivate control is already surfaced. Controls must reflect existing role/state data; server-side authorization remains authoritative.
4. Preserve Slice 4A and Slice 4B behavior and all Milestone 3 read-only surfaces exactly.
5. Add a dedicated focused HTTP test file covering: successful reactivation and its redirect/flash behavior, owner-only authority (Admin, Staff, unrelated-user, and platform-admin-alone denial), the idempotent no-op on an already-active Workspace, and unknown/inaccessible-uid handling.

### Exact implementation scope

Only these implementation paths may change once a separate authorizing state update pins this contract to a real implementation PR/branch:

- `app/Http/Controllers/Customer/Workspace/WorkspaceController.php`
- `resources/views/customer/workspaces/show.blade.php`
- `routes/customer.php`
- `tests/Feature/Workspace/WorkspaceReactivationHttpTest.php` (new)

No new Form Request is needed — `reactivate()` takes no request body, matching the existing `deactivate()` action's signature exactly.

The automation state and this contract are included in any future allow-list only so the trusted control plane can validate the human-merged authorization contract; Claude must not edit either file on the implementation PR once one exists.

### Required verification

Every command must discover a positive test count and exit successfully:

- `php artisan test tests/Feature/Workspace/WorkspaceReactivationHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceMutationHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceOverviewHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceSwitcherHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceBusinessListHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceMemberManagementHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceLifecycleTest.php`

These commands may be executed by an automated deterministic gate, or manually by a human developer in their own local environment against the exact verified head, per the manual completion path established in `docs/automation/AI-SUBSCRIPTION-LOOP.md`. Either satisfies this requirement provided the outcome and the exact verified head are recorded.

### Required test coverage

The focused HTTP suite must prove at minimum:

- the route name, POST-only mutation verb, and CSRF/customer-authentication boundary;
- successful reactivation of an inactive Workspace, including its redirect and flash-success behavior;
- owner-only authority: active Admin, Staff, an inactive Admin, an unrelated user, and platform-admin status alone are all denied;
- the manager's idempotent no-op when reactivating an already-active Workspace;
- an unknown or inaccessible Workspace uid fails closed with 404;
- existing Slice 4A, Slice 4B, and Milestone 3 behavior does not regress.

### Explicit exclusions

- No Business creation, mutation, deletion, or Workspace reassignment.
- No Workspace ownership transfer.
- No member add, role, scope, deactivate, or reactivate change — Slice 4B's membership surface is closed and out of scope here.
- No invitation/email flow, new-user creation, password flow, outbound notification, or platform-administrator Workspace control.
- No change to `WorkspaceManager`, repositories, models, enums, events, exceptions, migrations, database configuration, dependencies, environment files, billing, plans, entitlements, or usage wallets — `reactivateWorkspace()` already exists and is fully implemented; this slice only wires an HTTP action to it.
- No new generic service layer or alternate authorization algorithm.
- No automatic merge, force-push, push to `main`, tag, metered model API, or usage-credit enablement.
- No Codex review requirement for completion, and no automatic Claude Routine handoff requirement — per the manual completion path established in `docs/automation/AI-SUBSCRIPTION-LOOP.md`.
- No implementation of any kind, and no implementation PR or branch, before this contract itself is human-reviewed, merged, and paired with a separate authorizing `AI-AUTONOMY-STATE.json` update.
- No implementation beyond the exact paths and behavior above.

### Completion condition

Slice 4C is ready for human review when the exact-scope implementation is at the pinned PR head and every required test command has passed with a positive recognized count against that exact head — verified either by an automated deterministic gate or by a human developer running the required commands manually and recording the result, per the manual completion path. Codex review and an automatic Claude Routine handoff are not required. Final product merge remains exclusively human-approved.

**Implementation is not authorized under this document alone.** This contract must first be human-reviewed and merged; a separate `AI-AUTONOMY-STATE.json` update must then explicitly authorize and pin an implementation PR/branch/SHA before any code under "Exact implementation scope" may be written.
