<?php

namespace Tests\Feature\Settings;

use App\Models\AppConfig;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Settings\Concerns\SettingsTestHelpers;
use Tests\TestCase;

/**
 * B3 Simplified Platform Settings §8/§18/§19 (Default customer
 * permissions). The reconnaissance found SettingsController::permissions()
 * previously stored the raw PHP array directly into app_config.value (a
 * string column) while every reader (Customer::customerPermissions(),
 * User model, CustomerController) calls json_decode() on it. Proves the
 * fix persists a JSON string readers decode correctly, and that demo mode
 * (previously the only settings writer missing that guard) now blocks
 * mutation.
 */
class PlatformSettingsCustomerPermissionsTest extends TestCase
{
    use RefreshDatabase;
    use SettingsTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->consumeSuperAdminId();
    }

    public function test_valid_permissions_persist_as_a_json_string_readers_decode_correctly(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend', 'authentication settings']);

        $this->post(route('admin.settings.permissions'), [
            'permissions' => ['access_backend' => 'access_backend', 'view_reports', 'automations'],
        ])->assertRedirect();

        $raw = AppConfig::where('setting', 'customer_permissions')->value('value');

        $this->assertIsString($raw);
        $decoded = json_decode($raw, true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertIsArray($decoded);
        $this->assertContains('access_backend', $decoded);
        $this->assertContains('view_reports', $decoded);
        $this->assertContains('automations', $decoded);

        // Customer::customerPermissions() is the real reader every
        // consumer (User model, CustomerController) goes through.
        $this->assertSame($raw, Customer::customerPermissions());
    }

    public function test_demo_mode_blocks_permissions_mutation(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $original = AppConfig::where('setting', 'customer_permissions')->value('value');
        config(['app.stage' => 'demo']);
        $this->actingAsAdmin(['access backend', 'authentication settings']);

        $this->post(route('admin.settings.permissions'), [
            'permissions' => ['access_backend' => 'access_backend', 'a_completely_different_set'],
        ])->assertRedirect()->assertSessionHas('status', 'error');

        $this->assertSame($original, AppConfig::where('setting', 'customer_permissions')->value('value'));
    }
}
