<?php

namespace App\Library\Calendar\ExternalCalendar;

use App\DTO\Calendar\ExternalCalendarTokenGrant;
use App\Enums\Calendar\ExternalCalendarConnectionState;
use App\Enums\Calendar\ExternalCalendarProvider;
use App\Exceptions\Calendar\ExternalCalendarConcurrencyException;
use App\Exceptions\Calendar\ExternalCalendarProviderException;
use App\Library\Calendar\ExternalCalendar\Contracts\CalendarProviderClient;
use App\Library\Calendar\StaffBookingLockManager;
use App\Models\ExternalCalendarConnection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Implementation Contract 15 §5.5 — the connection state machine, and the
 * ONLY writer of refresh_token_encrypted. Mirrors
 * GoogleBusinessProfileConnectionManager's proven shape:
 *
 *  - OPTIMISTIC LOCKING IS REAL. Every state transition is a single
 *    conditional UPDATE carrying `WHERE lock_version = ?`. Zero affected
 *    rows means another writer won the race and aborts with
 *    ExternalCalendarConcurrencyException — no blind retry.
 *  - THE TOKEN INVARIANT IS MECHANICAL. Entering pending, revoked or
 *    disconnected NULLs refresh_token_encrypted in the same conditional
 *    update that sets the state. Completing a connection REQUIRES a
 *    newly-returned refresh token; a callback that yields none fails closed
 *    and stays pending.
 *  - NO PROVIDER CALL EVER HAPPENS INSIDE A TRANSACTION.
 *
 * ONE CONNECTION PER USER, TOTAL — never one per provider (§5.5). There is
 * therefore no "reconnect a healthy connection" path, mirroring GBP exactly:
 * beginConnect() refuses while a live connection (pending or active)
 * exists, so switching providers is an explicit disconnect() followed by a
 * fresh beginConnect() — never an implicit in-place swap.
 */
final class ExternalCalendarConnectionManager
{
    public function __construct(
        private readonly ExternalCalendarOAuthConfig $oauthConfig,
        private readonly ExternalCalendarOAuthStateSigner $stateSigner,
        private readonly StaffBookingLockManager $locks,
    ) {
    }

    public function findForUser(int $userId): ?ExternalCalendarConnection
    {
        // The generated active_user_id column IS the "current live
        // connection" answer — equal to user_id only while pending/active.
        return ExternalCalendarConnection::query()
            ->where('active_user_id', $userId)
            ->first();
    }

    public function clientFor(ExternalCalendarProvider $provider): CalendarProviderClient
    {
        return match ($provider) {
            ExternalCalendarProvider::Google => app(GoogleCalendarProviderClient::class),
            ExternalCalendarProvider::Outlook => app(MicrosoftCalendarProviderClient::class),
        };
    }

    /**
     * §5.5 pending-lifecycle rules 1, 2 and 4 — initiate a connect, refusing
     * while a live row already exists, and releasing a genuinely expired
     * pending row before inserting a new one.
     *
     * @throws \App\Exceptions\Calendar\ExternalCalendarConfigurationException
     */
    public function beginConnect(int $userId, ExternalCalendarProvider $provider): string
    {
        // Throws before ANY database state change (mirrors GBP item 7).
        $this->oauthConfig->assertUsable($provider);

        $existing = $this->findForUser($userId);

        if ($existing !== null && $existing->state === ExternalCalendarConnectionState::Active) {
            throw new LogicException('A calendar connection is already active for this account; disconnect before connecting a different one.');
        }

        if ($existing !== null && $existing->state === ExternalCalendarConnectionState::Pending) {
            $expired = $existing->oauth_state_expires_at !== null && $existing->oauth_state_expires_at->isPast();

            if (! $expired) {
                throw new LogicException('A calendar connection attempt is already in progress for this account.');
            }

            // Rule 4 — release the stranded slot in one transaction, then
            // fall through to inserting the new pending row.
            DB::transaction(function () use ($existing): void {
                $this->transition($existing, ExternalCalendarConnectionState::Disconnected, [
                    'disconnected_at' => now(),
                    'oauth_state_nonce' => null,
                    'oauth_state_expires_at' => null,
                ]);
            });
        }

        $connection = $this->createPendingConnection($userId, $provider);

        // Overwrites any previous un-consumed nonce for this row — a fresh
        // row always has none, but issue() is unconditional either way.
        $signedState = $this->stateSigner->issue($connection);

        return $this->clientFor($provider)->authorizationUrl($signedState, true);
    }

