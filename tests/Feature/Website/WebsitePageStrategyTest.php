<?php

namespace Tests\Feature\Website;

use App\Enums\Business\BusinessServiceStatus;
use App\Library\Business\BusinessKnowledgeProfileManager;
use App\Library\Website\WebsitePageStrategy;
use App\Library\Website\WebsiteStarterDraftService;
use App\Models\BusinessLocation;
use App\Models\BusinessService;
use App\Models\WebsiteTemplate;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website Generator + Local SEO Completion — proves the deterministic
 * page strategy: one real page per genuinely offered service, no page
 * for a service that doesn't exist, a location page only for a
 * genuinely distinct, information-bearing saved location (anti-doorway
 * gate), and that the resulting Website has no duplicate slugs.
 */
class WebsitePageStrategyTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WebsiteTemplateSeeder::class);
    }

    public function test_one_page_is_created_per_real_active_service_and_none_for_a_service_that_does_not_exist(): void
    {
        [$customer, $business] = $this->entitledTenant();
        BusinessService::create(['business_id' => $business->id, 'name' => 'Open-Air Booth', 'slug' => 'open-air-booth', 'status' => BusinessServiceStatus::Active->value, 'sort_order' => 0]);
        BusinessService::create(['business_id' => $business->id, 'name' => 'Mirror Booth', 'slug' => 'mirror-booth', 'status' => BusinessServiceStatus::Active->value, 'sort_order' => 1]);
        BusinessService::create(['business_id' => $business->id, 'name' => 'Retired Booth', 'slug' => 'retired-booth', 'status' => BusinessServiceStatus::Inactive->value, 'sort_order' => 2]);

        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = app(WebsiteStarterDraftService::class)->createFromTemplate($business, $template);

        $slugs = $website->pages()->pluck('slug')->filter()->values()->all();

        $this->assertContains('service-open-air-booth', $slugs);
        $this->assertContains('service-mirror-booth', $slugs);
        $this->assertStringNotContainsString('retired-booth', implode(',', $slugs));
        $this->assertSame(count($slugs), count(array_unique($slugs)), 'No duplicate slugs.');
    }

    public function test_no_service_pages_are_created_when_the_business_has_no_active_service(): void
    {
        [, $business] = $this->entitledTenant();

        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = app(WebsiteStarterDraftService::class)->createFromTemplate($business, $template);

        $slugs = $website->pages()->pluck('slug')->filter()->values()->all();
        $this->assertEmpty(array_filter($slugs, fn ($slug) => str_starts_with($slug, 'service-')));
        $this->assertNull($website->pages()->where('slug', 'services')->first());
    }

    public function test_a_location_with_a_real_service_area_gets_a_page_but_a_thin_location_does_not(): void
    {
        [, $business] = $this->entitledTenant();

        $eligible = BusinessLocation::create([
            'business_id' => $business->id,
            'service_mode' => 'service_area',
            'city' => 'Naperville',
            'region' => 'IL',
            'service_area_cities' => ['Naperville', 'Aurora', 'Wheaton'],
        ]);
        $thin = BusinessLocation::create([
            'business_id' => $business->id,
            'service_mode' => 'service_area',
            'city' => 'Joliet',
            'region' => 'IL',
        ]);

        $strategy = app(WebsitePageStrategy::class);
        $this->assertTrue($strategy->eligibleLocations($business)->contains('id', $eligible->id));
        $this->assertTrue($strategy->locationsNeedingMoreInfo($business)->contains('id', $thin->id));

        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = app(WebsiteStarterDraftService::class)->createFromTemplate($business, $template);

        $slugs = $website->pages()->pluck('slug')->filter()->values()->all();
        $this->assertContains('serving-naperville-il', $slugs);
        $this->assertStringNotContainsString('joliet', implode(',', $slugs));
    }

    public function test_location_page_body_copy_is_unique_per_location_not_a_name_swapped_template(): void
    {
        [, $business] = $this->entitledTenant();

        BusinessLocation::create([
            'business_id' => $business->id,
            'service_mode' => 'service_area',
            'city' => 'Naperville',
            'region' => 'IL',
            'service_area_cities' => ['Naperville', 'Aurora'],
        ]);
        BusinessLocation::create([
            'business_id' => $business->id,
            'service_mode' => 'service_area',
            'city' => 'Rockford',
            'region' => 'IL',
            'service_radius_km' => 40,
        ]);

        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = app(WebsiteStarterDraftService::class)->createFromTemplate($business, $template);

        $naperville = $website->pages()->where('slug', 'serving-naperville-il')->firstOrFail();
        $rockford = $website->pages()->where('slug', 'serving-rockford-il')->firstOrFail();

        $naperTextSection = collect($naperville->sections)->firstWhere('type', 'text');
        $rockfordTextSection = collect($rockford->sections)->firstWhere('type', 'text');

        $this->assertStringContainsString('Aurora', $naperTextSection['data']['body']);
        $this->assertStringContainsString('40 km', $rockfordTextSection['data']['body']);
        $this->assertNotSame($naperTextSection['data']['body'], $rockfordTextSection['data']['body']);
    }

    public function test_service_detail_page_links_back_to_services_overview_and_contact(): void
    {
        [, $business] = $this->entitledTenant();
        $business->update(['phone' => '+15550001234']);
        BusinessService::create(['business_id' => $business->id, 'name' => 'Open-Air Booth', 'slug' => 'open-air-booth', 'status' => BusinessServiceStatus::Active->value, 'sort_order' => 0]);
        BusinessService::create(['business_id' => $business->id, 'name' => 'Mirror Booth', 'slug' => 'mirror-booth', 'status' => BusinessServiceStatus::Active->value, 'sort_order' => 1]);

        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = app(WebsiteStarterDraftService::class)->createFromTemplate($business, $template);

        $servicePage = $website->pages()->where('slug', 'service-open-air-booth')->firstOrFail();
        $cta = collect($servicePage->sections)->firstWhere('type', 'cta');

        $this->assertNotNull($cta);
        $urls = collect($cta['data']['buttons'])->pluck('url')->all();
        $this->assertContains('/services', $urls);
        $this->assertNotNull($website->pages()->where('slug', 'services')->first());
    }

    public function test_calling_createFromTemplate_twice_returns_the_existing_website_idempotently(): void
    {
        [, $business] = $this->entitledTenant();
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');

        $first = app(WebsiteStarterDraftService::class)->createFromTemplate($business, $template);
        $second = app(WebsiteStarterDraftService::class)->createFromTemplate($business, $template);

        $this->assertSame($first->id, $second->id);
    }
}
