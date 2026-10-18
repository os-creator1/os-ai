<?php

namespace App\Library\Seo\Rank;

use App\Models\SeoRankObservation;
use Illuminate\Support\Collection;

/**
 * Pure geometry for the rank history chart (no JS library, no I/O): turns
 * observations of ONE check type into SVG-ready coordinates.
 *
 * Rank 1 is the BEST position, so it is drawn at the TOP. Lines connect only
 * consecutive FOUND observations; a not-found/not-matched check breaks the line
 * and is drawn as a separate marker on the baseline. There is no interpolation
 * across missing checks — a gap stays a gap.
 */
final class SeoRankChart
{
    private const PAD_LEFT = 40;
    private const PAD_RIGHT = 14;
    private const PAD_TOP = 14;
    private const PAD_BOTTOM = 28;

    /**
     * @param Collection<int, SeoRankObservation> $observations already ordered ascending by time
     * @return array{has_data: bool, width: int, height: int, segments: list<string>, points: list<array{x: float, y: float, position: int, label: string}>, gaps: list<array{x: float, y: float, label: string}>, ticks: list<array{y: float, label: string}>, x_start: string|null, x_end: string|null, baseline: float}
     */
    public static function build(Collection $observations, int $width = 640, int $height = 240): array
    {
        $empty = [
            'has_data' => false, 'width' => $width, 'height' => $height, 'segments' => [], 'points' => [],
            'gaps' => [], 'ticks' => [], 'x_start' => null, 'x_end' => null, 'baseline' => (float) ($height - self::PAD_BOTTOM),
        ];

        if ($observations->isEmpty()) {
            return $empty;
        }

        $found = $observations->filter(fn (SeoRankObservation $o) => $o->isFound());
        $maxPosition = $found->isEmpty() ? 10 : (int) $found->max('position');
        $yMax = max(10, (int) (ceil($maxPosition / 10) * 10));

        $times = $observations->map(fn (SeoRankObservation $o) => $o->checked_at->getTimestamp());
        $minT = $times->min();
        $maxT = $times->max();

        $plotW = $width - self::PAD_LEFT - self::PAD_RIGHT;
        $plotH = $height - self::PAD_TOP - self::PAD_BOTTOM;

        $x = fn (int $t): float => $maxT === $minT
            ? self::PAD_LEFT + $plotW / 2
            : self::PAD_LEFT + ($t - $minT) / ($maxT - $minT) * $plotW;
        $y = fn (int $position): float => self::PAD_TOP + ($position - 1) / ($yMax - 1) * $plotH;

        $segments = [];
        $current = [];
        $points = [];
        $gaps = [];

        foreach ($observations as $o) {
            $px = round($x($o->checked_at->getTimestamp()), 1);

            if ($o->isFound()) {
                $py = round($y((int) $o->position), 1);
                $current[] = [$px, $py];
                $points[] = ['x' => $px, 'y' => $py, 'position' => (int) $o->position, 'label' => $o->checked_at->format('M j, Y') . ' · #' . $o->position];

                continue;
            }

            if (count($current) > 1) {
                $segments[] = self::path($current);
            }

            $current = [];
            $gaps[] = ['x' => $px, 'y' => (float) ($height - self::PAD_BOTTOM), 'label' => $o->checked_at->format('M j, Y') . ' · not found'];
        }

        if (count($current) > 1) {
            $segments[] = self::path($current);
        }

        $ticks = [];

        foreach (array_unique([1, (int) round($yMax / 2), $yMax]) as $tick) {
            $ticks[] = ['y' => round($y($tick), 1), 'label' => '#' . $tick];
        }

        return [
            'has_data' => true,
            'width' => $width,
            'height' => $height,
            'segments' => $segments,
            'points' => $points,
            'gaps' => $gaps,
            'ticks' => $ticks,
            'x_start' => $observations->first()->checked_at->format('M j'),
            'x_end' => $observations->last()->checked_at->format('M j'),
            'baseline' => (float) ($height - self::PAD_BOTTOM),
        ];
    }

    /** @param list<array{0: float, 1: float}> $pts */
    private static function path(array $pts): string
    {
        $d = '';

        foreach ($pts as $i => [$px, $py]) {
            $d .= ($i === 0 ? 'M' : 'L') . $px . ' ' . $py . ' ';
        }

        return trim($d);
    }
}
