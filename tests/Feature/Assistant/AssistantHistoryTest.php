<?php

namespace Tests\Feature\Assistant;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Assistant\AssistantRetention;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Assistant\Concerns\AssistantTestSupport;
use Tests\TestCase;

/**
 * Ask MotionGrove — persistent history: the list of a customer's own conversations, cursor-based older-message pages,
 * deletion, and the retention policy that governs how long it all lasts. Every read here is a plain database read: none of
 * it may ever reach the model.
 */
class AssistantHistoryTest extends TestCase
{
    use RefreshDatabase;
    use AssistantTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAssistant();
    }

    private function conversation($customer, $business, $workspace, int $messages, string $title, ?\DateTimeInterface $last = null, ?int $userId = null): AssistantConversation
    {
        $c = AssistantConversation::create([
            'uid' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'business_id' => $business->id, 'user_id' => $userId ?? $customer->user_id,
            'title' => $title, 'message_count' => $messages, 'last_message_at' => $last ?? now(),
        ]);

        for ($i = 1; $i <= $messages; $i++) {
            $isUser = $i % 2 === 1;
            AssistantMessage::create([
                'conversation_id' => $c->id, 'uid' => (string) Str::uuid(), 'client_message_id' => $isUser ? (string) Str::uuid() : null,
                'role' => $isUser ? 'user' : 'assistant', 'kind' => $isUser ? 'text' : 'answer',
                'body' => ($isUser ? 'Q' : 'A') . $i . ' of ' . $title, 'payload' => [], 'created_at' => now()->subMinutes($messages - $i),
            ]);
        }

        return $c;
    }

    // ── the history list ─────────────────────────────────────────────────

    public function test_the_list_is_this_customers_conversations_in_this_business_newest_first_with_a_real_preview(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'List Venue', 'List Account');
        $this->authenticateAs($customer);
        $older = $this->conversation($customer, $business, $workspace, 4, 'Older chat', now()->subDays(3));
        $newer = $this->conversation($customer, $business, $workspace, 2, 'Newer chat', now()->subHour());

        $r = $this->getJson($this->assistantUrl($workspace, $business, 'conversations'))->assertOk();

        $this->assertSame([$newer->uid, $older->uid], array_column($r->json('conversations'), 'uid'));
        $this->assertSame('Newer chat', $r->json('conversations.0.title'));
        $this->assertSame('A2 of Newer chat', $r->json('conversations.0.preview'), 'The preview is the last thing said.');
        $this->assertFalse($r->json('has_more'));
        $this->assertNull($r->json('next'));
        $this->assertSame(0, $this->aiCalls(), 'Opening history never calls the assistant.');
    }

    public function test_the_list_never_shows_another_customers_or_businesss_or_workspaces_conversations(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Mine Venue', 'Mine Account');
        [$other, $otherBusiness, $otherWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Other Venue', 'Other Account');
        $mine = $this->conversation($customer, $business, $workspace, 2, 'Mine');
        $theirs = $this->conversation($other, $otherBusiness, $otherWorkspace, 2, 'Theirs');
        // A teammate in MY Business: same workspace and Business, a different user — private to them.
        $teammate = $this->conversation($other, $business, $workspace, 2, 'Teammate private', null, $other->user_id);
        $this->authenticateAs($customer);

        $uids = array_column($this->getJson($this->assistantUrl($workspace, $business, 'conversations'))->assertOk()->json('conversations'), 'uid');

        $this->assertSame([$mine->uid], $uids);
        $this->assertNotContains($theirs->uid, $uids);
        $this->assertNotContains($teammate->uid, $uids, 'Team members\' private chats are never listed.');

        // Another customer's Business: refused by the tenancy gate, never answered with data.
        $this->getJson($this->assistantUrl($otherWorkspace, $otherBusiness, 'conversations'))->assertStatus(404);
    }

    public function test_the_list_pages_by_a_stable_cursor_without_gaps_or_repeats(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Page Venue', 'Page Account');
        $this->authenticateAs($customer);
        config(['assistant.history_page_size' => 3]);
        $made = [];
        // Two share the very same last-activity instant: the tie must break by id, never drop or repeat one.
        $same = now()->subHours(2);
        foreach ([1, 2, 3, 4, 5, 6, 7] as $i) {
            $made[] = $this->conversation($customer, $business, $workspace, 2, "Chat {$i}", $i === 4 || $i === 5 ? $same : now()->subHours($i * 3));
        }

        $seen = [];
        $first = $this->getJson($this->assistantUrl($workspace, $business, 'conversations'))->assertOk();
        $this->assertTrue($first->json('has_more'));
        $this->assertCount(3, $first->json('conversations'));
        $seen = array_column($first->json('conversations'), 'uid');
        $cursor = $first->json('next');

        // A conversation arriving meanwhile does not shift the older pages.
        $this->conversation($customer, $business, $workspace, 2, 'Arrived during paging', now());

        while ($cursor !== null) {
            $page = $this->getJson($this->assistantUrl($workspace, $business, 'conversations') . '?after=' . $cursor)->assertOk();
            $seen = array_merge($seen, array_column($page->json('conversations'), 'uid'));
            $cursor = $page->json('next');
        }

        $this->assertSame(7, count($seen));
        $this->assertSame($seen, array_values(array_unique($seen)), 'No repeats.');
        $this->assertEqualsCanonicalizing(array_column(array_map(fn ($c) => ['uid' => $c->uid], $made), 'uid'), $seen, 'No gaps.');
    }

    public function test_a_cursor_from_another_customer_is_refused_not_followed(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Cur Venue', 'Cur Account');
        [$other, $otherBusiness, $otherWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Cur Other', 'Cur Other Account');
        $theirs = $this->conversation($other, $otherBusiness, $otherWorkspace, 2, 'Theirs');
        $this->authenticateAs($customer);

        $this->getJson($this->assistantUrl($workspace, $business, 'conversations') . '?after=' . $theirs->uid)->assertStatus(404);
        $this->getJson($this->assistantUrl($workspace, $business, 'conversations') . '?after=not-a-uuid')->assertStatus(422);
    }

    // ── older messages ───────────────────────────────────────────────────

    public function test_older_messages_load_page_by_page_with_no_duplicates_and_a_clean_end(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Scroll Venue', 'Scroll Account');
        $this->authenticateAs($customer);
        $c = $this->conversation($customer, $business, $workspace, 95, 'Long');

        $latest = $this->getJson($this->assistantUrl($workspace, $business, 'show', ['conversation' => $c->uid]))->assertOk();
        $this->assertCount(40, $latest->json('messages'), 'The newest page is the initial view.');
        $this->assertTrue($latest->json('page.has_more'));
        $this->assertSame('Q95 of Long', $latest->json('messages.39.body'), 'The newest message is last, at the bottom.');

        $seen = array_column($latest->json('messages'), 'uid');
        $cursor = $latest->json('page.before');
        $pages = 1;

        while ($cursor !== null) {
            $older = $this->getJson($this->assistantUrl($workspace, $business, 'show', ['conversation' => $c->uid, 'before' => $cursor]))->assertOk();
            $uids = array_column($older->json('messages'), 'uid');
            $this->assertSame([], array_intersect($seen, $uids), 'A page never repeats a message the view already has.');
            $this->assertLessThanOrEqual(40, count($uids));
            $seen = array_merge($uids, $seen);
            $cursor = $older->json('page.has_more') ? $older->json('page.before') : null;
            $pages++;
        }

        $this->assertSame(3, $pages, '95 messages are three pages of 40.');
        $this->assertCount(95, $seen);
        $this->assertSame('Q1 of Long', AssistantMessage::query()->whereIn('uid', [$seen[0]])->value('body'), 'The very first message is reached, and the pager stops there.');
        $this->assertSame(0, $this->aiCalls(), 'Scrolling back through history never calls the assistant.');
    }

    public function test_the_older_page_cursor_is_stable_when_new_messages_arrive(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Stable Venue', 'Stable Account');
        $this->authenticateAs($customer);
        $c = $this->conversation($customer, $business, $workspace, 60, 'Moving');

        $latest = $this->getJson($this->assistantUrl($workspace, $business, 'show', ['conversation' => $c->uid]))->assertOk();
        $cursor = $latest->json('page.before');

        // Five more messages arrive after the first page was read.
        for ($i = 0; $i < 5; $i++) {
            AssistantMessage::create(['conversation_id' => $c->id, 'uid' => (string) Str::uuid(), 'role' => 'user', 'kind' => 'text', 'body' => 'late ' . $i, 'payload' => [], 'created_at' => now()]);
        }

        $older = $this->getJson($this->assistantUrl($workspace, $business, 'show', ['conversation' => $c->uid, 'before' => $cursor]))->assertOk();

        $this->assertCount(20, $older->json('messages'));
        $this->assertSame([], array_intersect(array_column($latest->json('messages'), 'uid'), array_column($older->json('messages'), 'uid')));
        $this->assertFalse($older->json('page.has_more'));
        $this->assertSame('Q1 of Moving', $older->json('messages.0.body'));
    }

    public function test_an_older_page_is_confined_to_its_own_conversation_and_owner(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Conf Venue', 'Conf Account');
        $mine = $this->conversation($customer, $business, $workspace, 50, 'Mine');
        $mate = $this->conversation($customer, $business, $workspace, 50, 'Mine too');
        [$other, $otherBusiness, $otherWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Conf Other', 'Conf Other Account');
        $theirs = $this->conversation($other, $otherBusiness, $otherWorkspace, 50, 'Theirs');
        $this->authenticateAs($customer);

        $cursorOfOther = AssistantMessage::query()->where('conversation_id', $mate->id)->orderBy('id')->skip(20)->value('uid');
        $cursorOfTheirs = AssistantMessage::query()->where('conversation_id', $theirs->id)->orderBy('id')->skip(20)->value('uid');

        // A message uid of a different conversation is not a cursor into this one.
        $this->getJson($this->assistantUrl($workspace, $business, 'show', ['conversation' => $mine->uid, 'before' => $cursorOfOther]))->assertStatus(404);
        $this->getJson($this->assistantUrl($workspace, $business, 'show', ['conversation' => $mine->uid, 'before' => $cursorOfTheirs]))->assertStatus(404);
        // Another customer's conversation is a 404 whatever the cursor.
        $this->getJson($this->assistantUrl($workspace, $business, 'show', ['conversation' => $theirs->uid]))->assertStatus(404);
        // Pages are bounded however they are asked for, and a malformed cursor is refused.
        $this->getJson($this->assistantUrl($workspace, $business, 'show', ['conversation' => $mine->uid, 'limit' => 5000]))->assertStatus(422);
        $this->getJson($this->assistantUrl($workspace, $business, 'show', ['conversation' => $mine->uid, 'before' => 'nope']))->assertStatus(422);
        $this->assertSame(0, $this->aiCalls());
    }

    // ── resuming and deleting ────────────────────────────────────────────

    public function test_a_past_conversation_can_be_opened_and_continued_and_the_ceiling_starts_a_new_one_without_losing_the_old(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Cap Venue', 'Cap Account');
        $this->authenticateAs($customer);
        config(['assistant.conversation_max_messages' => 6]);
        $full = $this->conversation($customer, $business, $workspace, 6, 'Full chat', now()->subDays(2));

        $opened = $this->getJson($this->assistantUrl($workspace, $business, 'show', ['conversation' => $full->uid]))->assertOk();
        $this->assertCount(6, $opened->json('messages'));

        $this->modelSays(['action' => 'answer', 'message' => 'Fresh start.']);
        $r = $this->say($workspace, $business, 'Anything new?', ['conversation' => $full->uid])->assertOk();

        $this->assertNotSame($full->uid, $r->json('uid'), 'The ceiling starts a new conversation for cost and context reasons…');
        $this->assertSame(6, AssistantMessage::query()->where('conversation_id', $full->id)->count(), '…and does not touch the old one.');

        $uids = array_column($this->getJson($this->assistantUrl($workspace, $business, 'conversations'))->json('conversations'), 'uid');
        $this->assertContains($full->uid, $uids, 'The old conversation is still in the history.');
        $this->assertContains($r->json('uid'), $uids);
    }

    public function test_deleting_a_conversation_removes_it_from_history_and_pages_and_is_only_for_its_owner(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Gone Venue', 'Gone Account');
        [$other, $otherBusiness, $otherWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Gone Other', 'Gone Other Account');
        $mine = $this->conversation($customer, $business, $workspace, 4, 'Delete me');
        $theirs = $this->conversation($other, $otherBusiness, $otherWorkspace, 4, 'Not yours');
        $this->authenticateAs($customer);

        $this->postJson($this->assistantUrl($workspace, $business, 'delete', [$theirs->uid]))->assertStatus(404);
        $this->assertSame(1, AssistantConversation::query()->where('uid', $theirs->uid)->count());

        $this->postJson($this->assistantUrl($workspace, $business, 'delete', [$mine->uid]))->assertOk()->assertJson(['deleted' => true]);

        $this->assertSame([], $this->getJson($this->assistantUrl($workspace, $business, 'conversations'))->json('conversations'));
        $this->getJson($this->assistantUrl($workspace, $business, 'show', ['conversation' => $mine->uid]))->assertStatus(404);
        $this->assertSame(0, AssistantMessage::query()->where('conversation_id', $mine->id)->count());
    }

    // ── retention policy ─────────────────────────────────────────────────

    public function test_retention_is_configurable_and_a_zero_keeps_conversations_until_the_customer_deletes_them(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Keep Venue', 'Keep Account');
        $ancient = $this->conversation($customer, $business, $workspace, 2, 'Ancient', now()->subDays(900));

        $this->assertSame(90, AssistantRetention::retentionDays(), 'The default is unchanged: 90 days.');

        config(['assistant.retention_days' => 1000]);
        $this->assertSame(1000, AssistantRetention::retentionDays(), 'A longer period is a configuration, not a code change.');
        $this->assertSame(0, (new AssistantRetention())->purgeExpired(), 'Not yet 1000 days old.');

        config(['assistant.retention_days' => 100000]);
        $this->assertSame(3650, AssistantRetention::retentionDays(), 'A period is bounded.');

        config(['assistant.retention_days' => 0]);
        $this->assertTrue(AssistantRetention::keepsUntilDeleted());
        $this->assertSame(0, (new AssistantRetention())->purgeExpired(), 'Nothing ages out under "keep until deleted".');
        $this->artisan('assistant:purge-expired')->assertExitCode(0);
        $this->assertSame(1, AssistantConversation::query()->where('uid', $ancient->uid)->count());

        config(['assistant.retention_days' => 90]);
        $this->assertSame(1, (new AssistantRetention())->purgeExpired(), 'And the default policy still removes what is past it.');
    }

    public function test_the_disclosure_states_the_policy_actually_in_force(): void
    {
        config(['assistant.retention_days' => 90]);
        $this->assertStringContainsString('90 days', AssistantRetention::disclosure());

        config(['assistant.retention_days' => 400]);
        $this->assertStringContainsString('400 days', AssistantRetention::disclosure());

        config(['assistant.retention_days' => 0]);
        $text = AssistantRetention::disclosure();
        $this->assertStringContainsString('until you delete them', $text);
        $this->assertStringNotContainsString(' days', $text, 'No period is claimed when there is none.');
    }
}
