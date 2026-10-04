<?php

namespace Tests\Feature\AgencyOutreach;

use App\Jobs\Outreach\OutreachRespondJob;
use App\Library\AgencyOutreach\OutreachReplyComposer;
use App\Library\Ai\AiCompletionResult;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectMessage;
use App\Models\AiUsageLedgerEntry;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AgencyOutreach\Concerns\BuildsOutreachFixtures;
use Tests\TestCase;

/**
 * Agency Outreach V1 — the deterministic engine end to end (contract §4/§5/§8/§9/§14):
 * a prospect reply arrives through canonical messaging, the listener records it, the
 * responder decides, composes and sends through the real send core, and the ledger
 * says exactly what happened. Only the provider and the model are doubles.
 */
class OutreachEngineTest extends TestCase
{
    use RefreshDatabase;
    use BuildsOutreachFixtures;

    private const M1 = 'Hi, Snap Booth Co here. We book photo booths for local venues and you only pay at 9% when we deliver. Open to hearing more?';

    private const M2 = 'Great. Can we do a quick call about it?';

    private const M3 = 'Book a quick call here: https://cal.example.test/snap';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOutreach();
    }

    /** @return array{0: Workspace, 1: Business, 2: AgencyProspectCampaignMember, 3: AgencyProspect, 4: AgencyProspectCampaign} */
    private function scenario(string $phone = '12025551000', int $stage = 1): array
    {
        [, $business, $workspace] = $this->agency();
        $this->saveScript($workspace);

        $campaign = $this->managedCampaign($workspace);
        $prospect = $this->prospect($workspace, $phone);
        $member = $this->enroll($workspace, $campaign, $prospect, $stage);

        return [$workspace, $business, $member, $prospect, $campaign];
    }

    public function test_the_stage_sequence_sends_the_exact_script_text_and_advances_one_step_per_reply(): void
    {
        [$workspace, $business, $member] = $this->scenario();

        $this->inbound($business, 'yes sure');
        $this->assertSame([self::M1], $this->sentBodies());
        $this->assertSame(2, $this->member($member)->stage->value);

        $this->inbound($business, 'ok');
        $this->assertSame([self::M1, self::M2], $this->sentBodies());
        $this->assertSame(3, $this->member($member)->stage->value);

        $this->inbound($business, 'sounds good');
        $this->assertSame([self::M1, self::M2, self::M3], $this->sentBodies());

        $fresh = $this->member($member);
        $this->assertSame(4, $fresh->stage->value);
        $this->assertNotNull($fresh->booking_link_sent_at);
        $this->assertNotNull($fresh->followup_at);

        // The booking stage has no scripted message: a plain reply sends nothing more.
        $this->inbound($business, 'thanks');
        $this->assertCount(3, $this->fakeAdapter->sentRequests);
        $this->assertSame(4, $this->member($member)->stage->value);
    }

    public function test_every_outbound_ledger_row_is_fully_audited_and_the_member_is_linked_to_its_conversation(): void
    {
        [$workspace, $business, $member, $prospect] = $this->scenario();

        $this->inbound($business, 'yes sure');

        $rows = $this->ledger($member);
        $this->assertCount(2, $rows);

        [$in, $out] = $rows;
        $this->assertSame('inbound', $in->direction);
        $this->assertSame('handled', $in->status);
        $this->assertStringStartsWith('outreach:in:operation:', $in->operation_key);
        $this->assertSame('yes sure', $in->body);

        $this->assertSame('outbound', $out->direction);
        $this->assertSame('sent', $out->status);
        $this->assertSame('outreach:reply:' . $in->id, $out->operation_key);
        $this->assertSame('deterministic', $out->source);
        $this->assertSame([1, 2], [(int) $out->stage_from, (int) $out->stage_to]);
        $this->assertSame(2, (int) $out->script_version, 'The version of the script that wrote this message.');
        $this->assertNull($out->failure_reason);
        $this->assertNull($out->actor_user_id);
        $this->assertNotNull($out->provider_message_id);
        $this->assertSame(self::M1, $out->body);

        $box = ChatBox::query()->where('business_id', $business->id)->where('to', '12025551000')->sole();
        $this->assertSame($box->id, $this->member($member)->chat_box_id, 'The member links to the Agency Business conversation.');
        $this->assertSame($business->id, $box->business_id);
        $this->assertNotNull($this->member($member)->last_inbound_at);
        $this->assertNotNull($this->member($member)->last_outbound_at);
    }

    public function test_each_known_faq_is_answered_first_then_the_exact_stage_message_without_any_model_call(): void
    {
        $ai = $this->useFakeAi();
        [$workspace, $business, $member] = $this->scenario('12025551000');
        $campaign = AgencyProspectCampaign::query()->first();

        $cases = [
            ['How much does it cost?', 'Our commission is 9% of booked events.'],
            ['Are you based in Dallas?', 'We are based in Austin and serve Texas.'],
            ['How did you find me?', 'We found you on Google Maps.'],
            ['Do you have a website?', 'See https://snap.example.test for more.'],
            ['What do you do?', 'We get venues photo booth bookings.'],
            ['Is this for a wedding on Saturday?', 'This is not an event booking, just a quick intro.'],
            ['Who is this?', "We're Snap Booth Co."],
        ];

        foreach ($cases as $i => [$question, $answer]) {
            $phone = '1202555' . (2000 + $i);
            $prospect = $this->prospect($workspace, $phone);
            $this->enroll($workspace, $campaign, $prospect);

            $before = count($this->fakeAdapter->sentRequests);
            $this->inbound($business, $question, $phone);

            $this->assertCount($before + 1, $this->fakeAdapter->sentRequests, $question);
            $this->assertSame($answer . ' ' . self::M1, $this->sentBodies()[$before], $question);
        }

        $this->assertSame(0, $ai->callCount(), 'A known FAQ never costs a model call.');
    }

    public function test_an_unplaceable_question_gets_one_bounded_model_answer_then_the_exact_stage_message(): void
    {
        $ai = $this->useFakeAi();
        $ai->queueResult(AiCompletionResult::success('We do not handle accounting, but we can cover that on a call.', 'fake-model', 60, 14));
        [, $business, $member] = $this->scenario();

        $this->inbound($business, 'Can you do the accounting too?');

        $this->assertSame(['We do not handle accounting, but we can cover that on a call. ' . self::M1], $this->sentBodies());
        $this->assertSame(1, $ai->callCount(), 'Exactly one model call.');

        $request = $ai->requests()[0];
        $this->assertLessThanOrEqual(OutreachReplyComposer::MAX_OUTPUT_TOKENS, $request->maxOutputTokens);
        $this->assertLessThanOrEqual(1 + OutreachReplyComposer::MAX_HISTORY_MESSAGES, count($request->messages));
        $this->assertSame('system', $request->messages[0]['role']);
        $this->assertStringContainsString('Snap Booth Co', $request->messages[0]['content'], 'Only the Agency facts are given to the model.');
        $this->assertStringContainsString('Our commission is 9%', $request->messages[0]['content']);
        $this->assertSame('user', $request->messages[array_key_last($request->messages)]['role']);
        $this->assertSame('Can you do the accounting too?', $request->messages[array_key_last($request->messages)]['content']);

        $out = $this->ledger($member, 'outbound')[0];
        $this->assertSame('ai', $out->source);

        $entry = AiUsageLedgerEntry::query()->where('category', 'agency_prospect_reply')->sole();
        $this->assertSame('agency_prospect_reply:' . $this->ledger($member, 'inbound')[0]->id, $entry->idempotency_key);
    }

    public function test_the_model_history_is_capped_at_eight_messages(): void
    {
        $ai = $this->useFakeAi();
        $ai->setDefaultResult(AiCompletionResult::success('Best covered on a quick call.', 'fake-model', 40, 10));
        [$workspace, $business, $member, , $campaign] = $this->scenario();

        // Stack up a long conversation of FAQ-free replies, then ask the unplaceable question at stage 3.
        $this->inbound($business, 'yes');
        $this->inbound($business, 'ok');
        $this->inbound($business, 'Can you do the accounting too?');

        $request = $ai->requests()[0];
        $this->assertLessThanOrEqual(1 + OutreachReplyComposer::MAX_HISTORY_MESSAGES, count($request->messages));
    }

    public function test_a_failing_refusing_garbage_or_overlong_model_falls_back_to_the_stage_message_alone(): void
    {
        $ai = $this->useFakeAi();
        [$workspace, $business, , , $campaign] = $this->scenario();

        $bad = [
            'provider failure' => AiCompletionResult::failure('fake-model'),
            'empty' => AiCompletionResult::success('', 'fake-model', 10, 1),
            'json garbage' => AiCompletionResult::success('{"answer": "maybe"}', 'fake-model', 10, 5),
            'multi line' => AiCompletionResult::success("First.\n\nSecond line.", 'fake-model', 10, 5),
            'over long' => AiCompletionResult::success(str_repeat('We can help with that. ', 40), 'fake-model', 10, 200),
            'only a url' => AiCompletionResult::success('https://evil.example.test/offer', 'fake-model', 10, 5),
            'leaked token' => AiCompletionResult::success('Hello {{agency.name}} there', 'fake-model', 10, 5),
        ];

        $i = 0;

        foreach ($bad as $label => $result) {
            $phone = '1202555' . (3000 + $i++);
            $this->enroll($workspace, $campaign, $this->prospect($workspace, $phone));
            $ai->queueResult($result);

            $before = count($this->fakeAdapter->sentRequests);
            $this->inbound($business, 'Can you do the accounting too?', $phone);

            $this->assertSame(self::M1, $this->sentBodies()[$before], $label);
        }

        $this->assertSame(count($bad), $ai->callCount());
    }

    public function test_urls_in_the_model_text_are_stripped_and_the_stage_message_still_follows(): void
    {
        $ai = $this->useFakeAi();
        $ai->queueResult(AiCompletionResult::success('Visit https://evil.example.test or www.evil.test for details, we can cover it on a call.', 'fake-model', 20, 20));
        [, $business] = $this->scenario();

        $this->inbound($business, 'Can you do the accounting too?');

        $body = $this->sentBodies()[0];
        $this->assertStringNotContainsString('evil', $body);
        $this->assertStringEndsWith(self::M1, $body);
    }

    public function test_ai_disabled_by_the_agency_or_the_platform_means_no_model_call(): void
    {
        $ai = $this->useFakeAi();
        [$workspace, $business, , , $campaign] = $this->scenario();
        \App\Library\AgencyOutreach\OutreachScriptManager::save($workspace, ['ai_enabled' => false]);

        $this->inbound($business, 'Can you do the accounting too?');
        $this->assertSame([self::M1], $this->sentBodies());
        $this->assertSame(0, $ai->callCount());

        \App\Library\AgencyOutreach\OutreachScriptManager::save($workspace, ['ai_enabled' => true]);
        config(['services.openai.active' => false]);
        $this->enroll($workspace, $campaign, $this->prospect($workspace, '12025554000'));

        $this->inbound($business, 'Can you do the accounting too?', '12025554000');
        $this->assertSame(self::M1, $this->sentBodies()[1], 'A platform-disabled model means the stage message alone.');
        $this->assertSame(0, $ai->callCount());
    }

    public function test_the_booking_stage_answers_a_faq_without_a_scripted_message_and_repeats_the_link_once(): void
    {
        $ai = $this->useFakeAi();
        [$workspace, $business, $member] = $this->scenario('12025551000', 4);

        $this->inbound($business, 'How much does it cost?');
        $this->assertSame(['Our commission is 9% of booked events.'], $this->sentBodies());
        $this->assertSame(4, $this->member($member)->stage->value);

        $this->inbound($business, 'tomorrow at 3pm works for me');
        $this->assertSame(self::M3, $this->sentBodies()[1], 'A scheduling reply gets the calendar link again.');

        $this->inbound($business, 'tomorrow at 3pm works for me');
        $this->assertCount(2, $this->fakeAdapter->sentRequests, 'The link is repeated once, never twice.');
        $this->assertSame(0, $ai->callCount());
    }

    public function test_a_duplicate_inbound_event_makes_one_inbound_row_and_one_reply(): void
    {
        [, $business, $member] = $this->scenario();

        $key = $this->inbound($business, 'yes sure');
        $this->deliverInbound($business, '12025551000', $key);
        $this->deliverInbound($business, '12025551000', $key);

        $this->assertCount(1, $this->ledger($member, 'inbound'));
        $this->assertCount(1, $this->ledger($member, 'outbound'));
        $this->assertCount(1, $this->fakeAdapter->sentRequests);
        $this->assertSame(2, $this->member($member)->stage->value, 'A redelivery never advances the stage twice.');
    }

    public function test_a_duplicate_job_run_sends_one_outbound(): void
    {
        [, $business, $member] = $this->scenario();

        $this->inbound($business, 'yes sure');
        $inbound = $this->ledger($member, 'inbound')[0];

        $job = new OutreachRespondJob($member->id, $inbound->id);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);

        $this->assertCount(1, $this->fakeAdapter->sentRequests);
        $this->assertCount(1, $this->ledger($member, 'outbound'));
        $this->assertSame(2, $this->member($member)->stage->value);
    }

    public function test_a_reply_identical_to_the_last_outbound_is_not_sent_but_the_inbound_is_handled(): void
    {
        [$workspace, $business, $member] = $this->scenario();
        \App\Library\AgencyOutreach\OutreachScriptManager::save($workspace, ['message_1' => 'Same text', 'message_2' => 'Same text']);

        $this->inbound($business, 'yes');
        $this->inbound($business, 'ok');

        $this->assertSame(['Same text'], $this->sentBodies());
        $second = $this->ledger($member, 'inbound')[1];
        $this->assertSame('handled', $second->status);
        $this->assertSame('duplicate_of_last_outbound', $second->failure_reason);
        $this->assertSame(2, $this->member($member)->stage->value, 'Nothing was sent, so the stage did not move.');
    }

    public function test_a_stage_that_moved_after_the_decision_is_never_applied_twice(): void
    {
        [, $business, $member] = $this->scenario();
        $pipeline = app(\App\Library\AgencyOutreach\OutreachSendPipeline::class);

        $this->inbound($business, 'yes sure'); // member is now at stage 2

        $result = $pipeline->run($member->id, new \App\Library\AgencyOutreach\OutreachSendPlan(
            operationKey: 'outreach:reply:stale',
            body: 'stale decision',
            source: 'deterministic',
            purpose: 'ai_reply',
            stageFrom: 1,
            stageTo: 2,
        ));

        $this->assertSame('stage_changed', $result->skipReason);
        $this->assertCount(1, $this->fakeAdapter->sentRequests);
        $this->assertSame(0, AgencyProspectMessage::query()->where('operation_key', 'outreach:reply:stale')->count());
    }

    public function test_a_prospect_that_is_not_in_any_managed_campaign_is_never_touched(): void
    {
        [$workspace, $business, $member, $prospect, $campaign] = $this->scenario();
        $channelCampaign = $this->managedCampaign($workspace, ['sending_mode' => 'channel', 'name' => 'BYO']);

        $other = $this->prospect($workspace, '12025557777');
        $byo = $this->enroll($workspace, $channelCampaign, $other);

        $this->inbound($business, 'hello there', '12025557777');
        $this->inbound($business, 'hello', '12025558888'); // a number that is not a prospect at all

        $this->assertCount(0, $this->fakeAdapter->sentRequests);
        $this->assertCount(0, $this->ledger($byo));
        $this->assertSame(1, $this->member($byo)->stage->value);
        $this->assertSame(0, AgencyProspectMessage::query()->count(), 'No ledger row was written for either.');
    }

    public function test_only_canonical_messaging_events_are_handled(): void
    {
        [, $business, $member] = $this->scenario();

        $this->inbound($business, 'yes sure', '12025551000', 'report:77');

        $this->assertCount(0, $this->ledger($member));
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
    }

    public function test_the_listener_is_registered_for_inbound_messages_beside_the_existing_one(): void
    {
        $listens = (new \App\Providers\EventServiceProvider($this->app))->listens();
        $registered = $listens[\App\Events\Conversation\InboundMessageReceived::class] ?? [];

        $this->assertContains(\App\Listeners\Outreach\HandleOutreachInboundMessage::class, $registered);
        $this->assertContains(\App\Listeners\Automation\Workflow\EnrollFromInboundMessage::class, $registered);
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, new \App\Listeners\Outreach\HandleOutreachInboundMessage(
            app(\App\Library\AgencyOutreach\OutreachConversationLinker::class),
            app(\App\Library\AgencyOutreach\OutreachInboundLedger::class),
            app(\App\Library\AgencyOutreach\OutreachStopService::class),
        ));
    }
}
