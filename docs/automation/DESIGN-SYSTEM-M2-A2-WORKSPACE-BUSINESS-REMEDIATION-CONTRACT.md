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
correction_round: 0
correction_round_is_final: false

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

Sequence (mirrors the completed A1 precedent — visual contract → nonvisual remediation contract PR #189 → remediation implementation PR #190 → visual implementation PR #191 — while using architecture specific to A2's actual code, not copied from A1's):

```
A2 visual contract — PR #192 — MERGED
  ↓
A2 nonvisual remediation contract — THIS TASK
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

Confirmed via `git log -1 --format="%H %P %s"`: this is the merge of PR #192 ("Design System M2 A2 — Workspace & Business Contract"), parents `5be68c00ee146c34f2fd9ef8985389309db6c7e8` (PR #191 merge) and `1e2770b9afbcfd9ccc01e43b005b1a9cdc6e7423` (the A2 visual contract's Correction Round 2 final commit). The merged `docs/automation/DESIGN-SYSTEM-M2-A2-WORKSPACE-BUSINESS-CONTRACT.md` was re-read from `origin/main` and confirmed to carry `correction_round: 2`, `correction_round_is_final: true`, `a2_visual_status: blocked_until_nonvisual_workspace_business_remediation_human_merged`, `security_pre_audit_status: complete_with_blocking_authorization_drift`, and exactly two blockers (`blocking_authorization_prerequisite_count: 1`, `blocking_nonsecurity_correctness_prerequisite_count: 1`). Branch `chore/design-system-m2-a2-workspace-business-remediation-contract` was created fresh from this exact commit via `git worktree add -b`.

---

## 3. Finding 6 — inactive-Workspace direct-Business-access drift

### 3.1 Exact current defect (mechanically re-confirmed against `origin/main` at `59ac514...`)

- **Routes** (`routes/customer.php:522-525`): `GET business` → `customer.business.edit`; `PUT business` → `customer.business.update`. Both session-derived — neither takes a Business identifier in the URL; both always resolve "the authenticated customer's own primary Business."
- **Controller** (`app/Http/Controllers/Customer/BusinessController.php`): `edit()` and `update()` both resolve `$business = $this->businessRepository->findPrimaryByCustomer($customer->user_id)` and, if non-null, proceed directly — **no call to `WorkspaceManager::userCanAccessBusiness()`/`assertUserCanAccessBusiness()`, and no check of `$business`'s Workspace `is_active` state, anywhere in this file.**
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

public function assertUserCanAccessBusiness(int $userId, Business $business): void
{
    if (! $this->userCanAccessBusiness($userId, $business)) {
        throw new WorkspaceAccessDeniedException($userId, $business->id);
    }
}
```

No `is_admin` branch exists — this method is customer-side only; platform-admin access is governed entirely separately (RFC-003 §14 path 1) and `Admin\BusinessController` never calls `WorkspaceManager` at all (confirmed by grep — zero references). Admin is structurally unaffected by this remediation.

**Established, repeated app-wide precedent for denying access via this exact function** — 5 existing call sites, all customer-side, all single-Business-resource resolution, all deny with `abort(404)`:

| Call site | Form used | Denial behavior |
|---|---|---|
| `WorkspaceController::reassignBusiness()` (target-Business resolution) | `assertUserCanAccessBusiness()` → catch `WorkspaceAccessDeniedException` | `abort(404)` |
| `UsageBillingController::resolveViewableBusiness()` | `! userCanAccessBusiness(...)` inline | `abort(404)` |
| `UsageBillingAutoRechargeController` (same pattern) | `! userCanAccessBusiness(...)` inline | `abort(404)` |
| `UsageBillingPaymentMethodController` (same pattern) | `! userCanAccessBusiness(...)` inline | `abort(404)` |
| `UsageBillingTopUpController` (same pattern) | `! userCanAccessBusiness(...)` inline | `abort(404)` |

`UsageBillingController::resolveViewableBusiness()`'s own docblock states the rationale directly: *"An unknown Workspace/Business uid, or a Business this actor cannot view, both fail closed identically with 404 (M2 contract §7) — this route can never be used to probe for existence."* This is the established convention `BusinessController` must join, not a new one to invent.

### 3.3 Selected architecture (smallest change, reuses the existing seam, zero duplication)

1. Inject `WorkspaceManager $workspaceManager` into `Customer\BusinessController`'s constructor, alongside the existing `BusinessManager`/`BusinessRepository` dependencies.
2. In `edit()`, after `$business = $this->businessRepository->findPrimaryByCustomer(...)` resolves non-null: add `if (! $this->workspaceManager->userCanAccessBusiness($customer->user_id, $business)) { abort(404); }` before returning the view. (Capture `$customer = $this->customer();` once at the top of the method, reused for both the repository lookup and the access check — a minor internal tidy, not a behavior change.)
3. In `update()`: identical check, in the same position, before the existing `try { $this->businessManager->updateBusiness(...) }` block.
4. No change to `BusinessManager`, `assertOwnership()`, `EloquentBusinessRepository`, `Admin\BusinessController`, `WorkspaceManager` itself, any route, or any middleware.
5. No new locking/transaction. `userCanAccessBusiness()` is a plain, unlocked read at all 5 existing call sites (including the one inside a `DB::transaction()` in `reassignBusiness()`, where it is still called unlocked relative to its own Workspace/Business rows) — consistent with treating "can this user currently see this Business" as an ordinary read-time check, not a race-sensitive mutation gate. RFC-003 §22's "lock and recheck authoritative rows" invariant governs Workspace *mutations* (rename, reassignment, ownership transfer), not this kind of read-access check; none of the 5 existing precedent call sites lock either.

### 3.4 Why membership/scope semantics do not matter to this specific route

`findPrimaryByCustomer($customer->user_id)` only ever resolves a Business whose `customer_id === $customer->user_id`. Since `$customer->user_id === Auth::id()` for the authenticated actor, the `userId` argument passed to `userCanAccessBusiness()` will always equal the resolved Business's own `customer_id`. Mechanically tracing the algorithm (§3.2): the **only** branches that can flip the result away from "true" for this specific call pattern are the Workspace-active gate (line 3, `! $workspace->is_active`) and the defensive `workspace_id === null` branch (line 1). The Workspace-owner-fallback and membership/scope branches are structurally unreachable here, since direct ownership is always checked first and always matches. **Staff/Admin/Owner membership role therefore has no bearing on this route's behavior** — it is not a route any Workspace member reaches on someone else's behalf; it is always "view/edit my own primary Business."

The `workspace_id === null` branch is defensive legacy-era handling (RFC-003 §14.1: "only occurs pre-M1B") — `businesses.workspace_id` is `NOT NULL`-enforced post-M1B (confirmed live: `WorkspaceEnforcementMigrationTest` covers this constraint), so this branch is not reachable on the current schema for any real row and requires no dedicated test.

### 3.5 Required post-remediation HTTP behavior

| Scenario | GET `customer.business.edit` | PUT `customer.business.update` |
|---|---|---|
| No primary Business exists for the customer | Redirect to `customer.onboarding.show` (existing, **unchanged**) | Redirect to `customer.onboarding.show` (existing, **unchanged**) |
| Primary Business exists, its Workspace is **active** | 200, form renders (existing, **unchanged**) | Validates and persists; redirect with `status=success` (existing, **unchanged**) |
| Primary Business exists, its Workspace is **inactive** | **404** (currently: 200 — this is the fix) | **404**, zero Business mutation persisted (currently: succeeds — this is the fix) |

"Unrelated customer" is not a reachable, distinct state for this route (§3.4's session-derived-resolution finding) — there is no way to address another customer's Business through this URL shape at all, before or after remediation. It collapses into the existing "no primary Business" row above and needs no new scenario beyond confirming that row is unaffected by this change.

---

## 4. Finding 7 — customer "Other" industry impossible submission

### 4.1 Exact current defect (mechanically re-confirmed)

- `App\Enums\Business\BusinessIndustry` has 7 cases including `Other = 'other'`.
- `resources/views/customer/business/edit.blade.php`'s industry `<select>` loops over all 7 cases (`Other` included) — **but the view contains zero `industry_other` field** (confirmed: `grep -c industry_other` → `0`).
- `App\Http\Requests\Business\UpdateBusinessRequest::rules()` has `'industry_other' => ['nullable', 'string', 'max:255', 'required_if:industry,' . BusinessIndustry::Other->value]` — a customer who selects `Other` and submits therefore always fails validation, with no rendered field to resolve it.

### 4.2 Everything downstream of the view is already correct — no backend change needed

- `Business::$fillable` already includes `industry_other` (`app/Models/Business.php:22`).
- `database/migrations/2026_07_18_120001_create_businesses_table.php` already defines the `industry_other` column — **the schema already exists; no migration is needed or authorized.**
- `EloquentBusinessRepository::update()`'s `Arr::except()` protected-field list does **not** exclude `industry_other` — a submitted value already persists correctly once present in `$request->validated()`.
- `UpdateBusinessRequest::prepareForValidation()` already normalizes an empty-string `industry_other` to `null` when the field is present in the request at all — no new normalization logic is needed provided the customer form submits the field (even blank) on every request, matching the two existing precedents below.

**The entire defect is a single missing Blade field.** No enum, validation, model, repository, or migration change is required or authorized.

### 4.3 Existing, established pattern to reuse (not invent)

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

### 4.4 Selected architecture (smallest change, matches existing precedent exactly)

Add one field to `resources/views/customer/business/edit.blade.php`, positioned immediately after the existing industry `<select>` (mirroring both precedents' placement and label text exactly):

```blade
<div class="mb-1">
    <label class="form-label" for="industry_other">Industry (other)</label>
    <input type="text" class="form-control" id="industry_other" name="industry_other" maxlength="255" value="{{ old('industry_other', $business->industry_other) }}">
</div>
```

No JS. No conditional visibility. No stale-value clearing on transition away from `Other` — this deliberately matches the existing, established convention (neither precedent clears the field on transition either); the value simply becomes irrelevant, not actively erased, when `industry !== other`. Inventing auto-clear behavior here would be new functionality beyond what either blocker requires or what any existing pattern in this codebase does.

### 4.5 Required post-remediation behavior

| Scenario | Result |
|---|---|
| Select `Other`, submit valid `industry_other` | Persists both fields; success |
| Select `Other`, submit blank/missing `industry_other` | Validation error on `industry_other`; `old()`-redisplay preserves the customer's other entered values; zero mutation |
| Select a standard industry, submit (with or without `industry_other`) | Succeeds exactly as today (regression check) |
| GET edit for a Business whose current `industry` is `Other` | Field pre-filled with the existing stored `industry_other` value |
| GET edit for a Business whose current `industry` is a standard value | Field renders present but empty (or with whatever stale value exists — never hidden), matching both existing precedents |
| Transition `Other` → standard industry | Succeeds; any previously stored `industry_other` value is **not** auto-cleared (matches existing app-wide convention, §4.4) |
| Transition standard → `Other` | Succeeds once a valid `industry_other` is supplied |
| Admin form / admin behavior | Entirely unaffected — already correct, not touched |

---

## 5. Security audit (both blockers)

| # | Area | Verified position |
|---|---|---|
| 1 | Tenant isolation | Unaffected — `findPrimaryByCustomer()`'s own `customer_id` scoping is untouched; the new check only ever narrows access further (adds a Workspace-active requirement), never widens it |
| 2 | Business ownership checks | `assertOwnership()` remains exactly as-is, still called, still a defense-in-depth backstop independent of the new check |
| 3 | Workspace-active gating | Now enforced on this route via the existing, unmodified `userCanAccessBusiness()` — no second, divergent gate is introduced |
| 4 | Workspace membership semantics | Proven irrelevant to this route (§3.4) — no membership/scope logic is added, none is needed |
| 5 | Inactive-Workspace behavior | Now correctly denies (404) instead of silently permitting — the actual security fix |
| 6 | Privilege boundaries | No new privilege is granted anywhere; the change is strictly a new denial path |
| 7 | Customer vs. platform-admin separation | `Admin\BusinessController` is untouched and still never calls `WorkspaceManager` — RFC-003 §14 path 1 remains intact |
| 8 | Mass assignment | No field-set change to `UpdateBusinessRequest`/`Arr::except()` — `industry_other` was already safely fillable and already excluded from the 5 explicitly-protected fields, unaffected by this remediation |
| 9 | Route-model binding | Not applicable — this route resolves no bound model; both endpoints remain session/customer-derived only |
| 10 | Information disclosure | `abort(404)` matches the established, deliberately non-disclosing convention used identically at all 5 existing `userCanAccessBusiness()` call sites — no new disclosure surface |
| 11 | Authorization order | New check runs after `findPrimaryByCustomer()` resolves a business and before any further work in both `edit()` and `update()` — no business logic executes before authorization in either method |
| 12 | Update authorization parity with read authorization | Both `edit()` (GET) and `update()` (PUT) receive the identical check — no asymmetry |
| 13 | No bypass through direct URLs | Confirmed — this route takes no identifier at all; it is not addressable by URL manipulation, only by the authenticated session's own `customer_id` |
| 14 | No cross-customer inference/mutation | Confirmed — was already true before this remediation (§3.5) and remains true after; this remediation does not change that property, it fixes a different one (inactive-Workspace bypass for the *same* customer's own Business) |
| 15 | No regression on active-Workspace paths | Explicitly locked in §3.5's behavior table and §6's test matrix (row: active Workspace, unchanged) |

**No security defect inseparable from Finding 6's fix was discovered.** No general Workspace security audit was performed or is authorized — scope remains exactly the two blockers.

---

## 6. Correctness audit (Finding 7)

| Area | Verified position |
|---|---|
| Validation/persistence consistency | `UpdateBusinessRequest` and `EloquentBusinessRepository::update()` already correctly validate and persist `industry_other` — confirmed no change needed |
| Rendered-control/validation consistency | This is precisely the gap being closed — the new field makes the rendered form match what the request already requires |
| Edit-state population | `old('industry_other', $business->industry_other)` matches the exact established pattern from `admin/businesses/edit.blade.php` |
| Validation-error repopulation | Already works correctly for every other field on this form via the same `old()` mechanism; the new field inherits it identically, no special-casing needed |
| Enum-value compatibility | `BusinessIndustry::Other->value === 'other'` matches `UpdateBusinessRequest`'s `required_if:industry,other` exactly — confirmed, no drift |
| Other → standard / standard → Other transitions | Covered in §4.5; no clearing logic needed or added |
| Customer/admin behavior boundaries | Full parity achieved — this is in fact the *minimal* fix, since it makes the customer form match the admin form's already-correct pattern rather than diverge from it |
| Existing Business records | Any Business already carrying a stored `industry_other` value (e.g., created via the Workspace's own "Create Business" form, which already collects it) will now correctly display it on the customer edit form for the first time — a pure bugfix, not a data change |
| Nullability/requiredness semantics | Unchanged — `industry_other` remains `nullable` with `required_if:industry,other`, exactly as already defined |
| Schema/migration change | **Not required.** The column, model fillability, and repository behavior are already complete; confirmed by direct inspection of the migration and model (§4.2). `schema_or_migration_change_required: false` (§0). |

---

## 7. Test plan

**No existing HTTP-level test file covers `Customer\BusinessController::edit()`/`update()`** — confirmed by repository-wide search (`grep -rln "customer.business.update\|customer.business.edit" tests/` matches only `AdminBusinessControllerTest.php`, which tests the *admin* route of a similar name, and `OpportunityQueueHttpTest.php`, which uses the route only as unrelated fixture setup). `WorkspaceManager::userCanAccessBusiness()` itself is already exhaustively covered by `tests/Feature/Workspace/WorkspaceEffectiveAccessTest.php` and is **not modified** by this remediation, so its own algorithm correctness needs no new coverage — only the controller's *wiring* to it does. This makes a new, focused HTTP test file the correct fit, mirroring the naming precedent of `AdminBusinessControllerTest.php`.

**New file:** `tests/Feature/Business/CustomerBusinessControllerTest.php`

Reuses existing fixture helpers — `Tests\Feature\Business\Concerns\CreatesBusinessTestData` (`createCustomer()`, `businessAttributes()`) and `Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData` (`createWorkspace($owner, $overrides)`, `createBusinessForCustomer($customerId, $workspaceId)`). **Implementation note:** `createBusinessForCustomer()` does not set `is_primary` (column default `false`); every fixture in this file must explicitly set `is_primary = true` after creation, since `findPrimaryByCustomer()` filters on it.

**Finding 6 coverage (minimum):**
1. Active Workspace, rightful customer, `GET customer.business.edit` → 200, form renders (regression).
2. Active Workspace, rightful customer, `PUT customer.business.update` with valid data → redirect + `status=success`, fields persisted (regression).
3. Inactive Workspace, same rightful customer, `GET customer.business.edit` → 404.
4. Inactive Workspace, same rightful customer, `PUT customer.business.update` → 404, and the Business's stored attributes are byte-identical before/after the denied attempt (zero mutation).
5. No primary Business at all, `GET`/`PUT` → redirect to `customer.onboarding.show` (regression — confirms the unrelated "no business" path is untouched by this change).

**Finding 7 coverage (minimum):**
6. `industry=other` + valid `industry_other` → success, both fields persisted.
7. `industry=other` + blank/missing `industry_other` → validation error on `industry_other`, zero mutation, `old()` reflects the submitted (non-industry_other) fields.
8. Standard industry, with or without `industry_other` → success (regression).
9. `GET edit` for a Business whose current industry is `Other` → response contains the existing `industry_other` value.
10. Submitting a transition from `Other` to a standard industry (no `industry_other` required) → succeeds; the business's stored `industry_other` column is not asserted to change (documents the deliberate non-clearing behavior from §4.4, so a future change to that behavior is a visible, deliberate decision rather than an accidental regression).

**Explicitly out of scope for this test file:** Design System visual/adoption tests (this is nonvisual remediation only, per the task's own instruction), and any re-testing of `userCanAccessBusiness()`'s own algorithm (already covered by `WorkspaceEffectiveAccessTest.php`, unmodified here).

---

## 8. Regression baseline and test policy

No PHPUnit run was performed for this docs-only contract task (consistent with every prior Design System M2 contract-only task in this sequence — A1's remediation contract, A2's visual contract Rounds 1 and 2). The most recent known evidence is **historical context only, not gathered fresh in this task, and not the baseline for the future implementation**: A1's post-implementation full suite, 3779 tests / 13591 assertions / 1 pre-existing unrelated `BrandingAdminFooterRenderTest` failure / 0 skipped, at commit `c3fcff3b84100ae03d600995220b2fae0a823ae3`. Application code has not changed between that commit and this contract's base SHA (`59ac514...` is a chain of docs-only merges on top of it), but the future implementation task must not assume this number is still current without re-gathering it from its own exact base — it is cited here only as prior evidence that the repository's test suite executes cleanly modulo that one known, unrelated failure.

**Policy for the future remediation implementation task:**

1. Establish the exact implementation base commit (this remediation contract's own human-merged `main`).
2. Run the 4 focused suites this remediation touches, in this order: the new `tests/Feature/Business/CustomerBusinessControllerTest.php`; `tests/Feature/Business/BusinessManagerTest.php` and `tests/Feature/Business/BusinessRepositoryTest.php` (unmodified but adjacent — confirm no incidental regression); `tests/Feature/Workspace/WorkspaceEffectiveAccessTest.php` (confirms `userCanAccessBusiness()` itself is untouched and still exhaustively passes); `tests/Feature/Business/AdminBusinessControllerTest.php` (confirms admin remains unaffected).
3. Collect a full-suite run at that same base **before** implementing, only if the product-code tree at that base has not already been verified clean by a prior task (the same `git rev-parse ...^{tree}` efficiency check used throughout this sequence) — labeled as the pre-remediation baseline, evidence-only.
4. Implement.
5. Run the full suite again post-implementation.
6. Report exact pre/post totals. The post-implementation total is expected to **increase** by exactly the new test file's own method count — this is not a discrepancy. **Zero newly introduced failures** is required. Any failure classified as pre-existing/unrelated must be mechanically demonstrated present and identical on **both** the pre- and post-implementation runs before being excluded — a failure seen only post-implementation is never pre-existing by definition and must be treated as a regression.

---

## 9. Future implementation path allowlist

Mechanically derived — every path has a stated reason, nothing speculative:

**Production (2 paths):**
1. `app/Http/Controllers/Customer/BusinessController.php` — Finding 6: inject `WorkspaceManager`, add the `userCanAccessBusiness()` check to `edit()` and `update()` (§3.3).
2. `resources/views/customer/business/edit.blade.php` — Finding 7: add the `industry_other` field (§4.4).

**Test (1 path):**
3. `tests/Feature/Business/CustomerBusinessControllerTest.php` — new file, HTTP coverage for both findings (§7).

**Total: exactly 3 paths.**

**Stop threshold: a 4th changed path is a mandatory STOP condition requiring human review.** No other production file (`app/Library/Business/BusinessManager.php`, `app/Repositories/Eloquent/EloquentBusinessRepository.php`, `app/Http/Requests/Business/UpdateBusinessRequest.php`, `app/Models/Business.php`, any migration, `app/Http/Controllers/Admin/BusinessController.php`, any route file) is authorized to change under this contract, since §3–§6 mechanically established none of them require a change. If the implementation task discovers one of them genuinely does, it must stop and report rather than silently widening this allowlist. This contract does not include, and does not authorize touching, the already-merged A2 visual contract (`docs/automation/DESIGN-SYSTEM-M2-A2-WORKSPACE-BUSINESS-CONTRACT.md`) — implementation is not coupled to that document; a post-implementation re-audit of it happens as its own, separate step (§1's sequence diagram, step "re-baseline/reverify").

---

## 10. Completion criteria

Remediation implementation is complete only when all of the following hold simultaneously:

- Exactly the 3 paths in §9 changed (or fewer — never more without a STOP-and-report).
- All 10 test scenarios in §7 pass.
- The full regression suite shows zero newly introduced failures per §8's policy.
- No schema or migration file was added or changed.
- `Admin\BusinessController` and `admin/businesses/edit.blade.php` are provably unchanged (git diff shows no touch).
- The A2 visual contract document is provably unchanged.
- A human has reviewed and merged the implementation.

Only after that merge, and the post-merge mechanical re-audit called for in the A2 visual contract's own §28, may a separately-authorized A2 visual implementation task begin. This remediation contract does not authorize that task, A3, or B1.

---

## 11. Stop conditions for this contract-drafting task

None were triggered. Specifically:

- No genuine defect requiring a schema/migration change was found (§4.2, §6) — had one been found, this task would have stopped rather than silently authorizing it.
- No security defect inseparable from Finding 6 beyond Finding 6 itself was found (§5) — had one been found, this task would have stopped rather than expanding scope.
- The exact 3-path allowlist was mechanically derivable with a stated reason for every path — no ambiguity requiring a stop.
