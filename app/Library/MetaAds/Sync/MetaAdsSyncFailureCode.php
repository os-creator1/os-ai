<?php

namespace App\Library\MetaAds\Sync;

use App\Exceptions\MetaAds\MetaProviderException;

/**
 * Meta Ads Module V1 contract 24 §6 — the CLOSED set of codes a sync run may
 * leave on `meta_ads_sync_runs.failure_code` and
 * `meta_ads_accounts.last_sync_failure_code` (varchar(32)).
 *
 * Provider failures reuse the provider exception's own classification (itself
 * a closed set). Nothing here is ever a raw message, token or provider
 * payload, so any stored code can be shown to the owner through label().
 */
final class MetaAdsSyncFailureCode
{
    /** A report hit its page / row cap: the run is `partial`. */
    public const ROW_CAP = 'row_cap';

    /** Meta reported API usage at/above the stop threshold: the run stopped cleanly as `partial`. */
    public const USAGE_HIGH = 'usage_high';

    /** Re-check at run time refused (inactive Business / workspace, no entitlement, no live connection). */
    public const NOT_SYNCABLE = 'not_syncable';

    /** Another sync holds the account claim. */
    public const ALREADY_RUNNING = 'already_running';

    /** A queued run that never started (its job was lost) was retired. */
    public const EXPIRED = 'expired';

    /** A running run whose claim went stale (worker died) was retired. */
    public const ABANDONED = 'abandoned';

    /** The ad account / currency was changed, or it was unselected, while the run was in flight (or Meta reports another account / currency). */
    public const ACCOUNT_CHANGED = 'account_changed';

    /** The connection is no longer the one the selection was made under (other Meta user, rebound, inactive). */
    public const CONNECTION_MISMATCH = 'connection_mismatch';

    /** This run's account claim was taken over by another worker (it went stale). */
    public const CLAIM_LOST = 'claim_lost';

    /** An unexpected local error; the exception itself is rethrown to the queue. */
    public const INTERNAL_ERROR = 'internal_error';

    /** Deferred by Meta (throttle) or by our own call budget; never retried inline. */
    public const RATE_LIMITED = MetaProviderException::RATE_LIMITED;

    /**
     * The code stored for a provider failure. A budget refusal is reported to
     * the owner exactly like a throttle (both mean "try again later"); the
     * ledger keeps the precise classification.
     */
    public static function forProviderException(MetaProviderException $exception): string
    {
        return $exception->isDeferrable() ? self::RATE_LIMITED : $exception->classification;
    }

    public static function isKnown(?string $code): bool
    {
        return $code !== null && array_key_exists($code, self::labels());
    }

    /** Owner-facing text for a stored code; anything unrecognised is generic. */
    public static function label(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return self::labels()[$code] ?? 'The last refresh did not complete.';
    }

    /** @return array<string, string> */
    private static function labels(): array
    {
        return [
            self::ROW_CAP => 'Meta returned more data than we load in one refresh, so some rows are missing.',
            self::USAGE_HIGH => 'Meta usage was high, so the refresh stopped early. The next refresh will continue.',
            self::NOT_SYNCABLE => 'This account cannot be refreshed right now.',
            self::ALREADY_RUNNING => 'A refresh is already in progress.',
            self::EXPIRED => 'A scheduled refresh never started.',
            self::ABANDONED => 'A refresh was interrupted before it finished.',
            self::ACCOUNT_CHANGED => 'The Meta ad account was changed during a refresh. The next refresh will start over.',
            self::CONNECTION_MISMATCH => 'The Meta connection no longer matches the selected ad account. Reconnect to continue.',
            self::CLAIM_LOST => 'A refresh was interrupted by another refresh.',
            self::INTERNAL_ERROR => 'The last refresh failed unexpectedly.',
            self::RATE_LIMITED => 'Meta is rate limiting requests. The next refresh will retry.',
            MetaProviderException::TOKEN_EXPIRED => 'Your Meta connection has expired. Reconnect to continue.',
            MetaProviderException::INVALID_TOKEN => 'Meta no longer accepts this connection. Reconnect to continue.',
            MetaProviderException::ACCESS_DENIED => 'Meta denied access for this account.',
            MetaProviderException::PROVIDER_UNAVAILABLE => 'Meta Ads is temporarily unavailable.',
            MetaProviderException::TIMEOUT => 'Meta Ads did not respond in time.',
            MetaProviderException::UNEXPECTED_RESPONSE => 'Meta returned an unexpected response.',
            MetaProviderException::NOT_FOUND => 'Meta could not find this account.',
            MetaProviderException::VALIDATION => 'Meta rejected the request.',
            MetaProviderException::BUDGET_EXHAUSTED => 'Meta Ads request limit reached. The next refresh will retry.',
        ];
    }
}
