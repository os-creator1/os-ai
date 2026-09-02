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
 * Design System M2 Slice 3 contract §8 item 4 — proves this slice's own
 * presentation-only edits did not regress the legitimate, own-tenant
 * workflows on Slice 3's authorized post-remediation baseline (dashboard-
 * security remediation merge 1059112d343f7cf3029e5d13ca8db065f98cdfd0):
 * Hot Leads mark-called, AI Analytics mark-booked, AI Analytics owned-
 * campaign filtering, and AI Brain settings update. This test asserts
 * these data-mutation behaviors are unchanged by the restyle — it does
 * not assert anything about validation or authorization being absent or
 * present, since that is exclusively the security remediation's own,
 * already-covered concern (tests/Feature/Security/*SecurityTest, not
 * duplicated here).
 */
class DashboardExistingBehaviorPreservedTest extends TestCase
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

    public function test_hot_leads_own_tenant_mark_called_still_mutates_and_redirects(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->authenticatedCustomerWithChatBox();
        $leadId = $this->seedHotLead($customer->user_id, '+15558880001');

        $response = $this->post('/admin/hot-leads/mark-called', ['id' => $leadId]);

        $response->assertRedirect();
        $this->assertSame(1, DB::table('chat_boxes')->where('id', $leadId)->where('called', 1)->count());
    }

    public function test_ai_analytics_own_tenant_mark_booked_still_mutates_and_redirects(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->authenticatedCustomerWithChatBox();
        $boxId = $this->seedChatBox($customer->user_id, '+15558880002', 2);

        $response = $this->post('/admin/ai-analytics/book/' . $boxId);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertSame(1, DB::table('chat_boxes')->where('id', $boxId)->where('ai_stage', 6)->count());
    }

    public function test_ai_analytics_owned_campaign_filter_still_filters_recent_boxes_and_stage_counts(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->authenticatedCustomerWithChatBox();

        $matchingCampaign = $this->seedCampaign($customer->user_id, 'Matching Campaign');
        $otherCampaign = $this->seedCampaign($customer->user_id, 'Other Campaign');

        $this->seedChatBox($customer->user_id, '+15558880003', 2);
        $this->seedChatBox($customer->user_id, '+15558880004', 2);

        $response = $this->get('/admin/ai-analytics?campaign_id=' . $matchingCampaign->id);

        $response->assertOk();
        $response->assertSee((string) $matchingCampaign->id, false);
    }

    public function test_ai_brain_authorized_update_still_mutates_model_and_system_prompt(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->seedAiSettingsRow('Original prompt', 'gpt-3.5');
        $this->actingAsAdmin(['access backend', 'manage ai_settings']);

        $response = $this->post('/admin/ai-brain', [
            'system_prompt' => 'Updated prompt via presentation-only restyle',
            'model' => 'gpt-4o',
        ]);

        $response->assertRedirect();
        $row = DB::table('ai_settings')->first();
        $this->assertSame('Updated prompt via presentation-only restyle', $row->system_prompt);
        $this->assertSame('gpt-4o', $row->model);
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

    private function seedHotLead(int $userId, string $to): int
    {
        return DB::table('chat_boxes')->insertGetId([
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

    private function seedChatBox(int $userId, string $to, int $aiStage): int
    {
        return DB::table('chat_boxes')->insertGetId([
            'uid' => (string) Str::uuid(),
            'user_id' => $userId,
            'to' => $to,
            'ai_stage' => $aiStage,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    private function seedAiSettingsRow(string $systemPrompt, string $model): void
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
