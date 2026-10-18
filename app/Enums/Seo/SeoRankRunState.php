<?php

namespace App\Enums\Seo;

/** Lifecycle of one provider task. Held = submit outcome ambiguous: reserved cost stays counted, never auto-resubmitted. */
enum SeoRankRunState: string
{
    case Scheduled = 'scheduled';
    case Submitting = 'submitting';
    case Submitted = 'submitted';
    case Completed = 'completed';
    case FailedTerminal = 'failed_terminal';
    case Held = 'held';

    public function isOpen(): bool
    {
        return in_array($this, [self::Scheduled, self::Submitting, self::Submitted, self::Held], true);
    }
}
