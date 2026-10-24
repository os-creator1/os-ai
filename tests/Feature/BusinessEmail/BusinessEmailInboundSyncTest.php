<?php

namespace Tests\Feature\BusinessEmail;

use App\DTO\BusinessEmail\BusinessEmailInboundBatch;
use App\DTO\BusinessEmail\BusinessEmailInboundMessage;
use App\DTO\BusinessEmail\BusinessEmailSendRequest;
use App\Enums\BusinessEmail\BusinessEmailAccountState;
use App\Enums\BusinessEmail\BusinessEmailFailureCategory as Category;
use App\Enums\BusinessEmail\BusinessEmailMessageStatus as Status;
use App\Exceptions\BusinessEmail\BusinessEmailProviderException;
use App\Library\BusinessEmail\BusinessEmailInboundSyncer;
use App\Library\BusinessEmail\BusinessEmailSender;
use App\Library\BusinessEmail\GoogleBusinessEmailProvider;
use App\Models\Business;
use App\Models\BusinessEmailAccount;
use App\Models\BusinessEmailMessage;
use App\Models\Contacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\BusinessEmail\Concerns\CreatesBusinessEmailFixtures;
use Tests\TestCase;

/**
 * Inbound Gmail synchronization against the in-memory provider (no network):
 * conversation placement, reply threading, idempotency, tenant isolation and
 * failure handling. The Gmail payload parser is covered with a canned
 * `users.messages` resource.
 */
