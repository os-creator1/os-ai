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
 * §14.1 (Correction Round 2) — chat_boxes.ai_stage/called/website_sent_at
 * have no migration anywhere in this repository (confirmed by exhaustive
 * content grep across all 213 tracked migrations) and are therefore
 * absent from a freshly, fully migrated ultimatesms_testing database.
 * Rather than skip the assertions that depend on them, this file adds
 * them itself, test-only, via a separate named DB connection
 * (`security_test_ddl`, pointing at the identical `mysql` config) so the
 * DDL never touches the default connection's RefreshDatabase-managed
 * transaction. Each column is added only if absent (once for the whole
 * class run) and dropped again at true process shutdown only if this
 * run added it — an already-existing column is always left untouched.
 * chat_boxes.user_id (the one column this
 * remediation's own tenant-scoping logic actually depends on) is real,
 * migrated schema and is never touched here.
 */
class HotLeadsSecurityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    /**
     * Columns this test class itself added to chat_boxes this run, so the
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

        $this->ensureEphemeralChatBoxColumns();

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
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $leadB = $this->seedHotLead($tenantB->user_id, '+15550000003');

        $this->authenticateAsCustomerWithChatBox($tenantA);

        $response = $this->post('/admin/hot-leads/mark-called', ['id' => $leadB->id]);

        $response->assertNotFound();
        $this->assertSame(0, DB::table('chat_boxes')->where('id', $leadB->id)->where('called', 1)->count());
    }

    public function test_mark_called_nonexistent_id_returns_404_with_the_same_response_shape_as_cross_tenant(): void
    {
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

    /**
     * §14.1 — test-only ephemeral schema fixture. Runs on a separate named
     * connection so its DDL (which MySQL always auto-commits) never
     * interacts with the default connection's RefreshDatabase transaction.
     * Adds only the specific legacy chat_boxes columns HotLeadController
     * actually reads/writes, and only when genuinely absent; shape is
     * inferred solely from that controller's own existing usage
     * (index(): where('ai_stage', 4), where('called', 0),
     * orderByDesc('website_sent_at'); markCalled(): update(['called' => 1])).
     * chat_boxes.user_id is real, migrated schema and is never touched.
     */
    private function ephemeralSchema(): \Illuminate\Database\Schema\Builder
    {
        config(['database.connections.security_test_ddl' => config('database.connections.mysql')]);

        return Schema::connection('security_test_ddl');
    }

    private function ensureEphemeralChatBoxColumns(): void
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
        ];

        foreach ($columns as $column => $definer) {
            if (! $schema->hasColumn('chat_boxes', $column)) {
                $schema->table('chat_boxes', function ($table) use ($definer) {
                    $definer($table);
                });
                self::$addedChatBoxColumns[] = $column;
            }
        }

        if (self::$addedChatBoxColumns === []) {
            return;
        }

        $columnsToDrop = self::$addedChatBoxColumns;
        $dsn = 'mysql:host=' . config('database.connections.mysql.host')
            . ';port=' . config('database.connections.mysql.port')
            . ';dbname=' . config('database.connections.mysql.database')
            . ';charset=' . config('database.connections.mysql.charset', 'utf8mb4');
        $username = config('database.connections.mysql.username');
        $password = config('database.connections.mysql.password');

        register_shutdown_function(function () use ($dsn, $username, $password, $columnsToDrop) {
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
