<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\Entitlement\UndefinedPlanPricingException;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Currency;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePlanCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class EntitlementManagerChangePlanTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): int
    {
        return User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    private function createNonAdmin(): int
    {
        return User::create([
            'first_name' => 'Non', 'last_name' => 'Admin', 'email' => 'nonadmin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ])->id;
    }

    private function createOwnerWorkspace(): Workspace
    {
        $owner = User::create([
            'first_name' => 'Owner', 'last_name' => 'User', 'email' => 'owner' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);

        return Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);
    }

    private function definePricing(WorkspacePlanTier $tier, ?float $ratio = 0.5): void
    {
        $currency = Currency::query()->first() ?? Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        WorkspacePlanCatalog::where('tier', $tier->value)->update([
            'price' => '49.00', 'currency_id' => $currency->id, 'additional_business_slot_price_ratio' => $ratio,
        ]);
    }

    private function assignedWorkspace(WorkspacePlanTier $tier, bool $complimentary, int $slots = 0): Workspace
    {
        $workspace = $this->createOwnerWorkspace();
        app(EntitlementManager::class)->assignFirstPlan($workspace, $tier, $this->createAdmin(), 'Fixture.', $complimentary, $slots);

        return $workspace->fresh();
    }

    public function test_core_to_growth_preserves_additional_business_slots_unchanged(): void
    {
        $this->definePricing(WorkspacePlanTier::Core);
        $this->definePricing(WorkspacePlanTier::Growth);
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core, false, 1);

        $updated = app(EntitlementManager::class)->changePlan($workspace, WorkspacePlanTier::Growth, $this->createAdmin());

        $this->assertSame(1, $updated->additional_business_slots);
    }

    public function test_core_or_growth_to_agency_resets_slots_to_zero_and_writes_both_transitions(): void
    {
        $this->definePricing(WorkspacePlanTier::Core);
        $this->definePricing(WorkspacePlanTier::Agency);
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core, false, 2);

        $updated = app(EntitlementManager::class)->changePlan($workspace, WorkspacePlanTier::Agency, $this->createAdmin());

        $this->assertSame(0, $updated->additional_business_slots);
        $this->assertDatabaseHas('workspace_entitlement_transitions', ['workspace_id' => $workspace->id, 'transition_type' => 'plan_changed']);
        $this->assertDatabaseHas('workspace_entitlement_transitions', ['workspace_id' => $workspace->id, 'transition_type' => 'additional_business_slots_changed']);
    }

    public function test_agency_to_core_defaults_slots_to_zero_when_not_specified(): void
    {
        $this->definePricing(WorkspacePlanTier::Agency);
        $this->definePricing(WorkspacePlanTier::Core);
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Agency, false, 0);

        $updated = app(EntitlementManager::class)->changePlan($workspace, WorkspacePlanTier::Core, $this->createAdmin());

        $this->assertSame(0, $updated->additional_business_slots);
    }

    public function test_agency_to_growth_allocates_the_explicitly_requested_slot_value(): void
    {
        $this->definePricing(WorkspacePlanTier::Agency);
        $this->definePricing(WorkspacePlanTier::Growth);
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Agency, false, 0);

        $updated = app(EntitlementManager::class)->changePlan($workspace, WorkspacePlanTier::Growth, $this->createAdmin(), null, 2);

        $this->assertSame(2, $updated->additional_business_slots);
    }

    public function test_core_to_growth_with_undefined_destination_price_is_rejected_even_when_slots_stay_null(): void
    {
        $this->definePricing(WorkspacePlanTier::Core);
        $paidWorkspace = $this->assignedWorkspace(WorkspacePlanTier::Core, false, 0);

        $this->expectException(UndefinedPlanPricingException::class);
        app(EntitlementManager::class)->changePlan($paidWorkspace, WorkspacePlanTier::Growth, $this->createAdmin());
    }

    public function test_complimentary_plan_change_remains_exempt_from_base_pricing(): void
    {
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core, true, 0);

        $updated = app(EntitlementManager::class)->changePlan($workspace, WorkspacePlanTier::Growth, $this->createAdmin());

        $this->assertNotNull($updated->id);
    }

    public function test_existing_businesses_are_never_removed_on_plan_change(): void
    {
        $this->definePricing(WorkspacePlanTier::Core);
        $this->definePricing(WorkspacePlanTier::Growth);
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core, false, 0);

        $business = app(\App\Repositories\Contracts\BusinessRepository::class)->createForCustomerInWorkspace(
            \App\Models\Customer::create(['user_id' => $workspace->owner_user_id]),
            $workspace,
            ['name' => 'B1', 'industry' => 'photo_booth_service', 'country_code' => 'US', 'timezone' => 'America/New_York', 'currency_code' => 'USD'],
        );

        app(EntitlementManager::class)->changePlan($workspace, WorkspacePlanTier::Growth, $this->createAdmin());

        $this->assertDatabaseHas('businesses', ['id' => $business->id]);
    }

    public function test_additional_business_slots_changed_fires_only_when_the_value_actually_changed(): void
    {
        $this->definePricing(WorkspacePlanTier::Core);
        $this->definePricing(WorkspacePlanTier::Growth);
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core, false, 0);

        app(EntitlementManager::class)->changePlan($workspace, WorkspacePlanTier::Growth, $this->createAdmin());

        $this->assertDatabaseMissing('workspace_entitlement_transitions', ['workspace_id' => $workspace->id, 'transition_type' => 'additional_business_slots_changed']);
    }

    public function test_non_administrator_actor_is_denied(): void
    {
        $this->definePricing(WorkspacePlanTier::Core);
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core, false, 0);

        $this->expectException(AuthorizationException::class);
        app(EntitlementManager::class)->changePlan($workspace, WorkspacePlanTier::Growth, $this->createNonAdmin());
    }

    public function test_non_administrator_against_undefined_destination_pricing_still_receives_authorization_exception(): void
    {
        // Core (the current tier) needs defined pricing for this fixture's
        // own non-complimentary first assignment to succeed at all; Growth
        // (the destination) is deliberately left with the seed default's
        // undefined pricing so the real action under test — a non-admin
        // requesting a change into Growth — hits the authority check before
        // Growth's undefined pricing is ever read.
        $this->definePricing(WorkspacePlanTier::Core);
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core, false, 0);

        try {
            app(EntitlementManager::class)->changePlan($workspace, WorkspacePlanTier::Growth, $this->createNonAdmin());
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        } catch (UndefinedPlanPricingException $e) {
            $this->fail('Expected AuthorizationException, got UndefinedPlanPricingException: ' . $e->getMessage());
        }
    }

    public function test_non_complimentary_change_into_inactive_destination_is_rejected(): void
    {
        $this->definePricing(WorkspacePlanTier::Core);
        $this->definePricing(WorkspacePlanTier::Growth);
        WorkspacePlanCatalog::where('tier', 'growth')->update(['is_active' => false]);
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core, false, 0);

        $this->expectException(InvalidArgumentException::class);
        app(EntitlementManager::class)->changePlan($workspace, WorkspacePlanTier::Growth, $this->createAdmin());
    }

    public function test_complimentary_change_into_inactive_destination_is_also_rejected(): void
    {
        WorkspacePlanCatalog::where('tier', 'growth')->update(['is_active' => false]);
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core, true, 0);

        $this->expectException(InvalidArgumentException::class);
        app(EntitlementManager::class)->changePlan($workspace, WorkspacePlanTier::Growth, $this->createAdmin());
    }

    public function test_changing_away_from_inactive_current_catalog_into_active_destination_remains_allowed(): void
    {
        $this->definePricing(WorkspacePlanTier::Core);
        $this->definePricing(WorkspacePlanTier::Growth);
        $workspace = $this->assignedWorkspace(WorkspacePlanTier::Core, false, 0);
        WorkspacePlanCatalog::where('tier', 'core')->update(['is_active' => false]);

        $updated = app(EntitlementManager::class)->changePlan($workspace, WorkspacePlanTier::Growth, $this->createAdmin());

        $this->assertNotNull($updated->id);
    }
}
