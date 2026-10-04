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
    public static function decide(AgencyProspectStage|int $stage, OutreachClassification $reply): OutreachDecision
    {
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

        $answer = $reply->hasFaq();

        return match ($current) {
            AgencyProspectStage::Introduction->value => new OutreachDecision(OutreachDecision::SEND_STAGE, 1, 2, 1, $answer),
            AgencyProspectStage::Qualification->value => new OutreachDecision(OutreachDecision::SEND_STAGE, 2, 3, 2, $answer),
            AgencyProspectStage::CallInvitation->value => new OutreachDecision(OutreachDecision::SEND_STAGE, 3, 4, 3, $answer, scheduleFollowUp: true),
            AgencyProspectStage::BookingLinkSent->value => self::booking($reply, $answer),
            default => new OutreachDecision(OutreachDecision::NONE, $current, $current, reason: 'stage_not_automated'),
        };
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
