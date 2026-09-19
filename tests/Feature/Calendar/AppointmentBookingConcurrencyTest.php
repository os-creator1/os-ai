<?php

namespace Tests\Feature\Calendar;

use App\Enums\Calendar\AppointmentStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * Implementation Contract 15 §7, §12.C — REAL concurrency for the booking
 * engine. Four races, none of them mocking a lock:
 *
 *   A. two overlapping create attempts for the SAME staff member — at most
 *      one appointment exists afterwards, and the loser is refused by the
 *      domain rule rather than by a raw database error;
 *   B. two FIRST-EVER bookings for a staff member with no
 *      `staff_booking_locks` row at all (§7.2) — still exactly one
 *      appointment, no duplicate-key error, no unserialized second write;
 *   C. reschedule racing cancel (§7.4) — exactly one wins, and the row is
 *      never left half-rescheduled, half-cancelled;
 *   D. two round-robin bookings for one Booking Type (§7.3) — no lost cursor
 *      update and no duplicate assignment.
 *
 * Deliberately does NOT use RefreshDatabase: a genuinely separate process
 * needs COMMITTED rows, which an open RefreshDatabase transaction would hide
 * entirely — the same rationale AgencyClientRelationshipConcurrencyTest and
 * WorkspaceManagerConcurrencyTest already record. Fixtures are inserted
 * directly and removed in tearDown().
 *
 * THE RACE IS SYNCHRONIZED, NOT ASSUMED. Every child busy-waits to a shared
 * epoch instant before entering the engine and reports the microsecond it
 * actually entered, so each test PROVES the two critical sections overlapped
 * instead of hoping process startup happened to line up. Race B cannot use
 * the parent-holds-a-row-lock gate the other tests could, because its whole
 * premise is that the contested row does not exist yet — which is exactly why
 * a synchronized start is used uniformly.
 */
class AppointmentBookingConcurrencyTest extends TestCase
{
    /** How far ahead the shared start instant is set, to absorb child boot time. */
    private const START_GATE_MICROSECONDS = 2_500_000;

    /** Two children that genuinely raced entered within this of each other. */
    private const MAX_ENTRY_SKEW_MICROSECONDS = 750_000;

    private array $createdUserIds = [];

    private array $createdWorkspaceIds = [];

    private array $createdBusinessIds = [];

    private array $createdLocationIds = [];

    private array $createdBookingTypeIds = [];

    private array $createdContactIds = [];

    private array $createdContactGroupIds = [];

