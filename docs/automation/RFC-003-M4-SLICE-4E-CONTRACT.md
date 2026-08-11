# RFC-003 Milestone 4 Slice 4E Contract

**Status: PROPOSED — NOT AUTHORIZED. Implementation must not begin under this document alone.**

## Implementation contract

### Purpose

Implement the sixth bounded RFC-003 Milestone 4 customer mutation surface: reassigning an existing Business from its current Workspace to a different Workspace, through the existing `WorkspaceManager::reassignBusiness()` domain method — **together with a narrowly bounded correction to that method**, found by inspection while drafting this contract, required so the HTTP surface does not expose a real RFC-003 §7.5/§14.1 authorization gap.

RFC-003 §23's Milestone 4 bullet has two remaining unimplemented HTTP surfaces after Slice 4D: Business reassignment (§16.2) and Workspace ownership transfer (§15). `transferOwnership()` locks two `users` rows and up to two membership rows, is owner-only (no active-Admin path), and requires the caller to resolve an explicit previous-owner disposition (`WorkspaceOwnershipTransferDisposition`). Reassignment remains the smaller of the two and is authorized here; ownership transfer — including any `WorkspaceOwnershipTransferDisposition` HTTP construction, incoming-owner selection, previous-owner deactivate/convert-to-admin UI, or ownership-transfer Business-access selection — is explicitly out of scope and reserved for a later, independently reviewed Slice 4F contract.

### Domain gap found by inspection

RFC-003 §7.5: role determines management authority; `business_access_scope` determines Business visibility/access, independent of role. §14/§14.1: effective Business access is the canonical `WorkspaceManager::userCanAccessBusiness()`/`assertUserCanAccessBusiness()` algorithm (direct ownership, Workspace owner, or active membership whose scope covers the Business).

`WorkspaceManager::reassignBusiness()` currently checks only `assertActorIsOwnerOrActiveAdmin()` against the source and target Workspaces — Workspace **management** authority. It never calls `userCanAccessBusiness()`/`assertUserCanAccessBusiness()` against the source Business. The existing active-Admin reassignment tests (`test_active_admin_of_both_workspaces_may_reassign` and others) all use `createMembership()`'s default `business_access_scope = All`, so no existing test exercises — or would catch — a selected-scope Admin reassigning a Business never assigned to them. This is a real, previously-unexercised gap: today, any active Admin of a Workspace can reassign *any* Business in it, including Businesses entirely outside their own effective access, purely because `business_access_scope` is never consulted.

RFC-003 requires **both** Workspace management authority and Business access under `business_access_scope` — it does not require the new Business-access check to take precedence over the already-shipped authority/inactive-state exception contract. The correction below closes the gap while preserving every existing exception type for every already-shipped denial scenario.

### Manager correction (in scope, narrowly bounded)

Add exactly one new call to `WorkspaceManager::reassignBusiness()`: `$this->assertUserCanAccessBusiness($actorUserId, $lockedBusiness);` — reusing the existing, unmodified canonical method. No new access algorithm is introduced.

**Exact placement**, preserving the existing domain ordering as closely as possible:

1. Source/target Workspaces locked in ascending-ID order — exactly as today.
2. Business locked — exactly as today.
3. Authoritative source re-verified against the caller-supplied model (`WorkspaceBusinessNotFoundException`/`BusinessWorkspaceMismatchException`) — exactly as today.
4. `assertActorIsOwnerOrActiveAdmin()` over the source Workspace — exactly as today.
5. `assertActorIsOwnerOrActiveAdmin()` over the target Workspace — exactly as today.
6. Inactive-source and inactive-target checks (`InactiveWorkspaceMutationException`) — exactly as today.
7. **New:** `assertUserCanAccessBusiness($actorUserId, $lockedBusiness)` — `WorkspaceAccessDeniedException` if the actor cannot access the authoritative (locked, now-confirmed-active) Business.
8. Same-target no-op — exactly as today, now reached only after authority, active-state, **and** Business-access have all passed.
9. Existing grant cleanup / reassignment / transition / event dispatch — exactly as today, unchanged.

