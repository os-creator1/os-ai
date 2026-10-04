<?php

namespace App\Library\GoogleAds\Sync;

use App\Exceptions\GoogleAds\GoogleAdsProviderException;

/**
 * Google Ads Module V1 contract §5 — the CLOSED set of codes a sync run may
 * leave on `google_ads_sync_runs.failure_code` and
 * `google_ads_accounts.last_sync_failure_code`.
 *
 * Provider failures reuse the provider exception's own classification (itself
 * a closed set). Nothing here is ever a raw message, token or provider
 * payload, so any stored code can be shown to the owner through label().
 */
final class GoogleAdsSyncFailureCode
{
    /** A report hit its page / row cap: the run is `partial`. */
    public const ROW_CAP = 'row_cap';

    /** Google reports a currency different from the stored account currency. */
    public const CURRENCY_CHANGED = 'currency_changed';

    /** Re-check at run time refused (inactive Business / workspace, no entitlement, no live connection). */
    public const NOT_SYNCABLE = 'not_syncable';

    /** Another sync holds the account claim. */
    public const ALREADY_RUNNING = 'already_running';

    /** A queued run that never started (its job was lost) was retired. */
    public const EXPIRED = 'expired';

    /** A running run whose claim went stale (worker died) was retired. */
    public const ABANDONED = 'abandoned';

    /** An unexpected local error; the exception itself is rethrown to the queue. */
    public const INTERNAL_ERROR = 'internal_error';

    /** Deferred by Google (429) or by our own call budget; never retried inline. */
    public const RATE_LIMITED = GoogleAdsProviderException::RATE_LIMITED;

    /**
     * The code stored for a provider failure. A budget refusal is reported to
     * the owner exactly like a rate limit (both mean "try again later"); the
     * ledger keeps the precise classification.
     */
    public static function forProviderException(GoogleAdsProviderException $exception): string
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
            self::ROW_CAP => 'Google returned more data than we load in one refresh, so some rows are missing.',
            self::CURRENCY_CHANGED => 'The Google Ads account currency changed. Reselect the account to continue.',
            self::NOT_SYNCABLE => 'This account cannot be refreshed right now.',
            self::ALREADY_RUNNING => 'A refresh is already in progress.',
            self::EXPIRED => 'A scheduled refresh never started.',
            self::ABANDONED => 'A refresh was interrupted before it finished.',
            self::INTERNAL_ERROR => 'The last refresh failed unexpectedly.',
            self::RATE_LIMITED => 'Google is rate limiting requests. The next refresh will retry.',
            GoogleAdsProviderException::INVALID_GRANT => 'Google has revoked this connection. Reconnect to continue.',
            GoogleAdsProviderException::ACCESS_DENIED => 'Google denied access for this account.',
            GoogleAdsProviderException::PROVIDER_UNAVAILABLE => 'Google Ads is temporarily unavailable.',
            GoogleAdsProviderException::TIMEOUT => 'Google Ads did not respond in time.',
            GoogleAdsProviderException::UNEXPECTED_RESPONSE => 'Google returned an unexpected response.',
            GoogleAdsProviderException::NOT_FOUND => 'Google could not find this account.',
            GoogleAdsProviderException::VALIDATION => 'Google rejected the request.',
            GoogleAdsProviderException::BUDGET_EXHAUSTED => 'Google Ads request limit reached. The next refresh will retry.',
        ];
    }
}
