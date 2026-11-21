<?php

namespace App\Library\Website\GuidedGeneration;

use App\Library\Website\Setup\ServiceAreaList;
use App\Models\Business;
use App\Models\Website;
use Illuminate\Support\Str;

/**
 * Facts an established website's OWN pages state that the Business records do not hold yet (a migrated or hand-built site keeps
 * its service areas and reviews on pages, while the generator reads only canonical records). These are CANDIDATES, never facts:
 *
 *   - read-only: nothing here writes anything, and a candidate is never used by the generator until the owner confirms it
 *     (confirmAreas() is the only write, and only for names that are still candidates);
 *   - scoped: only this website's own pages are read (website_id), never another tenant's;
 *   - structured only: service-area names come from the lists on the site's "service areas" page; reviews come from `testimonials`
 *     sections. Free prose elsewhere is never mined, so no city, venue, claim or history is guessed.
 *
 * A candidate the canonical record already holds is not offered again.
 */
final class ExistingPageFactCandidates
{
    private const AREA_PAGE_SLUGS = ['service-areas', 'service-area', 'areas-we-serve', 'locations', 'areas'];

    private const NOT_A_PLACE = ['areas', 'area', 'we serve', 'serving', 'contact', 'contact us', 'call', 'book', 'booking', 'availability', 'check availability', 'home', 'and', 'more', 'surrounding areas', 'nearby'];

    /** A word that makes a line a call to action or a sentence, never a place name. */
    private const NOT_PLACE_WORDS = ['call', 'today', 'contact', 'email', 'text', 'book', 'now', 'us', 'we', 'our', 'free', 'quote', 'get', 'click', 'here', 'visit', 'phone', 'serve', 'serving', 'serves', 'located', 'near', 'within', 'miles', 'mile', 'available', 'availability'];

    private const MAX_AREAS = 20;

    private const MAX_REVIEWS = 5;

    /**
     * @return array{service_areas: array<int, array{name: string, source: string}>, testimonials: array<int, array{quote: string, author: ?string, source: string}>, review_required: bool}
     */
    public static function forWebsite(Website $website): array
    {
        $business = $website->business;
        $location = $business?->primaryLocation()->first();
        $known = collect(array_merge(
            array_map(fn ($entry) => (string) $entry, (array) ($location?->service_area_cities ?? [])),
            $location?->city ? [(string) $location->city] : [],
        ))->map(fn (string $name) => Str::slug(Str::before($name, ',')))->filter()->all();

        $areas = [];
        $testimonials = [];

        foreach ($website->pages()->orderBy('sort_order')->orderBy('id')->get(['title', 'slug', 'sections']) as $page) {
            $slug = strtolower((string) $page->slug);
            $isAreaPage = in_array($slug, self::AREA_PAGE_SLUGS, true);

            foreach ((array) $page->sections as $section) {
                $type = $section['type'] ?? null;
                $data = is_array($section['data'] ?? null) ? $section['data'] : [];

                if ($isAreaPage && in_array($type, ['text', 'services', 'custom_section'], true)) {
                    foreach (self::areaNames($type, $data) as $name) {
                        $areas[Str::slug($name)] ??= ['name' => $name, 'source' => (string) $page->title];
                    }
                }

                if ($type === 'testimonials') {
                    foreach ((array) ($data['items'] ?? []) as $item) {
                        $quote = is_array($item) ? trim((string) ($item['quote'] ?? '')) : '';
                        if ($quote !== '' && count($testimonials) < self::MAX_REVIEWS) {
                            $testimonials[] = ['quote' => Str::limit($quote, 600, ''), 'author' => ($a = trim((string) ($item['author_name'] ?? ($item['author'] ?? '')))) === '' ? null : Str::limit($a, 120, ''), 'source' => (string) $page->title];
                        }
                    }
                }
            }
        }

        $areas = array_values(array_filter($areas, fn (array $area, string $slug) => ! in_array($slug, $known, true), ARRAY_FILTER_USE_BOTH));

        return [
            'service_areas' => array_slice($areas, 0, self::MAX_AREAS),
            'testimonials' => $testimonials,
            'review_required' => true,
        ];
    }

    /**
     * The owner confirms some candidate areas: they are ADDED after the Business's own (nothing is removed or overwritten), only
     * names that are still candidates are accepted, through the same normalisation every saved area list uses.
     *
     * @param  array<int, string>  $names
     * @return int how many areas were added
     */
    public static function confirmAreas(Business $business, Website $website, array $names): int
    {
        $location = $business->primaryLocation()->first();
        abort_if($location === null, 422, 'Add your business location first.');

        $allowed = collect(self::forWebsite($website)['service_areas'])->pluck('name', 'name');
        $accepted = array_values(array_filter(array_map(fn ($name) => $allowed[(string) $name] ?? null, $names)));
        $own = array_values(array_filter((array) $location->service_area_cities, fn ($entry) => is_string($entry) && trim($entry) !== ''));
        $merged = ServiceAreaList::normalize(array_merge($own, $accepted));
        $added = count($merged) - count(ServiceAreaList::normalize($own));

        if ($added > 0) {
            $location->service_area_cities = $merged;
            $location->save();
        }

        return max(0, $added);
    }

    /** @return array<int, string> */
    private static function areaNames(string $type, array $data): array
    {
        $chunks = [];

        if ($type === 'services') {
            foreach ((array) ($data['items'] ?? []) as $item) {
                $chunks[] = is_array($item) ? (string) ($item['name'] ?? '') : '';
            }
        } else {
            $chunks[] = (string) ($data['body'] ?? '');
        }

        $names = [];
        foreach ($chunks as $chunk) {
            foreach (preg_split('/[\r\n,;•·|]+/u', strip_tags($chunk)) ?: [] as $token) {
                $token = trim(preg_replace('/\s+/u', ' ', (string) $token) ?? '', " \t.:-–—");

                if ($token === '' || mb_strlen($token) < 3 || mb_strlen($token) > 40 || str_word_count($token) > 4
                    || preg_match('/^[\p{L}][\p{L}\s\'’\-\.]*$/u', $token) !== 1
                    || in_array(mb_strtolower($token), self::NOT_A_PLACE, true)
                    || array_intersect(preg_split('/[\s\.]+/u', mb_strtolower($token), -1, PREG_SPLIT_NO_EMPTY) ?: [], self::NOT_PLACE_WORDS) !== []
                    || ! preg_match('/^[\p{Lu}]/u', $token)) {
                    continue;
                }

                $names[] = $token;
            }
        }

        return $names;
    }
}
