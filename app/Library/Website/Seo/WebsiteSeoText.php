<?php

namespace App\Library\Website\Seo;

/**
 * Shortens generated titles and descriptions without leaving a visibly cut-off phrase.
 *
 * `Str::limit($text, 160, '')` and a trailing "…" both leave readers (and search results) looking at half a sentence. The
 * limits used here are display guidance, not ranking rules — Google may rewrite either text — so the goal is only that what
 * we write reads as finished: whole sentences when they fit, else whole words with no dangling joiner, never an ellipsis.
 */
final class WebsiteSeoText
{
    /** Words that cannot end a phrase. */
    private const DANGLING = ['a', 'an', 'and', 'or', 'the', 'of', 'in', 'for', 'to', 'with', 'by', 'at', 'on', 'from', 'across', 'throughout', 'is', 'are', '&', '-', '|', '–', '—'];

    public static function fit(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if ($text === '' || mb_strlen($text) <= $max) {
            return $text;
        }

        // The longest run of whole sentences that fits (a description).
        $window = mb_substr($text, 0, $max + 1);
        if (preg_match('/^(.{1,' . $max . '}[.!?])(?=\s|$)/us', $window, $match) === 1 && mb_strlen($match[1]) >= (int) ($max * 0.4)) {
            return $match[1];
        }

        // Otherwise whole words only (a title, or one very long sentence).
        $cut = mb_substr($text, 0, $max);
        if (! preg_match('/\s/u', mb_substr($text, $max, 1))) {
            $cut = preg_replace('/\s+\S*$/u', '', $cut) ?? $cut;
        }

        $cut = rtrim($cut, " \t,;:-–—|&");

        // Drop a joining word or separator left at the end ("... weddings and").
        while (preg_match('/^(.*\s)(\S+)$/us', $cut, $m) === 1 && in_array(mb_strtolower($m[2]), self::DANGLING, true)) {
            $cut = rtrim($m[1], " \t,;:-–—|&");
        }

        return $cut;
    }

    /** True when a stored description visibly stops mid-thought: an ellipsis, or no closing punctuation at all after a cut. */
    public static function looksCutOff(string $text): bool
    {
        $text = trim($text);

        return $text !== '' && (str_ends_with($text, '...') || str_ends_with($text, '…'));
    }
}
