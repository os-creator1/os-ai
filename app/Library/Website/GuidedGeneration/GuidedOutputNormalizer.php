<?php

namespace App\Library\Website\GuidedGeneration;

/**
 * Deterministic repair of the model's batch output BEFORE it is validated — only for mistakes whose repair cannot change what the
 * page says or add anything the model did not write:
 *
 *   - an `image_text` section on a page that allows `text` but not `image_text` is the same heading and body as a `text` section;
 *   - any OTHER section the plan does not allow on that page is left exactly as written: it is substantive content the page may not
 *     carry, so deleting it would hide a real problem. Validation rejects it and the existing bounded retry receives the reason;
 *   - an over-long seo_title / meta_description is shortened at a word boundary (never rewritten); a blank one becomes null.
 *
 * It never adds a page, removes a page, renames a page_key, touches page content, or invents a fact: a duplicate title, missing
 * pages, a missing hero or weak content are NOT repaired here — they fail validation and the existing bounded retry runs, now
 * carrying the reasons. Pure: no queries, no AI.
 */
final class GuidedOutputNormalizer
{
    public const SEO_TITLE_MAX = 70;

    public const META_DESCRIPTION_MAX = 160;

    /**
     * @param  array<int, mixed>  $pages  the decoded `pages` array, as returned
     * @param  array<int, array<string, mixed>>  $plan  the batch's plan (page_key => allowed_section_types)
     * @return array{pages: array<int, mixed>, notes: array<int, string>}
     */
    public static function normalize(array $pages, array $plan): array
    {
        $allowedByKey = [];
        foreach ($plan as $entry) {
            $allowedByKey[(string) ($entry['page_key'] ?? '')] = (array) ($entry['allowed_section_types'] ?? []);
        }

        $notes = [];

        foreach ($pages as $index => $page) {
            if (! is_array($page) || ! is_string($page['page_key'] ?? null) || ! isset($allowedByKey[$page['page_key']])) {
                continue;
            }

            $key = $page['page_key'];
            $allowed = $allowedByKey[$key];

            if (is_array($page['sections'] ?? null)) {
                $kept = [];
                foreach ($page['sections'] as $section) {
                    if (! is_array($section) || ! is_string($section['type'] ?? null) || in_array($section['type'], $allowed, true)) {
                        $kept[] = $section;

                        continue;
                    }

                    // Only the same heading and body as plain text is converted; anything else stays for validation to reject.
                    $converted = self::asText($section, $allowed);
                    if ($converted !== null) {
                        $kept[] = $converted;
                        $notes[] = "{$key}: image_text became text";
                    } else {
                        $kept[] = $section;
                    }
                }
                $pages[$index]['sections'] = $kept;
            }

            foreach (['seo_title' => self::SEO_TITLE_MAX, 'meta_description' => self::META_DESCRIPTION_MAX] as $field => $max) {
                if (! array_key_exists($field, $page) || ! is_string($page[$field])) {
                    continue;
                }

                $value = trim(preg_replace('/\s+/u', ' ', $page[$field]) ?? '');
                if ($value === '') {
                    $pages[$index][$field] = null;
                    $notes[] = "{$key}: blank {$field} removed";
                } elseif (mb_strlen($value) > $max) {
                    $short = self::shorten($value, $max);

                    if ($short !== null) {
                        $pages[$index][$field] = $short;
                        $notes[] = "{$key}: {$field} shortened";
                    }
                } else {
                    $pages[$index][$field] = $value;
                }
            }
        }

        return ['pages' => $pages, 'notes' => $notes];
    }

    /** The same heading and body as a plain text section, only when text is allowed and there is real body copy. */
    private static function asText(array $section, array $allowed): ?array
    {
        if (($section['type'] ?? null) !== 'image_text' || ! in_array('text', $allowed, true)) {
            return null;
        }

        $data = is_array($section['data'] ?? null) ? $section['data'] : [];
        $body = $data['body'] ?? null;

        if (! is_string($body) || trim($body) === '') {
            return null;
        }

        return ['type' => 'text', 'data' => ['heading' => is_string($data['heading'] ?? null) ? $data['heading'] : null, 'body' => $body]];
    }

    /** Cut at the last word boundary within the limit; null when no meaningful prefix remains (then validation rejects it). */
    private static function shorten(string $value, int $max): ?string
    {
        $cut = mb_substr($value, 0, $max);

        if (mb_substr($value, $max, 1) !== ' ') {
            $space = mb_strrpos($cut, ' ');
            $cut = $space !== false && $space >= (int) ($max / 2) ? mb_substr($cut, 0, $space) : $cut;
        }

        $cut = rtrim($cut, " \t\n\r\0\x0B-–—|:,;");

        return mb_strlen($cut) >= 10 ? $cut : null;
    }
}
