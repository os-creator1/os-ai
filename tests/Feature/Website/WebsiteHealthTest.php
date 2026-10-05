<?php

namespace Tests\Feature\Website;

use App\Library\Website\WebsiteHealthChecker;
use App\Library\Website\WebsitePublisher;
use App\Library\Website\WebsiteStarterDraftService;
use App\Models\BusinessService;
use App\Models\CatalogItem;
use App\Models\WebsiteAsset;
use App\Models\WebsiteTemplate;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website V1 final — "SEO & Website Health": a short list of REAL checks
 * computed from the website's own pages, assets, domain and business facts.
 * Each is true or false right now; none predicts rankings.
 */
class WebsiteHealthTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WebsiteTemplateSeeder::class);
    }

    private function check(array $report, string $key): array
    {
        return collect($report['checks'])->firstWhere('key', $key);
    }

    private function website(array $businessOverrides = []): array
    {
        [$customer, $business] = $this->entitledTenant($businessOverrides + ['phone' => '3125550188', 'email' => 'hello@lumabooth.test']);
        $website = app(WebsiteStarterDraftService::class)->createShellFromTemplate($business, WebsiteTemplate::findActiveOrFail('photo_booth_modern'));

        return [$customer, $business, $website];
    }

    public function test_a_complete_published_site_on_its_own_domain_is_all_good_but_the_unpublished_domain_state_is_explained(): void
    {
        [, $business, $website] = $this->website();
        $this->homePage($website, ['seo_title' => 'Luma Photo Booth Co', 'meta_description' => 'Photo booths in Chicago.', 'sections' => [$this->section('hero'), $this->section('contact_details')]]);
        $this->subPage($website, 'photo-booth-contact', ['seo_title' => 'Contact Luma', 'meta_description' => 'Reach Luma.']);
        $this->subPage($website, 'photo-booth-about', ['seo_title' => 'About Luma', 'meta_description' => 'Our story.']);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $report = app(WebsiteHealthChecker::class)->check($website->fresh(), ['publish' => '/p', 'domains' => '/d']);

        $this->assertSame('ok', $this->check($report, 'published')['status']);
        $this->assertSame('ok', $this->check($report, 'titles')['status']);
        $this->assertSame('ok', $this->check($report, 'descriptions')['status']);
        $this->assertSame('ok', $this->check($report, 'contact')['status']);
        $this->assertSame('ok', $this->check($report, 'cta')['status']);

        // Platform-path sites are never indexed, so the missing own domain is called out with a link.
        $domain = $this->check($report, 'domain');
        $this->assertSame('warn', $domain['status']);
        $this->assertStringContainsString('own domain', $domain['detail']);
        $this->assertSame('/d', $domain['action']['url']);
        $this->assertSame(array_sum($report['summary']), count($report['checks']));
    }

    public function test_missing_and_duplicate_titles_and_descriptions_are_named(): void
    {
        [, , $website] = $this->website();
        $this->homePage($website, ['title' => 'Home', 'seo_title' => 'Same title', 'meta_description' => null]);
        $this->subPage($website, 'photo-booth-about', ['title' => 'About', 'seo_title' => 'Same title', 'meta_description' => 'x']);

        $report = app(WebsiteHealthChecker::class)->check($website->fresh());

        $titles = $this->check($report, 'titles');
        $this->assertSame('warn', $titles['status']);
        $this->assertStringContainsString('Same title', $titles['detail']);
        $this->assertStringContainsString('Home', $this->check($report, 'descriptions')['detail']);

        $this->homePage($website, ['slug' => 'extra', 'is_home' => false, 'title' => 'No title page', 'seo_title' => null, 'meta_description' => 'y', 'sort_order' => 9]);
        $titles = $this->check(app(WebsiteHealthChecker::class)->check($website->fresh()), 'titles');
        $this->assertSame('fail', $titles['status']);
        $this->assertStringContainsString('No title page', $titles['detail']);
    }

    public function test_photos_without_alt_text_are_counted(): void
    {
        [, , $website] = $this->website();
        $with = WebsiteAsset::forceCreate(['website_id' => $website->id, 'disk' => 'public', 'path' => 'images/a.png', 'mime_type' => 'image/png', 'size' => 1, 'width' => 1, 'height' => 1, 'content_hash' => str_repeat('a', 64), 'purpose' => 'gallery', 'alt_text' => 'A guest']);
        $without = WebsiteAsset::forceCreate(['website_id' => $website->id, 'disk' => 'public', 'path' => 'images/b.png', 'mime_type' => 'image/png', 'size' => 1, 'width' => 1, 'height' => 1, 'content_hash' => str_repeat('b', 64), 'purpose' => 'gallery', 'alt_text' => null]);
        $this->homePage($website, ['sections' => [$this->section('hero'), ['type' => 'gallery', 'data' => ['heading' => 'G', 'items' => [['image' => $with->uid], ['image' => $without->uid]]]]]]);

        $alt = $this->check(app(WebsiteHealthChecker::class)->check($website->fresh()), 'alt_text');

        $this->assertSame('warn', $alt['status']);
        $this->assertStringContainsString('1 photo', $alt['detail']);
    }

    public function test_no_phone_or_email_is_a_failure_and_no_button_target_is_a_failure(): void
    {
        [, $business, $website] = $this->website();
        DB::table('businesses')->where('id', $business->id)->update(['phone' => null, 'email' => null]);
        $this->homePage($website);

        $report = app(WebsiteHealthChecker::class)->check($website->fresh()->load('business'), ['answers' => '/answers']);

        $this->assertSame('fail', $this->check($report, 'contact')['status']);
        $this->assertSame('/answers', $this->check($report, 'contact')['action']['url']);
        $this->assertSame('fail', $this->check($report, 'cta')['status']);
        $this->assertGreaterThanOrEqual(2, $report['summary']['fail']);
    }

    public function test_services_without_a_page_of_their_own_are_listed_with_the_reason_and_the_limit(): void
    {
        [, $business, $website] = $this->website();
        foreach (['Open-Air Photo Booth', 'Mirror Photo Booth'] as $i => $name) {
            BusinessService::create(['business_id' => $business->id, 'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'status' => 'active', 'sort_order' => $i]);
        }
        $this->homePage($website);
        $this->subPage($website, 'service-open-air-photo-booth');

        $services = $this->check(app(WebsiteHealthChecker::class)->check($website->fresh()), 'service_pages');

        $this->assertSame('warn', $services['status']);
        $this->assertStringContainsString('Mirror Photo Booth', $services['detail']);
        $this->assertStringContainsString('14 pages', $services['detail']);
    }

    public function test_a_package_changed_after_publishing_is_flagged_for_publish_update(): void
    {
        [$customer, $business, $website] = $this->website();
        $item = CatalogItem::create(['business_id' => $business->id, 'type' => 'package', 'name' => 'Signature', 'price_minor' => 84900, 'currency_code' => 'USD', 'position' => 0, 'created_by_user_id' => $customer->user_id]);
        $this->homePage($website, ['sections' => [$this->section('hero'), ['type' => 'services', 'data' => ['heading' => 'Packages', 'items' => [['catalog_item_uid' => $item->uid, 'name' => 'Signature', 'description' => null, 'price_label' => null, 'image' => null]]]]]]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $this->assertSame('ok', $this->check(app(WebsiteHealthChecker::class)->check($website->fresh()), 'packages')['status']);

        $this->travel(5)->minutes();
        $item->update(['price_minor' => 99900]);

        $packages = $this->check(app(WebsiteHealthChecker::class)->check($website->fresh(), ['publish' => '/pub']), 'packages');
        $this->assertSame('warn', $packages['status']);
        $this->assertSame('Publish update', $packages['action']['label']);
    }
}