**This ordering preserves every existing exception type for every already-shipped denial scenario**, verified by re-tracing each existing `WorkspaceBusinessOrchestrationTest.php` case against the new step order:

- `test_inactive_source_workspace_is_rejected` (owner, source inactive): still fails at step 6 (`InactiveWorkspaceMutationException`) — the owner's business access is never evaluated because step 6 runs first, exactly as today.
- `test_staff_inactive_admin_and_unrelated_user_cannot_reassign` (Staff / inactive Admin / unrelated, all against the source): all three still fail at step 4 (`UnauthorizedWorkspaceManagementException`) before step 7 is ever reached, exactly as today.
- `test_authority_over_source_only_is_rejected` and `test_authority_over_target_only_is_rejected`: both still fail at step 4 or 5 respectively (`UnauthorizedWorkspaceManagementException`) before step 7, exactly as today.
- `test_unauthorized_same_target_call_still_throws` (unrelated actor): still fails at step 4 (`UnauthorizedWorkspaceManagementException`) before step 7 or step 8, exactly as today.

**None of these four existing tests requires any change.** They remain valid regressions and must not be rewritten. Step 7 only becomes observable — and only produces `WorkspaceAccessDeniedException` — for an actor who has *already* passed both Workspace-authority checks and both active-state checks, i.e. exactly the previously-unexercised selected-scope-Admin gap this correction closes.

### Locked target

- Implementation PR: not yet opened
- Base: `main`
- Head: not yet created (expected name, following the existing per-slice convention: `agent/rfc-003-m4-slice-4e`)
- Starting SHA: not yet pinned
- Merge policy: human only
- Maximum bounded correction rounds: 2

**This contract is a proposal, not a lease.** The manual authorization sequence is exactly: (1) this Slice 4E contract is human-reviewed and merged; (2) only after that merge may a dedicated Slice 4E implementation branch be created from then-current `main` and an inert Draft baseline implementation PR be opened — solely to establish the exact target PR number, branch, and starting SHA identity; (3) at that stage no Slice 4E product/application implementation may be written yet; (4) the exact implementation PR number, head branch, and full starting SHA are then recorded in a separate, human-reviewed `docs/automation/AI-AUTONOMY-STATE.json` update that sets `implementation_authorized: true`; (5) a human reviews and merges that state update; (6) only after that authorizing state update is merged may Slice 4E product implementation begin. `start_automatically_after_contract_merge` remains `false` throughout — no automatic starter is involved at any step. Product merge remains exclusively human-only. No paid model API or usage-credit enablement is authorized at any step; no Codex review or automatic Claude Routine handoff is required for completion, per the manual completion path in `docs/automation/AI-SUBSCRIPTION-LOOP.md`.

### Authorized behavior

1. **Route and source resolution.** Add `POST workspaces/{workspaceUid}/businesses/{businessUid}/reassign`, route name `customer.workspaces.businesses.reassign` — nested under the existing `businesses.*`/`members.*` sub-resource convention. Resolve the source Workspace via the existing `resolveAccessibleWorkspace()` pattern, unchanged.

2. **Business resolution — existing repository method, addressability only, not a second authorization algorithm.** Resolve the Business by opaque `uid` via the existing `WorkspaceRepository::businessesForWorkspace($sourceWorkspace)` (already used by `accessibleBusinesses()`), selecting the row whose `uid` matches; 404 if absent. This requires no repository contract change — the method already exists. This step performs **no** membership/scope filtering of its own; it is a plain Workspace-scoped existence lookup, mirroring `resolveAccessibleMembership()`'s addressability pattern. `WorkspaceManager::assertUserCanAccessBusiness()` (manager correction, step 7 above) remains the sole authoritative access decision; its `WorkspaceAccessDeniedException` is caught and mapped to the same `abort(404)`, so a Business that exists in the source Workspace but is outside the actor's access is indistinguishable from an unknown uid — no Business-existence oracle.

