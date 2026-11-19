<?php

namespace App\Library\Website\GuidedGeneration;

/**
 * Deterministic, page-type-aware content completeness for AI-written pages. A page does not pass merely because it has one
 * hero and a short paragraph: what it must cover is derived from the planned page (its type, entity and search intent) and
 * the CONFIRMED facts (SeoContentFacts). Every rule is conditional — with no confirmed fact behind it a rule does not apply, so
 * a business that has not told us something is never forced into invented copy (see SeoContentFacts::gaps() for what is
 * reported instead).
 *
 * It judges structure and semantic coverage, never exact prose or word counts: a name is "present" when its significant words
 * appear, a confirmed fact is "represented" when enough of its words reach the page. Pure: no I/O.
 */
final class PageContentQuality
{
    /** Headings that say nothing a searcher could use. Matched as a phrase inside a heading, case-insensitively. */
    public const GENERIC_HEADINGS = [
        'our amazing services',
        'an unforgettable experience',
        'unforgettable experience',
        'discover the magic',
        'capture every moment',
        'look no further',
        'second to none',
        'welcome to our website',
        'why choose us',
    ];

    private const STOPWORDS = ['the', 'and', 'for', 'with', 'our', 'your', 'you', 'are', 'was', 'that', 'this', 'from', 'into', 'what', 'who', 'how', 'its', 'has', 'have', 'all', 'can', 'will', 'not', 'any', 'out', 'per', 'one', 'two'];

    /** Section types that are layout/conversion furniture, not information about the page's subject. */
    private const NON_CONTENT = ['hero', 'cta', 'contact_details', 'form', 'gallery', 'backdrops', 'custom_section', 'video'];

