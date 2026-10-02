<?php

namespace Tests\Feature\V1Acceptance\CalendarBooking;

use App\Enums\Business\BusinessLocationLifecycleState;
use App\Enums\Business\BusinessStatus;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\Appointment;
use App\Models\BookingType;
use App\Models\StaffAvailabilityRule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Acceptance journey C — the unauthenticated scheduler, end to end, on the real
 * entitlement stack. Journey H (Contact linkage) lives in
 * ContactLinkageJourneyTest. Every refusal here must leave nothing behind.
 */
class PublicSchedulerJourneyTest extends CalendarJourneyTestCase
{
    private BookingType $type;

    private int $staffId;

    protected function setUp(): void
    {
        parent::setUp();
        $staff = $this->bookableStaff(); // Monday 08:00–20:00 at Location A
        $this->staffId = (int) $staff->id;
        $this->type = $this->typeWithStaff($this->locationA, $staff);
        $this->type->update(['name' => 'Free consultation', 'description' => 'Bring your questions']);
    }

    private function book(array $overrides = [], ?BookingType $type = null)
    {
        $type ??= $this->type;

        return $this->from($this->publicShow($type))->post($this->publicStore($type), $this->guest($overrides));
    }

    public function test_the_public_page_is_reachable_without_signing_in_and_names_the_booking_type(): void
    {
        $this->get($this->publicShow($this->type))
            ->assertOk()
            ->assertSee('Free consultation')
            ->assertSee('Bring your questions')
            ->assertSee('60 minutes')
            ->assertSee('America/New_York');

        // Nothing about the page leaks internals.
        $this->get($this->publicShow($this->type, '2027-03-01'))
            ->assertOk()
            ->assertDontSee($this->type->uid)
            ->assertDontSee('staff_user_id')
            ->assertDontSee((string) $this->business->uid);
    }

    public function test_unavailable_slots_are_not_offered(): void
    {
        $this->assertSame(
            ['08:00', '08:30', '09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '12:00', '12:30',
                '13:00', '13:30', '14:00', '14:30', '15:00', '15:30', '16:00', '16:30', '17:00', '17:30',
                '18:00', '18:30', '19:00'],
            $this->offeredSlots($this->type, '2027-03-01')
        );

        // A booked 10:00–11:00 removes every start that would overlap it.
        $this->engine()->book($this->type, $this->staffId, $this->contactId(), $this->slotStart('10:00:00'));
        $slots = $this->offeredSlots($this->type, '2027-03-01');
        foreach (['09:30', '10:00', '10:30'] as $taken) {
            $this->assertNotContains($taken, $slots);
        }
        $this->assertContains('09:00', $slots);
        $this->assertContains('11:00', $slots);

        // Days with no window offer nothing and say so.
        $this->get($this->publicShow($this->type, '2027-03-02'))->assertOk()->assertSee('No available times on this date.');

        // Past instants are not offered: Thursday 2027-02-25, "now" is 07:00 New York.
        StaffAvailabilityRule::create([
            'business_location_id' => $this->locationA->id, 'staff_user_id' => $this->staffId,
            'day_of_week' => 4, 'start_time' => '06:00:00', 'end_time' => '12:00:00',
        ]);
        $slots = $this->offeredSlots($this->type, '2027-02-25');
        $this->assertSame('07:30', $slots[0]);
        $this->assertNotContains('07:00', $slots);

        // The page's own window: yesterday and 31+ days ahead do not exist.
        $this->get($this->publicShow($this->type, '2027-02-24'))->assertNotFound();
        $this->get($this->publicShow($this->type, '2027-03-27'))->assertOk();
        $this->get($this->publicShow($this->type, '2027-03-28'))->assertNotFound();
        foreach (['not-a-date', '2027-13-45', '2027-02-30', '20270301'] as $bad) {
            $this->get($this->publicShow($this->type, $bad))->assertNotFound();
        }
    }

