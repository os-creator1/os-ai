<?php

namespace App\Library\Website;

use App\Enums\Website\WebsiteStatus;
use App\Library\Website\Design\WebsiteCtaResolver;
use App\Library\Website\Seo\WebsiteCrawlFiles;
use App\Library\Website\Seo\WebsiteHeadMeta;
use App\Models\Website;
use App\Models\WebsiteRevision;
use Illuminate\Support\Collection;

/**
 * Website V1 final — the owner-facing "SEO & Website Health": a short list of
 * REAL checks, each computed from this website's actual pages, assets,
 * domain and Business facts, with one plain sentence on what it means and
 * where to fix it. No score, no benchmark, no invented advice and no promise
 * about rankings — only things that are true or false right now.
 *
 * SEO V1 final: a check never reports "good" for something that is not true
 * on the LIVE site. Anything about what search engines can see (indexing,
 * search files, structured data) reads the PUBLISHED snapshot when there is
 * one; draft-only facts (titles, headings, links, photos) are checked on the
 * draft the owner is about to publish, and a separate check says when the
 * draft has moved ahead of the live site.
 *
 * Wording is Good / Needs attention / Action. Technical terms stay out of
 * the sentences; the per-check `items` list names exactly which pages or
 * photos are affected.
 *
 * The deep technical audit of the published snapshot lives in the SEO module
 * (the Site Audit); this is the owner's everyday summary and never replaces it.
 */
