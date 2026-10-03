<?php

namespace App\Library\Seo;

use App\Enums\Seo\SeoCitationDisplayState;
use App\Enums\Seo\SeoCitationStatus;
use App\Enums\Seo\SeoNapFieldResult;
use App\Models\SeoCitation;
use App\Models\SeoCitationDirectory;

/**
 * Contract 18 §8.5 — one (Location, directory) line of the Citations page.
 *
 * `nap` is the read-time comparison; it is presentation only and is never
 * persisted or fed back into `status`. `safeListingUrl` is the listing URL
 * AFTER the render-time https check: null whenever the stored value is
 * absent or not a safe https URL, so a view that only ever links
 * `safeListingUrl` cannot render an unsafe link.
 *
 * `listedAddress` is null whenever the Location is not permitted to expose an
 * address, whatever the row holds — a privacy floor on the read side that
 * mirrors the write-side refusal.
 */
final class SeoCitationRow
{
    /**
     * @param  array{name: SeoNapFieldResult, phone: SeoNapFieldResult, address: SeoNapFieldResult}  $nap
     */
    public function __construct(
        public readonly SeoCitationDirectory $directory,
        public readonly ?SeoCitation $citation,
        public readonly SeoCitationStatus $status,
        public readonly ?string $safeListingUrl,
        public readonly ?string $listedName,
        public readonly ?string $listedPhone,
        public readonly ?string $listedAddress,
        public readonly array $nap,
        public readonly bool $writable,
    ) {
    }

    /**
     * Per-field tallies of the read-time comparison. `comparable` counts the
     * fields that HAVE a canonical value to compare against; a field with no
     * recorded listing value is `unchecked`, never `mismatched`.
     *
     * @return array{matched: int, mismatched: int, unchecked: int, comparable: int, differing: array<int, string>}
     */
    public function napTally(): array
    {
        $tally = ['matched' => 0, 'mismatched' => 0, 'unchecked' => 0, 'comparable' => 0, 'differing' => []];

        foreach ($this->nap as $field => $result) {
            if ($result === SeoNapFieldResult::NotComparable) {
                continue;
            }

            $tally['comparable']++;

            match ($result) {
                SeoNapFieldResult::Consistent => $tally['matched']++,
                SeoNapFieldResult::Mismatch => $tally['differing'][] = $field,
                default => $tally['unchecked']++,
            };
        }

        $tally['mismatched'] = count($tally['differing']);

        return $tally;
    }

    /**
     * The status the dashboard shows. Display only: derived from the user's
     * own status and the comparison, never persisted and never written back.
     */
    public function displayState(): SeoCitationDisplayState
    {
        $tally = $this->napTally();

        return match ($this->status) {
            SeoCitationStatus::NotApplicable => SeoCitationDisplayState::NotApplicable,
            SeoCitationStatus::NeedsCorrection => SeoCitationDisplayState::NeedsAttention,
            default => match (true) {
                $tally['mismatched'] > 0 => SeoCitationDisplayState::NeedsAttention,
                $this->status === SeoCitationStatus::Listed
                    && $tally['comparable'] > 0 && $tally['unchecked'] === 0 => SeoCitationDisplayState::Accurate,
                $this->status === SeoCitationStatus::Listed => SeoCitationDisplayState::Listed,
                $this->status === SeoCitationStatus::InProgress => SeoCitationDisplayState::InProgress,
                default => SeoCitationDisplayState::NotStarted,
            },
        };
    }

    /** True once the user has recorded anything at all for this listing. */
    public function hasRecordedDetails(): bool
    {
        return $this->listedName !== null
            || $this->listedPhone !== null
            || $this->listedAddress !== null
            || $this->safeListingUrl !== null
            || $this->citation?->last_verified_at !== null;
    }

    /** One short helper line explaining the state; fixed copy plus field names only. */
    public function helperText(): string
    {
        $tally = $this->napTally();

        return match ($this->displayState()) {
            SeoCitationDisplayState::NotApplicable => 'You marked this directory as not applicable.',
            SeoCitationDisplayState::NeedsAttention => $tally['mismatched'] > 0
                ? ucfirst(implode(' and ', $tally['differing'])) . ($tally['mismatched'] === 1 ? ' differs' : ' differ') . ' from your business profile.'
                : 'You marked this listing as needing a correction.',
            SeoCitationDisplayState::Accurate => 'Everything you recorded matches your business profile.',
            SeoCitationDisplayState::Listed => $tally['matched'] > 0
                ? $tally['matched'] . ' of ' . $tally['comparable'] . ' details checked and matching.'
                : 'Listed. Record what it shows to compare it.',
            SeoCitationDisplayState::InProgress => 'Setup is in progress.',
            SeoCitationDisplayState::NotStarted => $this->hasRecordedDetails()
                ? 'Not marked as listed yet.'
                : 'No listing details recorded yet.',
        };
    }
}
