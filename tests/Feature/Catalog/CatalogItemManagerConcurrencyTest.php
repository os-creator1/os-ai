<?php

namespace Tests\Feature\Catalog;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * Implementation Contract 16 §7 / §12.B / §18.B — the required "concurrent
 * edit-vs-edit test proving the lockForUpdate() serialization actually
 * blocks/serializes rather than racing." The `DB::listen()`-based tests in
 * CatalogItemManagerTest prove the SQL shape (a `SELECT ... FOR UPDATE` is
 * issued); this file proves the actual runtime effect, using genuinely
 * independent OS processes racing for the same row lock
 * `CatalogItemManager` itself takes — never sequential coincidence within
 * one process/connection, mirroring
 * BusinessLocationConcurrencyTest.php's proven cross-process pattern.
 *
 * Deliberately does NOT use RefreshDatabase — a genuinely separate process
 * needs committed rows, which an open RefreshDatabase transaction would
 * hide entirely. Fixture rows are inserted directly (auto-committed) and
 * explicitly cleaned up in tearDown().
 */
class CatalogItemManagerConcurrencyTest extends TestCase
{
    private const RUNNER = __DIR__ . '/Support/concurrent_catalog_item_runner.php';

    private array $userIds = [];

    private array $workspaceIds = [];

    protected function tearDown(): void
    {
        $businessIds = DB::table('businesses')->whereIn('workspace_id', $this->workspaceIds)->pluck('id');
        DB::table('catalog_items')->whereIn('business_id', $businessIds)->delete();
        DB::table('businesses')->whereIn('id', $businessIds)->delete();
        DB::table('workspace_entitlement_transitions')->whereIn('workspace_id', $this->workspaceIds)->delete();
        DB::table('workspace_plan_assignments')->whereIn('workspace_id', $this->workspaceIds)->delete();
        DB::table('workspaces')->whereIn('id', $this->workspaceIds)->delete();
        DB::table('customers')->whereIn('user_id', $this->userIds)->delete();
        DB::table('users')->whereIn('id', $this->userIds)->delete();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Edit-vs-edit: two concurrent updates against the SAME CatalogItem.
    // ------------------------------------------------------------------

    public function test_a_held_update_forces_the_waiter_to_observe_the_committed_price_change_not_stale_state(): void
    {
        [, $businessId] = $this->raceBusiness();
        $itemId = $this->insertCatalogItem($businessId, 'Original', 0, 1000, 'USD');

        // Holder changes the price/currency pair; waiter, started while the
        // holder still holds the row lock, only renames the item. If the
        // waiter's update() ever read the item BEFORE the lock was granted
        // (a stale pre-lock snapshot) instead of re-deriving it fresh under
        // its own lock, the price/currency this test never told it to touch
        // could revert to the original 1000/USD or trip the co-nullable
        // check — a lost update. Both fields landing correctly, with
        // neither request's write erased, is the proof §7 requires.
        $holder = $this->holderOnItem($itemId, ['update-item', (string) $businessId, (string) $itemId, json_encode(['price_minor' => 2000, 'currency_code' => 'USD'])]);
        $waiter = $this->runner(['update-item', (string) $businessId, (string) $itemId, json_encode(['name' => 'Renamed'])]);
        $waiter->start();
        $holder->wait();
        $waiter->wait();

        $this->assertTrue($holder->isSuccessful(), 'Holder: ' . $holder->getErrorOutput());
        $this->assertTrue($waiter->isSuccessful(), 'Waiter: ' . $waiter->getErrorOutput());

        $row = DB::table('catalog_items')->where('id', $itemId)->first();
        $this->assertSame('Renamed', $row->name, 'The waiter\'s own field must still land.');
        $this->assertSame(2000, (int) $row->price_minor, 'The holder\'s committed price must never be lost or reverted.');
        $this->assertSame('USD', $row->currency_code);
    }

    public function test_a_waiting_update_observes_the_holders_committed_clear_rather_than_a_stale_price(): void
    {
        [, $businessId] = $this->raceBusiness();
        $itemId = $this->insertCatalogItem($businessId, 'Original', 0, 1000, 'USD');

        // The decisive case for "no lost update from reading stale state
        // before either lock was acquired": the holder clears price AND
        // currency together (a legal co-nullable transition). The waiter's
        // update omits both fields entirely, so update()'s own fallback
        // logic ("keep the CURRENT persisted value") must resolve against
        // the item as it exists AFTER the holder's commit — null, null —
        // never against the value that existed before either process
        // started. If the manager instead trusted an in-memory copy
        // captured before the lock was granted, this would incorrectly
        // resurrect the stale 1000/USD.
        $holder = $this->holderOnItem($itemId, ['update-item', (string) $businessId, (string) $itemId, json_encode(['price_minor' => null, 'currency_code' => null])]);
        $waiter = $this->runner(['update-item', (string) $businessId, (string) $itemId, json_encode(['name' => 'Renamed'])]);
        $waiter->start();
        $holder->wait();
        $waiter->wait();

        $this->assertTrue($holder->isSuccessful(), 'Holder: ' . $holder->getErrorOutput());
        $this->assertTrue($waiter->isSuccessful(), 'Waiter: ' . $waiter->getErrorOutput());

        $row = DB::table('catalog_items')->where('id', $itemId)->first();
        $this->assertSame('Renamed', $row->name);
        $this->assertNull($row->price_minor, 'The waiter must observe and preserve the holder\'s committed clear.');
        $this->assertNull($row->currency_code);
    }

    public function test_two_genuinely_simultaneous_updates_on_different_fields_never_lose_either_write(): void
    {
        [, $businessId] = $this->raceBusiness();
        $itemId = $this->insertCatalogItem($businessId, 'Original', 0, 1000, 'USD');

        // No forced ordering here: both processes are started together and
        // race for the same row lock. Whichever wins, the loser's update()
        // re-derives the row fresh under its own lock after the winner
        // commits, so both edits must land regardless of which process the
        // OS scheduler happened to run first — proving the two mutations
        // serialize rather than interleave or clobber one another.
        $first = $this->runner(['update-item', (string) $businessId, (string) $itemId, json_encode(['name' => 'Renamed'])]);
        $second = $this->runner(['update-item', (string) $businessId, (string) $itemId, json_encode(['description' => 'A fresh description.'])]);
        $first->start();
        $second->start();
        $first->wait();
        $second->wait();

        $this->assertTrue($first->isSuccessful(), 'First: ' . $first->getErrorOutput());
        $this->assertTrue($second->isSuccessful(), 'Second: ' . $second->getErrorOutput());

        $row = DB::table('catalog_items')->where('id', $itemId)->first();
        $this->assertSame('Renamed', $row->name);
        $this->assertSame('A fresh description.', $row->description);
        $this->assertSame(1000, (int) $row->price_minor, 'Untouched fields must never interleave into a lost state.');
        $this->assertSame('USD', $row->currency_code);
    }

    // ------------------------------------------------------------------
    // First-create / empty-active-set serialization (Business row lock).
    // ------------------------------------------------------------------

    public function test_two_simultaneous_first_creates_never_collide_or_deadlock(): void
    {
        [, $businessId] = $this->raceBusiness();
        // Zero existing catalog_items rows: the exact empty-active-set
        // condition create() cannot serialize through a catalog_items lock
        // alone, since no such row exists yet.

        $first = $this->runner(['create-item', (string) $businessId, 'Racer A']);
        $second = $this->runner(['create-item', (string) $businessId, 'Racer B']);
        $first->start();
        $second->start();
        $first->wait();
        $second->wait();

        $this->assertTrue($first->isSuccessful(), 'A: ' . $first->getErrorOutput());
        $this->assertTrue($second->isSuccessful(), 'B: ' . $second->getErrorOutput());

        $positions = DB::table('catalog_items')->where('business_id', $businessId)->orderBy('position')->pluck('position')->all();
        $this->assertSame([0, 1], $positions, 'Both creates must land on distinct, deterministic positions with no lost row.');
        $this->assertSame(2, DB::table('catalog_items')->where('business_id', $businessId)->count());
    }

    public function test_two_simultaneous_creates_after_every_existing_item_is_archived_never_collide(): void
    {
        [, $businessId] = $this->raceBusiness();
        // A committed catalog_items row exists, but its active SET is
        // empty — the same empty-active-set condition, reached a different
        // way. max(position) still sees the archived row (position 0), so
        // the two new items are expected at 1 and 2.
        $this->insertCatalogItem($businessId, 'Old', 0, null, null, 'archived');

        $first = $this->runner(['create-item', (string) $businessId, 'Racer A']);
        $second = $this->runner(['create-item', (string) $businessId, 'Racer B']);
        $first->start();
        $second->start();
        $first->wait();
        $second->wait();

        $this->assertTrue($first->isSuccessful(), 'A: ' . $first->getErrorOutput());
        $this->assertTrue($second->isSuccessful(), 'B: ' . $second->getErrorOutput());

        $positions = DB::table('catalog_items')->where('business_id', $businessId)->where('lifecycle_state', 'active')->orderBy('position')->pluck('position')->all();
        $this->assertSame([1, 2], $positions, 'Both creates must land on distinct positions after the archived row.');
        $this->assertSame(3, DB::table('catalog_items')->where('business_id', $businessId)->count());
    }

    public function test_a_held_first_create_forces_the_waiter_to_observe_the_committed_position(): void
    {
        [, $businessId] = $this->raceBusiness();

        $holder = $this->holderOnBusiness($businessId, ['create-item', (string) $businessId, 'Holder']);
        $waiter = $this->runner(['create-item', (string) $businessId, 'Waiter']);
        $waiter->start();
        $holder->wait();
        $waiter->wait();

        $this->assertTrue($holder->isSuccessful(), 'Holder: ' . $holder->getErrorOutput());
        $this->assertTrue($waiter->isSuccessful(), 'Waiter: ' . $waiter->getErrorOutput());

        $rows = DB::table('catalog_items')->where('business_id', $businessId)->orderBy('position')->get(['name', 'position']);
        $this->assertSame(['Holder', 'Waiter'], $rows->pluck('name')->all());
        $this->assertSame([0, 1], $rows->pluck('position')->all(), 'The waiter must compute its position from the holder\'s committed row, never a stale empty set.');
    }

    // ------------------------------------------------------------------

    /**
     * @return array{0: int, 1: int} owner user id, business id
     */
    private function raceBusiness(): array
    {
        $ownerId = DB::table('users')->insertGetId([
            'uid' => (string) Str::uuid(), 'first_name' => 'Race', 'last_name' => 'Owner',
            'email' => 'slice16b-race-' . uniqid('', true) . '@example.test', 'status' => true, 'is_admin' => false,
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
            'status' => 'active', 'is_complimentary' => true, 'complimentary_reason' => 'Slice 16B race fixture.',
            'additional_business_slots' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $businessId = DB::table('businesses')->insertGetId([
            'uid' => (string) Str::uuid(), 'customer_id' => $ownerId, 'workspace_id' => $workspaceId,
            'name' => 'Race Business', 'industry' => 'photo_booth_service', 'country_code' => 'US',
            'timezone' => 'America/New_York', 'currency_code' => 'USD', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$ownerId, $businessId];
    }

    private function insertCatalogItem(int $businessId, string $name, int $position, ?int $priceMinor = 1000, ?string $currencyCode = 'USD', string $lifecycleState = 'active'): int
    {
        return DB::table('catalog_items')->insertGetId([
            'uid' => (string) Str::uuid(), 'business_id' => $businessId, 'type' => 'product', 'name' => $name,
            'price_minor' => $priceMinor, 'currency_code' => $currencyCode, 'position' => $position,
            'lifecycle_state' => $lifecycleState, 'archived_at' => $lifecycleState === 'archived' ? now() : null,
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

    private function holderOnBusiness(int $businessId, array $delegate): Process
    {
        $holder = $this->runner(array_merge(['hold-business-then', (string) $businessId, '1'], $delegate));
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
