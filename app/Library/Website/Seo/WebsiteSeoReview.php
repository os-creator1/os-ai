<?php

namespace App\Library\Website\Seo;

use App\Models\Business;
use App\Models\Website;
use App\Models\WebsiteAsset;
use App\Models\WebsitePage;
use Illuminate\Support\Collection;

/**
 * The review half of the publish-time SEO quality gate: risks that need the owner's judgement, never a block.
 *
 * Blocking problems (no homepage, an invalid address, broken sections, missing required photographs) stay in
 * WebsitePublisher::validateDraft(). Everything here is a WARNING that names exactly which page needs attention, because
 * none of it is objectively broken — a site without testimonials or a blog is still a valid site, and no word count is a
 * proxy for quality. A warning is only reported when the problem is actually found, from the draft as it will be published.
 *
 * Pure reads: the website's own pages, assets and its Business's saved services and places.
 */
final class WebsiteSeoReview
{
    /** Pages whose shared 4-word phrases exceed this share are the same page with a few words changed. */
    public const SIMILARITY_LIMIT = 0.6;

    /** The same alt text on this many photos or more says nothing about any of them. */
    private const REPEATED_ALT = 3;

    /**
     * @param  array<string, ?string>  $links  action URLs: pages, photos
     * @return array<int, array{key: string, status: string, title: string, detail: string, items: array<int, string>, action: ?array{label: string, url: string}}>
     */
    public function review(Website $website, Collection $pages, ?Business $business, array $links = []): array
    {
        if ($pages->isEmpty() || $business === null) {
            return [];
        }

        $classifier = WebsitePageIntentClassifier::forBusiness($business);
        $baseCity = trim((string) $business->primaryLocation()->first()?->city);

        $described = $pages->map(fn (WebsitePage $page) => [
            'page' => $page,
            'text' => $this->textOf($page->sections ?? []),
            'identity' => $classifier->classify([
                'slug' => $page->slug, 'is_home' => $page->is_home, 'title' => $page->title,
                'role' => $page->page_role, 'intent' => $page->page_intent, 'text' => $this->textOf($page->sections ?? []),
            ]),
        ]);

        $review = [];
        $add = function (string $key, string $title, string $detail, array $items, ?string $actionLabel = 'Review pages', string $linkKey = 'pages') use (&$review, $links): void {
            if ($items !== []) {
                $review[] = [
                    'key' => $key, 'status' => 'warn', 'title' => $title, 'detail' => $detail, 'items' => array_values($items),
                    'action' => $actionLabel !== null && ! empty($links[$linkKey]) ? ['label' => $actionLabel, 'url' => $links[$linkKey]] : null,
                ];
            }
        };

        // Two pages for the same search: the same place twice, the same booth twice, or a place page for the base city the home page owns.
        $groups = [];
        foreach ($described as $row) {
            $identity = $row['identity'];
            $subject = match ($identity['role']) {
                WebsitePageIntent::LOCATION => 'place:' . mb_strtolower((string) $identity['place']),
                WebsitePageIntent::SERVICE => 'offer:' . mb_strtolower((string) ($identity['offer'] ?? $row['page']->page_intent['service_uid'] ?? '')),
                default => null,
            };

            if ($subject !== null && ! str_ends_with($subject, ':')) {
                $groups[$subject][] = $row['page']->title;
            }
        }
        $duplicated = [];
        foreach ($groups as $titles) {
            if (count($titles) > 1) {
                $duplicated[] = implode(' and ', $titles) . ' target the same search';
            }
        }
        if ($baseCity !== '') {
            foreach ($described as $row) {
                if ($row['identity']['role'] === WebsitePageIntent::LOCATION && mb_strtolower((string) $row['identity']['place']) === mb_strtolower($baseCity)) {
                    $duplicated[] = $row['page']->title . ' targets ' . $baseCity . ', which your home page already targets';
                }
            }
        }
        $add('seo_duplicate_intent', 'Pages that compete for the same search', 'Two pages answering the same search compete with each other instead of both ranking. Merge them, or give each a clearly different purpose.', $duplicated);

        // Place and booth pages that are the same text with a few words changed.
        $sameCopy = [];
        $rows = $described->filter(fn ($row) => in_array($row['identity']['role'], [WebsitePageIntent::LOCATION, WebsitePageIntent::SERVICE], true))->values();
        $mask = array_merge(...array_map(fn ($row) => WebsitePageIntentClassifier::tokens((string) ($row['identity']['place'] ?? ''), false), $rows->all()) ?: [[]]);
        for ($a = 0; $a < $rows->count(); $a++) {
            for ($b = $a + 1; $b < $rows->count(); $b++) {
                if ($rows[$a]['identity']['role'] !== $rows[$b]['identity']['role']) {
                    continue;
                }
                $similarity = $this->similarity($rows[$a]['text'], $rows[$b]['text'], $mask);
                if ($similarity >= self::SIMILARITY_LIMIT) {
                    $sameCopy[] = $rows[$a]['page']->title . ' and ' . $rows[$b]['page']->title . ' share about ' . (int) round($similarity * 100) . '% of their wording';
                }
            }
        }
        $add('seo_repeated_copy', 'Pages that repeat each other', 'Pages that differ only by a name are close to doorway pages. Add what is genuinely different about each (what is included, a venue you have worked, how serving it works) or remove the page and keep it in your list of areas.', $sameCopy);

        // A commercial page with no way on to another page of the site inside its content.
        $islands = [];
        foreach ($described as $row) {
            $role = $row['identity']['role'];
            if (in_array($role, [WebsitePageIntent::SERVICE, WebsitePageIntent::LOCATION, WebsitePageIntent::AREA_HUB, WebsitePageIntent::SERVICES_HUB], true) && ! $this->linksToAnotherPage($row['page'], $pages)) {
                $islands[] = $row['page']->title;
            }
        }
        $add('seo_contextual_links', 'Pages with no links to your other pages', 'Visitors and search engines move through a site by its links. Link the page to the related services and places in its own content, not only the menu and footer.', $islands);

        // The same description on several photos.
        $alts = WebsiteAsset::query()->where('website_id', $website->id)->whereNotNull('alt_text')->where('alt_text', '!=', '')->get(['alt_text'])->countBy(fn ($asset) => mb_strtolower(trim((string) $asset->alt_text)));
        $repeated = $alts->filter(fn (int $n) => $n >= self::REPEATED_ALT)->map(fn (int $n, string $alt) => "\"{$alt}\" is the description of {$n} photos")->values()->all();
        $add('seo_repeated_alt', 'Photos with the same description', 'A description should say what is in that photo. The same words on many photos tell screen-reader users and search engines nothing about each one.', $repeated, 'Review photos', 'photos');

        // A description that visibly stops mid-thought.
        $cut = $pages->filter(fn (WebsitePage $page) => WebsiteSeoText::looksCutOff((string) $page->meta_description))->pluck('title')->all();
        $add('seo_cut_off_description', 'Descriptions that stop mid-sentence', 'These descriptions end in "..." and read as unfinished in search results. Rewrite them as one complete sentence.', $cut);

        // Real proof: event photographs or customer words.
        $hasProof = $pages->contains(fn (WebsitePage $page) => collect($page->sections ?? [])->contains(fn ($section) => in_array($section['type'] ?? null, ['gallery', 'testimonials'], true)));
        $add('seo_proof', 'No event photos or customer words yet', 'Visitors and search engines trust a business that shows real work. Add event photos or genuine customer quotes when you have them — never invented ones.', $hasProof ? [] : ['No page shows a gallery or testimonials'], 'Add photos', 'photos');

        return $review;
    }

