<?php

namespace Tests\Unit\Website\Acceptance;

use PHPUnit\Framework\TestCase;
use Tests\Support\WebsiteAcceptance\AcceptanceReport;
use Tests\Support\WebsiteAcceptance\AuditContext;
use Tests\Support\WebsiteAcceptance\PageDoc;
use Tests\Support\WebsiteAcceptance\SeoAudit;
use Tests\Support\WebsiteAcceptance\SiteCrawler;

/**
 * Website V1 full-site acceptance — the audit audited. A strict audit is only
 * worth anything if it demonstrably FAILS on a defective page, so this feeds it
 * one known-good page (must be clean) and then one deliberately broken page per
 * rule (each must report exactly that rule). Pure PHP: no database, no app.
 */
class SeoAuditSelfTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'seo-audit-self-' . uniqid();
        $im = imagecreatetruecolor(1200, 800);

        // x is this Business's folder; y is somebody else's (the file exists, but is not ours).
        foreach (['x', 'y'] as $folder) {
            mkdir($this->root . '/images/websites/' . $folder, 0755, true);
            imagejpeg($im, $this->root . '/images/websites/' . $folder . '/hero.jpg', 80);
        }

        imagedestroy($im);
    }

    protected function tearDown(): void
    {
        foreach (['x', 'y'] as $folder) {
            @unlink($this->root . '/images/websites/' . $folder . '/hero.jpg');
            @rmdir($this->root . '/images/websites/' . $folder);
        }

        @rmdir($this->root . '/images/websites');
        @rmdir($this->root . '/images');
        @rmdir($this->root);
        parent::tearDown();
    }

    private const ORIGIN = 'https://acme.test';

    /**
     * A clean home page. `$replace` swaps exact fragments to introduce one defect.
     *
     * @param  array<string, string>  $replace
     */
    private function html(array $replace = []): string
    {
        $html = <<<'HTML'
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Chicago Photo Booth Rentals — Acme Booths</title>
<meta name="description" content="Acme Booths brings photo booths to weddings and corporate events across Chicago and the suburbs.">
<meta name="robots" content="index, follow">
<meta property="og:title" content="Chicago Photo Booth Rentals — Acme Booths">
<meta property="og:description" content="Acme Booths brings photo booths to weddings and corporate events across Chicago and the suburbs.">
<meta property="og:type" content="website">
<meta property="og:url" content="https://acme.test/">
<meta property="og:image" content="https://acme.test/images/websites/x/hero.jpg">
<meta name="twitter:card" content="summary_large_image">
<link rel="canonical" href="https://acme.test/">
<script type="application/ld+json">{"@context":"https://schema.org","@type":"LocalBusiness","@id":"https://acme.test/#business","name":"Acme Booths","url":"https://acme.test/","telephone":"+13125550147"}</script>
</head><body>
<header><a href="https://acme.test/">Home</a> <a data-testid="header-cta" href="https://acme.test/contact">Request a quote</a></header>
<main>
<div data-section="hero"><section data-testid="site-hero"><img src="/images/websites/x/hero.jpg" width="1200" height="800" alt="" class="wd-hero-bg" fetchpriority="high" decoding="async"><h1>Photo booths for Chicago events</h1><p>Instant sharing and an attendant.</p></section></div>
<div data-section="text"><h2>Why choose us</h2><p>Acme Booths serves Chicago with professional lighting. Call (312) 555-0147 today.</p></div>
</main>
<footer><a href="https://acme.test/contact">Contact</a></footer>
</body></html>
HTML;

        return strtr($html, $replace);
    }

    /** @param  array<string, string>  $replace  @return array<int, string> the failing check names */
    private function failures(array $replace = [], ?\Closure $fetch = null, ?array $manifestOverride = null): array
    {
        $html = $this->html($replace);
        $fetch ??= fn (string $url) => ['status' => 200, 'body' => '<html><body>ok</body></html>', 'headers' => [], 'location' => null];
        $crawler = new SiteCrawler($fetch);
        $report = new AcceptanceReport();
        $report->context('custom_domain', 'self-test');

        $entry = $manifestOverride ?? ['uid' => 'u1', 'slug' => null, 'title' => 'Home', 'type' => 'home', 'url' => self::ORIGIN . '/', 'noindex' => false, 'service' => null, 'area' => null];
        $ctx = new AuditContext('custom_domain', 'Acme Booths', self::ORIGIN, self::ORIGIN, true, $this->root, [$entry], ['images/websites/x'], ['Essential' => 'USD 699.00']);
        $doc = new PageDoc($entry['url'], 200, $html, ['x-robots-tag' => 'index, follow']);

        (new SeoAudit($report, $crawler))->auditSite([$entry['url'] => $doc], $ctx);

        return array_values(array_unique(array_column($report->failures(), 'check')));
    }

    public function test_a_clean_page_has_no_failures_apart_from_site_level_navigation_it_cannot_have_alone(): void
    {
        $this->assertSame([], array_values(array_diff($this->failures(), ['page_reachable_from_header_or_footer'])), 'The control page must be clean, or every other assertion here is meaningless.');
    }

    public static function defects(): array
    {
        return [
            'missing title' => [['<title>Chicago Photo Booth Rentals — Acme Booths</title>' => ''], 'title_single_nonempty'],
            'generic title' => [['<title>Chicago Photo Booth Rentals — Acme Booths</title>' => '<title>Home</title>'], 'title_useful'],
            'missing description' => [['<meta name="description" content="Acme Booths brings photo booths to weddings and corporate events across Chicago and the suburbs.">' => ''], 'description_single_nonempty'],
            'irrelevant description' => [['<meta name="description" content="Welcome to our website, we hope that you enjoy your visit today and tomorrow.">' => '<meta name="description" content="Welcome to our website, we hope that you enjoy your visit today and tomorrow.">', '<meta name="description" content="Acme Booths brings photo booths to weddings and corporate events across Chicago and the suburbs.">' => '<meta name="description" content="Welcome to our website, we hope that you enjoy your visit today and tomorrow.">'], 'description_relevant'],
            'wrong canonical' => [['<link rel="canonical" href="https://acme.test/">' => '<link rel="canonical" href="https://preview.example/x">'], 'canonical_correct'],
            'two h1' => [['<h2>Why choose us</h2>' => '<h1>Another</h1>'], 'h1_exactly_one'],
            'no h1' => [['<h1>Photo booths for Chicago events</h1>' => '<h2>Photo booths for Chicago events</h2>'], 'h1_exactly_one'],
            'skipped heading level' => [['<h2>Why choose us</h2>' => '<h4>Why choose us</h4>'], 'heading_hierarchy'],
            'empty heading' => [['<h2>Why choose us</h2>' => '<h2> </h2>'], 'no_empty_headings'],
            'lorem ipsum' => [['Instant sharing and an attendant.' => 'Lorem ipsum dolor sit amet.'], 'no_placeholder_or_broken_copy'],
            'unresolved token' => [['Instant sharing and an attendant.' => 'Hello {{ business_name }}'], 'no_placeholder_or_broken_copy'],
            'undefined leak' => [['Instant sharing and an attendant.' => 'Serving undefined today'], 'no_placeholder_or_broken_copy'],
            'double full stop' => [['Instant sharing and an attendant.' => 'Serving Chicago with Acme Co..'], 'no_placeholder_or_broken_copy'],
            'raw blade' => [['Instant sharing and an attendant.' => '@include(partial)'], 'no_placeholder_or_broken_copy'],
            'unformatted phone' => [['Call (312) 555-0147 today.' => 'Call 3125550147 today.'], 'phone_number_formatted'],
            'hero lazy' => [[' fetchpriority="high"' => ' loading="lazy"'], 'hero_eager_high_priority'],
            'image without dimensions' => [[' width="1200" height="800"' => ''], 'image_dimensions_declared'],
            'wrong dimensions' => [['width="1200" height="800"' => 'width="600" height="800"'], 'image_dimensions_declared'],
            'missing image file' => [['/images/websites/x/hero.jpg' => '/images/websites/x/gone.jpg'], 'image_internal_and_exists'],
            'foreign image folder' => [['/images/websites/x/hero.jpg' => '/images/websites/y/hero.jpg'], 'image_owned_by_this_business'],
            'meaningless alt on a content image' => [['alt="" class="wd-hero-bg"' => 'alt="IMG_0042.jpg" class="photo"'], 'image_alt_text'],
            'noindex on a page that should index' => [['<meta name="robots" content="index, follow">' => '<meta name="robots" content="noindex, follow">'], 'robots_directive_matches_page_setting'],
            'invented rating schema' => [['"telephone":"+13125550147"' => '"telephone":"+13125550147","aggregateRating":{"ratingValue":"5"}'], 'schema_no_invented_ratings'],
            'wrong business in schema' => [['"name":"Acme Booths"' => '"name":"Someone Else"'], 'schema_business_identity'],
            'schema with an internal id' => [['"url":"https://acme.test/"' => '"url":"https://acme.test/?u=6a93c068-5b88-471f-bdba-b3810966119c"'], 'schema_no_internal_ids'],
            'invalid schema json' => [['"@type":"LocalBusiness",' => '"@type":"LocalBusiness" ,,'], 'schema_valid_json'],
            'duplicate id' => [['<div data-section="text">' => '<div data-section="text" id="a"><span id="a"></span>'], 'no_duplicate_ids'],
            'brand repeated in the title' => [['<title>Chicago Photo Booth Rentals — Acme Booths</title>' => '<title>Acme Booths — Photo booths | Acme Booths</title>'], 'title_brand_once'],
            'title too long for a search result' => [['<title>Chicago Photo Booth Rentals — Acme Booths</title>' => '<title>Chicago Photo Booth Rentals For Weddings, Corporate Events And Birthday Parties — Acme Booths</title>'], 'title_not_truncated_in_results'],
            'og:url not the canonical' => [['<meta property="og:url" content="https://acme.test/">' => '<meta property="og:url" content="https://acme.test/other">'], 'social_og_url_equals_canonical'],
            'no og:image' => [['<meta property="og:image" content="https://acme.test/images/websites/x/hero.jpg">' => ''], 'social_og_image'],
            'og:image from another folder' => [['https://acme.test/images/websites/x/hero.jpg">
<meta name' => 'https://acme.test/elsewhere/hero.jpg">
<meta name'], 'social_og_image_is_owner_media'],
            'no twitter card' => [['<meta name="twitter:card" content="summary_large_image">' => ''], 'social_twitter_card'],
            'schema url is the page not the site' => [['"url":"https://acme.test/"' => '"url":"https://acme.test/about"'], 'schema_canonical_url'],
            'schema without a site-wide id' => [['"@id":"https://acme.test/#business",' => ''], 'schema_one_site_entity'],
        ];
    }

    /**
     * @dataProvider defects
     *
     * @param  array<string, string>  $replace
     */
    public function test_each_defect_is_caught_by_the_rule_that_owns_it(array $replace, string $check): void
    {
        $failures = $this->failures($replace);

        $this->assertContains($check, $failures, 'The audit let this defect through. Failures reported: ' . json_encode($failures));
    }

    public function test_a_broken_internal_link_is_caught_and_a_redirect_loop_is_a_separate_finding(): void
    {
        $broken = $this->failures([], fn (string $url) => ['status' => str_ends_with($url, '/contact') ? 404 : 200, 'body' => '<html></html>', 'headers' => [], 'location' => null]);
        $this->assertContains('internal_links_resolve', $broken);

        $loop = $this->failures([], function (string $url) {
            // /contact and /contact/ redirect to each other forever.
            return str_ends_with($url, '/contact') || str_ends_with($url, '/contact/')
                ? ['status' => 302, 'body' => '', 'headers' => [], 'location' => str_ends_with($url, '/') ? rtrim($url, '/') : $url . '/']
                : ['status' => 200, 'body' => '<html></html>', 'headers' => [], 'location' => null];
        });
        $this->assertContains('no_redirect_loops', $loop, 'A redirect that never settles must be reported.');
    }

    public function test_a_wrong_package_price_and_malformed_currency_are_caught(): void
    {
        $entry = ['uid' => 'p1', 'slug' => 'packages', 'title' => 'Packages', 'type' => 'packages', 'url' => self::ORIGIN . '/packages', 'noindex' => false, 'service' => null, 'area' => null];

        $wrong = $this->failures(['Why choose us' => 'Essential USD 799.00'], null, $entry);
        $this->assertContains('package_price_matches_catalog', $wrong);

        $malformed = $this->failures(['Why choose us' => 'Essential USD 1299.00'], null, $entry);
        $this->assertContains('currency_well_formed', $malformed);
    }

    public function test_sitemap_and_robots_checks_catch_a_missing_url_a_preview_url_and_a_blocked_site(): void
    {
        $crawler = new SiteCrawler(fn (string $url) => ['status' => 200, 'body' => '', 'headers' => [], 'location' => null]);
        $report = new AcceptanceReport();
        $report->context('custom_domain', 'self-test');
        $audit = new SeoAudit($report, $crawler);

        $audit->auditSitemap('<urlset><url><loc>https://acme.test/</loc></url><url><loc>https://acme.test/workspaces/1/preview/x</loc></url></urlset>', ['https://acme.test/', 'https://acme.test/services'], 'https://acme.test');
        $audit->auditRobots("User-agent: *\nDisallow: /\n");

        $failed = array_column($report->failures(), 'check');
        $this->assertContains('sitemap_matches_indexable_pages', $failed);
        $this->assertContains('sitemap_canonical_urls_only', $failed);
        $this->assertContains('robots_does_not_block_site', $failed);
    }

    public function test_a_robots_file_must_carry_the_sitemap_line_and_unix_line_endings(): void
    {
        $crawler = new SiteCrawler(fn (string $url) => ['status' => 200, 'body' => '', 'headers' => [], 'location' => null]);

        $good = new AcceptanceReport();
        $good->context('custom_domain', 'self-test');
        (new SeoAudit($good, $crawler))->auditRobots("User-agent: *\nDisallow:\n\nSitemap: https://acme.test/sitemap.xml\n", 'https://acme.test/sitemap.xml');
        $this->assertSame([], $good->failures());

        // No Sitemap line, and Windows line endings: each is its own finding.
        $bad = new AcceptanceReport();
        $bad->context('custom_domain', 'self-test');
        (new SeoAudit($bad, $crawler))->auditRobots("User-agent: *\r\nDisallow:\r\n", 'https://acme.test/sitemap.xml');
        $this->assertEqualsCanonicalizing(['robots_sitemap_directive', 'robots_unix_line_endings'], array_column($bad->failures(), 'check'));
    }
}
