<?php

namespace App\Library\GoogleAds;

use App\DTO\GoogleBusinessProfile\GoogleTokenGrant;
use App\Enums\GoogleBusinessProfile\GoogleConnectionProduct;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Enums\GoogleBusinessProfile\GoogleOperationType;
use App\Exceptions\GoogleAds\GoogleAdsConcurrencyException;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Library\GoogleAds\Contracts\GoogleAdsAuthClient;
use App\Library\GoogleBusinessProfile\GoogleOAuthStateSigner;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Google Ads Module V1 contract §3 / D2 — the Google Ads connection state
 * machine and the ONLY writer of a `google_ads` row's refresh_token_encrypted.
 *
 * It is PARALLEL to GoogleBusinessProfileConnectionManager (which stays
 * untouched and GBP-only). Both operate on the one canonical
 * `business_google_connections` table; this class can only ever see or create
 * a `product = google_ads` row, and refuses any other product handed to it
 * (assertAdsConnection()) before any provider call, ledger entry or write.
 *
 * The same guarantees the GBP manager has, re-stated for Ads:
 *
 *  - OPTIMISTIC LOCKING IS REAL. Every transition and token write is one
 *    conditional UPDATE carrying `WHERE lock_version = ?`; zero affected rows
 *    means another writer won and aborts with GoogleAdsConcurrencyException.
 *  - EVERY ATTEMPT IS BOUND TO ITS INITIATING ACTOR. connected_by_user_id is
 *    rewritten on every newly issued attempt and replaces the previous nonce,
 *    so an older state dies with it; the callback checks
 *    attemptBelongsToActor() BEFORE consuming the nonce.
 *  - THE TOKEN INVARIANT IS MECHANICAL. Entering pending / revoked /
 *    disconnected NULLS refresh_token_encrypted in the same conditional update
 *    that sets the state. Completing a connection REQUIRES a newly returned
 *    refresh token; if Google returns none (or a scope other than adwords) we
 *    fail closed and stay pending.
 *  - NO ACCESS TOKEN IS EVER PERSISTED. accessTokenFor() derives one from the
 *    refresh token per unit of work. invalid_grant => revoke.
 *  - NO PROVIDER CALL HAPPENS INSIDE A TRANSACTION.
 *
 * BUDGET CONTEXT. completeConnect() accounts its token exchange itself.
 * accessTokenFor() does NOT open a ledger operation: the caller must invoke it
 * inside GoogleAdsCallBudget::withinOperation(), exactly like every other
 * provider call, or the budget fails closed.
 *
 * Disconnect keeps the Ads account row, normalised facts and ledger rows for
 * history (contract §3); it destroys only the authorization material.
 */
final class GoogleAdsConnectionManager
{
    private const PRODUCT = GoogleConnectionProduct::GoogleAds;

    public function __construct(
        private readonly GoogleAdsAuthClient $client,
        private readonly GoogleOAuthStateSigner $stateSigner,
        private readonly GoogleAdsOperationLedger $ledger,
        private readonly GoogleAdsOAuthConfig $oauthConfig,
        private readonly GoogleAdsCallBudget $budget,
    ) {
    }

    public function findForBusiness(Business $business): ?BusinessGoogleConnection
    {
        return BusinessGoogleConnection::query()
            ->where('business_id', $business->id)
            ->where('product', self::PRODUCT->value)
            ->first();
    }

    /**
     * The product guard for every method that is HANDED a connection rather
     * than looking one up: a `business_profile` or `search_console` row
     * reaching Ads code is a programming error with no customer-facing
     * remedy, so it is a LogicException and nothing is touched.
     */
    public function assertAdsConnection(BusinessGoogleConnection $connection): void
    {
        if ($connection->product !== self::PRODUCT) {
            throw new LogicException(sprintf(
                'Google Ads code refused a [%s] connection; only [%s] rows may be operated on here.',
                $connection->product instanceof GoogleConnectionProduct
                    ? $connection->product->value
                    : (string) $connection->product,
                self::PRODUCT->value,
            ));
        }
    }

