<?php

namespace App\Library\MetaAds;

use App\DTO\MetaAds\MetaTokenGrant;
use App\DTO\MetaAds\MetaUserProfile;
use App\Enums\MetaAds\MetaConnectionState;
use App\Enums\MetaAds\MetaOperationType;
use App\Exceptions\MetaAds\MetaAdsConcurrencyException;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Library\MetaAds\Contracts\MetaAuthClient;
use App\Models\Business;
use App\Models\BusinessMetaConnection;
use App\Models\BusinessMetaOperation;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Meta Ads Module V1 contract 24 §3 — the Meta connection state machine and
 * the ONLY writer of a connection's access_token_encrypted. Parallel to (and
 * sharing nothing with) GoogleAdsConnectionManager.
 *
 *  - OPTIMISTIC LOCKING IS REAL. Every transition is ONE conditional UPDATE
 *    carrying `WHERE lock_version = ?`; zero rows => MetaAdsConcurrencyException.
 *    An illegal transition throws before the database is touched.
 *  - EVERY ATTEMPT IS BOUND TO ITS INITIATING ACTOR (connected_by_user_id,
 *    rewritten per attempt; the callback checks attemptBelongsToActor()).
 *  - THE TOKEN INVARIANT IS MECHANICAL. Leaving `active` (other than
 *    active -> active re-auth) NULLS the token in the same conditional update.
 *  - THE TOKEN NEVER LEAVES except through accessTokenFor(). It is never
 *    logged, ledgered, put in an exception or returned by tokenStatus().
 *  - NO PROVIDER CALL HAPPENS INSIDE A TRANSACTION.
 *
 * Meta has no refresh token: re-authorising is the renewal path and is allowed
 * while `active` once the token is inside the warning window (or on request).
 *
 * BUDGET: completeConnect() accounts its provider calls itself. accessTokenFor()
 * makes no provider call and needs no ledger operation.
 */
final class MetaAdsConnectionManager
{
    /** Used when Meta supplied no expires_in: re-verify before the real ~60 days run out. */
    public const FALLBACK_TOKEN_DAYS = 55;

    public function __construct(
        private readonly MetaAuthClient $client,
        private readonly MetaOAuthStateSigner $stateSigner,
        private readonly MetaAdsOperationLedger $ledger,
        private readonly MetaAdsOAuthConfig $oauthConfig,
        private readonly MetaAdsCallBudget $budget,
        private readonly MetaAdsConfig $config,
    ) {
    }

    public function findForBusiness(Business $business): ?BusinessMetaConnection
    {
        return BusinessMetaConnection::query()->where('business_id', $business->id)->first();
    }

    /**
     * Connect / re-authorise initiation. Allowed when the connection is not
     * active, or when it IS active and either the token is inside the
     * reauth_warning_days window (or has no known expiry) or $forceReauth.
     * Configuration is validated BEFORE any row, nonce or ledger entry exists.
     *
     * @return string the Meta authorization dialog URL
     */
    public function beginConnect(Business $business, int $actorUserId, bool $forceReauth = false): string
    {
        $this->oauthConfig->assertUsable();

        $connection = $this->findForBusiness($business);

        if ($connection !== null && ! $this->mayBegin($connection, $forceReauth)) {
            throw new LogicException('Meta connection is active and not due for re-authorisation.');
        }

        if ($connection === null) {
            $connection = $this->createFirstConnection($business, $actorUserId);

            if (! $connection->wasRecentlyCreated && ! $this->mayBegin($connection, $forceReauth)) {
                throw new LogicException('Meta connection is active and not due for re-authorisation.');
            }
        }

        if (! $connection->wasRecentlyCreated) {
            if ($connection->isActive()) {
                // Re-auth: the live token stays usable until the new grant replaces it.
                $this->transition($connection, MetaConnectionState::Active, [
                    'connected_by_user_id' => $actorUserId,
                ]);
            } else {
                $this->transition($connection, MetaConnectionState::Pending, [
                    'connected_by_user_id' => $actorUserId,
                    'access_token_encrypted' => null,
                    'token_expires_at' => null,
                    'granted_scopes' => null,
                    'failure_classification' => null,
                ]);
            }
        }

        // A new nonce overwrites any previous one: the older attempt dies.
        $signedState = $this->stateSigner->issue($connection, $actorUserId);

        $this->ledger->open(
            businessId: (int) $business->id,
            type: MetaOperationType::ConnectInitiated,
            actorUserId: $actorUserId,
            summary: $connection->isActive() ? 'Meta re-authorization requested' : 'Meta authorization requested',
        );

        return $this->client->authorizationUrl($signedState);
    }

