<?php

namespace Tests\Feature\BusinessEmail;

use App\DTO\BusinessEmail\BusinessEmailOutbound;
use App\Library\BusinessEmail\GoogleBusinessEmailProvider;
use App\Library\BusinessEmail\SafeEmailMarkup;
use App\Models\BusinessEmailMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\BusinessEmail\Concerns\CreatesBusinessEmailFixtures;
use Tests\TestCase;

/**
 * The Email view's real thread actions (archive / mark unread / delete from MotionGrove), the formatting toolbar's
 * server side (safe markup -> sanitized HTML alternative, plain text always kept), and SMS Rename.
 */
class EmailThreadActionsAndRichTextTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessEmailFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->bindFakeEmailProviders();
    }

    private function message(object $business, string $thread, string $direction = 'inbound', ?int $contactId = null): BusinessEmailMessage
    {
        return BusinessEmailMessage::create([
            'business_id' => $business->id, 'direction' => $direction, 'contact_id' => $contactId, 'operation_key' => 'seed-' . Str::uuid(),
            'provider' => 'google', 'provider_message_id' => 'pm-' . Str::uuid(), 'provider_thread_id' => $thread,
            'internet_message_id' => '<' . Str::uuid() . '@x.test>', 'from_email' => $direction === 'inbound' ? 'pat@example.com' : 'owner@business.test',
            'to_email' => $direction === 'inbound' ? 'owner@business.test' : 'pat@example.com', 'subject' => 'Quote', 'body_text' => 'Hello',
            'status' => $direction === 'inbound' ? 'received' : 'accepted', 'received_at' => now(),
        ]);
    }

    private function act(string $action, object $workspace, object $business, string $thread)
    {
        return $this->postJson(route('customer.workspaces.businesses.conversations.email_thread_' . $action, [$workspace->uid, $business->uid]), ['thread' => $thread]);
    }

    private function listed(object $workspace, object $business): string
    {
        return $this->postJson(route('customer.workspaces.businesses.conversations.load', [$workspace->uid, $business->uid]), ['channel' => 'email', 'filter' => 'recents', 'page' => 1])->getContent();
    }

    public function test_archive_persists_hides_the_thread_and_a_new_reply_brings_it_back(): void
    {
        [$owner, $business, $workspace] = $this->emailTenant();
        $this->activeAccount($business);
        $this->message($business, 't-1');
        $this->authenticateAs($owner);
        $this->assertStringContainsString('data-email-thread="t-1"', $this->listed($workspace, $business));

        $this->act('archive', $workspace, $business, 't-1')->assertOk()->assertJsonPath('action', 'archived');
        $this->assertSame(1, BusinessEmailMessage::query()->whereNotNull('archived_at')->count());
        $this->assertStringNotContainsString('data-email-thread="t-1"', $this->listed($workspace, $business));

        // A new inbound message is a fresh, unarchived row: the thread is back without any extra step.
        $this->message($business, 't-1');
        $this->assertStringContainsString('data-email-thread="t-1"', $this->listed($workspace, $business));

        $this->act('archive', $workspace, $business, 't-1')->assertOk();
        $this->act('unarchive', $workspace, $business, 't-1')->assertOk();
        $this->assertSame(0, BusinessEmailMessage::query()->whereNotNull('archived_at')->count());
    }

    public function test_mark_unread_persists_and_opening_the_thread_reads_it(): void
    {
        [$owner, $business, $workspace] = $this->emailTenant();
        $this->activeAccount($business);
        $this->message($business, 't-2');
        $this->authenticateAs($owner);

        $this->act('unread', $workspace, $business, 't-2')->assertOk();
        $this->assertSame(1, BusinessEmailMessage::query()->whereNotNull('marked_unread_at')->count());
        $this->assertStringContainsString('conv-unread', $this->listed($workspace, $business));

        $this->postJson(route('customer.workspaces.businesses.conversations.email_thread', [$workspace->uid, $business->uid]), ['thread' => 't-2'])->assertOk();
        $this->assertSame(0, BusinessEmailMessage::query()->whereNotNull('marked_unread_at')->count());
    }

    public function test_delete_is_a_soft_delete_inside_motiongrove_only(): void
    {
        [$owner, $business, $workspace] = $this->emailTenant();
        $this->activeAccount($business);
        $this->message($business, 't-3');
        $this->authenticateAs($owner);

        $this->act('delete', $workspace, $business, 't-3')->assertOk()->assertJsonPath('action', 'deleted');

        $this->assertSame(1, BusinessEmailMessage::query()->count(), 'The row is kept (so inbound sync dedupes it); it is only hidden.');
        $this->assertStringNotContainsString('data-email-thread="t-3"', $this->listed($workspace, $business));
        $this->postJson(route('customer.workspaces.businesses.conversations.email_thread', [$workspace->uid, $business->uid]), ['thread' => 't-3'])->assertNotFound();
        $this->assertSame(0, $this->fakeGoogle->callCount('send'), 'No provider call is ever made.');
    }

    public function test_thread_actions_respect_business_boundaries_and_the_chat_permission(): void
    {
        [$owner, $business, $workspace] = $this->emailTenant();
        [, $other] = $this->emailTenant('Other Co');
        $this->message($other, 't-foreign');
        $this->message($business, 't-mine');
        $this->authenticateAs($owner);

        foreach (['archive', 'unarchive', 'unread', 'delete'] as $action) {
            $this->act($action, $workspace, $business, 't-foreign')->assertNotFound();
        }
        $this->assertSame(0, BusinessEmailMessage::query()->whereNotNull('archived_at')->orWhereNotNull('deleted_at')->orWhereNotNull('marked_unread_at')->count());

        $this->authenticateAs($owner, ['view_contact']);
        $this->assertContains($this->act('archive', $workspace, $business, 't-mine')->getStatusCode(), [401, 403, 404]);
        $this->assertSame(0, BusinessEmailMessage::query()->whereNotNull('archived_at')->count());
    }

    public function test_markup_becomes_a_safe_html_alternative_and_the_plain_text_never_shows_markers(): void
    {
        $out = SafeEmailMarkup::convert("Hi **Amira**, see [our quote](https://x.test/q?a=1&b=2) and _soon_.\n<script>alert(1)</script> [bad](javascript:alert(1))");

        $this->assertSame("Hi Amira, see our quote (https://x.test/q?a=1&b=2) and soon.\n<script>alert(1)</script> [bad](javascript:alert(1))", $out['text']);
        $this->assertStringContainsString('<strong>Amira</strong>', $out['html']);
        $this->assertStringContainsString('<a href="https://x.test/q?a=1&amp;b=2" rel="noopener noreferrer">our quote</a>', $out['html']);
        $this->assertStringContainsString('<em>soon</em>', $out['html']);
        $this->assertStringNotContainsString('<script', $out['html'], 'A typed tag is escaped, never emitted.');
        $this->assertStringContainsString('&lt;script&gt;', $out['html']);
        $this->assertStringNotContainsString('href="javascript', $out['html']);

        $this->assertNull(SafeEmailMarkup::convert("No formatting here.\nJust text.")['html'], 'Plain mail stays plain.');
    }

    public function test_a_formatted_send_stores_both_parts_and_reaches_the_provider_with_html_and_clean_text(): void
    {
        [$owner, $business, $workspace] = $this->emailTenant();
        $this->activeAccount($business);
        $contact = $this->contactWithEmails($business, ['pat@example.com'], null, 'Pat');
        $this->authenticateAs($owner);

        $this->postJson(route('customer.workspaces.businesses.email.send', [$workspace->uid, $business->uid]), [
            'contact_uid' => $contact->uid, 'subject' => 'Quote', 'body' => 'Hello **Pat**, [details](https://x.test/d)', 'send_token' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('outcome', 'accepted');

        $sent = $this->fakeGoogle->sent[0];
        $this->assertSame('Hello Pat, details (https://x.test/d)', $sent->bodyText);
        $this->assertSame('Hello <strong>Pat</strong>, <a href="https://x.test/d" rel="noopener noreferrer">details</a>', $sent->bodyHtml);

        $row = BusinessEmailMessage::query()->where('direction', 'outbound')->sole();
        $this->assertSame('Hello Pat, details (https://x.test/d)', $row->body_text);
        $this->assertSame($sent->bodyHtml, $row->body_html);

        // A plain message carries no HTML at all.
        $this->postJson(route('customer.workspaces.businesses.email.send', [$workspace->uid, $business->uid]), [
            'contact_uid' => $contact->uid, 'subject' => 'Plain', 'body' => 'Just text', 'send_token' => (string) Str::uuid(),
        ])->assertOk();
        $this->assertNull($this->fakeGoogle->sent[1]->bodyHtml);
    }

    public function test_a_provider_without_html_support_gets_the_clean_plain_text_only(): void
    {
        [$owner, $business, $workspace] = $this->emailTenant();
        $this->activeAccount($business);
        $this->fakeGoogle->htmlSend = false;
        $contact = $this->contactWithEmails($business, ['pat@example.com'], null, 'Pat');
        $this->authenticateAs($owner);

        $composer = $this->postJson(route('customer.workspaces.businesses.conversations.email_compose', [$workspace->uid, $business->uid]), ['contact' => $contact->uid])->json('composer');
        $this->assertFalse($composer['rich'], 'The toolbar is not offered when the provider cannot send HTML.');

        $this->postJson(route('customer.workspaces.businesses.email.send', [$workspace->uid, $business->uid]), [
            'contact_uid' => $contact->uid, 'subject' => 'Q', 'body' => '**Hi** there', 'send_token' => (string) Str::uuid(),
        ])->assertOk();
        $this->assertSame('Hi there', $this->fakeGoogle->sent[0]->bodyText);
        $this->assertNull($this->fakeGoogle->sent[0]->bodyHtml);
    }

    public function test_the_toolbar_is_offered_when_the_provider_can_send_html(): void
    {
        [$owner, $business, $workspace] = $this->emailTenant();
        $this->activeAccount($business);
        $contact = $this->contactWithEmails($business, ['pat@example.com'], null, 'Pat');
        $this->authenticateAs($owner);

        $this->assertTrue($this->postJson(route('customer.workspaces.businesses.conversations.email_compose', [$workspace->uid, $business->uid]), ['contact' => $contact->uid])->json('composer.rich'));
    }

    // ---- SMS Rename ----

    private function smsBox(object $business, string $phone)
    {
        $id = DB::table('chat_boxes')->insertGetId(['uid' => (string) Str::uuid(), 'user_id' => $business->customer_id, 'business_id' => $business->id, 'from' => '18005550100', 'to' => $phone, 'notification' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('chat_boxes')->find($id);
    }

    public function test_rename_changes_the_linked_contacts_name_and_nothing_else(): void
    {
        [$owner, $business, $workspace] = $this->emailTenant();
        $contact = $this->contactWithEmails($business, ['pat@example.com'], null, 'Pat');
        $box = $this->smsBox($business, (string) $contact->phone);
        $this->authenticateAs($owner);
        $url = route('customer.workspaces.businesses.conversations.rename', [$workspace->uid, $business->uid, $box->uid]);

        $this->postJson($url, ['name' => "  Amira   Misurati-Lee "])->assertOk()->assertJsonPath('name', 'Amira Misurati-Lee');

        $names = DB::table('contacts_custom_field as v')->join('contact_group_fields as f', 'f.id', '=', 'v.field_id')
            ->where('v.contact_id', $contact->id)->whereIn('f.tag', ['FIRST_NAME', 'LAST_NAME'])->pluck('v.value', 'f.tag');
        $this->assertSame('Amira', $names['FIRST_NAME']);
        $this->assertSame('Misurati-Lee', $names['LAST_NAME']);
        $this->assertSame($contact->phone, $contact->fresh()->phone);

        $this->postJson($url, ['name' => ''])->assertStatus(422);
        $this->postJson($url, ['name' => str_repeat('x', 121)])->assertStatus(422);
    }

    public function test_rename_is_refused_without_a_linked_contact_a_foreign_conversation_or_permission(): void
    {
        [$owner, $business, $workspace] = $this->emailTenant();
        [, $other, $otherWorkspace] = $this->emailTenant('Other Co');
        $unlinked = $this->smsBox($business, '15558887777');
        $foreignContact = $this->contactWithEmails($other, ['x@example.com'], null, 'X');
        $foreignBox = $this->smsBox($other, (string) $foreignContact->phone);
        $mine = $this->contactWithEmails($business, ['pat@example.com'], null, 'Pat');
        $mineBox = $this->smsBox($business, (string) $mine->phone);
        $this->authenticateAs($owner);

        $this->postJson(route('customer.workspaces.businesses.conversations.rename', [$workspace->uid, $business->uid, $unlinked->uid]), ['name' => 'Nope'])->assertStatus(422);
        $this->postJson(route('customer.workspaces.businesses.conversations.rename', [$workspace->uid, $business->uid, $foreignBox->uid]), ['name' => 'Nope'])->assertNotFound();

        $this->authenticateAs($owner, ['chat_box', 'view_contact']);
        $this->postJson(route('customer.workspaces.businesses.conversations.rename', [$workspace->uid, $business->uid, $mineBox->uid]), ['name' => 'Nope'])->assertStatus(422);
        $this->assertSame(0, DB::table('contacts_custom_field')->where('value', 'Nope')->count());
    }
}