3. **Target Workspace resolution.** The caller submits an opaque `target_workspace_uid`, resolved via the same `resolveAccessibleWorkspace()` pattern, applied a second time. `business_access_scope` on the *target* membership plays no role in this resolution — the Business does not belong to the target yet. `WorkspaceManager` remains exclusively authoritative for owner-or-active-Admin authority over the target.

4. **`WorkspaceManager::reassignBusiness()` correction — see "Manager correction" above.** `assertUserCanAccessBusiness()` is called against the authoritative, locked Business at the exact position specified there (after authority and active-state, before the same-target no-op). No new access algorithm; the existing method is reused unmodified; existing exception precedence for every already-shipped scenario is preserved.

5. **Delegate entirely to `WorkspaceManager::reassignBusiness($actorUserId, $business, $targetWorkspace)`** with the corrected internal ordering above. No part of the manager's locking, re-verification, authority, active-state, access, cleanup, audit, or event behavior is reimplemented, duplicated, or second-guessed in the controller.

6. **Manager exception → HTTP response policy:**
   - `UnauthorizedWorkspaceManagementException` → `redirect()->back()->with('flash_error', ...)` — existing Workspace-mutation pattern, unchanged, matching `rename()`/`deactivate()`/`reactivate()`/`storeBusiness()`.
   - `InactiveWorkspaceMutationException` → `redirect()->back()->with('flash_error', ...)` — existing Workspace-mutation pattern, unchanged.
   - `WorkspaceAccessDeniedException` → `abort(404)` — the actor cannot access the source Business; identical response to "Business not found," no oracle.
   - `WorkspaceNotFoundException`, `WorkspaceBusinessNotFoundException`, `BusinessWorkspaceMismatchException` → `abort(404)`. Inspection of `app/Exceptions/Handler.php` found no project-wide mapping for these `RuntimeException` subclasses to any HTTP status; left uncaught they would surface as a raw 500, which is not a safe deterministic response for a resource that is "no longer valid/available." 404 is the anti-oracle-safe, existing-precedent-consistent response for every other "the addressed resource is gone" case in this controller.
   - Same-target call → not an exception; ordinary success (redirect + flash-success), matching `deactivateWorkspace()`'s/`reactivateWorkspace()`'s existing no-op treatment — now correctly reachable only after authority, active-state, **and** Business-access have all passed.
   - Unknown/inaccessible source Workspace, target Workspace, or Business uid → existing `abort(404)` patterns from items 1–3, unchanged.

7. **Request validation.** Add one narrowly-scoped new Form Request, `App\Http\Requests\Customer\Workspace\ReassignWorkspaceBusinessRequest`, validating `target_workspace_uid` as `required|string` — matching `StoreWorkspaceMemberRequest`'s existing `user_uid` shape. No unrelated existing Form Request is modified.

8. **UI: source Business list is the canonical effective-access list.** The source Business selector must use the existing `accessibleBusinesses()`/`effectiveBusinesses()` machinery (already wrapping `userCanAccessBusiness()` per Business, RFC-003 §14.1) — never the raw, unfiltered `businessesForWorkspace()`. The Owner naturally sees every Business (unconditional access); an all-scope Admin sees every Business; a selected-scope Admin sees only Businesses assigned to them — no raw unfiltered Business list is ever rendered to a selected-scope Admin. Visible only when the viewer's role is Owner or Admin. A crafted POST naming an existing source-Workspace Business outside a selected-scope Admin's access must still reach the manager's step-7 check and return the same non-oracular 404 as an unknown Business — the UI list is a convenience, not the authorization boundary. Target Workspace list: `allForUser()` filtered in the controller to rows where the existing `effectiveRoleKey()` resolves to `'owner'` or `'admin'` — reused, not a new algorithm, and explicitly a UI convenience only; a stale or hand-crafted `target_workspace_uid` outside this list is still independently and correctly enforced at submission time by `resolveAccessibleWorkspace()` and the manager.

9. **Preserve** Slice 4A, 4B, 4C, 4D behavior and all Milestone 3 read-only surfaces exactly. A reassigned Business must be reflected in both the (former) source and the target Workspace's existing effective-access Business list (`effectiveBusinesses()`) afterward, without any change to that method's own filtering algorithm.

