<?php

namespace App\Library\Calendar\ExternalCalendar;

use App\Enums\Calendar\ExternalCalendarProvider;
use App\Models\ExternalCalendarConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Implementation Contract 15 §5.5 (mirroring GoogleOAuthStateSigner) — the
 * OAuth state: signed, expiring, single-use, and carrying the minimum
 * identifiers only.
 *
 * The payload is exactly {"u":<user_id>,"p":"<provider>","n":"<nonce>",
 * "e":<unix ts>}. It carries NO redirect_to/return/URL of any kind. Unlike
 * GBP's Business-scoped state, the user identity travels IN the signed
 * state itself (`u`) — the connection is per-User (§5.5), so there is no
 * separate tenancy chain to re-run before knowing whose connection this is;
 * the callback still re-checks Auth::id() === payload['u'] before consuming
 * the nonce, so a state can never be completed by anyone other than the
 * User who initiated it.
 *
 * Signing uses the application key via hash_hmac; comparison is
 * hash_equals. The nonce lives on external_calendar_connections.
 * oauth_state_nonce, which §5.5 already creates in `pending` at initiation.
 * Consumption is a conditional UPDATE that must affect EXACTLY ONE ROW; a
 * replayed state affects zero and fails closed.
 */
final class ExternalCalendarOAuthStateSigner
{
    private const DEFAULT_TTL_SECONDS = 600;

    public function issue(ExternalCalendarConnection $connection): string
    {
        $nonce = (string) Str::uuid();
        $expiresAt = now()->addSeconds($this->ttlSeconds());

        $connection->forceFill([
            'oauth_state_nonce' => $nonce,
            'oauth_state_expires_at' => $expiresAt,
        ])->save();

        $payload = [
            'u' => (int) $connection->user_id,
            'p' => $connection->provider instanceof ExternalCalendarProvider
                ? $connection->provider->value
                : (string) $connection->provider,
            'n' => $nonce,
            'e' => $expiresAt->getTimestamp(),
        ];

        $encoded = $this->encode($payload);

        return $encoded . '.' . $this->sign($encoded);
    }

    /**
     * Verifies signature and expiry only. Does NOT consume the nonce and
     * does NOT read the database.
     *
     * @return array{u:int, p:string, n:string, e:int}|null
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

        $userId = $decoded['u'] ?? null;
        $provider = $decoded['p'] ?? null;
        $nonce = $decoded['n'] ?? null;
        $expiresAt = $decoded['e'] ?? null;

        if (! is_int($userId) || ! is_string($provider) || ! is_string($nonce) || ! is_int($expiresAt)) {
            return null;
        }

        if (ExternalCalendarProvider::tryFrom($provider) === null) {
            return null;
        }

        if ($nonce === '' || $expiresAt <= now()->getTimestamp()) {
            return null;
        }

        return ['u' => $userId, 'p' => $provider, 'n' => $nonce, 'e' => $expiresAt];
    }

    /**
     * Atomic single-use consumption. Returns true only when THIS call
     * consumed the nonce — a replay, a nonce issued for a different User, a
     * nonce issued for the OTHER provider on the same User, or an expired
     * row all affect zero rows.
     */
    public function consume(int $userId, ExternalCalendarProvider $provider, string $nonce): bool
    {
        $affected = DB::table('external_calendar_connections')
            ->where('user_id', $userId)
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
        $configured = config('calendar_external.oauth.state_ttl_seconds');

        if (! is_int($configured) && ! (is_string($configured) && ctype_digit($configured))) {
            return self::DEFAULT_TTL_SECONDS;
        }

        $seconds = (int) $configured;

        return ($seconds >= 60 && $seconds <= 3600) ? $seconds : self::DEFAULT_TTL_SECONDS;
    }

    /** @param array<string, mixed> $payload */
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
