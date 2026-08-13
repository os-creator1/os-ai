# RFC-003 Milestone 4 Slice 4F Contract

**Status: completed and closed.** Authorization was completed via PR #53; implementation was PR #52 (branch `agent/rfc-003-m4-slice-4f`), human-merged as `db7829d2c33ff3eb77a5bd42820eb39e349f6d94`. See `docs/automation/RFC-003-M4-CLOSURE.md` ("Slice 4F final evidence") for final closure evidence — Slice 4F does not have a separate slice-level closure document; its completion is recorded in the combined Milestone 4 closure.

## Implementation contract

### Purpose

Implement the seventh and final bounded RFC-003 Milestone 4 customer mutation surface: Workspace ownership transfer, through the existing `WorkspaceManager::transferOwnership()` domain method — reused entirely, not redesigned.

Per `docs/automation/RFC-003-M4-SLICE-4E-CLOSURE.md`'s "Remaining Milestone 4 scope," `transferOwnership()` is the sole customer mutation surface from RFC-003 §23's Milestone 4 bullet ("Create/rename/deactivate Workspace, manage members (role, scope, assignments), create/reassign Business, transfer ownership") still unexposed at the HTTP layer. Its domain implementation, the `WorkspaceOwnershipTransferDisposition` DTO, and the `WorkspaceOwnershipTransferMode` enum all already exist and are already fully tested in `tests/Feature/Workspace/WorkspaceOwnershipTransferTest.php`. This contract adds only the HTTP/UI wiring; it authorizes no change to any of that domain code.

### Domain semantics this contract must not duplicate

Read directly from `WorkspaceManager::transferOwnership()` and `WorkspaceOwnershipTransferDisposition` before implementation, and reused exactly as-is:

- The Workspace is re-locked authoritatively; a missing Workspace throws `WorkspaceNotFoundException`; an inactive Workspace throws `InactiveWorkspaceMutationException`.
- `assertActorIsOwner()` is the sole authority check — **owner-only**, no active-Admin bypass, no Staff bypass, no platform-admin bypass. This is stricter than every prior Milestone 4 slice's owner-or-active-Admin rule.
- A `new_owner_user_uid` resolving to the same user as the current owner is an authorized no-op, evaluated immediately after the authority/active-state checks and *before* the incoming/previous User rows are ever locked — the submitted disposition is never inspected or written for a same-owner call, matching every other Slice's no-op treatment.
- For a real transfer: previous and incoming owner `users` rows are locked in ascending-ID order, then both `WorkspaceMembership` rows (a missing row is a valid `null`) in the same order. A missing incoming user throws `WorkspaceInvalidIncomingOwnerException`. A missing *current*-owner `users` row is an uncaught `RuntimeException` representing corrupted persisted state (a foreign-key invariant violation), never a normal input-validation case — this contract must not catch or reinterpret it as a request error.
- The incoming owner's existing active membership, if any, is deactivated (never deleted) before `owner_user_id` changes.
- `owner_user_id` changes only inside `WorkspaceRepository::transferOwnership()`, called only from within `WorkspaceManager::transferOwnership()`.
- The previous owner's disposition is then reconciled exactly per `WorkspaceOwnershipTransferDisposition`'s two modes (see below), a `workspace_transitions` ownership-transfer row is written, and `WorkspaceOwnershipTransferred` is dispatched — all existing, unmodified `WorkspaceManager` behavior.
- Cross-Workspace Business IDs inside a `convert_to_admin` disposition surface as `CrossWorkspaceAssignmentException` from the same `syncForMembership()` validation `reassignBusiness()`/`changeMemberBusinessAccessScope()` already rely on (confirmed reachable and tested: `test_cross_workspace_business_id_in_disposition_rolls_back_everything`), rolling back the entire transaction. This should be unreachable after correct controller-side Business uid resolution (see below), but must still fail closed if the domain detects it.

None of this may be reimplemented, duplicated, or second-guessed in the controller.

### Previous-owner disposition — existing DTO, used exactly as it exists

