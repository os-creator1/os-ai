<?php

namespace Tests\Feature\Website\Acceptance;

use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Catalog\CatalogItemManager;
use App\Library\Catalog\CatalogMoney;
use App\Library\Website\Design\WebsiteDesigns;
use App\Library\Website\Media\ImageVariants;
use App\Library\Website\WebsiteCatalogReferences;
use App\Library\Website\WebsitePageStrategy;
use App\Models\BusinessBackdropImage;
use App\Models\CatalogItem;
use App\Models\CatalogItemImage;
use App\Models\Website;
use App\Models\WebsiteAsset;
use App\Models\WebsiteRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\WebsiteAcceptance\AcceptanceReport;
use Tests\Support\WebsiteAcceptance\AuditContext;
use Tests\Support\WebsiteAcceptance\PageDoc;
use Tests\Support\WebsiteAcceptance\PhotoBoothFixture;
use Tests\Support\WebsiteAcceptance\SeoAudit;
use Tests\Support\WebsiteAcceptance\SiteCrawler;
use Tests\TestCase;

/**
 * Website V1 — FULL-SITE ACCEPTANCE.
 *
 * "If a real Photo Booth company signed up today and created its website,
 * would the whole resulting site be complete, coherent, technically correct,
 * responsive and SEO-ready?" One deterministic bot answers it, end to end,
 * through the same routes a customer uses (no Website row is ever written by
 * hand):
 *
 *   fixture Business → setup wizard (every screen) → gallery + logo + large
 *   hero uploads → Review → Generate (fake AI) → Preview → Studio + Health →
 *   crawl EVERY preview page → Publish → crawl EVERY public page (custom
 *   domain and platform path) → strict SEO / technical audit across the whole
 *   site → the same content through ALL FOUR templates → package-sync
 *   regression → legacy-asset (no derivatives) fallback.
 *
 * The AI seam is a deterministic fake: this proves the pipeline and the
 * output architecture, NOT live-AI copy quality (which stays unverified).
 *
 * Output: a human-readable report and a machine-readable JSON, written to
 * WEBSITE_ACCEPTANCE_REPORT_DIR (default: the system temp dir).
 */
class WebsiteFullSiteAcceptanceTest extends TestCase
{
    use RefreshDatabase;
    use RunsOwnerJourney;

    private AcceptanceReport $report;

    private Website $website;

    private $business;

    private $workspace;

