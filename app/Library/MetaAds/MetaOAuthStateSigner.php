<?php

namespace App\Library\MetaAds;

use App\Models\BusinessMetaConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Meta Ads Module V1 contract 24 §3 / M2 — the Meta OAuth state: signed,
 * expiring, single-use, bound to the Business AND the initiating user.
 *
 * Payload: {"b":business_id,"u":actor_user_id,"p":"meta_ads","n":nonce,"e":exp}.
 * No URL of any kind (no open redirect).
 *
 * Own HMAC domain: the key is sha256("meta_ads:" . app.key), so a Google state
 * can never verify here and a Meta state can never verify in
 * GoogleOAuthStateSigner (different key AND different product claim).
 * Comparison is hash_equals.
 *
 * The nonce lives on business_meta_connections.oauth_state_nonce. consume() is
 * one conditional UPDATE that must affect exactly one row; a replay affects
 * zero.
 */
final class MetaOAuthStateSigner
{
    public const PRODUCT = 'meta_ads';

    public function __construct(private readonly MetaAdsConfig $config)
    {
    }

    /**
     * Writes a fresh nonce + expiry onto the connection (replacing any
     * un-consumed one: an abandoned attempt dies) and returns the signed state.
     */
    public function issue(BusinessMetaConnection $connection, int $actorUserId): string
    {
        $nonce = (string) Str::uuid();
        $expiresAt = now()->addSeconds($this->ttlSeconds());

        DB::table('business_meta_connections')
            ->where('id', $connection->id)
            ->update([
                'oauth_state_nonce' => $nonce,
                'oauth_state_expires_at' => $expiresAt,
                'connected_by_user_id' => $actorUserId,
                'updated_at' => now(),
            ]);

        $connection->forceFill([
            'oauth_state_nonce' => $nonce,
            'oauth_state_expires_at' => $expiresAt,
            'connected_by_user_id' => $actorUserId,
        ])->syncOriginal();

        $encoded = $this->base64UrlEncode((string) json_encode([
            'b' => (int) $connection->business_id,
            'u' => $actorUserId,
            'p' => self::PRODUCT,
            'n' => $nonce,
            'e' => $expiresAt->getTimestamp(),
        ]));

        return $encoded . '.' . $this->sign($encoded);
    }

    /**
     * Verifies signature, product and expiry; returns the payload or null.
     * Does NOT consume the nonce and does not touch the database.
     *
     * @return array{b:int, u:int, p:string, n:string, e:int}|null
     */
    public function verify(?string $state): ?array
    {
        if (! is_string($state) || $state === '' || strlen($state) > 1024) {
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
        $actorId = $decoded['u'] ?? null;
        $product = $decoded['p'] ?? null;
        $nonce = $decoded['n'] ?? null;
        $expiresAt = $decoded['e'] ?? null;

        if (! is_int($businessId) || ! is_int($actorId) || ! is_string($nonce) || ! is_int($expiresAt) || $product !== self::PRODUCT) {
            return null;
        }

        if ($nonce === '' || $expiresAt <= now()->getTimestamp()) {
            return null;
        }

        return ['b' => $businessId, 'u' => $actorId, 'p' => $product, 'n' => $nonce, 'e' => $expiresAt];
    }

    /**
     * ATOMIC single-use consumption: true only for the call that consumed the
     * nonce. The WHERE re-checks nonce, Business, the initiating user and the
     * stored expiry, so a replay, a foreign nonce or an expired row affect zero.
     *
     * @param  array{b:int, u:int, n:string}  $payload  a verify() result
     */
    public function consume(array $payload): bool
    {
        $affected = DB::table('business_meta_connections')
            ->where('business_id', (int) $payload['b'])
            ->where('connected_by_user_id', (int) $payload['u'])
            ->where('oauth_state_nonce', (string) $payload['n'])
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
        return $this->config->oauthStateTtlSeconds();
    }

    private function sign(string $encoded): string
    {
        return hash_hmac('sha256', $encoded, hash('sha256', 'meta_ads:' . (string) config('app.key')));
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
