<?php

namespace Tests\Feature\AgencyOutreach;

use App\Jobs\Outreach\OutreachDeferredSendJob;
use App\Jobs\Outreach\OutreachInitialSendJob;
use App\Jobs\Outreach\OutreachRespondJob;
use App\Library\AgencyOutreach\OutreachCampaignDiagnostics;
use App\Library\AgencyOutreach\OutreachSendBudget;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectMessage;
use App\Models\Business;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\AgencyOutreach\Concerns\BuildsOutreachFixtures;
use Tests\TestCase;

/**
 * One shared, atomic outbound budget per sending number: spacing, an opener cap that never
 * touches replies, manual priority, deferral that keeps the idempotency key, and a full
 * re-check before the deferred send. Telnyx is the fake adapter; time is frozen and advanced.
 */
class OutreachSendPacingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsOutreachFixtures;

    private const P1 = '12025551000';
    private const P2 = '12025552000';
    private const P3 = '12025553000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOutreach();
        config([
            'outreach.send_pacing.enabled' => true,
            'queue.default' => 'database',                 // phpunit uses sync, which cannot defer
            'outreach.reply_delay_enabled' => false,       // isolate pacing from the conversational delay
        ]);
        $this->freezeTime();
        Queue::fake();
    }

    /** @return array{0: Workspace, 1: Business, 2: AgencyProspectCampaign} */
    private function agencyWithCampaign(): array
    {
        [, $business, $workspace] = $this->agency();
        $this->saveScript($workspace);

        return [$workspace, $business, $this->managedCampaign($workspace, ['name' => 'First'])];
    }

    private function member(Workspace $workspace, AgencyProspectCampaign $campaign, string $phone): AgencyProspectCampaignMember
    {
        return $this->enroll($workspace, $campaign, $this->prospect($workspace, $phone), 1);
    }

    /** The prospect writes; the responder runs (the queue is faked, so it is invoked directly). */
    private function reply(Business $business, AgencyProspectCampaignMember $member, string $phone, string $body = 'sounds good'): AgencyProspectMessage
    {
        $this->inbound($business, $body, $phone);
        $inbound = AgencyProspectMessage::where('campaign_member_id', $member->id)->where('direction', 'inbound')->orderByDesc('id')->firstOrFail();
        app()->call([new OutreachRespondJob($member->id, $inbound->id), 'handle']);

        return $inbound;
    }

    private function outboundRow(AgencyProspectCampaignMember $member): AgencyProspectMessage
    {
        return AgencyProspectMessage::where('campaign_member_id', $member->id)->where('direction', 'outbound')->orderByDesc('id')->firstOrFail();
    }

    private function runDeferred(AgencyProspectMessage $row): void
    {
        app()->call([new OutreachDeferredSendJob($row->id), 'handle']);
    }

    private function opener(AgencyProspectCampaignMember $member): void
    {
        app()->call([new OutreachInitialSendJob($member->id), 'handle']);
    }

    // ---- shared across campaigns, exact boundary ---------------------------------------------------------------

    public function test_campaigns_on_the_same_sending_number_share_one_budget_and_a_deferred_send_keeps_its_key(): void
    {
        [$workspace, $business, $c1] = $this->agencyWithCampaign();
        $c2 = $this->managedCampaign($workspace, ['name' => 'Second']);
        $m1 = $this->member($workspace, $c1, self::P1);
        $m2 = $this->member($workspace, $c2, self::P2);

        $inbound1 = $this->reply($business, $m1, self::P1);
        $inbound2 = $this->reply($business, $m2, self::P2);                              // same second, other campaign

        $this->assertCount(1, $this->sentBodies(), 'Only one text this second on this number.');
        $row = $this->outboundRow($m2);
        $this->assertSame('paused', $row->status, 'Deferred, not failed and not sent.');
        $this->assertSame('rate_limited', $row->failure_reason);
        $this->assertNull($row->sent_at);
        $this->assertSame('outreach:reply:' . $inbound2->id, $row->operation_key, 'The idempotency key is kept.');
        $this->assertSame(1, $this->member2Stage($m2), 'Nothing is recorded as delivered: the stage did not move.');
        $this->assertNull($m2->fresh()->sequence_sent_ids, 'No step is recorded as delivered.');
        $this->assertGreaterThan(now()->timestamp, $row->deferred_until->timestamp);
        Queue::assertPushed(OutreachDeferredSendJob::class, fn ($job) => $job->delay !== null);

        $this->assertSame('handled', $inbound2->fresh()->status);
        $this->assertNull($inbound2->fresh()->failure_reason, 'A deferral is not reported as silence.');
    }

    private function member2Stage(AgencyProspectCampaignMember $member): int
    {
        return $member->fresh()->stage->value;
    }

    public function test_the_rate_boundary_is_exactly_ten_seconds(): void
    {
        [$workspace, $business, $c1] = $this->agencyWithCampaign();
        $m1 = $this->member($workspace, $c1, self::P1);
        $m2 = $this->member($workspace, $c1, self::P2);
        $this->reply($business, $m1, self::P1);                                          // granted at t0
        $this->reply($business, $m2, self::P2);                                          // deferred
        $row = $this->outboundRow($m2);

        $this->travel(9)->seconds();
        $this->runDeferred($row);
        $this->assertCount(1, $this->sentBodies(), 'Nine seconds later it is still too early.');
        $this->assertSame('paused', $row->fresh()->status);

        $this->travel(1)->seconds();                                                     // exactly t0 + 10
        $this->runDeferred($row);
        $this->assertCount(2, $this->sentBodies());
        $this->assertSame('sent', $row->fresh()->status);
        $this->assertNotNull($row->fresh()->sent_at);
        $this->assertSame(2, $m2->fresh()->stage->value, 'Only now does the conversation move on.');
    }

    // ---- replays and duplicate runs -----------------------------------------------------------------------------

    public function test_replays_and_duplicate_deferred_runs_send_exactly_once(): void
    {
        [$workspace, $business, $c1] = $this->agencyWithCampaign();
        $m1 = $this->member($workspace, $c1, self::P1);
        $m2 = $this->member($workspace, $c1, self::P2);
        $this->reply($business, $m1, self::P1);
        $inbound2 = $this->reply($business, $m2, self::P2);                              // deferred
        $row = $this->outboundRow($m2);

        app()->call([new OutreachRespondJob($m2->id, $inbound2->id), 'handle']);        // duplicate respond job
        $this->assertSame(1, AgencyProspectMessage::where('operation_key', 'outreach:reply:' . $inbound2->id)->count());

        $this->travel(10)->seconds();
        $this->runDeferred($row);
        $this->runDeferred($row);
        $this->runDeferred($row);

        $this->assertCount(2, $this->sentBodies(), 'One reply per conversation, once.');
        $this->assertSame(1, AgencyProspectMessage::where('operation_key', 'outreach:reply:' . $inbound2->id)->count());
    }

    // ---- manual priority ----------------------------------------------------------------------------------------------

    public function test_a_manual_message_consumes_the_budget_and_automatic_sends_wait_behind_it(): void
    {
        [$workspace, $business, $c1] = $this->agencyWithCampaign();
        $m1 = $this->member($workspace, $c1, self::P1);
        $m2 = $this->member($workspace, $c1, self::P2);
        $this->reply($business, $m1, self::P1);                                          // t0
        $this->reply($business, $m2, self::P2);                                          // deferred
        $row = $this->outboundRow($m2);

        $this->travel(5)->seconds();
        DB::table('business_messaging_operations')->insert([                              // the owner texts by hand at t0+5
            'business_id' => $business->id, 'transport_mode' => 'managed', 'provider' => 'telnyx', 'direction' => 'outbound',
            'message_type' => 'sms', 'operation_key' => 'manual:1', 'status' => 'accepted',
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->travel(5)->seconds();                                                     // t0+10: free by Outreach's own clock, not by the manual send
        $this->runDeferred($row);
        $this->assertCount(1, $this->sentBodies(), 'It waits behind the manual message.');

        $this->travel(5)->seconds();                                                     // t0+15 = manual + 10
        $this->runDeferred($row);
        $this->assertCount(2, $this->sentBodies());
    }

    public function test_openers_wait_behind_a_deferred_reply_and_replies_go_first(): void
    {
        [$workspace, $business, $c1] = $this->agencyWithCampaign();
        $c2 = $this->managedCampaign($workspace, ['name' => 'Bulk']);
        $m1 = $this->member($workspace, $c1, self::P1);
        $m2 = $this->member($workspace, $c1, self::P2);
        $m3 = $this->member($workspace, $c2, self::P3);
        $this->reply($business, $m1, self::P1);                                          // t0
        $this->reply($business, $m2, self::P2);                                          // reply deferred
        $reply = $this->outboundRow($m2);

        $this->travel(10)->seconds();                                                    // the slot is free
        $this->opener($m3);                                                               // ... but a reply is waiting
        $opener = $this->outboundRow($m3);
        $this->assertSame('paused', $opener->status);
        $this->assertSame('rate_limited', $opener->failure_reason);
        $this->assertCount(1, $this->sentBodies(), 'The opener did not jump the queue.');

        $this->runDeferred($reply);                                                       // the reply takes the slot
        $this->assertCount(2, $this->sentBodies());
        $this->assertSame('sent', $reply->fresh()->status);

        $this->travel(10)->seconds();
        $this->runDeferred($opener);                                                      // now nothing higher is waiting
        $this->assertCount(3, $this->sentBodies());
        $this->assertSame('outreach:opener:' . $m3->id, $opener->fresh()->operation_key);
    }

    // ---- the opener cap ---------------------------------------------------------------------------------------------------

    public function test_the_opener_cap_defers_new_conversations_but_never_replies(): void
    {
        config(['outreach.send_pacing.opener_daily_cap' => 2]);
        [$workspace, $business, $c1] = $this->agencyWithCampaign();
        $openers = [$this->member($workspace, $c1, self::P1), $this->member($workspace, $c1, self::P2), $this->member($workspace, $c1, self::P3)];
        $existing = $this->member($workspace, $c1, '12025554000');

        foreach ($openers as $m) {
            $this->opener($m);
            $this->travel(10)->seconds();                                                 // keep spacing out of this test
        }

        $this->assertCount(2, $this->sentBodies(), 'Two openers fit in the day.');
        $third = $this->outboundRow($openers[2]);
        $this->assertSame('paused', $third->status);
        $this->assertSame('opener_daily_cap', $third->failure_reason);
        $first = AgencyProspectMessage::where('campaign_member_id', $openers[0]->id)->first();
        $this->assertEqualsWithDelta($first->created_at->copy()->addDay()->timestamp, $third->deferred_until->timestamp, 10 + 3, 'It is retried when the oldest opener leaves the 24h window.');

        $this->reply($business, $existing, '12025554000');                                // a reply to an existing conversation
        $this->assertCount(3, $this->sentBodies(), 'The cap never blocks a legitimate reply.');
    }

    // ---- stricter limits ------------------------------------------------------------------------------------------------------

    public function test_a_stricter_campaign_interval_and_a_provider_floor_both_apply(): void
    {
        [$workspace, $business, $c1] = $this->agencyWithCampaign();
        $c1->update(['min_send_interval_seconds' => 30]);
        $m1 = $this->member($workspace, $c1, self::P1);
        $c2 = $this->managedCampaign($workspace, ['name' => 'Other']);
        $m2 = $this->member($workspace, $c2, self::P2);

        $this->reply($business, $m1, self::P1);                                          // spaced by the campaign's own 30s
        $this->travel(10)->seconds();
        $this->reply($business, $m2, self::P2);
        $this->assertCount(1, $this->sentBodies(), 'Ten seconds is not enough after a send from the stricter campaign.');

        $row = $this->outboundRow($m2);
        $this->travel(19)->seconds();
        $this->runDeferred($row);
        $this->assertCount(1, $this->sentBodies());
        $this->travel(1)->seconds();                                                     // t0 + 30
        $this->runDeferred($row);
        $this->assertCount(2, $this->sentBodies());

        // A provider floor of 45s now dominates the default 10s and the campaign's 30s is irrelevant for the other campaign.
        config(['outreach.send_pacing.provider_min_interval_seconds' => ['managed' => 45]]);
        $m3 = $this->member($workspace, $c2, self::P3);
        $this->reply($business, $m3, self::P3);
        $this->travel(40)->seconds();
        $row3 = $this->outboundRow($m3);
        $this->runDeferred($row3);
        $this->assertSame('paused', $row3->fresh()->status, 'Inside the provider floor.');
        $this->travel(5)->seconds();
        $this->runDeferred($row3);
        $this->assertSame('sent', $row3->fresh()->status);
    }

    // ---- everything is re-checked before the deferred send -----------------------------------------------------------

    private function deferredReply(): array
    {
        [$workspace, $business, $c1] = $this->agencyWithCampaign();
        $m1 = $this->member($workspace, $c1, self::P1);
        $m2 = $this->member($workspace, $c1, self::P2);
        $this->reply($business, $m1, self::P1);
        $inbound = $this->reply($business, $m2, self::P2);

        return [$business, $c1, $m2, $inbound, $this->outboundRow($m2), $m2->prospect];
    }

    public function test_a_stop_while_deferred_prevents_the_send(): void
    {
        [$business, , $m2, , $row] = $this->deferredReply();
        $this->inbound($business, 'STOP', self::P2);                                     // opt-out is applied at once
        $this->travel(10)->seconds();

        $this->runDeferred($row);

        $this->assertCount(1, $this->sentBodies());
        $this->assertSame('failed', $row->fresh()->status);
        $this->assertSame(99, $m2->fresh()->stage->value);
    }

    public function test_a_newer_message_replaces_the_deferred_reply(): void
    {
        [$business, , $m2, , $row] = $this->deferredReply();
        $this->inbound($business, 'actually how much is it?', self::P2);
        $this->travel(10)->seconds();

        $this->runDeferred($row);

        $this->assertCount(1, $this->sentBodies(), 'The stale reply is not sent.');
        $this->assertSame('failed', $row->fresh()->status);
        $this->assertSame('superseded_by_newer_inbound', $row->fresh()->failure_reason);
    }

    public function test_a_manual_reply_or_a_paused_campaign_while_deferred_prevents_the_send(): void
    {
        [$business, $campaign, , , $row] = $this->deferredReply();
        $this->ownerRepliesByHand($business, self::P2, 'Hi, it is the owner.');
        $this->travel(10)->seconds();
        $this->runDeferred($row);
        $this->assertCount(1, $this->sentBodies());
        $this->assertSame('manual_reply_sent', $row->fresh()->failure_reason);

        [$business2, $campaign2, , , $row2] = $this->deferredReply2();
        $campaign2->update(['status' => 'paused']);
        $this->travel(10)->seconds();
        $this->runDeferred($row2);
        $this->assertSame('paused', $row2->fresh()->status, 'A paused campaign holds the send; it is not delivered.');
        $this->assertNull($row2->fresh()->sent_at);
    }

    private function deferredReply2(): array
    {
        [, $business2, $workspace2] = $this->agency('Second Agency');
        $this->saveScript($workspace2);
        $c = $this->managedCampaign($workspace2);
        $a = $this->member($workspace2, $c, self::P1);
        $b = $this->member($workspace2, $c, self::P2);
        $this->reply($business2, $a, self::P1);
        $this->reply($business2, $b, self::P2);

        return [$business2, $c, $b, null, $this->outboundRow($b)];
    }

    // ---- visibility and switches ----------------------------------------------------------------------------------------------

    public function test_a_deferred_send_is_visible_in_the_campaign_diagnostics(): void
    {
        [, $campaign, , , $row] = $this->deferredReply();
        $workspace = Workspace::find($campaign->workspace_id);

        $d = app(OutreachCampaignDiagnostics::class)->forCampaign($workspace, $campaign->fresh());

        $this->assertSame(1, $d['deferred'][0]['count']);
        $this->assertSame('rate_limited', $d['deferred'][0]['reason']);
        $this->assertStringContainsString('one text at a time', $d['deferred'][0]['label']);
        $this->assertNotNull($d['deferred'][0]['next_at']);
    }


    public function test_opener_cap_uses_actual_sent_time_even_when_claim_is_days_old(): void
    {
        config(['outreach.send_pacing.opener_daily_cap' => 2]);
        [$workspace, , $campaign] = $this->agencyWithCampaign();
        $m1 = $this->member($workspace, $campaign, self::P1);
        $m2 = $this->member($workspace, $campaign, self::P2);
        $m3 = $this->member($workspace, $campaign, self::P3);

        $this->opener($m1);
        $this->travel(10)->seconds();
        $this->opener($m2);
        $this->assertCount(2, $this->sentBodies());

        $sent = AgencyProspectMessage::query()
            ->whereIn('campaign_member_id', [$m1->id, $m2->id])
            ->where('purpose', AgencyProspectMessage::PURPOSE_INITIAL)
            ->get();

        // Deferred messages were originally claimed days earlier; a new
        // sender-day cap must still count them when they are delivered.
        foreach ($sent as $row) {
            $row->created_at = now()->subDays(2);
            $row->save();
        }

        $this->travel(10)->seconds();
        $this->opener($m3);
        $third = $this->outboundRow($m3);

        $this->assertSame('opener_daily_cap', $third->failure_reason);
        $this->assertSame('paused', $third->status);
        $this->assertEqualsWithDelta($sent->first()->sent_at->copy()->addDay()->timestamp,
            $third->deferred_until->timestamp, 10);
    }

    public function test_campaign_resume_recovers_a_rate_deferred_reply_after_its_job_ran_while_paused(): void
    {
        [$workspace, $business, $campaign] = $this->agencyWithCampaign();
        $m1 = $this->member($workspace, $campaign, self::P1);
        $m2 = $this->member($workspace, $campaign, self::P2);
        $this->reply($business, $m1, self::P1);
        $this->reply($business, $m2, self::P2);
        $waiting = $this->outboundRow($m2);

        $campaign->update(['status' => 'paused']);
        $this->travel(20)->seconds();
        $this->runDeferred($waiting);
        $this->assertSame('paused', $waiting->fresh()->status);
        $this->assertCount(1, $this->sentBodies());

        $resumed = app(\App\Library\AgencyOutreach\OutreachCampaignService::class)
            ->resume($workspace, $campaign->fresh());
        $this->assertTrue($resumed['ok']);
        Queue::assertPushed(OutreachDeferredSendJob::class, fn ($job) => $job->delay !== null);
        $this->runDeferred($waiting);

        $this->assertSame('sent', $waiting->fresh()->status);
        $this->assertCount(2, $this->sentBodies());
    }

    public function test_a_cancelled_rate_deferred_followup_never_sends_or_blocks_the_new_reply(): void
    {
        [$workspace, $business, $campaign] = $this->agencyWithCampaign();
        $member = $this->member($workspace, $campaign, self::P1);
        $member->update([
            'followup_at' => now()->subMinute(),
            'followup_cancelled_at' => now(),
            'booking_link_sent_at' => now()->subHour(),
        ]);
        $deferred = AgencyProspectMessage::create([
            'workspace_id' => $workspace->id,
            'campaign_member_id' => $member->id,
            'direction' => AgencyProspectMessage::DIRECTION_OUTBOUND,
            'purpose' => AgencyProspectMessage::PURPOSE_FOLLOWUP,
            'operation_key' => 'outreach:followup:' . $member->id,
            'body' => 'Checking in about our call.',
            'status' => AgencyProspectMessage::STATUS_PAUSED,
            'failure_reason' => 'rate_limited',
            'deferred_until' => now()->subSecond(),
            'stage_from' => $member->stage->value,
            'stage_to' => $member->stage->value,
            'sequence_step_id' => 'legacy-followup',
        ]);

        // An ordinary inbound response must not be silenced by the cancelled
        // follow-up's paused row; the old delayed retry must not send a nudge.
        $this->reply($business, $member, self::P1);
        $this->assertSame('failed', $deferred->fresh()->status);
        $this->assertSame('followup_cancelled', $deferred->fresh()->failure_reason);
        $this->assertCount(1, $this->sentBodies());

        $this->runDeferred($deferred);
        $this->assertCount(1, $this->sentBodies());
    }

    public function test_pacing_is_off_on_a_synchronous_queue_so_nothing_is_stuck(): void
    {
        config(['queue.default' => 'sync']);

        $this->assertFalse(OutreachSendBudget::enabled());
    }
}
