<?php

namespace App\Enums\Seo;

/**
 * Contract 18 §8.6 — the ledger row's outcome. `Reviewed` is SELF-REPORTED
 * by the user (nothing observes a Google review), and is labelled as such
 * wherever it is shown. `Declined` frees the Contact from the cooldown.
 */
enum SeoReviewRequestStatus: string
{
    case Requested = 'requested';
    case Reviewed = 'reviewed';
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Asked',
            self::Reviewed => 'Reviewed (self-reported)',
            self::Declined => 'Declined',
        };
    }
}
