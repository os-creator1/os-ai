<?php

namespace App\Library\GoogleBusinessProfile;

use App\DTO\GoogleBusinessProfile\GoogleTokenGrant;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Enums\GoogleBusinessProfile\GoogleOperationType;
use App\Events\GoogleBusinessProfile\GoogleBusinessProfileConnected;
use App\Events\GoogleBusinessProfile\GoogleBusinessProfileConnectionRevoked;
use App\Events\GoogleBusinessProfile\GoogleBusinessProfileDisconnected;
use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileProviderException;
use App\Library\GoogleBusinessProfile\Contracts\GoogleBusinessProfileReadClient;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * GBP Slice A contract §10.1 / §13.5 — the connection state machine, and
 * the ONLY writer of refresh_token_encrypted.
 *
 * A transition absent from GoogleConnectionState::transitionsTo() throws
 * (contract §10.1: "must throw, never silently no-op").
 *
 * NO PROVIDER CALL EVER HAPPENS INSIDE A TRANSACTION (contract §24.9,
 * §14.4). Each method below calls the provider first, outside any
 * transaction, and opens a short transaction only to persist the result.
 */
final class GoogleBusinessProfileConnectionManager
{
    public function __construct(
        private readonly GoogleBusinessProfileReadClient $client,
        private readonly GoogleOAuthStateSigner $stateSigner,
        private readonly GoogleBusinessProfileOperationLedger $ledger,
    ) {
    }

    public function findForBusiness(Business $business): ?BusinessGoogleConnection
    {
        return BusinessGoogleConnection::query()->where('business_id', $business->id)->first();
    }

    /**
     * Contract §9.3 — connect initiation. Creates or reuses the
     * Business's single connection row, moves it to `pending`, issues a
     * signed single-use state, writes a connect_initiated ledger row, and
     * returns the Google authorization URL.
     *
     * prompt=consent is sent ONLY when there is no stored refresh token or
     * the connection is revoked — a reconnect of a healthy connection does
     * not force consent.
     */
    public function beginConnect(Business $business, int $actorUserId): string
    {
        $connection = $this->findForBusiness($business);

        if ($connection === null) {
            $connection = BusinessGoogleConnection::create([
                'business_id' => $business->id,
                'state' => GoogleConnectionState::Pending,
                'connected_by_user_id' => $actorUserId,
            ]);

            // A brand-new connection has no stored authorization, so the
            // first exchange must be the one that yields a refresh token.
            $forceConsent = true;
        } else {
            // Decided BEFORE the transition, from the state as it stands:
            // a revoked connection, or one that somehow holds no token,
            // needs a fresh consent to obtain a refresh token at all.
            // A reconnect of a healthy connection does not force consent.
            $forceConsent = ! $connection->hasStoredAuthorization()
                || $connection->state === GoogleConnectionState::Revoked;

            if ($connection->state !== GoogleConnectionState::Pending) {
                $this->transition($connection, GoogleConnectionState::Pending, [
                    'connected_by_user_id' => $actorUserId,
                    'failure_classification' => null,
                ]);
            }
        }

        $signedState = $this->stateSigner->issue($connection);

        $this->ledger->open(
            businessId: (int) $business->id,
            type: GoogleOperationType::ConnectInitiated,
            actorUserId: $actorUserId,
            summary: 'Authorization requested',
        );

        return $this->client->authorizationUrl($signedState, $forceConsent);
    }

    /**
     * Contract §9.5 step 13+ — exchange the authorization code and store
     * the encrypted refresh token. The caller has ALREADY validated state,
     * nonce, expiry, tenancy, entitlement and permission, and has ALREADY
     * consumed the nonce atomically.
     *
     * Google returns a refresh token only on the first exchange for a
     * grant; when it returns none and we already hold one, the stored
     * token is kept rather than nulled.
     */
    public function completeConnect(BusinessGoogleConnection $connection, string $code, int $actorUserId): void
    {
        $operation = $this->ledger->open(
            businessId: (int) $connection->business_id,
            type: GoogleOperationType::ConnectCompleted,
            actorUserId: $actorUserId,
            summary: 'Authorization code exchange',
            fingerprintParts: ['exchangeAuthorizationCode'],
        );

        try {
            // Outside any transaction (contract §24.9).
            $grant = $this->client->exchangeAuthorizationCode($code);
        } catch (GoogleBusinessProfileProviderException $exception) {
            $this->ledger->fail($operation, $exception, 'Authorization code exchange');

            $this->markFailure($connection, $exception);

            throw $exception;
        }

        $this->storeGrant($connection, $grant, $actorUserId);

        $this->ledger->succeed($operation, 'Connection established');

        GoogleBusinessProfileConnected::dispatch(
            (int) $connection->business_id,
            (int) $connection->id,
            $actorUserId,
        );
    }

    /**
     * Contract §9.7 — derive a REQUEST-LIFETIME access token from the
     * encrypted refresh token. Nothing is persisted;
     * business_google_connections has no access-token column.
     *
     * Contract §9.8 — invalid_grant transitions to `revoked` and is NEVER
     * retried automatically.
     */
    public function accessTokenFor(BusinessGoogleConnection $connection): string
    {
        if (! $connection->isActive() || ! $connection->hasStoredAuthorization()) {
            throw GoogleBusinessProfileProviderException::invalidGrant();
        }

        try {
            $grant = $this->client->exchangeRefreshToken((string) $connection->refresh_token_encrypted);
        } catch (GoogleBusinessProfileProviderException $exception) {
            if ($exception->isRevocation()) {
                $this->revoke($connection);
            } else {
                $this->markFailure($connection, $exception);
            }

            throw $exception;
        }

        $connection->forceFill([
            'last_refreshed_at' => now(),
            'failure_classification' => null,
        ])->save();

        return $grant->accessToken;
    }

