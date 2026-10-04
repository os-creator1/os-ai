<?php

namespace App\Enums\Seo;

/**
 * Citations V1 — what Business OS can REALLY do for a directory.
 *
 *  - Connected      an authorized official connection supplies listing facts
 *                   (today only the Google row, read from the GBP read model;
 *                   never a stored directory).
 *  - AutomaticCheck an official API reader runs on a schedule. No directory
 *                   has one today; the value is reserved for the seam.
 *  - Assisted       the official claim/manage page is known; the owner claims
 *                   it there and records what it shows. Nothing is checked.
 *  - Manual         the owner supplies everything (every custom directory).
 *
 * "Checked automatically" may only be said for Connected/AutomaticCheck rows
 * that actually fetched data. Assisted and Manual are "Manually tracked".
 */
enum SeoDirectoryTrackingMode: string
{
    case Connected = 'connected';
    case AutomaticCheck = 'automatic_check';
    case Assisted = 'assisted';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            self::AutomaticCheck => 'Checked automatically',
            self::Assisted => 'Guided setup',
            self::Manual => 'Manually tracked',
        };
    }

    public function isAutomatic(): bool
    {
        return $this === self::Connected || $this === self::AutomaticCheck;
    }
}
