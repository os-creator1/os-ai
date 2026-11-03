<?php

namespace Tests\Feature\Website;

use App\Enums\Business\BusinessServiceStatus;
use App\Library\Website\WebsitePageStrategy;
use App\Models\BusinessService;
use App\Models\Website;
use App\Models\WebsiteTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesSmartWizard;
use Tests\TestCase;

/**
 * The 14-page budget never drops an eligible page silently: every candidate (booth, event type, qualified area) is either in
 * the plan or carries a decision that says it was left out and why — the list the owner sees on the Review screen.
 */
class WebsitePageBudgetVisibilityTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use DrivesSmartWizard;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSmartWizard();
    }

    public function test_every_eligible_booth_event_and_area_is_planned_or_explained_when_the_budget_is_exceeded(): void
    {
        [, $business] = $this->entitledTenant();
        $boothNames = array_map(fn ($n) => "Booth Style {$n}", range(1, 9));
        foreach ($boothNames as $i => $name) {
            BusinessService::create(['business_id' => $business->id, 'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'description' => "What {$name} is and what it includes for your guests.", 'status' => BusinessServiceStatus::Active->value, 'sort_order' => $i]);
        }
        $eventNames = ['Weddings', 'Corporate Events', 'Birthday Parties', 'Graduations', 'Proms'];
        foreach ($eventNames as $i => $name) {
            BusinessService::create(['business_id' => $business->id, 'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'kind' => BusinessService::KIND_EVENT, 'description' => "How we run {$name} with an attendant, props and instant sharing for every guest.", 'status' => BusinessServiceStatus::Active->value, 'sort_order' => 100 + $i]);
        }
        $areas = array_map(fn ($n) => ['name' => "Town {$n}, WA", 'details' => ['logistics' => "Town {$n} is a {$n}0 minute drive; we load in two hours early."]], range(1, 10));
        $website = $this->createWebsite($business);

        $result = app(WebsitePageStrategy::class)->planWithExplanation($business, WebsiteTemplate::findActiveOrFail('photo_booth_modern'), $website, null, null, $areas);

        $this->assertLessThanOrEqual(WebsitePageStrategy::MAX_TOTAL_PAGES, count($result['plan']), 'The ceiling holds.');

        $planned = array_column($result['plan'], 'page_key');
        $left = collect($result['decisions'])->where('included', false)->keyBy('key');

        foreach ($left as $decision) {
            $this->assertNotSame('', trim((string) $decision['reason']), "{$decision['key']} was left out with a reason.");
        }

        foreach (BusinessService::where('business_id', $business->id)->get() as $service) {
            $key = ($service->kind === BusinessService::KIND_EVENT ? 'event:' : 'service:') . $service->uid;
            $this->assertTrue(in_array($key, $planned, true) || $left->has($key), "{$service->name} is planned or explained.");
        }

        foreach ($areas as $area) {
            $key = 'area:' . \Illuminate\Support\Str::slug($area['name']);
            $this->assertTrue(in_array($key, $planned, true) || $left->has($key), "{$area['name']} is planned or explained.");
        }

        // The Review screen's own area summary agrees: every qualified area is planned or listed as over budget.
        $this->assertSame(10, $result['areas']['planned'] + count($result['areas']['over_budget']));
        $this->assertSame(10, count($result['areas']['planned_list']) + count($result['areas']['over_budget']));
        $this->assertNotEmpty($result['budget']['priority']);
        $this->assertGreaterThan(0, $left->count(), 'This fixture really does exceed the budget.');
    }
}
