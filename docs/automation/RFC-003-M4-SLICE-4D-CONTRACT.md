# RFC-003 Milestone 4 Slice 4D Contract

**Status: proposed. Not yet authorized. Implementation must not begin under this document alone.**

## Implementation contract

### Purpose

Implement the fifth bounded RFC-003 Milestone 4 customer mutation surface: creating a Business inside an existing, accessible Workspace, through the existing `WorkspaceManager::createBusinessInWorkspace()` domain method.

RFC-003 §23's Milestone 4 bullet ("Create/rename/deactivate Workspace, manage members (role, scope, assignments), create/reassign Business, transfer ownership") has three remaining unimplemented HTTP surfaces: Business creation (§16.1), Business reassignment (§16.2), and Workspace ownership transfer (§15). All three domain methods already exist and are already covered by `tests/Feature/Workspace/WorkspaceBusinessOrchestrationTest.php` (creation, reassignment) and `tests/Feature/Workspace/WorkspaceOwnershipTransferTest.php` (transfer) at the domain level — none has an HTTP surface today.

Of the three, `createBusinessInWorkspace()` is the smallest and least coupled: a single Workspace lock, owner-or-active-Admin authority (the same rule already used by `renameWorkspace()` and `changeMemberBusinessAccessScope()`), no cross-Workspace consistency check, and — per the method's own docblock — no `workspace_transitions` audit row. By contrast `reassignBusiness()` locks two Workspaces in ascending-ID order, re-verifies the Business's actual source Workspace against a possibly-stale caller model, requires authority over both Workspaces, removes stale scoped-access grants, and writes a durable `workspace_transitions` row; `transferOwnership()` locks two `users` rows and up to two membership rows and requires the caller to resolve the previous owner's disposition explicitly. This contract authorizes only Business creation. Business reassignment and Workspace ownership transfer remain for later, independently reviewed and separately bounded contracts — deliberately not combined here.

### Locked target

- Implementation PR: not yet opened
- Base: `main`
- Head: not yet created (expected name, following the existing per-slice convention: `agent/rfc-003-m4-slice-4d`)
- Starting SHA: not yet pinned
- Merge policy: human only
- Maximum bounded correction rounds: 2

**This contract is a proposal, not a lease.** The manual authorization sequence is exactly: (1) this Slice 4D contract is human-reviewed and merged; (2) only after that merge may a dedicated Slice 4D implementation branch be created from current `main` and a draft/baseline implementation PR be opened — solely to establish the exact target PR number, branch, and starting SHA identity; (3) at that stage no Slice 4D product/application implementation may be written yet; (4) the exact implementation PR number, head branch, and full starting SHA are then recorded in a separate, human-reviewed `docs/automation/AI-AUTONOMY-STATE.json` update that sets `implementation_authorized: true`, under the manual bounded-contract workflow described in `docs/automation/AI-SUBSCRIPTION-LOOP.md`'s manual completion path; (5) a human reviews and merges that state update; (6) only after that authorizing state update is merged may Slice 4D product implementation begin. `start_automatically_after_contract_merge` remains `false` throughout this sequence — no automatic starter is involved at any step. Product merge remains exclusively human-only.

### Authorized behavior

1. Add a customer-authenticated "create Business" HTTP mutation, scoped to an existing Workspace, resolved by the Workspace's opaque `uid` via the existing `resolveAccessibleWorkspace()` pattern already used by every other Workspace mutation action in this controller.
2. The controller derives the acting Customer exclusively from the authenticated user's existing `User::customer()` relationship — never from request input, route input, or any other caller-controlled value. No customer uid, id, or similar value is accepted as an authoritative target from anywhere in the request. This authenticated-user-to-Customer binding is the required HTTP tenancy boundary for this slice, not a competing Workspace authorization algorithm.
3. The `WorkspaceController` creation action reuses the existing `App\Http\Requests\Business\UpsertBusinessIdentityRequest` directly and unchanged — it is already a generic Business request (not Workspace-specific or onboarding-only) that owns the complete Business identity contract: validation rules, `BusinessIndustry` enum validation, `industry_other` handling, `country_code`/`currency_code` normalization, and blank nullable-field normalization. Its validated payload contains Business identity fields only — no customer identifier of any kind. `UpsertBusinessIdentityRequest` is not modified by this slice. No new Form Request is created; the controller only type-hints it and consumes its validated payload before calling the manager.
4. The controller calls `WorkspaceManager::createBusinessInWorkspace()` passing exactly: the authenticated actor's user id, the authenticated actor's own `Customer` (resolved per item 2 above, never from request input), the resolved target Workspace, and the validated Business identity payload. `WorkspaceManager` is authoritative only for Workspace mutation authority (owner-or-active-Admin) and the active-Workspace requirement — its documented contract deliberately does **not** infer or verify Customer ownership from `actorUserId`; it accepts the caller-supplied `Customer` independently of Workspace ownership and the actor (RFC-003 §11.2), by design. The item-2 tenancy binding above is what keeps this safe, not the manager. No alternate Workspace authorization check may be added in the controller.
5. Unknown or inaccessible Workspace targets fail closed with the same 404 already used by every other Workspace mutation action in this controller.
6. Add a minimal "Create Business" control to the existing Workspace overview, visible only when the viewer's role is Owner or Admin — mirroring how the existing member-management controls are already gated. Controls must reflect existing role/state data; server-side authorization remains authoritative.
7. Preserve Slice 4A, Slice 4B, Slice 4C behavior and all Milestone 3 read-only surfaces exactly. A successfully created Business must appear in the existing effective-access Business list (`effectiveBusinesses()`) without any change to that method's own filtering algorithm.
8. Add a dedicated focused HTTP test file covering: successful creation (owner and active Admin), validation failures, owner-or-active-Admin authority denial (Staff, inactive Admin, unrelated user, platform-admin-alone), inactive-Workspace denial, and unknown/inaccessible-uid handling.

