<?php

namespace App\Enums\Voice;

/**
 * The life of one call. `rank` is what makes provider events safe to replay and
 * to receive out of order: a transition is applied only to a strictly higher rank,
 * so a late "ringing" can never pull a finished call back, and a confirmed final
 * outcome is never replaced by a weaker one.
 *
 * `Unconfirmed` is the one honest in-between: the browser said the call ended but
 * Telnyx has not (yet) confirmed an outcome. A real provider event outranks it.
 */
enum VoiceCallStatus: string
{
    case Initiating = 'initiating';
    case Ringing = 'ringing';
    case Answered = 'answered';
    case Unconfirmed = 'unconfirmed';
    case Completed = 'completed';
    case Missed = 'missed';
    case Declined = 'declined';
    case Busy = 'busy';
    case NoAnswer = 'no_answer';
    case Canceled = 'canceled';
    case Failed = 'failed';

    public function rank(): int
    {
        return match ($this) {
            self::Initiating => 0,
            self::Ringing => 1,
            self::Answered => 2,
            self::Unconfirmed => 3,
            default => 4,
        };
    }

    public function isFinal(): bool
    {
        return $this->rank() >= 4;
    }

    public function label(): string
    {
        return match ($this) {
            self::Initiating => 'Dialing',
            self::Ringing => 'Ringing',
            self::Answered => 'In progress',
            self::Unconfirmed => 'Ended (not yet confirmed)',
            self::Completed => 'Completed',
            self::Missed => 'Missed',
            self::Declined => 'Declined',
            self::Busy => 'Busy',
            self::NoAnswer => 'No answer',
            self::Canceled => 'Canceled',
            self::Failed => 'Failed',
        };
    }
}
