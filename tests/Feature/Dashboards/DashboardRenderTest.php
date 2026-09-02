<?php

namespace Tests\Feature\Dashboards;

use App\Models\AppConfig;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Design System M2 Slice 3 contract §8 item 1 — proves the actor genuinely
 * authorized on this Slice 3 implementation's own pinned post-remediation
 * baseline (dashboard-security remediation merge
 * 1059112d343f7cf3029e5d13ca8db065f98cdfd0) receives HTTP 200 from all five
 * in-scope routes. The actor model is read directly from the merged
 * security remediation, not re-invented: Hot Leads/AI Analytics require an
 * authenticated customer holding `chat_box`; AI Brain requires an
 * authenticated admin holding `manage ai_settings`; user.home/admin.home
 * are unchanged by the remediation (§17 of that contract), reached by an
 * ordinary customer/admin respectively.
 *
 * chat_boxes.ai_stage/called/website_sent_at/followup_sent/followup_at/
 * ai_replied and the ai_settings/ai_box_campaign_map tables have no
 * tracked migration (established by the merged security remediation's own
 * audit) and are absent from a freshly migrated ultimatesms_testing. This
 * file reproduces the same test-only, self-cleaning ephemeral-schema
 * fixture approach already merged in tests/Feature/Security/*SecurityTest
 * — created once per class via a separate `security_test_ddl` connection
 * so its DDL never touches the default connection's RefreshDatabase
 * transaction, and dropped once at true PHP process shutdown.
 */
class DashboardRenderTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private static array $addedChatBoxColumns = [];
    private static bool $createdAiBoxCampaignMapTable = false;
    private static bool $createdAiSettingsTable = false;
    private static bool $ephemeralSchemaEnsured = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureEphemeralSchema();

        // Consume users.id === 1 (repository-wide inherited super-admin Gate
        // bypass, EloquentAccountRepository::hasPermission()) so this file's
        // own actors are never accidentally granted every permission by
        // landing on the first auto-increment id.
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

    public function test_customer_home_returns_200_and_retains_sms_reports_id(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->createCustomer();
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(['access_backend'])]);
        $this->actingAs($customer->user);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertSee('id="sms-reports"', false);
    }

    public function test_admin_home_returns_200(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend']);

        $response = $this->get(route('admin.home'));

        $response->assertOk();
    }

    public function test_hot_leads_returns_200_for_authorized_customer(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->createCustomer();
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(['access_backend', 'chat_box'])]);
        $this->actingAs($customer->user);

        $response = $this->get('/admin/hot-leads');

        $response->assertOk();
    }

    public function test_ai_analytics_returns_200_for_authorized_customer(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->createCustomer();
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(['access_backend', 'chat_box'])]);
        $this->actingAs($customer->user);

        $response = $this->get('/admin/ai-analytics');

        $response->assertOk();
    }

    public function test_ai_brain_returns_200_for_authorized_admin(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->seedAiSettingsRow();
        $this->actingAsAdmin(['access backend', 'manage ai_settings']);

        $response = $this->get('/admin/ai-brain');

        $response->assertOk();
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
        DB::table('ai_settings')->updateOrInsert(['id' => 1], [
            'system_prompt' => $systemPrompt,
            'model' => $model,
        ]);
    }

    /**
     * §14.1-equivalent ephemeral schema fixture, reproduced here per the
     * merged Slice-3 contract's own "may reproduce the established,
     * self-cleaning test-only fixture approach" allowance. Runs on a
     * separate named connection so its DDL never interacts with the
     * default connection's RefreshDatabase transaction. Adds only the
     * specific legacy chat_boxes columns / ai_box_campaign_map /
     * ai_settings columns the existing, unmodified controllers actually
     * read/write, only when genuinely absent, once for the whole class
     * run. Cleanup runs once, at true PHP process shutdown, via a raw PDO
     * connection captured while the app is still available.
     */
    private function ephemeralSchema(): \Illuminate\Database\Schema\Builder
    {
        config(['database.connections.security_test_ddl' => config('database.connections.mysql')]);

        return Schema::connection('security_test_ddl');
    }

    private function ensureEphemeralSchema(): void
    {
        if (self::$ephemeralSchemaEnsured) {
            return;
        }

        self::$ephemeralSchemaEnsured = true;

        $schema = $this->ephemeralSchema();

        $columns = [
            'ai_stage' => fn ($table) => $table->unsignedTinyInteger('ai_stage')->default(0),
            'called' => fn ($table) => $table->boolean('called')->default(false),
            'website_sent_at' => fn ($table) => $table->timestamp('website_sent_at')->nullable(),
            'followup_sent' => fn ($table) => $table->boolean('followup_sent')->default(false),
            'followup_at' => fn ($table) => $table->timestamp('followup_at')->nullable(),
            'ai_replied' => fn ($table) => $table->boolean('ai_replied')->default(false),
        ];

        foreach ($columns as $column => $definer) {
            if (! $schema->hasColumn('chat_boxes', $column)) {
                $schema->table('chat_boxes', function ($table) use ($definer) {
                    $definer($table);
                });
                self::$addedChatBoxColumns[] = $column;
            }
        }

        if (! $schema->hasTable('ai_box_campaign_map')) {
            $schema->create('ai_box_campaign_map', function ($table) {
                $table->id();
                $table->unsignedBigInteger('box_id');
                $table->unsignedBigInteger('campaign_id');
            });
            self::$createdAiBoxCampaignMapTable = true;
        }

        if (! $schema->hasTable('ai_settings')) {
            $schema->create('ai_settings', function ($table) {
                $table->id();
                $table->text('system_prompt')->nullable();
                $table->string('model')->nullable();
            });
            self::$createdAiSettingsTable = true;
        }

        if (self::$addedChatBoxColumns === [] && ! self::$createdAiBoxCampaignMapTable && ! self::$createdAiSettingsTable) {
            return;
        }

        $columnsToDrop = self::$addedChatBoxColumns;
        $dropCampaignMapTable = self::$createdAiBoxCampaignMapTable;
        $dropAiSettingsTable = self::$createdAiSettingsTable;
        $dsn = 'mysql:host=' . config('database.connections.mysql.host')
            . ';port=' . config('database.connections.mysql.port')
            . ';dbname=' . config('database.connections.mysql.database')
            . ';charset=' . config('database.connections.mysql.charset', 'utf8mb4');
        $username = config('database.connections.mysql.username');
        $password = config('database.connections.mysql.password');

        register_shutdown_function(function () use ($dsn, $username, $password, $columnsToDrop, $dropCampaignMapTable, $dropAiSettingsTable) {
            try {
                $pdo = new \PDO($dsn, $username, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

                foreach ($columnsToDrop as $column) {
                    $exists = $pdo->query(
                        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'chat_boxes' AND column_name = " . $pdo->quote($column)
                    )->fetchColumn();
                    if ($exists) {
                        $pdo->exec('ALTER TABLE `chat_boxes` DROP COLUMN `' . $column . '`');
                    }
                }

                if ($dropCampaignMapTable) {
                    $pdo->exec('DROP TABLE IF EXISTS `ai_box_campaign_map`');
                }

                if ($dropAiSettingsTable) {
                    $pdo->exec('DROP TABLE IF EXISTS `ai_settings`');
                }
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
