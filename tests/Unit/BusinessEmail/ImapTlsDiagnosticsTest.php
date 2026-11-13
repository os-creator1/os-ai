<?php

namespace Tests\Unit\BusinessEmail;

use App\Exceptions\BusinessEmail\BusinessEmailImapException as E;
use App\Library\BusinessEmail\Imap\SymfonySmtpMailer;
use App\Library\BusinessEmail\ImapBusinessEmailProvider;
use PHPUnit\Framework\TestCase;

final class ImapTlsDiagnosticsTest extends TestCase
{
    public function test_smtp_tls_uses_the_configured_name_for_sni_and_keeps_every_check_on(): void
    {
        $options = SymfonySmtpMailer::sslOptions('mail.privateemail.com');

        $this->assertSame('mail.privateemail.com', $options['SNI_server_name'], 'SNI must be the name, not the pinned IP');
        $this->assertSame('mail.privateemail.com', $options['peer_name']);
        $this->assertTrue($options['SNI_enabled']);
        $this->assertTrue($options['verify_peer']);
        $this->assertTrue($options['verify_peer_name']);
        $this->assertFalse($options['allow_self_signed']);
    }

    public function test_smtp_failures_are_classified_without_the_ssl_scheme_causing_false_tls_errors(): void
    {
        $classify = ImapBusinessEmailProvider::classifySmtpTestFailure(...);

        $this->assertSame(E::SMTP_TLS_FAILED, $classify('Connection could not be established with host "ssl://203.0.113.9:465": stream_socket_client(): SSL operation failed with code 1. OpenSSL Error messages: certificate verify failed'));
        $this->assertSame(E::SMTP_TLS_FAILED, $classify('Unable to connect with STARTTLS.'));
        $this->assertSame(E::SMTP_CONNECT_FAILED, $classify('Connection could not be established with host "ssl://203.0.113.9:465": stream_socket_client(): Unable to connect (Connection refused)'));
        $this->assertSame(E::SMTP_CONNECT_FAILED, $classify('Connection to "203.0.113.9:465" timed out'));
        $this->assertSame(E::SMTP_AUTH_FAILED, $classify('Expected response code "235" but got code "535", with message "535 Authentication failed"'));
        $this->assertSame(E::PROTOCOL, $classify('something unexpected'));
    }

    public function test_imap_and_smtp_tls_messages_are_distinct_and_leak_nothing(): void
    {
        $imap = (new E(E::TLS_FAILED))->customerMessage();
        $smtp = (new E(E::SMTP_TLS_FAILED))->customerMessage();

        $this->assertStringContainsString('incoming (IMAP)', $imap);
        $this->assertStringContainsString('outgoing (SMTP)', $smtp);
        $this->assertNotSame($imap, $smtp);
        $this->assertStringNotContainsString('203.0.113', $smtp);
        $this->assertFalse((new E(E::SMTP_TLS_FAILED))->isTemporary());
    }
}
