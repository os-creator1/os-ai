<?php

namespace App\Enums\Website;

/**
 * External Website Audit Mode V1 — the Business's PRIMARY WEBSITE SOURCE.
 *
 * The Website module means "MotionGrove understands and improves your website",
 * not "you must host it with us". `null` (nothing stored, no Website record)
 * means the owner has not chosen yet and is asked; the three values are the
 * three answers. The mode is about the PRIMARY site only, so an `external`
 * Business may still own platform-hosted campaign landing pages later.
 */
enum WebsiteMode: string
{
    case Hosted = 'hosted';
    case External = 'external';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Hosted => 'Built with MotionGrove',
            self::External => 'Existing website',
            self::None => 'Decide later',
        };
    }
}
