<?php

namespace App\Enums\Seo;

/**
 * Citations dashboard — the ONE status vocabulary a listing row is shown with.
 *
 * DISPLAY ONLY. This is derived at read time from the user's own persisted
 * SeoCitationStatus plus the read-time NAP comparison (SeoCitationRow::
 * displayState()). It is never stored, and it never writes back to
 * SeoCitationStatus: a mismatch makes a row *look* like it needs attention,
 * it does not change what the user recorded (Contract 18 §8.5).
 *
 * Every case is justified by data that exists:
 *  - Accurate       Listed, and every comparable field the user recorded
 *                   matches the business profile (none unchecked, none off).
 *  - Listed         the user marked it Listed; not every field is checked yet.
 *  - NeedsAttention the user marked it "needs correction", or a recorded
 *                   value differs from the business profile.
 *  - InProgress     the user marked it in progress.
 *  - NotStarted     nothing recorded yet (the default).
 *  - NotApplicable  the user marked it not applicable.
 */
enum SeoCitationDisplayState: string
{
    case Accurate = 'accurate';
    case Listed = 'listed';
    case NeedsAttention = 'needs_attention';
    case InProgress = 'in_progress';
    case NotStarted = 'not_started';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::Accurate => 'Accurate',
            self::Listed => 'Listed',
            self::NeedsAttention => 'Needs attention',
            self::InProgress => 'In progress',
            self::NotStarted => 'Not started',
            self::NotApplicable => 'Not applicable',
        };
    }

    /** x-badge variant. "Not started" is neutral/accent, never red. */
    public function variant(): string
    {
        return match ($this) {
            self::Accurate, self::Listed => 'success',
            self::NeedsAttention => 'warning',
            self::InProgress, self::NotStarted => 'accent',
            self::NotApplicable => 'neutral',
        };
    }

    /** x-ds-icon (Lucide) name. */
    public function icon(): string
    {
        return match ($this) {
            self::Accurate => 'circle-check',
            self::Listed => 'check',
            self::NeedsAttention => 'triangle-alert',
            self::InProgress => 'clock',
            self::NotStarted => 'circle-dashed',
            self::NotApplicable => 'minus',
        };
    }

    /** True when the row should surface in the "Needs attention" list. */
    public function isActionable(): bool
    {
        return match ($this) {
            self::NeedsAttention, self::InProgress, self::NotStarted => true,
            default => false,
        };
    }
}
