# RFC-003 Milestone 5 Contract

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

After this exact contract PR is human-reviewed and merged, manual Milestone 5 implementation is authorized directly under this document, without another authorization PR, target marker, or inert baseline PR — see "Manual authorization model" below.

## Implementation contract

### Purpose

Add a backend administrator-only, cross-tenant Workspace **inspection** surface: a platform administrator may list Workspaces across all tenants, filter/search that list, open a Workspace detail page, and inspect Workspace identity, active/inactive state, owner, Businesses, memberships (role, `business_access_scope`, active/inactive state, selected-Business assignments where applicable). This is the sole capability RFC-003 §23 authorizes for Milestone 5.

### RFC source of truth

RFC-003 §23 titles Milestone 5 "Platform-administrator inspection and controls," but its actual implementation bullet is narrower and is what this contract treats as authoritative:

> Admin Workspace listing/inspection, following the existing `EnsureUserIsAdministrator` boundary pattern (§6 finding 5). No new admin mechanism.

The milestone heading's word "controls" is not read as authorizing mutation — the implementation bullet is the binding scope statement, exactly as it has been for every prior RFC-003 milestone in this repository. **Milestone 5 is therefore read-only Workspace administration.** No admin Workspace create, rename, deactivate/reactivate, member add/edit/deactivate/reactivate, Business-access-scope change, Business create/reassign, ownership transfer, or delete of any kind is authorized by this contract. Those are customer-side RFC-003 Milestone 4 mutation surfaces (already closed — `docs/automation/RFC-003-M4-CLOSURE.md`) and are not reintroduced here in an admin form.

### Verified repository state

Every file this contract depends on was inspected before drafting, and repository reality matches every assumption below — no conflict was found, so nothing here is redesigned from what was requested:

- `app/Http/Middleware/EnsureUserIsAdministrator.php` — the explicit, independent `users.is_admin` boundary layered on top of the admin route group's blanket `can:access backend` gate (RFC-001 §16/§19), applied today to the Business and Opportunity admin modules in `routes/admin.php`. Unmodified by this contract.
- `RouteServiceProvider` wraps the entire `routes/admin.php` file in `['web', 'auth', 'can:access backend', 'ValidProduct', 'twofactor']` — this is Layer 1, already present for every admin route including the new ones.
- `app/Http/Controllers/Admin/BusinessController.php` and `app/Http/Requests/Business/AdminBusinessIndexRequest.php` — the exact `index()`/`show()` + Form Request pattern this contract's `WorkspaceController`/`AdminWorkspaceIndexRequest` mirror.
- `app/Repositories/Contracts/BusinessRepository.php` / `EloquentBusinessRepository::paginateForAdmin()` (and the equivalent `OpportunityRepository::paginateForAdmin()`) — the exact precedent `WorkspaceRepository::paginateForAdmin()` mirrors: `Arr::only($filters, [...])`, `max(1, min($perPage, MAX))` clamping, conditional `filled()`-guarded `where`s, deterministic `orderBy('id', 'desc')->paginate($perPage)`.
- `resources/views/admin/businesses/index.blade.php` / `show.blade.php` — the filter-form/table/pagination pattern the new Workspace admin views mirror.
- `tests/Feature/Business/AdminBusinessControllerTest.php` — including `test_a_non_admin_account_is_blocked_even_with_business_permissions_in_session()`, the exact defense-in-depth regression pattern required below.
- `app/Models/Workspace.php` already has `owner()`, `memberships()`, `businesses()`. `app/Models/WorkspaceMembership.php` already has `user()`, `businessAssignments()`, and — critically — `assignedBusinesses(): BelongsToMany`. `app/Models/WorkspaceMembershipBusiness.php` and `app/Models/Business.php::customer()` are unchanged. **No Workspace-side model relationship is missing; no model change of any kind is required or authorized.**
- `app/Repositories/Contracts/WorkspaceRepository.php` / `EloquentWorkspaceRepository.php` — existing methods (`findByUid()`, `findOwnedBy()`, `allForUser()`, `businessesForWorkspace()`, `transferOwnership()`, etc.) inspected; none of their semantics are touched by adding one new method.
- `config/permissions.php` — flat associative array of `'permission string' => ['display_name' => ..., 'category' => ...]`, e.g. the existing `'view business' => ['display_name' => 'read', 'category' => 'Business']`.
- `app/Providers/AuthServiceProvider::boot()` — `foreach (config('permissions') as $key => $permissions) { Gate::define($key, fn (User $user) => $accountRepository->hasPermission($user, $key)); }`. Every `config('permissions')` entry is **already** automatically registered as a Gate ability. Adding one new config entry requires **zero** change to this file.

