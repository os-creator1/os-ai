<?php

namespace Tests\Feature\MetaAds\Http;

use App\Enums\MetaAds\MetaAdsLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\MetaAds\Http\Concerns\CreatesMetaAdsHttpFixtures;
use Tests\TestCase;

/**
 * Pacing meters on the Overview card and the Meta Recommendations page: the BAR is
 * capped at 100% wide, but the text and the accessible label state the real
 * (unbounded) utilisation, so spend at 150% of budget never reads as 100%.
 * Clock pinned to 2026-10-04: 3 days x 100M = 300M spent.
 */
class AdsPacingMeterUtilisationTest extends TestCase
{
    use CreatesMetaAdsHttpFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareMetaHttp(withGoogle: true);
    }

    protected function tearDown(): void
    {
        $this->finishMetaHttp();
        parent::tearDown();
    }

    /** @return array{0: string, 1: string} [overview html, recommendations html] */
    private function pages(int $targetMicros): array
    {
        config(['meta_ads.pacing.min_days' => 3]);   // the fixture has 3 days of data; the default needs 7
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $meta = $this->metaSelected($business, ['monthly_budget_target_micros' => $targetMicros]);
        $campaign = $this->seedMetaCampaign($meta, 'Spender');
        $this->seedMetaDays($meta, MetaAdsLevel::Campaign, (int) $campaign->id, '2026-10-01', '2026-10-03', 100_000_000, 1);
        $this->asMetaUser($customer, [self::G_VIEW, self::G_MANAGE, self::META_VIEW, self::META_MANAGE]);

        return [
            $this->get($this->channelsUrl($workspace, $business))->assertOk()->getContent(),
            $this->get($this->metaPage($workspace, $business, 'recommendations.index'))->assertOk()->getContent(),
        ];
    }

    public function test_spend_above_budget_shows_the_real_percentage_while_the_bar_stays_full(): void
    {
        [$overview, $recommendations] = $this->pages(200_000_000);   // 300M of 200M = 150%

        $this->assertStringContainsString('150% of the monthly budget used', $overview, 'accessible label');
        $this->assertStringContainsString('150% of USD 200.00 used', $overview, 'visible text');
        $this->assertStringContainsString('width: 100%', $overview, 'bar capped at full width');
        $this->assertStringNotContainsString('width: 150', $overview);

        $this->assertStringContainsString('150% of the monthly budget used', $recommendations);
        $this->assertStringContainsString('150% of budget used', $recommendations);
        $this->assertStringContainsString('width: 100%', $recommendations);
        $this->assertStringNotContainsString('width: 150', $recommendations);
    }

    public function test_ordinary_utilisation_is_unchanged(): void
    {
        [$overview, $recommendations] = $this->pages(600_000_000);   // 300M of 600M = 50%

        $this->assertStringContainsString('50% of the monthly budget used', $overview);
        $this->assertStringContainsString('50% of USD 600.00 used', $overview);
        $this->assertStringContainsString('width: 50%', $overview, 'bar width is the real value below 100%');

        $this->assertStringContainsString('50% of the monthly budget used', $recommendations);
        $this->assertStringContainsString('50% of budget used', $recommendations);
        $this->assertStringContainsString('width: 50%', $recommendations);
    }
}
