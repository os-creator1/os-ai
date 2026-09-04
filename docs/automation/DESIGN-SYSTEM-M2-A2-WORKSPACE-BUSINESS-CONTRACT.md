# Design System M2 — A2 Workspace / Business — Visual Contract + Security/Behavior Pre-Audit

## 0. Governance

```yaml
roadmap_group: A2
roadmap_group_name: Workspace / Business

classification: KEEP_PLUS_REDESIGN

docs_only: true
implementation_has_occurred: false

retention_gate_required: true
central_control_gate_required: true
component_reuse_gate_required: true
behavior_security_gate_required: true

legacy_subaccounts_in_scope: false
legacy_subaccounts_disposition: delete_later_after_workspace_membership_migration

usage_billing_in_scope: false
additional_business_slot_commercial_ui_in_scope: false
workspace_plan_catalog_in_scope: false

per_workspace_theme_authorized: false
per_business_theme_authorized: false
agency_whitelabel_authorized: false

security_pre_audit_required: true
security_pre_audit_status: complete
security_pre_audit_result: no_blocking_finding

visual_implementation_requires_separate_human_authorization: true

advance_automatically: false
start_a3_automatically: false
start_b1_automatically: false

merge_authority: human_only
no_force_push: true
no_deployment: true

maximum_correction_rounds: 2
correction_round: 0
correction_round_is_final: false

base_sha: 5be68c00ee146c34f2fd9ef8985389309db6c7e8
base_pr: 191
base_pr_title: "Design System M2 A1 — Business Onboarding"
base_merge_parents:
  - caa48f1b975dbaaaec9ce84c87952f4cb077ca9a
  - c3fcff3b84100ae03d600995220b2fae0a823ae3
```

---

## 1. Governance (narrative)

This document is a **docs-only contract and security/behavior pre-audit** for "A2 — Workspace / Business," the second surviving roadmap group named in `docs/automation/PRODUCT-SURFACE-RETENTION-AUDIT.md` §9 (surviving roadmap group 2 of 8). It does not implement any visual change. It does not modify production code, tests, or any other document. It exists to (a) mechanically lock the exact set of retained Workspace/Business Blade views, (b) trace and document their current authorization/behavior model so a future visual redesign cannot accidentally regress it, (c) run a dedicated security/correctness pre-audit, and (d) derive the exact future visual implementation allowlist and test plan.

No visual implementation may begin from this document alone. A separate, explicitly-authorized "A2 Workspace/Business — Visual Implementation" task, created only after this contract is human-merged, is required — mirroring the A1 precedent (`DESIGN-SYSTEM-M2-A1-BUSINESS-ONBOARDING-CONTRACT.md` → `DESIGN-SYSTEM-M2-A1-ONBOARDING-BEHAVIOR-REMEDIATION-CONTRACT.md` → implementation PRs #190/#191).

---

## 2. Exact base SHA

Verified before any file was created:

```
git fetch origin --tags
git rev-parse origin/main
5be68c00ee146c34f2fd9ef8985389309db6c7e8
```

This is the human merge of PR #191, "Design System M2 A1 — Business Onboarding," merge parents `caa48f1b975dbaaaec9ce84c87952f4cb077ca9a` (PR #190 merge) and `c3fcff3b84100ae03d600995220b2fae0a823ae3` (the A1 visual implementation commit) — both confirmed via `git log -1 --format="%H %P"` against `origin/main`. Branch `chore/design-system-m2-a2-workspace-business-contract` was created fresh from this exact commit via `git worktree add -b`.

---

## 3. Retention decision

Per `PRODUCT-SURFACE-RETENTION-AUDIT.md` §7 (row 229) and §9 ("A2. Workspace / Business"):

> Classification: **KEEP + REDESIGN**. Includes the surviving Workspace/Business customer/admin surfaces (`customer/workspace{,s}/**`, `admin/workspaces/**`, `admin/businesses/**`, old Slice 13/16/17 partials) and the Business-profile carve-out from the legacy Billing/Payments/Accounts slice (`customer/business/edit.blade.php`).

This contract independently, mechanically re-verifies that decision against current code (§4) rather than assuming the audit's estimate is still accurate. **One deliberate refinement of the audit's own path-prefix estimate is made and documented in §5**: `admin/workspace-plan-catalog/**`, though swept into the same consolidated table row (229) by path-prefix convenience, is excluded from A2 — this is consistent with, not contradictory to, the audit's own more granular row 104 ("Workspace Plan Catalog & Business admin" — a *separate* bucket from row 103's "Sub-Accounts & Workspaces").

Legacy Sub-Accounts (`customer/SubAccounts/**`) are explicitly **not** part of A2, per audit §12.3 ("RESOLVED... Classification: DELETE LATER, after explicit migration. No Design System work on legacy Sub-Account pages.").

---

## 4. Exact A2 view inventory (mechanically verified)

Mechanical search method: `resources/views/customer/**` and `resources/views/admin/**` globbed for `workspace*`/`business*` paths; every one of the 8 in-scope files grepped for `@include`/`@each`/`<x-`/`@component` (all four returned **zero matches in all 8 files** — none of these views has any app-specific sub-partial); `routes/customer.php` and `routes/admin.php` grepped for every route whose name/URI contains "workspace" or "business"; a repository-wide grep for `Workspace`/`Business`/`$workspace`/`$business` across all of `resources/views/customer/**` and `resources/views/admin/**` to catch anything outside the two obvious folders.

**Exactly 8 Blade view files constitute A2:**

| # | Path | Perspective | Purpose |
|---|---|---|---|
| 1 | `resources/views/customer/workspaces/index.blade.php` | Customer (any member) | List Workspaces the user belongs to; create-Workspace form |
| 2 | `resources/views/customer/workspaces/show.blade.php` | Customer (Owner/Admin see mutation forms; Staff read-only) | Workspace overview, rename/deactivate/reactivate, ownership transfer, Business creation/reassignment, member management — **plus an embedded, out-of-scope Plan & Capacity / Usage & Billing region, see §5** |
| 3 | `resources/views/customer/business/edit.blade.php` | Customer (Business-scoped, direct ownership) | Business profile identity/contact/profile edit form |
| 4 | `resources/views/admin/workspaces/index.blade.php` | Platform admin | Cross-tenant Workspace list/search, read-only |
| 5 | `resources/views/admin/workspaces/show.blade.php` | Platform admin | Cross-tenant Workspace detail (identity, Businesses, Memberships), read-only — **plus an embedded, out-of-scope Plan & Entitlement / Mutate Plan region, see §5** |
| 6 | `resources/views/admin/businesses/index.blade.php` | Platform admin | Cross-tenant Business list/search |
| 7 | `resources/views/admin/businesses/show.blade.php` | Platform admin | Business detail + status-change form; outbound links to Edit and Usage Billing |
| 8 | `resources/views/admin/businesses/edit.blade.php` | Platform admin | Business profile identity/contact/profile edit form (admin, cross-tenant) |

