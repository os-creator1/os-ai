<?php

namespace App\Library\Seo\Content;

use App\Library\NicheBlueprint\Workspace\BlueprintConfigReader;
use App\Library\Seo\SeoPublishedContentReader;
use App\Models\Business;
use App\Models\BusinessLocation;
use Illuminate\Support\Str;

/**
 * SEO Content Engine V1 — the ONLY facts an AI draft is allowed to know about a Business. Everything is read from
 * the Business's own records: its name, the services it publishes, its real catalog (names, descriptions, prices),
 * the cities it serves, FAQs and a short summary already on its published website, and the niche's own FAQ topics.
 * Nothing is inferred, estimated or supplied from general knowledge, and nothing belongs to another Business.
 *
 * Reads only; no AI, no provider call.
 */
final class ArticleGroundingFacts
{
    private const MAX_FAQS = 8;
    private const MAX_CITIES = 12;
    private const SUMMARY_CHARS = 600;

    public function __construct(
        private readonly ArticleSiteInventory $inventory,
        private readonly ArticleCatalogFacts $catalog,
        private readonly SeoPublishedContentReader $content,
        private readonly BlueprintConfigReader $blueprint,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function forBusiness(Business $business): array
    {
        $pages = $this->inventory->pages($business);
        $published = $this->content->forBusiness($business);

        $services = array_values(array_map(
            fn (array $p) => $p['title'],
            array_filter($pages, fn (array $p) => $p['kind'] === ArticleSiteInventory::KIND_SERVICE && $p['linkable']),
        ));

        $faqs = [];
        $summary = null;

        foreach ($published?->pages ?? [] as $page) {
            if ($page->isHome && $summary === null) {
                $summary = Str::limit(trim($page->textSurfaces()['body']), self::SUMMARY_CHARS, '');
            }

            foreach ($page->sections() as $section) {
                if (($section['type'] ?? null) !== 'faq') {
                    continue;
                }

                foreach (($section['data']['items'] ?? []) as $item) {
                    $q = trim((string) ($item['question'] ?? ''));
                    $a = trim((string) ($item['answer'] ?? ''));

                    if ($q !== '' && $a !== '' && count($faqs) < self::MAX_FAQS) {
                        $faqs[] = ['question' => $q, 'answer' => Str::limit($a, 400, '')];
                    }
                }
            }
        }

        $strategy = $this->blueprint->seoStrategy($business);

        return [
            'business_name' => (string) $business->name,
            'services' => $services,
            'packages' => array_map(fn (array $p) => array_filter([
                'name' => $p['name'],
                'description' => $p['description'] !== null ? Str::limit($p['description'], 240, '') : null,
                'price' => $p['price_label'],
                'price_note' => $p['price_label'] === null ? 'price on request' : null,
            ]), $this->catalog->active($business)),
            'service_areas' => $this->cities($business, $pages),
            'faqs' => $faqs,
            'site_summary' => $summary !== '' ? $summary : null,
            'niche_faq_topics' => array_slice((array) ($strategy['faq_topics'] ?? []), 0, 10),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<int, string>
     */
    private function cities(Business $business, array $pages): array
    {
        $cities = [];

        foreach (BusinessLocation::query()->where('business_id', $business->id)->get(['city', 'service_area_cities']) as $location) {
            if (trim((string) $location->city) !== '') {
                $cities[] = trim((string) $location->city);
            }

            foreach ((array) $location->service_area_cities as $city) {
                if (is_string($city) && trim($city) !== '') {
                    $cities[] = trim($city);
                }
            }
        }

        foreach ($pages as $page) {
            if ($page['kind'] === ArticleSiteInventory::KIND_LOCATION && $page['linkable']) {
                $cities[] = Str::title(str_replace('-', ' ', (string) preg_replace('/^serving-/', '', (string) $page['slug'])));
            }
        }

        return array_slice(array_values(array_unique($cities)), 0, self::MAX_CITIES);
    }
}
