<?php

namespace Tests\Feature\Business;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerBasedSendingServer;
use App\Models\SendingServer;
use App\Models\User;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Customer Messaging Setup UX Cleanup — the setup-form-specific behavior
 * added on top of MessagingChannelsController: the connect form's MMS
 * checkbox (both real B2 providers, Twilio and Telnyx, support MMS today —
 * BusinessMessagingProviderCatalogTest covers the "provider without MMS
 * support" branch directly since no real allowlisted provider exercises
 * it), the create-flow's redirect landing on the new connection's own
 * detail page rather than the generic list, and that none of this weakens
 * Business isolation.
 *
 * Deliberately a separate file from MessagingChannelsTest — that file pins
 * the pre-existing access/validation/isolation/redaction contract; this one
 * is scoped to what this cleanup actually changed.
 */
class MessagingChannelsMmsAndRedirectTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private int $platformAdminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId = User::create([
            'first_name' => 'Placeholder',
            'last_name' => 'SuperAdmin',
            'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ])->id;

        $this->ensureRequiredAppConfigRowsExist();
    }

    // -----------------------------------------------------------------
    // Conditional MMS UI on the connect (setup) form.
    // -----------------------------------------------------------------

    public function test_the_connect_form_offers_an_mms_toggle_for_an_mms_capable_provider(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant, ['view_numbers', 'manage_advanced_provider']);

        $response = $this->get(route('customer.workspaces.businesses.channels.connect', [$business->workspace->uid, $business->uid, SendingServer::TYPE_TWILIO]));

        $response->assertOk();
        $response->assertSee('name="enable_mms"', false);
        $response->assertSee('Enable MMS', false);
    }

    // -----------------------------------------------------------------
    // Validation / provider capability — the actual mms flag stored.
    // -----------------------------------------------------------------

    public function test_connecting_without_unchecking_mms_stores_mms_enabled_by_default(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant, ['view_numbers', 'manage_advanced_provider']);

        // Mirrors a real browser submit where a checked, defaulted checkbox
        // is simply absent from a raw test POST — must still behave as "on".
        $this->post(route('customer.workspaces.businesses.channels.connect', [$business->workspace->uid, $business->uid, SendingServer::TYPE_TWILIO]), [
            'account_sid' => 'AC_TEST',
            'auth_token' => 'token_test',
        ])->assertSessionHas('status', 'success');

        $assignment = CustomerBasedSendingServer::where('business_id', $business->id)->first();
        $this->assertTrue(SendingServer::find($assignment->sending_server)->mms);
    }

    public function test_unchecking_mms_stores_the_connection_as_mms_disabled(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant, ['view_numbers', 'manage_advanced_provider']);

        $this->post(route('customer.workspaces.businesses.channels.connect', [$business->workspace->uid, $business->uid, SendingServer::TYPE_TWILIO]), [
            'account_sid' => 'AC_TEST',
            'auth_token' => 'token_test',
            'enable_mms' => '0',
        ])->assertSessionHas('status', 'success');

        $assignment = CustomerBasedSendingServer::where('business_id', $business->id)->first();
        $this->assertFalse(SendingServer::find($assignment->sending_server)->mms);
    }

    // -----------------------------------------------------------------
    // Create-flow redirect — lands on the new connection's detail page.
    // -----------------------------------------------------------------

    public function test_a_successful_connect_redirects_to_the_new_connections_own_detail_page(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant, ['view_numbers', 'manage_advanced_provider']);

        $response = $this->post(route('customer.workspaces.businesses.channels.connect', [$business->workspace->uid, $business->uid, SendingServer::TYPE_TWILIO]), [
            'account_sid' => 'AC_TEST',
            'auth_token' => 'token_test',
        ]);

        $assignment = CustomerBasedSendingServer::where('business_id', $business->id)->first();

        $response->assertRedirect(route('customer.workspaces.businesses.channels.connections.show', [$business->workspace->uid, $business->uid, $assignment->uid]));
    }

    public function test_following_the_create_redirect_shows_the_new_connection_not_the_generic_list(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant, ['view_numbers', 'manage_advanced_provider']);

        $response = $this->post(route('customer.workspaces.businesses.channels.connect', [$business->workspace->uid, $business->uid, SendingServer::TYPE_TWILIO]), [
            'account_sid' => 'AC_TEST',
            'auth_token' => 'token_test',
        ]);

        $followed = $this->get($response->headers->get('Location'));

        $followed->assertOk();
        $followed->assertSee('Twilio');
        $followed->assertSee('Provider credentials (Advanced)');
        $followed->assertDontSee('Not connected yet.');
    }

    // -----------------------------------------------------------------
    // Business isolation — unaffected by the redirect/UX changes.
    // -----------------------------------------------------------------

    public function test_business_a_cannot_be_redirected_into_business_bs_new_connection(): void
    {
        $tenant = $this->createCustomer();
        $businessA = $this->makeAgencyReady($this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business A'])));
        $businessB = $this->makeAgencyReady($this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business B'])));

        $this->authenticateAsCustomer($tenant, ['view_numbers', 'manage_advanced_provider']);

        $this->post(route('customer.workspaces.businesses.channels.connect', [$businessB->workspace->uid, $businessB->uid, SendingServer::TYPE_TWILIO]), [
            'account_sid' => 'AC_TEST',
            'auth_token' => 'token_test',
        ])->assertSessionHas('status', 'success');

        $connectionB = CustomerBasedSendingServer::where('business_id', $businessB->id)->first();

        // Business A's own workspace/business pair paired with Business B's
        // freshly created connection uid must still 404 — the new redirect
        // target does not create a new way to reach another Business's
        // connection.
        $this->get(route('customer.workspaces.businesses.channels.connections.show', [$businessA->workspace->uid, $businessA->uid, $connectionB->uid]))
            ->assertStatus(404);

        $this->assertDatabaseMissing('customer_based_sending_servers', ['business_id' => $businessA->id]);
    }

    // -----------------------------------------------------------------
    // Helpers (mirrors MessagingChannelsTest's own fixtures).
    // -----------------------------------------------------------------

    private function makeAgencyReady(Business $business): Business
    {
        app(EntitlementManager::class)->assignFirstPlan(
            $business->workspace,
            WorkspacePlanTier::Agency,
            $this->platformAdminId,
            'MessagingChannelsMmsAndRedirectTest fixture assignment.',
            true,
            0,
        );

        app(BusinessRepository::class)->updateStatus($business, BusinessStatus::Active);

        return $business->fresh();
    }

    /**
     * @return array{0: Customer, 1: Business}
     */
    private function tenantWithBusiness(): array
    {
        $this->ensureRequiredAppConfigRowsExist();
        $tenant = $this->createCustomer();
        $business = $this->makeAgencyReady($this->createBusinessWithWorkspace($tenant, $this->businessAttributes()));

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
}
