<?php

namespace Tests\Feature\Dashboards;

use App\Models\AppConfig;
use App\Models\Campaigns;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Design System M2 Slice 3 contract §8 item 3 — mechanically proves the
 * exact locked component adoptions (§5 item 2) are real, using each
 * component's own stable, distinguishing markup marker read directly from
 * its current source (resources/views/components/*.blade.php): card ->
 * `ds-card`; table -> `ds-table`; empty-state -> `ds-empty-state`; alert ->
 * `ds-alert`; select -> the component's own `form-select` class (native
 * raw markup used `form-control`, never `form-select`, for a <select>);
 * input -> `ds-field` + `form-control` wrapper combined with the
 * component's own auto-generated `id`; button -> the component's own
 * `btn-primary`/`transition-fast` classes (the original ai-settings Save
 * button was a bare, class-less <button>). Badge has no `ds-` marker in
 * its own source — `rounded-pill` combined with a status-variant class is
 * its distinguishing signature (the pre-existing native badge this
 * replaced used the different, non-pill `badge-success` class).
 *
 * `<x-alert>` in admin/ai_analytics.blade.php is only reachable when
 * `$campaignFilterEnabled` is false — AiAnalyticsController::index()
 * always returns `campaignFilterEnabled => true` today (unmodified,
 * read-only per the security remediation baseline), so that branch cannot
 * be exercised through any real request. That adoption is proven instead
 * by a direct raw-source check for the exact `<x-alert` tag, consistent
 * with this file's own "real adoption, not merely 200" standard.
 */
class DashboardComponentAdoptionTest extends TestCase
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

    public function test_hot_leads_card_and_empty_state_adoption_are_real(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->authenticatedCustomerWithChatBox();

        $response = $this->get('/admin/hot-leads');

        $response->assertOk();
        $response->assertSee('ds-card', false);
        $response->assertSee('ds-empty-state', false);
    }

    public function test_hot_leads_table_adoption_is_real(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->authenticatedCustomerWithChatBox();
        $this->seedHotLead($customer->user_id, '+15559990001');

        $response = $this->get('/admin/hot-leads');

        $response->assertOk();
        $response->assertSee('ds-table', false);
    }

    public function test_ai_analytics_card_select_and_empty_state_adoption_are_real(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->authenticatedCustomerWithChatBox();

        $response = $this->get('/admin/ai-analytics');

        $response->assertOk();
        $response->assertSee('ds-card', false);
        $response->assertSee('form-select', false);
        $response->assertSee('ds-empty-state', false);
    }

    public function test_ai_analytics_table_and_badge_adoption_are_real(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->authenticatedCustomerWithChatBox();
        $this->seedChatBox($customer->user_id, '+15559990002', 6);

        $response = $this->get('/admin/ai-analytics');

        $response->assertOk();
        $response->assertSee('ds-table', false);
        $response->assertSee('rounded-pill', false);
    }

    public function test_ai_analytics_alert_adoption_is_real_in_source(): void
    {
        $source = file_get_contents(resource_path('views/admin/ai_analytics.blade.php'));

        $this->assertStringContainsString('<x-alert', $source);
    }

    public function test_ai_settings_input_and_button_adoption_are_real(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->seedAiSettingsRow();
        $this->actingAsAdmin(['access backend', 'manage ai_settings']);

        $response = $this->get('/admin/ai-brain');

        $response->assertOk();
        $response->assertSee('ds-field', false);
        $response->assertSee('id="model"', false);
        $response->assertSee('btn-primary', false);
    }

    public function test_customer_dashboard_empty_state_adoption_is_real(): void
    {
        // UserController::opportunityPanel() returns null (skipping the
        // whole @if($opportunities !== null) block, empty-state included)
        // unless the Opportunity Engine is enabled — a config-only,
        // test-scoped override, not a change to any tracked file.
        config(['opportunity.enabled' => true]);

        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->createCustomer();
        $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(['access_backend'])]);
        $this->actingAs($customer->user);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertSee('ds-empty-state', false);
    }

    public function test_admin_dashboard_table_adoption_is_real(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend']);

        $response = $this->get(route('admin.home'));

        $response->assertOk();
        $response->assertSee('ds-table', false);
    }

    private function authenticatedCustomerWithChatBox(): Customer
    {
        $customer = $this->createCustomer();
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(['access_backend', 'chat_box'])]);
        $this->actingAs($customer->user);

        return $customer;
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

    private function seedHotLead(int $userId, string $to): void
    {
        DB::table('chat_boxes')->insert([
            'uid' => (string) Str::uuid(),
            'user_id' => $userId,
            'to' => $to,
            'ai_stage' => 4,
            'called' => 0,
            'website_sent_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedChatBox(int $userId, string $to, int $aiStage): void
    {
        DB::table('chat_boxes')->insert([
            'uid' => (string) Str::uuid(),
            'user_id' => $userId,
            'to' => $to,
            'ai_stage' => $aiStage,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedAiSettingsRow(string $systemPrompt = 'Original prompt', string $model = 'gpt-3.5'): void
    {
        DB::table('ai_settings')->updateOrInsert(['id' => 1], [
            'system_prompt' => $systemPrompt,
            'model' => $model,
        ]);
    }

    /**
     * §14.1-equivalent ephemeral schema fixture, reproduced per the merged
     * Slice-3 contract's own allowance (identical to the pattern already
     * merged in tests/Feature/Security/*SecurityTest).
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
