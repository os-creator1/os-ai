<?php

namespace App\Library\GoogleBusinessProfile;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * GBP Slice A contract §13.1 / §13.4 — the Google-content retention rule,
 * and the mandatory correction this slice carries (Appendix A, A-1).
 *
 * Google's API policy states that stored Content "must be stored
 * temporarily for no more than 30 calendar days", "must be stored
 * securely" and "cannot be manipulated or aggregated in any way".
 *
 * FAIL-CLOSED DIRECTION IS DELIBERATELY INVERTED relative to
 * App\Jobs\Usage\PurgeExpiredWebhookPayloads::resolvedRetentionDays(),
 * whose validation idiom this copies. For webhook payloads, failing closed
 * means DO NOT PURGE, because purging destroys evidence. For Google
 * Content the policy risk runs the other way: keeping Content too long
 * breaches Google's terms. So an absent, blank, non-digit, zero, negative
 * OR ABOVE-CEILING configuration all resolve to an effective TTL of ZERO
 * DAYS — every mirror is treated as expired on read and purged on the next
 * sweep.
 *
 * A value above the 30-day ceiling is a misconfiguration, not a request:
 * it fails closed, it is NOT clamped.
 *
 * A 0-day effective TTL leaves the product usable: a manual refresh still
 * fetches and renders inside the request; the mirror is simply not reused
 * across requests.
 */
final class GoogleBusinessProfileRetention
{
    /**
     * Contract §13.1 — the hard policy ceiling. No code path may produce a
     * mirror_expires_at more than this many calendar days after
     * mirror_fetched_at.
     */
    public const MAX_MIRROR_RETENTION_DAYS = 30;

    /**
     * The effective mirror TTL in days: 0 when unset or invalid, otherwise
     * the configured value in [1, 30].
     */
    public function mirrorRetentionDays(): int
    {
        $configured = config('google_business_profile.mirror.retention_days');

        if (! is_int($configured) && ! (is_string($configured) && ctype_digit($configured))) {
            return 0;
        }

        $days = (int) $configured;

        if ($days < 1 || $days > self::MAX_MIRROR_RETENTION_DAYS) {
            return 0;
        }

        return $days;
    }

    /**
     * Contract §13.1 — mirror_expires_at is always
     * fetchedAt + min(configured, 30) calendar days. `addDays()` on a
     * Carbon instance is calendar-day arithmetic, which is what the policy
     * text says.
     */
    public function mirrorExpiresAt(CarbonInterface $fetchedAt): Carbon
    {
        $days = min($this->mirrorRetentionDays(), self::MAX_MIRROR_RETENTION_DAYS);

        return Carbon::instance($fetchedAt->toDateTime())->addDays($days);
    }

    /**
     * Contract §13.6 — the LEDGER fails closed in the OPPOSITE direction:
     * absent or invalid means RETAIN, because business_google_operations
     * contains no Google Content. Returns null when no purge should
     * happen. No ledger purge job is scheduled in Slice A; this exists so
     * a later operations pass has an unambiguous target.
     */
    public function ledgerRetentionDays(): ?int
    {
        $configured = config('google_business_profile.ledger.retention_days');

        if (! is_int($configured) && ! (is_string($configured) && ctype_digit($configured))) {
            return null;
        }

        $days = (int) $configured;

        return $days > 0 ? $days : null;
    }
}