final class WebsiteHealthChecker
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /** A rendered title longer than this is usually cut off in search results. */
    private const TITLE_LIMIT = WebsiteHeadMeta::TITLE_SOFT_LIMIT;

    public function __construct(
        private readonly WebsiteCtaResolver $cta,
        private readonly WebsiteCatalogReferences $catalogReferences,
        private readonly WebsitePageStrategy $strategy,
    ) {}

    /**
     * @param  array<string, string|null>  $links  action URLs: publish, domains, photos, pages, answers
     * @return array{checks: array<int, array{key: string, status: string, title: string, detail: string, items: array<int, string>, action: ?array{label: string, url: string}}>, summary: array{ok: int, warn: int, fail: int}}
     */
    public function check(Website $website, array $links = []): array
    {
        $pages = $website->pages()->orderBy('sort_order')->orderBy('id')->get();
        $business = $website->business;
        $siteName = (string) $website->name;
        $checks = [];

        $add = function (string $key, string $status, string $title, string $detail, ?string $actionLabel = null, ?string $actionUrl = null, array $items = []) use (&$checks) {
            $checks[] = [
                'key' => $key,
                'status' => $status,
                'title' => $title,
                'detail' => $detail,
                'items' => array_values($items),
                'action' => $actionLabel !== null && $actionUrl !== null ? ['label' => $actionLabel, 'url' => $actionUrl] : null,
            ];
        };

        $published = $website->status === WebsiteStatus::Published;
        $revision = $published && $website->published_revision_id !== null ? WebsiteRevision::find($website->published_revision_id) : null;
        $live = $revision?->snapshot;
        $livePages = (array) ($live['pages'] ?? []);
        $domain = $website->activePrimaryDomain();

        // 1. Published.
        $add('published', $published ? self::OK : self::WARN, 'Published', $published ? 'Visitors can see your published website.' : 'Your website is not published yet, so visitors cannot see it.', $published ? null : 'Publish', $links['publish'] ?? null);

        // 1b. Changes visitors cannot see yet (draft ahead of the live revision).
        if ($revision !== null) {
            $changed = $this->pagesChangedSince($pages, $livePages, $revision, $website);

            $add(
                'draft_changes',
                $changed === [] ? self::OK : self::WARN,
                'Unpublished changes',
                $changed === [] ? 'Your live website matches what you have edited.' : 'You have edited your website since you last published, so visitors still see the older version.',
                $changed === [] ? null : 'Publish update',
                $changed === [] ? null : ($links['publish'] ?? null),
                $changed,
            );
        }

        // 2. Own domain (platform addresses are never indexed).
        $add('domain', $domain !== null ? self::OK : self::WARN, 'Your own web address', $domain !== null ? 'Your website is on your own domain, so search engines can index it.' : 'Your website is on a preview address. Search engines only index a website on its own domain, so connect one to be found in search.', $domain !== null ? null : 'Connect a domain', $links['domains'] ?? null);

        // 3. Pages hidden from search. Counted on the LIVE site when it is published (what search engines
        // actually see), else on the draft (what will happen on publish). Generated pages start hidden
        // until the owner has read them; a connected domain does not change that.
        $this->indexingCheck($add, $pages, $livePages, $published, $links);

        // 4. Titles (as visitors and search engines see them: with the business name appended once).
        $this->titleCheck($add, $pages, $siteName, $links);

        // 5. Descriptions.
        $this->descriptionCheck($add, $pages, $links);

        // 6. One main heading per page.
        $badHeadings = $pages->filter(fn ($page) => $this->heroCount($page) !== 1)->map(fn ($page) => $page->title.($this->heroCount($page) === 0 ? ' (no main heading)' : ' (more than one main heading)'))->all();
        $add('headings', $badHeadings === [] ? self::OK : self::FAIL, 'Page headings', $badHeadings === [] ? 'Every page has one main heading, which tells visitors and search engines what it is about.' : count($badHeadings).' page(s) do not have exactly one main heading.', $badHeadings === [] ? null : 'Manage pages', $links['pages'] ?? null, $badHeadings);

        // 7. Photos exist and have descriptions.
        $this->photoChecks($add, $website, $pages, $links);

        // 8. Links on your pages.
        $brokenLinks = $this->brokenInternalLinks($pages);
        $add('links', $brokenLinks === [] ? self::OK : self::WARN, 'Links between your pages', $brokenLinks === [] ? 'Every link points to a page that exists.' : count($brokenLinks).' button(s) point to a page that no longer exists. They are hidden on your live website until you fix them.', $brokenLinks === [] ? null : 'Manage pages', $links['pages'] ?? null, $brokenLinks);

        // 9. Contact details (shown to visitors, not only saved).
        $this->contactCheck($add, $pages, $business, $links);

        // 10. The main button works.
        $navigation = $pages->map(fn ($page) => ['uid' => $page->uid, 'slug' => $page->slug, 'url' => '#', 'has_form' => collect($page->sections ?? [])->contains(fn ($section) => ($section['type'] ?? null) === 'form')])->all();
        $cta = $this->cta->resolve($business, $navigation);
        $ctaLabel = ['booking' => 'online booking', 'form' => 'your quote form', 'contact_page' => 'your contact page', 'phone' => 'your phone number', 'email' => 'your email'][$cta['kind'] ?? ''] ?? null;
        $add('cta', $cta === null ? self::FAIL : self::OK, 'Main button', $cta === null ? 'There is no way for a visitor to book or contact you from the main button.' : 'Your main button leads to '.$ctaLabel.'.', $cta === null ? 'Edit your answers' : null, $cta === null ? ($links['answers'] ?? null) : null);

        // 11. Service pages.
        $services = $this->strategy->eligibleServices($business);
        $servicePageSlugs = $pages->pluck('slug')->filter(fn ($slug) => str_starts_with((string) $slug, 'service-'))->all();
        $withoutPage = $services->filter(fn ($service) => ! in_array(WebsiteSlugRules::bounded('service-', (string) ($service->slug ?: $service->name), 'page'), $servicePageSlugs, true))->pluck('name')->all();
        $add('service_pages', $withoutPage === [] ? self::OK : self::WARN, 'Service pages', $withoutPage === [] ? 'Every service has its own page.' : count($withoutPage).' service(s) have no page of their own: '.implode(', ', array_slice($withoutPage, 0, 5)).'. A website has room for at most '.WebsitePageStrategy::MAX_TOTAL_PAGES.' pages; they are still listed on your Services page.');

        // 12. Prices match Packages & Products. Nothing is copied before the first publish.
        if ($revision === null) {
            $add('packages', self::OK, 'Package prices', 'Your package names and prices are copied from Packages & Products when you publish.');
        } else {
            $sync = $this->catalogReferences->staleness($website);
            $add('packages', $sync['out_of_sync'] ? self::WARN : self::OK, 'Package prices', $sync['out_of_sync'] ? 'Your packages changed after you last published, so the live prices may be out of date.' : 'Package names and prices match Packages & Products.', $sync['out_of_sync'] ? 'Publish update' : null, $sync['out_of_sync'] ? ($links['publish'] ?? null) : null);
        }

        // 13. Local details.
        $location = $business?->primaryLocation()->first();
        $hasLocal = $location !== null && trim((string) $location->city) !== '';
        $add('local', $hasLocal ? self::OK : self::WARN, 'Local details', $hasLocal ? 'Your business location is set, so your website can describe where you work.' : 'Add your business location so your website can describe where you work.', $hasLocal ? null : 'Edit your answers', $hasLocal ? null : ($links['answers'] ?? null));

        // 14. What search engines are told about your site (live): official address, sitemap, robots.
        $this->searchFilesCheck($add, $published, $domain, $live, $links);

        // 15. Business details search engines can read (structured data, live).
        if ($published && $live !== null) {
            $facts = (array) ($live['website']['localBusiness'] ?? []);
            $hasName = trim((string) ($facts['name'] ?? '')) !== '';
            $hasContact = ! empty($facts['telephone']) || ! empty($facts['email']) || ! empty($facts['address']);

            $add(
                'schema',
                $hasName && $hasContact ? self::OK : self::WARN,
                'Business details for search engines',
                $hasName && $hasContact ? 'Search engines can read your business name and contact details from your website.' : 'Your live website shows no phone, email or address in a contact section, so search engines have little to show about your business.',
                $hasName && $hasContact ? null : 'Manage pages',
                $hasName && $hasContact ? null : ($links['pages'] ?? null),
            );
        }

        $counts = array_count_values(array_column($checks, 'status'));

        return ['checks' => $checks, 'summary' => ['ok' => $counts[self::OK] ?? 0, 'warn' => $counts[self::WARN] ?? 0, 'fail' => $counts[self::FAIL] ?? 0]];
    }

    private function indexingCheck(callable $add, Collection $pages, array $livePages, bool $published, array $links): void
    {
        $draftHidden = $pages->where('noindex', true)->count();

        if ($published && $livePages !== []) {
            $liveTotal = count($livePages);
            $liveHidden = count(array_filter($livePages, fn ($page) => ! empty($page['seo']['noindex'])));

            if ($liveHidden === 0) {
                $add('indexing', self::OK, 'Pages visible to search engines', 'None of your '.$liveTotal.' live pages is hidden from search engines.');

                return;
            }

            $note = $draftHidden < $liveHidden ? ' You have already allowed more pages since you last published; publish to make that live.' : '';

            $add(
                'indexing',
                self::WARN,
                'Pages visible to search engines',
                $liveHidden.' of '.$liveTotal.' live pages are hidden from search engines, so they will not be found in search even on your own domain. Read your pages, then let search engines find them.'.$note,
                'Review pages',
                $links['pages'] ?? null,
            );

            return;
        }

        $add(
            'indexing',
            $draftHidden === 0 ? self::OK : self::WARN,
            'Pages visible to search engines',
            $draftHidden === 0
                ? 'None of your pages is hidden from search engines. They become visible once you publish.'
                : $draftHidden.' of '.$pages->count().' pages are hidden from search engines, so they will not be found in search once you publish. Read your pages, then let search engines find them.',
            $draftHidden === 0 ? null : 'Review pages',
            $links['pages'] ?? null,
        );
    }

    private function titleCheck(callable $add, Collection $pages, string $siteName, array $links): void
    {
        $rendered = $pages->mapWithKeys(fn ($page) => [$page->uid => WebsiteHeadMeta::title((string) $page->seo_title, (string) $page->title, $siteName)]);

        $missing = $pages->filter(fn ($page) => trim((string) $page->seo_title) === '')->pluck('title')->all();
        $duplicates = $rendered->groupBy(fn ($title) => mb_strtolower($title))->filter(fn (Collection $group) => $group->count() > 1)->map(fn (Collection $group) => $group->first())->values()->all();
        $tooLong = $pages->filter(fn ($page) => mb_strlen($rendered[$page->uid]) > self::TITLE_LIMIT)->pluck('title')->all();

        if ($missing !== []) {
            // The page's own name is used on the site, so this is a nudge, not a failure.
            $add('titles', self::WARN, 'Page titles', count($missing).' page(s) have no search title of their own, so the page name is used instead: '.implode(', ', array_slice($missing, 0, 5)).'.', 'Manage pages', $links['pages'] ?? null, $missing);
        } elseif ($duplicates !== []) {
            $add('titles', self::WARN, 'Page titles', 'Some pages share the same search title: '.implode(', ', array_slice($duplicates, 0, 3)).'. Each page should have its own.', 'Manage pages', $links['pages'] ?? null, $duplicates);
        } elseif ($tooLong !== []) {
            $add('titles', self::WARN, 'Page titles', count($tooLong).' page title(s) are long enough to be cut off in search results: '.implode(', ', array_slice($tooLong, 0, 3)).'.', 'Manage pages', $links['pages'] ?? null, $tooLong);
        } else {
            $add('titles', self::OK, 'Page titles', 'Every page has its own search title.');
        }
    }

    private function descriptionCheck(callable $add, Collection $pages, array $links): void
    {
        $missing = $pages->filter(fn ($page) => trim((string) $page->meta_description) === '')->pluck('title')->all();
        $duplicates = $pages->filter(fn ($page) => trim((string) $page->meta_description) !== '')->groupBy(fn ($page) => mb_strtolower(trim((string) $page->meta_description)))->filter(fn (Collection $group) => $group->count() > 1)->map(fn (Collection $group) => $group->first()->title)->values()->all();

        if ($missing !== []) {
            $add('descriptions', self::WARN, 'Page descriptions', count($missing).' page(s) have no search description: '.implode(', ', array_slice($missing, 0, 5)).'.', 'Manage pages', $links['pages'] ?? null, $missing);
        } elseif ($duplicates !== []) {
            $add('descriptions', self::WARN, 'Page descriptions', 'Some pages share the same search description. Each page should describe itself: '.implode(', ', array_slice($duplicates, 0, 3)).'.', 'Manage pages', $links['pages'] ?? null, $duplicates);
        } else {
            $add('descriptions', self::OK, 'Page descriptions', 'Every page has its own search description.');
        }
    }

    private function photoChecks(callable $add, Website $website, Collection $pages, array $links): void
    {
        $referenced = $this->referencedAssetUids($pages, $website);
        $existing = $referenced === [] ? [] : $website->assets()->whereIn('uid', $referenced)->pluck('uid')->all();
        $missing = array_values(array_diff($referenced, $existing));

        $add(
            'images',
            $missing === [] ? self::OK : self::FAIL,
            'Photos on your pages',
            $missing === [] ? 'Every photo your pages use is in your photo library.' : count($missing).' photo(s) used on your pages are no longer in your photo library, so visitors see a gap.',
            $missing === [] ? null : 'Review photos',
            $missing === [] ? null : ($links['photos'] ?? null),
        );

        $withoutAlt = $existing === [] ? 0 : $website->assets()->whereIn('uid', $existing)->where(fn ($q) => $q->whereNull('alt_text')->orWhere('alt_text', ''))->count();
        $add('alt_text', $withoutAlt === 0 ? self::OK : self::WARN, 'Photo descriptions (alt text)', $withoutAlt === 0 ? 'Every photo on your pages has a description for people using screen readers and for search.' : $withoutAlt.' photo(s) on your pages have no description.', $withoutAlt === 0 ? null : 'Review photos', $links['photos'] ?? null);
    }

    private function contactCheck(callable $add, Collection $pages, $business, array $links): void
    {
        $phone = $business !== null && trim((string) $business->phone) !== '';
        $email = $business !== null && trim((string) $business->email) !== '';

        if (! $phone && ! $email) {
            $add('contact', self::FAIL, 'Contact details', 'Your business has no phone number or email, so visitors have no way to reach you.', 'Edit your answers', $links['answers'] ?? null);

            return;
        }

        $shownPhone = false;
        $shownEmail = false;

        foreach ($pages as $page) {
            foreach ($page->sections ?? [] as $section) {
                if (($section['type'] ?? null) !== 'contact_details') {
                    continue;
                }

                $data = $section['data'] ?? [];
                $shownPhone = $shownPhone || ($phone && ! empty($data['show_phone']));
                $shownEmail = $shownEmail || ($email && ! empty($data['show_email']));
            }
        }

        if (! $shownPhone && ! $shownEmail) {
            $add('contact', self::WARN, 'Contact details', 'Your website does not show your phone or email on any page.', 'Manage pages', $links['pages'] ?? null);

            return;
        }

        $shown = array_filter([$shownPhone ? 'phone' : null, $shownEmail ? 'email' : null]);
        $add('contact', self::OK, 'Contact details', 'Your '.implode(' and ', $shown).' '.(count($shown) > 1 ? 'are' : 'is').' shown to visitors.');
    }

    /**
     * @param  array<string, mixed>|null  $live
     */
    private function searchFilesCheck(callable $add, bool $published, $domain, ?array $live, array $links): void
    {
        if (! $published || $live === null) {
            return;
        }

        if ($domain === null) {
            $add('search_files', self::WARN, 'What search engines are told about your site', 'No sitemap or official page addresses are shared yet, because your website is not on its own domain.', 'Connect a domain', $links['domains'] ?? null);

            return;
        }

        $indexable = count(WebsiteCrawlFiles::indexableUrls((string) $domain->domain, $live));

        if ($indexable === 0) {
            $add('search_files', self::WARN, 'What search engines are told about your site', 'Your sitemap is empty because every live page is hidden from search engines. Let search engines find at least your main pages.', 'Review pages', $links['pages'] ?? null);

            return;
        }

        $add('search_files', self::OK, 'What search engines are told about your site', 'Each page names its official address, your sitemap lists '.$indexable.' page'.($indexable === 1 ? '' : 's').', and nothing is blocked from search engines.');
    }

    /**
     * Titles of pages edited (or added/removed) after the live revision was made.
     *
     * @param  array<int, array<string, mixed>>  $livePages
     * @return array<int, string>
     */
    private function pagesChangedSince(Collection $pages, array $livePages, WebsiteRevision $revision, Website $website): array
    {
        $changed = [];
        $liveUids = array_column($livePages, 'uid');
        $publishedAt = $revision->created_at;

        foreach ($pages as $page) {
            if (! in_array($page->uid, $liveUids, true)) {
                $changed[] = $page->title.' (new page)';
            } elseif ($publishedAt !== null && $page->updated_at !== null && $page->updated_at->gt($publishedAt)) {
                $changed[] = $page->title;
            }
        }

        $draftUids = $pages->pluck('uid')->all();
        foreach ($livePages as $livePage) {
            if (! in_array($livePage['uid'] ?? null, $draftUids, true)) {
                $changed[] = ($livePage['title'] ?? 'A page').' (removed)';
            }
        }

        if ($changed === [] && $publishedAt !== null && $website->updated_at !== null && $website->updated_at->gt($publishedAt)) {
            $changed[] = 'Look and settings';
        }

        return $changed;
    }

    private function heroCount($page): int
    {
        return collect($page->sections ?? [])->where('type', 'hero')->count();
    }

    /**
     * Buttons whose address is a site-relative page ("/services") that no page of this website has.
     *
     * @return array<int, string>
     */
    private function brokenInternalLinks(Collection $pages): array
    {
        $slugs = $pages->where('is_home', false)->pluck('slug')->filter()->map(fn ($slug) => (string) $slug)->all();
        $broken = [];

        foreach ($pages as $page) {
            foreach ($this->urlsIn($page->sections ?? []) as $url) {
                $url = trim((string) $url);

                if ($url === '' || ! str_starts_with($url, '/')) {
                    continue;
                }

                // Protocol-relative addresses point off the site and are never rendered.
                $target = trim($url, '/');

                // Stored photo paths (/images/..., /storage/...) are files, not pages.
                if (str_starts_with($url, '//') || $target === '' || in_array($target, $slugs, true) || preg_match('#^(images|storage)/#', $target) === 1) {
                    continue;
                }

                $broken[$page->title.' → '.$url] = true;
            }
        }

        return array_keys($broken);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, string>
     */
    private function urlsIn(array $sections): array
    {
        $urls = [];
        $walk = function ($value, $key = null) use (&$walk, &$urls) {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $walk($v, $k);
                }

                return;
            }

            if ($key === 'url' && is_string($value)) {
                $urls[] = $value;
            }
        };

        foreach ($sections as $section) {
            $walk($section['data'] ?? []);
        }

        return $urls;
    }

    /**
     * @return array<int, string>
     */
    private function referencedAssetUids(Collection $pages, Website $website): array
    {
        $uids = [];

        foreach (['logo_asset_uid', 'hero_asset_uid'] as $themeKey) {
            $uid = $website->theme[$themeKey] ?? null;
            if (is_string($uid) && $uid !== '') {
                $uids[$uid] = true;
            }
        }

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
