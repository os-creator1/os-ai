<?php

namespace App\Library\BusinessEmail\Imap;

use App\Exceptions\BusinessEmail\BusinessEmailImapException as Imap;
use App\Library\BusinessEmail\BusinessEmailContactResolver;

/**
 * The complete, validated configuration of an "Other email" mailbox. The WHOLE
 * object (including the password) is stored as one encrypted blob in the
 * account's existing encrypted credential column; nothing here is ever logged
 * or serialized to a customer.
 *
 * Security is mandatory: `ssl` (implicit TLS) or `starttls` (required, never
 * opportunistic). Ports are an allow-list and must match their security mode,
 * so a form cannot be used to probe arbitrary services.
 */
final class ImapSettings
{
    public function __construct(
        public readonly string $email,
        public readonly string $username,
        public readonly string $password,
        public readonly string $imapHost,
        public readonly int $imapPort,
        public readonly string $imapSecurity,
        public readonly string $smtpHost,
        public readonly int $smtpPort,
        public readonly string $smtpSecurity,
    ) {
    }

    /** @param array<string, mixed> $input @throws Imap */
    public static function validated(array $input): self
    {
        $email = BusinessEmailContactResolver::normalize(is_string($input['email'] ?? null) ? $input['email'] : null);
        $username = self::text($input['username'] ?? null, 191);
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        $imapHost = self::host($input['imap_host'] ?? null);
        $smtpHost = self::host($input['smtp_host'] ?? null);
        $imapPort = (int) ($input['imap_port'] ?? 0);
        $smtpPort = (int) ($input['smtp_port'] ?? 0);

        if ($email === null) {
            throw new Imap(Imap::INVALID_SETTINGS, 'Enter a valid email address.');
        }

        if ($username === '') {
            throw new Imap(Imap::INVALID_SETTINGS, 'Enter the username for the mailbox (often the full email address).');
        }

        if ($password === '' || strlen($password) > 512 || preg_match('/[\x00-\x1F\x7F]/', $password) === 1) {
            throw new Imap(Imap::INVALID_SETTINGS, 'Enter the mailbox password or app password.');
        }

        if ($imapHost === null || $smtpHost === null) {
            throw new Imap(Imap::INVALID_SETTINGS, 'Enter a valid IMAP and SMTP server name (for example imap.example.com).');
        }

        if (! in_array($imapPort, (array) config('business_email.imap.imap_ports', [993, 143]), true)
            || ! in_array($smtpPort, (array) config('business_email.imap.smtp_ports', [465, 587]), true)) {
            throw new Imap(Imap::INVALID_SETTINGS, 'Use IMAP port 993 (or 143) and SMTP port 465 (or 587).');
        }

        // The security mode follows the port; there is no plaintext option.
        return new self(
            $email,
            $username,
            $password,
            $imapHost,
            $imapPort,
            $imapPort === 993 ? 'ssl' : 'starttls',
            $smtpHost,
            $smtpPort,
            $smtpPort === 465 ? 'ssl' : 'starttls',
        );
    }

    public function toBlob(): string
    {
        return (string) json_encode([
            'v' => 1, 'email' => $this->email, 'username' => $this->username, 'password' => $this->password,
            'imap_host' => $this->imapHost, 'imap_port' => $this->imapPort,
            'smtp_host' => $this->smtpHost, 'smtp_port' => $this->smtpPort,
        ]);
    }

    /** @throws Imap */
    public static function fromBlob(string $blob): self
    {
        $data = json_decode($blob, true);

        if (! is_array($data) || ($data['v'] ?? null) !== 1) {
            throw new Imap(Imap::INVALID_SETTINGS, 'The stored mailbox settings are not readable. Reconnect the mailbox.');
        }

        return self::validated($data);
    }

    /** Host name only: lowercase LDH labels, at least two labels, no IP literal, no credentials or paths. */
    public static function host(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $host = strtolower(trim($value));

        if (strlen($host) > 253
            || preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) !== 1) {
            return null;
        }

        return $host;
    }

    private static function text(mixed $value, int $max): string
    {
        $text = is_string($value) ? trim($value) : '';

        return ($text === '' || strlen($text) > $max || preg_match('/[\x00-\x1F\x7F]/', $text) === 1) ? '' : $text;
    }

    public function __debugInfo(): array
    {
        return ['email' => $this->email, 'imapHost' => $this->imapHost, 'smtpHost' => $this->smtpHost];
    }
}