- `WorkspaceOwnershipTransferDisposition::deactivate()` — no scope, no Business IDs.
- `WorkspaceOwnershipTransferDisposition::convertToAdmin(WorkspaceBusinessAccessScope $scope, array $businessIds = [])` — `all` with a non-empty `$businessIds` throws `InvalidBusinessAccessScopeAssignmentException` (existing DTO validation, already enforced); `selected` with an empty `$businessIds` is a valid, already-tested state (`test_convert_to_admin_selected_scope_with_empty_ids_succeeds`).

The controller must construct exactly one of these two factories explicitly from validated request input — never a third disposition, never a default. The caller (customer) must choose explicitly; the Form Request enforces this is always present. Neither the DTO nor `WorkspaceOwnershipTransferMode` nor `WorkspaceBusinessAccessScope` may be modified.

### Locked target (historical — closed)

- Implementation PR: [#52](https://github.com/os-creator1/os-ai/pull/52)
- Base: `main`
- Head: `agent/rfc-003-m4-slice-4f`
- Authorization PR: [#53](https://github.com/os-creator1/os-ai/pull/53) (human-merged, pinned the authorized baseline SHA below)
- Authorized baseline SHA: `c24af2212e017a66ab8fc42c7bcbba3d469dda72`
- Final product head: `99f3e218094f40a283bab3ded6a097734b68787b`
- Human merge commit on `main`: `db7829d2c33ff3eb77a5bd42820eb39e349f6d94`
- Merge policy: human only
- Maximum bounded correction rounds: 2

The manual authorization sequence that was actually followed: (1) this Slice 4F contract was human-reviewed and merged (PR #51); (2) a dedicated Slice 4F implementation branch (`agent/rfc-003-m4-slice-4f`) was created from then-current `main` and an inert Draft baseline implementation PR (#52) was opened solely to establish the exact target PR number, branch, and starting SHA identity; (3) no Slice 4F product/application implementation was written until that baseline was pinned; (4) the exact implementation PR number, head branch, and full starting SHA were then recorded in a separate, human-reviewed `docs/automation/AI-AUTONOMY-STATE.json` update (PR #53) that set `implementation_authorized: true`; (5) a human reviewed and merged that state update; (6) only after that authorizing state update was merged did Slice 4F product implementation begin. `start_automatically_after_contract_merge` remained `false` throughout — no automatic starter was involved at any step. Product merge was human-only. `docs/automation/AI-AUTONOMY-STATE.json` has since been returned to an idle, non-authorized state — see `docs/automation/RFC-003-M4-CLOSURE.md`.

### Authorized behavior

1. **Route.** Add exactly one mutation route: `POST workspaces/{workspaceUid}/ownership/transfer`, route name `customer.workspaces.ownership.transfer`. No GET mutation route.

2. **Workspace addressability, not authority.** Resolve the Workspace via the existing `resolveAccessibleWorkspace()` pattern, unchanged — no competing Workspace authorization algorithm. This establishes only that the actor has *some* effective role (owner, active Admin, or active Staff); `WorkspaceManager::transferOwnership()` remains exclusively authoritative for the actual owner-only mutation rule. Concretely: the current owner may transfer; an active Admin or active Staff can *address* the Workspace (resolver succeeds) but the manager denies them (`UnauthorizedWorkspaceManagementException`); an inactive Admin, an unrelated user, or a platform-admin-only user with no owner/active-membership relationship all fail closed at the resolver with 404, before the manager is ever reached — identical to every prior Workspace-level mutation in this controller.

3. **Incoming owner resolved by opaque uid only.** `new_owner_user_uid` is resolved via the existing convention already used by member management: `User::query()->where('uid', $uid)->first()`. No new User repository is introduced. An unknown incoming-User uid fails closed with 404 before any manager call. The incoming User is **not** required to already be a Workspace member (the manager already handles a missing incoming membership as a valid `null`), and no additional Customer requirement is invented — `WorkspaceManager::transferOwnership()` itself accepts a plain `int $newOwnerUserId`, nothing more.

4. **Disposition constructed explicitly from validated input.** `previous_owner_disposition = deactivate` → `WorkspaceOwnershipTransferDisposition::deactivate()`. `previous_owner_disposition = convert_to_admin` → `WorkspaceOwnershipTransferDisposition::convertToAdmin($scope, $businessIds)`, where `$scope` is the validated `business_access_scope` and `$businessIds` is resolved per item 5 below (empty array for `all`).

5. **Business uid resolution for `convert_to_admin` + `selected` — existing machinery only, never trusted numeric IDs.** Submitted `business_uids[]` are resolved through the existing `resolveManageableBusinessIds()` (already used by `storeMember()`/`updateMemberAccess()`), which filters through `accessibleBusinesses()`/`userCanAccessBusiness()`. Because ownership transfer is owner-only, and the Workspace owner always has unconditional Business access under the canonical §14.1 algorithm, this reused method naturally resolves against the owner's full effective Business set — no new algorithm, no relaxed check. An unknown, inaccessible, or cross-Workspace Business uid returns `null` from that existing method and fails closed with 404 before any transfer write, exactly as `storeMember()` already does. For `convert_to_admin` + `all`, no Business uids are accepted (validated at the Form Request level) and an empty ID array is passed. For `deactivate`, no Business-scope or assignment input participates at all.

6. **Delegate entirely to `WorkspaceManager::transferOwnership($actorUserId, $workspace, $newOwnerUserId, $disposition)`.** No part of the manager's locking, re-verification, authority, active-state, no-op, membership reconciliation, transition-audit, or event behavior is reimplemented, duplicated, or second-guessed in the controller.

7. **Manager exception → HTTP response policy:**
   - `UnauthorizedWorkspaceManagementException` → `redirect()->back()->with('flash_error', ...)` — reachable only once Workspace addressability already succeeded (owner, active Admin, or active Staff), consistent with every existing Workspace-level mutation's flash-error pattern.
   - `InactiveWorkspaceMutationException` → `redirect()->back()->with('flash_error', ...)` — the owner of an inactive Workspace can still address it under existing presentation semantics; the manager remains authoritative for the inactive-mutation prohibition.
   - `WorkspaceNotFoundException` → `abort(404)`.
   - `WorkspaceInvalidIncomingOwnerException` → `abort(404)` — normally prevented by the item-3 uid resolution; caught only as a safe race/staleness backstop, matching how the equivalent Business-side races are handled in Slice 4E.
   - `CrossWorkspaceAssignmentException` → `abort(404)` — normally unreachable after correct item-5 Business uid resolution; caught only as a safe backstop for stale/cross-Workspace domain-detected input, same rationale.
   - Same-owner call → not an exception; ordinary success, matching every other Slice's no-op treatment.
   - Unknown/inaccessible Workspace uid → existing `abort(404)` from item 2, unchanged.
   - The manager's own uncaught `RuntimeException` for a corrupted current-owner row is **not** caught here — it is not a request-input condition and must not be disguised as one.

8. **Success redirect — deliberately not back to the transferred Workspace.** A successful transfer redirects to `customer.workspaces.index` with `flash_success`, for both `deactivate` and `convert_to_admin`. This is required because a `deactivate` disposition may cause the acting previous owner to lose all access to that Workspace immediately — redirecting to its own `show` route would risk an immediate second 404/denial for the same actor who just successfully completed the action. `customer.workspaces.index` makes no assumption about the previous owner's post-transfer access.

9. **UI — owner-only, never rendered for Admin or Staff.** Add an ownership-transfer control to the Workspace overview, visible only when the viewer's role is Owner (not `in_array(..., ['Owner', 'Admin'])` as elsewhere — this control is strictly Owner-only, matching the manager's stricter authority rule). Fields: new-owner User UID (free-text, opaque, no directory/search feature — consistent with the existing member-management UID-entry pattern); previous-owner disposition (deactivate vs convert-to-admin); when convert-to-admin, a Business-access-scope choice (All/Selected) and, for Selected, checkboxes sourced from the existing `manageableBusinesses` list (opaque uids only, no numeric IDs rendered anywhere). The UI is convenience only; `WorkspaceManager` remains authoritative regardless of what the form shows or omits.

10. Add a dedicated focused HTTP test file per "Required test coverage" below.

### Request validation

One new narrowly-scoped Form Request, `App\Http\Requests\Customer\Workspace\TransferWorkspaceOwnershipRequest`, encoding the DTO's legal states rather than passing arbitrary strings downstream:

- `new_owner_user_uid`: `required`, `string`, `max:255`.
- `previous_owner_disposition`: `required`, one of `deactivate` / `convert_to_admin`.
- `business_access_scope`: `prohibited` when disposition is `deactivate`; `required`, one of `all` / `selected`, when disposition is `convert_to_admin`.
- `business_uids`: `array` when supplied; `prohibited` when disposition is `deactivate`; `prohibited` when `business_access_scope` is `all`; may be an empty array when `selected`, since `selected` + `[]` is a valid, already-tested DTO/domain state.
- `business_uids.*`: `string`, `max:255`, `distinct`.

No other field is accepted. No unrelated existing Form Request is modified.

### Exact implementation scope

Only these implementation paths were authorized to change, once the separate authorizing state update (PR #53) pinned this contract to the real implementation PR/branch — and only these five actually changed in PR #52:

- `app/Http/Controllers/Customer/Workspace/WorkspaceController.php`
- `app/Http/Requests/Customer/Workspace/TransferWorkspaceOwnershipRequest.php` (new)
- `resources/views/customer/workspaces/show.blade.php`
- `routes/customer.php`
- `tests/Feature/Workspace/WorkspaceOwnershipTransferHttpTest.php` (new)

No change to `WorkspaceManager`, `WorkspaceOwnershipTransferDisposition`, `WorkspaceOwnershipTransferMode`, `WorkspaceBusinessAccessScope`, any repository, model, migration, event, or exception is authorized — all confirmed by inspection to already be sufficient. No modification to the existing `tests/Feature/Workspace/WorkspaceOwnershipTransferTest.php` (domain suite) or to any other prior-slice test merely to make new behavior pass — the domain suite is a required regression suite, not an implementation path. If implementation discovers the existing domain contract is insufficient, stop and report rather than broadening scope.

### Required verification

Every command must discover a positive test count and exit successfully:

- `php artisan test tests/Feature/Workspace/WorkspaceOwnershipTransferHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceOwnershipTransferTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceMutationHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceReactivationHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceOverviewHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceSwitcherHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceBusinessListHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceMemberManagementHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceBusinessCreationHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceBusinessReassignmentHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceBusinessOrchestrationTest.php`

These commands may be executed by an automated deterministic gate, or manually by a human developer in their own local environment against the exact verified head, per the manual completion path in `docs/automation/AI-SUBSCRIPTION-LOOP.md`. No individual test counts are prescribed here; none may be fabricated at completion.

### Required test coverage

**HTTP (`WorkspaceOwnershipTransferHttpTest.php`)** must prove at minimum:

- exact route name, exact URI, POST-only mutation verb;
- guest/customer-authentication boundary;
- the ownership-transfer control is Owner-only — an active Admin and an active Staff viewer both receive no such control in the rendered overview;
- successful transfer with `deactivate`;
- successful transfer with `convert_to_admin` + `all`;
- successful transfer with `convert_to_admin` + `selected` + exact Business uid assignments;
- `convert_to_admin` + `selected` + `[]` is a valid, successful request;
- an unknown incoming-User uid fails closed with 404;
- a crafted POST from an active Admin fails via manager denial (flash-error), not a resolver 404;
- a crafted POST from an active Staff fails via manager denial (flash-error), not a resolver 404;
- an inactive Admin fails closed with 404 at the resolver;
- an unrelated user fails closed with 404 at the resolver;
- a platform-admin-only user fails closed with 404 at the resolver;
- an inactive Workspace's owner is denied via flash-error, no transfer occurs;
- a same-owner call by the actual owner is an authorized success no-op;
- a same-owner call by an unauthorized actor (e.g. Staff) is still denied;
- an unknown or cross-Workspace `selected` Business uid fails closed with 404, no transfer occurs;
- a request with `business_access_scope: all` cannot also carry Business selections (validation failure);
- a `deactivate` request cannot carry `business_access_scope` or Business selections (validation failure);
- a successful transfer changes `owner_user_id` through the manager's own behavior (asserted as an outcome, not re-implemented);
- an existing active incoming membership is retained but inactive afterward;
- with `deactivate`: the previous owner has no independent access path back to the Workspace afterward, unless a separately valid access path exists (documented as a possibility, not assumed away);
- with `convert_to_admin`: the previous owner is an active Admin with the requested scope/assignments afterward;
- a successful transfer redirects to `customer.workspaces.index`, never back to the transferred Workspace's own overview;
- the existing `workspace_transitions`/event outcome is observable as a result, never reimplemented or re-verified via a new controller-level mechanism;
- no ownership-transfer write of any kind occurs on any denied or invalid request.

**Domain regression only** (`WorkspaceOwnershipTransferTest.php`, `WorkspaceBusinessOrchestrationTest.php`, and every other suite in "Required verification") — must continue to pass unmodified; none may be edited to accommodate this slice.

### Explicit exclusions

- No change to `WorkspaceManager`, `WorkspaceOwnershipTransferDisposition`, `WorkspaceOwnershipTransferMode`, `WorkspaceBusinessAccessScope`, any repository, model, migration, event, or exception.
- No modification to `tests/Feature/Workspace/WorkspaceOwnershipTransferTest.php` or any other prior-slice test merely to make new behavior pass.
- No third or default disposition; the caller must always choose explicitly between `deactivate` and `convert_to_admin`.
- No caller-supplied numeric User ID, Business ID, Workspace ID, membership ID, owner ID, tenant ID, or customer ID anywhere in the request.
- No new User repository, no User directory/search feature, no requirement that the incoming User already be a Workspace member, no invented Customer requirement beyond what `transferOwnership()` itself accepts.
- No catching or reinterpreting the manager's invariant `RuntimeException` for a corrupted current-owner row as ordinary request-input error handling.
- No alternate Workspace or Business authorization algorithm anywhere, including in Blade/controller UI filtering.
- No automatic merge, force-push, push to `main`, tag, metered model API, or usage-credit enablement.
- No Codex review requirement for completion, and no automatic Claude Routine handoff requirement.
- No automatic next-Milestone or next-Slice start of any kind.
- No implementation of any kind, and no implementation PR or branch, before this contract itself is human-reviewed, merged, and paired with a separate authorizing `AI-AUTONOMY-STATE.json` update.
- No implementation beyond the exact paths and behavior above.

### Completion condition

Slice 4F is ready for human review when the exact-scope implementation is at the pinned PR head and every required test command has passed with a positive recognized count against that exact head — verified either by an automated deterministic gate or by a human developer running the required commands manually and recording the result, per the manual completion path. Codex review and an automatic Claude Routine handoff are not required. Final product merge remains exclusively human-approved.

**Closed.** This condition was met: PR #52 reached final product head `99f3e218094f40a283bab3ded6a097734b68787b` (implementation commit `eec1f1650bf35314d48d68f3bffd9c3da28382eb`, followed by a main-merge-sync commit that neutralized the target marker from the diff, per the Slice 4E precedent). All eleven required test commands were reported by the human developer as passing manually; individual test counts were not recorded and are not fabricated here. `git diff --check` was clean, and a human merged PR #52 into `main` as `db7829d2c33ff3eb77a5bd42820eb39e349f6d94`. See `docs/automation/RFC-003-M4-CLOSURE.md` ("Slice 4F final evidence") for full closure evidence. No further implementation, correction, or product work is authorized under this document.

### Milestone boundary

Slice 4F was the final remaining RFC-003 Milestone 4 customer mutation surface identified by the completed Slice 4E closure. Completion of Slice 4F product work completed the enumerated RFC-003 §23 Milestone 4 mutation-surface list — but, as required, this contract did not by itself close Milestone 4 or start any next Milestone. Milestone 4 closure was recorded separately, as its own independent, human-reviewed governance action, in `docs/automation/RFC-003-M4-CLOSURE.md`, after Slice 4F product work was completed and closed through its own six-step authorization sequence above. That closure document also does not select, authorize, or start RFC-003 Milestone 5.
