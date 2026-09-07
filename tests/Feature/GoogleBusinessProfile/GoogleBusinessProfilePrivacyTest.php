<?php

namespace Tests\Feature\GoogleBusinessProfile;

use App\Enums\Business\BusinessServiceMode;
use App\Enums\GoogleBusinessProfile\GoogleComparisonStatus;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileComparator;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileMirrorService;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileReadMask;
use App\Models\BusinessGoogleLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\GoogleBusinessProfile\Concerns\CreatesGoogleBusinessProfileFixtures;
use Tests\TestCase;

/**
 * GBP Slice A contract §23 / §32.5 — the BLOCKING private-address
 * invariant, security criterion G-6:
 *
 *   "No GBP surface may display, transmit, log, persist, compare, or send
 *    to Google a street address when business_locations.public_address is
 *    false."
 *
 * The fixture address is '77 Secret Lane'. Every assertion below looks for
 * that exact string escaping into somewhere it must never reach.
 */
class GoogleBusinessProfilePrivacyTest extends TestCase
{
    use CreatesGoogleBusinessProfileFixtures;
    use RefreshDatabase;

    private const SECRET_ADDRESS = '77 Secret Lane';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeGoogleClient();
    }

    /** T-MASK-1 — every enumeration and read call sends a non-empty readMask. */
    public function test_every_provider_read_sends_a_non_empty_read_mask(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->createLocation($business, true);
        $this->activeConnection($business);
        $this->fakeGoogleWithLocation();

        $this->authenticateAsCustomer($customer);
        $this->get(route('customer.workspaces.businesses.gbp.locations', [$workspace->uid, $business->uid]));

        $calls = $this->fakeGoogle->callsTo('listLocations');

        $this->assertNotEmpty($calls);

        foreach ($calls as $call) {
            $this->assertNotEmpty($call['read_mask']);
            $this->assertContains('name', $call['read_mask']);
        }
    }

    /**
     * T-PRIV-1 / enforcement point 1 — when public_address is false, the
     * read mask sent to Google DOES NOT CONTAIN storefrontAddress. The
     * address is never even requested.
     */
    public function test_private_address_is_never_requested_from_google(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->createLocation($business, false);
        $this->activeConnection($business);
        $this->fakeGoogleWithLocation();

        $this->authenticateAsCustomer($customer);
        $this->get(route('customer.workspaces.businesses.gbp.locations', [$workspace->uid, $business->uid]));

        foreach ($this->fakeGoogle->callsTo('listLocations') as $call) {
            $this->assertNotContains('storefrontAddress', $call['read_mask']);
            $this->assertFalse($call['address_permitted']);
        }
    }

    /**
     * T-PRIV-4 — a service-area or online location omits the address
     * REGARDLESS of public_address, and hybrid is treated as service-area
     * unless consent is explicitly true.
     */
    public function test_service_mode_alone_can_suppress_the_address(): void
    {
        $mask = app(GoogleBusinessProfileReadMask::class);

        [, $business] = $this->entitledTenant();

        $serviceArea = $this->createLocation($business, true, BusinessServiceMode::ServiceArea, ['is_primary' => false]);
        $online = $this->createLocation($business, true, BusinessServiceMode::Online, ['is_primary' => false]);
        $hybridNoConsent = $this->createLocation($business, false, BusinessServiceMode::Hybrid, ['is_primary' => false]);
        $hybridConsent = $this->createLocation($business, true, BusinessServiceMode::Hybrid, ['is_primary' => false]);
        $storefront = $this->createLocation($business, true, BusinessServiceMode::Storefront, ['is_primary' => false]);

        $this->assertFalse($mask->addressPermittedForLocation($serviceArea));
        $this->assertFalse($mask->addressPermittedForLocation($online));
        $this->assertFalse($mask->addressPermittedForLocation($hybridNoConsent));
        $this->assertTrue($mask->addressPermittedForLocation($hybridConsent));
        $this->assertTrue($mask->addressPermittedForLocation($storefront));

        $this->assertNotContains('storefrontAddress', $mask->forLocation($serviceArea));
        $this->assertContains('storefrontAddress', $mask->forLocation($storefront));
    }

    /**
     * Contract §23.2 — enumeration omits the address unless EVERY location
     * in the Business permits it. The default when in doubt is omission,
     * including for a Business with no locations at all.
     */
    public function test_enumeration_fails_closed_when_any_location_withholds_its_address(): void
    {
        $mask = app(GoogleBusinessProfileReadMask::class);

        [, $emptyBusiness] = $this->entitledTenant();
        $this->assertFalse($mask->addressPermittedForEnumeration($emptyBusiness));

        [, $mixedBusiness] = $this->entitledTenant();
        $this->createLocation($mixedBusiness, true, BusinessServiceMode::Storefront);
        $this->createLocation($mixedBusiness, false, BusinessServiceMode::Storefront, ['is_primary' => false]);
        $this->assertFalse($mask->addressPermittedForEnumeration($mixedBusiness));

        [, $allPublicBusiness] = $this->entitledTenant();
        $this->createLocation($allPublicBusiness, true, BusinessServiceMode::Storefront);
        $this->assertTrue($mask->addressPermittedForEnumeration($allPublicBusiness));
    }

    /**
     * T-PRIV-2 / enforcement points 2-4 — even when Google returns a
     * storefrontAddress DESPITE the mask, it is discarded before it
     * reaches a DTO, a model or the database.
     */
    public function test_an_address_returned_despite_the_mask_is_discarded(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $location = $this->createLocation($business, false);
        $connection = $this->activeConnection($business);

        // The RAW payload deliberately carries the street address.
        $this->fakeGoogleWithLocation();
        $this->assertStringContainsString(
            self::SECRET_ADDRESS,
            json_encode($this->fakeGoogle->rawLocations['locations/L1']),
            'The fixture must actually contain the address, or this test proves nothing.',
        );

        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.gbp.bind', [$workspace->uid, $business->uid]), [
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => 'locations/L1',
            'business_location_uid' => $location->uid,
        ])->assertRedirect();

        $binding = BusinessGoogleLocation::query()->where('business_id', $business->id)->firstOrFail();

        // Nothing in the persisted row carries it.
        $persisted = json_encode(DB::table('business_google_locations')->where('id', $binding->id)->get());
        $this->assertStringNotContainsString(self::SECRET_ADDRESS, (string) $persisted);

        $this->assertNull($binding->bound_locality_snapshot);
        $this->assertNull($binding->bound_region_code_snapshot);
        $this->assertArrayNotHasKey('address_line_1', (array) $binding->profile_mirror);
        $this->assertNull($binding->profile_mirror['locality']);

        // ...nor the ledger.
        $ledger = json_encode(DB::table('business_google_operations')->get());
        $this->assertStringNotContainsString(self::SECRET_ADDRESS, (string) $ledger);
    }

    /**
     * T-PRIV-3 — the RESPONSE BODY of every GBP view never contains the
     * private address.
     */
    public function test_no_gbp_response_body_contains_a_private_address(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $location = $this->createLocation($business, false);
        $connection = $this->activeConnection($business);
        $this->fakeGoogleWithLocation();

        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.gbp.bind', [$workspace->uid, $business->uid]), [
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => 'locations/L1',
            'business_location_uid' => $location->uid,
        ]);

        foreach (['index', 'comparison', 'settings', 'locations'] as $name) {
            $body = (string) $this->get(route('customer.workspaces.businesses.gbp.' . $name, [$workspace->uid, $business->uid]))->getContent();

            $this->assertStringNotContainsString(self::SECRET_ADDRESS, $body, "The {$name} view leaked a private address.");
            $this->assertStringNotContainsString('10001', $body, "The {$name} view leaked a private postal code.");
        }
    }

    /**
     * T-CMP-7 / enforcement point 5 — the street-address row is
     * Not comparable in EVERY case, and when consent is off the City and
     * Country rows are withheld too, with no verdict implied.
     */
    public function test_the_comparison_never_renders_a_withheld_address(): void
    {
        config(["google_business_profile.mirror.retention_days" => 7]);

        [, $business] = $this->entitledTenant();
        $location = $this->createLocation($business, false);
        $connection = $this->activeConnection($business);
        $this->fakeGoogleWithLocation();

        $binding = BusinessGoogleLocation::create([
            'business_google_connection_id' => $connection->id,
            'business_id' => $business->id,
            'business_location_id' => $location->id,
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => 'locations/L1',
        ]);

        app(GoogleBusinessProfileMirrorService::class)->refresh($binding->fresh(), $connection);

        $rows = app(GoogleBusinessProfileComparator::class)->compare($business, $location, $binding->fresh());

        $byField = [];
        foreach ($rows as $row) {
            $byField[$row->field] = $row;
        }

        foreach (['Street address', 'City', 'Country'] as $field) {
            $this->assertSame(GoogleComparisonStatus::NotComparable, $byField[$field]->status, $field . ' must be Not comparable.');
            $this->assertNull($byField[$field]->platformValue, $field . ' must not render a platform value.');
            $this->assertNull($byField[$field]->googleValue, $field . ' must not render a Google value.');
        }

        $this->assertStringContainsString('withheld by consent', (string) $byField['City']->reason);
    }

    /**
     * Contract §23.5 — even with consent ON, the street address row stays
     * Not comparable: Slice A never requests addressLines, and address
     * equality produces confident wrong answers.
     */
    public function test_street_address_is_not_compared_even_when_public(): void
    {
        config(["google_business_profile.mirror.retention_days" => 7]);

        [, $business] = $this->entitledTenant();
        $location = $this->createLocation($business, true);
        $connection = $this->activeConnection($business);
        $this->fakeGoogleWithLocation();

        $binding = BusinessGoogleLocation::create([
            'business_google_connection_id' => $connection->id,
            'business_id' => $business->id,
            'business_location_id' => $location->id,
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => 'locations/L1',
        ]);

        app(GoogleBusinessProfileMirrorService::class)->refresh($binding->fresh(), $connection);

        $rows = app(GoogleBusinessProfileComparator::class)->compare($business, $location, $binding->fresh());

        $street = collect($rows)->firstWhere('field', 'Street address');
        $city = collect($rows)->firstWhere('field', 'City');

        $this->assertSame(GoogleComparisonStatus::NotComparable, $street->status);
        $this->assertNull($street->googleValue);

        // ...but the locality IS compared once consent is given.
        $this->assertSame(GoogleComparisonStatus::Match, $city->status);
        $this->assertSame('New York', $city->googleValue);
    }

    /**
     * Contract §23.6 — the storefront/consent contradiction is SURFACED,
     * never silently resolved in either direction.
     */
    public function test_the_storefront_consent_contradiction_is_surfaced(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->createLocation($business, false, BusinessServiceMode::Storefront);
        $connection = $this->activeConnection($business);
        $this->fakeGoogleWithLocation();

        $location = $business->fresh()->id;
        $binding = BusinessGoogleLocation::create([
            'business_google_connection_id' => $connection->id,
            'business_id' => $business->id,
            'business_location_id' => \App\Models\BusinessLocation::where('business_id', $business->id)->value('id'),
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => 'locations/L1',
        ]);

        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee('marked as a storefront', false);
    }

    /**
     * T-XSS-1 — Google-supplied content containing a script tag is
     * escaped, never executed.
     */
    public function test_google_supplied_content_is_escaped(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $location = $this->createLocation($business, true);
        $connection = $this->activeConnection($business);

        $this->fakeGoogleWithLocation(['title' => '<script>alert(1)</script>Evil Co']);

        $this->authenticateAsCustomer($customer);
        $this->post(route('customer.workspaces.businesses.gbp.bind', [$workspace->uid, $business->uid]), [
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => 'locations/L1',
            'business_location_uid' => $location->uid,
        ]);

        $body = (string) $this->get(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]))->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    /**
     * T-URL-1 — a Google-supplied URL with a non-https scheme is nulled,
     * never rendered.
     */
    public function test_non_https_google_urls_are_dropped(): void
    {
        config(["google_business_profile.mirror.retention_days" => 7]);

        [, $business] = $this->entitledTenant();
        $location = $this->createLocation($business, true);
        $connection = $this->activeConnection($business);

        $this->fakeGoogleWithLocation([
            'websiteUri' => 'javascript:alert(1)',
            'metadata' => ['mapsUri' => 'http://insecure.test/place'],
        ]);

        $binding = BusinessGoogleLocation::create([
            'business_google_connection_id' => $connection->id,
            'business_id' => $business->id,
            'business_location_id' => $location->id,
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => 'locations/L1',
        ]);

        app(GoogleBusinessProfileMirrorService::class)->refresh($binding->fresh(), $connection);

        $mirror = $binding->fresh()->profile_mirror;

        $this->assertNull($mirror['website_uri']);
        $this->assertNull($mirror['maps_uri']);
    }
}
