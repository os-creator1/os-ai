<?php

namespace Tests\Feature\Seo;

use App\Enums\Business\BusinessServiceMode;
use App\Enums\Seo\SeoReadinessState;
use App\Library\Seo\SeoReadinessFacts;
use App\Library\Seo\SeoReadinessItem;
use App\Library\Seo\SeoReadinessRuleRegistry;
use App\Models\Business;
use App\Models\BusinessLocation;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Contract 18 §5.2 — the Core readiness checklist is closed, deterministic
 * and pure. It never scores, and it evaluates only the Locations it is
 * handed (which the caller has already ACL-filtered).
 */
class SeoReadinessTest extends TestCase
{
    private function registry(): SeoReadinessRuleRegistry
    {
        return new SeoReadinessRuleRegistry();
    }

    private function facts(array $overrides = []): SeoReadinessFacts
    {
        $a = array_merge([
            'websiteUrlSet' => true, 'phoneSet' => true, 'websitePublished' => true,
            'gbpUrlPresent' => true, 'locationsTotal' => 2, 'locationsReady' => 2, 'keywordsDefined' => 3,
        ], $overrides);

        return new SeoReadinessFacts($a['websiteUrlSet'], $a['phoneSet'], $a['websitePublished'], $a['gbpUrlPresent'], $a['locationsTotal'], $a['locationsReady'], $a['keywordsDefined']);
    }

    /** @return array<string, SeoReadinessItem> */
    private function byKey(SeoReadinessFacts $facts): array
    {
        $items = [];

        foreach ($this->registry()->evaluate($facts) as $item) {
            $items[$item->key] = $item;
        }

        return $items;
    }

    private function location(array $attributes): BusinessLocation
    {
        return new BusinessLocation(array_merge(['service_mode' => BusinessServiceMode::Storefront, 'country_code' => 'US'], $attributes));
    }

    public function test_the_checklist_is_closed_ordered_and_within_the_contract_ceiling(): void
    {
        $items = $this->registry()->evaluate($this->facts());

        $this->assertSame(
            ['website_url_set', 'business_phone_set', 'locations_have_address_or_service_area', 'website_published', 'keywords_defined', 'gbp_url_present'],
            array_map(fn (SeoReadinessItem $i) => $i->key, $items),
        );
        $this->assertLessThanOrEqual(8, count($items), 'Contract 18 §5.2 allows at most eight items.');
    }

    public function test_the_keywords_item_is_met_with_any_visible_active_keyword_and_links_to_keywords_when_not(): void
    {
        $met = $this->byKey($this->facts(['keywordsDefined' => 1]))['keywords_defined'];
        $this->assertSame(SeoReadinessState::Met, $met->state);
        $this->assertNull($met->detail);
        $this->assertNull($met->fix);

        $notMet = $this->byKey($this->facts(['keywordsDefined' => 0]))['keywords_defined'];
        $this->assertSame(SeoReadinessState::NotMet, $notMet->state);
        $this->assertSame('Add the phrases you want customers to find you with.', $notMet->detail);
        $this->assertSame(SeoReadinessItem::FIX_SEO_KEYWORDS, $notMet->fix);
    }

    public function test_a_fully_ready_business_has_no_open_items_and_no_fix_links(): void
    {
        foreach ($this->registry()->evaluate($this->facts()) as $item) {
            $this->assertSame(SeoReadinessState::Met, $item->state, $item->key);
            $this->assertNull($item->fix, $item->key . ' has nothing to fix.');
        }
    }

    public function test_each_unmet_item_names_the_existing_screen_that_fixes_it(): void
    {
        $items = $this->byKey($this->facts([
            'websiteUrlSet' => false, 'phoneSet' => false, 'websitePublished' => false, 'gbpUrlPresent' => false,
        ]));

        foreach (['website_url_set', 'business_phone_set', 'gbp_url_present'] as $key) {
            $this->assertSame(SeoReadinessState::NotMet, $items[$key]->state, $key);
            $this->assertSame(SeoReadinessItem::FIX_BUSINESS_SETTINGS, $items[$key]->fix, $key);
            $this->assertNotNull($items[$key]->detail, $key);
        }

        $this->assertSame(SeoReadinessState::NotMet, $items['website_published']->state);
        $this->assertSame(SeoReadinessItem::FIX_WEBSITE, $items['website_published']->fix);
    }

    public function test_the_locations_item_counts_only_the_locations_it_is_given(): void
    {
        $all = $this->byKey($this->facts(['locationsTotal' => 3, 'locationsReady' => 1]))['locations_have_address_or_service_area'];
        $this->assertSame(SeoReadinessState::NotMet, $all->state);
        $this->assertSame('1 of 3 locations are ready. Add an address or a service area to the rest.', $all->detail);
        $this->assertSame(SeoReadinessItem::FIX_BUSINESS_SETTINGS, $all->fix);

        $one = $this->byKey($this->facts(['locationsTotal' => 1, 'locationsReady' => 1]))['locations_have_address_or_service_area'];
        $this->assertSame(SeoReadinessState::Met, $one->state);
        $this->assertSame('1 of 1 location', $one->detail);

        $many = $this->byKey($this->facts(['locationsTotal' => 4, 'locationsReady' => 4]))['locations_have_address_or_service_area'];
        $this->assertSame('4 of 4 locations', $many->detail);
    }

