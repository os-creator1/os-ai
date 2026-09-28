<?php

namespace Tests\Feature\GoogleBusinessProfile;

use App\Enums\Business\BusinessServiceMode;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileOAuthConfig;
use App\Library\GoogleBusinessProfile\GoogleOAuthStateSigner;
use App\Models\BusinessGoogleConnection;
use App\Models\BusinessGoogleLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\GoogleBusinessProfile\Concerns\CreatesGoogleBusinessProfileFixtures;
use Tests\TestCase;

/**
 * Customer acceptance pass for GBP Slice A.
 *
 * The suites elsewhere in this directory each prove one narrow contract
 * clause in isolation. This test instead drives the real routes in the
 * order an owner actually uses them — connect, choose, bind, view, refresh,
 * lose access — for the one combination none of them chains together in a
 * single flow: a Storefront location AND a Service-area location bound to
 * the SAME Business, through the real /gbp HTTP surface.
 *
 * FakeGoogleBusinessProfileReadClient is bound throughout, so no real
 * request to any googleapis.com host is made and no Google credential is
 * required.
 */
class GoogleBusinessProfileCustomerAcceptanceTest extends TestCase
{
    use CreatesGoogleBusinessProfileFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeGoogleClient();
    }

    public function test_the_owner_connects_binds_a_storefront_and_a_service_area_location_views_refreshes_and_loses_access(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $storefront = $this->createLocation($business, true, BusinessServiceMode::Storefront, [
            'name' => 'Snap Booth Co — Studio',
            'is_primary' => true,
        ]);

        $serviceArea = $this->createLocation($business, true, BusinessServiceMode::ServiceArea, [
            'name' => 'Snap Booth Co — Mobile',
            'is_primary' => false,
        ]);

        // ---------------------------------------------------------------
        // 1. Connect a Google account.
        // ---------------------------------------------------------------
        $this->post(route('customer.workspaces.businesses.gbp.connect', [$workspace->uid, $business->uid]))
            ->assertRedirect();

        $connection = BusinessGoogleConnection::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame(GoogleConnectionState::Pending, $connection->state);

        $state = app(GoogleOAuthStateSigner::class)->issue($connection);

        $this->get(route(GoogleBusinessProfileOAuthConfig::CALLBACK_ROUTE) . '?code=auth-code&state=' . urlencode($state))
            ->assertRedirect(route('customer.workspaces.businesses.gbp.locations', [$workspace->uid, $business->uid]));

        $connection->refresh();
        $this->assertSame(GoogleConnectionState::Active, $connection->state);

        // ---------------------------------------------------------------
        // 2. Choose: the chooser lists both a storefront-shaped and a
        // service-area-shaped Google location.
        // ---------------------------------------------------------------
        $this->fakeGoogle->withAccount('accounts/A1', 'Snap Booth Co');
        $this->fakeGoogle->withLocation('accounts/A1', 'locations/L1', $this->rawLocationPayload([
            'name' => 'locations/L1',
            'title' => 'Snap Booth Co — Studio',
        ]));
        $this->fakeGoogle->withLocation('accounts/A1', 'locations/L2', $this->rawLocationPayload([
            'name' => 'locations/L2',
            'title' => 'Snap Booth Co — Mobile',
            'storefrontAddress' => null,
            'serviceArea' => ['businessType' => 'CUSTOMER_LOCATION_ONLY'],
        ]));

        $this->get(route('customer.workspaces.businesses.gbp.locations', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee('locations/L1', false)
            ->assertSee('locations/L2', false)
            ->assertSee('name="candidate_token"', false);

        // ---------------------------------------------------------------
        // 3. Bind both — the storefront to the storefront BusinessLocation,
        // the service-area Google location to the service-area one.
        // ---------------------------------------------------------------
        $this->post(route('customer.workspaces.businesses.gbp.bind', [$workspace->uid, $business->uid]), [
            'candidate_token' => $this->candidateTokenFor($business, $connection, $customer->user_id, 'accounts/A1', 'locations/L1'),
            'business_location_uid' => $storefront->uid,
        ])->assertRedirect(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]));

        $this->post(route('customer.workspaces.businesses.gbp.bind', [$workspace->uid, $business->uid]), [
            'candidate_token' => $this->candidateTokenFor($business, $connection, $customer->user_id, 'accounts/A1', 'locations/L2'),
            'business_location_uid' => $serviceArea->uid,
        ])->assertRedirect(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]));

        $this->assertSame(2, BusinessGoogleLocation::query()->where('business_id', $business->id)->count());

        // ---------------------------------------------------------------
        // 4. The overview lists both bindings, and each has its own
        // reachable comparison — including the service-area one, which
        // must render with no address and without error.
        // ---------------------------------------------------------------
        $overview = $this->get(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]))
            ->assertOk();
        $overview->assertSee('locations/L1', false);
        $overview->assertSee('locations/L2', false);

        foreach (BusinessGoogleLocation::query()->where('business_id', $business->id)->get() as $binding) {
            $this->get(route('customer.workspaces.businesses.gbp.comparison', [$workspace->uid, $business->uid, $binding->uid]))
                ->assertOk();
        }

        // ---------------------------------------------------------------
        // 5. Refresh both in one manual refresh. A positive retention TTL
        // is configured so the refresh persists and redirects, exactly
        // like GoogleBusinessProfileMultiLocationTest's comparison-route
        // fixture — a zero TTL renders the comparison ephemerally instead
        // (contract §24.8), which is a different, already-covered path.
        // ---------------------------------------------------------------
        config(['google_business_profile.mirror.retention_days' => 7]);

        $this->post(route('customer.workspaces.businesses.gbp.refresh', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]))
            ->assertSessionHas('status', 'success');

        // ---------------------------------------------------------------
        // 6. Disconnect — losing access is guided back to reconnecting,
        // never a raw error, and the stored authorization is gone.
        // ---------------------------------------------------------------
        $this->post(route('customer.workspaces.businesses.gbp.disconnect', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]));

        $connection->refresh();
        $this->assertSame(GoogleConnectionState::Disconnected, $connection->state);
        $this->assertNull($connection->refresh_token_encrypted);

        // Contract §13.5 (as GoogleBusinessProfileOAuthTest also proves for
        // a single binding): disconnect destroys both bindings along with
        // the authorization, for every location the Business had bound —
        // not just the storefront one.
        $this->assertSame(0, BusinessGoogleLocation::query()->where('business_id', $business->id)->count());

        $this->post(route('customer.workspaces.businesses.gbp.refresh', [$workspace->uid, $business->uid]))
            ->assertRedirect(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]))
            ->assertSessionHas('message', 'Connect a Google account first.');
    }
}
