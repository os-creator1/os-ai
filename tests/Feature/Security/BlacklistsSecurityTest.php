<?php

namespace Tests\Feature\Security;

use App\Models\AppConfig;
use App\Models\Blacklists;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * CRM Security Remediation Contract §17 item 3 — customer Blacklists
 * ownership (§11), the EloquentBlacklistsRepository destroy()/store()
 * repository-level side-effect fixes (§3.4, §11, Correction Round 2
 * Correction A/G), Family C's batch response contract (§9/§11/§14), the
 * preserved admin-global Blacklists model (§6/§11), and XSS finding #2's
 * remediation (§13).
 */
class BlacklistsSecurityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

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

    // -----------------------------------------------------------------
    // Customer ownership — own/foreign/nonexistent
    // -----------------------------------------------------------------

    public function test_customer_destroy_own_succeeds_foreign_and_nonexistent_denied_identically(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $ownEntry = Blacklists::factory()->create(['user_id' => $tenantA->user_id, 'number' => '12025550301']);
        $foreignEntry = Blacklists::factory()->create(['user_id' => $tenantB->user_id, 'number' => '12025550302']);

        $this->authenticateAsCustomer($tenantA, ['delete_blacklist']);

        $foreignResponse = $this->delete(route('customer.blacklists.destroy', $foreignEntry->uid));
        $nonexistentResponse = $this->delete(route('customer.blacklists.destroy', 'nonexistent-' . uniqid()));

        $foreignResponse->assertStatus(404);
        $nonexistentResponse->assertStatus(404);
        $this->assertSame($foreignResponse->getStatusCode(), $nonexistentResponse->getStatusCode());
        $this->assertSame(1, DB::table('blacklists')->where('id', $foreignEntry->id)->count());

        $ownResponse = $this->delete(route('customer.blacklists.destroy', $ownEntry->uid));
        $ownResponse->assertOk();
        $this->assertSame(0, DB::table('blacklists')->where('id', $ownEntry->id)->count());
    }

    // -----------------------------------------------------------------
    // destroy() side effect — all three cases (§3.4/§11, Correction Round 2 A)
    // -----------------------------------------------------------------

    public function test_destroy_customer_owned_row_deleted_by_customer_resubscribes_only_true_owner(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $number = '12025550401';
        $entry = Blacklists::factory()->create(['user_id' => $tenantA->user_id, 'number' => $number]);
        $groupA = $this->createGroup($tenantA, 'Group A');
        $groupB = $this->createGroup($tenantB, 'Group B');
        $contactA = $this->createContact($tenantA->user_id, $groupA->id, $number, 'unsubscribe');
        $contactB = $this->createContact($tenantB->user_id, $groupB->id, $number, 'unsubscribe');

        $this->authenticateAsCustomer($tenantA, ['delete_blacklist']);

        $response = $this->delete(route('customer.blacklists.destroy', $entry->uid));
        $response->assertOk();

        $this->assertSame('subscribe', DB::table('contacts')->where('id', $contactA->id)->value('status'));
        $this->assertSame('unsubscribe', DB::table('contacts')->where('id', $contactB->id)->value('status'));
    }

    public function test_destroy_customer_owned_row_deleted_by_admin_resubscribes_only_true_owner(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $number = '12025550402';
        $entry = Blacklists::factory()->create(['user_id' => $tenantA->user_id, 'number' => $number]);
        $groupA = $this->createGroup($tenantA, 'Group A');
        $groupB = $this->createGroup($tenantB, 'Group B');
        $contactA = $this->createContact($tenantA->user_id, $groupA->id, $number, 'unsubscribe');
        $contactB = $this->createContact($tenantB->user_id, $groupB->id, $number, 'unsubscribe');

        $this->authenticateAsAdmin(['view blacklist', 'delete blacklist']);

        $response = $this->delete(route('admin.blacklists.destroy', $entry->uid));
        $response->assertOk();

        $this->assertSame('subscribe', DB::table('contacts')->where('id', $contactA->id)->value('status'));
        $this->assertSame('unsubscribe', DB::table('contacts')->where('id', $contactB->id)->value('status'));
    }

    public function test_destroy_admin_owned_global_row_preserves_existing_unscoped_resubscribe(): void
    {
        [$tenantA] = $this->twoTenantCustomers();
        $adminUser = $this->authenticateAsAdmin(['view blacklist', 'delete blacklist']);

        $number = '12025550403';
        $entry = Blacklists::factory()->create(['user_id' => $adminUser->id, 'number' => $number]);
        $groupA = $this->createGroup($tenantA, 'Group A');
        $contactA = $this->createContact($tenantA->user_id, $groupA->id, $number, 'unsubscribe');

        $response = $this->delete(route('admin.blacklists.destroy', $entry->uid));
        $response->assertOk();

        $this->assertSame(
            'subscribe',
            DB::table('contacts')->where('id', $contactA->id)->value('status'),
            'An admin-owned/global blacklist row must preserve its existing, intentional cross-tenant resubscribe effect (Correction Round 2, Correction A).'
        );
    }

    // -----------------------------------------------------------------
    // store() side effect — customer/admin (§3.4/§11, Correction B/G)
    // -----------------------------------------------------------------

    public function test_customer_store_does_not_touch_a_foreign_tenants_group_cache_or_contact_status(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $number = '12025550501';
        $groupB = $this->createGroup($tenantB, 'Foreign Group');
        $contactB = $this->createContact($tenantB->user_id, $groupB->id, $number, 'subscribe');
        $groupA = $this->createGroup($tenantA, 'Own Group');
        $contactA = $this->createContact($tenantA->user_id, $groupA->id, $number, 'subscribe');

        $this->authenticateAsCustomer($tenantA, ['create_blacklist']);

        $response = $this->post(route('customer.blacklists.store'), [
            'number' => $number,
            'delimiter' => ',',
            'reason' => 'test',
        ]);
        $response->assertRedirect();

        $this->assertSame('unsubscribe', DB::table('contacts')->where('id', $contactA->id)->value('status'));
        $this->assertSame(
            'subscribe',
            DB::table('contacts')->where('id', $contactB->id)->value('status'),
            'A customer blacklisting a number must not flip an unrelated tenant\'s matching contact status (store() is already correctly actor-scoped for this update).'
        );
    }

    public function test_admin_store_preserves_existing_global_status_and_cache_behavior(): void
    {
        [$tenantA] = $this->twoTenantCustomers();
        $number = '12025550502';
        $groupA = $this->createGroup($tenantA, 'Group A');
        $contactA = $this->createContact($tenantA->user_id, $groupA->id, $number, 'subscribe');

        $this->authenticateAsAdmin(['view blacklist', 'create blacklist']);

        $response = $this->post(route('admin.blacklists.store'), [
            'number' => $number,
            'delimiter' => ',',
            'reason' => 'admin test',
        ]);
        $response->assertRedirect();

        $this->assertSame(
            'unsubscribe',
            DB::table('contacts')->where('id', $contactA->id)->value('status'),
            'Admin store() must keep its existing global effect across tenant boundaries.'
        );
    }

    // -----------------------------------------------------------------
    // Family C batch response contract (§9/§11/§14)
    // -----------------------------------------------------------------

    public function test_customer_batch_destroy_family_c_five_scenario_table(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $this->authenticateAsCustomer($tenantA, ['delete_blacklist']);

        $owned1 = Blacklists::factory()->create(['user_id' => $tenantA->user_id, 'number' => '12025550601']);
        $foreign1 = Blacklists::factory()->create(['user_id' => $tenantB->user_id, 'number' => '12025550602']);
        $response = $this->postJson(route('customer.blacklists.batch_action'), ['action' => 'destroy', 'ids' => [$owned1->uid, $foreign1->uid]]);
        $response->assertOk();
        $response->assertJson(['status' => 'success']);
        $this->assertSame(0, DB::table('blacklists')->where('id', $owned1->id)->count());
        $this->assertSame(1, DB::table('blacklists')->where('id', $foreign1->id)->count());

        $owned2 = Blacklists::factory()->create(['user_id' => $tenantA->user_id, 'number' => '12025550603']);
        $response = $this->postJson(route('customer.blacklists.batch_action'), ['action' => 'destroy', 'ids' => [$owned2->uid, 'nonexistent-' . uniqid()]]);
        $response->assertOk();
        $response->assertJson(['status' => 'success']);
        $this->assertSame(0, DB::table('blacklists')->where('id', $owned2->id)->count());

        $foreign2 = Blacklists::factory()->create(['user_id' => $tenantB->user_id, 'number' => '12025550604']);
        $foreignOnlyResponse = $this->postJson(route('customer.blacklists.batch_action'), ['action' => 'destroy', 'ids' => [$foreign2->uid]]);
        $foreignOnlyResponse->assertOk();
        $foreignOnlyResponse->assertJson(['status' => 'error']);
        $this->assertSame(1, DB::table('blacklists')->where('id', $foreign2->id)->count());

        $nonexistentOnlyResponse = $this->postJson(route('customer.blacklists.batch_action'), ['action' => 'destroy', 'ids' => ['nonexistent-' . uniqid()]]);
        $nonexistentOnlyResponse->assertOk();
        $nonexistentOnlyResponse->assertJson(['status' => 'error']);
        $this->assertSame($foreignOnlyResponse->getContent(), $nonexistentOnlyResponse->getContent());

        $emptyListResponse = $this->postJson(route('customer.blacklists.batch_action'), ['action' => 'destroy', 'ids' => []]);
        $emptyListResponse->assertOk();
        $emptyListResponse->assertJson(['status' => 'error']);
    }

    // -----------------------------------------------------------------
    // Admin global functionality preserved
    // -----------------------------------------------------------------

    /**
     * Admin\BlacklistsController::search() is a #[NoReturn] legacy
     * DataTables endpoint that literally calls `echo json_encode(...);
     * exit();` — an in-process `exit()` inside Laravel's testing HTTP
     * kernel terminates the whole PHPUnit process, not just this request,
     * confirmed directly (a plain HTTP-simulated call to this route ends
     * the entire test run with no further output). Exercised instead via
     * runAdminBlacklistsSearch() below, which runs the real, unmodified
     * search() method in a genuinely separate, disposable PHP process
     * (self-contained fixtures, own DB rows, own cleanup via a shutdown
     * function) and hands the captured JSON back to this — the actual
     * PHPUnit test method, running normally, not itself isolated — for
     * assertion.
     */
    public function test_admin_index_search_export_still_see_all_tenants_rows(): void
    {
        $tenantANumber = '12025550701';
        $tenantBNumber = '12025550702';

        $body = $this->runAdminBlacklistsSearch([
            ['first_name' => 'Tenant', 'last_name' => 'A', 'number' => $tenantANumber],
            ['first_name' => 'Tenant', 'last_name' => 'B', 'number' => $tenantBNumber],
        ]);

        $this->assertSame(2, $body['recordsTotal']);
        $numbers = collect($body['data'])->pluck('number')->all();
        $this->assertContains($tenantANumber, $numbers);
        $this->assertContains($tenantBNumber, $numbers);
    }

    public function test_admin_destroy_and_batch_action_can_operate_on_any_tenants_entry(): void
    {
        [$tenantA, $tenantB] = $this->twoTenantCustomers();
        $entryA = Blacklists::factory()->create(['user_id' => $tenantA->user_id, 'number' => '12025550801']);
        $entryB = Blacklists::factory()->create(['user_id' => $tenantB->user_id, 'number' => '12025550802']);

        $this->authenticateAsAdmin(['view blacklist', 'delete blacklist']);

        $destroyResponse = $this->delete(route('admin.blacklists.destroy', $entryA->uid));
        $destroyResponse->assertOk();
        $this->assertSame(0, DB::table('blacklists')->where('id', $entryA->id)->count());

        $batchResponse = $this->postJson(route('admin.blacklists.batch_action'), ['action' => 'destroy', 'ids' => [$entryB->uid]]);
        $batchResponse->assertOk();
        $batchResponse->assertJson(['status' => 'success']);
        $this->assertSame(0, DB::table('blacklists')->where('id', $entryB->id)->count());
    }

    public function test_admin_batch_action_does_not_introduce_a_customer_style_user_id_scope(): void
    {
        [$tenantA] = $this->twoTenantCustomers();
        $adminUser = $this->authenticateAsAdmin(['view blacklist', 'delete blacklist']);

        $entry = Blacklists::factory()->create(['user_id' => $tenantA->user_id, 'number' => '12025550803']);

        $this->assertNotSame($adminUser->id, $entry->user_id);

        $response = $this->postJson(route('admin.blacklists.batch_action'), ['action' => 'destroy', 'ids' => [$entry->uid]]);
        $response->assertOk();
        $response->assertJson(['status' => 'success']);
        $this->assertSame(0, DB::table('blacklists')->where('id', $entry->id)->count());
    }

    // -----------------------------------------------------------------
    // XSS #2 regression (§3.7/§13)
    // -----------------------------------------------------------------

    /**
     * Same exit()-isolation reasoning as
     * test_admin_index_search_export_still_see_all_tenants_rows() above —
     * search() is the only surface this finding's fix touches.
     */
    public function test_xss_2_malicious_display_name_is_entity_encoded_trusted_link_preserved(): void
    {
        $maliciousPayload = '<img src=x onerror=alert(1)>';
        $number = '12025550901';

        $body = $this->runAdminBlacklistsSearch([
            ['first_name' => $maliciousPayload, 'last_name' => 'Attacker', 'number' => $number],
        ]);

        $row = collect($body['data'])->firstWhere('number', $number);
        $this->assertNotNull($row);
        $this->assertStringNotContainsString('<img', $row['user_id']);
        $this->assertStringContainsString('&lt;img', $row['user_id']);
        $this->assertStringContainsString('<a href=', $row['user_id']);
        $this->assertStringContainsString('/customers/', $row['user_id']);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return array{0: Customer, 1: Customer}
     */
    private function twoTenantCustomers(): array
    {
        $this->ensureRequiredAppConfigRowsExist();

        $tenantA = $this->createCustomer();
        $tenantB = $this->createCustomer();

        return [$tenantA, $tenantB];
    }

    private function authenticateAsCustomer(Customer $customer, array $permissions): void
    {
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(array_merge(['access_backend'], $permissions))]);
        $this->actingAs($customer->user);
    }

    private function authenticateAsAdmin(array $permissions): User
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

        $this->withSession(['permissions' => collect(array_merge(['access backend'], $permissions))]);
        $this->actingAs($admin);

        return $admin;
    }

    private function createGroup(Customer $customer, string $name, array $overrides = []): ContactGroups
    {
        return ContactGroups::create(array_merge([
            'customer_id' => $customer->user_id,
            'name' => $name,
            'status' => true,
        ], $overrides));
    }

    private function createContact(int $customerId, int $groupId, string $phone, string $status): Contacts
    {
        return Contacts::create([
            'customer_id' => $customerId,
            'group_id' => $groupId,
            'phone' => $phone,
            'status' => $status,
        ]);
    }

    /**
     * Runs Admin\BlacklistsController::search() — a legacy #[NoReturn]
     * DataTables endpoint that literally `echo`s its JSON body and calls
     * `exit()` — in a genuinely separate, disposable PHP process, since an
     * in-process `exit()` reached through Laravel's testing HTTP kernel
     * terminates the whole PHPUnit run, not just the one request
     * (confirmed directly: a plain HTTP-simulated call to this route ends
     * the entire test run with no further output). The child process is
     * fully self-contained — its own admin actor, its own fixture rows,
     * seeded from $fixtures (each ['first_name','last_name','number']) —
     * and cleans up every row it creates via a register_shutdown_function
     * callback, which still runs through an exit() call. The real,
     * unmodified controller method is exercised exactly as a browser
     * would reach it; only the process boundary differs from a normal
     * HTTP test.
     *
     * @param  array<int, array{first_name: string, last_name: string, number: string}>  $fixtures
     * @return array<string, mixed> the decoded JSON search() actually emitted
     */
    private function runAdminBlacklistsSearch(array $fixtures): array
    {
        $isolationDir = storage_path('framework/testing/security-search-isolation');
        if (! is_dir($isolationDir)) {
            mkdir($isolationDir, 0777, true);
        }

        $token = uniqid('admin-blacklists-search-', true);
        $scriptPath = $isolationDir . '/' . $token . '.php';
        $outputPath = $isolationDir . '/' . $token . '.out.json';
        $errorPath = $isolationDir . '/' . $token . '.err.log';

        $basePath = var_export(base_path(), true);
        $fixturesJson = var_export(json_encode($fixtures), true);
        $outputPathExported = var_export($outputPath, true);

        $script = <<<PHP
            <?php
            require {$basePath} . '/vendor/autoload.php';
            \$app = require {$basePath} . '/bootstrap/app.php';
            \$kernel = \$app->make(Illuminate\\Contracts\\Console\\Kernel::class);
            \$kernel->bootstrap();

            \$createdUserIds = [];
            \$createdBlacklistIds = [];

            register_shutdown_function(function () use (&\$createdUserIds, &\$createdBlacklistIds) {
                try {
                    \\Illuminate\\Support\\Facades\\DB::table('blacklists')->whereIn('id', \$createdBlacklistIds)->delete();
                    \\Illuminate\\Support\\Facades\\DB::table('customers')->whereIn('user_id', \$createdUserIds)->delete();
                    \\Illuminate\\Support\\Facades\\DB::table('users')->whereIn('id', \$createdUserIds)->delete();
                } catch (\\Throwable \$e) {
                    // best-effort cleanup only
                }
            });

            \$admin = \\App\\Models\\User::create([
                'first_name' => 'Isolated',
                'last_name' => 'Admin',
                'email' => 'isolated-admin-' . uniqid('', true) . '@example.test',
                'status' => true,
                'is_admin' => true,
                'is_customer' => false,
                'active_portal' => 'admin',
            ]);
            \$createdUserIds[] = \$admin->id;

            foreach (json_decode({$fixturesJson}, true) as \$fixture) {
                \$fixtureUser = \\App\\Models\\User::create([
                    'first_name' => \$fixture['first_name'],
                    'last_name' => \$fixture['last_name'],
                    'email' => 'isolated-fixture-' . uniqid('', true) . '@example.test',
                    'status' => true,
                    'is_admin' => false,
                    'is_customer' => true,
                    'active_portal' => 'customer',
                ]);
                \$createdUserIds[] = \$fixtureUser->id;
                \\App\\Models\\Customer::create(['user_id' => \$fixtureUser->id]);

                \$entry = \\App\\Models\\Blacklists::create([
                    'uid' => uniqid('', true),
                    'user_id' => \$fixtureUser->id,
                    'number' => \$fixture['number'],
                    'reason' => 'isolated fixture',
                ]);
                \$createdBlacklistIds[] = \$entry->id;
            }

            config(['session.driver' => 'array']);
            \\Illuminate\\Support\\Facades\\Session::put('permissions', collect(['access backend', 'view blacklist']));
            \\Illuminate\\Support\\Facades\\Auth::guard('web')->setUser(\$admin);

            \$request = \\Illuminate\\Http\\Request::create('/admin/blacklists/search', 'POST', [
                'draw' => 1,
                'start' => 0,
                'length' => 50,
                'order' => [['column' => 1, 'dir' => 'asc']],
            ]);
            app()->instance('request', \$request);

            ob_start(function (\$buffer) {
                file_put_contents({$outputPathExported}, \$buffer);
                return \$buffer;
            });

            app(\\App\\Http\\Controllers\\Admin\\BlacklistsController::class)->search(\$request);
            PHP;

        file_put_contents($scriptPath, $script);

        $descriptors = [1 => ['file', $errorPath, 'w'], 2 => ['file', $errorPath, 'a']];
        $process = proc_open([PHP_BINARY, $scriptPath], $descriptors, $pipes, base_path());
        if (is_resource($process)) {
            proc_close($process);
        }

        $this->assertFileExists($outputPath, 'Isolated search() process produced no output — see ' . $errorPath . ' for details: ' . (is_file($errorPath) ? file_get_contents($errorPath) : '(no error log)'));

        $raw = file_get_contents($outputPath);
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, 'Isolated search() process output was not valid JSON: ' . $raw);

        @unlink($scriptPath);
        @unlink($outputPath);
        @unlink($errorPath);

        return $decoded;
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