10. Add a dedicated focused HTTP test file and the required domain-test additions per "Required test coverage" below.

### Exact implementation scope

Only these implementation paths may change once a separate authorizing state update pins this contract to a real implementation PR/branch:

- `app/Http/Controllers/Customer/Workspace/WorkspaceController.php`
- `app/Http/Requests/Customer/Workspace/ReassignWorkspaceBusinessRequest.php` (new)
- `app/Library/Workspace/WorkspaceManager.php` — exactly one new call inside `reassignBusiness()`, at the position specified above; no other method touched
- `resources/views/customer/workspaces/show.blade.php`
- `routes/customer.php`
- `tests/Feature/Workspace/WorkspaceBusinessOrchestrationTest.php` — new selected-scope tests only; existing tests are unchanged regressions
- `tests/Feature/Workspace/WorkspaceBusinessReassignmentHttpTest.php` (new)

No repository implementation or contract change (`businessesForWorkspace()` already exists), no model, enum, event, exception, migration, or DTO change is authorized — `WorkspaceAccessDeniedException`, `userCanAccessBusiness()`, and `assertUserCanAccessBusiness()` already exist and are already sufficient. If implementation discovers this is insufficient, stop and report rather than broadening scope.

### Required verification

Every command must discover a positive test count and exit successfully:

- `php artisan test tests/Feature/Workspace/WorkspaceBusinessReassignmentHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceMutationHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceReactivationHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceOverviewHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceSwitcherHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceBusinessListHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceMemberManagementHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceBusinessCreationHttpTest.php`
- `php artisan test tests/Feature/Workspace/WorkspaceBusinessOrchestrationTest.php`

These commands may be executed by an automated deterministic gate, or manually by a human developer in their own local environment against the exact verified head, per the manual completion path in `docs/automation/AI-SUBSCRIPTION-LOOP.md`. No individual test counts are prescribed here; none may be fabricated at completion.

### Required test coverage

**Domain (`WorkspaceBusinessOrchestrationTest.php`) — existing tests are unchanged regressions, not rewritten:**

`test_inactive_source_workspace_is_rejected`, `test_staff_inactive_admin_and_unrelated_user_cannot_reassign`, `test_authority_over_source_only_is_rejected`, `test_authority_over_target_only_is_rejected`, and `test_unauthorized_same_target_call_still_throws` all continue to expect exactly the exception types they expect today (traced and confirmed above). They must pass unmodified.

**Domain — new tests:**

- All-scope active Admin with authority over both Workspaces → succeeds (regression confirmation that the existing `test_active_admin_of_both_workspaces_may_reassign` behavior is unaffected).
- Selected-scope active Admin, source Business explicitly assigned to their membership, authority over both Workspaces → succeeds.
- Selected-scope active Admin, source Business **not** assigned to their membership, authority over both Workspaces → `WorkspaceAccessDeniedException` (reached only because authority and active-state already passed — this isolates the previously-unexercised gap cleanly).
- A direct Business owner (`Business.customer_id`) who lacks Workspace management authority over the source and/or target → still `UnauthorizedWorkspaceManagementException` under the preserved ordering (the authority check runs before Business-access is ever evaluated, so direct ownership cannot substitute for management authority).
- Same-target call by a selected-scope Admin whose membership *is* assigned the Business → authorized no-op, no exception.
- Same-target call by a selected-scope Admin whose membership is **not** assigned the Business → `WorkspaceAccessDeniedException`, proving the no-op is reached only after Business-access also passes.

**HTTP (`WorkspaceBusinessReassignmentHttpTest.php`):**

