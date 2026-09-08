<?php

namespace Tests\Feature\Business;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Enums\Business\BusinessServiceMode;
use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AppConfig;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Customer Experience Slice 1A — real cross-process concurrency for
 * physical-location capacity.
 *
 * Two genuinely independent OS processes race for the SAME final location
 * slot against the same MySQL database. A single PHPUnit process cannot
 * prove this, and a preflight count followed by an unlocked insert would
 * silently pass a sequential test while failing in production — which is
 * exactly why BusinessLocationManager takes the Business row lock before
 * asserting capacity.
 *
 * Deliberately does NOT use RefreshDatabase: a separate process needs
 * COMMITTED rows, which an open RefreshDatabase transaction would hide
 * entirely. Fixture rows are inserted directly and removed in tearDown(),
 * following EntitlementManagerConcurrencyTest's proven pattern.
 */
class BusinessLocationConcurrencyTest extends TestCase
{
    private const RUNNER = __DIR__ . '/Support/concurrent_location_runner.php';

    private array $createdUserIds = [];
    private array $createdWorkspaceIds = [];
    private array $createdBusinessIds = [];

    protected function tearDown(): void
    {
        if ($this->createdBusinessIds !== []) {
            DB::table('business_locations')->whereIn('business_id', $this->createdBusinessIds)->delete();
            DB::table('businesses')->whereIn('id', $this->createdBusinessIds)->delete();
        }

        if ($this->createdWorkspaceIds !== []) {
            DB::table('workspace_entitlement_transitions')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
            DB::table('workspace_plan_assignments')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
            DB::table('workspaces')->whereIn('id', $this->createdWorkspaceIds)->delete();
        }

        if ($this->createdUserIds !== []) {
            DB::table('customers')->whereIn('user_id', $this->createdUserIds)->delete();
            DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
        }

        parent::tearDown();
    }

    /**
     * Two concurrent creations racing the FINAL available slot: exactly one
     * succeeds, and the Business never exceeds its capacity.
     */
    public function test_concurrent_creation_cannot_exceed_capacity(): void
    {
        [$business] = $this->committedTenant(activeLocations: 2);

        // Capacity is 3 included; 2 already active, so exactly ONE slot is
        // left and two processes race for it.
        $first = new Process([$this->phpBinary(), self::RUNNER, 'create-location', (string) $business->id]);
        $second = new Process([$this->phpBinary(), self::RUNNER, 'create-location', (string) $business->id]);

        $first->start();
        $second->start();
        $first->wait();
        $second->wait();

        $succeeded = (int) $first->isSuccessful() + (int) $second->isSuccessful();

        $this->assertSame(
            1,
            $succeeded,
            "Exactly one concurrent creation must win the final slot.\n"
            . 'First: ' . $first->getErrorOutput() . "\nSecond: " . $second->getErrorOutput()
        );

        $this->assertSame(3, $this->activeCount($business->id), 'Capacity must never be exceeded by a race.');
    }

    /**
     * The same race, but one process creates while the other reactivates an
     * archived location. Both increase the active count, so the row lock
     * must serialize them too.
     */
    public function test_concurrent_reactivation_and_creation_cannot_exceed_capacity(): void
    {
        [$business, $archivedUid] = $this->committedTenant(activeLocations: 2, withArchived: true);

        $creator = new Process([$this->phpBinary(), self::RUNNER, 'create-location', (string) $business->id]);
        $reactivator = new Process([$this->phpBinary(), self::RUNNER, 'reactivate', (string) $business->id, (string) $archivedUid]);

        $creator->start();
        $reactivator->start();
        $creator->wait();
        $reactivator->wait();

        $succeeded = (int) $creator->isSuccessful() + (int) $reactivator->isSuccessful();

        $this->assertSame(
            1,
            $succeeded,
            "Exactly one of create/reactivate must win the final slot.\n"
            . 'Creator: ' . $creator->getErrorOutput() . "\nReactivator: " . $reactivator->getErrorOutput()
        );

        $this->assertSame(3, $this->activeCount($business->id));
    }

    /**
     * Two concurrent reactivations of two DIFFERENT archived locations,
     * with one slot free: exactly one wins.
     */
    public function test_concurrent_reactivation_cannot_exceed_capacity(): void
    {
        [$business, $firstArchived, $secondArchived] = $this->committedTenantWithTwoArchived();

        $one = new Process([$this->phpBinary(), self::RUNNER, 'reactivate', (string) $business->id, (string) $firstArchived]);
        $two = new Process([$this->phpBinary(), self::RUNNER, 'reactivate', (string) $business->id, (string) $secondArchived]);

        $one->start();
        $two->start();
        $one->wait();
        $two->wait();

        $succeeded = (int) $one->isSuccessful() + (int) $two->isSuccessful();

        $this->assertSame(
            1,
            $succeeded,
            "Exactly one concurrent reactivation must win the final slot.\n"
            . 'One: ' . $one->getErrorOutput() . "\nTwo: " . $two->getErrorOutput()
        );

        $this->assertSame(3, $this->activeCount($business->id));
    }

