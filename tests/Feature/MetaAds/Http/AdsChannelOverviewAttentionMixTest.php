<?php

namespace Tests\Feature\MetaAds\Http;

use App\Library\Ads\AdsChannelOverviewReader;
use Tests\TestCase;

/**
 * The cross-channel "What needs attention" list stays capped at five in TOTAL
 * (ATTENTION_LIMIT), while up to two slots are held for "Going well" items so
 * a busy account still shows what is working. Pure merge logic: no data.
 */
class AdsChannelOverviewAttentionMixTest extends TestCase
{
    private function items(string $provider, string $tone, int $count): array
    {
        return array_map(fn (int $i): array => ['provider' => $provider, 'title' => "$provider $tone $i", 'tone' => $tone], range(1, $count));
    }

    private function merge(array $google, array $meta): array
    {
        $method = new \ReflectionMethod(AdsChannelOverviewReader::class, 'mergeAttention');
        $method->setAccessible(true);

        return $method->invoke(app(AdsChannelOverviewReader::class), $google, $meta);
    }

    public function test_the_total_never_exceeds_five_and_positives_keep_reserved_slots(): void
    {
        $merged = $this->merge(
            [...$this->items('google', 'neutral', 4), ...$this->items('google', 'success', 2)],
            [...$this->items('meta', 'neutral', 4), ...$this->items('meta', 'success', 2)],
        );

        $this->assertCount(5, $merged);
        $this->assertSame(2, count(array_filter($merged, fn ($i) => $i['tone'] === 'success')));
        $this->assertSame(['neutral', 'neutral', 'neutral', 'success', 'success'], array_column($merged, 'tone'), 'actionable first, positives after');
    }

    public function test_spare_slots_go_to_positives_and_nothing_positive_means_five_actionable(): void
    {
        $few = $this->merge($this->items('google', 'neutral', 1), $this->items('meta', 'success', 4));
        $this->assertCount(5, $few);
        $this->assertSame(1, count(array_filter($few, fn ($i) => $i['tone'] === 'neutral')));

        $none = $this->merge($this->items('google', 'neutral', 4), $this->items('meta', 'neutral', 4));
        $this->assertCount(5, $none);
        $this->assertSame([], array_filter($none, fn ($i) => $i['tone'] === 'success'));
    }
}
