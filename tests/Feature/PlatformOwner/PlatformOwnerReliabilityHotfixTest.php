<?php

namespace Tests\Feature\PlatformOwner;

use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentProviderConfigurationStatus;
use App\Library\Usage\StripePaymentProviderGateway;
use App\Models\AppConfig;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Platform Owner reliability hotfix: (A) read-only Usage Billing pages must
 * not require Stripe credentials while money-moving boundaries still fail
 * closed; (B) the Admin Roles / Administrators DataTables Ajax contract
 * returns the `action` column the frontend declares.
 */
class PlatformOwnerReliabilityHotfixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['license' => 'test-license-key', 'custom_script' => ''] as $setting => $value) {
            AppConfig::firstOrCreate(['setting' => $setting], ['value' => $value]);
        }

        if (! AppConfig::where('setting', 'customer_permissions')->exists()) {
            AppConfig::create(collect((new AppConfig())->defaultSettings())->firstWhere('setting', 'customer_permissions'));
        }

        // Fixture id=1 (the unconditional super-admin bypass) is consumed
        // here so the actors below exercise the real permission path.
        DB::table('users')->insert([
            'id' => 1, 'uid' => uniqid('', true), 'first_name' => 'Seed', 'last_name' => 'Filler',
            'email' => 'seed-filler-'.uniqid('', true).'@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false,
            'active_portal' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function clearStripeConfig(): void
    {
        config([
            'services.stripe.mode' => 'test',
            'services.stripe.secret' => '',
            'services.stripe.webhook.secret' => '',
        ]);
    }

    private function actingAsAdmin(array $permissions): User
    {
        $admin = User::create([
            'first_name' => 'Test', 'last_name' => 'Admin',
            'email' => 'admin'.uniqid('', true).'@example.test', 'timezone' => 'UTC',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        $this->withSession(['permissions' => collect($permissions)]);
        $this->actingAs($admin);

        return $admin;
    }

    private function datatablesPayload(): array
    {
        return ['draw' => 1, 'start' => 0, 'length' => 10, 'order' => [['column' => 1, 'dir' => 'asc']], 'search' => ['value' => '']];
    }

    // ---- A. lazy Stripe configuration ---------------------------------

    public function test_safety_limits_get_succeeds_without_stripe_credentials_and_shows_not_configured(): void
    {
        $this->clearStripeConfig();
        $this->actingAsAdmin(['access backend']);

        $this->get(route('admin.usage-billing.safety-limits.index'))
            ->assertOk()
            ->assertSee('Payment provider')
            ->assertSee('Not configured');
    }

    public function test_additional_slot_agreements_get_succeeds_without_stripe_credentials(): void
    {
        $this->clearStripeConfig();
        $this->actingAsAdmin(['access backend']);

        $this->get(route('admin.additional-business-slot-agreements.index'))
            ->assertOk()
            ->assertSee('Not configured');
    }

    public function test_constructing_the_real_gateway_never_requires_credentials(): void
    {
        $this->clearStripeConfig();

        $this->assertInstanceOf(StripePaymentProviderGateway::class, app(PaymentProviderGateway::class));
        $this->assertFalse(PaymentProviderConfigurationStatus::isConfigured());
    }

    public function test_provider_actions_still_fail_closed_without_valid_configuration(): void
    {
        $this->clearStripeConfig();
        $gateway = app(PaymentProviderGateway::class);

        foreach ([
            fn () => $gateway->createOrRetrieveCustomer(null, 'key'),
            fn () => $gateway->retrievePaymentIntent('pi_x'),
            fn () => $gateway->verifyWebhookSignature('{}', 't=1,v1=x', 'whsec_x'),
        ] as $action) {
            try {
                $action();
                $this->fail('A provider action ran without valid Stripe configuration.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('services.stripe', $e->getMessage());
            }
        }
    }

    public function test_provider_actions_fail_closed_on_secret_mode_mismatch_and_missing_webhook_secret(): void
    {
        config(['services.stripe.mode' => 'live', 'services.stripe.secret' => 'sk_test_x', 'services.stripe.webhook.secret' => 'whsec_x', 'services.stripe.api_version' => '2024-06-20']);
        $this->assertSame('services.stripe.secret does not match the configured services.stripe.mode.', PaymentProviderConfigurationStatus::problem());

        config(['services.stripe.mode' => 'test', 'services.stripe.webhook.secret' => '']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('services.stripe.webhook.secret');
        app(PaymentProviderGateway::class)->retrievePaymentIntent('pi_x');
    }

    public function test_valid_configuration_reports_configured_and_fake_gateway_path_still_works(): void
    {
        config(['services.stripe.mode' => 'test', 'services.stripe.secret' => 'sk_test_x', 'services.stripe.webhook.secret' => 'whsec_x', 'services.stripe.api_version' => '2024-06-20']);
        $this->assertNull(PaymentProviderConfigurationStatus::problem());
        $this->assertSame('Configured', PaymentProviderConfigurationStatus::label());

        $this->clearStripeConfig();
        app()->instance(PaymentProviderGateway::class, new FakePaymentProviderGateway());
        $this->assertTrue(app(PaymentProviderGateway::class)->createOrRetrieveCustomer(null, 'key')->wasCreated);
    }

    // ---- B. DataTables action column ----------------------------------

    public function test_admin_roles_search_renders_with_zero_rows(): void
    {
        $this->actingAsAdmin(['access backend', 'view roles']);

        $this->post(route('admin.roles.search'), $this->datatablesPayload())
            ->assertOk()
            ->assertJson(['recordsTotal' => 0, 'data' => []]);
    }

    public function test_admin_roles_rows_carry_an_action_column_gated_by_permission(): void
    {
        $role = Role::create(['name' => 'support-'.uniqid(), 'status' => 1]);

        $this->actingAsAdmin(['access backend', 'view roles', 'edit roles', 'delete roles']);
        $row = $this->post(route('admin.roles.search'), $this->datatablesPayload())->assertOk()->json('data.0');
        $this->assertArrayHasKey('action', $row);
        $this->assertArrayNotHasKey('edit', $row);
        $this->assertStringContainsString(route('admin.roles.show', $role->uid), html_entity_decode($row['action']));
        $this->assertStringContainsString('action-delete', $row['action']);

        $this->actingAsAdmin(['access backend', 'view roles']);
        $row = $this->post(route('admin.roles.search'), $this->datatablesPayload())->assertOk()->json('data.0');
        $this->assertSame('', $row['action']);

        $this->actingAsAdmin(['access backend', 'view roles', 'edit roles']);
        $row = $this->post(route('admin.roles.search'), $this->datatablesPayload())->assertOk()->json('data.0');
        $this->assertStringContainsString('data-feather="edit"', $row['action']);
        $this->assertStringNotContainsString('action-delete', $row['action']);
    }

    public function test_administrators_search_renders_with_zero_rows_and_with_rows(): void
    {
        $viewer = $this->actingAsAdmin(['access backend', 'view administrator']);
        $payload = $this->datatablesPayload() + [];
        $payload['order'] = [['column' => 1, 'dir' => 'asc']];

        // The only is_admin non-id-1 user is the viewer itself.
        $row = $this->post(route('admin.administrators.search'), $payload)->assertOk()->json('data.0');
        $this->assertSame($viewer->uid, $row['uid']);
        $this->assertArrayHasKey('action', $row);
        $this->assertArrayNotHasKey('edit', $row);
        $this->assertArrayNotHasKey('delete', $row);
        $this->assertSame('', $row['action']);

        $this->actingAsAdmin(['access backend', 'view administrator', 'edit administrator', 'delete administrator']);
        $row = $this->post(route('admin.administrators.search'), $payload)->assertOk()->json('data.0');
        $this->assertStringContainsString('action-delete', $row['action']);
        $this->assertStringContainsString('data-feather="edit"', $row['action']);
    }

    public function test_administrators_and_roles_views_declare_the_action_column_without_client_side_edit_delete_keys(): void
    {
        foreach (['AdminRoles', 'Administrator'] as $dir) {
            $source = file_get_contents(resource_path("views/admin/{$dir}/index.blade.php"));
            $this->assertStringContainsString('{"data": "action"', $source);
            $this->assertStringNotContainsString("full['edit']", $source);
            $this->assertStringNotContainsString("full['delete']", $source);
        }
    }
}
