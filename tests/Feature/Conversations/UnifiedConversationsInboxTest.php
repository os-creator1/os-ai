<?php

namespace Tests\Feature\Conversations;

use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\Business;
use App\Models\BusinessEmailMessage;
use App\Models\BusinessLocation;
use App\Models\ChatBox;
use App\Models\Customer;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\BusinessEmail\Concerns\CreatesBusinessEmailFixtures;
use Tests\TestCase;

/**
 * The unified Conversations inbox: SMS and Email in one list, one timeline and one
 * composer, composed over the existing SMS and email read/send paths.
 */
class UnifiedConversationsInboxTest extends TestCase
{
    use CreatesBusinessEmailFixtures;
    use RefreshDatabase;

    private Customer $owner;

    private Business $business;

    private Workspace $workspace;

    private BusinessLocation $locA;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->bindFakeEmailProviders();

        [$this->owner, $this->business, $this->workspace] = $this->emailTenant();
        $this->locA = BusinessLocation::query()->where('business_id', $this->business->id)->firstOrFail();
    }

    private function route(string $name, array $extra = []): string
    {
        return route('customer.workspaces.businesses.conversations.' . $name, array_merge([$this->workspace->uid, $this->business->uid], $extra));
    }

    private function box(string $to, string $text, \DateTimeInterface $at): ChatBox
    {
        $box = new ChatBox(['user_id' => $this->business->customer_id, 'business_id' => $this->business->id, 'from' => '15550000000', 'to' => $to, 'reply_by_customer' => true]);
        $box->uid = (string) Str::uuid();
        $box->save();
        DB::table('chat_boxes')->where('id', $box->id)->update(['updated_at' => $at]);
        DB::table('chat_box_messages')->insert(['box_id' => $box->id, 'message' => $text, 'direction' => 'in', 'sms_type' => 'plain', 'send_by' => 'to', 'created_at' => $at, 'updated_at' => $at]);

        return $box->refresh();
    }

    private function email(string $subject, string $thread, \DateTimeInterface $at, string $from = 'stranger@example.com', string $direction = 'inbound', ?int $contactId = null): BusinessEmailMessage
    {
        return BusinessEmailMessage::create([
            'business_id' => $this->business->id, 'location_id' => $this->locA->id, 'direction' => $direction, 'contact_id' => $contactId,
            'operation_key' => 'seed-' . Str::uuid(), 'provider' => 'google', 'provider_message_id' => 'pm-' . Str::uuid(),
            'provider_thread_id' => $thread, 'internet_message_id' => '<' . Str::uuid() . '@example.com>',
            'from_email' => $direction === 'inbound' ? $from : 'owner@business.test', 'to_email' => $direction === 'inbound' ? 'owner@business.test' : $from,
            'subject' => $subject, 'body_text' => 'Body of ' . $subject, 'status' => $direction === 'inbound' ? 'received' : 'accepted', 'received_at' => $at,
        ]);
    }

    private function list(string $channel = 'all', array $extra = []): string
    {
        return $this->post($this->route('load'), array_merge(['channel' => $channel, 'filter' => 'recents'], $extra))->assertOk()->getContent();
    }

    public function test_an_sms_only_account_lists_sms_and_explains_email_is_not_connected(): void
    {
        $this->box('15551110001', 'Hi from SMS', now());
        $this->authenticateAs($this->owner);

        $all = $this->list('all');
        $this->assertStringContainsString('Hi from SMS', $all);
        $this->assertStringContainsString('data-channel="sms"', $all);

        $email = $this->list('email');
        $this->assertStringNotContainsString('Hi from SMS', $email);
        $this->assertStringContainsString("Email isn't connected", $email);
    }

    public function test_all_sms_and_email_filters_and_newest_activity_first_ordering(): void
    {
        $this->activeAccount($this->business);
        $this->box('15551110001', 'Older text', now()->subHours(3));
        $this->email('Newer email', 't-new', now()->subHour());
        $this->authenticateAs($this->owner);

        $all = $this->list('all');
        $this->assertLessThan(strpos($all, 'Older text'), strpos($all, 'Newer email'), 'the newer email row comes before the older SMS row');

        $sms = $this->list('sms');
        $this->assertStringContainsString('Older text', $sms);
        $this->assertStringNotContainsString('Newer email', $sms);

        $email = $this->list('email');
        $this->assertStringContainsString('Newer email', $email);
        $this->assertStringNotContainsString('Older text', $email);
        $this->assertStringContainsString('data-channel="email"', $email);
    }

    public function test_a_contact_matched_email_is_one_row_with_the_sms_and_one_chronological_timeline(): void
    {
        $this->activeAccount($this->business);
        $contact = $this->contactWithEmails($this->business, ['pat@example.com'], $this->locA->id, 'Pat');
        $box = $this->box($contact->phone, 'First, a text', now()->subHours(2));
        $this->email('Then an email', 't-pat', now()->subHour(), 'pat@example.com', contactId: $contact->id);
        $this->authenticateAs($this->owner);

        $all = $this->list('all');
        $this->assertSame(1, substr_count($all, 'data-role="conversation-row-title"'), 'the person appears once in All');
        $this->assertStringContainsString('SMS · Email', $all);

        $json = $this->post($this->route('timeline', [$box->uid]))->assertOk()->json();
        $html = $json['timeline'];
        $this->assertLessThan(strpos($html, 'Then an email'), strpos($html, 'First, a text'));
        $this->assertStringContainsString('data-channel="sms"', $html);
        $this->assertStringContainsString('data-channel="email"', $html);
        $this->assertTrue($json['composer']['sms']);
        $this->assertTrue($json['composer']['email'], 'both channels are offered when both are usable');

        // The Email filter's row opens the SAME person timeline, on the SMS conversation.
        $opened = $this->post($this->route('email_thread'), ['thread' => 't-pat'])->assertOk()->json();
        $this->assertSame($box->uid, $opened['conversation_uid']);
        $this->assertStringContainsString('First, a text', $opened['timeline']);
    }

    public function test_an_unmatched_email_stays_its_own_thread_and_never_borrows_a_contacts_identity(): void
    {
        $this->activeAccount($this->business);
        // A Contact who happens to share the sender's NAME is not the sender.
        $this->contactWithEmails($this->business, ['someone.else@example.com'], $this->locA->id, 'Stranger');
        $this->email('Who is this', 't-un', now(), 'stranger@example.com');
        $this->authenticateAs($this->owner);

        $all = $this->list('all');
        $this->assertStringContainsString('stranger@example.com', $all);
        $this->assertStringContainsString('data-channel="email"', $all);

        $opened = $this->post($this->route('email_thread'), ['thread' => 't-un'])->assertOk()->json();
        $this->assertArrayNotHasKey('conversation_uid', $opened);
        $this->assertStringContainsString("isn't linked to a contact", $opened['context']);
        $this->assertStringNotContainsString('someone.else@example.com', $opened['context']);
        $this->assertFalse($opened['composer']['sms']);
        $this->assertTrue($opened['composer']['email']);
        $this->assertSame('email', $opened['composer']['default']);
        $this->assertStringContainsString('Re: Who is this', $opened['composer']['subject']);
    }

    public function test_email_not_connected_offers_sms_only_and_never_switches_channel_silently(): void
    {
        $contact = $this->contactWithEmails($this->business, ['pat@example.com'], $this->locA->id, 'Pat');
        $box = $this->box($contact->phone, 'Text only', now());
        $this->authenticateAs($this->owner);

        $composer = $this->post($this->route('timeline', [$box->uid]))->assertOk()->json('composer');

        $this->assertTrue($composer['sms']);
        $this->assertFalse($composer['email']);
        $this->assertTrue($composer['email_guidance']);
        $this->assertNull($composer['url']);
    }

    public function test_the_composer_replies_through_the_existing_endpoint_with_json_and_keeps_provider_threading(): void
    {
        $this->activeAccount($this->business);
        $inbound = $this->email('Hello', 't-a', now());
        $this->authenticateAs($this->owner);

        $composer = $this->post($this->route('email_thread'), ['thread' => 't-a'])->assertOk()->json('composer');
        $this->assertStringEndsWith('/messages/' . $inbound->uid . '/reply', $composer['url']);

        $this->postJson($composer['url'], ['subject' => 'Re: Hello', 'body' => 'Thanks for writing.', 'send_token' => (string) Str::uuid()])
            ->assertOk()->assertJson(['status' => 'success']);

        $this->assertSame(1, $this->fakeGoogle->callCount('send'));
        $this->assertSame('t-a', $this->fakeGoogle->sent[0]->threadId);
        $this->assertSame($inbound->internet_message_id, $this->fakeGoogle->sent[0]->inReplyTo);
    }

    public function test_the_former_email_page_redirects_into_the_inbox_email_filter(): void
    {
        $this->authenticateAs($this->owner);

        $url = route('customer.workspaces.businesses.conversations.email', [$this->workspace->uid, $this->business->uid, 'thread' => 't-a']);

        $this->get($url)->assertRedirect($this->route('index', ['channel' => 'email', 'thread' => 't-a']));
    }

    public function test_email_search_and_pagination_do_not_break_the_sms_search(): void
    {
        $this->activeAccount($this->business);
        $this->box('15551110001', 'Findable text', now()->subHour());
        $this->email('Invoice question', 't-inv', now());
        $this->email('Something else', 't-else', now()->subMinute());
        $this->authenticateAs($this->owner);

        $found = $this->list('all', ['search' => 'Invoice']);
        $this->assertStringContainsString('Invoice question', $found);
        $this->assertStringNotContainsString('Something else', $found);

        $this->assertStringContainsString('15551110001', $this->list('all', ['search' => '15551110001']));
        // A page past the end is empty, not an error.
        $this->assertStringNotContainsString('Invoice question', $this->list('email', ['page' => 9]));
    }

    public function test_location_limited_staff_cannot_open_a_thread_outside_their_reach(): void
    {
        $this->activeAccount($this->business);
        $locB = $this->makeLocation($this->business, 'Location B');
        $staff = $this->createCustomer();
        $membership = $this->member($this->workspace, $staff->user, WorkspaceMembershipRole::Staff);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $this->locA);

        $this->email('Visible', 't-a', now());
        $hidden = $this->email('Only B', 't-b', now());
        $hidden->forceFill(['location_id' => $locB->id])->save();

        $this->authenticateAs($staff, ['chat_box', 'view_contact']);

        $list = $this->list('email');
        $this->assertStringContainsString('Visible', $list);
        $this->assertStringNotContainsString('Only B', $list);
        $this->post($this->route('email_thread'), ['thread' => 't-b'])->assertNotFound();
    }

    public function test_another_business_cannot_read_this_inbox(): void
    {
        $this->activeAccount($this->business);
        $this->email('Private', 't-a', now());
        [$otherOwner] = $this->emailTenant('Other Studio');
        $this->authenticateAs($otherOwner);

        $this->post($this->route('load'), ['channel' => 'email'])->assertNotFound();
        $this->post($this->route('email_thread'), ['thread' => 't-a'])->assertNotFound();
    }

    public function test_a_new_email_to_a_contact_without_a_location_offers_the_actors_accessible_locations(): void
    {
        $this->activeAccount($this->business);
        $locB = $this->makeLocation($this->business, 'Location B');
        $contact = $this->contactWithEmails($this->business, ['pat@example.com'], null, 'Pat');
        $box = $this->box($contact->phone, 'Text', now());
        $this->authenticateAs($this->owner);

        $composer = $this->post($this->route('timeline', [$box->uid]))->assertOk()->json('composer');

        $this->assertTrue($composer['email']);
        $this->assertEqualsCanonicalizing([$this->locA->uid, $locB->uid], array_column($composer['locations'], 'uid'));
        $this->assertStringEndsWith('/email/send', $composer['url']);
    }

    public function test_no_location_choice_is_offered_when_the_contacts_own_or_the_single_location_is_provable(): void
    {
        $this->activeAccount($this->business);

        // One active Location: the sender's own fallback, nothing to choose.
        $contact = $this->contactWithEmails($this->business, ['pat@example.com'], null, 'Pat');
        $box = $this->box($contact->phone, 'Text', now());
        $this->authenticateAs($this->owner);
        $this->assertSame([], $this->post($this->route('timeline', [$box->uid]))->assertOk()->json('composer.locations'));

        // Several active Locations, but the Contact belongs to one: that Location is used, never asked.
        $this->makeLocation($this->business, 'Location B');
        $placed = $this->contactWithEmails($this->business, ['lee@example.com'], $this->locA->id, 'Lee');
        $placedBox = $this->box($placed->phone, 'Text', now());
        $composer = $this->post($this->route('timeline', [$placedBox->uid]))->assertOk()->json('composer');
        $this->assertTrue($composer['email']);
        $this->assertSame([], $composer['locations']);
    }

    public function test_email_is_not_offered_when_no_authorized_location_can_be_chosen(): void
    {
        $this->activeAccount($this->business);
        $locB = $this->makeLocation($this->business, 'Location B');
        $staff = $this->createCustomer();
        $membership = $this->member($this->workspace, $staff->user, WorkspaceMembershipRole::Staff);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        // Selected scope with NO assigned Location: nothing they may choose, and nothing to guess.
        $contact = $this->contactWithEmails($this->business, ['pat@example.com'], null, 'Pat');
        $box = $this->box($contact->phone, 'Text', now());
        $this->authenticateAs($staff, ['chat_box', 'view_contact']);

        $response = $this->post($this->route('timeline', [$box->uid]));

        if ($response->status() === 200) {
            $composer = $response->json('composer');
            $this->assertFalse($composer['email']);
            $this->assertSame([], $composer['locations']);
            $this->assertNotContains($locB->uid, array_column($composer['locations'], 'uid'));
        } else {
            $response->assertNotFound(); // the conversation itself is outside their reach
        }
    }

    public function test_search_finds_an_older_email_even_when_the_same_contacts_newer_sms_does_not_match(): void
    {
        $this->activeAccount($this->business);
        $contact = $this->contactWithEmails($this->business, ['pat@example.com'], $this->locA->id, 'Pat');
        $this->box($contact->phone, 'See you today', now());
        $this->email('Invoice for May', 't-inv', now()->subDay(), 'pat@example.com', contactId: $contact->id);
        $this->authenticateAs($this->owner);

        $found = $this->list('all', ['search' => 'Invoice']);

        $this->assertSame(1, substr_count($found, 'data-role="conversation-row-title"'), 'the person is found, once');
        $this->assertStringContainsString('Invoice for May', $found);
        $this->assertStringContainsString('SMS · Email', $found);
        // And the plain SMS search still works.
        $this->assertStringContainsString('See you today', $this->list('all', ['search' => $contact->phone]));
    }

    /** @return list<string> row ids in page order */
    private function rowIds(string $html): array
    {
        preg_match_all('/<li data-id="([^"]+)"/', $html, $m);

        return $m[1];
    }

    public function test_all_paginates_sixty_email_threads_without_any_sms_conversations(): void
    {
        $this->activeAccount($this->business);
        $base = now()->startOfMinute();

        for ($i = 0; $i < 60; $i++) {
            $this->email('Subject ' . $i, 't-' . $i, $base->copy()->subMinutes($i));
        }

        $this->authenticateAs($this->owner);

        $one = $this->rowIds($this->list('all', ['page' => 1]));
        $two = $this->rowIds($this->list('all', ['page' => 2]));
        $three = $this->rowIds($this->list('all', ['page' => 3]));

        $this->assertCount(50, $one);
        $this->assertCount(10, $two);
        $this->assertSame([], $three);
        $this->assertSame([], array_intersect($one, $two), 'no thread is repeated across pages');
        $this->assertSame(array_map(fn ($i) => 'email:t-' . $i, range(0, 59)), array_merge($one, $two), 'newest first, page after page');
    }

    public function test_mixed_sms_and_email_pages_are_bounded_ordered_and_not_duplicated(): void
    {
        $this->activeAccount($this->business);
        $base = now()->startOfMinute();
        $expected = [];

        for ($i = 0; $i < 30; $i++) {
            $box = $this->box('1555222' . str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'Text ' . $i, $base->copy()->subMinutes(2 * $i));
            $this->email('Subject ' . $i, 't-' . $i, $base->copy()->subMinutes(2 * $i + 1));
            $expected[] = $box->uid;
            $expected[] = 'email:t-' . $i;
        }

        $this->authenticateAs($this->owner);

        $one = $this->rowIds($this->list('all', ['page' => 1]));
        $two = $this->rowIds($this->list('all', ['page' => 2]));

        $this->assertCount(50, $one);
        $this->assertCount(10, $two);
        $this->assertSame($expected, array_merge($one, $two));

        // SMS-only pagination is unchanged: its own 50-row pages.
        $this->assertCount(30, $this->rowIds($this->list('sms')));
    }

    public function test_a_contact_in_an_inaccessible_location_is_not_named_in_email_lists_but_the_visible_email_still_is(): void
    {
        $this->activeAccount($this->business);
        $locB = $this->makeLocation($this->business, 'Location B');
        $staff = $this->createCustomer();
        $membership = $this->member($this->workspace, $staff->user, WorkspaceMembershipRole::Staff);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $this->locA);

        $restricted = $this->contactWithEmails($this->business, ['secret@example.com'], $locB->id, 'Restricted');
        $allowed = $this->contactWithEmails($this->business, ['open@example.com'], $this->locA->id, 'Allowed');
        $unplaced = $this->contactWithEmails($this->business, ['free@example.com'], null, 'Unplaced');
        // The EMAIL rows are in Location A (visible to this staff member); one references a Contact assigned to Location B.
        $this->email('About a quote', 't-r', now()->subMinutes(1), 'secret@example.com', contactId: $restricted->id);
        $this->email('Opening hours', 't-a', now()->subMinutes(2), 'open@example.com', contactId: $allowed->id);
        $this->email('A question', 't-u', now()->subMinutes(3), 'free@example.com', contactId: $unplaced->id);

        $this->authenticateAs($staff, ['chat_box', 'view_contact']);

        foreach (['email', 'all'] as $channel) {
            $html = $this->list($channel);
            $this->assertStringContainsString('About a quote', $html, "$channel: the visible email stays visible");
            $this->assertStringContainsString('secret@example.com', $html, "$channel: shown by its permitted address");
            $this->assertStringNotContainsString('Restricted', $html, "$channel: the restricted Contact's name is never disclosed");
            $this->assertStringContainsString('Allowed', $html, "$channel: an accessible Contact is named");
            $this->assertStringContainsString('Unplaced', $html, "$channel: a Location-less Contact is named");
        }

        // A full-reach owner still sees the name.
        $this->authenticateAs($this->owner);
        $this->assertStringContainsString('Restricted', $this->list('email'));
    }
}
