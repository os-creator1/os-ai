# Design System M2 A1 — Onboarding Nonvisual Behavior Remediation Contract

**Status: CONTRACT / AUDIT ONLY. No implementation has occurred under this
document. This is Correction Round 1 of the remediation contract.
Merging this contract does NOT authorize implementation — that requires
its own separate, explicit human authorization, exactly like every prior
contract in this repository. This contract is explicitly NONVISUAL: it
does not touch, and does not authorize touching, any of the nine
onboarding Blade views.**

---

## 0. Governance

```
roadmap_group: A1
remediation_type: nonvisual_onboarding_behavior

docs_only: true
implementation_has_occurred: false

blocking_prerequisite_count: 2

blocker_1: rfc001_onboarding_master_switch_drift
blocker_2: expected_capacity_denial_generic_500

rfc001_resolution_direction: implementation_must_conform_to_merged_rfc
rfc_amendment_authorized: false

visual_files_allowed: false
a1_visual_implementation_authorized: false

remediation_contract_merge_authorizes_implementation: false
remediation_implementation_requires_separate_human_authorization: true

a1_visual_status: blocked_until_nonvisual_onboarding_behavior_remediation_human_merged
post_remediation_main_becomes_visual_baseline: true

advance_automatically: false
start_a1_visual_automatically: false
start_a2_automatically: false
start_a3_automatically: false
start_b1_automatically: false

merge_authority: human_only
no_force_push: true
no_deployment: true

maximum_correction_rounds: 2
correction_round: 1
correction_round_is_final: false
```

**Base verification**: this correction is drafted on branch
`chore/design-system-m2-a1-onboarding-behavior-remediation-contract`, in
an isolated worktree, remaining on the same branch as the original draft.
`origin/main` confirmed exactly `9e4127b8159741fb61f3dca8174d33d267b6c759`
before this correction began — unchanged since the original draft (PR
#188, "Design System M2 A1 — Business Onboarding Contract Final
Correction," parents `b7eabccd0702723965023336dcc4f01d5389f42a` and
`2d8b43e88747a6b16a22ef2a8c80496afd055a6b`). Pre-correction head:
`d99ef6d3652edf967ebb6ef56e127229145b7f82`. This branch changes
**exactly one path**: this document. No `resources/`, `app/`,
`database/`, or `routes/` file is touched by drafting this correction —
the two production source files cited below (`BusinessOnboardingController.php`,
`BuildInitialBusinessSnapshot.php`, plus `routes/customer.php` and
`app/Http/Kernel.php`) were read directly to verify the corrections
below against real code, never edited.

---

## 1. Authoritative inputs read

Read in full (original draft, unchanged by this correction): `CLAUDE.md`,
`AGENTS.md`, `docs/automation/DESIGN-SYSTEM-M2-A1-BUSINESS-ONBOARDING-CONTRACT.md`,
`docs/rfcs/RFC-001-BUSINESS-CORE.md`, `docs/rfcs/RFC-001-BUSINESS-CORE-DEPLOYMENT.md`,
`docs/rfcs/RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS.md`,
`docs/rfcs/RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS-DEPLOYMENT.md`,
`app/Http/Middleware/EnsureRequiredBusinessOnboardingIsComplete.php`,
`app/Repositories/Eloquent/EloquentAccountRepository.php`,
`app/Library/Business/OnboardingManager.php`,
`app/Library/Business/BusinessManager.php`, `app/Exceptions/Handler.php`,
`config/business.php`, `app/Library/Entitlement/EntitlementManager.php`,
all six `App\Exceptions\Entitlement\*` classes reachable from
`assertCanCreateAnotherBusiness()`, the existing entitlement-denial
handling in `WorkspaceController`/`Admin\WorkspaceEntitlementController`,
the `opportunity.enabled` precedent, and every existing test file
covering these paths.

**Additionally re-read for this correction, in full, against current
source**: `app/Http/Controllers/Customer/BusinessOnboardingController.php`
(exact current `saveStep()`/`requestAnalysis()`/`completeAction()`/
`complete()` redirect code, verified line-by-line — §9), `app/Jobs/Business/BuildInitialBusinessSnapshot.php`
(exact current `handle()`/`markFailed()` code — §7), `routes/customer.php:508-520`
(exact current route-group definition — §4), `app/Http/Kernel.php:91-117`
(exact current `$routeMiddleware` alias registration convention — §4),
and `docs/rfcs/RFC-001-BUSINESS-CORE-DEPLOYMENT.md:158` (exact
in-flight-job wording, re-quoted verbatim — §7, previously
mischaracterized in the original draft).

---

## 2. Authoritative RFC semantics — Blocker 1

Unchanged from the original draft. `docs/rfcs/RFC-001-BUSINESS-CORE-DEPLOYMENT.md`
§1 (environment-variable table), verbatim:

> `BUSINESS_ONBOARDING_ENABLED` | `false` | **Master switch. When
> `false`, the entire onboarding wizard, analysis job, and dashboard
> redirect middleware behave as if the feature does not exist. Existing
> customer routes and the registration flow are unaffected.**

Reinforced at §8 (Rollback considerations): "setting
`BUSINESS_ONBOARDING_ENABLED=false` ... immediately stops new onboarding
rows, dashboard redirects, and analysis dispatches." Reinforced again at
§3 (rollout procedure): "voluntary onboarding" is only ever tested at
step 6, *after* `BUSINESS_ONBOARDING_ENABLED=true` is already set.

**No merged RFC-001 document contradicts this** — both documents are
confirmed silent on exact HTTP-level mechanics; that choice is made in
§5 from codebase precedent, not invented. **Resolution direction is
locked**: fix the implementation to conform to the merged RFC. No RFC
document is amended by this contract or its future implementation.

---

## 3. Blocker 1 — complete onboarding entry-point inventory

Unchanged from the original draft — mechanically re-enumerated against
current `main`, not assumed from any prior contract's list.

