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
            $stream->setStreamOptions(['ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => $settings->smtpHost,
                'SNI_enabled' => true,
            ]]);
        }

        return $transport;
    }
}
