<?php

namespace Tests\Feature\BusinessEmail;

use App\DTO\BusinessEmail\BusinessEmailOutbound;
use App\Enums\BusinessEmail\BusinessEmailFailureCategory as Category;
use App\Exceptions\BusinessEmail\BusinessEmailProviderException;
use App\Library\BusinessEmail\GoogleBusinessEmailProvider;
use App\Library\BusinessEmail\MicrosoftBusinessEmailProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The REAL Google and Microsoft adapters, driven only through Http::fake().
 * `Http::preventStrayRequests()` makes any request that is not faked a hard
 * failure, so no test here can reach Google or Microsoft.
 */
class BusinessEmailProviderAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        config([
            'business_email.google.client_id' => 'g-client',
            'business_email.google.client_secret' => 'g-secret',
            'business_email.google.redirect' => 'https://app.example.test/email/oauth/google/callback',
            'business_email.microsoft.client_id' => 'm-client',
            'business_email.microsoft.client_secret' => 'm-secret',
            'business_email.microsoft.redirect' => 'https://app.example.test/email/oauth/microsoft/callback',
        ]);
    }

    /** A fresh fake registry: stubs from an earlier Http::fake() would otherwise win. */
    private function freshHttp(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
    }

    private function google(): GoogleBusinessEmailProvider
    {
        return app(GoogleBusinessEmailProvider::class);
    }

    private function microsoft(): MicrosoftBusinessEmailProvider
    {
        return app(MicrosoftBusinessEmailProvider::class);
    }

    private function email(string $subject = 'Hello', string $body = 'Line one', string $to = 'pat@example.com'): BusinessEmailOutbound
    {
        return new BusinessEmailOutbound('owner@business.test', 'Owner Name', $to, $subject, $body);
    }

    private function failure(callable $call): BusinessEmailProviderException
    {
        try {
            $call();
        } catch (BusinessEmailProviderException $exception) {
            return $exception;
        }

        $this->fail('Expected a BusinessEmailProviderException.');
    }

    // ---- scopes and consent ---------------------------------------------

    public function test_google_requests_only_identity_and_send_scopes_with_offline_access_and_consent(): void
    {
        $url = $this->google()->authorizationUrl('STATE', true);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        $this->assertSame('openid email https://www.googleapis.com/auth/gmail.send', $q['scope']);
        $this->assertSame('offline', $q['access_type']);
        $this->assertSame('consent', $q['prompt']);
        $this->assertSame('STATE', $q['state']);
        $this->assertSame('https://app.example.test/email/oauth/google/callback', $q['redirect_uri']);
        $this->assertArrayNotHasKey('include_granted_scopes', $q, 'A Calendar/Business Profile grant is never folded into the email grant.');
        $this->assertStringNotContainsString('readonly', $url);
        $this->assertStringNotContainsString('calendar', $url);
    }

    public function test_microsoft_requests_only_identity_and_send_scopes(): void
    {
        $url = $this->microsoft()->authorizationUrl('STATE', true);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $this->assertStringStartsWith('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?', $url);
        $this->assertSame('offline_access User.Read Mail.Send', $q['scope']);
        $this->assertStringNotContainsString('Mail.Read ', $q['scope'] . ' ');
        $this->assertStringNotContainsString('Calendars', $url);
        $this->assertSame('consent', $q['prompt']);
    }

    public function test_the_microsoft_tenant_cannot_alter_the_url(): void
    {
        config(['business_email.microsoft.tenant' => 'evil.example/../x?y=']);

        $this->assertStringStartsWith('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?', $this->microsoft()->authorizationUrl('S', false));

        config(['business_email.microsoft.tenant' => 'contoso.onmicrosoft.com']);

        $this->assertStringStartsWith('https://login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/authorize?', $this->microsoft()->authorizationUrl('S', false));
    }

    // ---- Google ----------------------------------------------------------

    public function test_google_exchanges_the_code_and_identifies_the_mailbox_without_trusting_it_as_authorization(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'g-access', 'refresh_token' => 'g-refresh',
                'scope' => 'openid https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/gmail.send',
            ]),
            'openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => '123', 'email' => 'Boss@Business.TEST', 'name' => 'The Boss']),
        ]);

        $grant = $this->google()->exchangeAuthorizationCode('the-code');

        $this->assertSame('boss@business.test', $grant->accountEmail);
        $this->assertSame('g-refresh', $grant->refreshToken);
        $this->assertSame('123', $grant->accountId);
        $this->assertSame('The Boss', $grant->displayName);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/token'
            && $r['code'] === 'the-code' && $r['grant_type'] === 'authorization_code' && $r['client_secret'] === 'g-secret');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://openidconnect.googleapis.com/v1/userinfo'
            && $r->hasHeader('Authorization', 'Bearer g-access'));
    }

    public function test_google_fails_closed_when_the_user_unticked_the_send_permission(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'scope' => 'openid email']),
        ]);

        $exception = $this->failure(fn () => $this->google()->exchangeAuthorizationCode('code'));

        $this->assertSame(Category::PermissionDenied, $exception->category);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'userinfo'));
    }

    public function test_google_sends_one_plain_text_message_through_the_gmail_api(): void
    {
        Http::fake(['gmail.googleapis.com/*' => Http::response(['id' => 'gm-1', 'threadId' => 'th-1'])]);

        $result = $this->google()->send('g-access', $this->email('Héllo wörld', "Body text\nsecond line"));

        $this->assertSame('gm-1', $result->providerMessageId);
        $this->assertSame('th-1', $result->providerThreadId);
        $this->assertNotNull($result->internetMessageId);

        Http::assertSent(function (Request $r) {
            if ($r->url() !== 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send' || ! $r->hasHeader('Authorization', 'Bearer g-access')) {
                return false;
            }

            $mime = base64_decode(strtr($r['raw'], '-_', '+/'));
            [$headers, $body] = explode("\r\n\r\n", $mime, 2);

            return str_contains($headers, "To: pat@example.com\r\n")
                && str_contains($headers, 'From: Owner Name <owner@business.test>')
                && str_contains($headers, 'Subject: =?UTF-8?B?' . base64_encode('Héllo wörld') . '?=')
                && str_contains($headers, 'Content-Type: text/plain; charset=UTF-8')
                && base64_decode(str_replace("\r\n", '', $body)) === "Body text\nsecond line";
        });
    }

    public function test_google_mime_cannot_be_header_injected_through_subject_name_or_address(): void
    {
        $email = new BusinessEmailOutbound(
            "owner@business.test\r\nBcc: spy@example.com",
            "Evil\r\nBcc: spy2@example.com",
            "pat@example.com\r\nBcc: spy3@example.com",
            "Hi\r\nBcc: spy4@example.com",
            'body',
        );

        [$mime] = $this->google()->buildMime($email);
        [$headers] = explode("\r\n\r\n", $mime, 2);

        $this->assertDoesNotMatchRegularExpression('/^Bcc:/mi', $headers);
        $this->assertSame(1, substr_count($headers, 'To: '));
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('googleSendFailures')]
    public function test_google_send_failures_map_to_the_provider_neutral_taxonomy(int $status, array $body, Category $expected, bool $retryable, bool $revocation): void
    {
        Http::fake(['gmail.googleapis.com/*' => Http::response($body, $status)]);

        $exception = $this->failure(fn () => $this->google()->send('tok', $this->email()));

        $this->assertSame($expected, $exception->category);
        $this->assertSame($retryable, $exception->category->isRetryable());
        $this->assertSame($revocation, $exception->isRevocation());
        $this->assertFalse($exception->isAmbiguous());
        // Neither the exception nor its message may carry a provider payload.
        $this->assertStringNotContainsString('SECRET-DETAIL', $exception->getMessage() . (string) $exception->providerCode);
    }

    /** @return array<string, array{0: int, 1: array<string, mixed>, 2: Category, 3: bool, 4: bool}> */
    public static function googleSendFailures(): array
    {
        return [
            '401 token rejected' => [401, ['error' => ['code' => 401, 'message' => 'SECRET-DETAIL', 'status' => 'UNAUTHENTICATED']], Category::AuthenticationExpired, false, true],
            '403 insufficient scope' => [403, ['error' => ['code' => 403, 'message' => 'SECRET-DETAIL', 'errors' => [['reason' => 'insufficientPermissions']]]], Category::PermissionDenied, false, false],
            '403 rate limit' => [403, ['error' => ['code' => 403, 'message' => 'SECRET-DETAIL', 'errors' => [['reason' => 'rateLimitExceeded']]]], Category::ProviderRateLimited, true, false],
            '429' => [429, ['error' => ['code' => 429, 'message' => 'SECRET-DETAIL']], Category::ProviderRateLimited, true, false],
            '500' => [500, ['error' => ['code' => 500, 'message' => 'SECRET-DETAIL']], Category::TemporaryProviderFailure, true, false],
            '503' => [503, [], Category::TemporaryProviderFailure, true, false],
            '400 invalid recipient' => [400, ['error' => ['code' => 400, 'message' => 'Invalid To header SECRET-DETAIL', 'errors' => [['reason' => 'invalidArgument']]]], Category::RecipientInvalid, false, false],
            '400 other' => [400, ['error' => ['code' => 400, 'message' => 'SECRET-DETAIL']], Category::PermanentProviderFailure, false, false],
            '404' => [404, ['error' => ['code' => 404, 'message' => 'SECRET-DETAIL']], Category::PermanentProviderFailure, false, false],
        ];
    }

    public function test_an_invalid_grant_on_the_refresh_endpoint_is_a_revocation(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'SECRET-DETAIL'], 400)]);

        $exception = $this->failure(fn () => $this->google()->exchangeRefreshToken('dead-refresh'));

        $this->assertSame(Category::AuthenticationExpired, $exception->category);
        $this->assertTrue($exception->isRevocation());
        $this->assertSame('invalid_grant', $exception->providerCode);
    }

    public function test_a_token_endpoint_outage_is_temporary_and_not_a_revocation(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response('', 503)]);

        $exception = $this->failure(fn () => $this->google()->exchangeRefreshToken('r'));

        $this->assertSame(Category::TemporaryProviderFailure, $exception->category);
        $this->assertFalse($exception->isRevocation());
    }

    public function test_a_timeout_after_the_send_was_dispatched_is_ambiguous_but_a_refused_connection_is_not(): void
    {
        Http::fake(['gmail.googleapis.com/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);
        $timeout = $this->failure(fn () => $this->google()->send('tok', $this->email()));
        $this->assertTrue($timeout->isAmbiguous());
        $this->assertSame(Category::TemporaryProviderFailure, $timeout->category);

        $this->freshHttp();
        Http::fake(['gmail.googleapis.com/*' => fn () => throw new ConnectionException('cURL error 7: Failed to connect')]);
        $refused = $this->failure(fn () => $this->google()->send('tok', $this->email()));
        $this->assertFalse($refused->isAmbiguous());
    }

    public function test_google_revocation_calls_the_revoke_endpoint_and_never_throws(): void
    {
        Http::fake(['oauth2.googleapis.com/revoke' => Http::response('', 400)]);

        $this->google()->revokeGrant('the-refresh-token');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/revoke' && $r['token'] === 'the-refresh-token');

        $this->freshHttp();
        Http::fake(['oauth2.googleapis.com/revoke' => fn () => throw new ConnectionException('down')]);
        $this->google()->revokeGrant('x');
        $this->assertTrue(true);
    }

    // ---- Microsoft -------------------------------------------------------

    public function test_microsoft_exchanges_the_code_and_identifies_the_mailbox(): void
    {
        Http::fake([
            'login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response([
                'access_token' => 'm-access', 'refresh_token' => 'm-refresh', 'scope' => 'Mail.Send User.Read openid profile email',
            ]),
            'graph.microsoft.com/v1.0/me*' => Http::response(['id' => 'ms-1', 'displayName' => 'Ms Boss', 'mail' => 'Boss@Corp.Test', 'userPrincipalName' => 'upn@corp.test']),
        ]);

        $grant = $this->microsoft()->exchangeAuthorizationCode('the-code');

        $this->assertSame('boss@corp.test', $grant->accountEmail);
        $this->assertSame('m-refresh', $grant->refreshToken);
        $this->assertSame('ms-1', $grant->accountId);
    }

    public function test_microsoft_falls_back_to_the_user_principal_name_when_mail_is_empty(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'scope' => 'Mail.Send']),
            'graph.microsoft.com/v1.0/me*' => Http::response(['id' => '1', 'mail' => null, 'userPrincipalName' => 'UPN@Corp.Test']),
        ]);

        $this->assertSame('upn@corp.test', $this->microsoft()->exchangeAuthorizationCode('c')->accountEmail);
    }

    public function test_microsoft_fails_closed_without_the_send_permission(): void
    {
        Http::fake(['login.microsoftonline.com/*' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'scope' => 'User.Read'])]);

        $this->assertSame(Category::PermissionDenied, $this->failure(fn () => $this->microsoft()->exchangeAuthorizationCode('c'))->category);
    }

    public function test_microsoft_sends_by_creating_a_draft_then_sending_it(): void
    {
        Http::fake([
            'graph.microsoft.com/v1.0/me/messages' => Http::response(['id' => 'AAMk=draft/1', 'conversationId' => 'conv-9', 'internetMessageId' => '<abc@outlook.test>'], 201),
            'graph.microsoft.com/v1.0/me/messages/*/send' => Http::response('', 202),
        ]);

        $result = $this->microsoft()->send('m-access', $this->email('Subject', 'Plain body'));

        $this->assertSame('AAMk=draft/1', $result->providerMessageId);
        $this->assertSame('conv-9', $result->providerThreadId);
        $this->assertSame('<abc@outlook.test>', $result->internetMessageId);

        Http::assertSentInOrder([
            fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://graph.microsoft.com/v1.0/me/messages'
                && $r['subject'] === 'Subject'
                && $r['body'] === ['contentType' => 'Text', 'content' => 'Plain body']
                && $r['toRecipients'] === [['emailAddress' => ['address' => 'pat@example.com']]],
            fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://graph.microsoft.com/v1.0/me/messages/' . rawurlencode('AAMk=draft/1') . '/send',
        ]);
    }

    public function test_a_failed_draft_is_a_plain_failure_and_a_timeout_on_the_send_step_is_ambiguous(): void
    {
        Http::fake(['graph.microsoft.com/v1.0/me/messages' => Http::response(['error' => ['code' => 'ErrorSendQuotaExceeded', 'message' => 'SECRET-DETAIL']], 403)]);
        $quota = $this->failure(fn () => $this->microsoft()->send('t', $this->email()));
        $this->assertSame(Category::ProviderRateLimited, $quota->category);
        $this->assertFalse($quota->isAmbiguous());

        $this->freshHttp();
        Http::fake([
            'graph.microsoft.com/v1.0/me/messages' => Http::response(['id' => 'D1', 'conversationId' => 'c', 'internetMessageId' => '<x@y>'], 201),
            'graph.microsoft.com/v1.0/me/messages/*/send' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
        ]);
        $ambiguous = $this->failure(fn () => $this->microsoft()->send('t', $this->email()));
        $this->assertTrue($ambiguous->isAmbiguous());
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('graphSendFailures')]
    public function test_microsoft_send_failures_map_to_the_provider_neutral_taxonomy(int $status, array $body, Category $expected): void
    {
        Http::fake(['graph.microsoft.com/v1.0/me/messages' => Http::response($body, $status)]);

        $this->assertSame($expected, $this->failure(fn () => $this->microsoft()->send('t', $this->email()))->category);
    }

    /** @return array<string, array{0: int, 1: array<string, mixed>, 2: Category}> */
    public static function graphSendFailures(): array
    {
        return [
            '401' => [401, ['error' => ['code' => 'InvalidAuthenticationToken']], Category::AuthenticationExpired],
            '403' => [403, ['error' => ['code' => 'ErrorAccessDenied']], Category::PermissionDenied],
            '429' => [429, ['error' => ['code' => 'TooManyRequests']], Category::ProviderRateLimited],
            '503' => [503, ['error' => ['code' => 'ServiceNotAvailable']], Category::TemporaryProviderFailure],
            '400 bad recipient' => [400, ['error' => ['code' => 'ErrorInvalidRecipients']], Category::RecipientInvalid],
            '400 other' => [400, ['error' => ['code' => 'ErrorInvalidIdMalformed']], Category::PermanentProviderFailure],
        ];
    }

    public function test_microsoft_refresh_returns_the_rotated_refresh_token_and_revocation_is_detected(): void
    {
        Http::fake(['login.microsoftonline.com/*' => Http::response(['access_token' => 'new-a', 'refresh_token' => 'rotated', 'scope' => 'Mail.Send'])]);
        $grant = $this->microsoft()->exchangeRefreshToken('old');
        $this->assertSame('rotated', $grant->refreshToken);
        $this->assertSame('new-a', $grant->accessToken);

        $this->freshHttp();
        Http::fake(['login.microsoftonline.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->assertTrue($this->failure(fn () => $this->microsoft()->exchangeRefreshToken('old'))->isRevocation());
    }

    public function test_microsoft_has_no_provider_side_revocation_and_makes_no_http_call_for_it(): void
    {
        $this->microsoft()->revokeGrant('anything');

        Http::assertNothingSent();
        $this->assertFalse($this->microsoft()->capabilities()->revocableGrant);
        $this->assertTrue($this->google()->capabilities()->revocableGrant);
    }

    public function test_capabilities_are_explicit_and_exclude_everything_this_slice_defers(): void
    {
        foreach ([$this->google(), $this->microsoft()] as $provider) {
            $capabilities = $provider->capabilities();
            $this->assertTrue($capabilities->plainTextSend);
            $this->assertFalse($capabilities->htmlSend);
            $this->assertFalse($capabilities->attachments);
            $this->assertFalse($capabilities->inboundSync);
        }
    }
}
