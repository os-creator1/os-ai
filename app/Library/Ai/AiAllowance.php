<?php

namespace App\Library\Ai;

/**
 * What a customer may be told about their included AI right now — a STATE,
 * never a figure. Home (Ask MotionGrove) and Settings -> Billing -> AI usage
 * both read it from AiAllowanceReader, so the two can never disagree.
 *
 * `usable` is the only thing that decides whether the assistant attempts a
 * model call; it is advisory (the gateway still reserves atomically and is the
 * enforcement), but it spares a doomed call and keeps the wording honest.
 */
final readonly class AiAllowance
{
    public const AVAILABLE = 'available';

    public const NEARING_LIMIT = 'nearing_limit';

    /** The whole allowance is spent and the account is on its free trial. */
    public const TRIAL_LIMIT_REACHED = 'trial_limit_reached';

    /** The whole allowance is spent on a paid plan (returns next period). */
    public const LIMIT_REACHED = 'limit_reached';

    /** Only the interactive share is spent; the overall allowance is not. */
    public const INTERACTIVE_LIMIT = 'interactive_limit';

    /** No allowance at all: unassigned, locked, or an ended unconverted trial. */
    public const UNAVAILABLE = 'unavailable';

    public function __construct(
        public string $status,
        public bool $isTrial,
        public string $policyKey,
        public ?string $renewsOn,
    ) {
    }

    public function usable(): bool
    {
        return in_array($this->status, [self::AVAILABLE, self::NEARING_LIMIT], true);
    }

    /**
     * Is an upgrade the truthful remedy? ONLY when a trial's whole included allowance is used up. The interactive share
     * being spent, a trial that has ended, a lock or an unassigned plan are different facts with different words and no
     * upgrade button.
     */
    public function upgradeIsRemedy(): bool
    {
        return $this->status === self::TRIAL_LIMIT_REACHED;
    }
}
