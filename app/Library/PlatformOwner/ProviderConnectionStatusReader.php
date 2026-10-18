<?php

namespace App\Library\PlatformOwner;

use App\Enums\GoogleBusinessProfile\GoogleConnectionProduct;
use App\Models\BusinessEmailAccount;
use App\Models\BusinessGoogleConnection;
use App\Models\BusinessStripeConnection;
use App\Models\ExternalCalendarConnection;
use Illuminate\Support\Collection;

/**
 * Platform Owner / Admin V1 — read-only provider connection STATUS for the
 * support pages (Stripe, Business Email, Google Business Profile, Calendar).
 *
 * SECRETS NEVER ENTER MEMORY HERE. Every query below names its columns
 * explicitly and none of those lists contains a credential column
 * (`refresh_token_encrypted`, `oauth_state_nonce`, `notification_*`,
 * `sync_cursor`). `->select()` is the guarantee, not `$hidden`: a column the
 * query does not select can neither be rendered by a view nor serialized by
 * accident, so there is no ciphertext to decrypt (the models' `encrypted`
 * cast is never triggered) and nothing to leak if a template is edited
 * later.
 *
 * Batch shape: each method takes the whole page's ids and issues ONE query,
 * returning rows keyed for the caller. No method runs a query per Business.
 * Calendar connections belong to a USER, not a Business, so that one is
 * keyed by user id.
 */
final class ProviderConnectionStatusReader
{
    private const STRIPE_COLUMNS = [
        'id', 'business_id', 'stripe_account_id', 'status', 'charges_enabled', 'payouts_enabled',
        'details_submitted', 'requirements_disabled_reason', 'connected_at', 'disconnected_at', 'last_synced_at',
    ];

    private const EMAIL_COLUMNS = [
        'id', 'business_id', 'provider', 'state', 'mailbox_email', 'display_name',
        'connected_at', 'disconnected_at', 'revoked_at', 'last_refreshed_at', 'failure_classification',
    ];

    private const GOOGLE_COLUMNS = [
        'id', 'business_id', 'product', 'state', 'google_account_email',
        'connected_at', 'disconnected_at', 'revoked_at', 'last_refreshed_at', 'failure_classification',
    ];

    private const CALENDAR_COLUMNS = [
        'id', 'user_id', 'provider', 'state', 'external_account_email', 'last_synced_at',
        'last_sync_failure_at', 'sync_failure_count', 'failure_classification',
        'connected_at', 'disconnected_at', 'revoked_at', 'last_refreshed_at',
    ];

    /**
     * The newest Stripe connection row per Business (a Business can hold
     * historical disconnected rows; the newest is its current state).
     *
     * @param array<int, int> $businessIds
     * @return Collection<int, BusinessStripeConnection> keyed by business_id
     */
    public function stripeFor(array $businessIds): Collection
    {
        if ($businessIds === []) {
            return collect();
        }

        return BusinessStripeConnection::query()
            ->select(self::STRIPE_COLUMNS)
            ->whereIn('business_id', $businessIds)
            ->orderBy('id')
            ->get()
            ->keyBy('business_id');
    }

    /**
     * @param array<int, int> $businessIds
     * @return Collection<int, BusinessEmailAccount> keyed by business_id (one account per Business)
     */
    public function emailFor(array $businessIds): Collection
    {
        if ($businessIds === []) {
            return collect();
        }

        return BusinessEmailAccount::query()
            ->select(self::EMAIL_COLUMNS)
            ->whereIn('business_id', $businessIds)
            ->get()
            ->keyBy('business_id');
    }

    /**
     * @param array<int, int> $businessIds
     * @return Collection<int, BusinessGoogleConnection> keyed by business_id
     */
    public function googleFor(array $businessIds): Collection
    {
        if ($businessIds === []) {
            return collect();
        }

        return BusinessGoogleConnection::query()
            ->select(self::GOOGLE_COLUMNS)
            ->where('product', GoogleConnectionProduct::BusinessProfile->value)
            ->whereIn('business_id', $businessIds)
            ->orderBy('id')
            ->get()
            ->keyBy('business_id');
    }

    /**
     * @param array<int, int> $userIds
     * @return Collection<int, Collection<int, ExternalCalendarConnection>> keyed by user_id
     */
    public function calendarFor(array $userIds): Collection
    {
        if ($userIds === []) {
            return collect();
        }

        return ExternalCalendarConnection::query()
            ->select(self::CALENDAR_COLUMNS)
            ->whereIn('user_id', $userIds)
            ->orderBy('id')
            ->get()
            ->groupBy('user_id');
    }
}
