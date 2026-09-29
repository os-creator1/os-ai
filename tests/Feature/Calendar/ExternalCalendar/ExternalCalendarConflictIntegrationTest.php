<?php

namespace Tests\Feature\Calendar\ExternalCalendar;

use App\Enums\Calendar\ExternalCalendarProvider;
use App\Exceptions\Calendar\AppointmentSlotUnavailableException;
use App\Library\Calendar\ExternalCalendar\ExternalCalendarSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Calendar\Concerns\CreatesBookingEngineFixtures;
use Tests\Feature\Calendar\ExternalCalendar\Concerns\CreatesExternalCalendarFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 15 §7.6/§12.F — wiring external_calendar_busy_blocks
 * into BookingConflictDetector as the second busy source.
 *
 * Covers the task's explicit requirements: a staff member's external busy
 * block prevents booking across Location A and Location B; another staff
 * member is unaffected; provider failure cannot create/alter appointments
 * (fail-safe-stale honored end-to-end through the real booking engine, not
 * just the sync service in isolation).
 */
class ExternalCalendarConflictIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBookingEngineFixtures;
    use CreatesExternalCalendarFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCalendarAuthorityFixtures();
        $this->bindFakeCalendarProviders();
    }

    public function test_an_external_busy_block_refuses_a_booking_at_location_a(): void
    {
        $staff = $this->bookableStaff($this->locationA);
        $connection = $this->createActiveConnection($staff, ExternalCalendarProvider::Google);
        $type = $this->bookingType($this->locationA);
        $start = $this->slotStart();

        $this->seedBusyBlock($connection->id, $start->clone()->subMinutes(15), $start->clone()->addMinutes(15));

        $this->expectException(AppointmentSlotUnavailableException::class);
        $this->engine()->book($type, (int) $staff->id, $this->contactId(), $start, (int) $this->owner->user_id);
    }

    public function test_an_external_busy_block_refuses_a_booking_at_location_b_too_cross_location(): void
    {
        // Blueprint §12 — external busy/free is consulted for EVERY
        // Location the staff member is scheduled into, structurally, not
        // per-Location. A single connection, checked against a Booking
        // Type that belongs to Location B.
        $staff = $this->bookableStaff($this->locationA);
        $this->giveMondayAvailability((int) $staff->id, $this->locationB);
        $connection = $this->createActiveConnection($staff, ExternalCalendarProvider::Google);
        $typeAtB = $this->bookingType($this->locationB);
        $start = $this->slotStart();

        $this->seedBusyBlock($connection->id, $start->clone()->subMinutes(30), $start->clone()->addMinutes(90));

        $this->expectException(AppointmentSlotUnavailableException::class);
        $this->engine()->book($typeAtB, (int) $staff->id, $this->contactId(), $start, (int) $this->owner->user_id);
    }

    public function test_another_staff_member_without_the_connection_is_unaffected(): void
    {
        $busyStaff = $this->bookableStaff($this->locationA);
        $connection = $this->createActiveConnection($busyStaff, ExternalCalendarProvider::Google);
        $start = $this->slotStart();
        $this->seedBusyBlock($connection->id, $start->clone()->subMinutes(15), $start->clone()->addMinutes(15));

        $freeStaff = $this->bookableStaff($this->locationA);
        $type = $this->bookingType($this->locationA);

        $appointment = $this->engine()->book($type, (int) $freeStaff->id, $this->contactId(), $start, (int) $this->owner->user_id);

        $this->assertSame((int) $freeStaff->id, (int) $appointment->staff_user_id);
    }

    public function test_a_non_overlapping_external_event_does_not_refuse_the_booking(): void
    {
        $staff = $this->bookableStaff($this->locationA);
        $connection = $this->createActiveConnection($staff, ExternalCalendarProvider::Google);
        $type = $this->bookingType($this->locationA);
        $start = $this->slotStart();

        // Busy well before the candidate interval — half-open [start, end)
        // means no overlap.
        $this->seedBusyBlock($connection->id, $start->clone()->subHours(3), $start->clone()->subHours(2));

        $appointment = $this->engine()->book($type, (int) $staff->id, $this->contactId(), $start, (int) $this->owner->user_id);

        $this->assertSame((int) $staff->id, (int) $appointment->staff_user_id);
    }

    public function test_a_provider_outage_during_sync_never_blocks_or_corrupts_an_internal_booking(): void
    {
        $staff = $this->bookableStaff($this->locationA);
        $connection = $this->createActiveConnection($staff, ExternalCalendarProvider::Google, ['sync_cursor' => 'seed']);
        $type = $this->bookingType($this->locationA);
        $start = $this->slotStart();

        // No busy cache at all — the provider has simply never been synced
        // successfully. A failing sync attempt must not create a phantom
        // conflict, corrupt state, or throw out of the booking path.
        $this->fakeGoogle->incrementalBusyQueue[] = \App\Exceptions\Calendar\ExternalCalendarProviderException::providerUnavailable();
        app(ExternalCalendarSyncService::class)->syncConnection($connection);

        $appointment = $this->engine()->book($type, (int) $staff->id, $this->contactId(), $start, (int) $this->owner->user_id);

        $this->assertSame((int) $staff->id, (int) $appointment->staff_user_id);
        $this->assertSame(1, DB::table('appointments')->count());
    }

    private function seedBusyBlock(int $connectionId, $start, $end): void
    {
        DB::table('external_calendar_busy_blocks')->insert([
            'external_calendar_connection_id' => $connectionId,
            'provider_event_id' => 'seed-' . uniqid(),
            'start_at' => $start,
            'end_at' => $end,
            'busy_type' => 'busy',
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
