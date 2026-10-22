<?php

namespace App\Library\Calendar\Zoom;

use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use App\Models\User;
use App\Models\ZoomConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Zoom OAuth connections (authorization-code flow, user-managed app). One connection per (Business, staff user).
 *
 * SECURITY
 *  - No Zoom password and no pasted token is ever accepted: the only credential input is the OAuth code on our fixed callback.
 *  - The `state` is an encrypted, expiring payload bound to the Business, the staff user and a single-use nonce; the callback
 *    also requires the SAME signed-in user, who must still have access to that Business.
 *  - Tokens are stored encrypted (model cast) and are never logged. The access token is refreshed under a lock because Zoom
 *    rotates the refresh token: two concurrent refreshes would otherwise invalidate each other.
 *  - A connection can only ever be read through its own Business id.
 */
class ZoomConnectionManager
{
    private const REFRESH_SKEW_SECONDS = 120;

    public function __construct(
        private readonly ZoomClient $zoom,
        private readonly ZoomEligibility $eligibility,
        private readonly WorkspaceManager $workspaces,
    ) {
    }

    public function isEnabledFor(Business $business): bool
    {
        return $this->zoom->isConfigured() && $this->eligibility->allows($business);
    }

    /** The URL to send the staff member to. Creates or re-arms THEIR connection row only. */
    public function begin(Business $business, int $userId): string
    {
        if (! $this->isEnabledFor($business)) {
            throw new ZoomApiException('permanent', 'not_available');
        }

        $nonce = Str::random(40);
        $connection = ZoomConnection::query()->firstOrNew(['business_id' => $business->id, 'user_id' => $userId]);
        $connection->state ??= ZoomConnection::STATE_PENDING;
        $connection->state_nonce = hash('sha256', $nonce);
        $connection->save();

        $state = Crypt::encryptString(json_encode([
            'b' => (int) $business->id,
            'u' => $userId,
            'n' => $nonce,
            'e' => Carbon::now()->addMinutes((int) config('zoom.state_ttl_minutes', 15))->timestamp,
        ]));

        return $this->zoom->authorizeUrl($state);
    }

