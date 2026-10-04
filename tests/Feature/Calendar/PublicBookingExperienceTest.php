<?php

namespace Tests\Feature\Calendar;

use App\Events\Calendar\AppointmentScheduled;
use App\Models\Appointment;
use App\Models\BookingType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\TestCase;

/**
 * The customer-facing scheduler: the page, its two read-only JSON questions
 * (open dates in a month, offered times on a day), the AJAX booking, and the
 * regressions that made a valid link 404 or a template print as text.
 *
 * "Now" is Thursday 2027-02-25 12:00 UTC. The Business is America/New_York with
 * a Monday 08:00-20:00 window, so the bookable Mondays are Mar 1, 8, 15 and 22
 * (the window ends Mar 27), and a 60-minute type starts on the half hour from
 * 08:00 through 19:00 local.
 */
class PublicBookingExperienceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCalendarHttpFixtures;

    private BookingType $type;

    private int $staffId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-25 12:00:00 UTC');
        $this->bootCalendarHttpFixtures();
        $staff = $this->bookableStaff();
        $this->staffId = (int) $staff->id;
        $this->type = $this->bookingType();
        $this->type->staff()->attach($staff->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function route(string $name, ?BookingType $type = null): string
    {
        return route('public.booking.'.$name, [($type ?? $this->type)->public_booking_uuid]);
    }

    /** What the scheduler's own fetch() sends: an XHR with the default Accept header. */
    private function jget(string $url)
    {
        return $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => '*/*'])->get($url);
    }

    private function slots(string $date, ?string $tz = null, ?BookingType $type = null): array
    {
        $response = $this->jget($this->route('slots', $type).'?date='.$date.($tz ? '&tz='.urlencode($tz) : ''));

        return $response->assertOk()->json('slots');
    }

    private function ajaxBook(array $slot, array $overrides = [], ?BookingType $type = null)
    {
        return $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => '*/*'])
            ->post($this->route('store', $type), array_merge([
                'date' => $slot['date'], 'time' => $slot['time'],
                'first_name' => 'Ada', 'last_name' => 'Lovelace',
                'email' => 'ada@example.test', 'phone' => '+1 (415) 555-1234',
            ], $overrides));
    }

    // ---- the two regressions ------------------------------------------------

    public function test_a_valid_active_booking_type_opens_the_scheduler(): void
    {
        $this->get($this->route('show'))
            ->assertOk()
            ->assertSee('data-role="public-scheduler"', false)
            ->assertSee('Select a date &amp; time', false)
            ->assertSee('Consultation')
            ->assertSee('60 minutes')
            ->assertSee('id="pb-tz"', false);
    }

    public function test_an_active_type_nobody_can_serve_is_refused_and_the_owner_list_says_why(): void
    {
        // The preview regression: active, but no one assigned. The public page
        // answers its one indistinguishable 404 ...
        $unstaffed = $this->bookingType(null, ['name' => 'Unstaffed']);
        $this->get($this->route('show', $unstaffed))->assertNotFound();
        $this->jget($this->route('dates', $unstaffed).'?month=2027-03')->assertNotFound();
        $this->jget($this->route('slots', $unstaffed).'?date=2027-03-01')->assertNotFound();

        // ... so the owner's list must not offer it as a working link.
        $noHours = $this->bookingType(null, ['name' => 'No hours']);
        $noHours->staff()->attach($this->memberWithFullReach(\App\Enums\Workspace\WorkspaceMembershipRole::Staff)->id);

        $this->authenticate($this->owner->user);
        $list = $this->get($this->calendarUrl('booking-types.index', $this->scopeFor($this->locationA)))->assertOk();
        $list->assertSee($this->route('show'), false);
        $list->assertDontSee($this->route('show', $unstaffed), false);
        $list->assertDontSee($this->route('show', $noHours), false);
        $list->assertSee('No one is assigned', false);
        $list->assertSee('No working hours are set', false);
    }

    public function test_blade_comments_never_mention_the_directives_blade_scans_before_comments(): void
    {
        // A comment containing the literal verbatim or php directive makes Blade
        // pair it with a later closer and swallow the real comment terminator, so
        // the whole comment and the script after it print as page text. This is
        // the cause of the Calendar footer leak; no view may reintroduce it.
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));
        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            preg_match_all('/\{\{--(.*?)--\}\}/s', (string) file_get_contents($file->getPathname()), $comments);
            foreach ($comments[1] as $body) {
                if (preg_match('/(?<!@)@(verbatim|endverbatim|php|endphp)\b/', $body)) {
                    $offenders[] = str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $file->getPathname());
                }
            }
        }
        $this->assertSame([], array_values(array_unique($offenders)));
    }

    public function test_the_scheduler_page_renders_without_leaking_source_text(): void
    {
        $html = $this->get($this->route('show'))->assertOk()->getContent();

        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringNotContainsString('@verbatim', $html);
        $this->assertSame(substr_count($html, '<script'), substr_count($html, '</script>'));
        // Everything after the last closing script tag is just the end of the document.
        $tail = trim(substr($html, strrpos($html, '</script>') + 9));
        $this->assertSame('</body>'."\n".'</html>', preg_replace('/\s*\n\s*/', "\n", $tail));
    }

    // ---- authority ----------------------------------------------------------

    public function test_unknown_malformed_and_inactive_types_are_404_on_every_public_route(): void
    {
        $unknown = (string) \Illuminate\Support\Str::uuid();
        foreach (['', '/dates?month=2027-03', '/slots?date=2027-03-01'] as $suffix) {
            $this->get('/book/'.$unknown.$suffix)->assertNotFound();
            $this->get('/book/not-a-uuid'.$suffix)->assertNotFound();
            // The internal uid is not an address.
            $this->get('/book/'.$this->type->uid.$suffix)->assertNotFound();
        }

        $this->type->forceFill(['is_active' => false])->save();
        $this->get($this->route('show'))->assertNotFound();
        $this->jget($this->route('dates').'?month=2027-03')->assertNotFound();
        $this->jget($this->route('slots').'?date=2027-03-01')->assertNotFound();
        $this->post($this->route('store'), [])->assertNotFound();
    }

    public function test_another_business_or_location_cannot_leak_into_this_page_or_its_json(): void
    {
        [$foreignBusiness, $foreignLocation] = $this->foreignBusinessWithLocation();
        $foreignBusiness->forceFill(['name' => 'Foreign Biz Ltd'])->save();
        $foreignType = $this->bookingType($foreignLocation, ['name' => 'Foreign Secret Service']);

        // Nobody is assigned there, so it is not bookable, and nothing about it appears here.
        $this->get($this->route('show', $foreignType))->assertNotFound();
        $page = $this->get($this->route('show'))->assertOk();
        $page->assertDontSee('Foreign Secret Service');
        $page->assertDontSee((string) $foreignBusiness->name);
        $page->assertDontSee('Foreign Location');
        $this->assertSame(['2027-03-01', '2027-03-08', '2027-03-15', '2027-03-22'],
            $this->jget($this->route('dates').'?month=2027-03')->json('available'));
    }

    // ---- date calendar ------------------------------------------------------

    public function test_dates_endpoint_marks_only_days_with_an_offered_time(): void
    {
        $march = $this->jget($this->route('dates').'?month=2027-03&tz=America/New_York')->assertOk();
        $this->assertSame(['2027-03-01', '2027-03-08', '2027-03-15', '2027-03-22'], $march->json('available'));
        $this->assertSame('2027-02-25', $march->json('today'));
        $this->assertSame('2027-03-27', $march->json('to'));
        $this->assertContains('no-store', explode(', ', (string) $march->headers->get('Cache-Control')));

        // Unavailable day: Tuesday has no hours. Out of window: nothing.
        $this->assertNotContains('2027-03-02', $march->json('available'));
        $this->assertSame([], $this->jget($this->route('dates').'?month=2027-02')->json('available'));
        $this->assertSame([], $this->jget($this->route('dates').'?month=2027-05')->json('available'));
        $this->jget($this->route('dates').'?month=2027-13')->assertNotFound();
        $this->jget($this->route('dates').'?month[]=2027-03')->assertNotFound();
    }

    public function test_a_day_with_every_time_taken_stops_being_available(): void
    {
        $this->assertContains('2027-03-08', $this->jget($this->route('dates').'?month=2027-03')->json('available'));

        // Fill Monday 8 March 08:00-20:00 with 60-minute appointments.
        foreach (range(8, 19) as $hour) {
            $this->engine()->book($this->type, $this->staffId, $this->contactId(),
                Carbon::parse(sprintf('2027-03-08 %02d:00:00', $hour), 'America/New_York')->utc());
        }

        $this->assertSame([], $this->slots('2027-03-08'));
        $this->assertNotContains('2027-03-08', $this->jget($this->route('dates').'?month=2027-03')->json('available'));
    }

    // ---- time slots ---------------------------------------------------------

    public function test_slots_follow_the_window_duration_and_existing_appointments(): void
    {
        $labels = array_column($this->slots('2027-03-01'), 'label');
        $this->assertSame('8:00 AM', $labels[0]);
        $this->assertSame('7:00 PM', end($labels));
        $this->assertCount(23, $labels);
        $this->assertSame([], $this->slots('2027-03-02'), 'A day with no working hours offers nothing.');

        // A 10:00-11:00 appointment removes every start that would overlap it.
        $this->engine()->book($this->type, $this->staffId, $this->contactId(), $this->slotStart('10:00:00'));
        $after = array_column($this->slots('2027-03-01'), 'label');
        foreach (['9:30 AM', '10:00 AM', '10:30 AM'] as $gone) {
            $this->assertNotContains($gone, $after);
        }
        foreach (['9:00 AM', '11:00 AM'] as $kept) {
            $this->assertContains($kept, $after);
        }
    }

    public function test_slots_respect_the_type_duration(): void
    {
        $short = $this->bookingType(null, ['name' => 'Quick', 'duration_minutes' => 30]);
        $short->staff()->attach($this->staffId);

        $labels = array_column($this->slots('2027-03-01', null, $short), 'label');
        $this->assertSame('7:30 PM', end($labels), 'A 30-minute type may start up to 19:30 local.');
        $this->assertCount(24, $labels);
    }

    public function test_an_external_calendar_busy_period_removes_its_times(): void
    {
        $connectionId = DB::table('external_calendar_connections')->insertGetId([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $this->staffId, 'provider' => 'google', 'state' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('external_calendar_busy_blocks')->insert([
            'external_calendar_connection_id' => $connectionId, 'provider_event_id' => 'evt-1',
            'start_at' => $this->slotStart('13:00:00'), 'end_at' => $this->slotStart('14:00:00'),
            'synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $labels = array_column($this->slots('2027-03-01'), 'label');
        $this->assertNotContains('1:00 PM', $labels);
        $this->assertNotContains('12:30 PM', $labels);
        $this->assertContains('12:00 PM', $labels);
        $this->assertContains('2:00 PM', $labels);
    }

    public function test_slot_requests_validate_their_input(): void
    {
        foreach (['', 'tomorrow', '2027-02-30', '2027-3-1'] as $bad) {
            $this->jget($this->route('slots').'?date='.urlencode($bad))->assertNotFound();
        }
        $this->jget($this->route('slots').'?date[]=2027-03-01')->assertNotFound();
        $this->assertSame([], $this->slots('2027-04-05'), 'Beyond the 30-day window nothing is offered.');
        $this->assertSame([], $this->slots('2027-02-22'), 'The past offers nothing.');
    }

    // ---- timezone -----------------------------------------------------------

    public function test_slots_are_shown_on_the_visitors_day_and_clock_but_book_the_business_instant(): void
    {
        // 08:00 New York (13:00 UTC) is 22:00 the same evening in Tokyo.
        $evening = $this->slots('2027-03-01', 'Asia/Tokyo');
        $this->assertSame(['10:00 PM', '10:30 PM', '11:00 PM', '11:30 PM'], array_column($evening, 'label'));
        $this->assertSame(['2027-03-01', '08:00'], [$evening[0]['date'], $evening[0]['time']]);
        $this->assertSame('2027-03-01T13:00:00Z', $evening[0]['start']);
        $this->assertSame('11:00 PM', $evening[0]['end_label']);

        // The rest of the New York Monday is Tokyo's Tuesday, 12:00 AM onward.
        $morning = $this->slots('2027-03-02', 'Asia/Tokyo');
        $this->assertSame('12:00 AM', $morning[0]['label']);
        $this->assertSame(['2027-03-01', '10:00'], [$morning[0]['date'], $morning[0]['time']]);
        $this->assertSame('9:00 AM', end($morning)['label']);

        // And the month calendar agrees about which Tokyo days exist.
        $this->assertSame(['2027-03-01', '2027-03-02', '2027-03-08', '2027-03-09', '2027-03-15', '2027-03-16', '2027-03-22', '2027-03-23'],
            $this->jget($this->route('dates').'?month=2027-03&tz=Asia/Tokyo')->json('available'));
    }

    public function test_an_unknown_timezone_falls_back_to_the_business_zone(): void
    {
        foreach (['Mars/Olympus', '../../etc', '', 'EST5EDT; DROP'] as $bad) {
            $response = $this->jget($this->route('slots').'?date=2027-03-01&tz='.urlencode($bad))->assertOk();
            $this->assertSame('America/New_York', $response->json('timezone'));
            $this->assertSame('8:00 AM', $response->json('slots.0.label'));
        }
    }

    public function test_booking_in_a_visitor_timezone_stores_the_business_instant_and_confirms_in_that_zone(): void
    {
        $slot = $this->slots('2027-03-02', 'Asia/Tokyo')[0]; // Tokyo Tue 12:00 AM == NY Mon 10:00 AM

        $response = $this->ajaxBook($slot, ['visitor_timezone' => 'Asia/Tokyo'])->assertCreated();

        $this->assertSame('2027-03-01 15:00:00', Carbon::parse(Appointment::query()->sole()->start_at)->utc()->toDateTimeString());
        $this->assertSame('Tuesday, March 2, 2027', $response->json('booking.date'));
        $this->assertSame('12:00 AM – 1:00 AM', $response->json('booking.time'));
        $this->assertSame('Asia/Tokyo', $response->json('booking.timezone'));
        $this->assertSame('2027-03-01T15:00:00Z', $response->json('booking.start'));
        $this->assertSame('2027-03-01T16:00:00Z', $response->json('booking.end'));
    }

    // ---- booking ------------------------------------------------------------

    public function test_ajax_booking_creates_one_appointment_a_contact_and_a_confirmation(): void
    {
        Event::fake([AppointmentScheduled::class]);
        $slot = $this->slots('2027-03-01')[4]; // 10:00 AM

        $response = $this->ajaxBook($slot)->assertCreated();

        $appointment = Appointment::query()->sole();
        $this->assertSame((int) $this->locationA->id, (int) $appointment->business_location_id);
        $this->assertSame($this->staffId, (int) $appointment->staff_user_id);
        $this->assertSame('2027-03-01 15:00:00', Carbon::parse($appointment->start_at)->utc()->toDateTimeString());
        $this->assertSame('confirmed', $response->json('status'));
        $this->assertSame('Consultation', $response->json('booking.type'));
        $this->assertSame($this->business->name, $response->json('booking.business'));
        $this->assertSame('Monday, March 1, 2027', $response->json('booking.date'));
        $this->assertSame('10:00 AM – 11:00 AM', $response->json('booking.time'));
        $this->assertSame('America/New_York', $response->json('booking.timezone'));
        $this->assertSame(60, $response->json('booking.duration'));

        // The existing automation seam still fires, once, for this appointment.
        Event::assertDispatchedTimes(AppointmentScheduled::class, 1);

        // The email the guest typed is kept on the Contact.
        $stored = DB::table('contacts_custom_field')
            ->join('contact_group_fields', 'contact_group_fields.id', '=', 'contacts_custom_field.field_id')
            ->where('contacts_custom_field.contact_id', $appointment->contact_id)
            ->where('contact_group_fields.tag', 'EMAIL')
            ->value('contacts_custom_field.value');
        $this->assertSame('ada@example.test', $stored);

        // The slot leaves the page and the JSON.
        $this->assertNotContains('10:00 AM', array_column($this->slots('2027-03-01'), 'label'));
    }

    public function test_ajax_validation_failures_are_422_json_and_create_nothing(): void
    {
        $slot = $this->slots('2027-03-01')[4];

        $this->ajaxBook($slot, ['email' => 'not-an-email'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->ajaxBook($slot, ['email' => ''])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->ajaxBook($slot, ['first_name' => ''])->assertStatus(422)->assertJsonValidationErrors('first_name');
        $this->ajaxBook($slot, ['phone' => 'call me'])->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->ajaxBook($slot, ['time' => '10:15'])->assertStatus(422)->assertJsonValidationErrors('time');
        $this->ajaxBook(['date' => '2027-02-22', 'time' => '10:00'])->assertStatus(422)->assertJsonValidationErrors('time');

        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_a_taken_slot_answers_409_and_never_double_books(): void
    {
        $slot = $this->slots('2027-03-01')[4];
        $this->ajaxBook($slot)->assertCreated();

        $second = $this->ajaxBook($slot, ['first_name' => 'Grace', 'last_name' => 'Hopper', 'email' => 'grace@example.test', 'phone' => '14155559999']);
        $second->assertStatus(409);
        $this->assertSame('That time is no longer available. Choose another time.', $second->json('message'));

        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('contacts', 1); // the loser leaves no Contact behind
    }

    public function test_rapid_contention_for_one_slot_creates_exactly_one_appointment(): void
    {
        $slot = $this->slots('2027-03-01')[6]; // 11:00 AM
        $statuses = [];
        foreach (range(1, 6) as $n) {
            $statuses[] = $this->ajaxBook($slot, [
                'first_name' => 'Guest'.$n, 'email' => "guest{$n}@example.test", 'phone' => '1415555'.str_pad((string) (1000 + $n), 4, '0'),
            ])->getStatusCode();
        }

        $this->assertSame([201, 409, 409, 409, 409, 409], $statuses);
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_the_plain_form_post_still_redirects_and_the_confirmation_page_shows_the_booking(): void
    {
        $this->post($this->route('store'), [
            'date' => '2027-03-01', 'time' => '10:00', 'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'email' => 'ada@example.test', 'phone' => '+1 (415) 555-1234',
        ])->assertRedirect($this->route('confirmed'));

        $this->get($this->route('confirmed'))
            ->assertOk()
            ->assertSee('Booking confirmed')
            ->assertSee('Consultation')
            ->assertSee('Monday, March 1, 2027')
            ->assertSee('10:00 AM');
        // Direct visits, without a booking, still just say it is confirmed.
        $this->flushSession();
        $this->get($this->route('confirmed'))->assertOk()->assertSee('Booking confirmed')->assertDontSee('Monday, March 1, 2027');
    }

    public function test_email_is_required_for_a_public_booking(): void
    {
        $this->post($this->route('store'), [
            'date' => '2027-03-01', 'time' => '10:00', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'phone' => '+1 (415) 555-1234',
        ])->assertSessionHasErrors('email');
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_branding_is_the_business_name_and_the_types_own_colour_only(): void
    {
        $this->type->forceFill(['color' => '#1F7A5A'])->save();
        $page = $this->get($this->route('show'))->assertOk();
        $page->assertSee($this->business->name);
        $page->assertSee('--pb-accent: #1F7A5A', false);

        // A colour that is not a plain hex is ignored rather than echoed into the page.
        $this->type->forceFill(['color' => 'red;}</style><script>x()</script>'])->save();
        $this->get($this->route('show'))->assertOk()->assertDontSee('x()', false)->assertDontSee('--pb-accent: red', false);
    }
}
