<?php

namespace App\Library\GoogleBusinessProfile;

use App\DTO\GoogleBusinessProfile\GoogleTokenGrant;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Enums\GoogleBusinessProfile\GoogleOperationType;
use App\Events\GoogleBusinessProfile\GoogleBusinessProfileConnected;
use App\Events\GoogleBusinessProfile\GoogleBusinessProfileConnectionRevoked;
use App\Events\GoogleBusinessProfile\GoogleBusinessProfileDisconnected;
use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileConcurrencyException;
use App\Exceptions\GoogleBusinessProfile\GoogleBusinessProfileProviderException;
use App\Library\GoogleBusinessProfile\Contracts\GoogleBusinessProfileReadClient;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use App\Models\BusinessGoogleOperation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * GBP Slice A contract §10.1 / §13.5 — the connection state machine, and
 * the ONLY writer of refresh_token_encrypted.
 *
 * CORRECTION PASS (items 2, 3, 4):
 *
 *  - OPTIMISTIC LOCKING IS REAL (item 3). Every state transition and every
 *    token write is a single conditional UPDATE carrying
 *    `WHERE lock_version = ?`. Zero affected rows means another writer won
 *    the race, and that aborts with
 *    GoogleBusinessProfileConcurrencyException — no success event, no
 *    successful ledger outcome, no blind retry. Incrementing the attribute
 *    on a stale in-memory model and calling save() (the previous
 *    behaviour) silently let the loser overwrite the winner.
 *
 *  - EVERY ATTEMPT IS BOUND TO ITS INITIATING ACTOR (item 2).
 *    connected_by_user_id is rewritten on EVERY newly issued attempt,
 *    including when the row is already pending, and a second initiation
 *    replaces the previous nonce and actor binding together — so the older
 *    state dies with it. The callback compares the authenticated user
 *    against connected_by_user_id BEFORE consuming the nonce, so a
 *    mismatched actor cannot burn the rightful actor's live state.
 *
 *  - THE TOKEN INVARIANT IS MECHANICAL (item 4). Entering pending, revoked
 *    or disconnected NULLS refresh_token_encrypted in the same conditional
 *    update that sets the state, so no non-active row can retain
 *    authorization material. Completing a connection REQUIRES a
 *    newly-returned refresh token: if Google returns none we fail closed
 *    and stay pending rather than reactivating with a token we already
 *    know is dead.
 *
 * NO PROVIDER CALL EVER HAPPENS INSIDE A TRANSACTION (contract §24.9).
 */
final class GoogleBusinessProfileConnectionManager
{
    public function __construct(
        private readonly GoogleBusinessProfileReadClient $client,
        private readonly GoogleOAuthStateSigner $stateSigner,
        private readonly GoogleBusinessProfileOperationLedger $ledger,
        private readonly GoogleBusinessProfileOAuthConfig $oauthConfig,
        private readonly GoogleBusinessProfileCallBudget $budget,
    ) {
    }

    public function findForBusiness(Business $business): ?BusinessGoogleConnection
    {
        return BusinessGoogleConnection::query()->where('business_id', $business->id)->first();
    }

    /**
     * Contract §9.3 — connect initiation.
     *
     * PRECONDITION: the connection must not already be active. There is no
     * "reconnect a healthy connection" path (item 4): it would either
     * pointlessly re-consent or, worse, tempt a reactivation that reuses an
     * old token. The controller refuses first; this assertion is the
     * backstop.
     *
     * Consent is ALWAYS forced, because every reachable starting state
     * (none / pending / revoked / disconnected) holds no usable refresh
     * token — Google returns one only on a fresh consent.
     *
     * Configuration is validated BEFORE any row, nonce or ledger entry
     * exists (item 7), so a misconfigured deployment leaves nothing behind.
     */
    public function beginConnect(Business $business, int $actorUserId): string
    {
        // Item 7 — throws before ANY database state change.
        $this->oauthConfig->assertUsable();

        $connection = $this->findForBusiness($business);

        if ($connection !== null && $connection->isActive()) {
            throw new LogicException('Google Business Profile connection is already active; disconnect before reconnecting.');
        }

        if ($connection === null) {
            $connection = $this->createFirstConnection($business, $actorUserId);

            // Lost the create race — another request inserted the single
            // permitted row between our SELECT and our INSERT. Re-apply
            // the very check we made above against the winner's row, then
            // fall through to the ordinary re-stamp so this attempt still
            // goes THROUGH lock_version rather than around it.
            if (! $connection->wasRecentlyCreated && $connection->isActive()) {
                throw new LogicException('Google Business Profile connection is already active; disconnect before reconnecting.');
            }
        }

        if (! $connection->wasRecentlyCreated) {
            // Item 2 + item 4 — re-stamp the actor even when the row is
            // ALREADY pending (the previous implementation skipped this,
            // leaving a stale actor bound), and null any authorization
            // material as we re-enter pending.
            $this->transition($connection, GoogleConnectionState::Pending, [
                'connected_by_user_id' => $actorUserId,
                'refresh_token_encrypted' => null,
                'granted_scopes' => null,
                'failure_classification' => null,
            ]);
        }

        // Issuing a new nonce overwrites any previous one, so the older
        // attempt's state is dead the moment a newer one is issued.
        $signedState = $this->stateSigner->issue($connection);

        $this->ledger->open(
            businessId: (int) $business->id,
            type: GoogleOperationType::ConnectInitiated,
            actorUserId: $actorUserId,
            summary: 'Authorization requested',
        );

        return $this->client->authorizationUrl($signedState, true);
    }

