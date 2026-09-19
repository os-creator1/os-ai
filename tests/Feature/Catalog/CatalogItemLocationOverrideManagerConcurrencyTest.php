<?php

namespace Tests\Feature\Catalog;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * Implementation Contract 16 §7/§12.C/§18.C — real cross-process proof that
 * `CatalogItemLocationOverrideManager`'s `catalog_items`-row lock actually
 * serializes concurrent override writers, mirroring
 * CatalogItemManagerConcurrencyTest.php's proven pattern for Sub-slice B.
 *
 * Deliberately does NOT use RefreshDatabase — a genuinely separate process
 * needs committed rows, which an open RefreshDatabase transaction would
 * hide entirely. Fixture rows are inserted directly (auto-committed) and
 * explicitly cleaned up in tearDown().
 *
 * Also proves the §5.2 sparse invariant composes correctly across two
 * SEPARATE processes: the row is deleted only once BOTH concurrent
 * partial clears land on the canonical default, never after just one.
 */
class CatalogItemLocationOverrideManagerConcurrencyTest extends TestCase
{
    private const RUNNER = __DIR__ . '/Support/concurrent_catalog_override_runner.php';

    private array $userIds = [];

    private array $workspaceIds = [];

    protected function tearDown(): void
    {
        $businessIds = DB::table('businesses')->whereIn('workspace_id', $this->workspaceIds)->pluck('id');
        $itemIds = DB::table('catalog_items')->whereIn('business_id', $businessIds)->pluck('id');
        DB::table('catalog_item_location_overrides')->whereIn('catalog_item_id', $itemIds)->delete();
        DB::table('business_locations')->whereIn('business_id', $businessIds)->delete();
        DB::table('catalog_items')->whereIn('business_id', $businessIds)->delete();
        DB::table('businesses')->whereIn('id', $businessIds)->delete();
        DB::table('workspace_plan_assignments')->whereIn('workspace_id', $this->workspaceIds)->delete();
        DB::table('workspaces')->whereIn('id', $this->workspaceIds)->delete();
        DB::table('customers')->whereIn('user_id', $this->userIds)->delete();
        DB::table('users')->whereIn('id', $this->userIds)->delete();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Update-vs-update: two concurrent writes against the SAME override.
    // ------------------------------------------------------------------

    public function test_a_held_override_update_forces_the_waiter_to_observe_the_committed_price_not_a_lost_update(): void
    {
        [, $businessId, $itemId, $locationId] = $this->raceFixture();
        // A pre-existing override row: this test is specifically update-vs-
        // update, not first-create (that is covered separately below).
        $this->insertOverride($itemId, $locationId, true, 4200);

        // Holder sets a price override; waiter, started while the holder
        // still holds the catalog_items row lock, only disables the item at
        // that Location. If setOverride() ever fell back to a stale
        // pre-lock read for the field it didn't touch, the holder's
        // committed price could be lost/reverted instead of both edits
        // landing.
        $holder = $this->holderOnItem($itemId, ['set-price', (string) $businessId, (string) $itemId, (string) $locationId, '3000']);
        $waiter = $this->runner(['set-enabled', (string) $businessId, (string) $itemId, (string) $locationId, '0']);
        $waiter->start();
        $holder->wait();
        $waiter->wait();

        $this->assertTrue($holder->isSuccessful(), 'Holder: ' . $holder->getErrorOutput());
        $this->assertTrue($waiter->isSuccessful(), 'Waiter: ' . $waiter->getErrorOutput());

        $row = DB::table('catalog_item_location_overrides')->where('catalog_item_id', $itemId)->where('business_location_id', $locationId)->first();
        $this->assertSame(0, (int) $row->is_enabled, 'The waiter\'s own field must still land.');
        $this->assertSame(3000, (int) $row->price_minor_override, 'The holder\'s committed price must never be lost or reverted.');
        $this->assertSame(1, DB::table('catalog_item_location_overrides')->where('catalog_item_id', $itemId)->count(), 'Exactly one override row, never a duplicate.');
    }

    // ------------------------------------------------------------------
    // Return-to-default: the row must disappear only once BOTH concurrent
    // partial clears have landed, never after just one of them.
    // ------------------------------------------------------------------

    public function test_a_held_return_to_default_deletes_the_row_only_once_both_deviations_are_cleared(): void
    {
        [, $businessId, $itemId, $locationId] = $this->raceFixture();
        // Two deviations to start: disabled AND a price surcharge.
        $this->insertOverride($itemId, $locationId, false, 4200);

        // Holder clears the enabled deviation alone -- merged result
        // (true, 4200) is NOT canonical (the price deviation remains), so
        // the row must still exist after the holder commits. Only once the
        // waiter, observing that committed (true, 4200) state, also clears
        // the price does the merged result finally become the canonical
        // default and the row gets deleted. If the waiter instead read a
        // stale pre-lock snapshot (is_enabled still false), the final
        // merge would wrongly look like (false, null) -- still a
        // deviation -- and the row would incorrectly survive.
        $holder = $this->holderOnItem($itemId, ['set-enabled', (string) $businessId, (string) $itemId, (string) $locationId, '1']);
        $waiter = $this->runner(['set-price', (string) $businessId, (string) $itemId, (string) $locationId, 'null']);
        $waiter->start();
        $holder->wait();
        $waiter->wait();

        $this->assertTrue($holder->isSuccessful(), 'Holder: ' . $holder->getErrorOutput());
        $this->assertTrue($waiter->isSuccessful(), 'Waiter: ' . $waiter->getErrorOutput());

        $this->assertSame(0, DB::table('catalog_item_location_overrides')
            ->where('catalog_item_id', $itemId)
            ->where('business_location_id', $locationId)
            ->count(), 'Both deviations cleared: the canonical default must never persist as a row.');
    }

    public function test_a_held_partial_clear_alone_never_deletes_the_row(): void
    {
        [, $businessId, $itemId, $locationId] = $this->raceFixture();
        $this->insertOverride($itemId, $locationId, false, 4200);

        // Same starting state as above, but the waiter's own change does
        // NOT clear the remaining deviation -- the row must still exist,
        // proving the manager is not simply deleting on any write to a
        // row that started with a deviation.
        $holder = $this->holderOnItem($itemId, ['set-enabled', (string) $businessId, (string) $itemId, (string) $locationId, '1']);
        $waiter = $this->runner(['set-price', (string) $businessId, (string) $itemId, (string) $locationId, '4200']);
        $waiter->start();
        $holder->wait();
        $waiter->wait();

        $this->assertTrue($holder->isSuccessful(), 'Holder: ' . $holder->getErrorOutput());
        $this->assertTrue($waiter->isSuccessful(), 'Waiter: ' . $waiter->getErrorOutput());

        $row = DB::table('catalog_item_location_overrides')->where('catalog_item_id', $itemId)->where('business_location_id', $locationId)->first();
        $this->assertNotNull($row, 'Only one of the two deviations cleared: the row must still exist.');
        $this->assertSame(1, (int) $row->is_enabled);
        $this->assertSame(4200, (int) $row->price_minor_override);
    }

    // ------------------------------------------------------------------
    // First-create: two writers racing to create the SAME sparse row.
    // ------------------------------------------------------------------

    public function test_two_simultaneous_first_override_creations_never_collide_or_lose_a_write(): void
    {
        [, $businessId, $itemId, $locationId] = $this->raceFixture();
        // No override row exists yet for this (item, location) pair: the
        // exact first-create race the catalog_items lock (backed by the
        // unique-constraint defense-in-depth) must resolve deterministically.

        $first = $this->runner(['set-price', (string) $businessId, (string) $itemId, (string) $locationId, '1500']);
        $second = $this->runner(['set-enabled', (string) $businessId, (string) $itemId, (string) $locationId, '0']);
        $first->start();
        $second->start();
        $first->wait();
        $second->wait();

        $this->assertTrue($first->isSuccessful(), 'First: ' . $first->getErrorOutput());
        $this->assertTrue($second->isSuccessful(), 'Second: ' . $second->getErrorOutput());

        $this->assertSame(1, DB::table('catalog_item_location_overrides')->where('catalog_item_id', $itemId)->where('business_location_id', $locationId)->count(), 'Never a duplicate row from a first-create race.');

        $row = DB::table('catalog_item_location_overrides')->where('catalog_item_id', $itemId)->where('business_location_id', $locationId)->first();
        $this->assertSame(1500, (int) $row->price_minor_override, 'Whichever process ran second must observe and preserve the first\'s committed price.');
        $this->assertSame(0, (int) $row->is_enabled, 'Whichever process ran second must observe and preserve the first\'s committed enabled flag.');
    }

    public function test_a_held_first_override_creation_forces_the_waiter_to_update_the_committed_row(): void
    {
        [, $businessId, $itemId, $locationId] = $this->raceFixture();

        $holder = $this->holderOnItem($itemId, ['set-price', (string) $businessId, (string) $itemId, (string) $locationId, '2500']);
        $waiter = $this->runner(['set-enabled', (string) $businessId, (string) $itemId, (string) $locationId, '0']);
        $waiter->start();
        $holder->wait();
        $waiter->wait();

        $this->assertTrue($holder->isSuccessful(), 'Holder: ' . $holder->getErrorOutput());
        $this->assertTrue($waiter->isSuccessful(), 'Waiter: ' . $waiter->getErrorOutput());

        $this->assertSame(1, DB::table('catalog_item_location_overrides')->where('catalog_item_id', $itemId)->count());
        $row = DB::table('catalog_item_location_overrides')->where('catalog_item_id', $itemId)->first();
        $this->assertSame(2500, (int) $row->price_minor_override);
        $this->assertSame(0, (int) $row->is_enabled);
    }

    // ------------------------------------------------------------------

    /**
     * @return array{0: int, 1: int, 2: int, 3: int} owner user id, business id, catalog item id, location id
     */
    private function raceFixture(): array
    {
        $ownerId = DB::table('users')->insertGetId([
            'uid' => (string) Str::uuid(), 'first_name' => 'Race', 'last_name' => 'Owner',
            'email' => 'slice16c-race-' . uniqid('', true) . '@example.test', 'status' => true, 'is_admin' => false,
            'is_customer' => true, 'active_portal' => 'customer', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->userIds[] = $ownerId;
        DB::table('customers')->insert(['uid' => (string) Str::uuid(), 'user_id' => $ownerId, 'created_at' => now(), 'updated_at' => now()]);

        $workspaceId = DB::table('workspaces')->insertGetId([
            'uid' => (string) Str::uuid(), 'name' => 'Race Workspace', 'owner_user_id' => $ownerId, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->workspaceIds[] = $workspaceId;

        DB::table('workspace_plan_assignments')->insert([
            'workspace_id' => $workspaceId,
            'workspace_plan_catalog_id' => DB::table('workspace_plan_catalog')->where('tier', 'core')->value('id'),
            'status' => 'active', 'is_complimentary' => true, 'complimentary_reason' => 'Slice 16C race fixture.',
            'additional_business_slots' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $businessId = DB::table('businesses')->insertGetId([
            'uid' => (string) Str::uuid(), 'customer_id' => $ownerId, 'workspace_id' => $workspaceId,
            'name' => 'Race Business', 'industry' => 'photo_booth_service', 'country_code' => 'US',
            'timezone' => 'America/New_York', 'currency_code' => 'USD', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $itemId = DB::table('catalog_items')->insertGetId([
            'uid' => (string) Str::uuid(), 'business_id' => $businessId, 'type' => 'product', 'name' => 'Deep Clean',
            'price_minor' => 5000, 'currency_code' => 'USD', 'position' => 0, 'lifecycle_state' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $locationId = DB::table('business_locations')->insertGetId([
            'uid' => (string) Str::uuid(), 'business_id' => $businessId, 'name' => 'Main Street', 'service_mode' => 'storefront',
            'city' => 'Springfield', 'region' => 'IL', 'country_code' => 'US', 'public_address' => false,
            'is_primary' => true, 'lifecycle_state' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$ownerId, $businessId, $itemId, $locationId];
    }

    private function insertOverride(int $itemId, int $locationId, bool $isEnabled, ?int $priceMinorOverride): void
    {
        DB::table('catalog_item_location_overrides')->insert([
            'catalog_item_id' => $itemId, 'business_location_id' => $locationId,
            'is_enabled' => $isEnabled, 'price_minor_override' => $priceMinorOverride,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function runner(array $arguments): Process
    {
        return new Process(array_merge([(new PhpExecutableFinder())->find() ?: 'php', self::RUNNER], $arguments), null, $this->childEnvironment());
    }

    private function holderOnItem(int $itemId, array $delegate): Process
    {
        $holder = $this->runner(array_merge(['hold-item-then', (string) $itemId, '1'], $delegate));
        $holder->start();
        $this->waitForLocked($holder);

        return $holder;
    }

    private function waitForLocked(Process $holder): void
    {
        $deadline = microtime(true) + 10.0;

        while (! str_contains($holder->getOutput(), 'LOCKED') && microtime(true) < $deadline) {
            usleep(50_000);
        }

        $this->assertStringContainsString('LOCKED', $holder->getOutput(), 'The holder never acquired the lock: ' . $holder->getErrorOutput());
    }

    private function childEnvironment(): array
    {
        $database = TestDatabaseSafety::activeTestDatabase();

        return ['DB_DATABASE' => $database, 'EXPECTED_TEST_DATABASE' => $database];
    }
}
