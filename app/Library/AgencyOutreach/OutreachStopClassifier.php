<?php

namespace App\Library\AgencyOutreach;

/**
 * Deterministic classification of a prospect's reply for opt-out and rejection
 * (contract §9). Never a model, never a guess: this runs BEFORE any reply logic and
 * a hard opt-out cannot be disabled.
 *
 *   opt_out        a carrier-style opt-out. The prospect is blacklisted for the Agency
 *                  Business and never texted again.
 *   rejected_hard  a polite, unambiguous no. Conversation over, no blacklist row.
 *   rejected_soft  a soft no. Ends the conversation ONLY once message 1 has been sent
 *                  (decided by the stage machine, which knows the stage).
 *   none           an ordinary reply.
 *
 * MATCHING IS ON WORD BOUNDARIES of normalised text (case-folded, punctuation
 * collapsed, apostrophes dropped), so "STOP", "Stop!" and "please stop texting me"
 * match while "nonstop" and "unstoppable" never do. A message that contains an
 * opt-out phrase is an opt-out even when it also says "please": when in doubt the
 * prospect is not texted again — the safe error for a stranger's phone.
 */
final class OutreachStopClassifier
{
    public const OPT_OUT = 'opt_out';

    public const REJECTED_HARD = 'rejected_hard';

    public const REJECTED_SOFT = 'rejected_soft';

    public const NONE = 'none';

    /**
     * Carrier keywords. Each is an opt-out ONLY when it is the entire message (the CTIA
     * convention): "end", "cancel" and "quit" are ordinary words inside a sentence
     * ("what is the end goal", "cancel my other booking") and must never blacklist a
     * prospect for good.
     */
    private const OPT_OUT_WHOLE_MESSAGE = [
        'stop', 'stop all', 'stopall', 'unsubscribe', 'end', 'cancel', 'quit', 'opt out', 'optout', 'revoke',
    ];

    /** Unambiguous opt-out phrases, honoured anywhere in the message. */
    private const OPT_OUT_PHRASES = [
        'please stop', 'stop texting', 'stop messaging', 'stop contacting', 'stop sending', 'stop calling',
        'stop emailing', 'stop now', 'stop all', 'stop it', 'stop this', 'stop me', 'stop please',
        'unsubscribe me', 'remove me', 'remove my number',
        'dont text', 'do not text', 'dont contact', 'do not contact', 'dont message', 'do not message',
        'dont call', 'do not call', 'leave me alone', 'wrong number',
        'take me off', 'off your list', 'off this list',
    ];

    private const REJECTED_HARD_PHRASES = [
        'not interested', 'no longer interested', 'am not interested', 'no interest',
        'not interested at all', 'definitely not interested', 'never contact me',
    ];

    private const REJECTED_SOFT_PHRASES = [
        'no thanks', 'no thank you', 'no thx', 'nah', 'im good', 'i am good', 'we are good', 'were good',
        'not now', 'maybe later', 'maybe another time', 'already have', 'not looking', 'no need', 'all set', 'pass for now',
    ];

    public static function classify(string $body): string
    {
        $text = self::normalise($body);

        if ($text === '') {
            return self::NONE;
        }

        // A message that LEADS with "stop" ("Stop, I'm busy", "STOP texting") is an opt-out;
        // "stop by tomorrow" / "stop in" is not.
        $leadsWithStop = preg_match('/^stop(?!\s+(by|in|over|at|the)\b)(\s|$)/u', $text) === 1;

        if ($leadsWithStop || in_array($text, self::OPT_OUT_WHOLE_MESSAGE, true) || self::matchesAny($text, self::OPT_OUT_PHRASES)) {
            return self::OPT_OUT;
        }

        if (self::matchesAny($text, self::REJECTED_HARD_PHRASES)) {
            return self::REJECTED_HARD;
        }

        if (self::matchesAny($text, self::REJECTED_SOFT_PHRASES)) {
            return self::REJECTED_SOFT;
        }

        return self::NONE;
    }

    /** @param list<string> $phrases */
    private static function matchesAny(string $text, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($phrase, '/') . '(?![\p{L}\p{N}])/u', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Lower-case, apostrophes removed ("don't" -> "dont"), every other non-alphanumeric run -> one space. */
    private static function normalise(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = str_replace(["'", "\u{2019}", "\u{2018}", '`'], '', $text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? '';

        return trim($text);
    }
}