**No partials, fragments, or modals exist for any of these 8 files.** Each is fully self-contained (`@extends('layouts/contentLayoutMaster')` only). The retention audit's own rough "~11-12 files" estimate for this bucket is superseded by this mechanical count of exactly 8 — no separate partial files exist to reconcile the difference; the audit's estimate simply overcounted before file-level verification.

---

## 5. Excluded / deletion / rebuild boundaries

### 5.1 Legacy Sub-Accounts — excluded entirely (DELETE LATER)

`resources/views/customer/SubAccounts/{create,index,show}.blade.php` (3 files). Disposition: **DELETE LATER**, after a separately-contracted migration of `users.parent_id` delegated-access relationships into Workspace membership rows (audit §12.3, RESOLVED). No Design System work of any kind on these files. Grepped `customer/workspaces/show.blade.php` and `customer/business/edit.blade.php` for any reference to Sub-Accounts, `parent_id`, or the `SubAccounts` route/controller namespace — **zero matches**. The two systems do not currently link to or embed one another; the boundary is clean.

### 5.2 Billing / usage / commercial slot UI — excluded entirely

- `resources/views/customer/business/usage-billing/{show.blade.php,partials/payment-method.blade.php}`
- `resources/views/customer/workspace/additional-business-slots/show.blade.php`
- `resources/views/admin/usage-billing/businesses/show.blade.php`
- `resources/views/admin/usage-billing/provider-events/index.blade.php`
- `resources/views/admin/usage-billing/safety-limits/index.blade.php`
- `resources/views/admin/additional-business-slot-agreements/{index,show}.blade.php`
- `resources/views/admin/workspace-plan-catalog/index.blade.php` — **explicit refinement of the retention audit's path-prefix grouping.** Read in full: it renders a read-only plan/pricing tier table (display name, price, currency, billing cycle, slot counts, additional-slot price ratio, active flag, packaged feature keys) with zero Workspace or member data. Route `admin.workspace-plan-catalog.index` → `WorkspacePlanCatalogController@index`, gated by `$this->authorize('view workspace plans')`, sourced from `EntitlementManager::listPlanCatalogSummaries()` — an RFC-004 (entitlement/billing) surface, not RFC-003 (Workspace governance). Consistent with the audit's own row 104, which buckets it separately from row 103's core Workspace/Sub-Account surfaces.

