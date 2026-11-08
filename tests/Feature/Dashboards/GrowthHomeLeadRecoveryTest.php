<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Assistant\AssistantSuggestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * Unanswered-lead recovery on Growth Home: detect (the inbox's own waiting definition) → prioritise (longest wait first) → prepare (the
 * assistant, only when clicked) → the customer reviews and sends through the inbox's own reply endpoint → the conversation leaves the
 * list and the recorded reply is shown truthfully (submitted vs delivered, never a booking).
 */
class GrowthHomeLeadRecoveryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['opportunity.enabled' => true]);
    }

    private function html(): string
    {
        return $this->home()->assertOk()->getContent();
    }

    /** The section's markup only, so other parts of Home cannot satisfy or break an assertion. */
    private function section(string $html): string
    {
        return preg_match('#<section[^>]*data-role="growth-waiting".*?</section>#s', $html, $m) === 1 ? $m[0] : '';
    }

    private function reply(int $boxId, string $status, int $minutesAgo): void
    {
        DB::table('chat_box_messages')->insert([
            'box_id' => $boxId, 'message' => 'Our reply', 'sms_type' => 'sms', 'send_by' => 'from', 'direction' => 'outgoing',
            'send_status' => $status, 'created_at' => $this->minutesAgo($minutesAgo), 'updated_at' => $this->minutesAgo($minutesAgo),
        ]);
    }

    public function test_waiting_customers_are_listed_longest_first_with_the_true_total_and_a_prepare_action(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Recovery Venue', 'Recovery Account');
        foreach ([30, 600, 90, 240, 45, 120, 20] as $minutes) {
            $this->conversationWith($business, [['incoming', $this->minutesAgo($minutes)]]);
        }
        $this->authenticateAs($customer);

        $section = $this->section($this->html());

        $this->assertStringContainsString('7 customers are waiting.', $section);
        $this->assertStringContainsString('Showing the 5 who have waited longest.', $section);
        $this->assertSame(5, substr_count($section, 'data-role="waiting-item"'));
        $this->assertSame(5, substr_count($section, 'data-role="prepare-reply"'));
        $this->assertSame(5, substr_count($section, 'data-role="reply-manually"'));
        $order = array_map(fn (string $wait) => strpos($section, $wait), ['10 hours', '4 hours', '2 hours', '1 hour', '45 minutes']);
        $this->assertNotContains(false, $order, 'The five longest waits are listed.');
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order, 'Oldest first.');
        $this->assertStringNotContainsString('30 minutes', $section, 'The two most recent waits are beyond the bounded list.');
        $this->assertStringNotContainsString('20 minutes', $section);
        $this->assertStringContainsString('nothing is sent until you press Send', $section);
        $this->assertStringContainsString('data-home-drawer=', $section);
    }

    public function test_opening_home_makes_no_ai_call_sends_nothing_and_writes_nothing(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Quiet Recovery Venue', 'Quiet Recovery Account');
        $this->conversationWith($business, [['incoming', $this->minutesAgo(90)]]);
        $this->authenticateAs($customer);
        Queue::fake();
        Http::fake();
        $before = DB::table('chat_box_messages')->count();

        $this->html();

        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame($before, DB::table('chat_box_messages')->count());
    }

    public function test_the_manual_path_remains_when_the_assistant_cannot_prepare_a_reply(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Manual Venue', 'Manual Account');
        $this->conversationWith($business, [['incoming', $this->minutesAgo(90)]]);
        $this->authenticateAs($customer);
        $this->partialMock(AssistantSuggestions::class, fn ($m) => $m->shouldReceive('draftReplies')->andReturn(null));

        $section = $this->section($this->html());

        $this->assertStringNotContainsString('data-role="prepare-reply"', $section);
        $this->assertStringContainsString('data-role="reply-manually"', $section);
    }

    public function test_the_reply_form_posts_to_the_inboxs_own_endpoint_and_sends_nothing_until_pressed(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Send Venue', 'Send Account');
        $box = $this->conversationWith($business, [['incoming', $this->minutesAgo(90)]]);
        $uid = DB::table('chat_boxes')->where('id', $box)->value('uid');
        $this->authenticateAs($customer);
        $before = DB::table('chat_box_messages')->count();

        $this->assertSame(1, preg_match('#data-home-drawer="([^"]+)"#', $this->section($this->html()), $m));
        $drawer = $this->get(html_entity_decode($m[1]))->assertOk()->getContent();

        $this->assertStringContainsString(route('customer.workspaces.businesses.conversations.reply', [$workspace->uid, $business->uid, $uid]), $drawer);
        $this->assertStringContainsString('data-role="follow-up-send"', $drawer);
        $this->assertSame($before, DB::table('chat_box_messages')->count(), 'Showing the form sends nothing.');
    }

    public function test_after_a_reply_is_recorded_the_customer_leaves_the_list_and_the_state_is_truthful(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'After Venue', 'After Account');
        $submitted = $this->conversationWith($business, [['incoming', $this->minutesAgo(200)]]);
        $delivered = $this->conversationWith($business, [['incoming', $this->minutesAgo(190)]]);
        $failed = $this->conversationWith($business, [['incoming', $this->minutesAgo(180)]]);
        $this->reply($submitted, 'sent', 30);
        $this->reply($delivered, 'delivered', 25);
        $this->reply($failed, 'failed', 20);
        $this->authenticateAs($customer);

        $section = $this->section($this->html());

        $this->assertStringContainsString('1 customer is waiting.', $section, 'A failed reply is not an answer: that customer is still waiting.');
        $this->assertSame(1, substr_count($section, 'data-role="waiting-item"'));
        $this->assertSame(2, substr_count($section, 'data-role="answered-item"'));
        $this->assertStringContainsString('Reply submitted to your messaging provider', $section);
        $this->assertStringContainsString('Reply delivered', $section);
        $this->assertStringContainsString('A reply is not a booking', $section);
        $this->assertDoesNotMatchRegularExpression('/booked|revenue|recovered|converted/i', strip_tags($section));
    }

    public function test_a_customer_who_writes_again_is_actionable_again_and_not_shown_as_answered(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Again Venue', 'Again Account');
        $box = $this->conversationWith($business, [['incoming', $this->minutesAgo(200)]]);
        $this->reply($box, 'sent', 100);
        $this->authenticateAs($customer);
        $this->assertSame(1, substr_count($this->section($this->html()), 'data-role="answered-item"'));

        DB::table('chat_box_messages')->insert([
            'box_id' => $box, 'message' => 'Still there?', 'sms_type' => 'sms', 'send_by' => 'to', 'direction' => 'incoming',
            'created_at' => $this->minutesAgo(30), 'updated_at' => $this->minutesAgo(30),
        ]);
        DB::table('chat_boxes')->where('id', $box)->update(['updated_at' => $this->minutesAgo(30)]);

        $section = $this->section($this->html());
        $this->assertSame(1, substr_count($section, 'data-role="waiting-item"'));
        $this->assertSame(0, substr_count($section, 'data-role="answered-item"'));
    }

    public function test_another_business_never_contributes_and_an_actor_without_inbox_access_sees_nothing(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth, 'Mine Recovery', 'Mine Recovery Account');
        [, $other] = $this->tenant(WorkspacePlanTier::Growth, 'Theirs Recovery', 'Theirs Recovery Account');
        $this->conversationWith($other, [['incoming', $this->minutesAgo(900)]]);
        $this->authenticateAs($customer);
        $this->assertSame('', $this->section($this->html()), 'Nothing waiting here, and the neighbour\'s conversation is not shown.');

        $this->conversationWith($business, [['incoming', $this->minutesAgo(90)]]);
        $this->authenticateAs($customer, ['view_reports']);
        $this->assertSame('', $this->section($this->html()), 'No inbox permission: no list.');
    }

    public function test_core_home_never_carries_the_growth_waiting_list(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Core, 'Core Recovery', 'Core Recovery Account');
        $this->conversationWith($business, [['incoming', $this->minutesAgo(90)]]);
        $this->authenticateAs($customer);

        $this->assertStringNotContainsString('data-role="growth-waiting"', $this->html());
    }
}
