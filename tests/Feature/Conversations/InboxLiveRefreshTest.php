<?php

namespace Tests\Feature\Conversations;

use App\Library\Conversations\ConversationHistoryWriter;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\BusinessEmail\Concerns\CreatesBusinessEmailFixtures;
use Tests\TestCase;

/**
 * The server half of "a reply shows up in an open inbox without a reload".
 *
 * The open screen refreshes itself by re-reading what the inbox already serves — the list (`load`, also for the pinned rail) and
 * the open thread (`timeline`) — so these tests persist inbound messages through the real managed-inbound writer AFTER the page has
 * read the data once, and assert what the next read returns: the reply once and in order, the other conversation's preview /
 * position / unread, a first-ever conversation, an untouched unread state, and the same access boundary as every other action.
 * The client half (the screen actually updating, keeping the draft, the scroll position and the selected thread) is
 * tests/js/conversations-live.spec.js.
 */
class InboxLiveRefreshTest extends TestCase
{
    use CreatesBusinessEmailFixtures;
    use RefreshDatabase;

    private const BUSINESS_NUMBER = '15550000000';

    private Customer $owner;

    private Business $business;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        [$this->owner, $this->business, $this->workspace] = $this->emailTenant();
    }

    private function route(string $name, array $extra = []): string
    {
        return route('customer.workspaces.businesses.conversations.' . $name, array_merge([$this->workspace->uid, $this->business->uid], $extra));
    }

    /** A prospect's reply, persisted exactly as the managed inbound path stores it. */
    private function inbound(string $person, string $text): ChatBox
    {
        $writer = app(ConversationHistoryWriter::class);
        $writer->recordManagedInbound($this->business, self::BUSINESS_NUMBER, $person, $text, [], 'plain');

        return $writer->conversationFor($this->business, self::BUSINESS_NUMBER, $person)->refresh();
    }

    private function load(array $extra = []): string
    {
        return $this->post($this->route('load'), array_merge(['channel' => 'all', 'filter' => 'recents', 'page' => 1], $extra))->assertOk()->getContent();
    }

    /** @return list<string> */
    private function itemKeys(ChatBox $box): array
    {
        $html = $this->post($this->route('timeline', [$box->uid]))->assertOk()->json('timeline');
        preg_match_all('/data-item-key="([^"]+)"/', $html, $m);

        return $m[1];
    }

    private function unread(ChatBox $box): int
    {
        return (int) DB::table('chat_boxes')->where('id', $box->id)->value('notification');
    }

    public function test_a_reply_stored_after_the_thread_was_opened_arrives_once_and_in_order_on_the_next_read(): void
    {
        $a = $this->inbound('15551110001', 'first message');
        $this->authenticateAs($this->owner);

        $opened = $this->itemKeys($a);
        $this->assertCount(1, $opened, 'the thread the page opened with');

        $this->inbound('15551110001', 'reply while the page is open');

        $refreshed = $this->itemKeys($a);
        $this->assertCount(2, $refreshed);
        $this->assertSame($refreshed, array_values(array_unique($refreshed)), 'every item is drawn once, by its stable key');
        $this->assertSame($opened[0], $refreshed[0], 'the earlier message keeps its identity and place');

        $html = $this->post($this->route('timeline', [$a->uid]))->json('timeline');
        $this->assertLessThan(strpos($html, 'reply while the page is open'), strpos($html, 'first message'));
        $this->assertSame($refreshed, $this->itemKeys($a), 'reading again changes nothing');
    }

    public function test_a_reply_to_another_conversation_moves_it_to_the_top_with_its_preview_and_unread_and_reads_nothing(): void
    {
        $a = $this->inbound('15551110001', 'Ann says hi');
        $b = $this->inbound('15551110002', 'Bob says hi');
        DB::table('chat_boxes')->where('id', $a->id)->update(['updated_at' => now()->addMinute()]);
        $this->authenticateAs($this->owner);

        $before = $this->load();
        $this->assertLessThan(strpos($before, 'Bob says hi'), strpos($before, 'Ann says hi'), 'A is on top before B replies');

        $this->assertSame(1, $this->unread($b));
        $this->inbound('15551110002', 'Bob replies again');
        DB::table('chat_boxes')->where('id', $b->id)->update(['updated_at' => now()->addMinutes(2)]);

        $after = $this->load();
        $this->assertLessThan(strpos($after, 'Ann says hi'), strpos($after, 'Bob replies again'), 'B moved above A');
        $this->assertStringContainsString('Bob replies again', $after);
        $this->assertStringContainsString('conv-unread', $after);
        $this->assertSame(2, $this->unread($b));

        // Refreshing the list and the open thread never marks any conversation read.
        $this->itemKeys($a);
        $this->load();
        $this->assertSame(1, $this->unread($a));
        $this->assertSame(2, $this->unread($b));
    }

    public function test_a_first_ever_reply_appears_in_the_all_list(): void
    {
        $this->inbound('15551110001', 'someone already here');
        $this->authenticateAs($this->owner);
        $this->assertStringNotContainsString('brand new lead', $this->load());

        $this->inbound('15551110009', 'brand new lead');

        $all = $this->load(['channel' => 'all']);
        $this->assertStringContainsString('brand new lead', $all, 'All still shows SMS lead conversations');
        $this->assertStringContainsString('brand new lead', $this->load(['channel' => 'sms']));
        $this->assertSame(1, substr_count($all, 'brand new lead'));
        $this->assertStringNotContainsString('brand new lead', $this->load(['channel' => 'email']));
    }

    public function test_the_unread_view_keeps_its_rule_while_refreshing(): void
    {
        $read = $this->inbound('15551110001', 'already handled');
        DB::table('chat_boxes')->where('id', $read->id)->update(['notification' => 0]);
        $this->authenticateAs($this->owner);

        $this->assertStringNotContainsString('already handled', $this->load(['filter' => 'unread']));

        $this->inbound('15551110002', 'needs an answer');
        $unread = $this->load(['filter' => 'unread']);

        $this->assertStringContainsString('needs an answer', $unread);
        $this->assertStringNotContainsString('already handled', $unread);
    }

    public function test_the_pinned_rail_can_be_refreshed_on_its_own_without_the_main_lists_tab_or_search(): void
    {
        $pinned = $this->inbound('15551110001', 'pinned thread');
        $other = $this->inbound('15551110002', 'ordinary thread');
        DB::table('chat_boxes')->where('id', $pinned->id)->update(['pinned' => true, 'notification' => 0]);
        $this->authenticateAs($this->owner);

        $main = $this->load();
        $this->assertStringNotContainsString('pinned thread', $main, 'the main list never carries the pinned rail');
        $this->assertStringContainsString('ordinary thread', $main);

        $this->inbound('15551110001', 'pinned reply');

        // The tab and the search of the main list must not hide the rail's rows from its own refresh.
        $rail = $this->load(['pinned' => 1, 'filter' => 'unread', 'search' => 'zzz-no-match', 'channel' => 'email']);
        $this->assertStringContainsString('pinned reply', $rail);
        $this->assertStringContainsString('conv-unread', $rail);
        $this->assertStringNotContainsString('ordinary thread', $rail);
        $this->assertSame(1, $this->unread($pinned), 'refreshing it read nothing');
        $this->assertSame(1, $this->unread($other));
    }

    public function test_another_workspaces_user_cannot_refresh_this_inbox_or_read_its_threads(): void
    {
        $a = $this->inbound('15551110001', 'private');
        [$otherOwner] = $this->emailTenant('Other Studio');
        $this->authenticateAs($otherOwner);

        $this->post($this->route('load'), ['channel' => 'all', 'filter' => 'recents', 'page' => 1])->assertNotFound();
        $this->post($this->route('load'), ['channel' => 'all', 'filter' => 'recents', 'page' => 1, 'pinned' => 1])->assertNotFound();
        $this->post($this->route('timeline', [$a->uid]))->assertNotFound();
    }

    public function test_the_open_thread_of_one_business_never_resolves_a_conversation_of_another(): void
    {
        [$otherOwner, $otherBusiness, $otherWorkspace] = $this->emailTenant('Other Studio');
        $foreign = app(ConversationHistoryWriter::class);
        $foreign->recordManagedInbound($otherBusiness, self::BUSINESS_NUMBER, '15551119999', 'not yours', [], 'plain');
        $box = $foreign->conversationFor($otherBusiness, self::BUSINESS_NUMBER, '15551119999');
        $this->authenticateAs($this->owner);

        $this->post($this->route('timeline', [$box->uid]))->assertNotFound();
        $this->assertStringNotContainsString('not yours', $this->load());
        $this->assertNotSame($otherWorkspace->uid, $this->workspace->uid);
    }
}
