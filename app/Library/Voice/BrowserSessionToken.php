<?php

namespace App\Library\Voice;

use App\Models\Business;
use App\Models\BusinessVoiceBrowserCredential;

/**
 * The opaque identity of ONE browser softphone session, returned when the session is created and sent back with every
 * presence heartbeat. It is bound (HMAC, keyed from the application key) to the exact credential the browser signed into
 * plus the Business and user, so a heartbeat proves WHICH account that browser actually uses; the server never has to
 * guess it from "the user's latest credential". Carries no secret (ids and an expiry only) and cannot be forged or
 * moved to another Business, user or credential. A credential that was rotated or deleted no longer resolves.
 */
final class BrowserSessionToken
{
    private const TTL_SECONDS = 7200;

    public static function issue(BusinessVoiceBrowserCredential $credential): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode([
            'c' => (int) $credential->id, 'b' => (int) $credential->business_id, 'u' => (int) $credential->user_id, 'e' => time() + self::TTL_SECONDS,
        ])), '+/', '-_'), '=');

        return $payload . '.' . self::sign($payload);
    }

    /** The credential this token was issued for, only if it is genuine, unexpired and still belongs to this user and Business. */
    public static function resolve(?string $token, Business $business, int $userId): ?BusinessVoiceBrowserCredential
    {
        if (! is_string($token) || substr_count($token, '.') !== 1 || strlen($token) > 400) {
            return null;
        }

        [$payload, $signature] = explode('.', $token, 2);
        if (! hash_equals(self::sign($payload), $signature)) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);
        if (! is_array($data) || (int) ($data['e'] ?? 0) < time()
            || (int) ($data['b'] ?? 0) !== (int) $business->id || (int) ($data['u'] ?? 0) !== $userId) {
            return null;
        }

        return BusinessVoiceBrowserCredential::query()
            ->whereKey((int) ($data['c'] ?? 0))
            ->where('business_id', $business->id)->where('user_id', $userId)
            ->where('expires_at', '>', now())
            ->first();
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, hash_hmac('sha256', 'mg-browser-session', (string) config('app.key')));
    }
}
