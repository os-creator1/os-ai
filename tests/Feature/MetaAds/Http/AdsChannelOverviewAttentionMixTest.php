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

    public function test_both_providers_are_represented_when_their_positives_sit_at_different_positions(): void
    {
        // Google: two positives FIRST in its ranked list. Meta: its only positive far down, after actionable items.
        $google = [
            ['provider' => 'google', 'title' => 'g-pos-1', 'tone' => 'success'],
            ['provider' => 'google', 'title' => 'g-pos-2', 'tone' => 'success'],
            ['provider' => 'google', 'title' => 'g-act-1', 'tone' => 'neutral'],
            ['provider' => 'google', 'title' => 'g-act-2', 'tone' => 'neutral'],
        ];
        $meta = [
            ['provider' => 'meta', 'title' => 'm-act-1', 'tone' => 'warning'],
            ['provider' => 'meta', 'title' => 'm-act-2', 'tone' => 'warning'],
            ['provider' => 'meta', 'title' => 'm-act-3', 'tone' => 'warning'],
            ['provider' => 'meta', 'title' => 'm-pos-1', 'tone' => 'success'],
        ];

        $merged = $this->merge($google, $meta);

        $this->assertCount(5, $merged);
        $this->assertSame(
            ['g-act-1', 'm-act-1', 'g-act-2', 'g-pos-1', 'm-pos-1'],
            array_column($merged, 'title'),
            'actionable first (round-robined), then one positive per provider; each provider keeps its own order'
        );
        $positives = array_column(array_filter($merged, fn ($i) => $i['tone'] === 'success'), 'provider');
        $this->assertContains('google', $positives);
        $this->assertContains('meta', $positives, 'Meta\'s lower-positioned positive is not crowded out');
    }
}