    /**
     * @param  array<string, mixed>  $page  the generated page (title, seo_title, meta_description, sections)
     * @param  array<string, mixed>  $planPage  its plan entry
     * @param  array<string, mixed>  $facts  SeoContentFacts::build()
     * @param  array<int, array<string, mixed>>  $sitePlan  the complete plan (for the contact path)
     * @return array<int, string> human-readable problems; empty = the page is complete for what is known
     */
    public static function problems(array $page, array $planPage, array $facts, array $sitePlan): array
    {
        $type = (string) ($planPage['page_type'] ?? '');
        $entity = is_array($planPage['entity'] ?? null) ? $planPage['entity'] : [];
        $key = (string) ($page['page_key'] ?? $planPage['page_key'] ?? '');
        $problems = [];

        $sections = array_values(array_filter((array) ($page['sections'] ?? []), 'is_array'));
        $h1 = self::h1($sections);
        $headings = self::headings($sections);
        $body = self::text($page);
        $contentSections = array_values(array_filter($sections, fn (array $section) => ! in_array($section['type'] ?? '', self::NON_CONTENT, true)));

        // ---- every page: no generic headings, no repeated headings
        foreach ($headings as $heading) {
            foreach (self::GENERIC_HEADINGS as $generic) {
                if (str_contains(mb_strtolower($heading), $generic)) {
                    $problems[] = "Page '{$key}' has a generic heading (\"{$heading}\") — a heading must say something specific about the page.";
                }
            }
        }
        $normalized = array_map(fn (string $heading) => mb_strtolower(trim($heading)), $headings);
        if (count($normalized) !== count(array_unique($normalized))) {
            $problems[] = "Page '{$key}' repeats a heading — each section needs its own.";
        }

        $contactPath = self::contactPath($sitePlan);
        $city = $facts['market']['city'] ?? null;

        if ($type === 'home') {
            $services = (array) ($facts['services'] ?? []);
            $areas = (array) ($facts['areas'] ?? []);
            $diffs = (array) ($facts['differentiators'] ?? []);

            if ($city !== null && ! self::containsPhrase($h1, $city)) {
                $problems[] = "The home page headline must name the primary market ({$city}) — \"{$h1}\" does not.";
            }

            if ($services !== [] && ! self::headingNamesAService($h1, $services)) {
                $problems[] = 'The home page headline must say what the business does: it names none of the primary services (' . implode(', ', array_slice(array_column($services, 'name'), 0, 3)) . ').';
            }

            // Depth is conditional on there being something real to say.
            $needed = ($services !== [] || $areas !== [] || $diffs !== []) ? 2 : 1;
            if (count($contentSections) < $needed) {
                $problems[] = "The home page needs at least {$needed} informative sections after the headline (found " . count($contentSections) . ') — a headline and one short paragraph is not enough.';
            }

            if ($services !== []) {
                $named = count(array_filter($services, fn (array $service) => self::mentionsName($body, $service['name'])));
                $required = min(3, count($services));
                if ($named < $required) {
                    $problems[] = "The home page names {$named} of the real services; it should name at least {$required} (" . implode(', ', array_slice(array_column($services, 'name'), 0, 5)) . ').';
                }
            }

            if (count($areas) >= 2) {
                $namedAreas = count(array_filter($areas, fn (string $area) => self::containsPhrase($body, $area)));
                $required = min(2, count($areas));
                if ($namedAreas < $required) {
                    $problems[] = "The home page should state where the business works: it names {$namedAreas} of the confirmed service areas (" . implode(', ', array_slice($areas, 0, 5)) . ').';
                }
            }

            if ($diffs !== [] && ! self::representsAny($body, $diffs)) {
                $problems[] = 'None of the confirmed differentiators is reflected on the home page; state at least one real reason to choose this business.';
            }
        }

        if (in_array($type, ['service_detail', 'event_detail'], true)) {
            $name = trim((string) ($entity['name'] ?? ''));

            if ($name !== '' && ! self::mentionsName($h1, $name, 0.75)) {
                $problems[] = "The headline of page '{$key}' must name the service it is about (\"{$name}\") — \"{$h1}\" does not.";
            }

            if (count($contentSections) < 2) {
                $problems[] = "Page '{$key}' needs at least 2 informative sections (what it is and who it suits, what is included) — found " . count($contentSections) . '.';
            }

            $description = trim((string) ($entity['description'] ?? ''));
            if ($description !== '' && count(self::tokens($description)) >= 3 && self::overlap($body, $description) < 0.3) {
                $problems[] = "Page '{$key}' does not reflect the confirmed description of \"{$name}\" — explain what it actually is and includes.";
            }

            if ($city !== null && ! self::containsPhrase($body, $city)) {
                $problems[] = "Page '{$key}' never mentions the primary market ({$city}), so it is not tied to where customers search.";
            }
        }

        if (isset($entity['area']) && is_string($entity['area'])) {
            $local = (array) ($entity['local_facts'] ?? []);
            $venues = array_values(array_filter((array) ($local['venues'] ?? []), 'is_string'));
            $logistics = trim((string) ($local['logistics'] ?? ''));

            if (count($contentSections) < 2) {
                $problems[] = "Area page '{$key}' needs at least 2 informative sections — found " . count($contentSections) . '.';
            }

            if ($venues !== []) {
                $named = count(array_filter($venues, fn (string $venue) => self::mentionsName($body, $venue)));
                $required = min(2, count($venues));
                if ($named < $required) {
                    $problems[] = "Area page '{$key}' names {$named} of the confirmed venues for {$entity['area']}; it should name at least {$required}.";
                }
            }

            if ($logistics !== '' && count(self::tokens($logistics)) >= 3 && self::overlap($body, $logistics) < 0.3) {
                $problems[] = "Area page '{$key}' does not reflect the confirmed travel/setup details for {$entity['area']}.";
            }
        }

        // The fixed informational pages must say something: a headline alone is not a services overview, an About or an FAQ page.
        if (in_array($type, ['services_overview', 'about', 'faq'], true) && count($contentSections) < 1) {
            $problems[] = "Page '{$key}' has only a headline; add its informative content (" . match ($type) {
                'services_overview' => 'the list of the real services',
                'about' => 'who the business is, from the confirmed facts',
                default => 'the questions and answers',
            } . ').';
        }

        // One clear conversion path, wherever a page is about something a visitor can book.
        $bookable = in_array($type, ['home', 'service_detail', 'event_detail'], true) || isset($entity['area']);
        if ($contactPath !== null && $bookable && ! self::hasContactLink($sections, $contactPath)) {
            $problems[] = "Page '{$key}' has no clear booking/contact action — add a button to {$contactPath}.";
        }

        return $problems;
    }

    /**
     * Two pages of the same kind (services, events) whose text — with the subject's own name removed — is this similar read as one
     * page with the name swapped, which competes with itself in search. Returns the page_key pairs that are too alike.
     *
     * @param  array<int, array<string, mixed>>  $pages
     * @param  array<string, array<string, mixed>>  $planByKey
     * @return array<int, array{0: string, 1: string}>
     */
    public static function nearDuplicates(array $pages, array $planByKey, float $maxSimilarity = 88.0): array
    {
        $texts = [];

        foreach ($pages as $page) {
            $planPage = $planByKey[$page['page_key'] ?? ''] ?? null;
            $type = $planPage['page_type'] ?? null;

            if (! in_array($type, ['service_detail', 'event_detail'], true)) {
                continue;
            }

            $name = mb_strtolower(trim((string) ($planPage['entity']['name'] ?? '')));
            $text = mb_strtolower(self::text($page));
            $texts[$page['page_key']] = mb_substr(trim($name === '' ? $text : str_replace($name, '', $text)), 0, 1500);
        }

        $keys = array_keys($texts);
        $pairs = [];
        foreach ($keys as $i => $a) {
            foreach (array_slice($keys, $i + 1) as $b) {
                similar_text($texts[$a], $texts[$b], $percent);
                if ($percent >= $maxSimilarity) {
                    $pairs[] = [$a, $b];
                }
            }
        }

        return $pairs;
    }