### Exact implementation scope

Only these implementation paths may change once a separate authorizing state update pins this contract to a real implementation PR/branch:

- `app/Http/Controllers/Customer/Workspace/WorkspaceController.php`
- `resources/views/customer/workspaces/show.blade.php`
- `routes/customer.php`
- `tests/Feature/Workspace/WorkspaceBusinessCreationHttpTest.php` (new)

`App\Http\Requests\Business\UpsertBusinessIdentityRequest` is reused directly and is deliberately **not** listed above — it is not touched by this slice.

The automation state and this contract are included in any future allow-list only so the trusted control plane can validate the human-merged authorization contract; Claude must not edit either file on the implementation PR once one exists.

### Required verification

Every command must discover a positive test count and exit successfully:

- `php artisan test tests/Feature/Workspace/WorkspaceBusinessCreationHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceMutationHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceReactivationHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceOverviewHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceSwitcherHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceBusinessListHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceMemberManagementHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceBusinessOrchestrationTest.php`

These commands may be executed by an automated deterministic gate, or manually by a human developer in their own local environment against the exact verified head, per the manual completion path established in `docs/automation/AI-SUBSCRIPTION-LOOP.md`. Either satisfies this requirement provided the outcome and the exact verified head are recorded.

### Required test coverage

The focused HTTP suite must prove at minimum:

- the route name, POST-only mutation verb, and CSRF/customer-authentication boundary;
- validation: `name`, `industry`, `country_code`, `timezone`, and `currency_code` are required; `industry_other` is required only when `industry` is `other`; every field respects the same bounds already enforced by `UpsertBusinessIdentityRequest`;
- successful creation by the owner and by an active Admin, including its redirect and flash-success behavior, and that the created Business is persisted with `customer_id` equal to the actor's own Customer and `workspace_id` equal to the target Workspace;
- a caller cannot choose another Customer by submitting a `customer_id`, customer uid, or any similar caller-controlled value — such input is ignored (or rejected), never honored as the created Business's owner;
- the persisted `Business.customer_id` is always the authenticated actor's own Customer id, proven separately for both an owner and an authorized active Admin actor;
- owner-or-active-Admin authority: Staff, an inactive Admin, an unrelated user, and platform-admin status alone are all denied;
- creation against an inactive Workspace fails closed (fail-closed/flash-error, matching the manager's `InactiveWorkspaceMutationException`);
- an unknown or inaccessible Workspace uid fails closed with 404;
- a created Business appears in the read-only effective-access Business list afterward;
- existing Slice 4A, 4B, 4C, and Milestone 3 behavior does not regress.

### Explicit exclusions

- No Business reassignment (RFC-003 §16.2) or Workspace ownership transfer (§15) HTTP surface — later, separately bounded contracts.
- No Business creation on behalf of a customer other than the acting user's own Customer record.
- No member add, role, scope, deactivate, or reactivate change — Slice 4B's membership surface is closed and out of scope here.
- No Workspace create, rename, deactivate, or reactivate behavior change — Slices 4A and 4C are closed and out of scope here.
- No change to `WorkspaceManager`, repositories, models, enums, events, exceptions, migrations, database configuration, dependencies, environment files, billing, plans, entitlements, or usage wallets — `createBusinessInWorkspace()` already exists and is fully implemented; this slice only wires an HTTP action to it.
- No new generic service layer or alternate authorization algorithm.
- No new Form Request and no modification to `App\Http\Requests\Business\UpsertBusinessIdentityRequest` — it is reused exactly as it exists today.
- No automatic merge, force-push, push to `main`, tag, metered model API, or usage-credit enablement.
- No Codex review requirement for completion, and no automatic Claude Routine handoff requirement — per the manual completion path established in `docs/automation/AI-SUBSCRIPTION-LOOP.md`.
- No implementation of any kind, and no implementation PR or branch, before this contract itself is human-reviewed, merged, and paired with a separate authorizing `AI-AUTONOMY-STATE.json` update.
- No implementation beyond the exact paths and behavior above.

### Completion condition

Slice 4D is ready for human review when the exact-scope implementation is at the pinned PR head and every required test command has passed with a positive recognized count against that exact head — verified either by an automated deterministic gate or by a human developer running the required commands manually and recording the result, per the manual completion path. Codex review and an automatic Claude Routine handoff are not required. Final product merge remains exclusively human-approved.

**Implementation is not authorized under this document alone.** This contract must first be human-reviewed and merged; a separate `AI-AUTONOMY-STATE.json` update must then explicitly authorize and pin an implementation PR/branch/SHA before any code under "Exact implementation scope" may be written.
