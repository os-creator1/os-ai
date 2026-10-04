<?php

namespace Tests\Feature\Website;

use App\Enums\Business\BusinessServiceStatus;
use App\Library\Website\Setup\WebsitePlanSummary;
use App\Library\Website\Setup\WebsiteSetupAnswerApplier;
use App\Library\Website\WebsitePageStrategy;
use App\Models\BusinessLocation;
use App\Models\BusinessService;
use App\Models\Website;
use App\Models\WebsiteAsset;
use App\Models\WebsiteTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesSmartWizard;
use Tests\TestCase;

/**
 * Website V1 final — the deterministic, EXPLAINABLE page plan under the
 * 14-page budget: which services and service areas get a page, which do not,
 * and why. Covers the two reported defects:
 *  - a 360 and a mirror booth could be dropped from the plan (two service
 *    steps both numbered their rows from 0, so event types interleaved with
 *    booth types and pushed real booths past the cut-off);
 *  - an owner's area (Oak Park) was silently absent with no explanation.
 */
class WebsitePlanExplanationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;
    use DrivesSmartWizard;

    private const AREAS = ['Chicago', 'Evanston', 'Naperville', 'Schaumburg', 'Oak Park', 'Aurora', 'Joliet', 'Skokie', 'Arlington Heights', 'Orland Park', 'Wheaton', 'Elgin', 'Cicero', 'Berwyn'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSmartWizard();
    }

    private function services(object $business, array $names): void
    {
        foreach ($names as $i => $name) {
            BusinessService::create(['business_id' => $business->id, 'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'status' => BusinessServiceStatus::Active->value, 'sort_order' => $i]);
        }
    }

    private function explain(object $business, ?array $areas = self::AREAS, int $galleryPhotos = 0): array
    {
        $website = Website::where('business_id', $business->id)->first() ?? $this->createWebsite($business);
        for ($i = 0; $i < $galleryPhotos; $i++) {
            WebsiteAsset::forceCreate(['website_id' => $website->id, 'disk' => 'public', 'path' => "images/x{$i}.png", 'mime_type' => 'image/png', 'size' => 1, 'width' => 1, 'height' => 1, 'content_hash' => str_repeat((string) $i, 64), 'purpose' => 'gallery']);
        }

        return app(WebsitePageStrategy::class)->planWithExplanation($business, WebsiteTemplate::findActiveOrFail('photo_booth_modern'), $website, null, null, $areas);
    }

    public function test_a_360_and_a_mirror_booth_are_planned_before_event_types_when_the_budget_is_tight(): void
    {
        [, $business] = $this->entitledTenant();
        $this->services($business, ['Open-Air Photo Booth', 'Mirror Photo Booth', '360 Photo Booth', 'Enclosed Photo Booth', 'Weddings', 'Corporate Events', 'Birthday Parties']);

        $result = $this->explain($business);
        $slugs = array_column($result['plan'], 'slug');

        $this->assertContains('service-mirror-photo-booth', $slugs);
        $this->assertContains('service-360-photo-booth', $slugs);
        $this->assertContains('service-open-air-photo-booth', $slugs);
        $this->assertLessThanOrEqual(WebsitePageStrategy::MAX_TOTAL_PAGES, count($result['plan']));
        $this->assertSame(count($result['plan']), $result['budget']['planned']);
    }

    public function test_every_service_has_a_decision_and_every_exclusion_says_why(): void
    {
        [, $business] = $this->entitledTenant();
        $this->services($business, ['Open-Air Photo Booth', 'Mirror Photo Booth', '360 Photo Booth', 'Enclosed Photo Booth', 'Selfie Pod', 'Backdrop Wall Rental', 'Weddings', 'Corporate Events']);

        $result = $this->explain($business);
        $decisions = collect($result['decisions']);

        foreach ($decisions as $decision) {
            $this->assertNotSame('', trim($decision['reason']), "'{$decision['title']}' needs a reason, in or out.");
        }

        $serviceDecisions = $decisions->where('type', 'service_detail');
        $this->assertCount(8, $serviceDecisions, 'Each of the 8 services has exactly one decision.');

        $planned = $serviceDecisions->where('included', true);
        $left = $serviceDecisions->where('included', false);
        $this->assertGreaterThanOrEqual(5, $planned->count(), 'The first five services are always planned.');
        $this->assertNotEmpty($left, 'With 8 services plus areas the 14-page limit leaves some out.');
        foreach ($left as $decision) {
            $this->assertStringContainsString('Not planned', $decision['reason']);
            $this->assertStringContainsString('page limit', $decision['reason']);
        }

        // The services that were left out are the owner's LAST ones, in their order.
        $this->assertSame(['Open-Air Photo Booth', 'Mirror Photo Booth', '360 Photo Booth', 'Enclosed Photo Booth', 'Selfie Pod'], $planned->pluck('title')->take(5)->all());
    }

    public function test_the_service_area_plan_says_how_many_are_saved_how_many_get_pages_and_why_oak_park_does_not(): void
    {
        [, $business] = $this->entitledTenant();
        $this->services($business, ['Open-Air Photo Booth', 'Mirror Photo Booth', '360 Photo Booth', 'Enclosed Photo Booth', 'Selfie Pod', 'Backdrop Wall Rental']);

        $areas = $this->explain($business)['areas'];

        $this->assertSame(14, $areas['saved']);
        $this->assertSame(['Chicago', 'Evanston', 'Naperville'], array_slice($areas['planned_list'], 0, 3), 'The owner\'s order is the priority order.');
        $this->assertSame($areas['planned'], count($areas['planned_list']));
        $this->assertLessThanOrEqual(WebsitePageStrategy::MAX_AREA_PAGES, $areas['planned']);
        $this->assertSame(14 - $areas['planned'], count($areas['excluded']), 'Every saved area is either planned or explained.');

        $oakPark = collect($areas['excluded'])->firstWhere('area', 'Oak Park');
        $this->assertNotNull($oakPark ?? null, 'Oak Park is the 5th area, beyond the pages this budget affords.');
        $this->assertStringContainsString('Not planned', $oakPark['reason']);
        $this->assertStringContainsString('still listed as a service area', $oakPark['reason']);
    }

    public function test_putting_oak_park_first_gives_it_a_page_the_order_is_the_priority(): void
    {
        [, $business] = $this->entitledTenant();
        $this->services($business, ['Open-Air Photo Booth', 'Mirror Photo Booth']);

        $areas = $this->explain($business, ['Oak Park', ...array_values(array_diff(self::AREAS, ['Oak Park']))])['areas'];

        $this->assertSame('Oak Park', $areas['planned_list'][0]);
        $this->assertNull(collect($areas['excluded'])->firstWhere('area', 'Oak Park'));
    }

    public function test_the_plan_is_deterministic_and_a_services_tie_breaks_by_id_never_by_chance(): void
    {
        [, $business] = $this->entitledTenant();
        // All the same sort_order, exactly what two service steps used to produce.
        foreach (['Open-Air Photo Booth', 'Weddings', 'Mirror Photo Booth', 'Corporate Events', '360 Photo Booth', 'Enclosed Photo Booth', 'Selfie Pod'] as $name) {
            BusinessService::create(['business_id' => $business->id, 'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'status' => BusinessServiceStatus::Active->value, 'sort_order' => 0]);
        }

        $first = $this->explain($business);
        $second = $this->explain($business);

        $this->assertSame(array_column($first['plan'], 'page_key'), array_column($second['plan'], 'page_key'));
        $this->assertSame($first['decisions'], $second['decisions']);
        $this->assertSame(['Open-Air Photo Booth', 'Weddings', 'Mirror Photo Booth', 'Corporate Events', '360 Photo Booth'], array_slice(collect($first['decisions'])->where('type', 'service_detail')->where('included', true)->pluck('title')->all(), 0, 5));
    }

    public function test_no_areas_means_no_area_pages_and_the_budget_goes_to_services_gallery_and_faq(): void
    {
        [, $business] = $this->entitledTenant();
        $this->services($business, ['Open-Air Photo Booth', 'Mirror Photo Booth', '360 Photo Booth']);

        $result = $this->explain($business, null, 6);
        $slugs = array_column($result['plan'], 'slug');

        $this->assertSame(0, $result['areas']['saved']);
        $this->assertEmpty(array_filter($slugs, fn ($slug) => str_starts_with((string) $slug, 'serving-')));
        $this->assertContains('gallery', $slugs, 'Six photos earn a Gallery page.');
        $this->assertContains('photo-booth-faq', $slugs);
    }

    public function test_a_gallery_with_too_few_photos_is_explained_not_silently_missing(): void
    {
        [, $business] = $this->entitledTenant();
        $this->services($business, ['Open-Air Photo Booth']);

        $gallery = collect($this->explain($business, null, 2)['decisions'])->firstWhere('key', 'gallery');

        $this->assertFalse($gallery['included']);
        $this->assertStringContainsString('at least 6 gallery photos', $gallery['reason']);
    }

    public function test_the_two_service_steps_are_numbered_continuously_so_booths_stay_before_event_types(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $response = $this->completeV2Setup($workspace, $business, ['booth_types' => ['s' => [
            'booth_types' => ['items' => [['name' => 'Open-Air Photo Booth'], ['name' => 'Mirror Photo Booth'], ['name' => '360 Photo Booth']]],
            'services_event_types' => ['items' => [['name' => 'Weddings'], ['name' => 'Corporate Events']]],
        ]]]);
        $website = Website::where('business_id', $business->id)->sole();

        app(WebsiteSetupAnswerApplier::class)->apply($business, $website, $response, $customer->user_id);

        $ordered = $business->services()->orderBy('sort_order')->orderBy('id')->get();
        $this->assertSame(['Open-Air Photo Booth', 'Mirror Photo Booth', '360 Photo Booth', 'Weddings', 'Corporate Events'], $ordered->pluck('name')->all());
        $this->assertSame([0, 1, 2, 3, 4], $ordered->pluck('sort_order')->all(), 'Positions continue across both steps; no two services share one.');
    }

    public function test_the_review_summary_previews_exactly_the_pages_generate_will_plan(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        BusinessLocation::create(['business_id' => $business->id, 'name' => 'HQ', 'city' => 'Chicago', 'region' => 'IL', 'country_code' => 'US', 'service_mode' => 'hybrid', 'is_primary' => true]);

        $response = $this->completeV2Setup($workspace, $business, [
            'service_area_cities' => ['value' => self::AREAS],
            'booth_types' => ['s' => [
                'booth_types' => ['items' => [['name' => 'Open-Air Photo Booth'], ['name' => 'Mirror Photo Booth'], ['name' => '360 Photo Booth'], ['name' => 'Enclosed Photo Booth']]],
                'services_event_types' => ['items' => [['name' => 'Weddings'], ['name' => 'Corporate Events']]],
            ]],
        ]);
        $website = Website::where('business_id', $business->id)->sole();
        $template = WebsiteTemplate::findActiveOrFail($website->template_key);

        $preview = app(WebsitePlanSummary::class)->forResponse($business, $website, $template, $response);

        app(WebsiteSetupAnswerApplier::class)->apply($business, $website, $response, $customer->user_id);
        $real = app(WebsitePageStrategy::class)->planWithExplanation(
            $business->fresh(),
            $template,
            $website->fresh(),
            \App\Library\Website\Setup\WizardPresentationAnswers::customSection($response),
            \App\Library\Website\Setup\WizardPresentationAnswers::catalogSelection($response),
            \App\Library\Website\Setup\WizardPresentationAnswers::serviceAreas($response),
        );

        $previewTitles = array_column($preview['included'], 'title');
        $this->assertSame(array_column($real['plan'], 'title'), $previewTitles, 'The Review preview and the real plan list the same pages in the same order.');
        $this->assertSame($real['areas']['saved'], $preview['areas']['saved']);
        $this->assertSame($real['areas']['planned_list'], $preview['areas']['planned_list']);
        $this->assertSame(14, $preview['areas']['saved']);
    }
}