    private string $domain = PhotoBoothFixture::DOMAIN;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSmartWizard();
        $this->useDisposablePublicRoot();
        $this->report = new AcceptanceReport();
    }

    protected function tearDown(): void
    {
        $this->removeDisposablePublicRoot();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- surfaces

    /**
     * The app's own host, always: route() would otherwise follow the LAST request's host
     * (after a visit to the custom domain it would build custom-domain URLs, and the
     * platform correctly refuses a POST there).
     */
    protected function wizardUrl(object $workspace, object $business, string $route, array $extra = []): string
    {
        return 'http://127.0.0.1' . route('customer.workspaces.businesses.website.' . $route, array_merge([$workspace->uid, $business->uid], $extra), false);
    }

    private function appUrl(string $name, array $parameters): string
    {
        return 'http://127.0.0.1' . route($name, $parameters, false);
    }

    /** @return \Closure(string): array{status: int, body: string, headers: array<string, string>, location: ?string} */
    private function fetcher(): \Closure
    {
        return function (string $url): array {
            $response = $this->get($url);
            $headers = [];
            foreach ($response->headers->all() as $name => $values) {
                $headers[strtolower($name)] = (string) ($values[0] ?? '');
            }

            return [
                'status' => $response->getStatusCode(),
                'body' => (string) $response->getContent(),
                'headers' => $headers,
                'location' => $response->headers->get('Location'),
            ];
        };
    }

    private function previewUrl(string $uid): string
    {
        return $this->wizardUrl($this->workspace, $this->business, 'preview', [$uid]);
    }

    private function pageUrl(string $surface, $page): string
    {
        return match ($surface) {
            'preview' => $this->previewUrl($page->uid),
            'platform' => $page->is_home ? $this->appUrl('public.website.home', [$this->website->public_id]) : $this->appUrl('public.website.page', [$this->website->public_id, $page->slug]),
            default => 'https://' . $this->domain . ($page->is_home ? '/' : '/' . $page->slug),
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function manifest(string $surface): array
    {
        $entries = [];

        foreach ($this->website->fresh()->pages()->orderBy('sort_order')->orderBy('id')->get() as $page) {
            $slug = $page->slug;
            $type = match (true) {
                (bool) $page->is_home => 'home',
                str_starts_with((string) $slug, 'service-') => 'service',
                str_starts_with((string) $slug, 'serving-') => 'location',
                default => match ($slug) {
                    'services' => 'services_overview',
                    'packages' => 'packages',
                    'gallery' => 'gallery',
                    'photo-booth-about' => 'about',
                    'photo-booth-faq' => 'faq',
                    'photo-booth-contact' => 'contact',
                    default => 'other',
                },
            };

            $entries[] = [
                'uid' => $page->uid,
                'slug' => $slug,
                'title' => $page->title,
                'type' => $type,
                'url' => $this->pageUrl($surface, $page),
                'noindex' => (bool) $page->noindex,
                'service' => $type === 'service' ? $page->title : null,
                'area' => $type === 'location' ? Str::headline(substr((string) $slug, strlen('serving-'))) : null,
            ];
        }

        return $entries;
    }

    /** @return array<string, string> package name => canonical price label, from the catalog (the one truth) */
    private function packageTruth(): array
    {
        $truth = [];
        foreach (CatalogItem::where('business_id', $this->business->id)->where('type', 'package')->whereNull('archived_at')->orderBy('position')->get() as $item) {
            $truth[$item->name] = CatalogMoney::format($item->price_minor, $item->currency_code);
        }

        return $truth;
    }

    /** @return array<int, string> */
    private function allowedImageDirs(): array
    {
        $paths = WebsiteAsset::where('website_id', $this->website->id)->pluck('path')->all();
        $paths = array_merge(
            $paths,
            CatalogItemImage::whereIn('catalog_item_id', CatalogItem::where('business_id', $this->business->id)->pluck('id'))->pluck('path')->all(),
            BusinessBackdropImage::query()->whereIn('business_backdrop_id', \App\Models\BusinessBackdrop::where('business_id', $this->business->id)->pluck('id'))->pluck('path')->all(),
        );

        return array_values(array_unique(array_map(fn ($p) => dirname($p), $paths)));
    }

    private function context(string $surface): AuditContext
    {
        $origin = match ($surface) {
            'custom_domain' => 'https://' . $this->domain,
            default => rtrim((string) parse_url($this->previewUrl('x'), PHP_URL_SCHEME), ':') . '://' . parse_url($this->previewUrl('x'), PHP_URL_HOST),
        };

        return new AuditContext(
            surface: $surface,
            businessName: PhotoBoothFixture::NAME,
            origin: $origin,
            canonicalOrigin: $surface === 'preview' ? null : 'https://' . $this->domain,
            indexingAllowed: $surface === 'custom_domain',
            publicRoot: $this->publicRoot,
            manifest: $this->manifest($surface),
            allowedImageDirs: $this->allowedImageDirs(),
            packages: $this->packageTruth(),
        );
    }

    /**
     * Crawl every page a surface exposes (starting from the manifest, then following
     * the pages' own links) and run the audit across the whole site.
     *
     * @return array<string, PageDoc>
     */
    private function auditSurface(string $surface, string $template, bool $withSitemapAndRobots = false): array
    {
        $this->report->context($surface, $template);
        $ctx = $this->context($surface);
        $crawler = new SiteCrawler($this->fetcher());

        $host = parse_url($ctx->origin, PHP_URL_HOST);
        $prefix = match ($surface) {
            'preview' => '/workspaces/' . $this->workspace->uid . '/businesses/' . $this->business->uid . '/website/preview/',
            'platform' => '/sites/' . $this->website->public_id,
            default => '/',
        };

        $isPage = fn (string $url) => parse_url($url, PHP_URL_HOST) === $host
            && str_starts_with((string) parse_url($url, PHP_URL_PATH), $prefix)
            && ! preg_match('#^/(images|css|js)/#', (string) parse_url($url, PHP_URL_PATH));

        $docs = $crawler->crawl(array_column($ctx->manifest, 'url'), $isPage);

        // Debug aid: WEBSITE_ACCEPTANCE_SAVE_HTML=<dir> keeps every crawled page for inspection.
        if ($saveDir = getenv('WEBSITE_ACCEPTANCE_SAVE_HTML')) {
            @mkdir($saveDir, 0755, true);
            foreach ($ctx->manifest as $entry) {
                if (isset($docs[$entry['url']])) {
                    file_put_contents($saveDir . '/' . $surface . '-' . Str::slug($template) . '-' . ($entry['slug'] ?? 'home') . '.html', $docs[$entry['url']]->html);
                }
            }
        }

        $this->exportForBrowserReview($surface, $template, $ctx, $docs);

        $audit = new SeoAudit($this->report, $crawler);
        $audit->auditSite($docs, $ctx);

        $this->report->expect(
            count($docs) >= count($ctx->manifest),
            'site',
            'every_generated_page_crawled',
            count(array_filter($docs, fn ($d) => $d->status === 200)) . ' of ' . count($ctx->manifest) . ' manifest pages fetched OK (' . count($docs) . ' URLs crawled)',
        );

        $stray = array_diff(array_keys($docs), array_column($ctx->manifest, 'url'));
        $this->report->expect($stray === [], 'site', 'no_unexpected_pages_reachable', $stray === [] ? 'the crawl reached exactly the manifest pages' : 'also reached: ' . implode(', ', array_slice($stray, 0, 4)));

        if ($withSitemapAndRobots) {
            $intended = array_column(array_filter($ctx->manifest, fn ($e) => ! $e['noindex']), 'url');
            $audit->auditSitemap($crawler->get('https://' . $this->domain . '/sitemap')['body'], $intended, 'https://' . $this->domain);
            $audit->auditRobots((string) file_get_contents(base_path('public/robots.txt')));
        }

        return $docs;
    }

    /**
     * Browser review aid: WEBSITE_ACCEPTANCE_EXPORT_DIR=<dir> writes the PUBLISHED site of each template
     * as a static, browsable copy (exact HTML, the real CSS, the real uploaded images and derivatives;
     * only the host in links is made relative) so the same fixture can be inspected at 390/768/1440.
     *
     * @param  array<string, PageDoc>  $docs
     */
    private function exportForBrowserReview(string $surface, string $template, AuditContext $ctx, array $docs): void
    {
        $dir = getenv('WEBSITE_ACCEPTANCE_EXPORT_DIR');

        if (! $dir || $surface !== 'custom_domain' || str_contains($template, 'after package')) {
            return;
        }

        $slug = Str::slug($template);
        @mkdir($dir . '/' . $slug, 0755, true);
        @mkdir($dir . '/css', 0755, true);
        copy(base_path('public/css/website-public.css'), $dir . '/css/website-public.css');
        copy(base_path('public/css/website-design.css'), $dir . '/css/website-design.css');

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->publicRoot . '/images', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($this->publicRoot) + 1);
            @mkdir(dirname($dir . '/' . $relative), 0755, true);
            copy($file->getPathname(), $dir . '/' . $relative);
        }

        foreach ($ctx->manifest as $entry) {
            $html = $docs[$entry['url']]->html;
            $html = str_replace(['http://127.0.0.1/images/', 'https://' . $this->domain . '/images/'], '/images/', $html);
            $html = preg_replace('#https://' . preg_quote($this->domain, '#') . '/css/#', '/css/', $html);

            foreach ($ctx->manifest as $target) {
                $to = '/' . $slug . '/' . ($target['slug'] ?? 'home') . '.html';
                $html = str_replace('href="' . $target['url'] . '"', 'href="' . $to . '"', $html);
            }

            file_put_contents($dir . '/' . $slug . '/' . ($entry['slug'] ?? 'home') . '.html', $html);
        }
    }

    /** Publish through the real route, ensuring the custom domain is Active. */
    private function publish(): void
    {
        $this->post($this->wizardUrl($this->workspace, $this->business, 'publish'))->assertRedirect();
        // The owner lands back on Studio, which consumes the "Website published." flash exactly as a browser would.
        $this->get($this->wizardUrl($this->workspace, $this->business, 'studio.show'))->assertOk();
        $this->website = $this->website->fresh();
        app('cache')->flush();

        if ($this->website->domains()->where('domain', $this->domain)->doesntExist()) {
            $this->website->domains()->create(['domain' => $this->domain, 'is_primary' => true, 'status' => WebsiteDomainStatus::Active, 'verification_token' => 'acceptance', 'verified_at' => now(), 'activated_at' => now()]);
        }
    }

    /** Preview and public must say the same thing on every page. */
    private function assertPreviewMatchesPublic(string $template, array $previewDocs, array $publicDocs): void
    {
        $this->report->context('parity', $template);
        $previewManifest = $this->manifest('preview');
        $publicManifest = $this->manifest('custom_domain');

        foreach ($previewManifest as $i => $entry) {
            $preview = $previewDocs[$entry['url']] ?? null;
            $public = $publicDocs[$publicManifest[$i]['url']] ?? null;
            $label = $entry['slug'] ?? 'home';

            // The quote form is the one deliberate difference: Preview renders it inert.
            $same = $preview !== null && $public !== null && $preview->mainTextExcluding(['form']) === $public->mainTextExcluding(['form'])
                && ($preview->h1s() === $public->h1s());
            $this->report->expect($same, $label, 'preview_matches_published', $same ? 'identical visible content' : 'preview and published differ');
        }
    }

    // -------------------------------------------------------------------- test

    public function test_a_realistic_photo_booth_company_gets_a_complete_seo_ready_website_in_every_template(): void
    {
        $designs = array_values(WebsiteDesigns::all());
        $first = $designs[0];

        // ---- 1. The owner journey, through every real screen, up to Generate.
        [, $this->business, $this->workspace, $this->website] = $this->runOwnerJourneyThroughGenerate($first->templateKey);

        $pageCount = $this->website->pages()->count();
        $this->report->context('lifecycle');
        $this->report->expect($pageCount === WebsitePageStrategy::MAX_TOTAL_PAGES, 'site', 'generated_page_count', $pageCount . ' pages generated (canonical ceiling ' . WebsitePageStrategy::MAX_TOTAL_PAGES . ')');
        $this->report->expect($this->website->template_key === $first->templateKey && $this->website->pages()->where('is_home', true)->count() === 1, 'site', 'template_and_single_home', $this->website->template_key);
        $this->assertSame(array_values(array_unique($this->fakeAi->requests)), $this->fakeAi->requests, 'The fake AI was asked for each page exactly once.');
        $this->report->expect(
            WebsiteAsset::where('website_id', $this->website->id)->where('purpose', 'logo')->count() === 1
                && WebsiteAsset::where('website_id', $this->website->id)->where('purpose', 'hero')->count() === 1
                && WebsiteAsset::where('website_id', $this->website->id)->where('purpose', 'gallery')->count() === 8,
            'site',
            'owner_assets_stored',
            'one logo, one hero and eight gallery photos stored',
        );

        // Generated pages start hidden from search (documented), and Studio Health says so.
        $this->report->expect($this->website->pages()->where('noindex', false)->count() === 0, 'site', 'generated_pages_start_noindex', 'every generated page is noindex until the owner reviews');
        $studio = $this->get($this->wizardUrl($this->workspace, $this->business, 'studio.show'))->assertOk()->getContent();
        $this->report->expect(str_contains($studio, 'Pages visible to search engines') && str_contains($studio, 'Template 1'), 'site', 'studio_and_health_render', 'Studio shows the look card and the search-visibility health check');
        $pagesScreen = $this->get($this->wizardUrl($this->workspace, $this->business, 'pages.index'))->assertOk()->getContent();
        $this->report->expect(str_contains($pagesScreen, 'data-testid="allow-indexing"'), 'site', 'owner_can_allow_indexing_in_one_step', 'the Pages screen offers the one-step action');

        // ---- 2. Crawl EVERY preview page and audit the whole site (template 1).
        $previewDocs = $this->auditSurface('preview', $first->label);

        // ---- 3. Publish; the as-generated public site is consistently noindex with an empty sitemap.
        $this->publish();
        $this->report->context('as_generated', $first->label);
        $crawler = new SiteCrawler($this->fetcher());
        $robots = [];
        foreach ($this->manifest('custom_domain') as $entry) {
            $doc = new PageDoc($entry['url'], $crawler->get($entry['url'])['status'], $crawler->get($entry['url'])['body'], $crawler->get($entry['url'])['headers']);
            $robots[] = $doc->status === 200 && str_starts_with((string) $doc->robotsMeta(), 'noindex') && $doc->jsonLd() === [];
        }
        $this->report->expect(! in_array(false, $robots, true) && substr_count($crawler->get('https://' . $this->domain . '/sitemap')['body'], '<loc>') === 0, 'site', 'noindex_enforced_consistently_as_generated', count($robots) . ' pages noindex with no structured data, sitemap empty');

        // ---- 4. The owner has read the pages: one click lets search engines find them; publish the update.
        $this->post($this->wizardUrl($this->workspace, $this->business, 'pages.allowIndexing'))->assertRedirect();
        $this->report->context('lifecycle');
        $this->report->expect($this->website->pages()->where('noindex', true)->count() === 0, 'site', 'allow_indexing_clears_every_page', 'no page is hidden from search any more');
        $health = app(\App\Library\Website\WebsiteHealthChecker::class)->check($this->website->fresh(), ['pages' => '/pages']);
        $this->report->expect(collect($health['checks'])->firstWhere('key', 'indexing')['status'] === 'ok', 'site', 'health_reports_indexing_ok', 'Health: pages visible to search engines');
        $this->publish();

        // ---- 5. Every template: the SAME content, crawled in Preview and as the published site.
        foreach ($designs as $index => $design) {
            if ($index > 0) {
                $this->post($this->wizardUrl($this->workspace, $this->business, 'rebuild'), ['template_key' => $design->templateKey, 'mode' => 'look_only', 'confirm_rebuild' => '1'])->assertRedirect();
                $this->assertSame($design->templateKey, $this->website->fresh()->template_key);
                $this->assertSame($pageCount, $this->website->pages()->count(), 'A look-only change keeps every page.');
                $previewDocs = $this->auditSurface('preview', $design->label);
                $this->publish();
            }

            $publicDocs = $this->auditSurface('custom_domain', $design->label, true);
            $this->auditSurface('platform', $design->label);
            $this->assertPreviewMatchesPublic($design->label, $previewDocs, $publicDocs);

            // The template's own markup is what was rendered.
            $home = $publicDocs['https://' . $this->domain . '/'];
            $this->report->context('templates', $design->label);
            $this->report->expect(str_contains($home->html, 'wd wd-' . $design->key), 'home', 'renders_its_own_template', 'body class wd-' . $design->key);

            // The Hero image the owner uploaded on Review is the hero of the Home page AND of every other page.
            $heroStem = pathinfo(WebsiteAsset::where('website_id', $this->website->id)->where('purpose', 'hero')->value('path'), PATHINFO_FILENAME);
            foreach ($this->manifest('custom_domain') as $entry) {
                $heroImg = collect($publicDocs[$entry['url']]->images())->firstWhere('in_hero', true);
                $this->report->expect($heroImg !== null && str_contains($heroImg['src'], $heroStem), $entry['slug'] ?? 'home', 'hero_is_the_owners_hero_image', $heroImg['src'] ?? 'no hero image');
            }
        }

        // ---- 6. Legacy asset (no derivatives) falls back to the original; publishing backfills it.
        $this->report->context('legacy_asset', end($designs)->label);
        $this->legacyAssetFallback();

        // ---- 7. Package sync: preview truth, frozen published value, publish update, audit still green.
        $this->packageSyncRegression(end($designs)->label);

        $directory = getenv('WEBSITE_ACCEPTANCE_REPORT_DIR') ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'website-v1-full-site-acceptance';
        $this->report->write($directory, 'website-v1-full-site-acceptance', 'Website V1 Full-Site Acceptance', [
            'Fixture business' => PhotoBoothFixture::NAME,
            'Pages generated' => $pageCount,
            'Templates' => implode(', ', array_map(fn ($d) => $d->label, $designs)),
            'Live AI copy quality' => 'NOT verified (deterministic fake AI)',
        ]);

        $this->assertSame([], $this->report->failures(), "Full-site acceptance found defects:\n" . $this->report->toText('Website V1 Full-Site Acceptance'));
    }

    // ------------------------------------------------------ legacy asset

    private function legacyAssetFallback(): void
    {
        $asset = WebsiteAsset::where('website_id', $this->website->id)->where('purpose', 'gallery')->orderBy('id')->firstOrFail();
        $variants = app(ImageVariants::class);
        $this->assertNotSame([], $variants->existing($asset->path), 'The photo starts with derivatives.');

        // Make it a "legacy" asset: its derivatives never existed.
        $variants->delete($asset->path);
        $this->assertSame([], $variants->existing($asset->path));

        $gallery = $this->website->pages()->where('slug', 'gallery')->firstOrFail();
        $html = $this->get($this->previewUrl($gallery->uid))->assertOk()->getContent();
        $originalUrl = $asset->url();
        $tagged = preg_match('/<img[^>]*src="' . preg_quote($originalUrl, '/') . '"[^>]*>/', $html, $m) === 1;
        $this->report->expect($tagged && ! str_contains($m[0], 'srcset=') && is_file(public_path($asset->path)), 'gallery', 'legacy_asset_renders_original', $tagged ? 'original served with a plain src (no srcset), file present' : 'the original was not rendered');

        // Publishing again gives the asset its derivatives (additive) — the fixture is left healthy.
        $this->publish();
        $this->report->expect($variants->existing($asset->path) !== [] && is_file(public_path($asset->path)), 'gallery', 'publish_backfills_derivatives_original_kept', count($variants->existing($asset->path)) . ' derivatives regenerated, original untouched');
    }

    // ------------------------------------------------------ package sync

    private function packageSyncRegression(string $template): void
    {
        $item = CatalogItem::where('business_id', $this->business->id)->where('name', 'Luxe')->firstOrFail();
        $packages = $this->website->pages()->where('slug', 'packages')->firstOrFail();

        $oldLabel = CatalogMoney::format($item->price_minor, $item->currency_code);
        $this->report->context('package_sync', $template);

        // 1. The published site shows the current price.
        $before = $this->get('https://' . $this->domain . '/packages')->assertOk()->getContent();
        $this->report->expect(str_contains($before, $oldLabel), 'packages', 'published_shows_current_price', $oldLabel);
        $this->assertFalse(app(WebsiteCatalogReferences::class)->staleness($this->website->fresh())['out_of_sync']);

        // 2. The one truth (the catalog) changes.
        $this->travel(5)->seconds();
        app(CatalogItemManager::class)->update($this->business, $item, ['price_minor' => 134900, 'currency_code' => 'USD']);
        $newLabel = CatalogMoney::format(134900, 'USD');

        // 3. Website reports out of sync.
        $stale = app(WebsiteCatalogReferences::class)->staleness($this->website->fresh());
        $banner = $this->get($this->wizardUrl($this->workspace, $this->business, 'studio.show'))->assertOk()->getContent();
        $this->report->expect($stale['out_of_sync'] === true && str_contains($banner, 'Your packages changed after you last published') && str_contains($banner, 'Publish update'), 'packages', 'out_of_sync_reported', 'Studio banner: packages changed, Publish update offered');

        // 4. Preview uses the current truth; the published site stays frozen.
        $preview = $this->get($this->previewUrl($packages->uid))->assertOk()->getContent();
        $frozen = $this->get('https://' . $this->domain . '/packages')->assertOk()->getContent();
        $this->report->expect(str_contains($preview, $newLabel) && ! str_contains($preview, $oldLabel), 'packages', 'preview_uses_current_price', $newLabel . ' in Preview');
        $this->report->expect(str_contains($frozen, $oldLabel) && ! str_contains($frozen, $newLabel), 'packages', 'published_frozen_at_old_price', $oldLabel . ' still published');

        // 5. Publish update; the public page now shows the current price, the old revision is unchanged.
        $oldRevision = WebsiteRevision::where('website_id', $this->website->id)->orderByDesc('id')->firstOrFail();
        $this->travel(5)->seconds();
        $this->publish();
        $after = $this->get('https://' . $this->domain . '/packages')->assertOk()->getContent();
        $this->report->expect(str_contains($after, $newLabel) && ! str_contains($after, $oldLabel), 'packages', 'publish_update_shows_new_price', $newLabel . ' now published');
        $this->assertFalse(app(WebsiteCatalogReferences::class)->staleness($this->website->fresh())['out_of_sync']);
        $this->assertStringContainsString($oldLabel, json_encode($oldRevision->fresh()->snapshot), 'The earlier revision is never rewritten.');

        // 6. The whole public site passes the same audit with the new truth.
        $this->auditSurface('custom_domain', $template . ' (after package update)', true);
    }
}
