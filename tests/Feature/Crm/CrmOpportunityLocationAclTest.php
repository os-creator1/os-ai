<?php

namespace Tests\Feature\Crm;

use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\CrmOpportunity;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 08B Part 3/§6 — LocationAccessGuard wired into
 * CrmOpportunitiesController's single-deal actions (the genuine
 * Location-bound "Opportunity" resource; see the implementation report for
 * why the legacy AI Opportunity-detection engine, `OpportunityController`,
 * is correctly out of scope).
 */
class CrmOpportunityLocationAclTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private const STAFF_PERMISSIONS = ['view_contact', 'update_contact'];

    private function location(Business $business, array $overrides = []): BusinessLocation
    {
        return BusinessLocation::create(array_merge([
            'business_id' => $business->id,
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ], $overrides));
    }

    public function test_selected_location_scope_with_correct_grant_is_allowed(): void
    {
        [$owner, $business, $workspace] = $this->crmTenant();
        $location = $this->location($business);
        $deal = $this->deal($business);
        $deal->forceFill(['location_id' => $location->id])->save();

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $location);
        $this->authenticateAs($staff, self::STAFF_PERMISSIONS);

        $this->get($this->crmRoute('opportunities.show', $workspace, $business, [$deal->uid]))->assertOk();
    }

    public function test_selected_location_scope_with_no_grant_is_denied(): void
    {
        [$owner, $business, $workspace] = $this->crmTenant();
        $location = $this->location($business);
        $deal = $this->deal($business);
        $deal->forceFill(['location_id' => $location->id])->save();

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        $this->authenticateAs($staff, self::STAFF_PERMISSIONS);

        $this->get($this->crmRoute('opportunities.show', $workspace, $business, [$deal->uid]))->assertNotFound();
    }

    public function test_selected_location_scope_with_only_a_sibling_locations_grant_is_denied(): void
    {
        [$owner, $business, $workspace] = $this->crmTenant();
        $location = $this->location($business);
        $siblingLocation = $this->location($business);
        $deal = $this->deal($business);
        $deal->forceFill(['location_id' => $location->id])->save();

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $siblingLocation);
        $this->authenticateAs($staff, self::STAFF_PERMISSIONS);

        $this->get($this->crmRoute('opportunities.show', $workspace, $business, [$deal->uid]))->assertNotFound();
    }

    public function test_all_location_scope_reaches_any_location_in_the_business(): void
    {
        [$owner, $business, $workspace] = $this->crmTenant();
        $location = $this->location($business);
        $deal = $this->deal($business);
        $deal->forceFill(['location_id' => $location->id])->save();

        $staff = $this->createCustomer();
        $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $this->authenticateAs($staff, self::STAFF_PERMISSIONS);

        $this->get($this->crmRoute('opportunities.show', $workspace, $business, [$deal->uid]))->assertOk();
    }

    public function test_owner_reaches_any_location_regardless_of_membership(): void
    {
        [$owner, $business, $workspace] = $this->crmTenant();
        $location = $this->location($business);
        $deal = $this->deal($business);
        $deal->forceFill(['location_id' => $location->id])->save();

        $this->authenticateAs($owner);

        $this->get($this->crmRoute('opportunities.show', $workspace, $business, [$deal->uid]))->assertOk();
    }

    public function test_a_null_location_deal_is_unaffected_by_location_acl(): void
    {
        [$owner, $business, $workspace] = $this->crmTenant();
        $deal = $this->deal($business);
        $this->assertNull($deal->location_id);

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        $this->authenticateAs($staff, self::STAFF_PERMISSIONS);

        $this->get($this->crmRoute('opportunities.show', $workspace, $business, [$deal->uid]))->assertOk();
    }

    public function test_foreign_business_remains_denied_even_with_a_location_grant_elsewhere(): void
    {
        [$ownerA, $businessA, $workspaceA] = $this->crmTenant('A ' . uniqid(), 'WS A ' . uniqid());
        [$ownerB, $businessB, $workspaceB] = $this->crmTenant('B ' . uniqid(), 'WS B ' . uniqid());
        $locationB = $this->location($businessB);
        $dealB = $this->deal($businessB);
        $dealB->forceFill(['location_id' => $locationB->id])->save();

        $staff = $this->createCustomer();
        $membershipA = $this->member($workspaceA, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membershipA->forceFill(['location_access_scope' => LocationAccessScope::All])->save();
        $this->authenticateAs($staff, self::STAFF_PERMISSIONS);

        $this->get($this->crmRoute('opportunities.show', $workspaceA, $businessB, [$dealB->uid]))->assertNotFound();
    }

    public function test_a_grant_for_an_unrelated_location_cannot_substitute_for_the_persisted_one(): void
    {
        [$owner, $business, $workspace] = $this->crmTenant();
        $trueLocation = $this->location($business);
        $otherLocation = $this->location($business);
        $deal = $this->deal($business);
        $deal->forceFill(['location_id' => $trueLocation->id])->save();

        $staff = $this->createCustomer();
        $membership = $this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $otherLocation);
        $this->authenticateAs($staff, self::STAFF_PERMISSIONS);

        $this->get($this->crmRoute('opportunities.show', $workspace, $business, [$deal->uid]))->assertNotFound();
    }
}
