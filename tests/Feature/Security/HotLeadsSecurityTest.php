<?php

namespace Tests\Feature\Security;

use App\Models\AppConfig;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Dashboard Security Remediation Contract §14 item 1 — HotLeadController.
 *
 * Tenant-isolation and mutation-boundary assertions below require the
 * chat_boxes.ai_stage/called/website_sent_at columns HotLeadController
 * reads/writes. Those columns have no migration anywhere in this
 * repository (confirmed by exhaustive content grep across all 213
 * tracked migrations) and are therefore absent from the disposable
 * ultimatesms_testing database even fully migrated. Each assertion that
 * needs them checks column existence first and calls
 * markTestSkipped() with the exact missing column when absent, rather
 * than fabricating schema or silently passing/failing for an unrelated
 * reason. The route/gate-boundary assertions do not depend on these
 * columns and always run for real.
 */
class HotLeadsSecurityTest extends TestCase
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
        $response = $this->get('/admin/hot-leads');

        $response->assertStatus(401);
    }

    public function test_guest_post_is_blocked_with_401(): void
    {
        $response = $this->post('/admin/hot-leads/mark-called', ['id' => 1]);

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

        $response = $this->get('/admin/hot-leads');

        $response->assertStatus(401);
    }

    public function test_customer_with_chat_box_sees_only_own_leads_tenant_a_data_present_tenant_b_absent(): void
    {
        $this->skipUnlessHotLeadColumnsExist();

        [$tenantA, $tenantB] = $this->twoTenantCustomers();

        $leadA = $this->seedHotLead($tenantA->user_id, '+15550000001');
        $leadB = $this->seedHotLead($tenantB->user_id, '+15550000002');

        $this->authenticateAsCustomerWithChatBox($tenantA);

        $response = $this->get('/admin/hot-leads');

        $response->assertOk();
        $response->assertSee($leadA->to);
        $response->assertDontSee($leadB->to);
    }

    public function test_mark_called_cross_tenant_real_id_returns_404_and_leaves_row_unchanged(): void
    {
        $this->skipUnlessHotLeadColumnsExist();

        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $leadB = $this->seedHotLead($tenantB->user_id, '+15550000003');

        $this->authenticateAsCustomerWithChatBox($tenantA);

        $response = $this->post('/admin/hot-leads/mark-called', ['id' => $leadB->id]);

        $response->assertNotFound();
        $this->assertSame(0, DB::table('chat_boxes')->where('id', $leadB->id)->where('called', 1)->count());
    }

    public function test_mark_called_nonexistent_id_returns_404_with_the_same_response_shape_as_cross_tenant(): void
    {
        $this->skipUnlessHotLeadColumnsExist();

        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $leadB = $this->seedHotLead($tenantB->user_id, '+15550000004');

        $this->authenticateAsCustomerWithChatBox($tenantA);

        $crossTenantResponse = $this->post('/admin/hot-leads/mark-called', ['id' => $leadB->id]);
        $nonexistentResponse = $this->post('/admin/hot-leads/mark-called', ['id' => $leadB->id + 999999]);

        $crossTenantResponse->assertNotFound();
        $nonexistentResponse->assertNotFound();
        $this->assertSame(
            $crossTenantResponse->getStatusCode(),
            $nonexistentResponse->getStatusCode(),
            'Cross-tenant and nonexistent IDs must produce the identical 404 boundary (contract §11/§12).'
        );
    }

    public function test_mark_called_own_row_succeeds_and_sets_called(): void
    {
        $this->skipUnlessHotLeadColumnsExist();

        [$tenantA] = $this->twoTenantCustomers();
        $leadA = $this->seedHotLead($tenantA->user_id, '+15550000005');

        $this->authenticateAsCustomerWithChatBox($tenantA);

        $response = $this->post('/admin/hot-leads/mark-called', ['id' => $leadA->id]);

        $response->assertRedirect();
        $this->assertSame(1, DB::table('chat_boxes')->where('id', $leadA->id)->where('called', 1)->count());
    }

    public function test_mark_called_missing_id_fails_validation_separately_from_the_404_cases(): void
    {
        [$tenantA] = $this->twoTenantCustomers();
        $this->authenticateAsCustomerWithChatBox($tenantA);

        $response = $this->post('/admin/hot-leads/mark-called', []);

        $response->assertSessionHasErrors('id');
    }

    public function test_mark_called_non_integer_id_fails_validation_separately_from_the_404_cases(): void
    {
        [$tenantA] = $this->twoTenantCustomers();
        $this->authenticateAsCustomerWithChatBox($tenantA);

        $response = $this->post('/admin/hot-leads/mark-called', ['id' => 'not-an-integer']);

        $response->assertSessionHasErrors('id');
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

    private function seedHotLead(int $userId, string $to): object
    {
        $id = DB::table('chat_boxes')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $userId,
            'to' => $to,
            'ai_stage' => 4,
            'called' => 0,
            'website_sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('chat_boxes')->where('id', $id)->first();
    }

    private function skipUnlessHotLeadColumnsExist(): void
    {
        foreach (['ai_stage', 'called', 'website_sent_at'] as $column) {
            if (! Schema::hasColumn('chat_boxes', $column)) {
                $this->markTestSkipped(
                    "chat_boxes.$column has no migration anywhere in this repository and does not exist in ultimatesms_testing — cannot exercise HotLeadController against real data without fabricating schema."
                );
            }
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
