<?php

namespace Tests\Feature\Security;

use App\Models\AppConfig;
use App\Models\Campaigns;
use App\Models\Customer;
use App\Models\Templates;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * B1 Outreach / Compose — authorization and tenant-isolation coverage.
 *
 * Covers B1 focused-test items 1-4, 5 (partially, via authorization gating
 * of the two channel tabs), 12 and 16: Outreach page authorization,
 * SMS-only/MMS-only/neither-channel authorization, only-SMS/MMS ever
 * rendered, template ownership isolation, and no cross-user campaign
 * access via the new outreach.campaigns.* routes.
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
    // Item 1 — Outreach page authorization
    // -----------------------------------------------------------------

    public function test_outreach_index_denies_actor_with_no_sms_or_mms_permission(): void
    {
        [$tenant] = $this->tenants();
        $this->authenticateAsCustomer($tenant, []);

        $response = $this->get(route('customer.outreach.index'));

        $response->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // Item 2 — SMS-only authorization
    // -----------------------------------------------------------------

    public function test_sms_only_actor_can_open_outreach_and_only_sees_sms(): void
    {
        [$tenant] = $this->tenants();
        $this->authenticateAsCustomer($tenant, ['sms_quick_send']);
        $this->giveActiveSubscription($tenant);

        $response = $this->get(route('customer.outreach.index'));

        $response->assertOk();
        $response->assertSee('id="outreach-channel-sms"', false);
        $response->assertDontSee('id="outreach-channel-mms"', false);
    }

    // -----------------------------------------------------------------
    // Item 3 — MMS-only authorization
    // -----------------------------------------------------------------

    public function test_mms_only_actor_can_open_outreach_and_only_sees_mms(): void
    {
        [$tenant] = $this->tenants();
        $this->authenticateAsCustomer($tenant, ['mms_quick_send']);
        $this->giveActiveSubscription($tenant);

        $response = $this->get(route('customer.outreach.index'));

        $response->assertOk();
        $response->assertSee('id="outreach-channel-mms"', false);
        $response->assertDontSee('id="outreach-channel-sms"', false);
    }

    // -----------------------------------------------------------------
    // Item 4 — unauthorized channel rejection at the send boundary
    // -----------------------------------------------------------------

    public function test_sms_only_actor_is_rejected_sending_mms(): void
    {
        [$tenant] = $this->tenants();
        $this->authenticateAsCustomer($tenant, ['sms_quick_send']);

        $response = $this->post(route('customer.outreach.mms.send'), [
            'recipients' => '5551234567',
            'delimiter' => ',',
            'message' => 'hi',
        ]);

        $response->assertStatus(401);
    }

    public function test_mms_only_actor_is_rejected_sending_sms(): void
    {
        [$tenant] = $this->tenants();
        $this->authenticateAsCustomer($tenant, ['mms_quick_send']);

        $response = $this->post(route('customer.outreach.sms.send'), [
            'recipients' => '5551234567',
            'delimiter' => ',',
            'message' => 'hi',
        ]);

        $response->assertStatus(401);
    }

    public function test_actor_with_neither_permission_cannot_send_or_build_campaigns(): void
    {
        [$tenant] = $this->tenants();
        $this->authenticateAsCustomer($tenant, []);

        $this->post(route('customer.outreach.sms.send'), [])->assertStatus(401);
        $this->post(route('customer.outreach.mms.send'), [])->assertStatus(401);
        $this->post(route('customer.outreach.sms.campaign'), [])->assertStatus(401);
        $this->post(route('customer.outreach.mms.campaign'), [])->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // Item 5 — only SMS/MMS ever rendered, never the deferred channels
    // -----------------------------------------------------------------

    public function test_outreach_index_never_renders_deferred_channel_markers(): void
    {
        [$tenant] = $this->tenants();
        $this->authenticateAsCustomer($tenant, ['sms_quick_send', 'sms_campaign_builder', 'mms_quick_send', 'mms_campaign_builder']);
        $this->giveActiveSubscription($tenant);

        $response = $this->get(route('customer.outreach.index'));

        $response->assertOk();
        $response->assertDontSee('WhatsApp');
        $response->assertDontSee('Viber');
        $response->assertDontSeeText('OTP');
        $response->assertDontSee('name="outreach-channel-voice"', false);
    }

    // -----------------------------------------------------------------
    // Item 12 — template ownership isolation (TemplateController fix)
    // -----------------------------------------------------------------

    public function test_template_show_update_destroy_and_toggle_deny_foreign_owner(): void
    {
        [$tenantA, $tenantB] = $this->tenants();
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

    public function test_template_store_forces_authenticated_user_id_regardless_of_input(): void
    {
        [$tenantA, $tenantB] = $this->tenants();
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

    public function test_template_batch_action_cannot_mutate_a_foreign_template(): void
    {
        [$tenantA, $tenantB] = $this->tenants();
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
    // Item 16 — no cross-user campaign access via the new Outreach routes
    // -----------------------------------------------------------------

    public function test_campaign_show_pause_restart_resend_destroy_deny_foreign_owner(): void
    {
        [$tenantA, $tenantB] = $this->tenants();
        $foreignCampaign = Campaigns::create([
            'user_id' => $tenantB->user_id,
            'campaign_name' => 'Foreign Campaign',
            'message' => 'Hello',
            'sms_type' => 'plain',
            'status' => Campaigns::STATUS_PAUSED,
        ]);

        $this->authenticateAsCustomer($tenantA, ['sms_campaign_builder']);

        $this->get(route('customer.outreach.campaigns.show', $foreignCampaign->uid))->assertStatus(404);
        $this->post(route('customer.outreach.campaigns.pause', $foreignCampaign->uid))->assertStatus(404);
        $this->post(route('customer.outreach.campaigns.restart', $foreignCampaign->uid))->assertStatus(404);
        $this->post(route('customer.outreach.campaigns.resend', $foreignCampaign->uid))->assertStatus(404);
        $this->post(route('customer.outreach.campaigns.destroy', $foreignCampaign->uid))->assertStatus(404);

        $this->assertDatabaseHas('campaigns', ['id' => $foreignCampaign->id, 'status' => Campaigns::STATUS_PAUSED]);
    }

    public function test_campaign_list_only_shows_the_authenticated_users_own_campaigns(): void
    {
        [$tenantA, $tenantB] = $this->tenants();
        Campaigns::create(['user_id' => $tenantA->user_id, 'campaign_name' => 'Mine', 'message' => 'Hi', 'sms_type' => 'plain', 'status' => Campaigns::STATUS_DONE]);
        Campaigns::create(['user_id' => $tenantB->user_id, 'campaign_name' => 'Not Mine', 'message' => 'Hi', 'sms_type' => 'plain', 'status' => Campaigns::STATUS_DONE]);

        $this->authenticateAsCustomer($tenantA, ['sms_campaign_builder']);

        $response = $this->get(route('customer.outreach.campaigns'));

        $response->assertOk();
        $response->assertSee('Mine');
        $response->assertDontSee('Not Mine');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return array{0: Customer, 1: Customer}
     */
    private function tenants(): array
    {
        $this->ensureRequiredAppConfigRowsExist();

        return [$this->createCustomer(), $this->createCustomer()];
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
            'name' => 'Outreach Security Test Plan',
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