**Reachability check (mechanical):** grepped `customer/business/edit.blade.php` and `customer/workspaces/show.blade.php` for `usage-billing`/`additional-business-slot`. `customer/business/edit.blade.php`: zero matches. `customer/workspaces/show.blade.php`: exactly one match — a JS-rewritten outbound `<a data-business-action="usage-billing">` link (line 309; the anchor's `href` is built client-side from `window.location.pathname`, see §11) that navigates to the dedicated `customer.workspaces.businesses.usage-billing.show` route. This is a **link out to a separate route**, never an embedded partial — confirmed by a repository-wide grep for `additional-business-slots` and `usage-billing` markup finding no inlining anywhere.

### 5.3 Embedded out-of-scope regions inside two otherwise-in-scope files

Both `customer/workspaces/show.blade.php` and `admin/workspaces/show.blade.php` are **shared files**: the bulk of each is core Workspace/Business identity and membership management (in scope), but each also embeds a self-contained RFC-004 Plan/Entitlement region (out of scope). A future visual pass on these two files **must not restyle, touch, or reflow** the following regions — they remain fully native, unmodified, and are reserved for a separate future RFC-004/005 presentation contract:

- **`customer/workspaces/show.blade.php` lines 122–173** — the `@isset($entitlement)` "Plan & Capacity" `<div class="card">` block.
- **`customer/workspaces/show.blade.php` lines 301–366** — the `@isset($entitlement)` "Usage & Billing" links + "Platform feature preferences" table block (nested inside the otherwise-in-scope "Businesses" card).
- **`admin/workspaces/show.blade.php` lines 139–419** — the entire `@can('view workspace plans')` "Plan & Entitlement" `<section>` and `@can('manage workspace plans')` "Mutate Plan" `<section>`.

A future implementation task must re-verify these exact line ranges against its own base commit before editing, since line numbers will drift once other, unrelated commits land.

### 5.4 Incidental Business/Workspace references — not A2

- `resources/views/customer/onboarding/steps/{business,assets}.blade.php` — part of the A1 onboarding wizard (already redesigned in PR #191), not the retained management UI. Not touched, not re-touched.
- `resources/views/admin/opportunities/runs/index.blade.php` — displays a read-only "Business ID"/"Business name" label identifying which Business an Opportunity run belongs to. Incidental reference, not Business management.

### 5.5 Admin Tenant Management (legacy) — separately excluded

`resources/views/admin/customer/**` (legacy tenant CRUD/impersonation) is excluded per the retention audit's own §7 row 234 ("KEEP BACKEND, REBUILD UI" / "DO NOT DESIGN LEGACY UI"). Not part of A2. Not inspected further here — out of A2's file-search scope entirely (different controller namespace, `Admin\CustomerController`, not `Admin\WorkspaceController`/`Admin\BusinessController`).

---

## 6. Customer Workspace architecture trace

**Model:** `workspaces` table — `id`, unique `uid`, `name`, `owner_user_id` (FK → `users`, `restrictOnDelete()`), `is_active` (bool). Ownership is represented **solely** by `owner_user_id` — an owner is never also a membership row (`OwnerCannotBeMemberException` if attempted).

**Membership:** `workspace_memberships` — `role` (`App\Enums\Workspace\WorkspaceMembershipRole`: `admin`/`staff`, never `owner`), `business_access_scope` (`App\Enums\Workspace\WorkspaceBusinessAccessScope`: `all`/`selected`, no DB default — always explicit), `is_active`, unique `(workspace_id, user_id)`. `workspace_membership_businesses` links a `selected`-scope membership to specific Businesses.

**Domain authority — `App\Library\Workspace\WorkspaceManager`** (`app/Library/Workspace/WorkspaceManager.php`) is the **single centralized authorization seam** for every customer-side mutation. Every mutating method locks its target row(s) (`findForUpdate()`) inside `DB::transaction()` and calls one of exactly two private assertions:

```php
private function assertActorIsOwner(int $actorUserId, Workspace $workspace): void { /* owner_user_id === actor, else throw UnauthorizedWorkspaceManagementException */ }
private function assertActorIsOwnerOrActiveAdmin(int $actorUserId, Workspace $workspace): void { /* owner, or active membership with role=Admin, else throw */ }
```

Read-access algorithm — `userCanAccessBusiness()` (§14.1 of RFC-003): a Business is visible to a user only if (a) the Business's Workspace exists **and is active**, and (b) the user is the Business's direct `customer_id` owner, OR the Workspace owner, OR holds an active membership whose scope is `all` or explicitly includes that Business.

**Controller — `App\Http\Controllers\Customer\Workspace\WorkspaceController`** never re-implements authority; it resolves *addressability* only (`resolveAccessibleWorkspace()` → 404 if the actor has no role at all in the Workspace; `resolveAccessibleMembership()` → 404 if the target user is the owner) and translates `WorkspaceManager`'s typed exceptions into flash-error redirects (for self-service actions) or 404s (for member-management actions, to avoid disclosing membership existence to an unauthorized viewer).

Full method → route → authority table:

| Method | Route name | Authority (via `WorkspaceManager`) |
|---|---|---|
| `index()` | `customer.workspaces.index` | none — lists only Workspaces where actor is owner or active member |
| `show()` | `customer.workspaces.show` | addressability only (any role) |
| `store()` | `customer.workspaces.store` | none — any authenticated user creates a Workspace they own |
| `rename()` | `customer.workspaces.rename` | owner-or-active-Admin |
| `deactivate()` | `customer.workspaces.deactivate` | **owner-only** |
| `reactivate()` | `customer.workspaces.reactivate` | **owner-only** |
| `storeBusiness()` | `customer.workspaces.businesses.store` | owner-or-active-Admin + active Workspace + RFC-004 capacity gate |
| `reassignBusiness()` | `customer.workspaces.businesses.reassign` | owner-or-active-Admin on **both** source and target Workspace, actor's own Business access, RFC-004 capacity gate on target |
| `transferOwnership()` | `customer.workspaces.ownership.transfer` | **owner-only, no exception — no Admin/Staff/platform-admin bypass** |
| `storeMember()` | `customer.workspaces.members.store` | owner-only for an Admin-role target, owner-or-active-Admin for a Staff-role target; owner can never be added as a member |
| `updateMemberRole()` | `customer.workspaces.members.role` | **owner-only, no exception** |
| `updateMemberAccess()` | `customer.workspaces.members.access` | owner-or-active-Admin |
| `deactivateMember()` / `reactivateMember()` | `customer.workspaces.members.deactivate` / `.reactivate` | target-role-dependent (owner-only for Admin targets, owner-or-active-Admin for Staff targets) |

**Ownership transfer** (`transferOwnership()`): owner-only; reassigns `owner_user_id`; deactivates the incoming owner's pre-existing membership if any; reconciles the outgoing owner via an explicit caller-chosen disposition — `deactivate` or `convert_to_admin` (with an explicit `all`/`selected` scope). Writes one `workspace_transitions` audit row (`OwnershipTransferred`), dispatches `WorkspaceOwnershipTransferred`. Locks the Workspace, then both owner `users` rows and both membership rows in ascending-ID order, retried up to 3 times for deadlock recovery.

**Business reassignment** (`reassignBusiness()`): locks every distinct Workspace involved (ascending ID) then the Business row, re-verifies the Business's authoritative `workspace_id` against the expected source before proceeding (`BusinessWorkspaceMismatchException` guard). Only `businesses.workspace_id` changes — `customer_id` is never touched (`EloquentBusinessRepository::reassignWorkspace()`'s single-field write). Writes one `workspace_transitions` row (`BusinessReassigned`), dispatches `WorkspaceMembershipBusinessUnassigned` per removed scoped-access grant then `BusinessReassignedToWorkspace`.

**Existing test coverage** (53 files under `tests/Feature/Workspace/`, 4 under `tests/Unit/Workspace/`) covers every action above in depth — `WorkspaceLifecycleTest`, `WorkspaceMutationHttpTest`, `WorkspaceOwnershipTransferTest`/`...HttpTest`, `WorkspaceBusinessOrchestrationTest`, `WorkspaceBusinessReassignmentHttpTest`, `WorkspaceMemberManagementHttpTest`, `WorkspaceMembershipLifecycleTest`, `WorkspaceManagerConcurrencyTest`, `WorkspaceEffectiveAccessTest`, and repository/model/schema-level suites.

---

## 7. Admin Workspace architecture trace

**Controller — `App\Http\Controllers\Admin\WorkspaceController`** (151 lines) is **read-only** — `index()` and `show()` only, each gated by `$this->authorize('view workspace')` (a generic `config('permissions')` → `Gate::define()` → `AccountRepository::hasPermission()` entry, the same mechanism every other admin module uses). No `WorkspaceManager` dependency at all; never mutates; never consults owner/membership/scope. Sits inside `Route::middleware(EnsureUserIsAdministrator::class)` (checks `users.is_admin`), itself nested inside the outer admin route group's `['web','auth','can:access backend','ValidProduct','twofactor']`.

**No create/rename/deactivate/reactivate/member-management/Business-management/ownership-transfer route exists on the admin side at all** — confirmed by the admin route file's own explicit comment and by the exhaustive route grep in §4. The admin Workspace surface is strictly cross-tenant inspection.

There is **no dedicated `WorkspacePolicy` class** (`app/Policies/` contains only `UserPolicy.php`). Admin authorization is the generic Gate-permission mechanism (`view workspace`), entirely independent of, and never overlapping with, the customer-side `WorkspaceManager` authority model in §6. This is two coherent, purpose-specific seams, not one universal Policy and not scattered ad hoc checks.

**Existing test coverage:** `tests/Feature/Workspace/AdminWorkspaceControllerTest.php` (36 tests) — read-only index/show, cross-tenant inspection, `EnsureUserIsAdministrator` boundary, confirms no mutation route exists.

(`admin.workspace-plan-catalog.*` and `admin.workspaces.plan.*`/`admin.workspaces.entitlement-overrides.*` routes exist and are gated by `EnsureUserIsAdministrator` + `view workspace plans`/`manage workspace plans` Gate permissions — these back the excluded region in §5.3 and are out of A2's authorization trace.)

---

## 8. Customer Business architecture trace

**View:** `customer/business/edit.blade.php` → `route('customer.business.update')`.

**Route** (`routes/customer.php:522-525`): `GET business` → `BusinessController@edit` (`customer.business.edit`); `PUT business` → `BusinessController@update` (`customer.business.update`).

**Controller — `App\Http\Controllers\Customer\BusinessController`**: `edit()` resolves the customer's primary Business via `findPrimaryByCustomer($customer->user_id)` (redirects to onboarding if none exists, per RFC-001 §19). `update()` calls `BusinessManager::updateBusiness($customer, $business, $request->validated())`, which independently re-asserts ownership (`assertOwnership($customer, $business)`, defense-in-depth even though the controller already scoped `$business`) before delegating to the repository.

**FormRequest — `App\Http\Requests\Business\UpdateBusinessRequest`**, whose own docblock states the exact field-scope contract: *"May update identity/contact/profile fields only — never `customer_id`, `is_primary`, `canonical_domain`, `status`, or `activated_at` (RFC-001 §17). Those protections are enforced by `BusinessRepository`, not this request."*

Editable via this form: `name`, `industry`, `industry_other`, `description`, `email`, `phone`, `website_url`, `google_business_profile_url`, `facebook_url`, `instagram_url`, `country_code`, `timezone`, `currency_code`.

Protected (never listed in `rules()`, so `$request->validated()` never contains them regardless of submitted payload): `customer_id`, `is_primary`, `canonical_domain`, `status`, `activated_at`. `workspace_id` is likewise never in `rules()` — see §19's hardening note.

**Repository defense-in-depth — `EloquentBusinessRepository::update()`**:
```php
$attributes = Arr::except($attributes, ['customer_id', 'is_primary', 'canonical_domain', 'status', 'activated_at']);
```

**Existing test coverage:** `tests/Feature/Business/BusinessManagerTest.php`, `BusinessRepositoryTest.php` (including the field-exception protection), `BusinessOnboardingHttpTest.php` for the related onboarding-time Business creation path (A1 scope, already covered).

---

## 9. Admin Business architecture trace

**Routes** (`routes/admin.php:596-600`, inside `Route::middleware(EnsureUserIsAdministrator::class)`): `Route::resource('businesses', 'BusinessController', ['only' => ['index','show','edit','update']])` + `Route::patch('businesses/{business}/status', ...)->name('businesses.status.update')`. **No create or delete admin route exists** — the route file's own comment states this is intentional ("the RFC does not permit either from the admin surface").

**Controller — `App\Http\Controllers\Admin\BusinessController`**: `index()`/`show()`/`edit()` gated by `$this->authorize('view business')` / `$this->authorize('edit business')`; cross-tenant by route-model binding (no ownership filter — this is intentional, documented in-code: *"The business's own owning customer is resolved from the record itself, never from the authenticated admin... the check trivially passes."*). `update()` delegates to the same `BusinessManager::updateBusiness()` used by the customer path. `updateStatus()` is a **separate**, dedicated mutator (`BusinessRepository::updateStatus()`, bypassing the field-exception `update()` path since it only ever writes `status`).

**FormRequest — `App\Http\Requests\Business\AdminUpdateBusinessRequest`** — field-for-field identical to the customer `UpdateBusinessRequest` (its own docblock: *"Mirrors the same accepted identity/contact/profile fields... never `customer_id`, `canonical_domain`, `is_primary`, `status`, `activated_at`, `created_at`, or `updated_at`"*).

**What admin can do that the customer form cannot:** cross-tenant access to any Business (no ownership scoping); a separate `status` mutator (`UpdateBusinessStatusRequest` + `updateStatus()`, `admin/businesses/show.blade.php`'s `@can('edit business')`-gated "Change status" form, `BusinessStatus::cases()` = `draft`/`active`/`inactive`). **Nothing admin can do via these three views changes `workspace_id`, `customer_id`, `is_primary`, `canonical_domain`, or `activated_at`.**

**Permissions** (`config/permissions.php`): `view business`, `edit business` (category `Business`).

**Existing test coverage:** `tests/Feature/Business/AdminBusinessControllerTest.php` — admin cross-tenant index/show/edit/update/status HTTP coverage including the `EnsureUserIsAdministrator`/Gate authorization boundary.

---

## 10. Account-role / permission matrix

| Role | How determined | Workspace(s) visible | Business(es) visible | Owner-only actions | Owner-or-Admin actions | Never available |
|---|---|---|---|---|---|---|
| **Workspace Owner** (customer) | `workspace.owner_user_id === user.id` | Every Workspace they own | Every Business in their owned Workspace(s), regardless of scope | Deactivate/reactivate Workspace; ownership transfer; add/change-role of an Admin member; deactivate/reactivate an Admin member | Rename; create Business; reassign Business; add a Staff member; change member business-access scope | — |
| **Workspace Admin** (active membership, `role=admin`) | active `workspace_memberships` row, `role=Admin` | Workspaces where actively an Admin member | Businesses per their own `business_access_scope` (`all` or `selected`) | — | Rename; create Business; reassign Business (needs owner-or-Admin on **both** Workspaces); add/deactivate/reactivate a Staff member; change any member's business-access scope | Deactivate/reactivate the Workspace; transfer ownership; change any member's **role**; add, deactivate, reactivate, or change the scope of an **Admin** member |
| **Workspace Staff** (active membership, `role=staff`) | active `workspace_memberships` row, `role=Staff` | Workspaces where actively a Staff member | Businesses per their own `business_access_scope` | — | — | Every mutation form on `customer/workspaces/show.blade.php` is hidden by `@if (in_array($workspace['role'], ['Owner','Admin'], true))` — Staff sees a strictly read-only view |
| **Business-scoped user** (direct `businesses.customer_id` ownership) | `business.customer_id === user.id`, independent of any Workspace role | N/A (this axis is Business-direct, not Workspace-mediated) | Their own directly-owned Business(es) | — | Edit their own primary Business profile (`customer/business/edit.blade.php`) | Anything Workspace-scoped unless they also separately hold a Workspace role |
| **Platform admin** | `users.is_admin` + `EnsureUserIsAdministrator` + `view workspace`/`view business`/`edit business` Gate permissions | Every Workspace, cross-tenant, read-only | Every Business, cross-tenant, read + identity/profile edit + status change | — | — | Create/rename/deactivate/reactivate a Workspace; ownership transfer; any member-management action; Business creation or reassignment — **no such admin route exists at all** |

Per `AGENTS.md`'s own documented Workspace-authorization rule, independently confirmed against code: *"Workspace ownership, active membership, direct Business ownership, `users.parent_id`, and platform-admin access are separate authorization paths. Do not allow one to silently imply another. Owner role wins over an anomalous coexisting membership row. Inactive membership grants no Workspace-derived access. Business scope (`all`/`selected`) is independent from member role (`admin`/`staff`)."* This matches `userCanAccessBusiness()`'s exact algorithm in §6 precisely.

**Deactivated Workspace:** blocks all customer-side mutation (`renameWorkspace()`'s active-Workspace check) and blocks all customer-side Business access **even for the owner** (`userCanAccessBusiness()`'s inactive-Workspace gate returns `false` unconditionally once `!$workspace->is_active`).

---

## 11. Workspace behavior inventory (`customer/workspaces/show.blade.php` — large-view mechanical inventory)

This file is 602 lines and behavior-dense; it is not "just HTML." Full inventory:

**Forms (source occurrences; several render once per loop iteration at runtime):**
1. Rename Workspace (`data-workspace-action="rename"`) — Owner/Admin only
2. Deactivate Workspace (`data-workspace-action="deactivate"`) — Owner only
3. Reactivate Workspace (`data-workspace-action="reactivate"`) — Owner only
4. Transfer ownership (`data-workspace-action="ownership/transfer"`) — Owner only; contains a nested conditional sub-region (`data-ownership-transfer-admin-fields`) with its own scope select and dynamic checkbox list
5. Create Business (`data-workspace-action="businesses"`) — Owner/Admin only
6. Reassign Business (`data-business-action="reassign"`, one per manageable Business row) — Owner/Admin only
7. Enable/disable platform feature preference (`data-business-action="features/{key}/enable|disable"`, one per Business × feature row) — Owner/Admin only
8. Add member (`data-workspace-action="members"`) — Owner/Admin only; Admin role option itself hidden unless viewer is Owner
9. Change member role (`data-member-action="role"`, per member row) — Owner only (rendered only if `$viewerIsOwner`)
10. Update member business access (`data-member-action="access"`, per member row) — rendered only if `$viewerCanSeeMembersCompleteAccess`
11. Deactivate member (`data-member-action="deactivate"`, per member row) — rendered only if `$viewerCanManageLifecycle`
12. Reactivate member (`data-member-action="reactivate"`, per member row) — rendered only if `$viewerCanManageLifecycle`

**Hidden/hidden-by-role wiring:** every mutation form/section above is wrapped in a PHP-side `@if` role check (§10) — this is not merely CSS-hidden, it is never rendered to a Staff member's HTML at all.

**JS handlers (one `<script>` block, lines 518–598, rendered only when `in_array($workspace['role'], ['Owner','Admin'])`):**
- Dynamically sets every `form[data-workspace-action]`'s `action` attribute from `window.location.pathname` at page load (no static `action=` on these forms in the HTML source — this is a genuine, deliberate architectural pattern, not an oversight).
- Same pattern for `form[data-member-action]` (path includes `/members/{memberUid}/...`) and `form[data-business-action]` / `a[data-business-action]` (path includes `/businesses/{businessUid}/...`).
- A `business_access_scope` select → checkbox-sync handler: disables and unchecks all `business_uids[]` checkboxes in the same form when scope is switched to `all`.
- A `previous_owner_disposition` select → admin-fields-visibility handler: shows/hides the `data-ownership-transfer-admin-fields` block and toggles its inner scope select's `disabled` state based on whether `convert_to_admin` is selected.

**Confirmation flows:** none — every mutation (including Deactivate Workspace and Transfer ownership) submits immediately on click, no client-side confirm dialog exists today. Not to be added as new functionality by a future visual pass without separate authorization (would be new behavior, not restyle).

**Tables:** Reassign-target table (Business × target-Workspace-select), effective Business list, platform-feature-preference table (per Business, one `<h6>` + `<table>` pair), member directory table.

**Dialogs/modals/menus/tabs:** none present anywhere in this file today.

**Hidden inputs:** none beyond the CSRF token each `@csrf` directive emits — no other hidden fields.

**Non-form dynamic markup:** `dl`/`dt`/`dd` status/role/tier definition lists (both the Workspace-overview card and, when `$entitlement` is set, the out-of-scope Plan & Capacity card).

---

## 12. Business behavior inventory

**`customer/business/edit.blade.php`** (105 lines): a single form, one `PUT` submission (`@csrf` + `@method('PUT')`), 12 fields (11 `<input>` + 1 native `<textarea>` + 1 native `<select>`), `old()`-bound values, a session-status success alert and a validation-error alert. No JS, no tables, no dialogs, no hidden inputs beyond CSRF.

**`admin/businesses/edit.blade.php`** (119 lines): same field set plus `industry_other` (admin-only field, not present on the customer form), one `PUT` submission, one "Cancel" link back to `admin.businesses.show`. No JS.

**`admin/businesses/show.blade.php`** (94 lines): read-only `dl`/`dt`/`dd` detail block (owner, industry, status, contact fields, primary location, active-service count, onboarding status/step/error) plus one `@can('edit business')`-gated status-change form (`PATCH`, `@method('PATCH')`) and an "Edit" / "Usage Billing" (out-of-scope link-out) button pair in the card header.

**`admin/businesses/index.blade.php`** (84 lines) / **`admin/workspaces/index.blade.php`** (76 lines): `GET` filter forms (search/status/industry, search/is_active respectively) + a results table + `->links()` pagination. No mutation.

**`customer/workspaces/index.blade.php`** (78 lines): one `POST` create-Workspace form + a read-only Workspace list table.

**`admin/workspaces/show.blade.php`, in-scope lines 1–138**: pure read-only `dl` detail block + Businesses table + Memberships table. **Zero forms in the in-scope region.**

---

## 13. Central-control / theme audit

For all 8 in-scope files, mechanically counted:

| Metric | Count |
|---|---|
| `data-feather` occurrences | 0 |
| Hex/`rgb()`/`rgba()`/`hsl()` color literals | 0 |
| `font-family` declarations | 0 |
| Locally duplicated branding/logo logic | 0 |
| Legacy Theme Customizer dependency | 0 |
| Page-local palette/style `<style>` overrides | 0 |

All 8 views rely exclusively on Bootstrap utility classes (`.card`, `.badge`, `.table`, `.form-control`, `.btn`, `.row`/`.col-*`) which the existing Design System already retokenizes globally (per `DESIGN-SYSTEM-M2-CONTRACT.md`), plus the shared `layouts/contentLayoutMaster` chrome. No per-Workspace, per-Agency, or per-Business theme control exists today and none is authorized by this contract. Agency white-labeling remains explicitly out of scope, deferred to a separate future tenancy-aware contract.

---

## 14. Icon/color/font audit

Zero `data-feather` icon usage anywhere in the 8 in-scope files — no icon migration work is needed or authorized for A2 (same finding pattern as A1). The only icon-shaped markup present today is inside DS components already used elsewhere in the app (`x-ds-icon` inside `x-pagination`'s chevrons, if/when `<x-pagination>` is adopted — see §16) — no new icon inventory is required.

---

## 15. Component inventory

Read in full (`resources/views/components/*.blade.php`): `alert`, `badge`, `button`, `card`, `dialog`, `ds-icon`, `empty-state`, `input`, `menu`, `pagination`, `select`, `switch-toggle`, `table`, `tabs`, `tooltip`, plus `branding-{favicon,footer,illustration,logo}` (platform-branding infrastructure, out of A2's editing scope entirely).

Exact `@props()` signatures relevant to A2 (all pre-existing, unmodified by A1, confirmed byte-identical to the A1 contract's own record for the six components A1 already adopted):

- **`x-card`**: `title` (nullable), `padded` (default `true`).
- **`x-alert`**: `variant` (`neutral|accent|success|warning|danger`), `icon` (nullable), `dismissible` (default `false`).
- **`x-button`**: `variant` (`primary|secondary|outline|ghost|danger` — **no `success`/`warning` variant, and no `outline-danger`/`outline-warning`/`outline-success` sub-variant**), `size` (`sm|md|lg`), `type`, `href`, `icon`, `disabled`.
- **`x-input`** / **`x-select`**: unchanged from A1's record — `label`, `name`, `type`/`options`, forwarding via `$attributes->merge()`; `x-select` has no `error` prop (confirmed still absent).
- **`x-empty-state`**: `icon` (default `inbox`), `title` (required), `description` (nullable).
- **`x-badge`** *(not used by A1 — new to this audit)*: `variant` (`neutral|accent|success|warning|danger`) → renders `<span class="badge rounded-pill {bg-light-*} text-caption fw-medium">`. Directly matches the current hand-rolled `<span class="badge badge-light-success">Active</span>` / `badge-light-secondary` / `badge-light-danger` pattern used throughout all 8 views.
- **`x-table`** *(not used by A1)*: `headers` (array, optional) → wraps `<table class="table ds-table align-middle">` in its own `.table-responsive` div, `{{ $slot }}` for `<tbody>` rows. Directly matches every `<table class="table">`/`<table class="table table-hover">` in the 8 views.
- **`x-pagination`** *(not used by A1)*: takes a `paginator` prop, renders a "Showing X to Y of Z" caption + prev/current/next controls using `<x-ds-icon>` chevrons. Matches the `{{ $paginator->appends(...)->links() }}` calls in `admin/businesses/index.blade.php` / `admin/workspaces/index.blade.php`, with one adaptation needed at implementation time: the paginator instance passed to `<x-pagination :paginator="...">` must already have `->appends(...)` applied to it (the component itself does not call `appends()`).
- **`x-dialog`**, **`x-menu`**, **`x-tooltip`**, **`x-tabs`**: exist in the library but have **no current pattern to adopt to** in any of the 8 views (no modals, dropdown menus, tooltips, or tab groups exist today). Available for a *future*, separately-authorized structural redesign (e.g., using `x-tabs` to reorganize the very dense Workspace show pages into Overview/Businesses/Members panels) — **not authorized by this contract**, since that would be new visual structure, not a restyle, and A2's design intent (§20) explicitly limits scope to making the existing hierarchy clearer, not adding new navigation patterns.
- **`x-switch-toggle`**: inspected and explicitly rejected as a candidate — it is a legacy, non-`@props()`-based component that itself still emits `data-feather` icons internally, i.e. it is not Design-System-clean. Not relevant to any A2 pattern in any case (no toggle-switch UI exists in these 8 views).

---

## 16. Exact component-adoption matrix

Patterns are described by rule (adopt/native), since a future implementation task will produce the exact per-file literal counts (mirroring A1's own two-phase precedent: this contract locks the *rule*, the implementation phase's own DS content/adoption tests lock the *exact count*).

| Pattern | Where it appears | Adopt? | Component | Notes |
|---|---|---|---|---|
| `.card` / `.card-header` + `.card-body` section wrapper | All 8 views, every section (Workspace overview, Businesses, Members, Business detail/edit, index list wrappers) | **Adopt** | `x-card` | `title` prop for the header text; content becomes the default slot — same pattern as A1's `show.blade.php`/`services.blade.php` |
| `alert-success` / `alert-danger` (session flash, validation errors) | All 8 views except the two admin index pages | **Adopt** | `x-alert` | `variant="success"`/`variant="danger"` |
| `<span class="badge badge-light-{success,secondary,danger}">` status pills | Active/Inactive Workspace, Active/Inactive membership, Allowed/Denied entitlement decision (out-of-scope region only), Draft/Active/Inactive Business status where rendered as a badge | **Adopt** | `x-badge` | `variant="success"` (Active/Allowed), `variant="neutral"` (Inactive), `variant="danger"` (Denied) — genuinely new adoption opportunity, not used in A1 |
| `<table class="table">` / `table-hover` / `table-sm` result/detail tables | Workspace index, admin Workspace index/show, admin Business index/show, member directory, reassign-target table, feature-preference table | **Adopt** | `x-table` | `headers` prop for the `<thead>` row; body rows stay as native `<tr>`/`<td>` inside the slot |
| `{{ $paginator->appends(...)->links() }}` | `admin/businesses/index.blade.php`, `admin/workspaces/index.blade.php` | **Adopt** | `x-pagination` | Pass the already-`->appends()`-chained paginator instance as the `paginator` prop |
| "No X found" / "You don't have access..." empty states | Workspace index (no workspaces), Businesses card (no accessible Businesses), Members card (no members), both admin index tables' `@empty` rows | **Adopt** | `x-empty-state` | `icon="inbox"` per A1's locked precedent value |
| `<button class="btn btn-primary">` / `btn-outline-primary` / `btn-outline-secondary` submit/action buttons whose color maps to an existing `x-button` variant | Create Workspace, Save changes (both edit forms), Filter (both admin index forms), Rename, Create Business, Reassign, Add member, Change role, Update access, Record/Remove disable preference, Edit (admin Business show), Usage Billing link (admin Business show, restyling the button itself only — never its destination), Update status, Cancel | **Adopt** | `x-button` | `variant="primary"` / `variant="outline"` / `variant="secondary"` per current class; `size="sm"` where `btn-sm` is present today |
| `<button class="btn btn-outline-{danger,warning,success}">` | Deactivate Workspace, Reactivate Workspace, Transfer ownership, Deactivate member, Reactivate member | **Do not adopt — stays native** | — | `x-button` has no `outline-danger`/`outline-warning`/`outline-success` sub-variant (only solid `variant="danger"` and generic `variant="outline"` which maps to `btn-outline-primary`); adopting would silently change these buttons' color semantics. Same category of confirmed component-API gap as A1's `btn-success` "Finish setup" finding — component extension, if ever wanted, is a separate, explicitly-authorized change, never invented ad hoc during a restyle |
| `<input type="text|email|number">` with a `<label>` | Every identity/profile/filter field across all 8 views | **Adopt** | `x-input` | Forwarding-only pattern, identical to A1 |
| `<select>` with `<option>` children | Industry, status, is_active, business_access_scope, previous_owner_disposition, role, target_workspace_uid (14 distinct `<select>` tags counted in source across the 8 files, several rendered N times at runtime inside loops) | **Adopt** | `x-select` | `options` as a `value => label` map built the same `collect(...)->mapWithKeys(...)` way A1 established for enum-backed selects; plain array literals for the small non-enum option sets (e.g. `previous_owner_disposition`) |
| `<textarea>` (Business description) | `customer/business/edit.blade.php`, `admin/businesses/edit.blade.php` | **Do not adopt — stays native** | — | No `x-textarea` component exists (same confirmed gap as A1) |
| `<input type="checkbox">` (per-Business assignment checklists, ownership-transfer scope checklist) | `customer/workspaces/show.blade.php`, multiple locations | **Do not adopt — stays native** | — | No checkbox component exists (same confirmed gap as A1) |
| `<dl>`/`<dt>`/`<dd>` definition-list detail blocks | Workspace overview (customer + admin), Business detail (admin show) | **Do not adopt — stays native**, wrapped inside an adopted `x-card` | — | No dedicated "description list" / key-value component exists in the library; manufacturing one is out of scope (no speculative component creation) |
| `<a href="...">` plain text links (View/Edit in admin index tables, "Back to Workspaces") | Admin index tables, Workspace show breadcrumb link | **Do not adopt** | — | Not styled as buttons (no `.btn` class) — inline text links, not an `x-button` pattern |

---

## 17. Intentional non-adoptions (summary)

1. `outline-danger`/`outline-warning`/`outline-success` buttons — component variant gap (§16).
2. Native `<textarea>` — no `x-textarea` component.
3. Native `<input type="checkbox">` — no checkbox component.
4. Native `<dl>`/`<dt>`/`<dd>` — no key-value/description-list component.
5. Plain, unstyled `<a>` text links — not a button pattern, nothing to adopt.
6. `x-dialog`/`x-menu`/`x-tooltip`/`x-tabs` — no existing pattern in these 8 views to adopt them to; not authorized to invent new structure using them.
7. `x-switch-toggle` — rejected outright as not Design-System-clean (embeds its own `data-feather` icons) and has no matching pattern here regardless.

None of these are edited to force an adoption. This mirrors A1's own governing rule exactly.

---

## 18. Accessibility preservation

To be preserved unmodified by any future visual pass:

- Every `<label for="...">` / input `id` association — automatically carried forward by `x-input`/`x-select` adoption (each renders `id="{{ $name }}"` and `<label for="{{ $name }}">` when a `label` prop is passed, matching A1's precedent).
- `role="navigation" aria-label="Pagination"` already present inside `x-pagination` itself — no extra work needed once adopted.
- No current `aria-live`/`role="status"` region exists in any of the 8 views (unlike A1's analysis-polling screen) — nothing to preserve on that front, and none should be invented as new functionality.
- Native, keyboard-operable controls (`<select>`, `<input type="checkbox">`, `<button>`) must remain native where non-adopted (§17) — never converted to `<div>`-based custom widgets.
- The member-management table's conditional action rendering (`$viewerIsOwner`, `$viewerCanManageLifecycle`, `$viewerCanSeeMembersCompleteAccess`) must continue to omit forms from the DOM entirely when the viewer lacks authority — not merely visually hide them — preserving today's defense-in-depth posture where the HTML itself never discloses a control the viewer cannot use.

---

## 19. Security / correctness pre-audit

Dedicated pre-audit performed against real code (two independent Explore passes plus direct reading of all 8 in-scope views), covering: IDOR/cross-Workspace/cross-Business access, request-trusted Workspace/Business IDs, missing authorization on mutation routes, member role escalation, scope escalation, owner removal, ownership-transfer invariant bypass, Business reassignment bypass, deactivated-Workspace mutation, capacity-enforcement bypass, raw exception leakage, CSRF/method mismatches, unsafe mass assignment, admin/customer boundary leakage.

**Findings:**

| # | Area | Finding | Classification |
|---|---|---|---|
| 1 | Authorization centralization | Every customer-side mutation funnels through exactly two `WorkspaceManager` assertions (`assertActorIsOwner()` / `assertActorIsOwnerOrActiveAdmin()`), each re-locking its target row(s) inside a transaction before deciding. No controller re-implements or second-guesses this. | NO DEFECT |
| 2 | Admin/customer boundary | Admin Workspace/Business routes are layered: `can:access backend` → `ValidProduct` → `twofactor` → `EnsureUserIsAdministrator` (independent `users.is_admin` check) → per-action Gate permission (`view workspace`/`view business`/`edit business`). No admin route can mutate a Workspace or reassign/create a Business at all. | NO DEFECT |
| 3 | Ownership-transfer invariant | `transferOwnership()` is owner-only with **no** Admin/Staff/platform-admin bypass of any kind; locks Workspace, both owner `users` rows, and both membership rows in ascending-ID order (deadlock-safe); writes exactly one durable `workspace_transitions` audit row before dispatching the domain event. | NO DEFECT |
| 4 | Business reassignment bypass | Requires owner-or-active-Admin independently on **both** source and target Workspace, re-verifies the Business's authoritative `workspace_id` after locking (rejects a stale/mutated in-memory `workspace_id`), and re-runs the RFC-004 capacity gate (`assertCanCreateAnotherBusiness()`) against the target. Concurrency independently proven race-safe by `EntitlementManagerConcurrencyTest`'s 8 real two-OS-process scenarios (including a create-vs-reassign race), asserting exactly one winner and no over-allocation. | NO DEFECT |
| 5 | Role/scope escalation | `changeMemberRole()` is **owner-only with no exception** — an Admin member can never promote themselves or another Staff member to Admin, and can never touch another Admin's role. `addMember()` requires owner-only authority specifically for an Admin-role target. | NO DEFECT |
| 6 | Deactivated-Workspace mutation | `renameWorkspace()`/business-creation both re-check the Workspace is active; `userCanAccessBusiness()` unconditionally denies Business access (even to the owner) once `!$workspace->is_active`. | NO DEFECT |
| 7 | CSRF / method correctness | Every mutating form in all 8 views carries `@csrf`; every non-POST-semantic action (`admin.businesses.update` PUT, `admin.businesses.status.update` PATCH) carries the matching `@method(...)` directive matching its route's declared HTTP verb. No mismatch found. | NO DEFECT |
| 8 | Raw exception leakage | Controllers on both the customer and admin Workspace/Business paths catch typed domain exceptions and flash a fixed message or 404 — no `$exception->getMessage()` echoed to a view in any of the 8 files (confirmed by direct read; none of these 8 views references an exception object at all). | NO DEFECT |
| 9 | Mass assignment | Both `UpdateBusinessRequest` and `AdminUpdateBusinessRequest` explicitly enumerate allowed fields (no wildcard/`$request->all()` anywhere); `EloquentBusinessRepository::update()` additionally strips `customer_id`/`is_primary`/`canonical_domain`/`status`/`activated_at` via `Arr::except()` even if a future FormRequest change ever added them. | NO DEFECT |
| 10 | `workspace_id` mass-assignment hardening gap | `EloquentBusinessRepository::update()`'s `Arr::except()` list does **not** include `workspace_id`. Today this is safe *only* because neither `UpdateBusinessRequest` nor `AdminUpdateBusinessRequest` validates a `workspace_id` field, so `$request->validated()` never contains it. There is no independent, repository-level defense-in-depth for this one field the way there is for the other five protected fields. | **NONBLOCKING HARDENING** — no current exploit path exists (both `edit.blade.php` forms omit the field entirely and no route wires it through this `update()` method); worth closing in a future, separately-scoped hardening change (add `workspace_id` to the repository's exception list) but does not block A2 visual work, since the visual pass touches neither the FormRequest nor the repository |
| 11 | Client-side `action=` construction | `customer/workspaces/show.blade.php`'s mutation forms have no static `action=` attribute — it is set by JS from `window.location.pathname` at page load. This is an intentional existing pattern (not a defect), but any future visual pass must preserve every `data-workspace-action`/`data-member-action`/`data-business-action` attribute exactly, since the JS selects on them. | NO DEFECT (documented behavioral seam, see §11 and §18) |

**Conclusion: no BLOCKING SECURITY and no BLOCKING NONSECURITY CORRECTNESS finding.** One NONBLOCKING HARDENING item (#10) is recorded for future, separately-scoped remediation — it does not gate A2 visual work, since A2 does not touch the FormRequest or repository layer at all (write allowlist is Blade views + new DS tests only, per §22).

**A2 visual implementation is NOT blocked by any nonvisual prerequisite** — unlike A1, which required a full remediation contract + implementation (PRs #189/#190) before its visual work could begin, A2's pre-audit found nothing requiring a code fix first.

---

## 20. Design intent (non-binding guidance for the future implementation task)

The future A2 visual redesign should make the Workspace hierarchy legible — Workspace → Members → Businesses → roles/scopes/assignments — using the now-available `x-card`/`x-badge`/`x-table`/`x-button`/`x-input`/`x-select`/`x-empty-state`/`x-pagination` adoptions locked in §16, without reintroducing the legacy Sub-Account mental model (§5.1) and without touching the embedded billing/entitlement regions (§5.3). Business profile editing (customer and admin) remains a single-purpose identity/contact form. Admin Workspace/Business views remain read-mostly platform-administration surfaces. No new product functionality, no new navigation structure (e.g. tabs), and no new confirmation dialogs are authorized by this contract — those would each be new behavior, not a restyle, and would need their own explicit authorization exactly as A1's stepper-restyle-only boundary was locked.

---

## 21. Test strategy / exact future DS test plan

Following A1's own established pattern (`BusinessOnboardingDesignSystemContentTest`, `BusinessOnboardingComponentAdoptionTest`, `BusinessOnboardingExistingBehaviorPreservedTest`), the future implementation task should create exactly:

- `tests/Feature/DesignSystem/WorkspaceBusinessDesignSystemContentTest.php` — content hygiene: zero `data-feather`, zero hardcoded color/font literals, across all 8 files; confirms the two out-of-scope embedded regions (§5.3) were left byte-identical (a targeted diff-region check, not a full-file hash, since line numbers may legitimately shift elsewhere in the same file).
- `tests/Feature/DesignSystem/WorkspaceBusinessComponentAdoptionTest.php` — exact adoption-matrix counts per §16 (per-file `x-card`/`x-badge`/`x-table`/`x-button`/`x-input`/`x-select`/`x-empty-state`/`x-pagination` marker counts), plus explicit non-adoption assertions per §17 (zero `x-textarea`, zero checkbox-component markers, zero `<dl>`-replacement component, zero forbidden `outline-danger`/`outline-warning`/`outline-success` `x-button` variant usage).
- `tests/Feature/DesignSystem/WorkspaceBusinessExistingBehaviorPreservedTest.php` — HTTP-level behavior preservation: role/scope UI boundaries (Staff sees no mutation forms; Admin cannot see Admin-role member controls; owner-only forms only render for the owner), retained `data-workspace-action`/`data-member-action`/`data-business-action` attributes and the JS block's continued presence, retained route names/form field names, and a proof that the two out-of-scope embedded regions still render exactly as before for a user who can see them.

**Do not duplicate existing backend coverage.** The exhaustive Workspace/Business/Entitlement behavior and security suites already listed in §6/§7/§8/§9 (`WorkspaceLifecycleTest`, `WorkspaceOwnershipTransferTest`, `WorkspaceBusinessReassignmentHttpTest`, `WorkspaceMemberManagementHttpTest`, `AdminWorkspaceControllerTest`, `AdminBusinessControllerTest`, `EntitlementManagerBusinessSlotCapacityTest`, etc. — 50+ files) remain the authoritative, unduplicated proof of Workspace/Business correctness. The new DS tests exist only to prove the *visual* pass did not disturb any of it, exactly as A1's three DS test files did for onboarding.

---

## 22. Test-efficiency policy

This docs-only contract changes zero application code, so **no PHPUnit run of any kind was performed for this task.** The most recent known full-suite evidence (historical context only, not re-verified here): A1's post-implementation full suite, 3779 tests / 13591 assertions / 1 pre-existing unrelated `BrandingAdminFooterRenderTest` failure / 0 skipped, at commit `c3fcff3b84100ae03d600995220b2fae0a823ae3` (PR #190's implementation head, tree-identical to the current `origin/main` per PR #191's merge). Since no product code has changed between that commit and this contract's base SHA (`5be68c00...` is PR #191's own merge commit — a pure merge of already-tested content), this baseline remains valid and does not need to be rerun for a docs-only task.

When A2 visual implementation is later authorized: run the 3 new DS tests first, then the directly-affected existing Workspace/Business/Entitlement suites named in §21, then exactly one full suite at the end — no redundant pre-implementation full suite unless `origin/main`'s product-code tree has changed since this recorded baseline (verify via the same `git rev-parse ...^{tree}` comparison A1's implementation task used).

---

## 23. Exact future visual implementation allowlist

**8 existing Blade views** (restyle only, per §16's adoption matrix, excluding the §5.3 embedded regions):
1. `resources/views/customer/workspaces/index.blade.php`
2. `resources/views/customer/workspaces/show.blade.php`
3. `resources/views/customer/business/edit.blade.php`
4. `resources/views/admin/workspaces/index.blade.php`
5. `resources/views/admin/workspaces/show.blade.php`
6. `resources/views/admin/businesses/index.blade.php`
7. `resources/views/admin/businesses/show.blade.php`
8. `resources/views/admin/businesses/edit.blade.php`

**3 new Design System test files** (§21):
9. `tests/Feature/DesignSystem/WorkspaceBusinessDesignSystemContentTest.php`
10. `tests/Feature/DesignSystem/WorkspaceBusinessComponentAdoptionTest.php`
11. `tests/Feature/DesignSystem/WorkspaceBusinessExistingBehaviorPreservedTest.php`

**Total: exactly 11 paths.**

No backend path (`app/**`, `routes/**`, `config/**`, `database/**`) is authorized by this contract for any future A2 work, since §19 found no blocking prerequisite requiring one. If a future implementation task discovers a genuine need to touch backend code, that is out of the visual allowlist and requires its own separately-contracted nonvisual remediation, exactly as A1's Phase I/J did.

---

## 24. Stop threshold

**A 12th changed path in the future implementation task is the stop threshold** — mirroring A1's "13th path" rule (12 allowlisted paths there, one more than the allowlist size). If any future implementation task's diff touches a 12th path beyond the 11 named in §23, that task must stop and report rather than proceed.

For **this current docs-only contract task**, the stop threshold is **the 2nd changed path** — this document creates exactly one file (`docs/automation/DESIGN-SYSTEM-M2-A2-WORKSPACE-BUSINESS-CONTRACT.md`) and touches nothing else.

---

## 25. Explicit Sub-Account exclusion

Confirmed zero Sub-Account paths in §23's allowlist. Confirmed zero references to `SubAccounts`, `parent_id`, or Sub-Account routes/controllers in any of the 8 in-scope views (§5.1). Legacy Sub-Accounts remain **DELETE LATER**, untouched, unlinked from A2.

---

## 26. Explicit billing/usage exclusion

Confirmed zero billing/usage/commercial-slot/plan-catalog paths in §23's allowlist (§5.2). Confirmed the two embedded out-of-scope regions inside `customer/workspaces/show.blade.php` and `admin/workspaces/show.blade.php` are explicitly carved out by exact line range and excluded from any future restyle (§5.3). `admin/workspace-plan-catalog/index.blade.php` explicitly excluded with documented reasoning, refining the retention audit's own path-prefix estimate (§5.2, §3).

---

## 27. A3 / B1 boundary

This contract authorizes **A2 contract/audit work only**. It does not begin, plan the implementation of, or otherwise advance A3 or B1 (both remain future surviving-roadmap groups per `PRODUCT-SURFACE-RETENTION-AUDIT.md` §9's ordered list). No A3 or B1 file, route, or document was read, referenced as an implementation target, or modified by this task.

---

## 28. Human handoff / no-auto-advance rule

This contract must be reviewed and merged by a human before any A2 visual implementation task may begin, exactly as `DESIGN-SYSTEM-M2-A1-BUSINESS-ONBOARDING-CONTRACT.md` was merged (PR #187/#188) before A1's own remediation and visual implementation tasks were separately authorized (PRs #189/#190/#191). Per governance (§0): `advance_automatically: false`, `start_a3_automatically: false`, `start_b1_automatically: false`, `visual_implementation_requires_separate_human_authorization: true`, `merge_authority: human_only`. This task does not open, request, or imply approval for a follow-up implementation task — it stops after the report below.
