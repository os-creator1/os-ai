<?php

namespace Tests\Feature\Calendar;

use App\Enums\Calendar\AppointmentStatus;
use App\Library\Calendar\DemoBooking\DemoBookingManageLinks;
use App\Library\Calendar\DemoBooking\Form\DemoFormDefaults;
use App\Library\Calendar\DemoBooking\Form\DemoFormRepository;
use App\Library\Calendar\DemoBooking\Form\DemoFormSchema;
use App\Library\Calendar\Notifications\AppointmentNotificationDetails;
use App\Library\Calendar\Notifications\AppointmentNotificationScheduler;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\DemoBookingResponse;
use App\Notifications\Calendar\AppointmentCustomerNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Notifications\AnonymousNotifiable;
use Tests\Feature\Calendar\Concerns\CreatesCalendarHttpFixtures;
use Tests\TestCase;

/**
 * The /book-demo guest's notices after a reschedule or cancellation (platform transactional mail, through the existing
 * notification ledger), and the read-only answers card on the appointment page. The mail is a Notification, so
 * Notification::fake() is the matching fake.
 */
class DemoGuestChangeNoticesTest extends TestCase
{
    use CreatesCalendarHttpFixtures;
    use RefreshDatabase;

    private $type;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-25 12:00:00 UTC');
        $this->bootCalendarHttpFixtures();
        $this->type = $this->bookingType(null, [
            'name' => 'MotionGrove Demo',
            'notify_email' => true,
            'reminder_steps' => [['id' => 'r60', 'offset_minutes' => 60, 'email' => true, 'sms' => false, 'enabled' => true]],
        ]);
        $this->type->staff()->attach($this->bookableStaff()->id);