    /**
     * Connect initiation. PRECONDITION: the connection is not already active
     * (no "reconnect a healthy connection" path). Configuration is validated
     * BEFORE any row, nonce or ledger entry exists. Consent is always forced:
     * every reachable starting state holds no usable refresh token.
     *
     * @return string the Google consent URL
     */
    public function beginConnect(Business $business, int $actorUserId): string
    {
        $this->oauthConfig->assertUsable();

        $connection = $this->findForBusiness($business);

        if ($connection !== null && $connection->isActive()) {
            throw new LogicException('Google Ads connection is already active; disconnect before reconnecting.');
        }

        if ($connection === null) {
            $connection = $this->createFirstConnection($business, $actorUserId);

            if (! $connection->wasRecentlyCreated && $connection->isActive()) {
                throw new LogicException('Google Ads connection is already active; disconnect before reconnecting.');
            }
        }

        if (! $connection->wasRecentlyCreated) {
            $this->transition($connection, GoogleConnectionState::Pending, [
                'connected_by_user_id' => $actorUserId,
                'refresh_token_encrypted' => null,
                'granted_scopes' => null,
                'failure_classification' => null,
            ]);
        }

        // A new nonce overwrites any previous one: the older attempt dies.
        $signedState = $this->stateSigner->issue($connection);

        $this->ledger->open(
            businessId: (int) $business->id,
            type: GoogleOperationType::ConnectInitiated,
            actorUserId: $actorUserId,
            summary: 'Google Ads authorization requested',
        );

        return $this->client->authorizationUrl($signedState, true);
    }

