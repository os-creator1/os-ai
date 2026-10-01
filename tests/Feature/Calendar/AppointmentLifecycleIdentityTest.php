<?php

namespace Tests\Feature\Calendar;

use App\Events\Calendar\AppointmentCancelled;
use App\Events\Calendar\AppointmentCompleted;
use App\Events\Calendar\AppointmentNoShow;
use App\Events\Calendar\AppointmentRescheduled;
use App\Events\Calendar\AppointmentScheduled;
use App\Exceptions\Calendar\BookingTypeNotBookableException;
use App\Exceptions\Calendar\NoEligibleStaffAvailableException;
use App\Exceptions\Calendar\StaffNotEligibleForLocationException;
use App\Models\Appointment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Feature\Calendar\Concerns\CreatesBookingEngineFixtures;
use Tests\TestCase;

/**
 * V1 completion — the durable lifecycle-event seam and Location attribution.
 *
 * The five events are the seam the later Automations lane consumes. They are
 * asserted here through the REAL dispatcher (no Event::fake), so after-commit
 * behaviour is genuine: one event per committed transition, none for a refused
 * or rolled-back one, each carrying the same Business / Location / appointment
 * identity. Automations itself is deliberately not wired in this lane.
 */
class AppointmentLifecycleIdentityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBookingEngineFixtures;

    /** @var array<int, object> */
    private array $seen = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCalendarAuthorityFixtures();

        foreach ([
            AppointmentScheduled::class, AppointmentRescheduled::class, AppointmentCancelled::class,
            AppointmentCompleted::class, AppointmentNoShow::class,
        ] as $event) {
            Event::listen($event, function (object $e): void {
                $this->seen[] = $e;
            });
        }
    }

    /** @return array<int, class-string> */
    private function seenClasses(): array
    {
        return array_map(fn (object $e): string => $e::class, $this->seen);
    }

    private function scheduled(): Appointment
    {
        $staff = $this->bookableStaff();
        $type = $this->bookingType();

        return $this->engine()->book($type, (int) $staff->id, $this->contactId(), $this->slotStart(), (int) $this->owner->user_id);
    }

    public function test_every_lifecycle_event_carries_the_same_business_location_and_appointment_identity(): void
    {
        $appointment = $this->scheduled();
        $second = $this->engine()->book(
            $this->bookingType(), (int) $appointment->staff_user_id, $this->contactId(), $this->slotStart('13:00:00')
        );
        $third = $this->engine()->book(
            $this->bookingType(), (int) $appointment->staff_user_id, $this->contactId(), $this->slotStart('15:00:00')
        );
        $fourth = $this->engine()->book(
            $this->bookingType(), (int) $appointment->staff_user_id, $this->contactId(), $this->slotStart('17:00:00')
        );

        $this->engine()->reschedule($appointment, $this->slotStart('11:30:00'));
        $this->engine()->cancel($second);
        $this->engine()->complete($third);
        $this->engine()->markNoShow($fourth);

        $byClass = [];
        foreach ($this->seen as $event) {
            $byClass[$event::class][] = $event;
        }

        $this->assertCount(4, $byClass[AppointmentScheduled::class]);
        foreach ([AppointmentRescheduled::class, AppointmentCancelled::class, AppointmentCompleted::class, AppointmentNoShow::class] as $class) {
            $this->assertCount(1, $byClass[$class], $class.' must fire exactly once per committed transition.');
        }

        $expected = [
            AppointmentRescheduled::class => $appointment,
            AppointmentCancelled::class => $second,
            AppointmentCompleted::class => $third,
            AppointmentNoShow::class => $fourth,
        ];
        foreach ($expected as $class => $row) {
            $event = $byClass[$class][0];
            $this->assertSame((int) $row->id, $event->appointmentId, $class);
            $this->assertSame((int) $this->business->id, $event->businessId, $class);
            $this->assertSame((int) $this->locationA->id, $event->businessLocationId, $class);
        }

        $scheduled = $byClass[AppointmentScheduled::class][0];
        $this->assertSame((int) $this->business->id, $scheduled->businessId);
        $this->assertSame((int) $this->locationA->id, $scheduled->businessLocationId);
        $this->assertSame((int) $appointment->id, $scheduled->appointmentId);
    }

    public function test_a_booking_rolled_back_by_an_outer_transaction_emits_no_event_and_leaves_no_row(): void
    {
        $staff = $this->bookableStaff();
        $type = $this->bookingType();
        $contactId = $this->contactId();

        try {
            DB::transaction(function () use ($type, $staff, $contactId): void {
                $this->engine()->book($type, (int) $staff->id, $contactId, $this->slotStart());
                $this->assertSame([], $this->seen, 'Nothing may be announced before the outermost commit.');

                throw new RuntimeException('caller aborts after the engine returned');
            });
            $this->fail('The caller\'s exception must propagate.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame([], $this->seen, 'A rolled-back booking must never be announced.');
        $this->assertDatabaseCount('appointments', 0);

        // Control: the identical call that commits announces exactly once, after commit.
        DB::transaction(function () use ($type, $staff, $contactId): void {
            $this->engine()->book($type, (int) $staff->id, $contactId, $this->slotStart());
        });
        $this->assertSame([AppointmentScheduled::class], $this->seenClasses());
    }

    public function test_a_cancel_rolled_back_by_an_outer_transaction_emits_nothing_and_stays_scheduled(): void
    {
        $appointment = $this->scheduled();
        $this->seen = [];

        try {
            DB::transaction(function () use ($appointment): void {
                $this->engine()->cancel($appointment);
                throw new RuntimeException('abort');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->seen);
        $this->assertSame('scheduled', DB::table('appointments')->where('id', $appointment->id)->value('status'));
    }

    public function test_refused_mutations_emit_nothing(): void
    {
        $appointment = $this->scheduled();
        $this->seen = [];

        // Another Location's staff member is not eligible here: the move is refused whole.
        $outsiderStaff = $this->memberGrantedOnly($this->locationB);
        $this->giveMondayAvailability((int) $outsiderStaff->id, $this->locationA);

        try {
            $this->engine()->reschedule($appointment, $this->slotStart('14:00:00'), (int) $outsiderStaff->id);
            $this->fail('A cross-Location staff move must fail closed.');
        } catch (StaffNotEligibleForLocationException) {
        }

        $this->assertSame([], $this->seen);
        $fresh = Appointment::query()->findOrFail($appointment->id);
        $this->assertSame((int) $appointment->staff_user_id, (int) $fresh->staff_user_id);
        $this->assertSame(0, (int) $fresh->reschedule_count);
    }

    public function test_an_appointment_keeps_its_location_when_the_contact_staff_or_grants_change_later(): void
    {
        $appointment = $this->scheduled();
        $this->assertSame((int) $this->locationA->id, (int) $appointment->business_location_id);

        // The Contact is later re-homed to another Location.
        DB::table('contacts')->where('id', $appointment->contact_id)->update(['location_id' => $this->locationB->id]);

        // The booked staff member loses every grant.
        DB::table('workspace_memberships')->where('user_id', $appointment->staff_user_id)->update(['is_active' => false]);

        // The appointment is reassigned to another (eligible) staff member in time.
        $other = $this->bookableStaff();
        $moved = $this->engine()->reschedule($appointment, $this->slotStart('15:00:00'), (int) $other->id);

        $this->assertSame((int) $this->locationA->id, (int) $moved->business_location_id);
        $this->assertSame(
            (int) $this->locationA->id,
            (int) DB::table('appointments')->where('id', $appointment->id)->value('business_location_id')
        );
        $this->assertSame((int) $this->locationA->id, (int) $moved->location->id);
    }

    public function test_the_model_refuses_to_rewrite_an_appointments_location(): void
    {
        $appointment = $this->scheduled();

        $appointment->business_location_id = $this->locationB->id;
        try {
            $appointment->save();
            $this->fail('Rewriting an Appointment\'s Location must be refused.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        $this->assertSame(
            (int) $this->locationA->id,
            (int) DB::table('appointments')->where('id', $appointment->id)->value('business_location_id')
        );

        // Ordinary updates still work.
        $fresh = Appointment::query()->findOrFail($appointment->id);
        $fresh->cancellation_reason = 'note';
        $fresh->save();
        $this->assertSame('note', Appointment::query()->findOrFail($appointment->id)->cancellation_reason);
    }

    public function test_a_stale_active_booking_type_cannot_create_an_appointment_after_deactivation(): void
    {
        $staff = $this->bookableStaff();
        $type = $this->bookingType();
        $type->staff()->attach($staff->id);
        $stale = $type->fresh();
        $this->assertTrue($stale->isActive());

        // The owner switches the type off after the caller already loaded it.
        DB::table('booking_types')->where('id', $type->id)->update(['is_active' => false]);

        try {
            $this->engine()->book($stale, (int) $staff->id, $this->contactId(), $this->slotStart());
            $this->fail('Explicit booking must refuse an inactive type.');
        } catch (BookingTypeNotBookableException) {
        }

        try {
            $this->engine()->bookWithRoundRobin($stale, $this->contactId(), $this->slotStart());
            $this->fail('Round-robin booking must refuse an inactive type.');
        } catch (BookingTypeNotBookableException) {
        }

        $this->assertDatabaseCount('appointments', 0);
        $this->assertNull($this->cursorFor($type), 'A refused round-robin booking must not advance the cursor.');
        $this->assertSame([], $this->seen);
    }

    public function test_an_existing_appointment_of_a_deactivated_type_can_still_be_resolved_and_rescheduled(): void
    {
        $appointment = $this->scheduled();
        DB::table('booking_types')->where('id', $appointment->booking_type_id)->update(['is_active' => false]);

        $moved = $this->engine()->reschedule($appointment, $this->slotStart('15:00:00'));
        $this->engine()->complete($moved);

        $this->assertSame('completed', DB::table('appointments')->where('id', $appointment->id)->value('status'));
        $this->assertContains(AppointmentRescheduled::class, $this->seenClasses());
        $this->assertContains(AppointmentCompleted::class, $this->seenClasses());
    }

    public function test_round_robin_skips_an_ineligible_member_and_refuses_when_nobody_is_left(): void
    {
        $type = $this->bookingType();
        $eligible = $this->bookableStaff();
        $revoked = $this->bookableStaff();
        $type->staff()->attach([$eligible->id, $revoked->id]);
        DB::table('workspace_memberships')->where('user_id', $revoked->id)->update(['is_active' => false]);

        $first = $this->engine()->bookWithRoundRobin($type, $this->contactId(), $this->slotStart());
        $this->assertSame((int) $eligible->id, (int) $first->staff_user_id);

        // Same slot again: the only eligible member is busy, the revoked one is never chosen.
        $this->expectException(NoEligibleStaffAvailableException::class);
        $this->engine()->bookWithRoundRobin($type, $this->contactId(), $this->slotStart());
    }
}
