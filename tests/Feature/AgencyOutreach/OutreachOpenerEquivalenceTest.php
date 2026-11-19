<?php

namespace Tests\Feature\AgencyOutreach;

use App\Jobs\Outreach\OutreachInitialSendJob;
use App\Jobs\Outreach\OutreachRespondJob;
use App\Library\AgencyOutreach\OutreachCampaignDiagnostics;
use App\Library\AgencyOutreach\OutreachScriptManager;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectMessage;
use App\Models\Blacklists;
use App\Models\Business;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AgencyOutreach\Concerns\BuildsOutreachFixtures;
use Tests\TestCase;

/**
 * A campaign opener that IS the first scripted message counts as delivered, so the first
 * reply moves the conversation on instead of being blocked as a duplicate of itself.
 * Telnyx is the fake adapter throughout: no real SMS is ever sent.
 */
class OutreachOpenerEquivalenceTest extends TestCase
{
    use RefreshDatabase;
    use BuildsOutreachFixtures;

    private const MESSAGE_1 = 'Hi, {{agency_name}} here. We book photo booths for local venues and you only pay at 9% when we deliver. Open to hearing more?';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOutreach();
    }

    /** @return array{0: Workspace, 1: Business, 2: AgencyProspectCampaignMember, 3: AgencyProspect, 4: AgencyProspectCampaign} */
    private function started(?string $opener = self::MESSAGE_1, bool $sendOpener = true, array $script = []): array
    {
        [, $business, $workspace] = $this->agency();
        $this->saveScript($workspace, $script);
        $campaign = $this->managedCampaign($workspace, ['opening_message' => $opener]);
        $prospect = $this->prospect($workspace);
        $member = $this->enroll($workspace, $campaign, $prospect, 1);

        if ($sendOpener) {
            OutreachInitialSendJob::dispatchSync($member->id);
        }

        return [$workspace, $business, $member, $prospect, $campaign];
    }

    private function sequence(Workspace $workspace, array $steps): void
    {
        OutreachScriptManager::save($workspace, ['sequence_steps' => $steps]);
    }

    private function step(string $id, string $body, bool $enabled = true): array
    {
        return ['id' => $id, 'body' => $body, 'advance_on' => 'reply', 'enabled' => $enabled];
    }

    public function test_opener_equal_to_message_1_means_the_first_reply_gets_message_2_not_a_duplicate(): void
    {
        [, $business, $member] = $this->started();
        $this->assertCount(1, $this->sentBodies(), 'The opener went out once.');

        $this->inbound($business, 'sure');

        $this->assertSame('Great. Can we do a quick call about it?', $this->sentBodies()[1]);
        $this->assertCount(2, $this->sentBodies());
        $fresh = $this->member($member);
        $this->assertSame(['legacy-1', 'legacy-2'], $fresh->sequence_sent_ids);
        $this->assertSame(3, $fresh->stage->value);

        $this->inbound($business, 'yes');
        $this->assertStringContainsString('https://cal.example.test/snap', $this->sentBodies()[2]);
        $this->assertCount(3, $this->sentBodies());
    }

    public function test_an_opener_that_differs_from_message_1_keeps_the_normal_sequence_and_does_not_skip_message_1(): void
    {
        [, $business, $member] = $this->started('Hello! Quick question about your venue.');

        $this->inbound($business, 'ok');

        $this->assertStringStartsWith('Hi, Snap Booth Co here.', $this->sentBodies()[1]);
        $this->assertSame(['legacy-1'], $this->member($member)->sequence_sent_ids);
        $this->assertSame(2, $this->member($member)->stage->value);
    }

    public function test_whitespace_differences_still_count_as_the_same_message(): void
    {
        [, $business] = $this->started("  Hi, {{agency_name}} here.   We book photo booths for local venues and you only pay at 9% when we deliver.\nOpen to hearing more? ");

        $this->inbound($business, 'ok');

        $this->assertSame('Great. Can we do a quick call about it?', $this->sentBodies()[1]);
    }

    public function test_an_opener_that_was_never_delivered_does_not_skip_message_1(): void
    {
        [, $business] = $this->started(self::MESSAGE_1, sendOpener: false);

        $this->inbound($business, 'ok');

        $this->assertStringStartsWith('Hi, Snap Booth Co here.', $this->sentBodies()[0]);
        $this->assertCount(1, $this->sentBodies());
    }

    public function test_a_failed_opener_row_is_not_treated_as_delivered(): void
    {
        [, $business, $member] = $this->started(self::MESSAGE_1);
        AgencyProspectMessage::where('operation_key', 'outreach:opener:' . $member->id)->update(['status' => 'failed']);

        $this->inbound($business, 'ok');

        $this->assertStringStartsWith('Hi, Snap Booth Co here.', $this->sentBodies()[1]);
    }

    public function test_an_existing_campaign_with_the_mismatch_progresses_on_a_new_inbound_and_leaves_history_untouched(): void
    {
        [, $business, $member] = $this->started();

        // The production state: an earlier reply was swallowed as a duplicate and marked handled.
        $old = AgencyProspectMessage::create([
            'workspace_id' => $member->workspace_id, 'campaign_member_id' => $member->id, 'direction' => 'inbound',
            'operation_key' => 'outreach:in:historic', 'body' => 'hey, no', 'status' => 'handled',
            'failure_reason' => 'duplicate_of_last_outbound', 'received_at' => now()->subDay(),
        ]);

        $this->assertSame(1, count($this->sentBodies()), 'Nothing is replayed.');

        $this->inbound($business, 'who is this?');

        $this->assertCount(2, $this->sentBodies());
        $this->assertNotSame($this->sentBodies()[0], $this->sentBodies()[1]);
        $fresh = $old->fresh();
        $this->assertSame('handled', $fresh->status);
        $this->assertSame('duplicate_of_last_outbound', $fresh->failure_reason);
        $this->assertNull(AgencyProspectMessage::where('operation_key', 'outreach:reply:' . $old->id)->first());
    }

    public function test_custom_sequence_with_a_disabled_step_follows_the_enabled_order_and_stable_ids(): void
    {
        [$workspace, $business, $member] = $this->started('Intro A.');
        $this->sequence($workspace, [$this->step('a', 'Intro A.'), $this->step('b', 'Skipped B.', false), $this->step('c', 'Next C.'), $this->step('d', 'Last D.')]);

        $this->inbound($business, 'ok');
        $this->inbound($business, 'ok');
        $this->inbound($business, 'ok');

        $this->assertSame(['Intro A.', 'Next C.', 'Last D.'], $this->sentBodies());
        $this->assertSame(['a', 'c', 'd'], $this->member($member)->sequence_sent_ids);
    }

    public function test_an_opener_matching_a_later_or_disabled_step_is_not_treated_as_the_first_step(): void
    {
        [$workspace, $business] = $this->started('Second text.');
        $this->sequence($workspace, [$this->step('a', 'Disabled one.', false), $this->step('b', 'First enabled.'), $this->step('c', 'Second text.')]);

        $this->inbound($business, 'ok');

        $this->assertSame('First enabled.', $this->sentBodies()[1]);
    }

    public function test_a_polite_no_after_an_opener_that_is_message_1_ends_the_conversation_without_a_text(): void
    {
        [, $business, $member, $prospect] = $this->started();

        $this->inbound($business, 'no thanks');

        $this->assertCount(1, $this->sentBodies());
        $this->assertSame('stopped', $prospect->fresh()->status->value);
        $this->assertSame(99, $this->member($member)->stage->value);
        $this->assertSame('rejected', AgencyProspectMessage::where('direction', 'inbound')->first()->failure_reason);
    }

    public function test_hard_rejection_and_explicit_opt_out_send_nothing_and_opt_out_is_blacklisted(): void
    {
        [, $business, $member, $prospect] = $this->started();
        $this->inbound($business, 'not interested');
        $this->assertCount(1, $this->sentBodies());
        $this->assertSame(99, $this->member($member)->stage->value);
        $this->assertSame(0, Blacklists::where('business_id', $business->id)->count());

        [, $business2, $member2, $prospect2] = $this->started();
        $this->inbound($business2, 'STOP');
        $this->assertSame('opt_out', $prospect2->fresh()->stop_reason);
        $this->assertSame(1, Blacklists::where('business_id', $business2->id)->count());
        $this->assertSame(99, $this->member($member2)->stage->value);
    }

    public function test_an_ambiguous_reply_uses_the_configured_script_and_the_campaign_warns_about_the_unsuitable_next_step(): void
    {
        [$workspace, $business, , , $campaign] = $this->started();

        $diagnostics = app(OutreachCampaignDiagnostics::class)->forCampaign($workspace, $campaign);
        $this->assertTrue($diagnostics['opener_duplicates_first_step']);
        $this->assertSame('Message 2', $diagnostics['first_reply']['label']);
        $this->assertCount(2, $diagnostics['warnings'], 'Duplicate-opener warning and a "Great." next-step warning.');

        $this->inbound($business, 'hey, no');
        $this->assertSame('Great. Can we do a quick call about it?', $this->sentBodies()[1], 'Deterministic: the configured script decides; the warning tells the owner to reword it.');

        // A neutral Message 2 clears the warning.
        $this->saveScript($workspace, ['message_2' => 'Would a quick call make sense?']);
        $this->assertCount(1, app(OutreachCampaignDiagnostics::class)->forCampaign($workspace, $campaign->fresh())['warnings']);
    }

    public function test_diagnostics_explain_why_a_reply_got_no_text_and_a_different_opener_has_no_duplicate_warning(): void
    {
        [$workspace, , $member, , $campaign] = $this->started('A different opener.');
        AgencyProspectMessage::create([
            'workspace_id' => $workspace->id, 'campaign_member_id' => $member->id, 'direction' => 'inbound', 'operation_key' => 'outreach:in:x',
            'body' => 'hey, no', 'status' => 'handled', 'failure_reason' => 'duplicate_of_last_outbound', 'received_at' => now(),
        ]);

        $d = app(OutreachCampaignDiagnostics::class)->forCampaign($workspace, $campaign);

        $this->assertFalse($d['opener_duplicates_first_step']);
        $this->assertSame('Message 1', $d['first_reply']['label']);
        $this->assertSame(1, $d['silent_replies']->count());
        $this->assertStringContainsString('identical', OutreachCampaignDiagnostics::reasonLabel('duplicate_of_last_outbound'));
    }

    public function test_a_redelivered_webhook_and_a_retried_job_never_send_twice_and_nothing_extra_follows(): void
    {
        [, $business, $member] = $this->started();

        $key = $this->inbound($business, 'sure');
        $this->deliverInbound($business, '12025551000', $key);
        $this->deliverInbound($business, '12025551000', $key);

        $inbound = AgencyProspectMessage::where('direction', 'inbound')->first();
        (new OutreachRespondJob($member->id, $inbound->id))->handle(...$this->jobDeps());
        dispatch_sync(new OutreachRespondJob($member->id, $inbound->id));

        $this->assertCount(2, $this->sentBodies(), 'Opener + exactly one reply.');
        $this->assertSame(1, AgencyProspectMessage::where('operation_key', 'outreach:opener:' . $member->id)->count());
        $this->assertSame(1, AgencyProspectMessage::where('operation_key', 'outreach:reply:' . $inbound->id)->count());

        // No inbound, no further text.
        $this->assertCount(2, $this->sentBodies());
    }

    public function test_the_opener_is_never_sent_twice(): void
    {
        [, , $member] = $this->started();
        OutreachInitialSendJob::dispatchSync($member->id);
        OutreachInitialSendJob::dispatchSync($member->id);

        $this->assertCount(1, $this->sentBodies());
    }

    public function test_no_text_goes_out_when_the_owner_already_answered_by_hand(): void
    {
        [, $business, $member] = $this->started();

        $key = $this->inbound($business, 'sure', deliver: false);
        $this->ownerRepliesByHand($business, '12025551000', 'Hi, it is the owner, call me.');
        $this->deliverInbound($business, '12025551000', $key);

        $this->assertCount(1, $this->sentBodies());
        $this->assertSame('manual_reply_sent', AgencyProspectMessage::where('direction', 'inbound')->first()->failure_reason);
    }

    private function jobDeps(): array
    {
        return [
            app(\App\Library\AgencyOutreach\OutreachEligibility::class),
            app(\App\Library\AgencyOutreach\OutreachStopService::class),
            app(\App\Library\AgencyOutreach\OutreachReplyComposer::class),
            app(\App\Library\AgencyOutreach\OutreachSendPipeline::class),
            app(\App\Library\AgencyOutreach\OutreachInboundLedger::class),
        ];
    }
}
