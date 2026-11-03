<?php

namespace Tests\Feature\Growth;

use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Library\Growth\GrowthHomeBand;
use App\Library\Growth\GrowthOpportunityReader;
use App\Library\Growth\GrowthViewer;
use App\Models\Business;
use App\Models\BusinessLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

class GrowthHomeBandSeoCoverageTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    private const SEO = 'seo.technical_findings:v1';

    private const STALE = 'crm.stale_opportunities:v1';

    private const LEAD = 'crm.unanswered_new_leads:v1';

    private function row(Business $business, string $type, OpportunityWorkerKey $worker, int $score, array $o = []): \App\Models\Opportunity
    {
        return $this->createOpportunity($business, array_merge(['type' => $type, 'worker_key' => $worker->value, 'location_id' => null, 'priority_score' => $score, 'impact' => 3], $o));
    }

    private function band(Business $business, bool $seoFirst): array
    {
        $ws = $business->workspace;

        return app(GrowthHomeBand::class)->build($business, (int) $ws->owner_user_id, (string) $ws->uid, (string) $business->uid, $seoFirst);
    }

    private function crowd(Business $business, int $count = 3): void
    {
        foreach (range(1, $count) as $i) {
            $this->row($business, self::STALE, OpportunityWorkerKey::Sales, 90 - $i);
        }
    }

    public function test_a_qualifying_seo_opportunity_beyond_the_top_three_is_promoted_with_its_own_enriched_item(): void
    {
        $business = $this->createBusinessForOpportunities();
        $this->crowd($business);
        $seo = $this->row($business, self::SEO, OpportunityWorkerKey::Seo, 10);

        $band = $this->band($business, true);

        $this->assertSame($seo->uid, $band['head']->uid);
        $this->assertSame($seo->uid, $band['promoted']['uid']);
        $this->assertNotNull($band['promoted']['detail_url']);
        $this->assertCount(3, $band['items'], 'The listed rows stay bounded.');
        $this->assertNotContains($seo->uid, array_column($band['items'], 'uid'));
        $this->assertSame(4, $band['open_count'], 'The count is the real open total, not inflated.');
    }

    public function test_non_growth_plans_keep_the_queue_head_and_get_no_promotion(): void
    {
        $business = $this->createBusinessForOpportunities();
        $this->crowd($business);
        $this->row($business, self::SEO, OpportunityWorkerKey::Seo, 10);

        $band = $this->band($business, false);

        $this->assertSame(self::STALE, $band['head']->type);
        $this->assertNull($band['promoted']);
    }

    public function test_a_lead_response_row_in_first_place_keeps_its_place(): void
    {
        $business = $this->createBusinessForOpportunities();
        $this->row($business, self::LEAD, OpportunityWorkerKey::Sales, 95);
        $this->crowd($business, 2);
        $this->row($business, self::SEO, OpportunityWorkerKey::Seo, 10);

        $band = $this->band($business, true);

        $this->assertSame(self::LEAD, $band['head']->type);
        $this->assertNull($band['promoted']);
    }

    public function test_with_no_seo_opportunity_nothing_is_invented(): void
    {
        $business = $this->createBusinessForOpportunities();
        $this->crowd($business, 4);

        $band = $this->band($business, true);

        $this->assertSame(self::STALE, $band['head']->type);
        $this->assertNull($band['promoted']);
    }

    public function test_a_location_restricted_viewer_and_other_tenants_never_supply_the_seo_candidate(): void
    {
        $business = $this->createBusinessForOpportunities();
        $other = $this->createBusinessForOpportunities();
        $this->row($other, self::SEO, OpportunityWorkerKey::Seo, 99);
        $location = BusinessLocation::create(['business_id' => $business->id, 'service_mode' => 'storefront', 'country_code' => 'US']);
        $this->row($business, self::SEO, OpportunityWorkerKey::Seo, 80, ['location_id' => $location->id]);

        $reader = app(GrowthOpportunityReader::class);

        $this->assertNull($reader->topOfTypes($business, GrowthViewer::forTest(1, [], false), [self::SEO]));
        $this->assertSame($business->id, $reader->topOfTypes($business, GrowthViewer::forTest(1, [], true), [self::SEO])->business_id);
    }
}
