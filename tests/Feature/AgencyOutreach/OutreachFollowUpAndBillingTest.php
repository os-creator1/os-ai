<?php

namespace Tests\Feature\AgencyOutreach;

use App\Enums\AgencyProspecting\AgencyProspectStatus;
use App\Jobs\Outreach\OutreachFollowUpJob;
use App\Jobs\Outreach\OutreachInitialSendJob;
use App\Library\AgencyOutreach\OutreachResumeService;
use App\Library\AgencyOutreach\OutreachScriptManager;
use App\Library\AgencyOutreach\OutreachTakeoverService;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectMessage;
use App\Models\Blacklists;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\AgencyOutreach\Concerns\BuildsOutreachFixtures;
use Tests\TestCase;

/**
 * Agency Outreach V1 — the opener (§15), the one follow-up and its sweeper (§11), and
 * what happens when the wallet refuses (§7/§12).
 */
class OutreachFollowUpAndBillingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsOutreachFixtures;

    private const M3 = 'Book a quick call here: https://cal.example.test/snap';

    private const FOLLOW_UP = 'Following up, you can book here: https://cal.example.test/snap';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOutreach();
    }

    /** @return array{0: Workspace, 1: Business, 2: AgencyProspectCampaignMember, 3: AgencyProspect, 4: AgencyProspectCampaign} */
    private function scenario(int $stage = 1, string $phone = '12025551000'): array
    {
        [, $business, $workspace] = $this->agency();
        $this->saveScript($workspace);

        $campaign = $this->managedCampaign($workspace);
        $prospect = $this->prospect($workspace, $phone);
        $member = $this->enroll($workspace, $campaign, $prospect, $stage);

        return [$workspace, $business, $member, $prospect, $campaign];
    }

    /** A member that has been sent the calendar link, with its follow-up due. */
    private function dueForFollowUp(int $stage = 4): array
    {
        $scenario = $this->scenario($stage);
        $scenario[2]->update(['followup_at' => now()->subHour(), 'booking_link_sent_at' => now()->subDay()]);

        return $scenario;
    }

    // ------------------------------------------------------------------ opener

    public function test_the_opener_is_rendered_through_the_canonical_engine_and_sent_once(): void
    {
        [$workspace, $business, $member] = $this->scenario();

        OutreachInitialSendJob::dispatch($member->id);
        OutreachInitialSendJob::dispatch($member->id);

        $this->assertSame(['Hi Dana, Snap Booth Co here.'], $this->sentBodies());

        $row = $this->ledger($member, 'outbound')[0];
        $this->assertSame('outreach:opener:' . $member->id, $row->operation_key);
        $this->assertSame(['opener', 'initial', 'sent'], [$row->source, $row->purpose, $row->status]);
        $this->assertSame([1, 1], [(int) $row->stage_from, (int) $row->stage_to]);

        $fresh = $this->member($member);
        $this->assertSame(1, $fresh->stage->value, 'The opener starts the conversation; the first reply moves the stage.');
        $this->assertNotNull($fresh->last_outbound_at);
        $this->assertNotNull($fresh->chat_box_id);
        $this->assertSame($business->id, ChatBox::query()->findOrFail($fresh->chat_box_id)->business_id);
    }

    public function test_the_opener_respects_every_gate(): void
    {
        [$workspace, $business, $member, $prospect, $campaign] = $this->scenario();
        $campaign->update(['status' => 'paused']);
        OutreachInitialSendJob::dispatch($member->id);

        $campaign->update(['status' => 'active', 'sending_mode' => 'channel']);
        OutreachInitialSendJob::dispatch($member->id);

        $campaign->update(['sending_mode' => 'managed']);
        Blacklists::create(['user_id' => $business->customer_id, 'business_id' => $business->id, 'number' => $prospect->phone, 'reason' => 'manual']);
        OutreachInitialSendJob::dispatch($member->id);

        $this->assertCount(0, $this->fakeAdapter->sentRequests);
        $this->assertCount(0, $this->ledger($member));
    }

    // ------------------------------------------------------------------ follow-up

    public function test_the_follow_up_is_scheduled_after_message_3_with_the_configured_delay(): void
    {
        [$workspace, $business, $member] = $this->scenario(3);
        OutreachScriptManager::save($workspace, ['follow_up_delay_hours' => 6]);

        $this->inbound($business, 'sounds good');

        $this->assertSame([self::M3], $this->sentBodies());
        $fresh = $this->member($member);
        $this->assertEqualsWithDelta(6 * 3600, $fresh->followup_at->timestamp - now()->timestamp, 5);
        $this->assertNull($fresh->followup_sent_at);
    }

    public function test_a_follow_up_job_is_dispatched_delayed_when_message_3_goes_out(): void
    {
        [$workspace, $business, $member] = $this->scenario(3);
        $this->inbound($business, 'sounds good', '12025551000', null, false);
        $inbound = null;

        Queue::fake();
        $this->deliverInbound($business, '12025551000', 'operation:9001');
        Queue::assertPushed(\App\Jobs\Outreach\OutreachRespondJob::class);

        $inbound = $this->ledger($member, 'inbound')[0];
        app()->call([new \App\Jobs\Outreach\OutreachRespondJob($member->id, $inbound->id), 'handle']);

        Queue::assertPushed(OutreachFollowUpJob::class, fn (OutreachFollowUpJob $job): bool => $job->delay !== null);
        Queue::assertPushed(OutreachFollowUpJob::class, 1);
        $this->assertSame([self::M3], $this->sentBodies());
    }

    public function test_the_follow_up_is_sent_exactly_once(): void
    {
        [$workspace, $business, $member] = $this->dueForFollowUp();

        OutreachFollowUpJob::dispatch($member->id);
        OutreachFollowUpJob::dispatch($member->id);
        Artisan::call('outreach:dispatch-due-followups');

        $this->assertSame([self::FOLLOW_UP], $this->sentBodies());

        $row = $this->ledger($member, 'outbound')[0];
        $this->assertSame('outreach:followup:' . $member->id, $row->operation_key);
        $this->assertSame(['followup', 'followup', 'sent'], [$row->source, $row->purpose, $row->status]);
        $this->assertSame([4, 4], [(int) $row->stage_from, (int) $row->stage_to]);
        $this->assertNotNull($this->member($member)->followup_sent_at);
        $this->assertSame(4, $this->member($member)->stage->value);
    }

    public function test_the_follow_up_is_not_sent_before_it_is_due(): void
    {
        [$workspace, $business, $member] = $this->dueForFollowUp();
        $member->update(['followup_at' => now()->addHours(5)]);

        OutreachFollowUpJob::dispatch($member->id);

        $this->assertCount(0, $this->fakeAdapter->sentRequests);
        $this->assertNull($this->member($member)->followup_cancelled_at);
    }

    public function test_the_follow_up_is_not_sent_when_booked_rejected_replied_or_opted_out(): void
    {
        $closers = [
            'booked' => fn (AgencyProspectCampaignMember $m, AgencyProspect $p) => $m->update(['stage' => 6]),
            'rejected' => fn (AgencyProspectCampaignMember $m, AgencyProspect $p) => [$m->update(['stage' => 99]), $p->update(['status' => 'stopped', 'stop_reason' => 'rejected'])],
            'replied' => fn (AgencyProspectCampaignMember $m) => $m->update(['last_inbound_at' => now()->subMinutes(30)]),
            'opted out' => fn (AgencyProspectCampaignMember $m, AgencyProspect $p, Business $b) => [
                Blacklists::create(['user_id' => $b->customer_id, 'business_id' => $b->id, 'number' => $p->phone, 'reason' => 'x']),
            ],
            'switched off' => fn (AgencyProspectCampaignMember $m, AgencyProspect $p, Business $b, Workspace $w) => OutreachScriptManager::save($w, ['followup_enabled' => false]),
            'campaign paused' => fn (AgencyProspectCampaignMember $m, AgencyProspect $p, Business $b, Workspace $w, AgencyProspectCampaign $c) => $c->update(['status' => 'paused']),
        ];

        foreach ($closers as $label => $close) {
            $this->fakeAdapter->sentRequests = [];
            [$workspace, $business, $member, $prospect, $campaign] = $this->dueForFollowUp();

            $close($member, $prospect, $business, $workspace, $campaign);
            OutreachFollowUpJob::dispatch($member->id);

            $this->assertCount(0, $this->fakeAdapter->sentRequests, $label);
            $this->assertCount(0, $this->ledger($member, 'outbound'), $label);
        }
    }

    public function test_a_reply_after_the_link_cancels_the_pending_follow_up(): void
    {
        [$workspace, $business, $member] = $this->dueForFollowUp();

        $this->inbound($business, 'thanks, will book soon');
        $this->assertNotNull($this->member($member)->followup_cancelled_at, 'The inbound listener cancels the nudge.');

        OutreachFollowUpJob::dispatch($member->id);
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
    }

    public function test_the_sweeper_recovers_a_follow_up_whose_job_was_lost(): void
    {
        [$workspace, $business, $member] = $this->dueForFollowUp();
        [, , $future] = $this->scenario(4, '12025557001');
        $future->update(['followup_at' => now()->addHours(3)]);
        [, , $sent] = $this->scenario(4, '12025557002');
        $sent->update(['followup_at' => now()->subHour(), 'followup_sent_at' => now()]);
        [, , $cancelled] = $this->scenario(4, '12025557003');
        $cancelled->update(['followup_at' => now()->subHour(), 'followup_cancelled_at' => now()]);
        [, , $channel, , $channelCampaign] = $this->scenario(4, '12025557004');
        $channel->update(['followup_at' => now()->subHour()]);
        $channelCampaign->update(['sending_mode' => 'channel']);

        Queue::fake();
        Artisan::call('outreach:dispatch-due-followups');

        Queue::assertPushed(OutreachFollowUpJob::class, 1);
        Queue::assertPushed(OutreachFollowUpJob::class, fn (OutreachFollowUpJob $job): bool => (new \ReflectionProperty($job, 'campaignMemberId'))->getValue($job) === $member->id);
        Queue::assertNotPushed(\App\Jobs\Outreach\OutreachRespondJob::class);

    }

    public function test_the_follow_up_command_is_scheduled_every_five_minutes(): void
    {
        Artisan::call('schedule:list');
        $line = collect(explode("
", Artisan::output()))->first(fn (string $l): bool => str_contains($l, 'outreach:dispatch-due-followups'));

        $this->assertNotNull($line, 'The sweeper is on the schedule.');
        $this->assertStringContainsString('*/5', $line);
        $this->assertStringContainsString('outreach:dispatch-due-followups', $line);
    }

    // ------------------------------------------------------------------ balance

    public function test_insufficient_balance_pauses_the_send_not_fails_it_and_resume_sends_it_once(): void
    {
        [$workspace, $business, $member] = $this->scenario();
        $this->fundWallet($business, 0);
        $this->priceTransport();

        $this->inbound($business, 'yes sure');

        $this->assertCount(0, $this->fakeAdapter->sentRequests, 'The wallet refused before any provider contact.');

        $row = $this->ledger($member, 'outbound')[0];
        $this->assertSame('paused', $row->status);
        $this->assertSame('insufficient_balance', $row->failure_reason);
        $this->assertSame(1, $this->member($member)->stage->value, 'The member keeps its stage.');
        $this->assertSame('insufficient_balance', $this->ledger($member, 'inbound')[0]->failure_reason);

        // Still broke: nothing happens, nothing is lost.
        $this->assertSame(0, app(OutreachResumeService::class)->resumePausedSends($workspace));
        $this->assertSame('paused', $row->fresh()->status);

        // Funded: the SAME message goes out under the SAME key, exactly once.
        $this->fundWallet($business, 1_000_000);
        $this->assertSame(1, app(OutreachResumeService::class)->resumePausedSends($workspace));
        $this->assertSame(0, app(OutreachResumeService::class)->resumePausedSends($workspace));

        $this->assertCount(1, $this->fakeAdapter->sentRequests);
        $sent = $row->fresh();
        $this->assertSame('sent', $sent->status);
        $this->assertNull($sent->failure_reason);
        $this->assertNotNull($sent->provider_message_id);
        $this->assertSame(2, $this->member($member)->stage->value, 'The stage moves only when the message actually goes out.');
        $this->assertSame(1, DB::table('business_messaging_operations')->where('operation_key', $row->operation_key)->count());
        $this->assertSame(1, DB::table('business_usage_reservations')->where('status', 'committed')->count(), 'Charged once.');
    }

    public function test_a_paused_send_is_closed_not_sent_when_the_prospect_opted_out_meanwhile(): void
    {
        [$workspace, $business, $member, $prospect] = $this->scenario();
        $this->fundWallet($business, 0);
        $this->priceTransport();

        $this->inbound($business, 'yes sure');
        $this->assertSame('paused', $this->ledger($member, 'outbound')[0]->status);

        $this->fundWallet($business, 1_000_000);
        Blacklists::create(['user_id' => $business->customer_id, 'business_id' => $business->id, 'number' => $prospect->phone, 'reason' => 'x']);

        $this->assertSame(0, app(OutreachResumeService::class)->resumePausedSends($workspace));
        $this->assertCount(0, $this->fakeAdapter->sentRequests);

        $row = $this->ledger($member, 'outbound')[0];
        $this->assertSame(['failed', 'opted_out'], [$row->status, $row->failure_reason]);
    }

    public function test_a_paused_send_stays_paused_while_the_ai_is_held_and_goes_out_after_resume(): void
    {
        [$workspace, $business, $member] = $this->scenario();
        $this->fundWallet($business, 0);
        $this->priceTransport();
        $this->inbound($business, 'yes sure');

        $owner = User::query()->findOrFail($business->customer_id);
        app(OutreachTakeoverService::class)->pause($member, $owner);
        $this->fundWallet($business, 1_000_000);

        $this->assertSame(0, app(OutreachResumeService::class)->resumePausedSends($workspace));
        $this->assertSame('paused', $this->ledger($member, 'outbound')[0]->status);

        app(OutreachTakeoverService::class)->resume($member, $owner);
        $this->assertSame(1, app(OutreachResumeService::class)->resumePausedSends($workspace));
    }

    public function test_a_priced_wallet_with_funds_bills_one_segment_per_message(): void
    {
        [$workspace, $business, $member] = $this->scenario();
        $this->fundWallet($business, 1_000_000);
        $this->priceTransport(10_000);

        $this->inbound($business, 'yes sure');

        $this->assertCount(1, $this->fakeAdapter->sentRequests);
        $balance = (string) DB::table('business_usage_wallets')->where('business_id', $business->id)->value('available_balance_micro');
        $this->assertLessThan(1_000_000, (int) $balance);
        $this->assertSame('sent', $this->ledger($member, 'outbound')[0]->status);
    }

    public function test_a_provider_rejection_is_a_failed_ledger_row_and_the_stage_does_not_move(): void
    {
        [$workspace, $business, $member] = $this->scenario();
        $this->fakeAdapter->rejections['*'] = \App\Enums\Messaging\ProviderErrorCategory::Terminal;

        $this->inbound($business, 'yes sure');

        $row = $this->ledger($member, 'outbound')[0];
        $this->assertSame('failed', $row->status);
        $this->assertNotNull($row->failure_reason);
        $this->assertSame(1, $this->member($member)->stage->value);
        $this->assertSame(AgencyProspectStatus::Active, $member->prospect->fresh()->status);
    }
}
