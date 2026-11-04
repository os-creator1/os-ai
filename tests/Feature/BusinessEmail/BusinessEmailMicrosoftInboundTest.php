<?php

namespace Tests\Feature\BusinessEmail;

use App\DTO\BusinessEmail\BusinessEmailSendRequest;
use App\Enums\BusinessEmail\BusinessEmailAccountState as State;
use App\Enums\BusinessEmail\BusinessEmailFailureCategory as Category;
use App\Enums\BusinessEmail\BusinessEmailMessageStatus as Status;
use App\Enums\BusinessEmail\BusinessEmailProviderType;
use App\Exceptions\BusinessEmail\BusinessEmailProviderException;
use App\Library\BusinessEmail\BusinessEmailInboundSyncer;
use App\Library\BusinessEmail\BusinessEmailOAuthConfig;
use App\Library\BusinessEmail\BusinessEmailSender;
use App\Library\BusinessEmail\MicrosoftBusinessEmailProvider;
use App\Models\Business;
use App\Models\BusinessEmailAccount;
use App\Models\BusinessEmailMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\BusinessEmail\Concerns\CreatesBusinessEmailFixtures;
use Tests\TestCase;

/**
 * Microsoft Graph inbound (delta), threaded replies and Mail.Read
 * reauthorization, with the REAL adapter driven only through Http::fake().
 */
class BusinessEmailMicrosoftInboundTest extends TestCase
{
    use CreatesBusinessEmailFixtures;
    use RefreshDatabase;

