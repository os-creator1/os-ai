<?php

namespace Tests\Feature\BusinessEmail;

use App\Enums\BusinessEmail\BusinessEmailFailureCategory as Category;
use App\Enums\BusinessEmail\BusinessEmailMessageStatus as Status;
use App\Enums\BusinessEmail\BusinessEmailProviderType;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Exceptions\BusinessEmail\BusinessEmailProviderException;
use App\Models\BusinessEmailMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\BusinessEmail\Concerns\CreatesBusinessEmailFixtures;
use Tests\TestCase;

/**
 * Settings → Email (the minimum customer surface) and the manual send.
 */
class BusinessEmailSettingsHttpTest extends TestCase
{
    use CreatesBusinessEmailFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->bindFakeEmailProviders();
    }

    private function showUrl(object $workspace, object $business): string
    {
        return route('customer.workspaces.businesses.email.show', [$workspace->uid, $business->uid]);
    }

    private function sendUrl(object $workspace, object $business): string
    {
        return route('customer.workspaces.businesses.email.send', [$workspace->uid, $business->uid]);
    }

    /** @return array<string, string> */
    private function form(object $contact, string $token = null, string $subject = 'Your quote', string $body = 'Thanks for asking.'): array
    {
        return [
            'contact_uid' => $contact->uid,
            'subject' => $subject,
            'body' => $body,
            'send_token' => $token ?? (string) Str::uuid(),
        ];
    }

    public function test_the_page_offers_connect_for_each_configured_provider_when_nothing_is_connected(): void
    {
        [$customer, $business, $workspace] = $this->emailTenant();
        $this->authenticateAs($customer);

        $html = $this->get($this->showUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('Connect Google', $html);
        $this->assertStringContainsString('Connect Microsoft', $html);
        $this->assertStringContainsString(route('customer.workspaces.businesses.email.connect', [$workspace->uid, $business->uid, 'google']), $html);
        $this->assertStringNotContainsString('Send an email to a contact', $html);
        $this->assertStringNotContainsString('Disconnect', $html);
    }

    public function test_a_provider_without_credentials_configured_is_not_offered(): void
    {
        [$customer, $business, $workspace] = $this->emailTenant();
        $this->authenticateAs($customer);
        config(['business_email.microsoft.client_id' => null]);

        $html = $this->get($this->showUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('Connect Google', $html);
        $this->assertStringNotContainsString('Connect Microsoft', $html);
    }

    public function test_the_page_shows_the_connected_mailbox_status_and_never_a_secret(): void
    {
        [$customer, $business, $workspace] = $this->emailTenant();
        $this->activeAccount($business, BusinessEmailProviderType::Microsoft, 'boss@corp.test');
        $this->authenticateAs($customer);

        $html = $this->get($this->showUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('boss@corp.test', $html);
        $this->assertStringContainsString('Microsoft (Outlook / Microsoft 365)', $html);
        $this->assertStringContainsString('Disconnect', $html);
        $this->assertStringNotContainsString('stored-refresh-token', $html);
        $this->assertStringNotContainsString('refresh_token', $html);
    }

    public function test_a_revoked_account_asks_to_reconnect(): void
    {
        [$customer, $business, $workspace] = $this->emailTenant();
        $this->activeAccount($business);
        DB::table('business_email_accounts')->where('business_id', $business->id)->update(['state' => 'revoked', 'refresh_token_encrypted' => null]);
        $this->authenticateAs($customer);

        $html = $this->get($this->showUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('Reconnect to send email again', $html);
        $this->assertStringContainsString('Reconnect Google', $html);
        $this->assertStringNotContainsString('Send an email to a contact', $html);
    }

    public function test_the_page_is_hidden_from_a_user_with_neither_email_permission(): void
    {
        [$customer, $business, $workspace] = $this->emailTenant();
        $this->authenticateAs($customer, ['view_contact']);

        $this->get($this->showUrl($workspace, $business))->assertStatus(401);
    }

    public function test_a_conversation_user_without_the_manage_permission_sees_status_but_no_connect_or_disconnect(): void
    {
        [$customer, $business, $workspace] = $this->emailTenant();
        $this->activeAccount($business);
        $this->authenticateAs($customer, ['chat_box', 'view_contact']);

        $html = $this->get($this->showUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('owner@business.test', $html);
        $this->assertStringNotContainsString('Disconnect', $html);
        $this->assertStringContainsString('Send an email to a contact', $html);
    }

    // ---- manual send -----------------------------------------------------

    public function test_a_user_sends_an_email_to_a_contact_through_the_canonical_sender(): void
    {
        [$customer, $business, $workspace] = $this->emailTenant();
        $this->activeAccount($business);
        $contact = $this->contactWithEmails($business, ['pat@example.com'], first: 'Pat');
        $this->authenticateAs($customer);

        $html = $this->get($this->showUrl($workspace, $business))->getContent();
        $this->assertStringContainsString('Pat — pat@example.com', $html);

        $this->post($this->sendUrl($workspace, $business), $this->form($contact))
            ->assertRedirect($this->showUrl($workspace, $business))
            ->assertSessionHas('status', 'success');

        $message = BusinessEmailMessage::query()->sole();
        $this->assertSame(Status::Accepted, $message->status);
        $this->assertSame('manual', $message->source->value);
        $this->assertSame((int) $customer->user_id, (int) $message->sent_by_user_id);
        $this->assertSame('Your quote', $message->subject);
        $this->assertSame(1, $this->fakeGoogle->callCount('send'));
        $this->assertSame('pat@example.com', $this->fakeGoogle->sent[0]->toEmail);

        $this->assertStringContainsString('accepted by your email provider', $this->get($this->showUrl($workspace, $business))->getContent());
    }

    public function test_the_same_form_submitted_twice_sends_exactly_once(): void
    {
        [$customer, $business, $workspace] = $this->emailTenant();
        $this->activeAccount($business);
        $contact = $this->contactWithEmails($business, ['pat@example.com']);
        $this->authenticateAs($customer);
        $form = $this->form($contact);

        $this->post($this->sendUrl($workspace, $business), $form);
        $this->post($this->sendUrl($workspace, $business), $form);

        $this->assertSame(1, $this->fakeGoogle->callCount('send'));
        $this->assertSame(1, BusinessEmailMessage::query()->count());
    }

    public function test_a_foreign_contact_uid_is_indistinguishable_from_an_unknown_one_and_sends_nothing(): void
    {
        [$customer, $business, $workspace] = $this->emailTenant();
        $this->activeAccount($business);
        [, $other] = $this->emailTenant('Other Business');
        $foreign = $this->contactWithEmails($other, ['victim@example.com']);
        $this->authenticateAs($customer);

        $this->post($this->sendUrl($workspace, $business), $this->form($foreign))->assertSessionHas('status', 'error');
        $this->post($this->sendUrl($workspace, $business), array_merge($this->form($foreign), ['contact_uid' => 'nope-nope']))->assertSessionHas('status', 'error');

        $this->assertSame(0, BusinessEmailMessage::query()->count());
        $this->assertSame(0, $this->fakeGoogle->callCount('send'));
    }

    public function test_a_contact_without_an_email_is_refused_with_a_plain_message(): void
    {
        [$customer, $business, $workspace] = $this->emailTenant();
        $this->activeAccount($business);
        $contact = $this->contactWithEmails($business, []);
        $this->authenticateAs($customer);

        $this->post($this->sendUrl($workspace, $business), $this->form($contact))
            ->assertSessionHas('message', Category::RecipientInvalid->customerMessage());

        $this->assertSame(0, BusinessEmailMessage::query()->count());
    }

    public function test_sending_without_a_connected_account_says_to_connect_one(): void
    {
        [$customer, $business, $workspace] = $this->emailTenant();
        $contact = $this->contactWithEmails($business, ['pat@example.com']);
        $this->authenticateAs($customer);

        $this->post($this->sendUrl($workspace, $business), $this->form($contact))
            ->assertSessionHas('message', Category::DisconnectedAccount->customerMessage());
    }

    public function test_a_provider_failure_is_shown_in_provider_neutral_words_and_never_the_raw_error(): void
    {
        [$customer, $business, $workspace] = $this->emailTenant();
        $this->activeAccount($business);
        $contact = $this->contactWithEmails($business, ['pat@example.com']);
        $this->fakeGoogle->sendScript = [new BusinessEmailProviderException(Category::ProviderRateLimited, '429')];
        $this->authenticateAs($customer);

        $response = $this->post($this->sendUrl($workspace, $business), $this->form($contact));

        $response->assertSessionHas('status', 'error');
        $response->assertSessionHas('message', Category::ProviderRateLimited->customerMessage());
        $html = $this->get($this->showUrl($workspace, $business))->getContent();
        $this->assertStringContainsString(Category::ProviderRateLimited->customerMessage(), $html);
        $this->assertStringNotContainsString('429', strip_tags(str_replace(['<script', '</script>'], '', explode('Recent emails', $html)[1] ?? '')));
    }

    public function test_send_requires_both_conversation_and_contact_permissions(): void
    {
        [$customer, $business, $workspace] = $this->emailTenant();
        $this->activeAccount($business);
        $contact = $this->contactWithEmails($business, ['pat@example.com']);

        foreach ([['manage_business_email'], ['chat_box'], ['view_contact']] as $permissions) {
            $this->authenticateAs($customer, $permissions);
            $this->post($this->sendUrl($workspace, $business), $this->form($contact))->assertStatus(401);
        }

        $this->assertSame(0, $this->fakeGoogle->callCount('send'));
        $this->assertSame(0, BusinessEmailMessage::query()->count());
    }

    public function test_validation_rejects_malformed_input_before_the_sender_runs(): void
    {
        [$customer, $business, $workspace] = $this->emailTenant();
        $this->activeAccount($business);
        $contact = $this->contactWithEmails($business, ['pat@example.com']);
        $this->authenticateAs($customer);

        $this->from($this->showUrl($workspace, $business))
            ->post($this->sendUrl($workspace, $business), array_merge($this->form($contact), ['send_token' => 'not-a-uuid']))
            ->assertSessionHasErrors('send_token');
        $this->from($this->showUrl($workspace, $business))
            ->post($this->sendUrl($workspace, $business), array_merge($this->form($contact), ['subject' => str_repeat('x', 201)]))
            ->assertSessionHasErrors('subject');
        $this->from($this->showUrl($workspace, $business))
            ->post($this->sendUrl($workspace, $business), array_merge($this->form($contact), ['body' => '']))
            ->assertSessionHasErrors('body');

        $this->assertSame(0, $this->fakeGoogle->callCount('send'));
    }

    public function test_an_assigned_staff_member_can_send_but_one_scoped_elsewhere_cannot(): void
    {
        [, $business, $workspace] = $this->emailTenant();
        $this->activeAccount($business);
        $contact = $this->contactWithEmails($business, ['pat@example.com']);

        $assigned = $this->createCustomer();
        $this->assign($this->member($workspace, $assigned->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected), $business);
        $this->authenticateAs($assigned, ['chat_box', 'view_contact']);
        $this->post($this->sendUrl($workspace, $business), $this->form($contact))->assertSessionHas('status', 'success');
        $this->assertSame(1, $this->fakeGoogle->callCount('send'));

        $elsewhere = $this->createCustomer();
        $this->member($workspace, $elsewhere->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected);
        $this->authenticateAs($elsewhere, ['chat_box', 'view_contact']);
        $this->post($this->sendUrl($workspace, $business), $this->form($contact))->assertNotFound();
        $this->assertSame(1, $this->fakeGoogle->callCount('send'));
    }

    public function test_another_tenant_cannot_send_as_this_business(): void
    {
        [, $business, $workspace] = $this->emailTenant();
        $this->activeAccount($business);
        $contact = $this->contactWithEmails($business, ['pat@example.com']);
        [$stranger] = $this->emailTenant('Stranger');
        $this->authenticateAs($stranger);

        $this->post($this->sendUrl($workspace, $business), $this->form($contact))->assertNotFound();

        $this->assertSame(0, $this->fakeGoogle->callCount('send'));
    }

    // ---- bounded queries -------------------------------------------------

    public function test_the_contact_picker_is_bounded_and_the_page_query_count_is_constant(): void
    {
        [$customer, $business, $workspace] = $this->emailTenant();
        $this->activeAccount($business);
        $this->authenticateAs($customer);

        for ($i = 0; $i < 3; $i++) {
            $this->contactWithEmails($business, ["small{$i}@example.com"]);
        }

        $count = function () use ($workspace, $business): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $html = $this->get($this->showUrl($workspace, $business))->assertOk()->getContent();
            $log = DB::getQueryLog();
            $queries = count($log);
            DB::disableQueryLog();

            return [$queries, substr_count($html, '<option')];
        };

        // One warm-up request: the shell does a few one-time lookups on the
        // first request of a session that are not part of this page.
        $count();

        [$smallQueries, $smallOptions] = $count();
        $this->assertSame(3, $smallOptions);

        for ($i = 0; $i < 70; $i++) {
            $this->contactWithEmails($business, ["big{$i}@example.com"], first: 'N' . $i);
        }

        [$bigQueries, $bigOptions] = $count();

        $this->assertSame(50, $bigOptions, 'The picker never lists more than 50 contacts.');
        $this->assertSame($smallQueries, $bigQueries, 'Query count does not grow with the number of contacts.');
    }

    public function test_the_recent_emails_list_is_capped_and_scoped_to_this_business(): void
    {
        [$customer, $business, $workspace] = $this->emailTenant();
        $this->activeAccount($business);
        $contact = $this->contactWithEmails($business, ['pat@example.com']);
        [, $other] = $this->emailTenant('Other Business');
        $this->authenticateAs($customer);

        for ($i = 1; $i <= 14; $i++) {
            DB::table('business_email_messages')->insert([
                'uid' => (string) Str::uuid(), 'business_id' => $business->id, 'contact_id' => $contact->id,
                'operation_key' => "seed-{$i}", 'provider' => 'google', 'from_email' => 'a@b.test', 'to_email' => 'pat@example.com',
                'subject' => "Subject {$i}", 'body_text' => 'b', 'status' => 'accepted', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('business_email_messages')->insert([
            'uid' => (string) Str::uuid(), 'business_id' => $other->id, 'operation_key' => 'foreign', 'provider' => 'google',
            'from_email' => 'x@y.test', 'to_email' => 'z@w.test', 'subject' => 'FOREIGN-SUBJECT', 'body_text' => 'b',
            'status' => 'accepted', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = $this->get($this->showUrl($workspace, $business))->getContent();

        $this->assertSame(10, substr_count($html, 'accepted by your email provider'));
        $this->assertStringNotContainsString('FOREIGN-SUBJECT', $html);
    }

    // ---- navigation ------------------------------------------------------

    public function test_settings_offers_email_under_communication_to_a_permitted_user(): void
    {
        [$customer, $business, $workspace] = $this->emailTenant();
        $this->authenticateAs($customer);

        $hub = $this->get(route('customer.workspaces.businesses.settings.show', [$workspace->uid, $business->uid]))->assertOk()->getContent();

        $this->assertContains('email', $this->settingsHubModules($hub)['communication'] ?? []);
        $this->assertStringContainsString($this->showUrl($workspace, $business), $hub);
    }
}
