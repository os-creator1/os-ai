<?php

namespace Tests\Feature\Workspace;

use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Repositories\Contracts\WorkspaceMembershipBusinessRepository;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * Implementation Contract 19 §5.2/§5.8 and Contract 02 R-0 —
 * LocationAccessGuard::authorizedLocationIdsFor() is the SET shape of the one
 * Location authority, never a second one.
 *
 * The COO's authorization-scope fingerprint needs the actor's authorized set
 * rather than a per-Location question, so the guard grew a set-shaped reader
 * that walks the same authority table once. The risk that creates is drift:
 * the set and the predicate silently disagreeing after a later edit to one of
 * them. Every case below therefore asserts the set against
 * userCanAccessLocation() itself, Location by Location, rather than against a
 * hand-written expectation — so the two can never diverge unnoticed.
 */
class LocationAccessSetConsistencyTest extends TestCase
{
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;
    use RefreshDatabase;

    public function test_a_workspace_owner_sees_every_location_and_the_set_matches_the_predicate(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($this->createCustomer()->user_id, $workspace->id);
        $locations = $this->locations($business, 3);

        $this->assertSetMatchesPredicate((int) $owner->user_id, $business, $locations);
        $this->assertCount(3, $this->set((int) $owner->user_id, $business));
    }

    public function test_a_direct_business_owner_sees_every_location(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $businessOwner = $this->createCustomer();
        $business = $this->createBusinessForCustomer($businessOwner->user_id, $workspace->id);
        $locations = $this->locations($business, 2);

        $this->assertSetMatchesPredicate((int) $businessOwner->user_id, $business, $locations);
        $this->assertCount(2, $this->set((int) $businessOwner->user_id, $business));
    }

    public function test_a_selected_scope_membership_sees_exactly_its_granted_locations(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $locations = $this->locations($business, 3);

        $staff = $this->createCustomer();
        $membership = $this->createMembership($workspace, $staff->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::Selected,
        ]);
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $locations[0]);
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $locations[2]);

        $this->assertSetMatchesPredicate((int) $staff->user_id, $business, $locations);
        $this->assertSame(
            [(int) $locations[0]->id, (int) $locations[2]->id],
            $this->set((int) $staff->user_id, $business),
        );
    }

    public function test_an_all_scope_membership_sees_every_location(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $locations = $this->locations($business, 2);

        $staff = $this->createCustomer();
        $this->createMembership($workspace, $staff->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::All,
        ]);

        $this->assertSetMatchesPredicate((int) $staff->user_id, $business, $locations);
        $this->assertCount(2, $this->set((int) $staff->user_id, $business));
    }

    public function test_a_membership_that_cannot_reach_the_business_sees_nothing(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $locations = $this->locations($business, 2);

        $staff = $this->createCustomer();
        $this->createMembership($workspace, $staff->user, [
            // Selected Business scope with no grant for this Business: the
            // Location axis can never be wider than the live Business axis.
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
            'location_access_scope' => LocationAccessScope::All,
        ]);

        $this->assertSetMatchesPredicate((int) $staff->user_id, $business, $locations);
        $this->assertSame([], $this->set((int) $staff->user_id, $business));
    }

    public function test_a_business_grant_narrowed_after_a_location_grant_collapses_the_set(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $locations = $this->locations($business, 2);

        $staff = $this->createCustomer();
        $membership = $this->createMembership($workspace, $staff->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::Selected,
            'location_access_scope' => LocationAccessScope::Selected,
        ]);
        app(WorkspaceMembershipBusinessRepository::class)->assign($membership, $business);
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $locations[0]);

        $this->assertSame([(int) $locations[0]->id], $this->set((int) $staff->user_id, $business));

        app(WorkspaceMembershipBusinessRepository::class)->unassign($membership, (int) $business->id);

        $this->assertSetMatchesPredicate((int) $staff->user_id, $business, $locations);
        $this->assertSame([], $this->set((int) $staff->user_id, $business), 'A stale Location grant never outlives the Business grant.');
    }

    public function test_an_inactive_membership_and_a_stranger_both_see_nothing(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $locations = $this->locations($business, 2);

        $inactive = $this->createCustomer();
        $this->createMembership($workspace, $inactive->user, [
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::All,
            'is_active' => false,
        ]);

        $stranger = $this->createCustomer();

        foreach ([$inactive, $stranger] as $actor) {
            $this->assertSetMatchesPredicate((int) $actor->user_id, $business, $locations);
            $this->assertSame([], $this->set((int) $actor->user_id, $business));
        }
    }

    public function test_an_inactive_workspace_authorises_nothing_for_anyone(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $locations = $this->locations($business, 2);

        $workspace->forceFill(['is_active' => false])->save();

        $this->assertSetMatchesPredicate((int) $owner->user_id, $business, $locations);
        $this->assertSame([], $this->set((int) $owner->user_id, $business));
    }

    public function test_a_business_with_no_locations_yields_an_empty_set_for_an_authorised_actor(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);

        $this->assertSame([], $this->set((int) $owner->user_id, $business), 'No Locations is an honest empty set, not a denial.');
    }

    public function test_the_set_is_always_sorted_ascending_and_free_of_duplicates(): void
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $business = $this->createBusinessForCustomer($owner->user_id, $workspace->id);
        $this->locations($business, 4);

        $set = $this->set((int) $owner->user_id, $business);
        $sorted = $set;
        sort($sorted, SORT_NUMERIC);

        $this->assertSame($sorted, $set);
        $this->assertSame(array_values(array_unique($set)), $set);
    }

    // -----------------------------------------------------------------

    /** @return array<int, BusinessLocation> */
    private function locations(Business $business, int $count): array
    {
        $created = [];

        for ($i = 0; $i < $count; $i++) {
            $created[] = BusinessLocation::create([
                'business_id' => $business->id,
                'name' => 'Location ' . ($i + 1),
                'service_mode' => 'storefront',
                'country_code' => 'US',
            ]);
        }

        return $created;
    }

    /** @return array<int, int> */
    private function set(int $userId, Business $business): array
    {
        return app(LocationAccessGuard::class)->authorizedLocationIdsFor($userId, $business);
    }

    /**
     * The anti-drift assertion: the set contains a Location if and only if the
     * predicate allows it.
     *
     * @param  array<int, BusinessLocation>  $locations
     */
    private function assertSetMatchesPredicate(int $userId, Business $business, array $locations): void
    {
        $guard = app(LocationAccessGuard::class);
        $set = $this->set($userId, $business);

        foreach ($locations as $location) {
            $this->assertSame(
                $guard->userCanAccessLocation($userId, $location),
                in_array((int) $location->id, $set, true),
                'The set-shaped reader and the per-Location predicate must never disagree (Location ' . $location->id . ').',
            );
        }
    }
}
