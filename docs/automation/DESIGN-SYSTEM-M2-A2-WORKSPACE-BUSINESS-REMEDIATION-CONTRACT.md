# Design System M2 — A2 Workspace / Business — Nonvisual Remediation Contract

## 0. Governance

```yaml
roadmap_group: A2
roadmap_group_name: Workspace / Business

contract_type: nonvisual_remediation
docs_only: true
implementation_has_occurred: false

source_visual_contract: docs/automation/DESIGN-SYSTEM-M2-A2-WORKSPACE-BUSINESS-CONTRACT.md
source_visual_contract_pr: 192
source_visual_contract_merge_sha: 59ac514dccf077f5fbf6f8b8888e39737f78585a

blocking_finding_count: 2

finding_6_status: remediation_contract_defined
finding_7_status: remediation_contract_defined

a2_visual_status: blocked_until_nonvisual_workspace_business_remediation_human_merged

remediation_implementation_requires_separate_human_authorization: true
visual_implementation_requires_separate_human_authorization: true

advance_automatically: false
start_a2_visual_automatically: false
start_a3_automatically: false
start_b1_automatically: false

merge_authority: human_only
no_force_push: true
no_deployment: true

maximum_correction_rounds: 2
correction_round: 1
correction_round_is_final: false

contract_status: correction_round_1_pending_review

schema_or_migration_change_required: false

base_sha: 59ac514dccf077f5fbf6f8b8888e39737f78585a
base_pr: 192
base_pr_title: "Design System M2 A2 — Workspace & Business Contract"
base_merge_parents:
  - 5be68c00ee146c34f2fd9ef8985389309db6c7e8
  - 1e2770b9afbcfd9ccc01e43b005b1a9cdc6e7423
```

---

## 1. Purpose and precedent

This is the separate nonvisual remediation contract required by the merged A2 visual contract's §19 (Findings 6 and 7). It converts those two outcome-level blocker findings into a mechanically precise, minimal, testable implementation plan. It does not implement anything. It does not touch the merged A2 visual contract, application code, tests, or any other document.

**Correction Round 1 (this revision):** the initial draft selected a controller-body architecture for Finding 6 (checking `WorkspaceManager::userCanAccessBusiness()` inside `BusinessController::edit()`/`update()`). Independent review found this insufficient for the PUT route: `update(UpdateBusinessRequest $request)` type-hints a Laravel `FormRequest`, which Laravel resolves and validates during controller-method dependency injection — **before** the controller method body's first statement runs. A controller-body check can therefore never guarantee the inactive-Workspace denial happens before a malformed PUT payload's `UpdateBusinessRequest` validation. This is the exact ordering defect the merged A1 onboarding remediation contract already identified and corrected (rejecting a controller-body guard for `BusinessOnboardingController` in favor of route middleware, `EnsureBusinessOnboardingIsEnabled`) — reused here as *process* precedent only; A2's corrected architecture (§3.3) is derived independently from A2's own code, not copied from A1's middleware design. This revision replaces the controller-body architecture with a new route middleware, re-derives the future implementation allowlist (§9) accordingly, and corrects the no-primary-Business PUT behavior claim (§3.5) to distinguish a validation-passing payload from a malformed one. Finding 7 was freshly re-audited (§4) and found still correct as originally selected — no change was needed there.

