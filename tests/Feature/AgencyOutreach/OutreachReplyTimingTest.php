<?php

namespace Tests\Feature\AgencyOutreach;

use App\Jobs\Outreach\OutreachRespondJob;
use App\Library\AgencyOutreach\OutreachReplyTiming;
use App\Library\AgencyOutreach\OutreachScriptManager;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectMessage;
use App\Models\Business;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Feature\AgencyOutreach\Concerns\BuildsOutreachFixtures;
use Tests\TestCase;

/**
 * Natural reply pacing: a randomised, stored, queue-delayed wait before an automatic reply, with every
 * safety check repeated at the moment of sending. Telnyx is the fake adapter; the queue is faked so the
 * delayed dispatches can be inspected and the clock is advanced explicitly.
 */
class OutreachReplyTimingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsOutreachFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOutreach();
        config(['outreach.reply_delay_enabled' => true]);   // phpunit.xml turns it off for the immediate-reply suites
        config(['queue.default' => 'database']);             // a queue that can defer jobs (phpunit uses sync)
    }

    /** @return array{0: Workspace, 1: Business, 2: AgencyProspectCampaignMember, 3: AgencyProspect, 4: AgencyProspectCampaign} */
    private function scenario(): array
    {
        [, $business, $workspace] = $this->agency();
        $this->saveScript($workspace);
        $campaign = $this->managedCampaign($workspace);
        $prospect = $this->prospect($workspace);
        $member = $this->enroll($workspace, $campaign, $prospect, 1);

        return [$workspace, $business, $member, $prospect, $campaign];
    }

    private function lastInbound(): AgencyProspectMessage
    {
        return AgencyProspectMessage::where('direction', 'inbound')->orderByDesc('id')->firstOrFail();
    }

    private function respond(AgencyProspectCampaignMember $member, AgencyProspectMessage $inbound): void
    {
        // Run the job body itself: dispatch_sync would go through the faked queue and never execute.
        app()->call([new OutreachRespondJob($member->id, $inbound->id), 'handle']);
    }

    // ---- delay windows ----------------------------------------------------------------------------------

    public function test_every_reply_category_gets_a_delay_inside_its_approved_window_carried_by_a_delayed_job(): void
    {
        Queue::fake();
        [, $business, $member] = $this->scenario();

        $cases = [
            ['yes sure, sounds good', OutreachReplyTiming::INTERESTED, 120, 240],
            ['how much does it cost?', OutreachReplyTiming::QUESTION, 60, 180],
            ['hmm, not sure about this', OutreachReplyTiming::OBJECTION, 120, 300],
            ['tomorrow works', OutreachReplyTiming::BOOKING, 60, 180],
            ['the venue is on main street', OutreachReplyTiming::OTHER, 60, 240],
        ];

        foreach ($cases as [$body, $category, $min, $max]) {
            $before = now()->timestamp;
            $this->inbound($business, $body);
            $row = $this->lastInbound();

            $this->assertSame($category, $row->reply_category, $body);
            $wait = $row->reply_due_at->timestamp - $before;
            $this->assertGreaterThanOrEqual($min - 1, $wait, "$category lower bound");
            $this->assertLessThanOrEqual($max + 1, $wait, "$category upper bound");
        }

        Queue::assertPushed(OutreachRespondJob::class, 5);
        Queue::assertPushed(OutreachRespondJob::class, fn (OutreachRespondJob $job) => $job->delay !== null);
        $this->assertSame([], $this->sentBodies(), 'Nothing is sent by the webhook itself.');
    }

    public function test_the_stored_delay_is_a_real_random_draw_inside_the_window(): void
    {
        [$workspace, , $member] = $this->scenario();
        $timing = app(OutreachReplyTiming::class);
        $draws = [];

        for ($i = 0; $i < 40; $i++) {
            $row = AgencyProspectMessage::create([
                'workspace_id' => $workspace->id, 'campaign_member_id' => $member->id, 'direction' => 'inbound',
                'operation_key' => 'outreach:in:draw' . $i, 'body' => 'the venue is on main street', 'status' => 'received',
            ]);
            $due = $timing->dueAt($row, $workspace, \App\Library\AgencyOutreach\OutreachClassification::of('the venue is on main street'));
            $draws[] = $due->timestamp - now()->timestamp;
        }

        $this->assertGreaterThanOrEqual(59, min($draws));
        $this->assertLessThanOrEqual(241, max($draws));
        $this->assertGreaterThan(5, count(array_unique($draws)), 'The delay is randomised, not a constant.');
    }

    public function test_stop_is_immediate_and_never_scheduled(): void
    {
        Queue::fake();
        [, $business, $member, $prospect] = $this->scenario();

        $this->inbound($business, 'STOP');

        $this->assertNull($this->lastInbound()->reply_due_at, 'No delay is chosen for a STOP.');
        $this->assertSame('opt_out', $prospect->fresh()->stop_reason);
        $this->assertSame(99, $this->member($member)->stage->value);
        Queue::assertNothingPushed();
    }

    // ---- stability, early runs, duplicates ------------------------------------------------------------------

    public function test_a_webhook_replay_keeps_the_same_due_moment(): void
    {
        Queue::fake();
        [, $business] = $this->scenario();

        $key = $this->inbound($business, 'sounds good');
        $due = $this->lastInbound()->reply_due_at;
        $this->travel(30)->seconds();
        $this->deliverInbound($business, '12025551000', $key);
        $this->deliverInbound($business, '12025551000', $key);

        $this->assertEquals($due, $this->lastInbound()->reply_due_at, 'A replay never re-rolls the delay.');
        $this->assertSame(1, AgencyProspectMessage::where('direction', 'inbound')->count());
        Queue::assertPushed(OutreachRespondJob::class, fn (OutreachRespondJob $job) => $job->delay !== null);
    }

    public function test_nothing_is_sent_before_the_due_moment_and_exactly_one_reply_after_it(): void
    {
        Queue::fake();
        [, $business, $member] = $this->scenario();
        $this->inbound($business, 'sounds good');
        $inbound = $this->lastInbound();

        $this->respond($member, $inbound);                                                   // too early
        $this->assertSame([], $this->sentBodies());
        $this->assertSame(AgencyProspectMessage::STATUS_RECEIVED, $inbound->fresh()->status, 'An early run does not consume the message.');

        $this->travel(4)->minutes();
        $this->travel(5)->seconds();
        $this->respond($member, $inbound);

        $this->assertCount(1, $this->sentBodies());
        $this->assertStringStartsWith('Hi, Snap Booth Co here.', $this->sentBodies()[0]);
    }

    public function test_duplicate_job_executions_send_once(): void
    {
        Queue::fake();
        [, $business, $member] = $this->scenario();
        $this->inbound($business, 'sounds good');
        $inbound = $this->lastInbound();
        $this->travel(10)->minutes();

        $this->respond($member, $inbound);
        $this->respond($member, $inbound);
        $this->respond($member, $inbound);

        $this->assertCount(1, $this->sentBodies());
        $this->assertSame(1, AgencyProspectMessage::where('operation_key', 'outreach:reply:' . $inbound->id)->count());
    }

    // ---- revalidation at the moment of sending ---------------------------------------------------------------

    public function test_several_quick_messages_get_one_reply_to_the_latest(): void
    {
        Queue::fake();
        [, $business, $member] = $this->scenario();
        $this->inbound($business, 'sounds good');
        $first = $this->lastInbound();
        $this->inbound($business, 'actually, how much does it cost?');
        $second = $this->lastInbound();
        $this->travel(10)->minutes();

        $this->respond($member, $first);
        $this->respond($member, $second);
        $this->respond($member, $first);

        $this->assertCount(1, $this->sentBodies(), 'No stale reply to the older message.');
        $this->assertStringContainsString('Our commission is 9% of booked events.', $this->sentBodies()[0], 'The reply answers the latest message.');
        $this->assertSame('superseded_by_newer_inbound', $first->fresh()->failure_reason);
        $this->assertSame('handled', $first->fresh()->status);
    }

    public function test_a_manual_reply_while_waiting_suppresses_the_automatic_one(): void
    {
        Queue::fake();
        [, $business, $member] = $this->scenario();
        $this->inbound($business, 'sounds good');
        $inbound = $this->lastInbound();
        $this->ownerRepliesByHand($business, '12025551000', 'Hi, it is the owner, call me.');
        $this->travel(10)->minutes();

        $this->respond($member, $inbound);

        $this->assertSame([], $this->sentBodies());
        $this->assertSame('manual_reply_sent', $inbound->fresh()->failure_reason);
    }

    public function test_pausing_the_campaign_before_the_due_moment_sends_nothing(): void
    {
        Queue::fake();
        [, $business, $member, , $campaign] = $this->scenario();
        $this->inbound($business, 'sounds good');
        $inbound = $this->lastInbound();
        $campaign->update(['status' => 'paused']);
        $this->travel(10)->minutes();

        $this->respond($member, $inbound);

        $this->assertSame([], $this->sentBodies());
        $this->assertSame('campaign_not_active', $inbound->fresh()->failure_reason);
    }

    public function test_a_stop_arriving_before_the_due_moment_cancels_the_pending_reply(): void
    {
        Queue::fake();
        [, $business, $member, $prospect] = $this->scenario();
        $this->inbound($business, 'sounds good');
        $pending = $this->lastInbound();

        $this->inbound($business, 'STOP');                                               // applied at once, before any delay
        $this->travel(10)->minutes();
        $this->respond($member, $pending);

        $this->assertSame([], $this->sentBodies(), 'The earlier reply never goes out after a STOP.');
        $this->assertSame('opt_out', $prospect->fresh()->stop_reason);
    }

    // ---- unaffected behaviour -----------------------------------------------------------------------------------

    public function test_existing_timed_steps_keep_their_own_waits(): void
    {
        Queue::fake();
        [$workspace, $business, $member] = $this->scenario();
        OutreachScriptManager::save($workspace, ['sequence_steps' => [
            ['id' => 'a', 'body' => 'Intro A.', 'advance_on' => 'reply', 'enabled' => true],
            ['id' => 't', 'body' => 'Nudge after a day.', 'advance_on' => 'time', 'delay_hours' => 24, 'enabled' => true],
        ]]);
        $this->inbound($business, 'sounds good');
        $inbound = $this->lastInbound();
        $this->travel(10)->minutes();
        $this->respond($member, $inbound);

        $this->assertSame(['Intro A.'], $this->sentBodies());
        $armed = $this->member($member);
        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, $armed->followup_at->timestamp, 120, 'The 24h wait is exactly as configured.');
    }

    public function test_the_default_follow_up_delay_is_unchanged(): void
    {
        $this->assertSame(24, \App\Library\AgencyOutreach\OutreachScript::forWorkspace($this->scenario()[0])->followUpDelayHours());
    }

    // ---- settings -------------------------------------------------------------------------------------------------------

    public function test_windows_default_to_the_approved_ranges_and_an_agency_can_change_them(): void
    {
        [$workspace, $business] = $this->scenario();
        $this->assertSame(OutreachReplyTiming::DEFAULTS, OutreachReplyTiming::windows($workspace));

        OutreachScriptManager::save($workspace, ['reply_delay' => ['interested' => ['min' => 5, 'max' => 5], 'question' => ['min' => 0, 'max' => 0]]]);
        $windows = OutreachReplyTiming::windows($workspace);
        $this->assertSame([300, 300], $windows['interested']);
        $this->assertSame([0, 0], $windows['question']);
        $this->assertSame(OutreachReplyTiming::DEFAULTS['objection'], $windows['objection'], 'Unposted categories keep their approved window.');

        Queue::fake();
        $before = now()->timestamp;
        $this->inbound($business, 'sounds good');
        $this->assertEqualsWithDelta($before + 300, $this->lastInbound()->reply_due_at->timestamp, 2, 'The Agency\'s own window is used.');
    }

    public function test_invalid_windows_are_rejected_and_nothing_is_saved(): void
    {
        [$workspace] = $this->scenario();

        foreach ([['min' => 4, 'max' => 2], ['min' => -1, 'max' => 3], ['min' => 1, 'max' => 31], ['min' => 'x', 'max' => 3]] as $bad) {
            try {
                OutreachScriptManager::save($workspace, ['reply_delay' => ['interested' => $bad]]);
                $this->fail('Expected a validation error for ' . json_encode($bad));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('reply_delay.interested.max', $e->errors());
            }
        }

        $this->assertSame(OutreachReplyTiming::DEFAULTS, OutreachReplyTiming::windows($workspace));
    }

    public function test_a_synchronous_queue_cannot_defer_so_replies_are_immediate_not_lost(): void
    {
        config(['queue.default' => 'sync']);                                             // the checked-in .env.example setting
        [, $business, $member] = $this->scenario();

        $this->inbound($business, 'sounds good');                                        // the job runs inline

        $this->assertNull($this->lastInbound()->reply_due_at, 'No delay is chosen where it could never be honoured.');
        $this->assertCount(1, $this->sentBodies(), 'The reply is sent, not silently dropped.');
        $this->assertFalse(OutreachReplyTiming::enabled());
    }
}
