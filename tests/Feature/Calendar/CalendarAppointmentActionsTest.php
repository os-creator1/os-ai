<?php

namespace Tests\Feature\Calendar;

use App\Enums\Calendar\AppointmentStatus;
use App\Events\Calendar\AppointmentCancelled;
use App\Events\Calendar\AppointmentCompleted;
use App\Events\Calendar\AppointmentNoShow;
use App\Events\Calendar\AppointmentRescheduled;
use App\Events\Calendar\AppointmentScheduled;
use App\Exceptions\Calendar\AppointmentSlotUnavailableException;
use App\Exceptions\Calendar\InvalidAppointmentTransitionException;
use App\Exceptions\Calendar\NoEligibleStaffAvailableException;
use App\Exceptions\Calendar\StaffNotAvailableException;
use App\Library\Calendar\AppointmentBookingService;
use App\Models\Appointment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 15 §7, §10, §12.D — the five appointment actions
 * over HTTP, and the proof that every one of them is a single call into the
 * canonical AppointmentBookingService.
 *
 * TWO KINDS OF PROOF, deliberately, because each catches what the other
 * cannot:
 *   - END-TO-END through the REAL engine: the row changes and the contracted
 *     event fires, so the wiring genuinely works;
 *   - DELEGATION with the engine replaced by a Mockery double that expects
 *     exactly ONE call: the controller reaches the engine through nothing else
 *     and calls it exactly once — which is also what proves it never retries.
 *
 * Entitlement is supplied by entitledCalendar() (the answer only); tenancy and
 * LocationAccessGuard are real.
 */
class CalendarAppointmentActionsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCalendarHttpFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCalendarHttpFixtures();
        $this->entitledCalendar();
        $this->authenticate($this->owner->user);
    }

    private function scope(): array
    {
        return $this->scopeFor($this->locationA);
    }

    /** @return array{0: Appointment, 1: \App\Models\User} */
    private function booked(string $time = '10:00:00'): array
    {
        $staff = $this->bookableStaff();
        $appointment = $this->engine()->book($this->bookingType(), (int) $staff->id, $this->contactId(), $this->slotStart($time));

        return [$appointment, $staff];
    }

    private function actionUrl(string $action, Appointment $appointment): string
    {
        return $this->calendarUrl('appointments.' . $action, array_merge($this->scope(), [$appointment->uid]));
    }

    // -----------------------------------------------------------------
    // END-TO-END through the real engine
    // -----------------------------------------------------------------

    public function test_create_with_an_explicit_staff_member_books_through_the_engine(): void
    {
        Event::fake([AppointmentScheduled::class]);

        $staff = $this->bookableStaff();
        $type = $this->bookingType();
        $contactId = $this->contactId();
        $contactUid = DB::table('contacts')->where('id', $contactId)->value('uid');

        $response = $this->post($this->calendarUrl('appointments.store', $this->scope()), [
            'booking_type_uid' => $type->uid,
            'contact_uid' => $contactUid,
            'staff' => (string) $staff->id,
            'date' => '2027-03-01',
            'time' => '10:00',
        ]);

        $appointment = Appointment::query()->firstOrFail();
        $response->assertRedirect($this->calendarUrl('appointments.show', array_merge($this->scope(), [$appointment->uid])));

        $this->assertSame((int) $staff->id, (int) $appointment->staff_user_id);
        $this->assertSame($contactId, (int) $appointment->contact_id);
        // 10:00 New York is 15:00 UTC — the form's local time was converted.
        $this->assertSame('2027-03-01 15:00:00', $appointment->start_at->utc()->toDateTimeString());
        $this->assertSame((int) $this->owner->user_id, (int) $appointment->created_by_user_id);

        Event::assertDispatched(AppointmentScheduled::class, fn (AppointmentScheduled $e): bool
            => $e->appointmentId === (int) $appointment->id && $e->createdByUserId === (int) $this->owner->user_id);
    }

    public function test_create_with_automatic_assignment_uses_round_robin(): void
    {
        $staff = $this->bookableStaff();
        $type = $this->bookingType();
        $type->staff()->attach($staff->id);
        $contactUid = DB::table('contacts')->where('id', $this->contactId())->value('uid');

        $this->post($this->calendarUrl('appointments.store', $this->scope()), [
            'booking_type_uid' => $type->uid,
            'contact_uid' => $contactUid,
            'staff' => 'auto',
            'date' => '2027-03-01',
            'time' => '10:00',
        ])->assertRedirect();

        $this->assertSame((int) $staff->id, (int) Appointment::query()->firstOrFail()->staff_user_id);
        $this->assertSame((int) $staff->id, $this->cursorFor($type), 'The engine\'s durable cursor advanced.');
    }

    public function test_reschedule_moves_the_appointment_through_the_engine(): void
    {
        Event::fake([AppointmentRescheduled::class]);
        [$appointment, $staff] = $this->booked('10:00:00');

        $this->post($this->actionUrl('reschedule', $appointment), [
            'date' => '2027-03-01', 'time' => '14:00', 'staff' => (string) $staff->id,
        ])->assertRedirect();

        $fresh = $appointment->fresh();
        $this->assertSame('2027-03-01 19:00:00', $fresh->start_at->utc()->toDateTimeString());
        $this->assertSame(1, (int) $fresh->reschedule_count);

        Event::assertDispatched(AppointmentRescheduled::class, fn (AppointmentRescheduled $e): bool
            => $e->previousStaffUserId === (int) $staff->id && $e->newStaffUserId === (int) $staff->id);
    }

    public function test_reschedule_can_move_the_appointment_to_another_staff_member(): void
    {
        Event::fake([AppointmentRescheduled::class]);
        [$appointment, $from] = $this->booked('10:00:00');
        $to = $this->bookableStaff();

        $this->post($this->actionUrl('reschedule', $appointment), [
            'date' => '2027-03-01', 'time' => '10:00', 'staff' => (string) $to->id,
        ])->assertRedirect();

        $this->assertSame((int) $to->id, (int) $appointment->fresh()->staff_user_id);
        Event::assertDispatched(AppointmentRescheduled::class, fn (AppointmentRescheduled $e): bool
            => $e->previousStaffUserId === (int) $from->id && $e->newStaffUserId === (int) $to->id);
    }

    public function test_cancel_complete_and_no_show_each_transition_through_the_engine(): void
    {
        Event::fake([AppointmentCancelled::class, AppointmentCompleted::class, AppointmentNoShow::class]);

        [$toCancel] = $this->booked('09:00:00');
        [$toComplete] = $this->booked('11:00:00');
        [$toNoShow] = $this->booked('13:00:00');

        $this->post($this->actionUrl('cancel', $toCancel), ['reason' => 'Customer rang'])->assertRedirect();
        $this->post($this->actionUrl('complete', $toComplete))->assertRedirect();
        $this->post($this->actionUrl('no-show', $toNoShow))->assertRedirect();

        $this->assertSame(AppointmentStatus::Cancelled, $toCancel->fresh()->status);
        $this->assertSame('Customer rang', $toCancel->fresh()->cancellation_reason);
        $this->assertSame(AppointmentStatus::Completed, $toComplete->fresh()->status);
        $this->assertSame(AppointmentStatus::NoShow, $toNoShow->fresh()->status);

        Event::assertDispatchedTimes(AppointmentCancelled::class, 1);
        Event::assertDispatchedTimes(AppointmentCompleted::class, 1);
        Event::assertDispatchedTimes(AppointmentNoShow::class, 1);

        // The resolving actor is the signed-in user, never a request field.
        $this->assertSame((int) $this->owner->user_id, (int) $toCancel->fresh()->resolved_by_user_id);
    }

    public function test_the_detail_page_offers_controls_only_while_scheduled(): void
    {
        [$appointment] = $this->booked();

        $open = $this->get($this->calendarUrl('appointments.show', array_merge($this->scope(), [$appointment->uid])))
            ->assertOk()
            ->assertSee('data-role="reschedule"', false)
            ->assertSee('data-role="cancel"', false);

        $this->post($this->actionUrl('cancel', $appointment));

        $this->get($this->calendarUrl('appointments.show', array_merge($this->scope(), [$appointment->uid])))
            ->assertOk()
            ->assertDontSee('data-role="reschedule"', false)
            ->assertSee('data-role="appointment-closed"', false);
    }

    // -----------------------------------------------------------------
    // STALE STATE — the engine's typed refusal reaches the user, unretried
    // -----------------------------------------------------------------

    /** A slot filled after the form rendered surfaces as a message, and nothing is written. */
    public function test_a_slot_filled_after_render_surfaces_the_engines_refusal(): void
    {
        $staff = $this->bookableStaff();
        $type = $this->bookingType();
        $contactUid = DB::table('contacts')->where('id', $this->contactId())->value('uid');

        // Somebody else takes 10:00 between render and submit.
        $this->engine()->book($type, (int) $staff->id, $this->contactId(), $this->slotStart('10:00:00'));

        $response = $this->from($this->calendarUrl('appointments.create', $this->scope()))
            ->post($this->calendarUrl('appointments.store', $this->scope()), [
                'booking_type_uid' => $type->uid,
                'contact_uid' => $contactUid,
                'staff' => (string) $staff->id,
                'date' => '2027-03-01',
                'time' => '10:30',
            ]);

        $response->assertRedirect($this->calendarUrl('appointments.create', $this->scope()));
        $response->assertSessionHas('flash_error', fn (string $m): bool => str_contains($m, 'no longer free'));

        $this->assertSame(1, Appointment::query()->count(), 'The refused booking must write nothing.');
    }

    public function test_a_reschedule_into_a_taken_slot_leaves_the_original_untouched(): void
    {
        [$appointment, $staff] = $this->booked('10:00:00');
        $this->engine()->book($this->bookingType(), (int) $staff->id, $this->contactId(), $this->slotStart('14:00:00'));

        $before = DB::table('appointments')->where('id', $appointment->id)->first();

        $this->post($this->actionUrl('reschedule', $appointment), [
            'date' => '2027-03-01', 'time' => '14:00', 'staff' => (string) $staff->id,
        ])->assertSessionHas('flash_error');

        $this->assertEquals($before, DB::table('appointments')->where('id', $appointment->id)->first());
    }

    /** The concurrent-change case: the engine re-reads status under its lock and refuses. */
    public function test_acting_on_an_already_resolved_appointment_surfaces_the_transition_refusal(): void
    {
        [$appointment] = $this->booked();
        $this->engine()->cancel($appointment);

        foreach (['cancel', 'complete', 'no-show'] as $action) {
            $this->post($this->actionUrl($action, $appointment))
                ->assertSessionHas('flash_error', fn (string $m): bool => str_contains($m, 'already changed'));
        }

        $this->post($this->actionUrl('reschedule', $appointment), ['date' => '2027-03-01', 'time' => '15:00'])
            ->assertSessionHas('flash_error', fn (string $m): bool => str_contains($m, 'already changed'));

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->fresh()->status);
    }

    // -----------------------------------------------------------------
    // DELEGATION — every action is exactly ONE call into the canonical engine
    // -----------------------------------------------------------------

    private function engineDouble(): MockInterface
    {
        return $this->mock(AppointmentBookingService::class);
    }

    public function test_create_delegates_to_book_exactly_once(): void
    {
        $staff = $this->bookableStaff();
        $type = $this->bookingType();
        $contactId = $this->contactId();
        $contactUid = DB::table('contacts')->where('id', $contactId)->value('uid');

        $created = new Appointment(['uid' => 'stub']);
        $created->uid = (string) \Illuminate\Support\Str::uuid();

        $engine = $this->engineDouble();
        $engine->shouldReceive('book')->once()
            ->withArgs(fn ($bt, $staffId, $cid, $start, $actor) => (int) $bt->id === (int) $type->id
                && $staffId === (int) $staff->id && $cid === $contactId && $actor === (int) $this->owner->user_id)
            ->andReturn($created);
        $engine->shouldNotReceive('bookWithRoundRobin');

        $this->post($this->calendarUrl('appointments.store', $this->scope()), [
            'booking_type_uid' => $type->uid,
            'contact_uid' => $contactUid,
            'staff' => (string) $staff->id,
            'date' => '2027-03-01',
            'time' => '10:00',
        ])->assertRedirect();

        $this->assertSame(0, Appointment::query()->count(), 'The controller itself wrote no appointment row.');
    }

    public function test_automatic_create_delegates_to_book_with_round_robin_exactly_once(): void
    {
        $type = $this->bookingType();
        $contactUid = DB::table('contacts')->where('id', $this->contactId())->value('uid');

        $created = new Appointment();
        $created->uid = (string) \Illuminate\Support\Str::uuid();

        $engine = $this->engineDouble();
        $engine->shouldReceive('bookWithRoundRobin')->once()->andReturn($created);
        $engine->shouldNotReceive('book');

        $this->post($this->calendarUrl('appointments.store', $this->scope()), [
            'booking_type_uid' => $type->uid,
            'contact_uid' => $contactUid,
            'staff' => 'auto',
            'date' => '2027-03-01',
            'time' => '10:00',
        ])->assertRedirect();
    }

    public function test_reschedule_delegates_exactly_once(): void
    {
        [$appointment, $staff] = $this->booked();

        $this->engineDouble()->shouldReceive('reschedule')->once()
            ->withArgs(fn ($a, $start, $newStaff, $actor) => (int) $a->id === (int) $appointment->id
                && $newStaff === (int) $staff->id && $actor === (int) $this->owner->user_id)
            ->andReturn($appointment);

        $this->post($this->actionUrl('reschedule', $appointment), [
            'date' => '2027-03-01', 'time' => '14:00', 'staff' => (string) $staff->id,
        ])->assertRedirect();
    }

    public function test_cancel_complete_and_no_show_each_delegate_exactly_once(): void
    {
        [$appointment] = $this->booked();

        $engine = $this->engineDouble();
        $engine->shouldReceive('cancel')->once()->withArgs(fn ($a, $actor, $reason) => (int) $a->id === (int) $appointment->id
            && $actor === (int) $this->owner->user_id && $reason === 'why')->andReturn($appointment);
        $engine->shouldReceive('complete')->once()->withArgs(fn ($a, $actor) => (int) $a->id === (int) $appointment->id
            && $actor === (int) $this->owner->user_id)->andReturn($appointment);
        $engine->shouldReceive('markNoShow')->once()->withArgs(fn ($a, $actor) => (int) $a->id === (int) $appointment->id
            && $actor === (int) $this->owner->user_id)->andReturn($appointment);

        $this->post($this->actionUrl('cancel', $appointment), ['reason' => 'why'])->assertRedirect();
        $this->post($this->actionUrl('complete', $appointment))->assertRedirect();
        $this->post($this->actionUrl('no-show', $appointment))->assertRedirect();
    }

    /**
     * NEVER RETRIES. The engine refuses once; the controller calls it exactly
     * once and surfaces the refusal. A controller that quietly re-submitted
     * would be the one thing able to weaken the engine's checks.
     */
    public function test_a_refusal_is_surfaced_and_the_engine_is_not_called_again(): void
    {
        $staff = $this->bookableStaff();
        $type = $this->bookingType();
        $contactUid = DB::table('contacts')->where('id', $this->contactId())->value('uid');

        $this->engineDouble()->shouldReceive('book')->once()
            ->andThrow(AppointmentSlotUnavailableException::forStaff((int) $staff->id, 'a', 'b'));

        $this->post($this->calendarUrl('appointments.store', $this->scope()), [
            'booking_type_uid' => $type->uid,
            'contact_uid' => $contactUid,
            'staff' => (string) $staff->id,
            'date' => '2027-03-01',
            'time' => '10:00',
        ])->assertSessionHas('flash_error', fn (string $m): bool => str_contains($m, 'no longer free'));
    }

    /** @return array<string, array{0: class-string, 1: string}> */
    public static function refusalMessages(): array
    {
        return [
            'slot' => [AppointmentSlotUnavailableException::class, 'no longer free'],
            'availability' => [StaffNotAvailableException::class, 'not available'],
            'no staff' => [NoEligibleStaffAvailableException::class, 'No eligible staff'],
        ];
    }

    #[DataProvider('refusalMessages')]
    public function test_each_typed_refusal_has_its_own_plain_message(string $exception, string $expected): void
    {
        $type = $this->bookingType();
        $contactUid = DB::table('contacts')->where('id', $this->contactId())->value('uid');

        $thrown = match ($exception) {
            NoEligibleStaffAvailableException::class => NoEligibleStaffAvailableException::forBookingType((int) $type->id, 'a', 'b'),
            default => $exception::forStaff(1, 'a', 'b'),
        };

        $this->engineDouble()->shouldReceive('bookWithRoundRobin')->once()->andThrow($thrown);

        $this->post($this->calendarUrl('appointments.store', $this->scope()), [
            'booking_type_uid' => $type->uid,
            'contact_uid' => $contactUid,
            'staff' => 'auto',
            'date' => '2027-03-01',
            'time' => '10:00',
        ])->assertSessionHas('flash_error', fn (string $m): bool => str_contains($m, $expected));
    }

    // -----------------------------------------------------------------
    // Structural boundary — HTTP code holds no booking or lifecycle logic
    // -----------------------------------------------------------------

    /**
     * The controller may not write an appointment or change lifecycle state
     * itself. Asserted on the source so that a future edit that "just updates
     * the status here" fails immediately, whatever a mock says.
     */
    public function test_the_controller_contains_no_direct_appointment_writes_or_lock_logic(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Customer/Business/CalendarController.php'));
        // Strip comments so prose describing the rule cannot trip the scan.
        $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $source);

        foreach ([
            'DB::' => 'no raw database access',
            'Appointment::create' => 'no direct appointment insert',
            'Appointment::query()->update' => 'no direct appointment update',
            '->save(' => 'no model save',
            '->delete(' => 'no delete',
            'lockForUpdate' => 'no locking',
            'staff_booking_locks' => 'no lock-table access',
            'booking_type_round_robin_state' => 'no cursor access',
            'DB::transaction' => 'no transaction of its own',
            '->status =' => 'no direct status assignment',
            "update(['status'" => 'no direct status update',
        ] as $needle => $why) {
            $this->assertStringNotContainsString($needle, $code, "CalendarController must contain {$why}.");
        }

        $this->assertStringContainsString('AppointmentBookingService', $code);
    }

    // -----------------------------------------------------------------
    // Reach and validation
    // -----------------------------------------------------------------

    public function test_a_booking_type_from_a_sibling_location_is_not_found(): void
    {
        $staff = $this->bookableStaff();
        $siblingType = $this->bookingType($this->locationB);
        $contactUid = DB::table('contacts')->where('id', $this->contactId())->value('uid');

        $this->post($this->calendarUrl('appointments.store', $this->scope()), [
            'booking_type_uid' => $siblingType->uid,
            'contact_uid' => $contactUid,
            'staff' => (string) $staff->id,
            'date' => '2027-03-01',
            'time' => '10:00',
        ])->assertNotFound();

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_an_inactive_booking_type_is_not_bookable(): void
    {
        $staff = $this->bookableStaff();
        $type = $this->bookingType(null, ['is_active' => false]);
        $contactUid = DB::table('contacts')->where('id', $this->contactId())->value('uid');

        $this->post($this->calendarUrl('appointments.store', $this->scope()), [
            'booking_type_uid' => $type->uid,
            'contact_uid' => $contactUid,
            'staff' => (string) $staff->id,
            'date' => '2027-03-01',
            'time' => '10:00',
        ])->assertNotFound();
    }

    /** Addendum §5: a Contact attributed to a sibling Location is never attached here. */
    public function test_a_contact_from_a_sibling_location_is_not_bookable(): void
    {
        $staff = $this->bookableStaff();
        $type = $this->bookingType();
        $contactId = $this->contactId();
        DB::table('contacts')->where('id', $contactId)->update(['location_id' => $this->locationB->id]);
        $contactUid = DB::table('contacts')->where('id', $contactId)->value('uid');

        $this->post($this->calendarUrl('appointments.store', $this->scope()), [
            'booking_type_uid' => $type->uid,
            'contact_uid' => $contactUid,
            'staff' => (string) $staff->id,
            'date' => '2027-03-01',
            'time' => '10:00',
        ])->assertNotFound();

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_a_contact_from_another_business_is_not_bookable(): void
    {
        [$foreignBusiness, $foreignLocation] = $this->foreignBusinessWithLocation();
        $staff = $this->bookableStaff();
        $type = $this->bookingType();

        $group = \App\Models\ContactGroups::create([
            'customer_id' => $foreignBusiness->customer_id,
            'business_id' => $foreignBusiness->id,
            'name' => 'Foreign group',
            'status' => true,
        ]);
        $foreignContactUid = (string) \Illuminate\Support\Str::uuid();
        DB::table('contacts')->insert([
            'uid' => $foreignContactUid,
            'customer_id' => $foreignBusiness->customer_id,
            'business_id' => $foreignBusiness->id,
            'location_id' => $foreignLocation->id,
            'group_id' => $group->id,
            'phone' => '14155550000',
            'status' => 'subscribe',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->post($this->calendarUrl('appointments.store', $this->scope()), [
            'booking_type_uid' => $type->uid,
            'contact_uid' => $foreignContactUid,
            'staff' => (string) $staff->id,
            'date' => '2027-03-01',
            'time' => '10:00',
        ])->assertNotFound();
    }

    /** A Contact with no Location attribution (legacy) IS bookable, per Addendum §5's null-is-legacy rule. */
    public function test_a_contact_with_no_location_attribution_is_bookable(): void
    {
        $staff = $this->bookableStaff();
        $type = $this->bookingType();
        $contactId = $this->contactId();
        DB::table('contacts')->where('id', $contactId)->update(['location_id' => null]);
        $contactUid = DB::table('contacts')->where('id', $contactId)->value('uid');

        $this->post($this->calendarUrl('appointments.store', $this->scope()), [
            'booking_type_uid' => $type->uid,
            'contact_uid' => $contactUid,
            'staff' => (string) $staff->id,
            'date' => '2027-03-01',
            'time' => '10:00',
        ])->assertRedirect();

        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_the_create_form_searches_only_contacts_bookable_at_this_location(): void
    {
        // The picker renders inside the booking form, which needs a Booking Type to offer.
        $this->bookingType();
        $atA = $this->contactId();
        $atB = $this->contactId();
        DB::table('contacts')->where('id', $atA)->update(['phone' => '14155550111']);
        DB::table('contacts')->where('id', $atB)->update(['phone' => '14155550112', 'location_id' => $this->locationB->id]);

        $html = $this->get($this->calendarUrl('appointments.create', $this->scope()) . '?q=1415555011')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('14155550111', $html);
        $this->assertStringNotContainsString('14155550112', $html, 'A sibling Location\'s Contact leaked into the picker.');
    }

    public function test_validation_rejects_a_malformed_submission_without_touching_the_engine(): void
    {
        $engine = $this->engineDouble();
        $engine->shouldNotReceive('book');
        $engine->shouldNotReceive('bookWithRoundRobin');

        $type = $this->bookingType();

        $this->post($this->calendarUrl('appointments.store', $this->scope()), [
            'booking_type_uid' => $type->uid,
            'contact_uid' => 'x',
            'staff' => 'auto',
            'date' => 'not-a-date',
            'time' => '25:99',
        ])->assertSessionHasErrors(['date', 'time']);
    }

    public function test_a_non_numeric_explicit_staff_is_a_validation_error_not_a_confusing_engine_refusal(): void
    {
        $this->engineDouble()->shouldNotReceive('book');

        $type = $this->bookingType();
        $contactUid = DB::table('contacts')->where('id', $this->contactId())->value('uid');

        $this->post($this->calendarUrl('appointments.store', $this->scope()), [
            'booking_type_uid' => $type->uid,
            'contact_uid' => $contactUid,
            'staff' => 'someone',
            'date' => '2027-03-01',
            'time' => '10:00',
        ])->assertSessionHasErrors('staff');
    }

    /** Every Location-authorized role may manage appointments at it (§6: role-blind). */
    public function test_admin_and_staff_may_manage_appointments_at_a_location_they_can_reach(): void
    {
        [$appointment] = $this->booked();

        foreach ([$this->admin, $this->staff] as $actor) {
            $this->authenticate($actor);

            $this->get($this->calendarUrl('appointments.show', array_merge($this->scope(), [$appointment->uid])))->assertOk();
        }

        $this->authenticate($this->staff);
        $this->post($this->actionUrl('complete', $appointment))->assertRedirect();
        $this->assertSame(AppointmentStatus::Completed, $appointment->fresh()->status);
        $this->assertSame((int) $this->staff->id, (int) $appointment->fresh()->resolved_by_user_id);
    }

    /** A member with no reach to this Location cannot act on its appointments at all. */
    public function test_an_actor_without_location_access_cannot_run_any_action(): void
    {
        [$appointment] = $this->booked();

        $this->authenticate($this->memberGrantedOnly($this->locationB));

        foreach (['cancel', 'complete', 'no-show'] as $action) {
            $this->post($this->actionUrl($action, $appointment))->assertNotFound();
        }

        $this->post($this->actionUrl('reschedule', $appointment), ['date' => '2027-03-01', 'time' => '15:00'])->assertNotFound();

        $this->assertSame(AppointmentStatus::Scheduled, $appointment->fresh()->status);
    }
}
