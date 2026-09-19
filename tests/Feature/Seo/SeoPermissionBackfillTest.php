<?php

namespace Tests\Feature\Seo;

use App\Models\AppConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Contract 18 §10.2 — customer permissions are persisted per customer as a
 * JSON list, so a config key grants nothing to an existing customer. The
 * backfill migration adds `view_seo` and `manage_seo` (both default true) and
 * NEVER `manage_search_console` (default false, credential-class).
 */
class SeoPermissionBackfillTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private const MIGRATION = '2026_09_24_100001_backfill_seo_view_and_manage_permissions.php';

    private function runBackfill(): void
    {
        $migration = require database_path('migrations/' . self::MIGRATION);
        $migration->up();
    }

    private function setPermissions(int $customerId, ?string $raw): void
    {
        DB::table('customers')->where('id', $customerId)->update(['permissions' => $raw]);
    }

    /** @return array<int, string>|null */
    private function permissionsOf(int $customerId): ?array
    {
        $raw = DB::table('customers')->where('id', $customerId)->value('permissions');

        return $raw === null ? null : json_decode($raw, true);
    }

    public function test_an_existing_customer_gains_view_seo_and_manage_seo_and_keeps_everything_else(): void
    {
        $customer = $this->createCustomer();
        $this->setPermissions($customer->id, json_encode(['access_backend', 'view_reports', 'website']));

        $this->runBackfill();

        $this->assertSame(['access_backend', 'view_reports', 'website', 'view_seo', 'manage_seo'], $this->permissionsOf($customer->id));
    }

    public function test_manage_search_console_is_never_backfilled(): void
    {
        $customers = [$this->createCustomer(), $this->createCustomer(), $this->createCustomer()];
        $this->setPermissions($customers[0]->id, json_encode(['access_backend']));
        $this->setPermissions($customers[1]->id, json_encode(['access_backend', 'view_seo']));
        $this->setPermissions($customers[2]->id, json_encode(['access_backend', 'manage_search_console']));

        $this->runBackfill();

        $this->assertNotContains('manage_search_console', $this->permissionsOf($customers[0]->id));
        $this->assertNotContains('manage_search_console', $this->permissionsOf($customers[1]->id));

        // A customer who was DELIBERATELY granted it keeps it, and gets no more than the two.
        $this->assertSame(['access_backend', 'manage_search_console', 'view_seo', 'manage_seo'], $this->permissionsOf($customers[2]->id));
    }

    public function test_it_is_idempotent_and_never_duplicates_a_permission(): void
    {
        $customer = $this->createCustomer();
        $this->setPermissions($customer->id, json_encode(['access_backend']));

        $this->runBackfill();
        $once = $this->permissionsOf($customer->id);

        $this->runBackfill();
        $this->runBackfill();

        $this->assertSame($once, $this->permissionsOf($customer->id));
        $this->assertSame(1, count(array_keys($once, 'view_seo', true)));
        $this->assertSame(1, count(array_keys($once, 'manage_seo', true)));
    }

    public function test_only_the_missing_permission_is_added(): void
    {
        $viewOnly = $this->createCustomer();
        $manageOnly = $this->createCustomer();
        $both = $this->createCustomer();
        $this->setPermissions($viewOnly->id, json_encode(['access_backend', 'view_seo']));
        $this->setPermissions($manageOnly->id, json_encode(['access_backend', 'manage_seo']));
        $this->setPermissions($both->id, json_encode(['access_backend', 'view_seo', 'manage_seo']));

        $this->runBackfill();

        $this->assertSame(['access_backend', 'view_seo', 'manage_seo'], $this->permissionsOf($viewOnly->id));
        $this->assertSame(['access_backend', 'manage_seo', 'view_seo'], $this->permissionsOf($manageOnly->id));
        $this->assertSame(['access_backend', 'view_seo', 'manage_seo'], $this->permissionsOf($both->id));
    }

    public function test_null_empty_and_non_list_values_are_left_untouched_never_invented_into_a_list(): void
    {
        $null = $this->createCustomer();
        $blank = $this->createCustomer();
        $emptyList = $this->createCustomer();
        $object = $this->createCustomer();
        $scalar = $this->createCustomer();
        $this->setPermissions($null->id, null);
        $this->setPermissions($blank->id, '   ');
        $this->setPermissions($emptyList->id, '[]');
        $this->setPermissions($object->id, '{"access_backend":true}');
        $this->setPermissions($scalar->id, '"access_backend"');

        $this->runBackfill();

        $this->assertNull($this->permissionsOf($null->id));
        $this->assertSame('   ', DB::table('customers')->where('id', $blank->id)->value('permissions'));
        $this->assertSame('[]', DB::table('customers')->where('id', $emptyList->id)->value('permissions'), 'A customer deliberately given no permissions must stay that way.');
        $this->assertSame('{"access_backend":true}', DB::table('customers')->where('id', $object->id)->value('permissions'));
        $this->assertSame('"access_backend"', DB::table('customers')->where('id', $scalar->id)->value('permissions'));
    }

    public function test_the_customer_permissions_app_config_default_is_backfilled_too(): void
    {
        AppConfig::query()->where('setting', 'customer_permissions')->delete();
        AppConfig::create(['setting' => 'customer_permissions', 'value' => json_encode(['access_backend', 'view_reports'])]);

        $this->runBackfill();

        $this->assertSame(
            ['access_backend', 'view_reports', 'view_seo', 'manage_seo'],
            json_decode(AppConfig::query()->where('setting', 'customer_permissions')->value('value'), true),
        );
    }

    public function test_a_missing_app_config_row_is_not_an_error_and_is_not_invented(): void
    {
        AppConfig::query()->where('setting', 'customer_permissions')->delete();

        $this->runBackfill();

        $this->assertSame(0, AppConfig::query()->where('setting', 'customer_permissions')->count());
    }

    public function test_it_touches_nothing_but_customer_permissions(): void
    {
        $customer = $this->createCustomer();
        $this->setPermissions($customer->id, json_encode(['access_backend']));
        $before = $this->dbFingerprintOf(['businesses', 'business_locations', 'workspaces', 'users']);
        $customersBefore = DB::table('customers')->where('id', $customer->id)->first();

        $this->runBackfill();

        $this->assertSame($before, $this->dbFingerprintOf(['businesses', 'business_locations', 'workspaces', 'users']));

        $after = DB::table('customers')->where('id', $customer->id)->first();
        foreach ((array) $customersBefore as $column => $value) {
            if ($column === 'permissions') {
                continue;
            }
            $this->assertEquals($value, $after->{$column}, "customers.{$column} must be untouched.");
        }
    }

    public function test_a_freshly_created_customer_inherits_the_new_defaults(): void
    {
        // The config defaults (view_seo true, manage_seo true,
        // manage_search_console false) are what new customers receive.
        $defaults = collect(config('customer-permissions'))->filter(fn ($p) => $p['default'])->keys();

        $this->assertTrue($defaults->contains('view_seo'));
        $this->assertTrue($defaults->contains('manage_seo'));
        $this->assertFalse($defaults->contains('manage_search_console'));
    }

    /** @param  array<int, string>  $tables */
    private function dbFingerprintOf(array $tables): string
    {
        return implode('|', array_map(fn ($t) => $t . ':' . md5(DB::table($t)->orderBy('id')->get()->toJson()), $tables));
    }
}
