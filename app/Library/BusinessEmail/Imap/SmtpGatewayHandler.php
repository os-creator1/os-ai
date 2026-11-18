<?php

namespace App\Library\BusinessEmail\Imap;

use App\Exceptions\BusinessEmail\BusinessEmailImapException;
use App\Library\BusinessEmail\BusinessEmailContactResolver;
use App\Library\BusinessEmail\InboundText;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The server side of the SMTP gateway. NOT an open relay:
 *
 *  - every request must carry a valid HMAC signature (shared secret, current or
 *    previous for rotation), a fresh timestamp and a never-seen nonce;
 *  - it is stateless: the mailbox credential arrives per request, is used for
 *    that one SMTP session and is never stored or logged. Tenants cannot see
 *    each other because nothing is shared except the rate-limit counters,
 *    which are keyed by a hash of the mailbox identity;
 *  - the destination must be a public DNS name on 465/587 whose every address
 *    is public ({@see ImapHostGuard}), re-resolved HERE, connected by IP with
 *    certificate verification against the name; TLS is mandatory;
 *  - one recipient, the From address must be the authenticated mailbox, plain
 *    text only, bounded sizes. Header values are re-sanitised.
 *
 * @return array{0: int, 1: array<string, mixed>} HTTP status and JSON body
 */
final class SmtpGatewayHandler
{
    public function __construct(private readonly ImapHostGuard $guard, private readonly SmtpMailer $mailer)
    {
    }

    /** @param array<string, string|null> $headers lower-case header name => value */
    public function handle(string $action, string $rawBody, array $headers): array
    {
        $cfg = (array) config('business_email.smtp_gateway');

        if (! in_array($action, ['send', 'test'], true)) {
            return [404, ['ok' => false]];
        }

        if (strlen($rawBody) > (int) ($cfg['max_request_bytes'] ?? 131072)) {
            return [413, ['ok' => false]];
        }

        if (! $this->authentic($cfg, $rawBody, $headers)) {
            return [401, ['ok' => false]];
        }

        $data = json_decode($rawBody, true);
        $s = is_array($data) ? ($data['settings'] ?? null) : null;

        if (! is_array($s) || ($data['v'] ?? null) !== 1) {
            return [400, ['ok' => false]];
        }

        try {
            // Same validation as a customer-entered mailbox: host shape, port allow-list, credential bounds.
            $settings = ImapSettings::validated([
                'email' => $s['email'] ?? null, 'username' => $s['username'] ?? null, 'password' => $s['password'] ?? null,
                'imap_host' => 'placeholder.invalid.example.com', 'smtp_host' => $s['smtp_host'] ?? null,
                'smtp_port' => $s['smtp_port'] ?? null,
            ]);
            $ip = $this->guard->resolveSafe($settings->smtpHost);
        } catch (BusinessEmailImapException) {
            return [400, ['ok' => false]];
        }

        $email = $action === 'send' ? $this->message($settings, $data['message'] ?? null) : null;

        if ($action === 'send' && $email === null) {
            return [400, ['ok' => false]];
        }

        $mailboxKey = sha1($settings->email . '|' . $settings->smtpHost);

        if (! $this->withinLimits($action, $mailboxKey, $cfg)) {
            return [429, ['ok' => false]];
        }

        try {
            $action === 'send' ? $this->mailer->send($settings, $ip, $email) : $this->mailer->test($settings, $ip);
        } catch (\Throwable $exception) {
            $code = SmtpGatewayFailure::classify($exception->getMessage());
            // No host, address, credential or server text: just the action and the coarse code.
            Log::info('business_email.smtp_gateway.failed', ['action' => $action, 'code' => $code, 'mailbox' => $mailboxKey]);

            return [422, ['ok' => false, 'code' => $code]];
        }

        return [200, ['ok' => true]];
    }

    /** @param array<string, mixed> $cfg @param array<string, string|null> $headers */
    private function authentic(array $cfg, string $body, array $headers): bool
    {
        $timestamp = (string) ($headers['x-gateway-timestamp'] ?? '');
        $nonce = (string) ($headers['x-gateway-nonce'] ?? '');
        $signature = (string) ($headers['x-gateway-signature'] ?? '');

        if (preg_match('/^\d{9,12}$/', $timestamp) !== 1 || preg_match('/^[A-Za-z0-9]{16,64}$/', $nonce) !== 1 || $signature === '') {
            return false;
        }

        if (abs(time() - (int) $timestamp) > (int) ($cfg['clock_skew_seconds'] ?? 60)) {
            return false;
        }

        $valid = false;

        foreach ([$cfg['secret'] ?? null, $cfg['previous_secret'] ?? null] as $secret) {
            if (is_string($secret) && strlen($secret) >= 32
                && hash_equals(SmtpGatewaySigner::sign($secret, $timestamp, $nonce, $body), $signature)) {
                $valid = true;
            }
        }

        // Replay protection: a nonce is single-use inside the (2x skew) validity window.
        return $valid && Cache::add('smtp_gateway:nonce:' . $nonce, 1, ((int) ($cfg['clock_skew_seconds'] ?? 60)) * 2 + 5);
    }

    /** @param array<string, mixed> $cfg */
    private function withinLimits(string $action, string $mailboxKey, array $cfg): bool
    {
        $limit = (int) ($cfg[$action === 'send' ? 'send_per_mailbox_per_hour' : 'test_per_mailbox_per_hour'] ?? 20);
        $key = "smtp_gateway:{$action}:" . $mailboxKey;

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return false;
        }

        if ($action === 'send') {
            if (RateLimiter::tooManyAttempts('smtp_gateway:global', (int) ($cfg['send_global_per_hour'] ?? 2000))) {
                return false;
            }

            RateLimiter::hit('smtp_gateway:global', 3600);
        }

        RateLimiter::hit($key, 3600);

        return true;
    }

    private function message(ImapSettings $settings, mixed $m): ?Email
    {
        if (! is_array($m)) {
            return null;
        }

        $from = BusinessEmailContactResolver::normalize(is_string($m['from'] ?? null) ? $m['from'] : null);
        $to = BusinessEmailContactResolver::normalize(is_string($m['to'] ?? null) ? $m['to'] : null);
        $text = $m['text'] ?? null;
        $subject = $m['subject'] ?? null;
        $mid = $m['message_id'] ?? null;

        // The sender identity is the authenticated mailbox, never an arbitrary address.
        if ($from === null || $to === null || $from !== $settings->email
            || ! is_string($text) || strlen($text) > 100000 || ! is_string($subject)
            || ! is_string($mid) || preg_match('/^<?[A-Za-z0-9._%+\-]{1,100}@[A-Za-z0-9.\-]{1,200}>?$/', $mid) !== 1) {
            return null;
        }

        $email = (new Email())
            ->from(new Address($from, InboundText::headerLine(is_string($m['from_name'] ?? null) ? $m['from_name'] : '', 191)))
            ->to($to)
            ->subject(InboundText::headerLine($subject, 998))
            ->text($text);

        $email->getHeaders()->addIdHeader('Message-ID', trim($mid, '<>'));

        foreach (['in_reply_to' => ['In-Reply-To', 998], 'references' => ['References', 2000]] as $key => [$name, $max]) {
            $value = is_string($m[$key] ?? null) ? InboundText::headerLine($m[$key], $max) : '';

            if ($value !== '') {
                $email->getHeaders()->addTextHeader($name, $value);
            }
        }

        return $email;
    }
}
