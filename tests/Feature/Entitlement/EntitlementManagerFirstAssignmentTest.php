<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Events\Entitlement\WorkspacePlanAssigned;
use App\Exceptions\Entitlement\InvalidAdditionalBusinessSlotsException;
use App\Exceptions\Entitlement\UndefinedPlanPricingException;
use App\Exceptions\Entitlement\WorkspacePlanAlreadyAssignedException;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Currency;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePlanCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\TestCase;

class EntitlementManagerFirstAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): int
    {
        return User::create([
            'first_name' => 'Admin', 'last_name' => 'User',
            'email' => 'admin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    private function createOwnerWorkspace(): Workspace
    {
        $owner = User::create([
            'first_name' => 'Owner', 'last_name' => 'User',
            'email' => 'owner' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);

        return Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);
    }

    private function definePricing(WorkspacePlanTier $tier, ?float $ratio = 0.5): WorkspacePlanCatalog
    {
        $currency = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $catalog = WorkspacePlanCatalog::where('tier', $tier->value)->first();
        $catalog->update([
            'price' => '49.00',
            'currency_id' => $currency->id,
            'additional_business_slot_price_ratio' => $ratio,
        ]);

        return $catalog->fresh();
    }

    public function test_locked_unassigned_workspace_succeeds_and_returns_a_persisted_assignment(): void
    {
        $workspace = $this->createOwnerWorkspace();

        $assignment = app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $this->createAdmin(), 'First assignment.', true, 0);

        $this->assertDatabaseHas('workspace_plan_assignments', ['id' => $assignment->id, 'workspace_id' => $workspace->id]);
    }

    public function test_duplicate_first_assignment_is_rejected(): void
    {
        $workspace = $this->createOwnerWorkspace();
        $admin = $this->createAdmin();
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin, 'First.', true, 0);

        $this->expectException(WorkspacePlanAlreadyAssignedException::class);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin, 'Second.', true, 0);
    }

    public function test_writes_plan_assigned_transition_and_dispatches_event(): void
    {
        Event::fake([WorkspacePlanAssigned::class]);
        $workspace = $this->createOwnerWorkspace();
        $admin = $this->createAdmin();

        $assignment = app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin, 'Reason.', true, 0);

        $this->assertDatabaseHas('workspace_entitlement_transitions', [
            'workspace_id' => $workspace->id,
            'transition_type' => 'plan_assigned',
            'actor_user_id' => $admin,
        ]);
        Event::assertDispatched(WorkspacePlanAssigned::class, fn ($e) => $e->workspaceId === $workspace->id && $e->workspacePlanCatalogId === $assignment->workspace_plan_catalog_id);
    }

    public function test_non_administrator_actor_is_denied(): void
    {
        $workspace = $this->createOwnerWorkspace();
        $nonAdmin = User::create([
            'first_name' => 'Non', 'last_name' => 'Admin', 'email' => 'nonadmin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ])->id;

        $this->expectException(AuthorizationException::class);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $nonAdmin, 'Reason.', true, 0);
    }

    public function test_non_administrator_against_already_assigned_workspace_still_receives_authorization_exception(): void
    {
        $workspace = $this->createOwnerWorkspace();
        $admin = $this->createAdmin();
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin, 'First.', true, 0);

        $nonAdmin = User::create([
            'first_name' => 'Non', 'last_name' => 'Admin', 'email' => 'nonadmin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ])->id;

        try {
            app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $nonAdmin, 'Second.', true, 0);
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        } catch (WorkspacePlanAlreadyAssignedException $e) {
            $this->fail('Expected AuthorizationException, got WorkspacePlanAlreadyAssignedException: ' . $e->getMessage());
        }
    }

    public function test_non_administrator_against_undefined_pricing_still_receives_authorization_exception(): void
    {
        $workspace = $this->createOwnerWorkspace();
        $nonAdmin = User::create([
            'first_name' => 'Non', 'last_name' => 'Admin', 'email' => 'nonadmin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ])->id;

        try {
            app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $nonAdmin, 'Reason.', false, 0);
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        } catch (UndefinedPlanPricingException $e) {
            $this->fail('Expected AuthorizationException, got UndefinedPlanPricingException: ' . $e->getMessage());
        }
    }

    public function test_paid_first_assignment_to_inactive_destination_catalog_is_rejected(): void
    {
        $workspace = $this->createOwnerWorkspace();
        $this->definePricing(WorkspacePlanTier::Core);
        WorkspacePlanCatalog::where('tier', 'core')->update(['is_active' => false]);

        $this->expectException(InvalidArgumentException::class);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $this->createAdmin(), 'Reason.', false, 0);
    }

    public function test_complimentary_first_assignment_to_inactive_destination_catalog_is_also_rejected(): void
    {
        $workspace = $this->createOwnerWorkspace();
        WorkspacePlanCatalog::where('tier', 'core')->update(['is_active' => false]);

        $this->expectException(InvalidArgumentException::class);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $this->createAdmin(), 'Reason.', true, 0);
    }

    public function test_active_destination_still_succeeds(): void
    {
        $workspace = $this->createOwnerWorkspace();

        $assignment = app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $this->createAdmin(), 'Reason.', true, 0);

        $this->assertNotNull($assignment->id);
    }

    public function test_complimentary_provenance_is_persisted(): void
    {
        $workspace = $this->createOwnerWorkspace();
        $admin = $this->createAdmin();

        $assignment = app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin, 'Complimentary reason.', true, 0);

        $this->assertSame('Complimentary reason.', $assignment->complimentary_reason);
        $this->assertSame($admin, $assignment->complimentary_granted_by_user_id);
        $this->assertNotNull($assignment->complimentary_granted_at);
    }

    public function test_paid_first_assignment_stores_null_complimentary_provenance(): void
    {
        $workspace = $this->createOwnerWorkspace();
        $this->definePricing(WorkspacePlanTier::Core);

        $assignment = app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $this->createAdmin(), 'Paid reason.', false, 0);

        $this->assertNull($assignment->complimentary_reason);
        $this->assertNull($assignment->complimentary_granted_by_user_id);
        $this->assertNull($assignment->complimentary_granted_at);
    }

    public function test_paid_core_first_assignment_slot_one_with_null_ratio_is_rejected(): void
    {
        $workspace = $this->createOwnerWorkspace();
        $this->definePricing(WorkspacePlanTier::Core, ratio: null);

        $this->expectException(UndefinedPlanPricingException::class);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $this->createAdmin(), 'Reason.', false, 1);
    }

    public function test_paid_core_first_assignment_slot_two_with_null_ratio_is_rejected(): void
    {
        $workspace = $this->createOwnerWorkspace();
        $this->definePricing(WorkspacePlanTier::Core, ratio: null);

        $this->expectException(UndefinedPlanPricingException::class);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $this->createAdmin(), 'Reason.', false, 2);
    }

    public function test_paid_core_first_assignment_slot_zero_with_null_ratio_succeeds(): void
    {
        $workspace = $this->createOwnerWorkspace();
        $this->definePricing(WorkspacePlanTier::Core, ratio: null);

        $assignment = app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $this->createAdmin(), 'Reason.', false, 0);

        $this->assertSame(0, $assignment->additional_business_slots);
    }

    public function test_paid_core_first_assignment_slots_one_and_two_with_full_pricing_succeed(): void
    {
        $workspace1 = $this->createOwnerWorkspace();
        $workspace2 = $this->createOwnerWorkspace();
        $this->definePricing(WorkspacePlanTier::Core, ratio: 0.5);

        $a1 = app(EntitlementManager::class)->assignFirstPlan($workspace1, WorkspacePlanTier::Core, $this->createAdmin(), 'Reason.', false, 1);
        $a2 = app(EntitlementManager::class)->assignFirstPlan($workspace2, WorkspacePlanTier::Core, $this->createAdmin(), 'Reason.', false, 2);

        $this->assertSame(1, $a1->additional_business_slots);
        $this->assertSame(2, $a2->additional_business_slots);
    }

    public function test_complimentary_first_assignment_slots_remain_permitted_without_pricing(): void
    {
        $workspace = $this->createOwnerWorkspace();

        $assignment = app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $this->createAdmin(), 'Reason.', true, 2);

        $this->assertSame(2, $assignment->additional_business_slots);
    }

    public function test_paid_agency_first_assignment_slot_zero_with_null_ratio_succeeds(): void
    {
        $workspace = $this->createOwnerWorkspace();
        $this->definePricing(WorkspacePlanTier::Agency, ratio: null);

        $assignment = app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Agency, $this->createAdmin(), 'Reason.', false, 0);

        $this->assertSame(0, $assignment->additional_business_slots);
    }

    public function test_agency_non_zero_slot_request_is_rejected(): void
    {
        $workspace = $this->createOwnerWorkspace();

        $this->expectException(InvalidAdditionalBusinessSlotsException::class);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Agency, $this->createAdmin(), 'Reason.', true, 1);
    }

    public function test_empty_reason_is_rejected(): void
    {
        $workspace = $this->createOwnerWorkspace();

        $this->expectException(InvalidArgumentException::class);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $this->createAdmin(), '   ', true, 0);
    }
}
