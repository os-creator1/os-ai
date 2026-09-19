<?php

namespace App\DTO\GoogleBusinessProfile;

use App\Enums\GoogleBusinessProfile\GoogleLocationHealth;

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
 * `health` is the derived platform vocabulary stored in its own column; it
 * is operational binding metadata that survives the mirror purge (GBP
 * contract §13.7), so it is present whenever the Location is bound.
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
    ) {
    }
}
