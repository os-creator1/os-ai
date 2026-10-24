<?php

namespace App\Exceptions\BusinessEmail;

use RuntimeException;

/**
 * An IMAP/SMTP problem with a customer-safe, ACTIONABLE explanation. Never
 * carries a password, a server response body or message content.
 */
final class BusinessEmailImapException extends RuntimeException
{
    public const INVALID_SETTINGS = 'invalid_settings';

    public const UNSAFE_HOST = 'unsafe_host';

    public const UNRESOLVABLE = 'unresolvable_host';

    public const CONNECT_FAILED = 'connect_failed';

    public const TLS_FAILED = 'tls_failed';

    public const AUTH_FAILED = 'auth_failed';

    public const PROTOCOL = 'protocol_error';

    public const TIMEOUT = 'timeout';

    public const SMTP_AUTH_FAILED = 'smtp_auth_failed';

    public function __construct(public readonly string $reason, private readonly string $detail = '')
    {
        parent::__construct('business_email_imap:' . $reason);
    }

    public function isCredentialFailure(): bool
    {
        return in_array($this->reason, [self::AUTH_FAILED, self::SMTP_AUTH_FAILED], true);
    }

    public function isTemporary(): bool
    {
        return in_array($this->reason, [self::CONNECT_FAILED, self::TIMEOUT, self::UNRESOLVABLE, self::PROTOCOL], true);
    }

    public function customerMessage(): string
    {
        return match ($this->reason) {
            self::INVALID_SETTINGS => $this->detail !== '' ? $this->detail : 'Some of the connection settings are not valid.',
            self::UNSAFE_HOST => 'That server address is not allowed. Use the public mail server name your email provider gave you.',
            self::UNRESOLVABLE => 'We could not find that mail server. Check the server name for typos.',
            self::CONNECT_FAILED => 'We could not connect to the mail server. Check the server name and port, and that IMAP/SMTP access is enabled for the mailbox.',
            self::TLS_FAILED => 'A secure (TLS) connection could not be verified. The server must have a valid certificate for the name you entered; we never connect without it.',
            self::AUTH_FAILED => 'The mail server rejected the username or password. Many providers require an app password or have IMAP access turned off by default — check your provider\'s mail settings.',
            self::SMTP_AUTH_FAILED => 'The outgoing (SMTP) server rejected the username or password.',
            self::TIMEOUT => 'The mail server took too long to answer. Try again in a moment.',
            default => 'The mail server sent an unexpected answer. Check the server, port and security settings.',
        };
    }
}