    /** @param array<int, array<string, mixed>> $sections */
    private function textOf(array $sections): string
    {
        $out = [];

        foreach ($sections as $section) {
            if (($section['type'] ?? null) === 'contact_details') {
                continue;
            }

            array_walk_recursive($section, function ($value, $key) use (&$out) {
                if (is_string($value) && ! in_array($key, ['url', 'image', 'uid', 'type', 'catalog_item_uid', 'service_uid', 'form_uid', 'place', 'poster', 'video'], true)) {
                    $out[] = $value;
                }
            });
        }

        return trim(implode(' ', $out));
    }

    /** Jaccard similarity of 4-word shingles, with place words masked so a changed city name does not hide a copy. */
    private function similarity(string $a, string $b, array $maskWords): float
    {
        $shingles = function (string $text) use ($maskWords): array {
            $words = array_map(fn (string $word) => in_array($word, $maskWords, true) ? '#' : $word, WebsitePageIntentClassifier::tokens($text, false));
            $out = [];
            for ($i = 0; $i + 3 < count($words); $i++) {
                $out[implode(' ', array_slice($words, $i, 4))] = true;
            }

            return $out;
        };

        $x = $shingles($a);
        $y = $shingles($b);

        return $x === [] || $y === [] ? 0.0 : count(array_intersect_key($x, $y)) / count($x + $y);
    }

    /** Whether the page's content leads to another page of this site other than its own contact or quote page. */
    private function linksToAnotherPage(WebsitePage $page, Collection $pages): bool
    {
        $slugs = $pages->where('is_home', false)->pluck('slug')->filter()->map(fn ($slug) => (string) $slug)->all();
        $found = false;

        foreach ($page->sections ?? [] as $section) {
            foreach ((array) ($section['data']['items'] ?? []) as $item) {
                if (is_array($item) && (! empty($item['service_uid']) || ! empty($item['place']) || ! empty($item['page_slug']))) {
                    return true;
                }
            }

            array_walk_recursive($section, function ($value, $key) use (&$found, $slugs, $page) {
                if ($key !== 'url' || ! is_string($value)) {
                    return;
                }
                $target = trim((string) parse_url($value, PHP_URL_PATH), '/');
                if ($target !== '' && $target !== (string) $page->slug && (in_array($target, $slugs, true)) && ! preg_match('/contact|availability|quote|booking/', $target)) {
                    $found = true;
                }
            });
        }

        return $found;
    }
}
