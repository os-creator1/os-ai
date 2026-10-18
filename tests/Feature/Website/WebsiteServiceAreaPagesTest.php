<?php

namespace Tests\Feature\Website;

use App\Enums\Business\BusinessServiceStatus;
use App\Library\Website\GuidedGeneration\GuidedGenerationOutputValidator;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Library\Website\WebsitePageStrategy;
use App\Models\BusinessService;
use App\Models\Website;
use App\Models\WebsiteTemplate;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesSmartWizard;
use Tests\TestCase;

/**
 * Service-area pages: the owner's chosen cities are SEO/service-area
 * targets — planned straight from the list (never as operational
 * BusinessLocations), prioritized and capped, each with its own slug,
 * facts and internal links, and protected from becoming near-identical
 * doorway pages.
 */
class WebsiteServiceAreaPagesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;
    use DrivesSmartWizard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSmartWizard();
    }

    private function services(object $business, int $count = 2): void
    {
        foreach (range(1, $count) as $n) {
            BusinessService::create(['business_id' => $business->id, 'name' => 'Booth ' . $n, 'slug' => 'booth-' . $n, 'status' => BusinessServiceStatus::Active->value, 'sort_order' => $n]);
        }
    }

    private function plan(object $business, ?array $areas, ?array $catalogUids = null): array
    {
        $website = $this->createWebsite($business);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');

        return app(WebsitePageStrategy::class)->buildPlan($business, $template, $website, null, $catalogUids, $areas);
    }

    private function areaPages(array $plan): array
    {
        return array_values(array_filter($plan, fn ($page) => str_starts_with($page['page_key'], 'area:')));
    }

    public function test_each_chosen_area_gets_a_distinct_page_plan_in_the_owners_priority_order(): void
    {
        [, $business] = $this->entitledTenant();
        $this->services($business);

        $areas = $this->areaPages($this->plan($business, ['Manhattan, NY', 'Brooklyn', 'Queens']));

        $this->assertSame(['serving-manhattan-ny', 'serving-brooklyn', 'serving-queens'], array_column($areas, 'slug'));
        $this->assertSame(['Serving Manhattan, NY', 'Serving Brooklyn', 'Serving Queens'], array_column($areas, 'title'));
        $this->assertSame(['location'], array_values(array_unique(array_column($areas, 'page_type'))));
        $this->assertSame(['area:manhattan-ny', 'area:brooklyn', 'area:queens'], array_column($areas, 'page_key'));
    }

    public function test_an_area_page_carries_only_facts_the_owner_gave(): void
    {
        [, $business] = $this->entitledTenant();
        $this->services($business, 2);

        $entity = $this->areaPages($this->plan($business, ['Brooklyn', 'Queens', 'Bronx']))[0]['entity'];

        $this->assertSame('Brooklyn', $entity['area']);
        $this->assertSame(['Queens', 'Bronx'], $entity['nearby_areas'], 'The other chosen areas are the nearby context.');
        $this->assertSame(['Booth 1', 'Booth 2'], $entity['services']);
        $this->assertEqualsCanonicalizing(['area', 'nearby_areas', 'services', 'business_home_city'], array_unique(array_merge(array_keys($entity), ['business_home_city'])));
    }

    public function test_choosing_areas_never_creates_operational_locations(): void
    {
        [, $business] = $this->entitledTenant();
        $this->services($business);
        $before = $business->locations()->count();

        $this->plan($business, ['Manhattan, NY', 'Brooklyn', 'Queens']);

        $this->assertSame($before, $business->locations()->count());
    }

    public function test_no_area_pages_are_planned_without_a_list_or_without_real_service_content(): void
    {
        [, $business] = $this->entitledTenant();

        $this->services($business);
        $this->assertSame([], $this->areaPages($this->plan($business, null)), 'A questionnaire that collects no list plans none (v1 behaviour).');

        [, $empty] = $this->entitledTenant();
        $this->assertSame([], $this->areaPages($this->plan($empty, ['Brooklyn', 'Queens'])), 'No services and no packages: nothing to localize, so no thin pages.');
    }

    public function test_a_long_list_is_capped_instead_of_becoming_dozens_of_thin_pages(): void
    {
        [, $business] = $this->entitledTenant();
        $this->services($business, 2);
        $areas = array_map(fn ($n) => 'Town ' . $n, range(1, 40));

        $plan = $this->plan($business, $areas);
        $areaPages = $this->areaPages($plan);

        $this->assertGreaterThanOrEqual(5, count($areaPages));
        $this->assertLessThanOrEqual(WebsitePageStrategy::MAX_AREA_PAGES, count($areaPages));
        $this->assertLessThanOrEqual(WebsitePageStrategy::MAX_TOTAL_PAGES, count($plan));
        // The priority order is the owner's: the FIRST entries get the pages.
        $this->assertSame('serving-town-1', $areaPages[0]['slug']);
        $this->assertSame(array_map(fn ($n) => 'serving-town-' . $n, range(1, count($areaPages))), array_column($areaPages, 'slug'));
    }

    public function test_many_services_do_not_starve_the_area_pages(): void
    {
        [, $business] = $this->entitledTenant();
        $this->services($business, 12);

        $plan = $this->plan($business, ['Manhattan, NY', 'Brooklyn', 'Queens', 'Bronx', 'Hoboken', 'Newark']);

        $this->assertGreaterThanOrEqual(2, count($this->areaPages($plan)));
        $this->assertLessThanOrEqual(WebsitePageStrategy::MAX_TOTAL_PAGES, count($plan));
    }

    public function test_two_names_that_slugify_alike_never_share_a_url(): void
    {
        [, $business] = $this->entitledTenant();
        $this->services($business);

        $plan = $this->plan($business, ['St. Louis', 'St Louis', 'Saint Louis']);

        $this->assertSame(['serving-st-louis', 'serving-saint-louis'], array_column($this->areaPages($plan), 'slug'));
        $slugs = array_filter(array_column($plan, 'slug'));
        $this->assertSame(count($slugs), count(array_unique($slugs)), 'No two pages share a URL.');
    }

    // ---------------------------------------------------- anti-doorway

    private function validPagesFor(array $plan, callable $areaText): array
    {
        return array_map(function (array $page) use ($areaText) {
            $sections = [['type' => 'hero', 'data' => ['heading' => $page['title'], 'subheading' => null, 'background_image' => null, 'primary_cta' => null, 'secondary_cta' => null]]];

            if (isset($page['entity']['area'])) {
                $sections[] = ['type' => 'text', 'data' => ['heading' => 'About this area', 'body' => $areaText($page['entity']['area'], $page['page_key'])]];
            }

            return ['page_key' => $page['page_key'], 'title' => $page['title'], 'seo_title' => 'Title ' . $page['page_key'], 'meta_description' => 'Meta ' . $page['page_key'], 'sections' => $sections];
        }, $plan);
    }

    public function test_distinct_area_copy_passes_validation(): void
    {
        [, $business] = $this->entitledTenant();
        $this->services($business);
        $plan = WebsitePageStrategy::withoutAiUnfillableSections($this->plan($business, ['Brooklyn', 'Queens']));

        app(GuidedGenerationOutputValidator::class)->validate($this->validPagesFor($plan, fn ($area, $key) => 'Photo booths for ' . $area . ' — ' . hash('sha256', $key) . hash('sha256', $key . 'b')), $plan);

        $this->addToAssertionCount(1);
    }

    public function test_area_pages_that_only_swap_the_place_name_are_rejected(): void
    {
        [, $business] = $this->entitledTenant();
        $this->services($business);
        $plan = WebsitePageStrategy::withoutAiUnfillableSections($this->plan($business, ['Brooklyn', 'Queens']));
        $pages = $this->validPagesFor($plan, fn ($area) => 'We bring our photo booths to ' . $area . ' for weddings, birthdays and corporate events with unlimited prints and props.');

        try {
            app(GuidedGenerationOutputValidator::class)->validate($pages, $plan);
            $this->fail('Near-identical area pages must be rejected.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('nearly identical', json_encode($e->errors()));
        }
    }

    public function test_an_area_page_that_never_names_its_area_is_rejected(): void
    {
        [, $business] = $this->entitledTenant();
        $this->services($business);
        $plan = WebsitePageStrategy::withoutAiUnfillableSections($this->plan($business, ['Brooklyn']));
        $pages = $this->validPagesFor($plan, fn ($area, $key) => 'Generic copy about booths ' . hash('sha256', $key));
        $pages = array_map(function (array $page) {
            if (str_starts_with($page['page_key'], 'area:')) {
                $page['seo_title'] = 'A page';
                $page['meta_description'] = 'About booths';
                $page['title'] = 'A page';
                $page['sections'][0]['data']['heading'] = 'A page';
            }

            return $page;
        }, $pages);

        try {
            app(GuidedGenerationOutputValidator::class)->validate($pages, $plan);
            $this->fail('An area page must name its area.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('never mentions its own area', json_encode($e->errors()));
        }
    }

    // ------------------------------------------------------ end to end

    public function test_generation_builds_area_pages_with_internal_links_and_their_own_urls(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->bindDistinctAiClient();
        $areas = ['Manhattan, NY', 'Brooklyn', 'Queens', 'Bronx', 'Hoboken', 'Newark', 'Yonkers', 'Stamford', 'Albany', 'Buffalo'];
        $this->completeV2Setup($workspace, $business, ['service_area_cities' => ['value' => $areas]]);

        $this->post($this->wizardUrl($workspace, $business, 'setup.generate'))
            ->assertRedirect($this->wizardUrl($workspace, $business, 'preview'));

        $website = Website::where('business_id', $business->id)->sole();
        $areaPages = $website->pages()->where('slug', 'like', 'serving-%')->orderBy('id')->get();

        $this->assertGreaterThanOrEqual(4, $areaPages->count());
        $this->assertLessThanOrEqual(WebsitePageStrategy::MAX_AREA_PAGES, $areaPages->count());
        $this->assertSame(1, $areaPages->first(fn ($p) => $p->slug === 'serving-manhattan-ny') ? 1 : 0, 'The owner\'s first area has its own page.');
        $this->assertSame($areaPages->count(), $areaPages->pluck('slug')->unique()->count());
        $this->assertSame($areaPages->count(), $areaPages->pluck('seo_title')->unique()->count(), 'Distinct titles.');
        $this->assertSame($areaPages->count(), $areaPages->pluck('meta_description')->unique()->count(), 'Distinct meta descriptions.');

        foreach ($areaPages as $page) {
            $buttons = collect($page->sections)->flatMap(fn ($s) => $s['data']['buttons'] ?? [])->pluck('url')->all();
            $this->assertContains('/photo-booth-contact', $buttons, "{$page->slug} links to the contact page.");
            $this->assertContains('/services', $buttons, "{$page->slug} links to the services overview.");
        }

        // Nearby-area cross links between the area pages themselves.
        $firstButtons = collect($areaPages->first()->sections)->flatMap(fn ($s) => $s['data']['buttons'] ?? [])->pluck('url')->all();
        $this->assertNotEmpty(array_filter($firstButtons, fn ($url) => str_starts_with($url, '/serving-')));

        // The whole list is still saved for later pages; no per-city locations exist.
        $this->assertCount(10, $business->fresh()->primaryLocation()->first()->service_area_cities);
        $this->assertSame(1, $business->locations()->count());
    }

    public function test_the_service_area_contract_end_to_end(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->bindDistinctAiClient();
        // As in a real installation, onboarding already created the one primary location.
        (new \App\Models\BusinessLocation())->forceFill(['business_id' => $business->id, 'is_primary' => true, 'service_mode' => 'service_area', 'name' => 'Main', 'country_code' => 'US'])->save();
        $locationsBefore = $business->locations()->count();
        $this->assertSame(1, $locationsBefore);

        // 15 entered cities, with whitespace noise and case-insensitive duplicates.
        $entered = ['  Manhattan,  NY ', 'Brooklyn', 'brooklyn', 'Queens', 'The Bronx', 'Staten Island', 'Jersey City', 'Hoboken', 'Newark', 'Yonkers', 'Stamford', 'Albany', 'Buffalo', 'Rochester', 'Syracuse', 'QUEENS'];
        $expected = ['Manhattan, NY', 'Brooklyn', 'Queens', 'The Bronx', 'Staten Island', 'Jersey City', 'Hoboken', 'Newark', 'Yonkers', 'Stamford', 'Albany', 'Buffalo', 'Rochester', 'Syracuse'];
        $this->completeV2Setup($workspace, $business, ['service_area_cities' => ['value' => $entered]]);

        // Duplicates normalize safely; order preserved.
        $this->assertSame($expected, $this->activeResponse($business)->answer('service_area_cities'));

        $this->post($this->wizardUrl($workspace, $business, 'setup.generate'))->assertRedirect($this->wizardUrl($workspace, $business, 'preview'));

        // No BusinessLocation is created from any city; the FULL list stays saved, in order.
        $this->assertSame($locationsBefore, $business->locations()->count());
        $this->assertSame($expected, $business->fresh()->primaryLocation()->first()->service_area_cities);

        // Initial planning respects the area cap and the total page budget, in the owner's order.
        $website = Website::where('business_id', $business->id)->sole();
        $areaSlugs = $website->pages()->where('slug', 'like', 'serving-%')->orderBy('id')->pluck('slug')->all();
        $this->assertGreaterThanOrEqual(4, count($areaSlugs));
        $this->assertLessThanOrEqual(WebsitePageStrategy::MAX_AREA_PAGES, count($areaSlugs));
        $this->assertLessThanOrEqual(WebsitePageStrategy::MAX_TOTAL_PAGES, $website->pages()->count());
        $this->assertSame(
            array_slice(['serving-manhattan-ny', 'serving-brooklyn', 'serving-queens', 'serving-the-bronx', 'serving-staten-island', 'serving-jersey-city', 'serving-hoboken', 'serving-newark'], 0, count($areaSlugs)),
            $areaSlugs,
            'The first (highest-priority) areas get the pages.'
        );
    }

    public function test_a_generation_of_near_duplicate_area_pages_fails_and_never_marks_setup_completed(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $mock->shouldReceive('complete')->andReturnUsing(function (array $messages) {
            $user = collect($messages)->firstWhere('role', 'user')['content'];
            $payload = json_decode(substr($user, (int) strpos($user, '{')), true);

            return json_encode(['pages' => collect($payload['plan'])->map(function ($page) {
                $area = $page['entity']['area'] ?? null;
                $sections = [['type' => 'hero', 'data' => ['heading' => $area ? 'Serving ' . $area : $page['page_key'], 'subheading' => null, 'background_image' => null, 'primary_cta' => null, 'secondary_cta' => null]]];
                if ($area) {
                    $sections[] = ['type' => 'text', 'data' => ['heading' => 'Booths', 'body' => 'We bring our photo booths to ' . $area . ' for every kind of event with unlimited prints and props included.']];
                }

                return ['page_key' => $page['page_key'], 'title' => $area ? 'Serving ' . $area : $page['page_key'], 'seo_title' => 'T ' . $page['page_key'], 'meta_description' => 'M ' . $page['page_key'], 'sections' => $sections];
            })->values()->all()]);
        });
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);

        $this->completeV2Setup($workspace, $business);

        $this->post($this->wizardUrl($workspace, $business, 'setup.generate'))
            ->assertRedirect($this->wizardUrl($workspace, $business, 'setup.review'));

        $this->assertSame(0, Website::where('business_id', $business->id)->sole()->pages()->count(), 'A doorway batch is never committed.');
        $this->assertSame('in_progress', $this->activeResponse($business)->status->value);
    }
}
