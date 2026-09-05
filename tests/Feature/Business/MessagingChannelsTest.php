<?php

namespace Tests\Feature\Business;

use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerBasedSendingServer;
use App\Models\SendingServer;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * B2 — Business Messaging Channels: a small, simple Business-level
 * connect/manage experience for exactly two launch providers (Twilio,
 * Telnyx), built entirely on the existing, unmodified SendingServer +
 * CustomerBasedSendingServer backend.
 *
 * Covers: Business access boundary (owner/staff/unauthorized/wrong-
 * workspace), the server-side provider allowlist, explicit-Business write
 * identity (never the acting staff member), cross-Business isolation,
 * credential redaction/blank-preserves-current-value, assignment enable/
 * disable scoping (never the shared/global server), and B1 Outreach
 * integration (no parallel provider registry).
 */
class MessagingChannelsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        User::create([
            'first_name' => 'Placeholder',
            'last_name' => 'SuperAdmin',
            'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);

        $this->ensureRequiredAppConfigRowsExist();
    }

    // -----------------------------------------------------------------
    // Access — owner, authorized staff, unauthorized, wrong Workspace.
    // -----------------------------------------------------------------

    public function test_business_owner_can_view_the_channels_page(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $this->get(route('customer.workspaces.businesses.channels.index', [$business->workspace->uid, $business->uid]))
            ->assertOk();
    }

    public function test_authorized_staff_can_view_the_channels_page_even_when_actor_id_differs_from_owner(): void
    {
        [$ownerCustomer, $business] = $this->tenantWithBusiness();
        $staffCustomer = $this->createCustomer();
        $this->makeStaff($business, $staffCustomer, 'all');

        $this->assertNotSame($staffCustomer->user_id, $business->customer_id);

        $this->authenticateAsCustomer($staffCustomer, ['view_numbers']);

        $this->get(route('customer.workspaces.businesses.channels.index', [$business->workspace->uid, $business->uid]))
            ->assertOk();
    }

    public function test_unauthorized_customer_is_denied_access_to_a_businesss_channels_page(): void
    {
        [, $businessA] = $this->tenantWithBusiness();
        [$tenantB] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenantB, ['view_numbers']);

        $this->get(route('customer.workspaces.businesses.channels.index', [$businessA->workspace->uid, $businessA->uid]))
            ->assertStatus(404);
    }

    public function test_wrong_workspace_business_pair_is_denied(): void
    {
        [$tenantA, $businessA] = $this->tenantWithBusiness();
        [, $businessB] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenantA, ['view_numbers']);

        $this->get(route('customer.workspaces.businesses.channels.index', [$businessA->workspace->uid, $businessB->uid]))
            ->assertStatus(404);
    }

    public function test_unknown_workspace_and_business_404(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $this->get(route('customer.workspaces.businesses.channels.index', ['no-such-workspace', $business->uid]))
            ->assertStatus(404);
        $this->get(route('customer.workspaces.businesses.channels.index', [$business->workspace->uid, 'no-such-business']))
            ->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // Entry/selector route.
    // -----------------------------------------------------------------

    public function test_entry_route_redirects_when_exactly_one_business_is_accessible(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $this->get(route('customer.channels.index'))
            ->assertRedirect(route('customer.workspaces.businesses.channels.index', [$business->workspace->uid, $business->uid]));
    }

    public function test_entry_route_shows_a_chooser_for_multiple_businesses(): void
    {
        $tenant = $this->createCustomer();
        $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business One']));
        $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business Two']));
        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $response = $this->get(route('customer.channels.index'));

        $response->assertOk();
        $response->assertSee('Business One');
        $response->assertSee('Business Two');
    }

    public function test_entry_route_shows_empty_state_for_zero_businesses(): void
    {
        $tenant = $this->createCustomer();
        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $this->get(route('customer.channels.index'))->assertOk();
    }

    // -----------------------------------------------------------------
    // Provider allowlist.
    // -----------------------------------------------------------------

    public function test_twilio_connect_form_is_reachable(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $this->get(route('customer.workspaces.businesses.channels.connect', [$business->workspace->uid, $business->uid, SendingServer::TYPE_TWILIO]))
            ->assertOk();
    }

    public function test_telnyx_connect_form_is_reachable(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $this->get(route('customer.workspaces.businesses.channels.connect', [$business->workspace->uid, $business->uid, SendingServer::TYPE_TELNYX]))
            ->assertOk();
    }

    public function test_an_arbitrary_inherited_provider_type_is_rejected(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $response = $this->post(route('customer.workspaces.businesses.channels.connect', [$business->workspace->uid, $business->uid, SendingServer::TYPE_PLIVO]), [
            'account_sid' => 'AC123',
        ]);

        $response->assertSessionHas('status', 'error');
        $this->assertDatabaseMissing('sending_servers', ['settings' => SendingServer::TYPE_PLIVO]);
    }

    // -----------------------------------------------------------------
    // Correction 1 — a Business-owned connection for an inherited
    // provider OUTSIDE B2's allowlist must never be reachable through B2,
    // even though it is genuinely owned by the acting Business (unlike
    // the cross-Business isolation tests above, which cover a DIFFERENT
    // Business's connection). Index, show, update, enable, and disable
    // must all treat it as if it doesn't exist.
    // -----------------------------------------------------------------

    public function test_an_owned_connection_for_a_non_allowlisted_provider_is_absent_from_the_index(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $plivoConnection = $this->createDedicatedConnection($business, SendingServer::TYPE_PLIVO);

        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $response = $this->get(route('customer.workspaces.businesses.channels.index', [$business->workspace->uid, $business->uid]));

        $response->assertOk();
        $response->assertDontSee($plivoConnection->uid);
    }

    public function test_an_owned_connection_for_a_non_allowlisted_provider_cannot_be_viewed_directly(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $plivoConnection = $this->createDedicatedConnection($business, SendingServer::TYPE_PLIVO);

        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $this->get(route('customer.workspaces.businesses.channels.connections.show', [$business->workspace->uid, $business->uid, $plivoConnection->uid]))
            ->assertStatus(404);
    }

    public function test_an_owned_connection_for_a_non_allowlisted_provider_cannot_be_updated(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $plivoConnection = $this->createDedicatedConnection($business, SendingServer::TYPE_PLIVO);
        $originalServer = SendingServer::find($plivoConnection->sending_server);

        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $this->put(route('customer.workspaces.businesses.channels.connections.update', [$business->workspace->uid, $business->uid, $plivoConnection->uid]), [
            'account_sid' => 'HACKED_VIA_B2',
        ])->assertStatus(404);

        $this->assertEquals($originalServer->toArray(), SendingServer::find($plivoConnection->sending_server)->fresh()->toArray());
    }

    public function test_an_owned_connection_for_a_non_allowlisted_provider_cannot_be_disabled(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $plivoConnection = $this->createDedicatedConnection($business, SendingServer::TYPE_PLIVO);

        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $this->post(route('customer.workspaces.businesses.channels.connections.disable', [$business->workspace->uid, $business->uid, $plivoConnection->uid]))
            ->assertStatus(404);

        $this->assertTrue($plivoConnection->fresh()->status);
    }

    public function test_an_owned_connection_for_a_non_allowlisted_provider_cannot_be_enabled(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $plivoConnection = $this->createDedicatedConnection($business, SendingServer::TYPE_PLIVO);
        $plivoConnection->update(['status' => false]);

        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $this->post(route('customer.workspaces.businesses.channels.connections.enable', [$business->workspace->uid, $business->uid, $plivoConnection->uid]))
            ->assertStatus(404);

        $this->assertFalse($plivoConnection->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Write identity — staff connecting a provider for Business B must
    // never write their own id as the tenant owner.
    // -----------------------------------------------------------------

    public function test_staff_connecting_twilio_writes_the_business_owners_identity_never_the_staff_actor(): void
    {
        [$ownerCustomer, $business] = $this->tenantWithBusiness();
        $staffCustomer = $this->createCustomer();
        $this->makeStaff($business, $staffCustomer, 'all');

        $this->authenticateAsCustomer($staffCustomer, ['view_numbers']);

        $response = $this->post(route('customer.workspaces.businesses.channels.connect', [$business->workspace->uid, $business->uid, SendingServer::TYPE_TWILIO]), [
            'account_sid' => 'AC_TEST_SID',
            'auth_token' => 'test_auth_token',
        ]);

        $response->assertSessionHas('status', 'success');

        $assignment = CustomerBasedSendingServer::where('business_id', $business->id)->first();
        $this->assertNotNull($assignment);
        $this->assertSame($business->id, $assignment->business_id);
        $this->assertSame($ownerCustomer->user_id, $assignment->user_id);
        $this->assertNotSame($staffCustomer->user_id, $assignment->user_id);

        $sendingServer = SendingServer::find($assignment->sending_server);
        $this->assertSame($ownerCustomer->user_id, $sendingServer->user_id);
        $this->assertNotSame($staffCustomer->user_id, $sendingServer->user_id);
        $this->assertSame(SendingServer::TYPE_TWILIO, $sendingServer->settings);
        $this->assertSame('AC_TEST_SID', $sendingServer->account_sid);
        $this->assertTrue((bool) $sendingServer->plain);
        $this->assertTrue((bool) $sendingServer->mms);
    }

    public function test_connecting_telnyx_stores_the_exact_reused_credential_fields(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $this->post(route('customer.workspaces.businesses.channels.connect', [$business->workspace->uid, $business->uid, SendingServer::TYPE_TELNYX]), [
            'api_key' => 'telnyx_key_123',
            'c1' => 'profile_abc',
            'c2' => 'connection_xyz',
        ])->assertSessionHas('status', 'success');

        $assignment = CustomerBasedSendingServer::where('business_id', $business->id)->first();
        $sendingServer = SendingServer::find($assignment->sending_server);

        $this->assertSame('telnyx_key_123', $sendingServer->api_key);
        $this->assertSame('profile_abc', $sendingServer->c1);
        $this->assertSame('connection_xyz', $sendingServer->c2);
    }

    public function test_connecting_a_provider_requires_its_required_credential_fields(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $response = $this->post(route('customer.workspaces.businesses.channels.connect', [$business->workspace->uid, $business->uid, SendingServer::TYPE_TWILIO]), [
            'account_sid' => '',
            'auth_token' => '',
        ]);

        $response->assertSessionHasErrors(['account_sid', 'auth_token']);
        $this->assertDatabaseMissing('sending_servers', ['settings' => SendingServer::TYPE_TWILIO]);
    }

    // -----------------------------------------------------------------
    // Isolation — Business A cannot see/view/edit/enable/disable
    // Business B's connection, even sharing the same legacy owner.
    // -----------------------------------------------------------------

    public function test_business_a_does_not_see_business_bs_connection_on_the_channels_page(): void
    {
        $tenant = $this->createCustomer();
        $businessA = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business A']));
        $businessB = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business B']));
        $connectionB = $this->createDedicatedConnection($businessB, SendingServer::TYPE_TWILIO);

        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $response = $this->get(route('customer.workspaces.businesses.channels.index', [$businessA->workspace->uid, $businessA->uid]));

        $response->assertOk();
        $response->assertDontSee($connectionB->uid);
    }

    public function test_business_a_cannot_view_business_bs_connection_directly(): void
    {
        $tenant = $this->createCustomer();
        $businessA = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business A']));
        $businessB = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business B']));
        $connectionB = $this->createDedicatedConnection($businessB, SendingServer::TYPE_TWILIO);

        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $this->get(route('customer.workspaces.businesses.channels.connections.show', [$businessA->workspace->uid, $businessA->uid, $connectionB->uid]))
            ->assertStatus(404);
    }

    public function test_business_a_cannot_edit_business_bs_credentials(): void
    {
        $tenant = $this->createCustomer();
        $businessA = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business A']));
        $businessB = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business B']));
        $connectionB = $this->createDedicatedConnection($businessB, SendingServer::TYPE_TWILIO);
        $originalToken = SendingServer::find($connectionB->sending_server)->auth_token;

        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $this->put(route('customer.workspaces.businesses.channels.connections.update', [$businessA->workspace->uid, $businessA->uid, $connectionB->uid]), [
            'auth_token' => 'HACKED_TOKEN',
        ])->assertStatus(404);

        $this->assertSame($originalToken, SendingServer::find($connectionB->sending_server)->fresh()->auth_token);
    }

    public function test_business_a_cannot_disable_business_bs_connection(): void
    {
        $tenant = $this->createCustomer();
        $businessA = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business A']));
        $businessB = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business B']));
        $connectionB = $this->createDedicatedConnection($businessB, SendingServer::TYPE_TWILIO);

        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $this->post(route('customer.workspaces.businesses.channels.connections.disable', [$businessA->workspace->uid, $businessA->uid, $connectionB->uid]))
            ->assertStatus(404);

        $this->assertTrue($connectionB->fresh()->status);
    }

    public function test_business_a_cannot_enable_business_bs_connection(): void
    {
        $tenant = $this->createCustomer();
        $businessA = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business A']));
        $businessB = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business B']));
        $connectionB = $this->createDedicatedConnection($businessB, SendingServer::TYPE_TWILIO);
        $connectionB->update(['status' => false]);

        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $this->post(route('customer.workspaces.businesses.channels.connections.enable', [$businessA->workspace->uid, $businessA->uid, $connectionB->uid]))
            ->assertStatus(404);

        $this->assertFalse($connectionB->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Credentials — never rendered back, blank preserves current value.
    // -----------------------------------------------------------------

    public function test_saved_credential_is_never_rendered_back_on_the_manage_page(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $connection = $this->createDedicatedConnection($business, SendingServer::TYPE_TWILIO, ['auth_token' => 'super-secret-token-value']);

        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $response = $this->get(route('customer.workspaces.businesses.channels.connections.show', [$business->workspace->uid, $business->uid, $connection->uid]));

        $response->assertOk();
        $response->assertDontSee('super-secret-token-value');
    }

    public function test_edit_form_never_pre_fills_the_secret_value(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $connection = $this->createDedicatedConnection($business, SendingServer::TYPE_TWILIO, ['account_sid' => 'AC_KNOWN_VALUE']);

        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $response = $this->get(route('customer.workspaces.businesses.channels.connections.show', [$business->workspace->uid, $business->uid, $connection->uid]));

        $response->assertOk();
        $response->assertDontSee('value="AC_KNOWN_VALUE"', false);
    }

    public function test_blank_credential_update_preserves_the_current_secret(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $connection = $this->createDedicatedConnection($business, SendingServer::TYPE_TWILIO, ['account_sid' => 'AC_ORIGINAL', 'auth_token' => 'token_original']);

        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $this->put(route('customer.workspaces.businesses.channels.connections.update', [$business->workspace->uid, $business->uid, $connection->uid]), [
            'account_sid' => '',
            'auth_token' => '',
        ])->assertSessionHas('status', 'success');

        $sendingServer = SendingServer::find($connection->sending_server)->fresh();
        $this->assertSame('AC_ORIGINAL', $sendingServer->account_sid);
        $this->assertSame('token_original', $sendingServer->auth_token);
    }

    public function test_non_blank_credential_update_replaces_only_the_submitted_fields(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $connection = $this->createDedicatedConnection($business, SendingServer::TYPE_TWILIO, ['account_sid' => 'AC_ORIGINAL', 'auth_token' => 'token_original']);

        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $this->put(route('customer.workspaces.businesses.channels.connections.update', [$business->workspace->uid, $business->uid, $connection->uid]), [
            'account_sid' => '',
            'auth_token' => 'token_replaced',
        ])->assertSessionHas('status', 'success');

        $sendingServer = SendingServer::find($connection->sending_server)->fresh();
        $this->assertSame('AC_ORIGINAL', $sendingServer->account_sid);
        $this->assertSame('token_replaced', $sendingServer->auth_token);
    }

    public function test_validation_error_response_does_not_leak_the_submitted_secret(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $response = $this->post(route('customer.workspaces.businesses.channels.connect', [$business->workspace->uid, $business->uid, SendingServer::TYPE_TELNYX]), [
            'api_key' => '',
            'c1' => '',
        ]);

        $response->assertDontSee('super-secret-value-that-should-never-appear');
        $this->assertDatabaseMissing('sending_servers', ['settings' => SendingServer::TYPE_TELNYX]);
    }

    // -----------------------------------------------------------------
    // Managed/shared connections — read-only, never editable by a
    // Business that doesn't exclusively own the underlying server.
    // -----------------------------------------------------------------

    public function test_a_connection_sharing_its_sending_server_with_another_assignment_is_read_only(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $sharedServer = SendingServer::create(['name' => 'Shared', 'settings' => SendingServer::TYPE_TWILIO, 'status' => true, 'plain' => true, 'mms' => true, 'user_id' => $tenant->user_id]);
        $connection = CustomerBasedSendingServer::create(['user_id' => $tenant->user_id, 'business_id' => $business->id, 'sending_server' => $sharedServer->id, 'status' => true]);
        // A second assignment referencing the SAME underlying server makes it shared.
        [$otherTenant, $otherBusiness] = $this->tenantWithBusiness();
        CustomerBasedSendingServer::create(['user_id' => $otherTenant->user_id, 'business_id' => $otherBusiness->id, 'sending_server' => $sharedServer->id, 'status' => true]);

        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $response = $this->get(route('customer.workspaces.businesses.channels.connections.show', [$business->workspace->uid, $business->uid, $connection->uid]));
        $response->assertOk();
        $response->assertSee('Managed connection');

        $this->put(route('customer.workspaces.businesses.channels.connections.update', [$business->workspace->uid, $business->uid, $connection->uid]), [
            'auth_token' => 'attempted-edit',
        ])->assertSessionHas('status', 'error');

        $this->assertNull($sharedServer->fresh()->auth_token);
    }

    // -----------------------------------------------------------------
    // Enable/disable scoping — never mutates the underlying (possibly
    // shared) SendingServer or another Business's assignment.
    // -----------------------------------------------------------------

    public function test_disabling_business_bs_assignment_does_not_disable_business_as_or_the_global_server(): void
    {
        [$tenant, $businessA] = $this->tenantWithBusiness();
        $businessB = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business B']));

        $server = SendingServer::create(['name' => 'Shared', 'settings' => SendingServer::TYPE_TWILIO, 'status' => true, 'plain' => true, 'mms' => true, 'user_id' => $tenant->user_id]);
        $connectionA = CustomerBasedSendingServer::create(['user_id' => $tenant->user_id, 'business_id' => $businessA->id, 'sending_server' => $server->id, 'status' => true]);
        $connectionB = CustomerBasedSendingServer::create(['user_id' => $tenant->user_id, 'business_id' => $businessB->id, 'sending_server' => $server->id, 'status' => true]);

        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $this->post(route('customer.workspaces.businesses.channels.connections.disable', [$businessB->workspace->uid, $businessB->uid, $connectionB->uid]))
            ->assertSessionHas('status', 'success');

        $this->assertFalse($connectionB->fresh()->status);
        $this->assertTrue($connectionA->fresh()->status, 'Business A\'s own assignment must be unaffected.');
        $this->assertTrue($server->fresh()->status, 'The shared underlying SendingServer must never be globally disabled by one Business.');
    }

    public function test_enabling_only_affects_the_current_businesss_assignment(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $connection = $this->createDedicatedConnection($business, SendingServer::TYPE_TWILIO);
        $connection->update(['status' => false]);

        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $this->post(route('customer.workspaces.businesses.channels.connections.enable', [$business->workspace->uid, $business->uid, $connection->uid]))
            ->assertSessionHas('status', 'success');

        $this->assertTrue($connection->fresh()->status);
    }

    public function test_admin_disabled_global_server_cannot_be_re_enabled_by_the_business(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $connection = $this->createDedicatedConnection($business, SendingServer::TYPE_TWILIO);
        SendingServer::find($connection->sending_server)->update(['status' => false]);
        $connection->update(['status' => false]);

        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $response = $this->post(route('customer.workspaces.businesses.channels.connections.enable', [$business->workspace->uid, $business->uid, $connection->uid]));

        $response->assertSessionHas('status', 'error');
        $this->assertFalse($connection->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Correction 1 — server-side view_numbers enforcement. Business
    // access alone is not enough: a user with Business access but
    // WITHOUT view_numbers must be denied on every one of the 8 B2
    // actions, with zero state mutation on the denied writes. Hiding
    // the nav item is not authorization.
    // -----------------------------------------------------------------

    public function test_entry_route_denies_a_business_accessible_user_without_view_numbers(): void
    {
        [$tenant] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant);

        $this->get(route('customer.channels.index'))->assertStatus(401);
    }

    public function test_channels_index_denies_a_business_accessible_user_without_view_numbers(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant);

        $this->get(route('customer.workspaces.businesses.channels.index', [$business->workspace->uid, $business->uid]))
            ->assertStatus(401);
    }

    public function test_connect_form_denies_a_business_accessible_user_without_view_numbers(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant);

        $this->get(route('customer.workspaces.businesses.channels.connect', [$business->workspace->uid, $business->uid, SendingServer::TYPE_TWILIO]))
            ->assertStatus(401);
    }

    public function test_store_connect_denies_a_business_accessible_user_without_view_numbers_and_creates_nothing(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant);

        $this->post(route('customer.workspaces.businesses.channels.connect', [$business->workspace->uid, $business->uid, SendingServer::TYPE_TWILIO]), [
            'account_sid' => 'AC_SHOULD_NOT_BE_SAVED',
            'auth_token' => 'token_should_not_be_saved',
        ])->assertStatus(401);

        $this->assertDatabaseMissing('sending_servers', ['settings' => SendingServer::TYPE_TWILIO]);
        $this->assertDatabaseMissing('customer_based_sending_servers', ['business_id' => $business->id]);
    }

    public function test_show_denies_a_business_accessible_user_without_view_numbers(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $connection = $this->createDedicatedConnection($business, SendingServer::TYPE_TWILIO);
        $this->authenticateAsCustomer($tenant);

        $this->get(route('customer.workspaces.businesses.channels.connections.show', [$business->workspace->uid, $business->uid, $connection->uid]))
            ->assertStatus(401);
    }

    public function test_update_denies_a_business_accessible_user_without_view_numbers_and_leaves_credentials_unchanged(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $connection = $this->createDedicatedConnection($business, SendingServer::TYPE_TWILIO, ['auth_token' => 'token_original']);
        $this->authenticateAsCustomer($tenant);

        $this->put(route('customer.workspaces.businesses.channels.connections.update', [$business->workspace->uid, $business->uid, $connection->uid]), [
            'auth_token' => 'token_should_not_apply',
        ])->assertStatus(401);

        $this->assertSame('token_original', SendingServer::find($connection->sending_server)->fresh()->auth_token);
    }

    public function test_enable_denies_a_business_accessible_user_without_view_numbers_and_leaves_status_unchanged(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $connection = $this->createDedicatedConnection($business, SendingServer::TYPE_TWILIO);
        $connection->update(['status' => false]);
        $this->authenticateAsCustomer($tenant);

        $this->post(route('customer.workspaces.businesses.channels.connections.enable', [$business->workspace->uid, $business->uid, $connection->uid]))
            ->assertStatus(401);

        $this->assertFalse($connection->fresh()->status);
    }

    public function test_disable_denies_a_business_accessible_user_without_view_numbers_and_leaves_status_unchanged(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $connection = $this->createDedicatedConnection($business, SendingServer::TYPE_TWILIO);
        $this->authenticateAsCustomer($tenant);

        $this->post(route('customer.workspaces.businesses.channels.connections.disable', [$business->workspace->uid, $business->uid, $connection->uid]))
            ->assertStatus(401);

        $this->assertTrue($connection->fresh()->status);
    }

    public function test_business_accessible_user_with_view_numbers_can_still_use_channels_normally(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant, ['view_numbers']);

        $this->get(route('customer.workspaces.businesses.channels.index', [$business->workspace->uid, $business->uid]))
            ->assertOk();
    }

    // -----------------------------------------------------------------
    // B1 integration — no parallel provider registry; Outreach sees
    // exactly the Business-scoped, enabled CustomerBasedSendingServer
    // rows B2 creates.
    // -----------------------------------------------------------------

    public function test_active_business_b_connection_is_visible_to_business_b_outreach(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->createDedicatedConnection($business, SendingServer::TYPE_TWILIO);

        $this->assertTrue(
            CustomerBasedSendingServer::where('business_id', $business->id)->where('status', 1)->exists(),
            'B1 Outreach\'s sendingServersExist check (CustomerBasedSendingServer scoped by business_id) must see the new connection.'
        );
    }

    public function test_business_a_outreach_does_not_see_business_bs_connection(): void
    {
        $tenant = $this->createCustomer();
        $businessA = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business A']));
        $businessB = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business B']));
        $this->createDedicatedConnection($businessB, SendingServer::TYPE_TWILIO);

        $this->assertFalse(
            CustomerBasedSendingServer::where('business_id', $businessA->id)->where('status', 1)->exists(),
            'Business A must not see Business B\'s connection through the same business_id-scoped query B1 Outreach uses.'
        );
    }

    public function test_disabled_business_b_connection_is_absent_from_business_b_outreach(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $connection = $this->createDedicatedConnection($business, SendingServer::TYPE_TWILIO);
        $connection->update(['status' => false]);

        $this->assertFalse(
            CustomerBasedSendingServer::where('business_id', $business->id)->where('status', 1)->exists(),
            'A disabled assignment must not be offered by B1 Outreach\'s sendingServersExist check.'
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return array{0: Customer, 1: Business}
     */
    private function tenantWithBusiness(): array
    {
        $this->ensureRequiredAppConfigRowsExist();
        $tenant = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes());

        return [$tenant, $business];
    }

    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])
            ->pluck('setting')
            ->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())
                ->firstWhere('setting', 'customer_permissions');

            AppConfig::create($default);
        }
    }

    private function authenticateAsCustomer(Customer $customer, array $permissions = []): void
    {
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(array_merge(['access_backend'], $permissions))]);
        $this->actingAs($customer->user);
    }

    private function makeStaff(Business $business, Customer $staffCustomer, string $scope): WorkspaceMembership
    {
        return WorkspaceMembership::create([
            'workspace_id' => $business->workspace_id,
            'user_id' => $staffCustomer->user_id,
            'role' => 'staff',
            'business_access_scope' => $scope,
            'is_active' => true,
        ]);
    }

    private function createDedicatedConnection(Business $business, string $provider, array $credentialOverrides = []): CustomerBasedSendingServer
    {
        $defaults = match ($provider) {
            SendingServer::TYPE_TWILIO => ['account_sid' => 'AC_DEFAULT', 'auth_token' => 'default_token'],
            SendingServer::TYPE_TELNYX => ['api_key' => 'default_key', 'c1' => 'default_profile', 'c2' => 'default_connection'],
            default => [],
        };

        $sendingServer = SendingServer::create(array_merge([
            'name' => $provider,
            'settings' => $provider,
            'status' => true,
            'plain' => true,
            'mms' => true,
            'user_id' => $business->customer_id,
        ], $defaults, $credentialOverrides));

        return CustomerBasedSendingServer::create([
            'user_id' => $business->customer_id,
            'business_id' => $business->id,
            'sending_server' => $sendingServer->id,
            'status' => true,
        ]);
    }
}
