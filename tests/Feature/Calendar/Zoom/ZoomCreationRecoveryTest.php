<?php

namespace Tests\Feature\Calendar\Zoom;

use App\Enums\Calendar\AppointmentStatus;
use App\Jobs\Calendar\SyncZoomMeeting;
use App\Library\Calendar\Zoom\ZoomApiException;
use App\Library\Calendar\Zoom\ZoomMeetingService;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Notifications\Calendar\AppointmentCustomerNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Zoom's create call has no idempotency key. These tests prove a lost response, a crash, concurrent workers and a cancel during
 * creation can never produce a second meeting or a second invitation: the outcome is reconciled (list + adopt) before any retry.
 */
class ZoomCreationRecoveryTest extends TestCase
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
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pending(): Appointment
    {
        Queue::fake();
        $this->fakeZoom();

        return $this->bookZoom($this->host);
    }

    private function listing(Appointment $a, array $extra = []): array
    {
        return ['meetings' => [array_merge([
            'id' => 81234567890, 'join_url' => self::JOIN_URL, 'topic' => 'x', 'agenda' => 'MotionGrove appointment '.$a->uid,
            'start_time' => $a->start_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
        ], $extra)]];
    }

    private function sync(Appointment $a): void
    {
        (new SyncZoomMeeting($a->id))->handle(app(ZoomMeetingService::class));
    }

    public function test_a_timeout_after_zoom_created_the_meeting_is_adopted_not_recreated(): void
    {
        $a = $this->pending();
        $listing = null;
        $this->fakeZoom(['https://api.zoom.us/v2/users/me/meetings*' => function ($request) use (&$a) {
            if ($request->method() === 'GET') {
                return Http::response($this->listing($a));
            }
            throw new ConnectionException('cURL error 28: timed out');
        }]);

        $job = new SyncZoomMeeting($a->id);
        $job->job = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->job->shouldReceive('attempts')->andReturn(1);
        $job->job->shouldReceive('release')->once();
        $job->handle(app(ZoomMeetingService::class));

        $row = $a->fresh();
        $this->assertNull($row->zoom_meeting_id);
        $this->assertNotNull($row->zoom_create_started_at, 'the uncertain create is remembered durably');
        $this->assertNull($row->zoom_create_lease_until, 'the lease is released so the retry can proceed');

        $this->sync($a);

        $row = $a->fresh();
        $this->assertSame('ready', $row->zoom_status);
        $this->assertSame('81234567890', $row->zoom_meeting_id);
        $this->assertNull($row->zoom_create_started_at);
        $this->assertSame(0, $this->zoomCalls('POST', '/users/me/meetings'), 'the timed-out create is the only one that was ever sent, and it is never repeated');
    }

    public function test_a_timeout_where_zoom_never_created_it_creates_exactly_once_after_checking(): void
    {
        $a = $this->pending();
        $first = true;
        $this->fakeZoom(['https://api.zoom.us/v2/users/me/meetings*' => function ($request) use (&$first) {
            if ($request->method() === 'GET') {
                return Http::response(['meetings' => []]);
            }
            if ($first) {
                $first = false;
                throw new ConnectionException('timed out');
            }

            return Http::response(['id' => 81234567890, 'join_url' => self::JOIN_URL, 'start_url' => self::START_URL], 201);
        }]);

        $this->assertThrows(fn () => app(ZoomMeetingService::class)->reconcile($a->id), ZoomApiException::class);
        $this->sync($a);

        $this->assertSame('ready', $a->fresh()->zoom_status);
        $this->assertSame(1, $this->zoomCalls('GET', '/users/me/meetings'));
        $this->assertSame(1, $this->zoomCalls('POST', '/users/me/meetings'), 'recorded POSTs exclude the timed-out one: exactly one create succeeded');
    }

    public function test_a_crashed_worker_is_recovered_after_its_lease_expires_without_a_duplicate(): void
    {
        $a = $this->pending();
        // The dead worker: lease taken, create may have reached Zoom, then the process vanished.
        Appointment::query()->whereKey($a->id)->update([
            'zoom_create_token' => 'dead-worker', 'zoom_create_started_at' => now(), 'zoom_create_lease_until' => now()->addSeconds(120),
        ]);
        $this->fakeZoom(['https://api.zoom.us/v2/users/me/meetings*' => fn ($r) => $r->method() === 'GET' ? Http::response($this->listing($a)) : Http::response([], 500)]);

        $this->assertThrows(fn () => app(ZoomMeetingService::class)->reconcile($a->id), ZoomApiException::class);
        $this->assertSame(0, $this->zoomCalls('POST', '/users/me/meetings'));

        Carbon::setTestNow(now()->addSeconds(121));
        $this->sync($a);

        $this->assertSame('81234567890', $a->fresh()->zoom_meeting_id);
        $this->assertSame(0, $this->zoomCalls('POST', '/users/me/meetings'), 'adopted, never re-created');
    }

    public function test_two_workers_racing_create_one_meeting(): void
    {
        $a = $this->pending();
        $second = null;
        $this->fakeZoom(['https://api.zoom.us/v2/users/me/meetings*' => function ($request) use ($a, &$second) {
            if ($request->method() === 'POST' && $second === null) {
                // While worker 1 is inside the Zoom call, worker 2 starts: it must be refused by the database lease.
                $second = 'tried';
                try {
                    app(ZoomMeetingService::class)->reconcile($a->id);
                    $second = 'ran';
                } catch (ZoomApiException $e) {
                    $second = $e->errorCode;
                }
            }

            return Http::response(['id' => 81234567890, 'join_url' => self::JOIN_URL, 'start_url' => self::START_URL], 201);
        }]);

        // The cache lock is per process here (array driver); the database lease must hold on its own.
        \Cache::lock('zoom-appt:'.$a->id)->forceRelease();
        $reflect = new \ReflectionMethod(ZoomMeetingService::class, 'claimCreate');
        $reflect->setAccessible(true);
        $svc = app(ZoomMeetingService::class);
        $one = $reflect->invoke($svc, $a->id);
        $two = $reflect->invoke($svc, $a->id);

        $this->assertNotNull($one);
        $this->assertNull($two, 'a live lease refuses a second creator');
    }

    public function test_cancelling_during_an_uncertain_create_deletes_the_meeting_zoom_made(): void
    {
        $a = $this->pending();
        Appointment::query()->whereKey($a->id)->update(['zoom_create_started_at' => now()]);
        $this->fakeZoom(['https://api.zoom.us/v2/users/me/meetings*' => Http::response($this->listing($a))]);

        Appointment::query()->whereKey($a->id)->update(['status' => AppointmentStatus::Cancelled->value]);
        $this->sync($a);

        $this->assertSame(1, $this->zoomCalls('DELETE', '/meetings/81234567890'), 'the orphan is removed');
        $row = $a->fresh();
        $this->assertSame('cancelled', $row->zoom_status);
        $this->assertNull($row->zoom_create_started_at);
    }

    public function test_cancelling_while_a_worker_is_creating_waits_instead_of_orphaning(): void
    {
        $a = $this->pending();
        Appointment::query()->whereKey($a->id)->update([
            'zoom_create_started_at' => now(), 'zoom_create_lease_until' => now()->addSeconds(60), 'zoom_create_token' => 't',
            'status' => AppointmentStatus::Cancelled->value,
        ]);

        $this->assertThrows(fn () => app(ZoomMeetingService::class)->reconcile($a->id), ZoomApiException::class);
        $this->assertSame(0, $this->zoomCalls('DELETE', '/meetings'));
    }

    public function test_an_unreadable_list_holds_the_booking_instead_of_creating_a_duplicate(): void
    {
        $a = $this->pending();
        Appointment::query()->whereKey($a->id)->update(['zoom_create_started_at' => now()]);
        $this->fakeZoom(['https://api.zoom.us/v2/users/me/meetings*' => fn ($r) => $r->method() === 'GET'
            ? Http::response(['code' => 4711, 'message' => 'Invalid access token, does not contain scopes'], 400) : Http::response([], 500)]);

        try {
            app(ZoomMeetingService::class)->reconcile($a->id);
            $this->fail('expected a hold');
        } catch (ZoomApiException $e) {
            $this->assertSame('create_unverifiable', $e->errorCode);
            $this->assertFalse($e->retryable());
        }

        $this->assertSame(0, $this->zoomCalls('POST', '/users/me/meetings'));
    }

    public function test_a_definite_refusal_clears_the_uncertainty_so_it_is_not_looked_up_again(): void
    {
        $a = $this->pending();
        $this->fakeZoom(['https://api.zoom.us/v2/users/me/meetings*' => Http::response(['code' => 300, 'message' => 'bad'], 400)]);

        $this->assertThrows(fn () => app(ZoomMeetingService::class)->reconcile($a->id), ZoomApiException::class);

        $this->assertNull($a->fresh()->zoom_create_started_at);
    }

    public function test_recovery_after_provider_failure_gives_the_guest_the_link_exactly_once(): void
    {
        $a = $this->pending();
        $this->deliverDue();
        // Booking confirmation was held while the link is pending.
        $mails = fn () => Notification::sent(new \Illuminate\Notifications\AnonymousNotifiable(), AppointmentCustomerNotification::class)->count();

        $this->fakeZoom(['https://api.zoom.us/v2/users/me/meetings*' => fn ($r) => $r->method() === 'GET'
            ? Http::response($this->listing($a)) : throw new ConnectionException('timed out')]);
        $this->assertThrows(fn () => app(ZoomMeetingService::class)->reconcile($a->id), ZoomApiException::class);
        $this->sync($a);
        $this->sync($a);
        $this->deliverDue();
        $this->deliverDue();

        $with = 0;
        foreach (Notification::sent(new \Illuminate\Notifications\AnonymousNotifiable(), AppointmentCustomerNotification::class) as $n) {
            $with += str_contains($this->lines($n->toMail(new \Illuminate\Notifications\AnonymousNotifiable())), 'us05web.zoom.us/j/81234567890') ? 1 : 0;
        }
        $this->assertSame(1, $with, 'exactly one email carries the link');
        $this->assertSame(1, AppointmentNotification::query()->where('kind', 'confirmation')->where('channel', 'email')->where('status', 'sent')->count());
    }

    public function test_cancelling_before_the_link_exists_sends_no_stale_confirmation(): void
    {
        $a = $this->pending();
        $this->deliverDue();                       // held: no link yet
        $this->engine()->cancel($a, (int) $this->host->id, 'changed mind');
        $this->sync($a);
        $this->deliverDue();

        Notification::assertNothingSent();
        $this->assertSame(0, AppointmentNotification::query()->where('appointment_id', $a->id)->where('status', 'sent')->count());
    }

    public function test_a_reschedule_before_the_zoom_time_is_updated_never_sends_the_link_for_the_old_time(): void
    {
        $this->pending();
        $a = Appointment::query()->latest('id')->first();
        $this->sync($a);                           // ready at the original time
        Notification::fake();
        $new = Carbon::parse('2027-03-15 10:00:00', 'America/New_York')->utc();
        $this->engine()->reschedule($a->fresh(), $new);   // listener queue is faked: Zoom not yet PATCHed

        $this->assertSame(\App\Library\Calendar\Zoom\ZoomLinkGate::PENDING, \App\Library\Calendar\Zoom\ZoomLinkGate::state($a->fresh()));
        $this->deliverDue();
        Notification::assertNothingSent();

        $this->sync($a);                           // the PATCH lands: now the link may go out
        $this->assertSame(\App\Library\Calendar\Zoom\ZoomLinkGate::READY, \App\Library\Calendar\Zoom\ZoomLinkGate::state($a->fresh()));
    }
}