    private const DELTA = 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeEmailProviders();
        // The REAL Microsoft adapter for these tests.
        $this->app->instance(MicrosoftBusinessEmailProvider::class, new MicrosoftBusinessEmailProvider(app(BusinessEmailOAuthConfig::class)));
        $this->freshHttp();
    }

    private function freshHttp(): void
    {
        Http::swap(new Factory());
        Http::preventStrayRequests();
    }

    private function msAccount(Business $business, string $scopes = 'Mail.Send Mail.Read User.Read'): BusinessEmailAccount
    {
        $account = $this->activeAccount($business, BusinessEmailProviderType::Microsoft, 'owner@contoso.com');
        DB::table('business_email_accounts')->where('id', $account->id)->update(['granted_scopes' => $scopes]);

        return $account->fresh();
    }

    private function token(): array
    {
        return ['login.microsoftonline.com/*' => Http::response(['access_token' => 'at', 'refresh_token' => 'rotated-rt', 'scope' => 'Mail.Send Mail.Read'])];
    }

    /** @return array<string, mixed> */
    private function item(string $id, string $from = 'pat@example.com', array $extra = []): array
    {
        return $extra + [
            'id' => $id, 'conversationId' => 'conv-' . $id, 'internetMessageId' => "<{$id}@mail.example.com>", 'subject' => 'Question',
            'from' => ['emailAddress' => ['address' => $from, 'name' => 'Pat']],
            'toRecipients' => [['emailAddress' => ['address' => 'owner@contoso.com']]],
            'body' => ['contentType' => 'text', 'content' => 'Hello from Outlook'],
            'receivedDateTime' => '2026-11-02T10:00:00Z', 'isDraft' => false,
        ];
    }

    private function sync(BusinessEmailAccount $account): array
    {
        return app(BusinessEmailInboundSyncer::class)->sync($account->fresh());
    }

    public function test_microsoft_asks_for_mail_read_and_a_send_only_grant_keeps_sending_but_does_not_sync(): void
    {
        $url = app(MicrosoftBusinessEmailProvider::class)->authorizationUrl('STATE', true);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertContains('Mail.Read', explode(' ', $q['scope']));
        $this->assertContains('Mail.Send', explode(' ', $q['scope']));

        [, $business] = $this->emailTenant();
        $sendOnly = $this->msAccount($business, 'Mail.Send User.Read');
        $syncer = app(BusinessEmailInboundSyncer::class);

        $this->assertFalse($syncer->canSync($sendOnly));
        $this->assertTrue($syncer->supportsInbound($sendOnly));
        $this->assertSame(0, $this->sync($sendOnly)['stored']);
        $this->assertSame('reauthorization_required', $sendOnly->fresh()->inbound_failure);
        $this->assertTrue($sendOnly->fresh()->isActive(), 'sending is untouched');

        // Prefixed scope names (as personal accounts return them) count, case-insensitively.
        DB::table('business_email_accounts')->where('id', $sendOnly->id)->update(['granted_scopes' => 'https://graph.microsoft.com/mail.read https://graph.microsoft.com/Mail.Send']);
        $this->assertTrue($syncer->canSync($sendOnly->fresh()));
    }

    public function test_delta_sync_pages_stores_new_mail_once_and_resumes_from_the_saved_link(): void
    {
        [, $business] = $this->emailTenant();
        $account = $this->msAccount($business);
        $contact = $this->contactWithEmails($business, ['pat@example.com']);

        Http::fake($this->token() + [
            self::DELTA . '*' => Http::response([
                'value' => [
                    $this->item('m1'),
                    $this->item('m2', 'pat@example.com', ['body' => ['contentType' => 'html', 'content' => '<p>Hi <script>alert(1)</script>there</p>']]),
                    $this->item('draft', 'pat@example.com', ['isDraft' => true]),
                    ['id' => 'gone', '@removed' => ['reason' => 'deleted']],
                ],
                '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$skiptoken=PAGE2',
            ]),
        ]);

        $first = $this->sync($account);
        $this->assertSame(2, $first['stored']);
        $this->assertTrue($first['has_more']);
        $this->assertStringContainsString('skiptoken=PAGE2', (string) $account->fresh()->inbound_cursor);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/delta') && str_contains($r->url(), 'receivedDateTime') && str_contains(implode(',', $r->header('Prefer')), 'odata.maxpagesize='));

        $row = BusinessEmailMessage::query()->where('provider_message_id', 'm2')->firstOrFail();
        $this->assertSame('inbound', $row->direction);
        $this->assertSame($contact->id, (int) $row->contact_id);
        $this->assertSame('conv-m2', $row->provider_thread_id);
        $this->assertSame('<m2@mail.example.com>', $row->internet_message_id);
        $this->assertStringContainsString('Hi', $row->body_text);
        $this->assertStringNotContainsString('alert', $row->body_text);

        // Page 2 ends with a deltaLink; a repeated item is not duplicated.
        $this->freshHttp();
        Http::fake($this->token() + [
            '*skiptoken=PAGE2*' => Http::response([
                'value' => [$this->item('m1'), $this->item('m3')],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=DONE',
            ]),
        ]);

        $second = $this->sync($account);
        $this->assertSame(1, $second['stored']);
        $this->assertFalse($second['has_more']);
        $this->assertStringContainsString('deltatoken=DONE', (string) $account->fresh()->inbound_cursor);
        $this->assertSame(3, BusinessEmailMessage::query()->where('direction', 'inbound')->count());
        $this->assertSame('rotated-rt', \Illuminate\Support\Facades\Crypt::decryptString((string) DB::table('business_email_accounts')->where('id', $account->id)->value('refresh_token_encrypted')), 'the rotated refresh token is stored');
    }

    public function test_a_foreign_or_expired_cursor_never_sends_the_token_elsewhere_and_restarts_safely(): void
    {
        [, $business] = $this->emailTenant();
        $account = $this->msAccount($business);

        // A cursor pointing at another host is ignored (no token is ever sent there).
        DB::table('business_email_accounts')->where('id', $account->id)->update(['inbound_cursor' => 'https://evil.example.net/steal?x=1']);
        Http::fake($this->token() + [self::DELTA . '*' => Http::response(['value' => [$this->item('m1')], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/x?$deltatoken=A'])]);
        $this->assertSame(1, $this->sync($account)['stored']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'evil.example.net'));

        // 410 syncStateNotFound: restart from the bounded window; known ids dedupe.
        $this->freshHttp();
        $stale = 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=OLD';
        DB::table('business_email_accounts')->where('id', $account->id)->update(['inbound_cursor' => $stale]);
        Http::fake($this->token() + [
            '*deltatoken=OLD*' => Http::response(['error' => ['code' => 'SyncStateNotFound', 'message' => 'x']], 410),
            self::DELTA . '*' => Http::response(['value' => [$this->item('m1'), $this->item('m9')], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/x?$deltatoken=NEW']),
        ]);
        $this->assertSame(1, $this->sync($account)['stored']);
        $this->assertStringContainsString('deltatoken=NEW', (string) $account->fresh()->inbound_cursor);
    }

    public function test_microsoft_failures_keep_the_cursor_and_a_revoked_grant_ends_the_connection(): void
    {
        [, $business] = $this->emailTenant();
        $account = $this->msAccount($business);
        $cursor = 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$deltatoken=KEEP';
        DB::table('business_email_accounts')->where('id', $account->id)->update(['inbound_cursor' => $cursor]);

        Http::fake($this->token() + ['*deltatoken=KEEP*' => Http::response(['error' => ['code' => 'ServiceNotAvailable']], 503)]);
        try {
            $this->sync($account);
            $this->fail('expected a retryable failure');
        } catch (BusinessEmailProviderException $exception) {
            $this->assertSame(Category::TemporaryProviderFailure, $exception->category);
        }
        $this->assertSame($cursor, $account->fresh()->inbound_cursor);
        $this->assertTrue($account->fresh()->isActive());

        // 403 on the mail endpoints (Mail.Read withdrawn): no retry, no revoke, sending stays on.
        $this->freshHttp();
        Http::fake($this->token() + ['*deltatoken=KEEP*' => Http::response(['error' => ['code' => 'ErrorAccessDenied']], 403)]);
        $this->assertSame(0, $this->sync($account)['stored']);
        $this->assertSame('reauthorization_required', $account->fresh()->inbound_failure);
        $this->assertTrue($account->fresh()->isActive());

        // invalid_grant at the token endpoint: the connection ends.
        $this->freshHttp();
        Http::fake(['login.microsoftonline.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);
        try {
            $this->sync($account);
            $this->fail('expected a revocation');
        } catch (BusinessEmailProviderException $exception) {
            $this->assertTrue($exception->isRevocation());
        }
        $this->assertSame(State::Revoked, $account->fresh()->state);
        $this->assertNull($account->fresh()->refresh_token_encrypted);
    }

    public function test_replies_use_graph_createreply_to_the_stored_counterpart_and_fall_back_when_the_original_is_gone(): void
    {
        [, $business] = $this->emailTenant();
        $account = $this->msAccount($business);
        $contact = $this->contactWithEmails($business, ['pat@example.com']);

        Http::fake($this->token() + [
            self::DELTA . '*' => Http::response(['value' => [$this->item('m1')], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/x?$deltatoken=A']),
        ]);
        $this->sync($account);
        $inbound = BusinessEmailMessage::query()->where('direction', 'inbound')->firstOrFail();

        $this->freshHttp();
        Http::fake($this->token() + [
            'graph.microsoft.com/v1.0/me/messages/m1/createReply' => Http::response(['id' => 'draft-1', 'conversationId' => 'conv-m1', 'internetMessageId' => '<r1@contoso.com>'], 201),
            'graph.microsoft.com/v1.0/me/messages/draft-1' => Http::response(['id' => 'draft-1', 'conversationId' => 'conv-m1', 'internetMessageId' => '<r1@contoso.com>']),
            'graph.microsoft.com/v1.0/me/messages/draft-1/send' => Http::response('', 202),
        ]);

        $reply = app(BusinessEmailSender::class)->send(new BusinessEmailSendRequest($business, $contact, 'Re: Question', 'Answer', 'rep-1', inResponseTo: $inbound));

        $this->assertSame(Status::Accepted, $reply->status);
        $this->assertSame('conv-m1', $reply->provider_thread_id, 'same Graph conversation as the inbound message');
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && ($r->data()['toRecipients'][0]['emailAddress']['address'] ?? null) === 'pat@example.com' && ($r->data()['body']['content'] ?? null) === 'Answer');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/m1/createReply'));

        // The original is no longer addressable: a plain message is sent instead of failing the reply.
        $this->freshHttp();
        Http::fake($this->token() + [
            'graph.microsoft.com/v1.0/me/messages/m1/createReply' => Http::response(['error' => ['code' => 'ErrorItemNotFound']], 404),
            'graph.microsoft.com/v1.0/me/messages' => Http::response(['id' => 'draft-2', 'conversationId' => 'conv-new', 'internetMessageId' => '<r2@contoso.com>'], 201),
            'graph.microsoft.com/v1.0/me/messages/draft-2/send' => Http::response('', 202),
        ]);

        $fallback = app(BusinessEmailSender::class)->send(new BusinessEmailSendRequest($business, $contact, 'Re: Question', 'Answer 2', 'rep-2', inResponseTo: $inbound));
        $this->assertSame(Status::Accepted, $fallback->status);
        $this->assertSame('pat@example.com', $fallback->to_email);
    }

    public function test_reauthorizing_microsoft_for_mail_read_keeps_sending_until_the_verified_callback(): void
    {
        [$owner, $business, $workspace] = $this->emailTenant();
        $this->authenticateAs($owner);
        $account = $this->msAccount($business, 'Mail.Send User.Read');
        $this->app->instance(MicrosoftBusinessEmailProvider::class, $this->fakeMicrosoft);
        $contact = $this->contactWithEmails($business, ['pat@example.com']);

        $response = $this->post(route('customer.workspaces.businesses.email.connect', [$workspace->uid, $business->uid, 'microsoft']), ['reauthorize' => 1]);
        $response->assertRedirect();
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertSame(State::Active, $account->fresh()->state);
        $sent = app(BusinessEmailSender::class)->send(new BusinessEmailSendRequest($business, $contact, 'Hi', 'Body', 'during'));
        $this->assertSame(Status::Accepted, $sent->status);

        $this->fakeMicrosoft->mailbox = 'owner@contoso.com';
        $this->fakeMicrosoft->codeExchangeRefreshToken = 'new-rt';
        $this->fakeMicrosoft->codeExchangeScopes = 'Mail.Send Mail.Read User.Read';
        $this->get(route('customer.email.oauth.callback', ['provider' => 'microsoft']) . '?' . http_build_query(['state' => $query['state'], 'code' => 'c']))->assertRedirect();

        $fresh = $account->fresh();
        $this->assertSame(State::Active, $fresh->state);
        $this->assertStringContainsString('Mail.Read', (string) $fresh->granted_scopes);
        $this->assertSame('new-rt', $fresh->refresh_token_encrypted);
    }
}