- route name, exact URI, POST-only mutation verb;
- guest/customer-authentication boundary;
- source Workspace, Business, and target Workspace are all addressed exclusively by opaque uid;
- successful reassignment by the owner of both Workspaces;
- successful reassignment by an all-scope active Admin of both Workspaces;
- successful reassignment by a selected-scope active Admin whose membership is explicitly assigned the source Business;
- a selected-scope active Admin whose membership is *not* assigned the source Business is denied via a crafted POST naming that Business's real uid directly (not merely absent from the UI list) — 404, proving the manager, not the UI, is authoritative;
- the selected-scope Admin's rendered overview only lists Businesses they can access (UI reflects `effectiveBusinesses()`/`accessibleBusinesses()`, never the raw unfiltered Workspace roster);
- authority over the source Workspace only is denied (flash-error);
- authority over the target Workspace only is denied (flash-error);
- Staff is denied (flash-error);
- an inactive Admin is denied (flash-error);
- an unrelated user is denied (flash-error);
- platform-admin status alone grants no authority (flash-error — `is_admin` is not evaluated by `assertActorIsOwnerOrActiveAdmin()`, so a platform-admin-only actor still fails the existing authority check, exactly as every other Workspace-management action already behaves);
- an inactive source Workspace is denied (flash-error);
- an inactive target Workspace is denied (flash-error);
- an unknown or inaccessible source Workspace uid fails closed with 404;
- an unknown or inaccessible target Workspace uid fails closed with 404;
- an unknown Business uid fails closed with 404;
- a Business uid that exists but does not belong to the claimed source Workspace fails closed with 404, identical in shape to "unknown";
- a successful reassignment changes only `workspace_id` — `customer_id` and other Business fields unchanged;
- a same-target reassignment by an authorized actor is a no-op (redirect/flash-success, no observable state change);
- stale source-Workspace scoped grants for the reassigned Business are gone afterward (asserted as an outcome, not re-implemented);
- the reassigned Business is reflected in both Workspaces' existing effective-access Business list afterward;
- existing Slice 4A, 4B, 4C, 4D, and Milestone 3 behavior does not regress.

### Explicit exclusions

- **No Workspace ownership transfer HTTP or UI surface of any kind** — no `WorkspaceOwnershipTransferDisposition` construction from HTTP input, no incoming-owner selection, no previous-owner deactivate/convert-to-admin control, no ownership-transfer Business-access-scope selection. Reserved for an independent, later Slice 4F contract.
- No Business creation change (Slice 4D is closed) and no member-management change (Slice 4B is closed) beyond this slice's one new manager call.
- No Workspace create/rename/deactivate/reactivate behavior change (Slices 4A and 4C are closed).
- No repository implementation or contract change, no model, enum, event, migration, or DTO change; no new `WorkspaceManager` exception type — `WorkspaceAccessDeniedException` already exists.
- No new generic service layer or alternate authorization/access algorithm anywhere, including in Blade/controller UI filtering.
- No controller-side manual removal of scoped membership-Business grants, no controller-side `workspace_transitions` writes, no controller-side direct update of `Business.workspace_id`, and no controller-side reimplementation of `userCanAccessBusiness()`.
- No rewriting of existing `WorkspaceBusinessOrchestrationTest.php` authorization/inactive-state tests — they remain valid regressions under the preserved ordering.
- No automatic merge, force-push, push to `main`, tag, metered model API, or usage-credit enablement.
- No Codex review requirement for completion, and no automatic Claude Routine handoff requirement.
- No implementation of any kind, and no implementation PR or branch, before this contract itself is human-reviewed, merged, and paired with a separate authorizing `AI-AUTONOMY-STATE.json` update.
- No implementation beyond the exact paths and behavior above.

### Completion condition

Slice 4E is ready for human review when the exact-scope implementation is at the pinned PR head and every required test command has passed with a positive recognized count against that exact head — verified either by an automated deterministic gate or by a human developer running the required commands manually and recording the result, per the manual completion path. Codex review and an automatic Claude Routine handoff are not required. Final product merge remains exclusively human-approved.

**Implementation is not authorized under this document alone.** This contract must first be human-reviewed and merged; a separate `AI-AUTONOMY-STATE.json` update must then explicitly authorize and pin an implementation PR/branch/SHA before any code under "Exact implementation scope" may be written.
