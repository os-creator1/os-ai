<?php

namespace App\Library\AgencyOutreach;

use App\Enums\AgencyProspecting\AgencyProspectStage;

/**
 * The fixed sales process (contract §4) as ONE pure function: (current stage,
 * what the prospect said) -> what to do. No clock, no database, no model — which
 * is what lets it be tested exhaustively and trusted.
 *
 *   1  INTRO       reply -> message 1 -> stage 2
 *   2              reply -> message 2 (the call ask) -> stage 3
 *   3  CALL_ASK    reply -> message 3 (the calendar link) -> stage 4, one follow-up scheduled
 *   4  BOOKING     no scripted message: answer a FAQ only; a scheduling/time reply gets the
 *                  link once more (once ever)
 *   5              reserved (conversational scheduling is not part of V1): nothing
 *   6 / 99         terminal: never replies
 *
 * Opt-out and rejection are decided here too, so there is exactly one place that
 * says what a "no" does. A soft "no" ends the conversation only once message 1 has
 * been sent (stage >= 2); before that it is an ordinary reply, as in the legacy script.
 *
 * Applying the decision (locking, compare-and-set, sending) is the responder's job.
 */
final class OutreachStageMachine
{
    /**
     * @param  OutreachSequence|null  $sequence  the Agency's ordered steps (null = the legacy fixed three)
     * @param  array<int, string>  $sent  step ids this prospect already received
     * @param  bool  $faqMatched  an approved answer covers this reply (beyond the classifier's own FAQ intents)
     */
    public static function decide(
        AgencyProspectStage|int $stage,
        OutreachClassification $reply,
        ?OutreachSequence $sequence = null,
        array $sent = [],
        bool $faqMatched = false,
    ): OutreachDecision {
        $current = $stage instanceof AgencyProspectStage ? $stage->value : $stage;

        if ($current === AgencyProspectStage::Booked->value || $current === AgencyProspectStage::StoppedOptOut->value) {
            return new OutreachDecision(OutreachDecision::NONE, $current, $current, reason: 'terminal_stage');
        }

        if ($reply->stop === OutreachStopClassifier::OPT_OUT) {
            return new OutreachDecision(OutreachDecision::OPT_OUT, $current, AgencyProspectStage::StoppedOptOut->value, reason: 'opted_out');
        }

        if ($reply->stop === OutreachStopClassifier::REJECTED_HARD) {
            return new OutreachDecision(OutreachDecision::REJECT, $current, AgencyProspectStage::StoppedOptOut->value, reason: 'rejected');
        }

        if ($reply->stop === OutreachStopClassifier::REJECTED_SOFT && $current >= AgencyProspectStage::Qualification->value) {
            return new OutreachDecision(OutreachDecision::REJECT, $current, AgencyProspectStage::StoppedOptOut->value, reason: 'rejected');
        }

        $answer = $reply->hasFaq() || $faqMatched;

        if ($sequence !== null) {
            return self::sequenced($current, $reply, $sequence, $sent, $answer);
        }

        return match ($current) {
            AgencyProspectStage::Introduction->value => new OutreachDecision(OutreachDecision::SEND_STAGE, 1, 2, 1, $answer),
            AgencyProspectStage::Qualification->value => new OutreachDecision(OutreachDecision::SEND_STAGE, 2, 3, 2, $answer),
            AgencyProspectStage::CallInvitation->value => new OutreachDecision(OutreachDecision::SEND_STAGE, 3, 4, 3, $answer, scheduleFollowUp: true),
            AgencyProspectStage::BookingLinkSent->value => self::booking($reply, $answer),
            default => new OutreachDecision(OutreachDecision::NONE, $current, $current, reason: 'stage_not_automated'),
        };
    }

    /**
     * The dynamic process: the next unsent reply step is sent whatever its position,
     * and the stage only records how far the conversation has got (a message carrying
     * the calendar link means "link sent").
     *
     * @param  array<int, string>  $sent
     */
    private static function sequenced(int $current, OutreachClassification $reply, OutreachSequence $sequence, array $sent, bool $answer): OutreachDecision
    {
        $booking = AgencyProspectStage::BookingLinkSent->value;
        $step = $sequence->nextReplyStep($sent);

        if ($current === $booking) {
            if ($reply->schedulingReply && ! $reply->linkRepeatUsed) {
                $link = $sequence->linkStep($sent);

                if ($link !== null) {
                    return new OutreachDecision(OutreachDecision::RESEND_LINK, $booking, $booking, null, $answer, stepId: $link['id']);
                }
            }

            if ($step !== null) {
                return new OutreachDecision(OutreachDecision::SEND_STAGE, $booking, $booking, null, $answer, stepId: $step['id']);
            }

            return $answer
                ? new OutreachDecision(OutreachDecision::ANSWER_ONLY, $booking, $booking, null, true)
                : new OutreachDecision(OutreachDecision::NONE, $booking, $booking, reason: 'no_scripted_reply');
        }

        if (! in_array($current, [AgencyProspectStage::Introduction->value, AgencyProspectStage::Qualification->value, AgencyProspectStage::CallInvitation->value], true)) {
            return new OutreachDecision(OutreachDecision::NONE, $current, $current, reason: 'stage_not_automated');
        }

        if ($step === null) {
            return $answer
                ? new OutreachDecision(OutreachDecision::ANSWER_ONLY, $current, $current, null, true)
                : new OutreachDecision(OutreachDecision::NONE, $current, $current, reason: 'sequence_complete');
        }

        $delivered = count(array_filter($sent, static fn (string $id): bool => ($sequence->find($id)['advance_on'] ?? OutreachSequence::REPLY) !== OutreachSequence::TIME)) + 1;
        $to = OutreachSequence::stepHasCalendarLink($step) ? $booking : max($current, min(3, $delivered + 1));

        return new OutreachDecision(OutreachDecision::SEND_STAGE, $current, $to, null, $answer, stepId: $step['id']);
    }

    private static function booking(OutreachClassification $reply, bool $answer): OutreachDecision
    {
        $booking = AgencyProspectStage::BookingLinkSent->value;

        if ($reply->schedulingReply && ! $reply->linkRepeatUsed) {
            return new OutreachDecision(OutreachDecision::RESEND_LINK, $booking, $booking, 3, $answer);
        }

        if ($answer) {
            return new OutreachDecision(OutreachDecision::ANSWER_ONLY, $booking, $booking, null, true);
        }

        return new OutreachDecision(OutreachDecision::NONE, $booking, $booking, reason: 'no_scripted_reply');
    }
}
