<?php

namespace App\Enums\Dashboard;

/**
 * Customer Experience Slice 4 §4.5 — what a change in a headline MEANS,
 * declared per metric in code. Never "up is good" for everything, and never
 * decided by AI.
 *
 *  Directional        higher = positive, lower = negative (provider-accepted rate)
 *  Inverted           lower = positive, higher = negative (confirmed failures)
 *  Descriptive        says what changed, never whether it is good (volume)
 *  DescriptiveGrowth  may describe growth; never implies revenue or quality
 */
enum HeadlinePolarity: string
{
    case Directional = 'directional';
    case Inverted = 'inverted';
    case Descriptive = 'descriptive';
    case DescriptiveGrowth = 'descriptive_growth';

    /**
     * "positive", "negative" or "neutral" for a directional metric; null for
     * a descriptive one, which never carries a judgement.
     */
    public function judgement(HeadlineTrend $trend): ?string
    {
        return match ($this) {
            self::Directional => match ($trend) {
                HeadlineTrend::Up => 'positive',
                HeadlineTrend::Down => 'negative',
                HeadlineTrend::Unchanged => 'neutral',
            },
            self::Inverted => match ($trend) {
                HeadlineTrend::Up => 'negative',
                HeadlineTrend::Down => 'positive',
                HeadlineTrend::Unchanged => 'neutral',
            },
            self::Descriptive, self::DescriptiveGrowth => null,
        };
    }
}
