# RFC-003 Milestone 4 Slice 4B Contract

## Implementation contract

### Purpose

Implement the bounded RFC-003 Milestone 4 customer Workspace-membership management surface through the existing `WorkspaceManager` membership methods.

This contract keeps role (`admin`/`staff`) and Business-access scope (`all`/`selected`) as independent axes. It does not authorize Business creation/reassignment, Workspace ownership transfer/reactivation, or any later Milestone 4 surface.

### Locked target

- Implementation PR: #25
- Base: `main`
- Head: `agent/rfc-003-m4-slice-4b`
- Starting SHA: `ebd8f724564e206d5becec70f95f3b420156d60a`
- Merge policy: human only
- Maximum bounded correction rounds: 2

The implementation must remain on this PR and branch. The target marker at `docs/automation/RFC-003-M4-SLICE-4B-TARGET.md` predates the automatic-start lease and must not be edited by Claude. The trusted automation state is authoritative for the current-head lease and automatic start.

### Authorized behavior

1. Extend the existing customer Workspace overview with a manager-only membership surface. The Workspace owner and active Admin may see the management data; Staff must receive neither member-management data nor controls.
2. Address existing users and Businesses at the HTTP boundary only by their existing opaque `uid` values. Never expose, accept, or route on raw database IDs. Do not expose member email addresses, owner identifiers, or internal Workspace/membership/Business IDs.
3. Add an existing user as an active Workspace member with an explicit role (`admin` or `staff`), explicit Business-access scope (`all` or `selected`), and a complete selected-Business UID set when applicable. Resolve the submitted user UID through the existing `User` model query boundary with a nullable lookup and delegate the mutation to `WorkspaceManager::addMember()`. The legacy non-null `User::findByUid()` signature must not be allowed to turn an unknown UID into a 500 response; do not change the model in this slice.
4. Change an active member's role only through `WorkspaceManager::changeMemberRole()`. Owner-only role authority, including Admin promotion/demotion, remains exactly as enforced by the manager.
5. Change an active member's Business-access scope and complete selected-Business assignment set only through `WorkspaceManager::changeMemberBusinessAccessScope()`. `all` must carry no selected assignments. `selected` may carry zero or more Businesses.
6. Convert submitted Business UIDs to IDs only after resolving the current Workspace's Businesses. An active Admin with selected scope must not be shown or allowed to submit a Business outside that Admin's own effective access; use `WorkspaceManager::userCanAccessBusiness()` rather than creating a second access algorithm. Unknown, cross-Workspace, duplicate, malformed, or inaccessible Business UIDs must fail closed with no partial write.
7. Deactivate and reactivate membership rows only through `WorkspaceManager::deactivateMember()` and `WorkspaceManager::reactivateMember()`. Preserve assignment rows across deactivation/reactivation; never hard-delete a membership.
8. Preserve the manager's target-role authority rules: the owner manages Admin targets; owner or active Admin may manage Staff targets; Staff, inactive Admins, unrelated users, `users.parent_id`, and platform-admin status alone gain no customer Workspace authority.
9. Add one additive repository read method that returns all Workspace memberships, active and inactive, so authorized managers can see and reactivate inactive members. Keep the existing active-only repository method and existing access behavior intact.
10. Unknown or inaccessible Workspace/member/user targets must return 404 or an equivalent fail-closed response without revealing whether a hidden target exists. Domain authorization and inactive-state rules must not be replaced by parallel weaker rules.
11. Add bounded Form Requests, dedicated POST routes, minimal overview controls, and focused HTTP tests for add, role, scope/assignment, deactivate, and reactivate flows.
12. Preserve Slice 4A Workspace create/rename/deactivate behavior and all Milestone 3 switcher, overview, directory privacy, and effective Business-list behavior except for the explicitly authorized manager-only membership data/control additions. The structured read-only `businesses` rows must remain name-only; an older whole-document assertion may be narrowed because opaque Business UIDs are now intentionally present only inside authorized manager controls.

### Exact implementation scope

Only these implementation paths may change after the automatic-start lease:

