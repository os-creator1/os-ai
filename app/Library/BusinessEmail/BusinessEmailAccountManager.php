<?php

namespace App\Library\BusinessEmail;

use App\Enums\BusinessEmail\BusinessEmailAccountState;
use App\Enums\BusinessEmail\BusinessEmailFailureCategory;
use App\Enums\BusinessEmail\BusinessEmailProviderType;
use App\Exceptions\BusinessEmail\BusinessEmailConcurrencyException;
use App\Exceptions\BusinessEmail\BusinessEmailProviderException;
use App\Models\Business;
use App\Models\BusinessEmailAccount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The connection state machine and the ONLY writer of
 * business_email_accounts.refresh_token_encrypted. Mirrors
 * GoogleBusinessProfileConnectionManager / ExternalCalendarConnectionManager:
 *
 *  - OPTIMISTIC LOCKING IS REAL. Every state transition is one conditional
 *    UPDATE carrying `WHERE lock_version = ?`; zero affected rows aborts with
 *    BusinessEmailConcurrencyException — never a blind retry.
 *  - THE TOKEN INVARIANT IS MECHANICAL. Entering pending, revoked or
 *    disconnected NULLs the refresh token in the same UPDATE that sets the
 *    state, so no non-active row holds authorization material. Completing a
 *    connection REQUIRES a refresh token; a callback that yields none fails
 *    closed and the account stays pending.
 *  - NO PROVIDER CALL HAPPENS INSIDE A TRANSACTION.
 *  - ONE ACCOUNT PER BUSINESS. A live (pending/active) account is never
 *    swapped in place: reconnect is only offered from none / pending /
 *    revoked / disconnected, and switching mailbox while active is an
 *    explicit disconnect first.
 */
final class BusinessEmailAccountManager
{
    public function __construct(
        private readonly BusinessEmailOAuthConfig $oauthConfig,
        private readonly BusinessEmailOAuthStateSigner $stateSigner,
        private readonly BusinessEmailProviderRegistry $providers,
    ) {
    }

    public function findForBusiness(Business $business): ?BusinessEmailAccount
    {
        return BusinessEmailAccount::query()->where('business_id', $business->id)->first();
    }

    /**
     * Starts consent. Config is validated BEFORE any row or nonce exists.
     *
     * @throws \App\Exceptions\BusinessEmail\BusinessEmailConfigurationException
     * @throws BusinessEmailConcurrencyException
     * @throws LogicException when an active account already exists
     */
    public function beginConnect(Business $business, int $actorUserId, BusinessEmailProviderType $provider): string
    {
        $this->oauthConfig->assertUsable($provider);

        $account = $this->findForBusiness($business);

        if ($account !== null && $account->state === BusinessEmailAccountState::Active) {
            throw new LogicException('An email account is already connected; disconnect it before connecting a different one.');
        }

        if ($account === null) {
            $account = $this->createPending($business, $actorUserId, $provider);
        } else {
            // Re-stamp the actor, the provider and any identity, and null the
            // credential, as the row (re-)enters pending. A second initiation
            // replaces the previous nonce below, so the older state dies.
            $this->transition($account, BusinessEmailAccountState::Pending, [
                'provider' => $provider->value,
                'connected_by_user_id' => $actorUserId,
                'refresh_token_encrypted' => null,
                'granted_scopes' => null,
                'mailbox_email' => null,
                'external_account_id' => null,
                'display_name' => null,
                'failure_classification' => null,
            ]);
        }

        $state = $this->stateSigner->issue($account->fresh());

        return $this->providers->for($provider)->authorizationUrl($state, true);
    }

