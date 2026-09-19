<?php

namespace App\Enums\Seo;

/**
 * Contract 18 §8.5 — the user's own workflow status for one listing.
 *
 * Persisted values. It is set ONLY by an explicit user action: no comparison
 * result, mismatch or "needs correction" hint ever changes it.
 */
enum SeoCitationStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Listed = 'listed';
    case NeedsCorrection = 'needs_correction';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not started',
            self::InProgress => 'In progress',
            self::Listed => 'Listed',
            self::NeedsCorrection => 'Needs correction',
            self::NotApplicable => 'Not applicable',
        };
    }
}
