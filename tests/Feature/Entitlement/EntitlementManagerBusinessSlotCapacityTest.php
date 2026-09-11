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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RFC-004 §17's decideBusinessSlotCapacity() arithmetic — included +
 * allocated additional slots, capped by the tier maximum, with
 * allocation-required versus limit-exceeded denials — is unchanged and must
 * stay unchanged (Customer Experience contract §22.3).
 *
 * Customer Experience Slice 1A (RFC-004 §33) corrected the SEEDED Core and
 * Growth values to exactly one Business with no priced additional slot. So
 * the engine tests below pin an explicit bounded catalog (3 included / 5
 * max) with engineCatalog(): they prove the arithmetic, not the product
 * rule. The corrected product rule is asserted against the real catalog by
 * the two test_corrected_* tests here and by
 * tests/Feature/Business/BusinessAccountCapacityTest.
 */
class EntitlementManagerBusinessSlotCapacityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An explicit bounded Business-slot configuration for engine tests, set
     * inside this test's own transaction only.
     */
    private function engineCatalog(): void
    {
        DB::table('workspace_plan_catalog')->where('tier', 'core')->update([
            'business_slot_included' => 3,
            'business_slot_max' => 5,
            'additional_business_slot_price_ratio' => '0.5000',
        ]);
    }

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

    public function test_first_second_third_business_succeed_with_zero_allocation(): void
    {
        $this->engineCatalog();
        [$workspace] = $this->assignedWorkspace(slots: 0);

        for ($i = 0; $i < 3; $i++) {
            app(EntitlementManager::class)->assertCanCreateAnotherBusiness($workspace);
            app(BusinessRepository::class)->createForCustomerInWorkspace(
                Customer::where('user_id', $workspace->owner_user_id)->first() ?? Customer::create(['user_id' => $workspace->owner_user_id]),
                $workspace,
                ['name' => "B{$i}", 'industry' => 'photo_booth_service', 'country_code' => 'US', 'timezone' => 'America/New_York', 'currency_code' => 'USD'],
            );
        }

        $this->assertDatabaseCount('businesses', 3);
    }

    public function test_fourth_business_requires_allocation(): void
    {
        $this->engineCatalog();
        [$workspace, $customer] = $this->assignedWorkspace(slots: 0);
        $this->createNBusinesses($workspace, $customer, 3);

        $this->expectException(BusinessSlotAllocationRequiredException::class);
        app(EntitlementManager::class)->assertCanCreateAnotherBusiness($workspace->fresh());
    }

    public function test_fourth_business_succeeds_with_slot_one(): void
    {
        $this->engineCatalog();
        [$workspace, $customer] = $this->assignedWorkspace(slots: 1);
        $this->createNBusinesses($workspace, $customer, 3);

        app(EntitlementManager::class)->assertCanCreateAnotherBusiness($workspace->fresh());
        $this->assertTrue(true);
    }

    public function test_fifth_business_requires_slot_two_denied_with_only_slot_one(): void
    {
        $this->engineCatalog();
        [$workspace, $customer] = $this->assignedWorkspace(slots: 1);
        $this->createNBusinesses($workspace, $customer, 4);

        $this->expectException(BusinessSlotAllocationRequiredException::class);
        app(EntitlementManager::class)->assertCanCreateAnotherBusiness($workspace->fresh());
    }

    public function test_fifth_business_succeeds_with_slot_two(): void
    {
        $this->engineCatalog();
        [$workspace, $customer] = $this->assignedWorkspace(slots: 2);
        $this->createNBusinesses($workspace, $customer, 4);

        app(EntitlementManager::class)->assertCanCreateAnotherBusiness($workspace->fresh());
        $this->assertTrue(true);
    }

    public function test_sixth_business_always_denied_regardless_of_allocation(): void
    {
        $this->engineCatalog();
        [$workspace, $customer] = $this->assignedWorkspace(slots: 2);
        $this->createNBusinesses($workspace, $customer, 5);

        $this->expectException(BusinessSlotLimitExceededException::class);
        app(EntitlementManager::class)->assertCanCreateAnotherBusiness($workspace->fresh());
    }

    public function test_corrected_core_catalog_holds_exactly_one_business(): void
    {
        [$workspace, $customer] = $this->assignedWorkspace(slots: 0);

        app(EntitlementManager::class)->assertCanCreateAnotherBusiness($workspace);
        $this->createNBusinesses($workspace, $customer, 1);

        $decision = app(EntitlementManager::class)->decideBusinessSlotCapacity($workspace->fresh());
        $this->assertSame(1, $decision->effectiveCapacity);
        $this->assertSame('business_slot_limit_exceeded', $decision->denialReason, 'Core offers no additional Business slot: Agency is the only path.');
    }

    public function test_corrected_core_catalog_ignores_a_legacy_additional_business_slot_allocation(): void
    {
        [$workspace, $customer] = $this->assignedWorkspace(slots: 2);
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
        $this->engineCatalog();
        [$workspace, $customer] = $this->assignedWorkspace(slots: 0);
        $this->createNBusinesses($workspace, $customer, 3, BusinessStatus::Inactive);

        $this->expectException(BusinessSlotAllocationRequiredException::class);
        app(EntitlementManager::class)->assertCanCreateAnotherBusiness($workspace->fresh());
    }

    public function test_grandfathered_over_capacity_keeps_every_business_and_still_denies_further_creation(): void
    {
        $this->engineCatalog();
        [$workspace, $customer] = $this->assignedWorkspace(slots: 2);
        $this->createNBusinesses($workspace, $customer, 5);
        // Downgrade allocation after the fact — existing Businesses remain.
        app(EntitlementManager::class)->setAdditionalBusinessSlots($workspace->fresh(), 0, $this->createAdmin());

        $this->assertDatabaseCount('businesses', 5);

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