    /**
     * business_id UNIQUE is the hard backstop for "one account per
     * Business": the loser of a concurrent first connect re-reads the
     * winner's row instead of surfacing a raw constraint violation.
     */
    private function createPending(Business $business, int $actorUserId, BusinessEmailProviderType $provider): BusinessEmailAccount
    {
        try {
            return BusinessEmailAccount::create([
                'business_id' => $business->id,
                'provider' => $provider,
                'state' => BusinessEmailAccountState::Pending,
                'connected_by_user_id' => $actorUserId,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            $winner = $this->findForBusiness($business);

            if ($winner === null) {
                throw $exception;
            }

            throw new LogicException('An email connection attempt is already in progress for this business.');
        }
    }

    /** Is this actor the one who initiated the CURRENT attempt? Checked before consuming the nonce. */
    public function attemptBelongsToActor(BusinessEmailAccount $account, int $actorUserId): bool
    {
        return $account->connected_by_user_id !== null
            && (int) $account->connected_by_user_id === $actorUserId;
    }

    /**
     * The caller has already validated state, signature, expiry, tenancy,
     * permission and actor, and has already consumed the nonce atomically.
     * A grant without a refresh token FAILS CLOSED (account stays pending).
     *
     * @throws BusinessEmailProviderException|BusinessEmailConcurrencyException
     */
    public function completeConnect(BusinessEmailAccount $account, string $code): void
    {
        if ($account->state !== BusinessEmailAccountState::Pending) {
            throw new LogicException('Only a pending email connection can be completed.');
        }

        // Outside any transaction — a real outbound HTTP request.
        $grant = $this->providers->for($account->provider)->exchangeAuthorizationCode($code);

        if ($grant->refreshToken === null || $grant->refreshToken === '') {
            throw new BusinessEmailProviderException(BusinessEmailFailureCategory::PermanentProviderFailure, 'no_refresh_token');
        }

        $this->transition($account, BusinessEmailAccountState::Active, [
            'refresh_token_encrypted' => $grant->refreshToken,
            'granted_scopes' => $grant->grantedScopes !== null ? substr($grant->grantedScopes, 0, 512) : null,
            'mailbox_email' => $grant->accountEmail,
            'external_account_id' => $grant->accountId !== null ? substr($grant->accountId, 0, 191) : null,
            'display_name' => $grant->displayName !== null ? substr($grant->displayName, 0, 191) : null,
            'connected_at' => now(),
            'last_refreshed_at' => now(),
            'failure_classification' => null,
            'revoked_at' => null,
            'disconnected_at' => null,
        ]);
    }

    /**
     * Derives a REQUEST-LIFETIME access token from the encrypted refresh
     * token. Nothing but bookkeeping (and a rotated refresh token, when the
     * provider returns one) is persisted — and only while the account is
     * STILL active, so a refresh racing a disconnect can never resurrect a
     * credential on a row that was just destroyed.
     *
     * @throws BusinessEmailProviderException
     */
    public function accessTokenFor(BusinessEmailAccount $account): string
    {
        if (! $account->isActive()) {
            throw new BusinessEmailProviderException(BusinessEmailFailureCategory::DisconnectedAccount, 'account_not_active');
        }

        try {
            $grant = $this->providers->for($account->provider)->exchangeRefreshToken((string) $account->refresh_token_encrypted);
        } catch (BusinessEmailProviderException $exception) {
            if ($exception->isRevocation()) {
                $this->revoke($account);
            } else {
                $this->markFailure($account, $exception);
            }

            throw $exception;
        }

        $update = ['last_refreshed_at' => now(), 'failure_classification' => null, 'updated_at' => now()];

        // Graph rotates refresh tokens; Google normally does not.
        if ($grant->refreshToken !== null && $grant->refreshToken !== '') {
            $update['refresh_token_encrypted'] = Crypt::encryptString($grant->refreshToken);
        }

        DB::table('business_email_accounts')
            ->where('id', $account->id)
            ->where('state', BusinessEmailAccountState::Active->value)
            ->update($update);

        return $grant->accessToken;
    }

    public function markFailure(BusinessEmailAccount $account, BusinessEmailProviderException $exception): void
    {
        DB::table('business_email_accounts')
            ->where('id', $account->id)
            ->update([
                'failure_classification' => $exception->category->value,
                'updated_at' => now(),
            ]);
    }

    /** Provider-invalidated authorization: active/pending → revoked, credential destroyed. */
    public function revoke(BusinessEmailAccount $account): void
    {
        $account->refresh();

        if (in_array($account->state, [BusinessEmailAccountState::Revoked, BusinessEmailAccountState::Disconnected], true)) {
            return;
        }

        $this->endConnection($account, BusinessEmailAccountState::Revoked);
    }

    /**
     * Explicit disconnect: best-effort provider-side revocation FIRST (it
     * needs the token this call is about to destroy), then local destruction
     * regardless of whether the provider call succeeded.
     */
    public function disconnect(BusinessEmailAccount $account): void
    {
        if (! in_array($account->state, [BusinessEmailAccountState::Pending, BusinessEmailAccountState::Active], true)) {
            return;
        }

        if ($account->state === BusinessEmailAccountState::Active && ! empty($account->refresh_token_encrypted)) {
            $this->providers->for($account->provider)->revokeGrant((string) $account->refresh_token_encrypted);
        }

        $this->endConnection($account, BusinessEmailAccountState::Disconnected);
    }

    private function endConnection(BusinessEmailAccount $account, BusinessEmailAccountState $target): void
    {
        $attributes = [
            'refresh_token_encrypted' => null,
            'granted_scopes' => null,
            'oauth_state_nonce' => null,
            'oauth_state_expires_at' => null,
            'failure_classification' => null,
        ];

        $attributes[$target === BusinessEmailAccountState::Revoked ? 'revoked_at' : 'disconnected_at'] = now();

        $this->transition($account, $target, $attributes);
    }

    /**
     * The ONLY place an account's state changes — genuinely optimistic. The
     * refresh token is encrypted here because this is a query-builder write
     * that bypasses the model's `encrypted` cast.
     *
     * @param array<string, mixed> $attributes
     * @throws BusinessEmailConcurrencyException
     */
    private function transition(BusinessEmailAccount $account, BusinessEmailAccountState $target, array $attributes = []): void
    {
        $expectedVersion = (int) $account->lock_version;

        if (array_key_exists('refresh_token_encrypted', $attributes)) {
            $plain = $attributes['refresh_token_encrypted'];
            $attributes['refresh_token_encrypted'] = ($plain === null || $plain === '')
                ? null
                : Crypt::encryptString((string) $plain);
        }

        $affected = DB::table('business_email_accounts')
            ->where('id', $account->id)
            ->where('lock_version', $expectedVersion)
            ->update(array_merge($attributes, [
                'state' => $target->value,
                'lock_version' => $expectedVersion + 1,
                'updated_at' => now(),
            ]));

        if ($affected !== 1) {
            throw new BusinessEmailConcurrencyException((int) $account->id);
        }

        $account->refresh();
    }
}
