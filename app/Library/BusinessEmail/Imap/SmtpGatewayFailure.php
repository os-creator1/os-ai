<?php

namespace App\Library\BusinessEmail\Imap;

/**
 * The only failure vocabulary that crosses the gateway wire. The gateway maps a
 * raw SMTP/transport error to one of these codes (raw server text, which may
 * echo addresses or banners, is never returned); the app turns the code back
 * into a message the existing {@see \App\Library\BusinessEmail\ImapBusinessEmailProvider}
 * classifiers already understand, so direct and gateway mode fail identically.
 */
final class SmtpGatewayFailure
{
    public const AUTH = 'auth';

    public const RECIPIENT = 'recipient';

    public const TLS = 'tls';

    public const CONNECT = 'connect';

    public const TIMEOUT = 'timeout';

    public const OTHER = 'other';

    public static function classify(string $message): string
    {
        $text = str_ireplace('ssl://', '', $message);

        return match (true) {
            preg_match('/code "?535|auth(entication)? (failed|required)|invalid credentials/i', $text) === 1 => self::AUTH,
            preg_match('/code "?55[0-4]/', $text) === 1 => self::RECIPIENT,
            preg_match('/ssl operation failed|certificate|openssl|crypto|starttls|\btls\b/i', $text) === 1 => self::TLS,
            preg_match('/timed out|timeout/i', $text) === 1 => self::TIMEOUT,
            preg_match('/could not be established|refused|getaddrinfo/i', $text) === 1 => self::CONNECT,
            default => self::OTHER,
        };
    }

    /** A synthetic, secret-free message the provider's regex classifiers map correctly. */
    public static function message(string $code): string
    {
        return match ($code) {
            self::AUTH => 'Expected response code "235" but got code "535", with message "535 Authentication failed"',
            self::RECIPIENT => 'Expected response code "250" but got code "550" (recipient rejected)',
            self::TLS => 'Unable to connect with STARTTLS (TLS failure)',
            self::CONNECT => 'Connection could not be established with the SMTP server',
            self::TIMEOUT => 'Connection to the SMTP server timed out',
            default => 'SMTP error',
        };
    }
}
