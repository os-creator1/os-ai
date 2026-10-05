<?php

namespace App\Library\Website;

use App\Enums\Website\WebsiteStatus;
use App\Library\Website\Design\WebsiteCtaResolver;
use App\Models\Website;
use Illuminate\Support\Collection;

/**
 * Website V1 final — the owner-facing "SEO & Website Health": a short list of
 * REAL checks, each computed from this website's actual pages, assets,
 * domain and Business facts, with one plain sentence on what it means and
 * where to fix it. No score, no benchmark, no invented advice and no promise
 * about rankings — only things that are true or false right now.
 *
 * The deep technical audit of the published snapshot lives in the SEO module
 * (the Site Audit); this is the owner's everyday summary and never replaces it.
 */
final class WebsiteHealthChecker
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    public function __construct(
        private readonly WebsiteCtaResolver $cta,
        private readonly WebsiteCatalogReferences $catalogReferences,
        private readonly WebsitePageStrategy $strategy,
    ) {}

    /**
     * @param  array<string, string|null>  $links  action URLs: publish, domains, photos, pages, answers
     * @return array{checks: array<int, array{key: string, status: string, title: string, detail: string, action: ?array{label: string, url: string}}>, summary: array{ok: int, warn: int, fail: int}}
     */
    public function check(Website $website, array $links = []): array
    {
        $pages = $website->pages()->orderBy('sort_order')->orderBy('id')->get();
        $business = $website->business;
        $checks = [];

        $add = function (string $key, string $status, string $title, string $detail, ?string $actionLabel = null, ?string $actionUrl = null) use (&$checks) {
            $checks[] = ['key' => $key, 'status' => $status, 'title' => $title, 'detail' => $detail, 'action' => $actionLabel !== null && $actionUrl !== null ? ['label' => $actionLabel, 'url' => $actionUrl] : null];
        };

        // 1. Published.
        $published = $website->status === WebsiteStatus::Published;
        $add('published', $published ? self::OK : self::WARN, 'Published', $published ? 'Visitors can see your published website.' : 'Your website is not published yet, so visitors cannot see it.', $published ? null : 'Publish', $links['publish'] ?? null);

        // 2. Own domain (platform addresses are never indexed).
        $hasDomain = $website->activePrimaryDomain() !== null;
        $add('domain', $hasDomain ? self::OK : self::WARN, 'Your own web address', $hasDomain ? 'Your website is on your own domain, so search engines can index it.' : 'Your website is on a preview address. Search engines only index a website on its own domain, so connect one to be found in search.', $hasDomain ? null : 'Connect a domain', $links['domains'] ?? null);

        // 2b. Pages hidden from search. Generated pages start hidden (noindex) until the owner has
        // read them; a connected domain does not change that, so say so plainly instead of implying
        // the site is findable.
        $hiddenCount = $pages->where('noindex', true)->count();
        $add(
            'indexing',
            $hiddenCount === 0 ? self::OK : self::WARN,
            'Pages visible to search engines',
            $hiddenCount === 0
                ? 'Every page can be found in search.'
                : $hiddenCount . ' of ' . $pages->count() . ' pages are hidden from search engines, so they will not be found in search even on your own domain. Read your pages, then let search engines find them.',
            $hiddenCount === 0 ? null : 'Review pages',
            $links['pages'] ?? null,
        );

        // 3 + 4. Titles and descriptions.
        $missingTitles = $pages->filter(fn ($page) => trim((string) $page->seo_title) === '')->pluck('title')->all();
        $duplicateTitles = $pages->filter(fn ($page) => trim((string) $page->seo_title) !== '')->groupBy(fn ($page) => mb_strtolower(trim($page->seo_title)))->filter(fn (Collection $group) => $group->count() > 1)->map(fn (Collection $group) => $group->first()->seo_title)->values()->all();
        if ($missingTitles !== []) {
            $add('titles', self::FAIL, 'Page titles', 'These pages have no search title: ' . implode(', ', array_slice($missingTitles, 0, 5)) . '.', 'Manage pages', $links['pages'] ?? null);
        } elseif ($duplicateTitles !== []) {
            $add('titles', self::WARN, 'Page titles', 'Some pages share the same search title: ' . implode(', ', array_slice($duplicateTitles, 0, 3)) . '. Each page should have its own.', 'Manage pages', $links['pages'] ?? null);
        } else {
            $add('titles', self::OK, 'Page titles', 'Every page has its own search title.');
        }

        $missingDescriptions = $pages->filter(fn ($page) => trim((string) $page->meta_description) === '')->pluck('title')->all();
        $add('descriptions', $missingDescriptions === [] ? self::OK : self::WARN, 'Page descriptions', $missingDescriptions === [] ? 'Every page has a search description.' : count($missingDescriptions) . ' page(s) have no search description: ' . implode(', ', array_slice($missingDescriptions, 0, 5)) . '.', $missingDescriptions === [] ? null : 'Manage pages', $links['pages'] ?? null);

        // 5. Photos have alt text.
        $referenced = $this->referencedAssetUids($pages);
        $withoutAlt = $referenced === [] ? 0 : $website->assets()->whereIn('uid', $referenced)->where(fn ($q) => $q->whereNull('alt_text')->orWhere('alt_text', ''))->count();
        $add('alt_text', $withoutAlt === 0 ? self::OK : self::WARN, 'Photo descriptions (alt text)', $withoutAlt === 0 ? 'Every photo on your pages has a description for people using screen readers and for search.' : $withoutAlt . ' photo(s) on your pages have no description.', $withoutAlt === 0 ? null : 'Review photos', $links['photos'] ?? null);

        // 6. Contact details.
        $hasContactSection = $pages->contains(fn ($page) => collect($page->sections ?? [])->contains(fn ($section) => ($section['type'] ?? null) === 'contact_details'));
        $hasPhoneOrEmail = $business !== null && (trim((string) $business->phone) !== '' || trim((string) $business->email) !== '');
        if (! $hasPhoneOrEmail) {
            $add('contact', self::FAIL, 'Contact details', 'Your business has no phone number or email, so visitors have no way to reach you.', 'Edit your answers', $links['answers'] ?? null);
        } elseif (! $hasContactSection) {
            $add('contact', self::WARN, 'Contact details', 'Your website does not show your phone or email on any page.', 'Manage pages', $links['pages'] ?? null);
        } else {
            $add('contact', self::OK, 'Contact details', 'Your phone and email are shown to visitors.');
        }

        // 7. The main button works.
        $navigation = $pages->map(fn ($page) => ['uid' => $page->uid, 'slug' => $page->slug, 'url' => '#', 'has_form' => collect($page->sections ?? [])->contains(fn ($section) => ($section['type'] ?? null) === 'form')])->all();
        $cta = $this->cta->resolve($business, $navigation);
        $ctaLabel = ['booking' => 'online booking', 'form' => 'your quote form', 'contact_page' => 'your contact page', 'phone' => 'your phone number', 'email' => 'your email'][$cta['kind'] ?? ''] ?? null;
        $add('cta', $cta === null ? self::FAIL : self::OK, 'Main button', $cta === null ? 'There is no way for a visitor to book or contact you from the main button.' : 'Your main button leads to ' . $ctaLabel . '.', $cta === null ? 'Edit your answers' : null, $cta === null ? ($links['answers'] ?? null) : null);

        // 8. Service pages.
        $services = $this->strategy->eligibleServices($business);
        $servicePageSlugs = $pages->pluck('slug')->filter(fn ($slug) => str_starts_with((string) $slug, 'service-'))->all();
        $withoutPage = $services->filter(fn ($service) => ! in_array('service-' . \Illuminate\Support\Str::slug($service->slug ?: $service->name), $servicePageSlugs, true))->pluck('name')->all();
        $add('service_pages', $withoutPage === [] ? self::OK : self::WARN, 'Service pages', $withoutPage === [] ? 'Every service has its own page.' : count($withoutPage) . ' service(s) have no page of their own: ' . implode(', ', array_slice($withoutPage, 0, 5)) . '. A website has room for at most ' . WebsitePageStrategy::MAX_TOTAL_PAGES . ' pages; they are still listed on your Services page.');

        // 9. Prices match Packages & Products.
        $sync = $this->catalogReferences->staleness($website);
        $add('packages', $sync['out_of_sync'] ? self::WARN : self::OK, 'Package prices', $sync['out_of_sync'] ? 'Your packages changed after you last published, so the live prices are out of date.' : 'Package names and prices match Packages & Products.', $sync['out_of_sync'] ? 'Publish update' : null, $sync['out_of_sync'] ? ($links['publish'] ?? null) : null);

        // 10. Local details.
        $location = $business?->primaryLocation()->first();
        $hasLocal = $location !== null && trim((string) $location->city) !== '';
        $add('local', $hasLocal ? self::OK : self::WARN, 'Local details', $hasLocal ? 'Your business location is set, so your website can describe where you work.' : 'Add your business location so your website can describe where you work.', $hasLocal ? null : 'Edit your answers', $hasLocal ? null : ($links['answers'] ?? null));

        $counts = array_count_values(array_column($checks, 'status'));

        return ['checks' => $checks, 'summary' => ['ok' => $counts[self::OK] ?? 0, 'warn' => $counts[self::WARN] ?? 0, 'fail' => $counts[self::FAIL] ?? 0]];
    }

    /**
     * @return array<int, string>
     */
    private function referencedAssetUids(Collection $pages): array
    {
        $uids = [];

        foreach ($pages as $page) {
            foreach ($page->sections ?? [] as $section) {
                $data = $section['data'] ?? [];

                foreach (['background_image', 'image'] as $key) {
                    if (! empty($data[$key]) && is_string($data[$key])) {
                        $uids[$data[$key]] = true;
                    }
                }

                foreach (($data['items'] ?? []) as $item) {
                    if (is_array($item) && ! empty($item['image']) && is_string($item['image'])) {
                        $uids[$item['image']] = true;
                    }
                }

                foreach (($data['images'] ?? []) as $imageUid) {
                    if (is_string($imageUid)) {
                        $uids[$imageUid] = true;
                    }
                }
            }
        }

        return array_keys($uids);
    }
}
