<?php

namespace Tests\Feature\BusinessEmail;

use App\DTO\BusinessEmail\BusinessEmailOutbound;
use App\Enums\BusinessEmail\BusinessEmailFailureCategory as Category;
use App\Exceptions\BusinessEmail\BusinessEmailImapException as ImapError;
use App\Exceptions\BusinessEmail\BusinessEmailProviderException;
use App\Library\BusinessEmail\Imap\ImapHostGuard;
use App\Library\BusinessEmail\Imap\ImapSettings;
use App\Library\BusinessEmail\Imap\SmtpGatewayHandler;
use App\Library\BusinessEmail\Imap\SmtpGatewaySigner;
use App\Library\BusinessEmail\Imap\SmtpMailer;
use App\Library\BusinessEmail\ImapBusinessEmailProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\BusinessEmail\Support\FakeSmtpMailer;
use Tests\TestCase;

/**
 * App client -> signed HTTPS -> gateway handler -> fake SMTP server.
 * No sockets, no real mail, no database.
 */
class SmtpGatewayTest extends TestCase
{
    private const SECRET = 'gateway-secret-0123456789-0123456789-abcdef';

    private FakeSmtpMailer $smtp;

    /** @var list<string> */
    private array $ips = ['93.184.216.34'];

    private int $gatewayCalls = 0;

