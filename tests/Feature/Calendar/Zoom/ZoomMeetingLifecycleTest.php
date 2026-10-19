<?php

namespace Tests\Feature\Calendar\Zoom;

use App\Enums\Calendar\AppointmentStatus;
use App\Exceptions\Calendar\ZoomHostUnavailableException;
use App\Jobs\Calendar\SyncZoomMeeting;
use App\Library\Calendar\Zoom\ZoomApiException;
use App\Library\Calendar\Zoom\ZoomMeetingService;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Notifications\Calendar\AppointmentCustomerNotification;
use App\Notifications\Calendar\ZoomMeetingFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Native Zoom meetings across the booking lifecycle: booking, reschedule, cancel, failures, retries and the customer emails.
 * The Zoom API is faked at the HTTP boundary; the booking engine, events, jobs and notification ledger are the real ones.
 */
class ZoomMeetingLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use ZoomTestSupport;

    private $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootZoom();
        $this->host = $this->bookableStaff();
        $this->type->staff()->attach($this->host->id);
        $this->connect($this->host);
        $this->fakeZoom();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_zoom_booking_creates_one_meeting_matching_time_zone_and_duration_and_stores_only_the_join_link(): void
    {
        $appointment = $this->bookZoom($this->host);

        $this->assertSame('ready', $appointment->zoom_status);
        $this->assertSame('81234567890', $appointment->zoom_meeting_id);
        $this->assertSame(self::JOIN_URL, $appointment->zoom_join_url);
        $this->assertSame(1, $this->zoomCalls('POST', '/users/me/meetings'));

        Http::assertSent(function ($request) use ($appointment) {
            if (! str_ends_with($request->url(), '/users/me/meetings')) {
                return false;
            }
            $b = $request->data();

            return $b['type'] === 2
                && $b['start_time'] === $appointment->start_at->copy()->utc()->format('Y-m-d\TH:i:s\Z')
                && $b['duration'] === 60
                && $b['timezone'] === ($this->business->timezone ?: 'UTC')
                && $request->hasHeader('Authorization', 'Bearer access-token-A');
        });

        // The privileged host link is dropped on the floor: nowhere in the database.
        $this->assertSame(0, \DB::table('appointments')->where('zoom_join_url', 'like', '%zak=%')->count());
        $this->assertStringNotContainsString('SECRET-HOST-TOKEN', json_encode(\DB::table('appointments')->get()));
    }

    public function test_a_booking_without_zoom_is_untouched(): void
    {
        $plain = $this->bookingType(null, ['name' => 'Consultation']);
        $plain->staff()->attach($this->host->id);
        Queue::fake();

        $appointment = $this->engine()->book($plain, (int) $this->host->id, $this->contactId(), $this->slotStart());

        $this->assertNull($appointment->meeting_location_type);
        $this->assertNull($appointment->zoom_status);
        Queue::assertNotPushed(SyncZoomMeeting::class);
        Http::assertNothingSent();
    }

    public function test_no_zoom_call_is_made_inside_the_booking_transaction(): void
    {
        Queue::fake();

        $appointment = $this->engine()->book($this->type, (int) $this->host->id, $this->contactId(), $this->slotStart());

        // Booking only queued the job (after commit); Zoom was not contacted and the appointment is honestly pending.
        Queue::assertPushed(SyncZoomMeeting::class, fn ($job) => $job->appointmentId === $appointment->id);
        Http::assertNothingSent();
        $this->assertSame('pending', $appointment->fresh()->zoom_status);
    }

    public function test_a_zoom_booking_is_refused_before_any_write_when_the_host_has_not_connected_zoom(): void
    {
        $other = $this->bookableStaff();
        $this->type->staff()->attach($other->id);

        $before = Appointment::query()->count();

        try {
            $this->engine()->book($this->type, (int) $other->id, $this->contactId(), $this->slotStart());
            $this->fail('expected a refusal');
        } catch (ZoomHostUnavailableException) {
        }

        $this->assertSame($before, Appointment::query()->count());
    }

    public function test_the_meeting_is_created_under_the_assigned_staff_hosts_own_connection(): void
    {
        $second = $this->bookableStaff();
        $this->type->staff()->attach($second->id);
        $secondConnection = $this->connect($second, null, 'access-token-B');

        $appointment = $this->bookZoom($second);

        $this->assertSame($secondConnection->id, $appointment->zoom_connection_id);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/users/me/meetings') && $r->hasHeader('Authorization', 'Bearer access-token-B'));
    }

    public function test_a_shared_host_from_another_business_is_never_used(): void
    {
        // A connection that belongs to a different Business cannot host this Business's appointments.
        $otherOwner = $this->createCustomer();
        $otherWorkspace = $this->createWorkspace($otherOwner->user);
        $otherBusiness = $this->createBusinessForCustomer($otherOwner->user_id, $otherWorkspace->id);
        $foreign = $this->connect($this->host, $otherBusiness->id, 'foreign-token');
        $this->type->forceFill(['zoom_host_connection_id' => $foreign->id])->save();

        $this->expectException(ZoomHostUnavailableException::class);

        $this->engine()->book($this->type->fresh(), (int) $this->host->id, $this->contactId(), $this->slotStart());
    }

    public function test_core_businesses_cannot_book_zoom_appointments(): void
    {
        $this->setTier('core');

        $this->expectException(ZoomHostUnavailableException::class);

        $this->engine()->book($this->type, (int) $this->host->id, $this->contactId(), $this->slotStart());
    }

    // ------------------------------------------------------------------ emails

    public function test_the_confirmation_waits_for_the_meeting_and_then_carries_a_valid_join_link(): void
    {
        // Provisioning is slow: the confirmation must be held, not sent without a link.
        Queue::fake();
        $appointment = $this->bookZoom($this->host);
        $this->deliverDue();

        Notification::assertNothingSent();
        $this->assertSame('pending', AppointmentNotification::query()->where('appointment_id', $appointment->id)->value('status'));

        // Zoom finishes: the held confirmation goes out with the participant link.
        (new SyncZoomMeeting($appointment->id))->handle(app(ZoomMeetingService::class));
        $this->deliverDue();

        Notification::assertSentOnDemand(AppointmentCustomerNotification::class, function ($n, $channels, $notifiable) {
            $mail = $n->toMail($notifiable);

            return $mail->actionText === 'Join Zoom meeting' && $mail->actionUrl === self::JOIN_URL
                && str_contains($this->lines($mail), self::JOIN_URL);
        });
        Notification::assertSentOnDemandTimes(AppointmentCustomerNotification::class, 1);
        $this->assertStringNotContainsString('zak=', json_encode(Notification::sent(new AnonymousNotifiable, AppointmentCustomerNotification::class)->map(fn ($n) => $n->toMail(new AnonymousNotifiable)->actionUrl)));
    }

    public function test_the_ics_and_google_links_use_the_join_link(): void
    {
        $appointment = $this->bookZoom($this->host);
        $details = \App\Library\Calendar\Notifications\AppointmentNotificationDetails::for($appointment);

        $this->assertSame(self::JOIN_URL, $details->joinUrl);
        $this->assertStringContainsString('LOCATION:'.str_replace([',', ';'], ['\\,', '\;'], self::JOIN_URL), \App\Library\Calendar\Notifications\AppointmentCalendarLinks::ics($details));
        $this->assertStringContainsString(rawurlencode(self::JOIN_URL), \App\Library\Calendar\Notifications\AppointmentCalendarLinks::googleUrl($details));
    }

    public function test_a_non_zoom_confirmation_is_exactly_as_before(): void
    {
        $plain = $this->bookingType(null, ['name' => 'Consultation', 'notify_email' => true]);
        $plain->staff()->attach($this->host->id);

        $appointment = $this->bookZoom($this->host, null, $plain);
        $this->deliverDue();

        Notification::assertSentOnDemand(AppointmentCustomerNotification::class, function ($n, $c, $notifiable) {
            $mail = $n->toMail($notifiable);

            return $mail->actionText === 'Add to Google Calendar' && ! str_contains(strtolower($this->lines($mail)), 'zoom');
        });
    }

    public function test_an_invalid_or_host_link_is_never_stored_or_sent(): void
    {
        $this->fakeZoom(['https://api.zoom.us/v2/users/me/meetings' => Http::response(['id' => 1, 'join_url' => self::START_URL, 'start_url' => self::START_URL], 201)]);

        $appointment = $this->bookZoom($this->host);

        $appointment = $appointment->fresh();
        $this->assertNull($appointment->zoom_join_url);
        $this->assertNull(\App\Library\Calendar\Zoom\ZoomLinkGate::joinUrl($appointment));
        $this->assertFalse(\App\Library\Calendar\Zoom\ZoomJoinUrl::valid(self::START_URL));
        $this->assertFalse(\App\Library\Calendar\Zoom\ZoomJoinUrl::valid('http://zoom.us/j/81234567890'));
        $this->assertFalse(\App\Library\Calendar\Zoom\ZoomJoinUrl::valid('https://evil.example/j/81234567890'));
        $this->assertTrue(\App\Library\Calendar\Zoom\ZoomJoinUrl::valid(self::JOIN_URL));
    }

    // -------------------------------------------------------- reschedule / cancel

    public function test_a_reschedule_updates_the_same_meeting_and_keeps_its_link(): void
    {
        $appointment = $this->bookZoom($this->host);

        $newStart = Carbon::parse('2027-03-08 14:00:00', 'America/New_York')->utc();
        $this->engine()->reschedule($appointment, $newStart, (int) $this->host->id);

        $fresh = $appointment->fresh();
        $this->assertSame(1, $this->zoomCalls('POST', '/users/me/meetings'), 'no second meeting');
        $this->assertSame(1, $this->zoomCalls('PATCH', '/meetings/81234567890'));
        $this->assertSame(self::JOIN_URL, $fresh->zoom_join_url);
        $this->assertTrue($fresh->zoom_synced_start_at->equalTo($newStart));
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r['start_time'] === $newStart->format('Y-m-d\TH:i:s\Z'));
    }

    public function test_a_stale_job_cannot_restore_an_old_time_and_repeating_a_job_is_a_no_op(): void
    {
        $appointment = $this->bookZoom($this->host);
        $newStart = Carbon::parse('2027-03-08 14:00:00', 'America/New_York')->utc();
        $this->engine()->reschedule($appointment, $newStart, (int) $this->host->id);

        $patches = $this->zoomCalls('PATCH');
        // Replay old/duplicate jobs: each reconciles to the CURRENT state, which Zoom already has.
        (new SyncZoomMeeting($appointment->id))->handle(app(ZoomMeetingService::class));
        (new SyncZoomMeeting($appointment->id))->handle(app(ZoomMeetingService::class));

        $this->assertSame($patches, $this->zoomCalls('PATCH'));
        $this->assertSame(1, $this->zoomCalls('POST', '/users/me/meetings'));
        $this->assertTrue($appointment->fresh()->zoom_synced_start_at->equalTo($newStart));
    }

    public function test_a_cancel_deletes_the_zoom_meeting_and_stops_pending_reminders(): void
    {
        $appointment = $this->bookZoom($this->host);

        $this->engine()->cancel($appointment, (int) $this->host->id, 'guest asked');

        $this->assertSame(1, $this->zoomCalls('DELETE', '/meetings/81234567890'));
        $fresh = $appointment->fresh();
        $this->assertSame(AppointmentStatus::Cancelled, $fresh->status);
        $this->assertSame('cancelled', $fresh->zoom_status);
        $this->assertSame(0, AppointmentNotification::query()->where('appointment_id', $appointment->id)->where('status', 'pending')->count());
        $this->assertNull(\App\Library\Calendar\Zoom\ZoomLinkGate::joinUrl($fresh));
    }

    public function test_cancelling_a_meeting_zoom_already_deleted_counts_as_cancelled(): void
    {
        $appointment = $this->bookZoom($this->host);
        $this->fakeZoom(['https://api.zoom.us/v2/meetings/*' => Http::response(['code' => 3001, 'message' => 'gone'], 404)]);

        $this->engine()->cancel($appointment, (int) $this->host->id);

        $this->assertSame('cancelled', $appointment->fresh()->zoom_status);
    }

    // ------------------------------------------------- failures, retries, duplicates

    public function test_a_provider_outage_is_retried_with_backoff_and_never_creates_a_duplicate(): void
    {
        Queue::fake();
        $appointment = $this->bookZoom($this->host);
        $this->fakeZoom(['https://api.zoom.us/v2/users/me/meetings' => Http::sequence()
            ->push('', 503)->push('', 503)->push(['id' => 81234567890, 'join_url' => self::JOIN_URL, 'start_url' => self::START_URL], 201)]);

        $job = new SyncZoomMeeting($appointment->id);
        $released = [];
        $job->job = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class, ['attempts' => 1, 'release' => null]);
        $job->job->shouldReceive('release')->andReturnUsing(function ($delay) use (&$released) {
            $released[] = $delay;
        });

        $job->handle(app(ZoomMeetingService::class));
        $job->handle(app(ZoomMeetingService::class));
        $this->assertSame([30, 30], $released, 'bounded, configured backoff');
        $this->assertNull($appointment->fresh()->zoom_meeting_id);

        $job->handle(app(ZoomMeetingService::class));
        $this->assertSame('ready', $appointment->fresh()->zoom_status);
        $this->assertSame(3, $this->zoomCalls('POST', '/users/me/meetings'), 'one successful create after two failed attempts');
        $this->assertSame(1, Appointment::query()->whereNotNull('zoom_meeting_id')->count());
    }

    public function test_after_the_last_attempt_it_fails_explicitly_alerts_the_host_and_tells_the_guest_the_link_will_follow(): void
    {
        Queue::fake();
        $appointment = $this->bookZoom($this->host);
        $this->fakeZoom(['https://api.zoom.us/v2/users/me/meetings' => Http::response('', 500)]);

        $job = new SyncZoomMeeting($appointment->id);
        $job->job = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->job->shouldReceive('attempts')->andReturn(6);
        $job->job->shouldReceive('release')->never();
        $job->handle(app(ZoomMeetingService::class));

        $fresh = $appointment->fresh();
        $this->assertSame('failed', $fresh->zoom_status);
        $this->assertNull($fresh->zoom_join_url);
        Notification::assertSentOnDemand(ZoomMeetingFailedNotification::class);

        // The guest is told honestly: booked, link to follow. The mail has no Zoom button.
        $this->deliverDue();
        Notification::assertSentOnDemand(AppointmentCustomerNotification::class, function ($n, $c, $notifiable) {
            $mail = $n->toMail($notifiable);

            return $mail->actionText === 'Add to Google Calendar' && str_contains($this->lines($mail), 'Zoom link is still being prepared');
        });

        // A person retries safely; the link then reaches the guest in a follow-up, once.
        $this->fakeZoom();
        $this->assertTrue(app(ZoomMeetingService::class)->resetForRetry($appointment->id));
        (new SyncZoomMeeting($appointment->id))->handle(app(ZoomMeetingService::class));
        $this->deliverDue();

        $this->assertSame('ready', $appointment->fresh()->zoom_status);
        Notification::assertSentOnDemand(AppointmentCustomerNotification::class, fn ($n, $c, $notifiable) => $n->toMail($notifiable)->actionUrl === self::JOIN_URL);
    }

    public function test_rate_limits_wait_for_retry_after(): void
    {
        Queue::fake();
        $appointment = $this->bookZoom($this->host);
        $this->fakeZoom(['https://api.zoom.us/v2/users/me/meetings' => Http::response('', 429, ['Retry-After' => '120'])]);

        $job = new SyncZoomMeeting($appointment->id);
        $delay = null;
        $job->job = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->job->shouldReceive('attempts')->andReturn(1);
        $job->job->shouldReceive('release')->andReturnUsing(function ($d) use (&$delay) {
            $delay = $d;
        });
        $job->handle(app(ZoomMeetingService::class));

        $this->assertSame(120, $delay);
    }

    public function test_a_revoked_authorization_fails_at_once_and_asks_for_a_reconnect(): void
    {
        Queue::fake();
        $appointment = $this->bookZoom($this->host);
        \App\Models\ZoomConnection::query()->update(['access_expires_at' => now()->subMinute()]);
        $this->fakeZoom(['https://zoom.us/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $job = new SyncZoomMeeting($appointment->id);
        $job->job = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->job->shouldReceive('attempts')->andReturn(1);
        $job->job->shouldReceive('release')->never();
        $job->handle(app(ZoomMeetingService::class));

        $this->assertSame('failed', $appointment->fresh()->zoom_status);
        $connection = \App\Models\ZoomConnection::query()->first();
        $this->assertSame('revoked', $connection->state);
        $this->assertNull($connection->refresh_token_encrypted);
        Notification::assertSentOnDemand(ZoomMeetingFailedNotification::class);
    }

    public function test_losing_the_save_race_deletes_the_extra_meeting(): void
    {
        $appointment = $this->bookZoom($this->host);
        // Simulate: another worker already saved a meeting for an appointment we are about to create for.
        $second = $this->bookZoom($this->host, Carbon::parse('2027-03-15 10:00:00', 'America/New_York')->utc());
        Appointment::query()->whereKey($second->id)->update(['zoom_meeting_id' => null, 'zoom_status' => 'pending', 'zoom_synced_start_at' => null]);

        $this->fakeZoom(['https://api.zoom.us/v2/users/me/meetings' => function () use ($second) {
            Appointment::query()->whereKey($second->id)->update(['zoom_meeting_id' => 'WON-BY-OTHER']);

            return Http::response(['id' => 222222222222, 'join_url' => 'https://us05web.zoom.us/j/222222222222', 'start_url' => self::START_URL], 201);
        }]);

        (new SyncZoomMeeting($second->id))->handle(app(ZoomMeetingService::class));

        $this->assertSame('WON-BY-OTHER', $second->fresh()->zoom_meeting_id);
        $this->assertSame(1, $this->zoomCalls('DELETE', '/meetings/222222222222'), 'our duplicate was removed');
    }

    public function test_the_reconcile_sweep_requeues_only_stuck_pending_appointments(): void
    {
        Queue::fake();
        $stuck = $this->bookZoom($this->host);
        Appointment::query()->whereKey($stuck->id)->update(['zoom_status' => 'pending', 'updated_at' => now()->subHour()]);
        $failed = $this->bookZoom($this->host, Carbon::parse('2027-03-15 10:00:00', 'America/New_York')->utc());
        Appointment::query()->whereKey($failed->id)->update(['zoom_status' => 'failed', 'updated_at' => now()->subHour()]);
        Queue::fake();

        $this->artisan('calendar:reconcile-zoom')->assertExitCode(0);

        Queue::assertPushed(SyncZoomMeeting::class, 1);
        Queue::assertPushed(SyncZoomMeeting::class, fn ($j) => $j->appointmentId === $stuck->id);
    }
}
