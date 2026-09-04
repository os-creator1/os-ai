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
security_pre_audit_status: complete_with_blocking_authorization_drift
security_pre_audit_result: blocking_authorization_finding

blocking_authorization_prerequisite_count: 1
blocking_nonsecurity_correctness_prerequisite_count: 1
nonvisual_blocking_prerequisite_count: 2

a2_visual_status: blocked_until_nonvisual_workspace_business_remediation_human_merged

visual_implementation_base: post_nonvisual_remediation_main
visual_implementation_base_must_include_nonvisual_remediation: true
pre_remediation_behavior_is_not_visual_preservation_baseline: true
post_remediation_behavior_requires_mechanical_reverification: true

visual_implementation_requires_separate_human_authorization: true

advance_automatically: false
start_a3_automatically: false
start_b1_automatically: false

merge_authority: human_only
no_force_push: true
no_deployment: true

maximum_correction_rounds: 2
correction_round: 1
correction_round_is_final: false

contract_status: correction_round_1_pending_review

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

**Correction Round 1 (this revision):** the initial draft's security/correctness pre-audit (§19) incorrectly concluded there was no blocking prerequisite. Independent review, mechanically re-verified against RFC-003 §14/§14.1/§22, `BusinessController`, `BusinessManager`, `WorkspaceManager`, `BusinessIndustry`, `UpdateBusinessRequest`, and both `customer`/`admin` Business edit views, found **two real blocking nonvisual prerequisites** (§19) plus several component-adoption and role-matrix inconsistencies (§10, §12, §16, §17) that this revision corrects. No visual implementation, no application code, and no other document is touched by this correction — exactly one path changes, as before.

No visual implementation may begin from this document alone, and — as of this correction — **A2 visual implementation is now explicitly blocked** pending a separate nonvisual remediation contract and its human-merged implementation (§19, §28). This mirrors, and is now consistent with, the A1 precedent's own two-phase structure: `DESIGN-SYSTEM-M2-A1-BUSINESS-ONBOARDING-CONTRACT.md` → `DESIGN-SYSTEM-M2-A1-ONBOARDING-BEHAVIOR-REMEDIATION-CONTRACT.md` (nonvisual remediation, PR #189) → remediation implementation (PR #190) → visual implementation (PR #191). A2 will follow the identical sequence (§28).

---

## 2. Exact base SHA

Verified before any file was created, and re-verified unchanged at the start of this correction:

```
git fetch origin --tags
git rev-parse origin/main
5be68c00ee146c34f2fd9ef8985389309db6c7e8
```

This is the human merge of PR #191, "Design System M2 A1 — Business Onboarding," merge parents `caa48f1b975dbaaaec9ce84c87952f4cb077ca9a` (PR #190 merge) and `c3fcff3b84100ae03d600995220b2fae0a823ae3` (the A1 visual implementation commit) — both confirmed via `git log -1 --format="%H %P"` against `origin/main`. Branch `chore/design-system-m2-a2-workspace-business-contract` was created fresh from this exact commit via `git worktree add -b`. This correction's starting head, `61099dca56cad6bb8aec84b8d4e02d5109533ca8`, was independently re-verified to have exactly `5be68c00...` as its sole parent before any edit was made.

---

## 3. Retention decision

Per `PRODUCT-SURFACE-RETENTION-AUDIT.md` §7 (row 229) and §9 ("A2. Workspace / Business"):

> Classification: **KEEP + REDESIGN**. Includes the surviving Workspace/Business customer/admin surfaces (`customer/workspace{,s}/**`, `admin/workspaces/**`, `admin/businesses/**`, old Slice 13/16/17 partials) and the Business-profile carve-out from the legacy Billing/Payments/Accounts slice (`customer/business/edit.blade.php`).

This contract independently, mechanically re-verifies that decision against current code (§4) rather than assuming the audit's estimate is still accurate. **One deliberate refinement of the audit's own path-prefix estimate is made and documented in §5**: `admin/workspace-plan-catalog/**`, though swept into the same consolidated table row (229) by path-prefix convenience, is excluded from A2 — this is consistent with, not contradictory to, the audit's own more granular row 104 ("Workspace Plan Catalog & Business admin" — a *separate* bucket from row 103's "Sub-Accounts & Workspaces").

Legacy Sub-Accounts (`customer/SubAccounts/**`) are explicitly **not** part of A2, per audit §12.3 ("RESOLVED... Classification: DELETE LATER, after explicit migration. No Design System work on legacy Sub-Account pages.").

**This retention decision itself is unaffected by Correction Round 1** — both blockers found (§19) are authorization/validation defects inside the already-retained `customer/business/edit.blade.php` surface, not a reason to reclassify any view's retention status.

---

## 4. Exact A2 view inventory (mechanically verified)

Mechanical search method: `resources/views/customer/**` and `resources/views/admin/**` globbed for `workspace*`/`business*` paths; every one of the 8 in-scope files grepped for `@include`/`@each`/`<x-`/`@component` (all four returned **zero matches in all 8 files** — none of these views has any app-specific sub-partial); `routes/customer.php` and `routes/admin.php` grepped for every route whose name/URI contains "workspace" or "business"; a repository-wide grep for `Workspace`/`Business`/`$workspace`/`$business` across all of `resources/views/customer/**` and `resources/views/admin/**` to catch anything outside the two obvious folders.

**Exactly 8 Blade view files constitute A2:**

