<?php

namespace App\Library\Website\GuidedGeneration;

/**
 * Deterministic choice of the facts a homepage trust strip shows — never AI, never invented.
 *
 * The ONLY input is the owner-CONFIRMED differentiators the generator already receives (SeoContentFacts: the knowledge profile's
 * differentiators, which reach it only when the owner confirmed them and they are fresh). The strip takes up to four SHORT, distinct ones in
 * the owner's own order and shows them verbatim; fewer than two suitable facts means no strip at all (a strip of one is not a strip).
 * Whatever the strip does not show is what a service page's inquiry panel may show, so a fact is not repeated site-wide.
 */
final class TrustFacts
{
    public const MIN = 2;

    public const MAX = 4;

    /** A compact strip item: longer facts stay available to the inquiry panels instead of being cut short. */
    public const MAX_LENGTH = 70;

    /**
     * @param  ?array<string, mixed>  $contentFacts  SeoContentFacts::build()
     * @return array{strip: array<int, string>, rest: array<int, string>}
     */
    public static function select(?array $contentFacts): array
    {
        $all = self::distinct((array) ($contentFacts['differentiators'] ?? []));

        $strip = [];
        foreach ($all as $fact) {
            if (count($strip) < self::MAX && mb_strlen($fact) <= self::MAX_LENGTH) {
                $strip[] = $fact;
            }
        }

        if (count($strip) < self::MIN) {
            return ['strip' => [], 'rest' => $all];
        }

        return ['strip' => $strip, 'rest' => array_values(array_diff($all, $strip))];
    }

    /**
     * Trimmed, non-empty, and distinct in meaning: a fact equal to, or contained in, one already kept (ignoring case, spacing and end punctuation)
     * is the same advantage said twice.
     *
     * @param  array<int, mixed>  $facts
     * @return array<int, string>
     */
    public static function distinct(array $facts): array
    {
        $kept = [];
        $keys = [];

        foreach ($facts as $fact) {
            $text = is_string($fact) ? trim(preg_replace('/\s+/u', ' ', $fact) ?? '') : '';
            $key = mb_strtolower(rtrim($text, " .!\t"));

            if ($key === '' || mb_strlen($key) < 3) {
                continue;
            }

            foreach ($keys as $existing) {
                if ($key === $existing || str_contains($existing, $key) || str_contains($key, $existing)) {
                    continue 2;
                }
            }

            $kept[] = $text;
            $keys[] = $key;
        }

        return $kept;
    }
}
