<?php

namespace Tests\Feature\Website\Public;

use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Website\WebsitePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * The renderer-level defects found on the live Transparent Photo Booths site, reproduced in a disposable fixture with the same
 * page addresses and owner settings, and shown to be corrected by shared code alone — with no change to any stored content.
 */
class MigratedSiteRenderingContractTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use RefreshDatabase;

    private const HOST = 'transparent-shape.test';

    private function migratedSite(): void
    {
        $this->seed(\Database\Seeders\WebsiteTemplateSeeder::class);
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business, [
            'name' => 'Transparent Photo Booths',
            'template_key' => 'photo_booth_modern',
            'theme' => ['privacy_notice_url' => 'https://' . self::HOST . '/Privacy-Policy', 'terms_url' => 'https://' . self::HOST . '/Terms-Conditions'],
        ]);
        $this->homePage($website, ['seo_title' => 'Transparent Photo Booth | Luxury Photo Booth Rentals Spokane WA']);
        foreach (['wedding-photo-booth-rental', 'corporate-photo-booth-rental', 'party-photo-booth-rental', 'terms-and-conditions'] as $slug) {
            $this->subPage($website, $slug);
        }
        $this->subPage($website, 'privacy-policy', ['title' => 'Privacy Policy', 'seo_title' => 'Privacy Policy | Transparent Photo Booth']);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $website->domains()->create(['domain' => self::HOST, 'is_primary' => true, 'status' => WebsiteDomainStatus::Active, 'verification_token' => 't', 'verified_at' => now(), 'activated_at' => now()]);
    }

    public function test_the_legacy_builder_addresses_redirect_permanently_to_the_real_pages(): void
    {
        $this->migratedSite();

        foreach ([
            'weddingphotoboothrental' => 'wedding-photo-booth-rental',
            'corporatephotoboothrental' => 'corporate-photo-booth-rental',
            'partyphotoboothrental' => 'party-photo-booth-rental',
            'Terms-Conditions' => 'terms-and-conditions',
            'Privacy-Policy' => 'privacy-policy',
        ] as $old => $new) {
            $this->get('http://' . self::HOST . '/' . $old)->assertStatus(301)->assertRedirect('https://' . self::HOST . '/' . $new);
        }

        $this->get('http://' . self::HOST . '/weddings-photo-booths')->assertNotFound()->assertHeader('X-Robots-Tag', 'noindex, follow');
        $this->get('http://' . self::HOST . '/privacy')->assertNotFound();
    }

    public function test_the_footer_links_the_real_legal_pages_and_keeps_its_columns_out_of_the_heading_outline(): void
    {
        $this->migratedSite();

        $html = $this->get('http://' . self::HOST . '/')->assertOk()->getContent();

        $this->assertStringContainsString('href="https://' . self::HOST . '/terms-and-conditions" rel="noopener" data-testid="terms-link"', $html);
        $this->assertStringContainsString('href="https://' . self::HOST . '/privacy-policy" rel="noopener" data-testid="privacy-notice-link"', $html);
        $this->assertStringNotContainsString('/Terms-Conditions', $html);
        $this->assertStringNotContainsString('<h2 class="wd-footer-heading"', $html);
        $this->assertStringContainsString('<p class="wd-footer-heading">Explore</p>', $html);
    }

    public function test_a_title_that_already_names_the_brand_is_not_given_it_again(): void
    {
        $this->migratedSite();

        $this->assertStringContainsString('<title>Privacy Policy | Transparent Photo Booth</title>', $this->get('http://' . self::HOST . '/privacy-policy')->getContent());
        $this->assertStringContainsString('<title>Transparent Photo Booth | Luxury Photo Booth Rentals Spokane WA</title>', $this->get('http://' . self::HOST . '/')->getContent());
    }
}
