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
 * §14.1 (Correction Round 2) — chat_boxes.ai_stage/followup_sent/
 * followup_at/ai_replied and the entire ai_box_campaign_map table have
 * no migration anywhere in this repository (confirmed by exhaustive
 * search) and are therefore absent from a freshly, fully migrated
 * ultimatesms_testing database. Rather than skip the assertions that
 * depend on them, this file adds them itself, test-only, via a separate
 * named DB connection (`security_test_ddl`, pointing at the identical
 * `mysql` config) so the DDL never touches the default connection's
 * RefreshDatabase-managed transaction. chat_boxes.reply_by_customer
 * already exists (migrated) and is reused as-is, never redefined.
 * chat_boxes.user_id and campaigns.user_id (the only columns this
 * remediation's own tenant-scoping logic depends on) are real, migrated
 * schema and are never touched here.
 */
class AiAnalyticsSecurityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    /**
     * Columns/table this test class itself added this run, so the
     * process-shutdown cleanup drops only what it created. Static and
     * ensured exactly once per run (not once per test method) — repeated
     * ALTER TABLE calls across every test method were measured to make
     * the suite hang under this environment's MySQL/Windows I/O, so the
     * ephemeral schema is created once, reused by every test method in
     * this class, and dropped once, at true PHP process shutdown, via a
     * raw PDO connection captured while the app is still available
     * (register_shutdown_function callbacks cannot rely on Laravel's
     * container still being alive).
     *
     * @var string[]
     */
    private static array $addedChatBoxColumns = [];

    private static bool $createdAiBoxCampaignMapTable = false;

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

        $this->ensureEphemeralSchema();

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
        [$tenantA] = $this->twoTenantCustomers();
        $this->authenticateAsCustomerWithChatBox($tenantA);

        $response = $this->get('/admin/ai-analytics');

        $response->assertOk();
    }

    public function test_stage_counts_and_recent_boxes_contain_tenant_a_only_tenant_b_absent(): void
    {
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
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $boxB = $this->seedChatBox($tenantB->user_id, '+15551110005', 2);

        $this->authenticateAsCustomerWithChatBox($tenantA);

        $response = $this->post('/admin/ai-analytics/book/' . $boxB->id);

        $response->assertNotFound();
        $this->assertSame(0, DB::table('chat_boxes')->where('id', $boxB->id)->where('ai_stage', 6)->count());
    }

    public function test_mark_booked_nonexistent_id_returns_404_no_mutation(): void
    {
        [$tenantA] = $this->twoTenantCustomers();
        $this->authenticateAsCustomerWithChatBox($tenantA);

        $response = $this->post('/admin/ai-analytics/book/999999999');

        $response->assertNotFound();
    }

    public function test_mark_booked_own_row_succeeds_with_existing_redirect_behavior(): void
    {
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
     * Adds only the specific legacy chat_boxes columns AiAnalyticsController
     * actually reads/writes, and creates only the box_id/campaign_id
     * columns the existing ->leftJoin('ai_box_campaign_map as map', ...)
     * /->where('map.campaign_id', ...) calls already require — each only
     * when genuinely absent, once for the whole class run. Shape is
     * inferred solely from the controller's own existing usage
     * (index(): ai_stage grouped/filtered as a small integer; markBooked():
     * 'ai_stage' => 6, 'followup_sent' => 1, 'followup_at' => null,
     * 'reply_by_customer' => 0 — already migrated, reused as-is, not
     * redefined — 'ai_replied' => 1). chat_boxes.user_id and
     * campaigns.user_id are real, migrated schema and are never touched.
     * Cleanup runs once, at true PHP process shutdown, via a raw PDO
     * connection captured while the app is still available.
     */
    private function ensureEphemeralSchema(): void
    {
        if (self::$ephemeralSchemaEnsured) {
            return;
        }

        self::$ephemeralSchemaEnsured = true;

        $schema = $this->ephemeralSchema();

        $columns = [
            'ai_stage' => fn ($table) => $table->unsignedTinyInteger('ai_stage')->default(0),
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

        if (self::$addedChatBoxColumns === [] && ! self::$createdAiBoxCampaignMapTable) {
            return;
        }

        $columnsToDrop = self::$addedChatBoxColumns;
        $dropCampaignMapTable = self::$createdAiBoxCampaignMapTable;
        $dsn = 'mysql:host=' . config('database.connections.mysql.host')
            . ';port=' . config('database.connections.mysql.port')
            . ';dbname=' . config('database.connections.mysql.database')
            . ';charset=' . config('database.connections.mysql.charset', 'utf8mb4');
        $username = config('database.connections.mysql.username');
        $password = config('database.connections.mysql.password');

        register_shutdown_function(function () use ($dsn, $username, $password, $columnsToDrop, $dropCampaignMapTable) {
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
