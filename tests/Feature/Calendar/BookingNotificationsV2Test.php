<?php

namespace Tests\Feature\Calendar;

use App\Library\Calendar\AppointmentBookingService;
use App\Library\Calendar\Notifications\AppointmentNotificationContent;
use App\Library\Calendar\Notifications\AppointmentNotificationDispatcher;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\BookingType;
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
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Automations\Workflow\Actions\Support\BuildsActionWorkflows;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\TestCase;

/**
 * Calendar Notifications V2: any number of reminders, with free timing, per-step
 * channels and editable wording -- on top of the unchanged consent, readiness,
 * idempotency and reschedule/cancel safety. "Now" is Thursday 2027-02-25 12:00 UTC;
 * the 10:00 America/New_York booking on Monday 2027-03-01 starts at 15:00 UTC.
 */
class BookingNotificationsV2Test extends TestCase
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
        $this->type = $this->bookingType(null, ['meeting_instructions' => 'Ring the bell.']);
        $this->type->staff()->attach($this->bookableStaff()->id);
        $this->smsPayloads = [];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function step(int $minutes, array $over = []): array
    {
        return $over + [
            'id' => 's' . $minutes, 'offset_minutes' => $minutes, 'email' => true, 'sms' => false, 'enabled' => true,
            'email_subject' => null, 'email_body' => null, 'sms_body' => null,
        ];
    }

    private function configure(array $steps, array $extra = []): void
    {
        $this->type->forceFill(['reminder_steps' => $steps] + $extra)->save();
        $this->type = $this->type->fresh();
    }

    private function book(array $over = [])
    {
        return $this->post(route('public.booking.show', [$this->type->public_booking_uuid]), $over + [
            'date' => '2027-03-01', 'time' => '10:00',
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test', 'phone' => '+1 (415) 555-1234',
        ]);
    }

    private function rows(?string $kind = null, ?string $channel = null)
    {
        return AppointmentNotification::query()
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->when($channel, fn ($q) => $q->where('channel', $channel))
            ->orderBy('scheduled_for')->orderBy('id')->get();
    }

    private function emails(): array
    {
        $out = [];
        foreach (Notification::sentNotifications()[AnonymousNotifiable::class] ?? [] as $byId) {
            foreach ($byId[AppointmentCustomerNotification::class] ?? [] as $sent) {
                $out[] = $sent['notification'];
            }
        }

        return $out;
    }

    private function sweepAt(string $when): int
    {
        Carbon::setTestNow($when);

        return app(AppointmentNotificationDispatcher::class)->dispatchDue();
    }

    private function textingReady(): void
    {
        Country::firstOrCreate(['country_code' => '1', 'iso_code' => 'US'], ['name' => 'United States', 'status' => 1]);
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'format' => '$', 'status' => true]);
        $server = SendingServer::create([
            'name' => 'Twilio', 'settings' => SendingServer::TYPE_TWILIO, 'status' => true, 'plain' => true, 'mms' => true,
            'user_id' => $this->business->customer_id, 'account_sid' => 'AC_TEST', 'auth_token' => 'token_test',
        ]);
        CustomerBasedSendingServer::create([
            'user_id' => $this->business->customer_id, 'business_id' => $this->business->id, 'sending_server' => $server->id, 'status' => 1,
        ]);
        Senderid::create([
            'user_id' => $this->business->customer_id, 'business_id' => $this->business->id, 'sender_id' => 'BOOKSENDER' . $this->business->id,
            'status' => Senderid::STATUS_ACTIVE, 'price' => 0, 'billing_cycle' => 'monthly', 'frequency_amount' => 1,
            'frequency_unit' => 'month', 'currency_id' => $currency->id,
        ]);

        $mock = \Mockery::mock(CampaignRepository::class);
        $mock->shouldReceive('checkQuickSendValidation')->andReturnUsing(fn (array $input) => response()->json([
            'status' => 'success', 'sender_id' => $input['sender_id'] ?? null, 'sms_type' => $input['sms_type'] ?? 'plain', 'user_id' => $input['user_id'] ?? null,
        ]));
        $mock->shouldReceive('quickSend')->andReturnUsing(function ($campaign, array $input) {
            $this->smsPayloads[] = $input;

            return response()->json(['status' => 'success', 'message' => 'sent']);
        });
        $this->app->instance(CampaignRepository::class, $mock);
    }

    // ---- timing ----------------------------------------------------------------------------------------

    public function test_more_than_four_reminders_with_free_timing_are_all_scheduled_at_their_exact_moments(): void
    {
        $this->configure([
            $this->step(45), $this->step(180), $this->step(60), $this->step(30), $this->step(4320), $this->step(2880), $this->step(1440),
        ]);

        $this->book();

        $reminders = $this->rows('reminder', 'email');
        $this->assertCount(7, $reminders);
        $start = Carbon::parse('2027-03-01 15:00:00 UTC');
        foreach ([45, 180, 60, 30, 4320, 2880, 1440] as $offset) {
            $row = $reminders->firstWhere('offset_minutes', $offset);
            $this->assertNotNull($row, "offset {$offset}");
            $this->assertTrue($start->copy()->subMinutes($offset)->equalTo($row->scheduled_for), "offset {$offset} is exactly that many minutes before the start");
            $this->assertSame('pending', $row->status);
        }
    }

    public function test_a_disabled_step_is_never_scheduled_and_channels_are_per_step(): void
    {
        $this->textingReady();
        $this->configure([
            $this->step(120, ['email' => true, 'sms' => false]),
            $this->step(60, ['email' => false, 'sms' => true]),
            $this->step(30, ['enabled' => false]),
        ], ['notify_sms' => false]);

        $this->book(['sms_consent' => '1']);

        $this->assertSame([120], $this->rows('reminder', 'email')->pluck('offset_minutes')->map(fn ($m) => (int) $m)->all());
        $this->assertSame([60], $this->rows('reminder', 'sms')->pluck('offset_minutes')->map(fn ($m) => (int) $m)->all());
        $this->assertCount(0, $this->rows('confirmation', 'sms'), 'A text-only REMINDER does not turn the text confirmation on.');
    }

    public function test_a_reminder_text_still_needs_the_consent_box(): void
    {
        $this->textingReady();
        $this->configure([$this->step(60, ['email' => false, 'sms' => true])], ['notify_sms' => false]);

        $this->book(); // no sms_consent

        $row = $this->rows('reminder', 'sms')->sole();
        $this->assertSame('skipped', $row->status);
        $this->assertSame('no_sms_consent', $row->reason);
    }

    // ---- wording ---------------------------------------------------------------------------------------

    public function test_the_confirmation_uses_the_types_own_wording_with_real_values(): void
    {
        $this->configure([], [
            'confirmation_email_subject' => 'See you {{date}} - {{business_name}}',
            'confirmation_email_body' => "Hi {{customer_name}},\n\nYour {{booking_type}} is at {{time_range}}.\n\nWhere: {{location}}",
        ]);

        $this->book();

        $mail = $this->emails()[0]->toMail(new AnonymousNotifiable());
        $this->assertSame('See you Monday, March 1, 2027 - ' . $this->business->name, $mail->subject);
        $body = implode(' ', array_map('strval', $mail->introLines));
        $this->assertStringContainsString('Hi Ada,', $body);
        $this->assertStringContainsString('Your Consultation is at 10:00 AM – 11:00 AM.', $body);
        $this->assertStringContainsString('Location A', $body);
        $this->assertNotNull($mail->actionUrl, 'The calendar link is still attached.');
        $this->assertCount(1, $mail->rawAttachments);
    }

    public function test_blank_wording_keeps_the_built_in_message(): void
    {
        $this->configure([]);

        $this->book();

        $mail = $this->emails()[0]->toMail(new AnonymousNotifiable());
        $this->assertStringStartsWith('Booking confirmed:', (string) $mail->subject);
    }

    public function test_each_reminder_step_sends_its_own_wording_and_a_text_always_ends_with_the_opt_out(): void
    {
        $this->textingReady();
        $this->configure([
            $this->step(1440, ['email_subject' => 'Tomorrow: {{booking_type}}', 'email_body' => 'Bring your ID, {{customer_name}}.']),
            $this->step(60, ['email' => false, 'sms' => true, 'sms_body' => 'Starting at {{time}} - {{business_name}}']),
        ], ['notify_email' => false, 'notify_sms' => false]);

        $this->book(['sms_consent' => '1']);
        $this->sweepAt('2027-02-28 15:00:00 UTC');
        $this->sweepAt('2027-03-01 14:00:00 UTC');

        $mail = $this->emails()[0]->toMail(new AnonymousNotifiable());
        $this->assertSame('Tomorrow: Consultation', $mail->subject);
        $this->assertStringContainsString('Bring your ID, Ada.', implode(' ', array_map('strval', $mail->introLines)));

        $this->assertCount(1, $this->smsPayloads);
        $this->assertStringStartsWith('Starting at 10:00 AM EST - ' . $this->business->name, $this->smsPayloads[0]['message']);
        $this->assertStringEndsWith('Reply STOP to opt out.', $this->smsPayloads[0]['message']);
    }

    public function test_an_edit_applies_to_reminders_that_have_not_gone_out_yet(): void
    {
        $this->configure([$this->step(60, ['email_subject' => 'Old subject'])]);
        $this->book();

        $this->configure([$this->step(60, ['email_subject' => 'New subject'])]);
        $this->sweepAt('2027-03-01 14:00:00 UTC');

        $reminder = collect($this->emails())->last()->toMail(new AnonymousNotifiable());
        $this->assertSame('New subject', $reminder->subject);
    }

    // ---- safety ----------------------------------------------------------------------------------------

    public function test_a_rescheduled_appointment_retires_custom_reminders_and_never_sends_the_old_time(): void
    {
        $this->configure([$this->step(90), $this->step(4320, ['email_subject' => 'Custom'])]);
        $this->book();
        $appointment = Appointment::query()->latest('id')->firstOrFail();

        app(AppointmentBookingService::class)->reschedule($appointment, Carbon::parse('2027-03-01 17:00:00 UTC'));

        $reminders = $this->rows('reminder', 'email');
        $old = $reminders->where('appointment_start_at', Carbon::parse('2027-03-01 15:00:00 UTC'));
        $new = $reminders->where('appointment_start_at', Carbon::parse('2027-03-01 17:00:00 UTC'));
        $this->assertSame(['cancelled', 'cancelled'], $old->pluck('status')->values()->all());
        $this->assertSame([90, 4320], $new->pluck('offset_minutes')->map(fn ($m) => (int) $m)->sort()->values()->all());

        // The 3-day reminder (new start - 4320 min) goes out on its own moment.
        $this->sweepAt('2027-02-26 17:00:00 UTC');

        // The OLD 90-minute moment (13:30 UTC) passes: nothing is sent for it.
        $before = count($this->emails());
        $this->sweepAt('2027-03-01 13:30:00 UTC');
        $this->assertCount($before, $this->emails());

        // The NEW moment (15:30 UTC) sends once.
        $this->sweepAt('2027-03-01 15:30:00 UTC');
        $this->sweepAt('2027-03-01 15:31:00 UTC');
        $this->assertCount($before + 1, $this->emails());
    }

    public function test_cancelling_retires_every_pending_step(): void
    {
        $this->configure([$this->step(60), $this->step(180), $this->step(1440)]);
        $this->book();
        $appointment = Appointment::query()->latest('id')->firstOrFail();

        app(\App\Library\Calendar\Notifications\AppointmentNotificationScheduler::class)->onCancelled((int) $appointment->id);

        $this->assertSame(0, $this->rows('reminder')->where('status', 'pending')->count());
    }

    public function test_the_variables_render_blank_when_unknown_and_never_evaluate(): void
    {
        $d = new \App\Library\Calendar\Notifications\AppointmentNotificationDetails(
            'u', 'Acme', 'Consult', 'Monday', '10-11', '10:00 AM', 'EST', 'UTC', null, null, now(), now(), 0,
        );

        $out = AppointmentNotificationContent::render('Hi {{customer_name}} {{nope}} {{business_name}} {{ date }}', $d, '');

        $this->assertSame('Hi there  Acme Monday', $out);
        $this->assertStringContainsString('STOP', AppointmentNotificationContent::renderSms('Hello', $d));
    }

    // ---- editor ----------------------------------------------------------------------------------------

    public function test_the_editor_saves_free_timing_in_minutes_hours_and_days_and_refuses_duplicate_moments(): void
    {
        $this->authenticate($this->owner->user);
        $url = route('customer.workspaces.businesses.calendar.booking-types.update', [
            $this->workspace->uid, $this->business->uid, $this->locationA->uid, $this->type->uid,
        ]);
        $base = ['name' => 'Consultation', 'duration_minutes' => 60, 'reminders_submitted' => 1, 'reminder_steps_submitted' => 1];

        $this->post($url, $base + ['reminder_steps' => [
            ['value' => 45, 'unit' => 'minutes', 'email' => 1, 'enabled' => 1],
            ['value' => 3, 'unit' => 'hours', 'sms' => 1, 'enabled' => 1],
            ['value' => 5, 'unit' => 'days', 'email' => 1, 'sms' => 1, 'enabled' => 0, 'email_subject' => 'Soon'],
        ]])->assertSessionHasNoErrors();

        $steps = $this->type->fresh()->reminder_steps;
        $this->assertSame([45, 180, 7200], array_column($steps, 'offset_minutes'));
        $this->assertFalse($steps[2]['enabled']);

        // 2 hours and 120 minutes are the same moment.
        $this->post($url, $base + ['reminder_steps' => [
            ['value' => 2, 'unit' => 'hours', 'email' => 1],
            ['value' => 120, 'unit' => 'minutes', 'email' => 1],
        ]])->assertSessionHasErrors('reminder_steps.1.value');
        $this->assertCount(3, $this->type->fresh()->reminder_steps, 'The refused save changed nothing.');

        // System bounds, not a count limit.
        $this->post($url, $base + ['reminder_steps' => [['value' => 2, 'unit' => 'minutes', 'email' => 1]]])
            ->assertSessionHasErrors('reminder_steps.0.value');
    }

    public function test_the_preview_renders_the_real_template_and_is_scoped_to_the_owners_booking_type(): void
    {
        $this->authenticate($this->owner->user);
        $url = route('customer.workspaces.businesses.calendar.booking-types.notification-preview', [
            $this->workspace->uid, $this->business->uid, $this->locationA->uid, $this->type->uid,
        ]);

        $this->postJson($url, ['channel' => 'sms', 'kind' => 'reminder', 'body' => 'See you at {{time}} for {{booking_type}}'])
            ->assertOk()
            ->assertJsonPath('body', fn ($b) => str_contains($b, 'for Consultation') && str_ends_with($b, 'Reply STOP to opt out.'));

        $this->postJson($url, ['channel' => 'email', 'kind' => 'confirmation', 'subject' => 'Hello {{business_name}}', 'body' => 'Body'])
            ->assertOk()->assertJsonPath('subject', 'Hello ' . $this->business->name);

        $this->postJson($url, ['channel' => 'fax', 'kind' => 'reminder'])->assertStatus(422);
    }
}
