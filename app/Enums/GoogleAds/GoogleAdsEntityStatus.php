<?php

namespace App\Enums\GoogleAds;

/**
 * Contract §2 / §4 — campaign / ad group / criterion status as Google
 * reports it. `Unknown` is what we store for any value Google adds that we
 * do not recognise; it is never written BACK to Google. Only Enabled and
 * Paused are ever sent in a mutation (§6: `REMOVED` is never written).
 */
enum GoogleAdsEntityStatus: string
{
    case Enabled = 'ENABLED';
    case Paused = 'PAUSED';
    case Removed = 'REMOVED';
    case Unknown = 'UNKNOWN';

    public static function fromProvider(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom(strtoupper($value)) ?? self::Unknown) : self::Unknown;
    }

    /** The only two states this module may request. */
    public function isWritable(): bool
    {
        return $this === self::Enabled || $this === self::Paused;
    }
}
