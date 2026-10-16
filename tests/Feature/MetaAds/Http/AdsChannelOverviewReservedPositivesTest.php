<?php

namespace Tests\Feature\MetaAds\Http;

use App\Enums\MetaAds\MetaAdsLevel;
use App\Library\MetaAds\Recommendations\MetaAdsAttentionItems;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\MetaAds\Http\Concerns\CreatesMetaAdsHttpFixtures;
use Tests\TestCase;

/**
 * "Going well" slots are reserved from the COMPLETE fact set: a strong performer
 * ranked below five actionable facts must still reach the Overview, while the
 * list stays capped at five in total and actionable items come first.
 */
class AdsChannelOverviewReservedPositivesTest extends TestCase
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

    public function test_a_lower_ranked_strong_performer_still_gets_its_reserved_slot(): void
    {
        [$customer, $business, $workspace] = $this->metaHttpTenant();
        $meta = $this->metaSelected($business, ['target_cost_per_result_micros' => 20_000_000]);

        // Five zero-result campaigns that outspend the one strong performer (so they rank above it) ...
        foreach (range(1, 5) as $i) {
            $campaign = $this->seedMetaCampaign($meta, 'Waste ' . $i);
            $this->seedMetaDays($meta, MetaAdsLevel::Campaign, (int) $campaign->id, '2026-10-01', '2026-10-03', 100_000_000 + $i, 0);
        }
        // ... and one campaign at 10M cost per result against a 20M target (3 results, 30M spend).
        $strong = $this->seedMetaCampaign($meta, 'Strong One');
        $this->seedMetaDays($meta, MetaAdsLevel::Campaign, (int) $strong->id, '2026-10-01', '2026-10-03', 10_000_000, 1);

        $this->asMetaUser($customer, [self::G_VIEW, self::G_MANAGE, self::META_VIEW, self::META_MANAGE]);

        $html = $this->get($this->channelsUrl($workspace, $business))->assertOk()->getContent();
        $attention = substr($html, (int) strpos($html, 'data-role="attention"'));
        preg_match_all('/data-role="attention-item" data-provider="meta" data-tone="([a-z]+)"/', $attention, $tones);

        $this->assertCount(5, $tones[1], 'five in total');
        $this->assertSame(1, count(array_filter($tones[1], fn ($t) => $t === 'success')), 'the strong performer made it in');
        $this->assertSame('success', end($tones[1]), 'actionable items first, positives last');
        $this->assertStringContainsString('Strong One', $attention);
    }

    public function test_the_selection_helper_takes_positives_from_the_whole_list(): void
    {
        $facts = [...array_map(fn ($i) => ['p' => false, 'n' => $i], range(1, 6)), ['p' => true, 'n' => 7], ['p' => true, 'n' => 8], ['p' => true, 'n' => 9]];

        $picked = MetaAdsAttentionItems::selectWithReserve($facts, 5, 2, fn ($f) => $f['p']);

        $this->assertSame([1, 2, 3, 4, 5, 7, 8], array_column($picked, 'n'), 'five actionable then two positives, each in ranked order');
        $this->assertSame([1, 2, 3], array_column(MetaAdsAttentionItems::selectWithReserve($facts, 3, 0, fn ($f) => $f['p']), 'n'));
    }
}
