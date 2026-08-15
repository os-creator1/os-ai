<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMembershipBusiness;
use App\Models\WorkspacePlanCatalog;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real concurrency coverage for the eleven scenarios in §6, using genuinely
 * independent OS processes (never sequential coincidence), following
 * WorkspaceManagerConcurrencyTest.php's own proven cross-process pattern.
 * Deliberately does NOT use RefreshDatabase — a genuinely separate process
 * needs committed rows, which an open RefreshDatabase transaction would
 * hide entirely. Fixture rows are inserted directly (auto-committed) and
 * explicitly cleaned up in tearDown().
 */
class EntitlementManagerConcurrencyTest extends TestCase
{
    private const RUNNER = __DIR__ . '/Support/concurrent_business_slot_runner.php';

    private array $createdUserIds = [];
    private array $createdWorkspaceIds = [];
    private ?array $originalCoreCatalogState = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Scenarios 6/7 mutate the shared seeded Core catalog row's
        // price/currency_id (via updateCatalogPricing()) on committed,
        // independent connections — this class deliberately does not use
        // RefreshDatabase, so those writes are never rolled back. Snapshot
        // here (every test, not just 6/7) and restore in tearDown() so a
        // race outcome that leaves the row non-null-priced never leaks into
        // later tests, regardless of which scenario ran or how it asserted.
        $this->originalCoreCatalogState = (array) DB::table('workspace_plan_catalog')->where('tier', 'core')->first();
    }

    protected function tearDown(): void
    {
        if ($this->originalCoreCatalogState !== null) {
            DB::table('workspace_plan_catalog')->where('tier', 'core')->update([
                'price' => $this->originalCoreCatalogState['price'],
                'currency_id' => $this->originalCoreCatalogState['currency_id'],
                'additional_business_slot_price_ratio' => $this->originalCoreCatalogState['additional_business_slot_price_ratio'],
            ]);
        }

        if ($this->createdWorkspaceIds !== []) {
            $businessIds = DB::table('businesses')->whereIn('workspace_id', $this->createdWorkspaceIds)->pluck('id');
            DB::table('business_feature_toggles')->whereIn('business_id', $businessIds)->delete();

            // workspace_membership_businesses restrictOnDelete()s against
            // both businesses and workspace_memberships — scenario 10's
            // "no source cleanup on denial" proof creates a Selected-scope
            // grant that must be cleared before either child is deleted.
            DB::table('workspace_membership_businesses')->whereIn('business_id', $businessIds)->delete();

            // workspace_transitions (RFC-003) restrictOnDelete()s against
            // businesses (business_id) AND workspaces (workspace_id,
            // from_workspace_id) — reassignBusiness()/transferOwnership()
            // write rows here, so both FKs must be cleared before the
            // Business and Workspace deletes below.
            DB::table('workspace_transitions')
                ->whereIn('business_id', $businessIds)
                ->orWhereIn('workspace_id', $this->createdWorkspaceIds)
                ->orWhereIn('from_workspace_id', $this->createdWorkspaceIds)
                ->delete();

            DB::table('businesses')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
            DB::table('workspace_entitlement_transitions')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
            DB::table('workspace_plan_assignments')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
            DB::table('workspace_memberships')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
            DB::table('workspaces')->whereIn('id', $this->createdWorkspaceIds)->delete();
        }

        if ($this->createdUserIds !== []) {
            DB::table('customers')->whereIn('user_id', $this->createdUserIds)->delete();
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }

        parent::tearDown();
    }

    private function phpBinary(): string
    {
        return (new PhpExecutableFinder())->find() ?: 'php';
    }

    private function createAdminUserId(): int
    {
        $id = DB::table('users')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'first_name' => 'Admin', 'last_name' => 'User',
            'email' => 'admin' . uniqid() . '@example.test', 'status' => true, 'is_admin' => true,
            'is_customer' => false, 'active_portal' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->createdUserIds[] = $id;

        return $id;
    }

    private function createOwnerUserId(): int
    {
        $id = DB::table('users')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'first_name' => 'Owner', 'last_name' => 'User',
            'email' => 'owner' . uniqid() . '@example.test', 'status' => true, 'is_admin' => false,
            'is_customer' => true, 'active_portal' => 'customer', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->createdUserIds[] = $id;

        return $id;
    }

    private function createWorkspace(int $ownerUserId): Workspace
    {
        $workspace = Workspace::create(['name' => 'Concurrency Workspace ' . uniqid(), 'owner_user_id' => $ownerUserId, 'is_active' => true]);
        $this->createdWorkspaceIds[] = $workspace->id;

        return $workspace;
    }

    /**
     * reassignBusiness() requires the actor to be owner/active-Admin of
     * BOTH the source and destination Workspace (RFC-003) — grants a
     * source-Workspace owner Admin standing in the destination so the
     * concurrent reassignment attempt is genuinely authorized rather than
     * failing closed on UnauthorizedWorkspaceManagementException.
     */
    private function grantDestinationAuthority(Workspace $destinationWorkspace, int $sourceOwnerUserId): void
    {
        WorkspaceMembership::create([
            'workspace_id' => $destinationWorkspace->id, 'user_id' => $sourceOwnerUserId,
            'role' => WorkspaceMembershipRole::Admin, 'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'is_active' => true,
        ]);
    }

    private function assignAtBoundary(Workspace $workspace, int $existingBusinesses, int $slots = 2): void
    {
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $this->createAdminUserId(), 'Concurrency fixture.', true, $slots);

        $customer = Customer::firstOrCreate(['user_id' => $workspace->owner_user_id]);

        for ($i = 0; $i < $existingBusinesses; $i++) {
            app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, [
                'name' => "Existing {$i}-" . uniqid(), 'industry' => 'photo_booth_service', 'country_code' => 'US', 'timezone' => 'America/New_York', 'currency_code' => 'USD',
            ]);
        }
    }

    /**
     * Deterministic lock-winner-forcing primitive (test-only — no
     * production sleep/backoff, see the runner's own `hold-then` docblock).
     * Starts a holder process that takes explicit row locks — in the exact
     * order $lockSpecs lists them — holds them for $holdSeconds, then runs
     * $delegateModeAndArgs's real production logic inside that same held
     * transaction. $lockSpecs MUST be the exact prefix of the delegate's
     * own real production lock order (e.g. Workspace-then-catalog for a
     * paid assignFirstPlan()/revokeComplimentaryStatus() holder, or
     * User-then-Workspace for a legacy-create holder) — never a single
     * later row locked in isolation, which would silently invert the
     * production method's real order and invalidate the proof. Returns
     * only once the holder has confirmed ("LOCKED") every listed lock is
     * genuinely acquired, so the caller's very next process — attempting
     * the same final row — is guaranteed to observe real contention rather
     * than racing on scheduler luck.
     *
     * @param  array<int, array{0: string, 1: string, 2: int|string}>  $lockSpecs
     * @param  array<int, string>  $delegateModeAndArgs
     */
    private function startHolderAndWaitForLock(array $lockSpecs, int $holdSeconds, array $delegateModeAndArgs): Process
    {
        $lockSpecsRaw = implode('|', array_map(
            static fn (array $spec): string => "{$spec[0]}:{$spec[1]}:{$spec[2]}",
            $lockSpecs
        ));

        $holder = new Process(array_merge(
            [$this->phpBinary(), self::RUNNER, 'hold-then', $lockSpecsRaw, (string) $holdSeconds],
            $delegateModeAndArgs
        ));
        $holder->start();

        $deadline = microtime(true) + 5.0;

        while (! str_contains($holder->getOutput(), 'LOCKED') && microtime(true) < $deadline) {
            usleep(50_000);
        }

        $this->assertStringContainsString('LOCKED', $holder->getOutput(), 'Holder process never signaled lock acquisition: ' . $holder->getErrorOutput());

        return $holder;
    }

    // Scenario 1: create + create racing a destination Workspace's final slot.
    public function test_scenario_1_create_plus_create_racing_final_slot(): void
    {
        $owner = $this->createOwnerUserId();
        $workspace = $this->createWorkspace($owner);
        $this->assignAtBoundary($workspace, 4);
        $unrelatedOwner = $this->createOwnerUserId();
        $unrelatedWorkspace = $this->createWorkspace($unrelatedOwner);
        $this->assignAtBoundary($unrelatedWorkspace, 0, 0);

        $customer = Customer::where('user_id', $owner)->first();

        $p1 = new Process([$this->phpBinary(), self::RUNNER, 'create-business', (string) $workspace->id, (string) $customer->id, (string) $owner]);
        $p2 = new Process([$this->phpBinary(), self::RUNNER, 'create-business', (string) $workspace->id, (string) $customer->id, (string) $owner]);
        $p1->start();
        $p2->start();
        $p1->wait();
        $p2->wait();

        $successCount = (int) $p1->isSuccessful() + (int) $p2->isSuccessful();
        $this->assertSame(1, $successCount, 'Exactly one create-business attempt must succeed. P1: ' . $p1->getErrorOutput() . ' P2: ' . $p2->getErrorOutput());
        $this->assertSame(5, DB::table('businesses')->where('workspace_id', $workspace->id)->count());
        $this->assertSame(0, DB::table('businesses')->where('workspace_id', $unrelatedWorkspace->id)->count(), 'Unrelated Workspace must be unaffected.');
    }

    // Scenario 2: create + reassign racing the same destination Workspace's final slot.
    public function test_scenario_2_create_plus_reassign_racing_final_slot(): void
    {
        $owner = $this->createOwnerUserId();
        $workspace = $this->createWorkspace($owner);
        $this->assignAtBoundary($workspace, 4);
        $customer = Customer::where('user_id', $owner)->first();

        $sourceOwner = $this->createOwnerUserId();
        $sourceWorkspace = $this->createWorkspace($sourceOwner);
        $this->assignAtBoundary($sourceWorkspace, 1, 0);
        $movingBusiness = DB::table('businesses')->where('workspace_id', $sourceWorkspace->id)->first();

        $p1 = new Process([$this->phpBinary(), self::RUNNER, 'create-business', (string) $workspace->id, (string) $customer->id, (string) $owner]);
        $p2 = new Process([$this->phpBinary(), self::RUNNER, 'reassign-business', (string) $movingBusiness->id, (string) $workspace->id, (string) $sourceOwner]);
        $p1->start();
        $p2->start();
        $p1->wait();
        $p2->wait();

        $successCount = (int) $p1->isSuccessful() + (int) $p2->isSuccessful();
        $this->assertSame(1, $successCount, 'Exactly one attempt must succeed. P1: ' . $p1->getErrorOutput() . ' P2: ' . $p2->getErrorOutput());
        $this->assertSame(5, DB::table('businesses')->where('workspace_id', $workspace->id)->count());
    }

    // Scenario 6: catalog-clear vs paid assignFirstPlan — both lock-winner
    // directions forced deterministically (§6/§13.E, contract-required).
    public function test_scenario_6_catalog_clear_vs_paid_assign_first_plan(): void
    {
        $currencyId = Currency::create(['name' => 'USD', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
        $catalog = WorkspacePlanCatalog::where('tier', 'core')->first();
        $admin = $this->createAdminUserId();
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '49.00', $currencyId, $admin);

        // Direction A: catalog-clear holds the catalog row lock first and
        // commits null/null pricing; the waiting paid assignFirstPlan()
        // must then observe the cleared pricing and fail closed.
        $ownerA = $this->createOwnerUserId();
        $workspaceA = $this->createWorkspace($ownerA);

        $holderA = $this->startHolderAndWaitForLock([['workspace_plan_catalog', 'id', $catalog->id]], 1, ['catalog-clear', (string) $catalog->id, (string) $admin]);
        $waiterA = new Process([$this->phpBinary(), self::RUNNER, 'assign-first-plan', (string) $workspaceA->id, 'core', (string) $admin, '0']);
        $waiterA->start();
        $holderA->wait();
        $waiterA->wait();

        $this->assertTrue($holderA->isSuccessful(), 'Direction A holder (catalog-clear) must succeed. ' . $holderA->getErrorOutput());
        $this->assertFalse($waiterA->isSuccessful(), 'Direction A waiter (paid assignFirstPlan) must fail once it observes the cleared pricing.');
        $this->assertStringContainsString('UndefinedPlanPricingException', $waiterA->getErrorOutput());
        $this->assertDatabaseMissing('workspace_plan_assignments', ['workspace_id' => $workspaceA->id]);
        $this->assertNull($catalog->fresh()->price, 'Direction A: pricing must remain cleared.');

        // Restore pricing so Direction B starts from the same known,
        // fully-defined baseline as Direction A did.
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '49.00', $currencyId, $admin);

        // Direction B: assignFirstPlan()'s real lock order is
        // Workspace -> catalog (never the reverse) — the holder must
        // reproduce that exact prefix itself, not just grab the catalog
        // row in isolation, or it would silently invert production's real
        // order. Locking Workspace-then-catalog here and only THEN
        // invoking the real assignFirstPlan() (whose own internal
        // re-acquisition of both is a no-op) is equivalent to production
        // genuinely pausing right after acquiring both locks, in its own
        // order, immediately before performing its writes — a faithful,
        // realistic timing scenario. The waiting catalog-clear must then
        // observe the committed non-complimentary reference via
        // hasNonComplimentaryForCatalogForUpdate() and fail closed.
        $ownerB = $this->createOwnerUserId();
        $workspaceB = $this->createWorkspace($ownerB);

        $holderB = $this->startHolderAndWaitForLock([['workspaces', 'id', $workspaceB->id], ['workspace_plan_catalog', 'id', $catalog->id]], 1, ['assign-first-plan', (string) $workspaceB->id, 'core', (string) $admin, '0']);
        $waiterB = new Process([$this->phpBinary(), self::RUNNER, 'catalog-clear', (string) $catalog->id, (string) $admin]);
        $waiterB->start();
        $holderB->wait();
        $waiterB->wait();

        $this->assertTrue($holderB->isSuccessful(), 'Direction B holder (paid assignFirstPlan) must succeed. ' . $holderB->getErrorOutput());
        $this->assertFalse($waiterB->isSuccessful(), 'Direction B waiter (catalog-clear) must fail once it observes the committed non-complimentary assignment.');
        $this->assertStringContainsString('PlanCatalogPricingInUseException', $waiterB->getErrorOutput());
        $this->assertDatabaseHas('workspace_plan_assignments', ['workspace_id' => $workspaceB->id, 'is_complimentary' => false]);
        $this->assertNotNull($catalog->fresh()->price, 'Direction B: pricing must remain defined.');
    }

    // Scenario 7: catalog-clear vs revokeComplimentaryStatus — both
    // lock-winner directions forced deterministically (§6/§13.H).
    public function test_scenario_7_catalog_clear_vs_revoke_complimentary(): void
    {
        $currencyId = Currency::create(['name' => 'USD', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
        $catalog = WorkspacePlanCatalog::where('tier', 'core')->first();
        $admin = $this->createAdminUserId();
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '49.00', $currencyId, $admin);

        // Direction A: catalog-clear holds the catalog lock first and
        // commits null/null pricing; the waiting revokeComplimentaryStatus()
        // must then observe the cleared pricing and fail closed, leaving
        // the assignment complimentary.
        $ownerA = $this->createOwnerUserId();
        $workspaceA = $this->createWorkspace($ownerA);
        app(EntitlementManager::class)->assignFirstPlan($workspaceA, WorkspacePlanTier::Core, $admin, 'Fixture.', true, 0);

        $holderA = $this->startHolderAndWaitForLock([['workspace_plan_catalog', 'id', $catalog->id]], 1, ['catalog-clear', (string) $catalog->id, (string) $admin]);
        $waiterA = new Process([$this->phpBinary(), self::RUNNER, 'revoke-complimentary', (string) $workspaceA->id, (string) $admin]);
        $waiterA->start();
        $holderA->wait();
        $waiterA->wait();

        $this->assertTrue($holderA->isSuccessful(), 'Direction A holder (catalog-clear) must succeed. ' . $holderA->getErrorOutput());
        $this->assertFalse($waiterA->isSuccessful(), 'Direction A waiter (revoke) must fail once it observes the cleared pricing.');
        $this->assertStringContainsString('UndefinedPlanPricingException', $waiterA->getErrorOutput());
        $this->assertDatabaseHas('workspace_plan_assignments', ['workspace_id' => $workspaceA->id, 'is_complimentary' => true]);
        $this->assertNull($catalog->fresh()->price, 'Direction A: pricing must remain cleared.');

        // Restore pricing so Direction B starts from the same known,
        // fully-defined baseline as Direction A did.
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '49.00', $currencyId, $admin);

        // Direction B: revokeComplimentaryStatus()'s real lock order is
        // also Workspace -> catalog — the holder reproduces that exact
        // prefix itself (see scenario 6 Direction B's identical rationale)
        // rather than pre-locking the catalog row in isolation, which
        // would silently invert production's real order. The waiting
        // catalog-clear must then observe the committed non-complimentary
        // state and fail closed.
        $ownerB = $this->createOwnerUserId();
        $workspaceB = $this->createWorkspace($ownerB);
        app(EntitlementManager::class)->assignFirstPlan($workspaceB, WorkspacePlanTier::Core, $admin, 'Fixture.', true, 0);

        $holderB = $this->startHolderAndWaitForLock([['workspaces', 'id', $workspaceB->id], ['workspace_plan_catalog', 'id', $catalog->id]], 1, ['revoke-complimentary', (string) $workspaceB->id, (string) $admin]);
        $waiterB = new Process([$this->phpBinary(), self::RUNNER, 'catalog-clear', (string) $catalog->id, (string) $admin]);
        $waiterB->start();
        $holderB->wait();
        $waiterB->wait();

        $this->assertTrue($holderB->isSuccessful(), 'Direction B holder (revoke) must succeed. ' . $holderB->getErrorOutput());
        $this->assertFalse($waiterB->isSuccessful(), 'Direction B waiter (catalog-clear) must fail once it observes the committed non-complimentary state.');
        $this->assertStringContainsString('PlanCatalogPricingInUseException', $waiterB->getErrorOutput());
        $this->assertDatabaseHas('workspace_plan_assignments', ['workspace_id' => $workspaceB->id, 'is_complimentary' => false]);
        $this->assertNotNull($catalog->fresh()->price, 'Direction B: pricing must remain defined.');
    }

    // Scenario 8: reassign-vs-toggle — both serialized outcomes forced
    // deterministically and genuinely proven (§6/§13.K).
    public function test_scenario_8_reassign_vs_toggle(): void
    {
        // Direction A: toggle-disable holds the source Workspace lock first
        // and completes while the Business still authoritatively belongs to
        // the source Workspace; the waiting reassignment then proceeds
        // normally afterward (the toggle never blocks a reassignment).
        $sourceOwnerA = $this->createOwnerUserId();
        $sourceWorkspaceA = $this->createWorkspace($sourceOwnerA);
        $this->assignAtBoundary($sourceWorkspaceA, 1, 0);
        $businessA = DB::table('businesses')->where('workspace_id', $sourceWorkspaceA->id)->first();

        $targetOwnerA = $this->createOwnerUserId();
        $targetWorkspaceA = $this->createWorkspace($targetOwnerA);
        $this->assignAtBoundary($targetWorkspaceA, 0, 0);
        $this->grantDestinationAuthority($targetWorkspaceA, $sourceOwnerA);

        // disableBusinessFeature()'s real lock order is source
        // Workspace -> Business (lockWorkspaceAndBusinessForToggle()) — a
        // single-row pre-lock on the source Workspace is therefore an
        // exact, faithful prefix of toggle's own real first lock; its
        // Business lock is left for the delegate's own natural, undisturbed
        // acquisition.
        $holderA = $this->startHolderAndWaitForLock([['workspaces', 'id', $sourceWorkspaceA->id]], 1, ['toggle-disable', (string) $businessA->id, PlatformFeature::Crm->value, (string) $sourceOwnerA]);
        $waiterA = new Process([$this->phpBinary(), self::RUNNER, 'reassign-business', (string) $businessA->id, (string) $targetWorkspaceA->id, (string) $sourceOwnerA]);
        $waiterA->start();
        $holderA->wait();
        $waiterA->wait();

        $this->assertTrue($holderA->isSuccessful(), 'Direction A holder (toggle-disable) must succeed while the Business is still in the source Workspace. ' . $holderA->getErrorOutput());
        $this->assertTrue($waiterA->isSuccessful(), 'Direction A waiter (reassign) must succeed afterward — a toggle row never blocks a reassignment. ' . $waiterA->getErrorOutput());
        $this->assertDatabaseHas('business_feature_toggles', ['business_id' => $businessA->id, 'feature_key' => 'crm']);
        $this->assertSame($targetWorkspaceA->id, (int) DB::table('businesses')->where('id', $businessA->id)->value('workspace_id'), 'Direction A: the Business must have completed its move to the target Workspace after the toggle.');

        // Direction B: reassignBusiness() holds the source Workspace lock
        // first and completes the move; the waiting old-Workspace
        // toggle-disable attempt must then observe the Business no longer
        // belongs to the source Workspace and fail closed, creating no
        // toggle row, before ever touching one.
        $sourceOwnerB = $this->createOwnerUserId();
        $sourceWorkspaceB = $this->createWorkspace($sourceOwnerB);
        $this->assignAtBoundary($sourceWorkspaceB, 1, 0);
        $businessB = DB::table('businesses')->where('workspace_id', $sourceWorkspaceB->id)->first();

        $targetOwnerB = $this->createOwnerUserId();
        $targetWorkspaceB = $this->createWorkspace($targetOwnerB);
        $this->assignAtBoundary($targetWorkspaceB, 0, 0);
        $this->grantDestinationAuthority($targetWorkspaceB, $sourceOwnerB);

        // reassignBusiness()'s real lock order is ascending-ID-sorted
        // (source, target) Workspaces -> Business. sourceWorkspaceB was
        // created strictly before targetWorkspaceB above, so its
        // auto-increment ID is deliberately guaranteed lower — proven
        // explicitly rather than left incidental — meaning source
        // genuinely IS the first Workspace reassignBusiness() itself would
        // lock in this fixture; a single-row pre-lock on it is therefore a
        // faithful prefix, not an inversion.
        $this->assertLessThan($targetWorkspaceB->id, $sourceWorkspaceB->id, 'Fixture invariant: source must have the lower Workspace ID so it is genuinely the first lock reassignBusiness() itself would take.');
        $holderB = $this->startHolderAndWaitForLock([['workspaces', 'id', $sourceWorkspaceB->id]], 1, ['reassign-business', (string) $businessB->id, (string) $targetWorkspaceB->id, (string) $sourceOwnerB]);
        $waiterB = new Process([$this->phpBinary(), self::RUNNER, 'toggle-disable', (string) $businessB->id, PlatformFeature::Crm->value, (string) $sourceOwnerB]);
        $waiterB->start();
        $holderB->wait();
        $waiterB->wait();

        $this->assertTrue($holderB->isSuccessful(), 'Direction B holder (reassign) must succeed. ' . $holderB->getErrorOutput());
        $this->assertFalse($waiterB->isSuccessful(), 'Direction B waiter (toggle-disable) must fail once it observes the Business has already moved.');
        $this->assertStringContainsString('BusinessWorkspaceMismatchException', $waiterB->getErrorOutput());
        $this->assertDatabaseMissing('business_feature_toggles', ['business_id' => $businessB->id]);
        $this->assertSame($targetWorkspaceB->id, (int) DB::table('businesses')->where('id', $businessB->id)->value('workspace_id'), 'Direction B: the Business must have completed its move to the target Workspace.');
    }

    // Scenario 9: legacy-onboarding vs transferOwnership (bounded deadlock retry).
    public function test_scenario_9_legacy_onboarding_vs_transfer_ownership(): void
    {
        $owner = $this->createOwnerUserId();
        $workspace = $this->createWorkspace($owner);
        $this->assignAtBoundary($workspace, 0, 0);
        $newOwner = $this->createOwnerUserId();

        // The legacy-create call must target the SAME owner/Workspace being
        // transferred — $owner directly owns exactly one Workspace
        // ($workspace), so resolveLegacyOnboardingWorkspace() resolves it as
        // the sole fallback candidate rather than auto-provisioning a new,
        // unrelated one. Only this genuine row overlap exercises the
        // inverse User->Workspace (legacy-create) vs Workspace->User(s)
        // (transferOwnership) lock order this scenario exists to prove.
        $customer = Customer::where('user_id', $owner)->first();

        $p1 = new Process([$this->phpBinary(), self::RUNNER, 'transfer-ownership', (string) $workspace->id, (string) $owner, (string) $newOwner]);
        $p2 = new Process([$this->phpBinary(), self::RUNNER, 'legacy-create', (string) $customer->id]);
        // Bounded execution: neither subprocess may hang past the bounded
        // 3-attempt retry policy actually resolving the deadlock.
        $p1->setTimeout(30);
        $p2->setTimeout(30);
        $p1->start();
        $p2->start();
        $p1->wait();
        $p2->wait();

        // No unhandled deadlock: a genuine MySQL deadlock (SQLSTATE 40001)
        // must never surface as the final failure reason for either side —
        // the bounded 3-attempt retry must have resolved it.
        $this->assertStringNotContainsStringIgnoringCase('Deadlock found', $p1->getErrorOutput());
        $this->assertStringNotContainsStringIgnoringCase('Deadlock found', $p2->getErrorOutput());

        $this->assertTrue($p1->isSuccessful(), 'transfer-ownership must succeed once retried past any deadlock. ' . $p1->getErrorOutput());
        $this->assertTrue($p2->isSuccessful(), 'legacy-create must succeed once retried past any deadlock. ' . $p2->getErrorOutput());

        $this->assertSame($newOwner, (int) Workspace::find($workspace->id)->owner_user_id, 'Ownership transfer must have completed exactly once.');

        // Cross-check against the runner's own reported result first:
        // EloquentBusinessRepository::createForCustomerInWorkspace() stores
        // $customer->user_id (NOT $customer->id) in businesses.customer_id
        // — a prior round's correction wrongly assumed customer_id
        // referenced customers.id, which made the query below always miss.
        $this->assertMatchesRegularExpression('/^OK business_id=\d+ workspace_id=\d+$/', trim($p2->getOutput()), 'legacy-create must report a real created Business. ' . $p2->getOutput());

        $legacyBusinessWorkspaceId = DB::table('businesses')->where('customer_id', $customer->user_id)->value('workspace_id');
        $this->assertNotNull($legacyBusinessWorkspaceId, 'The legacy-onboarding Business must have landed in a real, authoritative Workspace.');
        $this->assertSame(1, DB::table('businesses')->where('customer_id', $customer->user_id)->count(), 'No duplicate Business creation.');

        // No slot over-allocation: the resolved Workspace's own capacity
        // (3 included slots, per assignAtBoundary(workspace, 0, 0)) is
        // never exceeded by the single legacy Business created here.
        $this->assertSame(1, DB::table('businesses')->where('workspace_id', $legacyBusinessWorkspaceId)->count(), 'No slot over-allocation — exactly one Business must exist in the resolved Workspace.');

        // No partial ownership-transfer state: the Workspace row is not
        // duplicated, and the previous owner holds no lingering active
        // membership — consistent with the Deactivate disposition actually
        // selected for this transfer.
        $this->assertSame(1, DB::table('workspaces')->where('id', $workspace->id)->count(), 'Exactly one canonical Workspace row must exist — no partial/duplicate transfer state.');
        $this->assertDatabaseMissing('workspace_memberships', ['workspace_id' => $workspace->id, 'user_id' => $owner, 'is_active' => true]);

        if ($legacyBusinessWorkspaceId !== null && $legacyBusinessWorkspaceId !== $workspace->id) {
            $this->createdWorkspaceIds[] = $legacyBusinessWorkspaceId;
        }
    }

    // Scenario 10: reassign + reassign racing the same destination
    // Workspace's final slot — deterministic loser, exact exception, and an
    // observable "no source cleanup" proof (§6, RFC-004 §17.2).
    public function test_scenario_10_reassign_plus_reassign_racing_final_slot(): void
    {
        $destOwner = $this->createOwnerUserId();
        $destWorkspace = $this->createWorkspace($destOwner);
        $this->assignAtBoundary($destWorkspace, 4);

        $sourceOwner1 = $this->createOwnerUserId();
        $sourceWorkspace1 = $this->createWorkspace($sourceOwner1);
        $this->assignAtBoundary($sourceWorkspace1, 1, 0);
        $business1 = DB::table('businesses')->where('workspace_id', $sourceWorkspace1->id)->first();
        $this->grantDestinationAuthority($destWorkspace, $sourceOwner1);

        $sourceOwner2 = $this->createOwnerUserId();
        $sourceWorkspace2 = $this->createWorkspace($sourceOwner2);
        $this->assignAtBoundary($sourceWorkspace2, 1, 0);
        $business2 = DB::table('businesses')->where('workspace_id', $sourceWorkspace2->id)->first();
        $this->grantDestinationAuthority($destWorkspace, $sourceOwner2);

        // A Selected-scope grant for business2 inside its own source
        // Workspace, so "the loser performs no source cleanup" is an
        // observable assertion (the grant must still exist afterward)
        // rather than an assumption — removeAllForBusinessInWorkspace() is
        // only ever called AFTER the capacity assertion succeeds.
        $staffUser2 = $this->createOwnerUserId();
        $staffMembership2 = WorkspaceMembership::create([
            'workspace_id' => $sourceWorkspace2->id, 'user_id' => $staffUser2,
            'role' => WorkspaceMembershipRole::Staff, 'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
            'is_active' => true,
        ]);
        WorkspaceMembershipBusiness::create(['workspace_membership_id' => $staffMembership2->id, 'business_id' => $business2->id]);

        // reassignBusiness()'s real lock order is ascending-ID-sorted
        // (source, destination) Workspaces -> Business. destWorkspace was
        // deliberately created FIRST, above, before either source
        // Workspace — proven explicitly here rather than left incidental —
        // so it genuinely IS the lower-ID, first-locked Workspace in
        // business1's real reassignBusiness() call; a single-row pre-lock
        // on it is therefore a faithful prefix, not an inversion.
        $this->assertLessThan($sourceWorkspace1->id, $destWorkspace->id, 'Fixture invariant: destination must have the lower Workspace ID so it is genuinely the first lock reassignBusiness() itself would take for business1.');

        // business1's reassignment deterministically wins the destination
        // Workspace's row lock; business2's reassignment is forced to be
        // the waiter and must observe the now-exhausted destination.
        $holder = $this->startHolderAndWaitForLock([['workspaces', 'id', $destWorkspace->id]], 1, ['reassign-business', (string) $business1->id, (string) $destWorkspace->id, (string) $sourceOwner1]);
        $waiter = new Process([$this->phpBinary(), self::RUNNER, 'reassign-business', (string) $business2->id, (string) $destWorkspace->id, (string) $sourceOwner2]);
        $waiter->start();
        $holder->wait();
        $waiter->wait();

        $this->assertTrue($holder->isSuccessful(), 'Winner (business1 reassignment) must succeed. ' . $holder->getErrorOutput());
        $this->assertFalse($waiter->isSuccessful(), 'Loser (business2 reassignment) must fail once it observes the destination at capacity.');
        $this->assertStringContainsString('BusinessSlotLimitExceededException', $waiter->getErrorOutput());

        $this->assertSame(5, DB::table('businesses')->where('workspace_id', $destWorkspace->id)->count(), 'Destination count must increase by exactly one.');
        $this->assertSame($destWorkspace->id, (int) DB::table('businesses')->where('id', $business1->id)->value('workspace_id'), 'Winner Business must be in the destination.');
        $this->assertSame($sourceWorkspace2->id, (int) DB::table('businesses')->where('id', $business2->id)->value('workspace_id'), 'Loser Business must remain in its original source Workspace.');

        // No source cleanup for the loser: assertCanCreateAnotherBusiness()
        // rejects business2's reassignment before
        // removeAllForBusinessInWorkspace() is ever reached, so this grant
        // must be completely untouched.
        $this->assertDatabaseHas('workspace_membership_businesses', ['workspace_membership_id' => $staffMembership2->id, 'business_id' => $business2->id]);

        // No unrelated Workspace effect: the winner's own source Workspace
        // correctly loses its Business (the move completed), and nothing
        // beyond the three Workspaces genuinely involved in this race
        // (source1, source2, destination) was ever touched.
        $this->assertSame(0, DB::table('businesses')->where('workspace_id', $sourceWorkspace1->id)->count(), 'Winner source Workspace must have exactly zero Businesses after its Business moved out.');
    }

    // Scenario 11: legacy onboarding + ordinary Business creation racing the
    // same destination Workspace's final slot — deterministic loser, exact
    // exception, isolation, and genuine-destination-resolution proofs
    // (§13.C).
    public function test_scenario_11_legacy_onboarding_vs_ordinary_create_racing_final_slot(): void
    {
        $owner = $this->createOwnerUserId();
        $workspace = $this->createWorkspace($owner);
        $this->assignAtBoundary($workspace, 4);
        $customer = Customer::where('user_id', $owner)->first();

        // An unrelated Workspace, otherwise untouched, to prove this race
        // never has a side effect beyond the one Workspace genuinely
        // contested.
        $unrelatedOwner = $this->createOwnerUserId();
        $unrelatedWorkspace = $this->createWorkspace($unrelatedOwner);
        $this->assignAtBoundary($unrelatedWorkspace, 0, 0);

        // The legacy resolver must deterministically resolve to this exact
        // Workspace as its single candidate: an existing primary Business
        // for this same customer, already linked to this Workspace.
        $primaryBusiness = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, [
            'name' => 'Legacy candidate anchor', 'industry' => 'photo_booth_service', 'country_code' => 'US', 'timezone' => 'America/New_York', 'currency_code' => 'USD',
        ]);
        DB::table('businesses')->where('id', $primaryBusiness->id)->update(['is_primary' => true]);
        // Recount: this anchor Business itself already counts toward the
        // boundary, so we now have 5 Businesses total (4 fixture + 1
        // anchor) — reduce fixture to 4 total including anchor, i.e. start
        // with 3 pre-existing + this anchor = 4, leaving exactly one slot.
        $extra = DB::table('businesses')->where('workspace_id', $workspace->id)->where('id', '!=', $primaryBusiness->id)->limit(1)->first();
        if ($extra !== null) {
            DB::table('businesses')->where('id', $extra->id)->delete();
        }

        $this->assertSame(4, DB::table('businesses')->where('workspace_id', $workspace->id)->count());

        // legacy-create's real lock order is the owner's User row FIRST
        // (resolveLegacyOnboardingWorkspace() -> lockOwnerRow(), whose row
        // lock is held continuously — a nested transaction's savepoint
        // release does not release it) THEN the Workspace row
        // (lockForLegacyOnboardingBusinessCreation()). Pre-locking the
        // Workspace alone, skipping the User row, would silently invert
        // that real User -> Workspace order — so the holder reproduces
        // both, in that exact order, before ever invoking the real
        // legacy-create delegate. The ordinary create waiter (which only
        // ever locks the Workspace directly, no User-row involvement) is
        // forced to be the waiter and must observe the now-exhausted
        // capacity once the holder's Workspace lock is finally released.
        $holder = $this->startHolderAndWaitForLock([['users', 'id', $owner], ['workspaces', 'id', $workspace->id]], 1, ['legacy-create', (string) $customer->id]);
        $waiter = new Process([$this->phpBinary(), self::RUNNER, 'create-business', (string) $workspace->id, (string) $customer->id, (string) $owner]);
        $waiter->start();
        $holder->wait();
        $waiter->wait();

        $this->assertTrue($holder->isSuccessful(), 'Winner (legacy-create) must succeed. ' . $holder->getErrorOutput());
        $this->assertFalse($waiter->isSuccessful(), 'Loser (ordinary create) must fail once it observes the now-exhausted destination.');
        $this->assertStringContainsString('BusinessSlotLimitExceededException', $waiter->getErrorOutput());

        // The legacy resolver genuinely resolved the intended EXISTING
        // destination — not an auto-provisioned new Workspace — proven
        // directly from the winner's own reported result.
        $this->assertMatchesRegularExpression('/^LOCKED\nOK business_id=\d+ workspace_id=' . $workspace->id . '$/', trim($holder->getOutput()), 'legacy-create must have resolved the intended existing Workspace. ' . $holder->getOutput());

        $this->assertSame(5, DB::table('businesses')->where('workspace_id', $workspace->id)->count(), 'Final count must be exactly 5, never 6.');
        $this->assertSame(1, DB::table('businesses')->where('workspace_id', $workspace->id)->where('name', 'like', 'Legacy Concurrency Business%')->count(), 'No duplicate Business — exactly one legacy Business was added.');
        $this->assertSame(0, DB::table('businesses')->where('workspace_id', $unrelatedWorkspace->id)->count(), 'Unrelated Workspace must be completely unaffected.');
    }
}
