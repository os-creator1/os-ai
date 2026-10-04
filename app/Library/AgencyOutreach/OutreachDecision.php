<?php

namespace App\Library\AgencyOutreach;

/**
 * What the stage machine decided for one prospect reply (contract §4). A plain
 * value: every field is a fact the responder acts on, and nothing here performs
 * the action.
 *
 *   send_stage    send the scripted message `messageNumber` (1..3), answering a FAQ first
 *   resend_link   BOOKING stage only: the calendar link once more (message 3), never twice
 *   answer_only   BOOKING stage: answer the question, no scripted message
 *   opt_out       blacklist + stop (stage 99), send nothing
 *   reject        stage 99, no blacklist row, send nothing
 *   none          nothing to do (terminal, reserved stage, or nothing to say); `reason` says why
 */
final class OutreachDecision
{
    public const SEND_STAGE = 'send_stage';

    public const RESEND_LINK = 'resend_link';

    public const ANSWER_ONLY = 'answer_only';

    public const OPT_OUT = 'opt_out';

    public const REJECT = 'reject';

    public const NONE = 'none';

    public function __construct(
        public readonly string $action,
        public readonly int $stageFrom,
        public readonly int $stageTo,
        public readonly ?int $messageNumber = null,
        public readonly bool $answerFirst = false,
        public readonly bool $scheduleFollowUp = false,
        public readonly string $reason = '',
    ) {
    }

    /** Whether this decision produces an outbound text. */
    public function sends(): bool
    {
        return in_array($this->action, [self::SEND_STAGE, self::RESEND_LINK, self::ANSWER_ONLY], true);
    }

    public function stops(): bool
    {
        return in_array($this->action, [self::OPT_OUT, self::REJECT], true);
    }
}
