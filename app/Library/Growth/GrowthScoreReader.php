<?php

declare(strict_types=1);

namespace App\Library\Growth;

use App\Enums\Growth\GrowthScoreCategory;
use App\Models\Business;
use App\Models\GrowthScoreSnapshot;

/**
 * Reads the stored Growth Score history and explains it — deterministically,
 * with no AI (Growth Center §32-33, §38, §46).
 *
 * Only snapshots of the CURRENT algorithm version are compared: a score from
 * an older formula is never silently re-expressed on the new one, and a
 * movement across a version boundary is not reported as the Business getting
 * better or worse.
 *
 * Every method answers null/empty rather than inventing a figure when the
 * history it needs does not exist (no "+4 this month" on day one).
 */
final class GrowthScoreReader
{
    /** How far back the "this month" comparison reaches for a baseline. */
    private const COMPARE_DAYS = 28;

    public function latest(Business $business): ?GrowthScoreSnapshot
    {
        return GrowthScoreSnapshot::query()
            ->where('business_id', $business->id)
            ->where('algorithm_version', GrowthScoreCalculator::ALGORITHM_VERSION)
            ->orderByDesc('snapshot_date')
            ->first();
    }

    /**
     * The baseline snapshot to compare $latest against: the newest snapshot at
     * least 7 days older than it, else the oldest one inside COMPARE_DAYS. Null
     * when there is no older snapshot at all.
     */
    public function baseline(Business $business, GrowthScoreSnapshot $latest): ?GrowthScoreSnapshot
    {
        $base = GrowthScoreSnapshot::query()
            ->where('business_id', $business->id)
            ->where('algorithm_version', $latest->algorithm_version)
            ->where('snapshot_date', '<', $latest->snapshot_date->toDateString());

        $week = (clone $base)->where('snapshot_date', '<=', $latest->snapshot_date->copy()->subDays(7)->toDateString())
            ->where('snapshot_date', '>=', $latest->snapshot_date->copy()->subDays(self::COMPARE_DAYS)->toDateString())
            ->orderByDesc('snapshot_date')->first();

        return $week ?? $base
            ->where('snapshot_date', '>=', $latest->snapshot_date->copy()->subDays(self::COMPARE_DAYS)->toDateString())
            ->orderBy('snapshot_date')->first();
    }

    /**
     * Score movement and its deterministic contributors.
     *
     * @return array{from: int, to: int, delta: int, days: int, contributors: array<int, array{category: string, label: string, delta: int}>, sentence: string}|null
     */
    public function movement(GrowthScoreSnapshot $latest, ?GrowthScoreSnapshot $baseline): ?array
    {
        if ($baseline === null || $latest->overall_score === null || $baseline->overall_score === null) {
            return null;
        }

        $delta = (int) $latest->overall_score - (int) $baseline->overall_score;
        $contributors = [];

        foreach (GrowthScoreCategory::cases() as $category) {
            $now = $latest->category_scores[$category->value] ?? null;
            $then = $baseline->category_scores[$category->value] ?? null;

            // A category that was not scored on one side cannot be a contributor:
            // it appeared or disappeared, it did not improve or decline.
            if ($now === null || $then === null || $now === $then) {
                continue;
            }

            $contributors[] = ['category' => $category->value, 'label' => $category->label(), 'delta' => (int) $now - (int) $then];
        }

        usort($contributors, fn (array $a, array $b) => abs($b['delta']) <=> abs($a['delta']));

        $sentence = match (true) {
            $delta > 0 => "Your Growth Score improved from {$baseline->overall_score} to {$latest->overall_score}.",
            $delta < 0 => "Your Growth Score moved from {$baseline->overall_score} to {$latest->overall_score}.",
            default => "Your Growth Score is unchanged at {$latest->overall_score}.",
        };

        return [
            'from' => (int) $baseline->overall_score,
            'to' => (int) $latest->overall_score,
            'delta' => $delta,
            'days' => (int) $baseline->snapshot_date->diffInDays($latest->snapshot_date),
            'contributors' => array_slice($contributors, 0, 4),
            'sentence' => $sentence,
        ];
    }

