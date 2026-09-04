# Design System M2 A1 — Onboarding Nonvisual Behavior Remediation Contract

**Status: CONTRACT / AUDIT ONLY. No implementation has occurred under this
document. Merging this contract does NOT authorize implementation — that
requires its own separate, explicit human authorization, exactly like
every prior contract in this repository. This contract is explicitly
NONVISUAL: it does not touch, and does not authorize touching, any of the
nine onboarding Blade views.**

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
correction_round: 0
correction_round_is_final: false
```

**Base verification**: this contract is drafted on branch
`chore/design-system-m2-a1-onboarding-behavior-remediation-contract`, in
an isolated worktree, created fresh from `origin/main` at
`9e4127b8159741fb61f3dca8174d33d267b6c759` (confirmed exactly matching,
mechanically, before any drafting began — the human merge of PR #188,
"Design System M2 A1 — Business Onboarding Contract Final Correction,"
parents `b7eabccd0702723965023336dcc4f01d5389f42a` and
`2d8b43e88747a6b16a22ef2a8c80496afd055a6b`). This branch changes
**exactly one path**: this document. No `resources/`, `app/`,
`database/`, `routes/`, or test file is touched by drafting this
contract.

---

## 1. Authoritative inputs read

Read in full: `CLAUDE.md`, `AGENTS.md`,
`docs/automation/DESIGN-SYSTEM-M2-A1-BUSINESS-ONBOARDING-CONTRACT.md`
(the merged A1 visual contract, §5/§15 of which named the two blockers
this document resolves), `docs/rfcs/RFC-001-BUSINESS-CORE.md`,
`docs/rfcs/RFC-001-BUSINESS-CORE-DEPLOYMENT.md`,
`docs/rfcs/RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS.md`,
`docs/rfcs/RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS-DEPLOYMENT.md`.
Current implementation inspected in full: `routes/customer.php`,
`routes/auth.php`, `app/Http/Kernel.php`,
`app/Http/Controllers/Customer/BusinessOnboardingController.php`,
`app/Http/Middleware/EnsureRequiredBusinessOnboardingIsComplete.php`,
`app/Repositories/Eloquent/EloquentAccountRepository.php`,
`app/Library/Business/OnboardingManager.php`,
`app/Library/Business/BusinessManager.php`,
`app/Jobs/Business/BuildInitialBusinessSnapshot.php`,
`app/Exceptions/Handler.php`, `config/business.php`,
`app/Library/Entitlement/EntitlementManager.php`, all six
`App\Exceptions\Entitlement\*` classes reachable from
`assertCanCreateAnotherBusiness()`, the existing entitlement-denial
handling in `app/Http/Controllers/Customer/Workspace/WorkspaceController.php`
and `app/Http/Controllers/Admin/WorkspaceEntitlementController.php`, the
`opportunity.enabled` precedent (`config/opportunity.php`,
`app/Http/Controllers/Customer/OpportunityController.php`,
`app/Http/Controllers/Admin/{Opportunity,OpportunityRun}Controller.php`),
and every existing test file covering these paths (§15).

---

## 2. Authoritative RFC semantics — Blocker 1

`docs/rfcs/RFC-001-BUSINESS-CORE-DEPLOYMENT.md` §1 (environment-variable
table), verbatim:

> `BUSINESS_ONBOARDING_ENABLED` | `false` | **Master switch. When
> `false`, the entire onboarding wizard, analysis job, and dashboard
> redirect middleware behave as if the feature does not exist. Existing
> customer routes and the registration flow are unaffected.**

Reinforced at §8 (Rollback considerations): "setting
`BUSINESS_ONBOARDING_ENABLED=false` ... immediately stops new onboarding
rows, dashboard redirects, and analysis dispatches." Reinforced again at
§3 (rollout procedure): "voluntary onboarding" is only ever tested at
step 6, *after* `BUSINESS_ONBOARDING_ENABLED=true` is already set — the
documented rollout never exercises onboarding while the flag is `false`.

**No merged RFC-001 document contradicts this.** `RFC-001-BUSINESS-CORE.md`
§30 (Migration and deployment) and §29 are consistent with, not
contradictory to, the deployment guide's more explicit wording — neither
document claims the flag should leave onboarding routes reachable while
disabled. This is not a case of internally-contradictory merged RFCs
(§21's stop condition does not apply). **Both RFC-001 documents are
confirmed silent on the exact HTTP-level mechanics** (404? redirect?
something else?) of "behaves as if the feature does not exist" — that
choice is made in §6 below from codebase precedent, not invented.

**Resolution direction is locked**: fix the implementation to conform to
the merged RFC. `RFC-001-BUSINESS-CORE-DEPLOYMENT.md` is not amended by
this contract or its future implementation.

---

## 3. Blocker 1 — complete onboarding entry-point inventory

Mechanically re-enumerated against current `main`, not assumed from any
prior contract's list.

| # | Entry point | Route/caller | Current config read | Current disabled-state gate | Side effects possible today while `enabled=false` |
|---|---|---|---|---|---|
| 1 | Lazy `CustomerOnboarding` row creation | `BusinessOnboardingController::currentOnboarding()` (`:240`), called by all 11 controller actions; `OnboardingManager::start()` (`OnboardingManager.php:65-74`) | none | **none** | `INSERT` into `customer_onboardings`, fires `CustomerOnboardingStarted` |
| 2 | Render wizard (GET) | `customer.onboarding.show`, `BusinessOnboardingController::show()` (`:39-61`) | none | **none** | Full wizard HTML (200 OK) to any authenticated customer |
| 3 | Submit a step (5 routes: goals/business/location/services/assets) | `customer.onboarding.{goals,business,location,services,assets}.store`, `BusinessOnboardingController::store*()` (`:63-116`) | none | **none** | Creates/updates `Business`, `BusinessLocation`, `BusinessService` rows; fires `CustomerOnboardingStepCompleted` |
| 4 | Skip Assets | `customer.onboarding.assets.skip`, `::skipAssets()` (`:122-127`) | none | **none** | Advances `current_step` to `Analysis` |
| 5 | Request analysis | `customer.onboarding.analysis.request` (`throttle:5,60`), `::requestAnalysis()` (`:129-143`) | none | **none** | Increments `analysis_version`, sets `status=AnalysisPending`, **dispatches `BuildInitialBusinessSnapshot`** |
| 6 | Poll analysis status | `customer.onboarding.analysis.status` (`throttle:60,1`), `::analysisStatus()` (`:150-163`) | none | **none** | Read-only JSON; also triggers item 1's lazy-create if no row exists |
| 7 | Results-step action | `customer.onboarding.action.complete`, `::completeAction()` (`:165-186`) | none | **none** | Mutates a `Business` field; may set `current_step=Complete` |
| 8 | Complete onboarding | `customer.onboarding.complete`, `::complete()` (`:188-202`) | none | **none** | Sets `status=Completed`, fires `CustomerOnboardingCompleted`, redirects to dashboard |
| 9 | Dispatch `BuildInitialBusinessSnapshot` | Exactly one production call site: `OnboardingManager.php:260`, inside item 5's path | none (job's own `analysis_queue` config read is queue selection, not a gate) | **none** | Queues async job |
| 10 | Job processing | `BuildInitialBusinessSnapshot::handle()` (`:52-101`) | queue-name only | **none** | Builds/persists analysis snapshot, fires `InitialBusinessAnalysisCompleted` |
| 11 | Dashboard redirect | `user.home` (`routes/auth.php:56`), `EnsureRequiredBusinessOnboardingIsComplete` (alias `business.onboarding`, `Kernel.php:116`) | `business.onboarding.enabled` (`Middleware.php:40`, default `false`) | **YES — the only gated entry point today** | Pure passthrough when disabled; correct, unaffected by this remediation |
| 12 | Registration required-onboarding creation | `EloquentAccountRepository::register()` (`:125-127`) | `business.onboarding.enabled` **and** `business.onboarding.require_for_new_customers` (compound) | **YES — already correct today** | No required row created when disabled; correct, unaffected by this remediation |

**Confirmed**: the onboarding route group itself
(`routes/customer.php:508-520`, `Route::prefix('onboarding')->name('onboarding.')->group(...)`)
attaches no flag-aware middleware — only per-route throttling on 2 of the
11 routes. The group inherits solely the blanket `web, auth,
can:access_backend, ValidProduct, twofactor` stack applied to all of
`routes/customer.php`. **Items 1-10 (11 of the 12 entry points) have
zero disabled-state gating today** — only items 11 and 12 are already
correct and require no change.

---

## 4. Selected master-switch architecture

### 4.1 Seam comparison

| Candidate seam | Production paths touched | Fully satisfies RFC? | Duplicates existing checks? | Verdict |
|---|---|---|---|---|
| New onboarding route middleware | New middleware class + `Kernel.php` registration + 11 route-attribute edits across `routes/customer.php` | Yes, if applied to the whole group | No | Rejected: no codebase precedent for this shape (§6.3 of research); larger diff (3 files) than the alternative below for the same outcome |
| Reuse/extend `EnsureRequiredBusinessOnboardingIsComplete` | 1 file, but this middleware is registered only on `user.home` and is semantically "should I redirect you *into* onboarding," not "is onboarding *reachable at all*" — repurposing it would conflate two different gates | No — would require either duplicating it as a second alias (defeats "reuse") or changing its meaning (regression risk to item 11, which is already correct) | Yes, if forced onto the wrong route | Rejected |
| Manager/service-level guard (e.g., inside `OnboardingManager::start()`) | 1 file, but `OnboardingManager::start()` is called after `currentOnboarding()` already exists as the controller's own gate point — pushing the check one layer deeper doesn't prevent `analysisStatus()`'s independent JSON contract or `requestAnalysis()`'s job-dispatch from being reachable before reaching that check in every caller | No — several actions (`skipAssets`, `analysisStatus`, `completeAction`, `complete`) don't call `start()` on every path in a way that would uniformly gate them first | N/A | Rejected: doesn't cleanly cover all 11 route-level entry points as the *first* thing that runs |
| Job-level guard (inside `BuildInitialBusinessSnapshot::handle()`) | 1 file | Only addresses item 10, not items 2-8 (the wizard itself would remain fully reachable) | N/A | Rejected as a sole solution — necessary analysis only for §7's already-queued-job question, not sufficient for the master switch itself |
| **Controller-level centralized guard, mirroring the established `OpportunityController::ensureOpportunityEngineEnabled()` precedent** | **1 file**: `BusinessOnboardingController.php` | **Yes — every one of items 1-10 is reached only after this controller's own action methods execute, so gating each action's first statement structurally prevents all of them** | **No — this is the only entitlement/feature-flag "route reachability" gate pattern that already exists anywhere in this codebase (`opportunity.enabled`), so reusing its exact shape introduces no new pattern** | **SELECTED** |

### 4.2 Locked architecture

**Exact production file**: `app/Http/Controllers/Customer/BusinessOnboardingController.php`.

**Exact new method** (private, mirroring `OpportunityController::ensureOpportunityEngineEnabled()`'s
own docblock convention: "first executable line of every action"):

```php
private function ensureOnboardingEnabled(): void
{
    abort_unless(config('business.onboarding.enabled', false), 404);
}
```

**Exact call sites**: `ensureOnboardingEnabled();` added as the first
statement of all 11 public controller actions: `show()`, `storeGoals()`,
`storeBusiness()`, `storeLocation()`, `storeServices()`, `storeAssets()`,
`skipAssets()`, `requestAnalysis()`, `analysisStatus()`,
`completeAction()`, `complete()` — before `currentOnboarding()` or any
other logic runs, so no lazy row creation, no Business mutation, and no
job dispatch can occur while disabled.

**One authoritative config source**: `config('business.onboarding.enabled', false)`
— the identical key and default already used by the middleware (item
11) and registration check (item 12); no second flag, no new config
key.

**Items 11 and 12 are untouched** — both are already correct (§3); this
remediation does not modify `EnsureRequiredBusinessOnboardingIsComplete.php`
or `EloquentAccountRepository.php`.

This satisfies all ten criteria from the task: one config source (✓);
no partial access while disabled, since every action is individually
gated (✓); no lazy row creation, since the gate is the first statement
(✓); no new analysis dispatch, since `requestAnalysis()` is gated (✓);
queued-job treatment locked separately (§7) (✓); dashboard passthrough
preserved, untouched (✓); registration behavior preserved, untouched
(✓); no unrelated customer route touched — scoped to 11 actions in one
controller (✓); minimal production paths — exactly one file (✓);
straightforward, deterministic, directly mirrors an already-tested
pattern (✓).

---

## 5. Disabled response contract — browser and JSON

**Evidence, not taste**: `abort_unless($cond, 404)` throws a
`Symfony\Component\HttpKernel\Exception\NotFoundHttpException`, which
`app/Exceptions/Handler.php` routes as follows (confirmed by direct
reading of `Handler::render()`, `:67-103`, and by three independent
existing test files exercising the identical code path for a different
exception type):

**A. Normal browser request** (no `Accept: application/json`): falls
through `Handler::render()`'s `wantsJson()` check (false), then matches
the `HttpException` branch (`:97-99`, only when `config('app.env') !=
'local'`): `response()->view('errors.404', compact('exception'), 404)`
— a real HTTP **404** with the app's existing `errors/404.blade.php`
view. In local/debug environments, Laravel's own debug page renders
instead (unchanged framework behavior, identical to every other 404 in
this app). **This is the exact same code path the `opportunity.enabled`
precedent already uses and already has passing tests for**
(`tests/Feature/Opportunity/AdminOpportunityControllerTest.php`,
`OpportunityExecutionStatusHttpTest.php`, `OpportunityMutationHttpTest.php`
— all assert `assertNotFound()`).

**B. JSON/AJAX request** (the only onboarding route whose own client
code sets `Accept: application/json` is `analysis.status`'s polling
`fetch()` call, §14 of the merged A1 visual contract): `Handler::render()`'s
`wantsJson()` branch is checked *first*, before any exception-type
branch (`:70-75`): `response()->json(['status' => 'error', 'message' =>
$exception->getMessage()])` — **with no explicit status code**, which
Laravel's `ResponseFactory::json()` defaults to **HTTP 200**. This is a
real, established, already-relied-upon codebase-wide convention
(confirmed independently by `CustomerBaseController::redirectResponse()`,
`AdminBaseController::redirectResponse()`, and three passing security
test files asserting `assertOk()` + `assertJson(['status'=>'error'])`
for logically-403/404-class denials). **This remediation does not
change `Handler.php`'s global JSON behavior** — doing so would be a
repository-wide change far exceeding this contract's scope (explicitly
prohibited: "general exception architecture cleanup"). The `analysis.status`
polling response while disabled is therefore locked as: **HTTP 200,
body `{"status":"error","message":"Not Found"}`** (the default empty
message an unqualified `abort_unless(...,404)` produces, or, if a custom
message string is later found necessary at implementation time for
clarity, any fixed string containing no onboarding-specific or tenant
data — not prescribed further here since the *status code and envelope
shape* is what's being locked, not exception-message wording for a path
that carries no tenant information regardless).

**Consequence, documented not fixed**: the `analysis.blade.php` polling
script (unmodified — no visual work, §20) does not check
`response.ok` and calls `.json()` unconditionally (already a documented,
out-of-scope existing behavior). Receiving `{"status":"error",...}`
matches neither its `data.completed` nor `data.status === 'failed'`
branches, so it would simply keep polling at its existing capped
interval — a graceful, non-mutating degradation, not a new regression
introduced by this remediation, and explicitly not something this
nonvisual, view-untouched contract fixes.

**No genuine two-way ambiguity was found requiring a human decision
here** — the `opportunity.enabled` precedent and the Handler's own
global JSON convention together fully and uniquely determine both
responses from existing evidence.

---

## 6. Existing-row behavior while disabled

**Locked**: disabling is an availability gate, not a data-mutation
event. Because `ensureOnboardingEnabled()` is the first statement of
every gated action (§4.2), a request against a disabled onboarding
route is rejected via `abort_unless()` *before* `currentOnboarding()`,
any repository read, or any write ever executes. A customer's existing
`CustomerOnboarding` row — `current_step`, `completed_steps`,
`business_id`, `analysis_payload`, `analysis_version`, `status` — is
therefore **never read, never written, never touched** by a denied
request while the flag is off. Re-enabling the flag makes the existing
row immediately resumable exactly where it left off, with zero special
resume logic needed (none is added) — this falls directly out of the
guard's placement, not a separate mechanism.

---

## 7. Already-queued analysis jobs

**Scenario**: onboarding enabled → analysis dispatched (item 5/9) →
flag switched off → the already-queued job (item 10) executes.

**Locked decision: the job completes normally. No new flag check is
added inside `BuildInitialBusinessSnapshot::handle()`.**

**Evidence-based reasoning, not a default assumption**:

- RFC-001-BUSINESS-CORE-DEPLOYMENT.md's own wording is about **stopping
  new dispatches** ("immediately stops new onboarding rows, dashboard
  redirects, and analysis dispatches" — the plural noun describing the
  *action* of dispatching, consistent with §4's gate at the
  `requestAnalysis()` dispatch site, not a claim about jobs already in
  flight).
- The job's own existing design principle (`BuildInitialBusinessSnapshot.php`'s
  own comment, quoted in the merged A1 visual contract §14) is explicit
  that a missing/cross-tenant Business is treated as a **permanent**
  failure rather than left pending, specifically "so it doesn't stay
  stuck in AnalysisPending forever." Adding a flag-toggle no-op inside
  the job would introduce exactly the failure mode this job was already
  designed to avoid: an onboarding row permanently stuck in
  `AnalysisPending` if the flag happens to be off at execution time,
  with no customer-visible retry path (since the retry route,
  `analysis.request`, is itself now gated §4).
- This keeps the remediation isolated to the two actual blockers,
  avoiding "general exception architecture cleanup" / scope creep the
  task explicitly prohibits.

**Test to lock** (§15): dispatch `BuildInitialBusinessSnapshot` while
`enabled=true`, then set `config(['business.onboarding.enabled' =>
false])` before the job runs (simulating the toggle), run the job, and
assert it still completes normally — `status` transitions to
`ResultsReady`, `analysis_payload` is populated, no exception is thrown
by the job itself.

---

## 8. Blocker 2 — exact exception inventory (mechanically re-verified)

`EntitlementManager::assertCanCreateAnotherBusiness()` (`:225-241`) —
the exact, complete `match` statement:

| Denial reason | Thrown class | Extends | Message (no tenant data beyond a numeric Workspace ID) |
|---|---|---|---|
| `workspace_plan_unassigned` | `App\Exceptions\Entitlement\WorkspacePlanUnassignedException` | `\RuntimeException` | `"Workspace [{$id}] has no plan assignment."` |
| `plan_inactive` | `App\Exceptions\Entitlement\InactiveWorkspacePlanException` | `\RuntimeException` | `"Workspace [{$id}]'s plan assignment is inactive."` |
| `plan_suspended` | `App\Exceptions\Entitlement\SuspendedWorkspacePlanException` | `\RuntimeException` | `"Workspace [{$id}]'s plan assignment is suspended."` |
| `business_slot_allocation_required` | `App\Exceptions\Entitlement\BusinessSlotAllocationRequiredException` | `\RuntimeException` | `"Workspace [{$id}] requires an additional Business slot allocation to create another Business."` |
| `business_slot_limit_exceeded` | `App\Exceptions\Entitlement\BusinessSlotLimitExceededException` | `\RuntimeException` | `"Workspace [{$id}] is at its maximum Business slot capacity."` |
| *(default arm, currently unreachable)* | bare `\RuntimeException` | — | `"Unexpected capacity denial reason [...] for Workspace [{$id}]."` |

**Confirmed complete**: `decideBusinessSlotCapacity()` (the only method
`assertCanCreateAnotherBusiness()` calls) contains zero `throw`
statements and only ever returns one of the five known reason strings
or `null` — the `default` match arm is real code but currently
unreachable in practice. **No shared base/abstract exception class
exists anywhere in `app/Exceptions/**`** — every custom exception,
including all five above, extends the base PHP `\RuntimeException`
directly, confirmed by grep across all ~78 exception files. There is
therefore no legitimate shared parent to catch instead of the five
named types, and this contract does **not** introduce a new shared
exception interface/hierarchy purely for catching convenience (that
would be a "speculative new exception hierarchy," explicitly
prohibited).

**Confirmed anonymization**: all five constructors take only `public
readonly int $workspaceId`, interpolated into a fixed English sentence
— no customer name, email, Business name, or other tenant-identifying
string in any message. The prior contract's "already deliberately
anonymized to a numeric Workspace ID" claim is verified true, not
assumed.

**Confirmed via `app/Exceptions/Handler.php`**: none of the six throw
sites above is special-cased (`$dontReport` list and `render()`'s
`instanceof` branches both checked, §5). An uncaught instance of any of
them produces the exact same 404-shaped... no — **HTML: a generic
framework/500-shaped response** (falls through every branch to
`parent::render()`, which for a plain `RuntimeException` in production
is a generic 500, not a 404 — this is a different, uncaught-generic-error
outcome than Blocker 1's deliberate `abort_unless(...,404)`, and is the
actual defect being fixed here). **JSON**: same `wantsJson()` 200-status
envelope as §5 (`{"status":"error","message":"<raw message, currently
including the Workspace ID>"}` — i.e., **today, a JSON-wanting request
that hits this path leaks the numeric Workspace ID in the response
body**, since nothing intercepts it before Handler's generic pass-through;
this is corrected by this remediation, §9, since the seam intercepts the
exception before it ever reaches `Handler::render()`).

**Not part of `assertCanCreateAnotherBusiness()`'s own throw set, noted
but out of this contract's exact scope**: `BusinessManager::applyIdentity()`'s
immediately-preceding calls, `resolveLegacyOnboardingWorkspace()` and
`lockForLegacyOnboardingBusinessCreation()`, can throw
`WorkspaceContextRequiredException` or a missing-owner
`ModelNotFoundException` (per an existing code comment,
`BusinessManager.php:144-151`). These hit the identical `saveStep()`
catch-gap for the identical structural reason, but are **not** RFC-004
capacity-denial exceptions and are **not** added to this contract's
catch set — expanding the catch beyond the exact six `EntitlementManager`
throw sites above would exceed this contract's locked purpose (§ "Locked
purpose": resolves exactly two blockers, not a general exception-handling
audit of the onboarding Business-creation path). If a future defect
report finds these two exceptions also reach a customer as a generic
500, that is separate, future scope.

---

## 9. Selected capacity-denial architecture

### 9.1 Existing repository precedent (the deciding evidence)

**This exact pattern already exists, twice, for the other two callers
of `assertCanCreateAnotherBusiness()`** — `WorkspaceController::storeBusiness()`/`::reassignBusiness()`
(customer-facing, `app/Http/Controllers/Customer/Workspace/WorkspaceController.php:241-268,288-318`)
and `Admin\WorkspaceEntitlementController::mutationErrorRedirect()`
(`:230-248`): both catch each of the five named exception types
individually and redirect back with a `flash_error` session message.
One of these two (`WorkspaceController`) has its own test
(`tests/Feature/Workspace/WorkspaceBusinessCreationHttpTest.php:379-398`)
whose docblock states this exact defect — uncaught propagation —
**previously existed on that surface too, and was fixed by RFC-004
Milestone 3**. Onboarding's `BusinessManager::applyIdentity()` call site
is the one caller of `assertCanCreateAnotherBusiness()` that was never
given the same treatment.

**Why onboarding cannot reuse `flash_error` verbatim**: the
`flash_error` session-flash convention is rendered by a Blade partial
used in the Workspace/Admin surfaces, not present anywhere in the nine
onboarding views (confirmed: onboarding's only error-rendering surface
is the shared `$errors->any()` block in `show.blade.php`, per the
merged A1 visual contract §7.1/§11). Introducing `flash_error` into
onboarding would require either a Blade view change (explicitly
forbidden, §20) or would silently render nothing. The onboarding
surface already has its **own** working error-rendering seam:
`BusinessOnboardingController::saveStep()` (`:211-226`) already catches
`InvalidArgumentException` and redirects to the resolved step with
`withInput()->withErrors(['onboarding' => $message])` — which the
existing `show.blade.php` `$errors->any()` block already renders,
today, with zero further view work.

### 9.2 Locked architecture

**Exact production file/method**: `app/Http/Controllers/Customer/BusinessOnboardingController.php`,
method `saveStep(Closure $action)` (`:211-226`) — the same shared
closure-wrapper already used by all 5 step-store actions
(`storeGoals`/`storeBusiness`/`storeLocation`/`storeServices`/`storeAssets`).
Only the Business step's underlying call chain
(`OnboardingManager::saveBusinessStep()` → `BusinessManager::createOrUpdateOnboardingBusiness()`
→ `applyIdentity()`'s CREATE branch) can throw the six types in §8 —
Location/Services/Assets never call that CREATE branch — so adding one
additional `catch` clause to this single shared helper covers the
exact, and only, path that can produce them, without touching five
separate action methods individually.

**Exact caught type**: a union catch of the five named
`App\Exceptions\Entitlement\*` classes from §8 — **not** a bare
`\RuntimeException` catch (which would also swallow unrelated
`RuntimeException`s from elsewhere in the wrapped closure, masking real
bugs as friendly "try again" messages) and **not** a new shared
interface (§8, prohibited). The `default`-arm bare `RuntimeException`
(currently unreachable, §8) is **deliberately not caught** — it remains
whatever generic error it always was if it ever becomes reachable,
which this contract records as a conscious, evidence-based choice, not
an oversight.

```php
} catch (WorkspacePlanUnassignedException
       | InactiveWorkspacePlanException
       | SuspendedWorkspacePlanException
       | BusinessSlotAllocationRequiredException
       | BusinessSlotLimitExceededException $e) {
    return redirect()
        -&gt;to($this-&gt;onboarding-&gt;resolveStep($onboarding)-&gt;value)
        -&gt;withInput()
        -&gt;withErrors(['onboarding' =&gt; self::CAPACITY_DENIAL_MESSAGE]);
}
```

placed alongside the existing `catch (InvalidArgumentException $e)`
clause in `saveStep()`, using the identical redirect-target/`withInput()`/
`withErrors(['onboarding' => ...])` shape already established there —
zero new redirect logic, zero new error-rendering mechanism.

**Redirect target**: identical to the existing `InvalidArgumentException`
handler's target — the resolved current step (which will be `Business`,
since `current_step` never advances past it when `applyIdentity()`
throws before `completeStep()` is ever reached).

**Input preservation**: `withInput()`, matching every other `saveStep()`
failure path — the customer's submitted Business-step form values are
preserved.

**State-preservation guarantees** (all already true today, by virtue of
the existing `DB::transaction()` wrapping `applyIdentity()`'s CREATE
branch — not newly introduced by this catch clause, only newly
surfaced safely instead of as a 500): zero Business persisted (the
transaction rolls back before any insert completes); `business_id`
remains null (never attached); `current_step` remains `Business`, not
advanced; onboarding `status` unchanged, not completed; no analysis
dispatch (unreachable — the Business step precedes Analysis in
`STEP_ORDER`).

**Non-onboarding callers unaffected**: `WorkspaceController` and
`Admin\WorkspaceEntitlementController`'s own catch blocks are not
touched by this contract.

---

## 10. Exact safe customer message

**Locked, single, generic, deterministic string** — not five distinct
messages. Rationale: the task's own default preference ("prefer one
generic deterministic message unless repository precedent proves
separate messages are necessary") governs here; the existing five
distinct Workspace/Admin messages are written for an audience that
already understands "plan," "slot allocation," and Workspace-management
concepts — a brand-new customer mid-onboarding, who has typically never
seen a plan-management screen, is not that audience, and no
onboarding-specific evidence was found requiring finer granularity.

```php
private const CAPACITY_DENIAL_MESSAGE =
    "We can't create your business right now. Please try again in a moment, or contact support if this continues.";
```

(mirroring the existing `BuildInitialBusinessSnapshot::SAFE_ERROR`
fixed-string convention already established elsewhere in this exact
codebase for the identical "never leak raw exception text" discipline).

**Verified against every requirement**: actionable for a legitimate
customer (retry / contact support) — ✓; no Workspace numeric ID — ✓
(zero interpolation); no internal plan-assignment details — ✓; no
exception-class text — ✓; no stack/error information — ✓; applies
uniformly to all five capacity/plan-denial variants covered by the
catch — ✓ (deliberately generic, not variant-specific); exactly
testable — ✓ (`assertSessionHasErrors(['onboarding' =>
BusinessOnboardingController::CAPACITY_DENIAL_MESSAGE])` or the literal
string).

---

## 11. Security preservation

Reconfirmed, none weakened by either blocker's remediation:

- **Authentication**: unaffected — `ensureOnboardingEnabled()` runs
  after the route group's own `auth` middleware already ran; a
  guest still gets redirected to login before ever reaching the new
  guard (unchanged route-group ordering).
- **Onboarding/Business ownership, Workspace association, RFC-004
  capacity enforcement, transaction/locking**: entirely untouched code
  paths — this contract adds a guard clause and a catch clause; it does
  not modify `OnboardingManager::assertOwnership()`,
  `BusinessManager::assertOwnership()`, `EntitlementManager`'s decision
  logic, or any lock acquisition.
- **Cross-customer isolation**: unaffected — the new catch clause fires
  only after ownership has already been asserted upstream
  (`OnboardingManager::saveBusinessStep()`'s own `assertOwnership()`
  call precedes `BusinessManager` invocation).
- **Exception anonymization**: strengthened, not weakened — today, a
  JSON-wanting caller hitting this path would see the raw exception
  message (including the numeric Workspace ID) via Handler's generic
  pass-through (§8); after remediation, the fixed, zero-interpolation
  message in §10 is used instead, and the exception never reaches
  `Handler::render()` at all for this specific path.
- **No raw server exception output**: guaranteed — `withErrors()`
  receives only the fixed constant string, never `$e->getMessage()`.
- **`AuthorizationException` is not accidentally converted into a
  friendly capacity error**: guaranteed by type-safety — the new catch
  clause's union type list contains only the five named
  `RuntimeException` subclasses from §8; `AuthorizationException` does
  not extend `RuntimeException` and is structurally incapable of
  matching this catch, so it continues to propagate uncaught exactly as
  documented in the merged A1 visual contract §4.2/§16 item 14 (a
  framework 403, unchanged).
- **Cannot infer a foreign Workspace's ID/plan/allocation/capacity
  through the new path**: the fixed message in §10 carries zero
  interpolated data of any kind, for any of the five exception types,
  for any Workspace — a foreign customer triggering any of the five
  denial reasons against their own Workspace sees the identical fixed
  string a legitimate customer would, with nothing to distinguish which
  of the five reasons actually fired, let alone any other tenant's
  data.

---

## 12. State-machine preservation

Neither remediation redesigns the onboarding state machine. Preserved
exactly: step order `Goals → Business → Location → Services → Assets →
Analysis → Results → Complete`; Assets remains the only skippable step
(`OnboardingManager::SKIPPABLE_STEPS`, untouched).

**Capacity denial at Business** (§9): leaves the customer on/recoverable
at Business (redirect target = resolved current step, still `Business`)
— ✓; does not mark Business complete — ✓ (no Business row exists to
mark); does not advance `current_step` — ✓ (unreached `completeStep()`
call); does not create Business — ✓ (transaction rollback); does not
dispatch analysis — ✓ (unreachable, Analysis follows Business in
`STEP_ORDER`).

**Master-switch disablement** (§4-§7): does not itself complete,
dismiss, reset, delete, or advance an existing onboarding record — ✓
(§6: the guard runs before any read/write; no code path in this
remediation calls `complete()`, `dismiss()`, or any mutation method).

---

## 13. Stale-test inventory

Mechanically identified, from `tests/Feature/Business/BusinessOnboardingHttpTest.php`
(the only test file with disabled-flag-named tests):

- **`test_direct_onboarding_routes_remain_reachable_when_config_is_disabled`**
  (`:358-365`) — exact current body:
  ```php
  public function test_direct_onboarding_routes_remain_reachable_when_config_is_disabled(): void
  {
      config(['business.onboarding.enabled' =&gt; false]);
      $this-&gt;actingAsHttpCustomer();
      $this-&gt;get(route('customer.onboarding.show'))-&gt;assertOk();
  }
  ```
  **This is the one stale test** — it directly encodes the pre-remediation
  drift (asserting `assertOk()` where the merged RFC intent, and this
  contract's §4-§5, require `assertNotFound()`). It must be rewritten
  during implementation to assert the corrected behavior; it is not
  fixed by this docs-only contract (§20).

**Not stale, remain unchanged**: `test_dashboard_redirects_when_onboarding_enabled_and_required_incomplete`
(`:301-310`), `test_dashboard_is_not_redirected_when_onboarding_config_is_disabled`
(`:317-330`), `test_dashboard_is_not_redirected_when_onboarding_config_key_is_missing`
(`:336-342`), `test_dashboard_is_not_redirected_when_onboarding_is_completed_and_config_enabled`
(`:347-356`) — all four test the dashboard-redirect middleware (item 11,
§3), which is already correct and is not modified by this remediation;
they remain valid regression tests.

**No stale test exists for Blocker 2** — `BusinessOnboardingHttpTest.php`
contains zero tests referencing `EntitlementManager`/capacity/slot
keywords (confirmed by grep); the gap is a missing test, not a wrong
one.

---

## 14. Focused test file strategy

**Locked**: extend two existing files. No new test file is created.

- **`tests/Feature/Business/BusinessOnboardingHttpTest.php`** — already
  the HTTP-level test file for this exact controller/route surface
  (§13); its responsibility already matches both blockers' HTTP-level
  behavior. Receives: the corrected stale test (§13), the full "master
  switch off" matrix (§15), the "master switch on" regression
  confirmations, and the capacity-denial HTTP-level tests (§15) — the
  latter closing the coverage gap identified in §8/§9.1 (no HTTP-level
  capacity test exists today for onboarding, unlike the analogous,
  already-covered `WorkspaceController` surface).
- **`tests/Feature/Business/BuildInitialBusinessSnapshotJobTest.php`** —
  already the job-level test file; receives exactly one new test for
  the already-queued-job flag-toggle scenario (§7).

**Why not a new file**: both existing files' stated responsibilities
already exactly match what needs testing; the task explicitly disfavors
"redundant files merely for slice naming," and no distinct
responsibility exists here that isn't already "onboarding HTTP
behavior" or "onboarding analysis job behavior."

---

## 15. Exact focused test plan

**MASTER SWITCH OFF** (`BusinessOnboardingHttpTest.php`):

- Registration does not create a required onboarding row when disabled
  (already-correct regression, re-confirmed explicitly).
- Dashboard does not redirect into onboarding when disabled (existing
  test, unchanged, re-confirmed as still passing).
- Corrected: direct `GET customer.onboarding.show` returns **404**, not
  200 (replaces the stale test, §13).
- Every one of the 5 step-store POSTs denied with 404.
- Assets skip denied with 404.
- Analysis-request POST denied with 404.
- Analysis-status GET denied consistently: **HTTP 200**, body
  `{"status":"error",...}` (§5.B — locked, not a 404, since this route's
  own client sets `Accept: application/json`).
- Results-action POST denied with 404.
- Completion POST denied with 404.
- No `CustomerOnboarding` row is created by any denied request (assert
  `CustomerOnboarding::count()` unchanged before/after).
- No `Business`/`BusinessLocation`/`BusinessService` row is
  created/updated by any denied request.
- No `BuildInitialBusinessSnapshot` dispatch occurs from a denied
  `analysis.request` (assert via `Queue::fake()` + `Queue::assertNotPushed()`).
- An existing onboarding record's `current_step`/`completed_steps`/
  `business_id`/`analysis_payload`/`analysis_version`/`status` are
  byte-identical before and after a denied request (§6).

**MASTER SWITCH ON** (regression, existing behavior unchanged):

- Existing voluntary onboarding path still fully functional end-to-end.
- Required-on-registration behavior unchanged when both flags require
  it.
- Dashboard redirect behavior unchanged.
- Normal wizard step persistence unchanged for all 5 steps.
- Analysis dispatch remains functional.

**QUEUED ANALYSIS** (`BuildInitialBusinessSnapshotJobTest.php`):

- §7's locked flag-toggle behavior: job dispatched while enabled,
  flag flipped to `false` before the job runs, job still completes
  normally (`status → ResultsReady`, `analysis_payload` populated, no
  exception thrown by the job).

**CAPACITY DENIAL** (`BusinessOnboardingHttpTest.php`, new):

- For at least `BusinessSlotLimitExceededException` and
  `WorkspacePlanUnassignedException` (representative of the five;
  full coverage of all five preferred if the fixture setup allows it
  cheaply, mirroring `EntitlementManagerBusinessSlotCapacityTest.php`'s
  existing fixture patterns) — POSTing the Business step against a
  capacity-denying Workspace:
  - No generic 500 — response is a redirect (`assertRedirect()`), not a
    500 status.
  - Exact safe message present: `assertSessionHasErrors(['onboarding' =>
    BusinessOnboardingController::CAPACITY_DENIAL_MESSAGE])`.
  - No Business persisted (`Business::count()` unchanged).
  - No step advancement (`current_step` still `Business`).
  - No onboarding completion mutation (`status` unchanged).
  - Response body/session contains no digit-string matching the
    Workspace ID and no substring of any of the five exception class
    names (asserting the raw message never leaks).
  - Existing success path (capacity available) remains unchanged —
    regression confirmation.
  - The update-existing-Business branch (not the CREATE branch) remains
    unaffected — regression confirmation that the capacity gate applies
    only to Business creation, per §9.2/existing `BusinessManagerTest`
    coverage.

**SECURITY** (`BusinessOnboardingHttpTest.php`):

- Cross-tenant/foreign-customer semantics unchanged — existing ownership
  tests re-run unmodified.
- `AuthorizationException` (a genuine ownership failure, distinct from a
  capacity denial) still propagates as a 403, not converted into the
  new friendly capacity message — explicit test proving the new catch
  clause's type-safety boundary (§11).
- Only the five named exception types use the new denial seam — no
  other `RuntimeException` is silently caught by the new clause
  (regression test using a different, unrelated `RuntimeException`
  thrown from a test double, if the existing test infrastructure
  supports injecting one; otherwise documented as guaranteed by the
  union catch type alone, §9.2).

---

## 16. Regression plan

**Focused suites, run before and after implementation**:

```
php artisan test tests/Feature/Business tests/Unit/Business
php artisan test tests/Feature/Entitlement tests/Unit/Entitlement
php artisan test tests/Feature/Workspace tests/Unit/Workspace
php artisan test tests/Feature/Usage
```

(the last three groups because they exercise the same
`assertCanCreateAnotherBusiness()`/legacy-onboarding-Business-creation
path from other angles — `EntitlementManagerBusinessSlotCapacityTest`,
`EntitlementManagerConcurrencyTest`, `BusinessManagerTest`,
`WorkspaceManagerPreEnforcementTest`, `WorkspaceM1BBoundaryTest`,
`NewBusinessWalletInitializationTest`,
`NewBusinessPayerAssignmentInitializationTest` — none of which this
remediation's production change should affect, since it adds a
controller-layer guard/catch, not a domain-layer change).

**Also required**: full suite, `php artisan test`.

**Because remediation legitimately changes one stale test's assertion
and adds new tests** (§13-§15):

- Record the exact **PRE-REMEDIATION** baseline (on this contract's own
  base, `9e4127b8159741fb61f3dca8174d33d267b6c759`) before implementation
  begins.
- Record the exact **POST-REMEDIATION** baseline after implementation.
- **Do not require an equal total passing count** — the stale test's
  assertion changes (same test name, different assertion — net zero
  count change from that one) while multiple new tests are added (net
  positive count change) — the exact delta must be reported, not
  assumed to be exactly the raw new-test count, since the stale test's
  correction itself might also change whether it counts as 1 test
  post-fix (it does — same method, corrected body).
- Require **0 failures, 0 skipped, exit 0** on both baselines.
- Explain any test-count change mechanically (which files, which
  method names, added vs. corrected) in the implementation's own
  completion report — not fabricated here in advance of real numbers.

**The post-remediation, human-merged `main` becomes the future A1
visual implementation's baseline** (§18, and per the merged A1 visual
contract's own `visual_implementation_base: post_nonvisual_remediation_main`
governance key) — this remediation's own regression run on that merged
`main` is what the eventual A1 visual branch must re-verify against
(per that contract's §5 Correction Round 2 requirement), not any SHA
this document itself was drafted against.

---

## 17. Exact future implementation allowlist and stop threshold

**Mechanically derived, not assumed** — every path below is justified
by a specific section above; nothing is included speculatively.

**Production (1 path):**
1. `app/Http/Controllers/Customer/BusinessOnboardingController.php` —
   add `ensureOnboardingEnabled()` (§4.2) called as the first statement
   of all 11 public actions; add the five-exception union catch clause
   and `CAPACITY_DENIAL_MESSAGE` constant to `saveStep()` (§9.2/§10).

**Tests (2 paths):**
2. `tests/Feature/Business/BusinessOnboardingHttpTest.php` — correct
   the one stale test (§13); add the "master switch off/on" matrix and
   capacity-denial tests (§15).
3. `tests/Feature/Business/BuildInitialBusinessSnapshotJobTest.php` —
   add the queued-job flag-toggle test (§7/§15).

**Total: exactly 3 paths.**

**Explicitly NOT included, and not derivable from any section above**:
`config/business.php` (no new key needed — same flag, same default);
`app/Http/Middleware/EnsureRequiredBusinessOnboardingIsComplete.php`
(already correct, §3 item 11); `app/Repositories/Eloquent/EloquentAccountRepository.php`
(already correct, §3 item 12); `app/Jobs/Business/BuildInitialBusinessSnapshot.php`
(§7 — deliberately not gated); `app/Exceptions/Handler.php` (§5/§8 —
existing global behavior reused, not modified); `app/Http/Kernel.php`
and `routes/**` (no new middleware, no route changes, §4.2); any of the
nine onboarding Blade views (§20); any Design System component; any
schema/migration (no new column/table needed — the flag and all five
exception types already exist); any RFC document; the A1 visual
contract; the parent M2 contract; the Product Surface Retention Audit;
`AI-AUTONOMY-STATE.json`.

**Stop threshold: any path beyond this exact 3-path allowlist is a
required-4th-path-shaped stop condition** — implementation must stop,
leave the working tree unstaged, and report, exactly as this
repository's prior contracts require for their own allowlists.

---

## 18. No visual work — explicit boundary

This remediation is **explicitly nonvisual**. Future implementation
must not edit any of the nine `resources/views/customer/onboarding/**`
views (§17's allowlist contains none of them). It must not: adopt any
`x-*` Design System component; restyle the stepper; change any
card/button/field markup; change the Results empty state; change any
icon; change any layout. Nothing in §4-§10's chosen architecture
requires a view change — §5 confirmed the 404/JSON responses need no
new Blade template (reuses `errors/404.blade.php` and the existing JSON
envelope shape), and §9.1 confirmed the capacity-denial message reuses
onboarding's own existing `$errors->any()` rendering with zero view
changes.

---

## 19. Post-remediation A1 visual handoff requirements

Once this remediation is implemented and human-merged (a separate,
future authorization, not granted by this contract):

- The resulting `main` becomes the authoritative preservation baseline
  for A1 visual implementation (§16, and the merged A1 visual contract's
  own `visual_implementation_base` governance key).
- Before any Blade edit, A1 visual implementation must mechanically
  re-verify, against that new `main`: the feature-flag/master-switch
  behavior now matches §4-§7 of this contract exactly; the 9-view
  inventory (merged A1 visual contract §2) is unchanged; the 12-path A1
  visual allowlist (merged A1 visual contract §18) is unchanged; the
  capacity-denial seam (§9-§10 here) now produces the locked outcome
  and does not require any additional Blade work beyond what that
  contract's own §11 component-adoption matrix already anticipates
  (none — the error still renders through the existing `x-alert`/
  `$errors->any()` adoption already planned there).
- If this remediation is found, once implemented, to have changed the
  9-view inventory or to require a new A1 visual path, A1 visual
  implementation must STOP and request a contract amendment to the
  merged A1 visual contract — not silently proceed (per that contract's
  own §5 Correction Round 2 language, restated here for this
  document's own completeness).
- A1 visual implementation still requires its own separate, explicit
  human authorization after this remediation merges — this remediation
  contract's own merge does not grant it (§0).

---

## 20. Stop-condition self-check

None of the task's stop conditions apply, verified against the evidence
gathered:

- Merged RFC-001 documents do not contradict each other (§2).
- Disabling semantics were determined from the `opportunity.enabled`
  codebase precedent, not a coin-flip product decision (§4-§5).
- The five known capacity exceptions were mechanically re-verified
  complete, plus one confirmed-unreachable default arm (§8).
- No schema migration is required (§17).
- No A2 product behavior changes (`WorkspaceController`/`Admin\WorkspaceEntitlementController`
  are read-only precedent, untouched, §9.1/§9.2).
- The remediation is fully isolated to `BusinessOnboardingController.php`
  (§17) — not entangled with general entitlement behavior.
- No visual view change is required (§18).
- The implementation allowlist is bounded at exactly 3 paths (§17).

---

## 21. Mechanical final check

- Exactly one changed path: this document. ✓
- Remediation contract only — no application, test, RFC, or other
  governance file created/modified by drafting this contract. ✓
- `origin/main` verified exactly `9e4127b8159741fb61f3dca8174d33d267b6c759`
  before drafting began. ✓
- PR #188's merged A1 visual contract read in full (§1). ✓
- Both RFC-001 documents read in full (§1-§2). ✓
- Relevant RFC-004 documents read in full (§1, §8). ✓
- Complete flag read-site inventory: 2 sites, both already correct
  (items 11-12, §3), plus 10 currently-ungated entry points identified
  and closed (§3-§4). ✓
- Complete onboarding entry-point inventory: 12 items, mechanically
  re-derived, not assumed (§3). ✓
- Complete expected capacity-exception inventory: 5 named + 1 confirmed-
  unreachable default arm, mechanically re-verified against current
  `main`, not trusted from a prior list (§8). ✓
- Exact master-switch architecture selected, with a seam comparison
  showing why alternatives were rejected (§4). ✓
- Exact disabled response selected for both browser and JSON, evidence-
  based (§5). ✓
- Queued-job semantics selected and justified (§7). ✓
- Exact capacity-denial seam selected, mirroring verified existing
  precedent (§9). ✓
- Exact safe customer message locked (§10). ✓
- Stale test identified and named exactly (§13). ✓
- Exact implementation allowlist locked at 3 paths, with an explicit
  list of what is deliberately excluded and why (§17). ✓
- Stop threshold locked at the 4th path (§17). ✓
- Zero onboarding Blade paths in the implementation allowlist. ✓
- Zero Design System visual paths in the implementation allowlist. ✓
- A1 visual implementation remains blocked; this contract's own merge
  does not authorize it (§0, §19). ✓
- `docs/automation/AI-AUTONOMY-STATE.json` untouched. ✓
- `docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md` untouched. ✓
- `docs/automation/DESIGN-SYSTEM-M2-A1-BUSINESS-ONBOARDING-CONTRACT.md`
  untouched. ✓
- `docs/rfcs/RFC-001-BUSINESS-CORE.md`,
  `docs/rfcs/RFC-001-BUSINESS-CORE-DEPLOYMENT.md`, and every RFC-004
  document untouched — no amendment performed (§2, §20). ✓

`git diff --check` run against the staged file before commit — reported
in the final chat report.

---

*End of Design System M2 A1 — Onboarding Nonvisual Behavior Remediation
Contract. Docs/audit only. No implementation has occurred. Implementation
requires its own separate, explicit human authorization. A1 visual
implementation remains blocked until this remediation is implemented and
human-merged. A2, A3, B1, and every other roadmap group remain
unstarted.*
