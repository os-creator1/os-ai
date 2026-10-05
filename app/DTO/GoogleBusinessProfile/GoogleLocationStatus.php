<?php

namespace App\DTO\GoogleBusinessProfile;

use App\Enums\GoogleBusinessProfile\GoogleLocationHealth;
use Carbon\CarbonInterface;

/**
 * Contract 18 §9.2 — one accessible Location's read-only Google Business
 * Profile status, as handed to a consumer (SEO) that must never touch
 * Google or the GBP tables itself.
 *
 * Presentation facts only. Nothing here is a provider identifier, a token,
 * a raw Google payload or a street address. The two mirror-derived fields
 * are null whenever the mirror is absent or expired (GBP contract §13): an
 * expired mirror is ABSENT to every consumer, never stale-but-shown.
 *
 * `mirrorName` / `mirrorPhone` / `mirrorWebsite` are the NAP-style facts the
 * mirror holds (never a street address). Like every mirror-derived field they
 * are null unless the mirror is fresh, so a consumer that compares them
 * (Citations) does so at read time and persists nothing — Google content may
 * not be stored beyond the mirror's own retention.
 *
 * `health` is the derived platform vocabulary stored in its own column; it
 * is operational binding metadata that survives the mirror purge (GBP
 * contract §13.7), so it is present whenever the Location is bound.
 *
 * Because `health` outlives the mirror it can be OLD. `healthAsOf` is when the
 * binding was last synced from Google (falling back to when the mirror was
 * fetched) and `healthIsStale` is true once that is outside the freshness
 * window (the mirror is no longer fresh / older than the policy ceiling), so a
 * consumer that prints `health` can say "as of <date>" and that it may be out
 * of date. Computed at read time, never stored.
 *
 * `napMismatchFields` names the GBP comparison rows that currently differ (the
 * same rows `napMismatchCount` counts), so a count is never shown without
 * saying which details; empty unless the mirror is fresh.
 */
final class GoogleLocationStatus
{
    public function __construct(
        public readonly int $locationId,
        public readonly string $locationUid,
        public readonly string $locationName,
        public readonly bool $bound,
        public readonly ?string $connectionState,
        public readonly ?GoogleLocationHealth $health,
        public readonly bool $mirrorIsFresh,
        public readonly ?string $newReviewUri,
        public readonly ?int $napMismatchCount,
        public readonly ?string $mirrorName = null,
        public readonly ?string $mirrorPhone = null,
        public readonly ?string $mirrorWebsite = null,
        public readonly ?CarbonInterface $healthAsOf = null,
        public readonly bool $healthIsStale = false,
        public readonly array $napMismatchFields = [],
    ) {
    }
}
