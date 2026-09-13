<?php

namespace App\Library\Coo\Insight;

/**
 * Contract §9.1 — the fixed count bands a figure is reduced to before it is
 * fingerprinted: 0, 1–4, 5–9, 10–24, 25–49, 50–99, 100+.
 *
 * The bands come from `config('coo.insight.count_buckets')` (their lower
 * bounds), so the only way to change what counts as "the same" figure is a
 * config change that also bumps the insight policy version.
 */
final class CountBucket
{
    public static function label(int $count): string
    {
        $bounds = self::bounds();
        $count = max(0, $count);
        $label = (string) $bounds[0];

        foreach ($bounds as $index => $lower) {
            if ($count < $lower) {
                break;
            }

            $upper = $bounds[$index + 1] ?? null;

            if ($upper === null) {
                $label = $lower . '+';
            } elseif ($upper - 1 === $lower) {
                $label = (string) $lower;
            } else {
                $label = $lower . '-' . ($upper - 1);
            }
        }

        return $label;
    }

    /** @return array<int, int> ascending lower bounds, always starting at 0 */
    private static function bounds(): array
    {
        $bounds = array_values(array_unique(array_map('intval', (array) config('coo.insight.count_buckets'))));
        sort($bounds);

        if ($bounds === [] || $bounds[0] !== 0) {
            array_unshift($bounds, 0);
        }

        return array_values(array_unique($bounds));
    }
}
