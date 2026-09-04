# Design System M2 — Surviving Roadmap A1: Business Onboarding — Visual Contract

**Status: CONTRACT / AUDIT ONLY. No implementation has occurred under this
document. Correction Round 1 found two BLOCKING NONVISUAL prerequisites
(§5, §15) — A1 visual implementation is BLOCKED until both are separately
remediated and human-merged. Merging this contract does NOT authorize
visual implementation, and would not even if it were unblocked — that
still requires its own separate, explicit human authorization, exactly
like every prior contract in this repository.**

---

## 0. Governance

```
roadmap_group: A1
roadmap_group_name: Business Onboarding
classification: KEEP_PLUS_REDESIGN

docs_only: true
implementation_has_occurred: false
merge_authorizes_implementation: false
implementation_requires_separate_human_authorization: true

security_pre_audit_required: true
security_pre_audit_complete: true
security_pre_audit_status: passed_no_blocking_security_defect

nonvisual_blocking_prerequisites_found: true
nonvisual_blocking_prerequisite_count: 2
onboarding_enabled_flag_rfc_drift_blocking: true
capacity_denial_500_blocking: true

a1_visual_status: blocked_until_nonvisual_onboarding_behavior_remediation_human_merged

advance_automatically: false
start_a2_automatically: false
start_b1_automatically: false

merge_authority: human_only
no_force_push: true
no_deployment: true

maximum_correction_rounds: 2
correction_round: 1
correction_round_is_final: false
```

This document is drafted on branch
`chore/design-system-m2-a1-business-onboarding-contract`, in an isolated
worktree, based on `origin/main` at
`3e36dd5e857da074b4334eb106cdce353e94f19c` — the human merge of PR #186,
"Design System M2 — Surviving Product Roadmap." This branch changes
**exactly one path**: this document. No `resources/`, `app/`, `database/`,
`routes/`, or test file is touched.

---

## 1. Base verification

- `git rev-parse origin/main` = `3e36dd5e857da074b4334eb106cdce353e94f19c`
  — confirmed exactly matching, mechanically, before any drafting began.
- Branch `chore/design-system-m2-a1-business-onboarding-contract` created
  fresh from that exact SHA via `git worktree add -b ... origin/main`.
- Read in full: `CLAUDE.md`, `AGENTS.md`,
  `docs/automation/DESIGN-SYSTEM-CONTRACT.md`,
  `docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md`,
  `docs/automation/PRODUCT-SURFACE-RETENTION-AUDIT.md`. The merged Slice
  3/5/6 contracts and their security-remediation contracts were consulted
  as governance/mechanical precedent (icon-verification method,
  component-adoption-matrix format, the "avoid manufacturing a no-op
  allowlist entry" discipline established after Slice 6's `_chat_list`
  correction) — no adoption counts or assumptions were copied from them.

---

## 2. Exact 9-view inventory (mechanically confirmed)

`find resources/views/customer/onboarding -name "*.blade.php" | sort` and
a direct `Read` of every returned file, both performed before any other
work:

1. `resources/views/customer/onboarding/show.blade.php`
2. `resources/views/customer/onboarding/steps/analysis.blade.php`
3. `resources/views/customer/onboarding/steps/assets.blade.php`
4. `resources/views/customer/onboarding/steps/business.blade.php`
5. `resources/views/customer/onboarding/steps/complete.blade.php`
6. `resources/views/customer/onboarding/steps/goals.blade.php`
7. `resources/views/customer/onboarding/steps/location.blade.php`
8. `resources/views/customer/onboarding/steps/results.blade.php`
9. `resources/views/customer/onboarding/steps/services.blade.php`

**Exactly 9 — matches the locked inventory exactly. No 10th onboarding
Blade file exists** (mechanical `find` count = 9). Every file was read in
full, not sampled. Total line count, hand-summed from each file's own
line count below (§7): **355 lines** (close to, and consistent with, the
Product Surface Retention Audit's own stated "~347 lines" approximation —
the audit's figure was explicitly an approximation; this contract's count
is exact, from direct inspection of every file).

---

## 3. A1 / A2 product boundary

A1 is **only** the retained Business Onboarding wizard experience — the 9
views in §2. The following are explicitly **out of scope** for A1 and are
**not** modified, read-for-editing, or planned by this contract:

- `resources/views/customer/business/**` (A2)
- `resources/views/customer/workspace/**`, `resources/views/customer/workspaces/**` (A2)
- `resources/views/admin/businesses/**`, `resources/views/admin/workspaces/**` (A2)

Onboarding creates/updates a `Business` record and, on its legacy path,
can auto-provision a `Workspace` — this is backend behavior the visual
contract must **preserve** (§15), not a reason to pull A2's Business/
Workspace management screens into A1's scope. Backend/controller/
service/config/test files were read extensively to understand behavior,
security, and preservation requirements (§4-§6, §14-§17) — none of them
are future visual-implementation write targets; the future write
allowlist (§18) contains only the 9 Blade views and 3 new Design System
test files.

---

## 4. Architecture trace

### 4.1 Routes (`routes/customer.php:508-520`, under the blanket
`web, auth, can:access_backend, ValidProduct, twofactor` stack applied to
all of `routes/customer.php` — `app/Providers/RouteServiceProvider.php:77-80`)

| Method | URI | Route name | Controller@action | Extra middleware |
|---|---|---|---|---|
| GET | `onboarding/{step?}` | `customer.onboarding.show` | `BusinessOnboardingController@show` | — |
| POST | `onboarding/goals` | `customer.onboarding.goals.store` | `@storeGoals` | — |
| POST | `onboarding/business` | `customer.onboarding.business.store` | `@storeBusiness` | — |
| POST | `onboarding/location` | `customer.onboarding.location.store` | `@storeLocation` | — |
| POST | `onboarding/services` | `customer.onboarding.services.store` | `@storeServices` | — |
| POST | `onboarding/assets` | `customer.onboarding.assets.store` | `@storeAssets` | — |
| POST | `onboarding/assets/skip` | `customer.onboarding.assets.skip` | `@skipAssets` | — |
| POST | `onboarding/analysis` | `customer.onboarding.analysis.request` | `@requestAnalysis` | `throttle:5,60` |
| GET | `onboarding/analysis/status` | `customer.onboarding.analysis.status` | `@analysisStatus` | `throttle:60,1` |
| POST | `onboarding/action` | `customer.onboarding.action.complete` | `@completeAction` | — |
| POST | `onboarding/complete` | `customer.onboarding.complete` | `@complete` | — |

Plus, outside the wizard: `GET /dashboard` (`user.home`,
`routes/auth.php:56`) carries `web, twofactor, auth, verified,
business.onboarding` — the redirect-gate route (§5), not a wizard route
and not touched by A1.

**Preservation requirement**: every URI, route name, HTTP method, and
throttle rate above is locked. A1 implementation must not add, remove, or
rename any route, or change any form's `action`/`method`.

### 4.2 Controller — `app/Http/Controllers/Customer/BusinessOnboardingController.php`

A deliberately thin wizard controller (its own docblock states this) —
all state-machine/invariant logic lives in `OnboardingManager`/
`OnboardingActionExecutor`/`BusinessManager`. `show()` resolves/starts
onboarding, parses the requested step, clamps forward-jump attempts via
`OnboardingManager::resolveStep()`, and redirects rather than renders
when clamped. The 5 step-store actions each validate via a FormRequest
then delegate to the matching `OnboardingManager::save*Step()` method
inside a shared `saveStep(Closure)` helper that redirects to the
resolved step with `withInput()` + a generic `'onboarding'` error key on
`InvalidArgumentException` — `AuthorizationException` is explicitly
**not** caught here; it propagates as a framework 403. `currentOnboarding()`
always resolves the onboarding row via `Auth::user()->customer` — never a
route parameter — which is the structural reason cross-tenant access is
impossible at the controller layer (§16 item 2).

### 4.3 FormRequests (`app/Http/Requests/Business/*.php`)

`UpdateOnboardingGoalsRequest`, `UpsertBusinessIdentityRequest`,
`UpsertBusinessLocationRequest`, `SyncBusinessServicesRequest`,
`UpdateBusinessAssetsRequest`, `CompleteOnboardingActionRequest` — all
six share `authorize(): return $this->user() !== null;` (any
authenticated user passes; real tenant ownership is enforced downstream
by the Managers, not here). Full validation-rule detail is in §7 per
view. `CompleteOnboardingActionRequest`'s `action_key` is validated
against `Rule::in(ALLOWED_ACTIONS)`, an explicit closed list intentionally
duplicated from `OnboardingActionExecutor::ACTION_STEPS` "so this request
never silently drifts" (its own comment).

### 4.4 `CustomerOnboarding` model / repository

Table `customer_onboardings`: `customer_id` (FK→users, **unique** — one
onboarding row per user), `business_id` (FK→businesses, nullable,
`nullOnDelete()`), `status` (`OnboardingStatus`), `current_step`
(`OnboardingStep` — the progress bookmark), `primary_goals`/
`completed_steps`/`metadata` (json), `analysis_version` (monotonic
counter guarding stale async writes), `analysis_payload`/`analysis_error`,
timestamps. Repository (`EloquentCustomerOnboardingRepository`):
`startForCustomer()` is idempotent (returns the existing row if present);
`completeAnalysis()`/`failAnalysis()` run inside `lockForUpdate()` and
re-check `analysis_version` — a stale/superseded write silently no-ops.

### 4.5 `app/Library/Business/OnboardingManager.php` (confirmed exact path)

`STEP_ORDER`: `Goals → Business → Location → Services → Assets → Analysis
→ Results → Complete` (§6). `SKIPPABLE_STEPS`: only `Assets`.
`resolveStep()` is the entire skip-prevention mechanism (a requested step
ahead of `current_step` is clamped back). `completeStep()` is idempotent
and validates via `assertValidStepCompletion()`. Every mutating public
method (`save*Step`, `requestAnalysis`, `complete`) wraps its work in
`DB::transaction()` and calls `assertOwnership()` first. No direct
`EntitlementManager` call here — that happens one layer deeper, inside
`BusinessManager::applyIdentity()` (§4.7).

### 4.6 `app/Library/Business/OnboardingActionExecutor.php` (confirmed exact path)