| # | Entry point | Route/caller | Current config read | Current disabled-state gate | Side effects possible today while `enabled=false` |
|---|---|---|---|---|---|
| 1 | Lazy `CustomerOnboarding` row creation | `BusinessOnboardingController::currentOnboarding()` (`:238-241`), called by all 11 controller actions; `OnboardingManager::start()` | none | **none** | `INSERT` into `customer_onboardings`, fires `CustomerOnboardingStarted` |
| 2 | Render wizard (GET) | `customer.onboarding.show`, `::show()` (`:39-61`) | none | **none** | Full wizard HTML (200 OK) to any authenticated customer |
| 3 | Submit a step (5 routes: goals/business/location/services/assets) | `customer.onboarding.{goals,business,location,services,assets}.store`, `::store*()` (`:63-116`) | none | **none** | Creates/updates `Business`, `BusinessLocation`, `BusinessService` rows |
| 4 | Skip Assets | `customer.onboarding.assets.skip`, `::skipAssets()` (`:122-127`) | none | **none** | Advances `current_step` to `Analysis` |
| 5 | Request analysis | `customer.onboarding.analysis.request` (`throttle:5,60`), `::requestAnalysis()` (`:129-143`) | none | **none** | Increments `analysis_version`, sets `status=AnalysisPending`, **dispatches `BuildInitialBusinessSnapshot`** |
| 6 | Poll analysis status | `customer.onboarding.analysis.status` (`throttle:60,1`), `::analysisStatus()` (`:150-163`) | none | **none** | Read-only JSON; also triggers item 1's lazy-create |
| 7 | Results-step action | `customer.onboarding.action.complete`, `::completeAction()` (`:165-186`) | none | **none** | Mutates a `Business` field; may set `current_step=Complete` |
| 8 | Complete onboarding | `customer.onboarding.complete`, `::complete()` (`:188-202`) | none | **none** | Sets `status=Completed`, redirects to dashboard |
| 9 | Dispatch `BuildInitialBusinessSnapshot` | `OnboardingManager.php:260`, inside item 5's path | none (queue-name selection only) | **none** | Queues async job |
| 10 | Job processing | `BuildInitialBusinessSnapshot::handle()` (`:52-101`) | queue-name only, **before this correction** | **none, before this correction** | Builds/persists analysis snapshot — **corrected, §7** |
| 11 | Dashboard redirect | `user.home` (`routes/auth.php:56`), `EnsureRequiredBusinessOnboardingIsComplete` (alias `business.onboarding`, `Kernel.php:116`) | `business.onboarding.enabled` (default `false`) | **YES — already correct today** | Pure passthrough when disabled; unaffected by this remediation |
| 12 | Registration required-onboarding creation | `EloquentAccountRepository::register()` (`:125-127`) | compound `enabled` + `require_for_new_customers` | **YES — already correct today** | No required row created when disabled; unaffected by this remediation |

**Confirmed**: the onboarding route group itself
(`routes/customer.php:508-520`) attaches no flag-aware middleware today
— only per-route throttling on 2 of the 11 routes. **Items 1-10 have
zero disabled-state gating today** — only items 11 and 12 are already
correct.

---

## 4. Selected master-switch architecture

### 4.1 Correction Round 1 — the original controller-body guard is rejected

The original draft selected a private `BusinessOnboardingController::ensureOnboardingEnabled()`
method called as the first statement of each of the 11 controller
actions. **This is rejected — it does not fully satisfy the RFC.**

**Verified against the real controller** (`app/Http/Controllers/Customer/BusinessOnboardingController.php`):
six of the eleven actions type-hint a dedicated `FormRequest` parameter
— `storeGoals(UpdateOnboardingGoalsRequest $request)`,
`storeBusiness(UpsertBusinessIdentityRequest $request)`,
`storeLocation(UpsertBusinessLocationRequest $request)`,
`storeServices(SyncBusinessServicesRequest $request)`,
`storeAssets(UpdateBusinessAssetsRequest $request)`,
`completeAction(CompleteOnboardingActionRequest $request)`. Laravel
resolves a type-hinted `FormRequest` from the container as part of
building the controller method's call arguments — via
`ValidatesWhenResolvedTrait::validateResolved()`, triggered during
dependency resolution, which happens **before** the controller method
body's first statement ever executes. A guard placed as the first line
of the method body therefore runs **after** that FormRequest has already
been authorized and validated — a disabled onboarding feature would
still authorize/validate a malformed or well-formed POST body before
ever reaching the guard, which does not "behave as if the feature does
not exist" for those six actions. **This is a real defect in the
original architecture, not a stylistic preference.**

### 4.2 Locked architecture — route middleware

**Selected**: a dedicated route middleware, applied to the whole
onboarding route group, which runs in the middleware pipeline strictly
before controller dispatch and therefore strictly before any
`FormRequest` is ever resolved.

**Exact new file**: `app/Http/Middleware/EnsureBusinessOnboardingIsEnabled.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Master switch (RFC-001-BUSINESS-CORE-DEPLOYMENT.md §1): when
 * business.onboarding.enabled is false, the entire onboarding route
 * group behaves as if it does not exist. Runs before controller
 * dispatch and before any FormRequest is resolved, so a disabled
 * feature rejects a malformed or well-formed request identically —
 * no onboarding row lookup, no tenant lookup beyond what the inherited
 * route stack already ran, no mutation.
 */
class EnsureBusinessOnboardingIsEnabled
{
    public function handle(Request $request, Closure $next)
    {
        if (config('business.onboarding.enabled', false)) {
            return $next($request);
        }

        if ($request->wantsJson()) {
            return response()->json(['status' => 'error', 'message' => 'Not Found'], 404);
        }

        abort(404);
    }
}
```

**Exact `Kernel.php` registration**: one new line in the existing
`$routeMiddleware` array (`app/Http/Kernel.php:91-117`), immediately
after the existing `'business.onboarding' => EnsureRequiredBusinessOnboardingIsComplete::class,`
entry (`:116`), following the same alias-naming convention already
established there:

```php
'business.onboarding.enabled' => EnsureBusinessOnboardingIsEnabled::class,
```

**Exact `routes/customer.php` change** — one attribute added to the
existing group definition (`:508`), no route added/removed/reordered:

```php
Route::prefix('onboarding')->name('onboarding.')->middleware('business.onboarding.enabled')->group(function () {
    // ...existing 11 routes, byte-identical, unchanged...
});
```

**`EnsureRequiredBusinessOnboardingIsComplete` is untouched** — it
remains registered only on `user.home` (`routes/auth.php:56`), with its
own distinct "should I redirect you *into* onboarding" responsibility,
separate from this new "*is* onboarding reachable at all" gate.

### 4.3 Seam comparison (revised)

| Candidate seam | Production paths touched | Runs before FormRequest resolution? | Verdict |
|---|---|---|---|
| Controller-body guard (original draft) | 1 file | **No — confirmed defect, §4.1** | **Rejected** |
| Manager/service-level guard | 1 file, but doesn't uniformly cover every route-level entry point as the first thing that runs | No (deeper than the controller, same problem) | Rejected |
| Job-level guard alone | 1 file | N/A — addresses only item 10, not the wizard itself | Rejected as sole solution |
| Reuse/extend `EnsureRequiredBusinessOnboardingIsComplete` | Would conflate two different gates (item 11's own responsibility vs. this one) | Yes, but wrong semantics | Rejected |
| **New dedicated route middleware, applied to the whole onboarding group** | **3 files**: new middleware class, one `Kernel.php` line, one `routes/customer.php` attribute | **Yes — middleware always runs before controller dispatch/FormRequest resolution in Laravel's pipeline** | **SELECTED** |

The 3-file diff is larger than the original 1-file controller-body
approach, but is the only seam that actually satisfies "the entire
onboarding wizard ... behave[s] as if the feature does not exist" for
every one of the 11 entry points, including the six backed by a
`FormRequest`.

### 4.4 Criteria re-verified against the corrected architecture

One authoritative config source (✓, same key/default); no partial
access while disabled — every route in the group is gated identically,
before dispatch (✓); no lazy row creation (✓, middleware runs before
`currentOnboarding()` is ever called); no new analysis dispatch (✓); no
FormRequest authorization/validation runs while disabled (✓ — the
specific defect this correction fixes); queued-job treatment locked
separately (§7, corrected); dashboard passthrough preserved, untouched
(✓); registration behavior preserved, untouched (✓); no unrelated
customer route touched — only the onboarding group's own middleware
attribute changes (✓); straightforward, deterministic, directly testable
(✓).

---

## 5. Disabled response contract — browser and JSON

**Corrected — locked to an exact, local, self-contained response, not
inherited from the repository's global `Handler.php` JSON convention.**
The original draft relied on `app/Exceptions/Handler.php`'s existing
`wantsJson()` branch (which returns HTTP 200 for any exception,
including a 404) as the *de facto* JSON behavior. That repository-wide
legacy convention is real and is not modified by this contract (§17),
but this new, local feature gate does not need to inherit that
particular quirk — the middleware (§4.2) builds and returns its own
`JsonResponse` directly for a JSON-wanting request, entirely bypassing
`Handler::render()` for this specific gate.

**Locked, exact responses**:

**A. Normal browser request** (`$request->wantsJson()` is `false`):
`abort(404)` inside the middleware — standard Laravel 404 handling,
identical to every other `abort(404)` in this app (e.g. the
`opportunity.enabled` precedent's own `abort_unless(...,404)`, confirmed
tested via `assertNotFound()` in three existing Opportunity test files).

**B. JSON/AJAX request** (`$request->wantsJson()` is `true` — the only
onboarding route whose own client sets `Accept: application/json` is
`analysis.status`'s polling `fetch()` call): the middleware returns
```php
response()->json(['status' => 'error', 'message' => 'Not Found'], 404)
```
directly — **HTTP 404**, with the exact body `{"status":"error","message":"Not Found"}`.
This is a real 404 status, not the repository-wide 200-status JSON quirk
— because the middleware constructs and returns this response itself,
before the request ever reaches `Handler::render()`, the global
`wantsJson()`-returns-200 convention never applies to this specific gate.

**`app/Exceptions/Handler.php` remains entirely unmodified** — this
correction achieves a locally-correct JSON status code without touching
any repository-wide exception-handling behavior.

**Consequence, documented not fixed**: the `analysis.blade.php` polling
script (unmodified — no visual work, §18) does not check `response.ok`
and calls `.json()` unconditionally (an existing, out-of-scope
behavior). Receiving `{"status":"error","message":"Not Found"}` (now a
genuine 404, previously it would have been a 200 under the rejected
design) matches neither its `data.completed` nor
`data.status === 'failed'` branches, so it continues polling at its
existing capped interval — a graceful, non-mutating degradation, not a
regression this remediation introduces.

**No genuine two-way ambiguity was found** — the exact response shape is
fully determined by this contract's own choice to keep the gate local
and self-contained, not by an inherited convention.

---

## 6. Existing-row behavior while disabled

**Locked, clarified in this correction**: disabling is an availability
gate, not a data-mutation event, for every **ordinary denied HTTP
request**. Because the middleware (§4.2) runs before controller
dispatch, a denied request never reaches `currentOnboarding()`, any
repository read, or any write — a customer's existing
`CustomerOnboarding` row (`current_step`, `completed_steps`,
`business_id`, `analysis_payload`, `analysis_version`, `status`) is
**never read, never written** by a denied HTTP request while the flag is
off. Re-enabling makes the row immediately resumable exactly where it
left off.

**The one deliberate exception, clarified by Correction Round 1**: an
analysis job dispatched *before* the flag was disabled, which then
executes *while* the flag is disabled, **may** terminalize *that one
analysis attempt* as `Failed`, using the job's own existing safe-failure
path (§7) — this is not a violation of "disablement does not
mutate data," since it is the job's own established terminal-failure
mechanism reacting to an unavailable feature at execution time, not a
side effect of the HTTP-level gate itself. Disablement does not delete,
reset, or otherwise touch any other field on the row.

---

## 7. Already-queued analysis jobs

**Scenario**: onboarding enabled → analysis dispatched (item 5/9) →
flag switched off → the already-queued job (item 10) executes.

### 7.1 Correction Round 1 — the original "completes normally" decision is withdrawn

The original draft locked "the job completes normally, no new flag
check." **This directly contradicted the merged RFC and is withdrawn.**

**Exact RFC text, re-verified by direct grep against
`docs/rfcs/RFC-001-BUSINESS-CORE-DEPLOYMENT.md:158`** (§8, "Queued jobs
in flight during a rollback"):

> "`BuildInitialBusinessSnapshot` re-validates the onboarding's status
> and analysis version on every run (including retries) and **safely
> no-ops or marks a safe failure** rather than writing stale data — an
> in-flight job encountering a disabled feature or a since-completed/
> dismissed onboarding will not corrupt state."

This sentence restricts an in-flight job's behavior under a disabled
feature to exactly two options — **no-op**, or **mark a safe failure** —
and does not sanction a third option of completing the snapshot build
and transitioning the onboarding to `ResultsReady` while the feature is
disabled, which is what the original draft locked. That was a drafting
error, confirmed against the RFC's own text, not a matter of taste.

### 7.2 Locked decision: safe failure, not a no-op

**Locked**: the job marks a safe failure, using its own existing
`markFailed()`/`failAnalysis()` mechanism — not a silent no-op.
**Rationale**: `failAnalysis()` already gives the customer a retryable
terminal state (`status = Failed`) at the Analysis step once the feature
is re-enabled. A pure no-op would leave the onboarding row stuck in
`AnalysisPending` indefinitely, with no retry control available while
pending (the existing UI has no "retry" affordance for the pending
state, only for the failed state, per the merged A1 visual contract §7.2)
— exactly the "stuck in AnalysisPending forever" failure mode the job's
own existing design (its comment at `handle()` lines 68-70) was already
built to avoid for the missing/cross-tenant-Business case. This choice
is explicitly permitted by the RFC's own "no-op or marks a safe failure"
wording and reuses an entirely existing mechanism — no new one is
invented.

### 7.3 Exact production change

`app/Jobs/Business/BuildInitialBusinessSnapshot.php`'s `handle()`
method — exact current code (verified directly):

```php
public function handle(
    InitialBusinessSnapshotBuilder $builder,
    CustomerOnboardingRepository $onboardingRepository,
    BusinessRepository $businessRepository,
): void {
    $onboarding = CustomerOnboarding::find($this->onboardingId);

    if ($onboarding === null || $onboarding->analysis_version !== $this->expectedVersion) {
        return;
    }

    if (in_array($onboarding->status, [OnboardingStatus::Completed, OnboardingStatus::Dismissed], true)) {
        return;
    }

    if ($onboarding->business_id === null) {
        // ...existing missing-business safe-failure branch...
    }
    // ...
}
```

**Exact insertion point**: a new check, immediately after the
`Completed`/`Dismissed` terminal-state check and immediately before the
`business_id === null` check:

```php
    if (! config('business.onboarding.enabled', false)) {
        // RFC-001-BUSINESS-CORE-DEPLOYMENT.md §8: an in-flight job encountering
        // a disabled feature "safely no-ops or marks a safe failure" -- a safe
        // failure is chosen here, not a no-op, so the customer is not left
        // stuck in AnalysisPending indefinitely once the retry route is
        // itself gated (§4) while the feature remains disabled.
        $this->markFailed($onboarding, $onboardingRepository);

        return;
    }
```

**Preserved exactly, per §6's clarification**: `analysis_version` is
preserved (guarded by `failAnalysis()`'s own existing version re-check
under `lockForUpdate()`, unchanged); `business_id` is preserved
(`markFailed()`/`failAnalysis()` never touch it); `completed_steps` is
preserved (untouched by this branch); `current_step` remains `Analysis`
through the existing `failAnalysis()` implementation (it does not
advance `current_step`, matching every other `markFailed()` call site);
`status` becomes `Failed`; `analysis_error` is set to the job's existing
`SAFE_ERROR` constant, verbatim: `"We could not finish the analysis.
Please retry."`; the existing `InitialBusinessAnalysisFailed` event
dispatch follows `markFailed()`'s own established, unmodified logic.

**This adds `app/Jobs/Business/BuildInitialBusinessSnapshot.php` to the
production implementation allowlist** (§17) — it is no longer excluded.

---

## 8. Blocker 2 — exact exception inventory (unchanged, mechanically re-verified)

Unchanged from the original draft. `EntitlementManager::assertCanCreateAnotherBusiness()`
(`:225-241`) — the exact, complete `match` statement:

| Denial reason | Thrown class | Extends | Message (no tenant data beyond a numeric Workspace ID) |
|---|---|---|---|
| `workspace_plan_unassigned` | `App\Exceptions\Entitlement\WorkspacePlanUnassignedException` | `\RuntimeException` | `"Workspace [{$id}] has no plan assignment."` |
| `plan_inactive` | `App\Exceptions\Entitlement\InactiveWorkspacePlanException` | `\RuntimeException` | `"Workspace [{$id}]'s plan assignment is inactive."` |
| `plan_suspended` | `App\Exceptions\Entitlement\SuspendedWorkspacePlanException` | `\RuntimeException` | `"Workspace [{$id}]'s plan assignment is suspended."` |
| `business_slot_allocation_required` | `App\Exceptions\Entitlement\BusinessSlotAllocationRequiredException` | `\RuntimeException` | `"Workspace [{$id}] requires an additional Business slot allocation to create another Business."` |
| `business_slot_limit_exceeded` | `App\Exceptions\Entitlement\BusinessSlotLimitExceededException` | `\RuntimeException` | `"Workspace [{$id}] is at its maximum Business slot capacity."` |
| *(default arm, currently unreachable)* | bare `\RuntimeException` | — | `"Unexpected capacity denial reason [...] for Workspace [{$id}]."` |

**Confirmed complete** (unchanged): `decideBusinessSlotCapacity()`
throws nothing and only ever returns one of the five known reasons or
`null`. **No shared base/abstract exception class exists** anywhere in
`app/Exceptions/**` — every custom exception extends base
`\RuntimeException` directly; no legitimate shared parent to catch
instead, and this contract does not invent one. **Confirmed
anonymization**: all five messages carry only a numeric Workspace ID,
nothing else. **Confirmed via `Handler.php`**: none of the six is
special-cased; HTML → generic framework 500 today (the actual defect);
JSON → the repository-wide `wantsJson()` 200-status envelope, which
today leaks the raw message (including the Workspace ID) — corrected by
§9, since the new catch intercepts the exception before it ever reaches
`Handler::render()`.

**Not part of this contract's catch set, unchanged**:
`resolveLegacyOnboardingWorkspace()`/`lockForLegacyOnboardingBusinessCreation()`'s
own possible `WorkspaceContextRequiredException`/`ModelNotFoundException`
throws remain out of scope, as originally decided.

---

## 9. Selected capacity-denial architecture

### 9.1 Existing repository precedent (unchanged — the deciding evidence)

Unchanged from the original draft: `WorkspaceController::storeBusiness()`/`::reassignBusiness()`
and `Admin\WorkspaceEntitlementController::mutationErrorRedirect()` both
already catch each of the five named exception types and redirect with
a message — a pattern already fixed on those two surfaces under RFC-004
Milestone 3, never extended to onboarding. Onboarding cannot reuse
`flash_error` verbatim (no Blade partial renders it in any of the nine
onboarding views) — it reuses its own existing `saveStep()` error seam
instead.

### 9.2 Locked architecture — corrected redirect target

**Exact production file/method**: `app/Http/Controllers/Customer/BusinessOnboardingController.php`,
method `saveStep(Closure $action)` (`:211-226`) — verified exact current
code:

```php
private function saveStep(Closure $action): RedirectResponse
{
    $onboarding = $this->currentOnboarding();
    $customer = $this->customer();

    try {
        $onboarding = $action($onboarding, $customer);
    } catch (InvalidArgumentException) {
        return redirect()
            ->route('customer.onboarding.show', ['step' => $this->onboarding->resolveStep($onboarding)->value])
            ->withInput()
            ->withErrors(['onboarding' => 'We could not save that step. Please check your entries and try again.']);
    }

    return redirect()->route('customer.onboarding.show', ['step' => $onboarding->current_step->value]);
}
```

**Correction Round 1 — redirect target fixed.** The original draft's
locked example used `redirect()->to($this->onboarding->resolveStep($onboarding)->value)`
— **this is wrong**: `resolveStep(...)->value` is a bare step-name
string (e.g. `"business"`), and `redirect()->to('business')` would
target the relative path `/business`, not the actual onboarding show
route `/onboarding/business`. **Locked, exact, corrected catch clause**,
mirroring the verified existing `InvalidArgumentException` catch above
exactly — via the named route, not a bare value:

```php
} catch (WorkspacePlanUnassignedException
       | InactiveWorkspacePlanException
       | SuspendedWorkspacePlanException
       | BusinessSlotAllocationRequiredException
       | BusinessSlotLimitExceededException $e) {
    return redirect()
        ->route('customer.onboarding.show', ['step' => $this->onboarding->resolveStep($onboarding)->value])
        ->withInput()
        ->withErrors(['onboarding' => self::CAPACITY_DENIAL_MESSAGE]);
}
```

placed alongside the existing `catch (InvalidArgumentException)` clause,
using the identical named-route/`withInput()`/`withErrors(['onboarding'
=> ...])` shape already established there.

**Exact caught type** (unchanged): a union catch of the five named
`App\Exceptions\Entitlement\*` classes from §8 — never a bare
`\RuntimeException`, never a new shared interface.

**State-preservation guarantees** (unchanged, all already true today by
virtue of the existing transaction wrapping `applyIdentity()`'s CREATE
branch): zero Business persisted; `business_id` remains null;
`current_step` remains `Business`, not advanced; `status` unchanged; no
analysis dispatch.

**Non-onboarding callers unaffected** (unchanged).

---

## 10. Exact safe customer message

**Correction Round 1 — message text corrected; the constant stays
private.**

**The original draft's message was misleading**: "We can't create your
business right now. Please try again in a moment, or contact support if
this continues." implies transience ("in a moment," "try again") for
denials that will not resolve merely by waiting — an unassigned,
inactive, or suspended plan, or a hard slot limit, none of which self-heal
on retry. This is corrected to a message that does not claim transience:

```php
private const CAPACITY_DENIAL_MESSAGE =
    "We can't create your business with the current account setup. Please contact support for help.";
```

(`private`, unchanged — mirroring `BuildInitialBusinessSnapshot::SAFE_ERROR`'s
own private, fixed-string convention. **Correction Round 1**: the
original draft's test plan referenced
`BusinessOnboardingController::CAPACITY_DENIAL_MESSAGE` from within test
assertions — **a private class constant cannot be accessed from outside
its own class in PHP**, so that reference was invalid. Tests must assert
the exact literal string above, never the constant reference — corrected
throughout §15.)

**Verified against every requirement**: still actionable (contact
support) — ✓; no Workspace numeric ID, zero interpolation — ✓; no
internal plan-assignation details — ✓; no exception-class text — ✓; no
stack/error information — ✓; the same exact string for all five
covered denial types — ✓; no longer implies the denial is transient or
retry-resolvable — ✓ (the correction itself); exactly testable via the
literal string — ✓.

---

## 11. Security preservation

Reconfirmed, unchanged in substance, updated only where the corrected
architecture (§4, §7, §9) changes *which* file enforces a guarantee:

- **Authentication**: unaffected — the new middleware (§4.2) runs after
  the route group's own `auth` middleware, inheriting the same ordering
  as every other guard in the group; a guest is redirected to login
  before ever reaching the new gate.
- **Onboarding/Business ownership, Workspace association, RFC-004
  capacity enforcement, transaction/locking**: entirely untouched — this
  correction still only adds a route middleware, a `saveStep()` catch
  clause, and one job-level guard; it does not modify
  `assertOwnership()`, `EntitlementManager`'s decision logic, or any
  lock acquisition.
- **Cross-customer isolation**: unaffected — the capacity catch clause
  fires only after ownership has already been asserted upstream.
- **Exception anonymization**: strengthened — today, a JSON-wanting
  caller hitting the capacity-denial path would see the raw exception
  message (including the Workspace ID) via `Handler`'s generic
  pass-through (§8); after remediation, the fixed §10 message is used,
  and the exception never reaches `Handler::render()`.
- **No raw server exception output**: guaranteed — `withErrors()`
  receives only the fixed constant string, never `$e->getMessage()`;
  the middleware's JSON body (§5) is a fixed literal, never derived from
  request/tenant data.
- **`AuthorizationException` is not converted into a friendly capacity
  error**: guaranteed by type-safety — unchanged reasoning, `AuthorizationException`
  does not extend `RuntimeException` and cannot match the new catch's
  union type.
- **No foreign Workspace ID/plan/allocation/capacity can be inferred**
  through either new path — the middleware's JSON body (§5) and the
  capacity-denial message (§10) both carry zero interpolated data.

---

## 12. State-machine preservation

Unchanged in substance; re-verified against the corrected architecture.
Step order `Goals → Business → Location → Services → Assets → Analysis
→ Results → Complete` preserved; Assets remains the only skippable
step.

**Capacity denial at Business** (§9): leaves the customer recoverable at
Business; does not mark Business complete; does not advance
`current_step`; does not create Business; does not dispatch analysis —
all ✓, unchanged reasoning.

**Master-switch disablement** (§4-§7, updated): an ordinary denied HTTP
request does not itself complete, dismiss, reset, delete, or advance an
existing onboarding record (§6) — ✓. The one deliberate exception,
clarified by this correction: an in-flight analysis job executing while
disabled may terminalize *that analysis attempt* to `Failed` via the
job's own existing `markFailed()` path (§7) — this does not advance
`current_step` (remains `Analysis`) and does not otherwise touch the
row.

---

## 13. Stale-test inventory

Unchanged core finding, expanded scope per the corrected architecture.

- **`test_direct_onboarding_routes_remain_reachable_when_config_is_disabled`**
  (`tests/Feature/Business/BusinessOnboardingHttpTest.php:358-365`) —
  still the one stale test from the original inventory; must be
  rewritten to assert `assertNotFound()` (§15).

**Newly identified by this correction — not a stale assertion, but a
missing test-fixture precondition**: `BusinessOnboardingHttpTest.php`'s
existing "master switch on" wizard-workflow tests (the majority of the
file) currently pass without ever setting `config(['business.onboarding.enabled'
=> true])`, because today's routes are ungated. Once the route
middleware (§4.2) is added, those tests will 404 unless the file
establishes an enabled baseline. **These tests are not reclassified as
stale — their workflow assertions remain entirely valid** (per explicit
instruction: do not treat all of them as stale); only the missing
fixture precondition changes (§15/§16). The identical situation applies
to `BuildInitialBusinessSnapshotJobTest.php`'s existing successful-job
tests, which historically ran without the flag enabled because the job
ignored it — once the job enforces the flag (§7.3), those tests need
the same explicit enabled baseline.

**No stale test exists for Blocker 2** (unchanged) — zero existing
capacity/slot-keyword tests in `BusinessOnboardingHttpTest.php`.

---

## 14. Focused test file strategy

Unchanged: extend the same two existing files, no new test file.

- **`tests/Feature/Business/BusinessOnboardingHttpTest.php`** — receives
  the corrected stale test, an explicit enabled-baseline fixture (§13,
  §15), the full master-switch-off matrix (now including
  before-FormRequest-validation proof, §15), and the full five-exception
  capacity-denial matrix (§15).
- **`tests/Feature/Business/BuildInitialBusinessSnapshotJobTest.php`** —
  receives an explicit enabled-baseline fixture (§13) and the corrected
  disabled-queued-job safe-failure test (§7.3/§15).

---

## 15. Exact focused test plan

**Test-fixture baselines (Correction Round 1, both files)**:

```php
protected function setUp(): void
{
    parent::setUp();

    config(['business.onboarding.enabled' => true]);
}
```

added to `BusinessOnboardingHttpTest.php` and
`BuildInitialBusinessSnapshotJobTest.php` alike — every existing
"master switch on" test continues to exercise the enabled path
explicitly rather than accidentally; every disabled-state test
explicitly overrides `config(['business.onboarding.enabled' => false])`
within its own method, as several already do. Implementation must
mechanically re-audit every test in both files to confirm each one
intentionally runs in either the enabled baseline or an explicit
disabled override — not silently left ambiguous.

**MASTER SWITCH OFF** (`BusinessOnboardingHttpTest.php`):

- Registration does not create a required onboarding row when disabled
  (already-correct regression, re-confirmed).
- Dashboard does not redirect into onboarding when disabled (existing
  test, unchanged).
- Corrected: direct `GET customer.onboarding.show` returns **404**
  (replaces the stale test, §13).
- Every one of the 5 step-store POSTs denied with 404 — **including with
  malformed/empty input**, proving the new middleware wins over
  FormRequest validation (the exact defect §4.1 corrects) — e.g. POST to
  `business.store` with an empty body while disabled must still 404, not
  produce a 422 validation-error response.
- Assets skip denied with 404.
- Analysis-request POST denied with 404.
- Analysis-status GET denied consistently: **HTTP 404**, exact body
  `assertExactJson(['status' => 'error', 'message' => 'Not Found'])`
  (§5.B — corrected from the original draft's HTTP 200 expectation).
- Results-action POST denied with 404, including malformed input (same
  FormRequest-precedence proof as the step-store routes).
- Completion POST denied with 404.
- No `CustomerOnboarding` row created by any denied request.
- No `Business`/`BusinessLocation`/`BusinessService` row created/updated
  by any denied request.
- No `BuildInitialBusinessSnapshot` dispatch from a denied
  `analysis.request` (`Queue::fake()` + `Queue::assertNotPushed()`).
- An existing onboarding record's full state is byte-identical
  before/after a denied ordinary HTTP request (§6).
- Guest (unauthenticated) behavior is unchanged — still governed by the
  outer `auth` middleware, confirmed unaffected by the new gate.

**MASTER SWITCH ON** (regression, existing behavior unchanged, now
explicit under the new `setUp()` baseline):

- Existing voluntary onboarding path still fully functional end-to-end.
- Required-on-registration behavior unchanged.
- Dashboard redirect behavior unchanged.
- Normal wizard step persistence unchanged for all 5 steps.
- Analysis dispatch remains functional.

**QUEUED ANALYSIS** (`BuildInitialBusinessSnapshotJobTest.php`):

- **Corrected outcome**: job dispatched while enabled, flag flipped to
  `false` before the job runs — assert: `status == Failed`; `current_step
  == Analysis` (unchanged); `analysis_payload` remains `null`;
  `analysis_error` equals the exact existing literal `"We could not
  finish the analysis. Please retry."`; no `InitialBusinessAnalysisCompleted`
  event dispatched; `InitialBusinessAnalysisFailed` dispatched, following
  `markFailed()`'s existing semantics; `analysis_version` unchanged; no
  `Business` mutation. **Do not test that the disabled job becomes
  `ResultsReady`** — that was the original draft's incorrect expectation.

**CAPACITY DENIAL** (`BusinessOnboardingHttpTest.php`, new — corrected to
cover all five, not two representative types):

For **each** of the five exception families —
`WorkspacePlanUnassignedException`, `InactiveWorkspacePlanException`,
`SuspendedWorkspacePlanException`, `BusinessSlotAllocationRequiredException`,
`BusinessSlotLimitExceededException` — POSTing the Business step against
a Workspace fixture that deterministically triggers that exact denial
reason:

- Redirect target is `customer.onboarding.show` at the `Business` step
  (§9.2's corrected named-route redirect) — `assertRedirect(route('customer.onboarding.show',
  ['step' => 'business']))`, not merely `assertRedirect()`.
- Exact safe message present, asserted as the **literal string**, never
  the (private) constant: `assertSessionHasErrors(['onboarding' =>
  "We can't create your business with the current account setup. Please
  contact support for help."])`.
- `withInput()` preserved where testable (submitted Business-step field
  values still present via `old()` on redirect).
- No Business persisted (`Business::count()` unchanged).
- No step advancement (`current_step` still `Business`).
- No onboarding completion mutation (`status` unchanged).
- `business_id` unchanged.
- No raw exception message and no numeric Workspace ID present anywhere
  in the response/session.
- Existing success path (capacity available) remains unchanged —
  regression confirmation.
- The update-existing-Business branch remains unaffected — regression
  confirmation that the capacity gate applies only to Business creation.

**SECURITY** (`BusinessOnboardingHttpTest.php`, unchanged):

- Cross-tenant/foreign-customer semantics unchanged.
- `AuthorizationException` still propagates as a 403, not converted into
  the capacity message.
- Only the five named exception types use the new denial seam.

---

## 16. Regression plan

Unchanged core structure; test-count expectations updated to reflect
this correction's additional job-file change.

**Focused suites, run before and after implementation**:

```
php artisan test tests/Feature/Business tests/Unit/Business
php artisan test tests/Feature/Entitlement tests/Unit/Entitlement
php artisan test tests/Feature/Workspace tests/Unit/Workspace
php artisan test tests/Feature/Usage
```

**Also required**: full suite, `php artisan test`.

**Because remediation legitimately changes assertions on existing tests
and adds new tests**:

- Record the exact **PRE-REMEDIATION** baseline (on this contract's own
  base, `9e4127b8159741fb61f3dca8174d33d267b6c759`) before implementation
  begins.
- Record the exact **POST-REMEDIATION** baseline after implementation.
- **Do not require an equal total passing count.**
- Require **0 failures, 0 skipped, exit 0** on both baselines.
- Explain any test-count change mechanically in the implementation's own
  completion report — not fabricated here.

**The post-remediation, human-merged `main` becomes the future A1 visual
implementation's baseline** (§19), unchanged from the original draft.

---

## 17. Exact future implementation allowlist and stop threshold

**Correction Round 1 — revised from 3 paths to 7, reflecting the
corrected architecture (§4, §7, §9-§10).**

**Production (5 paths):**
1. `app/Http/Middleware/EnsureBusinessOnboardingIsEnabled.php` — new file
   (§4.2).
2. `app/Http/Kernel.php` — one new `$routeMiddleware` alias line (§4.2).
3. `routes/customer.php` — one `->middleware('business.onboarding.enabled')`
   attribute added to the existing onboarding route group definition
   (§4.2); the 11 routes inside the group are otherwise byte-identical.
4. `app/Jobs/Business/BuildInitialBusinessSnapshot.php` — one new
   disabled-flag check inside `handle()`, calling the job's existing
   `markFailed()` (§7.3).
5. `app/Http/Controllers/Customer/BusinessOnboardingController.php` —
   add the five-exception union catch clause and `CAPACITY_DENIAL_MESSAGE`
   constant to `saveStep()` (§9.2/§10). **The original draft's
   `ensureOnboardingEnabled()` controller method and its 11 per-action
   call sites are removed from this allowlist entirely** (§4.1) —
   replaced by the route middleware above.

**Tests (2 paths):**
6. `tests/Feature/Business/BusinessOnboardingHttpTest.php` — enabled
   baseline `setUp()`; corrected stale test; full master-switch-off
   matrix including FormRequest-precedence proof; full five-exception
   capacity-denial matrix; security tests (§15).
7. `tests/Feature/Business/BuildInitialBusinessSnapshotJobTest.php` —
   enabled baseline `setUp()`; corrected disabled-queued-job
   safe-failure test (§7.3/§15).

**Total: exactly 7 paths.**

**Explicitly NOT included**: `config/business.php` (same flag, same
default, no new key); `app/Http/Middleware/EnsureRequiredBusinessOnboardingIsComplete.php`
(already correct, §3 item 11); `app/Repositories/Eloquent/EloquentAccountRepository.php`
(already correct, §3 item 12); `app/Exceptions/Handler.php` (§5 — the
new middleware is self-contained, does not modify global JSON handling);
any of the nine onboarding Blade views; any Design System component/test;
any schema/migration; any RFC document; the A1 visual contract; the
parent M2 contract; the Product Surface Retention Audit;
`AI-AUTONOMY-STATE.json`.

**Stop threshold: any path beyond this exact 7-path allowlist is a
required-8th-path-shaped stop condition** — implementation must stop,
leave the working tree unstaged, and report.

---

## 18. No visual work — explicit boundary

Unchanged. This remediation remains explicitly nonvisual — the corrected
7-path allowlist (§17) contains none of the nine
`resources/views/customer/onboarding/**` views, no Design System
component, and no A1 visual DS test. §5's corrected local JSON response
and §9's corrected redirect target both still require zero Blade
changes: the 404 responses reuse Laravel's existing 404 handling, and
the capacity-denial message still renders through onboarding's own
existing `$errors->any()` block.

---

## 19. Post-remediation A1 visual handoff requirements

Unchanged from the original draft. Once this remediation is implemented
and human-merged (a separate, future authorization): the resulting
`main` becomes the A1 visual implementation's authoritative preservation
baseline; A1 visual implementation must mechanically re-verify the
feature-flag/master-switch behavior now matches §4-§7 of this contract,
the 9-view inventory and 12-path A1 visual allowlist are unchanged, and
the capacity-denial seam now produces the locked §9-§10 outcome without
requiring additional Blade work; if this remediation is found to have
changed the 9-view inventory or to require a new A1 visual path, A1
visual implementation must STOP and request a contract amendment; A1
visual implementation still requires its own separate, explicit human
authorization after this remediation merges.

---

## 20. Stop-condition self-check

Re-verified against the corrected evidence:

- Merged RFC-001 documents do not contradict each other (§2).
- Disabling semantics were determined from codebase precedent and the
  RFC's own exact wording, not invented (§4-§5, §7.1 — this correction
  specifically re-grounded the queued-job decision in the RFC's literal
  text after finding the original draft had misread it).
- The five known capacity exceptions were mechanically re-verified
  complete (§8, unchanged).
- No schema migration is required (§17).
- No A2 product behavior changes.
- The remediation remains isolated to the onboarding surface — 5
  production files, all specific to onboarding's own controller, job,
  routes, and middleware registration; no general entitlement or
  exception-architecture change (§17).
- No visual view change is required (§18).
- The implementation allowlist is bounded at exactly 7 paths (§17).

---

## 21. Mechanical final check

- Exactly one changed path this correction: this document. ✓
- `origin/main` re-verified exactly `9e4127b8159741fb61f3dca8174d33d267b6c759`,
  unchanged since the original draft. ✓
- Pre-correction head verified exactly `d99ef6d3652edf967ebb6ef56e127229145b7f82`. ✓
- `correction_round: 1` recorded (§0). ✓
- Controller-body guard defect identified and corrected — replaced with
  route middleware (§4). ✓
- Exact local disabled response locked for browser and JSON, no
  `Handler.php` dependency (§5). ✓
- Queued-job decision corrected against the RFC's exact, re-quoted text
  — safe failure, not normal completion (§7). ✓
- `BuildInitialBusinessSnapshot.php` added to the production allowlist (§17). ✓
- Capacity-denial redirect target corrected to the named-route form,
  verified against the real existing `saveStep()` code (§9.2). ✓
- Safe capacity message corrected to avoid implying transience (§10). ✓
- Capacity-denial test coverage expanded to all five exception families,
  not two representative ones (§15). ✓
- Private constant confirmed to remain private; every test reference
  corrected to assert the literal string (§10, §15). ✓
- Both test files' enabled-baseline `setUp()` fixtures locked (§13, §15). ✓
- Exact implementation allowlist locked at 7 paths, 8th-path stop
  threshold (§17). ✓
- Zero onboarding Blade paths, zero Design System visual paths in the
  allowlist. ✓
- A1 visual implementation remains blocked; this contract's own merge
  does not authorize it (§0, §19). ✓
- `docs/automation/AI-AUTONOMY-STATE.json` untouched. ✓
- `docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md` untouched. ✓
- `docs/automation/DESIGN-SYSTEM-M2-A1-BUSINESS-ONBOARDING-CONTRACT.md`
  untouched. ✓
- All four RFC-001/RFC-004 documents untouched — no amendment performed. ✓
- **Stale-claim sweep performed mechanically**: zero live claims that a
  job dispatched before disablement completes normally while disabled;
  zero live claims that disabled jobs transition to `ResultsReady`; zero
  live claims that `BuildInitialBusinessSnapshot.php` is excluded from
  the production allowlist; zero live claims that RFC-001 only governs
  new dispatches (§7.1 explicitly quotes and applies the in-flight-job
  sentence); zero live claims that a controller first-line guard executes
  before FormRequest validation (§4.1 explicitly documents why it does
  not); zero live claims that controller-only guarding fully hides
  disabled onboarding routes; zero live claims that
  `redirect()->to($resolvedStepValue)` is the capacity-denial redirect
  (§9.2 corrected to the named-route form); zero live claims that
  capacity coverage of only two representative exception families is
  sufficient (§15 covers all five); zero live claims that tests may
  access a private `CAPACITY_DENIAL_MESSAGE` constant (§10, §15 assert
  the literal string); zero live claims that "try again in a moment" is
  the locked capacity-denial message (§10 replaced it); zero live claims
  that the implementation allowlist total is 3 or that the stop
  threshold is the 4th path (§17 now locks 7 and the 8th). ✓

`git diff --check` run against the staged file before commit — reported
in the final chat report.

---

*End of Design System M2 A1 — Onboarding Nonvisual Behavior Remediation
Contract, Correction Round 1. Docs/audit only. No implementation has
occurred. Implementation requires its own separate, explicit human
authorization. A1 visual implementation remains blocked until this
remediation is implemented and human-merged. A2, A3, B1, and every other
roadmap group remain unstarted.*