### Admin security boundary (three layers, all reused, none redesigned)

1. **Blanket admin-route boundary.** The existing `RouteServiceProvider` middleware group (`web`, `auth`, `can:access backend`, `ValidProduct`, `twofactor`) already wraps all of `routes/admin.php`, including the two new routes. Nothing added here.
2. **`EnsureUserIsAdministrator` middleware**, applied to every M5 Workspace admin route, exactly as it is already applied to the Business and Opportunity admin route groups. This is an independent `users.is_admin` check — administrator *identity* is, and remains, `users.is_admin` plus this middleware. Nothing about M5 changes how an administrator account is recognized.
3. **One new feature-level Gate permission**, `view workspace`, checked via `$this->authorize('view workspace')` in both controller actions — the same third layer `BusinessController` already uses for `'view business'`/`'edit business'`.

**This is not a new administrator mechanism.** Adding `'view workspace'` to `config/permissions.php` adds one more string to an array that `AuthServiceProvider::boot()` already iterates generically; the Gate closure it produces still resolves through the same, unmodified `AccountRepository::hasPermission($user, $key)` used by every other permission in the system. No new identity check, no new middleware, no new authorization algorithm is introduced — only one more entry in an existing, already-generic table of feature-level read/write flags layered on top of the two boundaries above, which remain the actual gatekeepers of whether the account is an administrator at all.

Add exactly one permission to `config/permissions.php`:

```php
'view workspace' => [
    'display_name' => 'read',
    'category' => 'Workspace',
],
```

Do not add `edit workspace`, `create workspace`, `delete workspace`, `manage workspace`, or any other Workspace permission. M5 is read-only; there is no mutation action for a write-level permission to gate.

### Non-admin defense (must be directly tested)

A non-admin account must remain denied even if its session somehow carries `access backend` and `view workspace` — proving `EnsureUserIsAdministrator`'s `users.is_admin` check is independent of, and does not rely on, permission-string content. This mirrors `AdminBusinessControllerTest::test_a_non_admin_account_is_blocked_even_with_business_permissions_in_session()` exactly and must be present for the new Workspace admin routes.

### Platform-admin access semantics (intentionally cross-tenant)

Platform-administrator Workspace inspection is deliberately independent of every customer-side Workspace authorization primitive (RFC-003 §6 finding 5, §14 — platform-administrator access is upstream of and independent from Workspace-derived authorization). Do **not** apply, call, or reimplement any of:

- Workspace owner checks
- Workspace membership checks
- `business_access_scope` filtering
- `WorkspaceManager`'s customer-facing authorization
- the customer controller's `resolveAccessibleWorkspace()`
- `WorkspaceManager::userCanAccessBusiness()`

An authorized administrator may inspect active Workspaces, inactive Workspaces, Workspaces they do not own, Workspaces where they hold no membership, and Businesses they do not directly own. No tenant relationship to the inspected Workspace is required or checked. `WorkspaceController` (admin) therefore has no dependency on `WorkspaceManager` — M5 does not mutate, so none of `WorkspaceManager`'s authority/locking logic is relevant here.

### Authorized behavior

**Routes.** Add exactly two `GET` routes to `routes/admin.php`, inside the `EnsureUserIsAdministrator::class` middleware group, alongside the existing Business/Opportunity admin groups:

- `GET workspaces` — name `admin.workspaces.index`
- `GET workspaces/{workspace}` — name `admin.workspaces.show`

`{workspace}` route-model-binds through `Workspace`'s existing `HasUid`/`getRouteKeyName()` behavior, so the admin URL addresses a Workspace by its opaque `uid`, never its numeric id — identical in spirit to every customer-side Workspace route. No `POST`, `PUT`, `PATCH`, `DELETE`, action, mutation, export, impersonation, or batch route of any kind.

**Controller.** Create `app/Http/Controllers/Admin/WorkspaceController.php`, extending `AdminBaseController`, with exactly two actions:

