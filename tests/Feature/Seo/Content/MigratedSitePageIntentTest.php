<?php

namespace Tests\Feature\Seo\Content;

use App\Enums\Business\BusinessServiceMode;
use App\Enums\Business\BusinessServiceStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Seo\Content\ArticleCannibalizationGuard;
use App\Library\Seo\Content\ArticleSiteInventory;
use App\Library\Seo\Content\ArticleSupportPageResolver;
use App\Models\Business;
use App\Models\BusinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Seo\Concerns\CreatesSeoFixtures;
use Tests\TestCase;

/**
 * The live Transparent Photo Booths shape: a site moved from another builder, whose pages are /wedding-photo-booth-rental,
 * /cheney, /service-areas ... and carry no stored page role. Every one of them used to be classified "other", so the blog's
 * cannibalization guard could not see that an article about "wedding photo booth rental Spokane" competes with a money page,
 * and an article about Cheney could not find the Cheney page.
 */
class MigratedSitePageIntentTest extends TestCase
{
    use CreatesSeoFixtures;
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->business] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->createLocation($this->business, true, BusinessServiceMode::Storefront, [
            'name' => 'Spokane', 'city' => 'Spokane', 'region' => 'WA',
            'service_area_cities' => ['Spokane Valley, WA', 'Liberty Lake, WA', 'Cheney, WA', 'Airway Heights, WA'],
        ]);

        foreach (['Weddings' => 'event', 'Corporate events' => 'event', 'Parties' => 'event', 'Digital Photo Booth' => 'service', 'Polaroid Photo Booth' => 'service'] as $name => $kind) {
            BusinessService::create(['business_id' => $this->business->id, 'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'kind' => $kind, 'status' => BusinessServiceStatus::Active->value, 'sort_order' => 0]);
        }

        $text = fn (string $body) => [['type' => 'text', 'data' => ['heading' => 'Heading', 'body' => $body]]];
        $this->publishWebsite($this->business, [
            $this->snapshotPage('11111111-0000-4000-8000-000000000001', 'Photo Booth Rental Spokane', ['seo_title' => 'Photo Booth Rental Spokane WA'], $text('Photo booths for weddings, corporate events and parties.'), true),
            $this->snapshotPage('11111111-0000-4000-8000-000000000002', 'Wedding Photo Booth Rental', ['seo_title' => 'Wedding Photo Booth Rental Spokane'], $text('Wedding photo booths.'), false, 'wedding-photo-booth-rental'),
            $this->snapshotPage('11111111-0000-4000-8000-000000000003', 'Photo Booth Rental Cheney WA', [], $text('Cheney photo booths.'), false, 'cheney'),
            $this->snapshotPage('11111111-0000-4000-8000-000000000004', 'Photo Booth Rental Airway Heights WA', [], $text('Airway Heights photo booths.'), false, 'airway'),
            $this->snapshotPage('11111111-0000-4000-8000-000000000005', 'Areas we serve', [], $text('We serve Spokane, Spokane Valley, Liberty Lake, Cheney and Airway Heights.'), false, 'service-areas'),
            $this->snapshotPage('11111111-0000-4000-8000-000000000006', 'Check availability', [], $text('Request a quote.'), false, 'check-availability'),
            $this->snapshotPage('11111111-0000-4000-8000-000000000007', 'Privacy Policy', [], $text('Privacy.'), false, 'privacy-policy'),
        ]);
    }

    private function pagesBySlug(): array
    {
        return collect(app(ArticleSiteInventory::class)->pages($this->business))->keyBy(fn ($page) => $page['slug'] ?? '')->all();
    }

    public function test_pages_without_a_stored_role_are_classified_from_the_businesss_own_services_and_places(): void
    {
        $pages = $this->pagesBySlug();

        $this->assertSame('home', $pages['']['kind']);
        $this->assertSame('service', $pages['wedding-photo-booth-rental']['kind']);
        $this->assertSame('location', $pages['cheney']['kind']);
        $this->assertSame('Airway Heights', $pages['airway']['place']);
        $this->assertSame('area_hub', $pages['service-areas']['kind']);
        $this->assertSame('contact', $pages['check-availability']['kind']);
        $this->assertSame('other', $pages['privacy-policy']['kind']);
        $this->assertTrue($pages['wedding-photo-booth-rental']['money']);
        $this->assertFalse($pages['privacy-policy']['money']);
    }

    public function test_an_article_that_is_the_same_search_as_a_migrated_money_page_is_now_a_cannibalization_conflict(): void
    {
        $result = app(ArticleCannibalizationGuard::class)->check($this->business, ['wedding photo booth rental spokane']);

        $this->assertTrue($result->strong(), 'the wedding page was invisible to the guard while it was classified "other"');
        $this->assertSame('page', $result->strongFindings()[0]['type']);

        $informational = app(ArticleCannibalizationGuard::class)->check($this->business, ['how much does a wedding photo booth rental cost in spokane']);
        $this->assertFalse($informational->strong(), 'a different, informational question supports the page and stays allowed');
    }

    public function test_an_article_about_a_city_finds_that_citys_page_by_place_not_by_a_serving_prefix(): void
    {
        $pages = $this->pagesBySlug();
        $resolver = app(ArticleSupportPageResolver::class);

        $this->assertSame('cheney', $resolver->pageForCity(array_values($pages), 'Cheney')['slug']);
        $this->assertSame('airway', $resolver->pageForCity(array_values($pages), 'Airway Heights')['slug']);
        $this->assertSame('airway', $resolver->pageForCityInText(array_values($pages), 'planning a graduation party in airway heights')['slug']);
        $this->assertNull($resolver->pageForCity(array_values($pages), 'Seattle'));
    }
}