    /**
     * The score line for a sparkline: [date => overall] at the current
     * version, oldest first, only days that had a score.
     *
     * @return array<string, int>
     */
    public function history(Business $business, int $days = 90): array
    {
        return GrowthScoreSnapshot::query()
            ->where('business_id', $business->id)
            ->where('algorithm_version', GrowthScoreCalculator::ALGORITHM_VERSION)
            ->whereNotNull('overall_score')
            ->where('snapshot_date', '>=', now()->subDays($days)->toDateString())
            ->orderBy('snapshot_date')
            ->get(['snapshot_date', 'overall_score'])
            ->mapWithKeys(fn ($s) => [$s->snapshot_date->toDateString() => (int) $s->overall_score])
            ->all();
    }

    /**
     * "What changed": comparable period figures and checks that flipped,
     * only where a fair comparison exists. Every line is a fixed template over
     * stored numbers.
     *
     * @return array<int, array{kind: string, text: string}>
     */
    public function changes(GrowthScoreSnapshot $latest, ?GrowthScoreSnapshot $baseline, ?int $minSample = null): array
    {
        $minSample ??= (new GrowthThresholds())->get('min_sample');
        $lines = [];

        $leads = $latest->metrics['new_leads'] ?? null;

        if (is_array($leads)) {
            $cur = (int) ($leads['current'] ?? 0);
            $prev = (int) ($leads['previous'] ?? 0);

            // Never a percentage from zero, and never from a sample too small
            // to mean anything (2 -> 1 is not "down 50%").
            if ($prev >= $minSample || $cur >= $minSample) {
                if ($prev === 0) {
                    $lines[] = ['kind' => 'up', 'text' => "{$cur} new leads in the last 7 days (none in the 7 days before)."];
                } else {
                    $pct = (int) round((($cur - $prev) / $prev) * 100);
                    $lines[] = [
                        'kind' => $pct >= 0 ? 'up' : 'down',
                        'text' => 'New leads ' . ($pct >= 0 ? '+' : '') . "{$pct}% over the previous 7 days ({$prev} → {$cur}).",
                    ];
                }
            }
        }

        if ($baseline !== null) {
            $was = $this->ruleStatuses($baseline);
            $is = $this->ruleStatuses($latest);

            foreach ($is as $key => $status) {
                $rule = GrowthRuleRegistry::find($key);

                if ($rule === null || ! isset($was[$key])) {
                    continue;
                }

                $title = $rule->definition()->title;

                if ($status === 'passing' && $was[$key] === 'finding') {
                    $lines[] = ['kind' => 'up', 'text' => "Resolved: {$title}."];
                } elseif ($status === 'finding' && $was[$key] === 'passing') {
                    $lines[] = ['kind' => 'down', 'text' => "New: {$title}."];
                }
            }
        }

        return array_slice($lines, 0, 6);
    }

    /** @return array<string, string> rule key => status, from a snapshot's breakdown */
    private function ruleStatuses(GrowthScoreSnapshot $snapshot): array
    {
        $out = [];

        foreach ($snapshot->breakdown as $category) {
            foreach ($category['rules'] ?? [] as $rule) {
                $out[$rule['key']] = $rule['status'];
            }
        }

        return $out;
    }

    /**
     * "What's working" — the latest snapshot's positive facts rendered through
     * each rule's own fixed statement. Bounded (the recorder stores at most 8)
     * and never turned into a standing opportunity.
     *
     * @return array<int, string>
     */
    public function positives(GrowthScoreSnapshot $latest, int $limit = 5): array
    {
        $out = [];

        foreach ($latest->positives ?? [] as $row) {
            $rule = GrowthRuleRegistry::find($row['rule'] ?? '');
            $text = $rule?->positiveStatement($row['facts'] ?? []);

            if ($text !== null) {
                $out[] = $text;
            }

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
