<?php

namespace App\Library\BusinessEmail;

use App\Enums\BusinessEmail\BusinessEmailProviderType;
use App\Models\BusinessEmailAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The OAuth state: signed, expiring, single-use, minimum identifiers only
 * (mirrors GoogleOAuthStateSigner / ExternalCalendarOAuthStateSigner).
 *
 * Payload is exactly {"b":<business_id>,"p":"<provider>","n":"<nonce>",
 * "e":<unix ts>}. It carries NO redirect/return/URL and NO user id: the
 * actor is bound on the pending row (connected_by_user_id) and re-checked
 * by the controller before the nonce is consumed.
 *
 * The state is OPAQUE to the browser (HMAC over the application key), so it
 * cannot be forged, tampered with or re-pointed at another Business. The
 * nonce lives on business_email_accounts.oauth_state_nonce; consume() is a
 * conditional UPDATE that must affect EXACTLY ONE row — a replayed state
 * affects zero and fails closed.
 */
final class BusinessEmailOAuthStateSigner
{
    private const DEFAULT_TTL_SECONDS = 600;

    /** Issues a nonce onto the account row and returns the signed state. */
    public function issue(BusinessEmailAccount $account): string
    {
        $nonce = (string) Str::uuid();
        $expiresAt = now()->addSeconds($this->ttlSeconds());

        $account->forceFill([
            'oauth_state_nonce' => $nonce,
            'oauth_state_expires_at' => $expiresAt,
        ])->save();

        $payload = [
            'b' => (int) $account->business_id,
            'p' => $account->provider->value,
            'n' => $nonce,
            'e' => $expiresAt->getTimestamp(),
        ];

        $encoded = $this->base64UrlEncode((string) json_encode($payload));

        return $encoded . '.' . $this->sign($encoded);
    }

    /**
     * Signature + expiry only; never reads the database or consumes the nonce.
     *
     * @return array{b:int, p:string, n:string, e:int}|null
     */
    public function verify(?string $state): ?array
    {
        if (! is_string($state) || $state === '') {
            return null;
        }

        $parts = explode('.', $state, 2);

        if (count($parts) !== 2) {
            return null;
        }

        [$encoded, $signature] = $parts;

        if (! hash_equals($this->sign($encoded), $signature)) {
            return null;
        }

        $decoded = json_decode($this->base64UrlDecode($encoded), true);

        if (! is_array($decoded)) {
            return null;
        }

        $businessId = $decoded['b'] ?? null;
        $provider = $decoded['p'] ?? null;
        $nonce = $decoded['n'] ?? null;
        $expiresAt = $decoded['e'] ?? null;

        if (! is_int($businessId) || ! is_string($provider) || ! is_string($nonce) || ! is_int($expiresAt)) {
            return null;
        }

        if (BusinessEmailProviderType::tryFrom($provider) === null) {
            return null;
        }

        if ($nonce === '' || $expiresAt <= now()->getTimestamp()) {
            return null;
        }

        return ['b' => $businessId, 'p' => $provider, 'n' => $nonce, 'e' => $expiresAt];
    }

    /**
     * Atomic single-use consumption. True only when THIS call consumed the
     * nonce: a replay, a nonce for another Business, the OTHER provider, or
     * an expired row all affect zero rows.
     */
    public function consume(int $businessId, BusinessEmailProviderType $provider, string $nonce): bool
    {
        $affected = DB::table('business_email_accounts')
            ->where('business_id', $businessId)
            ->where('provider', $provider->value)
            ->where('oauth_state_nonce', $nonce)
            ->where('oauth_state_expires_at', '>', now())
            ->update([
                'oauth_state_nonce' => null,
                'oauth_state_expires_at' => null,
                'updated_at' => now(),
            ]);

        return $affected === 1;
    }

    public function ttlSeconds(): int
    {
        $configured = config('business_email.oauth.state_ttl_seconds');

        if (! is_int($configured) && ! (is_string($configured) && ctype_digit($configured))) {
            return self::DEFAULT_TTL_SECONDS;
        }

        $seconds = (int) $configured;

        return ($seconds >= 60 && $seconds <= 3600) ? $seconds : self::DEFAULT_TTL_SECONDS;
    }

    private function sign(string $encoded): string
    {
        return hash_hmac('sha256', $encoded, $this->key());
    }

    private function key(): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $key;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