    /** True when beginConnect() would be allowed for this existing row. */
    public function mayBegin(BusinessMetaConnection $connection, bool $forceReauth = false): bool
    {
        if (! $connection->isActive()) {
            return true;
        }

        return $forceReauth || $this->isExpiringSoon($connection);
    }

    /**
     * `(business_id)` is unique, so two concurrent first-connect requests can
     * both see no row and the loser's INSERT violates it. The loser re-reads
     * the winner's row and continues through the lock_version-guarded
     * transition. A violation with no row present is a DIFFERENT key
     * (uid / nonce) and is re-thrown.
     */
    private function createFirstConnection(Business $business, int $actorUserId): BusinessMetaConnection
    {
        try {
            return BusinessMetaConnection::create([
                'business_id' => $business->id,
                'state' => MetaConnectionState::Pending,
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

    /** Is this actor the one who initiated the CURRENT attempt? Checked before nonce consumption. */
    public function attemptBelongsToActor(BusinessMetaConnection $connection, int $actorUserId): bool
    {
        return $connection->connected_by_user_id !== null
            && (int) $connection->connected_by_user_id === $actorUserId;
    }

    /**
     * Exchanges the authorization code (-> long-lived token), reads the
     * profile and granted permissions, and stores the encrypted token. The
     * caller has already validated state/tenancy/permission/actor and consumed
     * the nonce.
     *
     * FAILS CLOSED when `ads_read` is not granted: state is unchanged (stays
     * pending), nothing is stored, the new token is discarded, and the ledger
     * records `connect_failed` / `access_denied`. A missing `ads_management`
     * connects READ-ONLY (granted_scopes records only what was granted;
     * see canManage()).
     *
     * @throws MetaProviderException
     */
    public function completeConnect(BusinessMetaConnection $connection, string $code, int $actorUserId): void
    {
        $this->oauthConfig->assertUsable();

        $operation = $this->ledger->open(
            businessId: (int) $connection->business_id,
            type: MetaOperationType::ConnectCompleted,
            actorUserId: $actorUserId,
            summary: 'Authorization code exchange',
            fingerprintParts: ['exchangeCode'],
        );

        try {
            /** @var array{0: MetaTokenGrant, 1: MetaUserProfile, 2: array<int, string>} $result */
            $result = $this->budget->withinOperation($connection, $operation, function () use ($code): array {
                $grant = $this->client->exchangeCode($code);

                return [
                    $grant,
                    $this->client->profile($grant->accessToken),
                    $this->client->grantedPermissions($grant->accessToken),
                ];
            });
        } catch (MetaProviderException $exception) {
            $this->failConnect($connection, $operation, $actorUserId, $exception->classification, $exception);

            throw $exception;
        }

        [$grant, $profile, $permissions] = $result;

        if (! in_array('ads_read', $permissions, true)) {
            $this->failConnect($connection, $operation, $actorUserId, MetaProviderException::ACCESS_DENIED, null);

            throw MetaProviderException::accessDenied();
        }

        $granted = array_values(array_intersect(MetaAdsConfig::SCOPES, $permissions));
        $previousUserId = $connection->meta_user_id;

        // Token storage and the transition to active are inseparable.
        $this->transition($connection, MetaConnectionState::Active, [
            'access_token_encrypted' => $grant->accessToken,
            'token_expires_at' => $grant->expiresAt ?? CarbonImmutable::now()->addDays(self::FALLBACK_TOKEN_DAYS),
            'granted_scopes' => implode(',', $granted),
            'meta_user_id' => $profile->id,
            'meta_user_name' => $profile->name !== null ? mb_substr($profile->name, 0, 191) : null,
            'connected_at' => now(),
            'last_verified_at' => now(),
            'connected_by_user_id' => $actorUserId,
            'revoked_at' => null,
            'disconnected_at' => null,
            'failure_classification' => null,
        ]);

        $this->unselectIfDifferentUser((int) $connection->business_id, $profile->id, $previousUserId);

        $this->ledger->succeed(
            $operation,
            in_array('ads_management', $granted, true) ? 'Connection established' : 'Connection established (read-only: ads_management not granted)',
        );
    }

    /**
     * A re-authorising user who is not the one the account was selected under
     * must not silently inherit the selection: it is UNSELECTED and the owner
     * chooses again.
     */
    private function unselectIfDifferentUser(int $businessId, string $newUserId, ?string $previousConnectionUserId): void
    {
        $account = DB::table('meta_ads_accounts')->where('business_id', $businessId)->first();

        if ($account === null || $account->selected_at === null) {
            return;
        }

        $selectedUser = $account->selected_meta_user_id ?? $previousConnectionUserId;

        if ($selectedUser !== null && (string) $selectedUser !== $newUserId) {
            $this->unselectAccount($businessId);
        }
    }

    private function failConnect(
        BusinessMetaConnection $connection,
        BusinessMetaOperation $operation,
        int $actorUserId,
        string $classification,
        ?MetaProviderException $exception,
    ): void {
        if ($exception !== null) {
            $this->ledger->fail($operation, $exception, 'Authorization code exchange failed');
        } else {
            $this->ledger->failLocally($operation, $classification, 'Meta did not grant ads_read; connection not activated');
        }

        $failed = $this->ledger->open(
            businessId: (int) $connection->business_id,
            type: MetaOperationType::ConnectFailed,
            actorUserId: $actorUserId,
            summary: 'Meta connection could not be completed',
        );
        $this->ledger->failLocally($failed, $classification, 'Meta connection could not be completed');

        // A healthy active connection (failed re-auth) keeps its clean state.
        if (! $connection->isActive()) {
            DB::table('business_meta_connections')
                ->where('id', $connection->id)
                ->update(['failure_classification' => $classification, 'updated_at' => now()]);
            $connection->refresh();
        }
    }

    /** Active and the granted scopes include ads_management (pause/resume allowed). */
    public function canManage(BusinessMetaConnection $connection): bool
    {
        if (! $connection->isActive()) {
            return false;
        }

        return in_array('ads_management', $this->grantedScopes($connection), true);
    }

    /** @return array<int, string> */
    private function grantedScopes(BusinessMetaConnection $connection): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $connection->granted_scopes))));
    }

    /**
     * The decrypted token, ONLY when the connection is active, holds a token
     * and has not passed token_expires_at. A past-expiry active connection is
     * moved to `expired` (token wiped) before the throw.
     *
     * @throws MetaProviderException token_expired
     */
    public function accessTokenFor(BusinessMetaConnection $connection): string
    {
        if (! $connection->isActive() || ! $connection->hasStoredAuthorization()) {
            throw MetaProviderException::tokenExpired();
        }

        if ($connection->token_expires_at === null || $connection->token_expires_at->lte(now())) {
            $exception = MetaProviderException::tokenExpired();

            try {
                $this->markTokenFailure($connection, $exception);
            } catch (MetaAdsConcurrencyException) {
                // Another writer already moved the row; the refusal stands.
            }

            throw $exception;
        }

        return (string) $connection->access_token_encrypted;
    }

    /**
     * Records what a provider failure means for the connection.
     *  - revocation (190/458)      -> revoked, token wiped
     *  - other 190 / token_expired -> expired, token wiped
     *  - anything else             -> no state change
     * A connection that is not active is left alone (already handled).
     */
    public function markTokenFailure(BusinessMetaConnection $connection, MetaProviderException $exception): void
    {
        if (! $exception->isTokenFailure() || ! $connection->isActive()) {
            return;
        }

        $revoked = $exception->isRevocation();

        $this->transition($connection, $revoked ? MetaConnectionState::Revoked : MetaConnectionState::Expired, array_filter([
            'access_token_encrypted' => null,
            'token_expires_at' => null,
            'granted_scopes' => null,
            'oauth_state_nonce' => null,
            'oauth_state_expires_at' => null,
            'revoked_at' => $revoked ? now() : false,
            'failure_classification' => $exception->classification,
        ], static fn ($value): bool => $value !== false));

        $operation = $this->ledger->open(
            businessId: (int) $connection->business_id,
            type: MetaOperationType::TokenExpired,
            summary: $revoked ? 'Meta says the app was de-authorised; reconnect required' : 'Meta token expired; reconnect required',
        );
        $this->ledger->succeed($operation);
    }

    /**
     * Disconnect DESTROYS authorization material, UNSELECTS the account and
     * writes a ledger row. The account row, facts and ledger history stay.
     * Meta's provider-side revoke is intentionally not called (Google precedent).
     */
    public function disconnect(BusinessMetaConnection $connection, ?int $actorUserId): void
    {
        $businessId = (int) $connection->business_id;

        $this->transition($connection, MetaConnectionState::Disconnected, [
            'disconnected_at' => now(),
            'access_token_encrypted' => null,
            'token_expires_at' => null,
            'granted_scopes' => null,
            'meta_user_id' => null,
            'meta_user_name' => null,
            'oauth_state_nonce' => null,
            'oauth_state_expires_at' => null,
            'failure_classification' => null,
        ]);

        $this->unselectAccount($businessId);

        $operation = $this->ledger->open(
            businessId: $businessId,
            type: MetaOperationType::Disconnected,
            actorUserId: $actorUserId,
            summary: 'Meta disconnected; stored authorization destroyed',
        );
        $this->ledger->succeed($operation);
    }

    /** Token facts for the UI. Never contains the token. */
    public function tokenStatus(BusinessMetaConnection $connection): MetaTokenStatus
    {
        $active = $connection->isActive();
        $expiresAt = $active && $connection->token_expires_at !== null
            ? CarbonImmutable::instance($connection->token_expires_at)
            : null;

        $daysLeft = $expiresAt === null
            ? null
            : max(0, (int) floor(now()->diffInSeconds($expiresAt, false) / 86400));

        return new MetaTokenStatus(
            state: $connection->state,
            expiresAt: $expiresAt,
            daysLeft: $daysLeft,
            needsReauthSoon: $active && $this->isExpiringSoon($connection),
            canManage: $this->canManage($connection),
        );
    }

    /** Inside reauth_warning_days (an unknown expiry counts as due). */
    private function isExpiringSoon(BusinessMetaConnection $connection): bool
    {
        if ($connection->token_expires_at === null) {
            return true;
        }

        return $connection->token_expires_at->lte(now()->addDays($this->config->reauthWarningDays()));
    }

    private function unselectAccount(int $businessId): void
    {
        DB::table('meta_ads_accounts')->where('business_id', $businessId)->update(['selected_at' => null, 'updated_at' => now()]);
    }

    /**
     * The ONLY place a connection state changes. access_token_encrypted is
     * encrypted here because a query-builder write bypasses the model's
     * `encrypted` cast.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws MetaAdsConcurrencyException
     */
    private function transition(BusinessMetaConnection $connection, MetaConnectionState $target, array $attributes = []): void
    {
        $current = $connection->state;

        if ($current !== $target && ! $current->canTransitionTo($target)) {
            throw new LogicException(sprintf(
                'Illegal Meta connection transition %s -> %s.',
                $current->value,
                $target->value,
            ));
        }

        $expectedVersion = (int) $connection->lock_version;

        if (array_key_exists('access_token_encrypted', $attributes)) {
            $plain = $attributes['access_token_encrypted'];
            $attributes['access_token_encrypted'] = ($plain === null || $plain === '')
                ? null
                : Crypt::encryptString((string) $plain);
        }

        $affected = DB::table('business_meta_connections')
            ->where('id', $connection->id)
            ->where('lock_version', $expectedVersion)
            ->update(array_merge($attributes, [
                'state' => $target->value,
                'lock_version' => $expectedVersion + 1,
                'updated_at' => now(),
            ]));

        if ($affected !== 1) {
            throw new MetaAdsConcurrencyException((int) $connection->id);
        }

        $connection->refresh();
    }
}
