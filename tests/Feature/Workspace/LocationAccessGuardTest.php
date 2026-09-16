<?php

namespace Tests\Feature\Workspace;

use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Exceptions\Workspace\LocationAccessDeniedException;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Repositories\Contracts\WorkspaceMembershipBusinessRepository;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * Implementation Contract 02 (Location ACL Foundation) §6/§13 —
 * LocationAccessGuard's full authority table, exactly mirroring
 * WorkspaceManager::userCanAccessBusiness()'s own precedent and test
 * coverage shape.
 */
class LocationAccessGuardTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;

    private function guard(): LocationAccessGuard
    {
        return app(LocationAccessGuard::class);
    }

    private function location(Business $business, array $overrides = []): BusinessLocation
    {
        return BusinessLocation::create(array_merge([
            'business_id' => $business->id,
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ], $overrides));
    }

    // -----------------------------------------------------------------
    // §6 authority table — happy path per actor row.
    // -----------------------------------------------------------------

    public function test_workspace_owner_always_has_full_access(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($this->createCustomer()->user_id, $workspace->id);
        $location = $this->location($business);

        $this->assertTrue($this->guard()->userCanAccessLocation((int) $owner->user_id, $location));
    }

    public function test_direct_business_owner_has_full_access(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $businessOwner = $this->createCustomer();
        $business = $this->createBusinessForCustomer($businessOwner->user_id, $workspace->id);
        $location = $this->location($business);

        $this->assertTrue($this->guard()->userCanAccessLocation((int) $businessOwner->user_id, $location));
    }

    public function test_active_membership_with_all_scope_and_business_all_has_full_access(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($this->createCustomer()->user_id, $workspace->id);
        $location = $this->location($business);

        $staff = $this->createCustomer();
        $this->createMembership($workspace, $staff->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::All,
        ]);

        $this->assertTrue($this->guard()->userCanAccessLocation((int) $staff->user_id, $location));
    }

    public function test_active_membership_with_all_scope_and_business_selected_and_assigned_has_full_access(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($this->createCustomer()->user_id, $workspace->id);
        $location = $this->location($business);

        $staff = $this->createCustomer();
        $membership = $this->createMembership($workspace, $staff->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
            'location_access_scope' => LocationAccessScope::All,
        ]);
        app(WorkspaceMembershipBusinessRepository::class)->assign($membership, $business);

        $this->assertTrue($this->guard()->userCanAccessLocation((int) $staff->user_id, $location));
    }

    public function test_active_membership_with_selected_scope_and_an_explicit_grant_has_full_access(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($this->createCustomer()->user_id, $workspace->id);
        $location = $this->location($business);

        $staff = $this->createCustomer();
        $membership = $this->createMembership($workspace, $staff->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::Selected,
        ]);
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $location);

        $this->assertTrue($this->guard()->userCanAccessLocation((int) $staff->user_id, $location));
    }

    // -----------------------------------------------------------------
    // §6 authority table — every denial row.
    // -----------------------------------------------------------------

    public function test_active_membership_with_all_scope_but_unreachable_business_is_denied(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($this->createCustomer()->user_id, $workspace->id);
        $location = $this->location($business);

        $staff = $this->createCustomer();
        // Selected Business scope, with NO Business-level grant for
        // $business — the transitional §5 composed check must deny even
        // though location_access_scope is All.
        $this->createMembership($workspace, $staff->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
            'location_access_scope' => LocationAccessScope::All,
        ]);

        $this->assertFalse($this->guard()->userCanAccessLocation((int) $staff->user_id, $location));
    }

    /**
     * IDOR/adversarial (§13): a Selected-scope membership with no grant for
     * this exact, real, persisted Location must be refused — proving
     * re-derivation, not obscurity, is the guard.
     */
    public function test_active_membership_with_selected_scope_and_no_grant_is_denied_for_a_real_location_id(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($this->createCustomer()->user_id, $workspace->id);
        $location = $this->location($business);

        $staff = $this->createCustomer();
        $this->createMembership($workspace, $staff->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::Selected,
        ]);

        $this->assertFalse($this->guard()->userCanAccessLocation((int) $staff->user_id, $location));
    }

    public function test_inactive_membership_is_denied(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($this->createCustomer()->user_id, $workspace->id);
        $location = $this->location($business);

        $staff = $this->createCustomer();
        $this->createMembership($workspace, $staff->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::All,
            'is_active' => false,
        ]);

        $this->assertFalse($this->guard()->userCanAccessLocation((int) $staff->user_id, $location));
    }

    public function test_no_membership_at_all_is_denied(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($this->createCustomer()->user_id, $workspace->id);
        $location = $this->location($business);

        $stranger = $this->createCustomer();

        $this->assertFalse($this->guard()->userCanAccessLocation((int) $stranger->user_id, $location));
    }

    /**
     * Cross-Workspace isolation (§13): a membership from Workspace X, with
     * a grant row force-inserted directly at the DB layer for a Location
     * that actually belongs to Workspace Y, must never be recognized as
     * valid — the guard derives the Workspace from the LOCATION, not from
     * the membership or the grant row, so this is structurally impossible
     * to satisfy regardless of what the grant table says.
     */
    public function test_a_membership_from_another_workspace_is_never_recognized_for_this_locations_workspace(): void
    {
        $ownerX = $this->createCustomer();
        $workspaceX = $this->createWorkspace($ownerX->user);
        $ownerY = $this->createCustomer();
        $workspaceY = $this->createWorkspace($ownerY->user);
        $businessY = $this->createBusinessForCustomer($this->createCustomer()->user_id, $workspaceY->id);
        $locationY = $this->location($businessY);

        $staff = $this->createCustomer();
        $membershipX = $this->createMembership($workspaceX, $staff->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::Selected,
        ]);

        // Force-inserted directly at the DB layer — assign() itself would
        // refuse this (CrossWorkspaceAssignmentException); this proves the
        // guard is safe even if such a row existed by some other means.
        DB::table('workspace_membership_locations')->insert([
            'workspace_membership_id' => $membershipX->id,
            'business_location_id' => $locationY->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse($this->guard()->userCanAccessLocation((int) $staff->user_id, $locationY));
    }

    // -----------------------------------------------------------------
    // assertUserCanAccessLocation() — delegates entirely, no second
    // algorithm.
    // -----------------------------------------------------------------

    public function test_assert_throws_location_access_denied_exception_when_denied(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($this->createCustomer()->user_id, $workspace->id);
        $location = $this->location($business);
        $stranger = $this->createCustomer();

        $this->expectException(LocationAccessDeniedException::class);
        $this->guard()->assertUserCanAccessLocation((int) $stranger->user_id, $location);
    }

    public function test_assert_does_not_throw_when_allowed(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($this->createCustomer()->user_id, $workspace->id);
        $location = $this->location($business);

        $this->guard()->assertUserCanAccessLocation((int) $owner->user_id, $location);
        $this->addToAssertionCount(1);
    }
}
