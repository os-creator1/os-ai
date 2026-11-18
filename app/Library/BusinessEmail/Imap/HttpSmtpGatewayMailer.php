<?php

namespace App\Library\BusinessEmail\Imap;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

/**
 * Sends through the shared SMTP gateway over HTTPS instead of opening port
 * 465/587 from the app host (blocked on DigitalOcean).
 *
 * Request: JSON body signed with HMAC-SHA256 over "timestamp.nonce.body"
 * (headers X-Gateway-Timestamp / -Nonce / -Signature). The mailbox credential
 * is in the body, so a non-https gateway URL is refused outright.
 *
 * The gateway re-resolves and re-vets the SMTP host itself; the app-side IP is
 * not sent.
 */
final class HttpSmtpGatewayMailer implements SmtpMailer
{
    public function send(ImapSettings $settings, string $ip, Email $email): void
    {
        $from = $email->getFrom()[0] ?? null;
        $to = $email->getTo()[0] ?? null;
        $headers = $email->getHeaders();

        $this->post('send', [
            'message' => [
                'from' => $from?->getAddress(),
                'from_name' => $from?->getName(),
                'to' => $to?->getAddress(),
                'subject' => $email->getSubject(),
                'text' => $email->getTextBody(),
                'message_id' => $headers->has('Message-ID') ? $headers->get('Message-ID')->getBodyAsString() : null,
                'in_reply_to' => $headers->has('In-Reply-To') ? $headers->get('In-Reply-To')->getBodyAsString() : null,
                'references' => $headers->has('References') ? $headers->get('References')->getBodyAsString() : null,
            ],
        ], $settings);
    }

    public function test(ImapSettings $settings, string $ip): void
    {
        $this->post('test', [], $settings);
    }

    /** @param array<string, mixed> $payload */
    private function post(string $action, array $payload, ImapSettings $settings): void
    {
        $url = (string) config('business_email.smtp_gateway.url');
        $secret = (string) config('business_email.smtp_gateway.secret');

        if (! str_starts_with(strtolower($url), 'https://') || strlen($secret) < 32) {
            Log::error('business_email.smtp_gateway.misconfigured');

            throw new \RuntimeException(SmtpGatewayFailure::message(SmtpGatewayFailure::CONNECT));
        }

        $body = (string) json_encode($payload + [
            'v' => 1,
            'settings' => [
                'email' => $settings->email,
                'username' => $settings->username,
                'password' => $settings->password,
                'smtp_host' => $settings->smtpHost,
                'smtp_port' => $settings->smtpPort,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $timestamp = (string) time();
        $nonce = Str::random(32);

        try {
            $response = Http::withHeaders([
                'X-Gateway-Timestamp' => $timestamp,
                'X-Gateway-Nonce' => $nonce,
                'X-Gateway-Signature' => SmtpGatewaySigner::sign($secret, $timestamp, $nonce, $body),
            ])
                ->withBody($body, 'application/json')
                ->connectTimeout((int) config('business_email.http.connect_timeout_seconds', 5))
                ->timeout((int) config('business_email.smtp_gateway.client_timeout_seconds', 45))
                ->withoutRedirecting()
                ->post(rtrim($url, '/') . '/' . $action);
        } catch (ConnectionException $exception) {
            // Only DNS resolution and connect failures prove the request was never
            // transmitted. An empty reply (cURL 52), reset, or other transport
            // failure may occur AFTER SMTP accepted the message. In those cases
            // the provider must mark delivery ambiguous and never auto-resend.
            $message = $exception->getMessage();
            $code = match (true) {
                preg_match('/cURL error (?:6|7):/i', $message) === 1 => SmtpGatewayFailure::CONNECT,
                preg_match('/cURL error 28:/i', $message) === 1 => SmtpGatewayFailure::TIMEOUT,
                default => SmtpGatewayFailure::OTHER,
            };

            throw new \RuntimeException(SmtpGatewayFailure::message($code));
        }

        if ($response->successful() && $response->json('ok') === true) {
            return;
        }

        $status = $response->status();
        $code = (string) $response->json('code');

        if ($status === 422 && $code !== '') {
            throw new \RuntimeException(SmtpGatewayFailure::message($code));
        }

        // Rejected before any SMTP work (bad signature, rate limit, bad request): safe to retry later.
        if (in_array($status, [400, 401, 403, 413, 429], true)) {
            Log::error('business_email.smtp_gateway.rejected', ['status' => $status]);

            throw new \RuntimeException(SmtpGatewayFailure::message(SmtpGatewayFailure::CONNECT));
        }

        // Anything else may have happened after the gateway began sending: ambiguous, never auto-resent.
        Log::error('business_email.smtp_gateway.error', ['status' => $status]);

        throw new \RuntimeException(SmtpGatewayFailure::message(SmtpGatewayFailure::OTHER));
    }
}
