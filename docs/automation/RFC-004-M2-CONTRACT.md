# RFC-004 Milestone 2 Contract

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

Human merge of this contract (bundled with the RFC-004 v1.3 narrow correction) directly authorizes one bounded M2 implementation branch — `agent/rfc-004-m2` — with no target-marker PR, no inert implementation PR, and no separate authorization PR, the same simplified workflow RFC-003 Milestone 5/6 and RFC-004 Milestone 1 already established and proved out. `start_automatically_after_contract_merge: false` and `advance_automatically: false` both hold throughout: a human still explicitly decides to start implementation after this contract merges, and nothing here triggers that start automatically.

## Governing documents

- [`docs/rfcs/RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS.md`](../rfcs/RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS.md), version **1.3** (bundled with this contract — the narrow correction to RFC-004 §17/§24/§29 is a precondition for this contract's own scope, not an independent change), read in full before drafting.
- [`docs/automation/RFC-004-M1-CONTRACT.md`](RFC-004-M1-CONTRACT.md) — M1's own precedent for structure, evidence discipline, and governance, reused exactly.
- RFC-004 M1 is complete, implemented, and merged via PR #63.

## Base/branch assumptions

- Contract-drafting branch: `chore/rfc-004-m2-contract`.
- Base/HEAD: `18f52f3fec5fcb1ed89389cf2b344b9da3c3037c` (current `main`), working tree clean.
- After this contract (and the bundled v1.3 correction) is human-merged, the M2 implementation branch (`agent/rfc-004-m2`) is created from the then-current `main` containing that merge.

---

## 1. Purpose

Implement RFC-004 v1.3 §29's Milestone 2 scope: `App\Library\Entitlement\EntitlementManager` as the sole authority for RFC-004's structural entitlement decisions and mutations — the full RFC-004 §14 decision algorithm, RFC-004 §17/§17.2/§17.3/§17.4's Business-slot-capacity enforcement integrated at **every** actual Business-count-increasing operation (not only `createBusinessInWorkspace()` — this is the exact gap the bundled v1.3 correction closes), plan assignment/change/status mutations, complimentary-status mutations, additional-slot mutations, Workspace-override mutations, Business-toggle mutations, the catalog-pricing mutation guard, the `UsageAuthorizationGateway` seam with its Null implementation, all seven actor-driven entitlement events, and the typed exception set the algorithm and mutations require. M2 introduces **no** HTTP/admin/customer surface, **no** new permission keys, **no** RFC-005 implementation, and **no** M3 work of any kind.

---

## 2. Genuine design gap this contract closes (audit finding, not discretionary scope)

Direct inspection of `app/Library/Workspace/WorkspaceManager.php`, `app/Library/Business/BusinessManager.php`, `app/Http/Controllers/Customer/Workspace/WorkspaceController.php`, `app/Repositories/Contracts/BusinessRepository.php`, and `app/Repositories/Eloquent/EloquentBusinessRepository.php`, and every actual caller of `createForCustomerInWorkspace()`, `createWorkspace()`, `createBusinessInWorkspace()`, and `reassignBusiness()`, found RFC-004 v1.2 §17/§24's "`createBusinessInWorkspace()` is the sole Business-creation orchestration entry point" statement false against the repository:

1. **`BusinessManager::applyIdentity()`** (legacy onboarding path, `app/Library/Business/BusinessManager.php:143-144`) calls `WorkspaceManager::resolveLegacyOnboardingWorkspace()` and then `BusinessRepository::createForCustomerInWorkspace()` **directly** — it never calls `createBusinessInWorkspace()`.
2. **`WorkspaceManager::createWorkspace()`** (`app/Library/Workspace/WorkspaceManager.php:161-205`) accepts an optional `WorkspaceFirstBusinessInput` and calls `createForCustomerInWorkspace()` directly when supplied.
3. **`WorkspaceController::store()`** (`app/Http/Controllers/Customer/Workspace/WorkspaceController.php:135-145`) is `createWorkspace()`'s **only** production caller, and calls it as `createWorkspace((int) Auth::id(), $request->validated('name'))` — **never** with a `WorkspaceFirstBusinessInput`. `grep -r WorkspaceFirstBusinessInput app tests` confirms exactly four files reference it: the DTO itself, `WorkspaceManager.php`, and two test files (`tests/Feature/Workspace/WorkspaceLifecycleTest.php`, `tests/Unit/Workspace/WorkspaceFirstBusinessInputTest.php`) — no controller, job, or other production caller anywhere.
4. **`WorkspaceManager::reassignBusiness()`** (`app/Library/Workspace/WorkspaceManager.php:976-1070`) increases the destination Workspace's Business-row count on a real cross-Workspace move and must therefore participate in destination-capacity enforcement.

This is exactly the class of finding RFC-004 M1's own contract's stop/gap rule anticipates for the *next* milestone's own mandatory audit — it is corrected in RFC-004 v1.3 (bundled with this contract, RFC-004 §17/§17.2-§17.5/§24/§29) before M2's own scope is locked below, not discovered and silently worked around during M2's implementation.

---

## 3. Exact M2 scope

- `App\Library\Entitlement\EntitlementManager` implementing RFC-004 v1.3 §14's full 8-step decision algorithm and §17's slot-decision API.
- The `UsageAuthorizationGateway` seam (RFC-004 §19) with its `NullUsageAuthorizationGateway` implementation, bound in `AppServiceProvider`.
- Plan assignment (§13.E below, new), the narrowly-scoped legacy-onboarding compatibility assignment (§13.D below), `changePlan()` (RFC-004 §17.1), `changePlanStatus()` (RFC-004 §18).
- Complimentary-status grant/revoke (RFC-004 §8, §13).
- `setAdditionalBusinessSlots()` (RFC-004 §17).
- Workspace-override create/change/revert (RFC-004 §15).
- Business-toggle create/remove (RFC-004 §16).
- The catalog price/currency mutation guard (RFC-004 §12.5).
- All seven actor-driven entitlement events (RFC-004 §21) and every actor-driven `workspace_entitlement_transitions` write M2 itself is responsible for (eight of the nine types — `plan_assigned` was already exercised once by M1's own backfill and is exercised again here by every actor-driven and legacy-compatibility first assignment).
- Capacity-enforcement integration at **every** actual Business-count-increasing operation identified by §2 above: `createBusinessInWorkspace()`, `reassignBusiness()` (real cross-Workspace moves only), and the legacy onboarding path (including the newly-auto-provisioned-Workspace compatibility assignment).
- Retirement of `WorkspaceFirstBusinessInput` and `createWorkspace()`'s optional third parameter (RFC-004 v1.3 §17.5 — confirmed unused by direct production-caller audit, §2 above).
- The minimal M1-repository extensions genuinely required by the above (§10 below).
- M2-scoped tests (§14 below).

## 4. Exact out-of-scope

- Any controller, route, view, or `config/permissions.php` change — those are M3 (RFC-004 §29).
- Any RFC-005 wallet/ledger/payer/Stripe/usage-billing implementation (`UsageAuthorizationGateway` ships only its Null implementation).
- Any Prospect Outreach or white-label gating/implementation.
- Any legacy `plans`/`subscriptions`/`SubscriptionLog`/`SubscriptionTransaction`/`CustomerBasedPricingPlan`/`PlanSendingCreditPrice` schema, model, or repository change.
- Any migration, unless direct implementation-time inspection proves an M1 schema defect makes M2 impossible — if so, implementation must **STOP and report**, not silently add schema.
- Any RFC-004 M3/M4 work of any kind.
- Any RFC-004 tag.
- `docs/automation/AI-AUTONOMY-STATE.json` or any other workflow/autonomy-state file.
- **Customer-facing production activation of M2's entitlement enforcement — this contract authorizes M2's code completion and merge only, never its independent production activation (§23, added in this round).**
- **Any re-run, scheduling, or invocation of the existing `workspaces:backfill-entitlements` command / `WorkspaceEntitlementBackfillV1` as an M2 activation or catch-up step** — neither is modified, re-invoked, or scheduled by this contract (§23).
- **Any automatic complimentary-plan assignment for an ordinarily-created Workspace** — RFC-004 v1.3 §17.5 already deliberately rejected this; M2 does not revisit it (§23).
- **Any new runtime feature flag, config toggle, or scheduler entry to bypass or defer entitlement enforcement** — no such mechanism is introduced anywhere in M2.

---

## 5. Dependency direction (preventing a WorkspaceManager ↔ EntitlementManager cycle)

**One-directional: `WorkspaceManager` → `EntitlementManager` and `BusinessManager` → `EntitlementManager`. `EntitlementManager` never depends on `WorkspaceManager` or `BusinessManager`.**

`EntitlementManager`'s own constructor depends only on: its six existing M1 repositories (`WorkspacePlanCatalogRepository`, `WorkspacePlanFeatureRepository`, `WorkspacePlanAssignmentRepository`, `WorkspaceEntitlementOverrideRepository`, `BusinessFeatureToggleRepository`, `WorkspaceEntitlementTransitionRepository`), plus four existing, **plain data-access** repositories it needs read/lock/authority-lookup access to and that themselves have no knowledge of `EntitlementManager` — `WorkspaceRepository` (RFC-003, for `findForUpdate()` — every `EntitlementManager` actor-driven mutation locks its target Workspace row itself, §6 below), `BusinessRepository` (RFC-001, for the new `countForWorkspace()`, §10 below), `WorkspaceMembershipRepository` (RFC-003, existing — used only for its existing `findByWorkspaceAndUser(Workspace $workspace, int $userId)` method, to enforce the already-existing RFC-003 owner-or-active-Admin authority rule for Business feature-toggle mutations, §13.K below — no code change to this repository is required or authorized), and `UserRepository` (existing — used only to look up an acting user's `users.is_admin` flag for the mutations restricted to a platform administrator, §13.N below, matching `EnsureUserIsAdministrator`'s own established administrator truth exactly — no code change to this repository is required or authorized) — plus the `UsageAuthorizationGateway` interface. None of these ten repository dependencies is `WorkspaceManager` or `BusinessManager` themselves, so no cycle exists regardless of how many orchestration classes come to depend on `EntitlementManager`. Neither `WorkspaceMembershipRepository` nor `UserRepository` is added to §16's authorized-path list — both are existing, unmodified files, referenced only as dependencies.

`WorkspaceManager`'s constructor gains exactly one new dependency, `EntitlementManager`, alongside its seven existing ones. `BusinessManager`'s constructor gains exactly one new dependency, `EntitlementManager`, alongside its five existing ones (it already depends on `WorkspaceManager`; that pre-existing edge is unaffected).

### 5.1 Constructor-compatibility for existing manual test doubles (added in this correction, read-only audit finding)

Both new required constructor parameters are added purely additively — **production dependency remains required, never nullable/optional, and no `app()`/service-location call is introduced inside either manager to hide it.** Every production call site resolves `WorkspaceManager`/`BusinessManager` through the Laravel container, which autowires the new dependency with zero production-code impact — confirmed by a direct repository-wide audit finding no `new BusinessManager(`/`new WorkspaceManager(` and no `ServiceProvider` binding closure for either class anywhere outside the four files below. Exactly four existing test/support files manually instantiate one of these two classes (or a subclass) with positional constructor arguments and require a compatibility update — no other file in the repository is affected:

- **`tests/Feature/Opportunity/ExecuteOpportunityActionJobTest.php`** — both existing `new class(...) extends BusinessManager { ... }` anonymous-double constructions add `app(EntitlementManager::class)` as the sixth positional argument, after the five existing ones (`BusinessRepository`, `BusinessLocationRepository`, `BusinessServiceRepository`, `UrlNormalizer`, `WorkspaceManager`).
- **`tests/Feature/Opportunity/OpportunityActionExecutorTest.php`** — the same two-site pattern, the same fix.
- **`tests/Feature/Workspace/Support/SlowWorkspaceManager.php`** — this named `WorkspaceManager` subclass's own `__construct()` gains `EntitlementManager $entitlementManager` as a new parameter, inserted **before** its existing test-only `float $holdSeconds` parameter (preserving `$holdSeconds` as the trailing, defaultless parameter), and forwards `$entitlementManager` into `parent::__construct(...)` after `WorkspaceManager`'s seven existing constructor dependencies.
- **`tests/Feature/Workspace/Support/concurrent_workspace_resolver_runner.php`** — its `'slow'` branch's `new SlowWorkspaceManager(...)` construction adds the corresponding new `$app->make(App\Library\Entitlement\EntitlementManager::class)` argument in the matching position before `$holdSeconds`. Its `else` branch (`$app->make(WorkspaceManager::class)`) already autowires correctly and needs no change.

These four files are added to §16's authorized-path list purely for this mechanical compatibility reason — none of them exercises new RFC-004 business logic; each already-existing test's own assertions and behavior are otherwise unchanged.

---

## 6. Locking / concurrency — exact required order

**No count-increasing operation may read the Business count before locking the destination Workspace. Every Workspace-scoped `EntitlementManager` actor-driven mutation method locks its target Workspace row (`WorkspaceRepository::findForUpdate()`) as its own first step, before reading or writing any entitlement table for that Workspace — the single canonical serialization point for everything entitlement-related about a Workspace, exactly mirroring how `WorkspaceManager` already treats the Workspace row as the sole lock point for every one of its own mutations. `updateCatalogPricing()` is the one deliberate exception (below): it has no target Workspace and never acquires a Workspace lock.**

- **`createBusinessInWorkspace()`:** destination Workspace lock (already present, unchanged) → `EntitlementManager::assertCanCreateAnotherBusiness()` → `Business` insert, all in the same existing transaction.
- **Legacy onboarding:** the one selected destination Workspace is locked (new, explicit — §13.C below) → `EntitlementManager::assertCanCreateAnotherBusiness()` → `Business` insert, all in the same existing `BusinessManager::applyIdentity()` transaction. When the resolver is going to throw because multiple candidates exist, **no candidate Workspace is locked at all** — this matches `resolveLegacyOnboardingWorkspace()`'s own existing, unchanged behavior exactly (it locks no Workspace today; verified by direct inspection).
- **`reassignBusiness()`:** source + destination Workspaces locked in the existing deterministic ascending-ID order (unchanged) → Business locked + existing consistency/authority/active-state checks (unchanged) → for a **real cross-Workspace move only**, target-capacity decision using the already-locked target Workspace → source membership-grant cleanup → `BusinessRepository::reassignWorkspace()`, all in the same existing transaction. A same-Workspace call remains the existing authorized no-op, evaluated (as today) only after every lock/consistency/authority/active-state check has passed, and never reaches the capacity assertion.
- **Every other Workspace-scoped `EntitlementManager` mutation** (`changePlan()`, `changePlanStatus()`, grant/revoke complimentary, `setAdditionalBusinessSlots()`, Workspace-override create/change/revert) locks its own target Workspace row as its own first step before reading or writing `workspace_plan_assignments`/`workspace_entitlement_overrides`/`workspace_entitlement_transitions` for that Workspace.
- **Business-toggle create/remove (`disableBusinessFeature()`/`enableBusinessFeature()`) — corrected in this round, a genuinely distinct Business-scoped case, not merely "locks its Workspace like everything else":** the caller-supplied `Business` object's `workspace_id` cannot be trusted as current — a concurrent `reassignBusiness()` call may already have moved the persisted row. Exact order, matching `reassignBusiness()`'s own existing Workspace→Business lock direction exactly (never inverted): capture `expectedWorkspaceId` from the caller-supplied `Business` → lock that expected Workspace (`WorkspaceRepository::findForUpdate()`) → lock/reload the Business by ID (`BusinessRepository::findForUpdate()`, the same existing method `reassignBusiness()` already uses — no new repository method) → if the Business no longer exists, throw the existing `WorkspaceBusinessNotFoundException` → if the freshly-locked Business's `workspace_id` no longer equals `expectedWorkspaceId`, throw the existing `BusinessWorkspaceMismatchException` (same constructor shape `reassignBusiness()` already uses — no new exception) → only then evaluate Workspace owner-or-active-Admin authority (§13.N) against the locked Workspace → every remaining decision/toggle operation uses the freshly-locked Business and Workspace, never the stale caller-supplied model (§13.K below).

**Catalog-row serialization (new in this correction — closes a TOCTOU gap between a Workspace-scoped paid mutation and a concurrent `updateCatalogPricing()` clear on the same catalog row).** `WorkspacePlanCatalogRepository::findForUpdate(int $id): ?WorkspacePlanCatalog` (§10 below) is the single canonical per-catalog-row serialization point, exactly mirroring `WorkspaceRepository::findForUpdate()`'s own role for a Workspace row. Two disjoint, non-overlapping lock orders exist, and their disjointness is what prevents a catalog↔Workspace lock-order cycle — no method anywhere acquires both a catalog lock and a Workspace lock in the reverse of the order stated below:

- **Workspace-scoped paid mutations** — `assignFirstPlan()` for a non-complimentary assignment (§13.E), `changePlan()` for any non-complimentary destination (§13.F), `setAdditionalBusinessSlots()` for a non-complimentary increase (§13.I), `revokeComplimentaryStatus()` (§13.H) — follow, in this exact order: Workspace lock → destination/relevant catalog row lock (`findForUpdate()`) → **`is_active` rechecked on the now-locked row, authoritative for the write (new in this correction, RFC-004 §10.1 — `assignFirstPlan()`/`changePlan()` only)** → both-null-or-both-populated pricing validation against the now-locked catalog row → entitlement write → commit. The catalog lock is always acquired strictly **after** the Workspace lock, never before. For a **complimentary** destination in `assignFirstPlan()`/`changePlan()`, no catalog lock is acquired at all (no pricing to serialize) — the resolved destination row's `is_active` is nonetheless required to be `true` on its initial, unlocked read; M2 exposes no `is_active` mutation on the catalog, so no lock is needed to make that read race-safe.
- **`updateCatalogPricing()`** (§13.L) follows a disjoint order that never touches a Workspace row at all: platform-administrator authority check → catalog row lock (`findForUpdate()`) → both-null-or-both-populated validation → (only when clearing) `WorkspacePlanAssignmentRepository::hasNonComplimentaryForCatalogForUpdate()` checked, via a locking/current read, while the catalog lock is still held → update → commit. **This corrected read must not be an ordinary consistent-snapshot `exists()` query**: the actor/`UserRepository` authority lookup that runs before the catalog lock is acquired may itself establish a transaction read view (MySQL/InnoDB REPEATABLE READ), so an ordinary snapshot read taken afterward could still miss a non-complimentary assignment that committed while `updateCatalogPricing()` was waiting on the catalog lock. The reference check must use `lockForUpdate()` (or an equivalent proven current-read mechanism) so it is guaranteed to observe every assignment committed before the catalog lock was acquired, exactly like `findForUpdate()`'s own current-read guarantee.

Because every Workspace-scoped paid mutation locks Workspace-then-catalog and `updateCatalogPricing()` locks catalog-only (never Workspace), the two lock orders can never deadlock against each other, and the shared catalog-row lock is what makes the final state race-safe: whichever of a paid mutation or a concurrent catalog clear commits first is authoritative, and the loser observes the committed state and fails closed (`UndefinedPlanPricingException` for a paid mutation racing a clear that won; `PlanCatalogPricingInUseException` for a clear racing a paid mutation that won) — the final state can never be a non-complimentary assignment referencing a catalog row with undefined pricing.

**Legacy onboarding vs. `transferOwnership()` — a genuine inverse-lock cycle across two independent operations, resolved by bounded retry, not by reordering (new in this correction, §13.O).** Legacy onboarding (§13.C) locks the owner's `users` row first, then this milestone adds a Workspace lock — `User → Workspace`. `WorkspaceManager::transferOwnership()` (RFC-003, existing, unchanged) locks `Workspace → User(s) → Membership`. These are genuine inverse orders and a real cross-operation MySQL deadlock is possible during the initial lock-acquisition phase of either operation, before either has performed its own domain writes or dispatched its own events. RFC-003's `transferOwnership()` lock order is **not** reopened or reordered by M2. Instead, both operations' own transactions are each given up to **3** attempts via Laravel's existing `DB::transaction($callback, $attempts)` retry parameter — a deadlock-victim attempt is retried cleanly from the start rather than surfacing an unhandled MySQL deadlock. No manual lock reordering and no manual sleep/backoff is introduced in production code (§13.O).

Required concurrency tests (§14 below), using real concurrent processes/transactions following this repository's existing proven patterns (`WorkspaceManagerConcurrencyTest.php`'s cross-process runner-script pattern **and** its same-process second-connection lock-probe pattern — both already shipped and reused, not invented) — never sequential coincidence:

1. Create + create racing a destination Workspace's final slot: exactly one succeeds.
2. Create + reassign racing the same destination Workspace's final slot: exactly one succeeds.
3. No target over-allocation in either race.
4. Source/destination isolation — a race on one Workspace never affects an unrelated Workspace's own count/decision.
5. The existing opposite-direction-reassignment deadlock-prevention behavior (ascending-ID lock order for two concurrent reassignments swapping Businesses between the same two Workspaces, `WorkspaceBusinessOrchestrationTest.php`'s existing coverage) remains intact and unaffected by the new capacity assertion.
6. Catalog-clear-vs-paid-`assignFirstPlan()`: a concurrent `updateCatalogPricing()` clear racing a non-complimentary `assignFirstPlan()` against the same catalog row never leaves a non-complimentary assignment with undefined pricing — exactly one of the two operations succeeds against the shared invariant, the other observes the committed state and fails closed.
7. Catalog-clear-vs-`revokeComplimentaryStatus()`: a concurrent `updateCatalogPricing()` clear racing a `revokeComplimentaryStatus()` call against the same catalog row never leaves a non-complimentary assignment with undefined pricing — same fail-closed guarantee as scenario 6.
8. Reassign-vs-toggle: a concurrent `reassignBusiness()` move racing a `disableBusinessFeature()`/`enableBusinessFeature()` call against the same Business never lets an old-Workspace actor mutate the toggle after the Business has authoritatively moved — either the toggle genuinely completes while the Business still belonged to the old Workspace, or the toggle call observes the moved `workspace_id` after its own Business lock and fails closed (`BusinessWorkspaceMismatchException`) before any toggle row is created or deleted.
9. Legacy-onboarding-vs-`transferOwnership()` (new in this round, §6/§13.O): a real concurrent race between `BusinessManager::applyIdentity()`'s legacy CREATE path (`User → Workspace` lock order) and `WorkspaceManager::transferOwnership()` (`Workspace → User(s)` lock order) against the same owner/Workspace deliberately exercises the inverse-lock situation and proves: no unhandled MySQL deadlock escapes the bounded 3-attempt retry policy; no partial ownership transfer; no duplicate Business creation; no slot over-allocation; the final Business belongs to a valid, authoritative Workspace; the final Workspace ownership/membership state is internally consistent; and the retry remains bounded — the test does not hang.

---

## 7. EntitlementManager — exact method surface

```php
final class EntitlementManager
{
    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly BusinessRepository $businessRepository,
        private readonly WorkspaceMembershipRepository $membershipRepository,
        private readonly UserRepository $userRepository,
        private readonly WorkspacePlanCatalogRepository $catalogRepository,
        private readonly WorkspacePlanFeatureRepository $planFeatureRepository,
        private readonly WorkspacePlanAssignmentRepository $assignmentRepository,
        private readonly WorkspaceEntitlementOverrideRepository $overrideRepository,
        private readonly BusinessFeatureToggleRepository $toggleRepository,
        private readonly WorkspaceEntitlementTransitionRepository $transitionRepository,
        private readonly UsageAuthorizationGateway $usageAuthorizationGateway,
    ) {}

    // --- Read / decision (no lock — pure read, matches RFC-003's own
    // read-side precedent of not locking for a plain authorization check) ---
    public function decide(Workspace $workspace, Business $business, string $featureKey, int $actorUserId): EntitlementDecision;
    public function decideBusinessSlotCapacity(Workspace $workspace): BusinessSlotCapacityDecision;
    public function assertCanCreateAnotherBusiness(Workspace $workspace): void; // locks nothing itself — caller already holds the Workspace lock (§6)

    // --- Plan assignment ---
    public function assignFirstPlan(Workspace $workspace, WorkspacePlanTier $tier, int $actorUserId, string $reason, bool $isComplimentary = false, int $additionalBusinessSlots = 0): WorkspacePlanAssignment;
    public function createLegacyOnboardingCompatibilityAssignment(Workspace $workspace): WorkspacePlanAssignment; // narrow, system-provenance only — §13.D
    public function changePlan(Workspace $workspace, WorkspacePlanTier $newTier, int $actorUserId, ?string $reason = null, ?int $additionalBusinessSlots = null): WorkspacePlanAssignment;
    public function changePlanStatus(Workspace $workspace, WorkspacePlanAssignmentStatus $status, int $actorUserId, string $reason): WorkspacePlanAssignment;

    // --- Complimentary status ---
    public function grantComplimentaryStatus(Workspace $workspace, int $actorUserId, string $reason): WorkspacePlanAssignment;
    public function revokeComplimentaryStatus(Workspace $workspace, int $actorUserId, ?string $reason = null): WorkspacePlanAssignment;

    // --- Additional Business slots ---
    public function setAdditionalBusinessSlots(Workspace $workspace, int $count, int $actorUserId, ?string $reason = null): WorkspacePlanAssignment;

    // --- Workspace overrides ---
    public function createOrChangeOverride(Workspace $workspace, PlatformFeature $feature, WorkspaceEntitlementOverrideState $state, int $actorUserId, string $reason): WorkspaceEntitlementOverride;
    public function revertOverride(Workspace $workspace, PlatformFeature $feature, int $actorUserId): void;

    // --- Business feature toggles ---
    public function disableBusinessFeature(Business $business, PlatformFeature $feature, int $actorUserId, ?string $reason = null): BusinessFeatureToggle;
    public function enableBusinessFeature(Business $business, PlatformFeature $feature, int $actorUserId): void;

    // --- Catalog pricing mutation guard ---
    public function updateCatalogPricing(WorkspacePlanCatalog $catalog, ?string $price, ?int $currencyId, int $actorUserId): WorkspacePlanCatalog;
}
```

Every mutating method re-checks state even when a caller already checked, matching RFC-001/RFC-003's manager posture (RFC-004 §20). No repository method outside these nine (six M1 repositories + the three extended ones, §10) is ever queried directly for entitlement data by any other class — `EntitlementManager` and its repositories are the only authorized readers/writers of the six RFC-004 tables (§15 below).

### 7.1 `decide()` — exact precedence (RFC-004 §14, unchanged in substance; boundary corrected in this round)

**Corrected in this round: `decide()` takes a raw `string $featureKey`, not a `PlatformFeature` enum — the caller is never required to already possess a valid enum instance, which is exactly what makes step 1 below reachable.** Exact conversion sequence, performed first, before any other read: `$feature = PlatformFeature::tryFrom($featureKey);` — if `$feature === null`, `decide()` returns immediately with `platform_feature_unknown`, before any Workspace-assignment, override, toggle, or gateway lookup is made.

**Corrected in this round — registry call fixed to preserve the shipped API, no signature change:** the shipped M1 API is `PlatformFeatureRegistry::isAvailable(string $featureKey): bool` — it was never modified to accept an enum, and `PlatformFeatureRegistry.php` is **not** modified by M2 at all. Step 2 below therefore calls `PlatformFeatureRegistry::isAvailable($feature->value)`, passing the validated enum's `->value`, never the enum instance itself (which would `TypeError` against the existing `string` parameter). **General type rule, locked for the whole of `EntitlementManager`:** `PlatformFeature` is the validated typed identity used internally once resolved; any API that already accepts a `string` feature key — `PlatformFeatureRegistry::isAvailable(string $featureKey)`, `WorkspacePlanFeatureRepository::includesFeature(WorkspacePlanCatalog $catalog, string $featureKey)`, `WorkspaceEntitlementOverrideRepository::findByWorkspaceAndFeature(int $workspaceId, string $featureKey)`, `BusinessFeatureToggleRepository::findByBusinessAndFeature(int $businessId, string $featureKey)` — receives `$feature->value`; any API that already accepts `PlatformFeature` — `UsageAuthorizationGateway::check(Business $business, PlatformFeature $feature)` — receives `$feature` itself. No existing M1 repository or registry method signature is changed merely to accept an enum.

**Corrected in this round — defensive Workspace/Business consistency check (new steps 3-4, before any entitlement-table read):** RFC-003's own `userCanAccessBusiness()`/`assertUserCanAccessBusiness()` authorization remains an entirely independent precondition (unchanged, below) — this is a separate, narrower **domain-consistency** check, not a tenancy-authorization algorithm: `EntitlementManager` must never combine one Workspace's entitlement state with a Business that currently belongs to a different Workspace. After feature identity and implementation availability have both passed, but before any Workspace-assignment/override/toggle entitlement-table lookup: the Business is reloaded authoritatively by ID via the existing `BusinessRepository::findById($business->id)` (no new repository method — `BusinessRepository` is already an `EntitlementManager` dependency, §5); if it no longer exists, the existing `WorkspaceBusinessNotFoundException` is thrown (no new exception); if the authoritative `(int) $currentBusiness->workspace_id` does not equal `(int) $workspace->id`, the existing `BusinessWorkspaceMismatchException` is thrown, constructed with the Business ID, the supplied Workspace's `id` as `expectedWorkspaceId`, and the authoritative `Business.workspace_id` as `actualWorkspaceId` (no new exception, same constructor shape already used elsewhere, §13.K). From this point on, `decide()` uses the freshly-reloaded Business for every remaining step — **never the stale caller-supplied model** — specifically including the `BusinessFeatureToggleRepository` lookup (step 7 below) and `UsageAuthorizationGateway::check()` (step 9 below). This check does **not** query `WorkspaceMembershipRepository` or `UserRepository`, does **not** grant access, does **not** evaluate ownership or membership, and is never a substitute for RFC-003's own independent authorization precondition — it exists solely to prevent stale/mismatched aggregate inputs from mixing one Workspace's entitlements with another Workspace's Business.

Exact precedence, corrected in this round:

1. Known feature (`PlatformFeature::tryFrom($featureKey)` succeeds) — else `platform_feature_unknown`.
2. Implementation available (`PlatformFeatureRegistry::isAvailable($feature->value)`) — else `platform_feature_unavailable`, never bypassable by an override.
3. Authoritative Business reload (`BusinessRepository::findById($business->id)`) — if it no longer exists, `WorkspaceBusinessNotFoundException` (a thrown exception, not one of the nine denial keys — `decide()` does not return an `EntitlementDecision` for this case).
4. Authoritative `Business.workspace_id` vs. the supplied `Workspace.id` — if mismatched, `BusinessWorkspaceMismatchException` (likewise a thrown exception, not a denial key).
5. Workspace assignment exists (against the now-confirmed-consistent Workspace/Business pair) — else `workspace_plan_unassigned`.
6. Workspace override if present, else plan-feature mapping — else `not_entitled_by_plan` / `denied_by_workspace_override`.
7. Business toggle narrows only (checked against the freshly-reloaded Business) — else `disabled_for_business`.
8. Billing/operational state (RFC-004 §18) — else `plan_inactive` / `plan_suspended`.
9. `UsageAuthorizationGateway::check()` (checked against the freshly-reloaded Business, receiving the validated `PlatformFeature $feature` enum, not a string) — else the gateway's own reason (reserved `usage_unauthorized`, unreachable until RFC-005 binds a real gateway).
10. Allowed.

**Stable denial keys, exactly nine, no additions and no reordering of this set**: `platform_feature_unknown`, `platform_feature_unavailable`, `workspace_plan_unassigned`, `not_entitled_by_plan`, `denied_by_workspace_override`, `disabled_for_business`, `plan_inactive`, `plan_suspended`, `usage_unauthorized`. **The new consistency check (steps 3-4) is explicitly not a tenth entitlement denial reason** — it throws an exception rather than returning an `EntitlementDecision` with a denial key, never a new entry in the stable-keys set.

**`decide()` and `decideBusinessSlotCapacity()` establish no tenancy authorization of any kind** — neither checks `WorkspaceMembershipRepository`, `UserRepository`, or any owner/admin/platform-admin rule, and neither should ever be modified to do so. RFC-003's own `userCanAccessBusiness()`/`assertUserCanAccessBusiness()` authorization remains an entirely independent precondition, already required to have passed before either read/decision method is ever called — exactly as RFC-004 §9 already requires (§13.N below states the full authority split for every *mutation*; these two read-only methods sit outside it entirely). The new Workspace/Business consistency check (steps 3-4 above) is likewise not a substitute for that independent precondition — it is a narrower domain-consistency guard, never a tenancy-authorization algorithm.

### 7.2 `decideBusinessSlotCapacity()` — exact algorithm (RFC-004 §17, unchanged, reproduced not redesigned)

Reproduces RFC-004 §17's pseudocode exactly: unassigned → `workspace_plan_unassigned`; suspended/inactive → `plan_suspended`/`plan_inactive`; unlimited (Agency) → always allowed; otherwise `effectiveCapacity = min(included + additionalSlots, max)`; under capacity → allowed; at capacity but `effectiveCapacity < max` → `business_slot_allocation_required`; at `max` → `business_slot_limit_exceeded`. `currentCount` is read via `BusinessRepository::countForWorkspace($workspace)` (§10) — every Business row regardless of `status` (RFC-004 §8/§13), never a Collection loaded into memory just to be counted.

---

## 8. Value objects (RFC-004 §17/§20, exact shapes)

```php
final readonly class EntitlementDecision
{
    public function __construct(public bool $allowed, public ?string $reason) {}
}

final readonly class BusinessSlotCapacityDecision
{
    public function __construct(
        public int $currentBusinessCount,
        public int $includedSlots,
        public int $additionalSlotsAllocated,
        public ?int $effectiveCapacity,
        public bool $unlimited,
        public bool $allowed,
        public ?string $denialReason,
    ) {}
}

final readonly class UsageAuthorizationResult
{
    public function __construct(public bool $authorized, public ?string $reason = null) {}
}
```

---

## 9. Usage authorization seam (RFC-004 §19, exact)

```php
interface UsageAuthorizationGateway
{
    public function check(Business $business, PlatformFeature $feature): UsageAuthorizationResult;
}

final class NullUsageAuthorizationGateway implements UsageAuthorizationGateway
{
    public function check(Business $business, PlatformFeature $feature): UsageAuthorizationResult
    {
        return new UsageAuthorizationResult(authorized: true);
    }
}
```

`AppServiceProvider` gains exactly one additive line in the existing `$bindings` array: `UsageAuthorizationGateway::class => NullUsageAuthorizationGateway::class`. No other line in that file changes. No RFC-005 implementation ships.

---

## 10. M1/RFC-001 repository extensions genuinely required by M2

Direct inspection of all six M1 repositories plus `BusinessRepository`/`WorkspaceRepository` found the following **plain data-access** additions genuinely required — no entitlement-decision logic added to any repository, matching M1's own "plain data-access contract" boundary exactly:

- **`BusinessRepository`** (contract + Eloquent): add `countForWorkspace(Workspace $workspace): int` — a lean `COUNT(*)` query (`Business::where('workspace_id', $workspace->id)->count()`), replacing what would otherwise be an inefficient full-Collection load merely to count rows. Every Business row counts, regardless of `status` (RFC-004 §8/§13) — the query applies no status filter.
- **`WorkspacePlanCatalogRepository`** (contract + Eloquent): add `update(WorkspacePlanCatalog $catalog, array $attributes): WorkspacePlanCatalog` — M1's contract had `findById`/`findByTier`/`create()` only; the catalog-pricing mutation guard (RFC-004 §12.5) genuinely needs to update `price`/`currency_id` on an existing row. Also add `findForUpdate(int $id): ?WorkspacePlanCatalog` — a `SELECT ... FOR UPDATE` row-locking variant of `findById()`, named to match `WorkspaceRepository`/`BusinessRepository`/`WorkspaceMembershipRepository`'s own identical existing `findForUpdate()` naming convention exactly (not `findByIdForUpdate` — the established convention in this codebase clearly requires the shorter name; flagged explicitly per governance, no other naming departure taken). This is the canonical per-catalog-row serialization point required by §6's new catalog-locking rule (this correction).
- **`WorkspacePlanAssignmentRepository`** (contract + Eloquent): add `update(WorkspacePlanAssignment $assignment, array $attributes): WorkspacePlanAssignment` — M1's contract had `findByWorkspaceId`/`create()` only; `changePlan()`/`changePlanStatus()`/complimentary grant-revoke/`setAdditionalBusinessSlots()` all genuinely need to mutate an existing assignment row. Also add `hasNonComplimentaryForCatalogForUpdate(int $catalogId): bool` (**renamed in this correction from the original `hasNonComplimentaryForCatalog` name** to make its locking/current-read semantic explicit — matching this repository's own `findForUpdate()`-suffix convention rather than introducing an unrelated naming scheme) — `WorkspacePlanAssignment::where('workspace_plan_catalog_id', $catalogId)->where('is_complimentary', false)->lockForUpdate()->exists()`, a genuine locking/current read, **deliberately not an ordinary consistent-snapshot `exists()` query**: required by `updateCatalogPricing()`'s clearing guard (§13.L) to guarantee visibility of a non-complimentary assignment committed before the caller acquired the catalog row lock, even under MySQL/InnoDB REPEATABLE READ — a plain snapshot read could otherwise miss a row committed while the caller was waiting on the catalog lock. No entitlement/business logic in the repository; the method only reports existence under a current/locking read.
- **`WorkspaceEntitlementOverrideRepository`** (contract + Eloquent): add `update(WorkspaceEntitlementOverride $override, WorkspaceEntitlementOverrideState $state): WorkspaceEntitlementOverride` — M1's contract had `findByWorkspaceAndFeature`/`create()`/`delete()` only; changing an existing override's state (allow↔deny, RFC-004 §15) is a genuinely distinct operation from creating a first-time override.

No other M1 repository (`WorkspacePlanFeatureRepository`, `BusinessFeatureToggleRepository`, `WorkspaceEntitlementTransitionRepository`) needs a new method — plan-feature packaging is not admin-mutated in M2 (no HTTP surface), toggles are create/remove-only by design (RFC-004 §16), and the transition repository is already append-only with the exact `create()`/`forWorkspace()` M2 needs.

Each `update()` implementation is a plain `$model->fill(Arr::only($attributes, [...]))->save()`-style mutation (matching `EloquentWorkspaceRepository::update()`'s own exact precedent) — no business-rule logic in the repository; every invariant (pricing pairing, slot-value bounds, status transitions) is enforced exclusively by `EntitlementManager` before it calls `update()`.

---

## 11. Events (RFC-004 §21, exact seven, matching `App\Events\Workspace\*`'s exact convention)

`WorkspacePlanAssigned`, `WorkspacePlanChanged`, `WorkspacePlanStatusChanged`, `WorkspaceComplimentaryStatusChanged`, `WorkspaceAdditionalBusinessSlotsChanged`, `WorkspaceEntitlementOverrideChanged`, `BusinessFeatureToggleChanged` — under `App\Events\Entitlement\*`. Immutable constructor-promoted scalar/ID properties only, dispatched after commit, matching `App\Events\Workspace\WorkspaceCreated`'s own exact shape and dispatch-timing convention. M2 exercises every one of them at least once in its own tests (§14).

---

## 12. Exceptions — exact bounded set

**Seven already reserved by the M1 contract's own out-of-scope list** (verbatim names, `app/Exceptions/Entitlement/`): `WorkspacePlanUnassignedException`, `InactiveWorkspacePlanException`, `SuspendedWorkspacePlanException`, `BusinessSlotAllocationRequiredException`, `BusinessSlotLimitExceededException`, `UndefinedPlanPricingException`, `PlanCatalogPricingInUseException`.

**Three additional, genuinely distinct RFC-004 failure semantics** (not collapsible into the above without losing a distinct, named failure mode RFC-004 itself requires):

- `InvalidAdditionalBusinessSlotsException` — `setAdditionalBusinessSlots()`/`changePlan()`'s allocation argument outside `{0,1,2}` for Core/Growth, or any non-zero value for Agency (RFC-004 §17/§17.1).
- `WorkspacePlanAlreadyAssignedException` — `assignFirstPlan()` called against a Workspace that already has an assignment (RFC-004 §8 — every Workspace has at most one).
- `UnavailablePlatformFeatureOverrideException` — an `allow`-override write for a feature `PlatformFeatureRegistry::isAvailable()` reports `false` for (RFC-004 §11/§15; a `deny` override for the same feature remains permitted, no exception).

No other new exception class is introduced. An unknown `feature_key` supplied to an override/toggle mutation reuses the M1 repositories' own existing `InvalidArgumentException` (already thrown by `WorkspacePlanFeatureRepository`/`WorkspaceEntitlementOverrideRepository`/`BusinessFeatureToggleRepository::create()`) — no redundant `EntitlementManager`-level exception duplicates that already-correct M1 behavior. **Corrected in this round: an inactive destination catalog tier supplied to `assignFirstPlan()`/`changePlan()` (RFC-004 §10.1, §13.E/§13.F) reuses this exact same established `InvalidArgumentException` convention** (the plain global PHP exception, matching the M1 repositories' own `use InvalidArgumentException;`/`throw new InvalidArgumentException(...)` pattern exactly — not a Symfony/Illuminate-namespaced variant), message `"Workspace plan catalog tier [{$tier->value}] is inactive and cannot receive new assignments."` — no new exception class for this either.

---

## 13. Exact mutation semantics

### 13.A `createBusinessInWorkspace()` (WorkspaceManager, modified)

Keeps its existing requirements exactly: target Workspace locked first, existing active-state/RFC-003-authority checks unchanged, Business insert in the same transaction, no controller-side slot arithmetic. The one addition: `$this->entitlementManager->assertCanCreateAnotherBusiness($lockedWorkspace);` after the lock, before the insert (RFC-004 §17).

### 13.B `reassignBusiness()` (WorkspaceManager, modified)

Preserves its exact existing architecture (derive source/target IDs → dedupe/sort ascending → lock every distinct Workspace in that order → lock Business → re-verify source → authority + active-state both sides → same-target no-op check). For a **real** cross-Workspace move, after every existing check has passed but **before** `removeAllForBusinessInWorkspace()` and **before** `reassignWorkspace()`: `$this->entitlementManager->assertCanCreateAnotherBusiness($lockedTargetWorkspace);`. A same-Workspace call remains the existing no-op — it must never reach this assertion and must never fail merely because the Workspace is at capacity (RFC-004 §17.2).

### 13.C Legacy onboarding integration (BusinessManager + WorkspaceManager, modified)

`WorkspaceManager` gains one new narrow public method:

```php
public function lockForLegacyOnboardingBusinessCreation(Workspace $workspace): Workspace
{
    $locked = $this->workspaceRepository->findForUpdate($workspace->id);

    if ($locked === null) {
        throw new WorkspaceNotFoundException($workspace->id);
    }

    return $locked;
}
```

Deliberately no authority or active-state check — the legacy path has never enforced either, and this integration adds exactly one new thing (capacity enforcement), never a new authority requirement (RFC-004 §17.3). `BusinessManager::applyIdentity()`'s creation branch becomes:

```php
$workspace = $this->workspaceManager->resolveLegacyOnboardingWorkspace($customer->user_id);
$lockedWorkspace = $this->workspaceManager->lockForLegacyOnboardingBusinessCreation($workspace);
$this->entitlementManager->assertCanCreateAnotherBusiness($lockedWorkspace);
$result = $this->businessRepository->createForCustomerInWorkspace($customer, $lockedWorkspace, $normalizedAttributes);
```

This lock is a genuinely new, explicit acquisition performed immediately after resolution, inside `applyIdentity()`'s own existing transaction — it is not assumed to be inherited implicitly from `resolveLegacyOnboardingWorkspace()`'s own internal `DB::transaction()` call (which, being called while `applyIdentity()`'s transaction is already open, executes as a Laravel savepoint rather than a separate real transaction — a lock taken inside it would, in practice, already survive for the rest of the outer transaction too, but this design deliberately does not rely on a reader correctly reasoning through that subtlety: the lock here is explicit, self-evident, and independently correct regardless of savepoint semantics). `resolveLegacyOnboardingWorkspace()`'s own candidate-resolution behavior is completely unchanged — when it throws for multiple candidates, it still locks nothing, exactly as today (verified: `verifyWorkspaceIds()` uses plain `findById()`, never `findForUpdate()`) — so no candidate Workspace is ever locked unnecessarily. `BusinessManager` gains no raw entitlement-table query anywhere — it only ever calls `EntitlementManager::assertCanCreateAnotherBusiness()`.

**Deadlock retry (added in this correction — see §13.O for the full policy):** this lock's `User → Workspace` order is the exact inverse of `WorkspaceManager::transferOwnership()`'s existing `Workspace → User(s)` order (RFC-003, unchanged) — a real cross-operation deadlock is possible. `applyIdentity()`'s CREATE path (this branch, `$business === null`) is retried up to 3 attempts via `DB::transaction()`'s own attempt parameter rather than reordering either operation's locks.

### 13.D Newly auto-provisioned legacy Workspace (WorkspaceManager + EntitlementManager, modified/new)

`WorkspaceManager::provisionWorkspaceRecord()` (private, called only from `resolveLegacyOnboardingWorkspace()`'s no-fallback-candidates branch) gains one additive call immediately after creating the Workspace row: `$this->entitlementManager->createLegacyOnboardingCompatibilityAssignment($workspace);`. This is the **only** call site for this method anywhere in M2's authorized scope.

`EntitlementManager::createLegacyOnboardingCompatibilityAssignment(Workspace $workspace): WorkspacePlanAssignment` — narrow, system-provenance only:

- Resolves the Core catalog row (same `tier = 'core'` lookup M1's own backfill used).
- Creates a `workspace_plan_assignments` row: `status = active`, `is_complimentary = true`, `additional_business_slots = 0` (a brand-new Workspace has no existing Businesses), `complimentary_granted_by_user_id = null`.
- **Exact fixed reason string**: `"Legacy onboarding Workspace — auto-provisioned complimentary Core assignment continuing Milestone 1's backfill posture for a brand-new Workspace created by the legacy resolver."`
- Writes one `plan_assigned` `workspace_entitlement_transitions` row (`actor_user_id = null`, `to_plan_catalog_id` = the Core row, `reason` = the same fixed string) — the normal first-assignment transition, no new transition type.
- Dispatches `WorkspacePlanAssigned` after commit, exactly like any other first assignment.
- Writes **no** `complimentary_granted` transition — complimentary is part of the row's initial state, not a change to an existing one (RFC-004 §17.4/§10.4's own existing create-vs-change distinction).
- `null` actor provenance is permitted **only** for this one narrowly-defined case and the already-existing M1 backfill — never a general admin-bypass precedent. This is a **system-only internal path**: it is never called from anywhere except `provisionWorkspaceRecord()`, is not part of any ordinary/HTTP-reachable mutation surface, and can never be invoked as a normal actor-driven self-grant by any Workspace owner, member, or administrator — `assignFirstPlan()` (§13.E) remains the only actor-driven first-assignment authority (§13.N).

### 13.E Normal first plan assignment (`assignFirstPlan()`, new)

**Platform administrator only (§13.N).** **Corrected in this round — exact order, authority before any entitlement-state read, and inactive-catalog rejection added (RFC-004 §10.1):** Workspace locked first → `actorUserId` required and validated as a platform administrator (else `AuthorizationException`, before the Workspace's assignment state or any catalog row is ever read — a non-administrator never learns whether the Workspace is already assigned) → must currently be unassigned (else `WorkspacePlanAlreadyAssignedException`) → destination catalog resolved by tier → destination catalog must exist → destination catalog's `is_active` must be `true` on this initial read (else the existing `InvalidArgumentException` convention, message `"Workspace plan catalog tier [{$tier->value}] is inactive and cannot receive new assignments."` — matching the M1 repositories' own already-established `InvalidArgumentException` convention, §12; no new exception class) → for a **non-complimentary** assignment, the resolved catalog row is locked (`WorkspacePlanCatalogRepository::findForUpdate()`, after the Workspace lock — §6), `is_active` is **rechecked on the now-locked row** (authoritative for the write — a concurrent deactivation between the initial read and the lock must still be caught; same `InvalidArgumentException`/message if it flipped to `false`), and its `price`+`currency_id` must both be defined on the now-locked row (else `UndefinedPlanPricingException`, RFC-004 §12.5) — the assignment is created while the catalog lock is still held, so a concurrent `updateCatalogPricing()` clear cannot land between the pricing check and the write (§6 scenario 6); a **complimentary** assignment acquires no catalog lock and requires no pricing, **but is not exempt from the `is_active` requirement** — the initial-read `is_active` check above still applies and rejects a complimentary first assignment to an inactive tier → `additionalBusinessSlots` validated `{0,1,2}` for Core/Growth, must be `0` for Agency (else `InvalidAdditionalBusinessSlotsException`) → `reason` required (non-empty) → assignment created → `plan_assigned` transition written → `WorkspacePlanAssigned` dispatched after commit.

### 13.F `changePlan()` (exact, reproducing RFC-004 §17.1 without redesign)

**Platform administrator only (§13.N).** **Corrected in this round — exact order, authority before any entitlement-state read, and destination-only inactive-catalog rejection added (RFC-004 §10.1):** Workspace lock → `actorUserId` validated as a platform administrator (else `AuthorizationException`, before the current assignment or any catalog row is ever read — a non-administrator never learns the Workspace's current plan or its destination's active state) → current assignment read → **destination** catalog resolved → destination catalog's `is_active` must be `true` on this initial read (else the existing `InvalidArgumentException` convention, same message format as §13.E — no new exception class) → for a destination that is or remains non-complimentary, the destination catalog row is locked (`findForUpdate()`, after the Workspace lock — §6), `is_active` is **rechecked on the now-locked row** (authoritative for the write), and price/currency validated → slot-value validation/write → transitions/events. Same locked transaction throughout.

**Corrected in this round (genuine RFC-004 §12.5 omission, not a redesign): for a destination that is or remains non-complimentary, EVERY direction — Core↔Growth, Core/Growth→Agency, Agency→Core/Growth — locks the destination catalog row (`findForUpdate()`, after the Workspace lock — §6) and requires its `price`+`currency_id` both defined (else `UndefinedPlanPricingException`) before `workspace_plan_catalog_id` changes, independently of whether `additional_business_slots` changes at all.** A **complimentary** assignment changing plan remains fully exempt from this base price/currency requirement — no catalog lock is acquired for a complimentary destination — **but is not exempt from the `is_active` requirement**: the initial-read `is_active` check above still applies to a complimentary destination and rejects a complimentary change into an inactive tier. **This rule concerns the destination only** (RFC-004 §10.1 — an inactive row cannot receive new assignments, but existing assignments referencing it are unaffected): changing **away from** a current assignment already referencing an inactive catalog, into an active destination, is allowed and unaffected by this correction; remaining on an inactive current catalog via any other, unrelated mutation is not turned into a new failure by this correction; `workspace_plan_catalog.is_active` and `workspace_plan_assignments.status` remain entirely separate concepts — this correction never inspects or changes `status`, and no automatic status change or catalog migration is introduced. Core↔Growth preserves `additional_business_slots` unchanged (`$additionalBusinessSlots` must be `null`). Core/Growth→Agency atomically resets slots to `0` (writes both `plan_changed` and `additional_business_slots_changed` transitions, dispatches both events). Agency→Core/Growth defaults slots to `0`, or allocates `1`/`2` in the same operation, subject to §13.I's additional-slot pricing check **on top of** this base destination-pricing requirement when both apply. Existing Businesses are never removed/hidden/deactivated; grandfathered over-capacity is allowed and expected. `additional_business_slots_changed` transition/event fire only when the value actually changed.

### 13.G `changePlanStatus()` (exact, reproducing RFC-004 §18 without redesign)

**Platform administrator only (§13.N).** **Corrected in this round — exact order, authority before any entitlement-state read:** Workspace lock → `actorUserId` and non-empty `reason` validated (mandatory, unlike lower-stakes mutations elsewhere) — `actorUserId` validated as a platform administrator, else `AuthorizationException`, before the current assignment/status is ever read — → current assignment/status read → no-op/transition decision → write. Any of `active`/`inactive`/`suspended` → any other. **No-op behavior, defined here since RFC-004 left it implicit**: requesting the assignment's own current status is an authorized no-op — actor authority and reason are validated before the current status is ever read, so a non-administrator actor is denied without ever learning whether their requested status happens to equal the current one; no transition is written and no event is dispatched for a true no-op. A real transition writes `plan_status_changed` (`from_status`/`to_status`) and dispatches `WorkspacePlanStatusChanged` after commit. Status gates feature execution and Business-count increase only, never Workspace visibility (RFC-004 §18).

### 13.H Complimentary status (exact, reproducing RFC-004 §8/§13/§18)

**Platform administrator only (§13.N).** **Corrected in this round — exact order for both directions, authority before any entitlement-state read:** Workspace lock → `actorUserId` validated as a platform administrator (else `AuthorizationException`, before the assignment's complimentary state or any catalog row is ever read) → current assignment/complimentary state read → (revoke only) destination catalog row locked when required → write.

`grantComplimentaryStatus()`: non-empty `reason` required, sets `is_complimentary = true` (already-`true` is a no-op, no transition/event — the no-op comparison itself only happens after the authority check, so a non-administrator never learns the current complimentary state), writes `complimentary_granted`, dispatches `WorkspaceComplimentaryStatusChanged`. **Never requires catalog pricing and never locks the catalog row** — granting complimentary status never creates a paid state, so RFC-004 §12.5's pricing invariant does not apply to it.

`revokeComplimentaryStatus()`: `reason` optional. **Corrected in this round (previously missing §12.5 consequence): revoking complimentary status turns the assignment into a non-complimentary one, exactly like an initial paid assignment or a plan change — after the authority check and the current-state read, and before flipping `is_complimentary` to `false`, the currently-referenced catalog row is locked (`findForUpdate()`, after the Workspace lock — §6) and its `price`+`currency_id` must both be defined on the now-locked row, else `UndefinedPlanPricingException` is thrown and the assignment remains complimentary and unchanged.** When the catalog pricing is defined, revoke proceeds with the existing symmetric no-op/transition/event behavior (`complimentary_revoked`). Neither `grantComplimentaryStatus()` nor a successful `revokeComplimentaryStatus()` bypasses `inactive`/`suspended` status or slot capacity — both remain orthogonal gates exactly as RFC-004 §18 requires. No RFC-005 usage-cost waiver of any kind is invented beyond RFC-004's own existing complimentary semantics (§13's already-closed additional-slot-billing clarification).

### 13.I `setAdditionalBusinessSlots()` (exact, reproducing RFC-004 §17 without redesign)

**Platform administrator only (§13.N).** **Corrected in this round — exact order, authority before any entitlement-state read:** Workspace lock → `actorUserId` validated as a platform administrator (else `AuthorizationException`, before the current allocation or any catalog row is ever read — a non-administrator never learns the Workspace's current slot allocation) → current assignment/allocation read → (non-complimentary increase only) catalog row locked → validation → write. Core/Growth: `{0,1,2}` only. Agency: `0` only (else `InvalidAdditionalBusinessSlotsException`). Increasing for a **non-complimentary** assignment locks the currently-referenced catalog row (`findForUpdate()`, after the Workspace lock — §6) and requires the now-locked catalog's `price`+`currency_id`+`additional_business_slot_price_ratio` all defined (else `UndefinedPlanPricingException`). Increasing for a **complimentary** assignment never requires pricing and acquires no catalog lock. Decreasing is always permitted regardless of pricing state and likewise acquires no catalog lock — a missing/undefined price is never turned into a blocker for a decrease. Writes `additional_business_slots_changed` with exact before/after values, dispatches `WorkspaceAdditionalBusinessSlotsChanged`. No payment collection of any kind.

### 13.J Workspace overrides (exact, reproducing RFC-004 §15 without redesign)

**Platform administrator only (§13.N).** **Corrected in this round — exact order for both methods, authority before any entitlement-state read:** Workspace lock → `actorUserId` validated as a platform administrator (else `AuthorizationException`, before any override row or entitlement state is ever read) → current override/entitlement state read → write/revert.

`createOrChangeOverride()`: known feature required (else the M1 repository's own `InvalidArgumentException`, validated as an argument check alongside authority, before the existing-override read); an `allow` state for a feature `PlatformFeatureRegistry::isAvailable()` reports `false` for is rejected (`UnavailablePlatformFeatureOverrideException`) — `deny` for an unavailable feature is permitted (redundant but harmless, RFC-004 §11). Non-empty `reason` required. First-time create vs. change-existing both durably audited (`entitlement_override_allowed`/`entitlement_override_denied`, correct `from_override_state`/`to_override_state`), dispatches `WorkspaceEntitlementOverrideChanged` — the create-vs-change distinction and the existing `from_override_state` are only ever read after the authority check has passed, so a non-administrator never learns whether an override already exists for the feature. An override, once present, completely replaces the plan-mapping answer for that feature — never partially. `revertOverride()`: authority is checked before the existing override row is ever read; deletes the row, writes `entitlement_override_reverted` recording the state it had immediately before removal, dispatches the same event.

### 13.K Business feature toggles (exact, reproducing RFC-004 §16 without redesign)

**Workspace owner or active Workspace Admin only (§13.N) — never a platform administrator by itself, and never Staff.** **Corrected in this round — both `disableBusinessFeature()` and `enableBusinessFeature()` follow this exact sequence, revalidating the authoritative Business rather than trusting the caller-supplied model (closes a staleness gap a concurrent `reassignBusiness()` move can otherwise open, §6):**

1. Capture `expectedWorkspaceId` from the caller-supplied `Business`.
2. Lock that expected Workspace (`WorkspaceRepository::findForUpdate()`).
3. Lock/reload the Business by ID (the existing `BusinessRepository::findForUpdate()` — no new repository method).
4. If the Business no longer exists: throw the existing `WorkspaceBusinessNotFoundException`.
5. If the freshly-locked Business's `workspace_id` no longer equals `expectedWorkspaceId`: throw the existing `BusinessWorkspaceMismatchException`.
6. Only after that authoritative match succeeds, evaluate Workspace owner-or-active-Admin authority (§13.N) against the locked Workspace.
7. Use the freshly-locked Business for every remaining decision/toggle operation — never the stale caller-supplied model.
8. `disableBusinessFeature()` calls `decide()` using the locked Workspace, the locked Business, `$feature->value`, and `actorUserId`.
9. The toggle row is created/deleted against the freshly-locked Business.

This deliberately matches `reassignBusiness()`'s own existing Workspace→Business lock direction exactly (§6) — never a Business→Workspace inversion — and reuses its exact two existing exceptions verbatim; **no new exception class and no new repository method are introduced.**

Disable-only — absence means enabled subject to Workspace entitlement. Owner-or-active-Admin authority (RFC-003 §7.3, reused unmodified — `WorkspaceManager`'s existing `assertActorIsOwnerOrActiveAdmin()`-equivalent check, using `WorkspaceMembershipRepository::findByWorkspaceAndUser()` against the locked Workspace, step 6 above). Known feature required. The Workspace must currently be effectively entitled to the feature (via `decide()`, step 8 above) before a disable row can be created — there would be nothing meaningful to disable otherwise. Create/remove only — event-only (`BusinessFeatureToggleChanged`), **no** durable transition row, matching RFC-004 §21's own explicit "lower-stakes, more frequent than a Workspace-level change" justification for this one deliberate exception.

### 13.L Catalog pricing mutation guard (exact, reproducing RFC-004 §12.5 without redesign)

**Platform administrator only (§13.N).** `updateCatalogPricing(WorkspacePlanCatalog $catalog, ?string $price, ?int $currencyId, int $actorUserId): WorkspacePlanCatalog` — **corrected in this round: `$actorUserId` is a required 4th parameter**, checked first via `UserRepository`'s `users.is_admin` lookup (the same platform-administrator authority every other mutation in §13.N uses; a non-administrator actor is denied via the same `Illuminate\Auth\Access\AuthorizationException` convention `BusinessManager::assertOwnership()` already uses in this codebase — no new exception file). Exact order, **never acquiring a Workspace lock** (§6): platform-administrator authority check → the target catalog row is locked (`WorkspacePlanCatalogRepository::findForUpdate()`) → **`$price` decimal-string validation (below) against the `DECIMAL(16,2)` column, corrected in this round from a `?float` parameter** → `price`/`currency_id` are always both-null or both-populated on the now-locked row — setting exactly one is rejected at the application layer → when clearing both (transitioning a defined price to null/null), `WorkspacePlanAssignmentRepository::hasNonComplimentaryForCatalogForUpdate($catalog->id)` is checked **while the catalog lock is still held, using a locking/current read** (`lockForUpdate()`, not an ordinary consistent-snapshot `exists()` query), and a `true` result is rejected (`PlanCatalogPricingInUseException`) — a complimentary-only-referenced or unreferenced row may always be cleared → the locked, normalized-string row is updated via `WorkspacePlanCatalogRepository::update()` → commit. `EntitlementManager` never issues a raw query against `workspace_plan_assignments` itself for this check — it only ever calls the repository method. **Why the locking/current-read semantic is required**: the platform-administrator authority check (via `UserRepository`) runs before the catalog lock is acquired and may itself establish a transaction read view under MySQL/InnoDB REPEATABLE READ; without an explicit locking/current read, a non-complimentary assignment that committed on another connection while this call was waiting on the catalog row lock could be invisible to an ordinary snapshot `exists()` query, letting the clear incorrectly succeed against a row that is, at commit time, actually in use. No final product price is invented anywhere in M2 — every seed/test value stays `null`/`null` unless a test scenario explicitly needs a defined price to exercise the guard itself, in which case it uses an obviously-synthetic placeholder value, never a claimed real price. No HTTP surface exists yet to reach this method — M2 tests call it directly.

**Corrected in this round — exact `$price` decimal-string validation (read-only audit finding: the model's `'price' => 'decimal:2'` cast already returns a string, never a float; matching, not inventing, Laravel's own float-precision-avoidance design for `DECIMAL` columns).** `$price === null` is handled entirely by the both-null-or-both-populated rule above and skips every rule below. A non-null `$price` is validated and normalized entirely as a string — **no step in this validation or the write path ever casts through PHP `float`**:

1. Input must contain ordinary decimal digits only, with an optional decimal point and 1-2 fractional digits — no other characters.
2. Scientific notation (e.g. `"4.9e1"`) is rejected.
3. A leading `+` or `-` sign is rejected outright (see rule 4 — this is stricter than "reject negative," it rejects an explicit `+` too).
4. **Negative values are invalid** — a plan price has no legitimate negative case; RFC-004 §12.5 is silent on sign, so this is a new, narrow invariant this correction adds, not an inference from existing schema/product evidence.
5. Whitespace-containing or blank strings (including `""` and `" 49.00 "`) are rejected — `""` is a distinct invalid case from `null`, never treated as "no price."
6. Comma-containing strings (e.g. `"49,00"`) are rejected — no thousands-separator support.
7. The integer and fractional components are split on the decimal point (if present).
8. Leading zeroes in the integer component are normalized away, preserving a single `"0"` (e.g. `"00049.50"` → `"49.50"`; `"00"` → `"0"`).
9. The normalized integer component may contain at most **14 digits** (the `DECIMAL(16,2)` schema's 16 total digits minus 2 fractional digits) — more is rejected.
10. A missing fractional component is normalized to `"00"` (e.g. `"49"` → `"49.00"`).
11. A single fractional digit is normalized by padding one trailing zero (e.g. `"49.5"` → `"49.50"`).
12. Exactly two fractional digits are preserved exactly.
13. **Three or more fractional digits are invalid** — exceeds the column's scale of 2.
14. The resulting canonical value is always `integer.frac` with exactly two fractional digits — this exact string (never a float-derived re-serialization) is what is passed to `WorkspacePlanCatalogRepository::update()`.
15. `"0"`/`"0.00"`/equivalent zero forms are valid and normalize to `"0.00"` — no special rejection for zero.
16. Maximum valid boundary: `"99999999999999.99"` (14 nines, `.99`).
17. First invalid boundary: a 15-digit normalized integer component (e.g. `"100000000000000.00"`) — rejected by rule 9.

Any rejection under rules 1-6, 9, or 13 throws the same established global `\InvalidArgumentException` convention already selected for this contract's other input-shape rejections (§12) — a stable, descriptive message identifying the exact malformed input, no new exception class. No new value-object/file is introduced for this validation — it lives entirely inside the already-authorized `EntitlementManager.php`.

**Examples — accepted and normalized:** `"49"` → `"49.00"`; `"49.5"` → `"49.50"`; `"00049.50"` → `"49.50"`; `"0"` → `"0.00"`; `"99999999999999.99"` → `"99999999999999.99"` (unchanged, already canonical).

**Examples — rejected:** `""`; `"-1.00"`; `"+1.00"`; `"4.9e1"`; `"49.999"`; `"100000000000000.00"`; `" 49.00 "`; `"49,00"`.

### 13.M Retirement of `WorkspaceFirstBusinessInput` (RFC-004 v1.3 §17.5)

`createWorkspace()`'s signature becomes `createWorkspace(int $ownerUserId, string $name): Workspace` — the `?WorkspaceFirstBusinessInput $firstBusiness` parameter, its `createForCustomerInWorkspace()` branch, and the now-unreachable `BusinessAssignedToWorkspace::dispatch()` call inside it are removed. `app/DTO/Workspace/WorkspaceFirstBusinessInput.php` is deleted. `WorkspaceCreated` dispatch is unaffected. No automatic complimentary plan is invented for an ordinarily-created Workspace — it may legitimately remain unassigned; any Business-creation attempt against it is fail-closed via `assertCanCreateAnotherBusiness()`'s own `workspace_plan_unassigned` denial, exactly like any other unassigned Workspace.

### 13.N Actor authority split (added in this correction)

Every `EntitlementManager` **mutation** method requires an `actorUserId` and enforces exactly one of two independent authority rules — never a HTTP-layer concern, never re-derived by any caller:

- **Platform administrator only** — `assignFirstPlan()` (§13.E), `changePlan()` (§13.F), `changePlanStatus()` (§13.G), `grantComplimentaryStatus()`/`revokeComplimentaryStatus()` (§13.H), `setAdditionalBusinessSlots()` (§13.I), `createOrChangeOverride()`/`revertOverride()` (§13.J), `updateCatalogPricing()` (§13.L, corrected in this round to require `actorUserId`). Authority is checked via `UserRepository`'s existing lookup of `actorUserId`, testing `users.is_admin` — the same, already-established administrator truth `EnsureUserIsAdministrator` already uses, never a new or parallel authorization mechanism. A non-administrator actor is denied before any entitlement table is read or written, via `Illuminate\Auth\Access\AuthorizationException` — the same framework exception class `BusinessManager::assertOwnership()` already throws for its own authorization failure in this codebase; no new exception file is introduced for this correction. **Corrected in this round — exact ordering made to actually match this rule:** for every Workspace-scoped mutation in this list, the Workspace row lock (`WorkspaceRepository::findForUpdate()`) may precede the authority check — `workspaces` is tenancy state, not one of RFC-004's own entitlement tables, so locking it first reveals nothing entitlement-specific — but the authority check itself always occurs immediately after the Workspace lock and strictly before any `workspace_plan_assignments`/`workspace_plan_catalog`/`workspace_entitlement_overrides` row is read, and strictly before any catalog row lock is acquired (§13.E-§13.J each now state their own exact order explicitly). A non-administrator actor must never be able to infer, through a more state-specific exception or no-op than the authority denial itself, whether a Workspace already has a plan, whether catalog pricing exists, its current status, its complimentary state, its slot allocation, or its override state. `updateCatalogPricing()` (§13.L) remains the one method with no target Workspace at all — its authority check is necessarily its own first step, before its catalog lock, and this was already correct.
- **Workspace owner or active Workspace Admin only** — `disableBusinessFeature()`/`enableBusinessFeature()` (§13.K). Authority is checked via `WorkspaceMembershipRepository::findByWorkspaceAndUser()`, reusing RFC-003 §7.3's already-established rule exactly (owner always qualifies; an active membership with role Admin qualifies; Staff and inactive Admin do not) — never a platform-administrator requirement, and never satisfied by platform-administrator status alone absent Workspace owner/Admin standing.
- **Read/decision methods** (`decide()`, `decideBusinessSlotCapacity()`, `assertCanCreateAnotherBusiness()`) establish **no** tenancy authorization of any kind (§7.1) — RFC-003's own authorization remains an entirely independent precondition, already required to have passed before either is called.
- **The narrow legacy auto-provisioning compatibility assignment** (`createLegacyOnboardingCompatibilityAssignment()`, §13.D) is a **system-only internal path** with a `null` actor — reachable only from `WorkspaceManager::provisionWorkspaceRecord()`'s own specific legacy-provisioning integration already defined by RFC-004 v1.3, never invocable as a normal actor-driven self-grant by any Workspace owner, member, or administrator, and never a template for any other mutation's authority.

### 13.O Legacy onboarding vs. `transferOwnership()` deadlock — bounded retry policy (added in this correction)

Repository evidence: the legacy onboarding path (§13.C) locks the owner's `users` row first, then this milestone adds a Workspace lock — `User → Workspace`. `WorkspaceManager::transferOwnership()` (RFC-003, already existing, unmodified in its own lock order) locks `Workspace → User(s) → Membership`. These are genuinely inverse lock orders across two independent operations, capable of a real cross-operation MySQL deadlock. This is resolved by a **bounded retry policy**, not by reordering either operation's locks — RFC-003's `transferOwnership()` lock order is not reopened or manually reordered by M2.

- **`BusinessManager::applyIdentity()`, CREATE path only** (`$business === null`): the outer transaction already owned by `applyIdentity()` is retried up to **3** attempts total via Laravel's existing `DB::transaction($callback, $attempts)` retry parameter, covering the resolver, the new Workspace lock (§13.C), the capacity assertion, and the Business insert as one retried unit. The update-existing-Business path is unaffected and remains at its existing single attempt. `BusinessCreated`/`BusinessUpdated` are dispatched only after `applyIdentity()` returns — i.e., only after a successful committed attempt — so a retried, ultimately-successful attempt never double-dispatches a domain event for an earlier, rolled-back attempt.
- **`WorkspaceManager::transferOwnership()`**: its existing `Workspace → User(s) → Membership` lock order is preserved exactly, unchanged — no manual reordering. Its existing transaction is likewise given up to **3** attempts via the same `DB::transaction($callback, $attempts)` mechanism, so if it is selected as the deadlock victim in a cross-operation race against a concurrent legacy-onboarding CREATE, the entire transfer is retried from the start rather than surfacing an unhandled MySQL deadlock to the caller.
- No manual sleep/backoff is introduced in production code — retry is Laravel's own built-in transaction-attempt mechanism, the same primitive already available and already idiomatic to this codebase; no new class, method, or exception is introduced by this policy.
- The specific deadlock this closes occurs during the initial lock-acquisition phase of both operations, before either performs its own domain writes or dispatches its own events — a retried attempt is therefore a clean re-run, never a partial-state resume.

---

## 14. Exact test scope

### 14.1 New `tests/Feature/Entitlement/*` files (14)

- **`EntitlementManagerDecisionTest.php`** — all precedence steps in order; **corrected in this round: `decide()`'s `string $featureKey` boundary explicitly proven** — an arbitrary unknown string (never a valid `PlatformFeature` case, e.g. `'not-a-real-feature-key'`) resolves to `platform_feature_unknown`, proven to occur before any Workspace-assignment/override/toggle lookup is ever made (e.g. by asserting the result even when the `Workspace` argument is left completely unassigned/unconfigured, which would otherwise surface a different denial key first); a syntactically known but `Planned` (unavailable) feature key resolves to `platform_feature_unavailable`; unknown/unavailable cannot be overridden into execution (an `allow` override write for `Planned` rejected; existing `deny` override for `Planned` permitted, still denies via step 2 not step 4); exact plan matrix both directions; toggle narrows only (never widens a Workspace `false`); active/inactive/suspended precedence; complimentary/status precedence (`suspended` denies even complimentary; `active`+complimentary allows without catalog pricing); `NullUsageAuthorizationGateway` always authorizes at step 9; all nine denial keys individually reachable and correctly named. **Corrected in this round — registry type-contract proof**: a known `Available` feature checked against a valid, matching Workspace/Business pair proceeds past the registry check without a `TypeError` (i.e. `PlatformFeatureRegistry::isAvailable()` is genuinely invoked with `$feature->value`, a `string`, never the enum instance). **Corrected in this round — defensive Workspace/Business consistency proofs (§7.1 steps 3-4)**: a Workspace A decided together with a Business that authoritatively belongs to Workspace B throws `BusinessWorkspaceMismatchException`, proven to occur before any Workspace-assignment/override/toggle/gateway entitlement-table read is ever made; a caller-held `Business` object loaded before a real reassignment to Workspace B can no longer be decided against the old Workspace A — the stale pair throws the same `BusinessWorkspaceMismatchException`; the same Business decided against its current Workspace B succeeds, using the freshly-reloaded Business, and follows the normal entitlement precedence (steps 5-10) exactly as any other valid pair would; a Business ID that no longer exists (deleted or invalid) throws `WorkspaceBusinessNotFoundException`; both `WorkspaceBusinessNotFoundException` and `BusinessWorkspaceMismatchException` are proven to be thrown exceptions, never a returned `EntitlementDecision` — neither adds nor replaces any of the nine stable entitlement denial keys, and the nine-key set is re-asserted unchanged by this same test file.
- **`EntitlementManagerFirstAssignmentTest.php`** — locked/unassigned-required/tier-resolved/pricing-invariant/slot-value bounds/reason/actor all required; duplicate first assignment rejected (`WorkspacePlanAlreadyAssignedException`); `plan_assigned` transition + `WorkspacePlanAssigned` event; **a non-platform-administrator actor is denied** (§13.N). **Corrected in this round — two explicit authority-precedence (state-conflict) proofs**: a non-administrator actor calling `assignFirstPlan()` against a Workspace that **already has an assignment** still receives `AuthorizationException`, never `WorkspacePlanAlreadyAssignedException`; a non-administrator actor calling it for a **non-complimentary** assignment against a catalog row with **undefined pricing** still receives `AuthorizationException`, never `UndefinedPlanPricingException` — proving the authority check runs before either piece of entitlement state is ever read. **Corrected in this round — inactive-destination-catalog proofs (RFC-004 §10.1)**: a **paid** first assignment to an inactive destination catalog tier is rejected (`InvalidArgumentException`); a **complimentary** first assignment to an inactive destination catalog tier is also rejected (proving complimentary is pricing-exempt but not `is_active`-exempt); an active destination still succeeds under the existing pricing rules, unaffected by this correction; the inactive-catalog denial occurs after platform-admin authority, so a non-administrator actor calling `assignFirstPlan()` against an inactive destination still receives `AuthorizationException` first, never `InvalidArgumentException`.
- **`EntitlementManagerLegacyCompatibilityAssignmentTest.php`** — only reachable via `provisionWorkspaceRecord()`; exact fixed reason string; `additional_business_slots = 0`; null actor; `plan_assigned` transition (not `complimentary_granted`); event dispatched; never reachable for an ordinarily-created Workspace; **direct proof it cannot be invoked as a normal actor-driven self-grant by any Workspace owner, member, or platform administrator — no actor-supplied path reaches it** (§13.D/§13.N).
- **`EntitlementManagerChangePlanTest.php`** — all three direction normalizations (Core↔Growth preserves; Core/Growth→Agency resets to 0 atomically with both transitions in one call; Agency→Core/Growth defaults 0 or allocates 1/2 subject to pricing); **corrected in this round: destination catalog pricing (`price`+`currency_id`) is required for every non-complimentary destination in all three directions, independently of whether `additional_business_slots` changes — a Core↔Growth change with an undefined destination price is rejected (`UndefinedPlanPricingException`) even when slots stay `null`, not only on a slot increase**; a **complimentary** plan change in any direction remains fully exempt from this base pricing requirement; the additional-slot pricing check (§13.I) still applies on top of the base requirement when a slot increase also occurs; existing Businesses never removed; grandfathered over-capacity; `additional_business_slots_changed` fires only when the value actually changed; **a non-platform-administrator actor is denied** (§13.N). **Corrected in this round — authority-precedence (state-conflict) proof**: a non-administrator actor requesting a plan change whose destination catalog pricing is undefined still receives `AuthorizationException`, never `UndefinedPlanPricingException`. **Corrected in this round — inactive-destination-catalog proofs (RFC-004 §10.1)**: a **non-complimentary** change **into** an inactive destination tier is rejected (`InvalidArgumentException`); a **complimentary** change **into** an inactive destination tier is also rejected (complimentary is pricing-exempt but not `is_active`-exempt); a change **away from** a current assignment already referencing an inactive catalog, into an **active** destination, remains allowed and unaffected by this correction; an inactive destination catalog is proven distinct from an `inactive`/`suspended` assignment `status` — the two are never confused by the same test; a non-administrator actor requesting a change into an inactive destination still receives `AuthorizationException` first, before ever learning the destination's active state.
- **`EntitlementManagerChangePlanStatusTest.php`** — actor/reason mandatory (both omissions rejected); all six real active/inactive/suspended transitions; the same-status no-op writes no transition/event but still validates actor authority/reason first; **a non-platform-administrator actor is denied** (§13.N). **Corrected in this round — authority-precedence (state-conflict) proof**: a non-administrator actor requesting the assignment's **own current status** (an authorized no-op for an administrator) still receives `AuthorizationException`, never a silent no-op — proving the authority check runs before the current status is ever read.
- **`EntitlementManagerComplimentaryStatusTest.php`** — reason required on grant; actor required both directions; correct transitions/events; does not bypass inactive/suspended or capacity; `grantComplimentaryStatus()` requires no catalog pricing and acquires no catalog lock under any condition; **corrected in this round: `revokeComplimentaryStatus()` against a catalog row with undefined `price`/`currency_id` is rejected (`UndefinedPlanPricingException`), and the assignment remains complimentary and unchanged — proven separately from a successful revoke against a catalog row with defined pricing, which proceeds with the existing transition/event behavior**; **a non-platform-administrator actor is denied for both grant and revoke** (§13.N). **Corrected in this round — authority-precedence (state-conflict) proof**: a non-administrator actor calling `revokeComplimentaryStatus()` against a catalog row with undefined pricing still receives `AuthorizationException`, never `UndefinedPlanPricingException`.
- **`EntitlementManagerAdditionalSlotsTest.php`** — Core/Growth `{0,1,2}` only, Agency `0` only; increase-for-non-complimentary requires pricing; increase-for-complimentary does not; decrease always permitted; exact before/after audit + event; **a non-platform-administrator actor is denied** (§13.N). **Corrected in this round — authority-precedence (state-conflict) proof**: a non-administrator actor requesting a non-complimentary increase against a catalog row with undefined pricing still receives `AuthorizationException`, never `UndefinedPlanPricingException`.
- **`EntitlementManagerOverrideTest.php`** — known-feature required; `allow` for unavailable rejected, `deny` for unavailable permitted; reason/actor required; precedence over plan mapping; all three transition types with correct before/after state; event; **a non-platform-administrator actor is denied for create/change/revert** (§13.N). **Corrected in this round — authority-precedence (state-conflict) proof**: a non-administrator actor writing an `allow` override for a feature `PlatformFeatureRegistry::isAvailable()` reports `false` for still receives `AuthorizationException`, never `UnavailablePlatformFeatureOverrideException`.
- **`EntitlementManagerBusinessToggleTest.php`** — must be currently entitled to create a disable row; disable-only (never a grant); create/remove only; event fires, no transition row is ever written; **full owner-or-active-Admin authority matrix**: ordinary Workspace Staff denied; an active Workspace Admin allowed; the Workspace owner allowed; an inactive Admin denied — and a platform administrator with no Workspace owner/Admin standing is also denied, since this mutation is never platform-administrator-gated (§13.K/§13.N). **Corrected in this round — stale-Business authoritative-revalidation proofs**: a stale `Business` object captured before a real `reassignBusiness()` move cannot `disableBusinessFeature()` using authority derived from the old Workspace — the call fails closed with `BusinessWorkspaceMismatchException` before any toggle row is created; the same stale object cannot `enableBusinessFeature()` (remove an existing toggle) using old-Workspace authority either, failing the same way before the toggle row is deleted; both proofs assert the failure occurs before any toggle mutation, not merely that the end result differs; a freshly-loaded `Business` in the new Workspace succeeds using only the new Workspace's owner-or-active-Admin authority, and an old-Workspace owner/Admin with no standing in the new Workspace is denied even against the freshly-loaded Business.
- **`EntitlementManagerCatalogPricingTest.php`** — `updateCatalogPricing()`'s four-parameter signature including `actorUserId`; both-null-or-both-populated; clearing blocked while a non-complimentary assignment references the row, via `hasNonComplimentaryForCatalogForUpdate()`'s locking/current read evaluated while the catalog row is locked; complimentary references never block clearing; `UndefinedPlanPricingException`/`PlanCatalogPricingInUseException` both reachable; **a non-platform-administrator actor is denied** (`Illuminate\Auth\Access\AuthorizationException`, §13.N) before the catalog row is even locked. **Corrected in this round — authority-precedence (state-conflict) proof**: a non-administrator actor attempting to clear pricing on a catalog row still referenced by a non-complimentary assignment still receives `AuthorizationException`, never `PlanCatalogPricingInUseException` — this method's ordering was already correct (no target Workspace, §13.L), so this proof confirms the existing behavior rather than fixing a defect. **Corrected in this round — exact decimal-string validation/normalization proofs (§13.L, `?string $price`, no float anywhere in the path)**: integer-form input (`"49"`) persists and re-reads as `"49.00"`; one-fractional-digit input (`"49.5"`) persists and re-reads as `"49.50"`; leading-zero input (`"00049.50"`) normalizes to `"49.50"`; `"0"`/`"0.00"` is accepted and normalizes to `"0.00"`; a negative string (`"-1.00"`) is rejected (`InvalidArgumentException`); a leading `+` (`"+1.00"`) is rejected; scientific notation (`"4.9e1"`) is rejected; a blank string (`""`) is rejected, distinctly from `null`; a whitespace-padded string (`" 49.00 "`) is rejected; a comma-containing string (`"49,00"`) is rejected; a three-fractional-digit string (`"49.999"`) is rejected; the maximum valid boundary (`"99999999999999.99"`) is accepted exactly; the first over-precision boundary (`"100000000000000.00"`, 15 integer digits) is rejected; the persisted maximum-boundary value re-reads as the exact same string through `WorkspacePlanCatalog`'s `'price' => 'decimal:2'` cast — proving no float round-trip occurred anywhere in the write or read path.
- **`EntitlementManagerBusinessSlotCapacityTest.php`** — included 1st/2nd/3rd succeed with zero allocation; 4th requires allocation (`business_slot_allocation_required`); 4th succeeds with slot 1; 5th requires slot 2 (denied with only slot 1); 5th succeeds with slot 2; 6th always denied regardless of allocation; Agency unlimited; inactive Business rows still consume slots; grandfathered over-capacity keeps every Business and still denies further creation; **an ordinary customer-created empty Workspace does NOT silently receive a complimentary plan** — remains unassigned, and a Business-creation attempt against it fails closed with `workspace_plan_unassigned`.
- **`NullUsageAuthorizationGatewayTest.php`** — always authorizes, for an arbitrary Business/feature pair.
- **`EntitlementManagerConcurrencyTest.php`** — **corrected in this round: the nine scenarios in §6** (the original five slot-capacity scenarios, the two catalog-serialization races — scenario 6, catalog-clear-vs-paid-`assignFirstPlan()`; scenario 7, catalog-clear-vs-`revokeComplimentaryStatus()` — scenario 8, reassign-vs-toggle, and scenario 9, legacy-onboarding-vs-`transferOwnership()`), using the existing proven runner-script and lock-probe patterns; one new inline/Support runner only if the existing two (`concurrent_backfill_runner.php`, `concurrent_workspace_resolver_runner.php`) cannot express a real `createBusinessInWorkspace()`/`reassignBusiness()` race — confirmed they cannot (neither invokes Business-creation or reassignment), so exactly one new file is authorized: `tests/Feature/Entitlement/Support/concurrent_business_slot_runner.php`, following `concurrent_backfill_runner.php`'s exact bootstrap/database-guard/exit-code shape. **Scenarios 6 and 7 are expressed by extending this same authorized runner file with an additional selectable race mode (a third CLI argument alongside its existing slow/plain mode selector, e.g. `catalog-clear`/`assign`/`revoke`), never a second Support filename** — the existing scenario-selection argument convention already generalizes to a catalog-row race without requiring new bootstrap/database-guard/exit-code plumbing, so no new file is structurally necessary and none is authorized. **Corrected in this round: scenarios 6 and 7 must each explicitly prove that the waiter observes the winner's committed state once the catalog lock is acquired** — not merely that exactly one operation succeeds. Scenario 6 proves that if the catalog-clear commits first, the waiting `assignFirstPlan()` call's own `hasNonComplimentaryForCatalogForUpdate()`-guarded pricing check (§13.E) fails closed against the now-cleared row (`UndefinedPlanPricingException`); and, run the other direction, that if `assignFirstPlan()` commits first, the waiting `updateCatalogPricing()` clear's `hasNonComplimentaryForCatalogForUpdate()` locking read correctly observes the newly-committed non-complimentary assignment and fails closed (`PlanCatalogPricingInUseException`) rather than racing ahead on a stale snapshot. Scenario 7 proves the identical pair of outcomes for `revokeComplimentaryStatus()` in place of `assignFirstPlan()`. **Scenario 8 (new in this round, §6/§13.K)** proves the reassign-vs-toggle race: a concurrent `reassignBusiness()` move racing `disableBusinessFeature()`/`enableBusinessFeature()` against the same Business never lets an old-Workspace actor mutate the toggle after the Business has authoritatively moved — either outcome is acceptable (the toggle genuinely completes before the move commits, or the toggle call's own Business lock observes the moved `workspace_id` and fails closed with `BusinessWorkspaceMismatchException`), but a toggle mutation succeeding using old-Workspace authority *after* the Business has moved is the one outcome the test must prove can never happen. Expressed via the same authorized `concurrent_business_slot_runner.php`, extended with an additional selectable race mode — no new Support filename. **Scenario 9 (new in this round, §6/§13.O)** proves the legacy-onboarding-vs-`transferOwnership()` deadlock-retry policy: a real concurrent race between `BusinessManager::applyIdentity()`'s legacy CREATE path and `WorkspaceManager::transferOwnership()` against the same owner/Workspace deliberately exercises the inverse `User → Workspace` / `Workspace → User(s)` lock order — the test proves no unhandled MySQL deadlock escapes the bounded 3-attempt retry, no partial ownership transfer, no duplicate Business creation, no slot over-allocation, the final Business belongs to a valid authoritative Workspace, the final Workspace ownership/membership state is internally consistent, and the retry remains bounded (the test does not hang). Expressed via the same authorized `concurrent_business_slot_runner.php`, extended with a further selectable race mode — no new Support filename. **`tests/Feature/Workspace/WorkspaceManagerConcurrencyTest.php` is explicitly not modified for scenario 9 or any other purpose in this round** — it is not one of the 64 authorized paths, and its existing `resolveLegacyOnboardingWorkspace()` serialization coverage remains untouched; scenario 9's proof lives entirely inside the already-authorized `EntitlementManagerConcurrencyTest.php`.
- **`NoRawEntitlementTableQueryTest.php`** — source-scans `app/` confirming no raw query against any of the six RFC-004 tables exists outside `EntitlementManager` and its repositories, except the already-approved M1 migration/backfill historical exception (`WorkspaceEntitlementBackfillV1`, `..._120007_seed_...php`) — mirrors `WorkspaceM1BBoundaryTest.php`'s own `phpFilesUnder()`-based source-scan technique, not a new scanning mechanism.

### 14.2 Modified M1 repository tests (3)

- `WorkspacePlanCatalogRepositoryTest.php` — add `update()` round-trip coverage.
- `WorkspacePlanAssignmentRepositoryTest.php` — add `update()` round-trip coverage. **Corrected in this round:** add coverage for `hasNonComplimentaryForCatalogForUpdate()` proving it reports `true`/`false` correctly for complimentary vs. non-complimentary references, and — where practical within a single-process feature test — proving its locking/current-read semantics (e.g. asserting the query issued uses `lockForUpdate()`/`FOR UPDATE`, or, following `WorkspaceManagerConcurrencyTest.php`'s own second-connection lock-probe pattern, proving a concurrent transaction holding the row lock blocks this call rather than returning a stale/snapshot answer immediately).
- `WorkspaceEntitlementOverrideRepositoryTest.php` — add `update()` round-trip coverage.

### 14.3 Modified existing Workspace/Business tests (6, corrected in this round from 4 — read-only audit finding)

- `WorkspaceBusinessOrchestrationTest.php` — add: final-slot-available succeeds; full target denies (`BusinessSlotLimitExceededException`); unassigned target denies (`WorkspacePlanUnassignedException`); inactive-plan target denies (`InactiveWorkspacePlanException`); suspended-plan target denies (`SuspendedWorkspacePlanException`); source/destination isolation; same-target no-op still a no-op (unaffected by capacity); existing opposite-direction lock-order test still passes unmodified. **Corrected in this round — pre-existing fixture compatibility, explicitly required, not merely "add" new coverage**: this file's own `$this->createWorkspace(...)`-based fixture Workspaces are unassigned by construction (RFC-004 v1.3 §17.5), and the large majority of this file's pre-existing successful-creation/successful-reassignment methods will otherwise begin failing closed with `WorkspacePlanUnassignedException` purely because M2 activates capacity enforcement — not because of any actual behavior regression in what each test is asserting. Every pre-existing method whose fixture setup or assertion path relies on a **successful** `createBusinessInWorkspace()`/`reassignBusiness()` call against a Workspace that is not itself the subject of an intentional unassigned/inactive/suspended/full-target denial test receives an explicit valid complimentary Core `workspace_plan_assignments` row as fixture setup, established via `EntitlementManager::assignFirstPlan()`/`createLegacyOnboardingCompatibilityAssignment()`-equivalent direct fixture insertion (not through any new HTTP surface — none exists) before the method's own create/reassign call. Every method whose own purpose is to test an unassigned/inactive/suspended/full-target denial keeps its Workspace intentionally unassigned/inactive/suspended/full, exactly as today — this correction never removes an existing negative-path proof. `CreatesWorkspaceTestData.php` is **not** modified — each affected method receives its own inline fixture-assignment line, keeping the shared trait's existing unassigned-by-default behavior intact for every other consumer.
- `WorkspaceLifecycleTest.php` — remove the four `WorkspaceFirstBusinessInput`-based test methods (retired capability); remove `BusinessAssignedToWorkspace::class` from `ALL_LIFECYCLE_EVENTS` (no longer reachable from this file's own remaining tests).
- `WorkspaceM1BBoundaryTest.php` — `test_workspace_manager_has_no_unapproved_milestone_2_methods()`'s exact expected method list gains exactly one new name, `lockForLegacyOnboardingBusinessCreation` (RFC-003's own "Milestone 2" numbering is unrelated to RFC-004's — this is the one new public `WorkspaceManager` method M2 adds).
- `BusinessManagerTest.php` — add: legacy onboarding against an existing at-capacity Workspace denies; legacy onboarding against a newly-auto-provisioned Workspace receives the compatibility assignment then successfully creates its first Business. **No generic fixture conversion is required or authorized for this file** — its legacy-onboarding success paths already receive M2's own auto-provisioned compatibility assignment (§13.D) before capacity is ever checked; every other method in this file uses `createBusinessWithWorkspace()`, which bypasses `WorkspaceManager`/`BusinessManager::applyIdentity()` entirely and never reaches an M2-instrumented method.
- **`WorkspaceBusinessCreationHttpTest.php`** (added in this round, read-only audit finding — absent from the prior 58-path list despite carrying success-path fixtures this milestone directly breaks): Workspaces used by the cases that are expected to **successfully** create a Business (via `POST customer.workspaces.businesses.store` → `createBusinessInWorkspace()`) receive an explicit valid complimentary Core `workspace_plan_assignments` row as fixture setup before the HTTP request — established through `EntitlementManager` using a **distinct platform-admin fixture actor** (never the test's own customer/owner/admin actor under test), so this fixture step never alters or expands the HTTP endpoint's own Workspace/customer authority semantics. Tests whose purpose is unrelated-user/Staff/inactive-Admin/inactive-Workspace/validation denial keep their existing authority meaning completely unchanged — none of them is converted into an entitlement test. This file also gains (or retains, if already implicitly covered) a focused proof that an otherwise-fully-authorized Business-creation attempt against an **intentionally** unassigned Workspace fails closed with `WorkspacePlanUnassignedException` under M2, distinguishing "denied for authority reasons" from "denied for entitlement reasons" explicitly. `CreatesWorkspaceTestData.php` is not modified.
- **`WorkspaceBusinessReassignmentHttpTest.php`** (added in this round, same audit finding): destination Workspaces used by cases expected to **accept** a real cross-Workspace reassignment (via `POST customer.workspaces.businesses.reassign` → `reassignBusiness()`) receive the same explicit valid complimentary Core fixture assignment, via the same distinct platform-admin fixture actor. Workspaces specifically used to test an unassigned-target denial remain **intentionally** unassigned — this correction never globally auto-assigns every Workspace fixture in this file, only the ones whose test purpose requires a successful reassignment to occur. `CreatesWorkspaceTestData.php` is not modified.

### 14.4 Deleted test (1)

- `tests/Unit/Workspace/WorkspaceFirstBusinessInputTest.php` — the DTO it tests no longer exists.

### 14.5 Constructor-compatibility test/support updates (4, added in this round — §5.1)

- `tests/Feature/Opportunity/ExecuteOpportunityActionJobTest.php` — both `new class(...) extends BusinessManager` double constructions gain `app(EntitlementManager::class)` as a sixth positional argument; no other change to this file's own assertions.
- `tests/Feature/Opportunity/OpportunityActionExecutorTest.php` — the same two-site fix.
- `tests/Feature/Workspace/Support/SlowWorkspaceManager.php` — gains an `EntitlementManager $entitlementManager` constructor parameter (before `$holdSeconds`) and forwards it into `parent::__construct()`.
- `tests/Feature/Workspace/Support/concurrent_workspace_resolver_runner.php` — its `'slow'` branch's `new SlowWorkspaceManager(...)` call gains the matching new positional `$app->make(EntitlementManager::class)` argument.

None of these four files exercises new RFC-004 business logic — this is a pure mechanical compatibility update, and each file's own pre-existing test intent and assertions are otherwise unchanged.

---

## 15. No-raw-query boundary (RFC-004 §20, reaffirmed)

No direct query against `workspace_plan_catalog`, `workspace_plan_features`, `workspace_plan_assignments`, `workspace_entitlement_overrides`, `business_feature_toggles`, or `workspace_entitlement_transitions` exists anywhere outside `EntitlementManager` and its repositories, with exactly one already-approved historical exception: M1's own migration/backfill code (`WorkspaceEntitlementBackfillV1` and the seed/DDL migrations), which is query-builder-only by RFC-004 §25's own explicit, already-authorized design and is not reopened by M2. Verified by `NoRawEntitlementTableQueryTest.php` (§14.1), not merely believed.

---

## 16. Exact authorized implementation paths

**Sixty-four unique paths total, corrected in this round from 58 (read-only audit finding, §5.1/§14.3/§14.5 — no production-path change).** Category subtotals sum exactly: 6 Library/Entitlement core + 7 events + 10 exceptions + 1 provider binding + 8 repository extensions (4 pairs) + 2 manager integrations + 1 retirement (deleted) = **35 app-side, unchanged from the prior round**, plus 14 new Entitlement tests + 1 new concurrency-test support file + 3 modified M1 repository tests + 6 modified Workspace/Business tests (corrected in this round from 4) + 1 deleted test + 4 constructor-compatibility test/support updates (new in this round) = **29 test-side**. **35 + 29 = 64.**

### Library/Entitlement core (6 new)

1. `app/Library/Entitlement/EntitlementManager.php`
2. `app/Library/Entitlement/EntitlementDecision.php`
3. `app/Library/Entitlement/BusinessSlotCapacityDecision.php`
4. `app/Library/Entitlement/Contracts/UsageAuthorizationGateway.php`
5. `app/Library/Entitlement/NullUsageAuthorizationGateway.php`
6. `app/Library/Entitlement/UsageAuthorizationResult.php`

### Events (7 new)

7. `app/Events/Entitlement/WorkspacePlanAssigned.php`
8. `app/Events/Entitlement/WorkspacePlanChanged.php`
9. `app/Events/Entitlement/WorkspacePlanStatusChanged.php`
10. `app/Events/Entitlement/WorkspaceComplimentaryStatusChanged.php`
11. `app/Events/Entitlement/WorkspaceAdditionalBusinessSlotsChanged.php`
12. `app/Events/Entitlement/WorkspaceEntitlementOverrideChanged.php`
13. `app/Events/Entitlement/BusinessFeatureToggleChanged.php`

### Exceptions (10 new)

14. `app/Exceptions/Entitlement/WorkspacePlanUnassignedException.php`
15. `app/Exceptions/Entitlement/InactiveWorkspacePlanException.php`
16. `app/Exceptions/Entitlement/SuspendedWorkspacePlanException.php`
17. `app/Exceptions/Entitlement/BusinessSlotAllocationRequiredException.php`
18. `app/Exceptions/Entitlement/BusinessSlotLimitExceededException.php`
19. `app/Exceptions/Entitlement/UndefinedPlanPricingException.php`
20. `app/Exceptions/Entitlement/PlanCatalogPricingInUseException.php`
21. `app/Exceptions/Entitlement/InvalidAdditionalBusinessSlotsException.php`
22. `app/Exceptions/Entitlement/WorkspacePlanAlreadyAssignedException.php`
23. `app/Exceptions/Entitlement/UnavailablePlatformFeatureOverrideException.php`

### Provider binding (1 modified)

24. `app/Providers/AppServiceProvider.php` — exactly one additive line (`UsageAuthorizationGateway::class => NullUsageAuthorizationGateway::class`) plus its two required `use` imports. No other line changes.

### Repository extensions (8 modified — 4 pairs)

25. `app/Repositories/Contracts/BusinessRepository.php`
26. `app/Repositories/Eloquent/EloquentBusinessRepository.php`
27. `app/Repositories/Contracts/WorkspacePlanCatalogRepository.php`
28. `app/Repositories/Eloquent/EloquentWorkspacePlanCatalogRepository.php`
29. `app/Repositories/Contracts/WorkspacePlanAssignmentRepository.php`
30. `app/Repositories/Eloquent/EloquentWorkspacePlanAssignmentRepository.php`
31. `app/Repositories/Contracts/WorkspaceEntitlementOverrideRepository.php`
32. `app/Repositories/Eloquent/EloquentWorkspaceEntitlementOverrideRepository.php`

### Manager integration (2 modified)

33. `app/Library/Workspace/WorkspaceManager.php`
34. `app/Library/Business/BusinessManager.php`

### Retirement (1 deleted)

35. `app/DTO/Workspace/WorkspaceFirstBusinessInput.php`

### New Entitlement tests (14 new)

36. `tests/Feature/Entitlement/EntitlementManagerDecisionTest.php`
37. `tests/Feature/Entitlement/EntitlementManagerFirstAssignmentTest.php`
38. `tests/Feature/Entitlement/EntitlementManagerLegacyCompatibilityAssignmentTest.php`
39. `tests/Feature/Entitlement/EntitlementManagerChangePlanTest.php`
40. `tests/Feature/Entitlement/EntitlementManagerChangePlanStatusTest.php`
41. `tests/Feature/Entitlement/EntitlementManagerComplimentaryStatusTest.php`
42. `tests/Feature/Entitlement/EntitlementManagerAdditionalSlotsTest.php`
43. `tests/Feature/Entitlement/EntitlementManagerOverrideTest.php`
44. `tests/Feature/Entitlement/EntitlementManagerBusinessToggleTest.php`
45. `tests/Feature/Entitlement/EntitlementManagerCatalogPricingTest.php`
46. `tests/Feature/Entitlement/EntitlementManagerBusinessSlotCapacityTest.php`
47. `tests/Feature/Entitlement/NullUsageAuthorizationGatewayTest.php`
48. `tests/Feature/Entitlement/EntitlementManagerConcurrencyTest.php`
49. `tests/Feature/Entitlement/NoRawEntitlementTableQueryTest.php`

### New concurrency-test support (1 new)

50. `tests/Feature/Entitlement/Support/concurrent_business_slot_runner.php`

### Modified M1 repository tests (3 modified)

51. `tests/Feature/Entitlement/WorkspacePlanCatalogRepositoryTest.php`
52. `tests/Feature/Entitlement/WorkspacePlanAssignmentRepositoryTest.php`
53. `tests/Feature/Entitlement/WorkspaceEntitlementOverrideRepositoryTest.php`

### Modified existing Workspace/Business tests (6 modified, corrected in this round from 4)

54. `tests/Feature/Workspace/WorkspaceBusinessOrchestrationTest.php`
55. `tests/Feature/Workspace/WorkspaceLifecycleTest.php`
56. `tests/Feature/Workspace/WorkspaceM1BBoundaryTest.php`
57. `tests/Feature/Business/BusinessManagerTest.php`
58. `tests/Feature/Workspace/WorkspaceBusinessCreationHttpTest.php` (added in this round, §14.3)
59. `tests/Feature/Workspace/WorkspaceBusinessReassignmentHttpTest.php` (added in this round, §14.3)

### Deleted test (1 deleted)

60. `tests/Unit/Workspace/WorkspaceFirstBusinessInputTest.php`

### Constructor-compatibility test/support updates (4 modified, new category added in this round, §5.1/§14.5)

61. `tests/Feature/Opportunity/ExecuteOpportunityActionJobTest.php`
62. `tests/Feature/Opportunity/OpportunityActionExecutorTest.php`
63. `tests/Feature/Workspace/Support/SlowWorkspaceManager.php`
64. `tests/Feature/Workspace/Support/concurrent_workspace_resolver_runner.php`

**No unrelated path may be authorized.** If M2's own implementation discovers a genuine need for a path not listed above, that is a STOP-and-report condition for a bounded contract amendment — not something to add silently. No controllers/routes/views/permissions (M3). No migration unless direct inspection proves an M1 schema defect makes M2 impossible (STOP and report if so — do not silently add schema). No legacy Plan/Subscription file. No re-run, scheduling, or invocation of `workspaces:backfill-entitlements`/`WorkspaceEntitlementBackfillV1` is authorized as part of M2 (§23) — neither file, nor `BackfillWorkspaceEntitlementsCommand.php`, nor the M1 migration, nor either's existing test is touched by this contract.

---

## 17. Exact human-run regression commands

```
php artisan test tests/Unit/Entitlement tests/Feature/Entitlement
php artisan test tests/Unit/Workspace tests/Feature/Workspace
php artisan test tests/Unit/Business tests/Feature/Business
php artisan test --stop-on-failure
```

Every command must exit successfully and discover a positive test count — a zero/"no tests found" result is a failure. Claude must never claim any of them passed if PHP is unavailable in its own environment, and must never invent a test count. The actual results supplied by the human must be recorded honestly before M2 is considered complete.

---

## 18. Stop/gap rule

If, during M2's own implementation, repository evidence is found that conflicts materially with RFC-004 v1.3 or with this contract as specified — making M2 unsafe or impossible as written — **implementation must STOP and report**: the exact conflict, the section it contradicts, the repository evidence found, and a proposed bounded correction. Do not silently revise the RFC. Do not implement around the gap. Minor implementation-detail choices this contract intentionally leaves to ordinary repository convention (exact PHPDoc style, exact private-method decomposition) may be resolved directly without triggering this rule — the line is whether a *structural* fact this contract asserts turns out to be wrong.

---

## 19. Correction-round policy

`maximum_correction_rounds: 2` — matching every prior RFC-003/RFC-004 contract's bounded-correction discipline exactly. A correction round stays inside the exact §16 path list; it does not expand scope.

---

## 20. Governance

Locked:

- `human_only_merge: true`
- `maximum_correction_rounds: 2`
- `advance_automatically: false`
- `start_automatically_after_contract_merge: false`
- `codex_review_required_for_completion: false`
- `automatic_model_handoff_required: false`

No paid model API or usage-credit requirement at any step. No force push. No push directly to `main`. No RFC-004 tag during M2, at any point.

**Implementation authorization semantics after contract merge:** human merge of this contract (bundled with the RFC-004 v1.3 correction) authorizes exactly one bounded M2 implementation branch/PR (`agent/rfc-004-m2`, created from the then-current `main` containing this merge) — directly, with no further governance artifact required. No target-marker PR, no inert implementation PR, no separate authorization PR. This does **not** mean implementation starts automatically — a human still explicitly decides to begin it.

**M2 completion must not automatically start M3.** M3 requires its own separate, human-reviewed, bounded contract, exactly as this contract required after RFC-004 M1's own completion.

---

## 21. M2 completion criteria

M2 is complete only when:

- RFC-004 v1.3 (bundled) and this contract are human-merged;
- the M2 implementation PR stayed inside the exact 64 authorized paths (§16), with no unrelated file touched;
- `EntitlementManager` and every method in §7/§13 are complete and match this contract exactly;
- the `UsageAuthorizationGateway` seam and its Null implementation are bound and complete;
- capacity enforcement is integrated at all three identified count-increasing operations (§13.A/§13.B/§13.C), including the newly-auto-provisioned-Workspace compatibility assignment (§13.D);
- `WorkspaceFirstBusinessInput` is retired (§13.M);
- the four constructor-compatibility test/support files build and pass (§5.1/§14.5);
- the six modified Workspace/Business tests (§14.3) — including the two newly-added HTTP test files — pass with entitlement fixtures established as specified, and every intentional unassigned/inactive/suspended/full-target denial proof remains intact;
- no unresolved GAP/BLOCKED item exists (§18);
- all four required human-run regression commands (§17) pass, with actual results honestly recorded — not fabricated, not assumed;
- `git diff --check` is clean;
- human review is complete and the implementation PR is human-merged.

**No RFC-004 tag is created at any point during M2.** **No automatic M3 start occurs.** No separate M2 closure PR is required by default — a closure document may be produced if the human reviewer wants one, but this contract does not mandate it as a blocking gate.

**Corrected in this round — code-complete/merge is distinct from production activation (§23):** the criteria above define when M2 may be considered **code-complete and human-merged**. They do **not** by themselves authorize deploying/activating M2's entitlement enforcement in a customer-facing production environment — that is a separate, later release-gate decision governed entirely by §23, not by this section.

## 22. M3 non-authorization statement

This contract authorizes **M2 only**. It does not authorize, propose, or select RFC-004 Milestone 3 (admin/customer HTTP surfaces, new permission keys, or capability-gating integration for Prospect Outreach/white-label). M3 requires its own separate, human-reviewed, bounded contract, drafted only after M2 is itself complete and closed.

---

## 23. M2/M3 production release gate (added in this round, read-only audit finding — this is a release/deployment gate, not a runtime entitlement bypass)

**M2 may be implemented, tested, and human-merged under this contract. M2's entitlement capacity enforcement MUST NOT be independently activated in a customer-facing production environment before M3 is ready.**

**Why:** ordinary Workspace creation intentionally leaves the Workspace unassigned (RFC-004 v1.3 §17.5 — a deliberate decision, not an oversight, and not reopened by M2). Once M2's capacity enforcement is active, that first Business-creation attempt against such a Workspace correctly, deliberately fails closed with `WorkspacePlanUnassignedException` (§13.A, RFC-004 §17). M2 introduces **no** normal plan-assignment HTTP/admin/customer surface (§4) — so, standing alone, activating M2 in production would leave every ordinarily-created Workspace (existing or newly created) permanently unable to create its first Business, with no self-service or admin remedy, until M3 ships one. This is a correct consequence of M2's own fail-closed design, not a bug — but it makes independent activation unsafe.

**Explicit production release sequence:**

1. Complete and human-merge M2 (§21 — code-complete/merge only).
2. Prepare and implement M3 under its own separate, human-reviewed, bounded contract (§22 — not authorized by this document).
3. M3 must provide the legitimate ordinary plan-assignment surface (admin/customer HTTP, per RFC-004 §29).
4. M3's own contract must define the one-time production catch-up for Workspaces that remain unassigned at the point of combined activation — the exact catch-up mechanism (whether reusing `WorkspaceEntitlementBackfillV1`, a new M3-scoped mechanism, or something else) is **not selected or authorized by this M2 contract** and is left entirely to M3's own drafting process.
5. Activate/deploy the entitlement enforcement (M2) and the assignment surface (M3) **together**, not M2 alone.

**Explicitly not authorized by this section or anywhere else in this contract, per direct instruction:**

- Rerunning `workspaces:backfill-entitlements` / `WorkspaceEntitlementBackfillV1` as an M2 activation or catch-up step.
- Adding a scheduler entry or any periodic re-run of that command.
- Auto-assigning ordinary newly-created Workspaces a complimentary Core plan.
- Any new runtime feature flag or config toggle to bypass or defer entitlement enforcement.
- Any modification to `WorkspaceEntitlementBackfillV1.php`, `BackfillWorkspaceEntitlementsCommand.php`, either's existing tests, or the M1 migration.

**This section does not authorize M3 implementation now.** It is only an explicit release dependency, recorded so the M2/M3 sequencing gap is never silently papered over with an undesigned bypass or a quiet RFC-004 v1.3 §17.5 reversal. M3 still requires its own separate, human-reviewed contract after M2's completion, exactly as §22 already states.

**Implementation is not authorized under this document until it is human-reviewed and merged.**