    public function test_the_locations_item_is_not_applicable_when_the_actor_has_no_accessible_location(): void
    {
        $item = $this->byKey($this->facts(['locationsTotal' => 0, 'locationsReady' => 0]))['locations_have_address_or_service_area'];

        $this->assertSame(SeoReadinessState::NotApplicable, $item->state);
        $this->assertNull($item->detail, 'A count of zero must not reveal that inaccessible Locations exist.');
        $this->assertNull($item->fix);
    }

    public function test_no_item_carries_a_score_grade_or_percentage(): void
    {
        $facts = $this->facts(['websiteUrlSet' => false, 'locationsTotal' => 3, 'locationsReady' => 1]);

        foreach ($this->registry()->evaluate($facts) as $item) {
            $text = $item->label . ' ' . $item->detail;
            $this->assertStringNotContainsString('%', $text);
            $this->assertDoesNotMatchRegularExpression('/\b(score|grade|rating|rank)\b/i', $text);
        }
    }

    public function test_evaluation_is_pure_and_repeatable(): void
    {
        $facts = $this->facts(['phoneSet' => false]);

        $this->assertEquals($this->registry()->evaluate($facts), $this->registry()->evaluate($facts));
    }

    // -----------------------------------------------------------------
    // The per-Location predicate.
    // -----------------------------------------------------------------

    public function test_a_storefront_needs_a_street_line_and_a_city(): void
    {
        $this->assertTrue(SeoReadinessFacts::locationIsReady($this->location(['address_line_1' => '1 Main St', 'city' => 'Tampa'])));
        $this->assertFalse(SeoReadinessFacts::locationIsReady($this->location(['address_line_1' => '1 Main St'])));
        $this->assertFalse(SeoReadinessFacts::locationIsReady($this->location(['city' => 'Tampa'])));
        $this->assertFalse(SeoReadinessFacts::locationIsReady($this->location(['address_line_1' => '  ', 'city' => 'Tampa'])));
        $this->assertFalse(SeoReadinessFacts::locationIsReady($this->location([])));
    }

    public function test_a_storefront_is_not_made_ready_by_a_service_area_alone(): void
    {
        $this->assertFalse(SeoReadinessFacts::locationIsReady($this->location(['service_radius_km' => 30])));
    }

    public function test_a_service_area_location_needs_a_radius_or_a_named_city(): void
    {
        $mode = BusinessServiceMode::ServiceArea;

        $this->assertTrue(SeoReadinessFacts::locationIsReady($this->location(['service_mode' => $mode, 'service_radius_km' => 25])));
        $this->assertTrue(SeoReadinessFacts::locationIsReady($this->location(['service_mode' => $mode, 'service_area_cities' => ['Tampa']])));
        $this->assertFalse(SeoReadinessFacts::locationIsReady($this->location(['service_mode' => $mode, 'service_area_cities' => ['', '  ']])));
        $this->assertFalse(SeoReadinessFacts::locationIsReady($this->location(['service_mode' => $mode])));
        $this->assertFalse(SeoReadinessFacts::locationIsReady($this->location(['service_mode' => $mode, 'address_line_1' => '1 Main St', 'city' => 'Tampa'])), 'An address does not make a service-area Location ready.');
    }

    public function test_a_hybrid_location_needs_an_address_or_a_service_area(): void
    {
        $mode = BusinessServiceMode::Hybrid;

        $this->assertTrue(SeoReadinessFacts::locationIsReady($this->location(['service_mode' => $mode, 'address_line_1' => '1 Main St', 'city' => 'Tampa'])));
        $this->assertTrue(SeoReadinessFacts::locationIsReady($this->location(['service_mode' => $mode, 'service_radius_km' => 10])));
        $this->assertFalse(SeoReadinessFacts::locationIsReady($this->location(['service_mode' => $mode])));
    }

    public function test_an_online_location_needs_neither(): void
    {
        $this->assertTrue(SeoReadinessFacts::locationIsReady($this->location(['service_mode' => BusinessServiceMode::Online])));
    }

    public function test_facts_are_built_only_from_the_locations_handed_in(): void
    {
        $business = new Business(['website_url' => 'https://example.test', 'phone' => '  ', 'google_business_profile_url' => null]);

        $ready = $this->location(['address_line_1' => '1 Main St', 'city' => 'Tampa']);
        $notReady = $this->location([]);

        $facts = SeoReadinessFacts::build($business, true, new Collection([$ready, $notReady]), 4);

        $this->assertTrue($facts->websiteUrlSet);
        $this->assertFalse($facts->phoneSet, 'Whitespace is not a phone number.');
        $this->assertFalse($facts->gbpUrlPresent);
        $this->assertTrue($facts->websitePublished);
        $this->assertSame(2, $facts->locationsTotal);
        $this->assertSame(1, $facts->locationsReady);
        $this->assertSame(4, $facts->keywordsDefined);

        // A caller that already dropped an inaccessible Location simply
        // never passes it: the facts cannot see, and so cannot count, it.
        $filtered = SeoReadinessFacts::build($business, true, new Collection([$ready]), 0);
        $this->assertSame(1, $filtered->locationsTotal);
        $this->assertSame(1, $filtered->locationsReady);
    }
}
