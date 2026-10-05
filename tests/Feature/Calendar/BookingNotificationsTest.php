<?php

namespace Tests\Feature\Calendar;

use App\Enums\Business\BusinessStatus;
use App\Jobs\Calendar\SendAppointmentNotification;
use App\Library\Calendar\AppointmentBookingService;
use App\Library\Calendar\Notifications\AppointmentNotificationDispatcher;
use App\Library\Messaging\BusinessMessagingIdentityResolver;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\BookingType;
use App\Models\Business;
use App\Models\ContactsCustomField;
use App\Models\Contacts;
use App\Models\Country;
use App\Models\Currency;
use App\Models\CustomerBasedSendingServer;
use App\Models\Senderid;
use App\Models\SendingServer;
use App\Notifications\Calendar\AppointmentCustomerNotification;
use App\Repositories\Contracts\CampaignRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Automations\Workflow\Actions\Support\BuildsActionWorkflows;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\TestCase;

/**
 * Booking Notifications V1 — the Calendar's built-in confirmation and reminders,
 * end to end through the real public booking POST, the ledger, the due sweep and
 * the sender. "Now" is Thursday 2027-02-25 12:00 UTC; the Business is
 * America/New_York with Monday 08:00-20:00 availability, so a 10:00 booking on
 * Monday 2027-03-01 starts 15:00 UTC.
 *
 * The provider boundary is the platform mail transport (Notification::fake) and the
 * canonical CampaignRepository::quickSend (a capturing double), exactly where the
 * Documents and Automations suites draw the same line.
 */
class BookingNotificationsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCalendarHttpFixtures;
    use BuildsActionWorkflows;

    private BookingType $type;

    /** @var list<array<string, mixed>> */
    private array $smsPayloads = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-25 12:00:00 UTC');
        Notification::fake();
        $this->bootCalendarHttpFixtures();
        $this->business->forceFill(['timezone' => 'America/New_York'])->save();
        $this->type = $this->bookingType(null, ['meeting_instructions' => 'Ring the bell at the side door.']);
        $this->type->staff()->attach($this->bookableStaff()->id);
        $this->smsPayloads = [];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function url(?BookingType $type = null): string
    {
        return route('public.booking.show', [($type ?? $this->type)->public_booking_uuid]);
    }

    private function payload(array $over = []): array
    {
        return $over + [
            'date' => '2027-03-01', 'time' => '10:00',
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test', 'phone' => '+1 (415) 555-1234',
        ];
    }

    private function book(array $over = [], ?BookingType $type = null)
    {
        return $this->post($this->url($type), $this->payload($over));
    }

    private function appointment(): Appointment
    {
        return Appointment::query()->latest('id')->firstOrFail();
    }

    private function rows(?string $kind = null, ?string $channel = null)
    {
        return AppointmentNotification::query()
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->when($channel, fn ($q) => $q->where('channel', $channel))
            ->orderBy('scheduled_for')->orderBy('id')->get();
    }

    /** @return list<array{notification: AppointmentCustomerNotification, route: string}> */
    private function emails(): array
    {
        $out = [];
        foreach (Notification::sentNotifications()[AnonymousNotifiable::class] ?? [] as $byId) {
            foreach ($byId[AppointmentCustomerNotification::class] ?? [] as $sent) {
                $out[] = ['notification' => $sent['notification'], 'route' => $sent['notifiable']->routes['mail'] ?? ''];
            }
        }

        return $out;
    }

    /** BYO text path for a Business (the same shape the Automations suite uses), plus a capturing send core. */
    private function textingReady(?Business $business = null): void
    {
        $business ??= $this->business;
        Country::firstOrCreate(['country_code' => '1', 'iso_code' => 'US'], ['name' => 'United States', 'status' => 1]);
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'format' => '$', 'status' => true]);
        $server = SendingServer::create([
            'name' => 'Twilio', 'settings' => SendingServer::TYPE_TWILIO, 'status' => true, 'plain' => true, 'mms' => true,
            'user_id' => $business->customer_id, 'account_sid' => 'AC_TEST', 'auth_token' => 'token_test',
        ]);
        CustomerBasedSendingServer::create([
            'user_id' => $business->customer_id, 'business_id' => $business->id, 'sending_server' => $server->id, 'status' => 1,
        ]);
        Senderid::create([
            'user_id' => $business->customer_id, 'business_id' => $business->id, 'sender_id' => 'BOOKSENDER' . $business->id,
            'status' => Senderid::STATUS_ACTIVE, 'price' => 0, 'billing_cycle' => 'monthly', 'frequency_amount' => 1,
            'frequency_unit' => 'month', 'currency_id' => $currency->id,
        ]);
        $this->captureSms();
    }

    private function captureSms(bool $succeed = true): void
    {
        $mock = \Mockery::mock(CampaignRepository::class);
        $mock->shouldReceive('checkQuickSendValidation')->andReturnUsing(fn (array $input) => response()->json([
            'status' => 'success', 'sender_id' => $input['sender_id'] ?? null, 'sms_type' => $input['sms_type'] ?? 'plain', 'user_id' => $input['user_id'] ?? null,
        ]));
        $mock->shouldReceive('quickSend')->andReturnUsing(function ($campaign, array $input) use ($succeed) {
            $this->smsPayloads[] = $input;

            return response()->json($succeed ? ['status' => 'success', 'message' => 'sent'] : ['status' => 'error', 'message' => 'no']);
        });
        $this->app->instance(CampaignRepository::class, $mock);
    }

    private function smsOn(array $extra = []): void
    {
        $this->type->forceFill(['notify_sms' => true] + $extra)->save();
        $this->type = $this->type->fresh();
    }

    private function sweepAt(string $when): int
    {
        Carbon::setTestNow($when);

        return app(AppointmentNotificationDispatcher::class)->dispatchDue();
    }

    // ================================================================ confirmation

    public function test_a_public_booking_sends_a_confirmation_email_with_the_real_canonical_details(): void
    {
        $this->book()->assertRedirect(route('public.booking.confirmed', [$this->type->public_booking_uuid]));

        $emails = $this->emails();
        $this->assertCount(1, $emails);
        $this->assertSame('ada@example.test', $emails[0]['route']);

        $mail = $emails[0]['notification']->toMail(new AnonymousNotifiable());
        $text = $mail->subject . ' ' . implode(' ', array_map('strval', array_merge($mail->introLines, [$mail->actionText, $mail->actionUrl])));
        $this->assertSame($this->business->name, $mail->from[1], 'The Business is the From display name.');
        foreach ([
            $this->business->name, 'Consultation', 'Monday, March 1, 2027', '10:00 AM – 11:00 AM', 'America/New York (EST)',
            'Location A', 'Ring the bell at the side door.', 'calendar.google.com/calendar/render',
        ] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
        $this->assertStringContainsString('dates=20270301T150000Z/20270301T160000Z', $mail->actionUrl);
        $this->assertCount(1, $mail->rawAttachments);
        $this->assertStringContainsString('BEGIN:VEVENT', $mail->rawAttachments[0]['data']);
        $this->assertStringContainsString('DTSTART:20270301T150000Z', $mail->rawAttachments[0]['data']);
        $this->assertStringNotContainsString('reschedule', strtolower($text . $mail->rawAttachments[0]['data']));
        $this->assertStringNotContainsString('cancel', strtolower($text));

        $row = $this->rows('confirmation', 'email')->sole();
        $this->assertSame('sent', $row->status);
        $this->assertSame('ada@example.test', $row->recipient_email);
        $this->assertNotNull($row->sent_at);
    }

    public function test_the_confirmation_page_says_what_was_actually_arranged(): void
    {
        $this->book();
        $this->get(route('public.booking.confirmed', [$this->type->public_booking_uuid]))
            ->assertSee('confirmation email to ada@example.test');

        $this->type->forceFill(['notify_email' => false])->save();
        $this->book(['time' => '12:00']);
        $this->get(route('public.booking.confirmed', [$this->type->public_booking_uuid]))
            ->assertDontSee('confirmation email');
    }

    public function test_a_confirmation_text_is_sent_through_the_canonical_path_for_the_location_when_ready_and_consented(): void
    {
        $this->textingReady();
        $this->smsOn();

        $this->get($this->url() . '?date=2027-03-01')->assertSee('name="sms_consent"', false)->assertSee('not marketing consent');
        $this->book(['sms_consent' => '1'])->assertRedirect();

        $this->assertCount(1, $this->smsPayloads);
        $payload = $this->smsPayloads[0];
        $this->assertSame((int) $this->business->id, $payload['business_id']);
        $this->assertSame((int) $this->locationA->id, $payload['location_send_context']->locationId);
        $this->assertSame('4155551234', $payload['recipient']);
        $this->assertSame(1, $payload['country_code']);
        $this->assertStringContainsString('Monday, March 1, 2027 at 10:00 AM EST', $payload['message']);
        $this->assertStringContainsString('Where: Location A', $payload['message']);
        $this->assertSame('calendar:appt:' . $this->appointment()->id . ':sms:confirmation', $payload['managed_operation_key']);

        $row = $this->rows('confirmation', 'sms')->sole();
        $this->assertSame('sent', $row->status);
        $this->assertNotNull($row->sms_consent_at, 'The booking-time consent is stored.');
    }

    public function test_no_text_is_sent_without_a_ready_sender_and_the_booking_still_succeeds(): void
    {
        $this->smsOn();

        $this->get($this->url() . '?date=2027-03-01')->assertDontSee('name="sms_consent"', false);
        $this->book(['sms_consent' => '1'])->assertRedirect(route('public.booking.confirmed', [$this->type->public_booking_uuid]));

        $this->assertDatabaseCount('appointments', 1);
        $this->assertSame([], $this->smsPayloads);
        $row = $this->rows('confirmation', 'sms')->sole();
        $this->assertSame('skipped', $row->status);
        $this->assertSame('no_sms_consent', $row->reason, 'Consent for a channel that was not offered never counts.');
        $this->assertCount(1, $this->emails(), 'Email is unaffected.');
    }

    public function test_a_ready_sender_that_fails_at_send_time_is_recorded_not_thrown(): void
    {
        $this->textingReady();
        $this->captureSms(false);
        $this->smsOn();

        $this->book(['sms_consent' => '1'])->assertRedirect();

        $this->assertSame('failed', $this->rows('confirmation', 'sms')->sole()->status);
        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_sms_requires_the_transactional_consent_box_and_never_subscribes_anyone(): void
    {
        $this->textingReady();
        $this->smsOn();

        $this->book()->assertRedirect(); // box not ticked

        $this->assertSame([], $this->smsPayloads);
        $row = $this->rows('confirmation', 'sms')->sole();
        $this->assertSame(['skipped', 'no_sms_consent'], [$row->status, $row->reason]);
        $this->assertNull($row->sms_consent_at);
        $this->assertSame(1, Contacts::query()->count());
    }

    public function test_a_contact_who_opts_out_before_a_reminder_is_not_texted(): void
    {
        $this->textingReady();
        $this->smsOn(['reminder_offsets' => [1440]]);
        $this->book(['sms_consent' => '1']);
        $this->assertCount(1, $this->smsPayloads);

        Contacts::query()->update(['status' => Contacts::STATUS_UNSUBSCRIBE]);
        $this->sweepAt('2027-02-28 15:00:00 UTC');

        $this->assertCount(1, $this->smsPayloads, 'No reminder text after the opt-out.');
        $row = $this->rows('reminder', 'sms')->sole();
        $this->assertSame(['skipped', 'contact_unsubscribed'], [$row->status, $row->reason]);
    }

    public function test_channels_can_be_turned_off_per_booking_type(): void
    {
        $this->type->forceFill(['notify_email' => false])->save();
        $this->book();

        $this->assertSame([], $this->emails());
        $this->assertSame(0, AppointmentNotification::query()->count());
    }

    // ================================================================ reminders

    public function test_the_default_24_hour_and_2_hour_reminders_are_scheduled_in_utc_and_sent_once_each(): void
    {
        $this->book();

        $reminders = $this->rows('reminder', 'email');
        $this->assertSame(
            ['2027-02-28 15:00:00', '2027-03-01 13:00:00'],
            $reminders->map(fn ($r) => $r->scheduled_for->utc()->toDateTimeString())->all(),
        );
        $this->assertSame([1440, 120], $reminders->pluck('offset_minutes')->all());

        $this->assertSame(0, $this->sweepAt('2027-02-28 14:59:00 UTC'));
        $this->assertCount(1, $this->emails(), 'Only the confirmation so far.');

        $this->assertSame(1, $this->sweepAt('2027-02-28 15:00:00 UTC'));
        $this->assertCount(2, $this->emails());
        $this->assertSame(0, $this->sweepAt('2027-02-28 15:00:30 UTC'), 'A re-run sweep queues nothing.');
        $this->assertCount(2, $this->emails());

        $this->assertSame(1, $this->sweepAt('2027-03-01 13:00:00 UTC'));
        $this->assertCount(3, $this->emails());
        $this->assertSame(0, $this->sweepAt('2027-03-01 14:00:00 UTC'));
        $this->assertSame(['sent', 'sent', 'sent'], $this->rows(null, 'email')->pluck('status')->all());

        $reminder = $this->emails()[1]['notification']->toMail(new AnonymousNotifiable());
        $this->assertStringContainsString('Reminder:', $reminder->subject);
    }

    public function test_a_reminder_text_is_sent_for_each_enabled_offset(): void
    {
        $this->textingReady();
        $this->smsOn(['reminder_offsets' => [120]]);
        $this->book(['sms_consent' => '1']);
        $this->assertCount(1, $this->smsPayloads);

        $this->sweepAt('2027-03-01 13:00:00 UTC');

        $this->assertCount(2, $this->smsPayloads);
        $this->assertStringContainsString('reminder', $this->smsPayloads[1]['message']);
        $this->assertSame(
            'calendar:appt:' . $this->appointment()->id . ':sms:reminder:120:202703011500',
            $this->smsPayloads[1]['managed_operation_key'],
        );
    }

    public function test_reminders_whose_moment_has_passed_are_never_scheduled_late(): void
    {
        Carbon::setTestNow('2027-03-01 13:30:00 UTC'); // 08:30 local, appointment at 10:00 local (15:00 UTC)
        $this->book()->assertRedirect();

        $this->assertSame(
            [['skipped', 'past_due'], ['skipped', 'past_due']],
            $this->rows('reminder', 'email')->map(fn ($r) => [$r->status, $r->reason])->all(),
        );
        $this->assertCount(1, $this->emails(), 'The confirmation only.');
        $this->assertSame(0, $this->sweepAt('2027-03-01 14:00:00 UTC'));
    }

    public function test_a_reminder_that_slips_past_the_start_is_not_sent(): void
    {
        $this->book();

        // The sweep was down until after the appointment began.
        $this->sweepAt('2027-03-01 15:30:00 UTC');

        $this->assertCount(1, $this->emails());
        $this->assertSame(['skipped', 'skipped'], $this->rows('reminder', 'email')->pluck('status')->all());
    }

    public function test_zero_reminders_is_a_deliberate_setting_distinct_from_the_defaults(): void
    {
        $this->type->forceFill(['reminder_offsets' => []])->save();
        $this->book();

        $this->assertSame(0, $this->rows('reminder')->count());
        $this->assertCount(1, $this->emails());
    }

    // ================================================================ timezone

    public function test_times_are_stated_in_the_business_timezone_across_a_dst_change(): void
    {
        // 2027-03-14 is the US spring-forward: Monday 2027-03-15 10:00 local is EDT = 14:00 UTC.
        $this->book(['date' => '2027-03-15'])->assertRedirect();

        $this->assertSame('2027-03-15 14:00:00', $this->appointment()->start_at->utc()->toDateTimeString());
        $mail = $this->emails()[0]['notification']->toMail(new AnonymousNotifiable());
        $text = implode(' ', array_map('strval', $mail->introLines));
        $this->assertStringContainsString('Monday, March 15, 2027', $text);
        $this->assertStringContainsString('10:00 AM – 11:00 AM', $text);
        $this->assertStringContainsString('(EDT)', $text);
        $this->assertSame(
            ['2027-03-14 14:00:00', '2027-03-15 12:00:00'],
            $this->rows('reminder', 'email')->map(fn ($r) => $r->scheduled_for->utc()->toDateTimeString())->all(),
            'Offsets are exact elapsed time before the instant.',
        );
    }

    // ================================================================ idempotency

    public function test_a_redelivered_or_retried_job_never_sends_a_second_time(): void
    {
        $this->book();
        $id = $this->rows('confirmation', 'email')->sole()->id;

        SendAppointmentNotification::dispatchSync($id);
        SendAppointmentNotification::dispatchSync($id);
        app(\App\Library\Calendar\Notifications\AppointmentNotificationSender::class)->deliver($id);

        $this->assertCount(1, $this->emails());
    }

    public function test_a_row_already_claimed_by_another_worker_is_not_sent_again(): void
    {
        $this->book();
        $this->sweepAt('2027-02-28 15:00:00 UTC');
        $row = $this->rows('reminder', 'email')->first();
        $this->assertSame('sent', $row->status);

        // A second worker holding a stale job for the same row.
        SendAppointmentNotification::dispatchSync($row->id);

        $this->assertCount(2, $this->emails());
    }

    public function test_a_lost_job_is_re_queued_by_the_sweep_and_still_sends_once(): void
    {
        $this->book();
        $this->sweepAt('2027-02-28 15:00:00 UTC');
        $this->assertCount(2, $this->emails());

        // Pretend a due, never-sent row whose queue job vanished long ago.
        $row = $this->rows('reminder', 'email')->last();
        $row->forceFill(['dispatched_at' => Carbon::parse('2027-02-28 14:00:00 UTC')])->save();
        $this->assertSame(1, $this->sweepAt('2027-03-01 13:20:00 UTC'));
        $this->assertSame(0, $this->sweepAt('2027-03-01 13:21:00 UTC'));

        $this->assertCount(3, $this->emails());
    }

    public function test_replaying_the_booking_post_creates_no_second_appointment_or_notification(): void
    {
        $this->book()->assertRedirect(route('public.booking.confirmed', [$this->type->public_booking_uuid]));
        $replay = $this->book();

        $this->assertNotSame(route('public.booking.confirmed', [$this->type->public_booking_uuid]), $replay->headers->get('Location'));
        $this->assertDatabaseCount('appointments', 1);
        $this->assertSame(1, $this->rows('confirmation', 'email')->count());
        $this->assertCount(1, $this->emails());
    }

    public function test_recording_the_same_booking_twice_is_idempotent(): void
    {
        $this->book();
        $appointment = $this->appointment();
        $before = AppointmentNotification::query()->count();

        $scheduler = app(\App\Library\Calendar\Notifications\AppointmentNotificationScheduler::class);
        $scheduler->recordPublicBooking($appointment, $this->type, 'ada@example.test', '14155551234', false);
        $scheduler->recordPublicBooking($appointment, $this->type, 'other@example.test', '14155551234', false);

        $this->assertSame($before, AppointmentNotification::query()->count());
        $this->assertCount(1, $this->emails());
        $this->assertSame('ada@example.test', $this->rows('confirmation', 'email')->sole()->recipient_email, 'The first snapshot stands.');
    }

    // ================================================================ recipient snapshot

    public function test_the_confirmation_goes_to_the_email_typed_for_this_booking_and_the_contact_email_is_not_overwritten(): void
    {
        $this->book(['email' => 'old@example.test']);
        $contact = Contacts::query()->sole();

        $this->book(['email' => 'new@example.test', 'time' => '12:00']);

        $this->assertSame(1, Contacts::query()->count(), 'No duplicate Contact.');
        $this->assertSame(['old@example.test', 'new@example.test'], array_column($this->emails(), 'route'));
        $stored = ContactsCustomField::query()->where('contact_id', $contact->id)->pluck('value')->all();
        $this->assertContains('old@example.test', $stored);
        $this->assertNotContains('new@example.test', $stored, 'Existing Contact matching rules are untouched.');
    }

    public function test_a_later_contact_edit_does_not_rewrite_who_a_scheduled_reminder_goes_to(): void
    {
        $this->book();
        ContactsCustomField::query()->where('value', 'ada@example.test')->update(['value' => 'someone.else@example.test']);
        Contacts::query()->update(['phone' => '19995550000']);

        $this->sweepAt('2027-02-28 15:00:00 UTC');

        $this->assertSame(['ada@example.test', 'ada@example.test'], array_column($this->emails(), 'route'));
    }

    // ================================================================ cancel / reschedule

    public function test_a_cancelled_appointment_never_gets_a_reminder(): void
    {
        $this->book();
        app(AppointmentBookingService::class)->cancel($this->appointment(), null, 'changed my mind');

        $this->assertSame(
            ['cancelled', 'cancelled'],
            $this->rows('reminder', 'email')->pluck('status')->all(),
        );
        $this->sweepAt('2027-02-28 15:00:00 UTC');
        $this->sweepAt('2027-03-01 13:00:00 UTC');

        $this->assertCount(1, $this->emails(), 'The confirmation only.');
    }

    public function test_cancellation_suppresses_even_when_the_listener_never_ran(): void
    {
        $this->book();
        DB::table('appointments')->where('id', $this->appointment()->id)->update(['status' => 'cancelled']);

        $this->sweepAt('2027-02-28 15:00:00 UTC');

        $this->assertCount(1, $this->emails());
        $this->assertSame(['cancelled', 'pending'], [$this->rows('reminder')->first()->status, $this->rows('reminder')->last()->status]);
    }

    public function test_a_completed_or_no_show_appointment_gets_no_reminder(): void
    {
        $this->book();
        app(AppointmentBookingService::class)->complete($this->appointment());

        $this->sweepAt('2027-02-28 15:00:00 UTC');

        $this->assertCount(1, $this->emails());
    }

    public function test_rescheduling_retires_the_old_reminders_and_schedules_new_ones(): void
    {
        $this->book();
        $appointment = $this->appointment();

        app(AppointmentBookingService::class)->reschedule($appointment, Carbon::parse('2027-03-01 17:00:00 UTC')); // 12:00 local

        $reminders = $this->rows('reminder', 'email');
        $this->assertSame(4, $reminders->count());
        $old = $reminders->where('appointment_start_at', Carbon::parse('2027-03-01 15:00:00 UTC'));
        $new = $reminders->where('appointment_start_at', Carbon::parse('2027-03-01 17:00:00 UTC'));
        $this->assertSame(['cancelled', 'cancelled'], $old->pluck('status')->values()->all());
        $this->assertSame(['rescheduled', 'rescheduled'], $old->pluck('reason')->values()->all());
        $this->assertSame(['pending', 'pending'], $new->pluck('status')->values()->all());
        $this->assertSame(
            ['2027-02-28 17:00:00', '2027-03-01 15:00:00'],
            $new->map(fn ($r) => $r->scheduled_for->utc()->toDateTimeString())->values()->all(),
        );

        // The old 24h moment (15:00 UTC Sunday) is now 2 hours EARLY for the new time: nothing goes out.
        $this->sweepAt('2027-02-28 15:00:00 UTC');
        $this->assertCount(1, $this->emails());

        $this->sweepAt('2027-02-28 17:00:00 UTC');
        $this->sweepAt('2027-03-01 15:00:00 UTC');
        $this->assertCount(3, $this->emails());
        $reminder = $this->emails()[2]['notification']->toMail(new AnonymousNotifiable());
        $this->assertStringContainsString('12:00 PM – 1:00 PM', implode(' ', array_map('strval', $reminder->introLines)));
    }

    public function test_an_obsolete_reminder_is_never_sent_even_if_the_listener_never_ran(): void
    {
        $this->book();
        $appointment = $this->appointment();
        // The move happens, but the ledger is never told (event lost).
        DB::table('appointments')->where('id', $appointment->id)->update([
            'start_at' => '2027-03-01 17:00:00', 'end_at' => '2027-03-01 18:00:00', 'reschedule_count' => 1,
        ]);

        $this->sweepAt('2027-02-28 15:00:00 UTC');

        $this->assertCount(1, $this->emails());
        $this->assertSame('rescheduled', $this->rows('reminder')->first()->reason);
    }

    public function test_rescheduling_back_to_an_earlier_start_makes_its_reminders_owed_again(): void
    {
        $this->book();
        $service = app(AppointmentBookingService::class);
        $service->reschedule($this->appointment(), Carbon::parse('2027-03-01 17:00:00 UTC'));
        $service->reschedule($this->appointment(), Carbon::parse('2027-03-01 15:00:00 UTC'));

        $current = $this->rows('reminder', 'email')->where('appointment_start_at', Carbon::parse('2027-03-01 15:00:00 UTC'));
        $this->assertSame(['pending', 'pending'], $current->pluck('status')->values()->all());
        $this->assertSame(
            ['cancelled', 'cancelled'],
            $this->rows('reminder', 'email')->where('appointment_start_at', Carbon::parse('2027-03-01 17:00:00 UTC'))->pluck('status')->values()->all(),
        );
    }

    public function test_a_sent_reminder_is_rescheduled_for_the_new_time(): void
    {
        $this->book();
        $this->sweepAt('2027-02-28 15:00:00 UTC'); // 24h reminder sent for 15:00 Monday

        // 2027-03-02 is Tuesday: no availability. Use a later Monday.
        app(AppointmentBookingService::class)->reschedule($this->appointment(), Carbon::parse('2027-03-08 15:00:00 UTC'));

        $new = $this->rows('reminder', 'email')->where('appointment_start_at', Carbon::parse('2027-03-08 15:00:00 UTC'));
        $this->assertSame(['pending', 'pending'], $new->pluck('status')->values()->all(), 'The already-sent 24h reminder is owed again for the new time.');
    }

    // ================================================================ isolation & location

    public function test_the_location_aware_sender_rule_is_applied(): void
    {
        // Several Locations; the managed number is assigned to Location B only.
        $identity = $this->giveManagedIdentity($this->business);
        $number = app(BusinessMessagingIdentityResolver::class)->resolvePrimaryNumber($identity);
        app(BusinessMessagingIdentityResolver::class)->assignLocations($number, $this->business, [(int) $this->locationB->id]);
        Country::firstOrCreate(['country_code' => '1', 'iso_code' => 'US'], ['name' => 'United States', 'status' => 1]);
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'format' => '$', 'status' => true]);
        Senderid::create([
            'user_id' => $this->business->customer_id, 'business_id' => $this->business->id, 'sender_id' => 'MANAGED1',
            'status' => Senderid::STATUS_ACTIVE, 'price' => 0, 'billing_cycle' => 'monthly', 'frequency_amount' => 1,
            'frequency_unit' => 'month', 'currency_id' => $currency->id,
        ]);
        $this->captureSms();

        // Location A's type: the number is not shown to be A's -> not offered, not sent.
        $this->smsOn();
        $this->get($this->url() . '?date=2027-03-01')->assertDontSee('name="sms_consent"', false);
        $this->book(['sms_consent' => '1']);
        $this->assertSame([], $this->smsPayloads);
        $this->assertSame('no_sms_consent', $this->rows('confirmation', 'sms')->sole()->reason);

        // The SAME Business's Location B: served.
        $typeB = $this->bookingType($this->locationB, ['notify_sms' => true]);
        $typeB->staff()->attach($this->bookableStaff($this->locationB)->id);
        $this->get($this->url($typeB) . '?date=2027-03-01')->assertSee('name="sms_consent"', false);
        $this->book(['sms_consent' => '1', 'time' => '12:00'], $typeB);
        $this->assertCount(1, $this->smsPayloads);
        $this->assertSame((int) $this->locationB->id, $this->smsPayloads[0]['location_send_context']->locationId);
    }

    public function test_location_sender_unavailable_at_send_time_is_recorded(): void
    {
        // Ready (and consented) at booking time...
        $this->textingReady();
        $this->smsOn(['reminder_offsets' => [1440]]);
        $this->book(['sms_consent' => '1']);
        $this->assertCount(1, $this->smsPayloads);

        // ...then the Business loses its text path before the reminder.
        CustomerBasedSendingServer::query()->update(['status' => 0]);
        $this->sweepAt('2027-02-28 15:00:00 UTC');

        $this->assertCount(1, $this->smsPayloads);
        $row = $this->rows('reminder', 'sms')->sole();
        $this->assertSame(['skipped', 'no_business_sending_path'], [$row->status, $row->reason]);
    }

    public function test_two_businesses_are_isolated(): void
    {
        $this->textingReady(); // Business A can text
        $this->smsOn();

        // Business B: its own tenancy, location, type, staff and booking.
        $other = $this->createCustomer();
        $workspaceB = $this->createWorkspace($other->user);
        $businessB = $this->createBusinessForCustomer($other->user_id, $workspaceB->id);
        DB::table('businesses')->where('id', $businessB->id)->update(['status' => BusinessStatus::Active->value, 'timezone' => 'America/New_York']);
        $locationB = $this->makeLocation($businessB, ['name' => 'B Studio']);
        $admin = \App\Models\User::create([
            'first_name' => 'Platform', 'last_name' => 'Admin', 'email' => 'platformb' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        app(\App\Library\Entitlement\EntitlementManager::class)->assignFirstPlan(
            $workspaceB->fresh(), \App\Enums\Entitlement\WorkspacePlanTier::Core, $admin->id, 'Fixture assignment.', true, 0
        );
        $workspaceA = $this->workspace;
        $this->workspace = $workspaceB; // the fixture builds members in $this->workspace
        $staffB = $this->memberWithFullReach(\App\Enums\Workspace\WorkspaceMembershipRole::Staff);
        $this->workspace = $workspaceA;
        $this->giveMondayAvailability((int) $staffB->id, $locationB);
        $typeB = $this->bookingType($locationB, ['name' => 'B Session', 'notify_sms' => true]);
        $typeB->staff()->attach($staffB->id);

        $this->book(['sms_consent' => '1']);
        $this->post($this->url($typeB), $this->payload(['email' => 'bee@example.test']) + ['sms_consent' => '1'])->assertRedirect();

        $this->assertSame(2, Appointment::query()->count());
        $this->assertSame(2, Contacts::query()->count(), 'Same phone, different Business: two Contacts.');
        $this->assertSame([(int) $this->business->id], $this->rows(null, 'sms')->where('status', 'sent')->pluck('business_id')->unique()->values()->all(), 'Only A had a sender.');
        $this->assertSame(
            ['skipped'],
            AppointmentNotification::query()->where('business_id', $businessB->id)->where('channel', 'sms')->where('kind', 'confirmation')->pluck('status')->all(),
        );
        $this->assertCount(1, $this->smsPayloads);
        $this->assertSame((int) $this->business->id, $this->smsPayloads[0]['business_id']);
        $this->assertSame(
            [(int) $this->business->id => 'ada@example.test', (int) $businessB->id => 'bee@example.test'],
            AppointmentNotification::query()->where('kind', 'confirmation')->where('channel', 'email')->pluck('recipient_email', 'business_id')->all(),
        );
    }

    public function test_a_business_that_is_no_longer_active_sends_nothing_at_send_time(): void
    {
        $this->book();
        DB::table('businesses')->where('id', $this->business->id)->update(['status' => BusinessStatus::Inactive->value]);

        $this->sweepAt('2027-02-28 15:00:00 UTC');

        $this->assertCount(1, $this->emails());
        $this->assertSame('business_inactive', $this->rows('reminder')->first()->reason);
    }

    public function test_one_booking_makes_one_contact_and_one_appointment(): void
    {
        $this->textingReady();
        $this->smsOn();
        $this->book(['sms_consent' => '1']);
        $this->sweepAt('2027-02-28 15:00:00 UTC');
        $this->sweepAt('2027-03-01 13:00:00 UTC');

        $this->assertSame(1, Contacts::query()->count());
        $this->assertSame(1, Appointment::query()->count());
    }

    // ================================================================ command & scheduler

    public function test_the_sweep_command_runs_and_validates_its_limit(): void
    {
        $this->book();
        Carbon::setTestNow('2027-02-28 15:00:00 UTC');

        $this->artisan('calendar:dispatch-due-reminders')->expectsOutputToContain('Queued 1 appointment notification')->assertExitCode(0);
        $this->artisan('calendar:dispatch-due-reminders', ['--limit' => '0'])->assertExitCode(2);
        $this->assertCount(2, $this->emails());

        $scheduled = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->contains(fn ($e) => str_contains((string) $e->command, 'calendar:dispatch-due-reminders') && $e->expression === '* * * * *');
        $this->assertTrue($scheduled);
    }

    // ================================================================ settings UI

    public function test_the_editor_shows_and_saves_customer_notification_settings(): void
    {
        $this->authenticate($this->owner->user);
        $editUrl = $this->calendarUrl('booking-types.edit', [...$this->scopeFor($this->locationA), $this->type->uid]);
        $updateUrl = $this->calendarUrl('booking-types.update', [...$this->scopeFor($this->locationA), $this->type->uid]);

        $this->get($editUrl)->assertOk()
            ->assertSee('Customer notifications')->assertSee('Send by email')->assertSee('Send by text message')
            ->assertSee('24 hours before')->assertSee('2 hours before')->assertSee('Add reminder')
            ->assertSee('Text messages are not set up yet');

        $base = ['name' => 'Consultation', 'duration_minutes' => 60];
        $this->post($updateUrl, $base + [
            'notify_email' => '0', 'notify_sms' => '1', 'reminders_submitted' => '1', 'reminder_offsets' => ['60', '4320', '60', '999'],
        ])->assertSessionHasErrors('reminder_offsets.3'); // 999 is not an offered option

        $this->post($updateUrl, $base + [
            'notify_email' => '0', 'notify_sms' => '1', 'reminders_submitted' => '1', 'reminder_offsets' => ['60', '4320', '60'],
        ])->assertRedirect();
        $fresh = $this->type->fresh();
        $this->assertFalse($fresh->notifiesByEmail());
        $this->assertTrue($fresh->notifiesBySms());
        $this->assertSame([4320, 60], $fresh->reminderOffsetMinutes());

        $this->get($editUrl)->assertSee('Text messages are not ready')->assertSee('Bookings still go through');

        // Removing every row means "no reminders", not "unchanged".
        $this->post($updateUrl, $base + ['reminders_submitted' => '1'])->assertRedirect();
        $this->assertSame([], $this->type->fresh()->reminderOffsetMinutes());
        $this->assertSame([], $this->type->fresh()->reminder_offsets);

        // A caller that predates these settings leaves them alone.
        $this->type->forceFill(['reminder_offsets' => [120], 'notify_sms' => false])->save();
        $this->post($updateUrl, $base)->assertRedirect();
        $this->assertSame([120], $this->type->fresh()->reminderOffsetMinutes());
        $this->assertFalse($this->type->fresh()->notifiesBySms());
    }

    public function test_a_new_type_defaults_to_email_with_24_and_2_hour_reminders_and_no_texting(): void
    {
        $fresh = $this->bookingType(null, ['name' => 'Fresh'])->fresh();

        $this->assertTrue($fresh->notifiesByEmail());
        $this->assertFalse($fresh->notifiesBySms());
        $this->assertSame([1440, 120], $fresh->reminderOffsetMinutes());
        $this->assertNull($fresh->reminder_offsets);
    }

    public function test_the_editor_reports_a_ready_text_channel_without_warnings(): void
    {
        $this->textingReady();
        $this->smsOn();
        $this->authenticate($this->owner->user);

        $this->get($this->calendarUrl('booking-types.edit', [...$this->scopeFor($this->locationA), $this->type->uid]))
            ->assertOk()->assertDontSee('Text messages are not ready');
    }
}
