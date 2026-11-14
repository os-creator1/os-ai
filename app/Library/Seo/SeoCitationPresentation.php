<?php

namespace App\Library\Seo;

use App\Enums\Seo\SeoCitationDisplayState;
use App\Enums\Seo\SeoCitationStatus;

/**
 * Presentation helpers for the Citations page: pure functions of a SeoCitationRow / section. They choose
 * the status bucket, the directory icon tile and the "Worth doing next" cards — and decide nothing the
 * section does not already know (no new status, no new recommendation engine).
 */
final class SeoCitationPresentation
{
    /** Status buckets in display order: key => [label, pill class, bar colour]. */
    public const BUCKETS = [
        'listed' => ['Listed', 'cg-p-green', '#1F8A55'],
        'progress' => ['In progress', 'cg-p-blue', '#2F6FA3'],
        'attention' => ['Needs attention', 'cg-p-amber', '#D9A441'],
        'unchecked' => ['Not checked', 'cg-p-line', '#E0DAD4'],
    ];

    private const TILES = [
        'google' => ['G', '#FCE8E6', '#B3261E'], 'apple_business' => ['A', '#EEEEEE', '#1F1A19'], 'bing_places' => ['B', '#E3F0FB', '#0B5CAB'],
        'yelp' => ['Y', '#FDE7E7', '#B4231F'], 'facebook_pages' => ['f', '#E7EEFB', '#1B4FA8'], 'foursquare' => ['4', '#FCE9F1', '#A12B62'],
        'nextdoor_business' => ['N', '#E5F4EA', '#1D6B3A'], 'weddingwire_the_knot' => ['W', '#E6F2F1', '#16655F'],
        'gigsalad' => ['G', '#FFF0E0', '#9A4B00'], 'eventective' => ['E', '#EAF0F7', '#2E5C8A'], 'bbb' => ['B', '#E8EEF5', '#21436B'],
        'yellow_pages' => ['Y', '#FFF6D6', '#7A5A00'], 'mapquest' => ['M', '#EAF3E6', '#3D6B21'],
    ];

    private const FALLBACK = [['#E7EEFB', '#1B4FA8'], ['#E6F2F1', '#16655F'], ['#F0E9F8', '#5B3A8E'], ['#FFF0E0', '#9A4B00'], ['#EAF3E6', '#3D6B21'], ['#FCE9F1', '#A12B62']];

    /** @return array{0: string, 1: string, 2: string} letter, background, foreground */
    public static function tile(string $key, string $name): array
    {
        if (isset(self::TILES[$key])) {
            return self::TILES[$key];
        }

        [$bg, $fg] = self::FALLBACK[crc32($key) % count(self::FALLBACK)];

        return [mb_strtoupper(mb_substr(trim($name) !== '' ? trim($name) : '?', 0, 1)), $bg, $fg];
    }

    /** Bucket of a directory row, or 'na' (not applicable) / 'history' (kept record, no longer offered). */
    public static function bucket(SeoCitationRow $row): string
    {
        return match (true) {
            $row->isHistoryOnly() => 'history',
            $row->isNotApplicable() => 'na',
            $row->needsAttention() => 'attention',
            $row->displayState() === SeoCitationDisplayState::InProgress => 'progress',
            // An owner can mark a row Listed without recording any details. That is not complete
            // under SeoCitationRow::isComplete(), so never inflate the green listed progress.
            $row->status === SeoCitationStatus::Listed && ! $row->isComplete() => 'unchecked',
            $row->displayState() === SeoCitationDisplayState::NotStarted => 'unchecked',
            default => 'listed',
        };
    }

    public static function googleBucket(SeoCitationLocationSection $section): ?string
    {
        return match ($section->googleState()) {
            null => null,
            SeoCitationLocationSection::GOOGLE_CONNECTED => $section->googleNeedsAttention() ? 'attention' : 'listed',
            SeoCitationLocationSection::GOOGLE_CONNECTION_LOST => 'attention',
            default => 'unchecked',
        };
    }

    /**
     * Counts per bucket for the progress card. Same population as the section summary: not-applicable and
     * history-only rows are out; Google counts as one row when the actor may see it.
     *
     * @return array{counts: array<string, int>, total: int}
     */
    public static function counts(SeoCitationLocationSection $section): array
    {
        $counts = array_fill_keys(array_keys(self::BUCKETS), 0);

        foreach ($section->rows as $row) {
            $bucket = self::bucket($row);

            if (isset($counts[$bucket])) {
                $counts[$bucket]++;
            }
        }

        if (($g = self::googleBucket($section)) !== null) {
            $counts[$g]++;
        }

        return ['counts' => $counts, 'total' => array_sum($counts)];
    }

    /**
     * "Worth doing next": up to four more open directories after the hero's own pick, in the section's own
     * priority order. Rows only (Google has its own row and hero state). Never includes a not-applicable row.
     *
     * @return array<int, SeoCitationRow>
     */
    public static function opportunities(SeoCitationLocationSection $section, ?SeoCitationRow $exclude, int $limit = 4): array
    {
        $out = [];

        foreach ($section->attentionItems() as $item) {
            $row = $item['row'];

            if ($row === null || ($exclude !== null && $row->directory->id === $exclude->directory->id)) {
                continue;
            }

            $out[] = $row;

            if (count($out) === $limit) {
                break;
            }
        }

        return $out;
    }

    /** One honest sentence for an opportunity card, from catalog / niche text only. */
    public static function why(SeoCitationRow $row): string
    {
        return $row->nicheGuidance
            ?: ($row->directory->setup_guidance ?: $row->helperText());
    }
}