    /**
     * `(business_id, product)` is unique, so two concurrent first-connect
     * requests can both see no row and the loser's INSERT violates it. The
     * loser re-reads the winner's row and continues through the ordinary
     * lock_version-guarded transition. A unique violation with no row present
     * afterwards is a DIFFERENT key (uid / nonce) and is re-thrown.
     */
    private function createFirstConnection(Business $business, int $actorUserId): BusinessGoogleConnection
    {
        try {
            return BusinessGoogleConnection::create([
                'business_id' => $business->id,
                'product' => self::PRODUCT,
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
     * Is this actor the one who initiated the CURRENT attempt? Checked before
     * nonce consumption and before token exchange.
     */
    public function attemptBelongsToActor(BusinessGoogleConnection $connection, int $actorUserId): bool
    {
        $this->assertAdsConnection($connection);

        return $connection->connected_by_user_id !== null
            && (int) $connection->connected_by_user_id === $actorUserId;
    }

    /**
     * Exchanges the authorization code and stores the encrypted refresh
     * token. The caller has already validated state, expiry, tenancy,
     * entitlement, permission and actor, and has consumed the nonce.
     *
     * Fails CLOSED (row stays pending, holds no token) when Google returns no
     * refresh token or reports a granted scope that is not the adwords scope.
     */
    public function completeConnect(BusinessGoogleConnection $connection, string $code, int $actorUserId): void
    {
        $this->assertAdsConnection($connection);

        $this->oauthConfig->assertUsable();

        $operation = $this->ledger->open(
            businessId: (int) $connection->business_id,
            type: GoogleOperationType::ConnectCompleted,
            actorUserId: $actorUserId,
            summary: 'Authorization code exchange',
            fingerprintParts: ['exchangeAuthorizationCode'],
        );

        try {
            $grant = $this->budget->withinOperation(
                $connection,
                $operation,
                fn (): GoogleTokenGrant => $this->client->exchangeAuthorizationCode($code),
            );
        } catch (GoogleAdsProviderException $exception) {
            $this->ledger->fail($operation, $exception, 'Authorization code exchange');
            $this->markFailure($connection, $exception);

            throw $exception;
        }

        if ($grant->refreshToken === null || $grant->refreshToken === '') {
            $this->ledger->failLocally(
                $operation,
                GoogleAdsProviderException::UNEXPECTED_RESPONSE,
                'Google returned no refresh token; connection not activated',
            );

            throw GoogleAdsProviderException::unexpectedResponse();
        }

        if (! $this->grantsAdwordsScope($grant->grantedScopes)) {
            $this->ledger->failLocally(
                $operation,
                GoogleAdsProviderException::ACCESS_DENIED,
                'Google did not grant the Google Ads scope; connection not activated',
            );

            throw GoogleAdsProviderException::accessDenied();
        }

        // Token storage and the transition to active are inseparable.
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
    }

    /**
     * A REQUEST-LIFETIME access token derived from the encrypted refresh
     * token. Nothing is persisted. invalid_grant revokes the connection.
     *
     * @throws GoogleAdsProviderException
     */
    public function accessTokenFor(BusinessGoogleConnection $connection): string
    {
        $this->assertAdsConnection($connection);

        if (! $connection->isActive() || ! $connection->hasStoredAuthorization()) {
            throw GoogleAdsProviderException::invalidGrant();
        }

        try {
            $grant = $this->client->exchangeRefreshToken((string) $connection->refresh_token_encrypted);
        } catch (GoogleAdsProviderException $exception) {
            if ($exception->isRevocation()) {
                $this->revoke($connection);
            } else {
                $this->markFailure($connection, $exception);
            }

            throw $exception;
        }

        DB::table('business_google_connections')
            ->where('id', $connection->id)
            ->where('product', self::PRODUCT->value)
            ->update(['last_refreshed_at' => now(), 'failure_classification' => null, 'updated_at' => now()]);

        $connection->refresh();

        return $grant->accessToken;
    }

    /**
     * active -> revoked. The stored token is NULLED in the same conditional
     * update, so a later reconnect can never reactivate on a dead token.
     */
    public function revoke(BusinessGoogleConnection $connection): void
    {
        $this->assertAdsConnection($connection);

        if ($connection->state === GoogleConnectionState::Revoked) {
            return;
        }

        $this->transition($connection, GoogleConnectionState::Revoked, [
            'revoked_at' => now(),
            'refresh_token_encrypted' => null,
            'granted_scopes' => null,
            'oauth_state_nonce' => null,
            'oauth_state_expires_at' => null,
            'failure_classification' => GoogleAdsProviderException::INVALID_GRANT,
        ]);
    }

    /**
     * Disconnect DESTROYS authorization material and writes a ledger row.
     * The Ads account row, facts and ledger history are retained.
     */
    public function disconnect(BusinessGoogleConnection $connection, ?int $actorUserId): void
    {
        $this->assertAdsConnection($connection);

        $businessId = (int) $connection->business_id;

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

        $operation = $this->ledger->open(
            businessId: $businessId,
            type: GoogleOperationType::Disconnected,
            actorUserId: $actorUserId,
            summary: 'Google Ads disconnected; stored authorization destroyed',
        );

        $this->ledger->succeed($operation);
    }

    public function markFailure(BusinessGoogleConnection $connection, GoogleAdsProviderException $exception): void
    {
        $this->assertAdsConnection($connection);

        DB::table('business_google_connections')
            ->where('id', $connection->id)
            ->where('product', self::PRODUCT->value)
            ->update(['failure_classification' => $exception->classification, 'updated_at' => now()]);

        $connection->refresh();
    }

    /**
     * `adwords` is the only scope this connection requests, so a granted-scope
     * string that is present must contain it (Google separates scopes with a
     * space). An absent string is accepted: the client defaults it to adwords.
     */
    private function grantsAdwordsScope(string $grantedScopes): bool
    {
        $granted = trim($grantedScopes);

        return $granted === ''
            || in_array(self::PRODUCT->scope(), preg_split('/\s+/', $granted) ?: [], true);
    }

    /**
     * The ONLY place a connection state changes, and a genuinely optimistic
     * one. An illegal transition throws before touching the database; a lost
     * race throws GoogleAdsConcurrencyException.
     *
     * refresh_token_encrypted is encrypted here with Crypt::encryptString()
     * because this is a query-builder write that bypasses the model's
     * `encrypted` cast (exactly what the cast uses).
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws GoogleAdsConcurrencyException
     */
    private function transition(BusinessGoogleConnection $connection, GoogleConnectionState $target, array $attributes = []): void
    {
        $current = $connection->state;

        if ($current !== $target && ! $current->canTransitionTo($target)) {
            throw new LogicException(sprintf(
                'Illegal Google Ads connection transition %s -> %s.',
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
            ->where('product', self::PRODUCT->value)
            ->where('lock_version', $expectedVersion)
            ->update(array_merge($attributes, [
                'state' => $target->value,
                'lock_version' => $expectedVersion + 1,
                'updated_at' => now(),
            ]));

        if ($affected !== 1) {
            throw new GoogleAdsConcurrencyException((int) $connection->id);
        }

        $connection->refresh();
    }
}
