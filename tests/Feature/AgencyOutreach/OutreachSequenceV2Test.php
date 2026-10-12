<?php

namespace Tests\Feature\AgencyOutreach;

use App\Jobs\Outreach\OutreachFollowUpJob;
use App\Library\AgencyOutreach\OutreachScriptManager;
use App\Library\AgencyOutreach\OutreachSequence;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectingSetting;
use App\Models\Business;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\AgencyOutreach\Concerns\BuildsOutreachFixtures;
use Tests\TestCase;

/**
 * Agency Outreach V2: configurable ordered sequences and custom approved answers,
 * on top of the unchanged send pipeline (opt-out, duplicate protection, billing).
 */
class OutreachSequenceV2Test extends TestCase
{
    use RefreshDatabase;
    use BuildsOutreachFixtures;

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

    private function step(string $id, string $body, array $extra = []): array
    {
        return array_merge(['id' => $id, 'body' => $body, 'advance_on' => 'reply', 'enabled' => true], $extra);
    }

    private function sequence(Workspace $workspace, array $steps, array $more = []): void
    {
        OutreachScriptManager::save($workspace, array_merge(['sequence_steps' => $steps], $more));
    }

    // ---- length --------------------------------------------------------------------------------------

    public function test_a_five_step_sequence_sends_each_message_in_order_and_the_link_is_not_tied_to_message_three(): void
    {
        [$workspace, $business, $member] = $this->scenario();
        $this->sequence($workspace, [
            $this->step('a', 'Step A.'),
            $this->step('b', 'Step B. Book here: {{agency.calendar_link}}'),
            $this->step('c', 'Step C.'),
            $this->step('d', 'Step D.'),
            $this->step('e', 'Step E.'),
        ]);

        foreach (['yes', 'ok', 'sure', 'go on', 'nice'] as $reply) {
            $this->inbound($business, $reply);
        }

        $this->assertSame(
            ['Step A.', 'Step B. Book here: https://cal.example.test/snap', 'Step C.', 'Step D.', 'Step E.'],
            $this->sentBodies(),
        );

        $this->inbound($business, 'thanks');
        $this->assertCount(5, $this->fakeAdapter->sentRequests, 'The sequence is complete: nothing more is sent.');
        $fresh = $this->member($member);
        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $fresh->sequence_sent_ids);
        $this->assertNotNull($fresh->booking_link_sent_at, 'Message 2 carried the link.');
    }

    public function test_a_two_step_sequence_is_complete_after_two_replies(): void
    {
        [$workspace, $business] = $this->scenario();
        $this->sequence($workspace, [$this->step('a', 'Hello A.'), $this->step('b', 'Call link: {{agency.calendar_link}}')]);

        $this->inbound($business, 'yes');
        $this->inbound($business, 'ok');
        $this->inbound($business, 'ok');

        $this->assertCount(2, $this->fakeAdapter->sentRequests);
    }

    // ---- editing while a campaign runs ------------------------------------------------------------------

    public function test_editing_the_sequence_mid_campaign_never_repeats_skips_or_reorders_a_step_for_a_prospect(): void
    {
        [$workspace, $business, $member] = $this->scenario();
        $this->sequence($workspace, [
            $this->step('a', 'A.'), $this->step('b', 'B.'), $this->step('c', 'C. {{agency.calendar_link}}'), $this->step('d', 'D.'),
        ]);

        $this->inbound($business, 'yes'); // A sent

        // The Agency inserts a new first step, deletes B and reorders: [X, A, D, C]
        $this->sequence($workspace, [
            $this->step('x', 'X new first.'), $this->step('a', 'A.'), $this->step('d', 'D.'), $this->step('c', 'C. {{agency.calendar_link}}'),
        ]);

        $this->inbound($business, 'ok');
        $this->inbound($business, 'ok');
        $this->inbound($business, 'ok');

        // A is not repeated, X (positioned before what was received) is not sent late, B is gone.
        $this->assertSame(['A.', 'D.', 'C. https://cal.example.test/snap'], $this->sentBodies());
        $this->assertSame(['a', 'd', 'c'], $this->member($member)->sequence_sent_ids);
    }

    public function test_deleting_the_last_received_step_continues_with_the_next_unsent_one(): void
    {
        [$workspace, $business] = $this->scenario();
        $this->sequence($workspace, [$this->step('a', 'A.'), $this->step('b', 'B.')]);
        $this->inbound($business, 'yes');

        $this->sequence($workspace, [$this->step('b', 'B.'), $this->step('c', 'C.')]);
        $this->inbound($business, 'ok');
        $this->inbound($business, 'ok');

        $this->assertSame(['A.', 'B.', 'C.'], $this->sentBodies());
    }

    public function test_a_disabled_step_is_skipped_and_a_resubmitted_script_bumps_the_version_once(): void
    {
        [$workspace, $business] = $this->scenario();
        $before = (int) AgencyProspectingSetting::where('workspace_id', $workspace->id)->value('script_version');
        $steps = [$this->step('a', 'A.'), $this->step('b', 'B.', ['enabled' => false]), $this->step('c', 'C.')];

        $this->sequence($workspace, $steps);
        $this->assertSame($before + 1, (int) AgencyProspectingSetting::where('workspace_id', $workspace->id)->value('script_version'));
        $this->sequence($workspace, $steps);
        $this->assertSame($before + 1, (int) AgencyProspectingSetting::where('workspace_id', $workspace->id)->value('script_version'), 'An unchanged save is not a new version.');

        $this->inbound($business, 'yes');
        $this->inbound($business, 'ok');

        $this->assertSame(['A.', 'C.'], $this->sentBodies());
    }

    // ---- legacy compatibility ---------------------------------------------------------------------------

    public function test_a_prospect_part_way_through_the_legacy_flow_continues_when_the_agency_customises(): void
    {
        [$workspace, $business, $member] = $this->scenario('12025551000', 3);

        // Saved from the editor, which loads the legacy flow with its stable ids and adds a step.
        $legacy = OutreachSequence::forWorkspace($workspace)->steps();
        $this->assertSame(['legacy-1', 'legacy-2', 'legacy-3', 'legacy-followup'], array_column($legacy, 'id'));
        $this->assertFalse(OutreachSequence::forWorkspace($workspace)->isCustom());

        $custom = [$legacy[0], $legacy[1], $legacy[2], $this->step('extra', 'One more thing.')];
        $this->sequence($workspace, array_map(fn ($s) => $s + ['enabled' => true], $custom));

        $this->inbound($business, 'sounds good'); // stage 3 prospect has had legacy-1 and legacy-2: next is legacy-3
        $this->assertSame(['Book a quick call here: https://cal.example.test/snap'], $this->sentBodies());
        $this->assertSame(['legacy-1', 'legacy-2', 'legacy-3'], $this->member($member)->sequence_sent_ids);
    }

    // ---- timing & replies -----------------------------------------------------------------------------------

    public function test_timed_steps_have_their_own_waits_and_a_reply_cancels_the_timer(): void
    {
        [$workspace, $business, $member] = $this->scenario();
        $this->sequence($workspace, [
            $this->step('a', 'Intro A.'),
            $this->step('t1', 'Nudge after 2h.', ['advance_on' => 'time', 'delay_hours' => 2]),
            $this->step('t2', 'Nudge after 30h.', ['advance_on' => 'time', 'delay_hours' => 30]),
        ]);

        $this->inbound($business, 'yes');
        $armed = $this->member($member);
        $this->assertNotNull($armed->followup_at);
        $this->assertEqualsWithDelta(now()->addHours(2)->timestamp, $armed->followup_at->timestamp, 120);

        // The 2h timer fires: t1 is sent and the 30h timer is armed.
        AgencyProspectCampaignMember::whereKey($member->id)->update(['followup_at' => now()->subMinute()]);
        app()->call([new OutreachFollowUpJob($member->id), 'handle']);

        $this->assertSame(['Intro A.', 'Nudge after 2h.'], $this->sentBodies());
        $second = $this->member($member);
        $this->assertEqualsWithDelta(now()->addHours(30)->timestamp, $second->followup_at->timestamp, 120);
        $this->assertNull($second->followup_sent_at);

        // The prospect replies before it: the timer is cancelled and the timed step never goes out.
        $this->inbound($business, 'what is this?');
        AgencyProspectCampaignMember::whereKey($member->id)->update(['followup_at' => now()->subMinute()]);
        app()->call([new OutreachFollowUpJob($member->id), 'handle']);

        $this->assertNotContains('Nudge after 30h.', $this->sentBodies());
    }

    public function test_the_legacy_single_follow_up_still_fires_once_after_the_link(): void
    {
        [$workspace, $business, $member] = $this->scenario('12025551000', 3);

        $this->inbound($business, 'sounds good');
        $this->assertNotNull($this->member($member)->followup_at);

        AgencyProspectCampaignMember::whereKey($member->id)->update(['followup_at' => now()->subMinute()]);
        app()->call([new OutreachFollowUpJob($member->id), 'handle']);
        app()->call([new OutreachFollowUpJob($member->id), 'handle']);

        $follow = array_values(array_filter($this->sentBodies(), fn ($b) => str_starts_with($b, 'Following up')));
        $this->assertCount(1, $follow);
    }

    // ---- pause & safety -------------------------------------------------------------------------------------

    public function test_a_paused_sequence_sends_nothing_and_resumes_without_losing_the_prospect(): void
    {
        [$workspace, $business, $member] = $this->scenario();
        $this->sequence($workspace, [$this->step('a', 'A.'), $this->step('b', 'B.')], ['sequence_paused' => true]);

        $this->inbound($business, 'yes');
        $this->assertSame([], $this->sentBodies());
        $this->assertSame(1, $this->member($member)->stage->value);

        OutreachScriptManager::save($workspace, ['sequence_paused' => false]);
        $this->inbound($business, 'yes please');

        $this->assertSame(['A.'], $this->sentBodies());
    }

    public function test_stop_ends_the_sequence_whatever_its_length(): void
    {
        [$workspace, $business, $member] = $this->scenario();
        $this->sequence($workspace, [$this->step('a', 'A.'), $this->step('b', 'B.'), $this->step('c', 'C.'), $this->step('d', 'D.')]);

        $this->inbound($business, 'yes');
        $this->inbound($business, 'STOP');
        $this->inbound($business, 'ok');

        $this->assertSame(['A.'], $this->sentBodies());
        $this->assertSame(99, $this->member($member)->stage->value);
    }

    public function test_a_step_is_never_delivered_twice_even_if_a_second_reply_races_the_first(): void
    {
        [$workspace, $business, $member] = $this->scenario();
        $this->sequence($workspace, [$this->step('a', 'A.'), $this->step('b', 'B.'), $this->step('c', 'C.'), $this->step('d', 'D.')]);
        $this->inbound($business, 'yes');
        $this->inbound($business, 'ok');

        // A concurrent worker replays the same inbound: the ledger and step set refuse a second send.
        $inbound = $this->ledger($member, 'inbound')[1];
        app()->call([new \App\Jobs\Outreach\OutreachRespondJob($member->id, $inbound->id), 'handle']);

        $this->assertSame(['A.', 'B.'], $this->sentBodies());
    }

    // ---- approved answers ---------------------------------------------------------------------------------

    public function test_a_custom_approved_answer_is_used_first_then_the_next_message_with_no_model_call(): void
    {
        $ai = $this->useFakeAi();
        [$workspace, $business] = $this->scenario();
        $this->sequence($workspace, [$this->step('a', 'Intro A.')]);
        OutreachScriptManager::save($workspace, ['faq_entries' => [[
            'id' => 'w', 'question' => 'Do you work weekends?', 'answer' => 'Yes, weekends are our busiest days.',
            'keywords' => 'weekend, saturday, sunday',
        ]]]);

        $this->inbound($business, 'Do you guys work on weekends?');

        $this->assertSame(['Yes, weekends are our busiest days. Intro A.'], $this->sentBodies());
        $this->assertSame(0, $ai->callCount());
    }

    public function test_an_answer_scoped_to_another_campaign_or_niche_is_not_used(): void
    {
        [$workspace, $business, , , $campaign] = $this->scenario();
        $this->sequence($workspace, [$this->step('a', 'Intro A.')]);
        OutreachScriptManager::save($workspace, ['niche' => 'photo booths', 'faq_entries' => [
            ['id' => 'x', 'question' => 'Weekend?', 'answer' => 'Only for the other niche.', 'keywords' => 'weekend', 'niche' => 'plumbing'],
            ['id' => 'y', 'question' => 'Weekend?', 'answer' => 'Weekends for our booth campaigns.', 'keywords' => 'weekend', 'campaign_ids' => [$campaign->id]],
        ]]);

        $this->inbound($business, 'Are you open on weekends?');

        $this->assertSame(['Weekends for our booth campaigns. Intro A.'], $this->sentBodies());
    }

    public function test_handoff_leaves_an_uncovered_question_for_a_person_and_sends_nothing(): void
    {
        $ai = $this->useFakeAi();
        [$workspace, $business, $member] = $this->scenario();
        $this->sequence($workspace, [$this->step('a', 'Intro A.')]);
        OutreachScriptManager::save($workspace, ['faq_entries' => [], 'faq_fallback' => 'handoff']);

        $this->inbound($business, 'How many people are on your team and what insurance do you carry?');

        $this->assertSame([], $this->sentBodies());
        $this->assertSame(0, $ai->callCount());
        $this->assertSame(1, $this->member($member)->stage->value);
    }

    // ---- validation -----------------------------------------------------------------------------------------

    public function test_the_manager_rejects_an_empty_message_and_an_unbounded_list_and_scopes_campaigns_to_the_workspace(): void
    {
        [$workspace] = $this->scenario();

        try {
            $this->sequence($workspace, [$this->step('a', '   ')]);
            $this->fail('An empty message must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sequence_steps.0.body', $e->errors());
        }

        try {
            $this->sequence($workspace, array_map(fn ($i) => $this->step('s' . $i, 'Message ' . $i), range(1, OutreachScriptManager::MAX_STEPS + 1)));
            $this->fail('The list is bounded.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sequence_steps', $e->errors());
        }

        [, , $otherWorkspace] = $this->agency('Other Agency');
        $foreign = $this->managedCampaign($otherWorkspace);
        OutreachScriptManager::save($workspace, ['faq_entries' => [
            ['id' => 'q', 'question' => 'Q', 'answer' => 'A', 'campaign_ids' => [$foreign->id]],
        ]]);

        $saved = AgencyProspectingSetting::where('workspace_id', $workspace->id)->first()->faq_entries;
        $this->assertNull($saved[0]['campaign_ids'], 'A campaign from another Workspace is never accepted.');
    }
}
