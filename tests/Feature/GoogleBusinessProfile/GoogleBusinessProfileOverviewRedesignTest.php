<?php

namespace Tests\Feature\GoogleBusinessProfile;

use App\Enums\GoogleBusinessProfile\GoogleLocationHealth;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use App\Models\BusinessGoogleLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\GoogleBusinessProfile\Concerns\CreatesGoogleBusinessProfileFixtures;
use Tests\TestCase;

/**
 * The GBP overview redesign is presentation only: these tests pin that the
 * header, profile card and "fields differ" cards are fed by the existing
 * mirror + comparator, that nothing writable appears, and that the
 * disconnected state is unchanged.
 */
class GoogleBusinessProfileOverviewRedesignTest extends TestCase
{
    use CreatesGoogleBusinessProfileFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRequiredAppConfigRowsExist();
        $this->bindFakeGoogleClient();
    }

    protected function bound(Business $business, array $mirror, array $bindingOverrides = []): BusinessGoogleLocation
    {
        $connection = $this->activeConnection($business, ['connected_at' => now()->subDays(3)]);
        $location = $this->createLocation($business, true, overrides: ['name' => 'Chicago Booth Co Studio', 'city' => 'Chicago', 'latitude' => 41.8781, 'longitude' => -87.6298]);

        return BusinessGoogleLocation::create(array_merge([
            'business_google_connection_id' => $connection->id,
            'business_id' => $business->id,
            'business_location_id' => $location->id,
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => 'locations/L-INTERNAL-1',
            'verification_state' => GoogleLocationHealth::Verified,
            'profile_mirror' => $mirror,
            'mirror_fetched_at' => now()->subHours(10),
            'mirror_expires_at' => now()->addDays(20),
        ], $bindingOverrides));
    }

    protected function mirror(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Chicago Booth Co | Photo Booth Rental',
            'phone_primary' => '+1 312-555-0192',
            'website_uri' => 'https://www.chicago-booth-co.example',
            'primary_category_name' => 'Photo booth rental service',
            'additional_category_names' => ['Event planner'],
            'locality' => 'Chicago',
            'region_code' => 'US',
            'latitude' => 41.8781,
            'longitude' => -87.6298,
        ], $overrides);
    }

    protected function overview($workspace, $business)
    {
        return $this->get(route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid]));
    }

    public function test_connected_header_profile_card_and_differences_render_from_real_data(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $business->forceFill(['name' => 'Chicago Booth Co', 'phone' => '+1 312-555-0192', 'website_url' => null])->save();
        $binding = $this->bound($business, $this->mirror());
        $this->authenticateAsCustomer($customer);

        $response = $this->overview($workspace, $business)->assertOk();

        // Header + connection summary + settings link.
        $response->assertSee('Connected', false)->assertSee('read-only', false);
        $response->assertSee('owner@example.test', false);
        $response->assertSee('connected 3 days ago', false);
        $response->assertSee(route('customer.workspaces.businesses.gbp.settings', [$workspace->uid, $business->uid]), false);

        // Profile card: provider values, verification and freshness from real state.
        $response->assertSee('On Google', false);
        $response->assertSee('Chicago Booth Co | Photo Booth Rental', false);
        $response->assertSee('Chicago Booth Co Studio', false);
        $response->assertSee('Verified', false);
        $response->assertSee('Updated 10 hours ago', false);
        $response->assertSee('+1 312-555-0192', false);
        $response->assertSee('https://www.chicago-booth-co.example', false);
        $response->assertSee('Photo booth rental service', false);
        $response->assertSee('Chicago, US', false);

        // Details disclosure holds extra canonical fields, never internal ids.
        $response->assertSee('Show all details', false);
        $response->assertSee('Event planner', false);
        $response->assertDontSee('locations/L-INTERNAL-1', false);
        $response->assertDontSee('accounts/A1', false);

        // Differences: name differs, website not set here; phone matches.
        $response->assertSee('2 fields differ from Google', false);
        $response->assertSee('Stored here', false);
        $response->assertSee('Not set', false);
        $response->assertSee('View full comparison', false);
        $response->assertSee(route('customer.workspaces.businesses.gbp.comparison', [$workspace->uid, $business->uid, $binding->uid]), false);

        // Read-only: no write controls beyond refresh / link.
        $response->assertDontSee('Fix on Google', false);
        $response->assertDontSee('Sync to Google', false);
    }

    public function test_zero_differences_shows_the_positive_state_and_no_difference_card(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $business->forceFill([
            'name' => 'Chicago Booth Co | Photo Booth Rental',
            'phone' => '+1 312-555-0192',
            'website_url' => 'https://www.chicago-booth-co.example',
        ])->save();
        $this->bound($business, $this->mirror());
        $this->authenticateAsCustomer($customer);

        $response = $this->overview($workspace, $business)->assertOk();

        $response->assertSee('Everything matches Google', false);
        $response->assertDontSee('differ from Google', false);
        $response->assertDontSee('differs from Google', false);
    }

    public function test_an_expired_mirror_asks_for_a_refresh_instead_of_showing_stale_values(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->bound($business, $this->mirror(), ['mirror_expires_at' => now()->subDay()]);
        $this->authenticateAsCustomer($customer);

        $response = $this->overview($workspace, $business)->assertOk();

        $response->assertSee('Refresh required', false);
        $response->assertDontSee('Photo booth rental service', false);
        $response->assertDontSee('differ from Google', false);
    }

    public function test_the_disconnected_state_is_unchanged(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $response = $this->overview($workspace, $business)->assertOk();

        $response->assertSee('See how you look on Google', false);
        $response->assertSee('Connect Google', false);
        $response->assertDontSee('Connection settings', false);
        $response->assertDontSee('Show all details', false);
    }

    public function test_a_viewer_without_manage_permission_sees_no_manage_controls(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->bound($business, $this->mirror());
        // A NON-owner member holding only the view permission (the Workspace owner manages without it).
        $viewer = $this->createCustomer()->user;
        $this->addMember($workspace, $viewer, \App\Enums\Workspace\WorkspaceMembershipRole::Admin);
        $this->authenticateAsUser($viewer, ['view_google_business_profile']);

        $response = $this->overview($workspace, $business)->assertOk();

        $response->assertSee('Chicago Booth Co | Photo Booth Rental', false);
        $response->assertDontSee('from Google</button>', false);
        $response->assertDontSee('Link another location', false);
    }

    public function test_a_stale_listing_describes_the_actual_retention_configuration(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $binding = $this->bound($business, $this->mirror(), ['mirror_expires_at' => now()->subDay()]);
        $this->authenticateAsCustomer($customer);

        config(['google_business_profile.mirror.retention_days' => null]);
        $this->overview($workspace, $business)->assertOk()
            ->assertSee('does not keep your Google listing between visits', false)
            ->assertSee('View live from Google', false)
            ->assertDontSee('retention window', false);
        $this->get(route('customer.workspaces.businesses.gbp.comparison', [$workspace->uid, $business->uid, $binding->uid]))->assertOk()
            ->assertSee('not kept between visits', false)
            ->assertDontSee('30-day', false);

        config(['google_business_profile.mirror.retention_days' => 7]);
        $this->overview($workspace, $business)->assertOk()
            ->assertSee('7-day retention window', false)
            ->assertDontSee('30-day', false);
        $this->get(route('customer.workspaces.businesses.gbp.comparison', [$workspace->uid, $business->uid, $binding->uid]))->assertOk()
            ->assertSee('7-day retention window', false);
    }

    public function test_management_links_are_generic_and_name_the_linked_listing(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $business->forceFill(['name' => 'Chicago Booth Co', 'phone' => '+1 312-555-0192', 'website_url' => null])->save();
        $this->bound($business, $this->mirror(['maps_uri' => 'https://maps.google.com/?cid=12345', 'website_uri' => null]));
        $this->authenticateAsCustomer($customer);

        $html = $this->overview($workspace, $business)->assertOk()->getContent();

        // Honest labels: the generic manager, never "manage this listing".
        $this->assertStringContainsString('Open Google Business Profile Manager', $html);
        $this->assertStringNotContainsString('Manage photos on Google', $html);
        $this->assertStringNotContainsString('Manage reviews on Google', $html);
        $this->assertStringNotContainsString('>Open Google<', $html);

        // Customers are told which account and which listing to pick.
        $this->assertStringContainsString('Sign in as owner@example.test', $html);
        $this->assertStringContainsString('select “Chicago Booth Co | Photo Booth Rental”', $html);
        $this->assertStringContainsString('In the manager, select “Chicago Booth Co | Photo Booth Rental”', $html);

        // Every management link is exactly the generic manager URL: nothing derived from provider ids.
        preg_match_all('#href="(https://business\.google\.com[^"]*)"#', $html, $links);
        $this->assertNotEmpty($links[1]);
        foreach ($links[1] as $href) {
            $this->assertSame('https://business.google.com/', $href);
        }
        $this->assertStringNotContainsString('L-INTERNAL-1', implode(' ', $links[1]));

        // The public Maps view stays a separate, view-only link.
        $this->assertStringContainsString('href="https://maps.google.com/?cid=12345"', $html);
        $this->assertStringContainsString('Public Google Maps listing (view only)', $html);
    }

    public function test_the_guidance_names_the_platform_location_when_the_listing_title_is_not_retained(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->bound($business, $this->mirror(), ['mirror_expires_at' => now()->subDay(), 'bound_title_snapshot' => null]);
        $this->authenticateAsCustomer($customer);

        $html = $this->overview($workspace, $business)->assertOk()->getContent();

        $this->assertStringContainsString('select the Google listing linked to “Chicago Booth Co Studio”', $html);
        $this->assertStringNotContainsString('Manage photos on Google', $html);
    }
}
