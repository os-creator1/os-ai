<?php

namespace Tests\Feature\MetaAds\Http\Data;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\MetaAds\Http\Data\Concerns\CreatesMetaAdsDataFixtures;
use Tests\TestCase;

/** The Performance totals row names the status filter it actually covers, for every supported filter. */
class MetaAdsPerformanceTotalsLabelTest extends TestCase
{
    use CreatesMetaAdsDataFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareMetaHttp();
    }

    protected function tearDown(): void
    {
        $this->unpinMetaClock();
        parent::tearDown();
    }

    public function test_the_totals_scope_label_reflects_every_status_filter(): void
    {
        [$customer, $business, $workspace, $account] = $this->dataTenant();
        $this->seedMetaCampaign($account, 'Live');
        $this->seedMetaCampaign($account, 'Paused one', ['status' => 'PAUSED']);
        $this->seedMetaCampaign($account, 'Archived one', ['status' => 'ARCHIVED']);
        $this->seedMetaCampaign($account, 'Deleted one', ['status' => 'DELETED']);
        $this->asMetaUser($customer);

        $expected = [null => 'all campaigns: 4', 'active' => 'active campaigns: 1', 'paused' => 'paused campaigns: 1', 'archived' => 'archived campaigns: 1', 'deleted' => 'deleted campaigns: 1'];

        foreach ($expected as $filter => $label) {
            $html = $this->get($this->metaUrl($workspace, $business, 'campaigns.index', $filter === '' ? [] : ['status' => $filter]))->assertOk()->getContent();
            preg_match('/data-role="totals-scope">(.*?)<\/td>/s', $html, $m);
            $text = preg_replace('/\s+/', ' ', strip_tags($m[1] ?? ''));

            $this->assertStringContainsString($label, $text, 'filter [' . ($filter === '' ? 'none' : $filter) . ']');
        }
    }
}