- `app/Http/Controllers/Customer/Workspace/WorkspaceController.php`
- `app/Http/Requests/Customer/Workspace/StoreWorkspaceMemberRequest.php`
- `app/Http/Requests/Customer/Workspace/UpdateWorkspaceMemberRoleRequest.php`
- `app/Http/Requests/Customer/Workspace/UpdateWorkspaceMemberAccessRequest.php`
- `app/Repositories/Contracts/WorkspaceMembershipRepository.php`
- `app/Repositories/Eloquent/EloquentWorkspaceMembershipRepository.php`
- `resources/views/customer/workspaces/show.blade.php`
- `routes/customer.php`
- `tests/Feature/Workspace/WorkspaceMemberManagementHttpTest.php`
- `tests/Feature/Workspace/WorkspaceOverviewHttpTest.php`
- `tests/Feature/Workspace/WorkspaceBusinessListHttpTest.php`

The automation state and this contract are included in the state allow-list only so the trusted control plane can validate the human-merged authorization contract; Claude must not edit either file on the implementation PR. The pre-lease target marker must also remain unchanged.

### Required verification

Every command must discover a positive test count and exit successfully:

- `php artisan test tests/Feature/Workspace/WorkspaceMemberManagementHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceOverviewHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceMutationHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceBusinessListHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceSwitcherHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceMembershipLifecycleTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceMembershipBusinessAccessTest.php`

The deterministic gate must also enforce the exact lease delta and current PR head before Codex review is accepted.

These commands may be executed by the automated deterministic gate, or manually by a human developer in their own local environment against the exact verified head. Either satisfies this requirement, provided the outcome and the exact verified head are recorded in `docs/automation/AI-AUTONOMY-STATE.json`'s `manual_verification` record when run manually.

### Required test coverage

The focused HTTP suite must prove at minimum:

- route names, POST-only mutation verbs, CSRF/customer authentication boundary, and validation;
- successful add with each role and each scope, including selected assignments;
- owner-only Admin add/role changes and owner-or-active-Admin Staff lifecycle authority;
- Staff, inactive Admin, unrelated, `parent_id`, and platform-admin-only denial;
- owner cannot be added as a membership;
- duplicate add/no-op and conflicting duplicate behavior remain manager-defined;
- `all` rejects assignments; `selected` supports an empty set and normalized complete sets;
- unknown, duplicate, inaccessible, and cross-Workspace UIDs write nothing;
- selected-scope Admin cannot see or assign Businesses outside their effective access;
- role/scope independence is preserved;
- deactivation retains assignments, removes effective access, and reactivation restores the retained access;
- inactive Workspace blocks every membership mutation;
- manager-only data uses opaque UIDs and excludes raw database IDs and email addresses;
- existing Slice 4A and Milestone 3 behavior does not regress.

### Explicit exclusions

- No Business creation, mutation, deletion, or Workspace reassignment.
- No Workspace ownership transfer or Workspace reactivation HTTP surface.
- No invitation/email flow, new-user creation, password flow, or outbound notification.
- No member hard delete and no assignment behavior outside the complete-set synchronization already provided by `WorkspaceManager`.
- No platform-administrator Workspace controls.
- No change to `WorkspaceManager`, Workspace/Business/User/membership models, enums, events, exceptions, migrations, database configuration, dependencies, environment files, billing, plans, entitlements, feature toggles, or usage wallets.
- No new generic service layer, alternate authorization algorithm, direct model write, or repository bypass for mutations.
- No application change outside the eleven exact allowed implementation paths.
- No edit to the target marker, this contract, or automation state from the implementation PR.
- No automatic generation or approval of a later contract.
- No `advance_automatically: true`, merge-policy change, or weakening of current-head, exact-scope, positive-test, or Codex trust gates.
- No automatic merge, force-push, direct push to `main`, tag, metered model API, or usage-credit enablement.

### Completion condition

Slice 4B is ready for human review when the exact-scope implementation is at the current pinned PR head and every required test command has passed with a positive recognized count against that exact head — verified either by the automated deterministic gate followed by Codex review, or by a human developer running the required commands manually and recording the result as described above. Under the governance transition recorded in `docs/automation/AI-SUBSCRIPTION-LOOP.md`, Codex review and the automatic Claude Routine handoff are not required for completion; a human-reviewed manual verification is sufficient. Final product merge remains exclusively human-approved regardless of which verification path was used.