    // ------------------------------------------------------------------ helpers

    /** @param array<int, array<string, mixed>> $sitePlan */
    private static function contactPath(array $sitePlan): ?string
    {
        foreach ($sitePlan as $page) {
            if (($page['page_type'] ?? null) === 'contact' && ! empty($page['slug'])) {
                return '/' . ltrim((string) $page['slug'], '/');
            }
        }

        return null;
    }

    /** @param array<int, array<string, mixed>> $sections */
    private static function hasContactLink(array $sections, string $contactPath): bool
    {
        foreach ($sections as $section) {
            $data = (array) ($section['data'] ?? []);
            $urls = [];
            foreach (['primary_cta', 'secondary_cta'] as $field) {
                if (is_array($data[$field] ?? null)) {
                    $urls[] = (string) ($data[$field]['url'] ?? '');
                }
            }
            foreach ((array) ($data['buttons'] ?? []) as $button) {
                $urls[] = is_array($button) ? (string) ($button['url'] ?? '') : '';
            }

            foreach ($urls as $url) {
                $url = trim($url);
                if (strcasecmp(rtrim($url, '/'), $contactPath) === 0 || stripos($url, 'tel:') === 0 || stripos($url, 'mailto:') === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<int, array<string, mixed>> $sections */
    private static function h1(array $sections): string
    {
        foreach ($sections as $section) {
            if (($section['type'] ?? null) === 'hero') {
                return trim((string) ($section['data']['heading'] ?? ''));
            }
        }

        return '';
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, string>
     */
    private static function headings(array $sections): array
    {
        $headings = [];
        foreach ($sections as $section) {
            $heading = trim((string) ($section['data']['heading'] ?? ''));
            if ($heading !== '') {
                $headings[] = $heading;
            }
        }

        return $headings;
    }

    /** Rendered page section text only; SEO titles/descriptions are not substitutes for useful on-page copy. */
    public static function text(array $page): string
    {
        $parts = [];
        $sections = is_array($page['sections'] ?? null) ? $page['sections'] : [];
        array_walk_recursive($sections, function ($value, $key) use (&$parts) {
            if (is_string($value) && ! in_array($key, ['type', 'url'], true)) {
                $parts[] = $value;
            }
        });

        return implode(' ', $parts);
    }

    /** @return array<int, string> significant lowercase word stems */
    private static function tokens(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = [];
        foreach ($words as $word) {
            if (mb_strlen($word) < 3 || in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $tokens[] = mb_strlen($word) > 4 && str_ends_with($word, 's') ? mb_substr($word, 0, -1) : $word;
        }

        return array_values(array_unique($tokens));
    }

    /** The share of $needle's significant words that appear in $haystack. */
    private static function overlap(string $haystack, string $needle): float
    {
        $needleTokens = self::tokens($needle);

        if ($needleTokens === []) {
            return 1.0;
        }

        $present = array_intersect($needleTokens, self::tokens($haystack));

        return count($present) / count($needleTokens);
    }

    private static function mentionsName(string $haystack, string $name, float $share = 0.75): bool
    {
        return self::overlap($haystack, $name) >= $share;
    }

    /** A whole-phrase, case-insensitive match (so "Spokane" is not found inside "Spokane Valley" only when bounded by letters). */
    private static function containsPhrase(string $haystack, string $phrase): bool
    {
        $phrase = trim($phrase);

        return $phrase !== '' && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($phrase, '/') . '(?![\p{L}\p{N}])/iu', $haystack) === 1;
    }

    /**
     * The headline names a primary service when at least half of the significant words of one of the first three services appear
     * (so "Photo Booth Rental in Petal" can name "Magic Mirror Photo Booth", while a tagline with no service word cannot).
     *
     * @param  array<int, array{name: string}>  $services
     */
    private static function headingNamesAService(string $h1, array $services): bool
    {
        foreach (array_slice($services, 0, 3) as $service) {
            if (self::overlap($h1, $service['name']) >= 0.5) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, string> $facts */
    private static function representsAny(string $body, array $facts): bool
    {
        foreach ($facts as $fact) {
            if (count(self::tokens($fact)) >= 2 && self::overlap($body, $fact) >= 0.5) {
                return true;
            }
            if (count(self::tokens($fact)) < 2 && self::overlap($body, $fact) >= 1.0) {
                return true;
            }
        }

        return false;
    }
}
