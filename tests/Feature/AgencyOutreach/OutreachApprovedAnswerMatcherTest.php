<?php

namespace Tests\Feature\AgencyOutreach;

use App\Jobs\Outreach\OutreachRespondJob;
use App\Library\AgencyOutreach\OutreachScriptManager;
use App\Library\AgencyOutreach\OutreachUnansweredQuestions;
use App\Library\Ai\AiCompletionResult;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectUnansweredQuestion;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AgencyOutreach\Concerns\BuildsOutreachFixtures;
use Tests\TestCase;

/**
 * Approved-answer matching for stacked questions and the Unanswered questions queue, end to
 * end through the real inbound path (listener -> respond job -> send pipeline). Only the SMS
 * provider and the model are doubles.
 *
 * Every answer below is FIXTURE wording, prefixed "Fixture:", standing in for whatever an
 * Agency owner types. None of it is anyone's real offer, price or policy.
 */
class OutreachApprovedAnswerMatcherTest extends TestCase
{
    use RefreshDatabase;
    use BuildsOutreachFixtures;

    private const M1 = 'Hi, Snap Booth Co here. We book photo booths for local venues and you only pay at 9% when we deliver. Open to hearing more?';

    private const M3 = 'Book a quick call here: https://cal.example.test/snap';

    /** Seven approved answers; ad budget and speaking with customers are deliberately NOT covered. */
    private const FAQ = [
        ['id' => 'fee', 'question' => 'What is the monthly fee?', 'answer' => 'Fixture: the monthly fee is $500.'],
        ['id' => 'guarantee', 'question' => 'Do you guarantee leads?', 'answer' => 'Fixture: we do not guarantee lead numbers; you get a weekly lead report.'],
        ['id' => 'clients', 'question' => 'Who are your existing clients?', 'answer' => 'Fixture: yes, we work with photo booth and wedding vendors.'],
        ['id' => 'testimonials', 'question' => 'Do you have testimonials?', 'answer' => 'Fixture: read testimonials at https://fixture.example.test/reviews'],
        ['id' => 'ownership', 'question' => 'Who owns the leads?', 'answer' => 'Fixture: you own the leads and customer information.'],
        ['id' => 'terms', 'question' => 'How long is the contract and can I cancel?', 'answer' => 'Fixture: month to month, cancel any time with 30 days notice.'],
        ['id' => 'human', 'question' => 'Is this a human or AI?', 'answer' => 'Fixture: a real person on our team handles every conversation.'],
    ];

