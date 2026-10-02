<?php

namespace Tests\Feature\V1Acceptance\CalendarBooking;

use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\Appointment;
use App\Models\BookingType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Acceptance journey D (sequential, real routes) — a staff member can never be
 * in two places at once. The multi-process races that prove the SAME rule under
 * true concurrency are in PublicBookingRaceJourneyTest; the engine-level races
 * stay in Calendar/AppointmentBookingConcurrencyTest and are not redesigned.
 */
class DoubleBookingJourneyTest extends CalendarJourneyTestCase
{
    private User $worker;

    private BookingType $typeA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->worker = $this->bookableStaff($this->locationA);
        $this->giveMondayAvailability((int) $this->worker->id, $this->locationB);
        $this->typeA = $this->typeWithStaff($this->locationA, $this->worker);
    }

    private function guestBooks(BookingType $type, string $time, string $phone)
    {
        return $this->from($this->publicShow($type))
            ->post($this->publicStore($type), $this->guest(['time' => $time, 'phone' => $phone]));
    }

    private function confirmedUrl(BookingType $type): string
    {
        return route('public.booking.confirmed', [$type->public_booking_uuid]);
    }

    public function test_two_guests_cannot_hold_the_same_staff_member_at_the_same_time(): void
    {
        $this->guestBooks($this->typeA, '10:00', '4155550201')->assertRedirect($this->confirmedUrl($this->typeA));

        // Exact same slot, and every start that overlaps 10:00–11:00.
        foreach (['10:00', '10:30', '09:30'] as $time) {
            $this->guestBooks($this->typeA, $time, '4155550202')
                ->assertRedirect($this->publicShow($this->typeA))
                ->assertSessionHasErrors('time');
        }
        $this->assertSame(1, $this->appointmentCount());
        $this->assertSame(1, $this->contactCount(), 'the refused guest left no Contact behind');

        // Back-to-back is not an overlap: 11:00 and 09:00 are fine.
        $this->guestBooks($this->typeA, '11:00', '4155550202')->assertRedirect($this->confirmedUrl($this->typeA));
        $this->guestBooks($this->typeA, '09:00', '4155550203')->assertRedirect($this->confirmedUrl($this->typeA));
        $this->assertSame(3, $this->appointmentCount());
    }

    public function test_the_public_scheduler_and_the_staff_calendar_contest_one_slot_fairly(): void
    {
        // Owner books 14:00 in the staff UI.
        $this->actAsOwner();
        $contactUid = $this->contactUidAt($this->locationA);
        $this->post($this->cal('appointments.store', $this->locationA), [
            'booking_type_uid' => $this->typeA->uid, 'contact_uid' => $contactUid,
            'staff' => (string) $this->worker->id, 'date' => '2027-03-01', 'time' => '14:00',
        ])->assertRedirect();
        $this->assertSame(1, $this->appointmentCount());

        // The public page no longer offers it, and a forced POST is refused.
        $this->assertNotContains('14:00', $this->offeredSlots($this->typeA, '2027-03-01'));
        $this->guestBooks($this->typeA, '14:00', '4155550204')->assertSessionHasErrors('time');

        // And the other way round: a guest takes 16:00, the staff UI is refused.
        $this->guestBooks($this->typeA, '16:00', '4155550205')->assertRedirect($this->confirmedUrl($this->typeA));
        $this->actAsOwner()->post($this->cal('appointments.store', $this->locationA), [
            'booking_type_uid' => $this->typeA->uid, 'contact_uid' => $contactUid,
            'staff' => (string) $this->worker->id, 'date' => '2027-03-01', 'time' => '16:00',
        ])->assertSessionHas('flash_error');
        $this->assertSame(2, $this->appointmentCount());
    }

    public function test_the_same_staff_member_is_never_double_booked_across_locations(): void
    {
        $typeB = $this->typeWithStaff($this->locationB, $this->worker);

        $this->guestBooks($this->typeA, '10:00', '4155550206')->assertRedirect($this->confirmedUrl($this->typeA));

        // Occupied at A, so unavailable at B — offered nowhere, accepted nowhere.
        $offeredAtB = $this->offeredSlots($typeB, '2027-03-01');
        foreach (['09:30', '10:00', '10:30'] as $taken) {
            $this->assertNotContains($taken, $offeredAtB);
        }
        $this->assertContains('11:00', $offeredAtB);

        $this->guestBooks($typeB, '10:00', '4155550207')->assertSessionHasErrors('time');
        $this->guestBooks($typeB, '10:30', '4155550207')->assertSessionHasErrors('time');
        $this->assertSame(1, $this->appointmentCount());
        $this->assertSame(0, $this->contactCount((int) $this->locationB->id), 'no Contact for the refused cross-location guest');

        // The staff calendar at B refuses it as well.
        $this->actAsOwner();
        $contactUid = $this->contactUidAt($this->locationB);
        $this->post($this->cal('appointments.store', $this->locationB), [
            'booking_type_uid' => $typeB->uid, 'contact_uid' => $contactUid,
            'staff' => (string) $this->worker->id, 'date' => '2027-03-01', 'time' => '10:00',
        ])->assertSessionHas('flash_error');
        $this->assertSame(0, $this->appointmentCount((int) $this->locationB->id));

        // Free time at B is still bookable, and then blocks A symmetrically.
        $this->guestBooks($typeB, '13:00', '4155550208')->assertRedirect($this->confirmedUrl($typeB));
        $this->assertNotContains('13:00', $this->offeredSlots($this->typeA, '2027-03-01'));
        $this->guestBooks($this->typeA, '13:00', '4155550209')->assertSessionHasErrors('time');
        $this->assertSame(2, $this->appointmentCount());
    }

    public function test_round_robin_routes_around_a_busy_member_and_refuses_when_everyone_is_busy(): void
    {
        $second = $this->bookableStaff($this->locationA);
        $this->typeA->staff()->attach($second->id);

        $this->guestBooks($this->typeA, '10:00', '4155550210')->assertRedirect($this->confirmedUrl($this->typeA));
        $this->guestBooks($this->typeA, '10:00', '4155550211')->assertRedirect($this->confirmedUrl($this->typeA));

        $staffIds = Appointment::query()->pluck('staff_user_id')->map(fn ($i): int => (int) $i)->all();
        $this->assertCount(2, array_unique($staffIds), 'two simultaneous guests are served by two different people');

        // A third guest at the same instant: nobody is free.
        $this->assertNotContains('10:00', $this->offeredSlots($this->typeA, '2027-03-01'));
        $this->guestBooks($this->typeA, '10:00', '4155550212')->assertSessionHasErrors('time');
        $this->assertSame(2, $this->appointmentCount());
    }

    public function test_rescheduling_into_a_taken_slot_is_refused_and_leaves_the_original_untouched(): void
    {
        $this->guestBooks($this->typeA, '10:00', '4155550213');
        $this->guestBooks($this->typeA, '13:00', '4155550214');
        $moving = Appointment::query()->orderBy('id')->firstOrFail();
        $before = Carbon::parse($moving->start_at)->utc()->format('Y-m-d H:i:s');

        $this->actAsOwner();
        $this->post($this->cal('appointments.reschedule', $this->locationA, [$moving->uid]), ['date' => '2027-03-01', 'time' => '13:30'])
            ->assertSessionHas('flash_error');

        $this->assertSame($before, Carbon::parse($moving->fresh()->start_at)->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(0, (int) $moving->fresh()->reschedule_count);
    }

    public function test_a_cancelled_slot_is_bookable_again(): void
    {
        $this->guestBooks($this->typeA, '10:00', '4155550215');
        $appointment = Appointment::query()->firstOrFail();
        $this->guestBooks($this->typeA, '10:00', '4155550216')->assertSessionHasErrors('time');

        $this->actAsOwner()->post($this->cal('appointments.cancel', $this->locationA, [$appointment->uid]), ['reason' => 'Guest called'])
            ->assertRedirect();
        $this->assertContains('10:00', $this->offeredSlots($this->typeA, '2027-03-01'));

        $this->guestBooks($this->typeA, '10:00', '4155550216')->assertRedirect($this->confirmedUrl($this->typeA));
        $this->assertSame(1, Appointment::query()->where('status', 'scheduled')->count());
        $this->assertSame(1, Appointment::query()->where('status', 'cancelled')->count());
    }
}