| # | Path | Perspective | Purpose |
|---|---|---|---|
| 1 | `resources/views/customer/workspaces/index.blade.php` | Customer (any member) | List Workspaces the user belongs to; create-Workspace form |
| 2 | `resources/views/customer/workspaces/show.blade.php` | Customer (Owner/Admin see mutation forms; Staff read-only) | Workspace overview, rename/deactivate/reactivate, ownership transfer, Business creation/reassignment, member management — **plus an embedded, out-of-scope Plan & Capacity / Usage & Billing region, see §5** |
| 3 | `resources/views/customer/business/edit.blade.php` | Customer (Business-scoped, direct ownership) | Business profile identity/contact/profile edit form — **carries a confirmed BLOCKING authorization drift (§19, Blocker #1) and a confirmed BLOCKING correctness defect (§19, Blocker #2); its exact field inventory here is PRE-REMEDIATION evidence only, see §12** |
| 4 | `resources/views/admin/workspaces/index.blade.php` | Platform admin | Cross-tenant Workspace list/search, read-only |
| 5 | `resources/views/admin/workspaces/show.blade.php` | Platform admin | Cross-tenant Workspace detail (identity, Businesses, Memberships), read-only — **plus an embedded, out-of-scope Plan & Entitlement / Mutate Plan region, see §5** |
| 6 | `resources/views/admin/businesses/index.blade.php` | Platform admin | Cross-tenant Business list/search |
| 7 | `resources/views/admin/businesses/show.blade.php` | Platform admin | Business detail + status-change form; outbound links to Edit and Usage Billing |
| 8 | `resources/views/admin/businesses/edit.blade.php` | Platform admin | Business profile identity/contact/profile edit form (admin, cross-tenant) — unaffected by either blocker (§19) |

**No partials, fragments, or modals exist for any of these 8 files.** Each is fully self-contained (`@extends('layouts/contentLayoutMaster')` only). The retention audit's own rough "~11-12 files" estimate for this bucket is superseded by this mechanical count of exactly 8 — no separate partial files exist to reconcile the difference; the audit's estimate simply overcounted before file-level verification.

**This 8-file inventory is expected to remain stable through remediation** (§19's fix targets access-control and form/validation *behavior*, not the *existence* of `customer/business/edit.blade.php` as a retained view) but must be mechanically re-confirmed against post-remediation `main` before any future visual implementation task proceeds (§14 of Correction Round 1, folded into §23/§24 below).

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

A future implementation task must re-verify these exact line ranges against its own base commit before editing, since line numbers will drift once other, unrelated commits (including the future nonvisual remediation, §19) land.

**Correction Round 1 clarification — the shared-ancestor-card rule (see §16, §17):** because `customer/workspaces/show.blade.php`'s "Usage & Billing / Platform feature preferences" excluded region (lines 301–366) is *nested inside*, not a sibling of, the in-scope "Businesses" card's own `card-body`, that outer Businesses card's header/body wrapper itself must remain **native** for A2 — converting it to `x-card` would restyle/reflow the excluded descendant region, which this section already prohibits. `admin/workspaces/show.blade.php`'s excluded regions (lines 139–419) are separate sibling `<section>` blocks, not nested inside any in-scope card, so its in-scope cards (lines 1–138) have no such restriction.

### 5.4 Incidental Business/Workspace references — not A2

- `resources/views/customer/onboarding/steps/{business,assets}.blade.php` — part of the A1 onboarding wizard (already redesigned in PR #191), not the retained management UI. Not touched, not re-touched.
- `resources/views/admin/opportunities/runs/index.blade.php` — displays a read-only "Business ID"/"Business name" label identifying which Business an Opportunity run belongs to. Incidental reference, not Business management.

### 5.5 Admin Tenant Management (legacy) — separately excluded

`resources/views/admin/customer/**` (legacy tenant CRUD/impersonation) is excluded per the retention audit's own §7 row 234 ("KEEP BACKEND, REBUILD UI" / "DO NOT DESIGN LEGACY UI"). Not part of A2. Not inspected further here — out of A2's file-search scope entirely (different controller namespace, `Admin\CustomerController`, not `Admin\WorkspaceController`/`Admin\BusinessController`).

---

## 6. Customer Workspace architecture trace

**Model:** `workspaces` table — `id`, unique `uid`, `name`, `owner_user_id` (FK → `users`, `restrictOnDelete()`), `is_active` (bool). Ownership is represented **solely** by `owner_user_id` — an owner is never also a membership row (`OwnerCannotBeMemberException` if attempted).

**Membership:** `workspace_memberships` — `role` (`App\Enums\Workspace\WorkspaceMembershipRole`: `admin`/`staff`, never `owner`), `business_access_scope` (`App\Enums\Workspace\WorkspaceBusinessAccessScope`: `all`/`selected`, no DB default — always explicit), `is_active`, unique `(workspace_id, user_id)`. `workspace_membership_businesses` links a `selected`-scope membership to specific Businesses.

**Domain authority — `App\Library\Workspace\WorkspaceManager`** (`app/Library/Workspace/WorkspaceManager.php`) is the **single centralized authorization seam** for every customer-side Workspace mutation. Every mutating method locks its target row(s) (`findForUpdate()`) inside `DB::transaction()` and calls one of a small set of private assertions:

```php
private function assertActorIsOwner(int $actorUserId, Workspace $workspace): void { /* owner_user_id === actor, else throw UnauthorizedWorkspaceManagementException */ }
private function assertActorIsOwnerOrActiveAdmin(int $actorUserId, Workspace $workspace): void { /* owner, or active membership with role=Admin, else throw */ }
private function assertHasAuthorityOverRole(int $actorUserId, Workspace $workspace, WorkspaceMembershipRole $role): void { /* Admin-role target => owner-only; every other role => owner-or-active-Admin */ }
```

Read-access algorithm — RFC-003 §14.1's `userCanAccessBusiness()`, implemented verbatim in `WorkspaceManager::userCanAccessBusiness()`: a Business is visible to a user only if (a) the Business's Workspace exists **and is active** — an inactive (or, pre-M1B, still-unassigned) Workspace blocks **all** customer-side access to that Business, **including the Business's own direct `customer_id` owner** (RFC-003 §14, §14.1, §22 — "a deliberate behavior change from v1.0, where direct ownership always short-circuited to `true` regardless of Workspace state") — and only once that gate passes, (b) the user is the Business's direct `customer_id` owner, OR the Workspace owner, OR holds an active membership whose scope is `all` or explicitly includes that Business. **`WorkspaceManager::userCanAccessBusiness()` itself correctly implements this rule** — the defect found in Correction Round 1 (§19, Blocker #1) is that the *customer direct Business-profile route* (`BusinessController::edit()`/`update()`, §8) never calls it.

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
| `updateMemberAccess()` | `customer.workspaces.members.access` | **owner-or-active-Admin — not target-role-gated** (§10 correction: distinct from, and less restrictive than, lifecycle authority over an Admin-role member) |
| `deactivateMember()` / `reactivateMember()` | `customer.workspaces.members.deactivate` / `.reactivate` | target-role-dependent via `assertHasAuthorityOverRole()` (owner-only for Admin targets, owner-or-active-Admin for Staff targets) |

**Ownership transfer** (`transferOwnership()`): owner-only; reassigns `owner_user_id`; deactivates the incoming owner's pre-existing membership if any; reconciles the outgoing owner via an explicit caller-chosen disposition — `deactivate` or `convert_to_admin` (with an explicit `all`/`selected` scope). Writes one `workspace_transitions` audit row (`OwnershipTransferred`), dispatches `WorkspaceOwnershipTransferred`. Locks the Workspace, then both owner `users` rows and both membership rows in ascending-ID order, retried up to 3 times for deadlock recovery.

**Business reassignment** (`reassignBusiness()`): locks every distinct Workspace involved (ascending ID) then the Business row, re-verifies the Business's authoritative `workspace_id` against the expected source before proceeding (`BusinessWorkspaceMismatchException` guard). Only `businesses.workspace_id` changes — `customer_id` is never touched (`EloquentBusinessRepository::reassignWorkspace()`'s single-field write). Writes one `workspace_transitions` row (`BusinessReassigned`), dispatches `WorkspaceMembershipBusinessUnassigned` per removed scoped-access grant then `BusinessReassignedToWorkspace`.

**Existing test coverage** (53 files under `tests/Feature/Workspace/`, 4 under `tests/Unit/Workspace/`) covers every action above in depth — `WorkspaceLifecycleTest`, `WorkspaceMutationHttpTest`, `WorkspaceOwnershipTransferTest`/`...HttpTest`, `WorkspaceBusinessOrchestrationTest`, `WorkspaceBusinessReassignmentHttpTest`, `WorkspaceMemberManagementHttpTest`, `WorkspaceMembershipLifecycleTest`, `WorkspaceManagerConcurrencyTest`, `WorkspaceEffectiveAccessTest`, and repository/model/schema-level suites. **None of these cover the customer direct Business-profile route (`BusinessController`)** — that surface has its own, separate test files (§8, §19).

---

## 7. Admin Workspace architecture trace

**Controller — `App\Http\Controllers\Admin\WorkspaceController`** (151 lines) is **read-only** — `index()` and `show()` only, each gated by `$this->authorize('view workspace')` (a generic `config('permissions')` → `Gate::define()` → `AccountRepository::hasPermission()` entry, the same mechanism every other admin module uses). No `WorkspaceManager` dependency at all; never mutates; never consults owner/membership/scope. Sits inside `Route::middleware(EnsureUserIsAdministrator::class)` (checks `users.is_admin`), itself nested inside the outer admin route group's `['web','auth','can:access backend','ValidProduct','twofactor']`.

**No create/rename/deactivate/reactivate/member-management/Business-management/ownership-transfer route exists on the admin side at all** — confirmed by the admin route file's own explicit comment and by the exhaustive route grep in §4. The admin Workspace surface is strictly cross-tenant inspection, and is **not implicated by either blocker in §19** (both are customer-side findings).

There is **no dedicated `WorkspacePolicy` class** (`app/Policies/` contains only `UserPolicy.php`). Admin authorization is the generic Gate-permission mechanism (`view workspace`), entirely independent of, and never overlapping with, the customer-side `WorkspaceManager` authority model in §6. This is two coherent, purpose-specific seams, not one universal Policy and not scattered ad hoc checks. Per RFC-003 §14 path 1 and §22's own security invariant: *"Platform-admin behavior remains governed by existing backend authorization"* — entirely outside, and never constrained by, the customer-side remediation this contract requires (§19).

**Existing test coverage:** `tests/Feature/Workspace/AdminWorkspaceControllerTest.php` (36 tests) — read-only index/show, cross-tenant inspection, `EnsureUserIsAdministrator` boundary, confirms no mutation route exists.

(`admin.workspace-plan-catalog.*` and `admin.workspaces.plan.*`/`admin.workspaces.entitlement-overrides.*` routes exist and are gated by `EnsureUserIsAdministrator` + `view workspace plans`/`manage workspace plans` Gate permissions — these back the excluded region in §5.3 and are out of A2's authorization trace.)

---

## 8. Customer Business architecture trace

**View:** `customer/business/edit.blade.php` → `route('customer.business.update')`.

**Route** (`routes/customer.php:522-525`): `GET business` → `BusinessController@edit` (`customer.business.edit`); `PUT business` → `BusinessController@update` (`customer.business.update`).

**Controller — `App\Http\Controllers\Customer\BusinessController`**: `edit()` resolves the customer's primary Business via `findPrimaryByCustomer($customer->user_id)` (redirects to onboarding if none exists, per RFC-001 §19). `update()` calls `BusinessManager::updateBusiness($customer, $business, $request->validated())`, which independently re-asserts direct ownership (`assertOwnership($customer, $business)` — checks only `(int) $business->customer_id !== (int) $customer->user_id`, throwing `AuthorizationException` on mismatch) before delegating to the repository.

> ### ⚠ BLOCKING FINDING — Blocker #1 (§19): inactive-Workspace access gate never applied on this route
>
> Mechanically confirmed by direct code read: **neither `BusinessController::edit()`/`update()` nor `BusinessManager::updateBusiness()` calls `WorkspaceManager::userCanAccessBusiness()` / `assertUserCanAccessBusiness()`, or otherwise checks the Business's Workspace `is_active` state, at any point.** `assertOwnership()`'s entire check is the one line quoted above — it never inspects `businesses.workspace_id` or the Workspace it points to. RFC-003 §14/§14.1/§22 (§6 above, quoted verbatim) is explicit and unambiguous that direct Business ownership must be gated behind Workspace-active state exactly like every other access path, and that this was **a deliberate v1.0→v1.1+ correction**, not an optional refinement. `WorkspaceManager::userCanAccessBusiness()` itself correctly implements this rule and is used correctly elsewhere (the Workspace show/Business-listing paths, §6) — this route alone was never wired to it. See §19 for the full classification and required remediation outcomes (architecture intentionally not prescribed here).

**FormRequest — `App\Http\Requests\Business\UpdateBusinessRequest`**, whose own docblock states the exact field-scope contract: *"May update identity/contact/profile fields only — never `customer_id`, `is_primary`, `canonical_domain`, `status`, or `activated_at` (RFC-001 §17). Those protections are enforced by `BusinessRepository`, not this request."*

**Fields validated by this FormRequest** (i.e. potentially accepted, regardless of what the current view renders — see §12 for the exact PRE-REMEDIATION rendered-field inventory): `name`, `industry`, `industry_other` (`required_if:industry,other`), `description`, `email`, `phone`, `website_url`, `google_business_profile_url`, `facebook_url`, `instagram_url`, `country_code`, `timezone`, `currency_code`.

> ### ⚠ BLOCKING FINDING — Blocker #2 (§19): customer form cannot satisfy its own request's `industry_other` requirement
>
> Mechanically confirmed: `customer/business/edit.blade.php`'s industry `<select>` loops over **every** `BusinessIndustry::cases()`, including `Other = 'other'` (`app/Enums/Business/BusinessIndustry.php`), so `Other` is a selectable option — but the same view contains **zero** `industry_other` field (grepped, zero matches), while `UpdateBusinessRequest::rules()` makes `industry_other` `required_if:industry,other`. A customer who selects "Other" therefore cannot produce a valid submission through the rendered form at all: every attempt fails validation and redirects back with an error the customer has no rendered field to resolve. The admin edit view (`admin/businesses/edit.blade.php`) *does* render `industry_other` and is unaffected. See §19 for classification and required remediation outcomes (UI/controller/request architecture intentionally not prescribed here).

Protected (never listed in `rules()`, so `$request->validated()` never contains them regardless of submitted payload): `customer_id`, `is_primary`, `canonical_domain`, `status`, `activated_at`. `workspace_id` is likewise never in `rules()` — see §19's separate hardening note (Finding #10, nonblocking).

**Repository defense-in-depth — `EloquentBusinessRepository::update()`**:
```php
$attributes = Arr::except($attributes, ['customer_id', 'is_primary', 'canonical_domain', 'status', 'activated_at']);
```

**Existing test coverage:** `tests/Feature/Business/BusinessManagerTest.php`, `BusinessRepositoryTest.php` (including the field-exception protection), `BusinessOnboardingHttpTest.php` for the related onboarding-time Business creation path (A1 scope, already covered). **None of these exercise an inactive-Workspace scenario against `BusinessController::edit()`/`update()`, nor an `industry=other` submission through the customer form** — confirmed by a targeted search of both test files for `is_active`/`workspace` and `industry_other`/`Other` respectively; this is itself further evidence the gap was never covered, not merely undocumented.

---

## 9. Admin Business architecture trace

**Routes** (`routes/admin.php:596-600`, inside `Route::middleware(EnsureUserIsAdministrator::class)`): `Route::resource('businesses', 'BusinessController', ['only' => ['index','show','edit','update']])` + `Route::patch('businesses/{business}/status', ...)->name('businesses.status.update')`. **No create or delete admin route exists** — the route file's own comment states this is intentional ("the RFC does not permit either from the admin surface").

**Controller — `App\Http\Controllers\Admin\BusinessController`**: `index()`/`show()`/`edit()` gated by `$this->authorize('view business')` / `$this->authorize('edit business')`; cross-tenant by route-model binding (no ownership filter — this is intentional, documented in-code: *"The business's own owning customer is resolved from the record itself, never from the authenticated admin... the check trivially passes."*). `update()` delegates to the same `BusinessManager::updateBusiness()` used by the customer path — **platform-admin access is governed entirely upstream of `BusinessManager`/`WorkspaceManager`** (RFC-003 §14 path 1, quoted in §7), so admin's cross-tenant `update()` capability is intentionally unaffected by Blocker #1's customer-side remediation; this must remain true after remediation lands (§19 outcome list). `updateStatus()` is a **separate**, dedicated mutator (`BusinessRepository::updateStatus()`, bypassing the field-exception `update()` path since it only ever writes `status`).

**FormRequest — `App\Http\Requests\Business\AdminUpdateBusinessRequest`** — field-for-field identical rule set to the customer `UpdateBusinessRequest` (its own docblock: *"Mirrors the same accepted identity/contact/profile fields... never `customer_id`, `canonical_domain`, `is_primary`, `status`, `activated_at`, `created_at`, or `updated_at`"*). Unlike the customer view, `admin/businesses/edit.blade.php` **does render** an `industry_other` `<input>` — confirmed by direct grep — so admin is **not** affected by Blocker #2.

**What admin can do that the customer form cannot:** cross-tenant access to any Business (no ownership scoping); a separate `status` mutator (`UpdateBusinessStatusRequest` + `updateStatus()`, `admin/businesses/show.blade.php`'s `@can('edit business')`-gated "Change status" form, `BusinessStatus::cases()` = `draft`/`active`/`inactive`). **Nothing admin can do via these three views changes `workspace_id`, `customer_id`, `is_primary`, `canonical_domain`, or `activated_at`.**

**Permissions** (`config/permissions.php`): `view business`, `edit business` (category `Business`).

**Existing test coverage:** `tests/Feature/Business/AdminBusinessControllerTest.php` — admin cross-tenant index/show/edit/update/status HTTP coverage including the `EnsureUserIsAdministrator`/Gate authorization boundary.

---

## 10. Account-role / permission matrix

**Correction Round 1 fix:** the initial draft contradicted itself on Workspace Admin's business-access-scope authority (one cell implied Admin could change any member's scope; another implied Admin could never touch an Admin-role member's scope). Mechanically re-read `WorkspaceManager::changeMemberBusinessAccessScope()` directly (§6): it calls only `assertActorIsOwnerOrActiveAdmin($actorUserId, $lockedWorkspace)` — **it is not target-role-gated at all**, unlike `deactivateMember()`/`reactivateMember()`/`addMember()` (Admin-role target), which all route through `assertHasAuthorityOverRole()` and require owner authority specifically when the *target* is (or would become) an Admin. Business-access-scope authority and lifecycle/role authority over an Admin-role member are **two independent rules** — the corrected matrix below reflects this precisely, and separately documents the view-level `$viewerCanSeeMembersCompleteAccess` presentation safeguard, which is a UI/data-visibility condition layered on top of (not a restatement of) the domain-level authority.

| Role | How determined | Workspace(s) visible | Business(es) visible | Owner-only actions | Owner-or-Admin actions | Never available |
|---|---|---|---|---|---|---|
| **Workspace Owner** (customer) | `workspace.owner_user_id === user.id` | Every Workspace they own | Every Business in their owned, **active** Workspace(s), regardless of scope (Business access is denied even to the owner while the Workspace is inactive, §6) | Deactivate/reactivate Workspace; ownership transfer; add/change-role of an Admin member; deactivate/reactivate an Admin member | Rename; create Business; reassign Business; add a Staff member; change **any** member's business-access scope (including an Admin member's) | — |
| **Workspace Admin** (active membership, `role=admin`) | active `workspace_memberships` row, `role=Admin` | Workspaces where actively an Admin member | Businesses per their own `business_access_scope` (`all` or `selected`), in an active Workspace | — | Rename; create Business; reassign Business (needs owner-or-Admin on **both** Workspaces); add/deactivate/reactivate a Staff member; **change any member's business-access scope, including an Admin member's own scope** (`changeMemberBusinessAccessScope()` is owner-or-active-Admin authority, not target-role-gated) | Deactivate/reactivate the Workspace; transfer ownership; change any member's **role**; add, deactivate, or reactivate an **Admin** member (all three are target-role-gated to owner-only via `assertHasAuthorityOverRole()`) |
| **Workspace Staff** (active membership, `role=staff`) | active `workspace_memberships` row, `role=Staff` | Workspaces where actively a Staff member | Businesses per their own `business_access_scope`, in an active Workspace | — | — | Every mutation form on `customer/workspaces/show.blade.php` is hidden by `@if (in_array($workspace['role'], ['Owner','Admin'], true))` — Staff sees a strictly read-only view |
| **Business-scoped user** (direct `businesses.customer_id` ownership) | `business.customer_id === user.id`, independent of any Workspace role | N/A (this axis is Business-direct, not Workspace-mediated) | **Pre-remediation:** their own directly-owned Business(es), unconditionally — this is Blocker #1 (§19): the RFC-003-required Workspace-active gate is not applied here. **Post-remediation (required outcome):** their own directly-owned Business(es), only while that Business's Workspace is active | — | Edit their own primary Business profile (`customer/business/edit.blade.php`) — **subject to Blocker #1's fix once remediated** | Anything Workspace-scoped unless they also separately hold a Workspace role |
| **Platform admin** | `users.is_admin` + `EnsureUserIsAdministrator` + `view workspace`/`view business`/`edit business` Gate permissions | Every Workspace, cross-tenant, read-only | Every Business, cross-tenant, read + identity/profile edit + status change — governed entirely upstream of the Workspace-active gate (RFC-003 §14 path 1) and **not affected by either blocker** | — | — | Create/rename/deactivate/reactivate a Workspace; ownership transfer; any member-management action; Business creation or reassignment — **no such admin route exists at all** |

Per `AGENTS.md`'s own documented Workspace-authorization rule, independently confirmed against code: *"Workspace ownership, active membership, direct Business ownership, `users.parent_id`, and platform-admin access are separate authorization paths. Do not allow one to silently imply another. Owner role wins over an anomalous coexisting membership row. Inactive membership grants no Workspace-derived access. Business scope (`all`/`selected`) is independent from member role (`admin`/`staff`)."* This matches `WorkspaceManager::userCanAccessBusiness()`'s exact algorithm in §6 precisely — but, per Blocker #1, that algorithm is not yet consulted by the direct Business-profile route.

**Deactivated Workspace:** blocks all customer-side Workspace mutation (`renameWorkspace()`'s active-Workspace check, `changeMemberBusinessAccessScope()`'s own `InactiveWorkspaceMutationException` guard) and blocks all customer-side Business access **even for the owner**, via `userCanAccessBusiness()`, wherever that function is actually consulted — which, pre-remediation, excludes the direct Business-profile route (Blocker #1).

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
7. Enable/disable platform feature preference (`data-business-action="features/{key}/enable|disable"`, one per Business × feature row) — Owner/Admin only; **inside the §5.3-excluded region**
8. Add member (`data-workspace-action="members"`) — Owner/Admin only; Admin role option itself hidden unless viewer is Owner
9. Change member role (`data-member-action="role"`, per member row) — Owner only (rendered only if `$viewerIsOwner`)
10. Update member business access (`data-member-action="access"`, per member row) — **domain authority is owner-or-active-Admin (§6, §10), gated for rendering only if `$viewerCanSeeMembersCompleteAccess`** (a stricter view-level data-visibility condition, not a restatement of the domain authority — see §10's correction)
11. Deactivate member (`data-member-action="deactivate"`, per member row) — rendered only if `$viewerCanManageLifecycle`
12. Reactivate member (`data-member-action="reactivate"`, per member row) — rendered only if `$viewerCanManageLifecycle`

**Hidden/hidden-by-role wiring:** every mutation form/section above is wrapped in a PHP-side `@if` role check (§10) — this is not merely CSS-hidden, it is never rendered to a Staff member's HTML at all.

**JS handlers (one `<script>` block, lines 518–598, rendered only when `in_array($workspace['role'], ['Owner','Admin'])`):**
- Dynamically sets every `form[data-workspace-action]`'s `action` attribute from `window.location.pathname` at page load (no static `action=` on these forms in the HTML source — this is a genuine, deliberate architectural pattern, not an oversight).
- Same pattern for `form[data-member-action]` (path includes `/members/{memberUid}/...`) and `form[data-business-action]` / `a[data-business-action]` (path includes `/businesses/{businessUid}/...`).
- A `business_access_scope` select → checkbox-sync handler: disables and unchecks all `business_uids[]` checkboxes in the same form when scope is switched to `all`.
- A `previous_owner_disposition` select → admin-fields-visibility handler: shows/hides the `data-ownership-transfer-admin-fields` block and toggles its inner scope select's `disabled` state based on whether `convert_to_admin` is selected.

**Confirmation flows:** none — every mutation (including Deactivate Workspace and Transfer ownership) submits immediately on click, no client-side confirm dialog exists today. Not to be added as new functionality by a future visual pass without separate authorization (would be new behavior, not restyle).

**Tables:** Reassign-target table (Business × target-Workspace-select), effective Business list, platform-feature-preference table (per Business, one `<h6>` + `<table>` pair — inside the §5.3-excluded region), member directory table.

**Dialogs/modals/menus/tabs:** none present anywhere in this file today.

**Hidden inputs:** none beyond the CSRF token each `@csrf` directive emits — no other hidden fields.

**Non-form dynamic markup:** `dl`/`dt`/`dd` status/role/tier definition lists (both the Workspace-overview card and, when `$entitlement` is set, the out-of-scope Plan & Capacity card).

**Repeated backend field names on this single page (Correction Round 1 addition, see §16/§17):** `name` (Workspace-rename form + Business-create form, distinct original `id`s, same `name=`), `target_workspace_uid` (one `<select>` per manageable Business row inside a loop), `role` (add-member form + one `<select name="role">` per member row inside a loop, the per-row instances carrying no explicit `id` in the source today), `business_access_scope` (ownership-transfer form + add-member form + one `<select name="business_access_scope">` per member row inside a loop, the per-row instances likewise carrying no explicit `id` today). These four field names each appear more than once in this file's rendered output and are the basis for the native-control carve-outs in §16/§17.

---

## 12. Business behavior inventory

**`customer/business/edit.blade.php`** (105 lines): a single form, one `PUT` submission (`@csrf` + `@method('PUT')`), `old()`-bound values, a session-status success alert and a validation-error alert. No JS, no tables, no dialogs, no hidden inputs beyond CSRF.

**Correction Round 1 fix — exact field count.** Mechanically recounted (`grep -c '<input\|<select\|<textarea'` = 12): **10 `<input>` controls, 1 `<select>` (industry), 1 `<textarea>` (description) — 12 controls total, and zero `industry_other` field** (confirmed by a dedicated grep returning no matches). The original draft's "11 `<input>`" claim was an arithmetic error (11+1+1 ≠ 12) and its "editable via this form" list in §8 incorrectly included `industry_other`, which the rendered view does not contain. **These are PRE-REMEDIATION findings.** Because Blocker #2's remediation (§19) may legitimately change this form's field set (most plausibly by adding an `industry_other` field, though the exact shape is intentionally not prescribed here), this exact count must be re-measured mechanically from post-remediation `main` before any future A2 visual contract/test finalizes its own exact adoption/marker counts (§21, §23).

**`admin/businesses/edit.blade.php`** (119 lines): same 12-control field set **plus** `industry_other` (13 controls total; admin-only field today, not present on the customer form — see Blocker #2, §19), one `PUT` submission, one "Cancel" link back to `admin.businesses.show`. No JS. Unaffected by either blocker.

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

- **`x-card`**: `title` (nullable), `padded` (default `true`). **No `subtitle` prop, no way to place secondary header content without either an `actions` slot (only rendered next to the title in the header row) or pushing it into the body slot below the header** — see §16/§17 for the `admin/businesses/edit.blade.php` non-adoption this causes.
- **`x-alert`**: `variant` (`neutral|accent|success|warning|danger`), `icon` (nullable), `dismissible` (default `false`).
- **`x-button`**: `variant` (`primary|secondary|outline|ghost|danger` — **no `success`/`warning` variant, and no `outline-danger`/`outline-warning`/`outline-success` sub-variant**), `size` (`sm|md|lg`), `type`, `href`, `icon`, `disabled`.
- **`x-input`** / **`x-select`**: unchanged from A1's record — `label`, `name`, `type`/`options`, forwarding via `$attributes->merge()`. **Both hardwire `id="{{ $name }}"` on the rendered control and expose no separate, first-class `id` prop** — see §16/§17 for the repeated-backend-field-name carve-outs this requires. `x-select` has no `error` prop (confirmed still absent).
- **`x-empty-state`**: `icon` (default `inbox`), `title` (required), `description` (nullable). Renders a block-level `<div>` — **not valid as a direct `<tbody>` child** (see §16/§17).
- **`x-badge`** *(not used by A1 — new to this audit)*: `variant` (`neutral|accent|success|warning|danger`) → renders `<span class="badge rounded-pill {bg-light-*} text-caption fw-medium">`. Directly matches the current hand-rolled `<span class="badge badge-light-success">Active</span>` / `badge-light-secondary` / `badge-light-danger` pattern used throughout all 8 views — **outside the §5.3-excluded regions only** (see §16/§17).
- **`x-table`** *(not used by A1)*: `headers` (array, optional) → wraps `<table class="table ds-table align-middle">` in its own `.table-responsive` div, `{{ $slot }}` for `<tbody>` rows. Directly matches every `<table class="table">`/`<table class="table table-hover">` in the 8 views — **outside the §5.3-excluded regions only**.
- **`x-pagination`** *(not used by A1)*: takes a `paginator` prop, renders a "Showing X to Y of Z" caption + prev/current/next controls using `<x-ds-icon>` chevrons. Matches the `{{ $paginator->appends(...)->links() }}` calls in `admin/businesses/index.blade.php` / `admin/workspaces/index.blade.php`, with one adaptation needed at implementation time: the paginator instance passed to `<x-pagination :paginator="...">` must already have `->appends(...)` applied to it with its **exact existing filter set** (the component itself does not call `appends()`) — see §16, §21.
- **`x-dialog`**, **`x-menu`**, **`x-tooltip`**, **`x-tabs`**: exist in the library but have **no current pattern to adopt to** in any of the 8 views (no modals, dropdown menus, tooltips, or tab groups exist today). Available for a *future*, separately-authorized structural redesign (e.g., using `x-tabs` to reorganize the very dense Workspace show pages into Overview/Businesses/Members panels) — **not authorized by this contract**, since that would be new visual structure, not a restyle, and A2's design intent (§20) explicitly limits scope to making the existing hierarchy clearer, not adding new navigation patterns.
- **`x-switch-toggle`**: inspected and explicitly rejected as a candidate — it is a legacy, non-`@props()`-based component that itself still emits `data-feather` icons internally, i.e. it is not Design-System-clean. Not relevant to any A2 pattern in any case (no toggle-switch UI exists in these 8 views).

---

## 16. Exact component-adoption matrix

Patterns are described by rule (adopt/native), since a future implementation task will produce the exact per-file literal counts (mirroring A1's own two-phase precedent: this contract locks the *rule*, the implementation phase's own DS content/adoption tests lock the *exact count*). **Correction Round 1 replaces several blanket "adopt everywhere" claims from the initial draft with precise, mechanically-derived carve-outs** — see the inline notes below and §17's summary.

| Pattern | Where it appears | Adopt? | Component | Notes |
|---|---|---|---|---|
| `.card` / `.card-header` + `.card-body` section wrapper, **simple title-only or title+actions-slot header, with no excluded descendant nested inside** | `customer/workspaces/index.blade.php`; `customer/business/edit.blade.php`; the Workspace-overview card in `customer/workspaces/show.blade.php` (lines ~33-120, contains only in-scope rename/deactivate/reactivate/transfer forms, nothing from §5.3); the Members card in `customer/workspaces/show.blade.php` (lines ~372-515, no excluded content); all 3 in-scope cards in `admin/workspaces/show.blade.php` (title-only headers, no excluded descendant — the excluded regions are sibling `<section>`s, §5.3); `admin/workspaces/index.blade.php`; `admin/businesses/index.blade.php`; `admin/businesses/show.blade.php` (title + a 2-button `actions` slot fits `x-card`'s `actions` slot exactly) | **Adopt** | `x-card` | `title` prop for the header text; `actions` slot where a header button pair exists (`admin/businesses/show.blade.php`); content becomes the default slot |
| `.card` / `.card-header` + `.card-body` wrapper for the **"Businesses" card in `customer/workspaces/show.blade.php`** (lines ~175-369) | `customer/workspaces/show.blade.php` | **Do not adopt — stays native** | — | This card's own body directly nests the §5.3-excluded "Usage & Billing / Platform feature preferences" region (lines 301–366). Converting the card's own header/body wrapper to `x-card` would restyle/reflow that excluded descendant, which §5.3 prohibits. The in-scope content inside this card (create-Business form, Business list, reassign table) still individually adopts `x-input`/`x-select`/`x-button`/`x-table` per their own rows below — only this card's own outer frame stays native |
| `.card-header` containing **both a title and a distinct subtitle line** | `admin/businesses/edit.blade.php` (title `Edit {{ $business->name }}` + a separate `<p class="text-muted">Owner: ...</p>` subtitle, both inside the header) | **Do not adopt — stays native** | — | `x-card` has no `subtitle` prop (§15); representing the Owner line via the `actions` slot would misplace it visually (that slot renders inline with the title, not as a second line), and passing it as ordinary body-slot content would move it out of the header entirely — a structural change, not a restyle. Classified as intentional native non-adoption rather than a forced/lossy adoption. The form fields inside individually still adopt `x-input`/`x-select` per their own rows |
| `alert-success` / `alert-danger` (session flash, validation errors) | All 8 views except the two admin index pages, **outside the §5.3-excluded regions** | **Adopt** | `x-alert` | `variant="success"`/`variant="danger"` |
| `<span class="badge badge-light-{success,secondary,danger}">` status pills | Active/Inactive Workspace badge (`customer/workspaces/index.blade.php`, `customer/workspaces/show.blade.php`'s Workspace-overview card, `admin/workspaces/index.blade.php`, `admin/workspaces/show.blade.php`'s in-scope Workspace/Membership cards), Active/Inactive membership badge (`customer/workspaces/show.blade.php` Members card, `admin/workspaces/show.blade.php` Memberships table) | **Adopt** | `x-badge` | `variant="success"` (Active), `variant="neutral"` (Inactive) |
| `<span class="badge ...">` Allowed/Denied entitlement decision, Workspace-override badges | `customer/workspaces/show.blade.php` lines 301–366, `admin/workspaces/show.blade.php` lines 139–419 | **Do not adopt — stays native** | — | Entirely inside the §5.3-excluded regions; no A2 adoption of any kind is authorized there |
| `<table class="table">` / `table-hover` / `table-sm` result/detail tables | `customer/workspaces/index.blade.php` (Workspace list), `admin/workspaces/index.blade.php` / `admin/workspaces/show.blade.php`'s in-scope Businesses/Memberships tables, `admin/businesses/index.blade.php`, `customer/workspaces/show.blade.php`'s effective-Business-list table, reassign-target table, and member-directory table | **Adopt** | `x-table` | `headers` prop for the `<thead>` row; body rows stay as native `<tr>`/`<td>` inside the slot |
| Platform-feature-preference `<table class="table table-sm">` | `customer/workspaces/show.blade.php` lines 301–366 | **Do not adopt — stays native** | — | Entirely inside the §5.3-excluded region |
| `{{ $paginator->appends(...)->links() }}` | `admin/businesses/index.blade.php` (`$businesses->appends(array_filter($filters))`), `admin/workspaces/index.blade.php` (`$workspaces->appends(array_filter($filters, fn ($value) => $value !== null))`) | **Adopt** | `x-pagination` | The paginator instance passed to `<x-pagination :paginator="...">` **must retain the exact existing `->appends(...)` call with its exact existing filter-preservation expression** for each of the two pages (quoted verbatim above) — losing search/status/industry/is_active filters across a page change would be a behavior regression, not a restyle |
| "No X found" / "You don't have access..." empty states **rendered as a full block replacing a table entirely (an `@if/@else` around the whole table, not inside a `<tbody>`)** | `customer/workspaces/index.blade.php` (no Workspaces), `customer/workspaces/show.blade.php`'s Businesses card (no accessible Businesses) and Members card (no members), `admin/workspaces/show.blade.php`'s in-scope Businesses/Memberships cards (empty states) | **Adopt** | `x-empty-state` | `icon="inbox"` per A1's locked precedent value; safe here because these empty states replace the entire table block via `@if/@else`, never becoming a `<tbody>` child |
| "No X found" `<tr><td colspan="...">` row **inside a `@forelse/@empty` block that always renders inside `<tbody>`** | `admin/businesses/index.blade.php`, `admin/workspaces/index.blade.php` | **Adopt, with a locked structural rule** | `x-empty-state` | `<x-empty-state>` must never become a direct `<tbody>` child (it renders a block `<div>`, invalid table content). The existing `<tr><td colspan="...">...</td></tr>` wrapper **must be preserved**, with `<x-empty-state icon="inbox" .../>` placed **inside** the `<td>` — i.e. `<tbody><tr><td colspan="N"><x-empty-state ... /></td></tr></tbody>`, never `<tbody><x-empty-state ... /></tbody>` |
| `<button class="btn btn-primary">` / `btn-outline-primary` / `btn-outline-secondary` submit/action buttons whose color maps to an existing `x-button` variant | Create Workspace, Save changes (both edit forms), Filter (both admin index forms), Rename, Create Business, Reassign, Add member, Change role, Update access, Edit (admin Business show), Usage Billing link (admin Business show, restyling the button itself only — never its destination), Update status, Cancel | **Adopt** | `x-button` | `variant="primary"` / `variant="outline"` / `variant="secondary"` per current class; `size="sm"` where `btn-sm` is present today |
| `<button class="btn btn-outline-secondary">` Record/Remove disable-preference buttons | `customer/workspaces/show.blade.php` lines 301–366 | **Do not adopt — stays native** | — | Entirely inside the §5.3-excluded region — corrected from the initial draft, which incorrectly listed these as adoptable |
| `<button class="btn btn-outline-{danger,warning,success}">` | Deactivate Workspace, Reactivate Workspace, Transfer ownership, Deactivate member, Reactivate member | **Do not adopt — stays native** | — | `x-button` has no `outline-danger`/`outline-warning`/`outline-success` sub-variant (only solid `variant="danger"` and generic `variant="outline"` which maps to `btn-outline-primary`); adopting would silently change these buttons' color semantics. Same category of confirmed component-API gap as A1's `btn-success` "Finish setup" finding — component extension, if ever wanted, is a separate, explicitly-authorized change, never invented ad hoc during a restyle |
| `<input type="text|email|number">` with a `<label>`, **where the backend field `name` is unique within its own rendered page** | Every identity/profile/filter field across all 8 views **except** the repeated-name cases below | **Adopt** | `x-input` | Forwarding-only pattern, identical to A1; renders `id="{{ $name }}"` — safe only where that would not collide with another control's id on the same page |
| Native `<input>` where the backend field `name` is **not** unique within its own rendered page | `customer/workspaces/show.blade.php`: the `name` field (Workspace-rename form **and** Business-create form both use `name="name"`, with distinct original `id`s `workspace-rename`/`business-name`) | **Do not adopt — stays native** | — | `x-input` hardwires `id="{{ $name }}"` with no separate `id` prop (§15); adopting both would render two controls with the same `id="name"` — a duplicate-ID regression the original markup avoided by using distinct explicit `id`s. Do not rename either backend field to work around this; a component `id`-override prop, if ever wanted, is a separate authorized change |
| `<select>` with `<option>` children, **where the backend field `name` is unique within its own rendered page and not repeated inside a loop** | `industry` (business-create form in `customer/workspaces/show.blade.php`; `customer/business/edit.blade.php`; `admin/businesses/edit.blade.php`); `status`/`industry` filters (`admin/businesses/index.blade.php`); `is_active` filter (`admin/workspaces/index.blade.php`); `previous_owner_disposition` (ownership-transfer form, single instance) | **Adopt** | `x-select` | `options` as a `value => label` map, built via `collect(...)->mapWithKeys(...)` for enum-backed selects (A1's precedent) or a plain array literal for small non-enum option sets |
| `<select>` where the backend field `name` is **repeated on the same rendered page**, including inside a loop | `customer/workspaces/show.blade.php`: `target_workspace_uid` (one `<select>` per manageable Business row inside `@foreach`), `role` (add-member form's `#member-role` **and** one per-member-row `<select name="role">` inside `@foreach`, the latter with no explicit `id` today), `business_access_scope` (ownership-transfer form's `#ownership-transfer-scope`, add-member form's `#member-scope`, **and** one per-member-row `<select name="business_access_scope">` inside `@foreach`, the latter with no explicit `id` today) | **Do not adopt — stays native** | — | Same hardwired-`id` gap as `x-input` above, made worse by the loop cases: blind adoption would emit N controls sharing one `id`, both across the distinct-context instances (e.g. `#member-role` vs. a per-row role select) and across loop iterations themselves. Corrects the initial draft's blanket "all 14 selects adopt `x-select`" claim, which did not account for this |
| **All 14 distinct source `<select>` tags adopting `x-select` as a blanket rule** | — | **Superseded — see the two rows immediately above** | — | The initial draft's blanket claim is withdrawn; the exact safe/native split must be re-derived from post-remediation `main` before a future visual/test task locks final counts (§21) |
| `<textarea>` (Business description) | `customer/business/edit.blade.php`, `admin/businesses/edit.blade.php` | **Do not adopt — stays native** | — | No `x-textarea` component exists (same confirmed gap as A1) |
| `<input type="checkbox">` (per-Business assignment checklists, ownership-transfer scope checklist) | `customer/workspaces/show.blade.php`, multiple locations | **Do not adopt — stays native** | — | No checkbox component exists (same confirmed gap as A1) |
| `<dl>`/`<dt>`/`<dd>` definition-list detail blocks | Workspace overview (customer + admin), Business detail (admin show) | **Do not adopt — stays native**, wrapped inside an adopted (or, per the two rows above, native) `x-card`/`.card` | — | No dedicated "description list" / key-value component exists in the library; manufacturing one is out of scope (no speculative component creation) |
| `<a href="...">` plain text links (View/Edit in admin index tables, "Back to Workspaces") | Admin index tables, Workspace show breadcrumb link | **Do not adopt** | — | Not styled as buttons (no `.btn` class) — inline text links, not an `x-button` pattern; remain plain where currently plain |

---

## 17. Intentional non-adoptions (summary)

1. `outline-danger`/`outline-warning`/`outline-success` buttons — component variant gap (§16).
2. Native `<textarea>` — no `x-textarea` component.
3. Native `<input type="checkbox">` — no checkbox component.
4. Native `<dl>`/`<dt>`/`<dd>` — no key-value/description-list component.
5. Plain, unstyled `<a>` text links — not a button pattern, nothing to adopt.
6. `x-dialog`/`x-menu`/`x-tooltip`/`x-tabs` — no existing pattern in these 8 views to adopt them to; not authorized to invent new structure using them.
7. `x-switch-toggle` — rejected outright as not Design-System-clean (embeds its own `data-feather` icons) and has no matching pattern here regardless.
8. **(Correction Round 1)** Every marker, button, table, and card **inside** the §5.3-excluded regions — no `x-badge`, `x-table`, `x-button`, or `x-card` adoption of any kind for the entitlement-decision badges, the platform-feature-preference table, the Record/Remove-disable-preference buttons, or the Plan & Capacity / Plan & Entitlement / Mutate Plan cards themselves.
9. **(Correction Round 1)** The "Businesses" card's own outer `.card`/header/body wrapper in `customer/workspaces/show.blade.php` — stays native because it directly nests an excluded region (§16); only its own outer frame is native, its in-scope inner content still adopts per §16's other rows.
10. **(Correction Round 1)** The `admin/businesses/edit.blade.php` card header (title + Owner subtitle) — stays native; `x-card` has no subtitle representation that preserves the existing header layout (§15, §16).
11. **(Correction Round 1)** Every native `<input>`/`<select>` whose backend field `name` repeats on the same rendered page, including every loop-repeated `<select>` — `x-input`/`x-select` hardwire `id="{{ $name }}"` with no override, so blind adoption would create duplicate DOM ids (§16). Specifically: the `name` field in `customer/workspaces/show.blade.php`'s Workspace-rename and Business-create forms; `target_workspace_uid`; `role` (both the add-member field and every per-member-row field); `business_access_scope` (the ownership-transfer field, the add-member field, and every per-member-row field).

None of these are edited to force an adoption, and no shared component (`x-card`, `x-input`, `x-select`, `x-button`, `x-badge`, `x-table`, `x-empty-state`, or any other) is modified by this contract's authorized scope. This mirrors A1's own governing rule exactly.

---

## 18. Accessibility preservation

To be preserved unmodified by any future visual pass:

- Every `<label for="...">` / input `id` association — automatically carried forward by `x-input`/`x-select` adoption where safe to adopt (each renders `id="{{ $name }}"` and `<label for="{{ $name }}">` when a `label` prop is passed, matching A1's precedent); for the repeated-field-name controls that remain native (§16, §17), their **existing, already-distinct `id` attributes** (e.g. `workspace-rename` vs. `business-name`) must be preserved exactly, since that distinctness is precisely what avoids the collision a blind component adoption would introduce.
- `role="navigation" aria-label="Pagination"` already present inside `x-pagination` itself — no extra work needed once adopted, alongside preserving its `->appends(...)` filter chain (§16).
- No current `aria-live`/`role="status"` region exists in any of the 8 views (unlike A1's analysis-polling screen) — nothing to preserve on that front, and none should be invented as new functionality.
- Native, keyboard-operable controls (`<select>`, `<input type="checkbox">`, `<button>`) must remain native where non-adopted (§17) — never converted to `<div>`-based custom widgets.
- The member-management table's conditional action rendering (`$viewerIsOwner`, `$viewerCanManageLifecycle`, `$viewerCanSeeMembersCompleteAccess`) must continue to omit forms from the DOM entirely when the viewer lacks authority — not merely visually hide them — preserving today's defense-in-depth posture where the HTML itself never discloses a control the viewer cannot use. Per §10's correction, `$viewerCanSeeMembersCompleteAccess` is a presentation/data-visibility gate, layered on top of (not equivalent to) `changeMemberBusinessAccessScope()`'s own owner-or-active-Admin domain authority — both layers must survive any future visual pass independently.

---

## 19. Security / correctness pre-audit

Dedicated pre-audit performed against real code (two independent Explore passes plus direct reading of all 8 in-scope views), covering: IDOR/cross-Workspace/cross-Business access, request-trusted Workspace/Business IDs, missing authorization on mutation routes, member role escalation, scope escalation, owner removal, ownership-transfer invariant bypass, Business reassignment bypass, deactivated-Workspace mutation, capacity-enforcement bypass, raw exception leakage, CSRF/method mismatches, unsafe mass assignment, admin/customer boundary leakage.

**Correction Round 1 re-audit** additionally, mechanically re-read RFC-003 §14/§14.1/§22, `BusinessController`, `BusinessManager`, `WorkspaceManager`, `BusinessIndustry`, `UpdateBusinessRequest`, and both Business edit views, and found two real blocking prerequisites the initial draft missed. Both are documented in full, with code-quoted evidence, in §8 (Blocker #1: the `⚠ BLOCKING FINDING` callout after the Controller/FormRequest description; Blocker #2: the `⚠ BLOCKING FINDING` callout after the FormRequest field list). Summarized and formally classified here:

**Findings:**

| # | Area | Finding | Classification |
|---|---|---|---|
| 1 | Authorization centralization | Every customer-side Workspace mutation funnels through `WorkspaceManager`'s small set of centralized assertions (§6), each re-locking its target row(s) inside a transaction before deciding. No controller re-implements or second-guesses this. | NO DEFECT |
| 2 | Admin/customer boundary | Admin Workspace/Business routes are layered: `can:access backend` → `ValidProduct` → `twofactor` → `EnsureUserIsAdministrator` (independent `users.is_admin` check) → per-action Gate permission (`view workspace`/`view business`/`edit business`). No admin route can mutate a Workspace or reassign/create a Business at all. | NO DEFECT |
| 3 | Ownership-transfer invariant | `transferOwnership()` is owner-only with **no** Admin/Staff/platform-admin bypass of any kind; locks Workspace, both owner `users` rows, and both membership rows in ascending-ID order (deadlock-safe); writes exactly one durable `workspace_transitions` audit row before dispatching the domain event. | NO DEFECT |
| 4 | Business reassignment bypass | Requires owner-or-active-Admin independently on **both** source and target Workspace, re-verifies the Business's authoritative `workspace_id` after locking (rejects a stale/mutated in-memory `workspace_id`), and re-runs the RFC-004 capacity gate (`assertCanCreateAnotherBusiness()`) against the target. Concurrency independently proven race-safe by `EntitlementManagerConcurrencyTest`'s 8 real two-OS-process scenarios (including a create-vs-reassign race), asserting exactly one winner and no over-allocation. | NO DEFECT |
| 5 | Role/scope escalation (Workspace-mediated paths) | `changeMemberRole()` is **owner-only with no exception** — an Admin member can never promote themselves or another Staff member to Admin, and can never touch another Admin's role. `addMember()`/lifecycle actions require owner-only authority specifically for an Admin-role target (§10's corrected matrix). `changeMemberBusinessAccessScope()`'s broader owner-or-active-Admin authority (including over an Admin target's scope) is the RFC-003-intended shape, not an escalation — scope alone, per RFC-003 §22, "never grants access beyond its explicit assignments; it can only narrow, never widen, what role alone would imply." | NO DEFECT |
| **6** | **RFC-003 inactive-Workspace direct-Business-access drift (customer Business-profile route)** | **Confirmed via direct code read (§8): `BusinessController::edit()`/`update()` and `BusinessManager::updateBusiness()`/`assertOwnership()` never call `WorkspaceManager::userCanAccessBusiness()`/`assertUserCanAccessBusiness()`, and never otherwise check the Business's Workspace `is_active` state. RFC-003 §14 ("a deliberate behavior change from v1.0"), §14.1 (the `userCanAccessBusiness()` algorithm itself, which correctly implements the gate), and §22's own security invariant ("An inactive Workspace blocks all customer-side access, including direct Business ownership and Workspace ownership — corrected from v1.0") are explicit and unambiguous that direct ownership must be gated behind Workspace-active state on every customer-side access path. This one route was never wired to the otherwise-correct, already-implemented enforcement function.** | **BLOCKING AUTHORIZATION / CORRECTNESS** — an access-control semantic drift from the approved RFC, even though no cross-tenant privilege escalation has been demonstrated (the affected user is always the Business's own direct owner, never a stranger) |
| **7** | **Customer "Other" industry impossible submission** | **Confirmed via direct code read (§8): `BusinessIndustry::Other = 'other'` exists and is rendered as a selectable `<option>` by `customer/business/edit.blade.php`'s `@foreach (\App\Enums\Business\BusinessIndustry::cases() as $industry)`; `UpdateBusinessRequest::rules()` makes `industry_other` `required_if:industry,other`; the same view renders zero `industry_other` field (confirmed by grep). A customer selecting "Other" cannot produce a valid submission through the rendered form under any input.** | **BLOCKING NONSECURITY CORRECTNESS** — an impossible, unrecoverable valid-state transition reachable through ordinary use of the retained UI, not a security boundary defect |
| 8 | Deactivated-Workspace mutation (Workspace-mediated paths) | `renameWorkspace()`/business-creation/`changeMemberBusinessAccessScope()` all independently re-check the Workspace is active before mutating; `userCanAccessBusiness()` unconditionally denies Business access (even to the owner) once `!$workspace->is_active`, **wherever it is actually consulted** — which, per Finding 6, currently excludes the direct Business-profile route. | Workspace-mediated paths: NO DEFECT. Direct Business-profile path: see Finding 6 |
| 9 | CSRF / method correctness | Every mutating form in all 8 views carries `@csrf`; every non-POST-semantic action (`admin.businesses.update` PUT, `admin.businesses.status.update` PATCH, `customer.business.update` PUT) carries the matching `@method(...)` directive matching its route's declared HTTP verb. No mismatch found. | NO DEFECT |
| 10 | Raw exception leakage | Controllers on both the customer and admin Workspace/Business paths catch typed domain exceptions and flash a fixed message or 404 — no `$exception->getMessage()` echoed to a view in any of the 8 files (confirmed by direct read; none of these 8 views references an exception object at all). | NO DEFECT |
| 11 | Mass assignment | Both `UpdateBusinessRequest` and `AdminUpdateBusinessRequest` explicitly enumerate allowed fields (no wildcard/`$request->all()` anywhere); `EloquentBusinessRepository::update()` additionally strips `customer_id`/`is_primary`/`canonical_domain`/`status`/`activated_at` via `Arr::except()` even if a future FormRequest change ever added them. | NO DEFECT |
| 12 | `workspace_id` mass-assignment hardening gap | `EloquentBusinessRepository::update()`'s `Arr::except()` list does **not** include `workspace_id`. Today this is safe *only* because neither `UpdateBusinessRequest` nor `AdminUpdateBusinessRequest` validates a `workspace_id` field, so `$request->validated()` never contains it. There is no independent, repository-level defense-in-depth for this one field the way there is for the other five protected fields. | **NONBLOCKING HARDENING** — no current exploit path exists; worth closing in a future, separately-scoped hardening change (add `workspace_id` to the repository's exception list), and remains nonblocking even after Findings 6/7 are remediated, since neither blocker's fix requires touching this field |
| 13 | Client-side `action=` construction | `customer/workspaces/show.blade.php`'s mutation forms have no static `action=` attribute — it is set by JS from `window.location.pathname` at page load. This is an intentional existing pattern (not a defect), but any future visual pass must preserve every `data-workspace-action`/`data-member-action`/`data-business-action` attribute exactly, since the JS selects on them. | NO DEFECT (documented behavioral seam, see §11 and §18) |

**Conclusion (Correction Round 1): A2 visual implementation is BLOCKED. Two blocking prerequisites exist** — Finding 6 (BLOCKING AUTHORIZATION / CORRECTNESS) and Finding 7 (BLOCKING NONSECURITY CORRECTNESS) — both confined to `customer/business/edit.blade.php`'s backing controller/manager/request, neither touching the Workspace-mediated paths (§6, §7) or the admin Business paths (§9), both of which remain NO DEFECT. One NONBLOCKING HARDENING item (Finding 12) is recorded for future, separately-scoped remediation and does not itself block anything.

**Required remediation outcomes (architecture intentionally not prescribed by this contract — see §8's callouts):**

*Blocker #1 (Finding 6):*
- an inactive Workspace must block customer-side `GET` access to that Business profile;
- an inactive Workspace must block customer-side `PUT`/update access to that Business profile;
- direct Business ownership must not bypass that rule;
- denial must be intentional and safe (a deliberate, well-formed response), never an accidental generic 500;
- a denied update must persist zero Business mutation;
- active-Workspace direct-owner behavior must remain available exactly as today;
- platform-admin behavior (§7, §9) remains governed separately and must not be accidentally constrained by this customer-side remediation;
- RFC-003 tenancy semantics (§14, §14.1, §22) remain authoritative.

*Blocker #2 (Finding 7):*
- the rendered customer Business form and its validation contract must no longer expose an impossible valid-state transition;
- if `Other` remains selectable, the customer must have a valid, rendered path to provide the required `industry_other` value;
- `old()`-input / validation-error redisplay behavior must remain coherent;
- protected Business fields (§8) must remain protected;
- no broadening of customer field authority beyond what's needed to close this gap;
- admin behavior (§9, already unaffected) must not regress.

A separate nonvisual remediation contract must select the exact architecture for both blockers — this contract does not, and must not, prescribe whether either fix belongs in `BusinessController`, `BusinessManager`, reused `WorkspaceManager` calls, new middleware, or another existing domain seam; nor whether both blockers are remediated together or separately. That remediation contract will carry its own exact write allowlist, entirely independent of this visual contract's future 11-path allowlist (§23) — **do not conflate the two.**

**A2 visual implementation is now explicitly blocked** (§0, §28) pending: (a) a separate nonvisual remediation contract for both blockers, (b) its separate implementation, (c) human merge of that implementation, and (d) a mechanical re-audit of post-remediation `main` before any A2 visual branch may be created (§28).

---

## 20. Design intent (non-binding guidance for the future implementation task)

The future A2 visual redesign should make the Workspace hierarchy legible — Workspace → Members → Businesses → roles/scopes/assignments — using the now-available `x-card`/`x-badge`/`x-table`/`x-button`/`x-input`/`x-select`/`x-empty-state`/`x-pagination` adoptions locked in §16 (subject to the repeated-field-name and excluded-region carve-outs in §16/§17), without reintroducing the legacy Sub-Account mental model (§5.1) and without touching the embedded billing/entitlement regions (§5.3). Business profile editing (customer and admin) remains a single-purpose identity/contact form, whose exact post-remediation field set (§12, §19) must be re-verified before the visual pass begins. Admin Workspace/Business views remain read-mostly platform-administration surfaces. No new product functionality, no new navigation structure (e.g. tabs), and no new confirmation dialogs are authorized by this contract — those would each be new behavior, not a restyle, and would need their own explicit authorization exactly as A1's stepper-restyle-only boundary was locked.

---

## 21. Test strategy / exact future DS test plan

Following A1's own established pattern (`BusinessOnboardingDesignSystemContentTest`, `BusinessOnboardingComponentAdoptionTest`, `BusinessOnboardingExistingBehaviorPreservedTest`), the future implementation task should create exactly:

- `tests/Feature/DesignSystem/WorkspaceBusinessDesignSystemContentTest.php` — content hygiene: zero `data-feather`, zero hardcoded color/font literals, across all 8 files; confirms the two out-of-scope embedded regions (§5.3) were left byte-identical (a targeted diff-region check, not a full-file hash, since line numbers may legitimately shift elsewhere in the same file, including from the nonvisual remediation itself).
- `tests/Feature/DesignSystem/WorkspaceBusinessComponentAdoptionTest.php` — exact adoption-matrix counts per §16 (per-file `x-card`/`x-badge`/`x-table`/`x-button`/`x-input`/`x-select`/`x-empty-state`/`x-pagination` marker counts), plus explicit non-adoption assertions per §17 (zero `x-textarea`, zero checkbox-component markers, zero `<dl>`-replacement component, zero forbidden `outline-danger`/`outline-warning`/`outline-success` `x-button` variant usage, zero adoption markers inside the §5.3-excluded regions, zero duplicate-`id` regressions on the repeated-field-name controls). **These exact counts must be derived only after the nonvisual remediation (§19) has human-merged**, since Blocker #2's fix may add a field (most plausibly `industry_other`) to the customer form, changing its adoption-marker counts from the PRE-REMEDIATION figures in §12.
- `tests/Feature/DesignSystem/WorkspaceBusinessExistingBehaviorPreservedTest.php` — HTTP-level behavior preservation, asserting the **POST-REMEDIATION, authoritative** behavior, including: an inactive Workspace denies customer Business-profile `GET`/`PUT` access (Blocker #1's fixed outcome); active-Workspace direct-owner profile behavior remains available exactly as before; the corrected customer `Other`-industry submission flow succeeds (Blocker #2's fixed outcome); the corrected Workspace role/scope rendering rules (§10); the `data-workspace-action`/`data-member-action`/`data-business-action` JS seams (§11); retained route names and field names; the excluded billing/entitlement regions render unchanged (§5.3). **This file must never encode the pre-remediation drift or the pre-remediation impossible-`Other`-submission as "expected" behavior** — its purpose is to prove the visual pass preserved the *corrected* behavior, not to freeze either defect.

**Do not duplicate existing backend coverage.** The exhaustive Workspace/Business/Entitlement behavior and security suites already listed in §6/§7/§8/§9 (`WorkspaceLifecycleTest`, `WorkspaceOwnershipTransferTest`, `WorkspaceBusinessReassignmentHttpTest`, `WorkspaceMemberManagementHttpTest`, `AdminWorkspaceControllerTest`, `AdminBusinessControllerTest`, `EntitlementManagerBusinessSlotCapacityTest`, etc. — 50+ files), plus whatever new regression tests the nonvisual remediation contract itself adds for Blockers #1/#2, remain the authoritative, unduplicated proof of Workspace/Business correctness. The new DS tests exist only to prove the *visual* pass did not disturb any of it, exactly as A1's three DS test files did for onboarding.

---

## 22. Test-efficiency policy

This docs-only contract (both the original draft and this correction) changes zero application code, so **no PHPUnit run of any kind was performed for either.** The most recent known full-suite evidence (historical context only, **not the authoritative future visual baseline**): A1's post-implementation full suite, 3779 tests / 13591 assertions / 1 pre-existing unrelated `BrandingAdminFooterRenderTest` failure / 0 skipped, at commit `c3fcff3b84100ae03d600995220b2fae0a823ae3` (PR #190's implementation head, tree-identical to `origin/main` at this contract's base SHA per PR #191's merge).

**Correction Round 1 fix:** the initial draft's framing risked treating this A1-era count as the future visual task's regression baseline. It is not. The nonvisual remediation for Blockers #1/#2 (§19) will itself legitimately change application code, may change the in-scope retained view (`customer/business/edit.blade.php`, most plausibly), will add its own remediation regression tests, and will therefore change the total test/assertion counts — exactly as A1's own remediation phase (PR #190) changed its baseline from 3730 to 3743 before the visual phase (PR #191) added its own 36 DS tests on top, reaching 3779.

For the future A2 visual task, once separately authorized:

1. Establish the post-remediation, human-merged `main` as the actual base.
2. Mechanically verify whether the product-code tree changed between the remediation's implementation head and that merged `main` (the same `git rev-parse ...^{tree}` comparison A1's own visual-implementation task used, to determine whether a redundant pre-implementation full suite is needed).
3. Collect the appropriate focused/full baseline under the final, human-merged remediation contract and its implementation evidence — not from this document's A1-era figures.
4. Add the 3 A2 DS tests (§21) during visual implementation.
5. Report exact pre/post totals.
6. Require **zero NEW failures and zero skipped tests**, subject to whatever already-existing environment/repository failure policy is explicitly documented at that post-remediation base (e.g. a still-pre-existing, still-unrelated failure carried forward, the way `BrandingAdminFooterRenderTest` was carried through A1 unchanged). **Do not require equal total test counts after adding the new DS tests** — an increase equal to the new tests' own count, as A1 demonstrated, is the expected and correct outcome, not a discrepancy to explain away.

---

## 23. Exact future visual implementation allowlist

**Conditional on post-remediation mechanical re-verification (§19, §28).** The future visual allowlist below remains the following 11 paths **only if** that re-verification confirms the same 8 retained views still exist as named (§4's note) and no additional visual/component path becomes necessary as a side effect of the remediation. If remediation changes the retained view inventory, or creates a genuine need for an additional visual/component path, a future task must **stop and correct this contract** rather than silently expanding the allowlist (§14 of Correction Round 1's own instructions, carried forward as a standing rule here).

**8 existing Blade views** (restyle only, per §16's adoption matrix, excluding the §5.3 embedded regions and the §16/§17 native carve-outs):
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

**Total: exactly 11 paths, conditional as stated above.**

No backend path (`app/**`, `routes/**`, `config/**`, `database/**`) is authorized by this contract for any future A2 **visual** work. **The separate nonvisual remediation required by §19 is explicitly not constrained by this 11-path visual allowlist** — it will carry its own, separately-contracted exact allowlist, exactly as A1's Phase I/J (remediation contract + implementation, PRs #189/#190) did before A1's own visual phase (PR #191). This visual contract does not, and must not, invent or anticipate that remediation allowlist.

---

## 24. Stop threshold

**A 12th changed path in the future visual implementation task is the stop threshold** — mirroring A1's "13th path" rule (12 allowlisted paths there, one more than the allowlist size). If any future visual implementation task's diff touches a 12th path beyond the 11 named in §23, that task must stop and report rather than proceed. This threshold applies only once the future visual task is separately authorized, which requires the sequence in §28 to have completed first.

For **this current docs-only correction task**, the stop threshold is **the 2nd changed path** — this correction modifies exactly one file (`docs/automation/DESIGN-SYSTEM-M2-A2-WORKSPACE-BUSINESS-CONTRACT.md`) and touches nothing else.

---

## 25. Explicit Sub-Account exclusion

Confirmed zero Sub-Account paths in §23's allowlist. Confirmed zero references to `SubAccounts`, `parent_id`, or Sub-Account routes/controllers in any of the 8 in-scope views (§5.1). Legacy Sub-Accounts remain **DELETE LATER**, untouched, unlinked from A2. Unaffected by Correction Round 1.

---

## 26. Explicit billing/usage exclusion

Confirmed zero billing/usage/commercial-slot/plan-catalog paths in §23's allowlist (§5.2). Confirmed the two embedded out-of-scope regions inside `customer/workspaces/show.blade.php` and `admin/workspaces/show.blade.php` are explicitly carved out by exact line range and excluded from any future restyle (§5.3), and — per this correction — that no adoption marker of any kind is authorized *inside* those regions (§16, §17), and that the "Businesses" card's own outer wrapper in `customer/workspaces/show.blade.php` must stay native because it nests one such region (§16, §17). `admin/workspace-plan-catalog/index.blade.php` explicitly excluded with documented reasoning, refining the retention audit's own path-prefix estimate (§5.2, §3).

---

## 27. A3 / B1 boundary

This contract authorizes **A2 contract/audit work only**. It does not begin, plan the implementation of, or otherwise advance A3 or B1 (both remain future surviving-roadmap groups per `PRODUCT-SURFACE-RETENTION-AUDIT.md` §9's ordered list). No A3 or B1 file, route, or document was read, referenced as an implementation target, or modified by this task or this correction.

---

## 28. Human handoff / no-auto-advance rule

**Correction Round 1 fix — the sequence is now:**

A. A human reviews and merges this corrected contract.
B. A separate contract is drafted for the two nonvisual blockers found in §19 (Blocker #1: inactive-Workspace direct-Business-access gate; Blocker #2: customer "Other"-industry impossible submission) — its own architecture, write allowlist, and stop threshold, entirely independent of §23's visual allowlist.
C. That remediation is implemented separately, on its own branch.
D. A human merges that remediation implementation.
E. Post-remediation `main` is mechanically re-audited — re-verifying, at minimum: the exact retained view inventory (§4), direct Business-profile access behavior (§8, §19), inactive-Workspace behavior, the customer Business field inventory (§12), role/scope UI behavior (§10, §11), component-adoption compatibility (§16, §17), the embedded billing/entitlement boundaries (§5.3), existing regression coverage, and the exact visual implementation allowlist (§23).
F. Only then may a separately-authorized A2 visual implementation branch be created.

This governs strictly *in addition to* the general rule that no A2 visual implementation may begin from a contract document alone — exactly as A1's own two-phase structure required (`DESIGN-SYSTEM-M2-A1-BUSINESS-ONBOARDING-CONTRACT.md` → `DESIGN-SYSTEM-M2-A1-ONBOARDING-BEHAVIOR-REMEDIATION-CONTRACT.md` → remediation implementation PR #190 → visual implementation PR #191). Per governance (§0): `advance_automatically: false`, `start_a3_automatically: false`, `start_b1_automatically: false`, `a2_visual_status: blocked_until_nonvisual_workspace_business_remediation_human_merged`, `visual_implementation_requires_separate_human_authorization: true`, `merge_authority: human_only`. This task does not open, request, or imply approval for a follow-up remediation-contract task, remediation-implementation task, or visual-implementation task — it stops after the report below.
