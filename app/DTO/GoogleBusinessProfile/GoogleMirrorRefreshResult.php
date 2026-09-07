<?php

namespace App\DTO\GoogleBusinessProfile;

/**
 * GBP Slice A contract §13.4 — the outcome of one manual or scheduled
 * mirror refresh (correction pass item 8).
 *
 * Why this exists: with an effective TTL of ZERO days the contract permits
 * a manual refresh to fetch and render Google data WITHIN THAT REQUEST,
 * but forbids persisting anything reusable across requests. The previous
 * implementation stored an already-expired mirror and then redirected, so
 * the redirected request could never show what was fetched — the feature
 * was unusable at its own documented default.
 *
 * Carrying the freshly-fetched profile back to the caller lets the manual
 * path render it EPHEMERALLY, in the same response, while nothing reusable
 * is written. The next GET correctly shows "refresh required".
 *
 * $persisted says whether reusable Google Content was written:
 *   true  — a positive TTL is configured; the mirror is stored and the
 *           following request will render it normally.
 *   false — TTL is zero; only non-Content operational state was written,
 *           and this object is the only place the Content exists.
 */
final readonly class GoogleMirrorRefreshResult
{
    public function __construct(
        public GoogleLocationProfile $profile,
        public GoogleVoiceOfMerchantState $state,
        public bool $persisted,
    ) {
    }

    /**
     * The bounded mirror payload for ephemeral rendering. Identical in
     * shape to what would have been persisted, so the comparison behaves
     * the same either way.
     *
     * @return array<string, mixed>
     */
    public function mirror(): array
    {
        return $this->profile->toMirrorArray();
    }
}