Backs the Results step's per-finding actions (`onboarding/action` route).
`ACTION_STEPS` is an **11-key closed allowlist**: 4 keys
(`add_location`, `complete_location`, `add_service`,
`confirm_primary_service`) render as a plain "Go fix this" redirect
button in `results.blade.php`; the other 7 keys are in `INLINE_FIELDS`
and render as a text-input + "Save" button, writing exactly one
allowlisted `Business` column (never a request-controlled field name —
this is the anti-mass-assignment guard). `execute()` resolves the
`fingerprint` **only** inside the onboarding's own stored
`analysis_payload['findings']` (never a fresh rebuild) — a fingerprint
from a superseded analysis run is rejected. After an inline edit, it
rebuilds a fresh snapshot and only records completion if the same
fingerprint is genuinely gone — a click alone, or an edit that didn't
actually resolve the finding, never counts as complete.

### 4.7 `BusinessManager` / RFC-004 entitlement integration

`createOrUpdateOnboardingBusiness()` / `updateBusiness()` /
`upsertPrimaryLocation()` / `syncServices()` are the four methods the
onboarding flow calls. The capacity-critical path is `applyIdentity()`'s
**create** branch (`app/Library/Business/BusinessManager.php:123-175`):
inside `DB::transaction` (retried up to 3 attempts on create, to absorb a
documented, intentional lock-order conflict against
`WorkspaceManager::transferOwnership()`), it resolves/locks the target
Workspace (`resolveLegacyOnboardingWorkspace()` →
`lockForLegacyOnboardingBusinessCreation()`, a `SELECT ... FOR UPDATE` on
the Workspace row), then calls **`EntitlementManager::assertCanCreateAnotherBusiness($lockedWorkspace)`
before** `BusinessRepository::createForCustomerInWorkspace()` — the exact
RFC-004 capacity gate, inside the same lock, before the count-increasing
insert. A Business created here is flagged `is_primary = true` only if
it's the customer's first Business, and always created with `status =
Draft`. Full preservation detail in §15.

### 4.8 Analysis — deterministic, not an AI job

`InitialBusinessSnapshotBuilder` is explicitly documented as
"deterministic, local-data-only... no remote requests, no AI." Triggered
by `OnboardingManager::requestAnalysis()` dispatching the queued job
`App\Jobs\Business\BuildInitialBusinessSnapshot` (implements `ShouldQueue`
+ `ShouldQueueAfterCommit`, `tries=3`, `backoff=[10,60,300]`, queue name
from `config('business.onboarding.analysis_queue', 'default')`). Full
async/polling detail in §14.

### 4.9 Middleware — `app/Http/Middleware/EnsureRequiredBusinessOnboardingIsComplete.php`

Registered as route-middleware alias `business.onboarding`, applied only
to `user.home` (the dashboard route), not to any onboarding route itself.
Full behavior in §5.

### 4.10 Existing tests (found, not modified)

`tests/Feature/Business/{BusinessOnboardingHttpTest,OnboardingManagerTest,
CustomerOnboardingRepositoryTest,OnboardingActionExecutorTest,
BuildInitialBusinessSnapshotJobTest}.php`,
`tests/Feature/Entitlement/EntitlementManagerLegacyCompatibilityAssignmentTest.php`,
`tests/Feature/Workspace/{WorkspaceManagerPreEnforcementTest,WorkspaceM1BBoundaryTest}.php`,
`tests/Feature/Usage/{NewBusinessWalletInitializationTest,NewBusinessPayerAssignmentInitializationTest}.php`,
`tests/Feature/Entitlement/EntitlementManagerConcurrencyTest.php`,
`tests/Unit/Business/BusinessConfigTest.php`. Full coverage summary in
§20 (existing regression test plan).

### 4.11 RFC excerpts