    /**
     * MULTI-LOCATION CORRECTION PASS — the first-connect race.
     *
     * business_google_connections.business_id is UNIQUE (constraint C-1),
     * so exactly one row per Business can ever exist. Two concurrent
     * first-connect requests can both observe no row, and the loser's
     * INSERT then violates that constraint — previously surfacing as an
     * unhandled QueryException, i.e. a 500 carrying a database message.
     *
     * The constraint is NOT weakened and no row is ever overwritten. The
     * loser simply re-reads the winner's row and continues through the
     * ordinary lock_version-guarded transition, so the outcome is
     * identical to having arrived second: at most one row, one current
     * actor/nonce pair, and no bypass of optimistic locking. If the winner
     * transitions again in between, transition() reports zero affected
     * rows and the caller gets the safe retry message.
     *
     * ONLY the business_id race is absorbed. UniqueConstraintViolation-
     * Exception is re-thrown untouched when no row is present afterwards,
     * because that means a DIFFERENT unique key collided (uid, or the
     * OAuth state nonce) and swallowing it would hide a real defect.
     * Nothing here catches a generic QueryException.
     */
    private function createFirstConnection(Business $business, int $actorUserId): BusinessGoogleConnection
    {
        try {
            return BusinessGoogleConnection::create([
                'business_id' => $business->id,
                'state' => GoogleConnectionState::Pending,
                'connected_by_user_id' => $actorUserId,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            $winner = $this->findForBusiness($business);

            if ($winner === null) {
                throw $exception;
            }

            return $winner;
        }
    }

    /**
     * Item 2 — is this actor the one who initiated the CURRENT attempt?
     * Checked before nonce consumption and before token exchange.
     */
    public function attemptBelongsToActor(BusinessGoogleConnection $connection, int $actorUserId): bool
    {
        return $connection->connected_by_user_id !== null
            && (int) $connection->connected_by_user_id === $actorUserId;
    }

    /**
     * Contract §9.5 — exchange the authorization code and store the
     * encrypted refresh token. The caller has already validated state,
     * expiry, tenancy, entitlement, permission AND actor, and has already
     * consumed the nonce atomically.
     *
     * Item 4 — a missing refresh token FAILS CLOSED. Google returns one
     * only on a fresh consent; if it returns none here, the grant we hold
     * is not usable and the connection must stay pending rather than go
     * active on a token that may be the revoked one.
     */
    public function completeConnect(BusinessGoogleConnection $connection, string $code, int $actorUserId): void
    {
        $this->oauthConfig->assertUsable();

        $operation = $this->ledger->open(
            businessId: (int) $connection->business_id,
            type: GoogleOperationType::ConnectCompleted,
            actorUserId: $actorUserId,
            summary: 'Authorization code exchange',
            fingerprintParts: ['exchangeAuthorizationCode'],
        );

        try {
            // Outside any transaction (contract §24.9); budget-accounted
            // (item 6) — an OAuth token exchange is a real outbound
            // request.
            $grant = $this->budget->withinOperation(
                $connection,
                $operation,
                fn (): GoogleTokenGrant => $this->client->exchangeAuthorizationCode($code),
            );
        } catch (GoogleBusinessProfileProviderException $exception) {
            $this->ledger->fail($operation, $exception, 'Authorization code exchange');
            $this->markFailure($connection, $exception);

            throw $exception;
        }

        if ($grant->refreshToken === null || $grant->refreshToken === '') {
            // Fail closed. The row stays pending and holds no token.
            $this->ledger->failLocally(
                $operation,
                BusinessGoogleOperation::FAILURE_UNEXPECTED_RESPONSE,
                'Google returned no refresh token; connection not activated',
            );

            throw GoogleBusinessProfileProviderException::unexpectedResponse();
        }

        // ONE conditional update: token storage and the transition to
        // active are inseparable (item 3).
        $this->transition($connection, GoogleConnectionState::Active, [
            'refresh_token_encrypted' => $grant->refreshToken,
            'granted_scopes' => $grant->grantedScopes,
            'connected_at' => now(),
            'last_refreshed_at' => now(),
            'connected_by_user_id' => $actorUserId,
            'revoked_at' => null,
            'disconnected_at' => null,
            'failure_classification' => null,
        ]);

        $this->ledger->succeed($operation, 'Connection established');

        GoogleBusinessProfileConnected::dispatch(
            (int) $connection->business_id,
            (int) $connection->id,
            $actorUserId,
        );
    }

    /**
     * Contract §9.7 — derive a REQUEST-LIFETIME access token from the
     * encrypted refresh token. Nothing is persisted.
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

        DB::table('business_google_connections')
            ->where('id', $connection->id)
            ->update(['last_refreshed_at' => now(), 'failure_classification' => null, 'updated_at' => now()]);

        $connection->refresh();

        return $grant->accessToken;
    }

    /**
     * Contract §9.8 / §10.1 — active -> revoked.
     *
     * Item 4: revocation NULLS the stored token in the same conditional
     * update. Retaining it (the previous behaviour) meant a later reconnect
     * could reactivate on a token Google had already invalidated.
     */
    public function revoke(BusinessGoogleConnection $connection): void
    {
        if ($connection->state === GoogleConnectionState::Revoked) {
            return;
        }

        $this->transition($connection, GoogleConnectionState::Revoked, [
            'revoked_at' => now(),
            'refresh_token_encrypted' => null,
            'granted_scopes' => null,
            'oauth_state_nonce' => null,
            'oauth_state_expires_at' => null,
            'failure_classification' => BusinessGoogleOperation::FAILURE_INVALID_GRANT,
        ]);

        GoogleBusinessProfileConnectionRevoked::dispatch(
            (int) $connection->business_id,
            (int) $connection->id,
        );
    }

    /**
     * Contract §13.5 — disconnect DESTROYS authorization material.
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
     * Distinct from lock_version: this bounds CONCURRENT WORK, not
     * conflicting state writes.
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
        DB::table('business_google_connections')
            ->where('id', $connection->id)
            ->update(['failure_classification' => $exception->classification, 'updated_at' => now()]);

        $connection->refresh();
    }

    /**
     * Contract §10.1 + item 3 — the ONLY place a connection state changes,
     * and a genuinely optimistic one.
     *
     * An illegal transition throws before touching the database. A lost
     * race (zero affected rows) throws
     * GoogleBusinessProfileConcurrencyException, so the caller cannot
     * proceed to emit a success event or a successful ledger outcome.
     *
     * refresh_token_encrypted is encrypted here with Crypt::encryptString()
     * because this is a query-builder write that bypasses the model's
     * `encrypted` cast. That is exactly what the cast itself uses, and the
     * round trip is asserted by test: the manager writes, the model reads
     * back the plaintext, and a raw column read does not.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws GoogleBusinessProfileConcurrencyException
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

        $expectedVersion = (int) $connection->lock_version;

        if (array_key_exists('refresh_token_encrypted', $attributes)) {
            $plain = $attributes['refresh_token_encrypted'];
            $attributes['refresh_token_encrypted'] = ($plain === null || $plain === '')
                ? null
                : Crypt::encryptString((string) $plain);
        }

        $affected = DB::table('business_google_connections')
            ->where('id', $connection->id)
            ->where('lock_version', $expectedVersion)
            ->update(array_merge($attributes, [
                'state' => $target->value,
                'lock_version' => $expectedVersion + 1,
                'updated_at' => now(),
            ]));

        if ($affected !== 1) {
            throw new GoogleBusinessProfileConcurrencyException((int) $connection->id);
        }

        $connection->refresh();
    }
}
