<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\Entitlement\BusinessSlotAllocationRequiredException;
use App\Exceptions\Entitlement\BusinessSlotLimitExceededException;
use App\Exceptions\Entitlement\WorkspacePlanUnassignedException;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntitlementManagerBusinessSlotCapacityTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): int
    {
        return User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    private function assignedWorkspace(WorkspacePlanTier $tier = WorkspacePlanTier::Core, int $slots = 0): array
    {
        $owner = User::create([
            'first_name' => 'Owner', 'last_name' => 'User', 'email' => 'owner' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $customer = Customer::create(['user_id' => $owner->id]);
        $workspace = Workspace::create(['name' => 'W', 'owner_user_id' => $owner->id, 'is_active' => true]);
        app(EntitlementManager::class)->assignFirstPlan($workspace, $tier, $this->createAdmin(), 'Fixture.', true, $slots);

        return [$workspace->fresh(), $customer];
    }

    private function createNBusinesses(Workspace $workspace, Customer $customer, int $n, ?BusinessStatus $status = null): void
    {
        for ($i = 0; $i < $n; $i++) {
            $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, [
                'name' => "B{$i}-" . uniqid(), 'industry' => 'photo_booth_service', 'country_code' => 'US', 'timezone' => 'America/New_York', 'currency_code' => 'USD',
            ]);

            if ($status !== null) {
                app(BusinessRepository::class)->updateStatus($business, $status);
            }
        }
    }

    /**
     * CX Slice 1A / RFC-004 §33 — Core and Growth now hold exactly ONE
     * Business/client account. The 3-included / 4-and-5-at-50% rule that
     * these tests previously asserted was the CONFLATED reading of RFC-004
     * §2; it now governs PHYSICAL LOCATIONS instead, and is covered by
     * tests/Feature/Business/BusinessLocationCapacityTest.
     */
    public function test_the_first_business_succeeds_on_core_and_growth(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth] as $tier) {
            [$workspace, $customer] = $this->assignedWorkspace($tier, 0);

            app(EntitlementManager::class)->assertCanCreateAnotherBusiness($workspace);
            $this->createNBusinesses($workspace, $customer, 1);

            $this->assertSame(1, (int) \Illuminate\Support\Facades\DB::table('businesses')->where('workspace_id', $workspace->id)->count());
        }
    }

    public function test_a_second_business_is_denied_on_core_and_growth(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth] as $tier) {
            [$workspace, $customer] = $this->assignedWorkspace($tier, 0);
            $this->createNBusinesses($workspace, $customer, 1);

            try {
                app(EntitlementManager::class)->assertCanCreateAnotherBusiness($workspace->fresh());
                $this->fail("{$tier->value} must refuse a second Business.");
            } catch (BusinessSlotLimitExceededException $exception) {
                // The ceiling is 1 and no allocation can raise it.
                $this->assertSame((int) $workspace->id, $exception->workspaceId);
            }
        }
    }

    /**
     * No additional-Business-slot allocation can raise the Core/Growth
     * ceiling any more: business_slot_max is 1, so the only path is Agency.
     */
    public function test_no_allocation_can_raise_the_core_growth_business_ceiling(): void
    {
        [$workspace, $customer] = $this->assignedWorkspace(WorkspacePlanTier::Growth, 2);
        $this->createNBusinesses($workspace, $customer, 1);

        $this->expectException(BusinessSlotLimitExceededException::class);
        app(EntitlementManager::class)->assertCanCreateAnotherBusiness($workspace->fresh());
    }

    public function test_agency_is_unlimited(): void
    {
        [$workspace, $customer] = $this->assignedWorkspace(WorkspacePlanTier::Agency, 0);
        $this->createNBusinesses($workspace, $customer, 10);

        app(EntitlementManager::class)->assertCanCreateAnotherBusiness($workspace->fresh());
        $this->assertTrue(true);
    }

    public function test_inactive_business_rows_still_consume_slots(): void
    {
        [$workspace, $customer] = $this->assignedWorkspace(slots: 0);
        $this->createNBusinesses($workspace, $customer, 1, BusinessStatus::Inactive);

        $this->expectException(BusinessSlotLimitExceededException::class);
        app(EntitlementManager::class)->assertCanCreateAnotherBusiness($workspace->fresh());
    }

    /**
     * A Workspace that already held several Businesses when the corrected
     * capacity landed keeps every one of them; only NEW creation is denied
     * (RFC-004 §33.6).
     */
    public function test_grandfathered_over_capacity_keeps_every_business_and_still_denies_further_creation(): void
    {
        // Created while on Agency (unlimited), then downgraded — the same
        // shape as a real pre-correction Workspace.
        [$workspace, $customer] = $this->assignedWorkspace(WorkspacePlanTier::Agency, 0);
        $this->createNBusinesses($workspace, $customer, 5);

        app(EntitlementManager::class)->changePlan($workspace->fresh(), WorkspacePlanTier::Growth, $this->createAdmin(), 'Downgrade.');

        $this->assertSame(5, (int) \Illuminate\Support\Facades\DB::table('businesses')->where('workspace_id', $workspace->id)->count());

        $this->expectException(BusinessSlotLimitExceededException::class);
        app(EntitlementManager::class)->assertCanCreateAnotherBusiness($workspace->fresh());
    }

    public function test_ordinary_customer_created_workspace_does_not_silently_receive_a_complimentary_plan(): void
    {
        $owner = User::create([
            'first_name' => 'Owner', 'last_name' => 'User', 'email' => 'owner' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $workspace = app(\App\Library\Workspace\WorkspaceManager::class)->createWorkspace((int) $owner->id, 'Ordinary Workspace');

        $this->assertDatabaseMissing('workspace_plan_assignments', ['workspace_id' => $workspace->id]);

        $this->expectException(WorkspacePlanUnassignedException::class);
        app(EntitlementManager::class)->assertCanCreateAnotherBusiness($workspace);
    }
}
