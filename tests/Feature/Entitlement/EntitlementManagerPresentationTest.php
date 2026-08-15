<?php

namespace Tests\Feature\Entitlement;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Entitlement\WorkspaceEntitlementSummary;
use App\Library\Entitlement\WorkspacePlanCatalogSummary;
use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RFC-004 Milestone 3 §8.5: direct coverage of EntitlementManager's three
 * new presentation methods, independent of any HTTP surface.
 */
class EntitlementManagerPresentationTest extends TestCase
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

    /**
     * @return array{workspace: Workspace, business: Business}
     */
    private function createWorkspaceWithBusiness(bool $assign = true): array
    {
        $owner = User::create([
            'first_name' => 'Owner', 'last_name' => 'User',
            'email' => 'owner' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $customer = Customer::create(['user_id' => $owner->id]);
        $workspace = Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, [
            'name' => 'Test Business', 'industry' => 'photo_booth_service',
            'country_code' => 'US', 'timezone' => 'America/New_York', 'currency_code' => 'USD',
        ]);

        if ($assign) {
            app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $this->createAdmin(), 'Fixture assignment.', true, 0);
        }

        return ['workspace' => $workspace->fresh(), 'business' => $business->fresh()];
    }

    // --- listPlanCatalogSummaries() -----------------------------------------

    public function test_list_plan_catalog_summaries_returns_exactly_three_entries_in_order(): void
    {
        $summaries = app(EntitlementManager::class)->listPlanCatalogSummaries();

        $this->assertCount(3, $summaries);
        $this->assertContainsOnlyInstancesOf(WorkspacePlanCatalogSummary::class, $summaries);
        $this->assertSame([WorkspacePlanTier::Core, WorkspacePlanTier::Growth, WorkspacePlanTier::Agency], array_map(fn ($s) => $s->tier, $summaries));
    }

    public function test_list_plan_catalog_summaries_reports_seeded_structural_fields_and_feature_availability(): void
    {
        $summaries = app(EntitlementManager::class)->listPlanCatalogSummaries();
        $core = $summaries[0];

        $this->assertSame('Core', $core->displayName);
        $this->assertSame(3, $core->businessSlotIncluded);
        $this->assertSame(5, $core->businessSlotMax);
        $this->assertFalse($core->unlimitedBusinessSlots);
        $this->assertContains('crm', $core->planFeatureKeys);
        $this->assertContains('calendar', $core->planFeatureKeys);
        $this->assertTrue($core->featureAvailability['crm']);
        $this->assertFalse($core->featureAvailability['calendar']);

        $agency = $summaries[2];
        $this->assertTrue($agency->unlimitedBusinessSlots);
        $this->assertNull($agency->businessSlotMax);
        $this->assertContains('prospect_outreach', $agency->planFeatureKeys);
        $this->assertFalse($agency->featureAvailability['prospect_outreach']);
    }

    // --- getWorkspaceEntitlementSummary() -----------------------------------

    public function test_workspace_entitlement_summary_for_an_unassigned_workspace(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceWithBusiness(assign: false);

        $summary = app(EntitlementManager::class)->getWorkspaceEntitlementSummary($workspace);

        $this->assertInstanceOf(WorkspaceEntitlementSummary::class, $summary);
        $this->assertFalse($summary->isAssigned);
        $this->assertNull($summary->tier);
        $this->assertNull($summary->status);
        $this->assertNull($summary->isComplimentary);
        $this->assertSame([], $summary->planFeatureKeys);
    }

    public function test_workspace_entitlement_summary_for_an_assigned_active_workspace(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceWithBusiness();

        $summary = app(EntitlementManager::class)->getWorkspaceEntitlementSummary($workspace);

        $this->assertTrue($summary->isAssigned);
        $this->assertSame(WorkspacePlanTier::Core, $summary->tier);
        $this->assertSame('Core', $summary->tierDisplayName);
        $this->assertSame(WorkspacePlanAssignmentStatus::Active, $summary->status);
        $this->assertContains('crm', $summary->planFeatureKeys);
    }

    public function test_workspace_entitlement_summary_reports_complimentary_status(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceWithBusiness();

        $summary = app(EntitlementManager::class)->getWorkspaceEntitlementSummary($workspace);

        $this->assertTrue($summary->isComplimentary);
    }

    public function test_workspace_entitlement_summary_reports_workspace_overrides(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceWithBusiness();
        app(EntitlementManager::class)->createOrChangeOverride(
            $workspace, PlatformFeature::Conversations, WorkspaceEntitlementOverrideState::Deny, $this->createAdmin(), 'Test override.'
        );

        $summary = app(EntitlementManager::class)->getWorkspaceEntitlementSummary($workspace);

        $this->assertArrayHasKey('conversations', $summary->overrides);
        $this->assertSame(WorkspaceEntitlementOverrideState::Deny, $summary->overrides['conversations']);
    }

    public function test_workspace_entitlement_summary_capacity_matches_direct_capacity_decision(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceWithBusiness();

        $manager = app(EntitlementManager::class);
        $summary = $manager->getWorkspaceEntitlementSummary($workspace);
        $direct = $manager->decideBusinessSlotCapacity($workspace);

        $this->assertEquals($direct, $summary->capacity);
    }

    // --- decideAvailableFeaturesForBusiness() -------------------------------

    public function test_case_a_entitled_no_toggle(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->createWorkspaceWithBusiness();

        $result = app(EntitlementManager::class)->decideAvailableFeaturesForBusiness($workspace, $business, $this->createAdmin());

        $this->assertTrue($result[PlatformFeature::Crm->value]['decision']->allowed);
        $this->assertFalse($result[PlatformFeature::Crm->value]['disablePreferenceRecorded']);
    }

    public function test_case_b_entitled_feature_stored_toggle(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->createWorkspaceWithBusiness();
        $actorUserId = (int) $workspace->owner_user_id;
        app(EntitlementManager::class)->disableBusinessFeature($business, PlatformFeature::Crm, $actorUserId);

        $result = app(EntitlementManager::class)->decideAvailableFeaturesForBusiness($workspace, $business, $actorUserId);

        $this->assertFalse($result[PlatformFeature::Crm->value]['decision']->allowed);
        $this->assertSame('disabled_for_business', $result[PlatformFeature::Crm->value]['decision']->reason);
        $this->assertTrue($result[PlatformFeature::Crm->value]['disablePreferenceRecorded']);
    }

    public function test_case_c_stored_toggle_then_workspace_deny_override_preference_is_never_inferred_from_decision(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->createWorkspaceWithBusiness();
        $actorUserId = (int) $workspace->owner_user_id;
        $manager = app(EntitlementManager::class);
        $manager->disableBusinessFeature($business, PlatformFeature::Crm, $actorUserId);

        $manager->createOrChangeOverride($workspace, PlatformFeature::Crm, WorkspaceEntitlementOverrideState::Deny, $this->createAdmin(), 'Deny override.');

        $result = $manager->decideAvailableFeaturesForBusiness($workspace, $business, $actorUserId);

        $this->assertSame('denied_by_workspace_override', $result[PlatformFeature::Crm->value]['decision']->reason);
        $this->assertTrue($result[PlatformFeature::Crm->value]['disablePreferenceRecorded']);
    }

    public function test_case_d_no_toggle_effective_denial_for_any_reason(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->createWorkspaceWithBusiness();
        app(EntitlementManager::class)->createOrChangeOverride(
            $workspace, PlatformFeature::Crm, WorkspaceEntitlementOverrideState::Deny, $this->createAdmin(), 'Deny override.'
        );

        $result = app(EntitlementManager::class)->decideAvailableFeaturesForBusiness($workspace, $business, $this->createAdmin());

        $this->assertFalse($result[PlatformFeature::Crm->value]['decision']->allowed);
        $this->assertFalse($result[PlatformFeature::Crm->value]['disablePreferenceRecorded']);
    }

    /**
     * Blocker 3's actual claim: an allow override can grant a feature the
     * Workspace's base plan tier does NOT structurally package. Core
     * packages crm by default, so this proof removes that one mapping row
     * (test fixture only -- production seed/repository untouched), proving
     * crm is denied by base packaging alone, then proving the allow
     * override still surfaces it as entitled.
     */
    public function test_override_outside_base_plan_packaging_is_still_returned(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->createWorkspaceWithBusiness();
        $manager = app(EntitlementManager::class);

        $coreCatalog = \App\Models\WorkspacePlanCatalog::where('tier', 'core')->firstOrFail();
        \App\Models\WorkspacePlanFeature::where('workspace_plan_catalog_id', $coreCatalog->id)
            ->where('feature_key', PlatformFeature::Crm->value)
            ->delete();

        // With the packaging row removed and no override yet, crm must now
        // be denied strictly by base-plan packaging -- the baseline this
        // test's name actually needs.
        $baseline = $manager->decideAvailableFeaturesForBusiness($workspace, $business, $this->createAdmin());
        $this->assertFalse($baseline[PlatformFeature::Crm->value]['decision']->allowed);
        $this->assertSame('not_entitled_by_plan', $baseline[PlatformFeature::Crm->value]['decision']->reason);

        $manager->createOrChangeOverride(
            $workspace, PlatformFeature::Crm, WorkspaceEntitlementOverrideState::Allow, $this->createAdmin(), 'Allow outside base packaging.'
        );

        $result = $manager->decideAvailableFeaturesForBusiness($workspace, $business, $this->createAdmin());

        $this->assertArrayHasKey(PlatformFeature::Crm->value, $result);
        $this->assertTrue($result[PlatformFeature::Crm->value]['decision']->allowed);
    }

    public function test_planned_feature_keys_are_never_present_in_the_returned_map(): void
    {
        ['workspace' => $workspace, 'business' => $business] = $this->createWorkspaceWithBusiness();

        $result = app(EntitlementManager::class)->decideAvailableFeaturesForBusiness($workspace, $business, $this->createAdmin());

        $this->assertArrayNotHasKey(PlatformFeature::Calendar->value, $result);
        $this->assertArrayNotHasKey(PlatformFeature::ProspectOutreach->value, $result);
        $this->assertCount(3, $result);
    }

    public function test_stale_or_reassigned_business_returns_an_empty_map(): void
    {
        ['workspace' => $oldWorkspace, 'business' => $business] = $this->createWorkspaceWithBusiness();
        ['workspace' => $newWorkspace] = $this->createWorkspaceWithBusiness();
        $newWorkspace->update(['owner_user_id' => $oldWorkspace->owner_user_id]);

        app(\App\Library\Workspace\WorkspaceManager::class)->reassignBusiness((int) $oldWorkspace->owner_user_id, $business, $newWorkspace);

        $result = app(EntitlementManager::class)->decideAvailableFeaturesForBusiness($oldWorkspace, $business, $this->createAdmin());

        $this->assertSame([], $result);
    }
}