RFC-001 §22 "Onboarding state machine" documents exactly the
status/step transitions this implementation matches
(`not_started → started → analysis_pending → {results_ready|failed} →
completed`, step order `goals → business → location → services → assets
→ analysis → results → complete`). RFC-001 AD-007: mandatory onboarding
applies only when `is_required = true`, set only for new customers when
the release flag is enabled. RFC-004 §17/§17.3/§17.4 document the
"legacy onboarding compatibility path" precisely: `BusinessManager::
applyIdentity()` has never been routed through `createBusinessInWorkspace()`
and never carried RFC-003's owner-or-active-Admin authority requirement —
RFC-004's own bounded integration adds **only** the capacity-enforcement
call, deliberately not touching authority/ownership semantics, plus a
narrow complimentary-Core auto-assignment for a brand-new
auto-provisioned Workspace (continuation of a pre-RFC-004 posture, "not a
general customer self-grant path").

---

## 5. Feature-flag / routing preservation

`config/business.php`:
```php
'onboarding' => [
    'enabled' => env('BUSINESS_ONBOARDING_ENABLED', false),
    'require_for_new_customers' => env('BUSINESS_ONBOARDING_REQUIRE_NEW_CUSTOMERS', false),
    'analysis_queue' => env('BUSINESS_ONBOARDING_ANALYSIS_QUEUE', 'default'),
],
```
Both named flags exist exactly as specified, plus a third
(`analysis_queue`, §4.8/§14).

**`BUSINESS_ONBOARDING_ENABLED`** — **CORRECTION ROUND 1: read at
exactly TWO runtime sites, not one, as originally (incorrectly) stated.**

1. `EnsureRequiredBusinessOnboardingIsComplete::handle()` — when `false`,
   this middleware is a pure passthrough; it never redirects anyone.
2. `EloquentAccountRepository::register()` — as part of the compound
   condition `if (config('business.onboarding.enabled') &&
   config('business.onboarding.require_for_new_customers')) {
   OnboardingManager::start($customer, required: true); }` — this is the
   flag's *second* read site, originally omitted from this contract.

**BLOCKING NONVISUAL PREREQUISITE — documented-intent vs. actual-behavior
drift.** `docs/rfcs/RFC-001-BUSINESS-CORE-DEPLOYMENT.md` §1 (the merged
companion deployment doc for RFC-001, not found by this contract's
original research pass) states, verbatim, in its environment-variable
table:

> `BUSINESS_ONBOARDING_ENABLED` ... **"Master switch. When `false`, the
> entire onboarding wizard, analysis job, and dashboard redirect
> middleware behave as if the feature does not exist. Existing customer
> routes and the registration flow are unaffected."**

Its §8 "Rollback considerations" reinforces this: "setting
`BUSINESS_ONBOARDING_ENABLED=false` ... immediately stops new onboarding
rows, dashboard redirects, **and analysis dispatches**." Its §3 rollout
procedure only tests "voluntary onboarding" at step 6, *after* first
setting `BUSINESS_ONBOARDING_ENABLED=true` — i.e., the documented
rollout never exercises onboarding while the flag is `false`.

**The actual current implementation does not match this documented
intent.** As originally found (§4.9 above, and confirmed by the existing
test `test_direct_onboarding_routes_remain_reachable_when_config_is_disabled`):
the flag gates *only* the `EnsureRequiredBusinessOnboardingIsComplete`
dashboard-redirect middleware. Direct navigation to any `onboarding/*`
route, and a direct `POST onboarding/analysis` request, continue to work
identically whether the flag is `true` or `false` — there is no
route-level, controller-level, or job-dispatch-level gate anywhere tied
to this flag; only the redirect is gated. **This is a genuine,
mechanically-confirmed drift between the merged, documented "master
switch" contract and the shipped behavior — not a visual/presentation
matter, and not something this Blade-only contract can resolve.** It is
recorded here as **BLOCKING NONVISUAL PREREQUISITE #1**: A1 visual
implementation must not proceed until this drift is either (a) fixed in
the application (routes/analysis dispatch/job start out gated behind the
flag, matching the documented master-switch behavior) or (b) the
documentation is deliberately revised to match the shipped behavior —
either way, via a separate, explicitly-authorized, non-visual contract,
human-merged before A1 implementation begins.

**`BUSINESS_ONBOARDING_REQUIRE_NEW_CUSTOMERS`** — read in exactly one
place, `EloquentAccountRepository::register()` (the same compound
condition quoted above). Gates only whether a **required**
(`is_required=true`) onboarding row is auto-created at registration. If
either flag is off, no row is auto-created; a row is lazily created
(`is_required=false`, voluntary) the first time the customer visits
`customer.onboarding.show`.

**Middleware behavior on failure** (`business.onboarding`, applied only
to `user.home`): passthrough if the feature flag is off, the user isn't
authenticated, the current route matches an exempt-prefix list
(`customer.onboarding.`, `logout`, `verification.`, `customer.subscriptions.`,
`support.`), the user has no `customer` record, no onboarding row exists,
the row isn't `is_required`, or `status` is `Completed`/`Dismissed`.
**Otherwise: redirect to `customer.onboarding.show` at the resolved
(bookmarked) step — never a 403/404.**

**Resume semantics**: `current_step` on `CustomerOnboarding` is the
persistent bookmark; `resolveStep()` allows revisiting any step at or
behind it and clamps any attempt to jump ahead back to it, on both the
GET (view) and POST (submit) sides (§16 items 17-18).

**Optional vs. required**: `is_required` distinguishes a mandatory
(post-registration, redirect-enforced) onboarding from a voluntary one
(reachable only by the customer choosing to visit it) — both flow through
the exact same 9 views and the exact same state machine; the flag changes
only whether `user.home` redirects into it.

**Completion behavior**: `complete()` is idempotent (no-ops if already
`Completed`); its prerequisite check (§4.5, `assertCompletionPrerequisites()`)
requires exactly one primary location, ≥1 active service with exactly one
active primary, a non-null `analysis_payload`, and — only if that
payload's `findings` is non-empty — a recorded `first_value_action_key`.
On success, redirects to `user.home`.

**None of this is redesigned by A1.** A future visual implementation must
preserve every flag read-site, every redirect target, every exempt-route
prefix, and every resume/skip/completion condition exactly as documented
here.

---

## 6. Exact step-state-machine map

| Step | Route (store) | Controller action | Validated by | Persists via | Skippable? | Next | Back/revisit | Resume | Completion note |
|---|---|---|---|---|---|---|---|---|---|
| Goals | POST `onboarding/goals` | `storeGoals` | `UpdateOnboardingGoalsRequest` | `OnboardingManager::saveGoalsStep` | No | Business | Via GET to `show/{step}` at/behind `current_step` only | `current_step` bookmark | — |
| Business | POST `onboarding/business` | `storeBusiness` | `UpsertBusinessIdentityRequest` | `OnboardingManager::saveBusinessStep` → `BusinessManager::createOrUpdateOnboardingBusiness` | No | Location | same | same | Creates/updates the Business; RFC-004 capacity+Workspace-lock gate on **create** only (§4.7/§15) |
| Location | POST `onboarding/location` | `storeLocation` | `UpsertBusinessLocationRequest` | `OnboardingManager::saveLocationStep` → `BusinessManager::upsertPrimaryLocation` | No | Services | same | same | One-primary-location invariant enforced in the repository |
| Services | POST `onboarding/services` | `storeServices` | `SyncBusinessServicesRequest` | `OnboardingManager::saveServicesStep` → `BusinessManager::syncServices` | No | Assets | same | same | — |
| Assets | POST `onboarding/assets` (store) / POST `onboarding/assets/skip` (skip) | `storeAssets` / `skipAssets` | `UpdateBusinessAssetsRequest` (store only) | `OnboardingManager::saveAssetsStep` → `BusinessManager::updateBusiness` | **Yes — the only skippable step** | Analysis | same | same | Skip goes through `OnboardingManager::skipStep()`, which rejects any step not in `SKIPPABLE_STEPS` |
| Analysis | POST `onboarding/analysis` (`throttle:5,60`) | `requestAnalysis` | — (no FormRequest) | `OnboardingManager::requestAnalysis` → dispatches queued `BuildInitialBusinessSnapshot` | No (but idempotent re-request bumps `analysis_version`) | Results (once ready) | same | same | Rejected with a generic `'onboarding'` error if earlier steps aren't complete |
| Results | GET `onboarding/analysis/status` (`throttle:60,1`, polling) / POST `onboarding/action` (per-finding) | `analysisStatus` / `completeAction` | `CompleteOnboardingActionRequest` (action only) | `OnboardingActionExecutor::execute` → `BusinessManager::updateBusiness` (inline fields only) + `OnboardingManager::recordFirstValueAction` | No | Complete | same | same | §4.6/§14 |
| Complete | POST `onboarding/complete` | `complete` | — (no FormRequest) | `OnboardingManager::complete` | No | `user.home` (dashboard) | N/A — terminal | Idempotent no-op if already `Completed` | Full prerequisite check (§5) |

**Do not change step order or skip semantics for design convenience.**
The nav-pills label list in `show.blade.php` (`goals, business, location,
services, assets, analysis, results, complete`) matches `STEP_ORDER`
exactly — mechanically cross-checked, not assumed.

---

## 7. Per-view mechanical inventory

Exact counts, not estimates. "Fixed" = independent of dynamic collection
size; "dynamic" = scales with a collection (services, findings, goals).

### 7.1 `show.blade.php` (39 lines)
data-feather: 0. Hardcoded color/rgb/rgba/hsl: 0. Font-family: 0. Cards:
1 (wraps the entire wizard: `.card > .card-header > .card-title` +
`.card-body`). Real `<button>`: 0. Button-styled links: 0. Alerts: 1
(`alert alert-danger`, shared `$errors->any()` loop — rendered above
every step's own content). Badges: 0. Progress/stepper: 1 (`ul.nav.nav-pills`,
8 **non-interactive `<span>`** step labels — not links; see §13). Inputs/
selects/textareas/checkboxes/file inputs: 0 (this is the shell, not a
form). Inline style: 0. Inline JS: 0. IDs/classes: `#business-onboarding`
section id. Form actions: none (shell only). Validation/error rendering:
yes, `$errors->any()`. Accessibility attrs: none beyond native
`<h4>`/`<ul>` semantics. Localization: none (hardcoded English).

### 7.2 `steps/analysis.blade.php` (61 lines)
data-feather: 0. Colors/fonts: 0. Cards: 0. Real `<button>`: 2 ("Retry
analysis", "Run analysis", both `btn btn-primary`). Button-styled links:
1 ("View results", `btn btn-primary`). Alerts: 0. Badges: 0. Inputs/
selects/textareas/checkboxes/file: 0. Inline style: 0. **Inline JS: 1
script block (lines 25-59)** — the only inline `<script>` across all 9
views: exponential-backoff `fetch()` polling (`2000ms * 2^attempt`, capped
15000ms; retries on rejection after the cap). IDs/classes/hooks:
`#analysis-status`, `role="status" aria-live="polite"` (the only explicit
ARIA-live region in the feature). Form actions: 2, both
`route('customer.onboarding.analysis.request')`, POST, `@csrf`.
Validation/error rendering: displays `$onboarding->analysis_error` (the
safe fixed string). Disabled/loading button behavior: none (no
client-side disable-on-submit). Back/next/skip: N/A. Polling/AJAX: yes
(described above), targets `route('customer.onboarding.analysis.status')`,
handles `{completed, redirect_url}` → navigate, `{status:'failed'}` →
reload, else re-poll. **Note: the fetch call never checks `response.ok` —
it calls `.json()` unconditionally on every response**, an existing
behavior detail to preserve exactly, not fix, in A1. Accessibility: `role=
status aria-live=polite` present. Localization: none.

### 7.3 `steps/assets.blade.php` (30 lines)
data-feather: 0. Colors/fonts: 0. Cards: 0. Real `<button>`: 2
("Continue" `btn btn-primary`, "Skip for now" `btn btn-outline-secondary`).
Alerts/badges: 0. **Inputs: 3, all `type="text"`** (`google_business_profile_url`,
`facebook_url`, `instagram_url`) — **despite the module's "Assets" name,
there is no file input anywhere in this step; these are plain URL text
fields**, confirmed also by the security agent (item 12/11). Selects/
textareas/checkboxes/file: 0. Inline style/JS: 0. Form actions: 2 —
`route('customer.onboarding.assets.store')` and `.assets.skip`, both
POST, both `@csrf`. old()/value binding: yes, all 3 fields. Skippable:
yes (dedicated skip route/button). Validation/error rendering: relies on
the shared top-of-page block (§7.1) — no per-field inline error here.

### 7.4 `steps/business.blade.php` (58 lines)
data-feather: 0. Colors/fonts: 0. Cards: 0. Real `<button>`: 1
("Continue"). **Inputs: 7** (`name` required, `email` type=email, `phone`,
`website_url`, `country_code` required maxlength=2, `timezone` required,
`currency_code` required maxlength=3). **Selects: 1** (`industry`, options
generated from `BusinessIndustry::cases()`, required). **Textareas: 1**
(`description`, maxlength=5000). Checkboxes/file: 0. Inline style/JS: 0.
Form action: `route('customer.onboarding.business.store')`, POST, `@csrf`.
old()/value binding: yes, all fields. Required fields (5): `name`,
`industry`, `country_code`, `timezone`, `currency_code`.

### 7.5 `steps/complete.blade.php` (4 lines)
data-feather: 0. Colors/fonts: 0. Cards/alerts/badges: 0. Real
`<button>`: 0. **Button-styled links: 1** ("Go to dashboard", `btn
btn-primary`, → `route('user.home')`). No form, no inputs of any kind.
The simplest of the 9 views, but still contains one `.btn` element that
would change under a visual redesign — **not** a no-op view (§18).

### 7.6 `steps/goals.blade.php` (26 lines)
data-feather: 0. Colors/fonts: 0. Cards/alerts/badges: 0. Real
`<button>`: 1 ("Continue"). **Checkboxes: dynamic, one per
`BusinessGoal::cases()`**, `name="primary_goals[]"`, each with a proper
`<label for=>`. Selects/textareas/file: 0. Inline style/JS: 0. Form
action: `route('customer.onboarding.goals.store')`, POST, `@csrf`.
old()/value binding: yes, via `old('primary_goals', $onboarding->primary_goals ?? [])`.

### 7.7 `steps/location.blade.php` (49 lines)
data-feather: 0. Colors/fonts: 0. Cards/alerts/badges: 0. Real
`<button>`: 1 ("Continue"). **Inputs: 5** (`address_line_1`, `city`,
`region`, `postal_code`, `country_code` required maxlength=2). **Selects:
1** (`service_mode`, from `BusinessServiceMode::cases()`, required).
**Checkboxes: 1 fixed** (`public_address`). Textareas/file: 0. Inline
style/JS: 0. Form action: `route('customer.onboarding.location.store')`,
POST, `@csrf`. old()/value binding: yes, all fields.

### 7.8 `steps/results.blade.php` (36 lines)
data-feather: 0. Colors/fonts: 0. Cards/badges: 0. **Real `<button>`: 1
fixed** ("Finish setup", `btn btn-success` — note the variant, §12) **+
up to 5 dynamic per-finding buttons** (bounded by `MAX_FINDINGS=5` in
`InitialBusinessSnapshotBuilder`), each either "Go fix this"
(`btn btn-sm btn-outline-primary`, for the 4 step-redirect action keys)
or "Save" (`btn btn-sm btn-primary`, for the 7 inline-editable action
keys — 4+7=11, matching `ACTION_STEPS`'s full closed list exactly, a
mechanical cross-check). **Inputs: up to 5 dynamic** (`type="text"`,
`name="value"`, maxlength=2048, one per non-redirect finding — **no
old() binding on this field**, an existing minor inconsistency versus
every other step's forms, preserved as-is per §7 note below). Hidden
inputs: 2 per finding (`fingerprint`, `action_key`). Selects/textareas/
checkboxes/file: 0. Inline style/JS: 0. Form actions: `route('customer.onboarding.action.complete')`
(dynamic, per finding) + `route('customer.onboarding.complete')` (fixed,
final), all POST, all `@csrf`. **Empty state**: "No outstanding items
found in your stored profile data." when `findings` is empty (§11 —
`x-empty-state` adoption candidate).

### 7.9 `steps/services.blade.php` (52 lines)
data-feather: 0. Colors/fonts: 0. Cards: 0 (uses `.border.rounded.p-1.mb-1`
bordered `<div>`s, not `.card` — see §11 adoption note). Real `<button>`:
1 ("Continue"). **Inputs: dynamic, 2 per row** (`name` — required only on
existing rows, not the new blank row; `starting_price`, `type="number"
step="0.01"`) **× (existing service count + 1 blank row)**. **Checkboxes:
dynamic, 1 per row** (`is_primary`). Hidden input: 1 per existing row
(`services[i][id]`). Selects/textareas/file: 0. Inline style/JS: 0. Form
action: `route('customer.onboarding.services.store')`, POST, `@csrf`.
old()/value binding: yes, all fields, both existing and new rows.

### 7.10 Totals across all 9 views
- **data-feather icons: 0** (0 files, 0 occurrences — the standing M2
  icon-migration mandate is trivially satisfied for A1; §8).
- **Hardcoded hex/rgb/rgba/hsl colors: 0.** **Font-family declarations:
  0.** Every existing class is a Bootstrap semantic/utility class
  (`btn-primary`, `alert-danger`, `text-muted`, `border`, `rounded`, ...)
  already resolved through the M2 Slice-1 runtime-bindings retrofit layer
  — **zero color/font cleanup debt exists in these 9 files** (§9).
- Inline `<style>` blocks: 0. Inline `<script>` blocks: 1 (analysis
  only).
- Real `<button>`: 9 fixed + up to 5 dynamic (results). Button-styled
  links: 2 (analysis "View results", complete "Go to dashboard").
- Alerts: 1 (shared, show.blade.php). Cards: 1 (shared wrapper). Badges:
  0. Tooltips: 0. Progress/stepper: 1 (non-interactive nav-pills).
- Inputs: 15 fixed (assets 3 + business 7 + location 5) + dynamic
  (services 2/row, results ≤5). Selects: 2 (business, location).
  Textareas: 1 (business). Checkboxes: 1 fixed (location) + dynamic
  (goals, services). File inputs: **0 anywhere** (confirmed independently
  by both the architecture trace and the security audit).
- Forms: 12 static form elements across the 9 files (some conditionally
  rendered, e.g. analysis's 2 mutually-exclusive forms) plus results'
  dynamic per-finding forms. **Every single form carries `@csrf` — zero
  exceptions.** HTTP method: 100% POST; zero GET forms; zero PUT/PATCH/
  DELETE anywhere.
- Accessibility: only `analysis.blade.php` has explicit ARIA
  (`role="status" aria-live="polite"`); every labelled field elsewhere
  uses a correct `<label for=id>`/`id` pair; the nav-pills stepper has no
  `aria-current` or other state indication beyond the CSS `.active`
  class.
- Localization: **0** — no `__()`/`@lang()` anywhere in any of the 9
  views; all strings are hardcoded English literals. Not a redesign
  obligation to introduce i18n; nothing existing to preserve either.
- Disabled/loading button behavior: **none anywhere** — no view
  conditionally disables a submit button or shows a spinner; double-submit
  protection is entirely server-side (idempotency, §4.5/§4.6). A future
  A1 implementation MAY add client-side disable-on-submit / use
  `x-button`'s `disabled` prop as a pure presentation enhancement (no
  server-side change) — optional, not required, not locked.
- Back/next/skip UI: **the nav-pills are non-interactive `<span>`
  elements** — there is currently no clickable in-UI way to revisit an
  earlier completed step, even though `resolveStep()` already supports it
  server-side. **Per Correction Round 1 (§13), this stays non-interactive
  in A1 — no clickable step navigation is authorized**; only visual
  completed/current/upcoming state distinction is in-scope.
- `results.blade.php`'s dynamic "value" input lacks `old()` binding
  (every other field in every other step has it). This is an existing,
  minor, disclosed inconsistency — **not fixed by A1**, since restoring a
  post-validation-failure value is a behavior change, not a pure
  restyle, and A1 is presentation-only.

---

## 8. Icon matrix (static icon migration)

**Zero data-feather (or any other static icon) occurrences exist across
all 9 views.** The standing M2 "eliminate remaining static icons, migrate
to `x-ds-icon`" mandate (`DESIGN-SYSTEM-M2-CONTRACT.md` §8's "standing
mandate," carried forward to Category A items in §8.4) is **trivially
satisfied for A1 — there is nothing to migrate.** The one icon usage this
contract does lock (§11) — `x-empty-state`'s `icon="inbox"` on the
Results empty state — uses that component's own documented default
(§10), so it requires no separate Lucide-name verification. Any other
icon a future redesign chooses to *add* beyond that (e.g., via
`x-button`'s `icon` prop) is a new, optional addition, not a migration
obligation, and must still be verified against the installed Lucide set
before use — the mechanical verification
path this repo's own prior contracts use is
`vendor/technikermathe/blade-lucide-icons/resources/svg/{name}.svg`
(package `technikermathe/blade-lucide-icons` v3.166.0, on
`blade-ui-kit/blade-icons` v1.10.1, confirmed in `composer.json`/
`composer.lock`). **Caveat**: this worktree has no `vendor/` directory
installed (no `composer install` run here) — a future implementation
environment must run `composer install` (or check upstream Lucide's own
v3.166.0 icon list) before mechanically confirming any specific icon
name; this contract does not lock any specific icon name since none is
required.

---

## 9. Color / font matrix

**Zero hardcoded hex/rgb/rgba/hsl color literals and zero explicit
font-family declarations exist across all 9 views** (§7.10). Every
existing color-bearing class (`btn-primary`, `btn-outline-secondary`,
`btn-outline-primary`, `btn-danger`... wait, none used; `alert-danger`,
`text-muted`) is a Bootstrap-native semantic class already routed through
M2 Slice 1's runtime-bindings retrofit layer
(`resources/scss/base/tokens/_runtime-bindings.scss`) to `var(--color-*)`
— **no additional token mapping is required for A1.** This is a
materially smaller color/font cleanup scope than most other legacy
surfaces in this codebase (contrast with, e.g., the Sending Server
mega-forms' extensive per-file hardcoded literals, per the Product
Surface Retention Audit §6.7). No blocker, no undecided token mapping —
this matrix is empty by direct mechanical evidence, not by assumption.

---

## 10. Current component inventory

`resources/views/components/` (19 files, flat, no subdirectories):
`alert`, `badge`, `branding-favicon`, `branding-footer`,
`branding-illustration`, `branding-logo`, `button`, `card`, `dialog`,
`ds-icon`, `empty-state`, `input`, `menu`, `pagination`, `select`,
`switch-toggle`, `table`, `tabs`, `tooltip`. All 11 requested components
exist. **No stepper/progress/wizard component exists** (confirmed by a
case-insensitive filename grep for `step|progress|wizard` — zero matches;
§13).

| Component | `@props` | Slots | Notes relevant to A1 |
|---|---|---|---|
| `x-card` | `title=null, padded=true` | default, `actions`, `footer` | Base classes `card ds-card shadow-none transition-base` — visually a flat bordered box, not a heavy shadow card. |
| `x-button` | `variant=primary\|secondary\|outline\|ghost\|danger, size=sm\|md\|lg, type=button, href=null, icon=null, disabled=false` | default | Renders `<a>` when `href` passed, else `<button type>`. Variant map: `primary→btn-primary`, `secondary→btn-outline-secondary`, `outline→btn-outline-primary`, `ghost→btn-flat-secondary`, `danger→btn-danger`. **No `success` variant.** `disabled` renders the bare HTML attribute only — **no loading/spinner state at all.** |
| `x-alert` | `variant=neutral\|accent\|success\|warning\|danger, icon=null, dismissible=false` | default only | `danger→alert-danger` matches the current shared error block exactly. Flat icon+slot layout, no heading/body split. |
| `x-badge` | `variant=neutral\|accent\|success\|warning\|danger` | default only | No size prop. |
| `x-tooltip` | `text, placement=top` | default | Bootstrap tooltip JS, no new runtime dep. |
| `x-input` | `label=null, name, type=text, help=null, error=null` | — | `required`/`disabled`/`value` all pass through via forwarded attributes, not typed props. `help` is suppressed when `error` is set. No icon slot. No built-in `old()` binding — must still be passed explicitly. |
| `x-select` | `label=null, name, options=[], selected=null, help=null` | — | **No `error` prop at all** — no invalid-state styling exists on this component (§12). `options` is a value=>text array. |
| `x-empty-state` | `icon=inbox, title, description=null` | default (unused directly), `action` | Centered layout, `py-5`. |
| `x-dialog` | `id, title=null, size=sm\|md\|lg` | default, `footer` | Not used by A1 — no dialogs exist in onboarding. |
| `x-menu` | `label=null, icon=null, align=start\|end` | default | Not used by A1 — no dropdown menus exist in onboarding. |
| `x-ds-icon` | `name, size=18, strokeWidth=1.75` | — | The single centralized icon-rendering seam; renders via blade-icons' `svg('lucide-'.$name, ...)`. |

RFC excerpts confirming Business-creation/Workspace/capacity behavior
(§4.7/§15) are drawn from RFC-001 §22/AD-007 and RFC-004 §17/§17.3/§17.4,
already quoted in §4.11.

---

## 11. Exact component-adoption matrix

| Source file | Existing element | Target component | Props / variant / size | Classes/IDs/hooks that survive | Constraints | Why compatibility is proven |
|---|---|---|---|---|---|---|
| `show.blade.php` | `.card > .card-header/.card-title > .card-body` wrapper | `x-card` | `title="Business Setup"`, default `padded=true` | `#business-onboarding` section id stays on the outer `<section>`, not the card | Nav-pills, error block, and `@include`d step content all move inside the card's default slot | `x-card` already renders an identical `card`/header/body structure via its `title` prop and default slot — no missing feature |
| `show.blade.php` | `.alert.alert-danger` + `<ul>` error loop | `x-alert` | `variant="danger"`, default slot holds the existing `$errors->all()` loop unchanged | none | Must render before the step include, exactly as today | `danger→alert-danger` is an exact class match; only a flat icon+slot layout is needed, matching current markup (no heading/body split used today) |
| `analysis.blade.php` | "Retry analysis" `<button class="btn btn-primary">` | `x-button` | `type="submit"`, `variant="primary"` | none | Inside the existing `@if($status==='failed')` form | Direct 1:1 variant match |
| `analysis.blade.php` | "Run analysis" `<button class="btn btn-primary">` | `x-button` | `type="submit"`, `variant="primary"` | none | — | Direct 1:1 variant match |
| `analysis.blade.php` | "View results" `<a class="btn btn-primary">` | `x-button` | `href="{{ route(...) }}"`, `variant="primary"` | none | Preserve exact `route()` call | `x-button` renders `<a>` when `href` passed — proven compatible |
| `assets.blade.php` | "Continue" `<button class="btn btn-primary">` | `x-button` | `type="submit"`, `variant="primary"` | none | — | Direct match |
| `assets.blade.php` | "Skip for now" `<button class="btn btn-outline-secondary">` | `x-button` | `type="submit"`, `variant="secondary"` | none | — | `secondary→btn-outline-secondary` is an exact class match |
| `assets.blade.php` | 3× `<input type="text">` (URL fields) | `x-input` | `name`, `label`, `type="text"`, `value="{{ old(...) }}"` forwarded | `id="google_business_profile_url"` etc. | `old()`/value binding stays manual (component has none built in) — no behavior loss | `x-input` forwards `required`/`value`/`id` via attribute merge; nothing here needs `required` |
| `business.blade.php` | 7× text/email `<input>` | `x-input` | `name`, `label`, `type`, `required` (5 of 7, forwarded), `value="{{ old(...) }}"` forwarded | field `id`s stay identical (route names/JS don't reference them, so no external breakage even if changed, but kept identical for minimal diff) | — | Same as above; `type="email"` passes through `type` prop |
| `business.blade.php` | `industry` `<select>` | `x-select` | `name="industry"`, `label`, `options` built from `BusinessIndustry::cases()`, `selected="{{ old(...) }}"` | `required` forwarded | **No error-prop support** — falls back to the shared top-of-page alert for this field's validation feedback (§12) | `x-select`'s `options`/`selected` API is a direct fit for an enum-driven dropdown |
| `business.blade.php` | `description` `<textarea>` | *(non-adoption — see §12)* | — | — | — | No `x-textarea` component exists in the library |
| `goals.blade.php` | dynamic `BusinessGoal` checkboxes | *(non-adoption — see §12)* | — | — | — | No checkbox/radio component exists in the library |
| `goals.blade.php` | "Continue" `<button class="btn btn-primary">` | `x-button` | `type="submit"`, `variant="primary"` | none | — | Direct match |
| `location.blade.php` | `service_mode` `<select>` | `x-select` | same pattern as `industry` | `required` forwarded | Same no-error-prop caveat | Same enum-driven fit |
| `location.blade.php` | 5× text `<input>` | `x-input` | same pattern as business step | — | — | Same as business step |
| `location.blade.php` | `public_address` checkbox | *(non-adoption — see §12)* | — | — | — | Same no-checkbox-component reason |
| `location.blade.php` | "Continue" button | `x-button` | `type="submit"`, `variant="primary"` | — | — | Direct match |
| `services.blade.php` | per-service `.border.rounded.p-1.mb-1` row | `x-card` | `padded=true`, no `title` | hidden `services[i][id]` input stays inside the slot | Repeats once per existing service + 1 new blank row — a loop around the component, not a new component | `x-card`'s `shadow-none` base class already renders a flat bordered box visually equivalent to the current `.border.rounded` div |
| `services.blade.php` | `name`/`starting_price` inputs per row | `x-input` | `name="services[{{i}}][name]"` etc., `type="text"`/`type="number" step="0.01"` | — | Dynamic `name` attribute per row index, unchanged | Same forwarding pattern as other steps |
| `services.blade.php` | `is_primary` checkbox per row | *(non-adoption — see §12)* | — | — | — | Same no-checkbox-component reason |
| `services.blade.php` | "Continue" button | `x-button` | `type="submit"`, `variant="primary"` | — | — | Direct match |
| `results.blade.php` | "No outstanding items found..." empty state | `x-empty-state` | `title`, **`icon="inbox"` (locked, Correction Round 1 — matches the component's own default, no invented icon name)** | — | Only when `findings` is empty | `x-empty-state` is purpose-built for exactly this "nothing to show" case; `inbox` is already its documented default (§10), requiring no separate Lucide-name verification step |
| `results.blade.php` | "Go fix this" `<button class="btn btn-sm btn-outline-primary">` | `x-button` | `type="submit"`, `variant="outline"`, `size="sm"` | — | — | `outline→btn-outline-primary` exact match |
| `results.blade.php` | "Save" `<button class="btn btn-sm btn-primary">` | `x-button` | `type="submit"`, `variant="primary"`, `size="sm"` | — | — | Direct match |
| `results.blade.php` | dynamic "value" `<input type="text">` | `x-input` | `name="value"`, `type="text"` | maxlength=2048 forwarded | **No `old()` binding today — not added by A1** (§7.9) | Forwarding-only pattern, no feature gap |
| `results.blade.php` | "Finish setup" `<button class="btn btn-success">` | *(non-adoption — see §12)* | — | — | — | `x-button` has no `success` variant |
| `complete.blade.php` | "Go to dashboard" `<a class="btn btn-primary">` | `x-button` | `href="{{ route('user.home') }}"`, `variant="primary"` | — | — | Direct match, same `href` pattern as analysis's "View results" |

---

## 12. Intentional non-adoption matrix

| Source element | Component considered | Exact reason non-adoption is locked |
|---|---|---|
| `business.blade.php` `description` `<textarea>` | (would-be `x-textarea`) | **Does not exist in the component library.** Adopting a nonexistent component isn't possible; native `<textarea class="form-control">` is retained, restyled only via existing token-driven Bootstrap classes (§9 — already clean, no hardcoded literals to fix). |
| `goals.blade.php` dynamic checkboxes; `location.blade.php` `public_address`; `services.blade.php` `is_primary` | (would-be `x-checkbox`) | **No checkbox/radio component exists.** Native `.form-check`/`.form-check-input`/`.form-check-label` markup is retained unchanged — already token-clean (§9), no functional or visual debt to justify inventing a new component for this contract. |
| `results.blade.php` "Finish setup" `<button class="btn btn-success">` | `x-button` | `x-button`'s variant enum (`primary\|secondary\|outline\|ghost\|danger`) **has no `success` option.** Locked default: leave this one button as a native `<button class="btn btn-success">` (unchanged, already token-clean). See §13 for the alternative optional path (adding a `success` variant to `x-button`) — that requires human authorization as a shared-component change and is not decided by this contract. |
| `business.blade.php`/`location.blade.php` `<select>` inline validation state | `x-select`'s (nonexistent) error prop | `x-select` has **no `error` prop at all**, unlike `x-input`. The dropdown markup itself is still adopted (§11) for its options-generation convenience; only its error-state styling is non-adopted — the shared top-of-page `$errors->any()` alert (already present today, §7.1) continues to carry validation feedback for these 2 fields, an existing, disclosed, non-regressive gap, not a new one introduced by A1. |
| `show.blade.php` nav-pills stepper | (would-be a new stepper/progress component) | **No stepper component exists.** See §13 — this is escalated as an explicit OPTIONAL SHARED-COMPONENT DECISION requiring separate human authorization, with a no-new-component fallback also documented. |
| `analysis.blade.php` `#analysis-status` wrapper (`role="status" aria-live="polite"`) | `x-card` / `x-alert` | Neither component has a live-region variant, and wrapping this bespoke ARIA-live status region in generic card/alert chrome would risk disturbing the exact `role`/`aria-live` semantics that must be preserved byte-for-byte (§17). The wrapper `<div>` stays plain; only the buttons/links inside it individually adopt `x-button` (§11). |

No shared component file is modified by this contract or by A1's future
implementation — every non-adoption above is resolved either by keeping
native markup or, for the stepper only, by an explicit optional
human-authorizable decision (§13).

---

## 13. Progress / stepper UX — decision point

The current stepper (`show.blade.php`'s `nav-pills`) is a **non-interactive**
list of 8 `<span>` labels with only a CSS `.active` class distinguishing
the current step — no completed/upcoming visual distinction, no click
navigation, no `aria-current`.

**CORRECTION ROUND 1 — narrowed scope.** The original draft of this
section authorized making completed-step pills clickable links, reasoning
that it only exposed already-safe backend capability. Per human direction
in Correction Round 1, **that authorization is withdrawn.** A1's visual
scope is restricted to **restyling the current non-interactive stepper
only — no new navigation behavior of any kind is authorized**, even
where the underlying route/capability is already safe. This removes any
ambiguity about whether A1 is "purely presentational": the stepper's
*interactivity* (none today) is preserved exactly, not just its markup.

**One thing remains in-scope, since it changes no interactivity, no URL,
and no route** — visually distinguishing completed vs. current vs.
upcoming steps using only existing primitives (e.g. an `x-ds-icon`
check-mark next to a completed step's label, driven by
`$onboarding->completed_steps` membership), with the pills remaining
non-interactive `<span>`s exactly as today. This is restyling, not new
navigation.

**Making the pills clickable (or any other new stepper interactivity) is
withdrawn from A1's scope entirely** — it is not authorized here, it is
not an "optional, in-scope" enhancement, and it is not delegated to a
future implementer's discretion. If a future human decision wants
step-pill navigation, that must be authorized explicitly, in its own
future scope, separate from a "pure restyle" contract like this one.

**OPTIONAL SHARED-COMPONENT DECISION REQUIRING HUMAN AUTHORIZATION**: a
purpose-built `x-stepper`/`x-progress-steps` component (showing
current/completed/upcoming state, step numbers, and optionally a
progress percentage) would likely present more cleanly than restyled
nav-pills, especially on narrow/mobile layouts. **This contract does not
create it.** If a future implementer or the human authorizing A1's
visual work wants one, that is a new addition to the shared component
library and requires its own separate authorization, exactly like any
other shared-component change — not something A1's own implementation
allowlist may add silently.

**No-new-component alternative** (the default, always available without
further authorization): keep the current `nav-pills` markup, restyled
only with already-token-clean Bootstrap classes, augmented with items 1
and 2 above.

---

## 14. Analysis / async behavior preservation

- **How it starts**: `OnboardingManager::requestAnalysis()` (only
  reachable once earlier steps are complete, §6), inside a
  `DB::transaction`, increments `analysis_version`, calls the repository's
  `startAnalysis()`, dispatches `InitialBusinessAnalysisRequested`, and
  dispatches the queued job `BuildInitialBusinessSnapshot::dispatch($id,
  $version)`.
- **Queued, not synchronous**: the job implements `ShouldQueue` +
  `ShouldQueueAfterCommit` (only enters the queue after the outer
  transaction actually commits), `tries=3`, `backoff=[10,60,300]`, queue
  name from `config('business.onboarding.analysis_queue', 'default')`.
- **Polling** (`analysis.blade.php`'s only inline script, §7.2): starts
  2000ms after page load, then `2000 * 2^attempt` ms, capped at 15000ms;
  targets `GET customer.onboarding.analysis.status` (`throttle:60,1`);
  does **not** check `response.ok` before parsing JSON (existing
  behavior, preserved as-is).
- **Status values** (`OnboardingStatus` enum): `not_started, started,
  analysis_pending, results_ready, completed, dismissed, failed`.
- **`analysis_payload` structure** (from `InitialBusinessSnapshotBuilder::build()`):
  `{version, generated_at, profile_completeness_percent (0-100, weighted
  over 11 boolean facts), facts: {...11 boolean keys}, findings: [...
  up to MAX_FINDINGS=5, each {fingerprint, title, reason, impact, effort,
  confidence, worker_key, can_ai_prepare, action_key, action_step}]}`.
- **Failure handling**: `handle()` no-ops on a stale `analysis_version` or
  a `Completed`/`Dismissed` onboarding; a missing/cross-tenant Business is
  treated as a **permanent** failure (`markFailed()`), not retried, "so
  it doesn't stay stuck in AnalysisPending forever" (the job's own
  comment). `markFailed()` **always** persists the fixed safe string
  `"We could not finish the analysis. Please retry."` — never a raw
  exception message. `failed()` (retries-exhausted hook) does the same.
  A failed analysis is retried by the customer re-submitting
  `analysis.request`, which bumps `analysis_version` again, superseding
  the failed run.
- **Timeout/refresh**: no server-side timeout beyond the job's 3-try
  bounded retry with fixed backoff; client-side polling has no give-up —
  it keeps polling indefinitely at the 15000ms-capped interval. This is
  an existing behavior, not something A1 changes.
- **Unavailable/disabled analysis**: there is no separate flag disabling
  analysis itself (distinct from `BUSINESS_ONBOARDING_ENABLED`, §5); if
  the queue worker never processes the job, the customer is left on the
  polling screen indefinitely (bounded client backoff, no client give-up)
  — an existing gap, not addressed by A1 (out of scope; A1 is Blade-only).

**A1 does not replace this asynchronous behavior with static mocked UI.**
The 4 status branches in `analysis.blade.php` (pending / failed /
results_ready / not-yet-requested) and the polling script's exact
behavior are locked, preservation-only.

---

## 15. Business-creation / RFC-004 entitlement preservation

- **Workspace association**: on create, `BusinessManager::applyIdentity()`
  calls `WorkspaceManager::resolveLegacyOnboardingWorkspace($customerUserId)`
  — resolves a single "preferred" candidate Workspace if exactly one
  exists, throws `WorkspaceContextRequiredException` if more than one
  (ambiguous), or falls back to other candidates, provisioning a **brand
  new Workspace** if none exist. RFC-004 §17.4: a newly auto-provisioned
  Workspace additionally gets an initial Core/`active`/complimentary
  `workspace_plan_assignments` row in the same transaction — "a narrow
  continuation of a pre-RFC-004 posture, not a general customer
  self-grant path."
- **Business-capacity enforcement**: `EntitlementManager::assertCanCreateAnotherBusiness($lockedWorkspace)`
  is called **after** the Workspace row is locked
  (`lockForLegacyOnboardingBusinessCreation()`, a `SELECT ... FOR UPDATE`)
  and **before** `BusinessRepository::createForCustomerInWorkspace()` —
  all inside one `DB::transaction`. A denial throws one of
  `WorkspacePlanUnassignedException` / `InactiveWorkspacePlanException` /
  `SuspendedWorkspacePlanException` / `BusinessSlotAllocationRequiredException`
  / `BusinessSlotLimitExceededException` (all `RuntimeException`
  subclasses) — the transaction rolls back; no Business row is ever
  persisted on denial.
- **Final-slot behavior**: RFC-004 §13's own text — "a 4th or 5th
  Business must never succeed merely because `business_slot_max = 5` —
  the allocation must actually have happened," and "denial, not
  deletion: ... no existing Business is ever removed, hidden, or
  deactivated as a consequence of a slot limit."
- **Transaction/locking behavior**: create-branch `applyIdentity()` runs
  inside up to 3 retry attempts (documented, intentional — absorbs a
  known lock-order conflict against `WorkspaceManager::transferOwnership()`'s
  reverse lock order); the update-existing-business branch is a single
  attempt, no retry.
- **Legacy onboarding compatibility path**: RFC-004 §17.3 — this path
  has *never* been routed through `createBusinessInWorkspace()` and never
  carried RFC-003's owner-or-active-Admin authority requirement; RFC-004's
  integration adds *only* the capacity gate, "without touching authority
  or ownership semantics." A1 must not change this.
- **Ownership/customer binding**: `Business.customer_id` is always
  server-set via `createForCustomerInWorkspace($customer, ...)`; no
  onboarding FormRequest or route ever accepts a `business_id`/`customer_id`
  field from the client.
- **Primary-business flag**: `is_primary = true` is set only if the
  customer has zero existing Businesses at creation time (a row-count
  check before insert); status is always `Draft` on creation.
- **A visual implementation requires ZERO changes to `BusinessManager`,
  `EntitlementManager`, `OnboardingManager`, `OnboardingActionExecutor`,
  or the database schema.** If any presentation work is ever found to
  require touching one of those, it is out of A1's scope and must be
  identified as a separate, future, non-visual contract — not smuggled
  into this one.
- **CORRECTION ROUND 1 — reclassified from "disclosed, non-blocking" to
  BLOCKING NONVISUAL PREREQUISITE #2.** When `assertCanCreateAnotherBusiness()`
  denies during the Business step, none of its five exception types are
  caught anywhere in the onboarding call chain
  (`BusinessOnboardingController::saveStep()` only catches
  `InvalidArgumentException`) or specially handled by
  `app/Exceptions/Handler.php`. The result is an **uncaught 500 error**
  (or, for a JSON request, a generic anonymized-message JSON error) with
  **no onboarding-specific, wizard-styled error UI** for this case. This
  remains, as originally found, **not a security defect** (no data
  exposure, no auth bypass; the denial-message classes are already
  deliberately anonymized to a numeric Workspace ID per their own
  docblocks) — the security verdict itself is unchanged (§16). It is
  **not fixable by a pure Blade-only visual contract**, since a friendly
  wizard-level error message for this case requires catching a new
  exception type in the controller — a behavior/domain change, out of
  A1's Blade-only scope. Per human direction in Correction Round 1, this
  gap is now treated as a **blocking prerequisite for A1 visual
  authorization** (not merely a disclosed footnote): a capacity-exhausted
  customer must not be able to reach a redesigned onboarding wizard whose
  Business step still terminates in a generic framework 500 page with no
  wizard-consistent error handling. Remediation (catching the five
  `EntitlementManager` exception types in `BusinessOnboardingController::saveStep()`
  and redirecting back to the Business step with a wizard-consistent
  `'onboarding'` error message, mirroring the existing
  `InvalidArgumentException` handling) is a separate, small, non-visual
  contract — **not performed here, not performed by A1's future
  implementation allowlist (§18), and must be human-merged before A1
  visual implementation begins.**

---

## 16. Security / tenancy pre-audit

Performed against the same 20-item checklist pattern used successfully
before Slice 5 and Slice 6's visual authorization, covering
`BusinessOnboardingController`, `BusinessController`, all 6 onboarding
FormRequests, `OnboardingManager`, `OnboardingActionExecutor`,
`BusinessManager`, `CustomerOnboarding`/`Business` models and their
repositories, `EnsureRequiredBusinessOnboardingIsComplete`, all onboarding
routes, and all 9 Blade views.

1. **Authentication on every onboarding route** — no issue. Every route
   inherits `web, auth, can:access_backend, ValidProduct, twofactor` from
   the `routes/customer.php` group; confirmed by an existing test
   (`test_guest_cannot_access_onboarding`).
2. **Customer ownership of the onboarding record** — no issue. No route
   accepts an onboarding ID; always resolved via `Auth::user()->customer`.
   Confirmed by `test_each_customer_only_sees_their_own_onboarding`.
3. **Ownership/scope of the Business created/updated** — no issue.
   `saveBusinessStep` re-verifies `findOwnedByCustomer()` before reusing
   an attached business ID, throwing `AuthorizationException` otherwise;
   creation always server-sets `customer_id`/`workspace_id`.
4/5. **Location/service/goal/other foreign-resource IDs** — no issue. The
   only client-suppliable foreign ID is `services.*.id`, resolved strictly
   against the caller's own (ownership-checked) Business's services
   relation — an ID belonging to another business is absent from that
   collection and triggers a hard rejection (`InvalidArgumentException`),
   never a silent hijack. Locations have no client-supplied ID at all.
6. **Mass assignment** — no issue, with one non-blocking hardening note.
   `CustomerOnboarding::$fillable` excludes every state-machine field. No
   onboarding controller passes raw `$request->all()` into `fill()`/
   `create()`. **Note**: `Business::$fillable` includes `customer_id`,
   safe today only because both repository write paths explicitly strip
   it before `fill()` — not exploitable now, worth a future hardening
   pass (`$guarded` or removing it from `$fillable`), not a blocker.
7. **Unsafe request-controlled model lookup** — no issue. No `Model::find($request->input('id'))`
   pattern exists in scope.
8/9. **Unsafe raw Blade output** — no issue. Zero `{!! !!}` occurrences
   across all 9 views — every dynamic value, including the analysis
   findings' `title`/`reason` text, is emitted via `{{ }}`.
10. **Unsafe dynamic JS HTML** — no issue. The one `<script>` block
    (analysis polling) only calls `fetch()`/`window.location.href`/
    `window.location.reload()` — no `innerHTML`/`outerHTML` write of any
    kind.
11. **External URL/profile-link handling (Assets)** — no issue. URLs are
    only ever rendered inside an escaped `<input value>`, never as a
    clickable `href`. `UrlNormalizer` explicitly documents and enforces
    "no remote requests... preventing SSRF" — local `parse_url()`
    normalization only, rejects non-http(s) schemes and embedded
    credentials, applied uniformly to the dedicated Assets form and the
    Results-step inline-edit path alike.
12. **File upload handling** — no issue, **not applicable**: zero
    `type="file"`/`enctype`/`UploadedFile`/`Storage::` references
    anywhere in the 9 views. Confirmed independently by the architecture
    trace (§7.3) and the security audit.
13. **Analysis payload rendering safety** — no issue.
    `InitialBusinessSnapshotBuilder` is deterministic/local-data-only, no
    AI, no remote text; every rendered finding string is drawn from a
    small closed set of hardcoded literals keyed off boolean facts, never
    attacker- or AI-generated free text, and is still `{{ }}`-escaped
    regardless.
14. **Authorization before lookup/side effect** — no issue. Every
    `OnboardingManager` step method and `OnboardingActionExecutor::execute()`
    calls `assertOwnership()` as its first (or first-after-cheap-allowlist-check)
    statement.
15. **Business-capacity enforcement before persistence** — no issue.
    `assertCanCreateAnotherBusiness()` is called before
    `createForCustomerInWorkspace()`, inside the same transaction (§15).
16. **Race/concurrency protection for slot allocation** — no issue,
    explicit locking present: `SELECT ... FOR UPDATE` on the Workspace
    row before the capacity check, serializing concurrent
    onboarding-business-creation for the same Workspace; bounded 3-attempt
    retry absorbs a documented, intentional lock-order conflict, not a
    correctness gap.
17/18. **Step-order / completion bypass (POST and GET)** — no issue on
    either axis. GET: `resolveStep()` clamps any forward-jump request
    and redirects rather than rendering. POST: `assertValidStepCompletion()`
    rejects out-of-order step completion at the domain layer, and any
    step requiring an attached Business rejects if `business_id` is still
    null — confirmed by existing tests on both axes.
19. **Repeated POST / idempotency** — no issue. Resubmitting the Business
    step updates rather than duplicates; `completeStep()` never regresses
    `current_step`; `complete()` short-circuits to a no-op if already
    `Completed`, guaranteeing `CustomerOnboardingCompleted` never
    double-fires. Confirmed by existing tests.
20. **Cross-customer onboarding access** — no issue. The onboarding
    record, its attached Business, and its analysis payload are all
    resolved exclusively from the authenticated user server-side, with
    ownership re-asserted at every domain-method entry point even for
    already-attached IDs.

**Security verdict (unchanged by Correction Round 1): NO BLOCKING
SECURITY DEFECT FOUND.** `security_pre_audit_status:
passed_no_blocking_security_defect` (§0) —
**`a1_visual_status` is explicitly NOT** `blocked_until_security_remediation_human_merged`;
none of the 20 security items above found a defect, and nothing here
requires security remediation. The one non-blocking hardening note
(`Business::$fillable` including `customer_id`, item 6) remains
non-blocking — it is not a security defect either, merely a future
defense-in-depth improvement.

**Overall A1 visual status is nonetheless BLOCKED, for reasons entirely
separate from security** — Correction Round 1 identified two blocking
NONVISUAL prerequisites unrelated to tenancy/security: the
`BUSINESS_ONBOARDING_ENABLED` documented-master-switch-vs-actual-behavior
drift (§5) and the uncaught capacity-denial 500 (§15, formerly recorded
here as non-blocking, now reclassified as blocking). `a1_visual_status:
blocked_until_nonvisual_onboarding_behavior_remediation_human_merged`
(§0). **Do not conflate "security-clear" with "visual-work-authorized"
— they are independent gates in this contract, and only the first is
currently satisfied.**

---

## 17. Accessibility preservation

- **Preserve exactly**: `analysis.blade.php`'s `role="status"
  aria-live="polite"` on `#analysis-status` — the only explicit ARIA-live
  region in the feature, and the only place a future implementation must
  be careful not to disturb via a wrapping component (§12).
- **Preserve exactly**: every existing `<label for="...">`/`id` pairing
  across `business.blade.php`, `location.blade.php`, `goals.blade.php`,
  `services.blade.php`, `assets.blade.php` — `x-input`/`x-select`
  adoption (§11) preserves this automatically via their own `label`/`name`
  props, which render the same `for`/`id` association.
- **Genuine, in-scope, optional improvement**: adding `aria-current="step"`
  to the active nav-pill item, and visually-hidden "completed"/"upcoming"
  state text for the other pills, is a legitimate accessibility
  improvement within pure-presentation scope (no state-machine change,
  no new component required beyond what §13 already allows) — not
  required, not locked, available to a future implementer.
- No other explicit ARIA attributes exist anywhere in the 9 views today;
  A1 does not need to preserve what doesn't exist, only avoid removing
  the one ARIA-live region and the existing label associations.

---

## 18. Exact future implementation allowlist and stop threshold

**Mechanical determination, not a blind lock of 9+3=12**: every one of
the 9 onboarding views contains at least one real, currently-unstyled-by-the-design-system
element (a `.btn`/`.card`/`.alert`/form-control) that a Design System
visual redesign would genuinely change — confirmed individually in §7.
**No view qualifies as a read-only preservation surface** (unlike the
`_chat_list` partial in the Slice 6 precedent, which needed zero visual
change and was correctly excluded from that slice's write allowlist).
`complete.blade.php`, the shortest file (4 lines), still contains a real
`.btn` element (§7.5) that would change — it is not a no-op either.

**Exact future implementation allowlist — 12 paths, numbered 1-12:**

### Onboarding views (9)
1. `resources/views/customer/onboarding/show.blade.php` — `x-card`/`x-alert` adoption (§11); optional stepper enhancement (§13).
2. `resources/views/customer/onboarding/steps/analysis.blade.php` — `x-button` adoption on 3 elements; `#analysis-status` wrapper and its inline script preserved unchanged (§12/§14).
3. `resources/views/customer/onboarding/steps/assets.blade.php` — `x-button` ×2, `x-input` ×3.
4. `resources/views/customer/onboarding/steps/business.blade.php` — `x-input` ×7 (one non-adopted textarea, §12), `x-select` ×1, `x-button` ×1.
5. `resources/views/customer/onboarding/steps/complete.blade.php` — `x-button` (href variant) ×1.
6. `resources/views/customer/onboarding/steps/goals.blade.php` — `x-button` ×1 (checkboxes non-adopted, §12).
7. `resources/views/customer/onboarding/steps/location.blade.php` — `x-input` ×5, `x-select` ×1, `x-button` ×1 (checkbox non-adopted, §12).
8. `resources/views/customer/onboarding/steps/results.blade.php` — `x-empty-state` ×1, `x-button` ×2 patterns (fixed "success" button non-adopted, §12), `x-input` ×1 pattern.
9. `resources/views/customer/onboarding/steps/services.blade.php` — `x-card` ×1 pattern, `x-input` ×2 patterns, `x-button` ×1 (checkbox non-adopted, §12).

### New Design System tests (3)
10. `tests/Feature/DesignSystem/BusinessOnboardingDesignSystemContentTest.php` (new)
11. `tests/Feature/DesignSystem/BusinessOnboardingComponentAdoptionTest.php` (new)
12. `tests/Feature/DesignSystem/BusinessOnboardingExistingBehaviorPreservedTest.php` (new)

**No controller/domain/config/schema path is included.** §15 confirms a
visual implementation requires zero changes to `BusinessManager`,
`EntitlementManager`, `OnboardingManager`, `OnboardingActionExecutor`, or
the database — none are on this allowlist, and none may be touched
without a separate, future, explicitly-authorized non-visual contract.

**Stop threshold: any path beyond this exact 12-item allowlist is a
required-13th-path-shaped stop condition** — implementation must stop,
leave the working tree unstaged, and report, exactly as this repository's
prior contracts require for their own allowlists. **This 12-path
allowlist and 13th-path stop threshold are unchanged by Correction Round
1** — the two blocking nonvisual prerequisites (§5, §15) are, by
definition, not on this visual-only allowlist and are not remediated by
it. **This allowlist may not be executed until both blocking
prerequisites are separately remediated and human-merged** (§0
`a1_visual_status`) — its existence here documents the visual scope in
advance; it does not itself authorize starting implementation, now or
once unblocked.

---

## 19. Focused Design System test plan

1. **`BusinessOnboardingDesignSystemContentTest.php`** — static content
   assertions across all 9 views: zero `data-feather` occurrences (trivially
   true today, guards against regression); zero hardcoded hex/rgb/rgba/hsl
   literals; zero explicit `font-family` declarations; no stale
   pre-redesign Bootstrap-only presentation literals remain in touched
   markup (e.g. a lingering bare `btn-success` outside the one disclosed,
   locked non-adoption in §12).
2. **`BusinessOnboardingComponentAdoptionTest.php`** — exact component
   counts per file, matching §11's matrix precisely (e.g. `show.blade.php`
   uses exactly one `x-card` and one `x-alert`; `business.blade.php` uses
   exactly 7 `x-input` + 1 `x-select` + 1 `x-button`, and still contains a
   native `<textarea>`); exact locked non-adoptions from §12 present and
   unchanged (native checkboxes/radio still present; the one
   `btn-success` button still native, not wrapped in `x-button`).
3. **`BusinessOnboardingExistingBehaviorPreservedTest.php`** — the
   9-view inventory itself (no 10th view silently added); every form's
   `action`/`method` and `@csrf` token still present and unchanged; the
   step-order/skip hooks (`STEP_ORDER`, `SKIPPABLE_STEPS`) still reachable
   through the same routes; the analysis polling script's target route
   and status-branch structure unchanged; the Results step's fingerprint/
   action_key hidden-input pattern intact; all 6 feature-flag/redirect
   behaviors from §5 unchanged; critical IDs (`#business-onboarding`,
   `#analysis-status`) and the `role="status" aria-live="polite"`
   attributes intact.

Security tests, if any are ever needed, belong to a separate future
remediation contract — none are required here (§16 verdict: unblocked,
no defect found).

---

## 20. Existing regression test plan

**CORRECTION ROUND 1 — corrected test-count policy.** The original draft
required "the exact same pass count" before and after A1 implementation.
That is imprecise: A1's own future implementation *adds* 3 new Design
System test files (§18/§19), which genuinely increase the total passing
count — requiring an unchanged *total* would be self-contradictory. The
corrected policy:

- **Existing regression subset** (the tests listed below): must preserve
  its own **pre-existing test count** exactly — none of these files are
  modified by A1, so none of their individual test methods may be added,
  removed, or change outcome.
- **The 3 new A1 Design System tests** (§19): are net-new and **increase**
  the full suite's total passing count by their own test-method count —
  they are not expected to net to zero against anything.
- **Full suite**: requires **0 failures, 0 skipped, exit code 0**, both
  before and after — this is the actual regression guarantee, not a
  specific total number.
- **Reporting**: the pre-implementation and post-implementation full-suite
  pass counts must both be reported exactly (not estimated), and the
  post-count must equal the pre-count **plus** the 3 new test files' own
  method count — not simply "equal."

Must be run both **before** and **after** any eventual A1 visual
implementation (none of these are modified by A1 — they exercise
behavior, not markup):

- `tests/Feature/Business/BusinessOnboardingHttpTest.php`
- `tests/Feature/Business/OnboardingManagerTest.php`
- `tests/Feature/Business/CustomerOnboardingRepositoryTest.php`
- `tests/Feature/Business/OnboardingActionExecutorTest.php`
- `tests/Feature/Business/BuildInitialBusinessSnapshotJobTest.php`
- `tests/Feature/Entitlement/EntitlementManagerLegacyCompatibilityAssignmentTest.php`
- `tests/Feature/Workspace/WorkspaceManagerPreEnforcementTest.php`
- `tests/Feature/Workspace/WorkspaceM1BBoundaryTest.php`
- `tests/Feature/Usage/NewBusinessWalletInitializationTest.php`
- `tests/Feature/Usage/NewBusinessPayerAssignmentInitializationTest.php`
- `tests/Feature/Entitlement/EntitlementManagerConcurrencyTest.php`
- `tests/Unit/Business/BusinessConfigTest.php`

Plus the full existing suite's exact pass count, compared against the
pre-A1-implementation baseline per the corrected policy above — zero
regressions in the existing subset permitted, with the 3 new A1 tests
adding to (not replacing) the total, per this repository's standing
test-contract discipline.

---

## 21. Manual visual verification checklist (for the future implementer)

- Load `/onboarding` fresh (no row) as a brand-new customer — confirm
  `show.blade.php` renders the redesigned card/alert shell with the Goals
  step first.
- Step through Goals → Business → Location → Services → Assets (using
  both "Continue" and "Skip for now") → Analysis → Results → Complete,
  confirming every redesigned button/input/select still submits to its
  exact original route and the wizard advances exactly as before.
- Trigger a validation error on at least one field per step; confirm the
  shared top-of-page `x-alert` still lists every error, and — for
  `x-input`-adopted fields specifically — confirm any newly-added
  per-field `error` prop usage doesn't duplicate or contradict the shared
  alert.
- Submit the Business step, then reload `/onboarding/services` directly
  (skipping ahead) — confirm the redesigned UI still redirects back to
  the correct current step rather than rendering the skipped-ahead one.
- Trigger the Analysis step; watch the polling UI through pending →
  ready (or force a failure) — confirm the 4 status branches still render
  correctly with the redesigned buttons/links, and the polling script is
  untouched.
- On the Results step, exercise both an inline "Save"-type finding and a
  "Go fix this"-type finding; confirm the fingerprint/action_key hidden
  fields and redirect targets are unchanged.
- Resize to mobile/narrow width; confirm the redesigned card/stepper/
  forms remain usable (no component-driven regression).
- Toggle dark mode (if applicable per the M2 theme system) and confirm
  every adopted component responds via the existing token system with no
  new hardcoded literal introduced.
- Confirm `analysis.blade.php`'s `role="status" aria-live="polite"`
  region is still present and unchanged in a screen-reader pass, or at
  minimum in the rendered DOM.

---

## 22. Contract self-audit / mechanical final check

- Exactly one changed path: this document. ✓
- A1 contract only — no application, view, controller, model, route,
  config, or test file created/modified by drafting this contract. ✓
- All 9 onboarding views inspected directly, in full (§2, §7). ✓
- Exact 9-view inventory mechanically confirmed via `find`; no 10th file
  exists. ✓
- No A2 view (`customer/business/**`, `customer/workspace{,s}/**`,
  `admin/businesses/**`, `admin/workspaces/**`) appears anywhere in the
  future allowlist (§18). ✓
- No controller/domain implementation performed or allowlisted (§18). ✓
- All 3 onboarding feature flags documented with exact read-sites and
  exact disabled-state behavior (§5). ✓
- State-machine sequence mechanically documented and cross-checked
  against `STEP_ORDER` and the nav-pills label order (§6). ✓
- Analysis/async behavior fully documented, including the undocumented-until-now
  "fetch never checks response.ok" detail (§14). ✓
- RFC-004/Business-capacity behavior fully documented, including the
  exact call order relative to the Workspace lock (§15). ✓
- Security pre-audit complete, 20/20 items clear, verdict unblocked
  (§16). ✓
- Component APIs inspected by reading every one of the 11 requested
  components' actual source (§10). ✓
- Exact adoption counts locked (§11), exact non-adoptions locked with
  reasons (§12). ✓
- Exact icon counts locked: 0 (§8). ✓
- Exact hardcoded-color/font counts locked: 0 (§9). ✓
- No no-op implementation path in the allowlist — every one of the 9
  views mechanically confirmed to require a real change (§18). ✓
- `docs/automation/AI-AUTONOMY-STATE.json` untouched. ✓
- `docs/automation/DESIGN-SYSTEM-M2-CONTRACT.md` untouched. ✓
- `docs/automation/PRODUCT-SURFACE-RETENTION-AUDIT.md` untouched. ✓
- **Correction Round 1**: `correction_round: 1` recorded (§0); both
  blocking nonvisual prerequisites documented with evidence and cross-
  referenced consistently across §0/§5/§15/§16/§18/§23. ✓

`git diff --check` run against the staged file before commit — reported
in the final chat report.

---

## 23. Correction Round 1 — summary

Executed against this contract's own pre-correction head
`1018983cc7c68ea0923beb8b437c9e6304f38bf4`, on the same branch, changing
only this file. Outcomes:

1. **`BUSINESS_ONBOARDING_ENABLED` read-site count corrected**: 2 runtime
   sites (`EnsureRequiredBusinessOnboardingIsComplete::handle()` and
   `EloquentAccountRepository::register()`'s compound condition), not 1
   as originally stated (§5).
2. **RFC-001 drift documented and verified against the actual merged
   text**, not merely asserted: `docs/rfcs/RFC-001-BUSINESS-CORE-DEPLOYMENT.md`
   §1/§8 (a companion deployment doc this contract's original research
   pass did not find) explicitly calls `BUSINESS_ONBOARDING_ENABLED` a
   "Master switch" whose disabled state should make "the entire
   onboarding wizard, analysis job, and dashboard redirect middleware
   behave as if the feature does not exist" — contradicted by the actual
   shipped behavior (only the dashboard redirect is gated; direct routes
   and analysis dispatch remain reachable regardless of the flag,
   confirmed by an existing passing test). Recorded as **BLOCKING
   NONVISUAL PREREQUISITE #1** (§5, §0).
3. **Capacity-denial uncaught-500 gap reclassified** from "disclosed,
   non-blocking" to **BLOCKING NONVISUAL PREREQUISITE #2** (§15, §0) —
   per human direction, not a re-discovered defect; the underlying
   evidence is unchanged from the original draft.
4. **Security verdict kept explicitly separate** from the now-blocked
   overall status: `security_pre_audit_status:
   passed_no_blocking_security_defect` (§0, §16) — the block is entirely
   for the two nonvisual behavioral prerequisites above, not for any
   security finding.
5. **Overall status**: `a1_visual_status` changed from
   `awaiting_separate_human_implementation_authorization` to
   `blocked_until_nonvisual_onboarding_behavior_remediation_human_merged`
   (§0).
6. **Stepper-navigation authorization withdrawn** (§13): A1 visual scope
   is now restyle-only for the stepper — no clickable step-pill
   navigation or other new interactivity is authorized, narrowing the
   original draft's broader "presentation-safe enhancement" framing.
7. **`x-empty-state` icon locked** to `icon="inbox"` (§11) — the
   component's own documented default, not left "TBD-at-implementation."
8. **Test-count policy corrected** (§19/§20): the existing regression
   subset must preserve its own pre-existing count exactly; the 3 new A1
   Design System tests add to, not replace, the total; the full suite
   requires 0 failures/0 skipped/exit 0; pre- and post- counts are both
   reported, not required to be numerically equal.
9. **Preserved unchanged, per instruction**: the exact 9-view inventory
   (§2), exact step order and Assets-only-skippable (§6), the 0/0/0
   data-feather/hardcoded-color/hardcoded-font counts (§7-§9), and the
   12-path future visual allowlist with its 13th-path stop threshold
   (§18) — none of these were found to need correction, and none were
   altered.
10. **Aggregate diff against `origin/main` remains exactly one path**:
    this document. No application code, test, RFC document,
    `DESIGN-SYSTEM-M2-CONTRACT.md`, `PRODUCT-SURFACE-RETENTION-AUDIT.md`,
    or `AI-AUTONOMY-STATE.json` file was modified.

---

*End of Design System M2 A1 — Business Onboarding visual contract.
Docs/audit only. No implementation has occurred. A1 visual implementation
is BLOCKED pending separate, human-merged remediation of two nonvisual
prerequisites (§5, §15/§0) — once unblocked, implementation still
requires its own separate, explicit human authorization. A2, A3, B1, and
every other roadmap group remain unstarted.*
