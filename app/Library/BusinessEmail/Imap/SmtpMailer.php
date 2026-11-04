<?php

namespace App\Library\BusinessEmail\Imap;

use Symfony\Component\Mime\Email;

/** Outgoing SMTP; the seam that lets tests capture mail without a network. */
interface SmtpMailer
{
    /** Sends to the already-vetted address. TLS with certificate verification is mandatory. @throws \Throwable */
    public function send(ImapSettings $settings, string $ip, Email $email): void;

    /** Connects, negotiates TLS and authenticates; sends nothing. @throws \Throwable */
    public function test(ImapSettings $settings, string $ip): void;
}
