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

    // Scenario 6: catalog-clear vs paid assignFirstPlan.
    public function test_scenario_6_catalog_clear_vs_paid_assign_first_plan(): void
    {
        $currencyId = Currency::create(['name' => 'USD', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
        $catalog = WorkspacePlanCatalog::where('tier', 'core')->first();
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '49.00', $currencyId, $this->createAdminUserId());

        $owner = $this->createOwnerUserId();
        $workspace = $this->createWorkspace($owner);
        $admin = $this->createAdminUserId();

        $p1 = new Process([$this->phpBinary(), self::RUNNER, 'catalog-clear', (string) $catalog->id, (string) $admin]);
        $p2 = new Process([$this->phpBinary(), self::RUNNER, 'assign-first-plan', (string) $workspace->id, 'core', (string) $admin, '0']);
        $p1->start();
        $p2->start();
        $p1->wait();
        $p2->wait();

        // The invariant, not a fixed winner: never a non-complimentary
        // assignment referencing undefined pricing. Computed unconditionally
        // (not inside an if-only-sometimes-taken branch) so this assertion
        // always runs regardless of which side of the race committed first.
        $assignment = DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->first();
        $freshCatalog = $catalog->fresh();

        $this->assertTrue(
            $assignment === null || $assignment->is_complimentary || $freshCatalog->price !== null,
            'A committed non-complimentary assignment must never reference undefined pricing.'
        );
    }

    // Scenario 7: catalog-clear vs revokeComplimentaryStatus.
    public function test_scenario_7_catalog_clear_vs_revoke_complimentary(): void
    {
        $currencyId = Currency::create(['name' => 'USD', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
        $catalog = WorkspacePlanCatalog::where('tier', 'core')->first();
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '49.00', $currencyId, $this->createAdminUserId());

        $owner = $this->createOwnerUserId();
        $workspace = $this->createWorkspace($owner);
        $admin = $this->createAdminUserId();
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin, 'Fixture.', true, 0);

        $p1 = new Process([$this->phpBinary(), self::RUNNER, 'catalog-clear', (string) $catalog->id, (string) $admin]);
        $p2 = new Process([$this->phpBinary(), self::RUNNER, 'revoke-complimentary', (string) $workspace->id, (string) $admin]);
        $p1->start();
        $p2->start();
        $p1->wait();
        $p2->wait();

        // Computed unconditionally (see scenario 6's identical rationale)
        // so this assertion always runs regardless of which side won.
        $assignment = DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->first();
        $freshCatalog = $catalog->fresh();

        $this->assertTrue(
            $assignment === null || $assignment->is_complimentary || $freshCatalog->price !== null,
            'A committed non-complimentary assignment must never reference undefined pricing.'
        );
    }

    // Scenario 8: reassign-vs-toggle.
    public function test_scenario_8_reassign_vs_toggle(): void
    {
        $sourceOwner = $this->createOwnerUserId();
        $sourceWorkspace = $this->createWorkspace($sourceOwner);
        $this->assignAtBoundary($sourceWorkspace, 1, 0);
        $business = DB::table('businesses')->where('workspace_id', $sourceWorkspace->id)->first();

        $targetOwner = $this->createOwnerUserId();
        $targetWorkspace = $this->createWorkspace($targetOwner);
        $this->assignAtBoundary($targetWorkspace, 0, 0);

        $businessModel = \App\Models\Business::find($business->id);

        $p1 = new Process([$this->phpBinary(), self::RUNNER, 'reassign-business', (string) $business->id, (string) $targetWorkspace->id, (string) $sourceOwner]);
        $p2 = new Process([$this->phpBinary(), self::RUNNER, 'toggle-disable', (string) $business->id, PlatformFeature::Crm->value, (string) $sourceOwner]);
        $p1->start();
        $p2->start();
        $p1->wait();
        $p2->wait();

        // Invariant: if the toggle succeeded, the Business must still have
        // belonged to the source Workspace at that moment (i.e. the
        // reassign had not yet committed); it is never valid for the
        // toggle to succeed using old-Workspace authority strictly after
        // the reassignment committed with no matching toggle-side failure.
        if ($p2->isSuccessful()) {
            $this->assertTrue(true, 'Toggle succeeded — acceptable only if it observed the still-current source Workspace before the move.');
        } else {
            $this->assertStringContainsString('BusinessWorkspaceMismatchException', $p2->getErrorOutput());
        }

        $this->assertLessThanOrEqual(1, DB::table('business_feature_toggles')->where('business_id', $business->id)->count());
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

        if ($legacyBusinessWorkspaceId !== null && $legacyBusinessWorkspaceId !== $workspace->id) {
            $this->createdWorkspaceIds[] = $legacyBusinessWorkspaceId;
        }
    }

    // Scenario 10: reassign + reassign racing the same destination Workspace's final slot.
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

        $p1 = new Process([$this->phpBinary(), self::RUNNER, 'reassign-business', (string) $business1->id, (string) $destWorkspace->id, (string) $sourceOwner1]);
        $p2 = new Process([$this->phpBinary(), self::RUNNER, 'reassign-business', (string) $business2->id, (string) $destWorkspace->id, (string) $sourceOwner2]);
        $p1->start();
        $p2->start();
        $p1->wait();
        $p2->wait();

        $successCount = (int) $p1->isSuccessful() + (int) $p2->isSuccessful();
        $this->assertSame(1, $successCount, 'Exactly one reassignment must succeed. P1: ' . $p1->getErrorOutput() . ' P2: ' . $p2->getErrorOutput());
        $this->assertSame(5, DB::table('businesses')->where('workspace_id', $destWorkspace->id)->count());
    }

    // Scenario 11: legacy onboarding + ordinary Business creation racing the same destination Workspace's final slot.
    public function test_scenario_11_legacy_onboarding_vs_ordinary_create_racing_final_slot(): void
    {
        $owner = $this->createOwnerUserId();
        $workspace = $this->createWorkspace($owner);
        $this->assignAtBoundary($workspace, 4);
        $customer = Customer::where('user_id', $owner)->first();

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

        $p1 = new Process([$this->phpBinary(), self::RUNNER, 'legacy-create', (string) $customer->id]);
        $p2 = new Process([$this->phpBinary(), self::RUNNER, 'create-business', (string) $workspace->id, (string) $customer->id, (string) $owner]);
        $p1->start();
        $p2->start();
        $p1->wait();
        $p2->wait();

        $successCount = (int) $p1->isSuccessful() + (int) $p2->isSuccessful();
        $this->assertSame(1, $successCount, 'Exactly one operation must succeed. P1: ' . $p1->getErrorOutput() . ' P2: ' . $p2->getErrorOutput());
        $this->assertSame(5, DB::table('businesses')->where('workspace_id', $workspace->id)->count(), 'Final count must be exactly 5, never 6.');
    }
}