    private array $createdCustomerUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Every write below is committed for real, so the disposable-database
        // guard runs before the first one rather than after.
        $this->assertSame(TestDatabaseSafety::activeTestDatabase(), DB::connection()->getDatabaseName());
    }

    protected function tearDown(): void
    {
        $this->deleteFixtures();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // A — overlapping creates for one staff member
    // -----------------------------------------------------------------

    public function test_two_concurrent_overlapping_bookings_for_one_staff_member_produce_exactly_one_appointment(): void
    {
        [$bookingTypeId, $staffUserId, $locationId] = $this->scenario();
        $contactA = $this->insertContact($locationId);
        $contactB = $this->insertContact($locationId);
        $slot = $this->slot('10:00:00');

        // Pre-create the lock row so THIS test isolates the overlap race
        // rather than also exercising first use — race B covers that.
        DB::table('staff_booking_locks')->insertOrIgnore([[
            'staff_user_id' => $staffUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]]);

        $results = $this->race([
            ['book', (string) $bookingTypeId, (string) $staffUserId, (string) $contactA, $slot],
            ['book', (string) $bookingTypeId, (string) $staffUserId, (string) $contactB, $slot],
        ]);

        $this->assertGenuinelyRaced($results);
        $this->assertExactlyOneCommitted($results);
        $this->assertLoserWasRefusedByTheDomainRule($results, 'AppointmentSlotUnavailableException');

        $this->assertSame(
            1,
            DB::table('appointments')->where('staff_user_id', $staffUserId)->count(),
            'Two overlapping bookings for one staff member must never both succeed.'
        );
    }

    // -----------------------------------------------------------------
    // B — first-ever bookings, no lock row in existence (§7.2)
    // -----------------------------------------------------------------

    public function test_two_first_ever_concurrent_bookings_still_serialize(): void
    {
        [$bookingTypeId, $staffUserId, $locationId] = $this->scenario();
        $contactA = $this->insertContact($locationId);
        $contactB = $this->insertContact($locationId);
        $slot = $this->slot('10:00:00');

        // The premise of §7.2: there is no serialization row yet, so both
        // children must create it and still end up serialized.
        $this->assertSame(
            0,
            DB::table('staff_booking_locks')->where('staff_user_id', $staffUserId)->count(),
            'This race is only meaningful with no lock row in existence.'
        );

        $results = $this->race([
            ['book', (string) $bookingTypeId, (string) $staffUserId, (string) $contactA, $slot],
            ['book', (string) $bookingTypeId, (string) $staffUserId, (string) $contactB, $slot],
        ]);

        $this->assertGenuinelyRaced($results);
        $this->assertExactlyOneCommitted($results);

        // The loser must lose to the DOMAIN rule, never to a duplicate-key
        // error from two concurrent INSERTs of the same lock row — that is
        // precisely what insertOrIgnore outside the transaction buys (§7.2).
        $this->assertLoserWasRefusedByTheDomainRule($results, 'AppointmentSlotUnavailableException');

        $this->assertSame(
            1,
            DB::table('appointments')->where('staff_user_id', $staffUserId)->count(),
            'Two first-ever concurrent bookings must produce exactly one appointment.'
        );
        $this->assertSame(
            1,
            DB::table('staff_booking_locks')->where('staff_user_id', $staffUserId)->count(),
            'The lock row is created exactly once, by whichever process got there first.'
        );
    }

    // -----------------------------------------------------------------
    // C — reschedule racing cancel (§7.4)
    // -----------------------------------------------------------------

    public function test_reschedule_racing_cancel_resolves_deterministically(): void
    {
        [$bookingTypeId, $staffUserId, $locationId] = $this->scenario();
        $contactId = $this->insertContact($locationId);

        $appointmentId = $this->insertAppointment($bookingTypeId, $staffUserId, $locationId, $contactId, $this->slot('10:00:00'));
        $originalStart = (string) DB::table('appointments')->where('id', $appointmentId)->value('start_at');

        $results = $this->race([
            ['reschedule', (string) $appointmentId, $this->slot('14:00:00')],
            ['cancel', (string) $appointmentId],
        ]);

        $this->assertGenuinelyRaced($results);

        $row = DB::table('appointments')->where('id', $appointmentId)->first();

        $rescheduleCommitted = $results[0]['exitCode'] === 0;
        $cancelCommitted = $results[1]['exitCode'] === 0;

        // Both may legitimately succeed — a cancel of an already-rescheduled
        // appointment is valid (§7.4). What must NEVER happen is a row that is
        // half one and half the other, or a second transition out of a
        // terminal state.
        $this->assertTrue(
            $rescheduleCommitted || $cancelCommitted,
            'At least one of the two transitions must have committed.'
        );

        if ($cancelCommitted) {
            $this->assertSame(AppointmentStatus::Cancelled->value, $row->status);
            $this->assertNotNull($row->resolved_at);
        } else {
            // Cancel lost: it must have been refused by the state machine, and
            // the appointment must be the rescheduled one, still scheduled.
            $this->assertSame(4, $results[1]['exitCode'], 'A losing cancel must be refused by the domain rule.');
            $this->assertStringContainsString('InvalidAppointmentTransitionException', $results[1]['stdout']);
            $this->assertSame(AppointmentStatus::Scheduled->value, $row->status);
        }

        if (! $rescheduleCommitted) {
            $this->assertSame(4, $results[0]['exitCode'], 'A losing reschedule must be refused by the domain rule.');
            $this->assertStringContainsString('InvalidAppointmentTransitionException', $results[0]['stdout']);
            $this->assertSame(
                $originalStart,
                (string) $row->start_at,
                'A refused reschedule must leave the original interval byte-identical.'
            );
            $this->assertSame(0, (int) $row->reschedule_count);
        } else {
            $this->assertSame(1, (int) $row->reschedule_count);
        }

        $this->assertSame(
            1,
            DB::table('appointments')->where('id', $appointmentId)->count(),
            'Neither path may duplicate the appointment.'
        );
    }

    // -----------------------------------------------------------------
    // D — concurrent round-robin (§7.3)
    // -----------------------------------------------------------------

    /**
     * DELIBERATELY NON-OVERLAPPING INTERVALS, and that is the whole point.
     *
     * If the two requests contended for the same interval, tier 2 alone would
     * separate them — the second would find the first's staff member busy and
     * move on — so such a test would pass even with no tier-1 lock at all and
     * would prove nothing about the cursor.
     *
     * With disjoint intervals every staff member is legitimately free for both
     * requests, so tier 2 can no longer distinguish them. The ONLY thing that
     * stops both transactions reading the same cursor and picking the same
     * first candidate is tier 1 (§7.3): the second waits, then computes its
     * successor from the first's COMMITTED result.
     *
     * Without the tier-1 lock this test fails with both bookings assigned to
     * the lowest id and the rotation permanently stuck there.
     */
    public function test_concurrent_round_robin_bookings_do_not_lose_the_cursor_or_double_assign(): void
    {
        [$bookingTypeId, $firstStaffId, $locationId, $secondStaffId] = $this->scenario(withSecondStaff: true);
        $contactA = $this->insertContact($locationId);
        $contactB = $this->insertContact($locationId);

        $results = $this->race([
            ['roundrobin', (string) $bookingTypeId, (string) $contactA, $this->slot('10:00:00')],
            ['roundrobin', (string) $bookingTypeId, (string) $contactB, $this->slot('15:00:00')],
        ]);

        $this->assertGenuinelyRaced($results);

        $committed = array_values(array_filter($results, static fn (array $r): bool => $r['exitCode'] === 0));
        $this->assertCount(2, $committed, 'Both disjoint intervals are bookable, so both must commit.');

        $appointments = DB::table('appointments')
            ->whereIn('staff_user_id', [$firstStaffId, $secondStaffId])
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $appointments);

        $assigned = $appointments->pluck('staff_user_id')->map(static fn ($id): int => (int) $id)->all();

        $expected = [$firstStaffId, $secondStaffId];
        sort($expected);
        $sortedAssigned = $assigned;
        sort($sortedAssigned);

        $this->assertSame(
            $expected,
            $sortedAssigned,
            'Two concurrent round-robin requests over disjoint intervals must still rotate: '
            . 'assigning the same staff member twice means the cursor was read before the other transaction committed.'
        );

        // Tier 1 is held for the whole assignment, so the transactions commit
        // in a definite order and the LATER appointment (higher auto-increment
        // id) is the one whose assignment the cursor must record. A lost cursor
        // update would leave it naming the earlier assignee instead.
        $cursor = (int) DB::table('booking_type_round_robin_state')
            ->where('booking_type_id', $bookingTypeId)
            ->value('last_assigned_staff_user_id');

        $this->assertSame(
            (int) $appointments->last()->staff_user_id,
            $cursor,
            'The cursor must record the assignment that committed last — anything else is a lost update.'
        );
    }

    /**
     * The same proof at greater width: three racers, three staff members,
     * three disjoint intervals. Every staff member is free for every interval,
     * so only tier 1 can produce three distinct assignees.
     */
    public function test_three_concurrent_round_robin_bookings_assign_three_distinct_staff(): void
    {
        [$bookingTypeId, $firstStaffId, $locationId, $secondStaffId, $thirdStaffId] = $this->scenario(
            withSecondStaff: true,
            withThirdStaff: true
        );

        $results = $this->race([
            ['roundrobin', (string) $bookingTypeId, (string) $this->insertContact($locationId), $this->slot('09:00:00')],
            ['roundrobin', (string) $bookingTypeId, (string) $this->insertContact($locationId), $this->slot('13:00:00')],
            ['roundrobin', (string) $bookingTypeId, (string) $this->insertContact($locationId), $this->slot('17:00:00')],
        ]);

        $this->assertGenuinelyRaced($results);

        $committed = array_filter($results, static fn (array $r): bool => $r['exitCode'] === 0);
        $this->assertCount(3, $committed);

        $assigned = DB::table('appointments')
            ->whereIn('staff_user_id', [$firstStaffId, $secondStaffId, $thirdStaffId])
            ->orderBy('id')
            ->pluck('staff_user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->assertCount(3, $assigned);
        $this->assertCount(3, array_unique($assigned), 'Three racing round-robin requests must assign three distinct staff members.');

        $cursor = (int) DB::table('booking_type_round_robin_state')
            ->where('booking_type_id', $bookingTypeId)
            ->value('last_assigned_staff_user_id');

        $this->assertSame(end($assigned), $cursor);
    }

    // -----------------------------------------------------------------
    // Race harness
    // -----------------------------------------------------------------

    /**
     * Starts one child per argument set, all handed the SAME start instant, and
     * returns each one's exit code and output.
     *
     * @param  array<int, array<int, string>>  $operations
     * @return array<int, array{exitCode: int, stdout: string, stderr: string, enteredUs: int}>
     */
    private function race(array $operations): array
    {
        $runner = __DIR__ . '/Support/concurrent_booking_runner.php';
        $php = (new PhpExecutableFinder())->find() ?: 'php';
        $startAt = (int) (microtime(true) * 1_000_000) + self::START_GATE_MICROSECONDS;

        $processes = [];

        foreach ($operations as $operation) {
            $command = array_merge([$php, $runner, array_shift($operation), (string) $startAt], $operation);
            $processes[] = new Process($command, null, $this->childEnvironment(), null, 60.0);
        }

        foreach ($processes as $process) {
            $process->start();
        }

        $results = [];

        foreach ($processes as $process) {
            $process->wait();

            $stdout = $process->getOutput();

            $results[] = [
                'exitCode' => (int) $process->getExitCode(),
                'stdout' => $stdout,
                'stderr' => $process->getErrorOutput(),
                'enteredUs' => $this->enteredMicrosecond($stdout),
            ];
        }

        foreach ($results as $index => $result) {
            $this->assertNotSame(
                3,
                $result['exitCode'],
                "Child {$index} refused to run against an unexpected database: {$result['stderr']}"
            );
            $this->assertNotSame(
                1,
                $result['exitCode'],
                "Child {$index} failed unexpectedly: {$result['stderr']}"
            );
        }

        return $results;
    }

    /**
     * Proves the two critical sections genuinely overlapped rather than the
     * children running one after the other — without which every assertion
     * below would pass vacuously.
     *
     * @param  array<int, array{enteredUs: int}>  $results
     */
    private function assertGenuinelyRaced(array $results): void
    {
        $entries = array_map(static fn (array $r): int => $r['enteredUs'], $results);

        foreach ($entries as $index => $entry) {
            $this->assertGreaterThan(0, $entry, "Child {$index} never reported entering the engine.");
        }

        $skew = max($entries) - min($entries);

        $this->assertLessThan(
            self::MAX_ENTRY_SKEW_MICROSECONDS,
            $skew,
            "The children entered {$skew}us apart, which is too far to be a genuine race."
        );
    }

    /** @param array<int, array{exitCode: int, stdout: string}> $results */
    private function assertExactlyOneCommitted(array $results): void
    {
        $committed = array_filter($results, static fn (array $r): bool => $r['exitCode'] === 0);

        $this->assertCount(
            1,
            $committed,
            'Exactly one process must commit; outputs: ' . json_encode(array_column($results, 'stdout'))
        );
    }

    /** @param array<int, array{exitCode: int, stdout: string}> $results */
    private function assertLoserWasRefusedByTheDomainRule(array $results, string $expectedException): void
    {
        $losers = array_values(array_filter($results, static fn (array $r): bool => $r['exitCode'] !== 0));

        $this->assertCount(1, $losers);
        $this->assertSame(4, $losers[0]['exitCode'], 'The loser must exit with the domain-refusal code.');
        $this->assertStringContainsString($expectedException, $losers[0]['stdout']);
    }

    private function enteredMicrosecond(string $stdout): int
    {
        return preg_match('/entered_us=(\d+)/', $stdout, $matches) === 1 ? (int) $matches[1] : 0;
    }

    /**
     * Forwarded explicitly to every spawned runner, mirroring the proven
     * pattern: the child must resolve the very same validated disposable
     * database this parent is running against, never a hardcoded literal.
     *
     * @return array<string, string>
     */
    private function childEnvironment(): array
    {
        $database = TestDatabaseSafety::activeTestDatabase();

        return [
            'DB_DATABASE' => $database,
            'EXPECTED_TEST_DATABASE' => $database,
        ];
    }

    // -----------------------------------------------------------------
    // Fixtures (committed, so the children can see them)
    // -----------------------------------------------------------------

    /**
     * A Workspace + Business + Location + Booking Type + one or two eligible,
     * available staff members. The staff members are Workspace OWNERS of
     * nothing and members of everything they need, so LocationAccessGuard
     * genuinely answers true for them.
     *
     * @return array<int, int> [bookingTypeId, firstStaffId, locationId, secondStaffId?, thirdStaffId?]
     */
    private function scenario(bool $withSecondStaff = false, bool $withThirdStaff = false): array
    {
        $ownerId = $this->insertUser('Owner');
        $this->insertCustomer($ownerId);

        $workspaceId = DB::table('workspaces')->insertGetId([
            'uid' => (string) Str::uuid(),
            'name' => 'Booking Concurrency Workspace',
            'owner_user_id' => $ownerId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createdWorkspaceIds[] = $workspaceId;

        $businessId = DB::table('businesses')->insertGetId([
            'uid' => (string) Str::uuid(),
            'customer_id' => $ownerId,
            'workspace_id' => $workspaceId,
            'name' => 'Booking Concurrency Business',
            'industry' => 'photo_booth_service',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createdBusinessIds[] = $businessId;

        $locationId = DB::table('business_locations')->insertGetId([
            'uid' => (string) Str::uuid(),
            'business_id' => $businessId,
            'name' => 'Concurrency Location',
            'service_mode' => 'storefront',
            'country_code' => 'US',
            'lifecycle_state' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createdLocationIds[] = $locationId;

        $bookingTypeId = DB::table('booking_types')->insertGetId([
            'uid' => (string) Str::uuid(),
            'public_booking_uuid' => (string) Str::uuid(),
            'business_location_id' => $locationId,
            'name' => 'Concurrent Consultation',
            'duration_minutes' => 60,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createdBookingTypeIds[] = $bookingTypeId;

        $firstStaffId = $this->insertEligibleStaff($workspaceId, $locationId, $bookingTypeId);

        if (! $withSecondStaff) {
            return [$bookingTypeId, $firstStaffId, $locationId];
        }

        $secondStaffId = $this->insertEligibleStaff($workspaceId, $locationId, $bookingTypeId);

        if (! $withThirdStaff) {
            return [$bookingTypeId, $firstStaffId, $locationId, $secondStaffId];
        }

        return [$bookingTypeId, $firstStaffId, $locationId, $secondStaffId, $this->insertEligibleStaff($workspaceId, $locationId, $bookingTypeId)];
    }

    private function insertEligibleStaff(int $workspaceId, int $locationId, int $bookingTypeId): int
    {
        $staffId = $this->insertUser('Staff');
        $this->insertCustomer($staffId);

        DB::table('workspace_memberships')->insert([
            'workspace_id' => $workspaceId,
            'user_id' => $staffId,
            'role' => 'staff',
            'business_access_scope' => 'all',
            'location_access_scope' => 'all',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('staff_availability_rules')->insert([
            'business_location_id' => $locationId,
            'staff_user_id' => $staffId,
            'day_of_week' => 1,
            'start_time' => '08:00:00',
            'end_time' => '20:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('booking_type_staff')->insert([
            'booking_type_id' => $bookingTypeId,
            'staff_user_id' => $staffId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $staffId;
    }

    private function insertUser(string $label): int
    {
        $userId = DB::table('users')->insertGetId([
            'uid' => (string) Str::uuid(),
            'first_name' => 'Booking',
            'last_name' => $label,
            'email' => 'booking-' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createdUserIds[] = $userId;

        return $userId;
    }

    private function insertCustomer(int $userId): void
    {
        DB::table('customers')->insert([
            'uid' => (string) Str::uuid(),
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createdCustomerUserIds[] = $userId;
    }

    private function insertContact(int $locationId): int
    {
        $businessId = end($this->createdBusinessIds);
        $customerId = DB::table('businesses')->where('id', $businessId)->value('customer_id');

        $groupId = DB::table('contact_groups')->insertGetId([
            'uid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'business_id' => $businessId,
            'name' => 'Concurrency Group ' . uniqid(),
            'status' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createdContactGroupIds[] = $groupId;

        $contactId = DB::table('contacts')->insertGetId([
            'uid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'business_id' => $businessId,
            'location_id' => $locationId,
            'group_id' => $groupId,
            'phone' => '1415' . random_int(1000000, 9999999),
            'status' => 'subscribe',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createdContactIds[] = $contactId;

        return $contactId;
    }

    private function insertAppointment(int $bookingTypeId, int $staffUserId, int $locationId, int $contactId, string $slotIso): int
    {
        $start = Carbon::parse($slotIso);

        return DB::table('appointments')->insertGetId([
            'uid' => (string) Str::uuid(),
            'business_location_id' => $locationId,
            'booking_type_id' => $bookingTypeId,
            'staff_user_id' => $staffUserId,
            'contact_id' => $contactId,
            'status' => AppointmentStatus::Scheduled->value,
            'start_at' => $start,
            'end_at' => $start->copy()->addMinutes(60),
            'reschedule_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A Monday, clear of any DST edge, expressed in UTC for the child. */
    private function slot(string $time): string
    {
        return Carbon::parse('2027-03-01 ' . $time, 'America/New_York')->utc()->toDateTimeString();
    }

    private function deleteFixtures(): void
    {
        DB::table('appointments')->whereIn('booking_type_id', $this->createdBookingTypeIds ?: [0])->delete();
        DB::table('booking_type_round_robin_state')->whereIn('booking_type_id', $this->createdBookingTypeIds ?: [0])->delete();
        DB::table('booking_type_staff')->whereIn('booking_type_id', $this->createdBookingTypeIds ?: [0])->delete();
        DB::table('staff_availability_rules')->whereIn('business_location_id', $this->createdLocationIds ?: [0])->delete();
        DB::table('staff_time_off')->whereIn('staff_user_id', $this->createdUserIds ?: [0])->delete();
        DB::table('staff_booking_locks')->whereIn('staff_user_id', $this->createdUserIds ?: [0])->delete();
        DB::table('booking_types')->whereIn('id', $this->createdBookingTypeIds ?: [0])->delete();
        DB::table('contacts')->whereIn('id', $this->createdContactIds ?: [0])->delete();
        DB::table('contact_groups')->whereIn('id', $this->createdContactGroupIds ?: [0])->delete();
        DB::table('business_locations')->whereIn('id', $this->createdLocationIds ?: [0])->delete();
        DB::table('workspace_memberships')->whereIn('workspace_id', $this->createdWorkspaceIds ?: [0])->delete();
        DB::table('businesses')->whereIn('id', $this->createdBusinessIds ?: [0])->delete();
        DB::table('workspaces')->whereIn('id', $this->createdWorkspaceIds ?: [0])->delete();
        DB::table('customers')->whereIn('user_id', $this->createdCustomerUserIds ?: [0])->delete();
        DB::table('users')->whereIn('id', $this->createdUserIds ?: [0])->delete();

        $this->createdBookingTypeIds = [];
        $this->createdContactIds = [];
        $this->createdContactGroupIds = [];
        $this->createdLocationIds = [];
        $this->createdBusinessIds = [];
        $this->createdWorkspaceIds = [];
        $this->createdCustomerUserIds = [];
        $this->createdUserIds = [];
    }
}
