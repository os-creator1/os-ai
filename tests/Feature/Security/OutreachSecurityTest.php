<?php

namespace Tests\Feature\Security;

use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Campaigns;
use App\Models\Customer;
use App\Models\Senderid;
use App\Models\Templates;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * B1 Pass 2 — Outreach / Compose, Business-addressable: authorization and
 * tenant-isolation coverage. Every action now requires BOTH actor-may-
 * access-selected-Business (WorkspaceManager::userCanAccessBusiness()) AND
 * the record's business_id equals the selected Business — never
 * campaign.user_id === Auth::id() and never business.customer_id ===
 * Auth::id().
 */
class OutreachSecurityTest extends TestCase
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
    }

    // -----------------------------------------------------------------
    // Outreach page authorization (unaffected by the Business cutover)
    // -----------------------------------------------------------------

    public function test_outreach_index_denies_actor_with_no_sms_or_mms_permission(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant, []);

        $response = $this->get(route('customer.workspaces.businesses.outreach.index', [$business->workspace->uid, $business->uid]));

        $response->assertStatus(401);
    }

    public function test_sms_only_actor_can_open_outreach_and_only_sees_sms(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant, ['sms_quick_send']);
        $this->giveActiveSubscription($tenant);

        $response = $this->get(route('customer.workspaces.businesses.outreach.index', [$business->workspace->uid, $business->uid]));

        $response->assertOk();
        $response->assertSee('id="outreach-channel-sms"', false);
        $response->assertDontSee('id="outreach-channel-mms"', false);
    }

    public function test_mms_only_actor_can_open_outreach_and_only_sees_mms(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant, ['mms_quick_send']);
        $this->giveActiveSubscription($tenant);

        $response = $this->get(route('customer.workspaces.businesses.outreach.index', [$business->workspace->uid, $business->uid]));

        $response->assertOk();
        $response->assertSee('id="outreach-channel-mms"', false);
        $response->assertDontSee('id="outreach-channel-sms"', false);
    }

    public function test_sms_only_actor_is_rejected_sending_mms(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant, ['sms_quick_send']);

        $response = $this->post(route('customer.workspaces.businesses.outreach.mms.send', [$business->workspace->uid, $business->uid]), [
            'recipients' => '5551234567',
            'delimiter' => ',',
            'message' => 'hi',
        ]);

        $response->assertStatus(401);
    }

    public function test_mms_only_actor_is_rejected_sending_sms(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant, ['mms_quick_send']);

        $response = $this->post(route('customer.workspaces.businesses.outreach.sms.send', [$business->workspace->uid, $business->uid]), [
            'recipients' => '5551234567',
            'delimiter' => ',',
            'message' => 'hi',
        ]);

        $response->assertStatus(401);
    }

    public function test_actor_with_neither_permission_cannot_send_or_build_campaigns(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant, []);
        $params = [$business->workspace->uid, $business->uid];

        $this->post(route('customer.workspaces.businesses.outreach.sms.send', $params), [])->assertStatus(401);
        $this->post(route('customer.workspaces.businesses.outreach.mms.send', $params), [])->assertStatus(401);
        $this->post(route('customer.workspaces.businesses.outreach.sms.campaign', $params), [])->assertStatus(401);
        $this->post(route('customer.workspaces.businesses.outreach.mms.campaign', $params), [])->assertStatus(401);
    }

    public function test_outreach_index_never_renders_deferred_channel_markers(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant, ['sms_quick_send', 'sms_campaign_builder', 'mms_quick_send', 'mms_campaign_builder']);
        $this->giveActiveSubscription($tenant);

        $response = $this->get(route('customer.workspaces.businesses.outreach.index', [$business->workspace->uid, $business->uid]));

        $response->assertOk();
        $response->assertDontSee('WhatsApp');
        $response->assertDontSee('Viber');
        $response->assertDontSeeText('OTP');
        $response->assertDontSee('name="outreach-channel-voice"', false);
    }

    // -----------------------------------------------------------------
    // Business access boundary — unknown/wrong/inaccessible Business
    // fail closed as 404, exactly like UsageBillingController.
    // -----------------------------------------------------------------

    public function test_outreach_index_404s_for_unknown_workspace(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant, ['sms_quick_send']);

        $this->get(route('customer.workspaces.businesses.outreach.index', ['no-such-workspace', $business->uid]))
            ->assertStatus(404);
    }

    public function test_outreach_index_404s_for_unknown_business(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant, ['sms_quick_send']);

        $this->get(route('customer.workspaces.businesses.outreach.index', [$business->workspace->uid, 'no-such-business']))
            ->assertStatus(404);
    }

    public function test_outreach_index_404s_when_business_belongs_to_a_different_workspace(): void
    {
        [$tenantA, $businessA] = $this->tenantWithBusiness();
        [, $businessB] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenantA, ['sms_quick_send']);

        // businessB's uid under businessA's workspace — wrong Workspace.
        $this->get(route('customer.workspaces.businesses.outreach.index', [$businessA->workspace->uid, $businessB->uid]))
            ->assertStatus(404);
    }

    public function test_outreach_index_404s_for_actor_with_no_access_to_the_business(): void
    {
        [, $businessA] = $this->tenantWithBusiness();
        [$tenantB] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenantB, ['sms_quick_send']);

        $this->get(route('customer.workspaces.businesses.outreach.index', [$businessA->workspace->uid, $businessA->uid]))
            ->assertStatus(404);
    }

    public function test_workspace_owner_can_access_a_business_they_do_not_directly_own(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $ownerCustomer = $this->createCustomer();
        $workspace = Workspace::create(['name' => 'Shared WS', 'owner_user_id' => $ownerCustomer->user_id, 'is_active' => true]);
        $memberCustomer = $this->createCustomer();
        $business = app(\App\Repositories\Contracts\BusinessRepository::class)->createForCustomerInWorkspace($memberCustomer, $workspace, $this->businessAttributes());
        $this->giveActiveSubscription($memberCustomer);

        $this->authenticateAsCustomer($ownerCustomer, ['sms_quick_send']);

        $this->get(route('customer.workspaces.businesses.outreach.index', [$workspace->uid, $business->uid]))->assertOk();
    }

    public function test_staff_member_with_all_access_scope_can_operate_a_business_they_do_not_own(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $ownerCustomer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($ownerCustomer, $this->businessAttributes());
        $this->giveActiveSubscription($ownerCustomer);

        $staffCustomer = $this->createCustomer();
        WorkspaceMembership::create([
            'workspace_id' => $business->workspace_id,
            'user_id' => $staffCustomer->user_id,
            'role' => 'staff',
            'business_access_scope' => 'all',
            'is_active' => true,
        ]);

        $this->authenticateAsCustomer($staffCustomer, ['sms_quick_send']);

        $this->get(route('customer.workspaces.businesses.outreach.index', [$business->workspace->uid, $business->uid]))->assertOk();
    }

    public function test_staff_member_without_business_assignment_is_denied(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $ownerCustomer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($ownerCustomer, $this->businessAttributes());

        $staffCustomer = $this->createCustomer();
        WorkspaceMembership::create([
            'workspace_id' => $business->workspace_id,
            'user_id' => $staffCustomer->user_id,
            'role' => 'staff',
            'business_access_scope' => 'selected',
            'is_active' => true,
        ]);

        $this->authenticateAsCustomer($staffCustomer, ['sms_quick_send']);

        $this->get(route('customer.workspaces.businesses.outreach.index', [$business->workspace->uid, $business->uid]))
            ->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // Staff acting on a Business they don't own: the created Campaign
    // still gets the Business owner's legacy user_id, never the staff
    // actor's id — Pass 1's correction, re-verified end to end here.
    // -----------------------------------------------------------------

    public function test_campaign_created_by_staff_keeps_the_business_owners_legacy_user_id(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $ownerCustomer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($ownerCustomer, $this->businessAttributes());
        $this->giveActiveSubscription($ownerCustomer);

        $staffCustomer = $this->createCustomer();
        WorkspaceMembership::create([
            'workspace_id' => $business->workspace_id,
            'user_id' => $staffCustomer->user_id,
            'role' => 'staff',
            'business_access_scope' => 'all',
            'is_active' => true,
        ]);

        $group = \App\Models\ContactGroups::create(['customer_id' => $ownerCustomer->user_id, 'business_id' => $business->id, 'name' => 'VIPs', 'status' => true]);

        $mockRepo = \Mockery::mock(\App\Repositories\Contracts\CampaignRepository::class);
        $mockRepo->shouldReceive('campaignBuilder')
            ->once()
            ->withArgs(function ($campaign, array $input) use ($business, $ownerCustomer, $staffCustomer) {
                return $input['business_id'] === $business->id
                    && $input['user_id'] === $ownerCustomer->user_id
                    && $input['user_id'] !== $staffCustomer->user_id;
            })
            ->andReturn(response()->json(['status' => 'success', 'message' => 'queued']));
        $this->app->instance(\App\Repositories\Contracts\CampaignRepository::class, $mockRepo);

        $this->authenticateAsCustomer($staffCustomer, ['sms_campaign_builder']);

        $this->post(route('customer.workspaces.businesses.outreach.sms.campaign', [$business->workspace->uid, $business->uid]), [
            'name' => 'Staff Sent Blast',
            'contact_groups' => [$group->id],
            'message' => 'Hello',
        ]);
    }

    // -----------------------------------------------------------------
    // Cross-Business isolation — a Business cannot see or use another
    // Business's sender identities, templates, or campaigns, even when
    // both share the same underlying customer.
    // -----------------------------------------------------------------

    public function test_outreach_index_never_offers_a_different_businesss_sender_id(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $tenant = $this->createCustomer();
        $businessA = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business A']));
        $businessB = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business B']));
        $this->giveActiveSubscription($tenant);

        Senderid::create(['user_id' => $tenant->user_id, 'business_id' => $businessB->id, 'sender_id' => 'ONLY_B', 'status' => 'active']);

        $this->authenticateAsCustomer($tenant, ['sms_quick_send']);

        $response = $this->get(route('customer.workspaces.businesses.outreach.index', [$businessA->workspace->uid, $businessA->uid]));

        $response->assertOk();
        $response->assertDontSee('ONLY_B');
    }

    public function test_template_show_data_denies_a_template_belonging_to_a_different_business(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $tenant = $this->createCustomer();
        $businessA = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business A']));
        $businessB = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business B']));

        $foreignTemplate = Templates::create([
            'user_id' => $tenant->user_id,
            'business_id' => $businessB->id,
            'name' => 'Business B Template',
            'message' => 'Hello from B',
            'status' => true,
        ]);

        $this->authenticateAsCustomer($tenant, ['sms_quick_send']);

        $response = $this->postJson(route('customer.workspaces.businesses.outreach.templates.show_data', [$businessA->workspace->uid, $businessA->uid, $foreignTemplate->id]));

        $response->assertOk();
        $response->assertJson(['status' => 'error']);
    }

    public function test_template_show_data_returns_the_same_error_shape_for_a_nonexistent_template(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant, ['sms_quick_send']);

        $response = $this->postJson(route('customer.workspaces.businesses.outreach.templates.show_data', [$business->workspace->uid, $business->uid, 999999]));

        $response->assertOk();
        $response->assertJson(['status' => 'error']);
    }

    // -----------------------------------------------------------------
    // Campaign lifecycle isolation — a foreign Business's campaign 404s
    // identically to a nonexistent one; never scoped by user_id anymore.
    // -----------------------------------------------------------------

    public function test_campaign_show_pause_restart_resend_destroy_deny_a_different_business(): void
    {
        [$tenantA, $businessA] = $this->tenantWithBusiness();
        [, $businessB] = $this->tenantWithBusiness();
        $foreignCampaign = Campaigns::create([
            'user_id' => $businessB->customer_id,
            'business_id' => $businessB->id,
            'campaign_name' => 'Foreign Campaign',
            'message' => 'Hello',
            'sms_type' => 'plain',
            'status' => Campaigns::STATUS_PAUSED,
        ]);

        $this->authenticateAsCustomer($tenantA, ['sms_campaign_builder']);
        $params = [$businessA->workspace->uid, $businessA->uid, $foreignCampaign->uid];

        $this->get(route('customer.workspaces.businesses.outreach.campaigns.show', $params))->assertStatus(404);
        $this->post(route('customer.workspaces.businesses.outreach.campaigns.pause', $params))->assertStatus(404);
        $this->post(route('customer.workspaces.businesses.outreach.campaigns.restart', $params))->assertStatus(404);
        $this->post(route('customer.workspaces.businesses.outreach.campaigns.resend', $params))->assertStatus(404);
        $this->post(route('customer.workspaces.businesses.outreach.campaigns.destroy', $params))->assertStatus(404);

        $this->assertDatabaseHas('campaigns', ['id' => $foreignCampaign->id, 'status' => Campaigns::STATUS_PAUSED]);
    }

    public function test_campaign_list_only_shows_the_selected_businesss_own_campaigns(): void
    {
        [$tenantA, $businessA] = $this->tenantWithBusiness();
        [, $businessB] = $this->tenantWithBusiness();
        Campaigns::create(['user_id' => $businessA->customer_id, 'business_id' => $businessA->id, 'campaign_name' => 'Mine', 'message' => 'Hi', 'sms_type' => 'plain', 'status' => Campaigns::STATUS_DONE]);
        Campaigns::create(['user_id' => $businessB->customer_id, 'business_id' => $businessB->id, 'campaign_name' => 'Not Mine', 'message' => 'Hi', 'sms_type' => 'plain', 'status' => Campaigns::STATUS_DONE]);

        $this->authenticateAsCustomer($tenantA, ['sms_campaign_builder']);

        $response = $this->get(route('customer.workspaces.businesses.outreach.campaigns', [$businessA->workspace->uid, $businessA->uid]));

        $response->assertOk();
        $response->assertSee('Mine');
        $response->assertDontSee('Not Mine');
    }

    // -----------------------------------------------------------------
    // Legacy standalone Template CRUD isolation — untouched by Pass 2,
    // kept operational for compatibility (still user_id-scoped).
    // -----------------------------------------------------------------

    public function test_legacy_template_show_update_destroy_and_toggle_deny_foreign_owner(): void
    {
        [$tenantA] = $this->tenantWithBusiness();
        [$tenantB] = $this->tenantWithBusiness();
        $foreignTemplate = Templates::create([
            'user_id' => $tenantB->user_id,
            'name' => 'Foreign Template',
            'message' => 'Hello from B',
            'status' => true,
        ]);

        $this->authenticateAsCustomer($tenantA, ['sms_template']);

        $this->get(route('customer.templates.show', $foreignTemplate->uid))->assertStatus(404);
        $this->put(route('customer.templates.update', $foreignTemplate->uid), ['name' => 'x', 'message' => 'y', 'user_type' => 'customer'])->assertStatus(404);
        $this->delete(route('customer.templates.destroy', $foreignTemplate->uid))->assertStatus(404);
        $this->post(route('customer.templates.active', $foreignTemplate->uid))->assertStatus(404);

        $this->assertDatabaseHas('templates', ['id' => $foreignTemplate->id, 'name' => 'Foreign Template']);
    }

    public function test_legacy_template_store_forces_authenticated_user_id_regardless_of_input(): void
    {
        [$tenantA] = $this->tenantWithBusiness();
        [$tenantB] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenantA, ['sms_template']);

        $this->post(route('customer.templates.store'), [
            'name' => 'Spoofed Owner Template',
            'message' => 'Hi',
            'user_type' => 'customer',
            'user_id' => $tenantB->user_id,
        ]);

        $this->assertDatabaseHas('templates', [
            'name' => 'Spoofed Owner Template',
            'user_id' => $tenantA->user_id,
        ]);
        $this->assertDatabaseMissing('templates', [
            'name' => 'Spoofed Owner Template',
            'user_id' => $tenantB->user_id,
        ]);
    }

    public function test_legacy_template_batch_action_cannot_mutate_a_foreign_template(): void
    {
        [$tenantA] = $this->tenantWithBusiness();
        [$tenantB] = $this->tenantWithBusiness();
        $foreignTemplate = Templates::create([
            'user_id' => $tenantB->user_id,
            'name' => 'Foreign Batch Target',
            'message' => 'Hello',
            'status' => true,
        ]);

        $this->authenticateAsCustomer($tenantA, ['sms_template']);

        $this->postJson(route('customer.templates.batch_action'), [
            'action' => 'destroy',
            'ids' => [$foreignTemplate->uid],
        ]);

        $this->assertDatabaseHas('templates', ['id' => $foreignTemplate->id]);
    }

    // -----------------------------------------------------------------
    // Entry/selector route — never guesses a Business.
    // -----------------------------------------------------------------

    public function test_entry_route_redirects_straight_through_when_exactly_one_business_is_accessible(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant, ['sms_quick_send']);

        $response = $this->get(route('customer.outreach.index'));

        $response->assertRedirect(route('customer.workspaces.businesses.outreach.index', [$business->workspace->uid, $business->uid]));
    }

    public function test_entry_route_shows_a_chooser_when_multiple_businesses_are_accessible(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $tenant = $this->createCustomer();
        $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business One']));
        $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business Two']));
        $this->authenticateAsCustomer($tenant, ['sms_quick_send']);

        $response = $this->get(route('customer.outreach.index'));

        $response->assertOk();
        $response->assertSee('Business One');
        $response->assertSee('Business Two');
    }

    public function test_entry_route_shows_an_empty_state_when_no_business_is_accessible(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $tenant = $this->createCustomer();
        $this->authenticateAsCustomer($tenant, ['sms_quick_send']);

        $response = $this->get(route('customer.outreach.index'));

        $response->assertOk();
        $response->assertDontSee('list-group-item-action', false);
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

    private function authenticateAsCustomer(Customer $customer, array $permissions): void
    {
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(array_merge(['access_backend'], $permissions))]);
        $this->actingAs($customer->user);
    }

    private function giveActiveSubscription(Customer $customer): void
    {
        $currency = \App\Models\Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'format' => '$', 'status' => true]);

        $plan = \App\Models\Plan::create([
            'currency_id' => $currency->id,
            'name' => 'Outreach Security Test Plan ' . uniqid(),
            'price' => 10,
            'billing_cycle' => 'monthly',
            'frequency_amount' => 1,
            'frequency_unit' => 'month',
            'options' => json_encode([]),
            'status' => true,
        ]);

        \App\Models\Subscription::create([
            'user_id' => $customer->user_id,
            'plan_id' => $plan->id,
            'status' => \App\Models\Subscription::STATUS_ACTIVE,
            'paid' => true,
            'start_at' => now(),
            'end_at' => null,
        ]);
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
}
