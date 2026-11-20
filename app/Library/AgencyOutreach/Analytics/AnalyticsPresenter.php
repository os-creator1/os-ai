<?php

namespace App\Library\AgencyOutreach\Analytics;

use App\Library\AgencyOutreach\OutreachSequence;
use App\Models\Workspace;

/** Names, order and plain-language trigger text for analytics steps, plus number formatting. */
final class AnalyticsPresenter
{
    /**
     * Order steps as the script has them NOW (opener first); steps since deleted from the script follow, in
     * the order they were first sent, labelled as removed. Disabled steps keep their data and are flagged.
     *
     * @param  list<array<string,mixed>>  $rows  rows from OutreachAnalytics::steps()
     * @return list<array<string,mixed>> same rows plus number, label, trigger, state
     */
    public static function steps(Workspace $workspace, array $rows): array
    {
        $byKey = [];
        foreach ($rows as $r) {
            $byKey[$r['step_key']] = $r;
        }

        $ordered = [];
        $n = 0;
        $previousLabel = null;

        if (isset($byKey['opener'])) {
            $ordered[] = $byKey['opener'] + ['number' => 1, 'label' => 'First message', 'trigger' => 'Sent when the campaign starts', 'state' => 'live'];
            $previousLabel = 'First message';
            $byKey['opener'] = null;
            $n = 1;
        }

        $current = OutreachSequence::forWorkspace($workspace)->steps();
        $seen = [];

        foreach ($current as $step) {
            $id = (string) $step['id'];
            $seen[$id] = true;

            if (! isset($byKey[$id])) {
                continue;
            }

            $n++;
            $label = 'Follow-up ' . ($n - 1);
            $trigger = ($step['advance_on'] ?? 'reply') === OutreachSequence::TIME
                ? 'Sent ' . self::hours((int) ($step['delay_hours'] ?? 0)) . ' after ' . ($previousLabel ?? 'the previous message') . ' if no reply'
                : 'Sent after they reply' . ($previousLabel ? ' to ' . $previousLabel : '');
            $ordered[] = $byKey[$id] + ['number' => $n, 'label' => $label, 'trigger' => $trigger, 'state' => ($step['enabled'] ?? true) ? 'live' : 'disabled'];
            $previousLabel = $label;
            $byKey[$id] = null;
        }

        $removed = array_filter($byKey);
        uasort($removed, fn ($a, $b) => $a['first_out_id'] <=> $b['first_out_id']);

        foreach ($removed as $r) {
            $n++;
            $ordered[] = $r + ['number' => $n, 'label' => 'Earlier message ' . ($n - 1 > 0 ? $n - 1 : 1), 'trigger' => 'No longer in your script. Its history is kept as it was sent.', 'state' => 'removed'];
        }

        return $ordered;
    }

    public static function hours(int $h): string
    {
        return $h >= 48 && $h % 24 === 0 ? ($h / 24) . ' days' : ($h === 1 ? '1 hour' : $h . ' hours');
    }

    public static function duration(?int $sec): string
    {
        if ($sec === null) {
            return '—';
        }

        return match (true) {
            $sec < 90 => $sec . ' s',
            $sec < 5400 => (int) round($sec / 60) . ' min',
            $sec < 172800 => rtrim(rtrim(number_format($sec / 3600, 1), '0'), '.') . ' h',
            default => rtrim(rtrim(number_format($sec / 86400, 1), '0'), '.') . ' d',
        };
    }

    public static function pct(int|float $num, int|float $den): string
    {
        return $den > 0 ? rtrim(rtrim(number_format($num / $den * 100, 1), '0'), '.') . '%' : '—';
    }
}
