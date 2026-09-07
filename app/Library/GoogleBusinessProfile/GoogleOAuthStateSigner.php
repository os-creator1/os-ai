<?php

namespace App\Library\GoogleBusinessProfile;

use App\Models\BusinessGoogleConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * GBP Slice A contract §9.4 — the OAuth state: signed, expiring,
 * single-use, and carrying the MINIMUM identifiers only.
 *
 * The payload is exactly {"b":<business_id>,"n":"<nonce>","e":<unix ts>}.
 * It carries NO redirect_to, NO return, NO URL of any kind and NO user id
 * (contract §9.4, §17.3 — there is no open redirect anywhere in GBP).
 *
 * Signing uses the application key via hash_hmac, so a tampered payload
 * fails verification. Comparison is hash_equals to avoid a timing oracle.
 *
 * The nonce lives on business_google_connections.oauth_state_nonce, which
 * §10.1 already creates in `pending` at connect initiation — so no fourth
 * table is needed and the §12 three-table cap holds (deviation D-1).
 * Consumption is a conditional UPDATE that must affect EXACTLY ONE ROW; a
 * replayed state affects zero and fails closed (test T-OAUTH-4).
 */
final class GoogleOAuthStateSigner
{
    private const DEFAULT_TTL_SECONDS = 600;

    /**
     * Issues a nonce onto the connection row and returns the signed state
     * string. Overwrites any previous un-consumed nonce for this
     * connection: a user who abandons a connect flow and starts another
     * must not leave a second live state behind.
     */
    public function issue(BusinessGoogleConnection $connection): string
    {
        $nonce = (string) Str::uuid();
        $expiresAt = now()->addSeconds($this->ttlSeconds());

        $connection->forceFill([
            'oauth_state_nonce' => $nonce,
            'oauth_state_expires_at' => $expiresAt,
        ])->save();

        $payload = [
            'b' => (int) $connection->business_id,
            'n' => $nonce,
            'e' => $expiresAt->getTimestamp(),
        ];

        $encoded = $this->encode($payload);

        return $encoded . '.' . $this->sign($encoded);
    }

    /**
     * Verifies signature and expiry and returns the decoded payload, or
     * null. Does NOT consume the nonce and does NOT look at the database —
     * the caller consumes only after every tenancy check has passed.
     *
     * @return array{b:int, n:string, e:int}|null
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
        $nonce = $decoded['n'] ?? null;
        $expiresAt = $decoded['e'] ?? null;

        if (! is_int($businessId) || ! is_string($nonce) || ! is_int($expiresAt)) {
            return null;
        }

        if ($nonce === '' || $expiresAt <= now()->getTimestamp()) {
            return null;
        }

        return ['b' => $businessId, 'n' => $nonce, 'e' => $expiresAt];
    }

    /**
     * Contract §9.4 — ATOMIC single-use consumption. Returns true only
     * when this call is the one that consumed the nonce.
     *
     * The WHERE clause re-checks the nonce, the owning Business and the
     * stored expiry together, so a replay, a nonce issued for a different
     * Business, or an expired row all affect zero rows.
     */
    public function consume(int $businessId, string $nonce): bool
    {
        $affected = DB::table('business_google_connections')
            ->where('business_id', $businessId)
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
        $configured = config('google_business_profile.oauth.state_ttl_seconds');

        if (! is_int($configured) && ! (is_string($configured) && ctype_digit($configured))) {
            // Fail closed: an invalid configuration must not produce a
            // long-lived or never-expiring state.
            return self::DEFAULT_TTL_SECONDS;
        }

        $seconds = (int) $configured;

        return ($seconds >= 60 && $seconds <= 3600) ? $seconds : self::DEFAULT_TTL_SECONDS;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return $this->base64UrlEncode((string) json_encode($payload));
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
