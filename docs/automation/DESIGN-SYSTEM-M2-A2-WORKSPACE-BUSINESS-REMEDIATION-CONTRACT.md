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
correction_round: 2
correction_round_is_final: true

contract_status: final_pending_human_review

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

**Correction Round 1** replaced the original controller-body architecture for Finding 6 with route middleware (`EnsureBusinessProfileIsAccessible`), since a controller-body check cannot run before `UpdateBusinessRequest`'s validation on the PUT route.

**Correction Round 2 (this revision, FINAL):** a fresh, independent audit found Round 1's middleware-only design left a genuine time-of-check-to-time-of-use (TOCTOU) gap. The middleware performs an *unlocked* read of `workspace.is_active`; the actual Business mutation happens later, inside `BusinessManager::updateBusiness()`'s own separate transaction. If a Workspace is deactivated in the window between the middleware's read and that later transaction's write, the write could still commit against an now-inactive Workspace — violating this contract's own required outcome ("a denied update must persist zero Business mutation") even though it does not violate RFC-003 §18's literal scope (§3.6 explains this precisely — §18 constrains *Workspace/membership-domain* mutating operations specifically, not every unrelated mutation that happens to touch a Business row; the gap is closed here because it defeats *this remediation's own* stated goal, not because RFC-003 mandates locking for every Business write everywhere). This revision adds a second, independent guarantee — **Boundary B**, a mutation-time authoritative recheck inside the same transaction that performs the write — alongside Round 1's **Boundary A** (the pre-validation HTTP gate, retained unchanged). The future implementation allowlist is re-derived from 5 to 8 paths accordingly (§9). Finding 7 was re-audited a second time and remains unchanged (§4).

Sequence (mirrors the completed A1 precedent — visual contract → nonvisual remediation contract PR #189 → remediation implementation PR #190 → visual implementation PR #191 — while using architecture specific to A2's actual code, not copied from A1's):

```
A2 visual contract — PR #192 — MERGED
  ↓
A2 nonvisual remediation contract — THIS TASK (Correction Round 2, FINAL)
  ↓
human review
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

No stage advances automatically (§0). No Correction Round 3 exists for this contract — any further issue found after this point requires a separate, human-authorized exception process.

---

## 2. Base verification

```
git fetch origin --tags
git rev-parse origin/main
59ac514dccf077f5fbf6f8b8888e39737f78585a
```

Re-confirmed unchanged at the start of this correction: `origin/main` is still exactly `59ac514...` (the PR #192 merge), the branch's starting HEAD was exactly `ec1034aadb46001621d1ad44912fe5eca84312db` (Correction Round 1's own commit, sole ancestor chain back to `59ac514...`), exactly 2 ahead / 0 behind, and the aggregate diff contained exactly this one document before any Round 2 edit was made. The merged A2 visual contract and `AI-AUTONOMY-STATE.json` were both re-confirmed untouched.

---

## 3. Finding 6 — inactive-Workspace direct-Business-access drift

### 3.1 Exact current defect (mechanically re-confirmed against `origin/main` at `59ac514...`)

- **Routes** (`routes/customer.php:522-525`): `GET business` → `customer.business.edit`; `PUT business` → `customer.business.update`. Both session-derived — neither takes a Business identifier in the URL.
- **Controller** (`app/Http/Controllers/Customer/BusinessController.php`): `edit()` and `update(UpdateBusinessRequest $request)` both resolve `$business = $this->businessRepository->findPrimaryByCustomer($customer->user_id)` and, if non-null, proceed directly — no Workspace-active check anywhere.
- `update()` calls `BusinessManager::updateBusiness()`, whose only authorization check, `assertOwnership()`, checks `customer_id` only, never `workspace_id`/Workspace state.
- RFC-003 §14/§14.1/§22 are unambiguous that direct Business access must be gated behind Workspace-active state.

### 3.2 Existing correct implementation of the access decision

`WorkspaceManager::userCanAccessBusiness()` (`app/Library/Workspace/WorkspaceManager.php:97-135`) already implements RFC-003 §14.1 correctly — gates the Workspace-active check before every other branch, has no `is_admin` bypass (customer-side only; platform-admin is governed entirely separately, RFC-003 §14 path 1). 5 existing customer-side call sites already deny access via this exact function with `abort(404)`: `WorkspaceController::reassignBusiness()`'s target-Business resolution, and all 4 `UsageBilling*Controller`s. This method is **reused verbatim by both boundaries below — never reimplemented.**

### 3.3 Boundary A — HTTP pre-validation gate (retained from Correction Round 1, re-audited, unchanged)

**Why route middleware, not a controller-body check.** `update()` type-hints `UpdateBusinessRequest`, which Laravel resolves and validates during controller-method dependency injection — before the method body executes. A controller-body check cannot guarantee an inactive-Workspace denial precedes a malformed PUT's validation. This is the exact ordering fact the merged A1 onboarding remediation contract already established (reused as *process* precedent only — A2's own architecture below is derived from A2's own code).

**Existing precedent.** `App\Http\Middleware\EnsureBusinessOnboardingIsEnabled` (stateless config check, applied to the whole `onboarding` group via `->middleware('business.onboarding.enabled')`) and `App\Http\Middleware\EnsureRequiredBusinessOnboardingIsComplete` (constructor-injected repository/manager, resolves `auth()->user()->customer`, resolves a per-customer domain row, passes through when absent/inapplicable, applied to a single route via `->middleware('business.onboarding')`) — both registered as named aliases in `app/Http/Kernel.php`'s `$routeMiddleware` array. This is the established, mechanically-confirmed pattern for "resolve the authenticated customer's own domain object, gate on its state, before controller/FormRequest dispatch."

**Locked design (re-audited, unchanged from Round 1):**

1. **New file:** `app/Http/Middleware/EnsureBusinessProfileIsAccessible.php`, class `EnsureBusinessProfileIsAccessible`.
2. **Dependencies:** `App\Repositories\Contracts\BusinessRepository $businessRepository`, `App\Library\Workspace\WorkspaceManager $workspaceManager`.
3. **Actor resolution:** `auth()->user()->customer`. If `null`, pass through unchanged (an edge case this remediation does not alter — `BusinessController::customer(): Customer`'s own non-nullable return type already assumes this is guaranteed for anyone reaching this controller).
4. **Primary-Business lookup:** `$this->businessRepository->findPrimaryByCustomer($customer->user_id)` — the same repository method the controller already calls, not duplicated.
5. **No-primary-Business behavior:** if `$business === null`, pass through — defers entirely to the controller's own existing, unmodified logic.
6. **Access check:** `if (! $this->workspaceManager->userCanAccessBusiness($customer->user_id, $business)) { abort(404); }`. No JSON-specific handling — neither `customer.business.edit` nor `customer.business.update` is JSON-consumed by anything in this app (confirmed by grep).
7. **Pass-through on success:** `return $next($request)`.
8. **Alias:** `business.profile.accessible`, registered in `app/Http/Kernel.php` immediately after the two existing `business.onboarding*` entries.
9. **Application point** (`routes/customer.php`):
   ```php
   Route::prefix('business')->name('business.')->middleware('business.profile.accessible')->group(function () {
       Route::get('/', 'BusinessController@edit')->name('edit');
       Route::put('/', 'BusinessController@update')->name('update');
   });
   ```
10. **Ordering:** route middleware runs strictly before Laravel resolves the controller method's own type-hinted dependencies, including `FormRequest` injection.
11. **GET and PUT share the identical seam** — group-level application, not per-route, so both pass through one middleware instance.
12. **What Boundary A alone does NOT close** (this is the Correction Round 2 finding, §3.5): the middleware's `userCanAccessBusiness()` read is unlocked. A Workspace deactivation racing between this read and the eventual write is not prevented by Boundary A alone — that is Boundary B's job (§3.4).

### 3.4 Boundary B — mutation-time authoritative recheck (new in Correction Round 2)

**Why this is needed — the TOCTOU gap, mechanically traced:**

1. Route middleware (Boundary A) reads `workspace.is_active` unlocked and passes through.
2. `UpdateBusinessRequest` validates.
3. Controller calls into `BusinessManager`.
4. `BusinessManager::updateBusiness()` calls `assertOwnership()` — checks `customer_id` only.
5. `applyIdentity()` opens a **new, separate** transaction and writes via `BusinessRepository::update()`.
6. Between steps 1 and 5, nothing re-locks or re-reads the Workspace's `is_active` state. A concurrent `WorkspaceManager::deactivateWorkspace()` (itself correctly using `findForUpdate()` under its own transaction) committing in that window would not be seen by this flow at all — the Business write in step 5 would proceed and commit regardless.

**Scope of the fix — precisely bounded, not a general RFC-003 reinterpretation.** RFC-003 §18 opens with "Specified for Milestone 2's *mutating operations*" and its enumerated locked operations (ownership transfer, membership creation, scoped-assignment creation, Business reassignment) are all `WorkspaceManager`-domain operations. `BusinessManager::updateBusiness()` is RFC-001's Business Core domain — it never touches `workspace_id` and is not one of §18's enumerated operations. **This correction does not claim RFC-003 requires locking for every Business mutation everywhere.** It closes this specific gap because *this remediation's own* required outcome (§3.5's behavior table, unchanged since Round 0/1: "a denied update must persist zero Business mutation") is not fully achieved by an HTTP-layer-only unlocked check, and RFC-003 §14.2's own locking discipline ("every mutating operation locks and rechecks authoritative rows... inside its transaction") is the established, precedent-matching technique already used in this exact codebase (`WorkspaceManager::reassignBusiness()`) for protecting a decision that depends on Workspace state against exactly this class of race.

**Every existing `BusinessManager::updateBusiness()` caller, audited (§3.7) — the fix must not touch any of them.** `Admin\BusinessController` must never be gated by Workspace-active state at all (RFC-003 §14 path 1). `OnboardingActionExecutor`, `OnboardingManager::saveAssetsStep()`, and `OpportunityActionExecutor` are unrelated flows this A2 remediation is not chartered to change. Modifying the shared `updateBusiness()`/`applyIdentity()` method itself would affect all five callers — rejected. The fix is therefore a **new, additive, narrowly-named method used only by the customer-direct-profile route**, leaving `updateBusiness()`, `applyIdentity()`, and `assertOwnership()` byte-for-byte unmodified.

**Locked design:**

New public method on `BusinessManager`: `updateOwnBusinessProfile(Customer $customer, Business $business, array $attributes): Business`. Used **only** by `Customer\BusinessController::update()`.

```php
public function updateOwnBusinessProfile(Customer $customer, Business $business, array $attributes): Business
{
    return DB::transaction(function () use ($customer, $business, $attributes) {
        $expectedWorkspaceId = $business->workspace_id;

        // Lock the Workspace FIRST — matches reassignBusiness()'s own
        // established Workspace-then-Business lock order (RFC-003 §16.2,
        // §18) to avoid an inverse-order deadlock against it. Locking-only:
        // the actual decision re-reads via userCanAccessBusiness() below,
        // safely, since both rows are already held under this transaction's
        // locks by that point.
        if ($expectedWorkspaceId !== null) {
            $this->workspaceRepository->findForUpdate($expectedWorkspaceId);
        }

        $lockedBusiness = $this->businessRepository->findForUpdate($business->id);

        if ($lockedBusiness === null) {
            throw new WorkspaceAccessDeniedException($customer->user_id, $business->id);
        }

        if ($expectedWorkspaceId !== null && (int) $lockedBusiness->workspace_id !== $expectedWorkspaceId) {
            // Reused verbatim from reassignBusiness()'s identical scenario:
            // the caller's expected Workspace no longer matches the
            // now-locked, authoritative row — most likely a concurrent
            // reassignment. Fail safely rather than silently re-locking a
            // different Workspace.
            throw new BusinessWorkspaceMismatchException(
                $lockedBusiness->id,
                $expectedWorkspaceId,
                (int) $lockedBusiness->workspace_id
            );
        }

        if (! $this->workspaceManager->userCanAccessBusiness($customer->user_id, $lockedBusiness)) {
            throw new WorkspaceAccessDeniedException($customer->user_id, $lockedBusiness->id);
        }

        return $this->updateBusiness($customer, $lockedBusiness, $attributes);
    });
}
```

- **New constructor dependency:** `WorkspaceRepository $workspaceRepository`, added to `BusinessManager`'s existing constructor — safe, since `BusinessManager` is exclusively container-resolved everywhere (confirmed: zero `new BusinessManager(...)` anywhere in `app/` or `tests/`; `BusinessManagerTest.php` resolves it via `app(BusinessManager::class)`).
- **`WorkspaceManager.php` is not modified at all.** `userCanAccessBusiness()` is called completely unmodified; its own internal unlocked re-reads (`businessRepository->findById()`, `workspaceRepository->findById()`) are safe when called from inside this method, since by that point both rows are already held under this transaction's own `findForUpdate()` locks — no other transaction can have changed them without first blocking on those locks.
- **The actual write is fully reused, not duplicated:** the final line delegates to the existing, unmodified `updateBusiness()` (which re-checks `assertOwnership()` again — harmless, always passes here, matches that method's own existing "always re-check" defense-in-depth philosophy — then calls `applyIdentity()`'s existing transaction, which nests via Laravel's own savepoint-based transaction nesting on the same connection, remaining inside the outer lock the whole time). Since `$lockedBusiness` is always non-null when passed in, `applyIdentity()`'s create-branch (with its own, unrelated Workspace-locking-for-creation logic) never executes in this path — only the plain, non-locking `BusinessRepository::update()` write path runs.
- **Exceptions reused, not invented:** `WorkspaceAccessDeniedException` and `BusinessWorkspaceMismatchException` both already exist (`app/Exceptions/Workspace/`), already used for structurally identical scenarios elsewhere (`assertUserCanAccessBusiness()`; `reassignBusiness()`'s own mismatch guard).

**Controller change required** (this reverses Correction Round 1's "BusinessController is not modified at all" claim — see §12's stale-claim sweep): `Customer\BusinessController::update()` must call `updateOwnBusinessProfile()` instead of `updateBusiness()`, and add one new catch clause:

```php
try {
    $this->businessManager->updateOwnBusinessProfile($customer, $business, $request->validated());
} catch (WorkspaceAccessDeniedException|BusinessWorkspaceMismatchException) {
    abort(404);
} catch (InvalidArgumentException) {
    return redirect()->route('customer.business.edit')->withInput()->withErrors([...]);
}
```

This exact catch-and-`abort(404)` pattern for this exact exception pair already exists verbatim in `WorkspaceController::reassignBusiness()` — reused, not invented. **`edit()` (GET) is not modified at all** — it performs no mutation, so Boundary A alone is sufficient and correct for it (a GET reflecting a Workspace's state a few milliseconds before it changes is not a security-relevant race; nothing is committed).

### 3.5 Required post-remediation HTTP behavior (unchanged conclusions, now delivered by two boundaries)

| Scenario | GET `customer.business.edit` | PUT `customer.business.update` |
|---|---|---|
| No primary Business, payload would **pass** validation | Redirect to `customer.onboarding.show` (existing, unchanged) | Boundary A passes through (no Business to gate); validation succeeds; controller's existing null-check redirects to onboarding (existing, unchanged) |
| No primary Business, payload is **malformed** | N/A | Boundary A passes through; `UpdateBusinessRequest` validation fails first — ordinary validation-error response, **not** the onboarding redirect. Pre-existing, unmodified behavior, unaffected by either boundary. |
| Primary Business exists, Workspace **active** | 200 (unchanged) | Boundary A passes through; Boundary B's recheck passes (same `userCanAccessBusiness()` result); validates and persists (unchanged) |
| Primary Business exists, Workspace **inactive**, well-formed request | **404** (Boundary A) | **404** (Boundary A, normally — before validation even runs), zero mutation |
| Primary Business exists, Workspace **inactive**, malformed request | N/A | **404** (Boundary A denies before `UpdateBusinessRequest` is ever resolved) |
| Workspace becomes inactive **after** Boundary A passes but **before** the write commits (the race) | N/A (no write on GET) | **404** (Boundary B denies inside the write's own transaction before any row is mutated), zero mutation. **This is the outcome Correction Round 1 could not guarantee and Correction Round 2 closes.** |
| Business concurrently reassigned to a different Workspace between request start and the write | N/A | Boundary B's mismatch check denies with the same 404 (fails safely, matching `reassignBusiness()`'s own precedent for this scenario) rather than silently locking the wrong Workspace |

Both boundaries produce the identical, non-disclosing `abort(404)` — the customer experience is uniform regardless of which boundary actually caught the denial.

### 3.6 Why membership/scope semantics do not matter to this route (unaffected by either boundary's design)

`findPrimaryByCustomer($customer->user_id)` only ever resolves a Business whose `customer_id === $customer->user_id`, and `$customer->user_id === Auth::id()`, so `userCanAccessBusiness()`'s `userId` argument always equals the resolved Business's own `customer_id` at both boundaries. The only branch that can flip the result is the Workspace-active gate (and the defensive, currently-unreachable `workspace_id === null` case, `NOT NULL`-enforced post-M1B). Staff/Admin/Owner membership role has no bearing on this route at either boundary.

### 3.7 Every `updateBusiness()` caller, audited for impact

| Caller | File | Impact of this remediation |
|---|---|---|
| `Customer\BusinessController::update()` | `app/Http/Controllers/Customer/BusinessController.php` | **Changed** — now calls the new `updateOwnBusinessProfile()` instead (§3.4). This is Finding 6's own target. |
| `Admin\BusinessController::update()` | `app/Http/Controllers/Admin/BusinessController.php` | **Unaffected** — still calls the unmodified `updateBusiness()` directly; never gated by Workspace-active state, matching RFC-003 §14 path 1 exactly (platform-admin access is governed entirely upstream and must remain so) |
| `OnboardingActionExecutor` (inline finding-action "Save", A1 Results step) | `app/Library/Business/OnboardingActionExecutor.php:90` | **Unaffected** — still calls the unmodified `updateBusiness()` directly |
| `OnboardingManager::saveAssetsStep()` | `app/Library/Business/OnboardingManager.php:233` | **Unaffected** — still calls the unmodified `updateBusiness()` directly |
| `OpportunityActionExecutor` (phone-update action, RFC-002) | `app/Library/Opportunity/OpportunityActionExecutor.php:90` | **Unaffected** — still calls the unmodified `updateBusiness()` directly |

`updateBusiness()`, `applyIdentity()`, and `assertOwnership()` are not modified in any way by this contract — every caller's own byte-for-byte source is unchanged; the only new code is the additive `updateOwnBusinessProfile()` method and the one call-site swap in `Customer\BusinessController::update()`. No RFC-003-unambiguous requirement exists to extend this gate to the other four callers, and doing so would be a scope expansion beyond this A2 remediation's own authorization — not performed here.

---

## 4. Finding 7 — customer "Other" industry impossible submission

### 4.1 Second fresh re-audit result (Correction Round 2): unchanged, confirmed still correct

Re-verified a second time against `origin/main` at `59ac514...` (still unchanged — no application code differs across any correction round so far): `BusinessIndustry` still has 7 cases including `Other = 'other'`; `Business::$fillable` still includes `industry_other`; the migration still defines the column; `EloquentBusinessRepository::update()`'s `Arr::except()` still does not exclude it; `UpdateBusinessRequest::rules()` still requires it via `required_if:industry,other`; the customer edit view still renders zero `industry_other` field; both existing precedents (`admin/businesses/edit.blade.php`, the Workspace's own Create-Business form) still render it identically, unconditionally. **No defect found. Architecture retained unchanged for the second consecutive round.**

### 4.2 Selected architecture (unchanged)

Add one field to `resources/views/customer/business/edit.blade.php`, immediately after the industry `<select>`:

```blade
<div class="mb-1">
    <label class="form-label" for="industry_other">Industry (other)</label>
    <input type="text" class="form-control" id="industry_other" name="industry_other" maxlength="255" value="{{ old('industry_other', $business->industry_other) }}">
</div>
```

No JS, no conditional visibility, no stale-value clearing (matches both existing precedents exactly). No backend, model, migration, or admin change. Full behavior table unchanged from Round 1 (§4.4 below).

### 4.3 Everything downstream of the view is already correct — no backend change needed

`Business::$fillable` already includes `industry_other`; the migration already defines the column; the repository's protected-field exception list already excludes it correctly (i.e., does not block it); `UpdateBusinessRequest::prepareForValidation()` already normalizes blank-to-null. **No schema/migration change required or authorized.**

### 4.4 Required post-remediation behavior

| Scenario | Result |
|---|---|
| Select `Other`, submit valid `industry_other` | Persists both fields; success |
| Select `Other`, submit blank/missing `industry_other` | Validation error on `industry_other`; `old()`-redisplay preserved; zero mutation |
| Select a standard industry, with or without `industry_other` | Succeeds exactly as today (regression) |
| GET edit, current industry is `Other` | Field pre-filled with the stored value |
| GET edit, current industry is standard | Field renders present but empty, never hidden |
| Transition `Other` → standard | Succeeds; stored `industry_other` is not auto-cleared (matches existing app-wide convention) |
| Transition standard → `Other` | Succeeds once a valid `industry_other` is supplied |
| Admin form/behavior | Entirely unaffected |

---

## 5. Security audit — final (both boundaries)

| # | Area | Verified position |
|---|---|---|
| 1 | Pre-validation authorization ordering (Boundary A) | Locked by construction — route middleware runs strictly before `UpdateBusinessRequest` resolution (§3.3 point 10) |
| 2 | Mutation-time authoritative recheck (Boundary B) | Locked by construction — `updateOwnBusinessProfile()` locks Workspace then Business inside the same transaction that performs the write, before delegating to it (§3.4) |
| 3 | TOCTOU closure | Both boundaries together close the full window: Boundary A denies most requests before any work begins; Boundary B denies the narrow race-window case where Workspace state changed between Boundary A's read and the write, inside the same transaction as the write, before any row is mutated |
| 4 | Deterministic lock ordering | Workspace locked before Business, matching `WorkspaceManager::reassignBusiness()`'s own established order exactly (§3.4) — avoids introducing an inverse-order deadlock against that existing operation |
| 5 | Stale Business model handling | The caller-supplied `$business`'s `workspace_id` is treated only as an initial "expected" value; the freshly-locked row's actual `workspace_id` is what's checked, with `BusinessWorkspaceMismatchException` on divergence (reused from `reassignBusiness()`'s identical precedent) |
| 6 | Concurrent Workspace deactivation | Closed — see rows 2/3; Boundary B's lock ensures no concurrent `deactivateWorkspace()` (which itself locks the Workspace row) can commit invisibly to this transaction |
| 7 | Concurrent Business reassignment | Closed by the mismatch check (row 5); `reassignBusiness()` itself locks the Business row it moves, so it cannot proceed while this transaction holds `updateOwnBusinessProfile()`'s own Business lock, and vice versa |
| 8 | Direct Business ownership | Unmodified — still the first successful branch of `userCanAccessBusiness()`, additionally backstopped by `assertOwnership()`'s own unmodified re-check inside the delegated `updateBusiness()` call |
| 9 | Workspace-active gating | Enforced at both boundaries via the same unmodified `userCanAccessBusiness()` |
| 10 | Tenant isolation | Unaffected — `findPrimaryByCustomer()`'s scoping is untouched; both new checks only narrow access further |
| 11 | Update/read authorization semantics | GET relies on Boundary A alone (correct — no mutation, no TOCTOU concern); PUT relies on both boundaries (correct — the only path that mutates) |
| 12 | No disclosure on denied HTTP access | Both boundaries produce the identical `abort(404)` — no behavioral difference a client could use to distinguish which boundary fired, or to infer Business/Workspace existence |
| 13 | No generic 500 from expected race/denial conditions | Both `WorkspaceAccessDeniedException` and `BusinessWorkspaceMismatchException` are explicitly caught in `Customer\BusinessController::update()` and mapped to `abort(404)` — neither reaches the framework's generic exception handler |
| 14 | Admin behavior unaffected | `Admin\BusinessController` still calls the unmodified `updateBusiness()` directly (§3.7); never touches `updateOwnBusinessProfile()`, `WorkspaceRepository`, or either boundary |
| 15 | No new privilege path | Both changes are strictly additional denial paths — no code grants access that was not already grantable |
| 16 | No duplicate, divergent access algorithm | `userCanAccessBusiness()` is called unmodified at both boundaries — never reimplemented, never forked |
| 17 | No schema/migration change | Confirmed for both findings (§4.3, and Boundary B introduces no new column/table — only additional `findForUpdate()` calls against already-existing repository methods) |
| 18 | No unrelated Workspace/member behavior change | `WorkspaceManager.php` is not modified at all; `WorkspaceManager::reassignBusiness()`, membership mutations, and ownership transfer are untouched by this contract |
| 19 | Every `updateBusiness()` caller audited | §3.7 — four of five callers provably unaffected; only the target route's own controller changes |

**No security defect requiring a third correction round or an out-of-scope architectural change was discovered.** This audit supersedes Correction Round 1's §5, which incorrectly concluded "no security defect inseparable from Finding 6's fix" without yet identifying the TOCTOU gap.

---

## 6. Correctness audit (Finding 7, re-confirmed unchanged for the second time)

| Area | Verified position |
|---|---|
| Validation/persistence consistency | Already correct; no change needed |
| Rendered-control/validation consistency | This is the gap being closed |
| Edit-state population | Matches the established `old('industry_other', $business->industry_other)` pattern exactly |
| Enum-value compatibility | `BusinessIndustry::Other->value === 'other'` matches `required_if:industry,other` exactly |
| Customer/admin parity | Achieved — the minimal fix, matching the admin form's already-correct pattern |
| Existing Business records | Any pre-existing stored `industry_other` (e.g., set via the Workspace's own Create-Business form) now correctly displays on the customer edit form for the first time — a pure bugfix |
| Schema/migration change | **Not required.** Confirmed for the second consecutive round (§4.1, §4.3). |

---

## 7. Test plan — final (Correction Round 2: Boundary B coverage added)

**No existing HTTP-level test file covers `Customer\BusinessController::edit()`/`update()`** (unchanged finding). `WorkspaceManager::userCanAccessBusiness()` is exhaustively covered by `WorkspaceEffectiveAccessTest.php` and is not modified. **A sequential HTTP test cannot, by itself, prove the TOCTOU race is closed** — an HTTP request/response cycle is single-threaded and cannot interleave a concurrent Workspace deactivation mid-request. Boundary B's own correctness (the lock/recheck logic itself) is therefore proven by a **direct, deterministic `BusinessManager`-level test**: deactivate the Workspace, *then* call `updateOwnBusinessProfile()` directly (bypassing HTTP/middleware entirely) and assert denial — this exactly reproduces "Workspace state changed before the mutation-time recheck ran" without needing genuine multi-process concurrency. The underlying locking primitive itself (`findForUpdate()`) is not new — it is the same mechanism already concurrency-tested elsewhere in this codebase (`WorkspaceManagerConcurrencyTest`, `EntitlementManagerConcurrencyTest`) for structurally identical Workspace/Business lock-and-recheck patterns; a new dedicated two-process concurrency test for this narrow addition is judged disproportionate scope beyond what's needed to prove *this* fix, and none is added.

**New file:** `tests/Feature/Business/CustomerBusinessControllerTest.php` — HTTP-level, end-to-end coverage (both boundaries as experienced by a real request, plus Finding 7):

*Active Workspace:*
1. Rightful customer, `GET` → 200 (regression).
2. Rightful customer, valid `PUT` → success, persisted (regression).
3. Rightful customer, invalid/malformed `PUT` → ordinary validation-error response remains reachable (proves neither boundary interferes with normal validation on the allowed path).

*Inactive Workspace:*
4. `GET` → 404.
5. Valid `PUT` → 404, zero mutation.
6. Malformed `PUT` → **404 — not a validation-error redirect**, zero mutation. **Mandatory** — proves Boundary A's ordering; a regression to a controller-body-only design would make this fail while 4-5 could still appear to pass.

*No primary Business:*
7. `GET` → redirect to onboarding (regression, unaffected).
8. Valid-payload `PUT` → redirect to onboarding (regression, unaffected).
9. Malformed-payload `PUT` → ordinary validation-error response, **not** the onboarding redirect — documents genuinely pre-existing, unmodified behavior.

*Finding 7:*
10. `industry=other` + valid `industry_other` → success, persisted.
11. `industry=other` + blank/missing → validation error, zero mutation.
12. Standard industry, with/without `industry_other` → success (regression).
13. `GET edit` for a Business whose industry is `Other` → response contains the existing value.
14. Transition `Other` → standard → succeeds; stored `industry_other` not asserted to change (documents the deliberate non-clearing behavior).

**Extended existing file:** `tests/Feature/Business/BusinessManagerTest.php` — direct, deterministic coverage of `updateOwnBusinessProfile()` itself, bypassing HTTP:

15. **Boundary B closes the race:** construct a customer + active-Workspace Business; deactivate the Workspace (simulating a concurrent deactivation that would have raced past Boundary A); call `updateOwnBusinessProfile()` directly; assert `WorkspaceAccessDeniedException` is thrown and the Business's stored attributes are byte-identical before/after (zero mutation). **This is the mandatory proof that Boundary B works independently of, and would catch what, Boundary A alone could miss.**
16. **Stale/reassigned Business relationship rejected:** construct a Business whose in-memory `workspace_id` (the value passed into the method) differs from its true, current, database `workspace_id` (simulating a concurrent reassignment observed between the caller's read and this method's own locks); call `updateOwnBusinessProfile()`; assert `BusinessWorkspaceMismatchException` is thrown with the correct `businessId`/`expectedWorkspaceId`/`actualWorkspaceId`, and zero mutation.
17. **No regression on the legitimate path:** active Workspace, rightful customer, call `updateOwnBusinessProfile()` directly; assert success, fields persisted, behavior matches what the old direct `updateBusiness()` call would have produced.

**Explicitly out of scope:** Design System visual/adoption tests; re-testing `userCanAccessBusiness()`'s own algorithm (unmodified, already covered); a dedicated multi-process concurrency test (judged disproportionate, reasoned above); any test of the four unaffected `updateBusiness()` callers beyond confirming via code review that they are untouched (§3.7) — their own existing test suites are not expected to need any change and are not included in the allowlist.

---

## 8. Regression baseline and test policy

No PHPUnit run was performed for this docs-only contract task, or either correction round, consistent with every prior Design System M2 contract-only task in this sequence. The most recent known evidence remains **historical context only**: A1's post-implementation full suite, 3779 tests / 13591 assertions / 1 pre-existing unrelated `BrandingAdminFooterRenderTest` failure / 0 skipped, at commit `c3fcff3b84100ae03d600995220b2fae0a823ae3`. Not the baseline for the future implementation.

**Policy for the future remediation implementation task (updated for the two-boundary architecture):**

1. Establish the exact implementation base (this contract's own human-merged `main`).
2. Run, as real HTTP integration tests where the middleware/Kernel/routing pipeline is genuinely exercised (not merely unit-testing a controller in isolation): the new `tests/Feature/Business/CustomerBusinessControllerTest.php` (§7, scenarios 1-14); the extended `tests/Feature/Business/BusinessManagerTest.php` (§7, scenarios 15-17, run as direct manager-level tests — these do not need the HTTP stack, only the database transaction/locking machinery); `tests/Feature/Workspace/WorkspaceEffectiveAccessTest.php` (confirms `userCanAccessBusiness()` itself untouched); `tests/Feature/Business/AdminBusinessControllerTest.php` (confirms admin unaffected); spot-check the four other `updateBusiness()` callers' own existing test suites (`OnboardingActionExecutor`/`OnboardingManager`/`OpportunityActionExecutor` tests, wherever they live) to confirm zero regression, without modifying them.
3. Collect a full-suite pre-implementation baseline only if the base's product-code tree has not already been verified clean by a prior task (the same `git rev-parse ...^{tree}` efficiency check used throughout this sequence).
4. Implement.
5. Run the full suite again post-implementation.
6. Report exact pre/post totals. The post-implementation total is expected to **increase** by the sum of the new test file's methods (§7, 14) plus the extended file's new methods (§7, 3) — not a discrepancy. **Zero newly introduced failures** required. Any pre-existing/unrelated failure must be mechanically demonstrated present and identical on both runs before exclusion.

---

## 9. Future implementation path allowlist — re-derived from zero (Correction Round 2)

The Correction Round 1 allowlist (5 paths, middleware-only) is **superseded** — it did not account for Boundary B. Every path below is included only because §3-§7 mechanically established it is required; none is included speculatively, and none of the previously-excluded files (`BusinessManager.php`, `BusinessController.php`) is re-included merely out of caution — each has a stated, specific reason.

**Production (6 paths):**

| # | Path | Reason | Implements |
|---|---|---|---|
| 1 | `app/Http/Middleware/EnsureBusinessProfileIsAccessible.php` (new) | Boundary A gate itself | Finding 6 |
| 2 | `app/Http/Kernel.php` | Register the `business.profile.accessible` alias | Finding 6 |
| 3 | `routes/customer.php` | Apply the alias to the existing `business` group | Finding 6 |
| 4 | `app/Library/Business/BusinessManager.php` | Add `WorkspaceRepository` constructor dependency + new `updateOwnBusinessProfile()` method (Boundary B); existing methods (`updateBusiness()`, `applyIdentity()`, `assertOwnership()`, and the other three) remain byte-for-byte unmodified | Finding 6 |
| 5 | `app/Http/Controllers/Customer/BusinessController.php` | `update()` calls the new method and adds one catch clause; `edit()` is not modified | Finding 6 |
| 6 | `resources/views/customer/business/edit.blade.php` | Add the `industry_other` field | Finding 7 |

**No new file for `WorkspaceRepository`/`WorkspaceManager`** — both already expose every method this remediation needs (`findForUpdate()`, `userCanAccessBusiness()`); confirmed by direct inspection, not assumed.

**Test (2 paths):**

| # | Path | Reason |
|---|---|---|
| 7 | `tests/Feature/Business/CustomerBusinessControllerTest.php` (new) | HTTP-level coverage, §7 scenarios 1-14 |
| 8 | `tests/Feature/Business/BusinessManagerTest.php` (existing, extended) | Direct Boundary B coverage, §7 scenarios 15-17 — the only test level that can actually prove the mutation-time recheck, per §7's own reasoning |

**Total: exactly 8 paths.**

**Explicitly excluded, with reasons:** `WorkspaceManager.php` (fully reused unmodified — §3.4); `EloquentBusinessRepository.php`/`EloquentWorkspaceRepository.php`/their contracts (both already expose `findForUpdate()` — nothing to add); `UpdateBusinessRequest.php` (unaffected by either finding); `Admin\BusinessController.php`/`admin/businesses/edit.blade.php` (both provably unaffected, §3.7/§4.4); any migration (§4.3, §5 row 17); `OnboardingActionExecutor.php`/`OnboardingManager.php`/`OpportunityActionExecutor.php` (provably unaffected, §3.7 — modifying them would be the exact scope expansion this contract is chartered to avoid).

**Stop threshold: a 9th changed path is a mandatory STOP condition requiring human review.** If the implementation task discovers a genuine need to touch any excluded file, it must stop and report rather than silently widening this allowlist. This contract does not include, and does not authorize touching, the already-merged A2 visual contract — a post-implementation re-audit of it happens as its own, separate step.

---

## 10. Completion criteria (Correction Round 2: final)

Remediation implementation is complete only when all of the following hold simultaneously:

- Exactly the 8 paths in §9 changed (or fewer — never more without a STOP-and-report).
- All 17 test scenarios in §7 pass, **including scenario 15**, the mandatory direct proof that Boundary B denies a mutation when the Workspace was deactivated after an earlier access check would have passed.
- `Admin\BusinessController.php`, `admin/businesses/edit.blade.php`, `WorkspaceManager.php`, and all four unaffected `updateBusiness()` callers (§3.7) are provably unchanged (git diff shows no touch).
- The full regression suite shows zero newly introduced failures per §8's policy.
- No schema or migration file was added or changed.
- The A2 visual contract document is provably unchanged.
- A human has reviewed and merged the implementation.

Only after that merge, and the post-merge mechanical re-audit called for in the A2 visual contract's own §28, may a separately-authorized A2 visual implementation task begin. This remediation contract does not authorize that task, A3, or B1.

---

## 11. Stop conditions

None were triggered across either correction round. Specifically for this final round: the TOCTOU gap was real and required a genuine architectural addition (Boundary B), but it was resolvable entirely within this contract's own docs-only scope, using only already-existing repository/exception primitives, without requiring a schema change, without requiring modification of any of the four unrelated `updateBusiness()` callers, and without requiring any change to `WorkspaceManager.php` — so no STOP was necessary. Had closing the race required broadening behavior for those other callers, or a schema change, or a new independent access algorithm, this task would have stopped and reported rather than forcing a fit.

---

## 12. Stale-claim sweep (Correction Round 2)

Mechanically swept the full corrected document for claims left over from Correction Round 1's middleware-only design. Confirmed corrected/removed:

- "route middleware alone fully solves Finding 6" — corrected throughout; §3 now explicitly locks two boundaries, and §1/§3.4 state why Boundary A alone was insufficient.
- "middleware is the sole enforcement point for PUT correctness" — corrected; §3.4 explicitly adds Boundary B for the mutation itself, while `edit()`/GET correctly retains Boundary A as sufficient on its own (§3.4's closing paragraph, §3.5's table).
- "no mutation-time recheck is needed" / "no locking applies because this is only a read-access check" — corrected; §3.4 explains precisely why the PUT case is not merely a read-access check (it performs a mutation) and why locking is required there specifically.
- "RFC-003's lock/recheck requirement applies only to Workspace mutations" (used in Round 1 to justify skipping locking entirely) — corrected and precisely re-scoped in §3.4's "Scope of the fix" paragraph: RFC-003 §18 is indeed scoped to Workspace/membership-domain operations, and this correction does not claim otherwise or reinterpret RFC-003 as universally requiring locking for all Business mutations — but it explains precisely why *this remediation's own* stated goal still requires closing the gap, a narrower and more precise justification than either Round 1's dismissal or an overbroad RFC-003 reinterpretation would have been.
- "`Customer\BusinessController.php` is not modified by this remediation at all" — corrected; §3.4 explicitly documents the required controller change (new method call + new catch clause) and flags this as a reversal of Round 1's claim.
- "exactly 5 implementation paths" — corrected to 8 throughout (§9, §10).
- "exactly 14 tests" (the prior file's own scenario count) — the new file retains 14 HTTP-level scenarios, but the total test-plan scope is now 17 across two files (§7); no section asserts a stale "14 total."
- Round 1's own §5 security-audit conclusion ("no security defect inseparable from Finding 6's fix was discovered") — explicitly superseded by this round's §5, which states outright that it corrects the omission.

Historical references to the Round 1 (middleware-only) or original-draft (controller-body-only) architectures remain only where explicitly labeled as such (§1's "Correction Round 1" paragraph, §3.3's opening description of what was retained from Round 1) — never as a live claim that either superseded design is the final one.