        config([
            'demo_booking.booking_type_uuid' => $this->type->public_booking_uuid,
            'demo_booking.turnstile.site_key' => 'site-key-public',
            'demo_booking.turnstile.secret_key' => 'secret-key-private',
            'demo_booking.turnstile.bypass' => false,
        ]);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true, 'hostname' => 'localhost'])]);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function book(array $override = []): Appointment
    {
        $this->post(route('demo.booking.store'), $override + [
            'date' => '2027-03-01', 'time' => '10:00', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test',
            'business_name' => 'Analytical Engines Ltd', 'phone' => '+1 (415) 555-1234', 'industry' => 'home_services',
            'help_with' => ['website', 'crm'], 'message' => 'Looking forward to it.', 'cf-turnstile-response' => 'token-1',
        ])->assertRedirect();

        return Appointment::query()->latest('id')->firstOrFail();
    }

    /** @return array<int, AppointmentCustomerNotification> the customer notices sent so far, in order */
    private function sent(): array
    {
        return Notification::sent(new AnonymousNotifiable(), AppointmentCustomerNotification::class)->all();
    }

    private function subjects(): array
    {
        return array_map(fn ($n) => $n->toMail(new \stdClass())->subject, $this->sent());
    }

    private function rows(string $kind): int
    {
        return AppointmentNotification::query()->where('kind', $kind)->count();
    }

    // ---- confirmation is untouched -----------------------------------------------------------

    public function test_the_booking_confirmation_is_unchanged_and_sent_once(): void
    {
        $this->book();

        $this->assertCount(1, $this->subjects());
        $this->assertStringStartsWith('Booking confirmed:', $this->subjects()[0]);
        $this->assertSame(0, $this->rows(AppointmentNotification::KIND_RESCHEDULED));
        $this->assertSame(0, $this->rows(AppointmentNotification::KIND_CANCELLATION));
    }

    // ---- reschedule --------------------------------------------------------------------------

    public function test_a_successful_reschedule_sends_exactly_one_notice_with_the_new_time_and_a_fresh_manage_link(): void
    {
        $appointment = $this->book();
        $old = DemoBookingManageLinks::urlFor($appointment);

        $this->post($old, ['action' => 'reschedule', 'date' => '2027-03-01', 'time' => '15:00'])->assertRedirect(route('demo.booking.confirmed'));

        $subjects = $this->subjects();
        $this->assertCount(2, $subjects);
        $this->assertStringStartsWith('Booking rescheduled:', $subjects[1]);

        $mail = $this->sent()[1]->toMail(new \stdClass());
        $text = implode(' ', array_map('strval', array_merge($mail->introLines, $mail->outroLines)));
        $moved = $appointment->fresh();
        $details = AppointmentNotificationDetails::for($moved);
        $this->assertSame($details->timeRange, $mail->viewData['details']['Time']);
        $this->assertSame($details->date, $mail->viewData['details']['Date']);
        $this->assertSame($details->timezone, $mail->viewData['details']['Time zone']);
        $this->assertSame($details->businessName, $mail->viewData['details']['Business']);
        $this->assertSame($details->businessName, $mail->from[1]);

        $fresh = DemoBookingManageLinks::urlFor($moved);
        $this->assertNotSame($old, $fresh);
        $this->assertStringContainsString(e($fresh), $text);
        $this->assertStringNotContainsString(e($old), $text);
    }

    public function test_a_replayed_reschedule_event_and_a_failed_reschedule_send_nothing_more(): void
    {
        $appointment = $this->book();
        $blocker = $this->book(['time' => '13:00', 'email' => 'other@example.test']);
        $url = DemoBookingManageLinks::urlFor($appointment);

        // Refused (the slot is taken): no notice.
        $this->post($url, ['action' => 'reschedule', 'date' => '2027-03-01', 'time' => '13:00'])->assertSessionHasErrors('time');
        $this->assertSame(0, $this->rows(AppointmentNotification::KIND_RESCHEDULED));

        $this->post($url, ['action' => 'reschedule', 'date' => '2027-03-01', 'time' => '15:00']);
        $this->assertSame(1, $this->rows(AppointmentNotification::KIND_RESCHEDULED));
        $before = count($this->sent());

        // The same event delivered again, and the retired link used again: still one notice.
        app(AppointmentNotificationScheduler::class)->onRescheduled($appointment->id);
        $this->post($url, ['action' => 'reschedule', 'date' => '2027-03-01', 'time' => '16:00'])->assertNotFound();
        $this->assertSame(1, $this->rows(AppointmentNotification::KIND_RESCHEDULED));
        $this->assertCount($before, $this->sent());
        $this->assertSame(AppointmentStatus::Scheduled, $blocker->fresh()->status);

        // A second genuine move is a second notice.
        $this->post(DemoBookingManageLinks::urlFor($appointment->fresh()), ['action' => 'reschedule', 'date' => '2027-03-01', 'time' => '16:00']);
        $this->assertSame(2, $this->rows(AppointmentNotification::KIND_RESCHEDULED));
    }

    public function test_reminders_follow_the_reschedule_and_stop_on_cancellation(): void
    {
        $appointment = $this->book();
        $url = DemoBookingManageLinks::urlFor($appointment);
        $pendingReminders = fn () => AppointmentNotification::query()->where('kind', AppointmentNotification::KIND_REMINDER)
            ->where('status', AppointmentNotification::STATUS_PENDING)->get();

        $this->assertCount(1, $pendingReminders());

        $this->post($url, ['action' => 'reschedule', 'date' => '2027-03-01', 'time' => '15:00']);
        $reminders = $pendingReminders();
        $this->assertCount(1, $reminders);
        $this->assertSame('2027-03-01 20:00:00', $reminders->first()->appointment_start_at->utc()->toDateTimeString());

        $this->post(DemoBookingManageLinks::urlFor($appointment->fresh()), ['action' => 'cancel'])->assertOk();
        $this->assertCount(0, $pendingReminders());
    }

    // ---- cancellation ------------------------------------------------------------------------

    public function test_a_successful_cancellation_sends_exactly_one_notice_with_no_links_and_a_replay_sends_none(): void
    {
        $appointment = $this->book();
        $url = DemoBookingManageLinks::urlFor($appointment);

        $this->post($url, ['action' => 'cancel'])->assertOk();

        $this->assertCount(2, $this->subjects());
        $this->assertStringStartsWith('Booking cancelled:', $this->subjects()[1]);

        $mail = $this->sent()[1]->toMail(new \stdClass());
        $text = implode(' ', array_map('strval', array_merge($mail->introLines, $mail->outroLines)));
        $this->assertStringContainsString('No further action is needed', $text);
        $this->assertStringNotContainsString('signature=', $text);
        $this->assertNull($mail->actionUrl);
        $this->assertSame([], $mail->rawAttachments);
        $this->assertSame(AppointmentNotificationDetails::for($appointment->fresh(), false, true)->businessName, $mail->viewData['details']['Business']);

        // Replayed event, replayed link: nothing more.
        app(AppointmentNotificationScheduler::class)->onCancelled($appointment->id);
        $this->post($url, ['action' => 'cancel'])->assertNotFound();
        $this->assertSame(1, $this->rows(AppointmentNotification::KIND_CANCELLATION));
        $this->assertCount(2, $this->sent());
    }

    public function test_a_cancellation_notice_never_offers_a_zoom_link(): void
    {
        $appointment = $this->book();
        $appointment->forceFill([
            'meeting_location_type' => 'zoom', 'zoom_status' => 'ready', 'zoom_join_url' => 'https://zoom.us/j/123456789',
            'zoom_synced_start_at' => $appointment->start_at,
        ])->save();

        $this->assertSame('https://zoom.us/j/123456789', AppointmentNotificationDetails::for($appointment->fresh())->joinUrl);

        $this->post(DemoBookingManageLinks::urlFor($appointment->fresh()), ['action' => 'cancel'])->assertOk();

        $cancelled = AppointmentNotificationDetails::for($appointment->fresh(), false, true);
        $this->assertNull($cancelled->joinUrl);
        $this->assertNull($cancelled->manageUrl);
        $mail = $this->sent()[1]->toMail(new \stdClass());
        $this->assertStringNotContainsString('zoom.us', json_encode([$mail->introLines, $mail->outroLines, $mail->actionUrl]));
    }

    public function test_other_booking_types_send_no_change_notices(): void
    {
        $appointment = $this->book();
        $other = $this->bookingType($this->locationB, ['name' => 'Other', 'notify_email' => true]);
        $appointment->forceFill(['booking_type_id' => $other->id])->save();

        app(AppointmentNotificationScheduler::class)->onRescheduled($appointment->id);
        $appointment->forceFill(['status' => AppointmentStatus::Cancelled])->save();
        app(AppointmentNotificationScheduler::class)->onCancelled($appointment->id);

        $this->assertSame(0, $this->rows(AppointmentNotification::KIND_RESCHEDULED));
        $this->assertSame(0, $this->rows(AppointmentNotification::KIND_CANCELLATION));
    }

    // ---- the answers card on the appointment page --------------------------------------------

    private function showUrl(Appointment $appointment): string
    {
        return route('customer.workspaces.businesses.calendar.appointments.show', array_merge($this->scopeFor($this->locationA), [$appointment->uid]));
    }

    public function test_the_appointment_page_shows_the_saved_answers_to_an_authorized_user_only(): void
    {
        $appointment = $this->book();
        $answers = DemoBookingResponse::query()->sole()->answers;
        $this->assertContains('Industry', array_column($answers, 'label'));

        $this->entitledCalendar();
        $this->authenticate($this->owner->user);
        $page = $this->get($this->showUrl($appointment))->assertOk()->assertSee('data-section="appointment-form-answers"', false);
        foreach ($answers as $answer) {
            $page->assertSee($answer['label'])->assertSee($answer['display']);
        }
        $page->assertSee('Looking forward to it.')->assertSee('Analytical Engines Ltd');

        // Someone with no membership in this Workspace is refused, and the answers never appear.
        $this->authenticate($this->outsider);
        $response = $this->get($this->showUrl($appointment));
        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('Analytical Engines Ltd', $response->getContent());
    }

    public function test_a_historical_submission_keeps_its_original_questions_after_the_form_changes(): void
    {
        $appointment = $this->book();

        $fields = array_values(array_filter(DemoFormDefaults::fields(), fn ($f) => ! in_array($f['key'], ['industry', 'help_with'], true)));
        $fields[0]['label'] = 'Given name (renamed)';
        $repo = app(DemoFormRepository::class);
        $repo->saveDraft(DemoFormSchema::normalize($fields)['fields'], 1);
        $repo->publish(1);

        $this->entitledCalendar();
        $this->authenticate($this->owner->user);
        $this->get($this->showUrl($appointment))->assertOk()
            ->assertSee('First name')->assertSee('Industry')->assertSee('Website')
            ->assertDontSee('Given name (renamed)');
    }
}
