<?php

namespace Tests\Feature\Calendar;

use App\Enums\Calendar\AppointmentStatus;
use App\Events\Calendar\AppointmentCancelled;
use App\Events\Calendar\AppointmentCompleted;
use App\Events\Calendar\AppointmentNoShow;
use App\Events\Calendar\AppointmentRescheduled;
use App\Events\Calendar\AppointmentScheduled;
use App\Exceptions\Calendar\AppointmentSlotUnavailableException;
use App\Exceptions\Calendar\AppointmentStaffChangedException;
use App\Exceptions\Calendar\InvalidAppointmentTransitionException;
use App\Exceptions\Calendar\NoEligibleStaffAvailableException;
use App\Exceptions\Calendar\StaffNotAvailableException;
use App\Exceptions\Calendar\StaffNotEligibleForLocationException;
use App\Library\Calendar\RoundRobinRotation;
use App\Models\Appointment;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Calendar\Concerns\CreatesBookingEngineFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 15 §7, §10, §12.C — the appointment lifecycle, its
 * guards and its event payloads, single-process.
 *
 * The genuinely concurrent proofs live in AppointmentBookingConcurrencyTest;
 * this file proves the rules those races are meant to preserve.
 */
class AppointmentLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBookingEngineFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCalendarAuthorityFixtures();
    }

    // --- create, explicit staff ---

    public function test_a_booking_is_created_for_an_eligible_available_staff_member(): void
    {
        Event::fake([AppointmentScheduled::class]);

        $staff = $this->bookableStaff();
        $type = $this->bookingType();
        $contactId = $this->contactId();
        $start = $this->slotStart();

        $appointment = $this->engine()->book($type, (int) $staff->id, $contactId, $start, (int) $this->owner->user_id);

        $this->assertSame(AppointmentStatus::Scheduled, $appointment->status);
        $this->assertSame((int) $staff->id, (int) $appointment->staff_user_id);
        $this->assertSame(0, (int) $appointment->reschedule_count);
        $this->assertSame(
            $start->copy()->addMinutes(60)->utc()->toDateTimeString(),
            $appointment->end_at->utc()->toDateTimeString()
        );

        Event::assertDispatched(AppointmentScheduled::class, function (AppointmentScheduled $e) use ($appointment, $staff, $contactId): bool {
            return $e->appointmentId === (int) $appointment->id
                && $e->businessLocationId === (int) $this->locationA->id
                && $e->staffUserId === (int) $staff->id
                && $e->contactId === $contactId
                && $e->crmOpportunityId === null
                && $e->createdByUserId === (int) $this->owner->user_id;
        });
    }

    /**
     * §7.2 — the lock row is created lazily on first use, so a brand-new staff
     * member's first ever booking must still work, and must leave the row
     * behind for the next one.
     */
    public function test_the_first_ever_booking_creates_the_staff_lock_row(): void
    {
        $staff = $this->bookableStaff();

        $this->assertDatabaseMissing('staff_booking_locks', ['staff_user_id' => $staff->id]);

        $this->engine()->book($this->bookingType(), (int) $staff->id, $this->contactId(), $this->slotStart());

        $this->assertDatabaseHas('staff_booking_locks', ['staff_user_id' => $staff->id]);
    }

    /** §7.6 — an overlapping interval for the same staff member is refused. */
    public function test_an_overlapping_booking_for_the_same_staff_member_is_refused(): void
    {
        $staff = $this->bookableStaff();
        $type = $this->bookingType();

        $this->engine()->book($type, (int) $staff->id, $this->contactId(), $this->slotStart('10:00:00'));

        $this->expectException(AppointmentSlotUnavailableException::class);
        $this->engine()->book($type, (int) $staff->id, $this->contactId(), $this->slotStart('10:30:00'));
    }

    /** Half-open intervals: back-to-back slots are bookable. */
    public function test_a_back_to_back_booking_is_allowed(): void
    {
        $staff = $this->bookableStaff();
        $type = $this->bookingType();

        $this->engine()->book($type, (int) $staff->id, $this->contactId(), $this->slotStart('10:00:00'));
        $second = $this->engine()->book($type, (int) $staff->id, $this->contactId(), $this->slotStart('11:00:00'));

        $this->assertSame(AppointmentStatus::Scheduled, $second->status);
        $this->assertSame(2, Appointment::query()->count());
    }

    /**
     * §7.1 tier 2 — one staff member's timeline is serialized across EVERY
     * Location, so a booking at Location A blocks the same interval at
     * Location B. That is the structural cross-Location guarantee.
     */
    public function test_a_booking_at_one_location_blocks_the_same_staff_member_at_another(): void
    {
        $staff = $this->bookableStaff($this->locationA);
        $this->giveMondayAvailability((int) $staff->id, $this->locationB);

        $atA = $this->bookingType($this->locationA);
        $atB = $this->bookingType($this->locationB);

        $this->engine()->book($atA, (int) $staff->id, $this->contactId(), $this->slotStart('10:00:00'));

        $this->expectException(AppointmentSlotUnavailableException::class);
        $this->engine()->book($atB, (int) $staff->id, $this->contactId(), $this->slotStart('10:30:00'));
    }

    /** A terminal appointment never blocks a new booking. */
    public function test_a_cancelled_appointment_frees_its_slot(): void
    {
        $staff = $this->bookableStaff();
        $type = $this->bookingType();

        $first = $this->engine()->book($type, (int) $staff->id, $this->contactId(), $this->slotStart('10:00:00'));
        $this->engine()->cancel($first);

        $second = $this->engine()->book($type, (int) $staff->id, $this->contactId(), $this->slotStart('10:00:00'));

        $this->assertSame(AppointmentStatus::Scheduled, $second->status);
    }

    // --- eligibility and availability guards ---

    /** §6 — eligibility is re-derived; a stale pivot row never authorizes. */
    public function test_a_staff_member_who_lost_location_access_cannot_be_booked(): void
    {
        $staff = $this->bookableStaff();
        $type = $this->bookingType();
        $type->staff()->attach($staff->id);

        $membership = app(WorkspaceMembershipRepository::class)
            ->findByWorkspaceAndUser($this->workspace, (int) $staff->id);
        app(WorkspaceMembershipRepository::class)->setActive($membership, false);

        // The configuration row is deliberately left in place.
        $this->assertDatabaseHas('booking_type_staff', ['booking_type_id' => $type->id, 'staff_user_id' => $staff->id]);

        $this->expectException(StaffNotEligibleForLocationException::class);
        $this->engine()->book($type, (int) $staff->id, $this->contactId(), $this->slotStart());
    }

    /** §5.2 — outside every recurring window is not bookable. */
    public function test_a_slot_outside_the_availability_window_is_refused(): void
    {
        $staff = $this->bookableStaff();

        $this->expectException(StaffNotAvailableException::class);
        $this->engine()->book($this->bookingType(), (int) $staff->id, $this->contactId(), $this->slotStart('06:00:00'));
    }

    /** §5.2 — a different weekday has no window at all. */
    public function test_a_slot_on_a_day_with_no_window_is_refused(): void
    {
        $staff = $this->bookableStaff();
        $tuesday = $this->slotStart()->copy()->addDay();

        $this->expectException(StaffNotAvailableException::class);
        $this->engine()->book($this->bookingType(), (int) $staff->id, $this->contactId(), $tuesday);
    }

    /** §5.3 — User-global time off blocks a booking at any Location. */
    public function test_time_off_blocks_a_booking(): void
    {
        $staff = $this->bookableStaff();
        $start = $this->slotStart();

        DB::table('staff_time_off')->insert([
            'staff_user_id' => $staff->id,
            'start_at' => $start->copy()->subHour(),
            'end_at' => $start->copy()->addHours(2),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(StaffNotAvailableException::class);
        $this->engine()->book($this->bookingType(), (int) $staff->id, $this->contactId(), $start);
    }

    // --- round robin (§7.3) ---

    public function test_round_robin_rotates_through_eligible_staff_in_ascending_order(): void
    {
        $first = $this->bookableStaff();
        $second = $this->bookableStaff();
        $type = $this->bookingType();
        $type->staff()->attach([$first->id, $second->id]);

        $lowest = min((int) $first->id, (int) $second->id);
        $highest = max((int) $first->id, (int) $second->id);

        $a = $this->engine()->bookWithRoundRobin($type, $this->contactId(), $this->slotStart('10:00:00'));
        $b = $this->engine()->bookWithRoundRobin($type, $this->contactId(), $this->slotStart('11:00:00'));
        $c = $this->engine()->bookWithRoundRobin($type, $this->contactId(), $this->slotStart('12:00:00'));

        $this->assertSame($lowest, (int) $a->staff_user_id, 'A null cursor starts at the lowest id.');
        $this->assertSame($highest, (int) $b->staff_user_id, 'The rotation continues at the successor.');
        $this->assertSame($lowest, (int) $c->staff_user_id, 'And wraps exactly once.');

        $this->assertSame($lowest, $this->cursorFor($type));
    }

    /** §7.3 step 4 — the cursor advances only with a committed booking. */
    public function test_the_cursor_advances_on_a_committed_booking(): void
    {
        $staff = $this->bookableStaff();
        $type = $this->bookingType();
        $type->staff()->attach($staff->id);

        $this->assertNull($this->cursorFor($type));

        $this->engine()->bookWithRoundRobin($type, $this->contactId(), $this->slotStart());

        $this->assertSame((int) $staff->id, $this->cursorFor($type));
    }

    /**
     * §7.3 step 5 — a refusal writes nothing AND leaves the cursor exactly as
     * it was. This is the non-starvation guarantee: skipping never moves it.
     */
    public function test_a_refused_round_robin_booking_leaves_the_cursor_untouched(): void
    {
        $first = $this->bookableStaff();
        $second = $this->bookableStaff();
        $type = $this->bookingType();
        $type->staff()->attach([$first->id, $second->id]);

        // Both members take the SAME interval, so after two bookings the whole
        // candidate order is busy for it and the third attempt must be refused.
        $this->engine()->bookWithRoundRobin($type, $this->contactId(), $this->slotStart('10:00:00'));
        $cursorAfterFirst = $this->cursorFor($type);

        $this->engine()->bookWithRoundRobin($type, $this->contactId(), $this->slotStart('10:00:00'));
        $cursorAfterSecond = $this->cursorFor($type);

        try {
            $this->engine()->bookWithRoundRobin($type, $this->contactId(), $this->slotStart('10:00:00'));
            $this->fail('Expected NoEligibleStaffAvailableException.');
        } catch (NoEligibleStaffAvailableException) {
            // expected
        }

        $this->assertSame($cursorAfterSecond, $this->cursorFor($type), 'A refusal must not move the cursor.');
        $this->assertNotSame($cursorAfterFirst, $cursorAfterSecond, 'Sanity: the first two bookings did move it.');
        $this->assertSame(2, Appointment::query()->count());
    }

    /**
     * §7.3 — an unavailable member keeps their place: they are skipped without
     * the cursor moving past them, so they are tried again at their normal turn.
     */
    public function test_an_unavailable_member_is_skipped_without_losing_their_place(): void
    {
        $available = $this->bookableStaff();
        $busy = $this->bookableStaff();

        $type = $this->bookingType();
        $type->staff()->attach([$available->id, $busy->id]);

        // Make the LOWER id unavailable for the first interval only.
        $lowerId = min((int) $available->id, (int) $busy->id);
        $higherId = max((int) $available->id, (int) $busy->id);

        DB::table('staff_time_off')->insert([
            'staff_user_id' => $lowerId,
            'start_at' => $this->slotStart('09:30:00'),
            'end_at' => $this->slotStart('11:00:00'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $first = $this->engine()->bookWithRoundRobin($type, $this->contactId(), $this->slotStart('10:00:00'));
        $this->assertSame($higherId, (int) $first->staff_user_id, 'The unavailable lower id is skipped.');
        $this->assertSame($higherId, $this->cursorFor($type), 'The cursor records who was ASSIGNED.');

        // Next interval: the successor of $higherId wraps to $lowerId, who is
        // now free — so they kept their place rather than being passed over.
        $second = $this->engine()->bookWithRoundRobin($type, $this->contactId(), $this->slotStart('12:00:00'));
        $this->assertSame($lowerId, (int) $second->staff_user_id);
    }

    public function test_round_robin_refuses_when_the_pool_is_empty(): void
    {
        $this->expectException(NoEligibleStaffAvailableException::class);
        $this->engine()->bookWithRoundRobin($this->bookingType(), $this->contactId(), $this->slotStart());
    }

    /** §6 — an ineligible member is not in the pool at all. */
    public function test_round_robin_ignores_a_pool_member_who_is_no_longer_eligible(): void
    {
        $eligible = $this->bookableStaff();
        $type = $this->bookingType();
        $type->staff()->attach([$eligible->id, $this->outsider->id]);

        $appointment = $this->engine()->bookWithRoundRobin($type, $this->contactId(), $this->slotStart());

        $this->assertSame((int) $eligible->id, (int) $appointment->staff_user_id);
    }

    /** §7.3 step 2 — the successor rule, as a pure function. */
    public function test_the_rotation_successor_rule(): void
    {
        $rotation = new RoundRobinRotation();

        $this->assertSame([3, 7, 9], $rotation->candidateOrder([9, 3, 7], null));
        $this->assertSame([7, 9, 3], $rotation->candidateOrder([3, 7, 9], 3));
        $this->assertSame([3, 7, 9], $rotation->candidateOrder([3, 7, 9], 9), 'Wraps exactly once.');
        $this->assertSame([7, 9], $rotation->candidateOrder([7, 9], 3), 'A vanished cursor starts at the lowest.');
        $this->assertSame([], $rotation->candidateOrder([], 5));
    }

    // --- reschedule (§7.4, §7.6) ---

    public function test_a_same_staff_reschedule_moves_the_interval_and_carries_equal_staff_ids(): void
    {
        Event::fake([AppointmentRescheduled::class]);

        $staff = $this->bookableStaff();
        $appointment = $this->engine()->book($this->bookingType(), (int) $staff->id, $this->contactId(), $this->slotStart('10:00:00'));
        $originalStart = $appointment->start_at->utc()->toDateTimeString();

        $moved = $this->engine()->reschedule($appointment, $this->slotStart('14:00:00'), null, (int) $this->owner->user_id);

        $this->assertSame($this->slotStart('14:00:00')->utc()->toDateTimeString(), $moved->start_at->utc()->toDateTimeString());
        $this->assertSame(1, (int) $moved->reschedule_count);
        $this->assertSame((int) $staff->id, (int) $moved->staff_user_id);

        Event::assertDispatched(AppointmentRescheduled::class, function (AppointmentRescheduled $e) use ($staff, $originalStart): bool {
            return $e->previousStaffUserId === (int) $staff->id
                && $e->newStaffUserId === (int) $staff->id
                && $e->previousStartAt === $originalStart
                && $e->rescheduledByUserId === (int) $this->owner->user_id;
        });
    }

    /** §7.4 row 4 + §10 — a staff move carries DIFFERENT ids. */
    public function test_a_staff_move_reschedule_carries_different_staff_ids(): void
    {
        Event::fake([AppointmentRescheduled::class]);

        $from = $this->bookableStaff();
        $to = $this->bookableStaff();

        $appointment = $this->engine()->book($this->bookingType(), (int) $from->id, $this->contactId(), $this->slotStart('10:00:00'));

        $moved = $this->engine()->reschedule($appointment, $this->slotStart('10:00:00'), (int) $to->id);

        $this->assertSame((int) $to->id, (int) $moved->staff_user_id);

        Event::assertDispatched(AppointmentRescheduled::class, fn (AppointmentRescheduled $e): bool
            => $e->previousStaffUserId === (int) $from->id && $e->newStaffUserId === (int) $to->id);
    }

    /** §7.6 — the NEW staff member's own cross-Location conflicts are re-checked. */
    public function test_a_staff_move_is_refused_when_the_new_staff_member_is_busy(): void
    {
        $from = $this->bookableStaff();
        $to = $this->bookableStaff();
        $type = $this->bookingType();

        $appointment = $this->engine()->book($type, (int) $from->id, $this->contactId(), $this->slotStart('10:00:00'));
        $this->engine()->book($type, (int) $to->id, $this->contactId(), $this->slotStart('10:00:00'));

        try {
            $this->engine()->reschedule($appointment, $this->slotStart('10:00:00'), (int) $to->id);
            $this->fail('Expected AppointmentSlotUnavailableException.');
        } catch (AppointmentSlotUnavailableException) {
            // expected
        }

        $fresh = $appointment->fresh();
        $this->assertSame((int) $from->id, (int) $fresh->staff_user_id, 'The original staff member is untouched.');
        $this->assertSame(0, (int) $fresh->reschedule_count);
    }

    /**
     * §7.6 — "a failed reschedule leaves the original appointment semantically
     * unchanged, byte for byte": the refusal is raised before any UPDATE.
     */
    public function test_a_failed_reschedule_leaves_the_original_row_completely_untouched(): void
    {
        Event::fake([AppointmentRescheduled::class]);

        $staff = $this->bookableStaff();
        $type = $this->bookingType();

        $appointment = $this->engine()->book($type, (int) $staff->id, $this->contactId(), $this->slotStart('10:00:00'));
        $this->engine()->book($type, (int) $staff->id, $this->contactId(), $this->slotStart('14:00:00'));

        $before = DB::table('appointments')->where('id', $appointment->id)->first();

        try {
            $this->engine()->reschedule($appointment, $this->slotStart('14:00:00'));
            $this->fail('Expected AppointmentSlotUnavailableException.');
        } catch (AppointmentSlotUnavailableException) {
            // expected
        }

        $after = DB::table('appointments')->where('id', $appointment->id)->first();

        $this->assertEquals($before, $after, 'Every column must be identical after a refused reschedule.');
        Event::assertNotDispatched(AppointmentRescheduled::class);
    }

    /** The interval check runs against the NEW interval, not the old one. */
    public function test_a_reschedule_does_not_conflict_with_its_own_current_interval(): void
    {
        $staff = $this->bookableStaff();
        $appointment = $this->engine()->book($this->bookingType(), (int) $staff->id, $this->contactId(), $this->slotStart('10:00:00'));

        $moved = $this->engine()->reschedule($appointment, $this->slotStart('10:30:00'));

        $this->assertSame($this->slotStart('10:30:00')->utc()->toDateTimeString(), $moved->start_at->utc()->toDateTimeString());
    }

    public function test_a_reschedule_is_refused_when_the_new_interval_is_outside_availability(): void
    {
        $staff = $this->bookableStaff();
        $appointment = $this->engine()->book($this->bookingType(), (int) $staff->id, $this->contactId(), $this->slotStart('10:00:00'));

        $this->expectException(StaffNotAvailableException::class);
        $this->engine()->reschedule($appointment, $this->slotStart('05:00:00'));
    }

    // --- terminal transitions (§7.4) ---

    public function test_cancel_completes_and_no_show_each_write_one_terminal_state(): void
    {
        Event::fake([AppointmentCancelled::class, AppointmentCompleted::class, AppointmentNoShow::class]);

        $staff = $this->bookableStaff();
        $type = $this->bookingType();

        $cancelled = $this->engine()->cancel(
            $this->engine()->book($type, (int) $staff->id, $this->contactId(), $this->slotStart('10:00:00')),
            (int) $this->owner->user_id,
            'Customer rang'
        );
        $completed = $this->engine()->complete(
            $this->engine()->book($type, (int) $staff->id, $this->contactId(), $this->slotStart('11:00:00')),
            (int) $this->owner->user_id
        );
        $noShow = $this->engine()->markNoShow(
            $this->engine()->book($type, (int) $staff->id, $this->contactId(), $this->slotStart('12:00:00')),
            (int) $this->owner->user_id
        );

        $this->assertSame(AppointmentStatus::Cancelled, $cancelled->status);
        $this->assertSame('Customer rang', $cancelled->cancellation_reason);
        $this->assertNotNull($cancelled->resolved_at);
        $this->assertSame((int) $this->owner->user_id, (int) $cancelled->resolved_by_user_id);

        $this->assertSame(AppointmentStatus::Completed, $completed->status);
        $this->assertSame(AppointmentStatus::NoShow, $noShow->status);

        Event::assertDispatched(AppointmentCancelled::class, fn (AppointmentCancelled $e): bool
            => $e->appointmentId === (int) $cancelled->id
            && $e->staffUserId === (int) $staff->id
            && $e->cancelledByUserId === (int) $this->owner->user_id
            && $e->reason === 'Customer rang');
        Event::assertDispatched(AppointmentCompleted::class, fn (AppointmentCompleted $e): bool
            => $e->appointmentId === (int) $completed->id && $e->completedByUserId === (int) $this->owner->user_id);
        Event::assertDispatched(AppointmentNoShow::class, fn (AppointmentNoShow $e): bool
            => $e->appointmentId === (int) $noShow->id && $e->markedByUserId === (int) $this->owner->user_id);
    }

    /** §7.4 — terminal states have no transition out of them. */
    public function test_a_cancelled_appointment_cannot_be_cancelled_completed_rescheduled_or_no_showed_again(): void
    {
        Event::fake();

        $staff = $this->bookableStaff();
        $appointment = $this->engine()->book($this->bookingType(), (int) $staff->id, $this->contactId(), $this->slotStart('10:00:00'));
        $cancelled = $this->engine()->cancel($appointment);

        foreach ([
            fn () => $this->engine()->cancel($cancelled),
            fn () => $this->engine()->complete($cancelled),
            fn () => $this->engine()->markNoShow($cancelled),
            fn () => $this->engine()->reschedule($cancelled, $this->slotStart('15:00:00')),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected InvalidAppointmentTransitionException.');
            } catch (InvalidAppointmentTransitionException) {
                // expected
            }
        }

        $this->assertSame(AppointmentStatus::Cancelled, $cancelled->fresh()->status);
        Event::assertDispatchedTimes(AppointmentCancelled::class, 1);
    }

    /** §7.4 — complete and no-show are mutually exclusive. */
    public function test_complete_and_no_show_cannot_both_succeed(): void
    {
        Event::fake([AppointmentCompleted::class, AppointmentNoShow::class]);

        $staff = $this->bookableStaff();
        $appointment = $this->engine()->book($this->bookingType(), (int) $staff->id, $this->contactId(), $this->slotStart('10:00:00'));

        $this->engine()->complete($appointment);

        $this->expectException(InvalidAppointmentTransitionException::class);
        $this->engine()->markNoShow($appointment);
    }

    // --- stale caller models (§7.4: tier 2 must be the appointment's OWN staff) ---

    /** @return array<string, array{string}> */
    public static function terminalTransitions(): array
    {
        return [
            'cancel' => ['cancel'],
            'complete' => ['complete'],
            'no-show' => ['markNoShow'],
        ];
    }

    /**
     * cancel, complete and no-show all run through resolveTerminal(), which
     * derives its tier-2 staff lock from the caller's model BEFORE any lock.
     * When that model is stale — the appointment was moved to another staff
     * member since the caller loaded it — the lock held is the WRONG staff
     * timeline, so the transition must be refused, write nothing at all and
     * dispatch nothing.
     */
    #[DataProvider('terminalTransitions')]
    public function test_a_stale_model_cannot_terminally_transition_an_appointment_that_moved_staff(string $transition): void
    {
        Event::fake([AppointmentCancelled::class, AppointmentCompleted::class, AppointmentNoShow::class]);

        $staffA = $this->bookableStaff();
        $staffB = $this->bookableStaff();

        $appointment = $this->engine()->book($this->bookingType(), (int) $staffA->id, $this->contactId(), $this->slotStart('10:00:00'));

        // The caller's copy, loaded while the appointment is still on staff A.
        $stale = Appointment::query()->findOrFail($appointment->id);

        // A competing reschedule moves it to staff B.
        $this->engine()->reschedule(Appointment::query()->findOrFail($appointment->id), $this->slotStart('10:00:00'), (int) $staffB->id);

        $this->assertSame((int) $staffA->id, (int) $stale->staff_user_id, 'Test premise: the caller still holds staff A.');

        $before = DB::table('appointments')->where('id', $appointment->id)->first();
        $this->assertSame((int) $staffB->id, (int) $before->staff_user_id, 'Test premise: the persisted row is on staff B.');

        try {
            $this->engine()->{$transition}($stale, (int) $this->owner->user_id);
            $this->fail('Expected AppointmentStaffChangedException.');
        } catch (AppointmentStaffChangedException $refusal) {
            $this->assertStringContainsString("[{$staffB->id}]", $refusal->getMessage());
        }

        $after = DB::table('appointments')->where('id', $appointment->id)->first();

        $this->assertEquals($before, $after, 'A refused stale transition must leave every column identical.');
        $this->assertSame(AppointmentStatus::Scheduled->value, $after->status);
        $this->assertSame((int) $staffB->id, (int) $after->staff_user_id, 'Staff B\'s assignment is unchanged.');
        $this->assertNull($after->resolved_at);
        $this->assertNull($after->resolved_by_user_id);
        $this->assertNull($after->cancellation_reason);

        Event::assertNotDispatched(AppointmentCancelled::class);
        Event::assertNotDispatched(AppointmentCompleted::class);
        Event::assertNotDispatched(AppointmentNoShow::class);
    }

    /** The refusal is recoverable: re-reading the appointment gives a model that transitions normally. */
    public function test_a_stale_terminal_refusal_is_recoverable_by_rereading_the_appointment(): void
    {
        Event::fake([AppointmentCancelled::class]);

        $staffA = $this->bookableStaff();
        $staffB = $this->bookableStaff();

        $appointment = $this->engine()->book($this->bookingType(), (int) $staffA->id, $this->contactId(), $this->slotStart('10:00:00'));
        $stale = Appointment::query()->findOrFail($appointment->id);
        $this->engine()->reschedule(Appointment::query()->findOrFail($appointment->id), $this->slotStart('10:00:00'), (int) $staffB->id);

        try {
            $this->engine()->cancel($stale);
            $this->fail('Expected AppointmentStaffChangedException.');
        } catch (AppointmentStaffChangedException) {
            // expected
        }

        $cancelled = $this->engine()->cancel(Appointment::query()->findOrFail($appointment->id));

        $this->assertSame(AppointmentStatus::Cancelled, $cancelled->status);
        Event::assertDispatchedTimes(AppointmentCancelled::class, 1);
        Event::assertDispatched(AppointmentCancelled::class, fn (AppointmentCancelled $e): bool => $e->staffUserId === (int) $staffB->id);
    }

    /** The same guard protects reschedule, which shares the helper. */
    public function test_a_stale_model_cannot_reschedule_an_appointment_that_moved_staff(): void
    {
        $staffA = $this->bookableStaff();
        $staffB = $this->bookableStaff();

        $appointment = $this->engine()->book($this->bookingType(), (int) $staffA->id, $this->contactId(), $this->slotStart('10:00:00'));
        $stale = Appointment::query()->findOrFail($appointment->id);
        $this->engine()->reschedule(Appointment::query()->findOrFail($appointment->id), $this->slotStart('10:00:00'), (int) $staffB->id);

        // Faked only now, so the setup reschedule above is not counted.
        Event::fake([AppointmentRescheduled::class]);

        $before = DB::table('appointments')->where('id', $appointment->id)->first();

        try {
            $this->engine()->reschedule($stale, $this->slotStart('14:00:00'));
            $this->fail('Expected AppointmentStaffChangedException.');
        } catch (AppointmentStaffChangedException) {
            // expected
        }

        $this->assertEquals($before, DB::table('appointments')->where('id', $appointment->id)->first());
        Event::assertNotDispatched(AppointmentRescheduled::class);
    }

    /** §5.4/§10/§15 — no appointment history table, by decision. */
    public function test_no_appointment_transitions_table_is_created(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('appointment_transitions'));
    }
}