    private const NINE_QUESTIONS = "What is your monthly fee?\n"
        . "Is there a separate advertising budget?\n"
        . "Do you guarantee a certain number of qualified leads?\n"
        . "Do you have existing photo booth or wedding vendor clients?\n"
        . "Can I speak with three current customers?\n"
        . "Do I own my leads and customer information?\n"
        . "Is there a long-term contract?\n"
        . "Can I cancel anytime?\n"
        . 'Are real people handling inquiries, or is most of it AI automation?';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOutreach();
    }

    /** @return array{0: Workspace, 1: \App\Models\Business, 2: AgencyProspectCampaignMember, 3: \App\Models\AgencyProspectCampaign, 4: \App\Models\Customer} */
    private function scenario(array $faq = self::FAQ): array
    {
        [$customer, $business, $workspace] = $this->agency();
        $this->saveScript($workspace);
        OutreachScriptManager::save($workspace, ['faq_entries' => $faq]);

        $campaign = $this->managedCampaign($workspace);
        $member = $this->enroll($workspace, $campaign, $this->prospect($workspace));

        return [$workspace, $business, $member, $campaign, $customer];
    }

    /** @return list<string> */
    private function openQuestions(Workspace $workspace): array
    {
        return AgencyProspectUnansweredQuestion::query()->where('workspace_id', $workspace->id)
            ->where('status', 'open')->orderBy('id')->pluck('question')->all();
    }

    public function test_nine_stacked_questions_each_get_their_saved_answer_or_their_own_unanswered_item(): void
    {
        $ai = $this->useFakeAi();
        // The one model call tries an off-topic approved id (testimonials for "speak with customers")
        // and an unknown id: the server accepts neither.
        $ai->queueResult(AiCompletionResult::success('{"matches":[{"n":1,"id":"ghost"},{"n":2,"id":"testimonials"}]}', 'fake-model', 50, 20));
        [$workspace, $business, $member] = $this->scenario();

        $this->inbound($business, self::NINE_QUESTIONS);

        $this->assertSame([
            "Fixture: the monthly fee is $500.\n"
            . "Fixture: we do not guarantee lead numbers; you get a weekly lead report.\n"
            . "Fixture: yes, we work with photo booth and wedding vendors.\n"
            . "Fixture: you own the leads and customer information.\n"
            . "Fixture: month to month, cancel any time with 30 days notice.\n"
            . "Fixture: a real person on our team handles every conversation.\n\n"
            . self::M1,
        ], $this->sentBodies(), 'Every covered question is answered in order; contract + cancel share one answer, rendered once.');

        $this->assertSame(
            ['Is there a separate advertising budget?', 'Can I speak with three current customers?'],
            $this->openQuestions($workspace),
            'The monthly fee never answers ad spend, testimonials never answer references: each gets its own item.',
        );
        $this->assertSame(1, $ai->callCount(), 'A mixed inbound makes at most one model call.');
        $this->assertSame('deterministic', $this->ledger($member, 'outbound')[0]->source, 'No model selection was accepted.');
    }

    public function test_info_and_website_before_booking_sends_the_url_once_and_keeps_the_booking_step_pending(): void
    {
        $ai = $this->useFakeAi();
        [$workspace, $business, $member] = $this->scenario([
            ['id' => 'info', 'question' => 'What do you do?', 'answer' => 'Fixture: we run booking ads for venues. Details: https://fixture.example.test'],
            ['id' => 'site', 'question' => 'Do you have a website?', 'answer' => 'Fixture: our website is https://fixture.example.test'],
        ]);

        $this->inbound($business, 'yes');
        $this->inbound($business, 'sure');
        $this->assertSame(3, $this->member($member)->stage->value, 'The calendar-link step is next.');

        $this->inbound($business, 'Before we do that, can I get more of your info and website?');

        $this->assertSame(
            'Fixture: we run booking ads for venues. Details: https://fixture.example.test',
            $this->sentBodies()[2],
            'Info and website once each, the repeated URL dropped, and no booking pitch.',
        );
        $this->assertSame(1, substr_count($this->sentBodies()[2], 'https://fixture.example.test'));
        $this->assertSame(3, $this->member($member)->stage->value, 'The deferred pitch did not advance the stage.');
        $this->assertNull($this->ledger($member, 'outbound')[2]->sequence_step_id, 'The deferred step is not recorded as delivered.');

        $this->inbound($business, 'ok sounds good');
        $this->assertSame(self::M3, $this->sentBodies()[3], 'The held booking step is still eligible next time.');
        $this->assertSame(0, $ai->callCount());
    }

    public function test_clear_matches_cost_no_model_call_and_a_paraphrase_can_only_select_an_approved_in_scope_id(): void
    {
        $ai = $this->useFakeAi();
        [$workspace, $business, , $campaign] = $this->scenario();
        $otherCampaign = $this->managedCampaign($workspace, ['name' => 'Other campaign']);
        OutreachScriptManager::save($workspace, ['faq_entries' => [
            ...self::FAQ,
            ['id' => 'elsewhere', 'question' => 'Outlay?', 'answer' => 'Fixture: other campaign only.', 'campaign_ids' => [$otherCampaign->id]],
        ]]);

        // Indirect request, no question mark: deterministic.
        $this->inbound($business, "I'd like a breakdown of what I'd be paying each month");
        $this->assertSame(['Fixture: the monthly fee is $500. ' . self::M1], $this->sentBodies());
        $this->assertSame(0, $ai->callCount(), 'A clear match makes zero model calls.');

        // A paraphrase the rules cannot place: the model may select the approved fee answer.
        $ai->queueResult(AiCompletionResult::success('{"matches":[{"n":1,"id":"fee"}]}', 'fake-model', 30, 8));
        $this->enroll($workspace, $campaign, $this->prospect($workspace, '12025552001'));
        $this->inbound($business, 'What would my outlay look like?', '12025552001');
        $this->assertSame('Fixture: the monthly fee is $500. ' . self::M1, $this->sentBodies()[1]);

        // An unknown id and an id scoped to another campaign produce no answer at all.
        foreach (['ghost', 'elsewhere'] as $i => $id) {
            $phone = '1202555300' . $i;
            $ai->queueResult(AiCompletionResult::success('{"matches":[{"n":1,"id":"' . $id . '"}]}', 'fake-model', 30, 8));
            $this->enroll($workspace, $campaign, $this->prospect($workspace, $phone));
            $this->inbound($business, 'What would the total outlay look like?', $phone);
            $this->assertSame(self::M1, $this->sentBodies()[2 + $i], $id . ' never produces an answer.');
        }

        $this->assertSame(3, $ai->callCount(), 'One call per mixed inbound, none for the clear one.');
        $this->assertSame(
            ['What would the total outlay look like?', 'What would the total outlay look like?'],
            $this->openQuestions($workspace),
            'Each rejected pick leaves its own inbound question in the queue; the accepted one leaves none.',
        );
    }

    public function test_teaching_sends_nothing_and_the_next_matching_inbound_uses_the_saved_answer_and_a_retry_duplicates_nothing(): void
    {
        $ai = $this->useFakeAi();
        [$workspace, $business, $member, $campaign, $customer] = $this->scenario();
        OutreachScriptManager::save($workspace, ['ai_enabled' => false]);

        $this->inbound($business, 'How much should I budget for ads?');
        $this->assertSame([self::M1], $this->sentBodies(), 'Unmatched on a non-pitch step: the configured continuation.');

        $item = AgencyProspectUnansweredQuestion::query()->sole();

        // A retry of the same inbound: no second item, no second outbound.
        app()->call([new OutreachRespondJob($member->id, (int) $item->inbound_message_id), 'handle']);
        $inbound = $this->ledger($member, 'inbound')[0];
        app(OutreachUnansweredQuestions::class)->record($member, $inbound, ['How much should I budget for ads?']);
        $this->assertSame(1, AgencyProspectUnansweredQuestion::query()->count());
        $this->assertCount(1, $this->fakeAdapter->sentRequests);

        // The owner teaches it from the tab.
        $this->signIn($customer);
        $this->post(route('customer.workspaces.prospecting.unanswered.answer', [$workspace->uid, $item->uid]), [
            'question' => 'How much should I budget for ads?',
            'answer' => 'Fixture: plan on $1,000 a month in ad spend, paid to the ad platform.',
            'scope' => 'campaign',
        ])->assertRedirect();

        $this->assertCount(1, $this->fakeAdapter->sentRequests, 'Saving an answer never texts the original prospect.');
        $this->assertSame('resolved', $item->fresh()->status);
        $this->assertSame([], $this->openQuestions($workspace));

        // The next prospect asking the same thing gets the saved answer, deterministically.
        $this->enroll($workspace, $campaign, $this->prospect($workspace, '12025554001'));
        $this->inbound($business, 'how much should I budget for ads', '12025554001');
        $this->assertSame('Fixture: plan on $1,000 a month in ad spend, paid to the ad platform. ' . self::M1, $this->sentBodies()[1]);
        $this->assertSame(0, $ai->callCount());
    }

    public function test_link_existing_answer_is_an_exact_alias_and_dismiss_and_scope_are_enforced(): void
    {
        [$workspace, $business, $member, $campaign, $customer] = $this->scenario();
        OutreachScriptManager::save($workspace, ['ai_enabled' => false]);

        $this->inbound($business, 'Any chance of a refund if it flops?');
        $this->enroll($workspace, $campaign, $this->prospect($workspace, '12025555001'));
        $this->inbound($business, 'Got any case studies?', '12025555001');
        [$refund, $cases] = AgencyProspectUnansweredQuestion::query()->orderBy('id')->get()->all();

        [$otherCustomer, , $other] = $this->agency('Other Agency');
        $this->signIn($otherCustomer);
        $this->get(route('customer.workspaces.prospecting.unanswered.index', $workspace->uid))->assertNotFound();
        $this->post(route('customer.workspaces.prospecting.unanswered.dismiss', [$other->uid, $refund->uid]))->assertNotFound();

        $this->signIn($customer);
        $html = $this->get(route('customer.workspaces.prospecting.unanswered.index', $workspace->uid))->assertOk()->getContent();
        $this->assertStringContainsString('Any chance of a refund if it flops?', $html);
        $this->assertStringContainsString('Unanswered questions', $html);

        $this->post(route('customer.workspaces.prospecting.unanswered.link', [$workspace->uid, $refund->uid]), ['faq_id' => 'ghost'])->assertSessionHasErrors('faq_id');
        $this->assertSame('open', $refund->fresh()->status, 'Nothing resolves until the alias saves.');

        $this->post(route('customer.workspaces.prospecting.unanswered.link', [$workspace->uid, $refund->uid]), ['faq_id' => 'guarantee'])->assertRedirect();
        $this->post(route('customer.workspaces.prospecting.unanswered.dismiss', [$workspace->uid, $cases->uid]))->assertRedirect();
        $this->assertSame(['resolved', 'dismissed'], [$refund->fresh()->status, $cases->fresh()->status]);

        $entries = collect(\App\Library\AgencyOutreach\OutreachFaq::forWorkspace($workspace)->all())->keyBy('id');
        $this->assertSame(['any chance of a refund if it flops'], $entries['guarantee']['aliases']);
        $this->assertSame([], $entries['guarantee']['keywords'], 'Never turned into substring keywords.');

        $sent = count($this->fakeAdapter->sentRequests);
        $this->enroll($workspace, $campaign, $this->prospect($workspace, '12025555002'));
        $this->inbound($business, 'any chance of a REFUND if it flops??', '12025555002');
        $this->assertSame('Fixture: we do not guarantee lead numbers; you get a weekly lead report. ' . self::M1, $this->sentBodies()[$sent]);

        // Only the exact wording: a longer message containing it is not swept in.
        $this->enroll($workspace, $campaign, $this->prospect($workspace, '12025555003'));
        $this->inbound($business, 'Any chance of a refund if it flops on week one, and who handles it?', '12025555003');
        $this->assertSame(self::M1, $this->sentBodies()[$sent + 1]);

        // Saving the Script page (which does not post aliases) keeps the taught phrasing.
        OutreachScriptManager::save($workspace, ['faq_entries' => array_map(fn ($e) => array_diff_key($e, ['aliases' => 1]), \App\Library\AgencyOutreach\OutreachFaq::forWorkspace($workspace)->all())]);
        $this->assertSame(['any chance of a refund if it flops'], collect(\App\Library\AgencyOutreach\OutreachFaq::forWorkspace($workspace)->all())->keyBy('id')['guarantee']['aliases']);
    }

    public function test_partial_ai_choice_keeps_the_uncovered_ad_budget_in_the_queue(): void
    {
        [$workspace, $business] = $this->scenario([self::FAQ[0]]);
        $this->useFakeAi()->queueResult(AiCompletionResult::success('{"matches":[{"n":1,"id":"fee"}]}', 'fake-model', 30, 8));
        $this->inbound($business, 'What is the monthly fee and ad budget?');
        $this->assertStringContainsString('Fixture: the monthly fee', $this->sentBodies()[0]);
        $this->assertSame(['What is the monthly fee and ad budget?'], $this->openQuestions($workspace));
    }

    public function test_terse_requests_reach_matching(): void
    {
        foreach (['More info please', 'price please', 'Testimonials?'] as $text) {
            $this->assertSame([$text], \App\Library\AgencyOutreach\OutreachQuestionMatcher::requests($text));
        }
        $this->assertSame([], \App\Library\AgencyOutreach\OutreachQuestionMatcher::requests('ok?'));
    }

    public function test_explicit_info_site_and_pricing_and_reverse_order_coverage(): void
    {
        $ai = $this->useFakeAi();
        [$workspace, $business, , $campaign] = $this->scenario([
            ['id' => 'info', 'question' => 'What do you do?', 'answer' => 'Fixture: we help agencies.'],
            ['id' => 'site', 'question' => 'Website?', 'answer' => 'Fixture: our website.'],
            self::FAQ[0],
            ['id' => 'term', 'question' => 'How long is the contract?', 'answer' => 'Fixture: a 12-month contract, cancel with 30 days notice.'],
        ]);
        $this->inbound($business, 'Can you send more info and your website and pricing?');
        $this->assertStringContainsString("Fixture: we help agencies.\nFixture: our website.\nFixture: the monthly fee", $this->sentBodies()[0]);

        $this->enroll($workspace, $campaign, $this->prospect($workspace, '12025555008'));
        $this->inbound($business, 'Can I cancel? Is there a contract?', '12025555008');
        $this->assertSame(1, substr_count($this->sentBodies()[1], '12-month contract'));
        $this->assertSame([], $this->openQuestions($workspace));
        $this->assertSame(0, $ai->callCount());
    }

    public function test_a_stale_script_save_cannot_remove_an_answer_taught_after_the_editor_opened(): void
    {
        [$workspace] = $this->scenario();
        $entries = \App\Library\AgencyOutreach\OutreachFaq::forWorkspace($workspace)->all();
        $snapshot = hash('sha256', serialize($entries));
        $updated = [...$entries, ['id' => 'new', 'question' => 'Who owns the photos?', 'answer' => 'Fixture: you do.']];
        OutreachScriptManager::save($workspace, ['faq_entries' => $updated]);

        try {
            OutreachScriptManager::save($workspace, ['faq_entries' => $entries, 'faq_snapshot' => $snapshot]);
            $this->fail('A stale edit removed a taught answer');
        } catch (\Illuminate\Validation\ValidationException $error) {
            $this->assertArrayHasKey('faq_entries', $error->errors());
        }
        $this->assertContains('new', array_column(\App\Library\AgencyOutreach\OutreachFaq::forWorkspace($workspace)->all(), 'id'));
    }

    private function signIn(\App\Models\Customer $customer): void
    {
        $user = $customer->user;
        $user->email_verified_at = now();
        $user->save();

        $this->withSession(['permissions' => collect(['access_backend'])]);
        $this->actingAs($user);
    }
}