    /**
     * Contract §9.8 / §10.1 — active -> revoked. No retry storm: the
     * connection stops being eligible for background refresh until an
     * explicit reconnect succeeds.
     */
    public function revoke(BusinessGoogleConnection $connection): void
    {
        if ($connection->state === GoogleConnectionState::Revoked) {
            return;
        }

        $this->transition($connection, GoogleConnectionState::Revoked, [
            'revoked_at' => now(),
            'failure_classification' => \App\Models\BusinessGoogleOperation::FAILURE_INVALID_GRANT,
        ]);

        GoogleBusinessProfileConnectionRevoked::dispatch(
            (int) $connection->business_id,
            (int) $connection->id,
        );
    }

    /**
     * Contract §13.5 — disconnect DESTROYS authorization material, in one
     * transaction:
     *   1. state -> disconnected, disconnected_at, lock_version++
     *   2. null refresh_token_encrypted, granted_scopes,
     *      google_account_email (and any live OAuth state)
     *   3. delete every binding for this connection (the FK would cascade;
     *      done explicitly so it is directly testable)
     *   4. one `disconnected` ledger row, which survives because
     *      business_id is denormalized with no FK
     *
     * The stored refresh token is NOT presented to Google for revocation:
     * that is an outbound mutation against the authorization server, and
     * the Slice A provider interface exposes no such method. The UI states
     * that the customer may also revoke access in their Google Account
     * settings.
     */
    public function disconnect(BusinessGoogleConnection $connection, ?int $actorUserId): void
    {
        $businessId = (int) $connection->business_id;

        DB::transaction(function () use ($connection) {
            $connection->locations()->delete();

            $this->transition($connection, GoogleConnectionState::Disconnected, [
                'disconnected_at' => now(),
                'refresh_token_encrypted' => null,
                'granted_scopes' => null,
                'google_account_email' => null,
                'oauth_state_nonce' => null,
                'oauth_state_expires_at' => null,
                'refresh_claimed_at' => null,
                'failure_classification' => null,
            ]);
        });

        $operation = $this->ledger->open(
            businessId: $businessId,
            type: GoogleOperationType::Disconnected,
            actorUserId: $actorUserId,
            summary: 'Connection disconnected; stored authorization destroyed',
        );

        $this->ledger->succeed($operation);

        GoogleBusinessProfileDisconnected::dispatch($businessId, (int) $connection->id, $actorUserId);
    }

    /**
     * Contract §24.3 — per-connection concurrency of one, claimed with a
     * conditional UPDATE in the B4 AutomationExecutionClaimService idiom.
     * Returns true only for the caller that won the claim; a concurrent
     * refresh returns false and does nothing, without an error.
     */
    public function claimRefresh(BusinessGoogleConnection $connection, int $staleAfterSeconds = 300): bool
    {
        $now = now();

        $affected = DB::table('business_google_connections')
            ->where('id', $connection->id)
            ->where(function ($query) use ($now, $staleAfterSeconds) {
                $query->whereNull('refresh_claimed_at')
                    ->orWhere('refresh_claimed_at', '<', $now->copy()->subSeconds($staleAfterSeconds));
            })
            ->update(['refresh_claimed_at' => $now, 'updated_at' => $now]);

        return $affected === 1;
    }

    public function releaseRefreshClaim(BusinessGoogleConnection $connection): void
    {
        DB::table('business_google_connections')
            ->where('id', $connection->id)
            ->update(['refresh_claimed_at' => null, 'updated_at' => now()]);
    }

    public function markFailure(BusinessGoogleConnection $connection, GoogleBusinessProfileProviderException $exception): void
    {
        $connection->forceFill(['failure_classification' => $exception->classification])->save();
    }

    private function storeGrant(BusinessGoogleConnection $connection, GoogleTokenGrant $grant, int $actorUserId): void
    {
        $attributes = [
            'granted_scopes' => $grant->grantedScopes,
            'connected_at' => now(),
            'last_refreshed_at' => now(),
            'connected_by_user_id' => $actorUserId,
            'revoked_at' => null,
            'disconnected_at' => null,
            'failure_classification' => null,
        ];

        // Google returns a refresh token only on the FIRST exchange for a
        // grant. Never null an existing one because this exchange did not
        // repeat it.
        if ($grant->refreshToken !== null && $grant->refreshToken !== '') {
            $attributes['refresh_token_encrypted'] = $grant->refreshToken;
        }

        $this->transition($connection, GoogleConnectionState::Active, $attributes);
    }

    /**
     * Contract §10.1 — the ONLY place a connection state changes. An
     * illegal transition is a programming error and throws.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function transition(BusinessGoogleConnection $connection, GoogleConnectionState $target, array $attributes = []): void
    {
        $current = $connection->state;

        if ($current !== $target && ! $current->canTransitionTo($target)) {
            throw new LogicException(sprintf(
                'Illegal Google Business Profile connection transition %s -> %s.',
                $current->value,
                $target->value,
            ));
        }

        $connection->forceFill(array_merge($attributes, [
            'state' => $target,
            'lock_version' => (int) $connection->lock_version + 1,
        ]))->save();
    }
}
