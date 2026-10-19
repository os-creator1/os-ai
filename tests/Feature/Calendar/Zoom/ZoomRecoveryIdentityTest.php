<?php

namespace Tests\Feature\Calendar\Zoom;

use App\Library\Calendar\Zoom\ZoomApiException;
use App\Library\Calendar\Zoom\ZoomClient;
use App\Library\Calendar\Zoom\ZoomMeetingService;
use App\Models\Appointment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A lost create is recovered only by PROVEN identity (the appointment's unique reference), never by a generic title and time.
 * The List Meetings entry may omit `agenda`; identity is then verified with GET /meetings/{id}.
 */
class ZoomRecoveryIdentityTest extends TestCase
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

    private function meetingFor(Appointment $a): array
    {
        return [
            'start_utc' => $a->start_at->copy()->utc(), 'agenda' => 'MotionGrove appointment '.$a->uid,
            'ref' => '[MG-'.$a->uid.']', 'topic' => 'x', 'duration' => 60, 'timezone' => 'UTC',
        ];
    }

    private function entry(int $id, Appointment $at, array $extra = []): array
    {
        return array_merge([
            'id' => $id, 'topic' => 'Demo with Acme', 'type' => 2, 'join_url' => 'https://us05web.zoom.us/j/'.$id,
            'start_time' => $at->start_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
        ], $extra);
    }

    /** @param  array<int, array<string, mixed>>  $details  GET /meetings/{id} bodies by id; @param list<array> $pages list pages */
    private function fakeLookup(array $pages, array $details = [], int $detailStatus = 200): void
    {
        $call = 0;
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake([
            'https://api.zoom.us/v2/users/me/meetings*' => function () use (&$call, $pages) {
                return Http::response($pages[min($call++, count($pages) - 1)]);
            },
            'https://api.zoom.us/v2/meetings/*' => function ($request) use ($details, $detailStatus) {
                $id = (int) basename(parse_url($request->url(), PHP_URL_PATH));

                return $detailStatus === 200 ? Http::response($details[$id] ?? [], isset($details[$id]) ? 200 : 404) : Http::response(['code' => 1], $detailStatus);
            },
        ]);
    }

    private function two(): array
    {
        Queue::fake();
        $this->fakeZoom();
        $a = $this->bookZoom($this->host);
        // A different appointment with the SAME topic and the SAME start (e.g. another staff member's or a rebooked slot).
        $b = $this->bookZoom($this->host, Carbon::parse('2027-03-15 10:00:00', 'America/New_York')->utc());
        Appointment::query()->whereKey($b->id)->update(['start_at' => $a->start_at, 'end_at' => $a->end_at]);

        return [$a->fresh(), $b->fresh()];
    }

    private function find(Appointment $a): ?array
    {
        return app(ZoomClient::class)->findScheduledMeeting('tok', $this->meetingFor($a));
    }

    public function test_identical_topics_and_start_times_never_cross_appointments(): void
    {
        [$a, $b] = $this->two();
        $this->fakeLookup(
            [['meetings' => [$this->entry(111111111, $a), $this->entry(222222222, $a)]]],
            [
                111111111 => ['id' => 111111111, 'agenda' => 'MotionGrove appointment '.$b->uid, 'join_url' => 'https://us05web.zoom.us/j/111111111'],
                222222222 => ['id' => 222222222, 'agenda' => 'MotionGrove appointment '.$a->uid, 'join_url' => 'https://us05web.zoom.us/j/222222222'],
            ]
        );

        $this->assertSame('222222222', $this->find($a)['id']);
        $this->assertSame('111111111', $this->find($b)['id']);
    }

    public function test_a_same_title_and_time_meeting_with_no_proof_is_never_adopted(): void
    {
        [$a] = $this->two();
        // List has no agenda; the full record is someone else's (or has no marker at all).
        $this->fakeLookup([['meetings' => [$this->entry(333333333, $a)]]], [333333333 => ['id' => 333333333, 'agenda' => 'Weekly sync', 'topic' => 'Demo with Acme']]);

        $this->assertNull($this->find($a));
    }

    public function test_missing_agenda_and_missing_detail_agenda_is_not_identity(): void
    {
        [$a] = $this->two();
        $this->fakeLookup([['meetings' => [$this->entry(444444444, $a)]]], [444444444 => ['id' => 444444444, 'join_url' => 'https://us05web.zoom.us/j/444444444']]);

        $this->assertNull($this->find($a));
    }

    public function test_a_topic_carrying_the_unique_reference_is_proof_without_an_extra_call(): void
    {
        [$a] = $this->two();
        $this->fakeLookup([['meetings' => [$this->entry(555555555, $a, ['topic' => 'Demo with Acme [MG-'.$a->uid.']'])]]]);

        $this->assertSame('555555555', $this->find($a)['id']);
        $this->assertSame(0, $this->zoomCalls('GET', '/v2/meetings/'));
    }

    public function test_pagination_finds_ours_on_a_later_page(): void
    {
        [$a] = $this->two();
        $this->fakeLookup([
            ['meetings' => [$this->entry(666666661, $a, ['start_time' => '2030-01-01T00:00:00Z', 'agenda' => 'other'])], 'next_page_token' => 'p2'],
            ['meetings' => [$this->entry(666666662, $a, ['agenda' => 'MotionGrove appointment '.$a->uid])]],
        ]);

        $this->assertSame('666666662', $this->find($a)['id']);
        $this->assertSame(2, $this->zoomCalls('GET', '/users/me/meetings'));
    }

    public function test_two_meetings_proving_to_be_ours_are_ambiguous_and_fail_safely(): void
    {
        [$a] = $this->two();
        $this->fakeLookup([['meetings' => [
            $this->entry(777777771, $a, ['agenda' => 'MotionGrove appointment '.$a->uid]),
            $this->entry(777777772, $a, ['topic' => 'z [MG-'.$a->uid.']']),
        ]]]);

        try {
            $this->find($a);
            $this->fail('expected ambiguity');
        } catch (ZoomApiException $e) {
            $this->assertSame('create_ambiguous', $e->errorCode);
        }
    }

    public function test_a_service_hold_on_ambiguity_or_failed_verification_never_creates_or_links_anything(): void
    {
        [$a] = $this->two();
        Appointment::query()->whereKey($a->id)->update(['zoom_create_started_at' => now()]);

        // Ambiguous.
        $this->fakeLookup([['meetings' => [
            $this->entry(1, $a, ['agenda' => 'MotionGrove appointment '.$a->uid]), $this->entry(2, $a, ['agenda' => 'MotionGrove appointment '.$a->uid]),
        ]]]);
        try {
            app(ZoomMeetingService::class)->reconcile($a->id);
            $this->fail('expected hold');
        } catch (ZoomApiException $e) {
            $this->assertSame('create_ambiguous', $e->errorCode);
            $this->assertFalse($e->retryable());
        }

        // Verification forbidden (scope missing): hold, not create.
        $this->fakeLookup([['meetings' => [$this->entry(3, $a)]]], [], 400);
        try {
            app(ZoomMeetingService::class)->reconcile($a->id);
            $this->fail('expected hold');
        } catch (ZoomApiException $e) {
            $this->assertSame('create_unverifiable', $e->errorCode);
        }

        // Verification temporarily failing: retryable, still no create.
        $this->fakeLookup([['meetings' => [$this->entry(3, $a)]]], [], 503);
        $this->assertThrows(fn () => app(ZoomMeetingService::class)->reconcile($a->id), ZoomApiException::class);

        $row = $a->fresh();
        $this->assertNull($row->zoom_meeting_id);
        $this->assertNull($row->zoom_join_url);
        $this->assertNotNull($row->zoom_create_started_at, 'still uncertain: never silently cleared');
        $this->assertSame(0, $this->zoomCalls('POST', '/users/me/meetings'));
    }

    public function test_the_unique_reference_is_sent_in_the_topic_and_agenda_on_create(): void
    {
        Queue::fake();
        $this->fakeZoom();
        $a = $this->bookZoom($this->host);
        (new \App\Jobs\Calendar\SyncZoomMeeting($a->id))->handle(app(ZoomMeetingService::class));

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/users/me/meetings')
            && str_ends_with($r['topic'], '[MG-'.$a->uid.']') && mb_strlen($r['topic']) <= 200 && $r['agenda'] === 'MotionGrove appointment '.$a->uid);
    }
}
