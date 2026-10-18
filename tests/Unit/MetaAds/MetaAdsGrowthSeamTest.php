<?php

namespace Tests\Unit\MetaAds;

use App\Library\GoogleAds\Recommendations\GoogleAdsRecommendationFact;
use App\Library\GoogleAds\Recommendations\GoogleAdsRecommendationType;
use App\Library\MetaAds\Recommendations\MetaAdsRecommendationFact;
use App\Library\MetaAds\Recommendations\MetaAdsRecommendationType;
use PHPUnit\Framework\TestCase;

/**
 * Contract 24 section 13 - the Growth seam. Both providers' deterministic facts
 * carry an explicit `provider`, so a later Opportunity producer can key on
 * {provider, type, subject, period} and a Google and a Meta fact of the same
 * shape never collide. Pure: no database, no provider.
 */
class MetaAdsGrowthSeamTest extends TestCase
{
    public function test_every_fact_names_its_provider(): void
    {
        $google = new GoogleAdsRecommendationFact(
            GoogleAdsRecommendationType::PacingOver, 'account', null, null,
            ['period_key' => 'this_month'], ['key' => 'review_budget', 'target_uid' => null],
            'rule:pacing_over', 1_000_000, 'USD',
        );
        $meta = new MetaAdsRecommendationFact(
            MetaAdsRecommendationType::PacingOver, 'account', null, null,
            ['period_key' => 'this_month'], ['key' => 'review_budget', 'target_uid' => null],
            'rule:pacing_over', 1_000_000, 'USD',
        );

        $this->assertSame('google', $google->toArray()['provider']);
        $this->assertSame('meta', $meta->toArray()['provider']);
        $this->assertSame('pacing_over', $google->toArray()['type']);
        $this->assertSame('pacing_over', $meta->toArray()['type']);
        $this->assertNotSame($google->toArray(), $meta->toArray());
    }

    public function test_the_meta_identity_is_provider_aware_and_carries_no_provider_id(): void
    {
        $fact = new MetaAdsRecommendationFact(
            MetaAdsRecommendationType::ZeroResultSpend, 'campaign', 'uid-1', 'Spring sale',
            ['period_key' => 'last_30', 'spend_micros' => 90_000_000], ['key' => 'review_campaign', 'target_uid' => 'uid-1'],
            'rule:zero_result_spend', 90_000_000, 'USD',
        );

        $this->assertSame([
            'provider' => 'meta',
            'type' => 'zero_result_spend',
            'subject_type' => 'campaign',
            'subject_uid' => 'uid-1',
            'period_key' => 'last_30',
        ], $fact->identity());
    }

    public function test_search_term_concepts_exist_only_on_the_google_side(): void
    {
        $meta = array_map(fn (MetaAdsRecommendationType $t) => $t->value, MetaAdsRecommendationType::cases());

        foreach ($meta as $value) {
            $this->assertStringNotContainsString('search', $value);
            $this->assertStringNotContainsString('keyword', $value);
        }

        $this->assertContains('wasted_search_terms', array_map(fn ($t) => $t->value, GoogleAdsRecommendationType::cases()));
        $this->assertNotContains('wasted_search_terms', $meta);
    }
}
