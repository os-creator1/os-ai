# RFC-003 Milestone 4 Slice 4A Contract

## Implementation contract

### Purpose

Implement the first bounded RFC-003 Milestone 4 customer mutation surface: create, rename, and deactivate Workspaces through the existing `WorkspaceManager` domain methods.

This contract intentionally does not authorize the rest of Milestone 4. Member management, Business creation/reassignment, and ownership transfer require later independently reviewed contracts.

### Locked target

- Implementation PR: #22
- Base: `main`
- Head: `agent/rfc-003-m4-slice-4a`
- Starting SHA: `9654e362814f85bbda7ee9154fccad9afba69c1b`
- Merge policy: human only
- Maximum bounded correction rounds: 2

The implementation must remain on this PR and branch. The trusted automation state is authoritative for the current-head lease and automatic start.

### Authorized behavior

1. Add a customer-authenticated Workspace-create HTTP action that accepts a bounded Workspace name and calls `WorkspaceManager::createWorkspace()` for the authenticated user without creating a Business in this slice.
2. Add a Workspace-rename HTTP action that resolves the target by Workspace uid and delegates authority and active-state enforcement to `WorkspaceManager::renameWorkspace()`; owner and active Admin authority must remain exactly as defined by the existing manager.
3. Add an owner-only Workspace-deactivate HTTP action that resolves the target by Workspace uid and delegates to `WorkspaceManager::deactivateWorkspace()`; Staff and Admin must not gain deactivation authority.
4. Unknown or inaccessible Workspace targets must fail closed without leaking a usable mutation surface. Domain authorization must not be reimplemented with a weaker parallel rule.
5. Add minimal customer UI controls to the existing Workspace index/overview for the authorized actions. Controls must reflect the existing role data, but server-side authorization remains authoritative.
6. Preserve the existing Milestone 3 Workspace switcher, overview, membership-directory visibility, and effective-access-filtered Business list.
7. Add focused HTTP tests covering successful create/rename/deactivate flows, validation failures, unauthorized role cases, inactive-state behavior, unknown uid behavior, persistence, redirects, and regression of the existing read-only surfaces.

### Exact implementation scope

Only these implementation paths may change after the automatic-start lease:

- `app/Http/Controllers/Customer/Workspace/WorkspaceController.php`
- `app/Http/Requests/Customer/Workspace/StoreWorkspaceRequest.php`
- `app/Http/Requests/Customer/Workspace/RenameWorkspaceRequest.php`
- `resources/views/customer/workspaces/index.blade.php`
- `resources/views/customer/workspaces/show.blade.php`
- `routes/customer.php`
- `tests/Feature/Workspace/WorkspaceMutationHttpTest.php`

The automation state and this contract are included in the state allow-list only so the trusted control plane can validate the human-merged authorization contract; Claude must not edit either file on the implementation PR.

### Required verification

Every command must discover a positive test count and exit successfully:

- `php artisan test tests/Feature/Workspace/WorkspaceMutationHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceOverviewHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceSwitcherHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceBusinessListHttpTest.php`

The deterministic gate must also enforce the exact lease delta and current PR head before Codex review is accepted.

### Explicit exclusions

- No member add/remove/reactivation, role changes, Business-access-scope changes, or Business assignments.
- No Business creation, Workspace reassignment, or Business mutation surface.
- No Workspace ownership transfer.
- No Workspace reactivation HTTP surface in this slice.
- No platform-administrator Workspace controls.
- No change to `WorkspaceManager`, repositories, models, enums, events, exceptions, migrations, database configuration, dependencies, environment files, billing, plans, entitlements, or usage wallets.
- No new generic service layer or alternate authorization algorithm.
- No automatic merge, force-push, push to `main`, tag, metered model API, or usage-credit enablement.
- No implementation beyond the exact paths and behavior above.

### Completion condition

Slice 4A is ready for human review only when the exact-scope implementation is at the current pinned PR head, every required test command passes with a positive recognized count, Codex has reviewed that same head, and any bounded correction cycle has completed. Final product merge remains human-approved.