class BusinessEmailInboundSyncTest extends TestCase
{
    use CreatesBusinessEmailFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->bindFakeEmailProviders();
    }

    private function syncable(Business $business): BusinessEmailAccount
    {
        $account = $this->activeAccount($business);
        DB::table('business_email_accounts')->where('id', $account->id)->update(['granted_scopes' => 'fake-send-scope fake-read-scope']);

        return $account->fresh();
    }

    private function inbound(string $id, string $from = 'pat@example.com', string $thread = 't-1', array $extra = []): BusinessEmailInboundMessage
    {
        return new BusinessEmailInboundMessage(
            providerMessageId: $id,
            providerThreadId: $thread,
            internetMessageId: $extra['mid'] ?? "<{$id}@example.com>",
            inReplyTo: $extra['reply_to'] ?? null,
            references: null,
            fromEmail: $from,
            fromName: 'Pat',
            toEmail: 'owner@business.test',
            subject: $extra['subject'] ?? 'Question',
            bodyText: 'Hello from a customer',
            receivedAt: now(),
            labels: $extra['labels'] ?? ['INBOX'],
        );
    }

    private function feed(array $messages, ?string $cursor = 'h-1'): void
    {
        $this->fakeGoogle->inboundScript[] = new BusinessEmailInboundBatch($messages, $cursor);
    }

    private function syncer(): BusinessEmailInboundSyncer
    {
        return app(BusinessEmailInboundSyncer::class);
    }

    public function test_incoming_mail_lands_in_the_matching_contacts_thread_with_original_identifiers(): void
    {
        [, $business] = $this->emailTenant();
        $account = $this->syncable($business);
        $contact = $this->contactWithEmails($business, ['pat@example.com']);

        $this->feed([$this->inbound('g-1')]);
        $result = $this->syncer()->sync($account);

        $this->assertSame(1, $result['stored']);
        $row = BusinessEmailMessage::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame('inbound', $row->direction);
        $this->assertSame(Status::Received, $row->status);
        $this->assertSame($contact->id, (int) $row->contact_id);
        $this->assertSame('pat@example.com', $row->from_email);
        $this->assertSame('owner@business.test', $row->to_email);
        $this->assertSame('Question', $row->subject);
        $this->assertSame('g-1', $row->provider_message_id);
        $this->assertSame('t-1', $row->provider_thread_id);
        $this->assertSame('<g-1@example.com>', $row->internet_message_id);
        $this->assertNotNull($row->location_id);
        $this->assertSame('h-1', $account->fresh()->inbound_cursor);
    }

    public function test_a_reply_stays_in_the_thread_of_the_message_we_sent_and_carries_reply_headers(): void
    {
        [, $business] = $this->emailTenant();
        $account = $this->syncable($business);
        $contact = $this->contactWithEmails($business, ['pat@example.com']);

        $sent = app(BusinessEmailSender::class)->send(new BusinessEmailSendRequest($business, $contact, 'Quote', 'Here it is', 'op-1'));
        $this->assertSame('thread-1', $sent->provider_thread_id);

        // Customer answers: matched by the provider thread, not only by address.
        $this->feed([$this->inbound('g-2', 'pat@example.com', 'thread-1', ['reply_to' => $sent->internet_message_id, 'subject' => 'Re: Quote'])]);
        $this->syncer()->sync($account);

        $inbound = BusinessEmailMessage::query()->where('direction', 'inbound')->firstOrFail();
        $this->assertSame('thread-1', $inbound->provider_thread_id);
        $this->assertSame($sent->location_id, $inbound->location_id);
        $this->assertSame($sent->internet_message_id, $inbound->in_reply_to);

        // Our reply goes to the customer, in the same thread, with RFC headers.
        $reply = app(BusinessEmailSender::class)->send(new BusinessEmailSendRequest(
            $business, $contact, 'Re: Quote', 'Thanks!', 'reply-1', replyTo: $inbound,
        ));

        $this->assertSame(Status::Accepted, $reply->status);
        $out = end($this->fakeGoogle->sent);
        $this->assertSame('pat@example.com', $out->toEmail);
        $this->assertSame('thread-1', $out->threadId);
        $this->assertSame('<g-2@example.com>', $out->inReplyTo);
        $this->assertStringContainsString('<g-2@example.com>', (string) $out->references);
    }

    public function test_repeated_and_overlapping_syncs_never_duplicate_messages_and_skip_our_own_mail(): void
    {
        [, $business] = $this->emailTenant();
        $account = $this->syncable($business);
        $contact = $this->contactWithEmails($business, ['pat@example.com']);

        $sent = app(BusinessEmailSender::class)->send(new BusinessEmailSendRequest($business, $contact, 'Hi', 'Body', 'op-dup'));

        $batch = [
            $this->inbound('g-1'),
            // Gmail shows our own sent mail too: by SENT label and by stored id.
            $this->inbound('x-sent', 'owner@business.test', 'x', ['labels' => ['SENT']]),
            $this->inbound($sent->provider_message_id, 'pat@example.com', 'thread-1'),
        ];

        $this->feed($batch);
        $this->feed($batch);
        $first = $this->syncer()->sync($account);
        $second = $this->syncer()->sync($account);

        $this->assertSame(1, $first['stored']);
        $this->assertSame(0, $second['stored']);
        $this->assertSame(1, BusinessEmailMessage::query()->where('direction', 'inbound')->count());
        $this->assertSame(2, BusinessEmailMessage::query()->where('business_id', $business->id)->count());
    }

    public function test_one_business_never_receives_or_matches_into_another_businesss_mailbox_data(): void
    {
        [, $businessA] = $this->emailTenant('Alpha Studio');
        [, $businessB] = $this->emailTenant('Beta Studio');
        $accountA = $this->syncable($businessA);
        $contactB = $this->contactWithEmails($businessB, ['pat@example.com']);
        $contactA = $this->contactWithEmails($businessA, ['pat@example.com']);

        $this->feed([$this->inbound('g-1')]);
        $this->syncer()->sync($accountA);

        $row = BusinessEmailMessage::query()->firstOrFail();
        $this->assertSame($businessA->id, (int) $row->business_id);
        $this->assertSame($contactA->id, (int) $row->contact_id);
        $this->assertNotSame($contactB->id, (int) $row->contact_id);
        $this->assertSame(0, BusinessEmailMessage::query()->where('business_id', $businessB->id)->count());
        // The location is one of A's own.
        $this->assertSame($businessA->id, (int) DB::table('business_locations')->where('id', $row->location_id)->value('business_id'));
    }

    public function test_a_reply_to_another_businesss_message_is_refused_without_a_provider_call(): void
    {
        [, $businessA] = $this->emailTenant('Alpha Studio');
        [, $businessB] = $this->emailTenant('Beta Studio');
        $this->activeAccount($businessA);
        $contactA = $this->contactWithEmails($businessA, ['pat@example.com']);
        $this->syncable($businessB);
        $contactB = $this->contactWithEmails($businessB, ['pat@example.com']);

        $this->feed([$this->inbound('g-1')]);
        $this->syncer()->sync(BusinessEmailAccount::query()->where('business_id', $businessB->id)->firstOrFail());
        $foreign = BusinessEmailMessage::query()->where('business_id', $businessB->id)->firstOrFail();

        $this->expectException(\App\Exceptions\BusinessEmail\BusinessEmailSendRefusedException::class);

        try {
            app(BusinessEmailSender::class)->send(new BusinessEmailSendRequest($businessA, $contactA, 'Re', 'x', 'op-x', replyTo: $foreign));
        } finally {
            $this->assertSame(0, $this->fakeGoogle->callCount('send'));
            unset($contactB);
        }
    }

    public function test_a_grant_without_the_read_permission_is_never_synced_and_asks_for_reauthorization(): void
    {
        [, $business] = $this->emailTenant();
        $account = $this->activeAccount($business); // pre-existing, send-only grant

        $result = $this->syncer()->sync($account);

        $this->assertSame(0, $result['stored']);
        $this->assertSame(0, $this->fakeGoogle->callCount('fetchInbound'));
        $this->assertSame('reauthorization_required', $account->fresh()->inbound_failure);
        $this->assertTrue($account->fresh()->isActive(), 'the existing connection keeps working for sending');
    }

    public function test_revoked_credentials_end_the_connection_and_destroy_the_token(): void
    {
        [, $business] = $this->emailTenant();
        $account = $this->syncable($business);
        $this->fakeGoogle->refreshFailure = new BusinessEmailProviderException(Category::AuthenticationExpired, 'invalid_grant', revocation: true);

        try {
            $this->syncer()->sync($account);
            $this->fail('A revoked grant must surface as a provider exception.');
        } catch (BusinessEmailProviderException $exception) {
            $this->assertTrue($exception->isRevocation());
        }

        $fresh = $account->fresh();
        $this->assertSame(BusinessEmailAccountState::Revoked, $fresh->state);
        $this->assertNull($fresh->refresh_token_encrypted);
        $this->assertSame(0, $this->fakeGoogle->callCount('fetchInbound'));
    }

    public function test_a_temporary_failure_keeps_the_cursor_and_the_next_run_resumes_without_loss(): void
    {
        [, $business] = $this->emailTenant();
        $account = $this->syncable($business);
        DB::table('business_email_accounts')->where('id', $account->id)->update(['inbound_cursor' => 'h-0']);

        $this->fakeGoogle->inboundScript[] = new BusinessEmailProviderException(Category::TemporaryProviderFailure, '503');
        $this->feed([$this->inbound('g-1')], 'h-5');

        try {
            $this->syncer()->sync($account->fresh());
            $this->fail('Expected a retryable provider exception.');
        } catch (BusinessEmailProviderException $exception) {
            $this->assertTrue($exception->category->isRetryable());
        }

        $this->assertSame('h-0', $account->fresh()->inbound_cursor);
        $this->assertSame(Category::TemporaryProviderFailure->value, $account->fresh()->inbound_failure);

        $this->syncer()->sync($account->fresh());

        $this->assertSame(['h-0', 'h-0'], $this->fakeGoogle->inboundCursors);
        $this->assertSame(1, BusinessEmailMessage::query()->where('direction', 'inbound')->count());
        $this->assertSame('h-5', $account->fresh()->inbound_cursor);
        $this->assertNull($account->fresh()->inbound_failure);
    }

    public function test_insufficient_permission_is_recorded_for_reauthorization_not_retried_or_revoked(): void
    {
        [, $business] = $this->emailTenant();
        $account = $this->syncable($business);
        $this->fakeGoogle->inboundScript[] = new BusinessEmailProviderException(Category::PermissionDenied, 'insufficientPermissions');

        $result = $this->syncer()->sync($account);

        $this->assertSame(0, $result['stored']);
        $this->assertTrue($account->fresh()->isActive());
        $this->assertSame('reauthorization_required', $account->fresh()->inbound_failure);
    }

    public function test_the_gmail_payload_is_parsed_into_sender_recipient_subject_ids_and_text(): void
    {
        $provider = new GoogleBusinessEmailProvider(app(\App\Library\BusinessEmail\BusinessEmailOAuthConfig::class));

        $message = $provider->parseMessage([
            'id' => '18c', 'threadId' => '18a', 'internalDate' => '1700000000000', 'labelIds' => ['INBOX', 'UNREAD'],
            'payload' => [
                'mimeType' => 'multipart/alternative',
                'headers' => [
                    ['name' => 'From', 'value' => '"Pat Q" <Pat@Example.com>'],
                    ['name' => 'To', 'value' => 'owner@business.test'],
                    ['name' => 'Subject', 'value' => 'Re: Quote'],
                    ['name' => 'Message-ID', 'value' => '<abc@mail.example.com>'],
                    ['name' => 'In-Reply-To', 'value' => '<orig@business.test>'],
                    ['name' => 'References', 'value' => '<orig@business.test>'],
                ],
                'parts' => [
                    ['mimeType' => 'text/html', 'body' => ['data' => rtrim(strtr(base64_encode('<b>html</b>'), '+/', '-_'), '=')]],
                    ['mimeType' => 'text/plain', 'body' => ['data' => rtrim(strtr(base64_encode('Plain body'), '+/', '-_'), '=')]],
                ],
            ],
        ]);

        $this->assertNotNull($message);
        $this->assertSame('pat@example.com', $message->fromEmail);
        $this->assertSame('Pat Q', $message->fromName);
        $this->assertSame('owner@business.test', $message->toEmail);
        $this->assertSame('Re: Quote', $message->subject);
        $this->assertSame('<abc@mail.example.com>', $message->internetMessageId);
        $this->assertSame('<orig@business.test>', $message->inReplyTo);
        $this->assertSame('18a', $message->providerThreadId);
        $this->assertSame('Plain body', $message->bodyText);
        $this->assertSame(['INBOX', 'UNREAD'], $message->labels);
    }

    public function test_the_gmail_adapter_syncs_incrementally_from_the_persisted_history_cursor_and_threads_replies(): void
    {
        $provider = new GoogleBusinessEmailProvider(app(\App\Library\BusinessEmail\BusinessEmailOAuthConfig::class));

        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/history*' => Http::response([
                'historyId' => '900',
                'history' => [['id' => '850', 'messagesAdded' => [['message' => ['id' => 'm1']], ['message' => ['id' => 'm1']]]]],
            ]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/m1*' => Http::response([
                'id' => 'm1', 'threadId' => 't1', 'labelIds' => ['INBOX'],
                'payload' => ['headers' => [['name' => 'From', 'value' => 'pat@example.com'], ['name' => 'Subject', 'value' => 'Hi']], 'body' => ['data' => '']],
            ]),
        ]);

        $batch = $provider->fetchInbound('token', '800', 50);

        $this->assertSame('900', $batch->cursor);
        $this->assertFalse($batch->hasMore);
        $this->assertCount(1, $batch->messages, 'a message listed twice is fetched once');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'startHistoryId=800') && str_contains($request->url(), 'labelId=INBOX'));

        [$mime] = $provider->buildMime(new \App\DTO\BusinessEmail\BusinessEmailOutbound(
            'owner@business.test', null, 'pat@example.com', 'Re: Hi', 'Body', inReplyTo: '<a@x>', references: '<a@x>', threadId: 't1',
        ));
        $this->assertStringContainsString("In-Reply-To: <a@x>\r\n", $mime);
        $this->assertStringContainsString("References: <a@x>\r\n", $mime);
    }
}
