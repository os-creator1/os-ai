<?php

namespace App\Library\BusinessEmail\Imap;

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Email;

/**
 * SMTP over Symfony's transport, connecting to the vetted IP while verifying
 * the certificate against the configured NAME. STARTTLS is required (a server
 * that does not offer it is refused, never used in plaintext).
 */
final class SymfonySmtpMailer implements SmtpMailer
{
    public function send(ImapSettings $settings, string $ip, Email $email): void
    {
        $this->transport($settings, $ip)->send($email);
    }

    public function test(ImapSettings $settings, string $ip): void
    {
        $this->transport($settings, $ip)->start();
    }

    private function transport(ImapSettings $settings, string $ip): EsmtpTransport
    {
        $transport = new EsmtpTransport($ip, $settings->smtpPort, $settings->smtpSecurity === 'ssl');
        $transport->setUsername($settings->username);
        $transport->setPassword($settings->password);
        $transport->setRequireTls(true);

        $stream = $transport->getStream();

        if ($stream instanceof SocketStream) {
            $stream->setTimeout((float) config('business_email.imap.io_timeout_seconds', 20));
            $stream->setStreamOptions(['ssl' => self::sslOptions($settings->smtpHost)]);
        }

        return $transport;
    }

    /**
     * The socket is opened to the vetted IP, so PHP would otherwise derive the
     * SNI name from that IP literal and name-based hosts (shared mail servers
     * such as Namecheap Private Email) would present a default certificate
     * that fails verification. SNI and verification both use the configured
     * NAME; every check stays on.
     *
     * @return array<string, mixed>
     */
    public static function sslOptions(string $host): array
    {
        return [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $host,
            'SNI_enabled' => true,
            'SNI_server_name' => $host,
        ];
    }
}
