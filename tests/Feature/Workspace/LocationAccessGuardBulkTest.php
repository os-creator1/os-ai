<?php

namespace Tests\Feature\Workspace;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Library\Business\BusinessLocationManager;
use App\Library\Support\RequestScopedCache;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Repositories\Contracts\BusinessLocationRepository;
use App\Repositories\Contracts\WorkspaceMembershipBusinessRepository;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\Feature\Workspace\Concerns\CreatesWorkspaceTestData;
use Tests\TestCase;

/**
 * Contract 18 Sub-slice 18A §10.4 — LocationAccessGuard::
 * accessibleLocationIdsForBusiness() is the additive constant-query bulk form
 * of userCanAccessLocation(). It must be EQUIVALENT to filtering the
 * per-Location check over every Location of the Business, for every actor
 * class in Contract 02 §6's authority table — otherwise SEO would either
 * leak an inaccessible Location or hide an accessible one.
 */
class LocationAccessGuardBulkTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;
    use CreatesWorkspaceTestData;
    use CreatesCustomerContextFixtures;

    private function guard(): LocationAccessGuard
    {
        return app(LocationAccessGuard::class);
    }

    private function location(Business $business, string $name, array $overrides = []): BusinessLocation
    {
        return BusinessLocation::create(array_merge([
            'business_id' => $business->id,
            'name' => $name,
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ], $overrides));
    }

    /**
     * The bulk answer must equal the per-Location answer, id for id and in
     * the same order.
     *
     * @return array<int, int> the (equal) accessible ids
     */
    private function assertEquivalent(int $userId, Business $business): array
    {
        $perLocation = app(BusinessLocationRepository::class)->forBusiness($business)
            ->filter(fn (BusinessLocation $l) => $this->guard()->userCanAccessLocation($userId, $l))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $bulk = $this->guard()->accessibleLocationIdsForBusiness($userId, $business);

        $this->assertSame($perLocation, $bulk, 'The bulk method must equal the per-Location check.');

        return $bulk;
    }

    /**
     * @return array{owner: \App\Models\Customer, workspace: \App\Models\Workspace, business: Business, locations: array<int, BusinessLocation>}
     */
    private function tenantWithLocations(int $locationCount = 4): array
    {
        $owner = $this->createCustomer();
        $workspace = $this->createWorkspace($owner->user);
        $businessOwner = $this->createCustomer();
        $business = $this->createBusinessForCustomer($businessOwner->user_id, $workspace->id);

        $locations = [];
        for ($i = 1; $i <= $locationCount; $i++) {
            $locations[] = $this->location($business, "Site {$i}");
        }

        return ['owner' => $owner, 'workspace' => $workspace, 'business' => $business, 'locations' => $locations, 'businessOwner' => $businessOwner];
    }

    // -----------------------------------------------------------------
    // Equivalence, one test per actor class.
    // -----------------------------------------------------------------

    public function test_the_workspace_owner_reaches_every_location(): void
    {
        $t = $this->tenantWithLocations();

        $ids = $this->assertEquivalent((int) $t['owner']->user_id, $t['business']);

        $this->assertCount(4, $ids);
    }

    public function test_the_direct_business_owner_reaches_every_location(): void
    {
        $t = $this->tenantWithLocations();

        $this->assertCount(4, $this->assertEquivalent((int) $t['businessOwner']->user_id, $t['business']));
    }

    public function test_an_all_scope_member_reaches_every_location(): void
    {
        $t = $this->tenantWithLocations();
        $staff = $this->createCustomer();
        $this->createMembership($t['workspace'], $staff->user);

        $this->assertCount(4, $this->assertEquivalent((int) $staff->user_id, $t['business']));
    }

    public function test_a_selected_scope_member_reaches_exactly_the_granted_locations(): void
    {
        $t = $this->tenantWithLocations();
        $staff = $this->createCustomer();
        $membership = $this->createMembership($t['workspace'], $staff->user, ['location_access_scope' => LocationAccessScope::Selected]);
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $t['locations'][1]);
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $t['locations'][3]);

        $ids = $this->assertEquivalent((int) $staff->user_id, $t['business']);

        $this->assertSame([(int) $t['locations'][1]->id, (int) $t['locations'][3]->id], $ids);
    }

    public function test_a_selected_scope_member_with_no_grants_reaches_nothing(): void
    {
        $t = $this->tenantWithLocations();
        $staff = $this->createCustomer();
        $this->createMembership($t['workspace'], $staff->user, ['location_access_scope' => LocationAccessScope::Selected]);

        $this->assertSame([], $this->assertEquivalent((int) $staff->user_id, $t['business']));
    }

    public function test_a_grant_for_another_businesss_location_never_leaks_into_this_businesss_ids(): void
    {
        $t = $this->tenantWithLocations(2);
        $otherWorkspace = $this->createWorkspace($this->createCustomer()->user);
        $other = $this->createBusinessForCustomer($this->createCustomer()->user_id, $otherWorkspace->id);
        $foreignLocation = $this->location($other, 'Foreign Site');

        $staff = $this->createCustomer();
        $membership = $this->createMembership($t['workspace'], $staff->user, ['location_access_scope' => LocationAccessScope::Selected]);
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $t['locations'][0]);
        // A stale/forced grant pointing at a Location of a DIFFERENT Business.
        DB::table('workspace_membership_locations')->insert([
            'workspace_membership_id' => $membership->id,
            'business_location_id' => $foreignLocation->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $ids = $this->assertEquivalent((int) $staff->user_id, $t['business']);

        $this->assertSame([(int) $t['locations'][0]->id], $ids);
        $this->assertNotContains((int) $foreignLocation->id, $ids);
    }

    public function test_business_reach_is_still_required_for_a_member_with_location_all(): void
    {
        $t = $this->tenantWithLocations();
        $staff = $this->createCustomer();
        $this->createMembership($t['workspace'], $staff->user, ['business_access_scope' => WorkspaceBusinessAccessScope::Selected]);

        $this->assertSame([], $this->assertEquivalent((int) $staff->user_id, $t['business']), 'No Business grant means no Location, whatever the Location scope.');

        app(WorkspaceMembershipBusinessRepository::class)->assign(
            \App\Models\WorkspaceMembership::query()->where('user_id', $staff->user_id)->firstOrFail(),
            $t['business'],
        );

        $this->assertCount(4, $this->assertEquivalent((int) $staff->user_id, $t['business']));
    }

    public function test_an_inactive_membership_and_a_stranger_reach_nothing(): void
    {
        $t = $this->tenantWithLocations();
        $inactive = $this->createCustomer();
        $this->createMembership($t['workspace'], $inactive->user, ['is_active' => false]);
        $stranger = $this->createCustomer();

        $this->assertSame([], $this->assertEquivalent((int) $inactive->user_id, $t['business']));
        $this->assertSame([], $this->assertEquivalent((int) $stranger->user_id, $t['business']));
    }

    public function test_a_membership_of_a_different_workspace_grants_nothing_here(): void
    {
        $t = $this->tenantWithLocations();
        $otherOwner = $this->createCustomer();
        $otherWorkspace = $this->createWorkspace($otherOwner->user);
        $member = $this->createCustomer();
        $this->createMembership($otherWorkspace, $member->user);

        $this->assertSame([], $this->assertEquivalent((int) $member->user_id, $t['business']));
    }

    public function test_an_inactive_workspace_reaches_nothing_even_for_its_owner(): void
    {
        $t = $this->tenantWithLocations();
        DB::table('workspaces')->where('id', $t['workspace']->id)->update(['is_active' => false]);

        $this->assertSame([], $this->assertEquivalent((int) $t['owner']->user_id, $t['business']));
    }

    public function test_archived_locations_are_answered_exactly_like_the_per_location_check(): void
    {
        $t = $this->tenantWithLocations(3);
        DB::table('business_locations')->where('id', $t['locations'][2]->id)->update(['lifecycle_state' => 'archived', 'archived_at' => now()]);

        $ids = $this->assertEquivalent((int) $t['owner']->user_id, $t['business']);

        $this->assertContains((int) $t['locations'][2]->id, $ids, 'Access does not depend on lifecycle; callers filter lifecycle themselves.');
    }

    public function test_a_business_with_no_locations_returns_an_empty_list(): void
    {
        $t = $this->tenantWithLocations(0);

        $this->assertSame([], $this->guard()->accessibleLocationIdsForBusiness((int) $t['owner']->user_id, $t['business']));
    }

    public function test_a_passed_in_model_is_never_trusted(): void
    {
        $t = $this->tenantWithLocations(2);
        $stranger = $this->createCustomer();

        // A Business model whose workspace_id was tampered with in memory.
        $tampered = $t['business']->replicate();
        $tampered->id = $t['business']->id;
        $tampered->workspace_id = 999999;
        $tampered->customer_id = $stranger->user_id;

        $this->assertSame([], $this->guard()->accessibleLocationIdsForBusiness((int) $stranger->user_id, $tampered));
    }

    // -----------------------------------------------------------------
    // Cross-Workspace Agency View As replaces the table wholesale.
    // -----------------------------------------------------------------

    public function test_agency_view_as_reaches_only_the_viewed_clients_locations_in_bulk_too(): void
    {
        $this->platformAdminId();
        $pair = $this->createAgencyManagedClient(
            clientBusinessName: 'Harbor Lane Studios',
            clientWorkspaceName: 'Harbor Lane',
            agencyBusinessName: 'Northwind House',
            agencyWorkspaceName: 'Northwind Agency',
        );
        $this->assignTier($pair['clientWorkspace'], WorkspacePlanTier::Growth);

        $manager = app(BusinessLocationManager::class);
        $attributes = fn (string $name) => [
            'name' => $name, 'service_mode' => 'storefront', 'address_line_1' => '1 ' . $name, 'city' => 'Springfield',
            'region' => 'IL', 'postal_code' => '62701', 'country_code' => 'US', 'public_address' => true,
        ];
        $manager->createLocation($pair['clientBusiness'], $attributes('Harbor Main'), (int) $pair['clientOwner']->user_id);
        $manager->createLocation($pair['clientBusiness'], $attributes('Harbor Annex'), (int) $pair['clientOwner']->user_id);
        $manager->createLocation($pair['agencyBusiness'], $attributes('Northwind HQ'), (int) $pair['agencyOwner']->user_id);

        $actorId = (int) $pair['agencyOwner']->user_id;

        app(RequestScopedCache::class)->flush();
        $this->authenticateAs($pair['agencyOwner']);
        $this->post(route('customer.workspaces.clients.view-as', [$pair['agencyWorkspace']->uid, $pair['clientWorkspace']->uid]))
            ->assertRedirect(route('user.home'));
        app(RequestScopedCache::class)->flush();

        $viewed = $this->assertEquivalent($actorId, $pair['clientBusiness']);
        $this->assertGreaterThanOrEqual(2, count($viewed), 'The viewed Client\'s Locations are reachable.');

        // View As only narrows: the Agency's OWN Business is refused while viewing.
        $this->assertSame([], $this->assertEquivalent($actorId, $pair['agencyBusiness']));
    }

    // -----------------------------------------------------------------
    // Constant cost: the whole point of the bulk form.
    // -----------------------------------------------------------------

    public function test_the_query_count_does_not_grow_with_the_number_of_locations(): void
    {
        $small = $this->tenantWithLocations(1);
        $large = $this->tenantWithLocations(25);

        foreach (['owner', 'selected'] as $actorKind) {
            $counts = [];

            foreach ([$small, $large] as $index => $t) {
                if ($actorKind === 'owner') {
                    $userId = (int) $t['owner']->user_id;
                } else {
                    $staff = $this->createCustomer();
                    $membership = $this->createMembership($t['workspace'], $staff->user, ['location_access_scope' => LocationAccessScope::Selected]);
                    foreach (array_slice($t['locations'], 0, min(3, count($t['locations']))) as $granted) {
                        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $granted);
                    }
                    $userId = (int) $staff->user_id;
                }

                // The query log (not DB::listen): a listener registered in an
                // earlier iteration would otherwise keep recording into this one.
                DB::flushQueryLog();
                DB::enableQueryLog();
                $this->guard()->accessibleLocationIdsForBusiness($userId, $t['business']);
                $counts[$index] = count(DB::getQueryLog());
                DB::disableQueryLog();
            }

            $this->assertSame($counts[0], $counts[1], "[{$actorKind}] Query count must be identical for 1 and 25 Locations.");
            $this->assertLessThanOrEqual(8, $counts[1], "[{$actorKind}] The bulk form must stay cheap.");
        }
    }
}
