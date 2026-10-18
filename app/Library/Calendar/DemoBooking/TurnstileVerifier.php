<?php

namespace App\Library\Calendar\DemoBooking;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Server-side Cloudflare Turnstile verification for /book-demo.
 *
 * The browser widget only produces a token; the token proves nothing until the
 * secret key has been used to verify it with Cloudflare, here. Anything short
 * of an explicit `success: true` is a refusal, and so is incomplete
 * configuration: only the local and testing environments may bypass it, and
 * only when DEMO_BOOKING_TURNSTILE_BYPASS is set.
 */
class TurnstileVerifier
{
    public function siteKey(): ?string
    {
        $key = trim((string) config('demo_booking.turnstile.site_key'));

        return $key === '' ? null : $key;
    }

    public function isConfigured(): bool
    {
        return $this->siteKey() !== null && trim((string) config('demo_booking.turnstile.secret_key')) !== '';
    }

    public function bypassActive(): bool
    {
        return ! $this->isConfigured()
            && (bool) config('demo_booking.turnstile.bypass')
            && app()->environment(['local', 'testing']);
    }

    /** Whether /book-demo may accept submissions at all. */
    public function isAvailable(): bool
    {
        return $this->isConfigured() || $this->bypassActive();
    }

    public function verify(?string $token, ?string $ip): bool
    {
        if ($this->bypassActive()) {
            return true;
        }

        $token = is_string($token) ? trim($token) : '';

        if (! $this->isConfigured() || $token === '' || strlen($token) > 2048) {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post((string) config('demo_booking.turnstile.verify_url'), array_filter([
                'secret' => (string) config('demo_booking.turnstile.secret_key'),
                'response' => $token,
                'remoteip' => $ip,
            ]));

            return $response->ok() && $response->json('success') === true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
