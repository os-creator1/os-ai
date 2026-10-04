<?php

namespace App\Library\Seo;

use App\DTO\GoogleBusinessProfile\GoogleLocationStatus;
use App\Enums\Seo\SeoCitationDisplayState;
use App\Enums\Seo\SeoCitationStatus;
use App\Enums\Seo\SeoDirectoryImportance;
use App\Enums\Seo\SeoNapFieldResult;
use App\Models\BusinessLocation;

/**
 * Contract 18 §8.5 — one ACCESSIBLE Location's slice of the Citations page.
 *
 * A Location the actor cannot access never becomes one of these, so nothing
 * here (rows, counts, the Google row) can leak an inaccessible Location.
 *
 * `google` is the synthetic, read-only Google Business Profile row — the
 * status the GBP read model reports for this Location, or null when the actor
 * may not see GBP at all (no permission / not entitled), in which case no row
 * renders. It is never stored as a citation. `googleNap` is the per-field
 * comparison of what the fresh Google mirror shows against the business
 * profile (name, phone, website — never the street address); it is null
 * whenever the mirror is absent or expired, computed for display and never
 * stored.
 *
 * `canonical` carries the canonical NAP for display. Its `address` is null
 * whenever `addressPermitted` is false. `canonicalWebsite` is the Business
 * website, compared only against a recorded listing website.
 *
 * COMPLETION IS DEFINED, NOT IMPLIED. A row is "complete" when its owner
 * marked it Listed and recorded something about it (SeoCitationRow::
 * isComplete()); the Google row is complete when it is connected. A directory
 * that merely exists in the catalog is never complete. Not-applicable rows
 * leave every denominator.
 */
final class SeoCitationLocationSection
{
    public const GOOGLE_CONNECTED = 'connected';
    public const GOOGLE_NOT_LINKED = 'not_linked';
    public const GOOGLE_CONNECTION_LOST = 'connection_lost';

    /** Page groups, in display order. */
    public const GROUP_ESSENTIAL = 'essential';
    public const GROUP_RECOMMENDED = 'recommended';
    public const GROUP_OPTIONAL = 'optional';
    public const GROUP_CUSTOM = 'custom';

    /**
     * @param  array{name: ?string, phone: ?string, address: ?string}  $canonical
     * @param  array<int, SeoCitationRow>  $rows  sorted: importance, then order
     * @param  array{name: SeoNapFieldResult, phone: SeoNapFieldResult, website: SeoNapFieldResult}|null  $googleNap
     */
    public function __construct(
        public readonly BusinessLocation $location,
        public readonly bool $writable,
        public readonly bool $addressPermitted,
        public readonly array $canonical,
        public readonly array $rows,
        public readonly ?GoogleLocationStatus $google,
        public readonly ?string $canonicalWebsite = null,
        public readonly ?string $nicheLabel = null,
        public readonly ?array $googleNap = null,
    ) {
    }

    /**
     * The Google row's state, from the GBP read model only: null when the
     * actor may not see GBP (no row at all).
     */
    public function googleState(): ?string
    {
        if ($this->google === null) {
            return null;
        }

        if (! $this->google->bound) {
            return self::GOOGLE_NOT_LINKED;
        }

        return $this->google->connectionState === 'revoked' ? self::GOOGLE_CONNECTION_LOST : self::GOOGLE_CONNECTED;
    }

    /** True only when Business OS actually read Google's facts for this row. */
    public function googleCheckedAutomatically(): bool
    {
        return $this->googleNap !== null && $this->googleState() === self::GOOGLE_CONNECTED;
    }

