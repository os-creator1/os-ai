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
 * Dashboard Security Remediation Contract §14 item 3 — AiSettingsController.
 *
 * The ai_settings table has no migration anywhere in this repository
 * (confirmed by exhaustive search across all 213 tracked migrations and
 * the absence of any .sql/install schema file) and does not exist in the
 * disposable ultimatesms_testing database even fully migrated.
 * Assertions that need it are skipped with markTestSkipped() naming the
 * exact missing table, never fabricated or silently passed. The
 * guest/unauthorized-actor route/gate-boundary assertions never reach
 * that table and always run for real.
 */
class AiSettingsSecurityTest extends TestCase
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
        $response = $this->get('/admin/ai-brain');

        $response->assertStatus(401);
    }

    public function test_guest_post_is_blocked_with_401(): void
    {
        $response = $this->post('/admin/ai-brain', ['system_prompt' => 'x', 'model' => 'gpt']);

        $response->assertStatus(401);
    }

    public function test_ordinary_customer_with_chat_box_cannot_access(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->createCustomer();
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(['access_backend', 'chat_box'])]);
        $this->actingAs($customer->user);

        $response = $this->get('/admin/ai-brain');

        $response->assertStatus(401);
    }

    public function test_admin_lacking_manage_ai_settings_cannot_access(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend']);

        $response = $this->get('/admin/ai-brain');

        $response->assertStatus(401);
    }

    public function test_authorized_admin_with_manage_ai_settings_can_get(): void
    {
        $this->skipUnlessAiSettingsTableExists();
        $this->seedAiSettingsRow();

        $this->actingAsAdmin(['access backend', 'manage ai_settings']);

        $response = $this->get('/admin/ai-brain');

        $response->assertOk();
    }

    public function test_authorized_admin_can_post_update_and_columns_actually_change(): void
    {
        $this->skipUnlessAiSettingsTableExists();
        $this->seedAiSettingsRow();

        $this->actingAsAdmin(['access backend', 'manage ai_settings']);

        $response = $this->post('/admin/ai-brain', [
            'system_prompt' => 'Updated prompt text',
            'model' => 'gpt-4o',
        ]);

        $response->assertRedirect();
        $row = DB::table('ai_settings')->first();
        $this->assertSame('Updated prompt text', $row->system_prompt);
        $this->assertSame('gpt-4o', $row->model);
    }

    public function test_missing_system_prompt_rejected_without_mutation(): void
    {
        $this->skipUnlessAiSettingsTableExists();
        $this->seedAiSettingsRow('Original prompt', 'gpt-3.5');

        $this->actingAsAdmin(['access backend', 'manage ai_settings']);

        $response = $this->post('/admin/ai-brain', ['model' => 'gpt-4o']);

        $response->assertSessionHasErrors('system_prompt');
        $row = DB::table('ai_settings')->first();
        $this->assertSame('Original prompt', $row->system_prompt);
        $this->assertSame('gpt-3.5', $row->model);
    }

    public function test_missing_model_rejected_without_mutation(): void
    {
        $this->skipUnlessAiSettingsTableExists();
        $this->seedAiSettingsRow('Original prompt', 'gpt-3.5');

        $this->actingAsAdmin(['access backend', 'manage ai_settings']);

        $response = $this->post('/admin/ai-brain', ['system_prompt' => 'New prompt']);

        $response->assertSessionHasErrors('model');
        $row = DB::table('ai_settings')->first();
        $this->assertSame('Original prompt', $row->system_prompt);
        $this->assertSame('gpt-3.5', $row->model);
    }

    private function actingAsAdmin(array $permissions): User
    {
        $admin = User::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'admin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);

        $this->withSession(['permissions' => collect($permissions)]);
        $this->actingAs($admin);

        return $admin;
    }

    private function seedAiSettingsRow(string $systemPrompt = 'Original prompt', string $model = 'gpt-3.5'): void
    {
        DB::table('ai_settings')->insert([
            'system_prompt' => $systemPrompt,
            'model' => $model,
        ]);
    }

    private function skipUnlessAiSettingsTableExists(): void
    {
        if (! Schema::hasTable('ai_settings')) {
            $this->markTestSkipped(
                'ai_settings has no migration anywhere in this repository and does not exist in ultimatesms_testing — cannot exercise AiSettingsController against real data without fabricating schema.'
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