- `index(): View` — calls `$this->authorize('view workspace')`, resolves `AdminWorkspaceIndexRequest` from the container (mirroring `BusinessController::index()`'s zero-argument-for-signature-compatibility pattern), calls `WorkspaceRepository::paginateForAdmin($request->filters(), $request->perPage())`, and returns `admin.workspaces.index`.
- `show(Workspace $workspace): View` — calls `$this->authorize('view workspace')`, eager-loads only the existing relationships the view needs (`owner`, `businesses.customer.user`, `memberships.user`, `memberships.assignedBusinesses`), and returns `admin.workspaces.show`.

No `WorkspaceManager` dependency. No direct persistence write of any kind.

**Index Form Request.** Create `app/Http/Requests/Workspace/AdminWorkspaceIndexRequest.php`, following `AdminBusinessIndexRequest`'s exact convention:

- Allowed query input: `search`, `is_active`, `per_page`, `page` — nothing else reaches the repository.
- Rules: `search` → `nullable|string|max:255`; `is_active` → `nullable|boolean`; `per_page` → `nullable|integer|min:1|max:100`; `page` → `nullable|integer|min:1`.
- `prepareForValidation()` normalizes a blank/whitespace-only `search` to `null`, exactly as `AdminBusinessIndexRequest` does.
- `filters(): array` returns only `search` and `is_active`.
- `perPage(): int` defaults to 25, matching `AdminBusinessIndexRequest::perPage()`'s default/clamp pattern.

**Repository.** Add exactly one new method to `WorkspaceRepository` (contract) and `EloquentWorkspaceRepository` (implementation):

```php
public function paginateForAdmin(array $filters, int $perPage): LengthAwarePaginator;
```

Document on the interface that this is the deliberate backend-admin cross-tenant listing exception and must never be called from a customer controller — mirroring `BusinessRepository::paginateForAdmin()`'s own documented exception status. Implementation must, mirroring `EloquentBusinessRepository::paginateForAdmin()`:

- Accept only `search` and `is_active` from `$filters` (`Arr::only()`); ignore any other key.
- Clamp `perPage` to `1..100` at the repository level (`max(1, min($perPage, 100))`) even though the Form Request already validates the same bound — defense in depth against any future non-HTTP caller.
- Eager-load the Workspace `owner` relation.
- Expose deterministic counts useful for the index: `businesses_count` (all Businesses in the Workspace, regardless of the Workspace's own active state) and `active_memberships_count` (`workspace_memberships.is_active = true` only — an inactive membership must never be counted as active access).
- `search`, when supplied, matches Workspace `name` or `uid` at minimum; if the existing query conventions make an owner-column search straightforward without fragile raw SQL, also match owner `first_name`, `last_name`, and `email` — otherwise name/uid-only search is sufficient and no fragile join-based search is required.
- Filter `is_active` only when the caller supplies it (`filled()`-guarded, matching the existing pattern) — omitting it returns both active and inactive Workspaces.
- Order deterministically by `workspaces.id DESC`.
- Return a `LengthAwarePaginator` via `->paginate($perPage)`.
- Add no denormalized column, no migration, no schema change of any kind.
- Do not change the semantics of `findByUid()`, `findOwnedBy()`, `allForUser()`, `businessesForWorkspace()`, `transferOwnership()`, or any other existing method.

**Index view.** Create `resources/views/admin/workspaces/index.blade.php`, pattern-matching `admin/businesses/index.blade.php`: a `GET` filter form (`search`; an "Active status" select — All/Active/Inactive, backed by `is_active`), and a table showing Workspace name, owner, active/inactive, Business count, active member count, and a "View" link to `admin.workspaces.show`. No edit/action controls of any kind; no mutation form.

**Show view.** Create `resources/views/admin/workspaces/show.blade.php`, inspection only:

- Workspace summary: name, uid, active/inactive, owner display name, owner email where available, `created_at`, `updated_at`.
- Businesses section: every Business belonging to the Workspace regardless of the Workspace's own active state — at minimum name, uid, direct Business owner, status if available on the existing `Business` model, and an optional link to `admin.businesses.show` gated behind `@can('view business')` (never assumed granted). No Business data is duplicated into a new representation or mutated.
- Memberships section: **all** membership rows, active and inactive — member display name, member email, role, `business_access_scope`, active/inactive state, and assigned Businesses when `business_access_scope = selected`. `all` scope displays "All Businesses"; `selected` scope with zero assignments displays an explicit empty state ("No Businesses assigned"), never a blank table row. An inactive membership is never rendered or treated as active access. The Workspace owner is displayed separately from the memberships table and is never fabricated as a membership row (an owner has no `workspace_memberships` row by RFC-003 §7.2/§7.3 design).

**Detail data loading.** Use existing Eloquent relationships and the existing `Workspace` route-model-binding convention (`HasUid`/`getRouteKeyName()`) exactly as the admin Business detail page already does — no new repository method created merely to support view rendering; `paginateForAdmin()` is the only new data-access method this contract authorizes.

### Exact implementation scope

After this contract is human-merged, Milestone 5 implementation may change exactly these ten paths — no more, no fewer:

1. `app/Http/Controllers/Admin/WorkspaceController.php` (new)
2. `app/Http/Requests/Workspace/AdminWorkspaceIndexRequest.php` (new)
3. `app/Repositories/Contracts/WorkspaceRepository.php`
4. `app/Repositories/Eloquent/EloquentWorkspaceRepository.php`
5. `config/permissions.php`
6. `resources/views/admin/workspaces/index.blade.php` (new)
7. `resources/views/admin/workspaces/show.blade.php` (new)
8. `routes/admin.php`
9. `tests/Feature/Workspace/AdminWorkspaceControllerTest.php` (new)
10. `tests/Feature/Workspace/WorkspaceRepositoryTest.php`

### Explicitly forbidden implementation paths

Do not modify: `app/Http/Middleware/EnsureUserIsAdministrator.php`; `app/Providers/AuthServiceProvider.php`; `app/Models/Workspace.php`; `app/Models/WorkspaceMembership.php`; `app/Models/WorkspaceMembershipBusiness.php`; `app/Models/Business.php`; `WorkspaceManager`; any membership repository; `BusinessRepository`; `BusinessManager`; any customer-side Workspace controller, route, or view; any migration; any event; any enum; any exception; any DTO; any AI automation script or workflow; `docs/automation/AI-AUTONOMY-STATE.json`; the RFC-003 specification itself.

Milestone 5 contains no schema change and no product billing/plan/entitlement work of any kind.

### Required HTTP test coverage

`tests/Feature/Workspace/AdminWorkspaceControllerTest.php` must prove at minimum:

1. `admin.workspaces.index` route exists.
2. `admin.workspaces.show` route exists.
3. both routes are `GET`-only.
4. the show route addresses a Workspace via its `uid`, not a numeric id.
5. a guest cannot access the index.
6. a guest cannot access show.
7. an ordinary customer cannot access either route.
8. a non-admin account with forged `access backend` + `view workspace` session permissions is still blocked (the defense-in-depth regression, mirroring `AdminBusinessControllerTest`).
9. an admin without `access backend` is blocked.
10. an admin with `access backend` but without `view workspace` is blocked.
11. an authorized admin can view the index.
12. an authorized admin can inspect a Workspace they do not own.
13. an authorized admin can inspect an inactive Workspace.
14. customer-side Workspace membership/business-access scope of the acting admin (if any) is irrelevant to platform-admin inspection — an admin with no membership in, and no ownership of, a Workspace can still inspect it.
15. the index shows the Workspace owner.
16. the index shows active/inactive state.
17. the index shows the Business count.
18. the index shows the active membership count.
19. search by Workspace name works.
20. search by Workspace uid works.
21. `is_active=true` filter works.
22. `is_active=false` filter works.
23. `per_page` validation rejects values above 100.
24. invalid `is_active` input fails validation.
25. show displays the owner separately from the memberships table.
26. show displays the Businesses in the Workspace.
27. show displays both active and inactive memberships.
28. show displays each membership's role.
29. show displays each membership's `business_access_scope`.
30. `selected` scope displays the exact assigned Businesses.
31. `selected` scope with zero assignments displays the explicit empty state.
32. `all` scope displays "All Businesses".
33. substituting the Workspace's numeric database id for its uid does not accidentally expose the record (the uid route-binding boundary holds).
34. no mutation route exists for Workspace admin create/update/delete/deactivate/reactivate/ownership-transfer.
35. rendered Milestone 5 views contain no Workspace mutation form or action control.

Tests must not weaken any existing customer-side access assertion.

### Repository coverage

Extend `tests/Feature/Workspace/WorkspaceRepositoryTest.php` to cover `paginateForAdmin()`:

- returns Workspaces across unrelated tenants;
- includes both active and inactive Workspaces;
- eager/correct owner data;
- `businesses_count` is correct;
- `active_memberships_count` excludes inactive memberships;
- search by name;
- search by uid;
- `is_active` filter true;
- `is_active` filter false;
- unknown filter keys are ignored;
- deterministic `id DESC` ordering;
- `perPage` is clamped to `<= 100` at the repository level.

Do not change any prior `WorkspaceRepositoryTest` expectation merely to make new behavior pass.

### Required regression commands

The implementation must ultimately pass all five:

```
php artisan test tests/Feature/Workspace/AdminWorkspaceControllerTest.php
php artisan test tests/Feature/Workspace/WorkspaceRepositoryTest.php
php artisan test tests/Feature/Business/AdminBusinessControllerTest.php
php artisan test tests/Feature/Workspace/WorkspaceOverviewHttpTest.php
php artisan test tests/Feature/Workspace/WorkspaceBusinessListHttpTest.php
```

Every command must exit successfully and discover a positive test count; a zero/"no tests found" result is a failure. Test counts must never be fabricated. If PHP is unavailable to Claude in the implementation session, the human developer runs these commands locally and reports the actual results — the same manual completion path already used for every Milestone 4 slice (`docs/automation/AI-SUBSCRIPTION-LOOP.md`).

### Manual authorization model (simplified — no target marker, no separate authorization PR)

Milestone 5 begins a simplified manual workflow. There is **no** target-marker PR, **no** inert implementation PR, **no** separate authorization PR, and **no** per-slice `AI-AUTONOMY-STATE.json` churn. The workflow is exactly:

1. this bounded Milestone 5 contract is drafted (this document);
2. a human reviews and merges the contract PR;
3. the merged contract itself authorizes **manual** implementation — no further authorization PR is required;
4. one dedicated implementation branch is created from then-current `main`;
5. the locked Milestone 5 scope above is implemented, exactly;
6. the human developer runs the five required regression commands locally and records the actual results;
7. one implementation PR is opened;
8. a human reviews and merges it;
9. one Milestone 5 closeout document is written.

`docs/automation/AI-AUTONOMY-STATE.json` is untouched by this contract and remains untouched throughout this workflow. Its automation-authorization fields remain exactly as the Milestone 4 closure left them (`implementation_authorized: false`, `advance_automatically: false`, `start_automatically_after_contract_merge: false`) — automatic subscription/start machinery remains disabled throughout Milestone 5. The state file is **not** being used as an M5 manual-implementation lease of any kind; this contract, once merged, is the sole authorization artifact.

**Important distinction:** human merge of this contract authorizes *manual* Milestone 5 implementation. It does not authorize, enable, or imply any automatic starter, automatic merge, or automatic next-Milestone start. `advance_automatically` and `start_automatically_after_contract_merge` remain `false` throughout, exactly as they are today.

### Milestone completion condition

Milestone 5 is complete only when: the two read-only routes exist; the existing admin-account boundary (`EnsureUserIsAdministrator` + the non-admin-forged-permission defense test) is proven; the `view workspace` permission is proven to gate both actions; cross-tenant listing works; Workspace inspection works; owner/Business/member/scope/assignment data is all visible on the show page; no Workspace admin mutation surface of any kind was introduced; the exact ten-path implementation scope was respected; all five required regression commands pass with a positive test count, run and reported by the human developer; and the implementation PR is human-reviewed and merged.

**Milestone 5 completion does not automatically start Milestone 6.** Milestone 6 (RFC-003 §23: "Conformance, deployment guide, final regression and annotated tag") remains a separate, independent, human-reviewed governance step, with its own bounded contract, exactly as Milestone 5's own authorization required a separate contract after Milestone 4's closure.

### Forbidden governance / automation

- No automatic implementation start.
- No automatic merge.
- No force push.
- No direct push to `main`.
- No paid model API or usage-credit requirement.
- No Codex completion requirement.
- No automatic model handoff.
- No tag of any kind — Milestone 6 owns the eventual `rfc-003-workspace-and-business-account-core` annotated tag (RFC-003 §25), not Milestone 5.
- No Milestone 6 implementation.
- No RFC-004 implementation.
- No RFC-005 implementation.
- `advance_automatically` remains `false`.
- `start_automatically_after_contract_merge` remains `false`.

### Contract PR boundary

This contract proposal itself changes exactly one file: `docs/automation/RFC-003-M5-CONTRACT.md`. No implementation file, no state file, and no target marker is created or modified by this PR. No implementation branch is required to exist before this contract PR is merged.

**Implementation is not authorized under this document until it is human-reviewed and merged.** Once merged, manual Milestone 5 implementation may begin directly under this contract, per "Manual authorization model" above — no further authorization PR is required or expected.
