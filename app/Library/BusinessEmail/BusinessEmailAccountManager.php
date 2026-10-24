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
     *
     * $reauthorize lets the owner of an ACTIVE mailbox run consent again
     * (e.g. to grant the inbound read permission) WITHOUT downtime: the
     * account stays active and its stored credential keeps working. Only the
     * attempt's actor and a fresh OAuth nonce are recorded; the credential is
     * replaced solely by a successful, verified callback (completeConnect).
     * A cancelled, failed or expired attempt therefore changes nothing.
     */
    public function beginConnect(Business $business, int $actorUserId, BusinessEmailProviderType $provider, bool $reauthorize = false): string
    {
        if ($provider === BusinessEmailProviderType::Imap) {
            throw new LogicException('Other email is connected with its own form, not with an OAuth sign-in.');
        }

        $this->oauthConfig->assertUsable($provider);

        $account = $this->findForBusiness($business);

        if ($account !== null && $account->state === BusinessEmailAccountState::Active && ! $reauthorize) {
            throw new LogicException('An email account is already connected; disconnect it before connecting a different one.');
        }

        if ($account !== null && $account->state === BusinessEmailAccountState::Active && $account->provider !== $provider) {
            throw new LogicException('Reauthorize with the provider that is connected, or disconnect it first to switch provider.');
        }

        if ($account === null) {
            $account = $this->createPending($business, $actorUserId, $provider);
        } elseif ($account->state === BusinessEmailAccountState::Active) {
            // Reauthorization of a live connection: same state, same credential,
            // same mailbox. The conditional UPDATE still guards against a
            // concurrent disconnect/revoke.
            $this->transition($account, BusinessEmailAccountState::Active, [
                'connected_by_user_id' => $actorUserId,
            ]);
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
                'inbound_failure' => null,
            ]);
        }

        $state = $this->stateSigner->issue($account->fresh());

        return $this->providers->for($provider)->authorizationUrl($state, true);
    }

    /**
     * Connects (or credential-refreshes) an "Other email" mailbox whose
     * settings the CALLER has already validated AND tested against the real
     * servers. There is no OAuth round trip: the validated settings blob is
     * written through the same encrypted-credential/optimistic-lock path.
     *
     * An ACTIVE account may only be updated for the SAME mailbox (new
     * password/server), and the old credential keeps working until this
     * write; switching mailbox is a disconnect first.
     *
     * @throws LogicException|BusinessEmailConcurrencyException
     */
    public function connectWithCredentials(Business $business, int $actorUserId, BusinessEmailProviderType $provider, string $mailboxEmail, string $credentialBlob): BusinessEmailAccount
    {
        $account = $this->findForBusiness($business);

        if ($account === null) {
            $account = $this->createPending($business, $actorUserId, $provider);
        } elseif ($account->state === BusinessEmailAccountState::Active) {
            if ($account->provider !== $provider || strtolower((string) $account->mailbox_email) !== strtolower($mailboxEmail)) {
                throw new LogicException('An email account is already connected; disconnect it before connecting a different one.');
            }
        }

        $reconnecting = $account->state === BusinessEmailAccountState::Active;

        $this->transition($account, BusinessEmailAccountState::Active, [
            'provider' => $provider->value,
            'connected_by_user_id' => $actorUserId,
            'refresh_token_encrypted' => $credentialBlob,
            'granted_scopes' => null,
            'mailbox_email' => strtolower($mailboxEmail),
            'display_name' => $account->display_name,
            'connected_at' => $reconnecting && $account->connected_at !== null ? $account->connected_at : now(),
            'last_refreshed_at' => now(),
            'failure_classification' => null,
            'inbound_failure' => null,
            'oauth_state_nonce' => null,
            'oauth_state_expires_at' => null,
            'revoked_at' => null,
            'disconnected_at' => null,
            // A different mailbox/server restarts the inbound window.
            'inbound_cursor' => $reconnecting ? $account->inbound_cursor : null,
        ]);

        return $account->fresh();
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
        // Pending = first connect / reconnect. Active = reauthorization of a
        // live connection (additional consent); it keeps working until the
        // verified grant below replaces it.
        if (! in_array($account->state, [BusinessEmailAccountState::Pending, BusinessEmailAccountState::Active], true)) {
            throw new LogicException('Only a pending or active email connection can be completed.');
        }

        $reauthorizing = $account->state === BusinessEmailAccountState::Active;

        // Outside any transaction — a real outbound HTTP request.
        $grant = $this->providers->for($account->provider)->exchangeAuthorizationCode($code);

        if ($grant->refreshToken === null || $grant->refreshToken === '') {
            throw new BusinessEmailProviderException(BusinessEmailFailureCategory::PermanentProviderFailure, 'no_refresh_token');
        }

        // A reauthorization must be for the SAME mailbox; switching mailbox
        // while active is an explicit disconnect first. Nothing is changed.
        if ($reauthorizing && strtolower((string) $grant->accountEmail) !== strtolower((string) $account->mailbox_email)) {
            throw new LogicException('Reauthorize with the same mailbox that is connected, or disconnect it first to connect a different one.');
        }

        $this->transition($account, BusinessEmailAccountState::Active, [
            'refresh_token_encrypted' => $grant->refreshToken,
            'granted_scopes' => $grant->grantedScopes !== null ? substr($grant->grantedScopes, 0, 512) : null,
            'mailbox_email' => $grant->accountEmail,
            'external_account_id' => $grant->accountId !== null ? substr($grant->accountId, 0, 191) : null,
            'display_name' => $grant->displayName !== null ? substr($grant->displayName, 0, 191) : null,
            'connected_at' => $reauthorizing && $account->connected_at !== null ? $account->connected_at : now(),
            'last_refreshed_at' => now(),
            'failure_classification' => null,
            'inbound_failure' => null,
            'oauth_state_nonce' => null,
            'oauth_state_expires_at' => null,
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
