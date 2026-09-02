<?php

namespace Tests\Feature\Security;

use App\Models\AppConfig;
use App\Models\Campaigns;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Dashboard Security Remediation Contract §14 item 2 — AiAnalyticsController.
 *
 * As with HotLeadsSecurityTest: the chat_boxes.ai_stage column and the
 * entire ai_box_campaign_map table have no migration anywhere in this
 * repository (confirmed by exhaustive search) and are therefore absent
 * from the disposable ultimatesms_testing database even fully migrated.
 * Assertions that need them are skipped with markTestSkipped() naming
 * the exact missing schema, never fabricated or silently passed. The
 * route/gate-boundary assertions do not depend on this schema and
 * always run for real.
 */
class AiAnalyticsSecurityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    /**
     * EloquentAccountRepository::hasPermission() unconditionally grants
     * every permission to the account whose id === 1 (the repository's
     * own pre-existing, deliberately-unchanged super-admin bypass — see
     * DESIGN-SYSTEM-M2-SLICE-3-DASHBOARD-SECURITY-REMEDIATION-CONTRACT.md
     * §6). RefreshDatabase migrates a fresh, empty users table per test
     * class, and MySQL's InnoDB auto_increment counter is not rolled
     * back by a transaction rollback — so the first User created in any
     * test method here would otherwise silently become id 1 and bypass
     * every permission assertion this file makes. Consuming id 1 with a
     * throwaway user, before any test's own actor is created, is the
     * deterministic fix rather than relying on incidental test order.
     */
    protected function setUp(): void
    {
        parent::setUp();

        \App\Models\User::create([
            'first_name' => 'Placeholder',
            'last_name' => 'SuperAdmin',
            'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);
    }

    /**
     * This app's own Handler.php maps both AuthenticationException and
     * AuthorizationException to a rendered 401 page whenever
     * config('app.env') !== 'local' — which includes the 'testing' env
     * phpunit.xml sets for every test run. A guest is therefore blocked
     * with 401, not a 302 redirect — real, pre-existing app behavior,
     * unrelated to this remediation, confirmed by direct inspection of
     * app/Exceptions/Handler.php rather than assumed from generic
     * Laravel defaults.
     */
    public function test_guest_get_is_blocked_with_401(): void
    {
        $response = $this->get('/admin/ai-analytics');

        $response->assertStatus(401);
    }

    public function test_guest_post_is_blocked_with_401(): void
    {
        $response = $this->post('/admin/ai-analytics/book/1');

        $response->assertStatus(401);
    }

    public function test_authenticated_customer_lacking_chat_box_receives_401(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->createCustomer();
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(['access_backend'])]);
        $this->actingAs($customer->user);

        $response = $this->get('/admin/ai-analytics');

        $response->assertStatus(401);
    }

    public function test_authorized_customer_succeeds(): void
    {
        $this->skipUnlessAnalyticsSchemaExists();

        [$tenantA] = $this->twoTenantCustomers();
        $this->authenticateAsCustomerWithChatBox($tenantA);

        $response = $this->get('/admin/ai-analytics');

        $response->assertOk();
    }

    public function test_stage_counts_and_recent_boxes_contain_tenant_a_only_tenant_b_absent(): void
    {
        $this->skipUnlessAnalyticsSchemaExists();

        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $boxA = $this->seedChatBox($tenantA->user_id, '+15551110001', 2);
        $boxB = $this->seedChatBox($tenantB->user_id, '+15551110002', 2);

        $this->authenticateAsCustomerWithChatBox($tenantA);

        $response = $this->get('/admin/ai-analytics');

        $response->assertOk();
        $response->assertSee($boxA->to);
        $response->assertDontSee($boxB->to);
    }

    public function test_campaigns_dropdown_includes_tenant_a_only_tenant_b_absent(): void
    {
        $this->skipUnlessAnalyticsSchemaExists();

        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $campaignA = $this->seedCampaign($tenantA->user_id, 'Tenant A Campaign');
        $campaignB = $this->seedCampaign($tenantB->user_id, 'Tenant B Campaign');

        $this->authenticateAsCustomerWithChatBox($tenantA);

        $response = $this->get('/admin/ai-analytics');

        $response->assertOk();
        $response->assertSee('Tenant A Campaign');
        $response->assertDontSee('Tenant B Campaign');
    }

    public function test_another_tenants_campaign_id_does_not_error_and_falls_back_to_tenant_a_only_unfiltered_view(): void
    {
        $this->skipUnlessAnalyticsSchemaExists();

        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $campaignB = $this->seedCampaign($tenantB->user_id, 'Tenant B Campaign');
        $boxA = $this->seedChatBox($tenantA->user_id, '+15551110003', 2);

        $this->authenticateAsCustomerWithChatBox($tenantA);

        $response = $this->get('/admin/ai-analytics?campaign_id=' . $campaignB->id);

        $response->assertOk();
        $response->assertSee($boxA->to);
        $response->assertDontSee('Tenant B Campaign');
    }

    public function test_nonexistent_and_malformed_campaign_filter_also_safely_falls_back(): void
    {
        $this->skipUnlessAnalyticsSchemaExists();

        [$tenantA] = $this->twoTenantCustomers();
        $boxA = $this->seedChatBox($tenantA->user_id, '+15551110004', 2);

        $this->authenticateAsCustomerWithChatBox($tenantA);

        $nonexistent = $this->get('/admin/ai-analytics?campaign_id=999999999');
        $malformed = $this->get('/admin/ai-analytics?campaign_id=not-a-number');

        $nonexistent->assertOk();
        $nonexistent->assertSee($boxA->to);
        $malformed->assertOk();
        $malformed->assertSee($boxA->to);
    }

    public function test_mark_booked_cross_tenant_real_id_returns_404_no_mutation(): void
    {
        $this->skipUnlessAnalyticsSchemaExists();

        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $boxB = $this->seedChatBox($tenantB->user_id, '+15551110005', 2);

        $this->authenticateAsCustomerWithChatBox($tenantA);

        $response = $this->post('/admin/ai-analytics/book/' . $boxB->id);

        $response->assertNotFound();
        $this->assertSame(0, DB::table('chat_boxes')->where('id', $boxB->id)->where('ai_stage', 6)->count());
    }

    public function test_mark_booked_nonexistent_id_returns_404_no_mutation(): void
    {
        $this->skipUnlessAnalyticsSchemaExists();

        [$tenantA] = $this->twoTenantCustomers();
        $this->authenticateAsCustomerWithChatBox($tenantA);

        $response = $this->post('/admin/ai-analytics/book/999999999');

        $response->assertNotFound();
    }

    public function test_mark_booked_own_row_succeeds_with_existing_redirect_behavior(): void
    {
        $this->skipUnlessAnalyticsSchemaExists();

        [$tenantA] = $this->twoTenantCustomers();
        $boxA = $this->seedChatBox($tenantA->user_id, '+15551110006', 2);

        $this->authenticateAsCustomerWithChatBox($tenantA);

        $response = $this->post('/admin/ai-analytics/book/' . $boxA->id);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertSame(1, DB::table('chat_boxes')->where('id', $boxA->id)->where('ai_stage', 6)->count());
    }

    /**
     * @return array{0: \App\Models\Customer, 1: \App\Models\Customer}
     */
    private function twoTenantCustomers(): array
    {
        $this->ensureRequiredAppConfigRowsExist();

        $tenantA = $this->createCustomer();
        $tenantB = $this->createCustomer();

        return [$tenantA, $tenantB];
    }

    private function authenticateAsCustomerWithChatBox(Customer $customer): void
    {
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(['access_backend', 'chat_box'])]);
        $this->actingAs($customer->user);
    }

    private function seedChatBox(int $userId, string $to, int $aiStage): object
    {
        $id = DB::table('chat_boxes')->insertGetId([
            'uid' => (string) Str::uuid(),
            'user_id' => $userId,
            'to' => $to,
            'ai_stage' => $aiStage,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('chat_boxes')->where('id', $id)->first();
    }

    private function seedCampaign(int $userId, string $name): Campaigns
    {
        return Campaigns::create([
            'uid' => (string) Str::uuid(),
            'user_id' => $userId,
            'campaign_name' => $name,
            'sms_type' => 'plain',
            'upload_type' => 'normal',
        ]);
    }

    private function skipUnlessAnalyticsSchemaExists(): void
    {
        if (! Schema::hasColumn('chat_boxes', 'ai_stage')) {
            $this->markTestSkipped(
                'chat_boxes.ai_stage has no migration anywhere in this repository and does not exist in ultimatesms_testing — cannot exercise AiAnalyticsController against real data without fabricating schema.'
            );
        }

        if (! Schema::hasTable('ai_box_campaign_map')) {
            $this->markTestSkipped(
                'ai_box_campaign_map has no migration anywhere in this repository and does not exist in ultimatesms_testing — cannot exercise AiAnalyticsController against real data without fabricating schema.'
            );
        }
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
