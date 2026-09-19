<?php

namespace Tests\Feature\Catalog;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * Implementation Contract 16 §7 / §12.D / §18.D — real cross-process proof that
 * `PackageSnapshotService`'s `catalog_items`-row lock serializes against:
 * 1. CatalogItem price edit
 * 2. Location override edit
 * 3. CatalogItem archive
 *
 * Each test proves the resulting PackageSnapshot corresponds completely to one
 * serialized state — never a mix of fields from before and after different writes.
 *
 * Deliberately does NOT use RefreshDatabase — genuinely independent child processes
 * require committed rows, which an open RefreshDatabase transaction would hide.
 * Fixtures are inserted directly and explicitly cleaned up in tearDown().
 */
class PackageSnapshotConcurrencyTest extends TestCase
{
    private const RUNNER = __DIR__ . '/Support/concurrent_package_snapshot_runner.php';

    private array $userIds = [];

    private array $workspaceIds = [];

    protected function tearDown(): void
    {
        $businessIds = DB::table('businesses')->whereIn('workspace_id', $this->workspaceIds)->pluck('id');
        $itemIds = DB::table('catalog_items')->whereIn('business_id', $businessIds)->pluck('id');

        DB::table('package_snapshots')->whereIn('catalog_item_id', $itemIds)->delete();
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
    // 1. Snapshot vs CatalogItem price edit
    // ------------------------------------------------------------------

    public function test_a_held_price_edit_forces_waiting_snapshot_to_observe_committed_new_price(): void
    {
        [, $businessId, $itemId, $locationId] = $this->raceFixture(1000);

        // Holder begins transaction, locks the catalog_items row, and updates price to 2500.
        // Waiter attempts to take a snapshot while the lock is held.
        // The snapshot must block on lockForUpdate(), re-read the item after the holder commits,
        // and capture the new price (2500), never the stale price (1000).
        $holder = $this->holderOnItem($itemId, [
            'update-item', (string) $businessId, (string) $itemId, json_encode(['price_minor' => 2500, 'currency_code' => 'USD']),
        ]);
        $waiter = $this->runner([
            'take-snapshot', (string) $itemId, (string) $locationId, 'null', 'null',
        ]);

        $waiter->start();
        $holder->wait();
        $waiter->wait();

        $this->assertTrue($holder->isSuccessful(), 'Holder error: ' . $holder->getErrorOutput());
        $this->assertTrue($waiter->isSuccessful(), 'Waiter error: ' . $waiter->getErrorOutput());

        $snapshot = DB::table('package_snapshots')->where('catalog_item_id', $itemId)->first();
        $this->assertNotNull($snapshot, 'PackageSnapshot must be created.');
        $this->assertSame(2500, (int) $snapshot->price_minor_at_snapshot, 'Snapshot must observe holder\'s committed price.');
        $this->assertSame('USD', $snapshot->currency_code_at_snapshot);
    }

    public function test_a_held_snapshot_preserves_original_price_when_racing_concurrent_price_edit(): void
    {
        [, $businessId, $itemId, $locationId] = $this->raceFixture(1000);

        // Holder begins transaction, locks the catalog_items row, and takes a snapshot.
        // Waiter attempts to update price to 4000 while the lock is held.
        // The update must block until the snapshot commits. The snapshot must reflect
        // the original price (1000), and the item in DB subsequently reflects 4000.
        $holder = $this->holderOnSnapshot($itemId, $locationId, 'null', 'null');
        $waiter = $this->runner([
            'update-item', (string) $businessId, (string) $itemId, json_encode(['price_minor' => 4000, 'currency_code' => 'USD']),
        ]);

        $waiter->start();
        $holder->wait();
        $waiter->wait();

        $this->assertTrue($holder->isSuccessful(), 'Holder error: ' . $holder->getErrorOutput());
        $this->assertTrue($waiter->isSuccessful(), 'Waiter error: ' . $waiter->getErrorOutput());

        $snapshot = DB::table('package_snapshots')->where('catalog_item_id', $itemId)->first();
        $this->assertNotNull($snapshot);
        $this->assertSame(1000, (int) $snapshot->price_minor_at_snapshot, 'Snapshot captured coherent original price.');

        $item = DB::table('catalog_items')->where('id', $itemId)->first();
        $this->assertSame(4000, (int) $item->price_minor, 'Item reflects waiter\'s subsequent committed price.');
    }

    // ------------------------------------------------------------------
    // 2. Snapshot vs Location override edit
    // ------------------------------------------------------------------

    public function test_a_held_location_override_price_forces_waiting_snapshot_to_observe_override_price(): void
    {
        [, $businessId, $itemId, $locationId] = $this->raceFixture(5000);

        // Holder locks the catalog_items row and creates/updates an override price of 7500.
        // Waiter attempts to snapshot at that location while the lock is held.
        // The snapshot must block, unblock after commit, and capture the override price 7500.
        $holder = $this->holderOnItem($itemId, [
            'set-override-price', (string) $businessId, (string) $itemId, (string) $locationId, '7500',
        ]);
        $waiter = $this->runner([
            'take-snapshot', (string) $itemId, (string) $locationId, 'null', 'null',
        ]);

        $waiter->start();
        $holder->wait();
        $waiter->wait();

        $this->assertTrue($holder->isSuccessful(), 'Holder error: ' . $holder->getErrorOutput());
        $this->assertTrue($waiter->isSuccessful(), 'Waiter error: ' . $waiter->getErrorOutput());

        $snapshot = DB::table('package_snapshots')->where('catalog_item_id', $itemId)->first();
        $this->assertNotNull($snapshot);
        $this->assertSame(7500, (int) $snapshot->price_minor_at_snapshot, 'Snapshot must reflect location override price.');
    }

    public function test_a_held_location_override_disable_forces_waiting_snapshot_to_refuse(): void
    {
        [, $businessId, $itemId, $locationId] = $this->raceFixture(5000);

        // Holder locks the catalog_items row and disables the item at this location.
        // Waiter attempts to take a snapshot at this location while the lock is held.
        // Waiter blocks, unblocks after commit, observes disabled state, and refuses with error.
        $holder = $this->holderOnItem($itemId, [
            'set-override-enabled', (string) $businessId, (string) $itemId, (string) $locationId, '0',
        ]);
        $waiter = $this->runner([
            'take-snapshot', (string) $itemId, (string) $locationId, 'null', 'null',
        ]);

        $waiter->start();
        $holder->wait();
        $waiter->wait();

        $this->assertTrue($holder->isSuccessful(), 'Holder error: ' . $holder->getErrorOutput());
        $this->assertFalse($waiter->isSuccessful(), 'Waiter must fail because item became disabled.');
        $this->assertStringContainsString('REFUSED', $waiter->getOutput());
        $this->assertStringContainsString('not offered at this Location', $waiter->getOutput());

        $this->assertSame(0, DB::table('package_snapshots')->where('catalog_item_id', $itemId)->count(), 'No snapshot should be created.');
    }

    // ------------------------------------------------------------------
    // 3. Snapshot vs CatalogItem archive
    // ------------------------------------------------------------------

    public function test_a_held_archive_forces_waiting_snapshot_to_refuse_archived_item(): void
    {
        [, $businessId, $itemId, $locationId] = $this->raceFixture(5000);

        // Holder locks the catalog_items row and archives the item.
        // Waiter attempts to snapshot while the lock is held.
        // Waiter blocks, unblocks after commit, re-reads item under lock, detects archived state, and refuses.
        $holder = $this->holderOnItem($itemId, [
            'archive-item', (string) $businessId, (string) $itemId,
        ]);
        $waiter = $this->runner([
            'take-snapshot', (string) $itemId, (string) $locationId, 'null', 'null',
        ]);

        $waiter->start();
        $holder->wait();
        $waiter->wait();

        $this->assertTrue($holder->isSuccessful(), 'Holder error: ' . $holder->getErrorOutput());
        $this->assertFalse($waiter->isSuccessful(), 'Waiter must fail because item was archived.');
        $this->assertStringContainsString('REFUSED', $waiter->getOutput());
        $this->assertStringContainsString('archived', $waiter->getOutput());

        $this->assertSame(0, DB::table('package_snapshots')->where('catalog_item_id', $itemId)->count(), 'No snapshot should be created.');
    }

    public function test_a_held_snapshot_completes_before_waiting_archive_commits(): void
    {
        [, $businessId, $itemId, $locationId] = $this->raceFixture(5000);

        // Holder locks the catalog_items row and takes a snapshot of the active item.
        // Waiter attempts to archive the item while the snapshot lock is held.
        // Waiter blocks until the snapshot commits. The snapshot was taken while active,
        // and the item in DB is subsequently archived.
        $holder = $this->holderOnSnapshot($itemId, $locationId, 'null', 'null');
        $waiter = $this->runner([
            'archive-item', (string) $businessId, (string) $itemId,
        ]);

        $waiter->start();
        $holder->wait();
        $waiter->wait();

        $this->assertTrue($holder->isSuccessful(), 'Holder error: ' . $holder->getErrorOutput());
        $this->assertTrue($waiter->isSuccessful(), 'Waiter error: ' . $waiter->getErrorOutput());

        $snapshot = DB::table('package_snapshots')->where('catalog_item_id', $itemId)->first();
        $this->assertNotNull($snapshot, 'Snapshot was created successfully before archive.');
        $this->assertSame(5000, (int) $snapshot->price_minor_at_snapshot);

        $item = DB::table('catalog_items')->where('id', $itemId)->first();
        $this->assertSame('archived', $item->lifecycle_state, 'Item is now archived.');
    }

    // ------------------------------------------------------------------
    // Fixtures & Process helpers
    // ------------------------------------------------------------------

    /**
     * @return array{0: int, 1: int, 2: int, 3: int} owner user id, business id, catalog item id, location id
     */
    private function raceFixture(int $priceMinor = 5000): array
    {
        $ownerId = DB::table('users')->insertGetId([
            'uid' => (string) Str::uuid(), 'first_name' => 'Race', 'last_name' => 'Owner',
            'email' => 'slice16d-race-' . uniqid('', true) . '@example.test', 'status' => true, 'is_admin' => false,
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
            'status' => 'active', 'is_complimentary' => true, 'complimentary_reason' => 'Slice 16D race fixture.',
            'additional_business_slots' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $businessId = DB::table('businesses')->insertGetId([
            'uid' => (string) Str::uuid(), 'customer_id' => $ownerId, 'workspace_id' => $workspaceId,
            'name' => 'Race Business', 'industry' => 'photo_booth_service', 'country_code' => 'US',
            'timezone' => 'America/New_York', 'currency_code' => 'USD', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $itemId = DB::table('catalog_items')->insertGetId([
            'uid' => (string) Str::uuid(), 'business_id' => $businessId, 'type' => 'package', 'name' => 'Race Package',
            'description' => 'Race Package Description', 'price_minor' => $priceMinor, 'currency_code' => 'USD',
            'position' => 0, 'lifecycle_state' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $locationId = DB::table('business_locations')->insertGetId([
            'uid' => (string) Str::uuid(), 'business_id' => $businessId, 'name' => 'Race Location', 'service_mode' => 'storefront',
            'city' => 'Springfield', 'region' => 'IL', 'country_code' => 'US', 'public_address' => false,
            'is_primary' => true, 'lifecycle_state' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$ownerId, $businessId, $itemId, $locationId];
    }

    private function runner(array $arguments): Process
    {
        return new Process(
            array_merge([(new PhpExecutableFinder())->find() ?: 'php', self::RUNNER], $arguments),
            null,
            $this->childEnvironment()
        );
    }

    private function holderOnItem(int $itemId, array $delegate): Process
    {
        $holder = $this->runner(array_merge(['hold-item-then', (string) $itemId, '1'], $delegate));
        $holder->start();
        $this->waitForLocked($holder);

        return $holder;
    }

    private function holderOnSnapshot(int $itemId, int $locationId, string $actorUserId = 'null', string $explicitPrice = 'null'): Process
    {
        $holder = $this->runner([
            'hold-snapshot-then', (string) $itemId, (string) $locationId, '1', $actorUserId, $explicitPrice,
        ]);
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
