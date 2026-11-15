<?php

namespace Tests\Feature\Contacts;

use App\Models\BusinessEmailMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\BusinessEmail\Concerns\CreatesBusinessEmailFixtures;
use Tests\TestCase;

/**
 * Contact -> Send Email -> Conversations Email composer -> existing sender -> message in the conversation.
 * Never a silent SMS fallback; the reason Email is unavailable is always stated with its one action.
 */
class ContactSendEmailFlowTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessEmailFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->bindFakeEmailProviders();
    }

    private function profile($workspace, $business, $contact)
    {
        return $this->get(route('customer.workspaces.businesses.people.show', [$workspace->uid, $business->uid, $contact->uid]))->assertOk();
    }

    private function compose($workspace, $business, $contact)
    {
        return $this->postJson(route('customer.workspaces.businesses.conversations.email_compose', [$workspace->uid, $business->uid]), ['contact' => $contact->uid]);
    }

    public function test_profile_offers_a_distinct_send_email_that_opens_the_email_composer_for_that_contact(): void
    {
        [$owner, $business, $workspace] = $this->emailTenant();
        $this->activeAccount($business);
        $contact = $this->contactWithEmails($business, ['pat@example.com'], null, 'Pat');
        $this->authenticateAs($owner);

        $page = $this->profile($workspace, $business, $contact);
        $page->assertSee('data-role="contact-send-email"', false)->assertSee('Send Email')->assertSee('data-role="contact-sms"', false);
        $page->assertSee(e(route('customer.workspaces.businesses.conversations.index', [$workspace->uid, $business->uid, 'channel' => 'email', 'compose' => $contact->uid])), false);
        $page->assertDontSee('Send message');
        $page->assertSee('pat@example.com');
    }

    public function test_profile_shows_connect_email_when_the_mailbox_is_disconnected(): void
    {
        [$owner, $business, $workspace] = $this->emailTenant();
        $contact = $this->contactWithEmails($business, ['pat@example.com'], null, 'Pat');
        $this->authenticateAs($owner);

        $page = $this->profile($workspace, $business, $contact);
        $page->assertDontSee('data-role="contact-send-email"', false)->assertSee('data-role="contact-connect-email"', false);
        $page->assertSee(route('customer.workspaces.businesses.email.show', [$workspace->uid, $business->uid]), false);
    }

    public function test_profile_shows_add_email_for_a_contact_without_one_and_never_a_send_email(): void
    {
        [$owner, $business, $workspace] = $this->emailTenant();
        $this->activeAccount($business);
        $contact = $this->contactWithEmails($business, [], null, 'Pat');
        $this->authenticateAs($owner);

        $page = $this->profile($workspace, $business, $contact);
        $page->assertSee('data-role="contact-add-email"', false)->assertDontSee('data-role="contact-send-email"', false)->assertSee('No email yet');
    }

    public function test_profile_flags_ambiguous_emails_instead_of_picking_one(): void
    {
        [$owner, $business, $workspace] = $this->emailTenant();
        $this->activeAccount($business);
        $contact = $this->contactWithEmails($business, ['a@example.com', 'b@example.com'], null, 'Pat');
        $this->authenticateAs($owner);

        $page = $this->profile($workspace, $business, $contact);
        $page->assertSee('Several different emails are stored')->assertDontSee('data-role="contact-send-email"', false);
    }

    public function test_compose_prepares_the_composer_with_the_canonical_recipient_and_sending_records_the_message(): void
    {
        [$owner, $business, $workspace] = $this->emailTenant();
        $this->activeAccount($business);
        $contact = $this->contactWithEmails($business, ['pat@example.com'], null, 'Pat');
        $this->authenticateAs($owner);

        $json = $this->compose($workspace, $business, $contact)->assertOk()->json();
        $this->assertTrue($json['composer']['email']);
        $this->assertFalse($json['composer']['sms'], 'No SMS fallback in the Email composer.');
        $this->assertSame('pat@example.com', $json['composer']['recipient']);
        $this->assertSame($contact->uid, $json['composer']['contact_uid']);
        $this->assertNull($json['composer']['blocked']);
        $this->assertStringContainsString('New email to', $json['timeline']);

        // The composer posts to the EXISTING Business-email sender.
        $this->postJson($json['composer']['url'], [
            'contact_uid' => $contact->uid, 'subject' => 'Your quote', 'body' => 'Here it is.', 'send_token' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('outcome', 'accepted');

        $this->assertSame(1, $this->fakeGoogle->callCount('send'));
        $this->assertSame('pat@example.com', $this->fakeGoogle->sent[0]->toEmail);
        $this->assertSame(1, BusinessEmailMessage::query()->where('business_id', $business->id)->where('contact_id', $contact->id)->where('direction', 'outbound')->count());

        // The resulting conversation shows the message.
        $after = $this->compose($workspace, $business, $contact)->assertOk()->json('timeline');
        $this->assertStringContainsString('Your quote', $after);
    }

    public function test_compose_names_the_blocker_and_its_action_instead_of_falling_back_to_sms(): void
    {
        [$owner, $business, $workspace] = $this->emailTenant();
        $noEmail = $this->contactWithEmails($business, [], null, 'Pat');
        $ambiguous = $this->contactWithEmails($business, ['a@example.com', 'b@example.com'], null, 'Sam');
        $valid = $this->contactWithEmails($business, ['v@example.com'], null, 'Val');
        $this->authenticateAs($owner);

        $disconnected = $this->compose($workspace, $business, $valid)->assertOk()->json('composer');
        $this->assertFalse($disconnected['email']);
        $this->assertFalse($disconnected['sms']);
        $this->assertSame('Connect Email', $disconnected['blocked']['label']);
        $this->assertSame(route('customer.workspaces.businesses.email.show', [$workspace->uid, $business->uid]), $disconnected['blocked']['url']);

        $this->activeAccount($business);
        $this->assertSame('Add Email', $this->compose($workspace, $business, $noEmail)->assertOk()->json('composer.blocked.label'));
        $this->assertSame('Fix email', $this->compose($workspace, $business, $ambiguous)->assertOk()->json('composer.blocked.label'));
        $this->assertSame(0, $this->fakeGoogle->callCount('send'));
    }

    public function test_compose_refuses_another_businesss_contact_and_users_without_conversation_access(): void
    {
        [$owner, $business, $workspace] = $this->emailTenant();
        [, $other] = $this->emailTenant('Other Co');
        $foreign = $this->contactWithEmails($other, ['x@example.com'], null, 'X');
        $mine = $this->contactWithEmails($business, ['m@example.com'], null, 'Me');
        $this->activeAccount($business);

        $this->authenticateAs($owner);
        $this->compose($workspace, $business, $foreign)->assertNotFound();

        $this->authenticateAs($owner, ['view_contact']);
        $this->compose($workspace, $business, $mine)->assertStatus(401);
    }

    public function test_a_provider_failure_is_shown_as_not_delivered_and_never_as_sent(): void
    {
        [$owner, $business, $workspace] = $this->emailTenant();
        $this->activeAccount($business);
        $contact = $this->contactWithEmails($business, ['pat@example.com'], null, 'Pat');
        $this->authenticateAs($owner);
        $composer = $this->compose($workspace, $business, $contact)->assertOk()->json('composer');

        $this->fakeGoogle->sendScript[] = new \App\Exceptions\BusinessEmail\BusinessEmailProviderException(\App\Enums\BusinessEmail\BusinessEmailFailureCategory::PermanentProviderFailure, '550');

        $response = $this->postJson($composer['url'], ['contact_uid' => $contact->uid, 'subject' => 'Hi', 'body' => 'Body', 'send_token' => (string) Str::uuid()]);
        $response->assertStatus(422)->assertJsonPath('status', 'error');
        $this->assertNotSame('accepted', $response->json('outcome'));

        $timeline = $this->compose($workspace, $business, $contact)->json('timeline');
        $this->assertStringContainsString('Not delivered', $timeline);
        $this->assertStringNotContainsString('Email sent', $response->getContent());
    }
}