    /**
     * An allocation racing a creation cannot produce an invalid state:
     * whichever order they land in, the active count never exceeds the
     * capacity that actually exists at the end.
     */
    public function test_allocation_and_creation_cannot_race_into_an_invalid_state(): void
    {
        [$business, , , $operatorUserId] = $this->committedTenant(activeLocations: 3, returnOperator: true);

        $allocator = new Process([$this->phpBinary(), self::RUNNER, 'allocate', (string) $business->id, (string) $operatorUserId]);
        $creator = new Process([$this->phpBinary(), self::RUNNER, 'create-location', (string) $business->id]);

        $allocator->start();
        $creator->start();
        $allocator->wait();
        $creator->wait();

        $this->assertTrue($allocator->isSuccessful(), 'Allocating must succeed: ' . $allocator->getErrorOutput());

        $allocated = (int) DB::table('businesses')->where('id', $business->id)->value('additional_location_slots');
        $active = $this->activeCount($business->id);

        $this->assertSame(1, $allocated, 'Exactly one allocation must be recorded — never a double increment.');
        $this->assertLessThanOrEqual(
            3 + $allocated,
            $active,
            'The active count must never exceed included + allocated, whichever order the race resolved in.'
        );

        // Exactly one allocation transition row — retries must not duplicate it.
        $this->assertSame(
            1,
            (int) DB::table('workspace_entitlement_transitions')
                ->where('workspace_id', $business->workspace_id)
                ->where('transition_type', 'additional_location_slots_changed')
                ->count()
        );
    }

    // -----------------------------------------------------------------
    // Committed fixtures (no RefreshDatabase — see the class docblock)
    // -----------------------------------------------------------------

    private function committedTenant(int $activeLocations, bool $withArchived = false, bool $returnOperator = false): array
    {
        $this->ensureAppConfig();

        $userId = DB::table('users')->insertGetId([
            'uid' => (string) Str::uuid(),
            'first_name' => 'Race', 'last_name' => 'Owner',
            'email' => 'race' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true,
            'active_portal' => 'customer', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->createdUserIds[] = $userId;
        DB::table('customers')->insert(['user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);

        $adminId = DB::table('users')->insertGetId([
            'uid' => (string) Str::uuid(),
            'first_name' => 'Race', 'last_name' => 'Admin',
            'email' => 'raceadmin' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false,
            'active_portal' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->createdUserIds[] = $adminId;

        $customer = Customer::query()->where('user_id', $userId)->firstOrFail();

        $workspace = Workspace::create([
            'name' => 'Race Workspace',
            'owner_user_id' => $userId,
            'is_active' => true,
        ]);
        $this->createdWorkspaceIds[] = $workspace->id;

        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, [
            'name' => 'Race Co', 'industry' => 'photo_booth_service',
            'country_code' => 'US', 'timezone' => 'America/New_York', 'currency_code' => 'USD',
        ]);
        $this->createdBusinessIds[] = $business->id;

        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Active->value]);

        app(EntitlementManager::class)->assignFirstPlan(
            $workspace, WorkspacePlanTier::Growth, $adminId, 'Race fixture.', true, 0
        );

        for ($i = 1; $i <= $activeLocations; $i++) {
            $this->insertLocation($business->id, 'Race Branch ' . $i, $i === 1, BusinessLocationLifecycleState::Active);
        }

        $archivedUid = null;

        if ($withArchived) {
            $archivedUid = $this->insertLocation($business->id, 'Race Archived', false, BusinessLocationLifecycleState::Archived);
        }

        // Correction round 1 — the paid-allocation seam demands operator
        // (or verified-billing) provenance, so the race fixture hands back
        // the ADMINISTRATOR id, not the customer owner id.
        return $returnOperator
            ? [$business->fresh(), $archivedUid, null, $adminId]
            : [$business->fresh(), $archivedUid];
    }

    private function committedTenantWithTwoArchived(): array
    {
        [$business] = $this->committedTenant(activeLocations: 2);

        $first = $this->insertLocation($business->id, 'Archived One', false, BusinessLocationLifecycleState::Archived);
        $second = $this->insertLocation($business->id, 'Archived Two', false, BusinessLocationLifecycleState::Archived);

        return [$business, $first, $second];
    }

    private function insertLocation(int $businessId, string $name, bool $isPrimary, BusinessLocationLifecycleState $state): string
    {
        $uid = (string) Str::uuid();

        DB::table('business_locations')->insert([
            'uid' => $uid,
            'business_id' => $businessId,
            'name' => $name,
            'service_mode' => BusinessServiceMode::Storefront->value,
            'address_line_1' => '1 Race Street',
            'city' => 'New York',
            'country_code' => 'US',
            'public_address' => true,
            'is_primary' => $isPrimary,
            'lifecycle_state' => $state->value,
            'archived_at' => $state === BusinessLocationLifecycleState::Archived ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $uid;
    }

    private function activeCount(int $businessId): int
    {
        return (int) DB::table('business_locations')
            ->where('business_id', $businessId)
            ->where('lifecycle_state', BusinessLocationLifecycleState::Active->value)
            ->count();
    }

    private function ensureAppConfig(): void
    {
        if (! AppConfig::query()->where('setting', 'license')->exists()) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }
    }

    private function phpBinary(): string
    {
        return (new PhpExecutableFinder())->find() ?: 'php';
    }
}