    public function test_a_guest_booking_creates_exactly_one_correctly_attributed_appointment(): void
    {
        $this->forgetEvents();

        $this->book()->assertRedirect(route('public.booking.confirmed', [$this->type->public_booking_uuid]));

        $this->assertSame(1, $this->appointmentCount());
        $appointment = Appointment::query()->firstOrFail();
        $this->assertSame((int) $this->locationA->id, (int) $appointment->business_location_id);
        $this->assertSame((int) $this->type->id, (int) $appointment->booking_type_id);
        $this->assertSame($this->staffId, (int) $appointment->staff_user_id);
        $this->assertSame('scheduled', $appointment->status->value);
        $this->assertSame($this->slotStart('10:00:00')->format('Y-m-d H:i:s'), Carbon::parse($appointment->start_at)->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($this->slotStart('11:00:00')->format('Y-m-d H:i:s'), Carbon::parse($appointment->end_at)->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($appointment->created_by_user_id, 'a guest booking has no internal creator');

        // The Contact belongs to this Business AND this Location.
        $contact = DB::table('contacts')->where('id', $appointment->contact_id)->first();
        $this->assertSame((int) $this->business->id, (int) $contact->business_id);
        $this->assertSame((int) $this->locationA->id, (int) $contact->location_id);
        $this->assertSame((int) $this->business->customer_id, (int) $contact->customer_id);
        $this->assertSame('14155551234', (string) $contact->phone);

        // One committed booking → exactly one Scheduled event, carrying the same identity.
        $scheduled = $this->eventsOf(\App\Events\Calendar\AppointmentScheduled::class);
        $this->assertCount(1, $scheduled);
        $this->assertSame((int) $appointment->id, (int) $scheduled[0]->appointmentId);
        $this->assertSame((int) $this->business->id, (int) $scheduled[0]->businessId);
        $this->assertSame((int) $this->locationA->id, (int) $scheduled[0]->businessLocationId);
        $this->assertNull($scheduled[0]->createdByUserId);

        $this->get(route('public.booking.confirmed', [$this->type->public_booking_uuid]))->assertOk();
        $this->assertSame(1, $this->appointmentCount(), 'viewing the confirmation never books again');
    }

    public function test_replay_and_double_submit_never_create_a_second_appointment(): void
    {
        $this->book()->assertRedirect(route('public.booking.confirmed', [$this->type->public_booking_uuid]));
        $this->forgetEvents();

        // Browser back + resubmit, an impatient double click, a scripted replay: all the same POST.
        for ($i = 0; $i < 3; $i++) {
            $this->book()
                ->assertRedirect($this->publicShow($this->type))
                ->assertSessionHasErrors('time');
        }

        $this->assertSame(1, $this->appointmentCount());
        $this->assertSame(1, $this->contactCount());
        $this->assertSame([], $this->eventsOf(\App\Events\Calendar\AppointmentScheduled::class), 'a refused replay emits nothing');
    }

    public function test_a_stale_page_whose_slot_was_taken_meanwhile_is_refused_cleanly(): void
    {
        $this->assertContains('10:00', $this->offeredSlots($this->type, '2027-03-01'));

        // Someone else (the owner, in the staff UI) takes the slot after the guest's page rendered.
        $this->engine()->book($this->type, $this->staffId, $this->contactId(), $this->slotStart('10:00:00'));
        $contactsBefore = $this->contactCount();

        $this->book(['time' => '10:30'])->assertSessionHasErrors('time');

        $this->assertSame(1, $this->appointmentCount());
        $this->assertSame($contactsBefore, $this->contactCount(), 'a refused booking must not leave a Contact behind');
    }

    public function test_invalid_expired_and_malformed_submissions_fail_safely(): void
    {
        $cases = [
            'past instant' => ['date' => '2027-02-25', 'time' => '06:00'],
            'beyond the booking window' => ['date' => '2027-05-03'],
            'off-grid minute' => ['time' => '10:15'],
            'unparseable time' => ['time' => 'ten'],
            'outside working hours' => ['time' => '07:00'],
            'day without a window' => ['date' => '2027-03-02'],
            'missing first name' => ['first_name' => ''],
            'missing phone' => ['phone' => ''],
            'letters in phone' => ['phone' => '415-CALL-NOW'],
            'overlong phone' => ['phone' => str_repeat('1', 40)],
        ];

        foreach ($cases as $label => $override) {
            $this->book($override)->assertSessionHasErrors();
            $this->assertSame(0, $this->appointmentCount(), "no appointment for: {$label}");
            $this->assertSame(0, $this->contactCount(), "no contact for: {$label}");
        }
    }

    public function test_guest_input_cannot_choose_the_location_staff_type_or_business(): void
    {
        $other = $this->memberWithFullReach(WorkspaceMembershipRole::Staff);
        $this->giveMondayAvailability((int) $other->id, $this->locationB);
        [$foreignBusiness, $foreignLocation] = $this->foreignBusinessWithLocation();

        $this->book([
            'business_location_id' => $this->locationB->id,
            'location_id' => $foreignLocation->id,
            'business_id' => $foreignBusiness->id,
            'staff_user_id' => $other->id,
            'booking_type_id' => 999999,
            'status' => 'completed',
        ])->assertRedirect(route('public.booking.confirmed', [$this->type->public_booking_uuid]));

        $appointment = Appointment::query()->firstOrFail();
        $this->assertSame((int) $this->locationA->id, (int) $appointment->business_location_id);
        $this->assertSame((int) $this->type->id, (int) $appointment->booking_type_id);
        $this->assertSame($this->staffId, (int) $appointment->staff_user_id);
        $this->assertSame('scheduled', $appointment->status->value);
        $this->assertSame(0, DB::table('contacts')->where('business_id', $foreignBusiness->id)->count());
    }

    public function test_unknown_foreign_and_unservable_identifiers_all_answer_one_indistinguishable_404(): void
    {
        $unservable = [];

        // Unknown and malformed identifiers.
        $unservable['unknown uuid'] = route('public.booking.show', [(string) Str::uuid()]);
        $unservable['internal uid is not the public address'] = route('public.booking.show', [$this->type->uid]);
        $this->get('/book/not-a-uuid')->assertNotFound();

        // Archived Location.
        $archivedLocation = $this->makeLocation($this->business, ['name' => 'Archived']);
        $archivedType = $this->bookingType($archivedLocation);
        $archivedType->staff()->attach($this->staffId);
        $this->giveMondayAvailability($this->staffId, $archivedLocation);
        $this->assertSame(200, $this->get($this->publicShow($archivedType))->status());
        DB::table('business_locations')->where('id', $archivedLocation->id)->update(['lifecycle_state' => BusinessLocationLifecycleState::Archived->value]);
        $unservable['archived Location'] = $this->publicShow($archivedType);

        // A type nobody can currently serve (its only member lapsed).
        $lapsed = $this->memberWithFullReach(WorkspaceMembershipRole::Staff);
        $orphanType = $this->typeWithStaff($this->locationB, $lapsed);
        $this->giveMondayAvailability((int) $lapsed->id, $this->locationB);
        $this->assertSame(200, $this->get($this->publicShow($orphanType))->status());
        DB::table('workspace_memberships')->where('user_id', $lapsed->id)->update(['is_active' => false]);
        $unservable['no eligible staff'] = $this->publicShow($orphanType);

        $bodies = [];
        foreach ($unservable as $label => $url) {
            $response = $this->get($url);
            $response->assertNotFound();
            $bodies[$label] = $response->getContent();
        }
        $this->assertCount(1, array_unique(array_map(fn (string $b): string => preg_replace('/\s+/', ' ', $b), $bodies)), 'every refusal must render the same page');

        // The mutation route refuses the same way and writes nothing.
        $this->post(route('public.booking.store', [$archivedType->public_booking_uuid]), $this->guest())->assertNotFound();
        $this->post(route('public.booking.store', [$orphanType->public_booking_uuid]), $this->guest())->assertNotFound();
        $this->get(route('public.booking.confirmed', [(string) Str::uuid()]))->assertNotFound();
        $this->assertSame(0, $this->appointmentCount());
        $this->assertSame(0, $this->contactCount());
    }

    public function test_a_business_that_is_not_active_or_whose_workspace_is_inactive_cannot_be_booked(): void
    {
        $this->get($this->publicShow($this->type))->assertOk();

        DB::table('businesses')->where('id', $this->business->id)->update(['status' => BusinessStatus::Draft->value]);
        $this->get($this->publicShow($this->type))->assertNotFound();
        $this->post($this->publicStore($this->type), $this->guest())->assertNotFound();

        DB::table('businesses')->where('id', $this->business->id)->update(['status' => BusinessStatus::Active->value]);
        $this->get($this->publicShow($this->type))->assertOk();

        DB::table('workspaces')->where('id', $this->workspace->id)->update(['is_active' => false]);
        $this->get($this->publicShow($this->type))->assertNotFound();
        $this->post($this->publicStore($this->type), $this->guest())->assertNotFound();

        $this->assertSame(0, $this->appointmentCount());
    }
}
