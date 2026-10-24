<?php

namespace Tests\Feature\BusinessEmail;

use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Models\Business;
use App\Models\BusinessEmailMessage;
use App\Models\BusinessLocation;
use App\Models\Customer;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\BusinessEmail\Concerns\CreatesBusinessEmailFixtures;
use Tests\TestCase;

/**
 * Gmail threads inside Conversations (Conversations → Email) and the reply
 * endpoint they use: listing, replying to a sender who is not a Contact,
 * business / Location isolation, unresolved-Location handling, and proof that
 * the SMS routes are untouched. Provider is an in-memory fake.
 */
class BusinessEmailConversationsTest extends TestCase
{
    use CreatesBusinessEmailFixtures;
    use RefreshDatabase;

    private Customer $owner;

    private Business $business;

    private Workspace $workspace;

    private BusinessLocation $locA;

    private BusinessLocation $locB;

    private Customer $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->bindFakeEmailProviders();

        [$this->owner, $this->business, $this->workspace] = $this->emailTenant();
        $this->activeAccount($this->business);

        $this->locA = BusinessLocation::query()->where('business_id', $this->business->id)->firstOrFail();
        $this->locB = $this->makeLocation($this->business, 'Location B');

        $this->staff = $this->createCustomer();
        $membership = $this->member($this->workspace, $this->staff->user, WorkspaceMembershipRole::Staff);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();
        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $this->locA);
    }

    private function seed(?BusinessLocation $location, string $subject, string $thread, string $from = 'stranger@example.com', string $direction = 'inbound', ?int $contactId = null): BusinessEmailMessage
    {
        return BusinessEmailMessage::create([
            'business_id' => $this->business->id,
            'location_id' => $location?->id,
            'direction' => $direction,
            'contact_id' => $contactId,
            'operation_key' => 'seed-' . Str::uuid(),
            'provider' => 'google',
            'provider_message_id' => 'pm-' . Str::uuid(),
            'provider_thread_id' => $thread,
            'internet_message_id' => '<' . Str::uuid() . '@example.com>',
            'from_email' => $direction === 'inbound' ? $from : 'owner@business.test',
            'to_email' => $direction === 'inbound' ? 'owner@business.test' : $from,
            'subject' => $subject,
            'body_text' => 'Body of ' . $subject,
            'status' => $direction === 'inbound' ? 'received' : 'accepted',
            'received_at' => now(),
        ]);
    }

    private function convUrl(?string $thread = null, ?Business $business = null, ?Workspace $workspace = null): string
    {
        return route('customer.workspaces.businesses.conversations.email', array_filter([
            ($workspace ?? $this->workspace)->uid, ($business ?? $this->business)->uid, 'thread' => $thread,
        ]));
    }

    private function replyUrl(BusinessEmailMessage $message, ?Business $business = null, ?Workspace $workspace = null): string
    {
        return route('customer.workspaces.businesses.email.reply', [($workspace ?? $this->workspace)->uid, ($business ?? $this->business)->uid, $message->uid]);
    }

    /** @return array<string, string> */
    private function form(array $extra = []): array
    {
        return array_merge(['subject' => 'Re: Hello', 'body' => 'Thanks for writing.', 'send_token' => (string) Str::uuid(), 'return_to' => 'conversations'], $extra);
    }

    public function test_gmail_threads_are_listed_in_conversations_with_sender_subject_time_and_body(): void
    {
        $this->seed($this->locA, 'Quote request', 't-a');
        $this->seed($this->locA, 'Re: Quote request', 't-a', direction: 'outbound');
        $this->authenticateAs($this->owner);

        $list = $this->get($this->convUrl())->assertOk()->getContent();
        $this->assertStringContainsString('Quote request', $list);
        $this->assertStringContainsString('2 messages', $list);

        $thread = $this->get($this->convUrl('t-a'))->assertOk()->getContent();
        $this->assertStringContainsString('stranger@example.com', $thread);
        $this->assertStringContainsString('Body of Quote request', $thread);
        $this->assertStringContainsString('You (owner@business.test)', $thread);
    }

    public function test_an_authorized_user_can_reply_to_a_sender_who_is_not_a_contact_without_creating_one(): void
    {
        $inbound = $this->seed($this->locA, 'Hello', 't-a');
        $contactsBefore = DB::table('contacts')->count();
        $this->authenticateAs($this->owner);

        // The recipient is the stored sender; a browser-supplied address is ignored.
        $this->post($this->replyUrl($inbound), $this->form(['to_email' => 'attacker@evil.test']))
            ->assertRedirect($this->convUrl('t-a'));

        $this->assertSame(1, $this->fakeGoogle->callCount('send'));
        $sent = $this->fakeGoogle->sent[0];
        $this->assertSame('stranger@example.com', $sent->toEmail);
        $this->assertSame('t-a', $sent->threadId);
        $this->assertSame($inbound->internet_message_id, $sent->inReplyTo);

        $out = BusinessEmailMessage::query()->where('direction', 'outbound')->firstOrFail();
        $this->assertNull($out->contact_id);
        $this->assertSame($this->locA->id, (int) $out->location_id);
        $this->assertSame($contactsBefore, DB::table('contacts')->count());
    }

    public function test_a_matched_contact_stays_associated_on_reply(): void
    {
        $contact = $this->contactWithEmails($this->business, ['pat@example.com'], $this->locA->id);
        $inbound = $this->seed($this->locA, 'Hello', 't-a', 'pat@example.com', contactId: $contact->id);
        $this->authenticateAs($this->owner);

        $this->post($this->replyUrl($inbound), $this->form())->assertRedirect();

        $this->assertSame($contact->id, (int) BusinessEmailMessage::query()->where('direction', 'outbound')->value('contact_id'));
    }

    public function test_other_businesses_and_unreachable_locations_are_denied_for_listing_and_reply(): void
    {
        $a = $this->seed($this->locA, 'Visible to A staff', 't-a');
        $b = $this->seed($this->locB, 'Only Location B', 't-b');
        $unresolved = $this->seed(null, 'Unresolved owner', 't-u');

        // Staff limited to Location A.
        $this->authenticateAs($this->staff, ['chat_box', 'view_contact']);
        $html = $this->get($this->convUrl())->assertOk()->getContent();
        $this->assertStringContainsString('Visible to A staff', $html);
        $this->assertStringNotContainsString('Only Location B', $html);
        $this->assertStringNotContainsString('Unresolved owner', $html);
        $this->assertStringNotContainsString('Body of Only Location B', $this->get($this->convUrl('t-b'))->getContent());

        $this->post($this->replyUrl($b), $this->form())->assertNotFound();
        $this->post($this->replyUrl($unresolved), $this->form())->assertNotFound();
        $this->assertSame(0, $this->fakeGoogle->callCount('send'));

        // Another Business's owner: neither this Business's URL nor a foreign message uid works.
        [$otherOwner, $otherBusiness, $otherWorkspace] = $this->emailTenant('Other Studio');
        $this->activeAccount($otherBusiness);
        $this->authenticateAs($otherOwner);
        $this->get($this->convUrl())->assertNotFound();
        $this->post($this->replyUrl($a), $this->form())->assertNotFound();
        $this->post($this->replyUrl($a, $otherBusiness, $otherWorkspace), $this->form())->assertNotFound();
        $this->assertSame(0, $this->fakeGoogle->callCount('send'));
    }

    public function test_unresolved_mail_is_visible_to_full_reach_actors_and_needs_an_explicit_location_to_answer(): void
    {
        $unresolved = $this->seed(null, 'Unresolved owner', 't-u');
        $this->authenticateAs($this->owner);

        $this->assertStringContainsString('Unresolved owner', $this->get($this->convUrl())->assertOk()->getContent());

        // Two active Locations and no proof which one: refused, never guessed.
        $this->post($this->replyUrl($unresolved), $this->form())->assertRedirect();
        $this->assertSame(0, $this->fakeGoogle->callCount('send'));

        $this->post($this->replyUrl($unresolved), $this->form(['location_uid' => $this->locB->uid]))->assertRedirect();
        $this->assertSame(1, $this->fakeGoogle->callCount('send'));
        $this->assertSame($this->locB->id, (int) BusinessEmailMessage::query()->where('direction', 'outbound')->value('location_id'));
    }

    public function test_untrusted_email_content_is_escaped_never_rendered_as_html(): void
    {
        $message = $this->seed($this->locA, '<script>alert("subject")</script>', 't-x', 'x@example.com');
        $message->forceFill([
            'from_name' => '<img src=x onerror=alert(1)>',
            'body_text' => "<script>alert('body')</script>\n<a href=\"javascript:alert(2)\">click</a>",
        ])->save();
        $this->authenticateAs($this->owner);

        foreach ([$this->convUrl(), $this->convUrl('t-x'), route('customer.workspaces.businesses.email.show', [$this->workspace->uid, $this->business->uid])] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('<script>alert', $html, $url);
            $this->assertStringNotContainsString('<img src=x', $html, $url);
            $this->assertStringNotContainsString('<a href="javascript:', $html, $url);
        }

        $this->assertStringContainsString('&lt;script&gt;alert(&#039;body&#039;)&lt;/script&gt;', $this->get($this->convUrl('t-x'))->getContent());
    }

    public function test_sms_conversation_routes_are_unchanged_by_the_email_tab(): void
    {
        $match = fn (string $method, string $uri) => Route::getRoutes()->match(Request::create($uri, $method))->getName();
        $base = '/' . trim(parse_url(route('customer.workspaces.businesses.conversations.index', ['w', 'b']), PHP_URL_PATH), '/');

        $this->assertSame('customer.workspaces.businesses.conversations.index', $match('GET', $base));
        $this->assertSame('customer.workspaces.businesses.conversations.new', $match('GET', $base . '/new'));
        $this->assertSame('customer.workspaces.businesses.conversations.email', $match('GET', $base . '/email'));
        $this->assertSame('customer.workspaces.businesses.conversations.messages', $match('POST', $base . '/some-uid/messages'));
        $this->assertSame('customer.workspaces.businesses.conversations.reply', $match('POST', $base . '/some-uid/reply'));
        $this->assertSame('customer.workspaces.businesses.conversations.load', $match('POST', $base . '/load'));
    }
}
