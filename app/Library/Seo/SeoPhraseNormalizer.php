<?php

namespace App\Library\Seo;

/**
 * Contract 18 §8.4 — THE single phrase normalization. Stored keywords and the
 * published-content text they are matched against are normalized by this one
 * function, so "the phrase" means the same thing on both sides (and, later, on
 * the Search Console side).
 *
 *   NFKC  →  lower-case  →  collapse whitespace  →  trim
 *
 * No stemming, no stop-word removal, no punctuation stripping, no synonym
 * folding: those would silently make two different phrases equal.
 *
 * Pure and deterministic. Returns '' for input that cannot be normalized
 * (invalid UTF-8, or normalization failure) so a caller can reject it.
 */
final class SeoPhraseNormalizer
{
    public static function normalize(string $phrase): string
    {
        if (! mb_check_encoding($phrase, 'UTF-8')) {
            return '';
        }

        $normalized = \Normalizer::normalize($phrase, \Normalizer::FORM_KC);

        if ($normalized === false) {
            return '';
        }

        $normalized = mb_strtolower($normalized, 'UTF-8');
        $normalized = preg_replace('/[\s\p{Z}]+/u', ' ', $normalized);

        if ($normalized === null) {
            return '';
        }

        return trim($normalized, " \t\n\r\0\x0B");
    }

    /**
     * Whether a phrase carries a search-engine OPERATOR rather than plain words:
     * quotes, "*", "|", a leading minus ("-word"), "word:" prefixes such as
     * "site:", or the words OR / AND in capitals. Such a phrase can never be
     * matched against page text as typed, and the rank provider bills operator
     * queries at several times the price, so it is refused where a keyword is
     * saved or tracked instead of failing silently later.
     */
    public static function hasSearchOperator(string $phrase): bool
    {
        return preg_match('/["*|]|(^|\s)-\S|\b[a-z]+:|\s(OR|AND)\s/u', $phrase) === 1;
    }
}
