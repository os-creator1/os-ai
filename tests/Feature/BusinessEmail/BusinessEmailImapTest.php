<?php

namespace Tests\Feature\BusinessEmail;

use App\DTO\BusinessEmail\BusinessEmailSendRequest;
use App\Enums\BusinessEmail\BusinessEmailAccountState;
use App\Enums\BusinessEmail\BusinessEmailFailureCategory as Category;
use App\Enums\BusinessEmail\BusinessEmailMessageStatus as Status;
use App\Enums\BusinessEmail\BusinessEmailProviderType;
use App\Exceptions\BusinessEmail\BusinessEmailImapException as ImapError;
use App\Exceptions\BusinessEmail\BusinessEmailProviderException;
use App\Library\BusinessEmail\BusinessEmailInboundSyncer;
use App\Library\BusinessEmail\BusinessEmailSender;
use App\Library\BusinessEmail\Imap\ImapHostGuard;
use App\Library\BusinessEmail\Imap\ImapPresets;
use App\Library\BusinessEmail\Imap\ImapSettings;
use App\Library\BusinessEmail\Imap\ImapTransport;
use App\Library\BusinessEmail\Imap\MimeMessageParser;
use App\Library\BusinessEmail\Imap\SmtpMailer;
use App\Library\BusinessEmail\ImapBusinessEmailProvider;
use App\Models\Business;
use App\Models\BusinessEmailAccount;
use App\Models\BusinessEmailMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\BusinessEmail\Concerns\CreatesBusinessEmailFixtures;
use Tests\Feature\BusinessEmail\Support\FakeImapTransport;
use Tests\Feature\BusinessEmail\Support\FakeSmtpMailer;
use Tests\TestCase;

/**
 * "Other email" (IMAP receive + SMTP send) end to end, with a scripted IMAP
 * server and a capturing SMTP mailer: no sockets, no real mail.
 */
class BusinessEmailImapTest extends TestCase
{
    use CreatesBusinessEmailFixtures;
    use RefreshDatabase;

    private FakeImapTransport $imap;

    private FakeSmtpMailer $smtp;

    /** @var array<int, string> uid => raw message */
    private array $mailbox = [];

    private int $uidValidity = 77;

    private int $uidNext = 1;

    private string $loginStatus = 'OK login';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->bindFakeEmailProviders();

