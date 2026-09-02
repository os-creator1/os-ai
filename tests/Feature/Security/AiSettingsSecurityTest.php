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
 * §14.1 (Correction Round 2) — the ai_settings table has no migration
 * anywhere in this repository (confirmed by exhaustive search across all
 * 213 tracked migrations and the absence of any .sql/install schema
 * file) and does not exist in a freshly, fully migrated
 * ultimatesms_testing database. Rather than skip the assertions that
 * depend on it, this file creates it itself, test-only, via a separate
 * named DB connection (`security_test_ddl`, pointing at the identical
 * `mysql` config) so the DDL never touches the default connection's
 * RefreshDatabase-managed transaction — with only the system_prompt/
 * model columns AiSettingsController::index()/save() already read and
 * write, plus the technically-necessary auto-increment primary key. If
 * the table already exists, it is used as-is and never replaced.
 */
class AiSettingsSecurityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    /**
     * Whether this test class itself created the ai_settings table this
     * run, so the process-shutdown cleanup drops it only if so. Static
     * and ensured exactly once per run — see ensureEphemeralAiSettingsTable().
     */
    private static bool $createdAiSettingsTable = false;

    private static bool $ephemeralSchemaEnsured = false;

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

        $this->ensureEphemeralAiSettingsTable();

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
        $this->seedAiSettingsRow();

        $this->actingAsAdmin(['access backend', 'manage ai_settings']);

        $response = $this->get('/admin/ai-brain');

        $response->assertOk();
    }

    public function test_authorized_admin_can_post_update_and_columns_actually_change(): void
    {
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
        $this->ensureRequiredAppConfigRowsExist();

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

    /**
     * §14.1 — test-only ephemeral schema fixture. Runs on a separate named
     * connection so its DDL (which MySQL always auto-commits) never
     * interacts with the default connection's RefreshDatabase transaction.
     */
    private function ephemeralSchema(): \Illuminate\Database\Schema\Builder
    {
        config(['database.connections.security_test_ddl' => config('database.connections.mysql')]);

        return Schema::connection('security_test_ddl');
    }

    /**
     * Creates only the system_prompt/model columns AiSettingsController's
     * own existing, unmodified index()/save() already read and write
     * (plus the technically-necessary auto-increment id), and only when
     * the table is genuinely absent, once for the whole class run. If it
     * already exists, it is used as-is — never replaced or redefined. No
     * tenant-ownership column is added, matching §3.4's audited
     * global-row model. Cleanup runs once, at true PHP process shutdown,
     * via a raw PDO connection captured while the app is still available
     * (register_shutdown_function callbacks cannot rely on Laravel's
     * container still being alive) — repeated per-test DDL was measured
     * to make the suite hang under this environment's MySQL/Windows I/O.
     */
    private function ensureEphemeralAiSettingsTable(): void
    {
        if (self::$ephemeralSchemaEnsured) {
            return;
        }

        self::$ephemeralSchemaEnsured = true;

        $schema = $this->ephemeralSchema();

        if ($schema->hasTable('ai_settings')) {
            return;
        }

        $schema->create('ai_settings', function ($table) {
            $table->id();
            $table->text('system_prompt')->nullable();
            $table->string('model')->nullable();
        });

        self::$createdAiSettingsTable = true;

        $dsn = 'mysql:host=' . config('database.connections.mysql.host')
            . ';port=' . config('database.connections.mysql.port')
            . ';dbname=' . config('database.connections.mysql.database')
            . ';charset=' . config('database.connections.mysql.charset', 'utf8mb4');
        $username = config('database.connections.mysql.username');
        $password = config('database.connections.mysql.password');

        register_shutdown_function(function () use ($dsn, $username, $password) {
            try {
                $pdo = new \PDO($dsn, $username, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
                $pdo->exec('DROP TABLE IF EXISTS `ai_settings`');
            } catch (\Throwable $e) {
                // Best-effort cleanup at process shutdown; nothing further can be reported here.
            }
        });
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