    /**
     * §5.5's unique `active_user_id` index is the hard backstop for "at most
     * one pending-or-active row per User" — two concurrent first-connect
     * requests can both observe no live row and both attempt to insert one.
     * Mirrors GoogleBusinessProfileConnectionManager::createFirstConnection():
     * the loser re-reads the winner's row rather than surfacing a raw
     * constraint violation as a 500, and the caller re-applies its own
     * "already pending/active" refusal against it.
     *
     * @throws LogicException when the winner's row is itself pending/active
     */
    private function createPendingConnection(int $userId, ExternalCalendarProvider $provider): ExternalCalendarConnection
    {
        try {
            return ExternalCalendarConnection::create([
                'user_id' => $userId,
                'provider' => $provider,
                'state' => ExternalCalendarConnectionState::Pending,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            $winner = $this->findForUser($userId);

            if ($winner === null) {
                throw $exception;
            }

            throw new LogicException('A calendar connection attempt is already in progress for this account.');
        }
    }

    /** Item 2 of the GBP precedent — is this actor the one who initiated THIS attempt? */
    public function attemptBelongsToActor(ExternalCalendarConnection $connection, int $actorUserId): bool
    {
        return (int) $connection->user_id === $actorUserId;
    }

    /**
     * The caller has already validated state, signature, expiry, actor
     * identity, and has already consumed the nonce atomically.
     *
     * A missing refresh token FAILS CLOSED: the connection stays pending
     * rather than going active on a grant that is not usable.
     *
     * @throws ExternalCalendarProviderException|ExternalCalendarConcurrencyException
     */
    public function completeConnect(ExternalCalendarConnection $connection, string $code, int $actorUserId): void
    {
        $provider = $connection->provider instanceof ExternalCalendarProvider
            ? $connection->provider
            : ExternalCalendarProvider::from((string) $connection->provider);

        // Outside any transaction — a real outbound HTTP request.
        $grant = $this->clientFor($provider)->exchangeAuthorizationCode($code);

        if ($grant->refreshToken === null || $grant->refreshToken === '') {
            throw ExternalCalendarProviderException::unexpectedResponse();
        }

        $this->transition($connection, ExternalCalendarConnectionState::Active, [
            'refresh_token_encrypted' => $grant->refreshToken,
            'granted_scopes' => $grant->grantedScopes,
            'external_account_email' => $grant->externalAccountEmail,
            'connected_at' => now(),
            'last_refreshed_at' => now(),
            'sync_cursor' => null,
            'last_synced_at' => null,
            'last_sync_failure_at' => null,
            'sync_failure_count' => 0,
            'failure_classification' => null,
            'revoked_at' => null,
            'disconnected_at' => null,
        ]);
    }

    /**
     * Contract §5.5 — derive a REQUEST-LIFETIME access token from the
     * encrypted refresh token. Nothing is persisted beyond the bookkeeping
     * columns (mirrors GoogleBusinessProfileConnectionManager::accessTokenFor()).
     *
     * @throws ExternalCalendarProviderException
     */
    public function accessTokenFor(ExternalCalendarConnection $connection): string
    {
        if ($connection->state !== ExternalCalendarConnectionState::Active || empty($connection->refresh_token_encrypted)) {
            throw ExternalCalendarProviderException::invalidGrant();
        }

        $provider = $connection->provider instanceof ExternalCalendarProvider
            ? $connection->provider
            : ExternalCalendarProvider::from((string) $connection->provider);

        try {
            $grant = $this->clientFor($provider)->exchangeRefreshToken((string) $connection->refresh_token_encrypted);
        } catch (ExternalCalendarProviderException $exception) {
            if ($exception->isRevocation()) {
                $this->revoke($connection);
            } else {
                $this->markFailure($connection, $exception);
            }

            throw $exception;
        }

        $update = ['last_refreshed_at' => now(), 'failure_classification' => null, 'updated_at' => now()];

        // Graph rotates refresh tokens; Google usually does not. Only
        // re-encrypt when a new one was actually returned.
        if ($grant->refreshToken !== null && $grant->refreshToken !== '') {
            $update['refresh_token_encrypted'] = Crypt::encryptString($grant->refreshToken);
        }

        DB::table('external_calendar_connections')->where('id', $connection->id)->update($update);
        $connection->refresh();

        return $grant->accessToken;
    }

    public function markFailure(ExternalCalendarConnection $connection, ExternalCalendarProviderException $exception): void
    {
        DB::table('external_calendar_connections')
            ->where('id', $connection->id)
            ->update([
                'last_sync_failure_at' => now(),
                'sync_failure_count' => DB::raw('sync_failure_count + 1'),
                'failure_classification' => $exception->classification,
                'updated_at' => now(),
            ]);

        $connection->refresh();
    }

    /**
     * §5.5 provider-invalidated authorization. Active/pending -> revoked,
     * clearing credentials and purging the busy-block cache — §5.6's rule
     * that a disconnected provider's cached intervals must never keep
     * blocking bookings applies to revocation exactly as it does to an
     * explicit disconnect.
     */
    public function revoke(ExternalCalendarConnection $connection): void
    {
        if ($connection->state === ExternalCalendarConnectionState::Revoked) {
            return;
        }

        $this->endConnection($connection, ExternalCalendarConnectionState::Revoked);
    }

    /**
     * §5.5/§7.5 — disconnect DESTROYS authorization material and purges the
     * busy-block cache, all inside one transaction holding this User's
     * staff_booking_locks row so a booking can never read a half-cleared
     * cache.
     */
    public function disconnect(ExternalCalendarConnection $connection, ?int $actorUserId): void
    {
        if (! in_array($connection->state, [ExternalCalendarConnectionState::Pending, ExternalCalendarConnectionState::Active], true)) {
            return;
        }

        $this->endConnection($connection, ExternalCalendarConnectionState::Disconnected);
    }

    private function endConnection(ExternalCalendarConnection $connection, ExternalCalendarConnectionState $target): void
    {
        $userId = (int) $connection->user_id;

        // §7.2 step 1 — ensure OUTSIDE the transaction, before any lock.
        $this->locks->ensure([$userId]);

        DB::transaction(function () use ($connection, $target, $userId): void {
            $this->locks->lockAscending([$userId]);

            // §5.6 — a disconnected/revoked provider's cached busy
            // intervals must never keep blocking bookings.
            DB::table('external_calendar_busy_blocks')
                ->where('external_calendar_connection_id', $connection->id)
                ->delete();

            $attributes = [
                'refresh_token_encrypted' => null,
                'granted_scopes' => null,
                'sync_cursor' => null,
                'oauth_state_nonce' => null,
                'oauth_state_expires_at' => null,
                'failure_classification' => null,
            ];

            $attributes[$target === ExternalCalendarConnectionState::Revoked ? 'revoked_at' : 'disconnected_at'] = now();

            $this->transition($connection, $target, $attributes);
        });
    }

    /**
     * The ONLY place a connection's state changes — a genuinely optimistic
     * one. An illegal transition throws before touching the database; a
     * lost race (zero affected rows) throws ExternalCalendarConcurrencyException.
     *
     * refresh_token_encrypted is encrypted here with Crypt::encryptString()
     * because this is a query-builder write that bypasses the model's
     * `encrypted` cast — the same idiom GoogleBusinessProfileConnectionManager
     * uses, for the same reason.
     *
     * @param array<string, mixed> $attributes
     * @throws ExternalCalendarConcurrencyException
     */
    private function transition(ExternalCalendarConnection $connection, ExternalCalendarConnectionState $target, array $attributes = []): void
    {
        $expectedVersion = (int) $connection->lock_version;

        if (array_key_exists('refresh_token_encrypted', $attributes)) {
            $plain = $attributes['refresh_token_encrypted'];
            $attributes['refresh_token_encrypted'] = ($plain === null || $plain === '')
                ? null
                : Crypt::encryptString((string) $plain);
        }

        $affected = DB::table('external_calendar_connections')
            ->where('id', $connection->id)
            ->where('lock_version', $expectedVersion)
            ->update(array_merge($attributes, [
                'state' => $target->value,
                'lock_version' => $expectedVersion + 1,
                'updated_at' => now(),
            ]));

        if ($affected !== 1) {
            throw new ExternalCalendarConcurrencyException((int) $connection->id);
        }

        $connection->refresh();
    }
}
