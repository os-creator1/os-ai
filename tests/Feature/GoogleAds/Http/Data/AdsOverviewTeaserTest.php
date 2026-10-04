<?php

namespace Tests\Feature\GoogleAds\Http\Data;

use App\Enums\Entitlement\WorkspacePlanTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\GoogleAds\Http\Data\Concerns\CreatesAdsDataFixtures;
use Tests\TestCase;

/**
 * The Overview's "Money wasted?" teaser was hidden by the shell until the
 * Search terms page existed. It now appears for a Business entitled to the
 * module, links to that page, and stays absent without waste or without the
 * module (a Core Business cannot open the page it would link to).
 */
class AdsOverviewTeaserTest extends TestCase
{
    use CreatesAdsDataFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAdsHttp();
    }

    protected function tearDown(): void
    {
        $this->unpinAdsClock();
        parent::tearDown();
    }

    private function overview(WorkspacePlanTier $tier, bool $withWaste): array
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant($tier);
        [$account] = $this->mirroredAdsAccount($business);
        $this->seedPhotoBoothMetrics($account);

        if ($withWaste) {
            $this->seedTerm($account, 'cheap photo booth hire', 24_000_000, 12, '0');
        }

        $this->asAdsUser($customer);

        return [$this->get($this->adsUrl($workspace, $business))->assertOk()->getContent(), $workspace, $business];
    }

    public function test_the_waste_teaser_appears_and_links_to_the_search_terms_page(): void
    {
        [$html, $workspace, $business] = $this->overview(WorkspacePlanTier::Growth, true);

        $this->assertStringContainsString('data-role="waste-teaser"', $html);
        $text = preg_replace('/\s+/', ' ', strip_tags(substr($html, (int) strpos($html, 'data-role="waste-teaser"'), 900)));
        $this->assertStringContainsString('1 search term spent USD 24.00', $text);
        $this->assertStringContainsString('without a single conversion', $text);
        $this->assertStringContainsString('href="' . $this->adsUrl($workspace, $business, 'search-terms.index') . '"', $html);
        $this->assertSame(0, $this->fakeAds->callCount());
    }

    public function test_the_teaser_is_absent_without_waste(): void
    {
        [$html] = $this->overview(WorkspacePlanTier::Growth, false);

        $this->assertStringNotContainsString('data-role="waste-teaser"', $html);
    }

    public function test_the_teaser_is_absent_for_a_core_business(): void
    {
        [$html, $workspace, $business] = $this->overview(WorkspacePlanTier::Core, true);

        $this->assertStringNotContainsString('data-role="waste-teaser"', $html);
        $this->assertStringNotContainsString($this->adsUrl($workspace, $business, 'search-terms.index'), $html);
    }
}
