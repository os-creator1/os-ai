<?php

namespace App\Library\AgencyOutreach;

/**
 * Two deterministic reads that keep a scripted message from landing badly: does a message
 * read as if the prospect had said YES, and is a reply a plain "no". A "no" is never
 * answered with "Great!" or a booking pitch; the owner is told to reword the step instead.
 */
final class OutreachStepTone
{
    private const POSITIVE_OPENERS = '/^\s*(great|awesome|perfect|excellent|fantastic|wonderful|amazing|glad|love that|nice|sweet|good to hear|thanks for (getting back|replying|responding|your interest))\b/i';

    /** A bare refusal that is not already a recognised rejection ("hey, no", "nope", "not really"). */
    private const BARE_NEGATIVE = '/^(?:(?:hey|hi|hello|um|uh|well|oh|ah)\s+)?(?:no|nope|nah|no way|not really|not at the moment|not right now|nothing)$/u';

    public static function assumesYes(string $messageText): bool
    {
        return preg_match(self::POSITIVE_OPENERS, $messageText) === 1;
    }

    public static function isBareNegative(string $reply): bool
    {
        $text = mb_strtolower(trim($reply));
        $text = str_replace(["'", "\u{2019}"], '', $text);
        $text = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? '');

        return $text !== '' && preg_match(self::BARE_NEGATIVE, $text) === 1;
    }

    /** Whether a message carries the booking pitch (the calendar link) -- wrong after a "no". */
    public static function isBookingPitch(string $stepBody): bool
    {
        return OutreachSequence::stepHasCalendarLink(['body' => $stepBody]);
    }
}