    /**
     * Rows by page group. Custom directories are their own group regardless
     * of importance; everything else groups by its effective importance.
     * Empty groups are omitted.
     *
     * @return array<string, array<int, SeoCitationRow>>
     */
    public function groups(): array
    {
        $groups = [
            self::GROUP_ESSENTIAL => [],
            self::GROUP_RECOMMENDED => [],
            self::GROUP_OPTIONAL => [],
            self::GROUP_CUSTOM => [],
        ];

        foreach ($this->rows as $row) {
            $key = $row->isCustom()
                ? self::GROUP_CUSTOM
                : match ($row->importance()) {
                    SeoDirectoryImportance::Essential => self::GROUP_ESSENTIAL,
                    SeoDirectoryImportance::Recommended => self::GROUP_RECOMMENDED,
                    SeoDirectoryImportance::Optional => self::GROUP_OPTIONAL,
                };

            $groups[$key][] = $row;
        }

        return array_filter($groups, fn (array $rows, string $key) => $rows !== [] || ($key === self::GROUP_ESSENTIAL && $this->googleState() !== null), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * The summary cards. Every number is a count of stored rows or of the
     * read-time comparison — there is no score and no percentage of
     * "visibility". Directories marked "not applicable" are out of every
     * count and denominator (reported separately).
     *
     * `napMatched / napCompared` counts only fields where BOTH a canonical
     * value and a recorded listing value exist; unchecked fields are not in
     * the denominator, so missing data is never counted as a mismatch. The
     * Google row is included only when its fresh mirror was actually read.
     *
     * `essential` / `recommended` are done-of-total over the (applicable)
     * Essential and Recommended rows — Google counts as Essential, done when
     * connected.
     *
     * @return array{tracked: int, completed: int, attention: int, notChecked: int, notApplicable: int, napMatched: int, napCompared: int, essentialDone: int, essentialTotal: int, recommendedDone: int, recommendedTotal: int}
     */
    public function summary(): array
    {
        $s = [
            'tracked' => 0, 'completed' => 0, 'attention' => 0, 'notChecked' => 0, 'notApplicable' => 0,
            'napMatched' => 0, 'napCompared' => 0,
            'essentialDone' => 0, 'essentialTotal' => 0, 'recommendedDone' => 0, 'recommendedTotal' => 0,
        ];

        foreach ($this->rows as $row) {
            // A record kept for a directory no longer offered here is history, not progress.
            if ($row->isHistoryOnly()) {
                continue;
            }

            if ($row->isNotApplicable()) {
                $s['notApplicable']++;

                continue;
            }

            $s['tracked']++;
            $done = $row->isComplete();

            if ($done) {
                $s['completed']++;
            }

            if ($row->displayState()->isActionable() || $row->reviewDue) {
                $s['attention']++;
            }

            if ($row->isNotChecked()) {
                $s['notChecked']++;
            }

            $tally = $row->napTally();
            $s['napMatched'] += $tally['matched'];
            $s['napCompared'] += $tally['matched'] + $tally['mismatched'];

            if (! $row->isCustom() && $row->importance() === SeoDirectoryImportance::Essential) {
                $s['essentialTotal']++;
                $s['essentialDone'] += $done ? 1 : 0;
            } elseif (! $row->isCustom() && $row->importance() === SeoDirectoryImportance::Recommended) {
                $s['recommendedTotal']++;
                $s['recommendedDone'] += $done ? 1 : 0;
            }
        }

        $google = $this->googleState();

        if ($google !== null) {
            $s['tracked']++;
            $s['essentialTotal']++;

            if ($google === self::GOOGLE_CONNECTED) {
                $s['completed']++;
                $s['essentialDone']++;
            } else {
                $s['attention']++;
            }

            if ($this->googleNap !== null) {
                foreach ($this->googleNap as $result) {
                    if ($result === SeoNapFieldResult::Consistent) {
                        $s['napMatched']++;
                        $s['napCompared']++;
                    } elseif ($result === SeoNapFieldResult::Mismatch) {
                        $s['napCompared']++;
                    }
                }
            } else {
                // Not connected, or connected with no fresh mirror to read.
                $s['notChecked']++;
            }
        }

        return $s;
    }

    /**
     * "What should I do next?" — the real actions, most important first:
     *   1 an Essential listing is missing        (Claim / Record details / Connect)
     *   2 an Essential listing is inaccurate     (Review)
     *   3 a Recommended listing is missing
     *   4 a manual listing is due for a review   (Review)
     *   5 any other Optional / Custom issue
     * Not-applicable rows never appear. Empty when there is nothing to do.
     *
     * Also the reader seam a future Growth/Opportunity producer can consume:
     * pure, no I/O, derived only from this section.
     *
     * @return array<int, array{priority: int, kind: string, row: ?SeoCitationRow, name: string, action: string, message: string}>
     */
    public function attentionItems(): array
    {
        $items = [];
        $google = $this->googleState();

        if ($google === self::GOOGLE_NOT_LINKED) {
            $items[] = ['priority' => 1, 'kind' => 'missing', 'row' => null, 'name' => 'Google Business Profile', 'action' => 'Connect', 'message' => 'Not linked to a Google listing yet.'];
        } elseif ($google === self::GOOGLE_CONNECTION_LOST) {
            $items[] = ['priority' => 2, 'kind' => 'inaccurate', 'row' => null, 'name' => 'Google Business Profile', 'action' => 'Reconnect', 'message' => 'The Google connection was lost.'];
        } elseif ($this->googleNap !== null && in_array(SeoNapFieldResult::Mismatch, $this->googleNap, true)) {
            $items[] = ['priority' => 2, 'kind' => 'inaccurate', 'row' => null, 'name' => 'Google Business Profile', 'action' => 'Review', 'message' => 'Google shows details that differ from your business profile.'];
        }

        foreach ($this->rows as $row) {
            if (! $row->countsTowardProgress()) {
                continue;
            }

            $state = $row->displayState();
            $essential = ! $row->isCustom() && $row->importance() === SeoDirectoryImportance::Essential;
            $recommended = ! $row->isCustom() && $row->importance() === SeoDirectoryImportance::Recommended;
            $missing = $state === SeoCitationDisplayState::NotStarted || $state === SeoCitationDisplayState::InProgress;
            $inaccurate = $state === SeoCitationDisplayState::NeedsAttention;

            if (! $missing && ! $inaccurate && ! $row->reviewDue) {
                continue;
            }

            if ($inaccurate) {
                $kind = 'inaccurate';
                $priority = $essential ? 2 : 5;
                $action = 'Review';
                $message = $row->helperText();
            } elseif ($missing) {
                $kind = 'missing';
                $priority = $essential ? 1 : ($recommended ? 3 : 5);
                $action = $state === SeoCitationDisplayState::InProgress
                    ? 'Update'
                    : ($row->directory->claim_url !== null ? 'Claim' : 'Record details');
                $message = $row->helperText();
            } else {
                $kind = 'stale';
                $priority = $essential || $recommended ? 4 : 5;
                $action = 'Review';
                $message = 'Last checked a while ago — confirm it still matches.';
            }

            $items[] = ['priority' => $priority, 'kind' => $kind, 'row' => $row, 'name' => $row->directory->name, 'action' => $action, 'message' => $message];
        }

        // Stable sort: priority, then page order (rows are already ordered).
        $indexed = array_map(null, array_keys($items), $items);
        usort($indexed, fn ($a, $b) => [$a[1]['priority'], $a[0]] <=> [$b[1]['priority'], $b[0]]);

        return array_values(array_map(fn ($pair) => $pair[1], $indexed));
    }
}
