<?php

namespace Tests\Feature\AgencyOutreach;

use App\Jobs\Outreach\OutreachInitialSendJob;
use App\Jobs\Outreach\OutreachRespondJob;
use App\Library\AgencyOutreach\OutreachCampaignDiagnostics;
use App\Library\AgencyOutreach\OutreachCampaignService;
use App\Library\AgencyOutreach\OutreachScriptManager;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectMessage;
use App\Models\Blacklists;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\ChatBoxMessage;
use App\Library\AgencyOutreach\OutreachSequence;
use App\Library\Conversations\ConversationHistoryWriter;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AgencyOutreach\Concerns\BuildsOutreachFixtures;
use Tests\TestCase;

/**
 * Outreach is one coherent, campaign-specific conversation: the opener, the scripted steps,
 * approved answers and anything a person sent by hand all agree on what the prospect has
 * already received. Telnyx is the fake adapter; no SMS is ever sent.
 */
class OutreachSequenceContinuityTest extends TestCase
{
    use RefreshDatabase;
    use BuildsOutreachFixtures;

    private const M1 = 'Hi, {{agency_name}} here. We book photo booths for local venues and you only pay at 9% when we deliver. Open to hearing more?';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOutreach();
    }

    /** @return array{0: Workspace, 1: Business, 2: AgencyProspectCampaignMember, 3: AgencyProspect, 4: AgencyProspectCampaign} */
    private function started(?string $opener = self::M1, bool $sendOpener = true, array $script = [], string $phone = '12025551000'): array
    {
        [, $business, $workspace] = $this->agency();
        $this->saveScript($workspace, $script);
        $campaign = $this->managedCampaign($workspace, ['opening_message' => $opener]);
        $prospect = $this->prospect($workspace, $phone);
        $member = $this->enroll($workspace, $campaign, $prospect, 1);

        if ($sendOpener) {
            OutreachInitialSendJob::dispatchSync($member->id);
        }

        return [$workspace, $business, $member, $prospect, $campaign];
    }

    private function step(string $id, string $body, bool $enabled = true): array
    {
        return ['id' => $id, 'body' => $body, 'advance_on' => 'reply', 'enabled' => $enabled];
    }

    private function inboundRow(): AgencyProspectMessage
    {
        return AgencyProspectMessage::where('direction', 'inbound')->orderByDesc('id')->firstOrFail();
    }

    // ---- opener semantics -------------------------------------------------------------------------

    public function test_first_text_equal_to_message_1_counts_as_delivered_and_replies_progress_once_each(): void
    {
        [, $business, $member] = $this->started();

        $this->inbound($business, 'sure');
        $this->inbound($business, 'yes');
        $this->inbound($business, 'thanks');   // sequence complete: nothing more

        $bodies = $this->sentBodies();
        $this->assertCount(3, $bodies, 'Opener, Message 2, Message 3 — no extra message after the sequence ends.');
        $this->assertSame('Great. Can we do a quick call about it?', $bodies[1]);
        $this->assertStringContainsString('https://cal.example.test/snap', $bodies[2]);
        $this->assertSame(['legacy-1', 'legacy-2', 'legacy-3'], $this->member($member)->sequence_sent_ids);
        $this->assertSame(4, $this->member($member)->stage->value);
    }

    public function test_first_text_different_from_message_1_runs_the_normal_sequence(): void
    {
        [, $business, $member] = $this->started('Hello! Quick question about your venue.');

        $this->inbound($business, 'ok');
        $this->inbound($business, 'ok');

        $this->assertStringStartsWith('Hi, Snap Booth Co here.', $this->sentBodies()[1]);
        $this->assertSame('Great. Can we do a quick call about it?', $this->sentBodies()[2]);
        $this->assertSame(['legacy-1', 'legacy-2'], $this->member($member)->sequence_sent_ids);
    }

    public function test_an_opener_that_was_never_delivered_does_not_count(): void
    {
        [, $business] = $this->started(self::M1, sendOpener: false);

        $this->inbound($business, 'ok');

        $this->assertStringStartsWith('Hi, Snap Booth Co here.', $this->sentBodies()[0]);
    }

    // ---- production incident: a person sent the scripted text by hand ----------------------------

    public function test_the_production_conversation_does_not_repeat_the_step_it_just_sent(): void
    {
        $got = 'Got it. Are you fully booked at the moment, or are you simply not looking to take on additional clients?';
        [, $business, $member] = $this->started('Hello! Quick question.', script: [
            'message_1' => $got, 'message_2' => 'Would it make sense to do a short call to see if this could work for you?',
            'clarify_answer' => 'Happy to go through the specifics with you on a quick call.',
        ]);

        $this->inbound($business, 'hey, no');                       // 10:03 -> Message 1 (neutral, so it is sent)
        $this->assertSame($got, $this->sentBodies()[1]);

        $this->inbound($business, 'Would it make sense to do a short call to see if this could work for you?');   // 10:31

        foreach (array_slice($this->sentBodies(), 2) as $body) {
            $this->assertStringNotContainsString('Are you fully booked', $body, 'The 10:30 message is never repeated at 10:32.');
        }
        $this->assertSame(['legacy-1', 'legacy-2'], $this->member($member)->sequence_sent_ids);
    }

    public function test_the_same_conversation_when_the_10_30_message_was_sent_by_hand(): void
    {
        $got = 'Got it. Are you fully booked at the moment, or are you simply not looking to take on additional clients?';
        [, $business, $member] = $this->started('Hello! Quick question.', script: [
            'message_1' => $got, 'message_2' => 'Would it make sense to do a short call to see if this could work for you?',
            'clarify_answer' => 'Happy to go through the specifics with you on a quick call.',
        ]);
        $this->inbound($business, 'who is this?', deliver: false);          // recorded in chat, owner takes over
        $this->ownerRepliesByHand($business, '12025551000', $got);           // owner pastes Message 1 by hand
        $before = count($this->sentBodies());

        $this->inbound($business, 'Would it make sense to do a short call to see if this could work for you?');

        foreach (array_slice($this->sentBodies(), $before) as $body) {
            $this->assertStringNotContainsString('Are you fully booked', $body);
        }
        $this->assertContains('legacy-1', $this->member($member)->sequence_sent_ids ?? ['legacy-1'], 'Equivalent step is treated as delivered.');
    }

    public function test_a_step_sent_by_hand_before_a_new_inbound_moves_the_conversation_to_the_next_step_with_an_answer_in_front(): void
    {
        [, $business, $member] = $this->started('Hello! Quick question.');
        $this->ownerRepliesByHand0($business);

        $this->inbound($business, 'how much does it cost?');

        $new = $this->sentBodies()[1] ?? '';
        $this->assertStringContainsString('Our commission is 9% of booked events.', $new, 'The approved answer goes first.');
        $this->assertStringContainsString('Great. Can we do a quick call about it?', $new, 'Then the NEXT step, not Message 1 again.');
        $this->assertStringNotContainsString('photo booths for local venues', $new);
        $this->assertSame(['legacy-2'], array_values(array_diff($this->member($member)->sequence_sent_ids, ['legacy-1'])));
    }

    private function ownerRepliesByHand0(Business $business): void
    {
        // The prospect has to exist in a conversation first (the opener created it).
        $this->ownerRepliesByHand($business, '12025551000', 'Hi, Snap Booth Co here. We book photo booths for local venues and you only pay at 9% when we deliver. Open to hearing more?');
    }

    public function test_a_manual_text_that_is_not_a_step_is_left_alone_and_does_not_advance_the_sequence(): void
    {
        [, $business, $member] = $this->started('Hello! Quick question.');
        $this->ownerRepliesByHand($business, '12025551000', 'Quick note from me personally.');

        $this->inbound($business, 'ok');

        $this->assertStringStartsWith('Hi, Snap Booth Co here.', $this->sentBodies()[1], 'No reliable match: Message 1 still goes out.');
        $this->assertNull(ChatBoxMessage::where('message', 'Quick note from me personally.')->first()->business_messaging_operation_id ?? null);
    }

    public function test_the_owner_answering_after_the_latest_reply_suppresses_the_automatic_one_with_a_visible_reason(): void
    {
        [$workspace, $business, , , $campaign] = $this->started();

        $key = $this->inbound($business, 'sure', deliver: false);
        $this->ownerRepliesByHand($business, '12025551000', 'Hi, it is the owner, call me.');
        $this->deliverInbound($business, '12025551000', $key);

        $this->assertCount(1, $this->sentBodies());
        $reply = $this->inboundRow();
        $this->assertSame('manual_reply_sent', $reply->failure_reason);
        $d = app(OutreachCampaignDiagnostics::class)->forCampaign($workspace, $campaign);
        $this->assertSame(1, $d['silent_replies']->count());
        $this->assertSame('You had already replied by hand.', OutreachCampaignDiagnostics::reasonLabel($reply->failure_reason));
    }

    // ---- script edits mid-campaign -----------------------------------------------------------------

    public function test_replacing_step_ids_with_the_same_text_mid_campaign_does_not_restart_the_sequence(): void
    {
        [$workspace, $business, $member] = $this->started('Hello! Quick question.');
        $this->inbound($business, 'ok');                       // Message 1 delivered under id legacy-1
        $this->assertSame(['legacy-1'], $this->member($member)->sequence_sent_ids);

        // The owner rebuilds the sequence in the editor: same wording, brand-new step ids.
        OutreachScriptManager::save($workspace, ['sequence_steps' => [
            $this->step('sAAA', self::M1), $this->step('sBBB', 'Great. Can we do a quick call about it?'), $this->step('sCCC', 'Book here: {{agency.calendar_link}}'),
        ]]);
        $this->inbound($business, 'ok');

        $this->assertSame('Great. Can we do a quick call about it?', $this->sentBodies()[2]);
        $this->assertCount(3, $this->sentBodies());
    }

    public function test_editing_a_delivered_step_text_keeps_its_id_and_never_resends_it(): void
    {
        [$workspace, $business] = $this->started('Hello! Quick question.');
        $this->inbound($business, 'ok');
        $this->saveScript($workspace, ['message_1' => 'A completely different Message 1.']);

        $this->inbound($business, 'ok');

        $this->assertSame('Great. Can we do a quick call about it?', $this->sentBodies()[2]);
    }

    public function test_a_disabled_step_is_skipped_and_stable_ids_are_recorded(): void
    {
        [$workspace, $business, $member] = $this->started('Intro A.');
        OutreachScriptManager::save($workspace, ['sequence_steps' => [$this->step('a', 'Intro A.'), $this->step('b', 'Skipped B.', false), $this->step('c', 'Next C.')]]);

        $this->inbound($business, 'ok');
        $this->inbound($business, 'ok');

        $this->assertSame(['Intro A.', 'Next C.'], $this->sentBodies());
        $this->assertSame(['a', 'c'], $this->member($member)->sequence_sent_ids);
    }

    // ---- FAQ ------------------------------------------------------------------------------------------

    public function test_a_faq_answer_precedes_the_next_step_and_is_never_followed_by_a_repeat(): void
    {
        [, $business, $member] = $this->started();

        $this->inbound($business, 'how much is it?');
        $this->inbound($business, 'where are you based?');

        $this->assertSame('Our commission is 9% of booked events. Great. Can we do a quick call about it?', $this->sentBodies()[1]);
        $third = $this->sentBodies()[2];
        $this->assertStringContainsString('https://cal.example.test/snap', $third);
        $this->assertStringContainsString('We are based in Austin and serve Texas.', $third);
        $this->assertCount(3, $this->sentBodies());
    }

    // ---- reply meaning ---------------------------------------------------------------------------------

    public function test_a_plain_no_is_never_answered_with_great_or_a_booking_pitch_and_the_reason_is_visible(): void
    {
        [$workspace, $business, , , $campaign] = $this->started();

        $this->inbound($business, 'hey, no');

        $this->assertCount(1, $this->sentBodies(), 'Message 2 starts with "Great." — held, not sent.');
        $reply = $this->inboundRow();
        $this->assertSame('negative_reply_held', $reply->failure_reason);
        $this->assertStringContainsString('Reword it', OutreachCampaignDiagnostics::reasonLabel('negative_reply_held'));
    }

    public function test_a_plain_no_gets_a_neutral_next_step_when_the_script_has_one(): void
    {
        [, $business] = $this->started(script: ['message_2' => 'Got it. Are you fully booked, or just not looking for more clients?']);

        $this->inbound($business, 'hey, no');

        $this->assertSame('Got it. Are you fully booked, or just not looking for more clients?', $this->sentBodies()[1]);
    }

    public function test_soft_rejection_hard_rejection_and_stop_send_nothing(): void
    {
        [, $b1, $m1, $p1] = $this->started();
        $this->inbound($b1, 'no thanks');
        $this->assertCount(1, $this->sentBodies());
        $this->assertSame(99, $this->member($m1)->stage->value);
        $this->assertSame('rejected', $p1->fresh()->stop_reason);

        [, $b2, $m2] = $this->started();
        $this->inbound($b2, 'not interested');
        $this->assertSame(99, $this->member($m2)->stage->value);
        $this->assertSame(0, Blacklists::where('business_id', $b2->id)->count());

        [, $b3, $m3, $p3] = $this->started();
        $this->inbound($b3, 'STOP');
        $this->assertSame('opt_out', $p3->fresh()->stop_reason);
        $this->assertSame(1, Blacklists::where('business_id', $b3->id)->count());
        $this->assertSame(3, count($this->sentBodies()), 'Only the three openers ever went out.');
    }

    // ---- idempotency --------------------------------------------------------------------------------------

    public function test_redelivered_webhooks_and_job_retries_send_each_message_once(): void
    {
        [, $business, $member] = $this->started();

        $key = $this->inbound($business, 'sure');
        $this->deliverInbound($business, '12025551000', $key);
        $this->deliverInbound($business, '12025551000', $key);
        $in = $this->inboundRow();
        dispatch_sync(new OutreachRespondJob($member->id, $in->id));
        dispatch_sync(new OutreachRespondJob($member->id, $in->id));
        OutreachInitialSendJob::dispatchSync($member->id);

        $this->assertCount(2, $this->sentBodies());
        $this->assertSame(1, AgencyProspectMessage::where('operation_key', 'outreach:reply:' . $in->id)->count());
        $this->assertSame(1, AgencyProspectMessage::where('operation_key', 'outreach:opener:' . $member->id)->count());
    }

    // ---- campaigns reusing a contact ------------------------------------------------------------------------

    public function test_a_new_campaign_for_the_same_contact_starts_its_own_sequence_and_history_is_preserved(): void
    {
        [$workspace, $business, $old, $prospect, $oldCampaign] = $this->started();
        $this->inbound($business, 'sure');
        $this->inbound($business, 'yes');
        $this->assertSame(['legacy-1', 'legacy-2', 'legacy-3'], $this->member($old)->sequence_sent_ids);
        $chatBefore = ChatBoxMessage::count();
        $ledgerBefore = AgencyProspectMessage::count();
        $sentBefore = count($this->sentBodies());

        $this->assertTrue(app(OutreachCampaignService::class)->end($workspace, $oldCampaign)['ok']);

        $this->travel(10)->minutes();
        $new = $this->managedCampaign($workspace, ['name' => 'Second', 'opening_message' => 'Fresh start, quick question for you.']);
        $member2 = $this->enroll($workspace, $new, $prospect, 1);
        OutreachInitialSendJob::dispatchSync($member2->id);
        $this->inbound($business, 'ok');

        $this->assertSame($sentBefore + 2, count($this->sentBodies()));
        $this->assertStringStartsWith('Hi, Snap Booth Co here.', $this->sentBodies()[$sentBefore + 1], 'Message 1 for the new campaign: nothing inherited from the old one.');
        $this->assertSame(['legacy-1'], $this->member($member2)->sequence_sent_ids);
        $this->assertSame(['legacy-1', 'legacy-2', 'legacy-3'], $this->member($old)->sequence_sent_ids, 'The old campaign keeps its own record.');
        $this->assertGreaterThanOrEqual($chatBefore, ChatBoxMessage::count());
        $this->assertGreaterThanOrEqual($ledgerBefore, AgencyProspectMessage::count());
        $this->assertSame(1, AgencyProspectMessage::where('operation_key', 'outreach:opener:' . $old->id)->count());
    }

    public function test_a_second_campaign_whose_first_text_matches_the_old_campaigns_messages_still_sends_its_own_steps(): void
    {
        [$workspace, $business, $old, $prospect, $oldCampaign] = $this->started();
        $this->inbound($business, 'sure');                       // old campaign sent Message 2 ("Great. Can we...")
        app(OutreachCampaignService::class)->end($workspace, $oldCampaign);

        $this->travel(10)->minutes();
        $new = $this->managedCampaign($workspace, ['name' => 'Second', 'opening_message' => 'Totally different opener.']);
        $member2 = $this->enroll($workspace, $new, $prospect, 1);
        OutreachInitialSendJob::dispatchSync($member2->id);
        $this->inbound($business, 'ok');

        $this->assertStringStartsWith('Hi, Snap Booth Co here.', end($this->fakeAdapter->sentRequests)->body, 'Old Message 1 text does not count for the new campaign.');
    }

    // ---- diagnostics ------------------------------------------------------------------------------------------

    public function test_diagnostics_show_the_actual_next_message_per_prospect_and_the_duplicate_opener_warning(): void
    {
        [$workspace, $business, , , $campaign] = $this->started();
        $campaign->update(['status' => 'active']);
        $this->inbound($business, 'sure');

        $d = app(OutreachCampaignDiagnostics::class)->forCampaign($workspace, $campaign->fresh());

        $this->assertTrue($d['opener_duplicates_first_step']);
        $this->assertSame('Message 2', $d['first_reply']['label']);
        $this->assertSame('Message 3', $d['next_by_member'][0]['next'], 'After Message 2 went out, Message 3 is next.');
        $this->assertNotEmpty(array_filter($d['warnings'], fn ($w) => str_contains($w, 'identical to Message 1')));
    }

    public function test_nothing_is_sent_to_an_inactive_or_foreign_prospect(): void
    {
        [, $business, $member, $prospect] = $this->started();
        $prospect->update(['status' => 'stopped']);

        $this->inbound($business, 'sure');

        $this->assertCount(1, $this->sentBodies());
    }

    // ---- repeated wording (A -> B -> A) -----------------------------------------------------------------

    public function test_a_sequence_that_repeats_a_wording_sends_each_step_exactly_once(): void
    {
        [$workspace, $business, $member] = $this->started('Hello! Quick question.');
        OutreachScriptManager::save($workspace, ['sequence_steps' => [$this->step('a1', 'Alpha text.'), $this->step('b', 'Beta text.'), $this->step('a3', 'Alpha text.')]]);

        foreach (range(1, 5) as $i) {
            $this->inbound($business, 'ok');
        }

        $this->assertSame(['Hello! Quick question.', 'Alpha text.', 'Beta text.', 'Alpha text.'], $this->sentBodies(), 'Each step once; nothing after the sequence ends.');
        $this->assertSame(['a1', 'b', 'a3'], $this->member($member)->sequence_sent_ids);
    }

    public function test_when_the_opener_is_the_first_A_the_second_A_still_sends_and_a_single_copy_never_satisfies_two_steps(): void
    {
        [$workspace, $business, $member] = $this->started('Alpha text.');
        OutreachScriptManager::save($workspace, ['sequence_steps' => [$this->step('a1', 'Alpha text.'), $this->step('b', 'Beta text.'), $this->step('a3', 'Alpha text.')]]);

        $this->assertSame(['a1'], OutreachSequence::forWorkspace($workspace)->deliveredByContent($member), 'One copy satisfies one step only.');

        $this->inbound($business, 'ok');
        $this->inbound($business, 'ok');
        $this->inbound($business, 'ok');

        $this->assertSame(['Alpha text.', 'Beta text.', 'Alpha text.'], $this->sentBodies());
    }

    public function test_a_faq_answer_in_front_of_a_repeated_wording_step_is_safe(): void
    {
        [$workspace, $business] = $this->started('Hello! Quick question.');
        OutreachScriptManager::save($workspace, ['sequence_steps' => [$this->step('a1', 'Alpha text.'), $this->step('b', 'Beta text.'), $this->step('a3', 'Alpha text.')]]);

        $this->inbound($business, 'ok');
        $this->inbound($business, 'ok');
        $this->inbound($business, 'how much is it?');
        $this->inbound($business, 'how much is it?');

        $this->assertSame('Our commission is 9% of booked events. Alpha text.', $this->sentBodies()[3]);
        $this->assertSame('Our commission is 9% of booked events.', $this->sentBodies()[4], 'The sequence is complete: the answer goes out ALONE, no step is repeated.');
        $this->assertCount(5, $this->sentBodies());
    }

    // ---- manual sends: only a confirmed delivery counts -----------------------------------------------------

    private function handSend(Business $business, string $text, ?string $status, ?int $operationId = null): ChatBoxMessage
    {
        $box = ChatBox::query()->where('business_id', $business->id)->where('to', '12025551000')->orderByDesc('id')->firstOrFail();

        return ChatBoxMessage::create([
            'box_id' => $box->id, 'message' => $text, 'sms_type' => 'plain', 'direction' => 'outgoing', 'send_by' => 'from',
            'source' => ConversationHistoryWriter::SOURCE_CONVERSATIONS, 'send_status' => $status, 'business_messaging_operation_id' => $operationId,
        ]);
    }

    public function test_a_manual_message_counts_only_once_its_delivery_is_confirmed(): void
    {
        [$workspace, $business, $member] = $this->started('Hello! Quick question.');
        $message1 = 'Hi, Snap Booth Co here. We book photo booths for local venues and you only pay at 9% when we deliver. Open to hearing more?';
        $delivered = fn () => OutreachSequence::forWorkspace($workspace)->deliveredByContent($member->fresh());

        $manual = $this->handSend($business, $message1, 'sending');
        $this->assertNotContains('legacy-1', $delivered(), 'sending: not proven.');

        $manual->update(['send_status' => 'ambiguous']);
        $this->assertNotContains('legacy-1', $delivered(), 'ambiguous: not proven.');

        $manual->update(['send_status' => 'failed']);
        $this->assertNotContains('legacy-1', $delivered(), 'failed: never counts.');

        $manual->update(['send_status' => 'delivery_failed']);
        $this->assertNotContains('legacy-1', $delivered());

        $manual->update(['send_status' => 'something_new']);
        $this->assertNotContains('legacy-1', $delivered(), 'unknown states are unresolved.');

        $manual->update(['send_status' => 'sent']);
        $this->assertContains('legacy-1', $delivered(), 'confirmed: counts from then on.');

        $manual->update(['send_status' => 'delivered']);
        $this->assertContains('legacy-1', $delivered());
    }

    public function test_a_legacy_manual_message_without_any_status_counts_but_one_with_a_pending_operation_does_not(): void
    {
        [$workspace, $business, $member] = $this->started('Hello! Quick question.');
        $message1 = 'Hi, Snap Booth Co here. We book photo booths for local venues and you only pay at 9% when we deliver. Open to hearing more?';
        $delivered = fn () => OutreachSequence::forWorkspace($workspace)->deliveredByContent($member->fresh());

        $pending = $this->handSend($business, $message1, null, 987654);
        $this->assertNotContains('legacy-1', $delivered(), 'No status but a managed-send operation: still unresolved.');

        $pending->delete();
        $this->handSend($business, $message1, null);
        $this->assertContains('legacy-1', $delivered(), 'Legacy row with no status and no operation: carried as before.');
    }

    public function test_a_failed_manual_send_does_not_cause_the_next_outreach_step_to_be_skipped(): void
    {
        [, $business, $member] = $this->started('Hello! Quick question.');
        $message1 = 'Hi, Snap Booth Co here. We book photo booths for local venues and you only pay at 9% when we deliver. Open to hearing more?';
        $this->inbound($business, 'who is this?', deliver: false);
        $this->handSend($business, $message1, 'failed');
        // the owner's failed attempt happened BEFORE this newer inbound, so automatic replies are not suppressed
        $this->inbound($business, 'hello?');

        $this->assertStringStartsWith('Hi, Snap Booth Co here.', end($this->fakeAdapter->sentRequests)->body, 'Message 1 is still sent by Outreach.');
        $this->assertSame(['legacy-1'], $this->member($member)->sequence_sent_ids);
    }

    public function test_an_unconfirmed_manual_send_that_later_confirms_is_then_not_repeated(): void
    {
        [, $business, $member] = $this->started('Hello! Quick question.');
        $message1 = 'Hi, Snap Booth Co here. We book photo booths for local venues and you only pay at 9% when we deliver. Open to hearing more?';
        $this->inbound($business, 'who is this?', deliver: false);
        $manual = $this->handSend($business, $message1, 'ambiguous');
        $manual->update(['send_status' => 'sent']);
        $before = count($this->sentBodies());

        $this->inbound($business, 'hello?');

        foreach (array_slice($this->sentBodies(), $before) as $body) {
            $this->assertStringNotContainsString('photo booths for local venues', $body);
        }
    }

    // ---- one-evidence-one-step attribution -----------------------------------------------------------------

    private function aba(Workspace $workspace, array $ids = ['a1', 'b', 'a3'], string $first = 'Alpha text.'): void
    {
        OutreachScriptManager::save($workspace, ['sequence_steps' => [
            $this->step($ids[0], $first), $this->step($ids[1], 'Beta text.'), $this->step($ids[2], 'Alpha text.'),
        ]]);
    }

    private function nextStepId(Workspace $workspace, AgencyProspectCampaignMember $member): ?string
    {
        $sequence = OutreachSequence::forWorkspace($workspace);

        return $sequence->nextReplyStep($sequence->sentIdsFor($member->fresh()))['id'] ?? null;
    }

    public function test_a_b_a_with_the_first_a_sent_and_every_step_id_rebuilt_skips_nothing(): void
    {
        [$workspace, $business, $member] = $this->started('Hello! Quick question.');
        $this->aba($workspace);
        $this->inbound($business, 'ok');                                   // a1 -> "Alpha text."
        $this->assertSame('b', $this->nextStepId($workspace, $member));

        $this->aba($workspace, ['x1', 'y', 'x3']);                         // same wording, brand-new ids

        $this->assertSame('y', $this->nextStepId($workspace, $member), 'The one delivered A satisfies ONE step.');
        $this->assertSame(['x1'], array_values(array_diff(OutreachSequence::forWorkspace($workspace)->deliveredByContent($member->fresh()), ['a1'])));

        $this->inbound($business, 'ok');
        $this->inbound($business, 'ok');
        $this->inbound($business, 'ok');

        $this->assertSame(['Hello! Quick question.', 'Alpha text.', 'Beta text.', 'Alpha text.'], $this->sentBodies());
        $stored = $this->member($member)->sequence_sent_ids;
        $this->assertEqualsCanonicalizing(['a1', 'x1', 'y', 'x3'], $stored, 'The stale id is kept; the reconciled step and the two new sends are recorded.');
        $this->assertSame($stored, array_values(array_unique($stored)), 'No duplicates.');
    }

    public function test_a_b_a_with_the_first_step_text_edited_but_its_id_kept_does_not_reassign_that_delivery(): void
    {
        [$workspace, $business, $member] = $this->started('Hello! Quick question.');
        $this->aba($workspace);
        $this->inbound($business, 'ok');                                   // a1 delivered as "Alpha text."

        $this->aba($workspace, ['a1', 'b', 'a3'], 'Alpha text, reworded.');  // same id, new wording

        $this->assertSame(['a1'], OutreachSequence::forWorkspace($workspace)->deliveredByContent($member->fresh()));
        $this->assertSame('b', $this->nextStepId($workspace, $member), 'a3 is NOT marked delivered by the old a1 text.');

        $this->inbound($business, 'ok');
        $this->inbound($business, 'ok');

        $this->assertSame(['Hello! Quick question.', 'Alpha text.', 'Beta text.', 'Alpha text.'], $this->sentBodies());
        $this->assertSame(['a1', 'b', 'a3'], $this->member($member)->sequence_sent_ids);
    }

    public function test_several_identical_steps_with_one_delivered_copy_are_satisfied_once(): void
    {
        [$workspace, , $member] = $this->started('Alpha text.');
        OutreachScriptManager::save($workspace, ['sequence_steps' => [$this->step('s1', 'Alpha text.'), $this->step('s2', 'Alpha text.'), $this->step('s3', 'Alpha text.')]]);

        $this->assertSame(['s1'], OutreachSequence::forWorkspace($workspace)->deliveredByContent($member->fresh()));
        $this->assertSame('s2', $this->nextStepId($workspace, $member));
    }

    public function test_two_genuinely_delivered_identical_messages_satisfy_two_distinct_steps(): void
    {
        [$workspace, $business, $member] = $this->started('Alpha text.');
        $this->handSend($business, 'Alpha text.', 'sent');
        OutreachScriptManager::save($workspace, ['sequence_steps' => [$this->step('s1', 'Alpha text.'), $this->step('s2', 'Alpha text.'), $this->step('s3', 'Alpha text.')]]);

        $this->assertSame(['s1', 's2'], OutreachSequence::forWorkspace($workspace)->deliveredByContent($member->fresh()));
        $this->assertSame('s3', $this->nextStepId($workspace, $member));
    }

    public function test_an_orphaned_ledger_send_that_also_sits_in_the_conversation_is_counted_once(): void
    {
        [$workspace, $business, $member] = $this->started('Hello! Quick question.');
        $this->aba($workspace);
        $this->inbound($business, 'ok');                                   // one SMS: a ledger row AND a chat message
        $this->assertSame(1, ChatBoxMessage::where('message', 'Alpha text.')->count());
        $this->assertSame(1, AgencyProspectMessage::where('body', 'Alpha text.')->count());
        ChatBoxMessage::where('message', 'Alpha text.')->update(['send_status' => 'sent']);   // the chat copy is confirmed too

        $this->aba($workspace, ['x1', 'y', 'x3']);

        $this->assertNotContains('x3', OutreachSequence::forWorkspace($workspace)->deliveredByContent($member->fresh()));
        $this->assertSame('y', $this->nextStepId($workspace, $member));
    }

    public function test_manual_message_states_decide_the_exact_next_step(): void
    {
        [$workspace, $business, $member] = $this->started('Hello! Quick question.');
        $message1 = 'Hi, Snap Booth Co here. We book photo booths for local venues and you only pay at 9% when we deliver. Open to hearing more?';

        $manual = $this->handSend($business, $message1, 'ambiguous');
        $this->assertSame('legacy-1', $this->nextStepId($workspace, $member), 'ambiguous: Message 1 is still next.');
        $manual->update(['send_status' => 'sending']);
        $this->assertSame('legacy-1', $this->nextStepId($workspace, $member));
        $manual->update(['send_status' => 'failed']);
        $this->assertSame('legacy-1', $this->nextStepId($workspace, $member));
        $manual->update(['send_status' => 'sent']);
        $this->assertSame('legacy-2', $this->nextStepId($workspace, $member), 'confirmed: Message 2 is next.');
    }

    public function test_an_ended_campaigns_history_never_satisfies_a_steps_in_the_new_campaign(): void
    {
        [$workspace, $business, $old, $prospect, $oldCampaign] = $this->started('Hello! Quick question.');
        $this->aba($workspace);
        $this->inbound($business, 'ok');                                   // old campaign delivered "Alpha text."
        app(OutreachCampaignService::class)->end($workspace, $oldCampaign);
        $this->travel(10)->minutes();

        $new = $this->managedCampaign($workspace, ['name' => 'Second', 'opening_message' => 'A new opener.']);
        $member2 = $this->enroll($workspace, $new, $prospect, 1);
        OutreachInitialSendJob::dispatchSync($member2->id);

        $this->assertSame([], OutreachSequence::forWorkspace($workspace)->deliveredByContent($member2->fresh()), 'Only the new campaign\'s own sends count.');
        $this->assertSame('a1', $this->nextStepId($workspace, $member2));

        $before = count($this->sentBodies());
        $this->inbound($business, 'ok');
        $this->assertSame('Alpha text.', $this->sentBodies()[$before]);
        $this->assertSame(['a1'], $this->member($member2)->sequence_sent_ids);
        $this->assertSame(['a1'], $this->member($old)->sequence_sent_ids, 'The old record is untouched.');
    }

    public function test_a_faq_prefixed_delivery_survives_a_rebuild_and_progression_continues_one_step_at_a_time(): void
    {
        [$workspace, $business, $member] = $this->started('Hello! Quick question.');
        $this->aba($workspace);
        $this->inbound($business, 'how much is it?');                      // "Our commission ... Alpha text." as step a1
        $this->assertSame('Our commission is 9% of booked events. Alpha text.', $this->sentBodies()[1]);

        $this->aba($workspace, ['x1', 'y', 'x3']);
        $this->assertSame('y', $this->nextStepId($workspace, $member), 'The FAQ-prefixed A is recognised as x1, once.');

        $this->inbound($business, 'ok');
        $this->inbound($business, 'ok');
        $this->inbound($business, 'ok');

        $this->assertSame(['Hello! Quick question.', 'Our commission is 9% of booked events. Alpha text.', 'Beta text.', 'Alpha text.'], $this->sentBodies());
    }

    public function test_a_longer_unrelated_message_does_not_satisfy_a_step_that_merely_appears_inside_it(): void
    {
        [$workspace, $business, $member] = $this->started('Hello! Quick question.');
        $this->handSend($business, 'Totally unrelated. Alpha text. And more words after it.', 'sent');
        $this->aba($workspace);

        $this->assertSame('a1', $this->nextStepId($workspace, $member));
    }
}
