<?php

namespace Tests\Feature\Website;

use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Website\GuidedGeneration\MediaBindingService;
use App\Library\Website\WebsiteHealthChecker;
use App\Library\Website\WebsitePublisher;
use App\Models\Business;
use App\Models\Website;
use App\Models\WebsiteAsset;
use App\Models\WebsitePage;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website V1 final closure — robots.txt Sitemap line, FAQPage schema, Open Graph / Twitter metadata,
 * the CTA-band cleanup, the CSS chevron alignment, and the owner-controlled search visibility rules.
 */
class WebsiteClosureTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private string $publicRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WebsiteTemplateSeeder::class);
        $this->publicRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'website-closure-' . uniqid();
        mkdir($this->publicRoot, 0755, true);
        // The platform's own 404 page reads the build manifest.
        copy(base_path('public/mix-manifest.json'), $this->publicRoot . DIRECTORY_SEPARATOR . 'mix-manifest.json');
        $this->app->usePublicPath($this->publicRoot);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->publicRoot);
        parent::tearDown();
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $path = $directory . DIRECTORY_SEPARATOR . $entry;
                is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
            }
        }

        @rmdir($directory);
    }

    /** @return array{0: Business, 1: Website} */
    private function published(array $pages, string $domain = 'closure-site.test', bool $withDomain = true, array $websiteOverrides = []): array
    {
        [, $business] = $this->entitledTenant(['phone' => '3125550147']);
        $website = $this->createWebsite($business, $websiteOverrides);

        foreach ($pages as $i => $page) {
            WebsitePage::create(array_merge([
                'website_id' => $website->id, 'title' => 'Page ' . $i, 'slug' => $i === 0 ? null : 'page-' . $i, 'is_home' => $i === 0,
                'sections' => [$this->section('hero')], 'seo_title' => 'Seo title ' . $i, 'meta_description' => 'A description for page ' . $i . ' of the site.',
                'noindex' => false, 'sort_order' => $i,
            ], $page));
        }

        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        if ($withDomain) {
            $website->domains()->create(['domain' => $domain, 'is_primary' => true, 'status' => WebsiteDomainStatus::Active, 'verification_token' => 't', 'verified_at' => now(), 'activated_at' => now()]);
        }

        return [$business, $website->fresh()];
    }

    private function jsonLd(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        return array_map(fn ($raw) => json_decode($raw, true), $m[1]);
    }

    // ----------------------------------------------------------- robots.txt

    public function test_a_published_site_on_its_own_domain_serves_a_robots_txt_with_its_sitemap_line(): void
    {
        [, $website] = $this->published([[]]);

        $response = $this->get('http://closure-site.test/robots.txt')->assertOk();

        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        $this->assertSame("User-agent: *\nDisallow:\n\nSitemap: https://closure-site.test/sitemap.xml\n", $response->getContent());
        $this->get('http://closure-site.test/sitemap.xml')->assertOk(); // the line points at something that resolves
        $this->get('http://closure-site.test/sitemap')->assertStatus(301)->assertRedirect('https://closure-site.test/sitemap.xml');
    }

    public function test_no_robots_txt_for_an_alias_an_unpublished_site_the_platform_path_or_preview(): void
    {
        [, $website] = $this->published([[]]);
        $website->domains()->create(['domain' => 'alias-site.test', 'is_primary' => false, 'status' => WebsiteDomainStatus::Active, 'verification_token' => 't', 'verified_at' => now(), 'activated_at' => now()]);

        // An alias redirects to the primary; it never serves its own file with its own host.
        $this->get('http://alias-site.test/robots.txt')->assertStatus(301)->assertRedirect('https://closure-site.test/robots.txt');
        // The platform path never has one (a slug cannot be "robots.txt").
        $this->get('http://127.0.0.1/sites/' . $website->public_id . '/robots.txt')->assertNotFound();

        // An unpublished site has nothing to point a Sitemap line at.
        $website->update(['published_revision_id' => null]);
        app('cache')->flush();
        $this->get('http://closure-site.test/robots.txt')->assertNotFound();
    }

    // ------------------------------------------------------------ FAQ schema

    public function test_faq_schema_is_exactly_the_faq_the_page_shows_and_only_on_that_page(): void
    {
        $faq = ['type' => 'faq', 'data' => ['heading' => 'Questions', 'items' => [
            ['question' => 'How far ahead should we book?', 'answer' => 'As soon as your date is set.'],
            ['question' => 'Is an attendant included?', 'answer' => 'Yes, with every package.'],
        ]]];
        $this->published([
            ['title' => 'Home', 'sections' => [$this->section('hero')]],
            ['title' => 'FAQ', 'slug' => 'faq', 'sections' => [$this->section('hero'), $faq]],
            ['title' => 'Hidden FAQ', 'slug' => 'hidden-faq', 'noindex' => true, 'sections' => [$this->section('hero'), $faq]],
        ]);

        $page = $this->get('http://closure-site.test/faq')->assertOk()->getContent();
        $blocks = array_values(array_filter($this->jsonLd($page), fn ($b) => ($b['@type'] ?? null) === 'FAQPage'));

        $this->assertCount(1, $blocks);
        $this->assertSame('https://schema.org', $blocks[0]['@context']);
        $this->assertSame([
            ['@type' => 'Question', 'name' => 'How far ahead should we book?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'As soon as your date is set.']],
            ['@type' => 'Question', 'name' => 'Is an attendant included?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Yes, with every package.']],
        ], $blocks[0]['mainEntity']);

        // Every Question/Answer in the schema is rendered on the page, verbatim.
        foreach ($blocks[0]['mainEntity'] as $entity) {
            $this->assertStringContainsString('<summary>' . e($entity['name']) . '</summary>', $page);
            $this->assertStringContainsString('<p>' . e($entity['acceptedAnswer']['text']) . '</p>', $page);
        }

        // No FAQ on the page -> no FAQ schema; a noindex page carries no schema at all.
        $home = $this->get('http://closure-site.test/')->assertOk()->getContent();
        $this->assertSame([], array_filter($this->jsonLd($home), fn ($b) => ($b['@type'] ?? null) === 'FAQPage'));
        $this->assertStringNotContainsString('FAQPage', $this->get('http://closure-site.test/hidden-faq')->getContent());
    }

    public function test_an_incomplete_faq_item_is_never_in_the_schema_and_an_empty_faq_emits_nothing(): void
    {
        $builder = new \App\Library\Website\Seo\WebsiteFaqStructuredData();

        $schema = $builder->build([['type' => 'faq', 'data' => ['items' => [['question' => 'Complete?', 'answer' => 'Yes.'], ['question' => 'No answer given', 'answer' => '   '], ['question' => '', 'answer' => 'No question']]]]]);

        $this->assertSame(['Complete?'], array_column($schema['mainEntity'], 'name'));
        $this->assertNull($builder->build([['type' => 'faq', 'data' => ['items' => [['question' => 'x', 'answer' => '']]]]]));
        $this->assertNull($builder->build([['type' => 'text', 'data' => ['body' => 'Questions? Answers.']]]), 'no FAQ section, no schema');
    }

    // -------------------------------------------------------- social metadata

    private function heroAsset(Website $website, int $width = 1600, int $height = 900, bool $writeFile = true): WebsiteAsset
    {
        $path = 'images/websites/' . $website->uid . '/hero-' . uniqid() . '.jpg';

        if ($writeFile) {
            mkdir(dirname(public_path($path)), 0755, true);
            $im = imagecreatetruecolor($width, $height);
            imagejpeg($im, public_path($path), 80);
            imagedestroy($im);
        }

        return WebsiteAsset::create(['website_id' => $website->id, 'disk' => 'public', 'path' => $path, 'mime_type' => 'image/jpeg', 'size' => 2048, 'width' => $width, 'height' => $height, 'alt_text' => 'Guests at a wedding photo booth', 'purpose' => 'hero']);
    }

    public function test_a_published_page_carries_open_graph_and_twitter_tags_with_a_real_owned_image(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $hero = $this->heroAsset($website);
        $website->update(['theme' => ['hero_asset_uid' => $hero->uid]]);
        WebsitePage::create(['website_id' => $website->id, 'title' => 'Home', 'slug' => null, 'is_home' => true, 'sections' => [$this->section('hero')], 'seo_title' => 'Home seo', 'meta_description' => 'A home description for the site.', 'noindex' => false, 'sort_order' => 0]);
        app(WebsitePublisher::class)->publish($website->fresh(), $this->platformAdminId());
        $website->domains()->create(['domain' => 'social-site.test', 'is_primary' => true, 'status' => WebsiteDomainStatus::Active, 'verification_token' => 't', 'verified_at' => now(), 'activated_at' => now()]);

        $html = $this->get('http://social-site.test/')->assertOk()->getContent();

        $this->assertStringContainsString('<meta property="og:type" content="website">', $html);
        $this->assertStringContainsString('<meta property="og:url" content="https://social-site.test/">', $html);
        $this->assertStringContainsString('<meta property="og:site_name"', $html);
        $this->assertStringContainsString('<meta property="og:title" content="Home seo | Test Website">', $html);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $html);

        preg_match('#<meta property="og:image" content="([^"]+)">#', $html, $m);
        $this->assertNotEmpty($m, 'an og:image is present');
        $this->assertMatchesRegularExpression('#^https?://#', $m[1], 'absolute URL');
        $this->assertFileExists(public_path(ltrim((string) parse_url($m[1], PHP_URL_PATH), '/')), 'the og:image file is really on disk');
        $this->assertStringContainsString('/hero-', $m[1], 'the owner\'s own hero (a derivative of it)');
        $this->assertStringContainsString('<meta property="og:image:alt" content="Guests at a wedding photo booth">', $html);
        $this->assertStringContainsString('<meta name="twitter:image" content="' . $m[1] . '">', $html);
    }

    public function test_no_og_image_and_a_small_card_when_there_is_no_usable_image(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $missing = $this->heroAsset($website, writeFile: false); // a row whose file is not there
        $website->update(['theme' => ['hero_asset_uid' => $missing->uid]]);
        WebsitePage::create(['website_id' => $website->id, 'title' => 'Home', 'slug' => null, 'is_home' => true, 'sections' => [$this->section('hero')], 'seo_title' => 'Home seo', 'meta_description' => 'A home description for the site.', 'noindex' => false, 'sort_order' => 0]);
        app(WebsitePublisher::class)->publish($website->fresh(), $this->platformAdminId());

        // Platform path: no canonical (no domain) -> no og:url; no usable image -> no og:image, small card.
        $html = $this->get(route('public.website.home', $website->fresh()->public_id))->assertOk()->getContent();

        $this->assertStringContainsString('<meta property="og:type" content="website">', $html);
        $this->assertStringNotContainsString('og:image', $html);
        $this->assertStringNotContainsString('twitter:image', $html);
        $this->assertStringNotContainsString('og:url', $html);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary">', $html);
    }

    public function test_preview_carries_no_social_tags_beyond_the_basics(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $page = WebsitePage::create(['website_id' => $website->id, 'title' => 'Home', 'slug' => null, 'is_home' => true, 'sections' => [$this->section('hero')], 'seo_title' => null, 'meta_description' => null, 'noindex' => false, 'sort_order' => 0]);
        $this->authenticateAsCustomer($customer);

        $html = $this->get(route('customer.workspaces.businesses.website.preview', [$workspace->uid, $business->uid, $page->uid]))->assertOk()->getContent();

        $this->assertStringNotContainsString('og:type', $html);
        $this->assertStringNotContainsString('twitter:card', $html);
        $this->assertStringNotContainsString('rel="canonical"', $html);
    }

    public function test_no_application_file_prints_bytes_before_its_php_tag(): void
    {
        // routes/web.php once began with blank lines, which Laravel emitted at the top of EVERY response — including
        // robots.txt and the XML sitemap (a document that must start with its XML declaration).
        $offenders = [];

        foreach (['routes', 'config', 'bootstrap', 'app/Providers'] as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($directory), \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === 'php' && ! str_starts_with((string) file_get_contents($file->getPathname(), false, null, 0, 5), '<?php')) {
                    $offenders[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    // ----------------------------------------------------- CTA bands + CSS

    private function areaPlanPages(array $areaSections): array
    {
        $contact = ['page_key' => 'contact', 'page_type' => 'contact', 'is_home' => false, 'slug' => 'contact', 'title' => 'Contact', 'seo_title' => null, 'meta_description' => null, 'entity' => null, 'sections' => [['type' => 'hero', 'data' => ['heading' => 'Contact']]]];
        $services = ['page_key' => 'services_overview', 'page_type' => 'services_overview', 'is_home' => false, 'slug' => 'services', 'title' => 'Services', 'seo_title' => null, 'meta_description' => null, 'entity' => null, 'sections' => [['type' => 'hero', 'data' => ['heading' => 'Services']]]];
        $area = fn (string $name, array $sections) => ['page_key' => 'area:' . strtolower($name), 'page_type' => 'location', 'is_home' => false, 'slug' => 'serving-' . strtolower($name), 'title' => $name, 'seo_title' => null, 'meta_description' => null, 'entity' => ['area' => $name], 'sections' => $sections];

        return [$contact, $services, $area('Chicago', $areaSections), $area('Naperville', $areaSections)];
    }

    public function test_a_location_page_with_its_own_cta_does_not_get_a_second_planning_band_stacked_under_it(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $ownCta = ['type' => 'cta', 'data' => ['heading' => 'Check your date', 'body' => null, 'buttons' => [['label' => 'Check availability', 'url' => 'mailto:a@b.test']]]];

        $with = app(MediaBindingService::class)->bind($website, $this->areaPlanPages([['type' => 'hero', 'data' => ['heading' => 'Chicago']], $ownCta]))['pages'][2]['sections'];
        $without = app(MediaBindingService::class)->bind($website, $this->areaPlanPages([['type' => 'hero', 'data' => ['heading' => 'Chicago']]]))['pages'][2]['sections'];

        $headings = fn (array $sections) => array_values(array_filter(array_map(fn ($s) => $s['type'] === 'cta' ? $s['data']['heading'] : null, $sections)));

        $this->assertSame(['Check your date', 'We also serve nearby'], $headings($with), 'its own CTA + the nearby-areas links; no duplicate "Planning an event" band');
        $this->assertSame(['Planning an event in Chicago?', 'We also serve nearby'], $headings($without), 'with no CTA of its own it still gets the contact / services links');
    }

    public function test_adjacent_cta_bands_read_as_one_block_and_the_mobile_dropdown_chevrons_line_up(): void
    {
        $css = (string) file_get_contents(base_path('public/css/website-design.css'));

        $this->assertMatchesRegularExpression('/\.wd-band-cta \+ \.wd-band-cta > \.wd-container \{[^}]*padding-top: 0/', $css);
        // Locations (label-only) and Services (toggle button) put their chevron in the same place on mobile.
        $this->assertMatchesRegularExpression('/\.wd-dd-label \{[^}]*padding-right: calc\(24px - 0\.225em\)/', $css);
        $this->assertMatchesRegularExpression('/\.wd-dd-toggle \{ flex: 0 0 48px; width: 48px/', $css, 'the toggle centres its chevron 24px from the edge');
    }

    // ------------------------------------------------- search visibility

    public function test_the_bulk_release_leaves_owner_hidden_and_empty_pages_alone_and_the_sitemap_follows_the_next_publish(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(['phone' => '3125550147']);
        $website = $this->createWebsite($business);
        $text = [$this->section('hero'), $this->section('text')];
        $rows = [
            'home' => ['title' => 'Home', 'slug' => null, 'is_home' => true, 'sections' => $text, 'noindex' => true],
            'services' => ['title' => 'Services', 'slug' => 'services', 'sections' => $text, 'noindex' => true],
            'about' => ['title' => 'About', 'slug' => 'about', 'sections' => $text, 'noindex' => true, 'noindex_explicit' => true],   // the owner hid it
            'empty' => ['title' => 'Empty', 'slug' => 'empty', 'sections' => [$this->section('hero')], 'noindex' => true],             // nothing of its own
            'live' => ['title' => 'Live', 'slug' => 'live', 'sections' => $text, 'noindex' => false],
        ];
        $i = 0;
        foreach ($rows as $row) {
            WebsitePage::create(array_merge(['website_id' => $website->id, 'is_home' => false, 'seo_title' => 'T' . $i, 'meta_description' => 'A description number ' . $i . ' for the page.', 'noindex_explicit' => false, 'sort_order' => $i++], $row));
        }
        $this->authenticateAsCustomer($customer);

        $health = fn () => collect(app(WebsiteHealthChecker::class)->check($website->fresh(), ['pages' => '/pages'])['checks'])->firstWhere('key', 'indexing');
        $this->assertStringContainsString('4 of 5 pages are hidden from search engines', $health()['detail']);
        $this->assertStringContainsString('You chose to hide 1 of them', $health()['detail']);

        $this->post(route('customer.workspaces.businesses.website.pages.allowIndexing', [$workspace->uid, $business->uid]))
            ->assertSessionHas('message', '2 pages can now be found in search. Publish to update your live website. 1 page you chose to hide stays hidden. 1 page has nothing of its own to show yet and stays hidden.');

        $hidden = $website->pages()->where('noindex', true)->pluck('slug')->all();
        sort($hidden);
        $this->assertSame(['about', 'empty'], $hidden);
        $this->assertSame('warn', $health()['status'], 'Health keeps telling the truth about what is still hidden');

        // The sitemap lists exactly the indexable pages once the owner publishes.
        app(WebsitePublisher::class)->publish($website->fresh(), $this->platformAdminId());
        $website->domains()->create(['domain' => 'visibility-site.test', 'is_primary' => true, 'status' => WebsiteDomainStatus::Active, 'verification_token' => 't', 'verified_at' => now(), 'activated_at' => now()]);
        $xml = $this->get('http://visibility-site.test/sitemap.xml')->getContent();
        foreach (['https://visibility-site.test/', 'https://visibility-site.test/services', 'https://visibility-site.test/live'] as $listed) {
            $this->assertStringContainsString('<loc>' . $listed . '</loc>', $xml);
        }
        $this->assertStringNotContainsString('/about', $xml);
        $this->assertStringNotContainsString('/empty', $xml);
    }

    public function test_only_changing_the_box_is_an_owner_choice_so_saving_a_generated_page_is_not(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $page = WebsitePage::create(['website_id' => $website->id, 'title' => 'Home', 'slug' => null, 'is_home' => true, 'sections' => [$this->section('hero'), $this->section('text')], 'seo_title' => null, 'meta_description' => null, 'noindex' => true, 'noindex_explicit' => false, 'sort_order' => 0]);
        $this->authenticateAsCustomer($customer);
        $save = fn (string $noindex) => $this->put(route('customer.workspaces.businesses.website.pages.update', [$workspace->uid, $business->uid, $page->uid]), [
            'title' => 'Home', 'slug' => '', 'is_home' => '1', 'sections' => json_encode($page->fresh()->sections), 'seo_title' => '', 'meta_description' => '', 'noindex' => $noindex,
        ])->assertSessionHasNoErrors();

        // The box is pre-ticked on a generated page; saving it unchanged is NOT a choice to hide.
        $save('1');
        $this->assertTrue($page->fresh()->noindex);
        $this->assertFalse($page->fresh()->noindex_explicit);

        // Unticking releases it; ticking it again is the owner's own decision.
        $save('');
        $this->assertFalse($page->fresh()->noindex);
        $save('1');
        $this->assertTrue($page->fresh()->noindex);
        $this->assertTrue($page->fresh()->noindex_explicit);

        // Saving it unchanged again keeps that choice, and the bulk release leaves it hidden.
        $save('1');
        $this->assertTrue($page->fresh()->noindex_explicit);
        $this->post(route('customer.workspaces.businesses.website.pages.allowIndexing', [$workspace->uid, $business->uid]));
        $this->assertTrue($page->fresh()->noindex);
    }
}
