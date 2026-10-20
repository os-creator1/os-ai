<?php

namespace Tests\Feature\Calendar;

use App\Enums\Calendar\AppointmentStatus;
use App\Library\Calendar\DemoBooking\DemoBookingManageLinks;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\Contacts;
use App\Models\ContactsCustomField;
use App\Notifications\Calendar\AppointmentCustomerNotification;
use App\Library\Calendar\Notifications\AppointmentNotificationDetails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\Feature\GoogleAds\Attribution\Concerns\BuildsAttributionCookies;
use Tests\TestCase;

/**
 * /book-demo: the public booking engine pointed at ONE designated internal
 * Booking Type. These tests pin what is new (the form, the Contact resolver,
 * the anti-abuse layer, the guest links) and re-prove, through the demo
 * routes, the inherited protections the issue names.
 */
class DemoBookingTest extends TestCase
{
    use BuildsAttributionCookies;
    use CreatesCalendarHttpFixtures;
    use RefreshDatabase;

    private $type;

    /** What the faked Cloudflare siteverify answers; swap per test. */
    private $verdict;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-25 12:00:00 UTC');
        $this->bootCalendarHttpFixtures();
        $this->type = $this->bookingType(null, ['name' => 'MotionGrove Demo', 'notify_email' => true]);
        $this->type->staff()->attach($this->bookableStaff()->id);

