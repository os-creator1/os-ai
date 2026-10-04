<?php

namespace Tests\Feature\MetaAds\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Meta Ads Module V1 (contract 24 §8) — `view_meta_ads` defaults true and
 * is backfilled to existing customers; `manage_meta_ads` is credential-class,
 * defaults false and is NEVER backfilled.
 */
class MetaAdsPermissionBackfillTest extends TestCase
{
    use CreatesBusinessTestData;
    use RefreshDatabase;

    private const MIGRATION = '2026_10_29_200002_backfill_meta_ads_view_permission.php';

    private function runBackfill(): void
    {
        (require database_path('migrations/' . self::MIGRATION))->up();
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

    public function test_the_config_defaults_are_view_true_and_manage_false(): void
    {
        $this->assertTrue(config('customer-permissions.view_meta_ads.default'));
        $this->assertFalse(config('customer-permissions.manage_meta_ads.default'), 'credential-class: off unless granted');
        $this->assertSame('Ads', config('customer-permissions.view_meta_ads.category'));
        $this->assertSame('Ads', config('customer-permissions.manage_meta_ads.category'));
    }

    public function test_an_existing_customer_gains_view_and_never_manage(): void
    {
        $customer = $this->createCustomer();
        $this->setPermissions($customer->id, json_encode(['access_backend', 'view_reports']));

        $this->runBackfill();

        $this->assertSame(['access_backend', 'view_reports', 'view_meta_ads'], $this->permissionsOf($customer->id));
        $this->assertNotContains('manage_meta_ads', $this->permissionsOf($customer->id));
    }

    public function test_a_customer_deliberately_granted_manage_keeps_it_and_gets_only_view(): void
    {
        $customer = $this->createCustomer();
        $this->setPermissions($customer->id, json_encode(['access_backend', 'manage_meta_ads']));

        $this->runBackfill();

        $this->assertSame(['access_backend', 'manage_meta_ads', 'view_meta_ads'], $this->permissionsOf($customer->id));
    }

    public function test_it_is_idempotent_and_leaves_null_empty_and_non_list_values_alone(): void
    {
        $with = $this->createCustomer();
        $null = $this->createCustomer();
        $empty = $this->createCustomer();
        $assoc = $this->createCustomer();
        $this->setPermissions($with->id, json_encode(['access_backend']));
        $this->setPermissions($null->id, null);
        $this->setPermissions($empty->id, json_encode([]));
        $this->setPermissions($assoc->id, json_encode(['a' => 'b']));

        $this->runBackfill();
        $once = $this->permissionsOf($with->id);
        $this->runBackfill();
        $this->runBackfill();

        $this->assertSame($once, $this->permissionsOf($with->id));
        $this->assertSame(1, count(array_keys($once, 'view_meta_ads', true)));
        $this->assertNull($this->permissionsOf($null->id));
        $this->assertSame([], $this->permissionsOf($empty->id));
        $this->assertSame(['a' => 'b'], $this->permissionsOf($assoc->id));
    }
}
