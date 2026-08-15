# RFC-004 Milestone 3 — Admin/Customer Surfaces + Capability Integration/Gating + Focused Regression

**Status:** PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE

Merging this contract does **not** authorize implementation. Implementation requires a separate, explicit human authorization message after merge, naming the implementation branch (`agent/rfc-004-m3`), exactly as RFC-004 Milestones 1 and 2 both required.

**Correction Round 1 note:** This revision corrects seven review findings against the original draft — an unauthorized controller→repository read path (§8, new), an over-broad Staff visibility grant (§12), an incorrect prefilter-by-base-plan-packaging algorithm for per-Business effective decisions (§12), a self-contradiction between the exception-to-HTTP sections for a stale/moved Business (§16), an under-specified toggle UI that could read as a working runtime control (§13), an incorrect admin route-prefix instruction (§11), and an unauthorized custom validation rule (§11). The runtime-gating decision (§6/§7) and the no-backfill-rerun/no-catalog-pricing-mutation decisions (§17/§18) are **unchanged** — none of the corrections below required reopening them.

**Residual structural type-boundary fix note:** After Correction Round 2, one narrow inaccuracy remained in §11's enum-conversion lock: `additional_business_slots` was described as arriving at the controller as an "already-validated plain `int`/`null`" — Laravel's `integer` validation rule confirms the submitted value is numeric, it does not coerce `$request->validated()`'s PHP type, and `assignFirstPlan()`'s `int $additionalBusinessSlots = 0` parameter does not accept an explicit `null` at all (unlike `changePlan()`'s genuinely nullable `?int`). §11 now locks the exact, per-action scalar conversion for `AssignWorkspacePlanRequest` (absent → semantic `0`, never `null`), `ChangeWorkspacePlanRequest` (`null` preserved, only a populated value is cast), and `UpdateWorkspaceAdditionalSlotsRequest` (always cast, the field is required). No FormRequest rule changed, no path added or removed, no other Round 1/2 decision reopened.

**Correction Round 2 note (final ordinary round):** This revision corrects `decideAvailableFeaturesForBusiness()`'s (§8.4) returned shape — the original Round 1 shape (`array<string, EntitlementDecision>`) could not distinguish "the effective decision is denied" from "a `business_feature_toggles` row exists," which are two independent facts (an `EntitlementDecision` follows the full §14 precedence chain and can be denied for a reason that has nothing to do with the toggle, e.g. a Workspace override, while the toggle row itself persists independently and is what `enableBusinessFeature()` actually removes). §8.4/§8.5/§12/§13/§21 are corrected accordingly — as a plain array shape, no new value-object file, no repository-boundary or path-count change. This round also locks the exact enum-instance conversions every admin FormRequest's controller action must perform before delegating to `EntitlementManager` (§11/§12), since Laravel's `Enum` validation rule validates the *request input*, not the value ultimately passed to a strongly-typed manager method. Nothing else is reopened — the runtime-gating deferral, Staff visibility rule, no-backfill decision, no-catalog-pricing-HTTP decision, admin route-prefix correction, and repository boundary (§8) all stand exactly as Round 1 locked them, and the path count remains 28.

---

## 1. Purpose

RFC-004 Milestone 2 (human-merged, PR #65, `af8be6840e46b1d5ff5733043d608427e90e4fd5`) built the complete `EntitlementManager` engine: the `decide()`/`decideBusinessSlotCapacity()` decision algorithms, every plan/status/complimentary/slot/override/toggle mutation, full event dispatch and durable audit, and slot-capacity enforcement at every Business-count-increasing operation. **No HTTP surface exists yet** — no controller anywhere in this repository calls `EntitlementManager::decide()`, `decideBusinessSlotCapacity()`, or any of its mutation methods (confirmed by direct repository search, §4 below).

Milestone 3 builds the minimum admin and customer HTTP surfaces RFC-004 §22 requires, wires the new permission boundary, and makes the one bounded capability-integration/gating decision this milestone's own repository evidence makes possible — no more, no less. It does **not** build any new product feature module, does **not** perform a tenancy migration, and does **not** touch RFC-005's billing boundary. It also does **not** turn any HTTP controller into an entitlement-table read layer — every read a controller or view needs is served by a small, exact, newly-locked `EntitlementManager` presentation API (§8), never a direct repository call from `app/Http`.

---

## 2. Authoritative base SHA

```
af8be6840e46b1d5ff5733043d608427e90e4fd5
```

This is the human merge commit for RFC-004 Milestone 2 (PR #65) into `main`. This contract's every file-path claim, every "does not currently exist" claim, and every "existing convention" claim was verified directly against a working tree at this exact commit. `AI-AUTONOMY-STATE.json` is not edited by this contract.

---

## 3. Verified repository state

**3.1 — `PlatformFeatureRegistry`/`PlatformFeature` (re-verified directly, not assumed):**

`app/Library/Entitlement/PlatformFeatureRegistry.php`'s `AVAILABILITY` constant, read in full:

| Available (3) | Planned (12) |
|---|---|
| `crm`, `conversations`, `automations` | `calendar`, `forms`, `website_generation`, `ai_coo_basic`, `seo_basic_visibility`, `ads_basic_visibility`, `seo_module`, `google_ads_module`, `meta_ads_module`, `white_label`, `agency_package_capabilities`, `prospect_outreach` |

`app/Enums/Entitlement/PlatformFeature.php` has exactly these 15 cases, matching `PlatformFeatureRegistry` exactly. **No discrepancy from the M1-locked values was found.** The M1 seed migration (`database/migrations/2026_08_13_120007_seed_workspace_plan_catalog_and_features.php`) packages 9 features for Core, 12 for Growth (Core + `seo_module`/`google_ads_module`/`meta_ads_module`), 15 for Agency (Growth + `white_label`/`agency_package_capabilities`/`prospect_outreach`) — matching RFC-004 §12.2 exactly.

**3.2 — No HTTP surface exists today.** A repository-wide search for `EntitlementManager|decideBusinessSlotCapacity()|->decide(` under `app/Http` returns zero matches. `EntitlementManager` is referenced only from `WorkspaceManager`/`BusinessManager` (domain layer) and its own repositories/exceptions.

**3.3 — Prospect Outreach: no executable implementation exists.** A repository-wide search (routes, controllers, models, jobs — excluding docs/tests-of-the-registry-itself) for `prospect.?outreach`/`ProspectOutreach` finds only: the `PlatformFeature` enum case, the `PlatformFeatureRegistry` `Planned` mapping, the M1 seed's packaging row, and tests/docs *of that metadata itself*. **No controller, route, model, or job implements Prospect Outreach anywhere in this repository.**

**3.4 — White Label: no executable implementation exists.** Identical search pattern for `white.?label`/`WhiteLabel`, plus adjacent-concept searches (`custom_domain`, `whitelabel`, `brand`) — the only "brand" hits are unrelated Twilio 10DLC brand-registration SDK parameters and unrelated demo-content prose. **No controller, route, model, job, or runtime branding/custom-domain toggle exists anywhere in this repository.**

**3.5 — CRM/Conversations/Automations: confirmed `Available`, but confirmed User/Customer-scoped, never Business-scoped.** This is the decisive finding for §6 below.

**3.6 — Admin surface: entirely new territory.** `app/Http/Controllers/Admin/WorkspaceController.php` exists (RFC-003 Milestone 5) and is read-only (`index()`/`show()` only, no `WorkspaceManager`/`EntitlementManager` dependency, doc-commented as intentionally read-only). `routes/admin.php` has exactly two Workspace routes (`admin.workspaces.index`, `admin.workspaces.show`), both GET, declared **without** any literal `admin/` prefix or `admin.` name prefix in the file itself — both are applied externally by `app/Providers/RouteServiceProvider.php`'s wrapping group (`->prefix(config('app.admin_path'))->as('admin.')`, confirmed by direct read — `admin_path` defaults to `admin` via `env('ADMIN_PATH', 'admin')` but is environment-configurable). `config/permissions.php` has exactly one Workspace-category key (`'view workspace'`). `tests/Feature/Workspace/AdminWorkspaceControllerTest.php` explicitly locks the admin Workspace route count at 2 and asserts the rendered views contain zero mutation controls.

**3.7 — Customer surface: no entitlement wiring today, but the seam is already visible.** `app/Http/Controllers/Customer/Workspace/WorkspaceController.php` (RFC-003) has 11 actions and does not inject `EntitlementManager`. `tests/Feature/Workspace/WorkspaceBusinessCreationHttpTest.php` already contains a test with an explicit code comment stating the controller does **not yet** map `WorkspacePlanUnassignedException` to a graceful flash-error redirect, and that this is "blocked until M3."

**3.8 — `EntitlementManager::updateCatalogPricing()` already exists and is fully authority-gated**, but RFC §22's admin bullet list never includes catalog price *mutation* among its required minimum actions. See §18.

**3.9 — `WorkspaceEntitlementBackfillV1`/`workspaces:backfill-entitlements` is proven idempotent and safe to re-run**, is **not scheduled anywhere**, and only ever touches Workspaces with zero existing assignment row. See §17 for why M3 does not re-run it.

**3.10 — No entitlement repository has a "list all" read shape today (re-verified during this correction, direct reads of every relevant contract):**
- `WorkspacePlanCatalogRepository`: exactly `findById()`, `findByTier()`, `findForUpdate()`, `create()`, `update()` — **no list-all method.**
- `WorkspaceEntitlementOverrideRepository`: exactly `findByWorkspaceAndFeature()`, `create()`, `delete()`, `update()` — **no list-for-Workspace method.**
- `WorkspacePlanFeatureRepository`: **already has** `featureKeysForCatalog(WorkspacePlanCatalog $catalog): Collection` and `includesFeature()` — sufficient as-is.
- `WorkspacePlanAssignmentRepository`: **already has** `findByWorkspaceId()` — sufficient as-is.

The original contract draft incorrectly assumed controllers could read these repositories directly for display purposes and, separately, incorrectly asserted no new repository method would ever be needed while simultaneously leaving an "add one later if discovered missing" escape hatch. **Both are corrected in §8**: the missing "list all three tiers" and "list a Workspace's overrides" shapes are assembled entirely from the methods that already exist (a fixed 3-iteration loop over `WorkspacePlanTier::cases()`, and a fixed 15-iteration loop over `PlatformFeature::cases()`, respectively) — inside two new `EntitlementManager` methods, never inside a controller, and never by adding a new repository method.

---

## 4. Exact M3 scope

**In scope:**
- Two new `EntitlementManager` read/presentation methods plus one new per-Business decision method (§8), and their exact value-object return shapes.
- New admin HTTP surface: catalog+feature-mapping inspection (read-only), plan assignment/change/status mutation, complimentary grant/revoke, additional-slot allocation/revocation, Workspace entitlement override create/revert, effective-entitlement and slot-capacity explanation display — all delegating exclusively to `EntitlementManager`.
- New customer HTTP surface: plan tier/feature-list/slot-capacity inspection on the existing Workspace overview page (owner/active-Admin only, §12), per-Business effective-feature display, and Business feature "disable preference" toggle (§13) — all delegating exclusively to `EntitlementManager`, reusing the owner-or-active-Admin authority `EntitlementManager` already self-enforces.
- New `Workspace Plans` permission category, two keys (§10).
- Exception-to-HTTP mapping for the entitlement exceptions that can now surface through the customer Business-creation/reassignment/feature-toggle HTTP paths (§16).
- Focused regression per §22.

**Explicitly not in scope (unchanged from the original draft — not reopened by this correction):**
- Wiring `EntitlementManager::decide()` into CRM/Conversations/Automations' actual runtime execution.
- Any tenancy migration adding `business_id` to Contacts/ChatBox/Automations or any other legacy module.
- Prospect Outreach or White Label gating of any kind.
- Catalog price/currency mutation HTTP endpoint.
- Re-running, scheduling, or otherwise re-invoking `WorkspaceEntitlementBackfillV1`/`workspaces:backfill-entitlements`.
- Any RFC-005 billing/wallet/Stripe work.
- Any new product feature module.
- Any new repository method or repository file.

---

## 5. Runtime capability-integration decision — unchanged, restated

Direct repository inspection of CRM (`ContactsController`), Conversations (`ChatBoxController`), and Automations (`AutomationsController`) found all three scoped to legacy `user_id`/`customer_id`, with **no `business_id` column anywhere**, no async job carrying Business context, and no authoritative "current Business" resolver either used or usable by any of them. The only "resolve a Business" pattern anywhere in the repository, `BusinessRepository::findPrimaryByCustomer()`, is an explicitly-documented arbitrary primary-Business pick — unsafe under RFC-003's multiple-Businesses-per-Workspace model.

**Decision, unchanged: M3 does not wire `EntitlementManager::decide()` into CRM's, Conversations', or Automations' runtime execution paths.** This is reported as a separately-required future capability-integration/tenancy-migration item — not absorbed into M3, not silently dropped. This is not an RFC-vs-repository contradiction requiring a stop-and-report RFC correction (RFC §29 conditions wiring on evidence; it never asserted these three modules are Business-scoped).

**Consequence for the toggle surface, corrected in this round — see §13 for the exact required UX.** The short version: `EntitlementManager::disableBusinessFeature()`/`enableBusinessFeature()` are real, fully-audited, already-built mutations, safe to expose regardless of runtime wiring — but the customer-facing presentation must never look or read like a working runtime on/off switch for a currently-`Available` feature. §13 locks the exact wording.

---

## 6. Explicit per-feature decisions — unchanged

**Currently `Available` (3):** `crm`, `conversations`, `automations` — plan/override entitlement inspection and Business-toggle mutation are wired (§13's exact "preference" framing). Runtime capability integration into the legacy controllers remains deferred (§5).

**Planned, no gate introduced (12):** `calendar`, `forms`, `website_generation`, `ai_coo_basic`, `seo_basic_visibility`, `ads_basic_visibility`, `seo_module`, `google_ads_module`, `meta_ads_module`, `agency_package_capabilities` — no executable implementation exists; shown as "planned" in the catalog inspection view, never as toggleable. `prospect_outreach` and `white_label` — no executable implementation confirmed (§3.3/§3.4); per RFC §26, no gate and no compatibility-override pass introduced for either.

No `PlatformFeatureRegistry` value changes in M3.

---

## 7. (reserved)

Intentionally left as a numbering placeholder — the original draft's §7 content is fully absorbed into §5/§6 above; no content was dropped, only consolidated during this correction to make room for the new §8.

---

## 8. `EntitlementManager` read/presentation API (new in this correction — resolves Blocker 1)

**Why no HTTP controller reads an entitlement repository directly:** RFC-004 §20 is explicit — "No direct entitlement-table query is authorized anywhere outside `EntitlementManager` and its six repositories... every controller, job, or future feature-gated code path calls `EntitlementManager::decide()` or `decideBusinessSlotCapacity()`, never a repository or the raw tables directly." The original contract draft violated this by having `WorkspacePlanCatalogController`, the admin `WorkspaceController`, and the customer `WorkspaceController` call `WorkspacePlanCatalogRepository`/`WorkspacePlanFeatureRepository`/`WorkspacePlanAssignmentRepository`/`WorkspaceEntitlementOverrideRepository` directly for display purposes — turning controllers into an entitlement-table orchestration layer exactly as this correction's Blocker 1 identifies. This section closes that gap with the **minimum** new `EntitlementManager` surface, assembled entirely from repositories `EntitlementManager` already depends on (§3.10 — no new repository method, no new repository file).

**Authorized modification: `app/Library/Entitlement/EntitlementManager.php` gains exactly three new public methods.** None of the three performs its own authority check — matching `decide()`/`decideBusinessSlotCapacity()`'s own existing precedent exactly (pure reads, no `assertPlatformAdministrator()`/`assertWorkspaceOwnerOrActiveAdmin()` call); the calling controller's own `$this->authorize()` (admin) or already-resolved effective role (customer, §12) is what gates *access* to the endpoint, exactly as it already gates access to every other read this app performs.

### 8.1 Two new value objects (new files, `app/Library/Entitlement/`)

```php
final readonly class WorkspacePlanCatalogSummary
{
    /**
     * @param array<int, string> $planFeatureKeys structural packaging (§10.2) — every feature_key this tier includes, regardless of availability.
     * @param array<string, bool> $featureAvailability feature_key => PlatformFeatureRegistry::isAvailable(), one entry per key in $planFeatureKeys.
     */
    public function __construct(
        public int $id,
        public WorkspacePlanTier $tier,
        public string $displayName,
        public ?string $price,
        public ?int $currencyId,
        public string $billingCycle,
        public int $businessSlotIncluded,
        public ?int $businessSlotMax,
        public bool $unlimitedBusinessSlots,
        public ?string $additionalBusinessSlotPriceRatio,
        public bool $isActive,
        public array $planFeatureKeys,
        public array $featureAvailability,
    ) {}
}
```

```php
final readonly class WorkspaceEntitlementSummary
{
    /**
     * @param array<int, string> $planFeatureKeys structural packaging for the assigned tier; empty when unassigned.
     * @param array<string, WorkspaceEntitlementOverrideState> $overrides feature_key => state, only for features with an actual override row on this Workspace.
     */
    public function __construct(
        public bool $isAssigned,
        public ?WorkspacePlanTier $tier,
        public ?string $tierDisplayName,
        public ?WorkspacePlanAssignmentStatus $status,
        public ?bool $isComplimentary,
        public array $planFeatureKeys,
        public array $overrides,
        public BusinessSlotCapacityDecision $capacity,
    ) {}
}
```

### 8.2 `EntitlementManager::listPlanCatalogSummaries(): array`

Returns `array<int, WorkspacePlanCatalogSummary>`, **exactly 3 entries**, in the fixed order `Core, Growth, Agency`. Algorithm:

```text
for tier in [Core, Growth, Agency]:                          // WorkspacePlanTier::cases(), fixed order
    catalog = catalogRepository.findByTier(tier)              // existing method
    // catalog is null only if the M1 seed is missing — defensive RuntimeException, never silently skipped
    planFeatureKeys = planFeatureRepository.featureKeysForCatalog(catalog).all()   // existing method
    featureAvailability = { key: PlatformFeatureRegistry.isAvailable(key) for key in planFeatureKeys }
    summaries[] = new WorkspacePlanCatalogSummary(catalog.id, tier, catalog.display_name, catalog.price,
        catalog.currency_id, catalog.billing_cycle, catalog.business_slot_included, catalog.business_slot_max,
        catalog.unlimited_business_slots, catalog.additional_business_slot_price_ratio, catalog.is_active,
        planFeatureKeys, featureAvailability)
return summaries
```

Satisfies admin scope A (§9 requirement A) exactly: exactly Core/Growth/Agency; every catalog structural field the UI needs; packaged feature keys; `PlatformFeatureRegistry` availability per key. No new repository method — three `findByTier()` calls plus three `featureKeysForCatalog()` calls, both pre-existing.

### 8.3 `EntitlementManager::getWorkspaceEntitlementSummary(Workspace $workspace): WorkspaceEntitlementSummary`

Algorithm:

```text
assignment = assignmentRepository.findByWorkspaceId(workspace.id)     // existing method
capacity = this.decideBusinessSlotCapacity(workspace)                  // existing method, reused, never re-derived

overrides = {}
for feature in PlatformFeature::cases():                               // fixed 15-iteration loop
    override = overrideRepository.findByWorkspaceAndFeature(workspace.id, feature.value)  // existing method
    if override is not null:
        overrides[feature.value] = override.state

if assignment is null:
    return new WorkspaceEntitlementSummary(isAssigned: false, tier: null, tierDisplayName: null,
        status: null, isComplimentary: null, planFeatureKeys: [], overrides: overrides, capacity: capacity)

catalog = catalogRepository.findById(assignment.workspace_plan_catalog_id)   // existing method
planFeatureKeys = planFeatureRepository.featureKeysForCatalog(catalog).all()

return new WorkspaceEntitlementSummary(isAssigned: true, tier: catalog.tier, tierDisplayName: catalog.display_name,
    status: assignment.status, isComplimentary: assignment.is_complimentary,
    planFeatureKeys: planFeatureKeys, overrides: overrides, capacity: capacity)
```

Satisfies admin scope B (§9 requirement B) exactly: assigned/unassigned; current tier/display name; assignment status; complimentary status; packaged plan feature keys; every Workspace override; `BusinessSlotCapacityDecision` sourced directly from the existing `decideBusinessSlotCapacity()` (never re-derived). Overrides are computed for a Workspace regardless of assignment state, since an override row is independent of whether an assignment currently exists. No new repository method — one `findByWorkspaceId()`, up to one `findById()`, up to one `featureKeysForCatalog()`, and fifteen `findByWorkspaceAndFeature()` calls, all pre-existing.

### 8.4 `EntitlementManager::decideAvailableFeaturesForBusiness(Workspace $workspace, Business $business, int $actorUserId): array`

**Corrected in Round 2.** An `EntitlementDecision` alone cannot answer "does a `business_feature_toggles` row exist for this (Business, feature)" — these are two independent facts. A stored disable-preference row can exist while the *effective* decision is denied for an entirely unrelated reason (e.g. a Workspace `deny` override, or the plan status going `suspended`) — the row is untouched by either of those; only `enableBusinessFeature()` removes it. Presenting only the effective decision would make it impossible for the customer UI to correctly offer "remove the preference I recorded" in exactly the cases where it still matters.

**Locked return shape — a plain array shape, no new value-object file:**

```php
/**
 * @return array<string, array{decision: EntitlementDecision, disablePreferenceRecorded: bool}>
 *         keyed by PlatformFeature value; empty array if the Business is no
 *         longer authoritatively part of $workspace as of this read.
 */
```

Algorithm (resolves Blocker 3 and the read-race wording, both unchanged from Round 1, plus the corrected two-fact shape):

```text
decisions = {}
for feature in PlatformFeature::cases():                               // fixed 15-iteration loop, NEVER filtered by base plan packaging
    if not PlatformFeatureRegistry.isAvailable(feature.value):
        continue                                                        // Planned features are never shown as toggleable or decided per-Business
    try:
        decision = this.decide(workspace, business, feature.value, actorUserId)
    catch (WorkspaceBusinessNotFoundException | BusinessWorkspaceMismatchException):
        return {}                                                       // see Round 1's read-race rationale, unchanged
    toggle = toggleRepository.findByBusinessAndFeature(business.id, feature.value)   // existing method, already an EntitlementManager dependency
    decisions[feature.value] = {
        'decision': decision,
        'disablePreferenceRecorded': toggle !== null,
    }
return decisions
```

`toggleRepository` (`BusinessFeatureToggleRepository`) is already one of `EntitlementManager`'s ten existing constructor dependencies (used internally by `disableBusinessFeature()`/`enableBusinessFeature()`/`decide()` itself) — this is a second, independent read of the same repository method `decide()`'s own step 5 already calls internally, never a new dependency, never a new repository method, and never a controller reading `BusinessFeatureToggleRepository` directly (§8's boundary is unchanged: only `EntitlementManager` itself touches its six repositories). `disablePreferenceRecorded` is never inferred from `decision.allowed` or `decision.reason` — it is read independently, exactly as this round requires.

**Blocker 3 resolution, exact rule (unchanged from Round 1):** this method iterates every `PlatformFeature` case that `PlatformFeatureRegistry` currently reports `Available` — **never** filtered by whether the Workspace's base plan mapping happens to include that feature. Plan packaging remains a **separate** structural display (`WorkspaceEntitlementSummary::planFeatureKeys`, §8.3) — informational, never used to skip a `decide()` call.

**Read-race resolution, exact rule (unchanged from Round 1):** if `decide()` throws `WorkspaceBusinessNotFoundException`/`BusinessWorkspaceMismatchException` for this Business, the method returns an **empty** map for that Business entirely (the toggle read for that feature never happens either, since the pair is already known to be invalid) — never a partial result, never another Workspace's state, never an uncaught 500.

### 8.5 Direct test coverage requirement

`tests/Feature/Entitlement/EntitlementManagerPresentationTest.php` (new — see §19, strengthened in Round 2) exercises all three methods directly, independent of any HTTP surface. For `decideAvailableFeaturesForBusiness()` specifically, at minimum:

- **A — entitled, no toggle:** `decision.allowed === true`, `disablePreferenceRecorded === false`.
- **B — entitled feature, stored toggle:** `decision.reason === 'disabled_for_business'`, `disablePreferenceRecorded === true`.
- **C — stored toggle, then a Workspace `deny` override is added (the critical proof):** `decision.reason === 'denied_by_workspace_override'`, `disablePreferenceRecorded === true` — direct proof that preference state is never inferred from the effective decision, since the decision's denial reason changed while the stored preference did not.
- **D — no toggle, effective denial for any reason:** `disablePreferenceRecorded === false`.
- The existing override-outside-base-plan proof, the `Planned`-keys-absent proof, and the stale/reassigned-Business-returns-`[]` proof — all unchanged from Round 1, now asserted against the corrected shape (`result[$key]['decision']`, not `result[$key]` directly).

---

## 9. Admin authorization model — unchanged

Behind the existing `EnsureUserIsAdministrator` middleware, unmodified. Every new admin controller action additionally calls `$this->authorize('view workspace plans')` or `$this->authorize('manage workspace plans')` as its first line. `EntitlementManager`'s own mutation methods independently re-check `assertPlatformAdministrator()` regardless of what the controller already checked (§20 of the RFC) — unmodified, pre-existing M2 behavior.

---

## 10. Exact permission keys/category — unchanged

```php
'view workspace plans' => [
    'display_name' => 'read',
    'category'     => 'Workspace Plans',
],
'manage workspace plans' => [
    'display_name' => 'update',
    'category'     => 'Workspace Plans',
],
```

Confirmed sufficient against repository precedent (`'view subscription'`/`'manage subscription'`, `'view business'`/`'edit business'`). `view workspace plans` gates every new read/inspection surface; `manage workspace plans` gates every new mutation action.

---

## 11. Exact admin routes, methods, controller actions and FormRequests (corrected for Blockers 6 and 7)

**Blocker 6 — route-file declaration vs. resolved name/URL, stated exactly once here and applied consistently below:** `routes/admin.php` is wrapped by `RouteServiceProvider` with `->prefix(config('app.admin_path'))->as('admin.')` (§3.6). Every route below is declared in `routes/admin.php` **with no literal `admin/` segment and no `admin.` name prefix in the file itself** — exactly matching the existing `workspaces.index`/`workspaces.show` declarations. The **resolved** external name (used by `route()`, redirects, and tests) and the **resolved** external URL (prefixed with whatever `admin_path` currently resolves to) both gain their `admin`/`admin.` prefix automatically from the provider — never written into the route file by hand.

**New controller: `App\Http\Controllers\Admin\WorkspacePlanCatalogController`** (read-only, global — not Workspace-scoped):

| Method | File-declared name | File-declared URI | Resolved name | Permission |
|---|---|---|---|---|
| `index()` | `workspace-plan-catalog.index` | `workspace-plan-catalog` | `admin.workspace-plan-catalog.index` | `view workspace plans` |

Calls `EntitlementManager::listPlanCatalogSummaries()` (§8.2) — no repository call in the controller. No FormRequest (GET-only, no input).

**New controller: `App\Http\Controllers\Admin\WorkspaceEntitlementController`** (mutations, Workspace-scoped via `Workspace $workspace` route-model-binding on `uid`, `->whereUuid('workspace')` — **unchanged, existing convention, not modified by this correction**):

| Method | File-declared name | File-declared URI | Resolved name | `EntitlementManager` call | FormRequest |
|---|---|---|---|---|---|
| `assignPlan()` | `workspaces.plan.assign` | `workspaces/{workspace}/plan` (POST) | `admin.workspaces.plan.assign` | `assignFirstPlan()` | `AssignWorkspacePlanRequest` |
| `changePlan()` | `workspaces.plan.change` | `workspaces/{workspace}/plan/change` (POST) | `admin.workspaces.plan.change` | `changePlan()` | `ChangeWorkspacePlanRequest` |
| `changeStatus()` | `workspaces.plan.status` | `workspaces/{workspace}/plan/status` (POST) | `admin.workspaces.plan.status` | `changePlanStatus()` | `ChangeWorkspacePlanStatusRequest` |
| `grantComplimentary()` | `workspaces.plan.complimentary.grant` | `workspaces/{workspace}/plan/complimentary` (POST) | `admin.workspaces.plan.complimentary.grant` | `grantComplimentaryStatus()` | `GrantWorkspaceComplimentaryStatusRequest` |
| `revokeComplimentary()` | `workspaces.plan.complimentary.revoke` | `workspaces/{workspace}/plan/complimentary` (DELETE) | `admin.workspaces.plan.complimentary.revoke` | `revokeComplimentaryStatus()` | `RevokeWorkspaceComplimentaryStatusRequest` |
| `updateAdditionalSlots()` | `workspaces.plan.additional-slots` | `workspaces/{workspace}/plan/additional-slots` (POST) | `admin.workspaces.plan.additional-slots` | `setAdditionalBusinessSlots()` | `UpdateWorkspaceAdditionalSlotsRequest` |
| `storeOverride()` | `workspaces.entitlement-overrides.store` | `workspaces/{workspace}/entitlement-overrides` (POST) | `admin.workspaces.entitlement-overrides.store` | `createOrChangeOverride()` | `StoreWorkspaceEntitlementOverrideRequest` |
| `revertOverride()` | `workspaces.entitlement-overrides.revert` | `workspaces/{workspace}/entitlement-overrides/{featureKey}` (DELETE) | `admin.workspaces.entitlement-overrides.revert` | `revertOverride()` | none (route param) |

All 8 actions: `$this->authorize('manage workspace plans');` first line, delegate to the appropriate `EntitlementManager` method with `Auth::id()` as the actor, map every typed exception per §16, then `redirect()->route('admin.workspaces.show', $workspace)->with([...])` on success.

**`revertOverride()`'s `{featureKey}` route parameter, exact rule (Blocker 4):** before calling `EntitlementManager::revertOverride()`, the controller checks `PlatformFeature::tryFrom($featureKey)`; if `null`, `abort(404)` — never reaches `EntitlementManager` with an unvalidated string.

**FormRequest validation rules** (all `authorize(): bool { return $this->user() !== null; }`, matching the RFC-00x convention — permission gating stays in the controller):

- `AssignWorkspacePlanRequest`: `tier` (`required`, `new Enum(WorkspacePlanTier::class)`), `reason` (`required|string`), `is_complimentary` (`sometimes|boolean`), `additional_business_slots` (`sometimes|integer|in:0,1,2`).
- `ChangeWorkspacePlanRequest`: `tier` (`required`, `new Enum(WorkspacePlanTier::class)`), `reason` (`nullable|string`), `additional_business_slots` (`nullable|integer|in:0,1,2`).
- `ChangeWorkspacePlanStatusRequest`: `status` (`required`, `new Enum(WorkspacePlanAssignmentStatus::class)`), `reason` (`required|string`).
- `GrantWorkspaceComplimentaryStatusRequest`: `reason` (`required|string`).
- `RevokeWorkspaceComplimentaryStatusRequest`: `reason` (`nullable|string`).
- `UpdateWorkspaceAdditionalSlotsRequest`: `additional_business_slots` (`required|integer|in:0,1,2`), `reason` (`nullable|string`).
- `StoreWorkspaceEntitlementOverrideRequest`: `feature_key` (`required`, `new Enum(PlatformFeature::class)`), `state` (`required`, `new Enum(WorkspaceEntitlementOverrideState::class)`), `reason` (`required|string`).

**Blocker 7, resolved exactly:** `feature_key` validation uses Laravel's built-in `Illuminate\Validation\Rules\Enum` rule directly — `new Enum(PlatformFeature::class)` — since `PlatformFeature` is already a string-backed enum. **No new custom `Rule` class is authorized or added anywhere in this contract.**

**Enum-instance conversion, locked exactly (Correction Round 2):** Laravel's `Enum` validation rule validates that the *request input* is a valid case value — it does not change what `$request->validated(...)` returns (still the raw scalar). Every `EntitlementManager` mutation method is strongly typed on the real enum instance (`WorkspacePlanTier`, `WorkspacePlanAssignmentStatus`, `PlatformFeature`, `WorkspaceEntitlementOverrideState` — never a plain string), so each controller action must convert before delegating. Locked exactly, no other conversion is authorized:

```php
// AssignWorkspacePlanRequest / ChangeWorkspacePlanRequest:
WorkspacePlanTier::from($request->validated('tier'))

// ChangeWorkspacePlanStatusRequest:
WorkspacePlanAssignmentStatus::from($request->validated('status'))

// StoreWorkspaceEntitlementOverrideRequest:
PlatformFeature::from($request->validated('feature_key'))
WorkspaceEntitlementOverrideState::from($request->validated('state'))

// AssignWorkspacePlanRequest's optional is_complimentary — a safe boolean
// conversion, never a raw HTML string:
$request->boolean('is_complimentary')
```

`::from()`, never `::tryFrom()`, is correct at every one of these call sites — the `Enum` validation rule has already guaranteed the value is a valid case by the time `$request->validated()` is reached, so a `::from()` failure here would indicate a framework-level defect, not a normal user-input path (unlike the route-parameter `PlatformFeature::tryFrom()` checks below, which validate raw, unvalidated route segments and must handle `null`).

**`additional_business_slots` scalar conversion, locked exactly (residual fix):** Laravel's `integer` validation rule validates that the *request value* is numeric — it does not itself coerce what `$request->validated(...)` returns into a PHP `int`, and `assignFirstPlan()`'s `int $additionalBusinessSlots = 0` parameter does **not** accept an explicit `null` (unlike `changePlan()`'s `?int $additionalBusinessSlots = null`, where `null` is a real, distinct domain meaning — §17.1 of the RFC, "Agency→Core/Growth... defaults to `0`... caller may optionally pass `$additionalBusinessSlots`"). Each of the three affected controller actions converts differently, exactly as follows — no other conversion is authorized:

```php
// AssignWorkspacePlanRequest — the field is optional (sometimes) and
// assignFirstPlan()'s parameter is a non-nullable int with its own default;
// an absent input must become the semantic int 0 before delegation, never
// passed as null:
$additionalBusinessSlots = (int) $request->validated('additional_business_slots', 0);
// passed as: assignFirstPlan(..., additionalBusinessSlots: $additionalBusinessSlots)

// ChangeWorkspacePlanRequest — the field is nullable and changePlan()'s
// parameter is genuinely ?int; null must be preserved, never coerced to 0:
$additionalBusinessSlots = $request->validated('additional_business_slots');
if ($additionalBusinessSlots !== null) {
    $additionalBusinessSlots = (int) $additionalBusinessSlots;
}
// passed as: changePlan(..., additionalBusinessSlots: $additionalBusinessSlots)  // int|null

// UpdateWorkspaceAdditionalSlotsRequest — the field is required, so no
// absent/null case exists, only the scalar-type coercion:
$additionalBusinessSlots = (int) $request->validated('additional_business_slots');
// passed as: setAdditionalBusinessSlots($workspace, $additionalBusinessSlots, ...)
```

These three conversions exist because `$request->validated()` returns whatever scalar shape the submitted form data actually had (commonly a numeric string from an HTML `<input>`, e.g. `"1"`) — the `integer` validation rule confirms it is numeric, it does not change its PHP type. No FormRequest rule is changed by this fix.

**`revertOverride()`'s `{featureKey}` route parameter, exact rule (Blocker 4), with the exact conversion locked:** `$feature = PlatformFeature::tryFrom($featureKey); if ($feature === null) { abort(404); }` — never reaches `EntitlementManager` with an unvalidated string; once resolved, `$feature` (the enum instance, not the raw route string) is passed to `EntitlementManager::revertOverride()`.

**Existing admin `WorkspaceController@show`** (modified) is enriched, when the acting admin's session also passes `Gate::allows('view workspace plans')` (a conditional check, never `$this->authorize()`, so a Workspace-only admin without the new permission still sees the unmodified existing page):
- `EntitlementManager::getWorkspaceEntitlementSummary($workspace)`'s full result (§8.3), rendered directly.
- An optional effective-entitlement explanation block: when the request supplies both `business_uid` and `feature_key` query parameters. `$feature = PlatformFeature::tryFrom($featureKey)`; `null` → `abort(404)` (Blocker 4's unknown-feature-key rule, applied identically here). `business_uid` resolves against the Workspace's already-eager-loaded `businesses`; if it doesn't match any of them, `abort(404)` (an addressability failure, same family as Blocker 4's stale-Business rule). Only once both resolve does the controller call `EntitlementManager::decide($workspace, $business, $feature->value, Auth::id())` — passing `$feature->value` (the stable **string** key), not the enum instance, since `decide()`'s own signature is `string $featureKey` (RFC-004 §20's one deliberate string boundary, unchanged by M3) — and render the resulting `EntitlementDecision` directly.

---

## 12. Exact customer routes, methods, controller actions and FormRequests (corrected for Blockers 2, 3, 4)

**Existing controller: `App\Http\Controllers\Customer\Workspace\WorkspaceController`** is modified to inject `EntitlementManager` and gains exactly two new actions:

| Method | Route name | Verb + URI |
|---|---|---|
| `disableBusinessFeature()` | `customer.workspaces.businesses.features.disable` | `POST {workspaceUid}/businesses/{businessUid}/features/{featureKey}/disable` |
| `enableBusinessFeature()` | `customer.workspaces.businesses.features.enable` | `POST {workspaceUid}/businesses/{businessUid}/features/{featureKey}/enable` |

Nested under the existing `workspaces.` route group, matching the established `{noun}Uid`/`{resource}.{subresource}.{action}` convention. No FormRequest.

**Blocker 4 — exact, canonical rule for both actions, resolving the original draft's §14-vs-§19 self-contradiction:**

```text
1. $feature = PlatformFeature::tryFrom($featureKey); if null      => abort(404)
2. resolveWorkspaceBusiness() cannot find the Business             => abort(404)   // existing addressability helper, unchanged
3. EntitlementManager::disableBusinessFeature($business, $feature, Auth::id())
   / enableBusinessFeature($business, $feature, Auth::id()) called — note $feature
   is the resolved enum instance from step 1, never the raw route string:
   - WorkspaceBusinessNotFoundException                       => abort(404)
   - BusinessWorkspaceMismatchException                       => abort(404)
   - UnauthorizedWorkspaceManagementException                 => flash_error, redirect()->back()
   - InactiveWorkspaceMutationException                       => flash_error, redirect()->back()
   - RuntimeException ("not currently entitled", disable only) => flash_error, redirect()->back()
4. success                                                     => flash_success, redirect()->route('customer.workspaces.show', $workspaceUid)
```

**Rationale, stated once, applied everywhere in this contract that touches this exact question (§11's `revertOverride`/explanation block deliberately mirror it):** `WorkspaceBusinessNotFoundException` and `BusinessWorkspaceMismatchException` are **addressability/stale-target failures** — the URL no longer names a real, current (Business, Workspace) pair, the same category as an unknown uid, and are therefore `404`, never a distinguishing flash message (which would otherwise let a crafted request probe for whether a Business exists/moved). `UnauthorizedWorkspaceManagementException` and `InactiveWorkspaceMutationException` occur only *after* the actor is already known to be addressing a real, current pair — they are authority/state failures for an otherwise-visible target, so they get the existing "visibility-authorized, mutation-denied" `flash_error` treatment, matching `rename()`/`deactivate()`'s established pattern exactly. There is no ambiguity left for the implementer to resolve.

**Existing `WorkspaceController@show`** is modified to add exactly one new top-level view-data key, `'entitlement'`, **present only for Owner/active-Admin** (Blocker 2 — see below):

```php
'entitlement' => [
    'summary' => EntitlementManager::getWorkspaceEntitlementSummary($workspace),   // §8.3, rendered directly
    'features' => [                                                                // per-Business effective decisions + preference state
        $businessUid => EntitlementManager::decideAvailableFeaturesForBusiness($workspace, $business, Auth::id()),
        // §8.4's corrected Round 2 shape: array<string, array{decision: EntitlementDecision, disablePreferenceRecorded: bool}>
        // ... one entry per Business already loaded for this role
    ],
],
```

**Blocker 2 — exact, corrected visibility rule:**

```text
Owner / active Admin (existing effectiveRoleKey() in {'owner', 'admin'}):
    - existing data (workspace, businesses, directory, manageableBusinesses) — unchanged
    - + 'entitlement' key (summary + per-Business features, §8.3/§8.4)
    - + the two toggle-mutation controls (§13), rendered in the Businesses card

Staff (effectiveRoleKey() === 'staff'):
    - existing data only (workspace, businesses) — unchanged, byte-for-byte
    - NO 'entitlement' top-level key at all — not an empty array, the key itself is absent,
      matching the existing 'directory'/'manageableBusinesses' omission-for-Staff precedent exactly
    - NO plan/capacity/feature display of any kind
    - NO toggle controls of any kind
```

This reuses the controller's **already-resolved** `effectiveRoleKey()` purely for view-data inclusion — no new authorization algorithm is introduced. Mutation authority remains independently re-checked by `EntitlementManager::disableBusinessFeature()`/`enableBusinessFeature()` regardless of what the view chose to render (§20 of the RFC — every mutating method re-checks even when the caller already checked), exactly as the original draft already established for the mutation path; this correction only narrows *display*.

---

## 13. Toggle UX — exact wording and behavior (resolves Blocker 5; action visibility corrected in Round 2)

The Business feature toggle mutation ships (RFC §16 requires the control; M2 already built the real, persistent, disable-only preference mechanism) — but because §5 confirms the legacy CRM/Conversations/Automations modules do not consult it, **the customer view must never present it as a working runtime on/off switch.**

**Two separate facts are shown per (Business, feature) row, each sourced from a distinct field of §8.4's corrected `decideAvailableFeaturesForBusiness()` result — never conflated:**

**A. Effective entitlement state** — derived only from `result[$key]['decision']`: rendered as the plain allow/deny fact (and, when denied, the stable `decision.reason` key, e.g. `not_entitled_by_plan`/`denied_by_workspace_override`/`plan_suspended`), informational only, no control attached to this half of the row.

**B. Platform feature preference state** — derived only from `result[$key]['disablePreferenceRecorded']`:
- Section label: **"Platform feature preference"** (never "Feature settings," never anything implying live control).
- State display, exactly one of two fixed strings: **"Disable preference recorded"** (when `disablePreferenceRecorded === true`) or **"No disable preference recorded"** (when `false`) — never a generic on/off toggle switch styled to look like a live setting.

**Exact mutation-control visibility rule, corrected in Round 2 — never decided from `decision.reason` alone:**

```text
1. disablePreferenceRecorded === true
   -> show "Disable preference recorded" + action "Remove disable preference"
      (submits to enableBusinessFeature()).
      Shown even when decision.allowed === false for an unrelated reason
      (denied_by_workspace_override, plan_inactive, plan_suspended, etc.) —
      the persisted preference row still exists regardless of why the
      effective decision is currently denied, and enableBusinessFeature()
      is the real, only operation that removes it (§8.4's proof C).

2. disablePreferenceRecorded === false AND decision.allowed === true
   -> show "No disable preference recorded" + action "Record disable preference"
      (submits to disableBusinessFeature()).

3. disablePreferenceRecorded === false AND decision.allowed === false
   -> show "No disable preference recorded", NO action rendered.
      A Business may only acquire a new disable preference when it is
      currently effectively entitled — mirrors disableBusinessFeature()'s
      own existing M2 authority (it throws when the feature is not
      currently entitled, §16 of this contract's §12 exception table);
      offering the action here would just surface that same rejection
      after a click, so it is never rendered in the first place.
```

- A **prominent, fixed, unconditional notice**, rendered immediately adjacent to every currently-`Available` feature's row (today: `crm`, `conversations`, `automations` — never hard-coded as those three literal strings; driven by `PlatformFeatureRegistry::isAvailable()` exactly as §8.4 already is), regardless of which of the three visibility cases above applies: **"Runtime enforcement pending. This preference is stored at the Business level but the legacy module does not yet consult it."**
- Action button wording, exactly as named in the visibility rule above: **"Record disable preference"** / **"Remove disable preference"** — never "Disable"/"Enable" alone.
- The route names and controller method names remain `disableBusinessFeature()`/`enableBusinessFeature()`/`.features.disable`/`.features.enable` — existing RFC-004 §16/M2 domain method names, not renamed; only the **customer-facing copy** changes.
- For a `Planned` feature (not currently `Available`), no row is rendered at all (§8.4 never includes it in the returned map) — matching §6's existing "shown as planned, never toggleable" rule.

**Test requirement (§19, strengthened in Round 2):** the enforcement-pending notice is present whenever an `Available` feature's row is shown, regardless of visibility case; the rendered HTML never contains "Feature enabled"/"Feature disabled"/a bare "On"/"Off" label/an unlabeled toggle switch; case 1's "Remove disable preference" action renders even when the effective decision is currently denied for an unrelated reason (the direct Blocker/Round-2 proof); case 3's "Record disable preference" action never renders when there is no stored preference and the effective decision is denied.

---

## 14. Exact view behavior

**`resources/views/customer/workspaces/show.blade.php`** (modified): one new "Plan & Capacity" card (Owner/active-Admin only, §12), sourced directly from `entitlement.summary`, never independently computed in Blade. Per-Business feature rows (§13) inside the existing Businesses card, each showing the effective entitlement state (fact A, read from `result[$key]['decision']`, informational, no control) alongside its "Platform feature preference" state and — only per §13's exact 3-case rule — a mutation control, each control a plain `<form method="POST">…@csrf…<button type="submit">` — matching this page's existing plain-form-per-row convention, never the AJAX/DataTables `<x-switch-toggle>` idiom. Staff sees none of this — the `@isset($entitlement)`-gated block is entirely absent from the rendered HTML for Staff, matching `@isset($directory)`'s existing precedent.

**`resources/views/admin/workspaces/show.blade.php`** (modified): "Plan & Entitlement" card (`@can('view workspace plans')`) showing `getWorkspaceEntitlementSummary()`'s data plus the explanation form; "Mutate Plan" card (`@can('manage workspace plans')`) hosting the 8 mutation forms, matching `admin/opportunities/show.blade.php`'s plain-form precedent. Both are new, separately-`id`'d sections, distinct from the existing `#admin-workspace-show` section (§19).

**`resources/views/admin/workspace-plan-catalog/index.blade.php`** (new): plain read-only table, sourced from `listPlanCatalogSummaries()`, matching `admin/workspaces/index.blade.php`'s existing card/table structure. No mutation controls.

---

## 15. Exact `EntitlementManager` calls used by every surface/action

| Surface/action | `EntitlementManager` call |
|---|---|
| Admin catalog index | `listPlanCatalogSummaries()` (§8.2) |
| Admin Workspace show (plan/capacity block) | `getWorkspaceEntitlementSummary()` (§8.3) |
| Admin Workspace show (explanation block) | `decide()` |
| Admin assign plan | `assignFirstPlan()` |
| Admin change plan | `changePlan()` |
| Admin change status | `changePlanStatus()` |
| Admin grant complimentary | `grantComplimentaryStatus()` |
| Admin revoke complimentary | `revokeComplimentaryStatus()` |
| Admin update additional slots | `setAdditionalBusinessSlots()` |
| Admin store override | `createOrChangeOverride()` |
| Admin revert override | `revertOverride()` |
| Customer Workspace show (plan/capacity/feature block, Owner/active-Admin only) | `getWorkspaceEntitlementSummary()` (§8.3), `decideAvailableFeaturesForBusiness()` (§8.4, one call per shown Business) |
| Customer disable Business feature | `disableBusinessFeature()` |
| Customer enable Business feature | `enableBusinessFeature()` |

No surface calls any other `EntitlementManager` method, and no surface reads any of the six repositories directly (§8).

---

## 16. Exact exception-to-HTTP/redirect behavior (corrected for Blocker 4)

**Admin mutation actions** (`WorkspaceEntitlementController`'s 8 actions): every typed `App\Exceptions\Entitlement\*` exception and `InvalidArgumentException` (reason/pairing validation) mapped to a **specific, human-readable** `flash_error` message, never the raw exception message text, matching `OpportunityController`'s `safeErrorRedirect()` precedent. `WorkspaceNotFoundException` → `abort(404)`.

**Customer Business feature toggle actions:** exactly the table in §12 — `WorkspaceBusinessNotFoundException`/`BusinessWorkspaceMismatchException` → `abort(404)`; `UnauthorizedWorkspaceManagementException`/`InactiveWorkspaceMutationException`/the "not currently entitled" `RuntimeException` → `flash_error` + `redirect()->back()`. **This is the single canonical rule for this action pair — no other section of this contract states or implies a different mapping for the same exceptions on the same actions.**

**Unknown `featureKey`, exact rule, locked identically for all three call sites (Blocker 4):** `PlatformFeature::tryFrom($featureKey) === null` → `abort(404)`, checked **before** calling into `EntitlementManager` at all, for:
- customer `disableBusinessFeature()`/`enableBusinessFeature()` route actions (§12);
- admin `revertOverride()` route action (§11);
- admin effective-entitlement explanation query (§11).

**Customer `storeBusiness()`/`reassignBusiness()` (existing actions, closing the §3.7 gap):** add `catch` clauses for `WorkspacePlanUnassignedException`, `InactiveWorkspacePlanException`, `SuspendedWorkspacePlanException`, `BusinessSlotAllocationRequiredException`, `BusinessSlotLimitExceededException` — each mapped to a specific `flash_error` message, `redirect()->back()` (matching the existing `InactiveWorkspaceMutationException` catch clause already present in both actions).

**Customer `show()`:** no new try/catch is added to `show()` itself — the one place a stale-Business race could otherwise surface (`decideAvailableFeaturesForBusiness()`) already handles it internally per §8.4's exact rule, returning an empty per-Business decision map rather than throwing into the controller.

---

## 17. Compatibility/catch-up decision for unassigned Workspaces — unchanged

No compatibility catch-up mechanism is introduced. `WorkspaceEntitlementBackfillV1`/`workspaces:backfill-entitlements` is not re-run, not scheduled, and not otherwise re-invoked. Ordinarily-unassigned Workspaces are RFC §17.5's own explicit, deliberate design. §16's new exception-to-flash mapping makes the existing fail-closed behavior gracefully visible; the admin's `assignPlan()` action (§11) is the resolution path. No RFC wording correction required.

---

## 18. Catalog pricing mutation — explicitly out of scope, unchanged

RFC §22's admin bullet list requires catalog **inspection** only; catalog price/currency *mutation* is never listed among its enumerated minimum admin actions. `EntitlementManager::updateCatalogPricing()` remains unexposed by any route, controller action, or FormRequest in this contract.

---

## 19. Exact implementation path allowlist (recalculated)

**Production — new (12, was 10; +2 for the new value objects):**
1. `app/Http/Controllers/Admin/WorkspacePlanCatalogController.php`
2. `app/Http/Controllers/Admin/WorkspaceEntitlementController.php`
3. `app/Http/Requests/Admin/Workspace/AssignWorkspacePlanRequest.php`
4. `app/Http/Requests/Admin/Workspace/ChangeWorkspacePlanRequest.php`
5. `app/Http/Requests/Admin/Workspace/ChangeWorkspacePlanStatusRequest.php`
6. `app/Http/Requests/Admin/Workspace/GrantWorkspaceComplimentaryStatusRequest.php`
7. `app/Http/Requests/Admin/Workspace/RevokeWorkspaceComplimentaryStatusRequest.php`
8. `app/Http/Requests/Admin/Workspace/UpdateWorkspaceAdditionalSlotsRequest.php`
9. `app/Http/Requests/Admin/Workspace/StoreWorkspaceEntitlementOverrideRequest.php`
10. `resources/views/admin/workspace-plan-catalog/index.blade.php`
11. `app/Library/Entitlement/WorkspacePlanCatalogSummary.php` **(new — §8.1)**
12. `app/Library/Entitlement/WorkspaceEntitlementSummary.php` **(new — §8.1)**

**Production — modified (8, was 7; +1 for `EntitlementManager.php` itself):**
13. `app/Http/Controllers/Admin/WorkspaceController.php`
14. `resources/views/admin/workspaces/show.blade.php`
15. `routes/admin.php`
16. `config/permissions.php`
17. `app/Http/Controllers/Customer/Workspace/WorkspaceController.php`
18. `resources/views/customer/workspaces/show.blade.php`
19. `routes/customer.php`
20. `app/Library/Entitlement/EntitlementManager.php` **(modified — §8.2/§8.3/§8.4, three new public methods, no existing method signature changed)**

**Test — new (4, was 3; +1 for direct `EntitlementManager` presentation-method coverage):**
21. `tests/Feature/Workspace/AdminWorkspacePlanCatalogControllerTest.php`
22. `tests/Feature/Workspace/AdminWorkspaceEntitlementControllerTest.php`
23. `tests/Feature/Workspace/WorkspaceBusinessFeatureToggleHttpTest.php`
24. `tests/Feature/Entitlement/EntitlementManagerPresentationTest.php` **(new — §8.5)**

**Test — modified (4, unchanged count from the original draft):**
25. `tests/Feature/Workspace/AdminWorkspaceControllerTest.php`
26. `tests/Feature/Workspace/WorkspaceOverviewHttpTest.php`
27. `tests/Feature/Workspace/WorkspaceBusinessCreationHttpTest.php`
28. `tests/Feature/Workspace/WorkspaceBusinessReassignmentHttpTest.php`

**Total: 28 paths (20 production: 12 new + 8 modified; 8 test: 4 new + 4 modified) — unchanged from Correction Round 1.** Net change versus the original 24-path list (established in Round 1, re-confirmed unchanged in Round 2): **+4** — `app/Library/Entitlement/WorkspacePlanCatalogSummary.php`, `app/Library/Entitlement/WorkspaceEntitlementSummary.php`, `app/Library/Entitlement/EntitlementManager.php` (modified — path #20, unchanged path, its algorithm at §8.4 corrected in Round 2), `tests/Feature/Entitlement/EntitlementManagerPresentationTest.php`. **Round 2's correction to `decideAvailableFeaturesForBusiness()`'s returned shape (§8.4) added zero paths** — it changed the method's internal algorithm and PHPDoc-typed return shape inside the already-authorized `EntitlementManager.php` (path #20) and the already-authorized `EntitlementManagerPresentationTest.php` (path #24), using a plain array shape rather than a third value-object class, exactly as this round required. No path was removed from either list; every one of the original 24 remains, several with corrected internal behavior (§8/§11/§12/§13/§14/§16). **No repository file, no repository method, and no new custom validation `Rule` class is authorized anywhere in this allowlist.** No wildcard scope. If implementation genuinely needs a 29th path, the implementer stops and reports it — never adds it silently. **The original draft's "implementation may discover and add one later" escape hatch remains removed** — this contract's scope is exact and final as of this correction round.

---

## 20. Explicit forbidden paths/categories

- Any file under `app/Http/Controllers/Customer/ContactsController.php`, `ChatBoxController.php`, `AutomationsController.php`, their models, repositories, or jobs.
- Any migration of any kind.
- `AI-AUTONOMY-STATE.json`.
- Any of the six existing entitlement repository contracts or Eloquent implementations, or any new repository file — **§8 proves none is needed; this is now an absolute prohibition, not a conditional one.**
- `app/Library/Workspace/WorkspaceManager.php`, `app/Library/Business/BusinessManager.php` (no new call sites into these).
- `PlatformFeatureRegistry.php`, `PlatformFeature.php` (no availability changes).
- Any RFC-005/billing/wallet/Stripe file.
- `WorkspaceEntitlementBackfillV1.php`, `BackfillWorkspaceEntitlementsCommand.php`, their existing tests, or the M1 seed/backfill migrations.
- The legacy `plans`/`subscriptions` stack.
- Any tenancy/ownership/`business_access_scope` change of any kind.
- Any new custom Laravel validation `Rule` class (§11 — `new Enum(...)` only).
- `docs/rfcs/RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS.md`, `docs/automation/RFC-004-M1-CONTRACT.md`, `docs/automation/RFC-004-M2-CONTRACT.md` (no correction found to be required).

---

## 21. Required focused test files and behavioral cases

**`EntitlementManagerPresentationTest.php` (new, §8.5):** `listPlanCatalogSummaries()` returns exactly 3 entries in Core/Growth/Agency order with the exact seeded structural fields and correct `featureAvailability` per key; `getWorkspaceEntitlementSummary()` covers unassigned, assigned-active, assigned-complimentary, assigned-with-overrides, and confirms `capacity` matches a direct `decideBusinessSlotCapacity()` call for the same Workspace; `decideAvailableFeaturesForBusiness()` proves, against the corrected `array{decision, disablePreferenceRecorded}` shape: (a) a feature outside the base plan but present via an `allow` override is still returned (the Blocker 3 proof); (b) a `Planned` feature is never present in the returned map regardless of plan packaging; (c) a concurrently-reassigned/deleted Business yields an empty map rather than a thrown exception or another Workspace's decision; (d) case A — entitled, no toggle: `decision.allowed === true`, `disablePreferenceRecorded === false`; (e) case B — entitled feature, stored toggle: `decision.reason === 'disabled_for_business'`, `disablePreferenceRecorded === true`; (f) case C, the critical proof — a stored toggle followed by a Workspace `deny` override: `decision.reason === 'denied_by_workspace_override'` while `disablePreferenceRecorded` remains `true`, directly proving preference state is never inferred from the effective decision; (g) case D — no toggle, effective denial for any reason: `disablePreferenceRecorded === false`.

**`AdminWorkspacePlanCatalogControllerTest.php`:** route exists GET-only; the existing four-tier admin authority matrix; fully-authorized admin → 200, exact 3-tier catalog data rendered via `listPlanCatalogSummaries()`, no mutation control present.

**`AdminWorkspaceEntitlementControllerTest.php`:** the same authority matrix per representative action; happy path per action; missing/empty `reason` where required → validation failure, no mutation; each typed exception → the correct specific `flash_error`, no partial mutation; unknown Workspace uid → 404; `revertOverride()` with an unknown `featureKey` → 404 before `EntitlementManager` is ever called (Blocker 4). **Residual scalar-conversion proofs (no new test file — added to this already-authorized file):**
1. `assignPlan()` submitted with `additional_business_slots` entirely omitted → the assignment persists with `additional_business_slots = 0` (the semantic default, never `null`) and the request completes with no `TypeError`.
2. `assignPlan()` submitted with `additional_business_slots` as the HTML-form string `"1"` → the assignment persists with the real integer `1`, not the string `"1"`.
3. `changePlan()` submitted with `additional_business_slots` entirely omitted → `null` is preserved and passed through to `EntitlementManager::changePlan()`, exercising that method's own existing per-direction normalization (§17.1 of the RFC) rather than being coerced to `0` by the controller.
4. `updateAdditionalSlots()` submitted with `additional_business_slots` as the HTML-form string `"1"` → `EntitlementManager::setAdditionalBusinessSlots()` receives/persists the real integer `1`.

**`WorkspaceBusinessFeatureToggleHttpTest.php`:** route shape; guest → 401; owner/active-Admin can record/remove a disable preference for a plan-entitled feature; Staff cannot; inactive Admin cannot; the enforcement-pending notice is present in the rendered response for `crm`/`conversations`/`automations` and the response contains none of the disallowed live-control phrases (§13); an unknown `featureKey` → 404 before any `EntitlementManager` call; a stale/moved Business → 404 (not `flash_error` — the exact Blocker 4 proof); an unauthorized-but-visible actor → `flash_error` + redirect back (not 404). **Strengthened in Round 2, direct §13 visibility-rule proofs on the rendered `show()` page:** a Business with a persisted disable preference for a feature that is *also* currently denied by a Workspace `deny` override still renders "Remove disable preference" (§13 case 1, never hidden by the unrelated denial); a Business with no persisted preference and a currently-denied feature (e.g. `plan_suspended`) renders "No disable preference recorded" and does **not** render "Record disable preference" anywhere in the response (§13 case 3).

**`AdminWorkspaceControllerTest.php` (modified):** `test_no_workspace_mutation_route_exists` replaced with an exact 11-route canonical list (2 original + 1 catalog + 8 Workspace-entitlement — note `workspace-plan-catalog.index` is *not* itself an `admin.workspaces.*`-named route, so the count of `admin.workspaces.*`-named routes specifically is 2 + 8 = 10, stated exactly in the test, not approximated); `test_rendered_views_contain_no_mutation_controls` rescoped to the original `#admin-workspace-show` section only, with a new, separate assertion proving the two new entitlement cards *do* contain the expected controls only when `manage workspace plans` is granted.

**`WorkspaceOverviewHttpTest.php` (modified):** the closed-shape key assertion for Owner/active-Admin updated to `['workspace', 'businesses', 'entitlement', 'directory', 'manageableBusinesses']`; a **separate, explicit** assertion that Staff's view-data key set remains exactly `['workspace', 'businesses']` — i.e. `'entitlement'` is asserted **absent**, not merely unused, for Staff (the direct Blocker 2 regression proof); `entitlement.summary.capacity` matches a direct `decideBusinessSlotCapacity()` call; an unassigned Workspace's `entitlement.summary` renders an `isAssigned: false` state without throwing.

**`WorkspaceBusinessCreationHttpTest.php`/`WorkspaceBusinessReassignmentHttpTest.php` (modified):** the existing `withoutExceptionHandling()`-based "propagates uncaught" test replaced with a test proving the exception is now **caught** and mapped to the correct specific `flash_error` message, with zero side effects.

**Opportunity regression:** still not added — this contract touches no Opportunity/Prospect-Outreach code path.

---

## 22. Formal human regression gates — unchanged

```bash
php artisan test tests/Unit/Entitlement tests/Feature/Entitlement
php artisan test tests/Unit/Workspace tests/Feature/Workspace
php artisan test tests/Unit/Business tests/Feature/Business
php artisan test --stop-on-failure
```

Run by the human's local PHP environment. No test count is fabricated by the implementer.

---

## 23. Scope verification commands

```bash
git diff --check
git diff --name-only
git status --short
git diff --cached --name-only
```

Expected: exactly the 28 paths in §19, nothing staged. A grep for `EntitlementManager|decideBusinessSlotCapacity|->decide(` outside `app/Http/Controllers/Admin/Workspace*Controller.php`, `app/Http/Controllers/Customer/Workspace/WorkspaceController.php`, the six existing repositories, and `EntitlementManager.php` itself should return zero matches. **A separate grep for any of the six repository class names (`WorkspacePlanCatalogRepository`, `WorkspacePlanFeatureRepository`, `WorkspacePlanAssignmentRepository`, `WorkspaceEntitlementOverrideRepository`, `BusinessFeatureToggleRepository`, `WorkspaceEntitlementTransitionRepository`) anywhere under `app/Http` or `resources/views` must return zero matches** — the direct, mechanical proof that Blocker 1 is actually closed, not merely described as closed.

---

## 24. Completion condition

M3 is complete when: all 28 paths in §19 exist exactly as specified; every §21 test passes under the implementer's own environment or is explicitly reported as unexecuted; the four §22 regression commands are handed to the human exactly as written; the §23 verification commands (including the new repository-reference grep) show only the 28 expected paths changed and nothing staged; no §20 forbidden path was touched; §5's runtime-gating deferral and §17's no-backfill-re-run decision are both restated in the closure report as explicit, evidence-based non-actions.

---

## 25. Production activation boundary — unchanged

M3 makes the admin and customer HTTP surfaces **mergeable**, not automatically **live for every Workspace's full commercial enforcement**. Shipping M3 does not itself change what CRM/Conversations/Automations actually do at runtime; it only makes plan/capacity/override/toggle-preference administration and inspection reachable. No customer's existing, already-legitimate usage of any currently-`Available` feature is restricted by M3 shipping.

---

## 26. Governance — unchanged

- Human merge required before any implementation begins.
- Implementation requires a separate, explicit human authorization message after merge, naming `agent/rfc-004-m3` as the implementation branch.
- No tag is created at M3 (RFC §30, M4's responsibility).
- No M4 auto-start.
- No implementation auto-start after this contract merges.
- Maximum two ordinary correction rounds for this contract before a structural stop-and-report is required.
- No paid model/API credential requirement anywhere in this contract's scope.
- No force push at any point.
- No separate closure PR by default.