        $this->imap = new FakeImapTransport(fn (string $command) => $this->server($command));
        $this->smtp = new FakeSmtpMailer();
        $this->app->bind(ImapTransport::class, fn () => $this->imap);
        $this->app->instance(SmtpMailer::class, $this->smtp);
        $this->useDns(['93.184.216.34']);
    }

    /** @param list<string> $ips */
    private function useDns(array $ips): void
    {
        $this->app->instance(ImapHostGuard::class, new ImapHostGuard(fn (string $host) => $ips));
        $this->app->forgetInstance(ImapBusinessEmailProvider::class);
    }

    /** @return array{0: string, 1: string} */
    private function server(string $command): array
    {
        if (str_starts_with($command, 'LOGIN')) {
            return ['', $this->loginStatus];
        }

        if (str_starts_with($command, 'SELECT')) {
            return ["* 5 EXISTS\r\n* OK [UIDVALIDITY {$this->uidValidity}] ok\r\n* OK [UIDNEXT {$this->uidNext}] ok\r\n", 'OK selected'];
        }

        if (preg_match('/^UID SEARCH SINCE /', $command) === 1) {
            return ['* SEARCH ' . implode(' ', array_keys($this->mailbox)) . "\r\n", 'OK done'];
        }

        if (preg_match('/^UID SEARCH UID (\d+):\*/', $command, $m) === 1) {
            // IMAP quirk: `N:*` always includes the highest message even when it is below N.
            $uids = array_values(array_filter(array_keys($this->mailbox), fn ($u) => $u >= (int) $m[1]));
            $uids = $uids === [] && $this->mailbox !== [] ? [max(array_keys($this->mailbox))] : $uids;

            return ['* SEARCH ' . implode(' ', $uids) . "\r\n", 'OK done'];
        }

        if (preg_match('/^UID FETCH (\d+) /', $command, $m) === 1) {
            return isset($this->mailbox[(int) $m[1]]) ? [FakeImapTransport::fetch((int) $m[1], $this->mailbox[(int) $m[1]]), 'OK fetched'] : ['', 'NO gone'];
        }

        return ["* BYE\r\n", 'OK'];
    }

    private function raw(string $mid, string $from = 'Pat <pat@example.com>', string $subject = 'Question', string $body = 'Hello there', string $extra = ''): string
    {
        return "From: {$from}\r\nTo: owner@acme-plumbing.com\r\nSubject: {$subject}\r\nMessage-ID: <{$mid}>\r\nDate: Mon, 02 Nov 2026 10:00:00 +0000\r\n{$extra}Content-Type: text/plain; charset=UTF-8\r\n\r\n{$body}\r\n";
    }

    /** @return array<string, mixed> */
    private function form(array $extra = []): array
    {
        return array_merge([
            'email' => 'owner@acme-plumbing.com', 'username' => 'owner@acme-plumbing.com', 'password' => 'secret-pass-123',
            'imap_host' => 'imap.acme-plumbing.com', 'imap_port' => 993, 'smtp_host' => 'smtp.acme-plumbing.com', 'smtp_port' => 465,
        ], $extra);
    }

    private function settings(array $extra = []): ImapSettings
    {
        return ImapSettings::validated($this->form($extra));
    }

    private function imapAccount(Business $business): BusinessEmailAccount
    {
        $account = $this->activeAccount($business, BusinessEmailProviderType::Imap, 'owner@acme-plumbing.com');
        DB::table('business_email_accounts')->where('id', $account->id)->update([
            'refresh_token_encrypted' => Crypt::encryptString($this->settings()->toBlob()),
        ]);

        return $account->fresh();
    }

    private function sync(BusinessEmailAccount $account): array
    {
        return app(BusinessEmailInboundSyncer::class)->sync($account->fresh());
    }

    // ---- settings, host safety ----------------------------------------------

    public function test_settings_require_public_names_known_ports_and_always_encrypted_transport(): void
    {
        $settings = $this->settings();
        $this->assertSame('ssl', $settings->imapSecurity);
        $this->assertSame('ssl', $settings->smtpSecurity);
        $this->assertSame('starttls', $this->settings(['imap_port' => 143, 'smtp_port' => 587])->imapSecurity);
        $this->assertSame('starttls', $this->settings(['smtp_port' => 587])->smtpSecurity);

        foreach ([
            ['imap_host' => '127.0.0.1'], ['imap_host' => 'localhost'], ['imap_host' => 'imap.example.com/../x'],
            ['imap_host' => 'user@evil.com'], ['smtp_host' => 'http://smtp.example.com'], ['imap_port' => 25], ['imap_port' => 22],
            ['smtp_port' => 25], ['password' => ''], ['password' => "pw\r\nA2 DELETE INBOX"], ['email' => 'not-an-email'],
        ] as $bad) {
            try {
                $this->settings($bad);
                $this->fail('Expected rejection of ' . json_encode(array_keys($bad)));
            } catch (ImapError $error) {
                $this->assertSame(ImapError::INVALID_SETTINGS, $error->reason);
            }
        }

        $this->assertSame($settings->password, ImapSettings::fromBlob($settings->toBlob())->password);
    }

    public function test_the_host_guard_blocks_internal_targets_and_dns_that_points_inward(): void
    {
        $guard = fn (array $ips) => new ImapHostGuard(fn () => $ips);

        $this->assertSame('93.184.216.34', $guard(['93.184.216.34'])->resolveSafe('imap.acme-plumbing.com'));

        foreach (['10.0.0.5', '192.168.1.10', '172.16.0.1', '127.0.0.1', '169.254.169.254', '100.64.0.1', '::1', 'fd00::1', 'fe80::1', '0.0.0.0'] as $ip) {
            try {
                $guard([$ip])->resolveSafe('imap.acme-plumbing.com');
                $this->fail("{$ip} must be refused");
            } catch (ImapError $error) {
                $this->assertSame(ImapError::UNSAFE_HOST, $error->reason, $ip);
            }
        }

        // One bad answer among good ones is still refused (rebinding / split-horizon).
        $this->expectException(ImapError::class);
        $guard(['93.184.216.34', '10.0.0.5'])->resolveSafe('imap.acme-plumbing.com');
    }

    public function test_internal_looking_names_are_refused_before_any_lookup(): void
    {
        $guard = new ImapHostGuard(function () {
            $this->fail('No DNS lookup may happen for an internal-looking name.');
        });

        foreach (['intranet.local', 'db.internal', 'localhost', 'printer.lan', '169.254.169.254', 'metadata'] as $host) {
            try {
                $guard->resolveSafe($host);
                $this->fail("{$host} must be refused");
            } catch (ImapError $error) {
                $this->assertSame(ImapError::UNSAFE_HOST, $error->reason, $host);
            }
        }
    }

    // ---- MIME ---------------------------------------------------------------

    public function test_the_mime_parser_prefers_plain_text_decodes_charsets_and_never_imports_attachments(): void
    {
        $raw = "From: =?UTF-8?B?UmVuw6k=?= <rene@example.com>\r\nSubject: =?ISO-8859-1?Q?Caf=E9?=\r\nContent-Type: multipart/mixed; boundary=\"b1\"\r\n\r\n"
            . "--b1\r\nContent-Type: multipart/alternative; boundary=\"b2\"\r\n\r\n"
            . "--b2\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<p>html <script>alert(1)</script></p>\r\n"
            . "--b2\r\nContent-Type: text/plain; charset=ISO-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nCaf=E9 cr=E8me\r\n--b2--\r\n"
            . "--b1\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment; filename=a.pdf\r\nContent-Transfer-Encoding: base64\r\n\r\nJVBERi0=\r\n--b1--\r\n";

        $parsed = (new MimeMessageParser())->parse($raw);

        $this->assertSame('René <rene@example.com>', $parsed['headers']['from']);
        $this->assertSame('Café', $parsed['headers']['subject']);
        $this->assertSame('Café crème', $parsed['text']);
        $this->assertTrue($parsed['has_attachments']);

        $htmlOnly = (new MimeMessageParser())->parse("Content-Type: text/html\r\n\r\n<b onclick=x>Hi</b><style>p{}</style><script>evil()</script>");
        $this->assertSame('Hi', $htmlOnly['text']);

        $garbage = (new MimeMessageParser())->parse("\xFF\xFE not mime at all");
        $this->assertTrue(mb_check_encoding($garbage['text'], 'UTF-8'));
    }

    // ---- receiving ----------------------------------------------------------

    public function test_inbound_sync_reads_a_bounded_first_window_then_only_new_uids_and_stays_idempotent(): void
    {
        [, $business] = $this->emailTenant();
        $account = $this->imapAccount($business);
        $contact = $this->contactWithEmails($business, ['pat@example.com']);
        $this->mailbox = [3 => $this->raw('m3@x'), 4 => $this->raw('m4@x'), 5 => $this->raw('m5@x')];
        $this->uidNext = 6;

        $first = $this->sync($account);
        $this->assertSame(3, $first['stored']);
        $this->assertSame('77:5', $account->fresh()->inbound_cursor);
        $this->assertTrue($this->imap->implicitTls);
        $this->assertSame('imap.acme-plumbing.com', $this->imap->peerName, 'certificate is verified against the name');
        $this->assertSame('93.184.216.34', $this->imap->openedIp, 'connection goes to the vetted address');
        $this->assertContains('LOGIN <redacted>', $this->imap->commands);

        $row = BusinessEmailMessage::query()->where('provider_message_id', '<m3@x>')->firstOrFail();
        $this->assertSame('inbound', $row->direction);
        $this->assertSame($contact->id, (int) $row->contact_id);
        $this->assertSame('pat@example.com', $row->from_email);
        $this->assertSame('Hello there', $row->body_text);

        // Nothing new: IMAP's `6:*` still returns uid 5; it must be ignored.
        $second = $this->sync($account);
        $this->assertSame(0, $second['stored']);
        $this->assertSame(3, BusinessEmailMessage::query()->where('direction', 'inbound')->count());

        // New mail arrives; the cursor advances; a re-delivery of an old message id is not duplicated.
        $this->mailbox[6] = $this->raw('m6@x');
        $this->mailbox[7] = $this->raw('m3@x');
        $third = $this->sync($account);
        $this->assertSame(1, $third['stored']);
        $this->assertSame('77:7', $account->fresh()->inbound_cursor);
        $this->assertSame(4, BusinessEmailMessage::query()->where('direction', 'inbound')->count());
    }

    public function test_a_batch_limit_resumes_without_skipping_and_a_uidvalidity_change_restarts_the_window(): void
    {
        [, $business] = $this->emailTenant();
        $account = $this->imapAccount($business);
        DB::table('business_email_accounts')->where('id', $account->id)->update(['inbound_cursor' => '77:5']);
        $this->uidNext = 9;
        $this->mailbox = [6 => $this->raw('a@x'), 7 => $this->raw('b@x'), 8 => $this->raw('c@x')];
        config(['business_email.inbound.max_messages_per_run' => 2]);

        $one = $this->sync($account);
        $this->assertSame(2, $one['stored']);
        $this->assertTrue($one['has_more']);
        $this->assertSame('77:7', $account->fresh()->inbound_cursor);

        $two = $this->sync($account);
        $this->assertSame(1, $two['stored']);
        $this->assertFalse($two['has_more']);
        $this->assertSame('77:8', $account->fresh()->inbound_cursor);

        // The server renumbered the mailbox: restart from the bounded window; known Message-IDs dedupe.
        $this->uidValidity = 99;
        $this->mailbox = [1 => $this->raw('a@x'), 2 => $this->raw('new@x')];
        $this->uidNext = 3;
        $three = $this->sync($account);
        $this->assertSame(1, $three['stored']);
        $this->assertSame('99:2', $account->fresh()->inbound_cursor);
    }

    public function test_a_rejected_password_revokes_the_connection_and_a_dead_server_is_retried_without_losing_the_cursor(): void
    {
        [, $business] = $this->emailTenant();
        $account = $this->imapAccount($business);
        DB::table('business_email_accounts')->where('id', $account->id)->update(['inbound_cursor' => '77:5']);

        $this->useDns(['10.1.2.3']); // DNS now points inward: refused, not an auth problem, not revoked
        try {
            $this->sync($account);
            $this->fail('expected a provider exception');
        } catch (BusinessEmailProviderException $exception) {
            $this->assertSame(Category::PermanentProviderFailure, $exception->category);
            $this->assertFalse($exception->isRevocation());
        }
        $this->assertTrue($account->fresh()->isActive());
        $this->assertSame('77:5', $account->fresh()->inbound_cursor);
        $this->assertNull($this->imap->openedIp);

        $this->useDns(['93.184.216.34']);
        $this->loginStatus = 'NO [AUTHENTICATIONFAILED] Invalid credentials';

        try {
            $this->sync($account);
            $this->fail('expected a revocation');
        } catch (BusinessEmailProviderException $exception) {
            $this->assertTrue($exception->isRevocation());
        }

        $fresh = $account->fresh();
        $this->assertSame(BusinessEmailAccountState::Revoked, $fresh->state);
        $this->assertNull($fresh->refresh_token_encrypted);
    }

    public function test_an_unparseable_message_is_skipped_by_id_and_does_not_block_the_mailbox(): void
    {
        [, $business] = $this->emailTenant();
        $account = $this->imapAccount($business);
        DB::table('business_email_accounts')->where('id', $account->id)->update(['inbound_cursor' => '77:2']);
        $this->uidNext = 5;
        $this->mailbox = [3 => "Subject: no sender at all\r\n\r\nbody", 4 => $this->raw('ok@x')];

        $result = $this->sync($account);

        $this->assertSame(1, $result['stored']);
        $this->assertSame('77:4', $account->fresh()->inbound_cursor);
    }

    // ---- sending, threading and replying -------------------------------------

    public function test_replies_stay_in_one_thread_in_both_directions_and_go_only_to_the_stored_counterpart(): void
    {
        [, $business] = $this->emailTenant();
        $account = $this->imapAccount($business);
        $contact = $this->contactWithEmails($business, ['pat@example.com']);

        $sent = app(BusinessEmailSender::class)->send(new BusinessEmailSendRequest($business, $contact, 'Your quote', 'Here it is', 'op-1'));
        $this->assertSame(Status::Accepted, $sent->status);
        $this->assertCount(1, $this->smtp->sent);
        $this->assertSame('owner@acme-plumbing.com', $this->smtp->sent[0]->getFrom()[0]->getAddress());
        $this->assertSame('pat@example.com', $this->smtp->sent[0]->getTo()[0]->getAddress());
        $this->assertSame('93.184.216.34', $this->smtp->lastIp);
        $this->assertSame('th:' . sha1($sent->internet_message_id), $sent->provider_thread_id);

        // The customer answers: the same thread, matched to the contact.
        $this->uidNext = 2;
        $this->mailbox = [1 => $this->raw('reply1@x', 'Pat <pat@example.com>', 'Re: Your quote', 'Thanks!', "In-Reply-To: {$sent->internet_message_id}\r\nReferences: {$sent->internet_message_id}\r\n")];
        $this->sync($account);

        $inbound = BusinessEmailMessage::query()->where('direction', 'inbound')->firstOrFail();
        $this->assertSame($sent->provider_thread_id, $inbound->provider_thread_id);
        $this->assertSame($contact->id, (int) $inbound->contact_id);

        // Our reply: RFC headers, same thread, recipient from the stored message (not caller input).
        $reply = app(BusinessEmailSender::class)->send(new BusinessEmailSendRequest($business, $contact, 'Re: Your quote', 'You are welcome', 'op-2', inResponseTo: $inbound));
        $this->assertSame(Status::Accepted, $reply->status);
        $mail = $this->smtp->sent[1];
        $this->assertSame('pat@example.com', $mail->getTo()[0]->getAddress());
        $this->assertSame('<reply1@x>', $mail->getHeaders()->get('In-Reply-To')->getBodyAsString());
        $this->assertStringContainsString($sent->internet_message_id, $mail->getHeaders()->get('References')->getBodyAsString());
        $this->assertSame($sent->provider_thread_id, $reply->provider_thread_id);
    }

    public function test_smtp_failures_are_classified_and_an_ambiguous_one_is_never_resent(): void
    {
        [, $business] = $this->emailTenant();
        $this->imapAccount($business);
        $contact = $this->contactWithEmails($business, ['pat@example.com']);
        $send = fn (string $key) => app(BusinessEmailSender::class)->send(new BusinessEmailSendRequest($business, $contact, 'Hi', 'Body', $key));

        $this->smtp->failure = new \RuntimeException('Expected response code "235" but got code "535", with message "5.7.8 Authentication failed".');
        $this->assertSame(Category::AuthenticationExpired, $send('a')->failure_category);

        $this->smtp->failure = new \RuntimeException('Expected response code "250" but got code "550", with message "No such user".');
        $this->assertSame(Category::RecipientInvalid, $send('b')->failure_category);

        $this->smtp->failure = new \RuntimeException('Connection could not be established with host "ssl://x:465": timed out');
        $this->assertSame(Category::TemporaryProviderFailure, $send('c')->failure_category);

        $this->smtp->failure = new \RuntimeException('the stream closed mid-message');
        $unconfirmed = $send('d');
        $this->assertSame(Status::Unconfirmed, $unconfirmed->status);
        $this->smtp->failure = null;
        $before = count($this->smtp->sent);
        $this->assertSame(Status::Unconfirmed, $send('d')->status);
        $this->assertCount($before, $this->smtp->sent, 'an ambiguous send is never repeated automatically');
    }

    // ---- connecting (HTTP) ----------------------------------------------------

    private function owner(): array
    {
        [$customer, $business, $workspace] = $this->emailTenant();
        $this->authenticateAs($customer);

        return [$customer, $business, $workspace];
    }

    private function connectOtherUrl(object $workspace, object $business): string
    {
        return route('customer.workspaces.businesses.email.connect_other', [$workspace->uid, $business->uid]);
    }

    public function test_connecting_other_email_tests_both_directions_and_stores_only_an_encrypted_credential(): void
    {
        [, $business, $workspace] = $this->owner();

        $this->post($this->connectOtherUrl($workspace, $business), $this->form())->assertRedirect();

        $account = BusinessEmailAccount::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame(BusinessEmailAccountState::Active, $account->state);
        $this->assertSame(BusinessEmailProviderType::Imap, $account->provider);
        $this->assertSame('owner@acme-plumbing.com', $account->mailbox_email);
        $this->assertSame(1, $this->smtp->tests, 'SMTP was tested as well as IMAP');
        $this->assertContains('LOGIN <redacted>', $this->imap->commands);

        $raw = (string) DB::table('business_email_accounts')->where('id', $account->id)->value('refresh_token_encrypted');
        $this->assertStringNotContainsString('secret-pass-123', $raw);
        $this->assertStringNotContainsString('acme-plumbing', $raw);
        $this->assertSame('secret-pass-123', ImapSettings::fromBlob(Crypt::decryptString($raw))->password);

        // The settings page shows the address, the servers and the health line - never the password.
        $html = $this->get(route('customer.workspaces.businesses.email.show', [$workspace->uid, $business->uid]))->assertOk()->getContent();
        $this->assertStringContainsString('owner@acme-plumbing.com', $html);
        $this->assertStringContainsString('imap.acme-plumbing.com:993', $html);
        $this->assertStringContainsString('Receiving is set up', $html);
        $this->assertStringNotContainsString('secret-pass-123', $html);
    }

    public function test_a_failed_connection_test_saves_nothing_and_explains_what_to_check(): void
    {
        [, $business, $workspace] = $this->owner();
        $this->loginStatus = 'NO [AUTHENTICATIONFAILED] bad';

        $response = $this->post($this->connectOtherUrl($workspace, $business), $this->form())->assertRedirect();

        $this->assertNull(BusinessEmailAccount::query()->where('business_id', $business->id)->first());
        $this->assertStringContainsString('app password', (string) session('message'));
        $this->assertNull(session()->getOldInput('password'), 'the password is never flashed back');
        $this->assertSame('owner@acme-plumbing.com', session()->getOldInput('email'));
        unset($response);

        // SMTP rejects while IMAP works: still nothing saved, with a specific message.
        $this->loginStatus = 'OK login';
        $this->smtp->testFailure = new \RuntimeException('Expected response code "235" but got code "535"');
        $this->post($this->connectOtherUrl($workspace, $business), $this->form());
        $this->assertNull(BusinessEmailAccount::query()->where('business_id', $business->id)->first());
        $this->assertStringContainsString('outgoing (SMTP) server rejected', (string) session('message'));
    }

    public function test_unsafe_servers_are_refused_without_any_connection(): void
    {
        [, $business, $workspace] = $this->owner();
        $this->useDns(['169.254.169.254']);

        $this->post($this->connectOtherUrl($workspace, $business), $this->form())->assertRedirect();
        $this->assertNull(BusinessEmailAccount::query()->where('business_id', $business->id)->first());
        $this->assertNull($this->imap->openedIp);
        $this->assertSame(0, $this->smtp->tests);
        $this->assertStringContainsString('not allowed', (string) session('message'));

        foreach (['imap_host' => '10.0.0.1', 'imap_port' => 22] as $field => $value) {
            $this->post($this->connectOtherUrl($workspace, $business), $this->form([$field => $value]))->assertRedirect();
            $this->assertNull($this->imap->openedIp);
        }
    }

    public function test_server_settings_are_detected_when_left_blank_and_google_or_microsoft_addresses_are_pointed_to_oauth(): void
    {
        [, $business, $workspace] = $this->owner();
        $this->app->instance(ImapPresets::class, new ImapPresets(fn (string $domain) => $domain === 'acme-plumbing.com' ? ['mx1.titan.email'] : []));

        $this->post($this->connectOtherUrl($workspace, $business), $this->form(['imap_host' => '', 'smtp_host' => '', 'imap_port' => '', 'smtp_port' => '']))->assertRedirect();

        $this->assertSame('imap.titan.email', $this->imap->peerName);
        $this->assertSame(BusinessEmailAccountState::Active, BusinessEmailAccount::query()->where('business_id', $business->id)->firstOrFail()->state);
        BusinessEmailAccount::query()->delete();

        $blank = ['imap_host' => '', 'smtp_host' => '', 'imap_port' => '', 'smtp_port' => ''];
        $this->post($this->connectOtherUrl($workspace, $business), $this->form(['email' => 'someone@gmail.com', 'username' => 'someone@gmail.com'] + $blank))->assertRedirect();
        $this->assertStringContainsString('Connect Google', (string) session('message'));
        $this->post($this->connectOtherUrl($workspace, $business), $this->form(['email' => 'x@hotmail.com', 'username' => 'x@hotmail.com'] + $blank))->assertRedirect();
        $this->assertStringContainsString('Connect Microsoft', (string) session('message'));
        $this->assertSame(0, BusinessEmailAccount::query()->count());
    }

    public function test_connecting_requires_the_manage_permission_and_cannot_replace_a_different_active_mailbox(): void
    {
        [$owner, $business, $workspace] = $this->emailTenant();
        $this->imapAccount($business);

        $this->authenticateAs($this->assignedStaff($workspace, $business), ['chat_box', 'view_contact']);
        $this->post($this->connectOtherUrl($workspace, $business), $this->form())->assertStatus(401);

        $this->authenticateAs($owner);
        $this->post($this->connectOtherUrl($workspace, $business), $this->form(['email' => 'other@acme-plumbing.com', 'username' => 'other@acme-plumbing.com']))->assertRedirect();
        $this->assertStringContainsString('disconnect it', (string) session('message'));
        $this->assertSame('owner@acme-plumbing.com', BusinessEmailAccount::query()->firstOrFail()->mailbox_email);

        // The SAME mailbox may be re-tested with a new password, and keeps working meanwhile.
        $this->post($this->connectOtherUrl($workspace, $business), $this->form(['password' => 'brand-new-pass']))->assertRedirect();
        $account = BusinessEmailAccount::query()->firstOrFail();
        $this->assertTrue($account->isActive());
        $this->assertSame('brand-new-pass', ImapSettings::fromBlob((string) $account->refresh_token_encrypted)->password);
    }

    public function test_the_settings_page_always_lists_all_three_options_and_explains_a_missing_oauth_configuration(): void
    {
        [, $business, $workspace] = $this->owner();
        config(['business_email.google.client_id' => null, 'business_email.microsoft.client_secret' => null]);

        $html = $this->get(route('customer.workspaces.businesses.email.show', [$workspace->uid, $business->uid]))->assertOk()->getContent();

        $this->assertStringContainsString('email-provider-google', $html);
        $this->assertStringContainsString('email-provider-microsoft', $html);
        $this->assertStringContainsString('Connect Other Email', $html);
        $this->assertSame(2, substr_count($html, 'data-role="email-provider-unavailable"'));
        $this->assertStringContainsString('sign-in is not set up on this platform yet', $html);
        $this->assertStringContainsString('verify the server', $html);

        config(['business_email.google.client_id' => 'g', 'business_email.microsoft.client_secret' => 's']);
        $this->configureEmailOAuth();
        $usable = $this->get(route('customer.workspaces.businesses.email.show', [$workspace->uid, $business->uid]))->getContent();
        $this->assertStringContainsString('Connect Google', $usable);
        $this->assertStringContainsString('Connect Microsoft', $usable);
    }

    public function test_the_scheduler_queues_every_syncable_mailbox_and_skips_ones_that_cannot_be_read(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $this->app->instance(\App\Library\BusinessEmail\MicrosoftBusinessEmailProvider::class, new \App\Library\BusinessEmail\MicrosoftBusinessEmailProvider(app(\App\Library\BusinessEmail\BusinessEmailOAuthConfig::class)));
        [, $imapBusiness] = $this->emailTenant('Imap Co');
        [, $googleBusiness] = $this->emailTenant('Google Co');
        [, $msBusiness] = $this->emailTenant('Ms Co');
        $imap = $this->imapAccount($imapBusiness);
        $google = $this->activeAccount($googleBusiness); // send-only grant: cannot be read yet
        $ms = $this->activeAccount($msBusiness, BusinessEmailProviderType::Microsoft);
        DB::table('business_email_accounts')->where('id', $ms->id)->update(['granted_scopes' => 'Mail.Send Mail.Read User.Read']);

        $this->artisan('business-email:sync-inbound')->assertSuccessful();

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\BusinessEmail\SyncBusinessEmailInboxJob::class, 2);
        \Illuminate\Support\Facades\Queue::assertPushed(fn (\App\Jobs\BusinessEmail\SyncBusinessEmailInboxJob $job) => $job->accountId === $imap->id);
        \Illuminate\Support\Facades\Queue::assertPushed(fn (\App\Jobs\BusinessEmail\SyncBusinessEmailInboxJob $job) => $job->accountId === $ms->id);
        \Illuminate\Support\Facades\Queue::assertNotPushed(fn (\App\Jobs\BusinessEmail\SyncBusinessEmailInboxJob $job) => $job->accountId === $google->id);
    }
}
