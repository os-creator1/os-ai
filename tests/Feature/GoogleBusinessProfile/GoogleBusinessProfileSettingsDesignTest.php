<?php

namespace Tests\Feature\GoogleBusinessProfile;

use App\Enums\GoogleBusinessProfile\GoogleLocationHealth;
use App\Enums\GoogleBusinessProfile\GoogleOperationType;
use App\Enums\GoogleBusinessProfile\GoogleOperationStatus;
use App\Models\BusinessGoogleOperation;
use Illuminate\Support\Str;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\BusinessGoogleLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\GoogleBusinessProfile\Concerns\CreatesGoogleBusinessProfileFixtures;
use Tests\TestCase;

/**
 * The owner-supplied GbpSettings.dc.html is the settings composition contract.
 * Tests check structure and safe actions; browser comparison is still needed
 * for precise geometry, mobile layout and the actual app shell.
 */
class GoogleBusinessProfileSettingsDesignTest extends TestCase
{
    use RefreshDatabase;
    use CreatesGoogleBusinessProfileFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeGoogleClient();
    }

    public function test_connected_settings_show_compact_cards_activity_and_collapsed_disconnect(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $connection = $this->activeConnection($business, ['connected_at' => now()->subHour()]);
        $location = $this->createLocation($business, true);
        $binding = BusinessGoogleLocation::create([
            'business_google_connection_id' => $connection->id,
            'business_id' => $business->id,
            'business_location_id' => $location->id,
            'provider_account_resource_name' => 'accounts/A1',
            'provider_location_resource_name' => 'locations/L1',
            'bound_title_snapshot' => 'Test Booth Google Listing',
            'verification_state' => GoogleLocationHealth::Verified,
        ]);
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.gbp.settings', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee('data-role="gbp-settings"', false)
            ->assertSee('Google connection', false)
            ->assertSee('Your linked listing, access and recent activity.', false)
            ->assertSee('data-role="gbp-settings-connection"', false)
            ->assertSee('Connected to Google', false)
            ->assertSee('Refresh now', false)
            ->assertSee('Read-only access to your Business Profile', false)
            ->assertSee('data-role="gbp-settings-location"', false)
            ->assertSee('Test Booth Google Listing', false)
            ->assertSee('Verified', false)
            ->assertSee('View comparison', false)
            ->assertSee('Technical details', false)
            ->assertSee('Change listing', false)
            ->assertSee('data-role="gbp-change-listing"', false)
            ->assertSee('Unlink to choose another listing', false)
            ->assertDontSee('<a class="gbps-change" href=', false)
            ->assertSee('data-role="gbp-settings-activity"', false)
            ->assertSee('data-role="gbp-settings-disconnect"', false)
            ->assertSee('binding_uid" value="' . $binding->uid . '"', false)
            ->assertDontSee('table-responsive', false)
            ->assertDontSee('plain-refresh-token-value', false);
    }

    public function test_a_pending_refresh_is_not_presented_as_completed(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->activeConnection($business);
        BusinessGoogleOperation::create([
            'uid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'operation_type' => GoogleOperationType::MirrorRefreshed,
            'local_operation_key' => 'settings-pending-' . Str::uuid(),
            'status' => GoogleOperationStatus::Pending,
            'summary' => 'Google listing refresh requested',
            'started_at' => now(),
        ]);
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.gbp.settings', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee('Refreshing listing details', false)
            ->assertSee('Pending — not completed yet', false)
            ->assertSee('In progress', false)
            ->assertDontSee('Listing details refreshed', false);
    }

    public function test_disconnected_page_keeps_compact_layout_and_no_destructive_action(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->get(route('customer.workspaces.businesses.gbp.settings', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee('Google connection', false)
            ->assertSee('Not connected to Google', false)
            ->assertSee('Connect Google', false)
            ->assertDontSee('data-role="gbp-settings-disconnect"', false);
    }

    public function test_read_only_member_can_view_settings_but_not_unlink_or_disconnect(): void
    {
        [, $business, $workspace] = $this->entitledTenant();
        $this->activeConnection($business);
        $viewer = $this->createCustomer()->user;
        $this->addMember($workspace, $viewer, WorkspaceMembershipRole::Admin);
        $this->authenticateAsUser($viewer, ['view_google_business_profile']);

        $this->get(route('customer.workspaces.businesses.gbp.settings', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->assertSee('Connected to Google', false)
            ->assertDontSee('Disconnect</button>', false)
            ->assertDontSee('Unlink this location</button>', false)
            ->assertDontSee('Refresh now', false);
    }
}
