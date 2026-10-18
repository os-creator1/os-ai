<?php

namespace App\Library\AgencyOutreach;

/**
 * The deterministic reading of one prospect reply, everything the stage machine
 * needs and nothing it does not: the stop class, the question intent, whether the
 * reply is a scheduling/time reply, and whether the one-time calendar-link repeat
 * has already been spent for this prospect.
 */
final class OutreachClassification
{
    public function __construct(
        public readonly string $stop = OutreachStopClassifier::NONE,
        public readonly string $intent = OutreachIntentClassifier::NONE,
        public readonly bool $schedulingReply = false,
        public readonly bool $linkRepeatUsed = false,
    ) {
    }

    public static function of(string $body, bool $linkRepeatUsed = false): self
    {
        return new self(
            OutreachStopClassifier::classify($body),
            OutreachIntentClassifier::classify($body),
            OutreachIntentClassifier::isSchedulingReply($body),
            $linkRepeatUsed,
        );
    }

    public function hasFaq(): bool
    {
        return $this->intent !== OutreachIntentClassifier::NONE;
    }
}