    private ?\Closure $override = null;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->smtp = new FakeSmtpMailer();
        config([
            'business_email.smtp_gateway.url' => 'https://smtp-gw.example.com/internal/smtp-gateway/v1',
            'business_email.smtp_gateway.secret' => self::SECRET,
            'business_email.smtp_gateway.previous_secret' => null,
        ]);
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $this->gateway($request));
    }

    private function gateway(Request $request)
    {
        $this->gatewayCalls++;

        if ($this->override !== null) {
            return ($this->override)();
        }

        $handler = new SmtpGatewayHandler(new ImapHostGuard(fn () => $this->ips), $this->smtp);
        $headers = [];
        foreach (['X-Gateway-Timestamp', 'X-Gateway-Nonce', 'X-Gateway-Signature'] as $h) {
            $headers[strtolower($h)] = $request->header($h)[0] ?? null;
        }
        [$status, $body] = $handler->handle(basename($request->url()), $request->body(), $headers);

        return Http::response($body, $status);
    }

    private function provider(): ImapBusinessEmailProvider
    {
        return new ImapBusinessEmailProvider(new ImapHostGuard(fn () => $this->ips));
    }

    private function blob(array $o = []): string
    {
        return ImapSettings::validated($o + [
            'email' => 'sales@acme.test.com', 'username' => 'sales@acme.test.com', 'password' => 'p4ss-SECRET-word',
            'imap_host' => 'imap.privateemail.com', 'smtp_host' => 'mail.privateemail.com', 'smtp_port' => 587,
        ])->toBlob();
    }

    private function outbound(array $o = []): BusinessEmailOutbound
    {
        return new BusinessEmailOutbound(
            fromEmail: $o['from'] ?? 'sales@acme.test.com', fromName: 'Acme Sales', toEmail: 'lead@customer.com',
            subject: 'Re: Quote', bodyText: 'Hello', inReplyTo: '<orig-1@customer.com>', references: '<root-0@customer.com> <orig-1@customer.com>',
            threadId: 'th:abc',
        );
    }

    /** @return array{0:int,1:array} */
    private function raw(string $action, string $body, ?int $ts = null, ?string $nonce = null, ?string $secret = null): array
    {
        $ts = (string) ($ts ?? time());
        $nonce ??= bin2hex(random_bytes(12));
        $handler = new SmtpGatewayHandler(new ImapHostGuard(fn () => $this->ips), $this->smtp);

        return $handler->handle($action, $body, [
            'x-gateway-timestamp' => $ts, 'x-gateway-nonce' => $nonce,
            'x-gateway-signature' => SmtpGatewaySigner::sign($secret ?? self::SECRET, $ts, $nonce, $body),
        ]);
    }

    private function body(array $settings = [], ?array $message = null): string
    {
        return json_encode([
            'v' => 1,
            'settings' => $settings + ['email' => 'sales@acme.test.com', 'username' => 'sales@acme.test.com', 'password' => 'pw', 'smtp_host' => 'mail.privateemail.com', 'smtp_port' => 587],
            'message' => $message ?? ['from' => 'sales@acme.test.com', 'from_name' => 'A', 'to' => 'x@y.com', 'subject' => 's', 'text' => 't', 'message_id' => '<m1@acme.test.com>'],
        ]);
    }

    public function test_send_keeps_sender_identity_threading_and_returns_thread_root(): void
    {
        $result = $this->provider()->send($this->blob(), $this->outbound());

        $this->assertCount(1, $this->smtp->sent);
        $mail = $this->smtp->sent[0];
        $this->assertSame('sales@acme.test.com', $mail->getFrom()[0]->getAddress());
        $this->assertSame('Acme Sales', $mail->getFrom()[0]->getName());
        $this->assertSame('lead@customer.com', $mail->getTo()[0]->getAddress());
        $this->assertSame('Re: Quote', $mail->getSubject());
        $this->assertSame('<orig-1@customer.com>', $mail->getHeaders()->get('In-Reply-To')->getBodyAsString());
        $this->assertStringContainsString('<root-0@customer.com>', $mail->getHeaders()->get('References')->getBodyAsString());
        $this->assertSame('<' . trim($mail->getHeaders()->get('Message-ID')->getBodyAsString(), '<>') . '>', $result->internetMessageId);
        $this->assertSame('th:abc', $result->providerThreadId);
        $this->assertSame('93.184.216.34', $this->smtp->lastIp);
    }

    public function test_the_request_is_signed_json_over_https_and_responses_never_echo_the_password(): void
    {
        $this->provider()->send($this->blob(), $this->outbound());

        Http::assertSent(function (Request $r) {
            return str_starts_with($r->url(), 'https://')
                && $r->hasHeader('X-Gateway-Signature')
                && hash_equals(SmtpGatewaySigner::sign(self::SECRET, $r->header('X-Gateway-Timestamp')[0], $r->header('X-Gateway-Nonce')[0], $r->body()), $r->header('X-Gateway-Signature')[0]);
        });

        $this->smtp->failure = new \RuntimeException('535 Authentication failed for p4ss-SECRET-word at 203.0.113.5');
        [$status, $json] = $this->raw('send', $this->body(['password' => 'p4ss-SECRET-word']));
        $this->assertSame(422, $status);
        $this->assertStringNotContainsString('p4ss-SECRET-word', json_encode($json));
        $this->assertStringNotContainsString('203.0.113', json_encode($json));
    }

    public function test_non_https_or_weak_secret_configuration_is_refused_before_any_request(): void
    {
        config(['business_email.smtp_gateway.url' => 'http://smtp-gw.example.com/v1']);

        try {
            $this->provider()->send($this->blob(), $this->outbound());
            $this->fail('expected failure');
        } catch (BusinessEmailProviderException $e) {
            $this->assertSame(Category::TemporaryProviderFailure, $e->category);
        }

        $this->assertSame(0, $this->gatewayCalls);

        config(['business_email.smtp_gateway.url' => 'https://smtp-gw.example.com/v1', 'business_email.smtp_gateway.secret' => 'short']);
        $this->expectException(BusinessEmailProviderException::class);
        $this->provider()->send($this->blob(), $this->outbound());
    }

    public function test_bad_signature_stale_timestamp_and_replayed_nonce_are_rejected_without_smtp_work(): void
    {
        $body = $this->body();

        $this->assertSame(401, $this->raw('send', $body, null, null, 'x' . self::SECRET)[0]);
        $this->assertSame(401, $this->raw('send', $body, time() - 600)[0]);
        $this->assertSame(401, $this->raw('send', $body, time() + 600)[0]);
        $this->assertSame(0, count($this->smtp->sent));

        $this->assertSame(200, $this->raw('send', $body, null, 'noncenoncenonce01')[0]);
        $this->assertSame(401, $this->raw('send', $body, null, 'noncenoncenonce01')[0], 'replay');
        $this->assertCount(1, $this->smtp->sent);

        [$status] = (new SmtpGatewayHandler(new ImapHostGuard(fn () => $this->ips), $this->smtp))->handle('send', $body, []);
        $this->assertSame(401, $status, 'unsigned');
    }

    public function test_previous_secret_is_accepted_for_rotation(): void
    {
        config(['business_email.smtp_gateway.secret' => 'new-secret-0123456789-0123456789-abcdefgh', 'business_email.smtp_gateway.previous_secret' => self::SECRET]);

        $this->assertSame(200, $this->raw('send', $this->body())[0]);
    }

    public function test_it_is_not_an_open_relay_or_internal_network_proxy(): void
    {
        foreach (['10.0.0.5', '127.0.0.1', '169.254.169.254', '100.64.0.1', '192.168.1.1', '::1'] as $ip) {
            $this->ips = [$ip];
            $this->assertSame(400, $this->raw('send', $this->body())[0], $ip);
        }

        $this->ips = ['93.184.216.34', '10.0.0.9'];
        $this->assertSame(400, $this->raw('send', $this->body())[0], 'any private answer refuses');

        $this->ips = ['93.184.216.34'];
        $this->assertSame(400, $this->raw('send', $this->body(['smtp_host' => '169.254.169.254']))[0], 'IP literal');
        $this->assertSame(400, $this->raw('send', $this->body(['smtp_host' => 'localhost']))[0]);
        $this->assertSame(400, $this->raw('send', $this->body(['smtp_host' => 'db.internal']))[0]);

        foreach ([25, 22, 3306, 2525] as $port) {
            $this->assertSame(400, $this->raw('send', $this->body(['smtp_port' => $port]))[0], "port $port");
        }

        $this->assertSame([], $this->smtp->sent);
        $this->assertSame(0, $this->smtp->tests);
    }

    public function test_sender_must_be_the_authenticated_mailbox_with_exactly_one_recipient(): void
    {
        $m = ['from' => 'ceo@other.com', 'to' => 'x@y.com', 'subject' => 's', 'text' => 't', 'message_id' => '<m@acme.test.com>'];
        $this->assertSame(400, $this->raw('send', $this->body([], $m))[0], 'spoofed From');

        $m['from'] = 'sales@acme.test.com';
        $m['to'] = 'a@b.com, c@d.com';
        $this->assertSame(400, $this->raw('send', $this->body([], $m))[0], 'multiple recipients');

        $m['to'] = "a@b.com\r\nBcc: z@z.com";
        $this->assertSame(400, $this->raw('send', $this->body([], $m))[0], 'header injection');

        $m['to'] = 'a@b.com';
        $m['subject'] = "Hi\r\nBcc: z@z.com";
        $this->assertSame(200, $this->raw('send', $this->body([], $m))[0]);
        $this->assertStringNotContainsString("\n", $this->smtp->sent[0]->getSubject());
        $this->assertCount(0, $this->smtp->sent[0]->getBcc());
    }

    public function test_per_mailbox_rate_limit_isolates_tenants(): void
    {
        RateLimiter::clear('x');
        config(['business_email.smtp_gateway.send_per_mailbox_per_hour' => 2]);

        $this->assertSame(200, $this->raw('send', $this->body())[0]);
        $this->assertSame(200, $this->raw('send', $this->body())[0]);
        $this->assertSame(429, $this->raw('send', $this->body())[0]);

        $other = ['email' => 'bob@other.test.com', 'username' => 'bob@other.test.com'];
        $m = ['from' => 'bob@other.test.com', 'to' => 'x@y.com', 'subject' => 's', 'text' => 't', 'message_id' => '<m@other.test.com>'];
        $this->assertSame(200, $this->raw('send', $this->body($other, $m))[0], 'another tenant is unaffected');
    }

    public function test_global_rate_limit_applies(): void
    {
        config(['business_email.smtp_gateway.send_global_per_hour' => 1]);

        $this->assertSame(200, $this->raw('send', $this->body())[0]);
        $this->assertSame(429, $this->raw('send', $this->body())[0]);
    }

    public function test_oversized_and_malformed_bodies_are_refused(): void
    {
        $this->assertSame(413, $this->raw('send', str_repeat('a', 200000))[0]);
        $this->assertSame(400, $this->raw('send', 'not json')[0]);
        $this->assertSame(404, $this->raw('delete', $this->body())[0]);
    }

    public function test_delivery_failures_map_to_the_same_categories_as_direct_sending(): void
    {
        $cases = [
            ['Expected response code "235" but got code "535"', Category::AuthenticationExpired, false],
            ['Expected response code "250" but got code "550"', Category::RecipientInvalid, false],
            ['Connection could not be established with host "ssl://1.2.3.4:465": Connection refused', Category::TemporaryProviderFailure, false],
            ['Connection to "1.2.3.4:587" timed out', Category::TemporaryProviderFailure, true],
            ['Unable to connect with STARTTLS.', Category::PermanentProviderFailure, false],
            ['weird', Category::TemporaryProviderFailure, true],
        ];

        foreach ($cases as [$message, $category, $ambiguous]) {
            $this->smtp->failure = new \RuntimeException($message);

            try {
                $this->provider()->send($this->blob(), $this->outbound());
                $this->fail('expected failure');
            } catch (BusinessEmailProviderException $e) {
                $this->assertSame($category, $e->category, $message);
                $this->assertSame($ambiguous, $e->isAmbiguous(), $message);
            }
        }
    }

    public function test_gateway_unreachable_is_safe_to_retry_but_a_read_timeout_is_ambiguous(): void
    {
        $this->override = fn () => throw new ConnectionException('cURL error 7: Failed to connect');
        try {
            $this->provider()->send($this->blob(), $this->outbound());
            $this->fail();
        } catch (BusinessEmailProviderException $e) {
            $this->assertSame('smtp_connect_failed', $e->providerCode);
            $this->assertFalse($e->isAmbiguous());
        }

        $this->override = fn () => throw new ConnectionException('cURL error 28: Operation timed out after 45000 ms');
        try {
            $this->provider()->send($this->blob(), $this->outbound());
            $this->fail();
        } catch (BusinessEmailProviderException $e) {
            $this->assertSame('smtp_timeout', $e->providerCode);
            $this->assertTrue($e->isAmbiguous());
        }

        $this->override = fn () => Http::response('boom', 502);
        try {
            $this->provider()->send($this->blob(), $this->outbound());
            $this->fail();
        } catch (BusinessEmailProviderException $e) {
            $this->assertTrue($e->isAmbiguous(), 'gateway 5xx may follow delivery: never auto-resend');
        }
    }

    public function test_connection_test_goes_through_the_gateway_and_sends_nothing(): void
    {
        $this->smtp->testFailure = new \RuntimeException('code "535" Authentication failed');

        $mailer = new \App\Library\BusinessEmail\Imap\HttpSmtpGatewayMailer();
        $settings = ImapSettings::fromBlob($this->blob());

        try {
            $mailer->test($settings, '93.184.216.34');
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertSame(ImapError::SMTP_AUTH_FAILED, ImapBusinessEmailProvider::classifySmtpTestFailure($e->getMessage()));
        }

        $this->smtp->testFailure = null;
        $mailer->test($settings, '93.184.216.34');
        $this->assertSame(2, $this->smtp->tests);
        $this->assertSame([], $this->smtp->sent);
    }

    public function test_direct_mode_is_unchanged_when_no_gateway_url_is_configured(): void
    {
        config(['business_email.smtp_gateway.url' => null]);
        $m = new \ReflectionMethod(ImapBusinessEmailProvider::class, 'mailer');
        $m->setAccessible(true);

        $this->assertInstanceOf(\App\Library\BusinessEmail\Imap\SymfonySmtpMailer::class, $m->invoke($this->provider()));

        config(['business_email.smtp_gateway.url' => 'https://gw.example.com/v1']);
        $this->assertInstanceOf(\App\Library\BusinessEmail\Imap\HttpSmtpGatewayMailer::class, $m->invoke($this->provider()));

        config(['business_email.smtp_gateway.serve' => true]);
        $this->assertInstanceOf(\App\Library\BusinessEmail\Imap\SymfonySmtpMailer::class, $m->invoke($this->provider()), 'the gateway never forwards to a gateway');
    }
}
