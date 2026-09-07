<?php

namespace Tests\Feature\GoogleBusinessProfile;

use App\Http\Controllers\Customer\Business\GoogleBusinessProfileController;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileBindingManager;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use App\Models\BusinessGoogleLocation;
use App\Models\BusinessLocation;
use App\Repositories\Contracts\BusinessGoogleLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\Feature\GoogleBusinessProfile\Concerns\CreatesGoogleBusinessProfileFixtures;
use Tests\TestCase;

/**
 * MULTI-LOCATION CORRECTION PASS — the customer surface must expose EVERY
 * binding a Business owns.
 *
 * The schema has always allowed one binding per BusinessLocation
 * (business_google_locations.business_location_id is UNIQUE) and therefore
 * MANY bindings per Business (business_id is only indexed). The
 * implementation nevertheless collapsed that collection everywhere:
 *
 *   - comparison() resolved a binding with findForBusiness(), which
 *     ordered by id and took the FIRST;
 *   - overviewData() exposed one $binding/$location pair;
 *   - the chooser received one $binding;
 *   - a zero-TTL refresh looped every binding but rendered only the LAST.
 *
 * Every binding except the first or the last was therefore invisible or
 * unreachable. These tests use ONE Business with THREE local locations and
 * THREE bindings, because a two-binding fixture cannot distinguish "shows
 * the first" from "shows the last" from "shows them all".
 */
