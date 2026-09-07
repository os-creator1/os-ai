<?php

namespace App\Library\GoogleBusinessProfile;

use App\DTO\GoogleBusinessProfile\GoogleLocationCandidate;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;

/**
 * GBP Slice A contract §19.2 — REQUEST-SCOPED PROOF that the
 * account/location pair being bound was actually returned by Google for
 * THIS connection, to THIS actor (correction pass item 5).
 *
 * Why this exists: regex validation only proves a string is well shaped,
 * and a successful locations.get only proves the grant can read some
 * location. Neither proves the pair was enumerated for this Business —
 * so without this, a caller could post any syntactically valid
 * accounts/X + locations/Y and, if the grant happened to reach it, bind
 * a listing that was never offered.
 *
 * The proof is a short-lived HMAC token issued alongside every rendered
 * candidate, NOT a persisted row: contract §8.3 forbids persisting
 * candidates and §12 caps GBP at three tables, so the proof travels in
 * the form and is verified on the way back.
 *
 * Bound fields (all six are covered by the signature, so changing any one
 * invalidates the token):
 *   b — Business id
 *   c — connection id
 *   u — the actor who was shown this candidate
 *   a — account resource name
 *   l — location resource name
 *   e — expiry (unix seconds)
 *
 * Because the account and location are BOTH inside the signature, pair
 * substitution — taking the token for locations/L1 and posting it beside
 * accounts/OTHER — fails: the controller derives both names from the
 * verified token and never reads a parallel raw field.
 */
final class GoogleBusinessProfileCandidateTokenSigner
{
    /**
     * Deliberately short: a candidate list is a single sitting's decision,
     * and a stale token should force a fresh enumeration rather than bind
     * against a listing the grant may no longer reach.
     */
    private const TTL_SECONDS = 900;

    public function issue(Business $business, BusinessGoogleConnection $connection, int $actorUserId, GoogleLocationCandidate $candidate): string
    {
        $payload = [
            'b' => (int) $business->id,
            'c' => (int) $connection->id,
            'u' => $actorUserId,
            'a' => $candidate->accountResourceName,
            'l' => $candidate->resourceName,
            'e' => now()->addSeconds(self::TTL_SECONDS)->getTimestamp(),
        ];

        $encoded = $this->base64UrlEncode((string) json_encode($payload));

        return $encoded . '.' . $this->sign($encoded);
    }

    /**
     * Verifies signature, expiry, and EVERY binding, then returns the two
     * provider resource names. Any mismatch returns null — the caller must
     * refuse before any provider read and before any binding write.
     *
     * @return array{account: string, location: string}|null
     */
    public function verify(?string $token, Business $business, BusinessGoogleConnection $connection, int $actorUserId): ?array
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        $parts = explode('.', $token, 2);

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
        $connectionId = $decoded['c'] ?? null;
        $actorId = $decoded['u'] ?? null;
        $account = $decoded['a'] ?? null;
        $location = $decoded['l'] ?? null;
        $expiresAt = $decoded['e'] ?? null;

        if (! is_int($businessId) || ! is_int($connectionId) || ! is_int($actorId) || ! is_int($expiresAt)) {
            return null;
        }

        if ($expiresAt <= now()->getTimestamp()) {
            return null;
        }

        if ($businessId !== (int) $business->id) {
            return null;
        }

        if ($connectionId !== (int) $connection->id) {
            return null;
        }

        if ($actorId !== $actorUserId) {
            return null;
        }

        $account = GoogleProviderValueNormalizer::accountResourceName($account);
        $location = GoogleProviderValueNormalizer::locationResourceName($location);

        if ($account === null || $location === null) {
            return null;
        }

        return ['account' => $account, 'location' => $location];
    }

    private function sign(string $encoded): string
    {
        return hash_hmac('sha256', 'gbp-candidate:' . $encoded, $this->key());
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