        config([
            'demo_booking.booking_type_uuid' => $this->type->public_booking_uuid,
            'demo_booking.turnstile.site_key' => 'site-key-public',
            'demo_booking.turnstile.secret_key' => 'secret-key-private',
            'demo_booking.turnstile.bypass' => false,
        ]);
        $this->verdict = fn () => Http::response(['success' => true, 'hostname' => 'localhost']);
        Http::fake(['challenges.cloudflare.com/*' => fn () => ($this->verdict)()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(array $override = []): array
    {
        return $override + [
            'date' => '2027-03-01', 'time' => '10:00',
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test',
            'business_name' => 'Analytical Engines Ltd', 'website' => 'analytical.example.test',
            'phone' => '+1 (415) 555-1234', 'cf-turnstile-response' => 'token-1',
        ];
    }

    private function book(array $override = [], array $cookies = [])
    {
        return $this->withCookies($cookies)->post(route('demo.booking.store'), $this->payload($override));
    }

    private function field(Contacts $contact, string $tag): ?string
    {
        $fieldId = $contact->contactGroup->getFieldByTag($tag)?->id;

        return ContactsCustomField::query()->where('contact_id', $contact->id)->where('field_id', $fieldId)->value('value');
    }

    // ---- fail closed ------------------------------------------------------------------------

    public function test_every_route_refuses_without_a_designated_booking_type(): void
    {
        config(['demo_booking.booking_type_uuid' => null]);
        $this->get('/book-demo')->assertNotFound();
        $this->get('/book-demo/dates?month=2027-03')->assertNotFound();
        $this->get('/book-demo/slots?date=2027-03-01')->assertNotFound();
        $this->get('/book-demo/confirmed')->assertNotFound();
        $this->book()->assertNotFound();
        $this->assertDatabaseCount('appointments', 0);

        config(['demo_booking.booking_type_uuid' => 'not-a-uuid']);
        $this->get('/book-demo')->assertNotFound();
    }

    public function test_incomplete_turnstile_configuration_fails_closed_and_the_bypass_is_local_only(): void
    {
        config(['demo_booking.turnstile.secret_key' => null]);
        $this->get('/book-demo')->assertNotFound();
        $this->book()->assertNotFound();

        // The bypass is honoured only in local/testing. Production-like environments ignore it.
        config(['demo_booking.turnstile.bypass' => true]);
        $this->app['env'] = 'production';
        $this->get('/book-demo')->assertNotFound();
        $this->withSession(['_token' => 'csrf'])->post(route('demo.booking.store'), $this->payload() + ['_token' => 'csrf'])->assertNotFound();
        $this->assertDatabaseCount('appointments', 0);
        Http::assertNothingSent();

        $this->app['env'] = 'testing';
        $this->get('/book-demo?date=2027-03-01')->assertOk();
    }

    public function test_demo_routes_do_not_open_any_other_booking_type(): void
    {
        $other = $this->bookingType($this->locationB, ['name' => 'Customer service']);
        $other->staff()->attach($this->bookableStaff($this->locationB)->id);

        $this->get('/book-demo')->assertOk()->assertSee('Let’s talk about your business.')->assertDontSee('Customer service');
        $this->assertSame('book-demo', Route::getRoutes()->getByName('demo.booking.store')->uri());
        $this->post('/book-demo', $this->payload(['booking_type_uuid' => $other->public_booking_uuid, 'booking_type_id' => $other->id]))
            ->assertRedirect();
        $this->assertSame((int) $this->type->id, (int) Appointment::query()->sole()->booking_type_id);
    }

    // ---- the page --------------------------------------------------------------------------

    public function test_the_page_collects_the_demo_fields_and_exposes_only_the_site_key(): void
    {
        $html = $this->get('/book-demo')->assertOk()
            ->assertSee('Let’s talk about your business.')
            ->assertSee('name="first_name"', false)->assertSee('name="business_name"', false)->assertSee('name="industry"', false)
            ->assertSee('name="help_with[]"', false)->assertSee('name="message"', false)
            ->assertSee('Continue to choose a time')
            ->assertSee('name="demo_hp"', false)->assertSee('data-sitekey="site-key-public"', false)
            ->assertSee('challenges.cloudflare.com/turnstile/v0/api.js', false)
            ->assertSee('data-role="public-scheduler"', false)
            ->getContent();

        $this->assertStringNotContainsString('secret-key-private', $html);
        $this->assertStringContainsString(route('demo.booking.store'), $html);
        $this->assertStringContainsString('book-demo\/slots', $html);
        $this->assertDoesNotMatchRegularExpression('/name="phone"[^>]*required/', $html);
        $this->assertStringNotContainsString('sms-consent', $html);
        // The design's placeholders never reach the public page.
        $this->assertStringNotContainsString('[Scheduling calendar]', $html);
        $this->assertStringNotContainsString('[Confirm demo length]', $html);
        $this->assertStringContainsString($this->type->duration_minutes.' minutes', $html);
    }

    // ---- booking, calendar, CRM ------------------------------------------------------------

    public function test_guest_books_into_the_internal_calendar_and_the_internal_crm_only(): void
    {
        $this->book()->assertRedirect(route('demo.booking.confirmed'));
        $this->get(route('demo.booking.confirmed'))->assertOk()->assertSee('Booking confirmed');

        $appointment = Appointment::query()->sole();
        $this->assertSame((int) $this->locationA->id, (int) $appointment->business_location_id);
        $this->assertNull($appointment->created_by_user_id);
        // 10:00 on 1 March is EST (UTC-5): the Business timezone decides, not the server.
        $this->assertSame('2027-03-01 15:00:00', Carbon::parse($appointment->start_at)->utc()->toDateTimeString());

        $contact = Contacts::query()->sole();
        $this->assertSame((int) $this->business->id, (int) $contact->business_id);
        $this->assertSame((int) $this->locationA->id, (int) $contact->location_id);
        $this->assertSame((int) $contact->id, (int) $appointment->contact_id);
        $this->assertSame('ada@example.test', $this->field($contact, 'EMAIL'));
        $this->assertSame('Analytical Engines Ltd', $this->field($contact, 'BUSINESS_NAME'));
        $this->assertSame('https://analytical.example.test', $this->field($contact, 'WEBSITE'));
        $this->assertSame('Ada', $this->field($contact, 'FIRST_NAME'));

        $this->authenticate($this->owner->user);
        $this->get($this->calendarUrl('schedule', $this->scopeFor($this->locationA)).'?date=2027-03-01')
            ->assertOk()->assertSee($appointment->uid);
    }

    public function test_the_phone_is_optional_and_identity_then_falls_back_to_the_email(): void
    {
        $this->book(['phone' => ''])->assertRedirect(route('demo.booking.confirmed'));
        $this->book(['phone' => '', 'time' => '11:00', 'business_name' => 'Renamed Ltd', 'website' => ''])->assertRedirect();
        $this->book(['phone' => '', 'time' => '12:00', 'email' => 'grace@example.test'])->assertRedirect();

        $this->assertDatabaseCount('appointments', 3);
        $this->assertSame(2, Contacts::query()->count());
        $ada = Contacts::query()->orderBy('id')->first();
        $this->assertSame('', (string) $ada->getRawOriginal('phone'));
        // Additive only: an anonymous resubmission never overwrites what the Business already holds.
        $this->assertSame('Analytical Engines Ltd', $this->field($ada, 'BUSINESS_NAME'));
        $this->assertSame(2, Appointment::query()->where('contact_id', $ada->id)->count());
    }

    public function test_a_phone_number_reuses_the_engines_own_location_local_identity(): void
    {
        $this->book()->assertRedirect();
        $this->book(['time' => '11:00', 'phone' => '14155551234'])->assertRedirect();
        $this->assertSame(1, Contacts::query()->count());
    }

    public function test_validation_runs_server_side_before_any_verification_is_spent(): void
    {
        foreach ([['first_name' => ''], ['email' => 'nope'], ['business_name' => ''], ['website' => 'not a site'], ['website' => 'javascript:alert(1)'], ['time' => '10:15'], ['phone' => 'abc']] as $bad) {
            $this->book($bad)->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('appointments', 0);
        Http::assertNothingSent();
    }

    // ---- anti-abuse ------------------------------------------------------------------------

    public function test_the_turnstile_token_is_verified_server_side_with_the_secret(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->book()->assertRedirect(route('demo.booking.confirmed'));

        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
            && $request['secret'] === 'secret-key-private'
            && $request['response'] === 'token-1'
            && $request['remoteip'] === '203.0.113.9');
    }

    public function test_a_failed_missing_or_unreachable_turnstile_check_books_nothing(): void
    {
        $this->verdict = fn () => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']]);
        $this->book()->assertSessionHasErrors('turnstile');

        $this->book(['cf-turnstile-response' => ''])->assertSessionHasErrors('turnstile');

        $this->verdict = fn () => Http::response('boom', 500);
        $this->book()->assertSessionHasErrors('turnstile');

        $this->verdict = fn () => throw new \Illuminate\Http\Client\ConnectionException('down');
        $this->book()->assertSessionHasErrors('turnstile');

        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('contacts', 0);

        $json = $this->postJson(route('demo.booking.store'), $this->payload(), ['X-Requested-With' => 'XMLHttpRequest']);
        $json->assertStatus(422)->assertJsonPath('errors.turnstile.0', fn ($m) => is_string($m));
    }

    public function test_the_honeypot_silently_discards_a_bot_submission_before_verification(): void
    {
        $this->book(['demo_hp' => 'http://spam.example'])->assertRedirect(route('demo.booking.confirmed'));
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('contacts', 0);
        Http::assertNothingSent();
    }

    public function test_the_booking_write_is_throttled(): void
    {
        $this->assertContains('throttle:10,1', Route::getRoutes()->getByName('demo.booking.store')->gatherMiddleware());
        $this->assertContains('throttle:60,1', Route::getRoutes()->getByName('demo.booking.slots')->gatherMiddleware());

        for ($i = 0; $i < 10; $i++) {
            $this->book(['first_name' => ''])->assertSessionHasErrors();
        }
        $this->book(['first_name' => ''])->assertStatus(429);
    }

    public function test_a_token_issued_for_another_hostname_is_refused(): void
    {
        $this->verdict = fn () => Http::response(['success' => true, 'hostname' => 'evil.example']);
        $this->book()->assertSessionHasErrors('turnstile');

        $this->verdict = fn () => Http::response(['success' => true]); // no hostname reported at all
        $this->book()->assertSessionHasErrors('turnstile');
        $this->assertDatabaseCount('appointments', 0);

        config(['demo_booking.turnstile.allowed_hostnames' => 'app.example, Localhost']);
        $this->verdict = fn () => Http::response(['success' => true, 'hostname' => 'localhost']);
        $this->book()->assertRedirect();
    }

    public function test_a_phoneless_booking_matches_an_earlier_contact_with_the_same_email_and_raises_the_new_contact_automations(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $this->book(['time' => '10:00'])->assertRedirect(); // with a phone
        $this->book(['time' => '11:00', 'phone' => '', 'email' => 'ADA@example.test'])->assertRedirect();
        $this->assertSame(1, Contacts::query()->count());
        $this->assertSame(2, Appointment::query()->where('contact_id', Contacts::query()->value('id'))->count());

        \Illuminate\Support\Facades\Queue::fake(); // reset, then a brand-new phone-less lead
        $this->book(['time' => '12:00', 'phone' => '', 'email' => 'grace@example.test'])->assertRedirect();
        $this->assertSame(2, Contacts::query()->count());
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\AutomationJob::class);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\Automation\Workflow\EnrollWorkflowContact::class);
    }

