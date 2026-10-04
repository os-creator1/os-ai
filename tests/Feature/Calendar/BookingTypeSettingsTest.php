<?php

namespace Tests\Feature\Calendar;

use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\Appointment;
use App\Models\BookingType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\TestCase;

/**
 * Booking Type scheduling settings: booking window, minimum notice, buffers and
 * start-time interval, enforced by the server for the public scheduler and the
 * booking engine, and editable by the Business.
 *
 * Unless a test says otherwise "now" is Thursday 2027-02-25 12:00 UTC. The
 * Business is America/New_York with a Monday 08:00-20:00 window, so the bookable
 * Mondays are those inside the type's window.
 */
class BookingTypeSettingsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCalendarHttpFixtures;

    private int $staffId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-25 12:00:00 UTC');
        $this->bootCalendarHttpFixtures();
        $this->staffId = (int) $this->bookableStaff()->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function type(array $settings = [], string $name = 'Settings type'): BookingType
    {
        $type = $this->bookingType(null, ['name' => $name] + $settings);
        $type->staff()->attach($this->staffId);

        return $type;
    }

    private function url(BookingType $type, string $suffix = ''): string
    {
        return route('public.booking.show', [$type->public_booking_uuid]).$suffix;
    }

    private function jget(string $url)
    {
        return $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => '*/*'])->get($url);
    }

    private function labels(BookingType $type, string $date = '2027-03-01', ?string $tz = null): array
    {
        $url = $this->url($type, '/slots?date='.$date.($tz ? '&tz='.urlencode($tz) : ''));

        return array_column($this->jget($url)->assertOk()->json('slots'), 'label');
    }

    private function dates(BookingType $type, string $month): array
    {
        return $this->jget($this->url($type, '/dates?month='.$month))->assertOk()->json('available');
    }

    private function book(BookingType $type, string $date, string $time, array $overrides = [])
    {
        return $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => '*/*'])
            ->post(route('public.booking.store', [$type->public_booking_uuid]), array_merge([
                'date' => $date, 'time' => $time, 'first_name' => 'Ada', 'last_name' => 'Lovelace',
                'email' => 'ada@example.test', 'phone' => '+1 (415) 555-1234',
            ], $overrides));
    }

    private function engineBook(BookingType $type, string $time): Appointment
    {
        return $this->engine()->book($type, $this->staffId, $this->contactId(), $this->slotStart($time));
    }

    private function editUrl(string $name, BookingType $type): string
    {
        return $this->calendarUrl('booking-types.'.$name, array_merge($this->scopeFor($this->locationA), [$type->uid]));
    }

    // ---- defaults ------------------------------------------------------------

    public function test_a_type_with_no_settings_behaves_exactly_as_before(): void
    {
        $type = $this->type();

        $row = DB::table('booking_types')->where('id', $type->id)->first();
        $this->assertSame([30, 0, 0, 0, 30], [
            (int) $row->booking_window_days, (int) $row->minimum_notice_minutes,
            (int) $row->buffer_before_minutes, (int) $row->buffer_after_minutes, (int) $row->slot_interval_minutes,
        ]);
        // A model that has not re-read its defaults still reports them.
        $this->assertSame([30, 0, 0, 0, 30], [
            $type->windowDays(), $type->minimumNoticeMinutes(), $type->bufferBeforeMinutes(),
            $type->bufferAfterMinutes(), $type->slotIntervalMinutes(),
        ]);

        $labels = $this->labels($type);
        $this->assertCount(23, $labels);
        $this->assertSame(['8:00 AM', '7:00 PM'], [$labels[0], end($labels)]);
        $this->assertSame(['2027-03-01', '2027-03-08', '2027-03-15', '2027-03-22'], $this->dates($type, '2027-03'));
        $this->get($this->url($type, '?date=2027-03-27'))->assertOk();
        $this->get($this->url($type, '?date=2027-03-28'))->assertNotFound();
    }

    // ---- booking window ------------------------------------------------------

    public function test_the_booking_window_decides_which_days_are_open(): void
    {
        $seven = $this->type(['booking_window_days' => 7], 'Seven');
        $fourteen = $this->type(['booking_window_days' => 14], 'Fourteen');
        $sixty = $this->type(['booking_window_days' => 60], 'Sixty');

        $this->assertSame(['2027-03-01'], $this->dates($seven, '2027-03'));
        $this->assertSame(['2027-03-01', '2027-03-08'], $this->dates($fourteen, '2027-03'));
        $this->assertSame(['2027-03-01', '2027-03-08', '2027-03-15', '2027-03-22', '2027-03-29'], $this->dates($sixty, '2027-03'));
        $this->assertSame(['2027-04-05', '2027-04-12', '2027-04-19', '2027-04-26'], $this->dates($sixty, '2027-04'));
        $this->assertSame([], $this->dates($seven, '2027-04'));

        // The last day of the window is open, the next one is not; the JSON says where it ends.
        $this->get($this->url($seven, '?date=2027-03-04'))->assertOk();
        $this->get($this->url($seven, '?date=2027-03-05'))->assertNotFound();
        $this->assertSame('2027-03-04', $this->jget($this->url($seven, '/dates?month=2027-03'))->json('to'));
        $this->assertSame('2027-04-26', $this->jget($this->url($sixty, '/dates?month=2027-04'))->json('to'));

        // Booking beyond the window is refused by the server, not just hidden.
        $this->book($seven, '2027-03-08', '10:00')->assertStatus(422)->assertJsonValidationErrors('time');
        $this->book($sixty, '2027-04-05', '10:00')->assertCreated();
    }

    // ---- minimum notice ------------------------------------------------------

    public function test_minimum_notice_removes_slots_that_are_too_soon_and_is_enforced_on_booking(): void
    {
        // Monday 2027-03-01 09:00 New York.
        Carbon::setTestNow('2027-03-01 14:00:00 UTC');
        $none = $this->type([], 'No notice');
        $notice = $this->type(['minimum_notice_minutes' => 240], 'Four hours');

        $this->assertSame('9:30 AM', $this->labels($none)[0], 'With no notice the next start after now is offered.');
        $labels = $this->labels($notice);
        $this->assertSame('1:00 PM', $labels[0], '09:00 + 4h = 13:00: that start is allowed, 12:30 is not.');
        $this->assertNotContains('12:30 PM', $labels);

        $this->book($notice, '2027-03-01', '12:30')->assertStatus(422)->assertJsonValidationErrors('time');
        $this->assertDatabaseCount('appointments', 0);
        $this->book($notice, '2027-03-01', '13:00')->assertCreated();
    }

    public function test_minimum_notice_is_server_side_and_independent_of_the_visitor_timezone(): void
    {
        Carbon::setTestNow('2027-03-01 14:00:00 UTC');
        $notice = $this->type(['minimum_notice_minutes' => 240]);

        // 13:00 New York is 03:00 the next morning in Tokyo; nothing earlier is offered there either.
        $tokyo = $this->jget($this->url($notice, '/slots?date=2027-03-02&tz=Asia/Tokyo'))->assertOk()->json('slots');
        $this->assertSame('3:00 AM', $tokyo[0]['label']);
        $this->assertSame(['2027-03-01', '13:00'], [$tokyo[0]['date'], $tokyo[0]['time']]);
        $this->assertSame([], array_column($this->jget($this->url($notice, '/slots?date=2027-03-01&tz=Asia/Tokyo'))->json('slots'), 'label'));
    }

    // ---- buffers -------------------------------------------------------------

    public function test_buffer_before_blocks_the_start_after_a_neighbouring_booking(): void
    {
        $this->engineBook($this->type([], 'Plain'), '10:00:00'); // 10:00-11:00

        $plain = $this->type([], 'Plain 2');
        $before = $this->type(['buffer_before_minutes' => 30], 'Before 30');

        $this->assertContains('11:00 AM', $this->labels($plain));
        $labels = $this->labels($before);
        $this->assertNotContains('11:00 AM', $labels, '11:00 minus the 30 minute buffer touches the 10:00-11:00 booking.');
        $this->assertContains('11:30 AM', $labels);
        $this->assertContains('9:00 AM', $labels, 'A before-buffer does not push an earlier slot away.');
    }

    public function test_buffer_after_blocks_the_start_before_a_neighbouring_booking(): void
    {
        $this->engineBook($this->type([], 'Plain'), '10:00:00');

        $after = $this->type(['buffer_after_minutes' => 15], 'After 15');
        $labels = $this->labels($after);

        $this->assertNotContains('9:00 AM', $labels, '9:00-10:00 plus 15 minutes runs into the 10:00 booking.');
        $this->assertContains('8:30 AM', $labels, '8:30-9:30 plus 15 minutes still clears it.');
        $this->assertContains('11:00 AM', $labels, 'An after-buffer does not push a later slot away.');
    }

    public function test_an_existing_appointments_own_buffers_protect_it(): void
    {
        $buffered = $this->type(['buffer_before_minutes' => 30, 'buffer_after_minutes' => 15], 'Buffered');
        $this->engineBook($buffered, '10:00:00'); // occupies 09:30-11:15

        $plain = $this->type([], 'Plain');
        $labels = $this->labels($plain);
        $this->assertNotContains('9:00 AM', $labels);
        $this->assertNotContains('11:00 AM', $labels, '11:00 starts inside the 11:15 end of the buffer.');
        $this->assertContains('8:30 AM', $labels);
        $this->assertContains('11:30 AM', $labels);
    }

    public function test_buffers_never_change_the_appointments_real_start_and_end(): void
    {
        $buffered = $this->type(['buffer_before_minutes' => 30, 'buffer_after_minutes' => 15], 'Buffered');

        $this->book($buffered, '2027-03-01', '10:00')->assertCreated();

        $appointment = Appointment::query()->sole();
        $this->assertSame('2027-03-01 15:00:00', Carbon::parse($appointment->start_at)->utc()->toDateTimeString());
        $this->assertSame('2027-03-01 16:00:00', Carbon::parse($appointment->end_at)->utc()->toDateTimeString(), 'The appointment keeps its 60 real minutes.');
    }

    public function test_an_external_calendar_busy_period_is_widened_by_the_buffer(): void
    {
        $connectionId = DB::table('external_calendar_connections')->insertGetId([
            'uid' => (string) Str::uuid(), 'user_id' => $this->staffId, 'provider' => 'google', 'state' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('external_calendar_busy_blocks')->insert([
            'external_calendar_connection_id' => $connectionId, 'provider_event_id' => 'evt-1',
            'start_at' => $this->slotStart('13:00:00'), 'end_at' => $this->slotStart('14:00:00'),
            'synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $plain = $this->labels($this->type([], 'Plain'));
        $buffered = $this->labels($this->type(['buffer_after_minutes' => 30], 'After 30'));

        $this->assertContains('12:00 PM', $plain, 'Without a buffer 12:00-13:00 just touches the busy block.');
        $this->assertNotContains('12:00 PM', $buffered, 'A 30 minute after-buffer makes 12:00 run into it.');
        $this->assertContains('11:30 AM', $buffered);
    }

    public function test_a_buffered_neighbour_cannot_be_double_booked_through_the_form(): void
    {
        $buffered = $this->type(['buffer_after_minutes' => 30], 'After 30');
        $this->book($buffered, '2027-03-01', '10:00')->assertCreated();

        // 11:00 is free on the clock but inside the first booking's after-buffer.
        $this->book($buffered, '2027-03-01', '11:00', ['phone' => '14155550000', 'email' => 'g@example.test'])->assertStatus(409);
        $this->book($buffered, '2027-03-01', '11:30', ['phone' => '14155550001', 'email' => 'h@example.test'])->assertCreated();
        $this->assertDatabaseCount('appointments', 2);
    }

    // ---- slot interval -------------------------------------------------------

    public function test_a_fifteen_minute_interval_is_independent_of_the_duration(): void
    {
        $type = $this->type(['duration_minutes' => 45, 'slot_interval_minutes' => 15], '45 by 15');

        $slots = $this->jget($this->url($type, '/slots?date=2027-03-01'))->assertOk()->json('slots');
        $labels = array_column($slots, 'label');
        $this->assertSame(['8:00 AM', '8:15 AM', '8:30 AM', '8:45 AM', '9:00 AM'], array_slice($labels, 0, 5));
        $this->assertSame('7:15 PM', end($labels), 'The last 45-minute start that still ends by 20:00.');
        $this->assertCount(46, $labels);
        $this->assertSame('9:00 AM', $slots[4]['label']);
        $this->assertSame('9:45 AM', $slots[4]['end_label']);

        $this->book($type, '2027-03-01', '10:15')->assertCreated();
        $appointment = Appointment::query()->sole();
        $this->assertEquals(45, Carbon::parse($appointment->start_at)->diffInMinutes(Carbon::parse($appointment->end_at)));
    }

    public function test_starts_off_the_types_interval_are_refused(): void
    {
        $thirty = $this->type([], 'Thirty');
        $this->book($thirty, '2027-03-01', '10:15')->assertStatus(422)->assertJsonValidationErrors('time');
        $this->assertDatabaseCount('appointments', 0);

        $forty5 = $this->type(['slot_interval_minutes' => 45], 'Forty five');
        $labels = $this->labels($forty5);
        $this->assertSame(['8:15 AM', '9:00 AM', '9:45 AM'], array_slice($labels, 0, 3));
        $this->book($forty5, '2027-03-01', '10:00')->assertStatus(422);
        $this->book($forty5, '2027-03-01', '09:45')->assertCreated();
    }

    // ---- readiness / fail closed ---------------------------------------------

    public function test_the_public_page_is_closed_without_staff_when_inactive_or_with_invalid_settings(): void
    {
        $noStaff = $this->bookingType(null, ['name' => 'No staff']);
        $this->get($this->url($noStaff))->assertNotFound();

        $inactive = $this->type(['is_active' => false], 'Inactive');
        $this->get($this->url($inactive))->assertNotFound();

        $broken = $this->type([], 'Broken');
        DB::table('booking_types')->where('id', $broken->id)->update(['slot_interval_minutes' => 20]);
        $this->get($this->url($broken))->assertNotFound();
        $this->jget($this->url($broken, '/slots?date=2027-03-01'))->assertNotFound();
        $this->book($broken, '2027-03-01', '10:00')->assertNotFound();
    }

    public function test_readiness_names_the_reason_a_type_is_not_bookable(): void
    {
        $manager = app(\App\Library\Calendar\BookingTypeManager::class);

        $this->assertStringContainsString('Inactive', $manager->publicBookingReadiness($this->type(['is_active' => false], 'A'))['reason']);
        $this->assertStringContainsString('No one is assigned', $manager->publicBookingReadiness($this->bookingType(null, ['name' => 'B']))['reason']);

        $noHours = $this->bookingType(null, ['name' => 'C']);
        $noHours->staff()->attach($this->memberWithFullReach(WorkspaceMembershipRole::Staff)->id);
        $this->assertStringContainsString('No working hours', $manager->publicBookingReadiness($noHours)['reason']);

        $broken = $this->type([], 'D');
        DB::table('booking_types')->where('id', $broken->id)->update(['booking_window_days' => 0]);
        $this->assertStringContainsString('Invalid scheduling settings', $manager->publicBookingReadiness($broken->fresh())['reason']);

        $this->assertSame(['ready' => true, 'reason' => null], $manager->publicBookingReadiness($this->type([], 'E')));
    }

    // ---- the editor ----------------------------------------------------------

    public function test_the_owner_saves_every_setting_and_the_public_page_follows(): void
    {
        $type = $this->type([], 'Photo booth');
        $this->authenticate($this->owner->user);

        $this->post($this->editUrl('update', $type), [
            'name' => 'Photo booth', 'duration_minutes' => 45, 'description' => 'Fun',
            'meeting_instructions' => 'Park behind the shop.', 'color' => '#0F766E',
            'slot_interval_minutes' => 15, 'minimum_notice_minutes' => 240,
            'booking_window_days' => 60, 'buffer_before_minutes' => 15, 'buffer_after_minutes' => 30,
        ])->assertRedirect();

        $this->assertDatabaseHas('booking_types', [
            'id' => $type->id, 'duration_minutes' => 45, 'slot_interval_minutes' => 15, 'minimum_notice_minutes' => 240,
            'booking_window_days' => 60, 'buffer_before_minutes' => 15, 'buffer_after_minutes' => 30,
            'meeting_instructions' => 'Park behind the shop.',
        ]);

        $this->get($this->editUrl('edit', $type))->assertOk()
            ->assertSee('data-section="booking-type-scheduling"', false)
            ->assertSee('data-section="booking-type-buffers"', false)
            ->assertSee('Availability comes from assigned staff working hours.')
            ->assertSee('data-role="view-staff-availability"', false)
            ->assertSee('data-role="copy-public-link"', false)
            ->assertSee('data-role="open-public-page"', false);

        $this->get($this->url($type))->assertOk()
            ->assertSee('Park behind the shop.')
            ->assertSee('45 minutes');
        $this->assertSame('2027-04-26', $this->jget($this->url($type, '/dates?month=2027-04'))->json('to'));
    }

    public function test_a_partial_update_keeps_the_settings_it_does_not_mention(): void
    {
        $type = $this->type(['buffer_after_minutes' => 30, 'slot_interval_minutes' => 15, 'booking_window_days' => 90]);
        $this->authenticate($this->owner->user);

        $this->post($this->editUrl('update', $type), ['name' => 'Renamed', 'duration_minutes' => 60])->assertRedirect();

        $this->assertDatabaseHas('booking_types', [
            'id' => $type->id, 'name' => 'Renamed', 'buffer_after_minutes' => 30,
            'slot_interval_minutes' => 15, 'booking_window_days' => 90,
        ]);
    }

    public function test_invalid_settings_are_refused_and_change_nothing(): void
    {
        $type = $this->type();
        $this->authenticate($this->owner->user);
        $base = ['name' => 'X', 'duration_minutes' => 60];

        foreach ([
            ['slot_interval_minutes' => 20], ['slot_interval_minutes' => 0], ['booking_window_days' => 0],
            ['booking_window_days' => 400], ['minimum_notice_minutes' => -1], ['buffer_before_minutes' => 999],
            ['buffer_after_minutes' => 'lots'],
        ] as $bad) {
            $this->post($this->editUrl('update', $type), $base + $bad)->assertSessionHasErrors();
        }
        $this->assertDatabaseHas('booking_types', ['id' => $type->id, 'name' => 'Settings type', 'slot_interval_minutes' => 30]);
    }

    public function test_the_create_form_stores_settings_and_defaults_the_rest(): void
    {
        $this->authenticate($this->owner->user);
        $this->post($this->calendarUrl('booking-types.store', $this->scopeFor($this->locationA)), [
            'name' => 'Walkthrough', 'duration_minutes' => 30, 'buffer_before_minutes' => 10,
        ])->assertRedirect();

        $this->assertDatabaseHas('booking_types', [
            'name' => 'Walkthrough', 'buffer_before_minutes' => 10, 'buffer_after_minutes' => 0,
            'slot_interval_minutes' => 30, 'booking_window_days' => 30, 'minimum_notice_minutes' => 0,
        ]);
        $this->get($this->calendarUrl('booking-types.create', $this->scopeFor($this->locationA)))->assertOk()
            ->assertSee('name="slot_interval_minutes"', false)
            ->assertSee('name="minimum_notice_minutes"', false);
    }

    public function test_the_edit_page_warns_when_nobody_is_assigned_and_hides_the_public_link(): void
    {
        $type = $this->bookingType(null, ['name' => 'Lonely']);
        $this->authenticate($this->owner->user);

        $this->get($this->editUrl('edit', $type))->assertOk()
            ->assertSee('data-role="no-staff-warning"', false)
            ->assertSee('data-role="public-page-not-ready"', false)
            ->assertDontSee('data-role="open-public-page"', false)
            ->assertDontSee('data-role="copy-public-link"', false);
    }

    public function test_a_forged_staff_or_location_id_fails_closed(): void
    {
        $type = $this->type();
        [, $foreignLocation, $foreignOwner] = $this->foreignBusinessWithLocation();
        $foreignStaffId = (int) $foreignOwner->user_id;
        $this->authenticate($this->owner->user);

        // A person who belongs to another Business cannot be assigned.
        $this->post($this->editUrl('staff', $type), ['staff_user_ids' => [(string) $this->staffId, (string) $foreignStaffId]])->assertRedirect();
        $this->assertSame([$this->staffId], $type->staff()->pluck('users.id')->map(fn ($id) => (int) $id)->all());

        // A body that names another Location or booking type moves nothing.
        $this->post($this->editUrl('update', $type), [
            'name' => 'Still mine', 'duration_minutes' => 60,
            'business_location_id' => $foreignLocation->id, 'public_booking_uuid' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->assertDatabaseHas('booking_types', [
            'id' => $type->id, 'name' => 'Still mine', 'business_location_id' => $this->locationA->id,
            'public_booking_uuid' => $type->public_booking_uuid,
        ]);
    }

    // ---- timezone and storage ------------------------------------------------

    public function test_buffers_and_notice_leave_stored_timestamps_in_utc_and_visitor_conversion_intact(): void
    {
        $type = $this->type(['buffer_before_minutes' => 15, 'buffer_after_minutes' => 30, 'minimum_notice_minutes' => 60]);

        $slot = $this->jget($this->url($type, '/slots?date=2027-03-02&tz=Asia/Tokyo'))->assertOk()->json('slots.0');
        $this->assertSame('12:00 AM', $slot['label']); // Tokyo Tue 00:00 == NY Mon 10:00
        $this->book($type, $slot['date'], $slot['time'], ['visitor_timezone' => 'Asia/Tokyo'])->assertCreated();

        $row = DB::table('appointments')->first();
        $this->assertSame('2027-03-01 15:00:00', $row->start_at);
        $this->assertSame('2027-03-01 16:00:00', $row->end_at);
    }

    public function test_two_guests_racing_for_one_slot_with_buffers_still_create_one_appointment(): void
    {
        $type = $this->type(['buffer_before_minutes' => 15, 'buffer_after_minutes' => 15]);

        $statuses = [];
        foreach (range(1, 4) as $n) {
            $statuses[] = $this->book($type, '2027-03-01', '10:00', [
                'email' => "g{$n}@example.test", 'phone' => '1415555'.str_pad((string) (2000 + $n), 4, '0'),
            ])->getStatusCode();
        }

        $this->assertSame([201, 409, 409, 409], $statuses);
        $this->assertDatabaseCount('appointments', 1);
    }
}
