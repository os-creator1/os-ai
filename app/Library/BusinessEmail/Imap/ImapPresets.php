<?php

namespace App\Library\BusinessEmail\Imap;

use Closure;

/**
 * Reliable auto-detection of common IMAP/SMTP settings, plus honest guidance
 * for providers that will NOT work with ordinary credentials.
 *
 * Detection never connects anywhere: it uses the address's domain and (for
 * custom domains) the DNS MX host to recognise Zoho, Titan and Hostinger. A
 * plain custom domain with no recognised host gets the cPanel convention
 * (`mail.<domain>`) marked as a GUESS that the connection test confirms.
 */
final class ImapPresets
{
    /** @param Closure(string): list<string>|null $mxResolver domain => MX host names; tests inject one */
    public function __construct(private readonly ?Closure $mxResolver = null)
    {
    }

    /**
     * @return array{settings: array<string, mixed>|null, note: string|null, use_oauth: string|null, confidence: string}
     */
    public function detect(string $email): array
    {
        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));

        if ($domain === '') {
            return $this->result(null, null, null, 'none');
        }

        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            return $this->result(null, 'Gmail and Google Workspace use secure sign-in, not a mailbox password. Use "Connect Google" instead.', 'google', 'none');
        }

        if (preg_match('/^(outlook|hotmail|live|msn)\.[a-z.]+$/', $domain) === 1) {
            return $this->result(null, 'Outlook.com and Hotmail no longer accept ordinary passwords over IMAP/SMTP. Use "Connect Microsoft" instead.', 'microsoft', 'none');
        }

        $known = [
            'zoho.com' => ['imap.zoho.com', 'smtp.zoho.com'], 'zohomail.com' => ['imap.zoho.com', 'smtp.zoho.com'],
            'zoho.eu' => ['imap.zoho.eu', 'smtp.zoho.eu'], 'zoho.in' => ['imap.zoho.in', 'smtp.zoho.in'],
            'yahoo.com' => ['imap.mail.yahoo.com', 'smtp.mail.yahoo.com', 'Yahoo requires an app password (Account security → Generate app password).'],
            'icloud.com' => ['imap.mail.me.com', 'smtp.mail.me.com', 'iCloud requires an app-specific password.'],
            'me.com' => ['imap.mail.me.com', 'smtp.mail.me.com', 'iCloud requires an app-specific password.'],
            'fastmail.com' => ['imap.fastmail.com', 'smtp.fastmail.com', 'Fastmail requires an app password.'],
        ];

        if (isset($known[$domain])) {
            return $this->result($this->settings($known[$domain][0], $known[$domain][1], $domain === 'icloud.com' || $domain === 'me.com'), $known[$domain][2] ?? null, null, 'high');
        }

        foreach ($this->mxHosts($domain) as $mx) {
            if (str_ends_with($mx, '.protection.outlook.com')) {
                return $this->result(null, 'This domain is hosted on Microsoft 365, which blocks ordinary passwords for IMAP/SMTP. Use "Connect Microsoft" instead.', 'microsoft', 'none');
            }

            if (str_ends_with($mx, '.google.com') || str_ends_with($mx, '.googlemail.com')) {
                return $this->result(null, 'This domain is hosted on Google Workspace. Use "Connect Google" instead.', 'google', 'none');
            }

            if (preg_match('/(^|\.)zoho\.(com|eu|in|com\.au|jp)$/', $mx, $m) === 1) {
                $tld = $m[2];

                return $this->result($this->settings('imap.zoho.' . $tld, 'smtp.zoho.' . $tld), 'Zoho Mail: IMAP access must be enabled in Zoho settings, and accounts with two-factor sign-in need an app password.', null, 'high');
            }

            if (str_ends_with($mx, '.titan.email') || $mx === 'titan.email') {
                return $this->result($this->settings('imap.titan.email', 'smtp.titan.email'), 'Titan Email: use the mailbox password.', null, 'high');
            }

            if (str_ends_with($mx, '.hostinger.com') || $mx === 'hostinger.com') {
                return $this->result($this->settings('imap.hostinger.com', 'smtp.hostinger.com'), 'Hostinger: use the mailbox password.', null, 'high');
            }
        }

        return $this->result(
            $this->settings('mail.' . $domain, 'mail.' . $domain),
            'We could not recognise your email host, so these are the usual cPanel/web-hosting settings. Check them with your host; the connection test will tell you if they are wrong.',
            null,
            'guess',
        );
    }

    /** @return array<string, mixed> */
    private function settings(string $imap, string $smtp, bool $smtpStartTls = false): array
    {
        return [
            'imap_host' => $imap, 'imap_port' => 993,
            'smtp_host' => $smtp, 'smtp_port' => $smtpStartTls ? 587 : 465,
        ];
    }

    /** @param array<string, mixed>|null $settings */
    private function result(?array $settings, ?string $note, ?string $useOAuth, string $confidence): array
    {
        return ['settings' => $settings, 'note' => $note, 'use_oauth' => $useOAuth, 'confidence' => $confidence];
    }

    /** @return list<string> */
    private function mxHosts(string $domain): array
    {
        if ($this->mxResolver !== null) {
            return array_map('strtolower', ($this->mxResolver)($domain));
        }

        $hosts = [];

        foreach ((array) @dns_get_record($domain, DNS_MX) as $record) {
            if (isset($record['target'])) {
                $hosts[] = strtolower(rtrim((string) $record['target'], '.'));
            }
        }

        return $hosts;
    }
}
