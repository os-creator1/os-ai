<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/** Growth Home lead-response evidence: the measured age of the oldest unanswered customer message, and nothing estimated. */
class GrowthHomeLeadResponseTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['opportunity.enabled' => true]);
    }

    private function evidence(): string
    {
        $html = $this->home()->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<details[^>]*data-role="growth-evidence".*?</details>#s', $html, $m), 'The collapsed evidence block is rendered.');
        $this->assertStringNotContainsString('open', substr($m[0], 0, strpos($m[0], '>')), 'Collapsed by default.');

        return html_entity_decode(strip_tags($m[0]), ENT_QUOTES);
    }

    public function test_one_waiting_enquiry_reports_its_real_age_and_keeps_the_unknowns(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'One Waiting Venue', 'One Waiting Account');
        $this->conversationWith($business, [['incoming', $this->minutesAgo(90)]]);
        $this->authenticateAs($customer);

        $text = $this->evidence();

        $this->assertStringContainsString('The oldest message still waiting for a reply arrived', $text);
        $this->assertMatchesRegularExpression('/\(1 hour ago\)/', $text);
        $this->assertStringContainsString('whether a reply leads to a booking', $text, 'The booking outcome stays unknown.');
        $this->assertStringContainsString('how quickly you usually reply', $text, 'Historical reply speed is not measured.');
        $this->assertStringNotContainsString('1909555', $text, 'No phone number in the summary.');
        $this->assertStringNotContainsString('Fixture message', $text, 'No message content in the summary.');
    }

    public function test_with_several_waiting_the_oldest_one_is_reported(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Many Waiting Venue', 'Many Waiting Account');
        $this->conversationWith($business, [['incoming', $this->minutesAgo(40)]]);
        $this->conversationWith($business, [['incoming', $this->minutesAgo(300)]]);
        $this->conversationWith($business, [['incoming', $this->minutesAgo(90)]]);
        $this->authenticateAs($customer);

        $this->assertMatchesRegularExpression('/\(5 hours ago\)/', $this->evidence());
    }

    public function test_answered_and_unrecorded_direction_conversations_do_not_count(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Answered Venue', 'Answered Account');
        $this->conversationWith($business, [['incoming', $this->minutesAgo(600)], ['outgoing', $this->minutesAgo(590)]]);
        $this->conversationWith($business, [['incoming', $this->minutesAgo(500)], [null, $this->minutesAgo(450)]]);
        $this->conversationWith($business, [['incoming', $this->minutesAgo(60)]]);
        $this->authenticateAs($customer);

        $text = $this->evidence();

        $this->assertMatchesRegularExpression('/\(1 hour ago\)/', $text, 'Only the genuinely unanswered conversation is measured.');
        $this->assertDoesNotMatchRegularExpression('/\(\d+ hours? ago\)/', str_replace('(1 hour ago)', '', $text));
    }

    public function test_without_a_waiting_enquiry_no_waiting_duration_is_invented(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Quiet Lead Venue', 'Quiet Lead Account');
        $this->conversationWith($business, [['incoming', $this->minutesAgo(200)], ['outgoing', $this->minutesAgo(190)]]);
        $this->authenticateAs($customer);

        $this->assertStringNotContainsString('still waiting for a reply arrived', $this->home()->assertOk()->getContent());
    }

    public function test_another_business_never_supplies_the_age(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Mine Lead Venue', 'Mine Lead Account');
        [, $other] = $this->tenant(WorkspacePlanTier::Growth, 'Theirs Lead Venue', 'Theirs Lead Account');
        $this->conversationWith($other, [['incoming', $this->minutesAgo(2000)]]);
        $this->conversationWith($business, [['incoming', $this->minutesAgo(60)]]);
        $this->authenticateAs($customer);

        $text = $this->evidence();

        $this->assertMatchesRegularExpression('/\(1 hour ago\)/', $text);
        $this->assertStringNotContainsString('day', $text);
    }

    public function test_core_home_carries_no_growth_response_evidence(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Core, 'Core Lead Venue', 'Core Lead Account');
        $this->conversationWith($business, [['incoming', $this->minutesAgo(90)]]);
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();

        $this->assertStringNotContainsString('still waiting for a reply arrived', $html);
        $this->assertStringNotContainsString('Not known yet:', $html);
    }

    public function test_reading_home_sends_and_writes_nothing(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'No Send Venue', 'No Send Account');
        $this->conversationWith($business, [['incoming', $this->minutesAgo(90)]]);
        $this->authenticateAs($customer);
        Queue::fake();
        $before = DB::table('chat_box_messages')->count();

        $this->home()->assertOk();

        Queue::assertNothingPushed();
        $this->assertSame($before, DB::table('chat_box_messages')->count());
    }
}
