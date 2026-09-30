<?php

namespace Tests\Feature\Website;

use App\Enums\Business\BusinessServiceStatus;
use App\Library\Business\BusinessKnowledgeProfileManager;
use App\Library\Website\WebsitePageStrategy;
use App\Library\Website\WebsiteStarterDraftService;
use App\Models\BusinessKnowledgeProfileFieldState;
use App\Models\BusinessLocation;
use App\Models\BusinessService;
use App\Models\CatalogItem;
use App\Models\CatalogItemLocationOverride;
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

        // Two independent real signals (acceptance-correction Blocker 8):
        // a saved service-area city list AND a genuine, findable public
        // address — never just one geographic token.
        $eligible = BusinessLocation::create([
            'business_id' => $business->id,
            'service_mode' => 'service_area',
            'city' => 'Naperville',
            'region' => 'IL',
            'service_area_cities' => ['Naperville', 'Aurora', 'Wheaton'],
            'public_address' => true,
            'address_line_1' => '400 S Washington St',
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

    /**
     * Acceptance-correction Blocker 8: a bare geographic token (a city
     * list, or a radius) is no longer, by itself, enough to justify a
     * page — it must land in the "needs more local information"
     * checklist instead of ever becoming an indexable page.
     */
    public function test_a_location_with_only_a_single_geographic_signal_needs_more_info_not_a_page(): void
    {
        [, $business] = $this->entitledTenant();

        $cityOnly = BusinessLocation::create([
            'business_id' => $business->id,
            'service_mode' => 'service_area',
            'city' => 'Elgin',
            'region' => 'IL',
            'service_area_cities' => ['Elgin', 'Carpentersville'],
        ]);
        $radiusOnly = BusinessLocation::create([
            'business_id' => $business->id,
            'service_mode' => 'service_area',
            'city' => 'Waukegan',
            'region' => 'IL',
            'service_radius_km' => 25,
        ]);

        $strategy = app(WebsitePageStrategy::class);
        $this->assertFalse($strategy->eligibleLocations($business)->contains('id', $cityOnly->id));
        $this->assertFalse($strategy->eligibleLocations($business)->contains('id', $radiusOnly->id));
        $this->assertTrue($strategy->locationsNeedingMoreInfo($business)->contains('id', $cityOnly->id));
        $this->assertTrue($strategy->locationsNeedingMoreInfo($business)->contains('id', $radiusOnly->id));

        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = app(WebsiteStarterDraftService::class)->createFromTemplate($business, $template);

        $slugs = $website->pages()->pluck('slug')->filter()->values()->all();
        $this->assertStringNotContainsString('elgin', implode(',', $slugs));
        $this->assertStringNotContainsString('waukegan', implode(',', $slugs));
    }

    /**
     * A location may clear the bar through a DIFFERENT pair of real
     * signals than the geography+address combination — here, a travel
     * radius plus a genuinely saved, distinct package/offer override for
     * that specific location — proving the gate counts independent real
     * facts rather than hardcoding one specific pair.
     */
    public function test_a_radius_plus_a_real_location_specific_package_override_is_enough_to_qualify(): void
    {
        [, $business] = $this->entitledTenant();

        $location = BusinessLocation::create([
            'business_id' => $business->id,
            'service_mode' => 'service_area',
            'city' => 'Schaumburg',
            'region' => 'IL',
            'service_radius_km' => 30,
        ]);
        $catalogItem = CatalogItem::create([
            'business_id' => $business->id,
            'type' => 'package',
            'name' => 'Weekend Package',
            'price_minor' => 50000,
            'currency_code' => 'USD',
            'position' => 0,
        ]);
        CatalogItemLocationOverride::create([
            'catalog_item_id' => $catalogItem->id,
            'business_location_id' => $location->id,
            'is_enabled' => true,
            'price_minor_override' => 45000,
        ]);

        $strategy = app(WebsitePageStrategy::class);
        $this->assertTrue($strategy->eligibleLocations($business)->contains('id', $location->id));
    }

    /**
     * Verified, location-specific opening hours are another independent
     * real signal — but only once genuinely confirmed (never a merely
     * saved, unverified value), mirroring the exact confirmation status
     * BusinessKnowledgeProfileManager itself requires before treating
     * any location's hours as real.
     */
    public function test_unverified_hours_do_not_count_as_a_signal_but_confirmed_hours_do(): void
    {
        [, $business] = $this->entitledTenant();

        $unverified = BusinessLocation::create([
            'business_id' => $business->id,
            'service_mode' => 'service_area',
            'city' => 'Batavia',
            'region' => 'IL',
            'service_radius_km' => 20,
            'hours' => ['mon' => [['09:00', '17:00']]],
            'hours_verification_status' => 'unverified',
        ]);
        $confirmed = BusinessLocation::create([
            'business_id' => $business->id,
            'service_mode' => 'service_area',
            'city' => 'Geneva',
            'region' => 'IL',
            'service_radius_km' => 20,
            'hours' => ['mon' => [['09:00', '17:00']]],
            'hours_verification_status' => BusinessKnowledgeProfileFieldState::STATUS_CUSTOMER_CONFIRMED,
            'hours_verified_at' => now(),
        ]);

        $strategy = app(WebsitePageStrategy::class);
        $this->assertFalse($strategy->eligibleLocations($business)->contains('id', $unverified->id));
        $this->assertTrue($strategy->eligibleLocations($business)->contains('id', $confirmed->id));
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
            'public_address' => true,
            'address_line_1' => '10 W Jefferson Ave',
        ]);
        BusinessLocation::create([
            'business_id' => $business->id,
            'service_mode' => 'service_area',
            'city' => 'Rockford',
            'region' => 'IL',
            'service_radius_km' => 40,
            'public_address' => true,
            'address_line_1' => '200 E State St',
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

    /**
     * Independent-review correction round 4: 'form', like 'gallery', can
     * never validly come from the guided-generation AI client — a
     * Contact page's form is always the Website's own real
     * quote-request form, attached server-side by MediaBindingService,
     * never something AI is asked to invent or choose.
     */
    public function test_gallery_and_form_are_always_excluded_from_ai_facing_allowed_section_types(): void
    {
        [, $business] = $this->entitledTenant();
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $website = $this->createWebsite($business);

        $plan = app(WebsitePageStrategy::class)->buildPlan($business, $template, $website);
        $contactBefore = collect($plan)->firstWhere('page_type', 'contact');
        $this->assertContains('form', $contactBefore['allowed_section_types'], 'The template manifest itself must still allow a form on Contact.');

        $aiPlan = WebsitePageStrategy::withoutAiUnfillableSections($plan);

        foreach ($aiPlan as $page) {
            $this->assertNotContains('gallery', $page['allowed_section_types']);
            $this->assertNotContains('form', $page['allowed_section_types']);
        }

        // Every other allowed type on the Contact page survives untouched.
        $contactAfter = collect($aiPlan)->firstWhere('page_type', 'contact');
        $this->assertContains('hero', $contactAfter['allowed_section_types']);
    }
}