Sequence (mirrors the completed A1 precedent — visual contract → nonvisual remediation contract PR #189 → remediation implementation PR #190 → visual implementation PR #191 — while using architecture specific to A2's actual code, not copied from A1's):

```
A2 visual contract — PR #192 — MERGED
  ↓
A2 nonvisual remediation contract — THIS TASK (Correction Round 1)
  ↓
human review + correction rounds if necessary
  ↓
human merge
  ↓
separately authorized remediation implementation
  ↓
human merge
  ↓
re-baseline/reverify the A2 visual contract
  ↓
separately authorized A2 visual implementation
```

No stage advances automatically (§0).

---

## 2. Base verification

```
git fetch origin --tags
git rev-parse origin/main
59ac514dccf077f5fbf6f8b8888e39737f78585a
```

Re-confirmed unchanged at the start of this correction: `origin/main` is still exactly `59ac514...` (the PR #192 merge), the branch's starting HEAD was exactly `29701fe1f71e43da1b2ce3d217b3ef31c1a31213` (this contract's own Correction Round 0 commit, sole parent `59ac514...`), exactly 1 ahead / 0 behind, and the aggregate diff contained exactly this one document before any Round 1 edit was made.

---

## 3. Finding 6 — inactive-Workspace direct-Business-access drift

### 3.1 Exact current defect (mechanically re-confirmed against `origin/main` at `59ac514...`)

- **Routes** (`routes/customer.php:522-525`):
  ```php
  Route::prefix('business')->name('business.')->group(function () {
      Route::get('/', 'BusinessController@edit')->name('edit');
      Route::put('/', 'BusinessController@update')->name('update');
  });
  ```
  `GET business` → `customer.business.edit`; `PUT business` → `customer.business.update`. Both session-derived — neither takes a Business identifier in the URL; both always resolve "the authenticated customer's own primary Business."
- **Controller** (`app/Http/Controllers/Customer/BusinessController.php`): `edit(Request $request)` and `update(UpdateBusinessRequest $request)` both resolve `$business = $this->businessRepository->findPrimaryByCustomer($customer->user_id)` and, if non-null, proceed directly — **no call to `WorkspaceManager::userCanAccessBusiness()`/`assertUserCanAccessBusiness()`, and no check of `$business`'s Workspace `is_active` state, anywhere in this file.**
- `update()` additionally calls `BusinessManager::updateBusiness()`, whose only authorization check is `assertOwnership()`:
  ```php
  private function assertOwnership(Customer $customer, Business $business): void
  {
      if ((int) $business->customer_id !== (int) $customer->user_id) {
          throw new AuthorizationException(...);
      }
  }
  ```
  This never inspects `businesses.workspace_id` or the Workspace it points to.
- RFC-003 §14 ("direct Business-owner access... now gated behind the Workspace being active... a deliberate behavior change from v1.0"), §14.1 (the `userCanAccessBusiness()` algorithm), and §22 ("An inactive Workspace blocks all customer-side access, including direct Business ownership and Workspace ownership") are unambiguous that this route's current behavior is wrong.

### 3.2 Existing correct implementation of the required gate

`WorkspaceManager::userCanAccessBusiness()` (`app/Library/Workspace/WorkspaceManager.php:97-135`) already implements RFC-003 §14.1 correctly:

```php
public function userCanAccessBusiness(int $userId, Business $business): bool
{
    $currentBusiness = $this->businessRepository->findById($business->id);
    if ($currentBusiness === null || $currentBusiness->workspace_id === null) return false;
    $workspace = $this->workspaceRepository->findById($currentBusiness->workspace_id);
    if ($workspace === null || ! $workspace->is_active) return false;      // gate evaluated first
    if ((int) $currentBusiness->customer_id === $userId) return true;      // direct ownership
    if ((int) $workspace->owner_user_id === $userId) return true;
    $membership = $this->membershipRepository->findByWorkspaceAndUser($workspace, $userId);
    if ($membership === null || ! $membership->is_active) return false;
    if ($membership->business_access_scope === WorkspaceBusinessAccessScope::All) return true;
    return $this->membershipBusinessRepository->isAssigned($membership, $currentBusiness->id);
}
```

No `is_admin` branch exists — this method is customer-side only; platform-admin access is governed entirely separately (RFC-003 §14 path 1) and `Admin\BusinessController` never calls `WorkspaceManager` at all (confirmed by grep — zero references). Admin is structurally unaffected by this remediation.

**Established, repeated app-wide precedent for denying access via this exact function** — 5 existing call sites, all customer-side, all single-Business-resource resolution, all deny with `abort(404)`: `WorkspaceController::reassignBusiness()`'s target-Business resolution (`assertUserCanAccessBusiness()` → catch `WorkspaceAccessDeniedException` → `abort(404)`), and `UsageBillingController`/`UsageBillingAutoRechargeController`/`UsageBillingPaymentMethodController`/`UsageBillingTopUpController` (all four: `! userCanAccessBusiness(...)` inline → `abort(404)`). `UsageBillingController::resolveViewableBusiness()`'s own docblock: *"An unknown Workspace/Business uid, or a Business this actor cannot view, both fail closed identically with 404 (M2 contract §7) — this route can never be used to probe for existence."*

### 3.3 Corrected architecture — route middleware (Correction Round 1)

**Why the original controller-body architecture is rejected.** `update()` type-hints `UpdateBusinessRequest $request`. Laravel resolves and validates a `FormRequest` while resolving the controller method's dependencies — this happens **before** the controller method body executes at all (this is exactly the ordering fact the merged A1 onboarding remediation contract already established and corrected for `BusinessOnboardingController`, reused here as process precedent). Concretely: a rightful owner of a Business in an **inactive** Workspace submitting a **malformed** PUT (e.g. missing `required` fields like `name`/`country_code`/`timezone`/`currency_code`) would, under the original architecture, hit `UpdateBusinessRequest`'s validation *before* any controller-body Workspace-active check ever ran — producing a validation-error redirect instead of the required 404, an authorization-order violation.

**Existing precedent for the corrected seam.** This app already has two middleware classes that resolve per-request domain state and gate a route/route-group before controller dispatch:

- `App\Http\Middleware\EnsureBusinessOnboardingIsEnabled` (`app/Http/Middleware/EnsureBusinessOnboardingIsEnabled.php`) — a stateless config check, applied to the entire `onboarding` route group via `->middleware('business.onboarding.enabled')` (`routes/customer.php:508`).
- `App\Http\Middleware\EnsureRequiredBusinessOnboardingIsComplete` (`app/Http/Middleware/EnsureRequiredBusinessOnboardingIsComplete.php`) — a closer architectural match: constructor-injected repository/manager dependencies, resolves `auth()->user()->customer`, resolves a per-customer domain row (`CustomerOnboardingRepository::findByCustomer($customer)`), passes through (`return $next($request)`) when the row is absent or the condition doesn't apply, otherwise redirects. Applied to a single route (`routes/auth.php:56`, `->middleware('business.onboarding')`).

Both classes are registered as named route-middleware aliases in `app/Http/Kernel.php`'s `$routeMiddleware` array (lines 117-118). This is the established, mechanically-confirmed pattern for "resolve the authenticated customer's own domain object, gate on its state, before controller/FormRequest dispatch" in this codebase — route middleware is the smallest correct architecture, not an assumption.

**Locked design:**

1. **New file:** `app/Http/Middleware/EnsureBusinessProfileIsAccessible.php`, class `EnsureBusinessProfileIsAccessible`.
2. **Dependencies** (constructor-injected, mirroring `EnsureRequiredBusinessOnboardingIsComplete`'s style): `App\Repositories\Contracts\BusinessRepository $businessRepository`, `App\Library\Workspace\WorkspaceManager $workspaceManager`.
3. **Actor/customer resolution:** `auth()->user()->customer` (same call as the existing precedent middleware). If `null`, `return $next($request)` — passes through unchanged to whatever existing behavior a customer-less authenticated user currently gets on this route (an edge case this remediation does not alter).
4. **Primary-Business lookup:** `$this->businessRepository->findPrimaryByCustomer($customer->user_id)` — the exact same repository method the controller already calls; not duplicated logic, the single existing call site.
5. **No-primary-Business behavior:** if `$business === null`, `return $next($request)`. The middleware never converts "no Business" into a 404 — it defers entirely to the controller's own existing, unmodified "redirect to onboarding" logic (§3.5's corrected table).
6. **Access check:** `if (! $this->workspaceManager->userCanAccessBusiness($customer->user_id, $business)) { abort(404); }` — reuses the unmodified method directly, matching the boolean-inline-check style used by 4 of the 5 existing precedent call sites (§3.2). No JSON-specific response handling is added (unlike `EnsureBusinessOnboardingIsEnabled`, which needed one only because the onboarding group includes a JS-polled JSON endpoint; neither `customer.business.edit` nor `customer.business.update` is JSON-consumed by anything in this app — confirmed by grep, zero `Accept: application/json`/`wantsJson()` callers of this route).
7. **Pass-through on success:** `return $next($request)`.
8. **Middleware alias:** `business.profile.accessible`, registered in `app/Http/Kernel.php`'s `$routeMiddleware` array immediately after the two existing `business.onboarding*` entries (`'business.profile.accessible' => EnsureBusinessProfileIsAccessible::class`).
9. **`routes/customer.php` application point** (exact, single-line change to the existing group):
   ```php
   Route::prefix('business')->name('business.')->middleware('business.profile.accessible')->group(function () {
       Route::get('/', 'BusinessController@edit')->name('edit');
       Route::put('/', 'BusinessController@update')->name('update');
   });
   ```
   mirroring the onboarding group's own `->middleware('business.onboarding.enabled')` placement exactly (`routes/customer.php:508`).
10. **Ordering relative to controller dispatch/FormRequest resolution:** route middleware executes as part of the HTTP kernel's middleware pipeline, which completes in full before Laravel resolves the controller method's own type-hinted dependencies (including `FormRequest` injection, which happens via reflection at controller-invocation time, strictly after every route middleware has run). This is the exact guarantee `EnsureBusinessOnboardingIsEnabled` already relies on for its own JSON-polled endpoints, and the exact fact the original architecture violated.
11. **GET and PUT use the identical seam.** Applying the middleware at the route-*group* level (not per-route) means both `edit()` and `update()` pass through the same single middleware instance's `handle()` method — structurally guaranteed parity, stronger than the rejected architecture's duplicated controller-body checks ever could be (duplication risks future drift; one shared middleware cannot).
12. **No controller-body duplicate check is needed or added.** `Customer\BusinessController` is **not modified by this remediation at all** — `WorkspaceManager` is not injected into it, and `edit()`/`update()` keep their exact current bodies. The middleware is the sole enforcement point for this concern, matching the DRY principle already established by `EnsureBusinessOnboardingIsEnabled` (also the sole enforcement point for its own concern, with no duplicate check inside `BusinessOnboardingController`) and by the explicit "does not duplicate Workspace access logic" requirement in both this contract and the source visual contract.
13. **Confirmed unchanged:** `BusinessManager`, `assertOwnership()`, `EloquentBusinessRepository`, `Admin\BusinessController`, `WorkspaceManager` itself, `UpdateBusinessRequest`, any model, any migration, and every RFC document. Only the three files named in points 1, 8, and 9 above change for Finding 6.
14. **No new locking/transaction.** `userCanAccessBusiness()` remains a plain, unlocked read at every call site including this new one — consistent with all 5 existing precedent call sites, none of which lock either. RFC-003 §22's "lock and recheck authoritative rows" invariant governs Workspace *mutations*, not this kind of read-access check.

### 3.4 Why membership/scope semantics do not matter to this specific route

Unaffected by the architecture correction — restated for completeness. `findPrimaryByCustomer($customer->user_id)` only ever resolves a Business whose `customer_id === $customer->user_id`, and `$customer->user_id === Auth::id()` for the authenticated actor, so the `userId` argument passed to `userCanAccessBusiness()` always equals the resolved Business's own `customer_id`. Mechanically tracing the algorithm (§3.2): the **only** branches that can flip the result away from "true" for this call pattern are the Workspace-active gate and the defensive `workspace_id === null` branch (unreachable on the current, `NOT NULL`-enforced-post-M1B schema — confirmed live by `WorkspaceEnforcementMigrationTest`). The Workspace-owner-fallback and membership/scope branches are structurally unreachable here. **Staff/Admin/Owner membership role has no bearing on this route** — it is always "view/edit my own primary Business," never reached on another Workspace member's behalf.

### 3.5 Required post-remediation HTTP behavior (Correction Round 1: no-Business PUT semantics corrected)

| Scenario | GET `customer.business.edit` | PUT `customer.business.update` |
|---|---|---|
| No primary Business exists, request would otherwise **pass** `UpdateBusinessRequest` validation | Redirect to `customer.onboarding.show` (existing, **unchanged** — GET has no `FormRequest` at all, so this row was never affected by the ordering defect) | Middleware passes through (no Business to gate); `UpdateBusinessRequest` validates successfully; controller's own existing `if ($business === null) return redirect()->route('customer.onboarding.show')` fires — redirect to onboarding (existing, **unchanged**) |
| No primary Business exists, request is **malformed** and would fail `UpdateBusinessRequest` validation | N/A — GET carries no request body to validate | Middleware passes through (no Business to gate against, by design — §3.3 point 5); `UpdateBusinessRequest` validation runs and fails **before** the controller body executes; the customer receives a standard validation-error response (redirect back with `withErrors`), **not** the onboarding redirect. **This is pre-existing, unmodified behavior, identical before and after this remediation** — the middleware does not, and is not required to, change it. Do not assert an unconditional "PUT with no Business always redirects to onboarding" — it does only when the payload is itself valid. |
| Primary Business exists, its Workspace is **active** | 200, form renders (existing, **unchanged**) | Middleware passes through; validates and persists; redirect with `status=success` (existing, **unchanged**) |
| Primary Business exists, its Workspace is **inactive**, request is **well-formed** | **404** (currently: 200 — this is the fix) | **404**, zero Business mutation persisted (currently: succeeds — this is the fix) |
| Primary Business exists, its Workspace is **inactive**, request is **malformed** | N/A (GET carries no body) | **404** — the middleware denies **before** `UpdateBusinessRequest` is ever resolved/validated, so a malformed payload cannot produce a validation-error response instead of the required 404. **This is the specific ordering guarantee the corrected architecture exists to provide** and the row the original controller-body architecture could not guarantee. |

"Unrelated customer" is not a reachable, distinct state for this route (§3.4) — there is no way to address another customer's Business through this URL shape at all. It collapses into the "no primary Business" rows above.

---

## 4. Finding 7 — customer "Other" industry impossible submission

### 4.1 Fresh re-audit result (Correction Round 1): unchanged, confirmed still correct

Re-verified against `origin/main` at `59ac514...` (unchanged since the original draft — no application code differs): `BusinessIndustry` still has 7 cases including `Other = 'other'`; `Business::$fillable` still includes `industry_other`; the `businesses` table migration still defines the column; `EloquentBusinessRepository::update()`'s `Arr::except()` list still does not exclude `industry_other`; `UpdateBusinessRequest::rules()` still requires it via `required_if:industry,other`; `resources/views/customer/business/edit.blade.php` still renders zero `industry_other` field; both existing precedents (`admin/businesses/edit.blade.php`, `customer/workspaces/show.blade.php`'s Create-Business form) still render it unconditionally, identically. **No defect was found in the original selection. The architecture is retained unchanged.**

### 4.2 Exact current defect

- `App\Enums\Business\BusinessIndustry` has 7 cases including `Other = 'other'`.
- `resources/views/customer/business/edit.blade.php`'s industry `<select>` loops over all 7 cases (`Other` included) — **but the view contains zero `industry_other` field** (confirmed: `grep -c industry_other` → `0`).
- `App\Http\Requests\Business\UpdateBusinessRequest::rules()` has `'industry_other' => ['nullable', 'string', 'max:255', 'required_if:industry,' . BusinessIndustry::Other->value]` — a customer who selects `Other` and submits therefore always fails validation, with no rendered field to resolve it.

### 4.3 Everything downstream of the view is already correct — no backend change needed

- `Business::$fillable` already includes `industry_other` (`app/Models/Business.php:22`).
- `database/migrations/2026_07_18_120001_create_businesses_table.php` already defines the `industry_other` column — **the schema already exists; no migration is needed or authorized.**
- `EloquentBusinessRepository::update()`'s `Arr::except()` protected-field list does **not** exclude `industry_other` — a submitted value already persists correctly once present in `$request->validated()`.
- `UpdateBusinessRequest::prepareForValidation()` already normalizes an empty-string `industry_other` to `null` when the field is present in the request at all — no new normalization logic is needed provided the customer form submits the field (even blank) on every request, matching the two existing precedents below.

**The entire defect is a single missing Blade field.** No enum, validation, model, repository, or migration change is required or authorized.

### 4.4 Existing, established pattern to reuse (not invent)

Two other places in this exact codebase already render an unconditional `industry_other` text field, with no JS show/hide, immediately after the industry `<select>`:

- `resources/views/admin/businesses/edit.blade.php` (lines 46-50):
  ```blade
  <label class="form-label">Industry (other)</label>
  <input type="text" name="industry_other" class="form-control" maxlength="255"
         value="{{ old('industry_other', $business->industry_other) }}">
  ```
- `resources/views/customer/workspaces/show.blade.php`'s own customer-facing "Create Business" form (lines 201-204):
  ```blade
  <label class="form-label" for="business-industry-other">Industry (other)</label>
  <input type="text" class="form-control" id="business-industry-other" name="industry_other" value="{{ old('industry_other') }}">
  ```

Both are unconditionally rendered regardless of the selected industry (no JS toggle exists anywhere in this app for this field), relying entirely on server-side `required_if` validation plus `old()`-repopulation on failure. This is the app's own, twice-established convention — **not a new pattern to design.**

### 4.5 Selected architecture (unchanged, matches existing precedent exactly)

Add one field to `resources/views/customer/business/edit.blade.php`, positioned immediately after the existing industry `<select>` (mirroring both precedents' placement and label text exactly):

```blade
<div class="mb-1">
    <label class="form-label" for="industry_other">Industry (other)</label>
    <input type="text" class="form-control" id="industry_other" name="industry_other" maxlength="255" value="{{ old('industry_other', $business->industry_other) }}">
</div>
```

No JS. No conditional visibility. No stale-value clearing on transition away from `Other` — this deliberately matches the existing, established convention (neither precedent clears the field on transition either); the value simply becomes irrelevant, not actively erased, when `industry !== other`. Inventing auto-clear behavior here would be new functionality beyond what either blocker requires or what any existing pattern in this codebase does.

### 4.6 Required post-remediation behavior

| Scenario | Result |
|---|---|
| Select `Other`, submit valid `industry_other` | Persists both fields; success |
| Select `Other`, submit blank/missing `industry_other` | Validation error on `industry_other`; `old()`-redisplay preserves the customer's other entered values; zero mutation |
| Select a standard industry, submit (with or without `industry_other`) | Succeeds exactly as today (regression check) |
| GET edit for a Business whose current `industry` is `Other` | Field pre-filled with the existing stored `industry_other` value |
| GET edit for a Business whose current `industry` is a standard value | Field renders present but empty (or with whatever stale value exists — never hidden), matching both existing precedents |
| Transition `Other` → standard industry | Succeeds; any previously stored `industry_other` value is **not** auto-cleared (matches existing app-wide convention, §4.5) |
| Transition standard → `Other` | Succeeds once a valid `industry_other` is supplied |
| Admin form / admin behavior | Entirely unaffected — already correct, not touched |

---

## 5. Security audit (both blockers, re-run after the architecture correction)

| # | Area | Verified position |
|---|---|---|
| 1 | Workspace-active authorization occurs before `FormRequest` validation for PUT | **Locked by construction (§3.3 point 10).** Route middleware runs during the HTTP kernel pipeline, strictly before Laravel resolves `UpdateBusinessRequest` as a controller-method dependency. This is the specific defect this correction round exists to fix, and is proven by the mandatory malformed-inactive-PUT test (§7, scenario 5). |
| 2 | GET and PUT use the same gate | Group-level middleware application (§3.3 point 9/11) — structurally one shared enforcement point, not two independently-maintained checks |
| 3 | Inactive Workspace produces the intended non-disclosing response regardless of request-body validity | Confirmed in §3.5's corrected table — well-formed and malformed PUT requests against an inactive Workspace both resolve to 404, since the middleware denies before the payload is ever inspected |
| 4 | No customer data mutation on denied PUT | The middleware's `abort(404)` throws before the controller (and therefore before `BusinessManager::updateBusiness()`) is ever invoked — no code path exists between denial and any write |
| 5 | Active Workspace requests continue into normal validation/controller behavior | Confirmed — middleware passes through unconditionally when `userCanAccessBusiness()` returns true, leaving `UpdateBusinessRequest`/`BusinessController`/`BusinessManager` entirely unmodified for this path |
| 6 | No-primary-Business semantics remain unchanged | Confirmed and precisely qualified in §3.5 — the middleware never converts "no Business" into a 404; it always passes through, deferring entirely to the controller's existing, unmodified logic (whose own behavior differs by payload validity on PUT, itself unaffected by this remediation) |
| 7 | Tenant isolation unchanged | `findPrimaryByCustomer()`'s own `customer_id` scoping is untouched; the new gate only ever narrows access further, never widens it |
| 8 | Direct Business ownership remains required | `userCanAccessBusiness()`'s direct-ownership branch is unmodified and still evaluated (§3.4) |
| 9 | `userCanAccessBusiness()` reused, not reimplemented | Confirmed — the middleware calls the existing, unmodified method; no second access algorithm is introduced anywhere |
| 10 | Admin behavior unaffected | `Admin\BusinessController` and `admin/businesses/edit.blade.php` remain untouched; the new middleware is applied only to the `customer.php` `business` prefix group, never to any admin route |
| 11 | No customer can infer or mutate another customer's Business | Unaffected by the architecture change — this route takes no identifier and was never addressable cross-customer before or after (§3.4, §3.5) |
| 12 | No route/model binding behavior introduced | The middleware performs no route-model binding; it resolves the Business exactly as the controller already did, via the same repository method |
| 13 | No duplicate, divergent authorization logic | `BusinessController` is not modified — the middleware is the sole enforcement point (§3.3 point 12); nothing to diverge from itself |
| 14 | Mass assignment | Unaffected — no change to `UpdateBusinessRequest`'s field set or `Arr::except()` from this Finding-6 architecture change |
| 15 | Update authorization parity with read authorization | Both `edit()` (GET) and `update()` (PUT) pass through the identical middleware instance before either's own body runs |

**No security defect inseparable from Finding 6's fix was discovered during this re-audit.** No general Workspace security audit was performed or is authorized — scope remains exactly the two blockers.

---

## 6. Correctness audit (Finding 7, re-confirmed unchanged)

| Area | Verified position |
|---|---|
| Validation/persistence consistency | `UpdateBusinessRequest` and `EloquentBusinessRepository::update()` already correctly validate and persist `industry_other` — confirmed no change needed |
| Rendered-control/validation consistency | This is precisely the gap being closed — the new field makes the rendered form match what the request already requires |
| Edit-state population | `old('industry_other', $business->industry_other)` matches the exact established pattern from `admin/businesses/edit.blade.php` |
| Validation-error repopulation | Already works correctly for every other field on this form via the same `old()` mechanism; the new field inherits it identically, no special-casing needed |
| Enum-value compatibility | `BusinessIndustry::Other->value === 'other'` matches `UpdateBusinessRequest`'s `required_if:industry,other` exactly — confirmed, no drift |
| Other → standard / standard → Other transitions | Covered in §4.6; no clearing logic needed or added |
| Customer/admin behavior boundaries | Full parity achieved — this is in fact the *minimal* fix, since it makes the customer form match the admin form's already-correct pattern rather than diverge from it |
| Existing Business records | Any Business already carrying a stored `industry_other` value (e.g., created via the Workspace's own "Create Business" form, which already collects it) will now correctly display it on the customer edit form for the first time — a pure bugfix, not a data change |
| Nullability/requiredness semantics | Unchanged — `industry_other` remains `nullable` with `required_if:industry,other`, exactly as already defined |
| Schema/migration change | **Not required.** The column, model fillability, and repository behavior are already complete; confirmed by direct inspection of the migration and model (§4.3). `schema_or_migration_change_required: false` (§0). |

---

## 7. Test plan (Correction Round 1: authorization-order coverage added)

**No existing HTTP-level test file covers `Customer\BusinessController::edit()`/`update()`** — confirmed by repository-wide search (`grep -rln "customer.business.update\|customer.business.edit" tests/` matches only `AdminBusinessControllerTest.php`, which tests the *admin* route of a similar name, and `OpportunityQueueHttpTest.php`, which uses the route only as unrelated fixture setup). `WorkspaceManager::userCanAccessBusiness()` itself is already exhaustively covered by `tests/Feature/Workspace/WorkspaceEffectiveAccessTest.php` and is **not modified** by this remediation, so its own algorithm correctness needs no new coverage. All coverage below is genuine HTTP-level integration testing (real routes, real middleware pipeline, real `FormRequest` resolution) — not controller-unit testing — since the enforcement point is now route middleware, and only an HTTP test actually exercises the middleware/routing/Kernel integration this remediation depends on.

**New file:** `tests/Feature/Business/CustomerBusinessControllerTest.php`

Reuses existing fixture helpers — `Tests\Feature\Business\Concerns\CreatesBusinessTestData` (`createCustomer()`, `businessAttributes()`) and `Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData` (`createWorkspace($owner, $overrides)`, `createBusinessForCustomer($customerId, $workspaceId)`). **Implementation note:** `createBusinessForCustomer()` does not set `is_primary` (column default `false`); every fixture in this file must explicitly set `is_primary = true` after creation, since `findPrimaryByCustomer()` filters on it.

**Finding 6 coverage — authorization PRECEDENCE, not merely final result (minimum):**

*Active Workspace:*
1. Rightful customer, `GET customer.business.edit` → 200, form renders (regression).
2. Rightful customer, valid `PUT customer.business.update` → redirect + `status=success`, fields persisted (regression).
3. Rightful customer, **invalid/malformed** `PUT customer.business.update` (e.g. missing `name`) → ordinary `UpdateBusinessRequest` validation-error response remains reachable (proves the middleware does not interfere with normal validation on the allowed path).

*Inactive Workspace:*
4. Same rightful customer, `GET customer.business.edit` → 404.
5. Same rightful customer, **valid** `PUT customer.business.update` → 404, and the Business's stored attributes are byte-identical before/after the denied attempt (zero mutation).
6. Same rightful customer, **invalid/malformed** `PUT customer.business.update` (e.g. missing `name`/`country_code`/`timezone`/`currency_code`) → **404 — not a validation-error redirect**, zero mutation. **This test is mandatory** — it is the only test that mechanically proves the corrected middleware seam runs before `UpdateBusinessRequest` validation; a regression back to the rejected controller-body architecture would make this specific test fail (return a validation-error response instead of 404) while tests 4-5 could still incorrectly appear to pass.

*No primary Business:*
7. No primary Business, `GET customer.business.edit` → redirect to `customer.onboarding.show` (regression, unaffected).
8. No primary Business, **valid** `PUT customer.business.update` payload → redirect to `customer.onboarding.show` (regression, unaffected — middleware passes through, validation succeeds, controller's own null-check redirects).
9. No primary Business, **invalid/malformed** `PUT customer.business.update` payload → ordinary `UpdateBusinessRequest` validation-error response, **not** the onboarding redirect — documents genuinely pre-existing, unmodified behavior (§3.5); this test must not assert an onboarding redirect for this specific case.

**Finding 7 coverage (minimum):**
10. `industry=other` + valid `industry_other` → success, both fields persisted.
11. `industry=other` + blank/missing `industry_other` → validation error on `industry_other`, zero mutation, `old()` reflects the submitted (non-industry_other) fields.
12. Standard industry, with or without `industry_other` → success (regression).
13. `GET edit` for a Business whose current industry is `Other` → response contains the existing `industry_other` value.
14. Submitting a transition from `Other` to a standard industry (no `industry_other` required) → succeeds; the business's stored `industry_other` column is not asserted to change (documents the deliberate non-clearing behavior from §4.5, so a future change to that behavior is a visible, deliberate decision rather than an accidental regression).

**Explicitly out of scope for this test file:** Design System visual/adoption tests (this is nonvisual remediation only), and any re-testing of `userCanAccessBusiness()`'s own algorithm (already covered by `WorkspaceEffectiveAccessTest.php`, unmodified here).

---

## 8. Regression baseline and test policy

No PHPUnit run was performed for this docs-only contract task, or this correction round (consistent with every prior Design System M2 contract-only task in this sequence). The most recent known evidence is **historical context only, not gathered fresh in this task, and not the baseline for the future implementation**: A1's post-implementation full suite, 3779 tests / 13591 assertions / 1 pre-existing unrelated `BrandingAdminFooterRenderTest` failure / 0 skipped, at commit `c3fcff3b84100ae03d600995220b2fae0a823ae3`. Application code has not changed between that commit and this contract's base SHA (`59ac514...` is a chain of docs-only merges on top of it), but the future implementation task must not assume this number is still current without re-gathering it from its own exact base.

**Policy for the future remediation implementation task:**

1. Establish the exact implementation base commit (this remediation contract's own human-merged `main`).
2. Run the focused suites this remediation touches, **as real HTTP tests so the middleware/Kernel/routing integration is actually exercised, not merely a controller in isolation**: the new `tests/Feature/Business/CustomerBusinessControllerTest.php` (14 scenarios, §7); `tests/Feature/Business/BusinessManagerTest.php` and `tests/Feature/Business/BusinessRepositoryTest.php` (unmodified but adjacent — confirm no incidental regression); `tests/Feature/Workspace/WorkspaceEffectiveAccessTest.php` (confirms `userCanAccessBusiness()` itself is untouched and still exhaustively passes); `tests/Feature/Business/AdminBusinessControllerTest.php` (confirms admin remains unaffected); any existing test suite that exercises the `business.onboarding*` middleware aliases or the `$routeMiddleware` array generally, as a smoke check that the new alias registration introduced no collision.
3. Collect a full-suite run at that same base **before** implementing, only if the product-code tree at that base has not already been verified clean by a prior task (the same `git rev-parse ...^{tree}` efficiency check used throughout this sequence) — labeled as the pre-remediation baseline, evidence-only.
4. Implement.
5. Run the full suite again post-implementation.
6. Report exact pre/post totals. The post-implementation total is expected to **increase** by exactly the new test file's own method count (14, per §7's current derivation — re-count from the actual implemented file, since a future correction round could still adjust this) — this is not a discrepancy. **Zero newly introduced failures** is required. Any failure classified as pre-existing/unrelated must be mechanically demonstrated present and identical on **both** the pre- and post-implementation runs before being excluded.

---

## 9. Future implementation path allowlist (Correction Round 1: re-derived for the middleware architecture)

The original 3-path allowlist (`BusinessController.php` + Blade view + test file) is **superseded** — it depended on the rejected controller-body architecture. Mechanically re-derived from §3.3/§4.5/§7:

**Production (4 paths):**
1. `app/Http/Middleware/EnsureBusinessProfileIsAccessible.php` — **new file.** Finding 6: the Workspace-active gate itself (§3.3 points 1-7).
2. `app/Http/Kernel.php` — Finding 6: register the `business.profile.accessible` middleware alias (§3.3 point 8) — one line added to the existing `$routeMiddleware` array.
3. `routes/customer.php` — Finding 6: apply the new alias to the existing `business` prefix group (§3.3 point 9) — one line changed (adding `->middleware('business.profile.accessible')` to the existing group chain).
4. `resources/views/customer/business/edit.blade.php` — Finding 7: add the `industry_other` field (§4.5) — unchanged from the original draft.

**Test (1 path):**
5. `tests/Feature/Business/CustomerBusinessControllerTest.php` — new file, HTTP coverage for both findings (§7).

**Total: exactly 5 paths.**

**`Customer\BusinessController.php` is explicitly removed from this allowlist** — the corrected architecture requires no change to it (§3.3 point 12).

**Stop threshold: a 6th changed path is a mandatory STOP condition requiring human review.** No other production file (`app/Http/Controllers/Customer/BusinessController.php`, `app/Library/Business/BusinessManager.php`, `app/Repositories/Eloquent/EloquentBusinessRepository.php`, `app/Http/Requests/Business/UpdateBusinessRequest.php`, `app/Models/Business.php`, any migration, `app/Http/Controllers/Admin/BusinessController.php`, any other route file) is authorized to change under this contract, since §3–§6 mechanically established none of them require a change. If the implementation task discovers one of them genuinely does, it must stop and report rather than silently widening this allowlist. This contract does not include, and does not authorize touching, the already-merged A2 visual contract (`docs/automation/DESIGN-SYSTEM-M2-A2-WORKSPACE-BUSINESS-CONTRACT.md`) — implementation is not coupled to that document; a post-implementation re-audit of it happens as its own, separate step (§1's sequence diagram, step "re-baseline/reverify").

---

## 10. Completion criteria (Correction Round 1: path count and test count updated)

Remediation implementation is complete only when all of the following hold simultaneously:

- Exactly the 5 paths in §9 changed (or fewer — never more without a STOP-and-report).
- All 14 test scenarios in §7 pass, **including the mandatory malformed-inactive-Workspace-PUT test (§7 scenario 6)**, which is the specific proof of correct authorization ordering.
- `Customer\BusinessController.php` is provably unchanged (git diff shows no touch).
- The full regression suite shows zero newly introduced failures per §8's policy.
- No schema or migration file was added or changed.
- `Admin\BusinessController` and `admin/businesses/edit.blade.php` are provably unchanged (git diff shows no touch).
- The A2 visual contract document is provably unchanged.
- A human has reviewed and merged the implementation.

Only after that merge, and the post-merge mechanical re-audit called for in the A2 visual contract's own §28, may a separately-authorized A2 visual implementation task begin. This remediation contract does not authorize that task, A3, or B1.

---

## 11. Stop conditions for this contract-drafting task

None were triggered in the original draft or in this correction round. Specifically:

- No genuine defect requiring a schema/migration change was found (§4.3, §6).
- No security defect inseparable from Finding 6 beyond Finding 6 itself was found (§5), including after the architecture correction.
- The exact 5-path allowlist was mechanically re-derivable with a stated reason for every path — no ambiguity requiring a stop.

---

## 12. Stale-claim sweep (Correction Round 1)

Mechanically swept the full corrected document for claims left over from the rejected controller-body architecture. Confirmed none remain live:

- "controller-body checks run before `FormRequest` validation" — removed; §3.3 now states the opposite (the rejection reason) and explicitly labels the original architecture rejected/superseded.
- "injecting `WorkspaceManager` into `BusinessController` alone solves Finding 6" — removed; §3.3 point 12 explicitly states `BusinessController` is not modified at all.
- "Finding 6 requires only `BusinessController.php`" — removed; §9 explicitly supersedes the 3-path allowlist and states the file is removed from it.
- "the future remediation allowlist contains exactly 3 paths" — corrected to 5 throughout (§9, §10).
- "inactive malformed PUT may validate before authorization" — removed; §3.5 and §7 scenario 6 now lock the opposite as a mandatory, explicitly-tested guarantee.
- "no-primary-Business PUT always redirects regardless of payload validity" — corrected; §3.5 and §7 scenarios 8-9 now distinguish valid-payload (redirects) from malformed-payload (ordinary validation error, unchanged pre-existing behavior) cases.
- "controller-body authorization gives exact GET/PUT parity" — replaced with the stronger, structurally-guaranteed group-middleware parity claim (§3.3 point 11).
- "the old completion criteria/path threshold remain valid after architecture correction" — §10 rewritten with the new 5-path count and the mandatory ordering-proof test.

Any remaining reference to the original 3-path/controller-body design is explicitly labeled "original draft," "rejected," or "superseded" and appears only as historical context (§1, §9's opening sentence), never as a live requirement.
