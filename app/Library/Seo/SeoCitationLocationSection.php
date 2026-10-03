<?php

namespace App\Library\Seo;

use App\DTO\GoogleBusinessProfile\GoogleLocationStatus;
use App\Enums\Seo\SeoCitationDisplayState;
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
 * renders. It is never stored as a citation.
 *
 * `canonical` carries the canonical NAP for display. Its `address` is null
 * whenever `addressPermitted` is false.
 */
final class SeoCitationLocationSection
{
    /**
     * @param  array{name: ?string, phone: ?string, address: ?string}  $canonical
     * @param  array<int, SeoCitationRow>  $rows
     */
    public function __construct(
        public readonly BusinessLocation $location,
        public readonly bool $writable,
        public readonly bool $addressPermitted,
        public readonly array $canonical,
        public readonly array $rows,
        public readonly ?GoogleLocationStatus $google,
    ) {
    }

    public const GOOGLE_CONNECTED = 'connected';
    public const GOOGLE_NOT_LINKED = 'not_linked';
    public const GOOGLE_CONNECTION_LOST = 'connection_lost';

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

    /**
     * The summary cards. Every number is a count of stored rows or of the
     * read-time comparison — there is no score and no percentage of
     * "visibility". Directories the user marked "not applicable" are left out
     * of the tracked count (reported separately).
     *
     * `napMatched / napCompared` counts only fields where BOTH a canonical
     * value and a recorded listing value exist; unchecked fields are not in
     * the denominator, so missing data is never counted as a mismatch.
     *
     * @return array{tracked: int, linked: int, attention: int, notApplicable: int, napMatched: int, napCompared: int}
     */
    public function summary(): array
    {
        $summary = ['tracked' => 0, 'linked' => 0, 'attention' => 0, 'notApplicable' => 0, 'napMatched' => 0, 'napCompared' => 0];

        foreach ($this->rows as $row) {
            $state = $row->displayState();

            if ($state === SeoCitationDisplayState::NotApplicable) {
                $summary['notApplicable']++;

                continue;
            }

            $summary['tracked']++;

            if ($state === SeoCitationDisplayState::Accurate || $state === SeoCitationDisplayState::Listed) {
                $summary['linked']++;
            } elseif ($state->isActionable()) {
                $summary['attention']++;
            }

            $tally = $row->napTally();
            $summary['napMatched'] += $tally['matched'];
            $summary['napCompared'] += $tally['matched'] + $tally['mismatched'];
        }

        $google = $this->googleState();

        if ($google !== null) {
            $summary['tracked']++;
            $google === self::GOOGLE_CONNECTED ? $summary['linked']++ : $summary['attention']++;
        }

        return $summary;
    }

    /**
     * The rows that call for an action, in page order. Empty when nothing
     * needs attention (the view then renders no action section).
     *
     * @return array<int, SeoCitationRow>
     */
    public function attentionRows(): array
    {
        return array_values(array_filter($this->rows, fn (SeoCitationRow $row) => $row->displayState()->isActionable()));
    }
}