class GoogleBusinessProfileMultiLocationTest extends TestCase
{
    use CreatesGoogleBusinessProfileFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeGoogleClient();
    }

    // -----------------------------------------------------------------
    // 1 / 2 — overview and settings render EVERY binding
    // -----------------------------------------------------------------

    /** Required test 1. */
    public function test_the_overview_renders_every_binding(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $fixture = $this->threeBoundLocations($business);

        $this->authenticateAsCustomer($customer);

        $response = $this->get(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]));

        $response->assertOk();

        foreach ($fixture['bindings'] as $index => $binding) {
            // Its Google identity, its LOCAL location, and a comparison
            // link addressed to this exact binding.
            $response->assertSee('Downtown ' . ($index + 1), false);
            $response->assertSee($binding->provider_location_resource_name, false);
            $response->assertSee(
                route('customer.workspaces.businesses.gbp.comparison', [$workspace->uid, $business->uid, $binding->uid]),
                false,
            );
        }
    }

    /** Required test 2. */
    public function test_settings_renders_every_binding(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $fixture = $this->threeBoundLocations($business);

        $this->authenticateAsCustomer($customer);

        $response = $this->get(route('customer.workspaces.businesses.gbp.settings', [$workspace->uid, $business->uid]));

        $response->assertOk();

        foreach ($fixture['bindings'] as $binding) {
            $response->assertSee($binding->provider_location_resource_name, false);
            // Each card carries an unlink form scoped to THAT binding uid.
            $response->assertSee('value="' . $binding->uid . '"', false);
        }

        foreach ($fixture['locations'] as $location) {
            $response->assertSee($location->name, false);
        }
    }

    // -----------------------------------------------------------------
    // 3 / 4 — the binding-addressed comparison route
    // -----------------------------------------------------------------

    /** Required test 3. */
    public function test_each_comparison_route_selects_the_requested_binding(): void
    {
        config(['google_business_profile.mirror.retention_days' => 7]);

        [$customer, $business, $workspace] = $this->entitledTenant();
        $fixture = $this->threeBoundLocations($business);

        $this->authenticateAsCustomer($customer);

        // Persist a real mirror for all three so each comparison has its
        // own Google-side values to render.
        $this->post(route('customer.workspaces.businesses.gbp.refresh', [$workspace->uid, $business->uid]))
            ->assertRedirect();

        foreach ($fixture['bindings'] as $index => $binding) {
            $response = $this->get(route(
                'customer.workspaces.businesses.gbp.comparison',
                [$workspace->uid, $business->uid, $binding->uid],
            ));

            $response->assertOk();
            $response->assertSee('Google Location ' . ($index + 1), false);

            // ...and NOT the other two. This is what proves the route
            // selects the requested binding rather than the first or last.
            foreach ([1, 2, 3] as $other) {
                if ($other === $index + 1) {
                    continue;
                }

                $response->assertDontSee('Google Location ' . $other, false);
            }
        }
    }

    /** Required test 4. */
    public function test_a_foreign_binding_uid_returns_404(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->threeBoundLocations($business);

        // A DIFFERENT Business, in a different Workspace, with its own
        // perfectly valid binding.
        [, $otherBusiness] = $this->entitledTenant();
        $foreign = $this->bindingFor(
            $otherBusiness,
            $this->activeConnection($otherBusiness),
            $this->createLocation($otherBusiness, true, overrides: ['name' => 'Foreign Location']),
            'locations/FOREIGN',
            'Foreign Google Location',
        );

        $this->authenticateAsCustomer($customer);

        $this->get(route(
            'customer.workspaces.businesses.gbp.comparison',
            [$workspace->uid, $business->uid, $foreign->uid],
        ))->assertNotFound();

        // An entirely unknown uid is indistinguishable from the foreign
        // one — neither discloses whether the binding exists.
        $this->get(route(
            'customer.workspaces.businesses.gbp.comparison',
            [$workspace->uid, $business->uid, 'e2b1c0de-0000-4000-8000-000000000000'],
        ))->assertNotFound();
    }

    // -----------------------------------------------------------------
    // 5 / 6 — binding and unbinding are per-location
    // -----------------------------------------------------------------

    /** Required test 5. */
    public function test_binding_a_second_location_does_not_overwrite_or_hide_the_first(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $connection = $this->activeConnection($business);

        $first = $this->createLocation($business, true, overrides: ['name' => 'Downtown 1']);
        $second = $this->createLocation($business, true, overrides: ['name' => 'Downtown 2', 'is_primary' => false]);

        $this->fakeGoogle->withAccount('accounts/A1', 'Multi Co');
        $this->fakeGoogle->withLocation('accounts/A1', 'locations/L1', ['name' => 'locations/L1', 'title' => 'Google Location 1']);
        $this->fakeGoogle->withLocation('accounts/A1', 'locations/L2', ['name' => 'locations/L2', 'title' => 'Google Location 2']);

        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.gbp.bind', [$workspace->uid, $business->uid]), [
            'candidate_token' => $this->candidateTokenFor($business, $connection, $customer->user_id, 'accounts/A1', 'locations/L1'),
            'business_location_uid' => $first->uid,
        ])->assertRedirect();

        $this->assertSame(1, BusinessGoogleLocation::query()->where('business_id', $business->id)->count());

        // Binding a SECOND local location must add a row, not replace one.
        $this->post(route('customer.workspaces.businesses.gbp.bind', [$workspace->uid, $business->uid]), [
            'candidate_token' => $this->candidateTokenFor($business, $connection, $customer->user_id, 'accounts/A1', 'locations/L2'),
            'business_location_uid' => $second->uid,
        ])->assertRedirect();

        $bindings = BusinessGoogleLocation::query()->where('business_id', $business->id)->orderBy('id')->get();

        $this->assertCount(2, $bindings, 'Binding a second location must not overwrite the first.');
        $this->assertSame(
            ['locations/L1', 'locations/L2'],
            $bindings->pluck('provider_location_resource_name')->all(),
        );
        $this->assertSame(
            [(int) $first->id, (int) $second->id],
            $bindings->pluck('business_location_id')->map(fn ($id) => (int) $id)->all(),
        );

        // Both are visible on the overview — the first is not hidden.
        $overview = $this->get(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]));
        $overview->assertOk();
        $overview->assertSee('Downtown 1', false);
        $overview->assertSee('Downtown 2', false);

        // The chooser now reports both local locations as already linked
        // and offers neither Google location again.
        $chooser = $this->get(route('customer.workspaces.businesses.gbp.locations', [$workspace->uid, $business->uid]));
        $chooser->assertOk();
        $chooser->assertSee('2 of 2 locations are already linked', false);
    }

    /** Required test 6. */
    public function test_unbinding_one_binding_leaves_the_others_intact(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $fixture = $this->threeBoundLocations($business);

        $this->authenticateAsCustomer($customer);

        $middle = $fixture['bindings'][1];

        $this->post(route('customer.workspaces.businesses.gbp.unbind', [$workspace->uid, $business->uid]), [
            'binding_uid' => $middle->uid,
        ])->assertRedirect();

        $remaining = BusinessGoogleLocation::query()->where('business_id', $business->id)->orderBy('id')->get();

        $this->assertCount(2, $remaining);
        $this->assertSame(
            ['locations/L1', 'locations/L3'],
            $remaining->pluck('provider_location_resource_name')->all(),
        );

        $overview = $this->get(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]));
        $overview->assertOk();
        $overview->assertSee('Downtown 1', false);
        $overview->assertSee('Downtown 3', false);
        $overview->assertDontSee('locations/L2', false);
    }

    // -----------------------------------------------------------------
    // 7 / 8 / 9 — refresh covers every binding
    // -----------------------------------------------------------------

    /** Required test 7. */
    public function test_a_persisted_refresh_processes_every_binding(): void
    {
        config(['google_business_profile.mirror.retention_days' => 7]);

        [$customer, $business, $workspace] = $this->entitledTenant();
        $fixture = $this->threeBoundLocations($business);

        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.gbp.refresh', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]));

        // Every binding was read from Google, exactly once each.
        $read = collect($this->fakeGoogle->callsTo('getLocation'))->pluck('location')->sort()->values()->all();
        $this->assertSame(['locations/L1', 'locations/L2', 'locations/L3'], $read);

        // ...and every binding now holds its own persisted mirror.
        foreach ($fixture['bindings'] as $binding) {
            $this->assertNotNull(
                DB::table('business_google_locations')->where('id', $binding->id)->value('profile_mirror'),
                'Binding ' . $binding->provider_location_resource_name . ' was not refreshed.',
            );
        }
    }

    /** Required test 8. */
    public function test_a_zero_ttl_refresh_renders_every_ephemeral_comparison(): void
    {
        config(['google_business_profile.mirror.retention_days' => null]);

        [$customer, $business, $workspace] = $this->entitledTenant();
        $fixture = $this->threeBoundLocations($business);

        $this->authenticateAsCustomer($customer);

        $response = $this->post(route('customer.workspaces.businesses.gbp.refresh', [$workspace->uid, $business->uid]));

        $response->assertOk();
        $response->assertSee('live view of what Google returned just now', false);

        // ALL THREE comparisons are in this one response — not just the
        // last binding, which is what the previous implementation returned.
        foreach ([1, 2, 3] as $index) {
            $response->assertSee('Google Location ' . $index, false);
            $response->assertSee('Downtown ' . $index, false);
        }

        // Nothing reusable was persisted for ANY of them...
        foreach ($fixture['bindings'] as $binding) {
            $this->assertNull(DB::table('business_google_locations')->where('id', $binding->id)->value('profile_mirror'));
        }

        // ...and nothing carried the Content out of band.
        $this->assertNull(session('message'));

        $ledger = (string) json_encode(DB::table('business_google_operations')->get());

        foreach ([1, 2, 3] as $index) {
            $this->assertStringNotContainsString('Google Location ' . $index, $ledger);
        }
    }

    /** Required test 9. */
    public function test_the_next_get_after_a_zero_ttl_refresh_reuses_none_of_them(): void
    {
        config(['google_business_profile.mirror.retention_days' => null]);

        [$customer, $business, $workspace] = $this->entitledTenant();
        $fixture = $this->threeBoundLocations($business);

        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.gbp.refresh', [$workspace->uid, $business->uid]))
            ->assertOk();

        foreach ($fixture['bindings'] as $index => $binding) {
            $next = $this->get(route(
                'customer.workspaces.businesses.gbp.comparison',
                [$workspace->uid, $business->uid, $binding->uid],
            ));

            $next->assertOk();
            $next->assertSee('Refresh to compare', false);
            $next->assertDontSee('Google Location ' . ($index + 1), false);
        }

        // The overview agrees: every binding needs a refresh again.
        $overview = $this->get(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]));
        $overview->assertOk();
        $this->assertSame(3, substr_count((string) $overview->getContent(), 'Refresh required'));
    }

    /**
     * One binding failing must not be reported as "everything refreshed",
     * and must not discard the independent read outcomes that already
     * completed for the other bindings (contract §24.10).
     */
    public function test_one_failing_binding_does_not_claim_that_every_binding_refreshed(): void
    {
        config(['google_business_profile.mirror.retention_days' => 7]);

        [$customer, $business, $workspace] = $this->entitledTenant();
        $fixture = $this->threeBoundLocations($business);

        // Remove the SECOND Google location from the provider, so its read
        // fails while the other two succeed.
        unset($this->fakeGoogle->rawLocations['locations/L2']);

        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.gbp.refresh', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]));

        $this->assertSame('error', session('status'));
        $this->assertStringContainsString('Refreshed 2 of 3', (string) session('message'));

        // The two that succeeded kept their mirrors; the failure did not.
        $this->assertNotNull(DB::table('business_google_locations')->where('id', $fixture['bindings'][0]->id)->value('profile_mirror'));
        $this->assertNull(DB::table('business_google_locations')->where('id', $fixture['bindings'][1]->id)->value('profile_mirror'));
        $this->assertNotNull(DB::table('business_google_locations')->where('id', $fixture['bindings'][2]->id)->value('profile_mirror'));
    }

    // -----------------------------------------------------------------
    // 10 — no first/last-binding shortcut remains
    // -----------------------------------------------------------------

    /**
     * Required test 10 — structural, so the collapse cannot silently
     * return. A behavioural test alone would pass again the moment someone
     * reintroduced a singular accessor for a one-binding Business.
     */
    public function test_no_first_or_last_binding_shortcut_remains(): void
    {
        // The ambiguous singular repository accessor is GONE, not merely
        // unused — it cannot be called back into service by accident.
        $this->assertFalse(
            method_exists(BusinessGoogleLocationRepository::class, 'findForBusiness'),
            'BusinessGoogleLocationRepository::findForBusiness() is ambiguous with many bindings per Business and must not exist.',
        );
        $this->assertFalse(
            method_exists(GoogleBusinessProfileBindingManager::class, 'findForBusiness'),
            'GoogleBusinessProfileBindingManager::findForBusiness() must not exist.',
        );

        // The interface exposes only unambiguous, plural or uid-addressed
        // reads.
        $methods = collect((new ReflectionClass(BusinessGoogleLocationRepository::class))->getMethods())
            ->map(fn ($method) => $method->getName())
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            ['allForBusiness', 'allForBusinessKeyedByLocationId', 'findByUidForBusiness'],
            $methods,
        );

        // And the controller keeps no "last binding wins" state.
        $source = (string) file_get_contents(
            (new ReflectionClass(GoogleBusinessProfileController::class))->getFileName(),
        );

        foreach (['lastBinding', 'lastResult', 'bindingRepository->findForBusiness'] as $shortcut) {
            $this->assertStringNotContainsString($shortcut, $source, "The controller still carries the {$shortcut} shortcut.");
        }
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /**
     * One Business, THREE local locations, THREE bindings, and a Fake
     * Google that can serve all three.
     *
     * @return array{connection: BusinessGoogleConnection, locations: array<int, BusinessLocation>, bindings: array<int, BusinessGoogleLocation>}
     */
    private function threeBoundLocations(Business $business): array
    {
        $connection = $this->activeConnection($business);

        $this->fakeGoogle->withAccount('accounts/A1', 'Multi Location Co');

        $locations = [];
        $bindings = [];

        foreach ([1, 2, 3] as $index) {
            $locations[] = $location = $this->createLocation($business, true, overrides: [
                'name' => 'Downtown ' . $index,
                'is_primary' => $index === 1,
            ]);

            $bindings[] = $this->bindingFor(
                $business,
                $connection,
                $location,
                'locations/L' . $index,
                'Google Location ' . $index,
            );
        }

        return ['connection' => $connection, 'locations' => $locations, 'bindings' => $bindings];
    }

    /**
     * Registers the Google side on the Fake and creates the binding row.
     * provider_location_resource_name is UNIQUE PLATFORM-WIDE (C-3), so
     * every caller supplies its own.
     */
    private function bindingFor(
        Business $business,
        BusinessGoogleConnection $connection,
        BusinessLocation $location,
        string $locationResourceName,
        string $googleTitle,
    ): BusinessGoogleLocation {
        $this->fakeGoogle->withLocation('accounts/A1', $locationResourceName, [
            'name' => $locationResourceName,
            'title' => $googleTitle,
        ]);

        return BusinessGoogleLocation::create([
            'business_google_connection_id' => $connection->id,
            'business_id' => $business->id,
            'business_location_id' => $location->id,
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => $locationResourceName,
        ]);
    }
}
