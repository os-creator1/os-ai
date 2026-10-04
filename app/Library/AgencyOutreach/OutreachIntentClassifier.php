<?php

namespace App\Library\AgencyOutreach;

/**
 * Deterministic placement of a prospect's question (contract §5). No model: a
 * known FAQ is answered from the Agency's own field and never costs an AI call.
 *
 * Returns one of the INTENT_* constants. `question_other` means "this is a
 * question the FAQ cannot place" — the only case where the single bounded model
 * call may be made. `none` means no question at all (a plain reply such as "ok").
 *
 * Order matters and is the specificity order: the narrow, answerable questions
 * (how did you find me, are you based in X, what is your name) come before the
 * broad ones (what do you do), and a bare question mark is the last resort.
 */
final class OutreachIntentClassifier
{
    public const PRICING = 'pricing';

    public const LOCATION = 'location';

    public const FOUND_YOU = 'found_you';

    public const WEBSITE = 'website';

    public const NAME = 'name';

    public const WHAT_WE_DO = 'what_we_do';

    public const CLARIFY = 'clarify';

    public const QUESTION_OTHER = 'question_other';

    public const NONE = 'none';

    /** Intents whose answer is a field of the Agency's script (or the field-less name line). */
    public const FAQ_INTENTS = [
        self::PRICING, self::LOCATION, self::FOUND_YOU, self::WEBSITE, self::NAME, self::WHAT_WE_DO, self::CLARIFY,
    ];

    private const DAYS = 'monday|tuesday|wednesday|thursday|friday|saturday|sunday|weekend|weekday|weekdays|tonight|tomorrow';

    public static function classify(string $body): string
    {
        $text = self::normalise($body);

        if ($text === '') {
            return self::NONE;
        }

        $isQuestion = str_contains($body, '?') || preg_match('/^(what|how|who|where|when|why|which|do|does|did|is|are|can|could|will|would|whats|hows)\b/u', $text) === 1;

        if (preg_match('/\b(how did you (find|get|know|hear)|how do you know|where did you (get|find|hear)|howd you (find|get)|who gave you|how did you get my)\b/u', $text) === 1) {
            return self::FOUND_YOU;
        }

        if (preg_match('/\b(how much|price|prices|pricing|cost|costs|fee|fees|charge|charges|commission|percent|percentage|rates|rate card|expensive|what do you charge)\b/u', $text) === 1) {
            return self::PRICING;
        }

        if (preg_match('/\b(are you (guys )?(based|located|local|from|in)|where are you|where re you|where is (this|your)|what (city|area|state|town|region)|which (city|area|state|town|region)|do you (serve|cover|work in|operate)|located|based in|where (are|is) (you|your (office|company|business)))\b/u', $text) === 1) {
            return self::LOCATION;
        }

        if (preg_match('/\b(website|web site|webpage|web page|url|your site|a site|link to (your|the) (site|page)|do you have a (site|page|link))\b/u', $text) === 1) {
            return self::WEBSITE;
        }

        if (preg_match('/\b(who is this|who are you|whos this|who s this|who dis|who is calling|who is texting|what is your (company|business|agency)?\s*name|whats your (company |business |agency )?name|what company|which company|what is the name|your name|company name)\b/u', $text) === 1) {
            return self::NAME;
        }

        if (preg_match('/\b(what do you do|what does (this|it) (do|mean)|what is this( about| for)?|whats this( about| for)?|what is it|how does (it|this) work|how do you work|what are you (offering|selling)|what services|what do you offer|what exactly|tell me more|more info|more information|explain|what is the offer|whats the offer|what s the offer|what s this)\b/u', $text) === 1) {
            return self::WHAT_WE_DO;
        }

        // The legacy "event" misunderstanding: the prospect reads the opener as a request
        // to book THEM (a date, a weekend, an event). Answered with the Agency's generic
        // clarification, never a model.
        if (preg_match('/\b(' . self::DAYS . '|date|dates|event|events|wedding|weddings|party|parties|occasion|booking|book (me|us)|availability|available|reserve|reservation)\b/u', $text) === 1 && $isQuestion) {
            return self::CLARIFY;
        }

        return $isQuestion ? self::QUESTION_OTHER : self::NONE;
    }

    /**
     * A reply that proposes or asks about a time ("tomorrow at 3", "does Tuesday work",
     * "can we do 2pm"). In the BOOKING stage this earns the calendar link once more.
     */
    public static function isSchedulingReply(string $body): bool
    {
        $text = self::normalise($body);

        if ($text === '') {
            return false;
        }

        return preg_match('/\b(' . self::DAYS . '|today|next week|this week|morning|afternoon|evening|noon|schedule|scheduling|reschedule|what time|any time|anytime|call me|give me a call|when can|can we (do|talk|speak|chat)|works for me|i am free|im free|free (at|on|after|before)|\d{1,2}\s?(am|pm)|\d{1,2}:\d{2}|o clock|oclock)\b/u', $text) === 1;
    }

    private static function normalise(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = str_replace(["'", "\u{2019}", "\u{2018}", '`'], '', $text);
        $text = preg_replace('/[^\p{L}\p{N}:%]+/u', ' ', $text) ?? '';

        return trim($text);
    }
}
