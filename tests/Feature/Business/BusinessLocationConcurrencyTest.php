<?php

namespace Tests\Feature\Business;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * Customer Experience Slice 1A — T-LOC-6: two concurrent activations can
 * never both consume a Business's last location slot.
 *
 * Uses genuinely independent OS processes (never sequential coincidence)
 * racing for the Business row lock BusinessLocationManager takes before it
 * counts. Deliberately does NOT use RefreshDatabase — a separate process
 * needs committed rows — so fixture rows are inserted directly and removed
 * in tearDown(), mirroring EntitlementManagerConcurrencyTest.
 */
class BusinessLocationConcurrencyTest extends TestCase
{
    private const RUNNER = __DIR__ . '/Support/concurrent_location_runner.php';

    private array $userIds = [];

    private array $workspaceIds = [];

    protected function tearDown(): void
    {
        $businessIds = DB::table('businesses')->whereIn('workspace_id', $this->workspaceIds)->pluck('id');
        DB::table('business_locations')->whereIn('business_id', $businessIds)->delete();
        DB::table('businesses')->whereIn('id', $businessIds)->delete();
        DB::table('workspace_entitlement_transitions')->whereIn('workspace_id', $this->workspaceIds)->delete();
        DB::table('workspace_plan_assignments')->whereIn('workspace_id', $this->workspaceIds)->delete();
        DB::table('workspaces')->whereIn('id', $this->workspaceIds)->delete();
        DB::table('customers')->whereIn('user_id', $this->userIds)->delete();
        DB::table('users')->whereIn('id', $this->userIds)->delete();

        parent::tearDown();
    }

    public function test_two_simultaneous_creations_cannot_both_take_the_last_included_slot(): void
    {
        [$ownerId, $businessId] = $this->coreBusinessWithActiveLocations(2);

        $first = $this->runner(['create-location', (string) $businessId, (string) $ownerId, 'Racer A']);
        $second = $this->runner(['create-location', (string) $businessId, (string) $ownerId, 'Racer B']);
        $first->start();
        $second->start();
        $first->wait();
        $second->wait();

        $this->assertSame(1, (int) $first->isSuccessful() + (int) $second->isSuccessful(), 'Exactly one creation wins. A: ' . $first->getErrorOutput() . ' B: ' . $second->getErrorOutput());
        $this->assertStringContainsString('LocationSlotAllocationRequiredException', $first->getErrorOutput() . $second->getErrorOutput());
        $this->assertSame(3, $this->activeCount($businessId), 'Never 4 on a Business that holds 3.');
    }

    public function test_a_held_creation_forces_the_waiter_to_observe_the_full_business(): void
    {
        [$ownerId, $businessId] = $this->coreBusinessWithActiveLocations(2);

        $holder = $this->holder($businessId, ['create-location', (string) $businessId, (string) $ownerId, 'Holder']);
        $waiter = $this->runner(['create-location', (string) $businessId, (string) $ownerId, 'Waiter']);
        $waiter->start();
        $holder->wait();
        $waiter->wait();

        $this->assertTrue($holder->isSuccessful(), 'The lock holder wins. ' . $holder->getErrorOutput());
        $this->assertFalse($waiter->isSuccessful(), 'The waiter sees the committed third location.');
        $this->assertStringContainsString('LocationSlotAllocationRequiredException', $waiter->getErrorOutput());
        $this->assertSame(3, $this->activeCount($businessId));
    }

    public function test_a_reactivation_and_a_creation_cannot_both_take_the_last_slot(): void
    {
        [$ownerId, $businessId] = $this->coreBusinessWithActiveLocations(2);
        $archivedId = $this->insertLocation($businessId, 'Archived branch', false, 'archived');

        $holder = $this->holder($businessId, ['reactivate-location', (string) $archivedId, (string) $ownerId]);
        $waiter = $this->runner(['create-location', (string) $businessId, (string) $ownerId, 'Too Late']);
        $waiter->start();
        $holder->wait();
        $waiter->wait();

        $this->assertTrue($holder->isSuccessful(), 'The reactivation wins. ' . $holder->getErrorOutput());
        $this->assertFalse($waiter->isSuccessful(), 'The creation sees the reactivated location.');
        $this->assertSame(3, $this->activeCount($businessId));
        $this->assertSame('active', DB::table('business_locations')->where('id', $archivedId)->value('lifecycle_state'));
    }

    // ------------------------------------------------------------------

    /**
     * @return array{0: int, 1: int} owner user id, business id
     */
    private function coreBusinessWithActiveLocations(int $count): array
    {
        $ownerId = DB::table('users')->insertGetId([
            'uid' => (string) Str::uuid(), 'first_name' => 'Race', 'last_name' => 'Owner',
            'email' => 'slice1a-race-' . uniqid('', true) . '@example.test', 'status' => true, 'is_admin' => false,
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
            'status' => 'active', 'is_complimentary' => true, 'complimentary_reason' => 'Slice 1A race fixture.',
            'additional_business_slots' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $businessId = DB::table('businesses')->insertGetId([
            'uid' => (string) Str::uuid(), 'customer_id' => $ownerId, 'workspace_id' => $workspaceId,
            'name' => 'Race Business', 'industry' => 'photo_booth_service', 'country_code' => 'US',
            'timezone' => 'America/New_York', 'currency_code' => 'USD', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        for ($i = 1; $i <= $count; $i++) {
            $this->insertLocation($businessId, "Branch {$i}", $i === 1);
        }

        return [$ownerId, $businessId];
    }

    private function insertLocation(int $businessId, string $name, bool $primary, string $state = 'active'): int
    {
        return DB::table('business_locations')->insertGetId([
            'uid' => (string) Str::uuid(), 'business_id' => $businessId, 'name' => $name, 'service_mode' => 'storefront',
            'city' => 'Springfield', 'region' => 'IL', 'country_code' => 'US', 'public_address' => false,
            'is_primary' => $primary, 'lifecycle_state' => $state, 'archived_at' => $state === 'archived' ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function activeCount(int $businessId): int
    {
        return DB::table('business_locations')->where('business_id', $businessId)->where('lifecycle_state', 'active')->count();
    }

    private function runner(array $arguments): Process
    {
        return new Process(array_merge([(new PhpExecutableFinder())->find() ?: 'php', self::RUNNER], $arguments), null, $this->childEnvironment());
    }

    private function holder(int $businessId, array $delegate): Process
    {
        $holder = $this->runner(array_merge(['hold-then', (string) $businessId, '1'], $delegate));
        $holder->start();

        $deadline = microtime(true) + 10.0;

        while (! str_contains($holder->getOutput(), 'LOCKED') && microtime(true) < $deadline) {
            usleep(50_000);
        }

        $this->assertStringContainsString('LOCKED', $holder->getOutput(), 'The holder never acquired the Business lock: ' . $holder->getErrorOutput());

        return $holder;
    }

    private function childEnvironment(): array
    {
        $database = TestDatabaseSafety::activeTestDatabase();

        return ['DB_DATABASE' => $database, 'EXPECTED_TEST_DATABASE' => $database];
    }
}
