<?php

namespace Tests\Feature\Conversations;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Conversations\Concerns\CreatesTimelineFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Conversations — the three-pane screen: the conversation list, the person's
 * activity timeline with an SMS composer, and the contact panel. Opening a
 * conversation returns both panes rendered and escaped by the server, through
 * the same Business-first authorization chain as every other conversation
 * action.
 */
class ConversationTimelineScreenTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;
    use CreatesTimelineFixtures;

    private const PHONE = '12025550111';

    protected function setUp(): void
    {
        parent::setUp();

        // The first user is always a super admin; keep the customer from being it.
        User::create([
            'first_name' => 'Placeholder',
            'last_name' => 'SuperAdmin',
            'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);

        $this->travelTo(Carbon::parse('2026-09-10 15:00:00'));
    }

    public function test_the_screen_has_a_list_a_timeline_with_an_sms_composer_and_a_contact_panel(): void
    {
        [$business, $workspace] = $this->signedInOwner();
        $this->conversationWith($business, self::PHONE);

        $html = $this->get(route('customer.workspaces.businesses.conversations.index', [$workspace->uid, $business->uid]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="users-list"', $html);
        $this->assertStringContainsString('data-role="conversation-timeline"', $html);
        $this->assertStringContainsString('id="conversation-context"', $html);
        $this->assertStringContainsString(
            route('customer.workspaces.businesses.conversations.timeline', [$workspace->uid, $business->uid, '__UID__']),
            $html,
        );

        $composer = $this->between($html, '<form class="chat-app-form"', '</form>');
        $this->assertStringContainsString('data-role="composer-channel">SMS</span>', $composer);
        $this->assertStringNotContainsStringIgnoringCase('email', $composer, 'No Email option until a Business has email.');
        $this->assertStringContainsString('id="message"', $composer);
    }

    public function test_opening_a_conversation_returns_the_timeline_and_the_person_beside_it(): void
    {
        [$business, $workspace] = $this->signedInOwner();
        $contact = $this->namedContact($business, self::PHONE, 'Maya', 'Lopez', 'maya@example.test');
        $box = $this->conversationWith($business, self::PHONE);
        $this->message($box, 'incoming', 'Do you have Saturday slots?', Carbon::parse('2026-09-10 10:00:00'));
        $this->message($box, 'outgoing', 'Yes — 10am or 2pm.', Carbon::parse('2026-09-10 10:05:00'));

        $response = $this->openTimeline($workspace, $business, $box)->assertOk()->assertJson(['status' => 'success', 'title' => 'Maya Lopez']);

        $timeline = $response->json('timeline');
        $this->assertStringContainsString('data-role="timeline-day"', $timeline);
        $this->assertStringContainsString('Today', $timeline);
        $this->assertMatchesRegularExpression('/data-direction="inbound".*Do you have Saturday slots\?/s', $timeline);
        $this->assertMatchesRegularExpression('/data-direction="outbound".*Yes — 10am or 2pm\./s', $timeline);
        $this->assertStringContainsString('Added to contacts', $timeline);

        $panel = $response->json('context');
        $this->assertStringContainsString('data-role="contact-panel-name">Maya Lopez', $panel);
        $this->assertStringContainsString(self::PHONE, $panel);
        $this->assertStringContainsString('maya@example.test', $panel);
        $this->assertStringContainsString('Clients', $panel);
        $this->assertStringContainsString('Subscribed to texts', $panel);
        $this->assertStringContainsString(route('customer.workspaces.businesses.people.show', [$workspace->uid, $business->uid, $contact->uid]), $panel);
    }

    public function test_message_text_and_contact_details_are_escaped_by_the_server(): void
    {
        [$business, $workspace] = $this->signedInOwner();
        $this->namedContact($business, self::PHONE, '<b>Maya</b>', 'Lopez');
        $box = $this->conversationWith($business, self::PHONE);
        $this->message($box, 'incoming', '<img src=x onerror=alert(1)>', Carbon::parse('2026-09-10 10:00:00'), 'javascript:alert(2)');

        $response = $this->openTimeline($workspace, $business, $box)->assertOk();

        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $response->json('timeline'));
        $this->assertStringNotContainsString('<img src=x', $response->json('timeline'));
        $this->assertStringNotContainsString('javascript:alert(2)', $response->json('timeline'), 'A media URL that is not http(s) is never rendered.');
        $this->assertStringContainsString('&lt;b&gt;Maya&lt;/b&gt;', $response->json('context'));
        $this->assertStringNotContainsString('<b>Maya</b>', $response->json('context'));
    }

    public function test_a_number_two_contacts_share_shows_the_number_alone(): void
    {
        [$business, $workspace] = $this->signedInOwner();
        $this->namedContact($business, self::PHONE, 'Maya', 'Lopez');
        $this->namedContact($business, self::PHONE, 'Marco', 'Lopez');
        $box = $this->conversationWith($business, self::PHONE);

        $response = $this->openTimeline($workspace, $business, $box)->assertOk()->assertJson(['title' => self::PHONE]);

        $this->assertStringContainsString('This number is not linked to a contact.', $response->json('context'));
        $this->assertStringNotContainsString('Maya', $response->json('context'));
        $this->assertStringNotContainsString('Marco', $response->json('context'));
        $this->assertStringNotContainsString('people', $response->json('context'));
    }

    public function test_a_number_on_the_block_list_says_so_in_the_panel_and_the_timeline(): void
    {
        [$business, $workspace] = $this->signedInOwner();
        $this->namedContact($business, self::PHONE, 'Maya', 'Lopez');
        $box = $this->conversationWith($business, self::PHONE);
        $this->blockListEntry($business, self::PHONE, 'Optout by User', Carbon::parse('2026-09-09 10:00:00'));

        $response = $this->openTimeline($workspace, $business, $box)->assertOk();

        $this->assertStringContainsString('On your block list', $response->json('context'));
        $this->assertStringContainsString('Opted out of texts', $response->json('timeline'));
    }

    public function test_the_profile_link_needs_permission_to_view_contacts(): void
    {
        [$business, $workspace] = $this->signedInOwner();
        $this->namedContact($business, self::PHONE, 'Maya', 'Lopez');
        $box = $this->conversationWith($business, self::PHONE);

        $this->withSession(['permissions' => collect(['access_backend', 'chat_box'])]);

        $panel = $this->openTimeline($workspace, $business, $box)->assertOk()->json('context');

        $this->assertStringContainsString('Maya Lopez', $panel, 'The person is still shown…');
        $this->assertStringNotContainsString('data-role="contact-panel-profile"', $panel, '…but not a link the viewer could not open.');
    }

    public function test_every_tenancy_failure_is_the_same_404(): void
    {
        [$business, $workspace] = $this->signedInOwner();
        $this->namedContact($business, self::PHONE, 'Maya', 'Lopez');

        $owner = $this->createCustomer();
        $otherWorkspace = $this->createWorkspace($owner->user, ['name' => 'Elsewhere']);
        $foreignBusiness = $this->addBusiness($owner, $otherWorkspace, 'Elsewhere Studio');
        $foreignBox = $this->conversationWith($foreignBusiness, self::PHONE);

        $legacyBox = new ChatBox(['user_id' => $business->customer_id, 'business_id' => null, 'from' => self::BUSINESS_NUMBER, 'to' => self::PHONE]);
        $legacyBox->uid = (string) \Illuminate\Support\Str::uuid();
        $legacyBox->save();

        foreach ([$foreignBox->uid, $legacyBox->uid, (string) \Illuminate\Support\Str::uuid(), (string) $foreignBox->id] as $uid) {
            $this->postJson(route('customer.workspaces.businesses.conversations.timeline', [$workspace->uid, $business->uid, $uid]))
                ->assertStatus(404)
                ->assertExactJson(['status' => 'error', 'message' => 'Chat box not found.']);
        }

        $ownBox = $this->conversationWith($business, self::PHONE);
        $this->postJson(route('customer.workspaces.businesses.conversations.timeline', [$otherWorkspace->uid, $business->uid, $ownBox->uid]))
            ->assertStatus(404);
    }

    public function test_the_list_leads_with_the_persons_name_and_the_raw_thread_endpoint_is_unchanged(): void
    {
        [$business, $workspace] = $this->signedInOwner();
        $this->namedContact($business, self::PHONE, 'Maya', 'Lopez');
        $box = $this->conversationWith($business, self::PHONE);
        $this->message($box, 'incoming', 'Hello there', Carbon::parse('2026-09-10 10:00:00'));

        $list = $this->post(route('customer.workspaces.businesses.conversations.load', [$workspace->uid, $business->uid]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-role="conversation-row-title">Maya Lopez</', $list);
        $this->assertStringContainsString('Hello there', $list);

        $this->postJson(route('customer.workspaces.businesses.conversations.messages', [$workspace->uid, $business->uid, $box->uid]))
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message', 'Hello there');
    }

    public function test_the_read_filter_includes_a_conversation_never_marked_unread_and_stays_inside_the_business(): void
    {
        [$business, $workspace] = $this->signedInOwner();

        $neverUnread = $this->conversationWith($business, '12025550122');
        $read = $this->conversationWith($business, '12025550133');
        $read->update(['notification' => 0]);
        $unread = $this->conversationWith($business, '12025550144');
        $unread->update(['notification' => 3]);

        $owner = $this->createCustomer();
        $elsewhere = $this->addBusiness($owner, $this->createWorkspace($owner->user, ['name' => 'Elsewhere']), 'Elsewhere Studio');
        $foreign = $this->conversationWith($elsewhere, '12025550155');

        $this->assertNull($neverUnread->fresh()->notification, 'Precondition: a new conversation has no unread count at all.');

        $list = $this->post(route('customer.workspaces.businesses.conversations.load', [$workspace->uid, $business->uid]), ['filter' => 'read'])->assertOk()->getContent();

        $this->assertStringContainsString('data-id="' . $neverUnread->uid . '"', $list);
        $this->assertStringContainsString('data-id="' . $read->uid . '"', $list);
        $this->assertStringNotContainsString('data-id="' . $unread->uid . '"', $list);
        $this->assertStringNotContainsString('data-id="' . $foreign->uid . '"', $list);

        $unreadList = $this->post(route('customer.workspaces.businesses.conversations.load', [$workspace->uid, $business->uid]), ['filter' => 'unread'])->assertOk()->getContent();
        $this->assertStringContainsString('data-id="' . $unread->uid . '"', $unreadList);
        $this->assertStringNotContainsString('data-id="' . $neverUnread->uid . '"', $unreadList);
    }

    // -----------------------------------------------------------------

    /** @return array{0: Business, 1: Workspace} */
    private function signedInOwner(): array
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core, 'Harbor Lane Studios', 'Harbor Lane');

        $user = $customer->user;
        $user->email_verified_at = now();
        $user->save();

        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        $this->actingAs($user);

        return [$business, $workspace];
    }

    private function openTimeline(Workspace $workspace, Business $business, ChatBox $box): TestResponse
    {
        return $this->postJson(route('customer.workspaces.businesses.conversations.timeline', [$workspace->uid, $business->uid, $box->uid]));
    }

    private function between(string $haystack, string $start, string $end): string
    {
        $from = strpos($haystack, $start);
        $this->assertNotFalse($from, "Expected to find {$start}.");

        return substr($haystack, $from, strpos($haystack, $end, $from) - $from);
    }
}
