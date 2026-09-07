<?php

namespace Tests\Feature\GoogleBusinessProfile;

use App\Models\BusinessGoogleLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\GoogleBusinessProfile\Concerns\CreatesGoogleBusinessProfileFixtures;
use Tests\TestCase;

/**
 * GBP Slice A contract §8 / §19.2 / §32.4 — explicit binding.
 *
 * Security criterion G-5: no Google location can be claimed by two
 * platform locations or two Businesses.
 */
class GoogleBusinessProfileBindingTest extends TestCase
{
    use CreatesGoogleBusinessProfileFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeGoogleClient();
    }

    /**
     * T-BIND-2 — rendering the chooser creates ZERO binding rows.
     * Candidates are request-scoped and are never persisted.
     */
    public function test_rendering_the_chooser_persists_no_candidate(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->createLocation($business);
        $this->activeConnection($business);
        $this->fakeGoogleWithLocation();

        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.gbp.locations', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee('Snap Booth Co');

        $this->assertDatabaseCount('business_google_locations', 0);
    }

    /**
     * T-BIND-3 — NOTHING is auto-bound, even when exactly one candidate
     * is returned. Binding requires the explicit POST.
     */
    public function test_a_single_candidate_is_never_auto_bound(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->createLocation($business);
        $this->activeConnection($business);
        $this->fakeGoogleWithLocation();

        $this->authenticateAsCustomer($customer);

        $response = $this->get(route('customer.workspaces.businesses.gbp.locations', [$workspace->uid, $business->uid]));

        $response->assertOk();
        $this->assertDatabaseCount('business_google_locations', 0);

        // The radio is rendered unchecked — nothing pre-selects it.
        $this->assertStringNotContainsString('checked', explode('provider_location_resource_name', $response->getContent())[1] ?? '');
    }

    /**
     * T-BIND-1 / T-BIND-5 / T-BIND-6 — an explicit bind persists BOTH
     * resource names (contract §8.4, correction A-2).
     */
    public function test_binding_persists_both_provider_resource_names(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $location = $this->createLocation($business);
        $this->activeConnection($business);
        $this->fakeGoogleWithLocation();

        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.gbp.bind', [$workspace->uid, $business->uid]), [
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => 'locations/L1',
            'business_location_uid' => $location->uid,
        ])->assertRedirect(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]));

        $this->assertDatabaseHas('business_google_locations', [
            'business_id' => $business->id,
            'business_location_id' => $location->id,
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => 'locations/L1',
        ]);

        $this->assertDatabaseHas('business_google_operations', [
            'business_id' => $business->id,
            'operation_type' => 'location_bound',
            'status' => 'succeeded',
        ]);
    }

    /**
     * T-BIND-4 / security criterion G-5 — provider_location_resource_name
     * is UNIQUE PLATFORM-WIDE. A second Business in a DIFFERENT Workspace
     * is refused with a product message, never a 500, and never told who
     * holds the other claim.
     */
    public function test_a_google_location_cannot_be_claimed_twice_across_workspaces(): void
    {
        [$firstCustomer, $firstBusiness, $firstWorkspace] = $this->entitledTenant();
        $firstLocation = $this->createLocation($firstBusiness);
        $this->activeConnection($firstBusiness);
        $this->fakeGoogleWithLocation();

        $this->authenticateAsCustomer($firstCustomer);
        $this->post(route('customer.workspaces.businesses.gbp.bind', [$firstWorkspace->uid, $firstBusiness->uid]), [
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => 'locations/L1',
            'business_location_uid' => $firstLocation->uid,
        ])->assertRedirect();

        $this->assertDatabaseCount('business_google_locations', 1);

        // A completely separate tenant tries to claim the same listing.
        [$secondCustomer, $secondBusiness, $secondWorkspace] = $this->entitledTenant();
        $secondLocation = $this->createLocation($secondBusiness);
        $this->activeConnection($secondBusiness);

        $this->authenticateAsCustomer($secondCustomer);
        $response = $this->post(route('customer.workspaces.businesses.gbp.bind', [$secondWorkspace->uid, $secondBusiness->uid]), [
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => 'locations/L1',
            'business_location_uid' => $secondLocation->uid,
        ]);

        $response->assertRedirect();
        $this->assertSame('error', session('status'));
        $this->assertStringContainsString('already connected', (string) session('message'));

        // Still exactly one binding, and it belongs to the first tenant.
        $this->assertDatabaseCount('business_google_locations', 1);
        $this->assertDatabaseHas('business_google_locations', ['business_id' => $firstBusiness->id]);

        // The refusal never names the other tenant.
        $this->assertStringNotContainsString($firstBusiness->name, (string) session('message'));
    }

    /** T-BIND-7 — a BusinessLocation uid from another Business is 404. */
    public function test_binding_a_foreign_business_location_is_not_found(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->createLocation($business);
        $this->activeConnection($business);
        $this->fakeGoogleWithLocation();

        [, $otherBusiness] = $this->entitledTenant();
        $foreignLocation = $this->createLocation($otherBusiness);

        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.gbp.bind', [$workspace->uid, $business->uid]), [
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => 'locations/L1',
            'business_location_uid' => $foreignLocation->uid,
        ])->assertNotFound();

        $this->assertDatabaseCount('business_google_locations', 0);
    }

    /** Contract §18.2 — shape validation rejects a malformed resource name. */
    public function test_malformed_resource_names_are_rejected_by_validation(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $location = $this->createLocation($business);
        $this->activeConnection($business);
        $this->fakeGoogleWithLocation();

        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.gbp.bind', [$workspace->uid, $business->uid]), [
            'provider_account_resource_name' => 'https://evil.test/accounts/A1',
            'provider_location_resource_name' => 'locations/../../etc/passwd',
            'business_location_uid' => $location->uid,
        ])->assertSessionHasErrors(['provider_account_resource_name', 'provider_location_resource_name']);

        $this->assertDatabaseCount('business_google_locations', 0);
        $this->assertSame(0, $this->fakeGoogle->callCount('getLocation'));
    }

    /**
     * T-BIND-8 — unbind deletes the binding, leaves the connection ACTIVE,
     * and writes a ledger row.
     */
    public function test_unbind_removes_the_binding_but_keeps_the_connection(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $location = $this->createLocation($business);
        $connection = $this->activeConnection($business);
        $this->fakeGoogleWithLocation();

        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.gbp.bind', [$workspace->uid, $business->uid]), [
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => 'locations/L1',
            'business_location_uid' => $location->uid,
        ]);

        $binding = BusinessGoogleLocation::query()->where('business_id', $business->id)->firstOrFail();

        $this->post(route('customer.workspaces.businesses.gbp.unbind', [$workspace->uid, $business->uid]), [
            'binding_uid' => $binding->uid,
        ])->assertRedirect();

        $this->assertDatabaseMissing('business_google_locations', ['id' => $binding->id]);

        $connection->refresh();
        $this->assertTrue($connection->isActive());

        $this->assertDatabaseHas('business_google_operations', [
            'business_id' => $business->id,
            'operation_type' => 'location_unbound',
            'status' => 'succeeded',
        ]);
    }

    /**
     * T-DEL-2 / contract §29.5 — deleting a BusinessLocation deletes its
     * binding through composite FK C-4, and leaves the connection alone.
     */
    public function test_deleting_a_business_location_deletes_only_its_binding(): void
    {
        [, $business] = $this->entitledTenant();
        $location = $this->createLocation($business);
        $connection = $this->activeConnection($business);

        BusinessGoogleLocation::create([
            'business_google_connection_id' => $connection->id,
            'business_id' => $business->id,
            'business_location_id' => $location->id,
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => 'locations/L1',
        ]);

        DB::table('business_locations')->where('id', $location->id)->delete();

        $this->assertSame(0, DB::table('business_google_locations')->where('business_id', $business->id)->count());
        $this->assertDatabaseHas('business_google_connections', ['id' => $connection->id, 'state' => 'active']);
    }

    /**
     * Contract §12 (C-1) — one connection per Business, enforced in the
     * database and not merely in code.
     */
    public function test_a_business_can_hold_only_one_connection(): void
    {
        [, $business] = $this->entitledTenant();
        $this->activeConnection($business);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $this->activeConnection($business);
    }
}