    /**
     * Finish the flow on the callback. Returns the connection, or null when the state is invalid, expired, replayed,
     * or belongs to another user or an inaccessible Business. A failure never reveals which.
     */
    public function complete(string $code, string $state, User $actor): ?ZoomConnection
    {
        try {
            $payload = json_decode(Crypt::decryptString($state), true, 4, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($payload) || (int) ($payload['e'] ?? 0) < Carbon::now()->timestamp || (int) ($payload['u'] ?? 0) !== (int) $actor->id) {
            return null;
        }

        $business = Business::query()->find((int) ($payload['b'] ?? 0));
        $connection = $business === null ? null : ZoomConnection::query()
            ->where('business_id', $business->id)->where('user_id', $actor->id)->first();

        if ($connection === null
            || blank($connection->state_nonce)
            || ! hash_equals((string) $connection->state_nonce, hash('sha256', (string) ($payload['n'] ?? '')))
            || ! $this->workspaces->userCanAccessBusiness((int) $actor->id, $business)
            || ! $this->isEnabledFor($business)) {
            return null;
        }

        // Single use: burn the nonce before the network call, so a replayed callback cannot run it twice.
        $claimed = ZoomConnection::query()->whereKey($connection->id)->where('state_nonce', $connection->state_nonce)->update(['state_nonce' => null]);
        if ($claimed !== 1) {
            return null;
        }

        try {
            $grant = $this->zoom->exchangeCode($code);
            $profile = $this->safeProfile($grant['access_token']);
        } catch (ZoomApiException $e) {
            $this->markFailure($connection, $e->errorCode);

            return null;
        } catch (ConnectionException) {
            $this->markFailure($connection, 'connection');

            return null;
        }

        $connection->forceFill([
            'state' => ZoomConnection::STATE_ACTIVE,
            'access_token_encrypted' => $grant['access_token'],
            'refresh_token_encrypted' => $grant['refresh_token'],
            'access_expires_at' => Carbon::now()->addSeconds($grant['expires_in']),
            'zoom_user_id' => $profile['id'],
            'zoom_account_email' => $profile['email'],
            'zoom_display_name' => $profile['name'],
            'failure_count' => 0,
            'last_error_code' => null,
            'last_error_at' => null,
            'connected_at' => now(),
        ])->save();

        return $connection->refresh();
    }

    /**
     * A valid access token, refreshing when it is (nearly) expired. The network call happens under a cache lock keyed by
     * the connection and never inside a database transaction. Throws ZoomApiException('auth', ...) when reconnecting is needed.
     */
    public function accessToken(ZoomConnection $connection): string
    {
        $fresh = fn (ZoomConnection $c): bool => ! blank($c->access_token_encrypted)
            && $c->access_expires_at !== null
            && $c->access_expires_at->greaterThan(Carbon::now()->addSeconds(self::REFRESH_SKEW_SECONDS));

        $connection = $connection->fresh() ?? throw new ZoomApiException('auth', 'connection_missing');

        if (! $connection->isActive()) {
            throw new ZoomApiException('auth', 'not_connected');
        }

        if ($fresh($connection)) {
            return (string) $connection->access_token_encrypted;
        }

        return Cache::lock('zoom-refresh:'.$connection->id, 30)->block(15, function () use ($connection, $fresh): string {
            // Another worker may have refreshed while we waited: re-read before using the (rotated) refresh token.
            $current = ZoomConnection::query()->find($connection->id);

            if ($current === null || ! $current->isActive()) {
                throw new ZoomApiException('auth', 'not_connected');
            }

            if ($fresh($current)) {
                return (string) $current->access_token_encrypted;
            }

            try {
                $grant = $this->zoom->refresh((string) $current->refresh_token_encrypted);
            } catch (ZoomApiException $e) {
                if ($e->kind === 'auth') {
                    $this->markRevoked($current, $e->errorCode);
                }
                throw $e;
            } catch (ConnectionException) {
                throw new ZoomApiException('transient', 'connection');
            }

            $current->forceFill([
                'access_token_encrypted' => $grant['access_token'],
                'refresh_token_encrypted' => $grant['refresh_token'],
                'access_expires_at' => Carbon::now()->addSeconds($grant['expires_in']),
                'failure_count' => 0,
            ])->save();

            return $grant['access_token'];
        });
    }

    /** Remove our copy of the credentials. Future meetings stay in Zoom; appointments keep their stored join links. */
    public function disconnect(ZoomConnection $connection): void
    {
        $connection->forceFill([
            'state' => ZoomConnection::STATE_REVOKED,
            'state_nonce' => null,
            'access_token_encrypted' => null,
            'refresh_token_encrypted' => null,
            'access_expires_at' => null,
        ])->save();
    }

    public function activeFor(int $businessId, int $userId): ?ZoomConnection
    {
        $connection = ZoomConnection::query()->where('business_id', $businessId)->where('user_id', $userId)->first();

        return $connection !== null && $connection->isActive() ? $connection : null;
    }

    /** The connection if (and only if) it belongs to this Business and is active: the isolation check for a chosen shared host. */
    public function activeInBusiness(int $connectionId, int $businessId): ?ZoomConnection
    {
        $connection = ZoomConnection::query()->whereKey($connectionId)->where('business_id', $businessId)->first();

        return $connection !== null && $connection->isActive() ? $connection : null;
    }

    private function markRevoked(ZoomConnection $connection, string $code): void
    {
        $connection->forceFill([
            'state' => ZoomConnection::STATE_REVOKED,
            'access_token_encrypted' => null,
            'refresh_token_encrypted' => null,
            'access_expires_at' => null,
            'last_error_code' => mb_substr($code, 0, 64),
            'last_error_at' => now(),
        ])->save();
    }

    private function markFailure(ZoomConnection $connection, string $code): void
    {
        $connection->forceFill([
            'failure_count' => $connection->failure_count + 1,
            'last_error_code' => mb_substr($code, 0, 64),
            'last_error_at' => now(),
        ])->save();
    }

    /** @return array{id: ?string, email: ?string, name: ?string} */
    private function safeProfile(string $accessToken): array
    {
        try {
            return $this->zoom->me($accessToken);
        } catch (ZoomApiException|ConnectionException) {
            // user:read:user is optional. The connection still works without a display name.
            return ['id' => null, 'email' => null, 'name' => null];
        }
    }
}
