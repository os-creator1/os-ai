<?php

namespace App\Library\Dashboard;

use App\Enums\Dashboard\HeadlinePolarity;

/**
 * Customer Experience Slice 4 §4.1/§4.5 — one headline figure, ready to
 * render: the number, the comparison and the honest interpretation beside
 * it. No raw number is ever shown without the last two.
 *
 * `judgement` is "positive", "negative" or "neutral" for a directional
 * metric only; a volume metric carries null and descriptive copy.
 */
final class Headline
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $figure,
        public readonly string $figureCaption,
        public readonly HeadlineComparison $comparison,
        public readonly HeadlinePolarity $polarity,
        public readonly string $comparisonSentence,
        public readonly string $interpretation,
        public readonly ?string $judgement,
    ) {
    }

    /** The judgement as a word — never colour alone (§15). */
    public function judgementWord(): ?string
    {
        return match ($this->judgement) {
            'positive' => 'Better',
            'negative' => 'Worse',
            'neutral' => 'No change',
            default => null,
        };
    }

    public function judgementVariant(): string
    {
        return match ($this->judgement) {
            'positive' => 'success',
            'negative' => 'danger',
            default => 'neutral',
        };
    }
}