    public function test_the_confirmation_page_and_guest_links_do_not_depend_on_the_bot_check_configuration(): void
    {
        $appointment = $this->bookedAppointmentForLinks();
        config(['demo_booking.turnstile.secret_key' => null]);
        $this->get(route('demo.booking.confirmed'))->assertOk();
        $this->get(DemoBookingManageLinks::urlFor($appointment))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    private function bookedAppointmentForLinks(): Appointment
    {
        $this->book()->assertRedirect();

        return Appointment::query()->latest('id')->firstOrFail();
    }

    // ---- inherited protections, proven through the demo routes -----------------------------

    public function test_a_taken_slot_is_refused_and_leaves_one_appointment(): void
    {
        $this->book()->assertRedirect();
        $json = $this->post(route('demo.booking.store'), $this->payload(['email' => 'other@example.test']), ['X-Requested-With' => 'XMLHttpRequest']);
        $json->assertStatus(409);
        $this->assertDatabaseCount('appointments', 1);
        $this->assertSame(1, Contacts::query()->count());
    }

    public function test_buffers_and_minimum_notice_come_from_the_booking_type(): void
    {
        $this->type->update(['buffer_after_minutes' => 60, 'minimum_notice_minutes' => 60 * 24 * 30]);
        $this->book()->assertSessionHasErrors('time');
        $this->type->update(['minimum_notice_minutes' => 0]);
        $this->book()->assertRedirect();

        $slots = $this->getJson('/book-demo/slots?date=2027-03-01&tz=America/New_York')->assertOk()->json('slots');
        $times = array_column($slots, 'time');
        $this->assertNotContains('10:00', $times);
        $this->assertNotContains('11:00', $times, 'the 60 minute buffer after the booking blocks the next hour');
        $this->assertContains('12:00', $times);
    }

    public function test_slots_follow_the_business_timezone_across_dst_and_the_visitors_zone(): void
    {
        // US DST starts Sunday 2027-03-14: Monday the 15th is EDT (UTC-4), 1 March was EST (UTC-5).
        $this->book(['date' => '2027-03-15', 'time' => '10:00'])->assertRedirect();
        $this->assertSame('2027-03-15 14:00:00', Carbon::parse(Appointment::query()->sole()->start_at)->utc()->toDateTimeString());

        $tokyo = $this->getJson('/book-demo/slots?date=2027-03-02&tz=Asia/Tokyo')->assertOk()->json('slots');
        $first = collect($tokyo)->firstWhere('time', '10:00');
        $this->assertSame('2027-03-01', $first['date']);
        $this->assertSame('12:00 AM', $first['label']);

        $this->getJson('/book-demo/dates?month=2027-03&tz=Europe/London')->assertOk()
            ->assertJsonPath('timezone', 'Europe/London');
    }

    // ---- confirmation, reminders, attribution ----------------------------------------------

    public function test_confirmation_and_reminders_are_recorded_for_the_guest_email(): void
    {
        $this->book(['phone' => ''])->assertRedirect();

        $rows = AppointmentNotification::query()->get();
        $this->assertNotEmpty($rows);
        $this->assertSame(['ada@example.test'], $rows->pluck('recipient_email')->unique()->values()->all());
        $this->assertTrue($rows->contains('kind', AppointmentNotification::KIND_CONFIRMATION));
        $this->assertTrue($rows->contains(fn ($r) => $r->kind !== AppointmentNotification::KIND_CONFIRMATION), 'at least one reminder is scheduled');
        $this->assertSame([(int) $this->business->id], $rows->pluck('business_id')->map(fn ($id) => (int) $id)->unique()->values()->all());
        $this->assertNull($rows->first()->recipient_phone);
    }

    public function test_attribution_is_recorded_against_the_internal_business(): void
    {
        $this->book([], $this->touchCookies(['gclid' => self::CLICK], ['utm_source' => 'google']))->assertRedirect();

        $appointment = Appointment::query()->sole();
        $rows = $this->touchRows();
        $this->assertSame(['first', 'last'], array_map(fn ($r) => $r->touch_role, $rows));
        foreach ($rows as $row) {
            $this->assertSame('booking', $row->entry_surface);
            $this->assertSame((int) $this->business->id, (int) $row->business_id);
            $this->assertSame((int) $appointment->contact_id, (int) $row->contact_id);
        }
        $this->assertNotNull($this->get('/book-demo?gclid='.self::CLICK)->getCookie('bos_at_last'));
    }

    // ---- tenant isolation ------------------------------------------------------------------

    public function test_nothing_is_ever_written_to_another_tenant(): void
    {
        [$foreign, $foreignLocation] = $this->foreignBusinessWithLocation();
        $foreignType = $this->bookingType($foreignLocation, ['name' => 'Foreign']);
        $foreignType->staff()->attach($this->bookableStaff($foreignLocation)->id);

        $this->book()->assertRedirect();

        $this->assertSame(0, Contacts::query()->where('business_id', $foreign->id)->count());
        $this->assertSame(0, Appointment::query()->where('business_location_id', $foreignLocation->id)->count());
        $this->assertSame(0, AppointmentNotification::query()->where('business_id', $foreign->id)->count());
        $this->assertSame(1, Contacts::query()->where('business_id', $this->business->id)->count());

        // A customer cannot be handed platform-owner authority by this surface: it adds no auth, no role and no admin route.
        $this->assertFalse(collect(Route::getRoutes()->getRoutes())->contains(
            fn ($route) => str_starts_with($route->uri(), 'book-demo') && in_array('auth', $route->gatherMiddleware(), true)
        ));
    }

    // ---- guest cancel / reschedule ---------------------------------------------------------

    private function bookedAppointment(string $time = '10:00'): Appointment
    {
        $this->book(['time' => $time])->assertRedirect();

        return Appointment::query()->latest('id')->firstOrFail();
    }

    public function test_links_exist_only_for_the_designated_type_and_the_email_carries_one(): void
    {
        $appointment = $this->bookedAppointment();
        $url = DemoBookingManageLinks::urlFor($appointment);
        $this->assertNotNull($url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);

        $mail = (new AppointmentCustomerNotification(AppointmentNotificationDetails::for($appointment), false))->toMail(new \stdClass());
        $this->assertStringContainsString(e($url), implode(' ', array_map('strval', $mail->outroLines)));

        $other = $this->bookingType($this->locationB, ['name' => 'Other']);
        $foreignAppointment = $appointment->replicate();
        $foreignAppointment->booking_type_id = $other->id;
        $foreignAppointment->setRelation('bookingType', $other);
        $this->assertNull(DemoBookingManageLinks::urlFor($foreignAppointment));
    }

    public function test_the_manage_page_requires_a_valid_unexpired_signature(): void
    {
        $appointment = $this->bookedAppointment();
        $url = DemoBookingManageLinks::urlFor($appointment);

        $this->get($url)->assertOk()->assertSee('Your appointment');
        $this->get($url.'&date=2027-03-08')->assertOk()->assertSee('10:00');
        $this->get(str_replace('signature=', 'signature=0', $url))->assertNotFound();
        $this->get(preg_replace('/rc=\d+/', 'rc=7', $url))->assertNotFound();
        $this->get(route('demo.booking.manage', [$appointment->uid]))->assertNotFound();

        Carbon::setTestNow('2027-03-02 00:00:00 UTC'); // after the appointment: the link has expired with it
        $this->get($url)->assertNotFound();
    }

    public function test_cancelling_works_once_and_the_link_cannot_be_replayed(): void
    {
        $appointment = $this->bookedAppointment();
        $url = DemoBookingManageLinks::urlFor($appointment);

        $this->post($url, ['action' => 'cancel'])->assertOk()->assertSee('Appointment cancelled');
        $this->assertSame(AppointmentStatus::Cancelled, $appointment->fresh()->status);
        $this->assertSame('Cancelled by guest', $appointment->fresh()->cancellation_reason);

        $this->post($url, ['action' => 'cancel'])->assertNotFound();
        $this->get($url)->assertNotFound();
        // The slot is free again for the next visitor.
        $this->book(['email' => 'next@example.test'])->assertRedirect();
    }

    public function test_rescheduling_moves_the_appointment_retires_the_old_link_and_rechecks_availability(): void
    {
        $appointment = $this->bookedAppointment();
        $blocker = $this->bookedAppointment('13:00');
        $url = DemoBookingManageLinks::urlFor($appointment);

        $this->post($url, ['action' => 'reschedule', 'date' => '2027-03-01', 'time' => '13:00'])->assertSessionHasErrors('time');
        $this->post($url, ['action' => 'reschedule', 'date' => '2027-03-01', 'time' => '10:15'])->assertSessionHasErrors('time');
        $this->assertSame('2027-03-01 15:00:00', Carbon::parse($appointment->fresh()->start_at)->utc()->toDateTimeString());

        $this->post($url, ['action' => 'reschedule', 'date' => '2027-03-01', 'time' => '15:00'])->assertRedirect(route('demo.booking.confirmed'));
        $moved = $appointment->fresh();
        $this->assertSame('2027-03-01 20:00:00', Carbon::parse($moved->start_at)->utc()->toDateTimeString());
        $this->assertSame(1, (int) $moved->reschedule_count);
        $this->assertSame(AppointmentStatus::Scheduled, $moved->status);

        $this->get($url)->assertNotFound();
        $this->post($url, ['action' => 'cancel'])->assertNotFound();
        $fresh = DemoBookingManageLinks::urlFor($moved);
        $this->assertNotSame($url, $fresh);
        $this->get($fresh)->assertOk();
        $this->assertSame(AppointmentStatus::Scheduled, $blocker->fresh()->status);
    }

    public function test_a_link_for_another_tenants_appointment_never_resolves(): void
    {
        [, $foreignLocation] = $this->foreignBusinessWithLocation();
        $foreignType = $this->bookingType($foreignLocation, ['name' => 'Foreign']);
        $foreignType->staff()->attach($this->bookableStaff($foreignLocation)->id);
        $contactId = $this->contactId();
        $foreignAppointment = Appointment::create([
            'business_location_id' => $foreignLocation->id, 'booking_type_id' => $foreignType->id,
            'staff_user_id' => $foreignType->staff()->first()->id, 'contact_id' => $contactId,
            'status' => AppointmentStatus::Scheduled, 'start_at' => Carbon::parse('2027-03-08 15:00:00', 'UTC'),
            'end_at' => Carbon::parse('2027-03-08 16:00:00', 'UTC'), 'reschedule_count' => 0,
        ]);

        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute('demo.booking.manage', now()->addDay(), ['appointmentUid' => $foreignAppointment->uid, 'rc' => 0]);
        $this->get($url)->assertNotFound();
        $this->post($url, ['action' => 'cancel'])->assertNotFound();
        $this->assertSame(AppointmentStatus::Scheduled, $foreignAppointment->fresh()->status);
    }

    public function test_a_guest_cancel_after_staff_already_resolved_the_appointment_is_a_clean_404(): void
    {
        $appointment = $this->bookedAppointment();
        $url = DemoBookingManageLinks::urlFor($appointment);
        $appointment->forceFill(['status' => AppointmentStatus::Completed])->save();

        $this->post($url, ['action' => 'cancel'])->assertNotFound();
        $this->assertSame(AppointmentStatus::Completed, $appointment->fresh()->status);
    }
}
